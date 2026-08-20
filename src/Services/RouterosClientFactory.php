<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

class RouterosClientFactory
{
    public function create(array $credentials, array $options = []): RouterosClient
    {
        $timeout = (int) ($_ENV['MIKROTIK_TIMEOUT'] ?? 5);

        return new RouterosClient(
            host: $credentials['host'],
            user: $credentials['username'],
            pass: $credentials['password'],
            port: (int) $credentials['port'],
            ssl: (bool) $credentials['ssl'],
            socketTimeout: (int) ($options['socket_timeout'] ?? $timeout),
            attempts: (int) ($options['attempts'] ?? 10),
            timeout: (int) ($options['timeout'] ?? $timeout),
        );
    }
}
