<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Models\RouterosVersion;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
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
        private string $host,
        private string $user,
        private string $pass,
        private int $port = 8728,
        private bool $ssl = false,
        private bool $legacy = false,
        private int $socketTimeout = 10
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
            'socket_timeout' => $this->socketTimeout,
        ];

        if ($this->legacy) {
            $options['legacy'] = true;
        }

        $this->client = new Client(new Config($options));
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
            $this->disconnect();
            throw $e;
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
            error_log("IDENTITY ".$e->getMessage());
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
        return $this->hotspotQuery('/ip/hotspot/active/print', ['stats' => 'true']);
    }

    public function getHotspotUsers(): array
    {
        return $this->hotspotQuery('/ip/hotspot/user/print');
    }

    public function getHotspotProfiles(): array
    {
        return $this->hotspotQuery('/ip/hotspot/user/profile/print');
    }

    public function getHotspotProfile(string $id): ?array
    {
        $result = $this->query('/ip/hotspot/user/profile/print', ['.id' => $id]);
        error_log("PROFILE RESULT ".print_r($result,true));

        return $result[0] ?? null;
    }

    public function addHotspotProfile(array $fields): void
    {
        $result = $this->rawQuery('/ip/hotspot/user/profile/add', $fields);
        $this->assertNotTrap($result);
    }

    public function setHotspotProfile(string $id, array $fields): void
    {
        $result = $this->rawQuery('/ip/hotspot/user/profile/set', ['.id' => $id] + $fields);
        $this->assertNotTrap($result);
    }

    public function removeHotspotProfile(string $id): void
    {
        $result = $this->rawQuery('/ip/hotspot/user/profile/remove', ['.id' => $id]);
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
        }catch (Throwable $e){
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
     * Run a query that sends raw API attributes (e.g. `=stats=true`) instead
     * of `where()` filters.
     */
    private function rawQuery(string $command, array $attributes = []): array
    {
        $this->connect();

        try {
            $query = new Query($command);

            foreach ($attributes as $key => $value) {
                $query->equal($key, $value);
            }

            return $this->client->query($query)->read();
        } catch (Throwable $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Execute a hotspot query with graceful fallback: when the hotspot menu is
     * not available on the router (package missing, not configured, or denied),
     * an empty result is returned instead of a trap payload.
     */
    private function hotspotQuery(string $command, array $attributes = []): array
    {
        try {
            $result = $this->rawQuery($command, $attributes);
            $this->hotspotAvailable = !$this->isTrap($result);

            return $this->hotspotAvailable ? $result : [];
        } catch (Throwable $e) {
            $this->hotspotAvailable = false;

            return [];
        }
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
