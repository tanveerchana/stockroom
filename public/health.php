<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $path = getenv('DB_PATH') ?: __DIR__ . '/../storage/stockroom.sqlite';
    $db = new PDO('sqlite:' . $path);
    $db->query('SELECT id FROM tenants LIMIT 1');
    echo '{"status":"ok"}';
} catch (Throwable) {
    http_response_code(503);
    echo '{"status":"unavailable"}';
}
