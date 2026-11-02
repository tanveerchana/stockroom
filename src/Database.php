<?php

declare(strict_types=1);

namespace Stockroom;

use PDO;

final class Database
{
    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version === 0) {
            $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
            $pdo->exec('PRAGMA user_version=1');
        } elseif ($version !== 1) {
            throw new \RuntimeException('Unsupported database schema version.');
        }
        return $pdo;
    }
}
