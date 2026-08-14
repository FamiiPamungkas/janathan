<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Support\Logger;
use RuntimeException;
use Throwable;

readonly class HotspotService
{
    public function __construct(
        private RouterRepository      $routers,
        private RouterosClientFactory $clientFactory
    )
    {
    }

    /**
     * @return array{router: array, profiles: array, hotspotAvailable: bool}
     */
    public function getProfiles(int $routerId): array
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $profiles = $client->getHotspotProfiles();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        return [
            'router' => $router,
            'profiles' => $this->buildProfiles($profiles),
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    /**
     * @return array|null The normalized profile, or null when it does not exist.
     */
    public function getProfile(int $routerId, string $id): ?array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($routerId);

        try {
            $profile = $client->getHotspotProfile($id);
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        return $profile === null ? null : $this->buildProfile($profile);
    }

    /**
     * @return string[] Sorted, unique pool names from the router.
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function getIpPools(int $routerId): array
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $rows = $client->getIpPools();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        $names = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
                $names[] = $row['name'];
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function createProfile(int $routerId, array $values): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->addHotspotProfile($this->normalizeFields($values))
        );
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function updateProfile(int $routerId, string $id, array $values): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->setHotspotProfile($id, $this->normalizeFields($values))
        );
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function removeProfile(int $routerId, string $id): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->removeHotspotProfile($id));
    }

    private function write(int $routerId, callable $operation): void
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $operation($client);
        } catch (RouterosCommandException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return array{router: array, client: RouterosClient}
     */
    private function connect(int $routerId): array
    {
        $router = $this->routers->find($routerId);

        if ($router === null) {
            throw new RuntimeException('The selected router no longer exists.');
        }

        return [$router, $this->clientFactory->create($this->routers->getCredentials($routerId))];
    }

    private function unreachable(array $router, Throwable $e): RuntimeException
    {
        error_log("ERROR " . $e->getMessage());
        return new RuntimeException(
            'Cannot reach router "' . $router['name'] . '" (' . $router['host'] . ').',
            0,
            $e
        );
    }

    private function buildProfiles(array $profiles): array
    {
        $rows = [];

        foreach ($profiles as $p) {
            $rows[] = [
                'id' => $p['.id'] ?? '',
                'name' => $p['name'] ?? '',
                'rate_limit' => $p['rate-limit'] ?? '-',
                'shared_users' => $p['shared-users'] ?? '-',
            ];
        }

        return $rows;
    }

    private function buildProfile(array $p): array
    {
        Logger::log("ADD MAC COOKIE ", $p['add-mac-cookie']);
        return [
            'id' => $p['.id'] ?? '',
            'name' => $p['name'] ?? '',
            'rate_limit' => $p['rate-limit'] ?? '',
            'shared_users' => $p['shared-users'] ?? '1',
            'add_mac_cookie' => $p['add-mac-cookie'],
            'address_pool' => $p['address-pool'] ?? '',
            'on_login' => $p['on-login'] ?? '',
            'on_logout' => $p['on-logout'] ?? '',
        ];
    }

    /**
     * Map form input to RouterOS attribute names. Blank rate limit becomes
     * `unlimited`; other RouterOS attributes not set by the form keep their
     * own defaults (e.g. idle/session/keepalive timeouts default to `none`).
     */
    private function normalizeFields(array $values): array
    {
        $fields = [
            'name' => $values['name'],
            'shared-users' => $values['shared_users'] === '' ? '1' : $values['shared_users'],
            'rate-limit' => $values['rate_limit'],
            'add-mac-cookie' => !empty($values['add_mac_cookie']) ? 'yes' : 'no',
            'on-login' => $values['on_login'],
            'on-logout' => $values['on_logout'],
        ];

        if (($values['address_pool'] ?? '') !== '') {
            $fields['address-pool'] = $values['address_pool'];
        }

        return $fields;
    }
}
