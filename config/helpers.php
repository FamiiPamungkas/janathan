<?php

declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/app.php';
    }

    return $config[$key] ?? $default;
}
