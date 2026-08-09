<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

class RouterosClient
{
    private ?Client $client = null;

    public function __construct(
        private string $host,
        private string $user,
        private string $pass,
        private int $port = 8728,
        private bool $ssl = false,
        private bool $legacy = false
    ) {
    }

    public function connect(): void
    {
        if ($this->client !== null) {
            return;
        }

        $options = [
            'host' => $this->host,
            'user' => $this->user,
            'pass' => $this->pass,
            'port' => $this->port,
            'ssl' => $this->ssl,
        ];

        if ($this->legacy) {
            $options['legacy'] = true;
        }

        $this->client = new Client(new Config($options));
    }

    public function query(string $command, array $arguments = []): array
    {
        $this->connect();

        $query = new Query($command);

        foreach ($arguments as $key => $value) {
            $query->where($key, $value);
        }

        return $this->client->query($query)->read();
    }

    public function test(): array
    {
        return $this->query('/system/resource/print');
    }

    public function getSystemResource(): array
    {
        return $this->query('/system/resource/print');
    }

    public function getActiveUsers(): array
    {
        return $this->query('/ip/hotspot/active/print');
    }

    public function getHotspotUsers(): array
    {
        return $this->query('/ip/hotspot/user/print');
    }

    public function disconnect(): void
    {
        $this->client = null;
    }
}
