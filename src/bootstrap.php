<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Stockroom\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
