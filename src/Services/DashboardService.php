<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Models\RouterosVersion;

class DashboardService
{
    public function __construct(
        private RouterRepository $routers,
        private RouterosClientFactory $clientFactory
    ) {
    }

    /**
     * @return array{demo: bool, router: array, stats: array, logs: array, hotspotAvailable: bool}
     */
    public function getDashboardData(int $routerId): array
    {
        $router = $this->routers->find($routerId);

        if ($router === null) {
            throw new \RuntimeException('The selected router no longer exists.');
        }

        $client = $this->clientFactory->create($this->routers->getCredentials($routerId));

        try {
            $users = $client->getHotspotUsers();
            $clock = $client->getClock();
            $resource = $client->getSystemResource();
            $board = $client->getRouterBoard();
            $active = $client->getActiveUsers();
            $identity = $client->getIdentity();
            $logs = $client->getHotspotLogs();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Cannot reach router "' . $router['name'] . '" (' . $router['host'] . ').',
                0,
                $e
            );
        } finally {
            $client->disconnect();
        }

        return $this->buildData($router, $resource, $active, $users, $client->isHotspotAvailable(), $identity, $board, $clock, $logs);
    }

    private function buildData(
        array $router,
        array $resource,
        array $active,
        array $users,
        bool $hotspotAvailable,
        ?string $identity,
        array $board,
        array $clock,
        array $logs
    ): array {
        $r = $resource[0] ?? [];
        $version = RouterosVersion::fromString($r['version'] ?? null);

        $totalMemory = (float) ($r['total-memory'] ?? 0);
        $freeMemory = (float) ($r['free-memory'] ?? 0);
        $totalHdd = (float) ($r['total-hdd-space'] ?? 0);
        $freeHdd = (float) ($r['free-hdd-space'] ?? 0);

        $traffic = 0;

        foreach ($active as $s) {
            $traffic += (int) ($s['bytes-in'] ?? 0) + (int) ($s['bytes-out'] ?? 0);
        }

        $logEntries = [];

        foreach ($logs as $l) {
            $logEntries[] = [
                'id' => $l['.id'] ?? '',
                'time' => $l['time'] ?? '',
                'topics' => $l['topics'] ?? '',
                'message' => $l['message'] ?? '',
            ];
        }

        $cpu = (int) ($r['cpu-load'] ?? 0);

        $clockRecord = $clock[0] ?? [];
        $clockDate = RouterosDate::normalize($clockRecord['date'] ?? null, $version);
        $clockTime = $clockRecord['time'] ?? '';
        $clockTimezone = $clockRecord['time-zone-name'] ?? ($clockRecord['gmt-offset'] ?? '');

        return [
            'demo' => false,
            'router' => [
                'name' => $router['name'],
                'host' => $router['host'],
                'port' => $router['port'],
                'identity' => $identity ?? 'Unknown',
                'model' => $board[0]['model'] ?? ($r['board-name'] ?? '-'),
                'board' => $r['board-name'] ?? '-',
                'clock' => [
                    'datetime' => trim($clockDate . ' ' . $clockTime),
                    'timezone' => $clockTimezone,
                ],
                'version' => $r['version'] ?? '-',
                'versionMajor' => $version?->major(),
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
            'logs' => array_slice(array_reverse($logEntries), 0, 50),
            'hotspotAvailable' => $hotspotAvailable,
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
