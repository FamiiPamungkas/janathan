<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

class RouterosClientFactory
{
    public function create(array $credentials, array $options = []): RouterosClient
    {
        return new RouterosClient(
            host: $credentials['host'],
            user: $credentials['username'],
            pass: $credentials['password'],
            port: (int) $credentials['port'],
            ssl: (bool) $credentials['ssl'],
            attempts: (int) ($options['attempts'] ?? 10),
            timeout: (int) ($options['timeout'] ?? 10),
        );
    }
}
