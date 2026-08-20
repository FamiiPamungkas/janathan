<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

class RouterosClientFactory
{
    public function create(array $credentials, array $options = []): RouterosClient
    {
        $timeout = (int) ($_ENV['MIKROTIK_TIMEOUT'] ?? 5);
        $socketTimeout = (int) ($_ENV['MIKROTIK_SOCKET_TIMEOUT'] ?? 30);
        $attempts = (int) ($_ENV['MIKROTIK_ATTEMPTS'] ?? 5);

        return new RouterosClient(
            host: $credentials['host'],
            user: $credentials['username'],
            pass: $credentials['password'],
            port: (int) $credentials['port'],
            ssl: (bool) $credentials['ssl'],
            socketTimeout: (int) ($options['socket_timeout'] ?? $socketTimeout),
            attempts: (int) ($options['attempts'] ?? $attempts),
            timeout: (int) ($options['timeout'] ?? $timeout),
        );
    }
}
