<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Exceptions\RouterosConnectionException;
use Fame1302\Janathan\Models\RouterosVersion;
use Fame1302\Janathan\Support\Logger;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Exceptions\QueryException;
use RouterOS\Query;
use Throwable;

class RouterosClient
{
    private ?Client $client = null;

    private ?RouterosVersion $version = null;

    private bool $versionDetected = false;

    private ?bool $hotspotAvailable = null;

    public function __construct(
        private string    $host,
        private string    $user,
        private string    $pass,
        private int       $port = 8728,
        private bool      $ssl = false,
        private bool      $legacy = false,
        private int       $socketTimeout = 10,
        private int       $attempts = 10,
        private int       $timeout = 10,
        ?\RouterOS\Client $client = null
    )
    {
        $this->client = $client;
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
            'socket_timeout' => $this->socketTimeout,
            'timeout' => $this->timeout,
            'attempts' => $this->attempts,
            // The vendor's ResourceStream throws "Stream timed out" on partial
            // reads when PHP's timed_out meta flag trips spuriously (a known
            // Windows/PHP quirk). Disabling it lets reads finish normally; a
            // genuinely dead connection is still caught by the vendor's total
            // read deadline ("Socket timeout reached").
            'throw_timeout_exception' => false,
        ];

        if ($this->legacy) {
            $options['legacy'] = true;
        }

        try {
            $this->client = new Client(new Config($options));
        } catch (Throwable $e) {
            throw $this->wrapConnectionError($e);
        }
    }

    /**
     * @throws ClientException
     * @throws Throwable
     * @throws QueryException
     * @throws ConfigException
     */
    public function query(string $command, array $arguments = []): array
    {
        $this->connect();

        try {
            $query = new Query($command);

            foreach ($arguments as $key => $value) {
                $query->where($key, $value);
            }

            return $this->client->query($query)->read();
        } catch (Throwable $e) {
            if ($this->isConnectionError($e)) {
                $this->disconnect();
            }

            throw $this->wrapConnectionError($e);
        }
    }

    public function test(): array
    {
        return $this->query('/system/resource/print');
    }

    public function getSystemResource(): array
    {
        return $this->query('/system/resource/print');
    }

    public function getIdentity(): ?string
    {
        try {
            $result = $this->query('/system/identity/print');

            return $result[0]['name'] ?? null;
        } catch (Throwable $e) {
            error_log("IDENTITY " . $e->getMessage());
            return null;
        }
    }

    public function getRouterBoard(): array
    {
        try {
            return $this->query('/system/routerboard/print');
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getClock(): array
    {
        try {
            return $this->query('/system/clock/print');
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * RouterOS firmware version, detected from `/system/resource/print`.
     * Returns null when it cannot be determined (e.g. unparseable value).
     */
    public function version(): ?RouterosVersion
    {
        if ($this->versionDetected) {
            return $this->version;
        }

        $this->versionDetected = true;

        try {
            $resource = $this->getSystemResource();
            $this->version = RouterosVersion::fromString($resource[0]['version'] ?? null);
        } catch (Throwable $e) {
            $this->version = null;
        }

        return $this->version;
    }

    public function getActiveUsers(): array
    {
        return $this->hotspotQuery('/ip/hotspot/active/print');
    }

    public function getHotspotHosts(): array
    {
        return $this->hotspotQuery('/ip/hotspot/host/print');
    }

    public function getHotspotUsers(): array
    {
        return $this->hotspotQuery('/ip/hotspot/user/print');
    }

    public function getHotspotUser(string $id): ?array
    {
        $result = $this->hotspotQuery('/ip/hotspot/user/print', ['.id' => $id]);

        return $result[0] ?? null;
    }

    public function addHotspotUser(array $fields): void
    {
        $result = $this->writeQuery('/ip/hotspot/user/add', $fields);
        $this->assertNotTrap($result);
    }

    public function setHotspotUser(string $id, array $fields): void
    {
        $result = $this->writeQuery('/ip/hotspot/user/set', ['.id' => $id] + $fields);
        $this->assertNotTrap($result);
    }

    public function removeHotspotUser(string $id): void
    {
        $result = $this->writeQuery('/ip/hotspot/user/remove', ['.id' => $id]);
        $this->assertNotTrap($result);
    }

    public function removeActiveUser(string $id): void
    {
        $result = $this->writeQuery('/ip/hotspot/active/remove', ['.id' => $id]);
        $this->assertNotTrap($result);
    }

    public function removeHotspotHost(string $id): void
    {
        $result = $this->writeQuery('/ip/hotspot/host/remove', ['.id' => $id]);
        $this->assertNotTrap($result);
    }

    public function getHotspotProfiles(): array
    {
        return $this->hotspotQuery('/ip/hotspot/user/profile/print');
    }

    public function getIpPools(): array
    {
        return $this->query('/ip/pool/print');
    }

    public function getHotspotProfile(string $id): ?array
    {
        $result = $this->hotspotQuery('/ip/hotspot/user/profile/print', ['.id' => $id]);
        return $result[0] ?? null;
    }

    /**
     * Add a hotspot user profile and return the RouterOS `.id` of the new
     * entry (from the `!done` reply's `ret` attribute), or null when the
     * router did not provide one.
     */
    public function addHotspotProfile(array $fields): ?string
    {
        Logger::log("ADD HOTSPOT PROFILE ", $fields);
        $result = $this->writeQuery('/ip/hotspot/user/profile/add', $fields);
        $this->assertNotTrap($result);

        $ret = $result['after']['ret'] ?? null;

        return is_string($ret) && $ret !== '' ? $ret : null;
    }

    public function setHotspotProfile(string $id, array $fields): void
    {
        $result = $this->writeQuery('/ip/hotspot/user/profile/set', ['.id' => $id] + $fields);
        $this->assertNotTrap($result);
    }

    public function removeHotspotProfile(string $id): void
    {
        $result = $this->writeQuery('/ip/hotspot/user/profile/remove', ['.id' => $id]);
        $this->assertNotTrap($result);
    }

    /**
     * Like getHotspotLogs() but lets connection/query errors propagate to the
     * caller, so a genuine empty result can be told apart from a failed query.
     */
    public function getHotspotLogs(): array
    {
        try {
            return $this->query('/log/print', ['topics' => 'hotspot, info, debug']);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function isHotspotAvailable(): bool
    {
        return $this->hotspotAvailable ?? false;
    }

    public function disconnect(): void
    {
        $this->client = null;
        $this->version = null;
        $this->versionDetected = false;
        $this->hotspotAvailable = null;
    }

    /**
     * Run a write command that sends API attributes (e.g. `=name=value`) via
     * `equal()` instead of `where()` query filters. Used for add/set/remove.
     */
    public function writeQuery(string $command, array $attributes = []): array
    {
        $this->connect();

        try {
            $query = new Query($command);

            foreach ($attributes as $key => $value) {
                $query->equal($key, $value);
            }

            return $this->client->query($query)->read();
        } catch (Throwable $e) {
            if ($this->isConnectionError($e)) {
                $this->disconnect();
            }

            throw $this->wrapConnectionError($e);
        }
    }

    /**
     * Map a connection-level vendor exception to a clear, credential-safe
     * RouterosConnectionException. Other exceptions pass through unchanged.
     */
    private function wrapConnectionError(Throwable $e): Throwable
    {
        $isTimeout = $e instanceof ClientException
            && str_contains(strtolower($e->getMessage()), 'timeout');

        if ($e instanceof ConnectException || $e instanceof ConfigException || $isTimeout) {
            $message = $isTimeout
                ? 'Connection to the MikroTik API timed out.'
                : 'Could not connect to the MikroTik API.';

            return new RouterosConnectionException($message, 0, $e);
        }

        return $e;
    }

    /**
     * A genuine transport failure (no socket, broken session, read timeout)
     * invalidates the cached client, so the next call re-establishes it. A
     * RouterOS `!trap` or bad query is not a transport error and must leave the
     * connection usable for subsequent calls.
     */
    private function isConnectionError(Throwable $e): bool
    {
        if ($e instanceof ConnectException || $e instanceof ConfigException) {
            return true;
        }

        return $e instanceof ClientException
            && str_contains(strtolower($e->getMessage()), 'timeout');
    }

    /**
     * Execute a hotspot query with graceful fallback: when the hotspot menu is
     * not available on the router (package missing, not configured, or denied),
     * the reply comes back as a RouterOS `!trap` (e.g. "no such command"). In
     * that case we cache availability as false and return an empty list instead
     * of leaking the trap payload. A successful reply marks the menu available.
     */
    private function hotspotQuery(string $command, array $attributes = []): array
    {
        $result = $this->query($command, $attributes);

        if ($this->isTrap($result)) {
            $this->hotspotAvailable = false;

            return [];
        }

        $this->hotspotAvailable = true;

        return $result;
    }

    /**
     * A RouterOS `!trap` reply is parsed into records carrying a `message` key
     * rather than the properties of a real hotspot record.
     */
    private function isTrap(array $result): bool
    {
        foreach ($result as $row) {
            if (is_array($row) && isset($row['message'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Throw when a write command came back as a RouterOS `!trap`, surfacing
     * the router's own message (e.g. a duplicate profile name).
     *
     * @throws RouterosCommandException
     */
    private function assertNotTrap(array $result): void
    {
        foreach ($result as $row) {
            if (!is_array($row)) {
                continue;
            }

            $message = $row['message'] ?? $row['after']['message'] ?? null;

            if (is_string($message) && $message !== '') {
                throw new RouterosCommandException($message);
            }
        }
    }
}
