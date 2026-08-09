<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

class DashboardService
{
    public function __construct(
        private RouterRepository $routers,
        private RouterosClientFactory $clientFactory
    ) {
    }

    /**
     * @return array{demo: bool, router: array, stats: array, sessions: array}
     */
    public function getDashboardData(int $routerId): array
    {
        $router = $this->routers->find($routerId);

        if ($router === null) {
            throw new \RuntimeException('The selected router no longer exists.');
        }

        $client = $this->clientFactory->create($this->routers->getCredentials($routerId));

        try {
            $resource = $client->getSystemResource();
            $active = $client->getActiveUsers();
            $users = $client->getHotspotUsers();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Cannot reach router "' . $router['name'] . '" (' . $router['host'] . ').',
                0,
                $e
            );
        } finally {
            $client->disconnect();
        }

        return $this->buildData($router, $resource, $active, $users);
    }

    private function buildData(array $router, array $resource, array $active, array $users): array
    {
        $r = $resource[0] ?? [];

        $totalMemory = (float) ($r['total-memory'] ?? 0);
        $freeMemory = (float) ($r['free-memory'] ?? 0);
        $totalHdd = (float) ($r['total-hdd-space'] ?? 0);
        $freeHdd = (float) ($r['free-hdd-space'] ?? 0);

        $traffic = 0;
        $sessions = [];

        foreach ($active as $s) {
            $traffic += (int) ($s['bytes-in'] ?? 0) + (int) ($s['bytes-out'] ?? 0);

            $sessions[] = [
                'id' => $s['.id'] ?? '',
                'user' => $s['user'] ?? '?',
                'ip' => $s['address'] ?? '',
                'mac' => $s['mac-address'] ?? '',
                'uptime' => $s['uptime'] ?? '0s',
                'download' => $this->formatBytes((int) ($s['bytes-in'] ?? 0)),
                'upload' => $this->formatBytes((int) ($s['bytes-out'] ?? 0)),
                'server' => $s['server'] ?? '-',
                'status' => 'Active',
            ];
        }

        $cpu = (int) ($r['cpu-load'] ?? 0);

        return [
            'demo' => false,
            'router' => [
                'name' => $router['name'],
                'host' => $router['host'],
                'port' => $router['port'],
                'identity' => $r['identity'] ?? 'Unknown',
                'model' => $r['board-name'] ?? '-',
                'version' => $r['version'] ?? '-',
                'uptime' => $r['uptime'] ?? '-',
                'cpu' => $cpu,
                'memory' => $totalMemory > 0 ? (int) round((($totalMemory - $freeMemory) / $totalMemory) * 100) : 0,
                'hdd' => $totalHdd > 0 ? (int) round((($totalHdd - $freeHdd) / $totalHdd) * 100) : 0,
                'freeMemory' => $this->formatBytes($freeMemory),
                'totalMemory' => $this->formatBytes($totalMemory),
                'freeHdd' => $this->formatBytes($freeHdd),
                'totalHdd' => $this->formatBytes($totalHdd),
            ],
            'stats' => [
                'activeSessions' => count($active),
                'totalUsers' => count($users),
                'traffic' => $this->formatBytes($traffic),
                'cpu' => $cpu,
            ],
            'sessions' => $sessions,
        ];
    }

    private function formatBytes(int|float $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GiB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MiB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        return (string) (int) $bytes . ' B';
    }
}
