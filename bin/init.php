<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
$path = getenv('DB_PATH') ?: __DIR__ . '/../storage/stockroom.sqlite';
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0750, true);
}
Stockroom\Database::open($path);
echo "Stockroom schema ready.\n";
