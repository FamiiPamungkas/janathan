<?php

declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/app.php';

        $envKeys = [
            'APP_DEBUG',
            'APP_NAME',
            'APP_VERSION',
            'APP_BASE_PATH',
            'DB_PATH',
            'MIKROTIK_TIMEOUT',
            'MIKROTIK_SOCKET_TIMEOUT',
            'MIKROTIK_ATTEMPTS',
            'APP_KEY',
        ];
        foreach ($envKeys as $envKey) {
            $value = getenv($envKey);
            if ($value !== false) {
                $config[$envKey] = $value;
            }
        }
    }

    return $config[$key] ?? $default;
}
