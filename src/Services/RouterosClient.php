<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

class RouterosClient
{
    private Client $client;
    private Config $config;

    public function __construct(
        private string $host,
        private string $user,
        private string $pass,
        private int $port = 8728,
        private bool $ssl = false
    ) {
        $this->config = new Config($this->user, $this->pass, [
            Config::OPTION_HOST => $this->host,
            Config::OPTION_PORT => $this->port,
        ]);

        if ($this->ssl) {
            $this->config->setOption(Config::OPTION_PORT, 8729);
        }
    }

    public function connect(): void
    {
        $this->client = new Client($this->config);
    }

    public function query(string $command, array $arguments = []): array
    {
        $query = Query::create($command);
        
        foreach ($arguments as $key => $value) {
            $query->where($key, $value);
        }

        return $this->client->query($query)->all();
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
        if (isset($this->client)) {
            $this->client->close();
        }
    }
}
