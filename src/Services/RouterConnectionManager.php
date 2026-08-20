<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Exceptions\RouterosConnectionException;

/**
 * Holds one RouterosClient per router for the lifetime of a single HTTP
 * request, so every service method reuses the same TCP session + login.
 *
 * This mirrors Mikhmon's per-request singleton pattern: the whole page runs on
 * a single RouterOS connection instead of opening (and re-authenticating) a
 * fresh one for every API call. A new request always starts with empty
 * connections — PHP cannot persist sockets between requests.
 */
class RouterConnectionManager
{
    /** @var array<int, RouterosClient> */
    private array $clients = [];

    public function __construct(
        private RouterosClientFactory $factory,
        private RouterRepository      $routers
    )
    {
    }

    public function get(int $routerId): RouterosClient
    {
        if (!isset($this->clients[$routerId])) {
            $credentials = $this->routers->getCredentials($routerId);

            if ($credentials === null) {
                throw new RouterosConnectionException('The selected router no longer exists.');
            }

            $this->clients[$routerId] = $this->factory->create($credentials);
        }

        return $this->clients[$routerId];
    }

    /**
     * Drop a cached connection so the next call re-establishes it. Used when a
     * caller knows the session is dead (e.g. after a successful test-connect
     * that should not linger).
     */
    public function release(int $routerId): void
    {
        if (isset($this->clients[$routerId])) {
            $this->clients[$routerId]->disconnect();
            unset($this->clients[$routerId]);
        }
    }

    public function __destruct()
    {
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
    }
}
