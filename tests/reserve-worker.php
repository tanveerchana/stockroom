<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
use Stockroom\{Database, Actor, Inventory};
[$script, $path, $user, $item, $barrier] = $argv;
$db = Database::open($path);
$actor = Actor::load($db, (int) $user);
echo "ready\n";
flush();
$deadline = microtime(true) + 5;
while (!is_file($barrier)) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}
try {
    (new Inventory($db))->reserve(
        $actor,
        (int) $item,
        7,
        'Parallel worker',
        bin2hex(random_bytes(16)),
    );
    echo "reserved\n";
} catch (DomainException) {
    echo "rejected\n";
}
