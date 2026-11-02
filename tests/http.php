<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
use Stockroom\Database;
$directory = sys_get_temp_dir() . '/stockroom-http-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$path = $directory . '/test.sqlite';
$port = 15000 + random_int(0, 10000);
$base = 'http://127.0.0.1:' . $port;
$ephemeralHosting = getenv('STOCKROOM_TEST_EPHEMERAL') === 'true';
$environment = array_merge(getenv(), [
    'APP_ENV' => 'local',
    'STOCKROOM_EPHEMERAL_HOSTING' => $ephemeralHosting ? 'true' : 'false',
    'DB_PATH' => $path,
    'SESSION_PATH' => $directory . '/sessions',
]);
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', __DIR__ . '/../public'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $directory . '/server.log', 'a'],
        2 => ['file', $directory . '/server.log', 'a'],
    ],
    $pipes,
    dirname(__DIR__),
    $environment,
);
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function request(string $base, string $jar, string $path = '/', ?array $data = null): array
{
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($data !== null) {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
        ]);
    }
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $size);
    $body = substr($response, $size);
    curl_close($curl);
    return [$status, $body, $headers];
}
function csrf(string $html): string
{
    if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $m)) {
        throw new RuntimeException('CSRF field absent');
    }
    return $m[1];
}
try {
    if (!is_resource($proc)) {
        throw new RuntimeException('HTTP server could not start');
    }
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($socket) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    check($ready, 'HTTP server did not become ready');
    $ownerJar = $directory . '/owner.cookies';
    [$status, $ignored, $sessionHeaders] = request($base, $ownerJar, '/?page=catalog');
    check($status === 303, 'Guests must be redirected to login');
    [$status, $html, $headers] = request($base, $ownerJar, '/?page=register');
    check($status === 200, 'Register view failed');
    check(str_contains($html, 'Temporary hosting demo') === $ephemeralHosting, 'Hosting notice must match explicit deployment mode on registration');
    check(str_contains(strtolower($sessionHeaders), 'httponly'), 'Session cookie must be HttpOnly');
    check(
        str_contains(strtolower($sessionHeaders), 'samesite=lax'),
        'Session cookie must have SameSite',
    );
    check(str_contains(strtolower($headers), "script-src 'none'"), 'CSP must disallow scripts');
    $token = csrf($html);
    [$status] = request($base, $ownerJar, '/', [
        'action' => 'register',
        'return' => 'register',
        'csrf' => $token,
        'name' => 'Owner',
        'email' => 'owner@example.invalid',
        'workspace' => 'Test stockroom',
        'password' => 'Correct horse battery!',
        'password_confirmation' => 'Correct horse battery!',
    ]);
    check($status === 303, 'Registration did not succeed');
    [$status, $html] = request($base, $ownerJar);
    check(
        $status === 200 && str_contains($html, 'Test stockroom'),
        'Registered workspace did not load',
    );
    check(str_contains($html, 'Temporary hosting demo') === $ephemeralHosting, 'Workspace hosting notice must match explicit deployment mode');
    if (!$ephemeralHosting) check(!str_contains($html, 'Free demo hosting uses ephemeral storage'), 'Self-hosted mode must not claim its storage is ephemeral');
    $token = csrf($html);
    [$status] = request($base, $ownerJar, '/', [
        'action' => 'create_item',
        'return' => 'catalog',
        'sku' => 'HTTP-ONE',
        'name' => 'Mailers <script>alert(1)</script>',
        'reorder_level' => '3',
    ]);
    check($status === 419, 'Missing CSRF must be rejected');
    [$status] = request($base, $ownerJar, '/', [
        'action' => 'create_item',
        'return' => 'catalog',
        'csrf' => $token,
        'sku' => 'HTTP-ONE',
        'name' => 'Mailers <script>alert(1)</script>',
        'reorder_level' => '3',
    ]);
    check($status === 303, 'Owner catalog creation failed');
    [$status, $html] = request($base, $ownerJar, '/?page=catalog');
    check(
        str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'),
        'Stored item names must be escaped',
    );
    check(!str_contains($html, 'Mailers <script>'), 'Stored script must never render raw');
    $token = csrf($html);
    $db = Database::open($path);
    $item = (int) $db->query('SELECT id FROM items')->fetchColumn();
    $payload = [
        'action' => 'receipt',
        'return' => 'overview',
        'csrf' => $token,
        'item_id' => (string) $item,
        'quantity' => '10',
        'reference' => '=SUM(1,2)',
        'request_key' => bin2hex(random_bytes(16)),
    ];
    request($base, $ownerJar, '/', $payload);
    request($base, $ownerJar, '/', $payload);
    check(
        (int) $db->query('SELECT on_hand FROM items')->fetchColumn() === 10,
        'HTTP retry must not duplicate receipt',
    );
    [$status] = request($base, $ownerJar, '/', [
        'action' => 'invite',
        'return' => 'team',
        'csrf' => $token,
        'email' => 'viewer@example.invalid',
        'role' => 'viewer',
    ]);
    check($status === 303, 'Invitation failed');
    [$status, $team] = request($base, $ownerJar, '/?page=team');
    check((bool) preg_match('/token=([a-f0-9]{64})/', $team, $match), 'Invitation link missing');
    $invite = $match[1];
    $viewerJar = $directory . '/viewer.cookies';
    [$status, $html] = request($base, $viewerJar, '/?page=join&token=' . $invite);
    check(
        $status === 200 && str_contains($html, 'viewer@example.invalid'),
        'Invitation view failed',
    );
    $viewerToken = csrf($html);
    request($base, $viewerJar, '/', [
        'action' => 'accept',
        'return' => 'login',
        'csrf' => $viewerToken,
        'token' => $invite,
        'name' => 'Viewer',
        'password' => 'Correct horse battery!',
        'password_confirmation' => 'Correct horse battery!',
    ]);
    [$status, $html] = request($base, $viewerJar, '/?page=catalog');
    check($status === 200, 'Viewer cannot inspect catalog');
    $viewerToken = csrf($html);
    [$status] = request($base, $viewerJar, '/', [
        'action' => 'receipt',
        'return' => 'overview',
        'csrf' => $viewerToken,
        'item_id' => (string) $item,
        'quantity' => '50',
        'reference' => 'Forbidden',
        'request_key' => bin2hex(random_bytes(16)),
    ]);
    check($status === 403, 'Server must deny viewer mutation');
    check(
        (int) $db->query('SELECT on_hand FROM items')->fetchColumn() === 10,
        'Denied viewer request changed stock',
    );
    [$status, $csv] = request($base, $viewerJar, '/?page=movements&export=csv');
    check($status === 200 && str_contains($csv, 'HTTP-ONE'), 'Viewer audit export failed');
    check(str_contains($csv, "'=SUM(1,2)"), 'CSV formulas must be neutralized');
    $otherJar = $directory . '/other.cookies';
    [$status, $html] = request($base, $otherJar, '/?page=register');
    request($base, $otherJar, '/', [
        'action' => 'register',
        'return' => 'register',
        'csrf' => csrf($html),
        'name' => 'Other owner',
        'email' => 'other@example.invalid',
        'workspace' => 'Other tenant',
        'password' => 'Correct horse battery!',
        'password_confirmation' => 'Correct horse battery!',
    ]);
    [$status, $html] = request($base, $otherJar);
    check(!str_contains($html, 'HTTP-ONE'), 'Other tenant read leaked item');
    request($base, $otherJar, '/', [
        'action' => 'receipt',
        'return' => 'overview',
        'csrf' => csrf($html),
        'item_id' => (string) $item,
        'quantity' => '1',
        'reference' => 'Cross tenant',
        'request_key' => bin2hex(random_bytes(16)),
    ]);
    check(
        (int) $db->query('SELECT on_hand FROM items')->fetchColumn() === 10,
        'Cross-tenant request changed stock',
    );
    [$status, $html] = request($base, $ownerJar);
    request($base, $ownerJar, '/', [
        'action' => 'logout',
        'return' => 'login',
        'csrf' => csrf($html),
    ]);
    [$status] = request($base, $ownerJar);
    check($status === 303, 'Logout did not revoke session access');
    $demoJar = $directory . '/demo.cookies';
    [$status, $html] = request($base, $demoJar, '/?page=login');
    request($base, $demoJar, '/', ['action' => 'demo', 'return' => 'login', 'csrf' => csrf($html)]);
    [$status, $html] = request($base, $demoJar);
    check($status === 200 && str_contains($html, 'Guest demo.'), 'Isolated demo failed to seed');
    check(
        (int) $db->query('SELECT count(*) FROM tenants WHERE is_demo=1')->fetchColumn() === 1,
        'Demo tenant was not separate',
    );
    echo "PASS HTTP hosting mode, registration, session cookies, CSRF, escaped output, receipt replay, invitation, viewer denial, export, tenant isolation, logout and isolated demo\n";
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    if (is_dir($directory)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
