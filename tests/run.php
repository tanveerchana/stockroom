<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
use Stockroom\{Database, Inventory, Actor, Forbidden};
$tests = [];
function test(string $name, Closure $run): void
{
    global $tests;
    $tests[$name] = $run;
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true),
        );
    }
}
function rejects(Closure $run, string $class = DomainException::class): void
{
    try {
        $run();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw $e;
    }
    throw new RuntimeException('Expected rejection: ' . $class);
}
function fixture(?string $path = null): array
{
    $db = Database::open($path ?? ':memory:');
    $now = gmdate('Y-m-d H:i:s');
    $db->prepare('INSERT INTO tenants(name,created_at) VALUES(?,?)')->execute([
        'Test warehouse',
        $now,
    ]);
    $tid = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO users(tenant_id,name,email,password,role,created_at) VALUES(?,?,?,?,?,?)',
    )->execute([
        $tid,
        'Owner',
        'owner' . bin2hex(random_bytes(4)) . '@example.invalid',
        'not-a-real-hash',
        'owner',
        $now,
    ]);
    return [$db, new Inventory($db), Actor::load($db, (int) $db->lastInsertId())];
}
function balance(PDO $db, int $item): array
{
    $q = $db->prepare('SELECT on_hand,reserved FROM items WHERE id=?');
    $q->execute([$item]);
    return $q->fetch();
}
function keyFor(): string
{
    return bin2hex(random_bytes(16));
}

test('integer units reject fractions, exponent notation, negatives and overflow', function () {
    same(29, Inventory::units('29'));
    same(0, Inventory::units('0', true));
    foreach (['0', '1.5', '1e2', '-1', '1000001', true, 1.2, ''] as $q) {
        rejects(fn() => Inventory::units($q));
    }
});
test('receipt, reservation and fulfilment preserve exact balances and audit deltas', function () {
    [$db, $s, $a] = fixture();
    $id = $s->createItem($a, 'SKU-ONE', 'Test item', '3');
    $s->receive($a, $id, '10', 'Inbound 001', keyFor());
    $r = $s->reserve($a, $id, '7', 'Order 001', keyFor());
    same(['on_hand' => 10, 'reserved' => 7], balance($db, $id));
    $s->close($a, $r, 'fulfil');
    same(['on_hand' => 3, 'reserved' => 0], balance($db, $id));
    same(
        [10, 0, -7],
        $db->query('SELECT on_hand_delta FROM movements ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
    );
    same(
        [0, 7, -7],
        $db->query('SELECT reserved_delta FROM movements ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
    );
});
test('overselling rejects without partial writes', function () {
    [$db, $s, $a] = fixture();
    $id = $s->createItem($a, 'ONE', 'One', 0);
    $s->receive($a, $id, 5, 'Receipt', keyFor());
    $s->reserve($a, $id, 4, 'First', keyFor());
    rejects(fn() => $s->reserve($a, $id, 2, 'Oversell', keyFor()));
    same(['on_hand' => 5, 'reserved' => 4], balance($db, $id));
    same(2, (int) $db->query('SELECT count(*) FROM movements')->fetchColumn());
    same(1, (int) $db->query('SELECT count(*) FROM reservations')->fetchColumn());
});
test('release restores availability and terminal reservations cannot run twice', function () {
    [$db, $s, $a] = fixture();
    $id = $s->createItem($a, 'ONE', 'One', 0);
    $s->receive($a, $id, 5, 'Receipt', keyFor());
    $r = $s->reserve($a, $id, 3, 'Hold', keyFor());
    $s->close($a, $r, 'release');
    same(['on_hand' => 5, 'reserved' => 0], balance($db, $id));
    rejects(fn() => $s->close($a, $r, 'fulfil'));
    rejects(fn() => $s->close($a, $r, 'release'));
    same(3, (int) $db->query('SELECT count(*) FROM movements')->fetchColumn());
});
test('repeated request key replays once and conflicting reuse is rejected', function () {
    [$db, $s, $a] = fixture();
    $id = $s->createItem($a, 'ONE', 'One', 0);
    $key = keyFor();
    $s->receive($a, $id, 5, 'Receipt', $key);
    $s->receive($a, $id, '5', 'Receipt', $key);
    same(['on_hand' => 5, 'reserved' => 0], balance($db, $id));
    same(1, (int) $db->query('SELECT count(*) FROM movements')->fetchColumn());
    rejects(fn() => $s->receive($a, $id, 6, 'Receipt', $key));
});
test('tenant scope prevents reading and mutating another workspace item', function () {
    [$db, $s, $a] = fixture();
    $id = $s->createItem($a, 'ONE', 'One', 0);
    $now = gmdate('Y-m-d H:i:s');
    $db->prepare('INSERT INTO tenants(name,created_at) VALUES(?,?)')->execute(['Other', $now]);
    $tid = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO users(tenant_id,name,email,password,role,created_at) VALUES(?,?,?,?,?,?)',
    )->execute([$tid, 'Other', 'other@example.invalid', 'hash', 'owner', $now]);
    $other = Actor::load($db, (int) $db->lastInsertId());
    same([], $s->items($other));
    rejects(fn() => $s->receive($other, $id, 3, 'Wrong tenant', keyFor()));
    same(['on_hand' => 0, 'reserved' => 0], balance($db, $id));
});
test('viewer cannot mutate and operator cannot create catalog items', function () {
    [$db, $s, $a] = fixture();
    $id = $s->createItem($a, 'ONE', 'One', 0);
    $db->exec("UPDATE users SET role='viewer'");
    $viewer = Actor::load($db, $a->id);
    rejects(fn() => $s->receive($viewer, $id, 2, 'No', keyFor()), Forbidden::class);
    $db->exec("UPDATE users SET role='operator'");
    $op = Actor::load($db, $a->id);
    rejects(fn() => $s->createItem($op, 'TWO', 'Two', 0), Forbidden::class);
    $s->receive($op, $id, 2, 'Allowed', keyFor());
    same(2, balance($db, $id)['on_hand']);
});
test('database prevents negative balances and audit mutation', function () {
    [$db, $s, $a] = fixture();
    $id = $s->createItem($a, 'ONE', 'One', 0);
    $s->receive($a, $id, 2, 'Receipt', keyFor());
    rejects(fn() => $db->exec('UPDATE items SET reserved=3'), PDOException::class);
    rejects(fn() => $db->exec("UPDATE movements SET reference='tampered'"), PDOException::class);
    rejects(fn() => $db->exec('DELETE FROM movements'), PDOException::class);
});
test('two independent connections cannot reserve beyond available stock', function () {
    $path = sys_get_temp_dir() . '/stockroom-race-' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        [$db, $s, $a] = fixture($path);
        $id = $s->createItem($a, 'ONE', 'One', 0);
        $s->receive($a, $id, 10, 'Receipt', keyFor());
        $second = new Inventory(Database::open($path));
        $s->reserve($a, $id, 7, 'Worker one', keyFor());
        rejects(fn() => $second->reserve($a, $id, 7, 'Worker two', keyFor()));
        same(['on_hand' => 10, 'reserved' => 7], balance($db, $id));
    } finally {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

test('account passwords are hashed and authentication rejects invalid credentials', function () {
    [$db] = fixture();
    $auth = new \Stockroom\Auth($db);
    $id = $auth->register(
        'New owner',
        'new@example.invalid',
        'Correct horse battery!',
        'New warehouse',
    );
    $actor = Actor::load($db, $id);
    same('owner', $actor->role);
    same('New warehouse', $actor->workspace);
    same($id, $auth->login('NEW@example.invalid', 'Correct horse battery!'));
    $hash = $db->query('SELECT password FROM users WHERE id=' . (int) $id)->fetchColumn();
    same(true, password_verify('Correct horse battery!', $hash));
    rejects(fn() => $auth->login('new@example.invalid', 'incorrect'));
    rejects(
        fn() => $auth->register(
            'Duplicate',
            'new@example.invalid',
            'Another safe password!',
            'Other',
        ),
    );
    rejects(fn() => $auth->register('Short', 'short@example.invalid', 'short', 'Other'));
});
test('invitation tokens are hashed, single-use and bind the recipient and role', function () {
    [$db, $s, $a] = fixture();
    $auth = new \Stockroom\Auth($db);
    $token = $auth->invite($a, 'reader@example.invalid', 'viewer');
    same(hash('sha256', $token), $db->query('SELECT token_hash FROM invitations')->fetchColumn());
    $id = $auth->accept($token, 'Reader', 'Correct horse battery!');
    $reader = Actor::load($db, $id);
    same($a->tenantId, $reader->tenantId);
    same('reader@example.invalid', $reader->email);
    same('viewer', $reader->role);
    rejects(fn() => $auth->accept($token, 'Again', 'Correct horse battery!'));
    rejects(fn() => $auth->invite($a, 'new@example.invalid', 'owner'));
    rejects(fn() => $auth->invite($reader, 'new@example.invalid', 'operator'), Forbidden::class);
});
test('tenant owners cannot manage other tenants or remove the protected owner', function () {
    [$db, $s, $a] = fixture();
    $auth = new \Stockroom\Auth($db);
    $other = $auth->register(
        'Other owner',
        'other@example.invalid',
        'Correct horse battery!',
        'Other',
    );
    rejects(fn() => $auth->updateMember($a, $other, 'viewer'));
    rejects(fn() => $auth->updateMember($a, $a->id, 'viewer'));
    $token = $auth->invite($a, 'operator@example.invalid', 'operator');
    $id = $auth->accept($token, 'Operator', 'Correct horse battery!');
    $auth->updateMember($a, $id, 'viewer');
    same('viewer', Actor::load($db, $id)->role);
    $auth->updateMember($a, $id, 'viewer', true);
    same(null, Actor::load($db, $id));
    rejects(fn() => $auth->login('operator@example.invalid', 'Correct horse battery!'));
});
test('expired guest tenants cannot authenticate a session actor', function () {
    [$db, $s, $a] = fixture();
    $db->exec("UPDATE tenants SET is_demo=1,expires_at='2000-01-01 00:00:00'");
    same(null, Actor::load($db, $a->id));
});
test('rate limiting bounds repeated authentication attempts', function () {
    [$db] = fixture();
    $auth = new \Stockroom\Auth($db);
    for ($i = 0; $i < 3; $i++) {
        $auth->throttle('login', 'test-address', 3, 600);
    }
    rejects(fn() => $auth->throttle('login', 'test-address', 3, 600));
});
test('simultaneous PHP processes serialize reservation writes without overselling', function () {
    $path = sys_get_temp_dir() . '/stockroom-parallel-' . bin2hex(random_bytes(6)) . '.sqlite';
    $barrier = $path . '.go';
    $processes = [];
    try {
        [$db, $s, $a] = fixture($path);
        $id = $s->createItem($a, 'PARALLEL', 'Parallel stock', 0);
        $s->receive($a, $id, 10, 'Initial', keyFor());
        for ($i = 0; $i < 2; $i++) {
            $proc = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/reserve-worker.php',
                    $path,
                    (string) $a->id,
                    (string) $id,
                    $barrier,
                ],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($proc)) {
                throw new RuntimeException('Could not start reservation worker');
            }
            same("ready\n", fgets($pipes[1]));
            $processes[] = [$proc, $pipes];
        }
        file_put_contents($barrier, 'go');
        $results = [];
        foreach ($processes as [$proc, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]));
            $stderr = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($proc);
            if ($status !== 0) {
                throw new RuntimeException('Worker failed: ' . $stderr);
            }
        }
        sort($results);
        same(['rejected', 'reserved'], $results);
        same(['on_hand' => 10, 'reserved' => 7], balance($db, $id));
        same(1, (int) $db->query('SELECT count(*) FROM reservations')->fetchColumn());
        same(2, (int) $db->query('SELECT count(*) FROM movements')->fetchColumn());
    } finally {
        foreach ([$path, $path . '-wal', $path . '-shm', $barrier] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

test(
    'invalid password bytes are rejected as validation instead of infrastructure errors',
    function () {
        [$db] = fixture();
        $auth = new \Stockroom\Auth($db);
        rejects(
            fn() => $auth->register(
                'Invalid',
                'invalid@example.invalid',
                "a sufficiently long\0password",
                'Workspace',
            ),
        );
    },
);
test('receipts cannot overflow item capacity and rejected writes leave no audit row', function () {
    [$db, $s, $a] = fixture();
    $id = $s->createItem($a, 'CAPACITY', 'Bounded item', 0);
    $s->receive($a, $id, 1000000, 'Initial', keyFor());
    rejects(fn() => $s->receive($a, $id, 1, 'Overflow', keyFor()));
    same(['on_hand' => 1000000, 'reserved' => 0], balance($db, $id));
    same(1, (int) $db->query('SELECT count(*) FROM movements')->fetchColumn());
});
$failed = 0;
foreach ($tests as $name => $run) {
    try {
        $run();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name: " . $e->getMessage() . "\n";
    }
}
echo count($tests) . ' tests; ' . $failed . " failures\n";
exit($failed ? 1 : 0);
