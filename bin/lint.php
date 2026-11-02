<?php

declare(strict_types=1);

$failed = false;
$root = dirname(__DIR__);
foreach (['src', 'public', 'views', 'tests', 'bin'] as $directory) {
    foreach (
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory))
        as $file
    ) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            passthru(
                escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()),
                $code,
            );
            if ($code !== 0) {
                $failed = true;
            }
        }
    }
}
exit($failed ? 1 : 0);
