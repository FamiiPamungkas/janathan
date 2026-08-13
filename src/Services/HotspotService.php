<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Throwable;

readonly class HotspotService
{
    public function __construct(
        private RouterRepository      $routers,
        private RouterosClientFactory $clientFactory
    ) {
    }

    /**
     * @return array{router: array, profiles: array, hotspotAvailable: bool}
     */
    public function getProfiles(int $routerId): array
    {
        $router = $this->routers->find($routerId);

        if ($router === null) {
            throw new \RuntimeException('The selected router no longer exists.');
        }

        $client = $this->clientFactory->create($this->routers->getCredentials($routerId));

        try {
            $profiles = $client->getHotspotProfiles();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'Cannot reach router "' . $router['name'] . '" (' . $router['host'] . ').',
                0,
                $e
            );
        } finally {
            $client->disconnect();
        }

        return [
            'router' => $router,
            'profiles' => $this->buildProfiles($profiles),
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    private function buildProfiles(array $profiles): array
    {
        $rows = [];

        foreach ($profiles as $p) {
            $rows[] = [
                'name' => $p['name'] ?? '',
                'rate_limit' => $p['rate-limit'] ?? '-',
                'shared_users' => $p['shared-users'] ?? '-',
            ];
        }

        return $rows;
    }
}
