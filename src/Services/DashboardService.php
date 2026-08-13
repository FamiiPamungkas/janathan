<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Models\RouterosVersion;
use Throwable;

readonly class DashboardService
{
    public function __construct(
        private RouterRepository      $routers,
        private RouterosClientFactory $clientFactory
    )
    {
    }

    /**
     * @return array{demo: bool, router: array, stats: array, logs: array, hotspotAvailable: bool}
     */
    public function getDashboardData(int $routerId): array
    {
        $data = $this->collect($routerId, withLogs: true);

        return $this->buildData(
            $data['router'],
            $data['resource'],
            $data['active'],
            $data['users'],
            $data['hotspotAvailable'],
            $data['identity'],
            $data['board'],
            $data['clock'],
            $data['logs']
        );
    }

    /**
     * Lightweight payload for the polling `/dashboard/data` endpoint — skips
     * the expensive system log query so it can refresh quickly and stay
     * responsive even on weak router hardware.
     *
     * @return array{demo: bool, router: array, stats: array, logs: array, hotspotAvailable: bool}
     */
    public function getStatsData(int $routerId): array
    {
        $data = $this->collect($routerId, withLogs: false);

        return $this->buildData(
            $data['router'],
            $data['resource'],
            $data['active'],
            $data['users'],
            $data['hotspotAvailable'],
            $data['identity'],
            $data['board'],
            $data['clock'],
            $data['logs']
        );
    }

    /**
     * Logs-only payload for `/dashboard/logs`. `ok` tells the client whether
     * the query succeeded, so a genuine empty result is distinguishable from a
     * transient failure (which must not clear the logs already on screen).
     *
     * @return array{logs: array, ok: bool}
     */
    public function getLogsData(int $routerId): array
    {
        /** @var $client RouterosClient */
        [, $client] = $this->connect($routerId);

        try {
            $logs = $client->getHotspotLogs();
        } catch (Throwable $e) {
            return ['logs' => [], 'ok' => false];
        } finally {
            $client->disconnect();
        }

        return ['logs' => $this->buildHotspotLogs($logs), 'ok' => true];
    }

    /**
     * @return array{router: array, resource: array, active: array, users: array, clock: array, board: array, identity: ?string, logs: array, hotspotAvailable: bool}
     */
    private function collect(int $routerId, bool $withLogs): array
    {
        [$router, $client] = $this->connect($routerId);

        $hotspotAvailable = false;

        try {
            $users = $client->getHotspotUsers();
            $clock = $client->getClock();
            $resource = $client->getSystemResource();
            $board = $client->getRouterBoard();
            $active = $client->getActiveUsers();
            $identity = $client->getIdentity();
            $logs = $withLogs ? $client->getHotspotLogs() : [];
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        return [
            'router' => $router,
            'resource' => $resource,
            'active' => $active,
            'users' => $users,
            'clock' => $clock,
            'board' => $board,
            'identity' => $identity,
            'logs' => $logs,
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    /**
     * @return array{router: array, client: RouterosClient}
     */
    private function connect(int $routerId): array
    {
        $router = $this->routers->find($routerId);

        if ($router === null) {
            throw new \RuntimeException('The selected router no longer exists.');
        }

        return [$router, $this->clientFactory->create($this->routers->getCredentials($routerId))];
    }

    private function unreachable(array $router, Throwable $e): \RuntimeException
    {
        return new \RuntimeException(
            'Cannot reach router "' . $router['name'] . '" (' . $router['host'] . ').',
            0,
            $e
        );
    }

    private function buildData(
        array   $router,
        array   $resource,
        array   $active,
        array   $users,
        bool    $hotspotAvailable,
        ?string $identity,
        array   $board,
        array   $clock,
        array   $logs
    ): array
    {
        $r = $resource[0] ?? [];
        $version = RouterosVersion::fromString($r['version'] ?? null);

        $totalMemory = (float)($r['total-memory'] ?? 0);
        $freeMemory = (float)($r['free-memory'] ?? 0);
        $totalHdd = (float)($r['total-hdd-space'] ?? 0);
        $freeHdd = (float)($r['free-hdd-space'] ?? 0);

        $traffic = 0;

        foreach ($active as $s) {
            $traffic += (int)($s['bytes-in'] ?? 0) + (int)($s['bytes-out'] ?? 0);
        }

        $cpu = (int)($r['cpu-load'] ?? 0);

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
                'memory' => $totalMemory > 0 ? (int)round((($totalMemory - $freeMemory) / $totalMemory) * 100) : 0,
                'hdd' => $totalHdd > 0 ? (int)round((($totalHdd - $freeHdd) / $totalHdd) * 100) : 0,
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
            'logs' => $this->buildHotspotLogs($logs),
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    /**
     * Normalize RouterOS log records and return them newest-first, capped at 50.
     */
    private function buildHotspotLogs(array $logs): array
    {
        $entries = [];

        foreach ($logs as $l) {
            if (str_starts_with($l['message'], "->")) {
                $mess = explode(":", $l['message']);
                $user_ip = $mess[1];
                $message = ($mess[2] ?? "") . " " . ($mess[3] ?? "") . " " . ($mess[4] ?? "") . " " . ($mess[5] ?? "");
                if (count($mess) > 6) {
                    $user_ip = $mess[1] . ":" . $mess[2] . ":" . $mess[3] . ":" . $mess[4] . ":" . $mess[5] . ":" . $mess[6];
                    $message = ($mess[7] ?? "") . " " . ($mess[8] ?? "") . " " . ($mess[9] ?? "") . " " . ($mess[10] ?? "");
                }

                $message = str_replace("trying to", "", $message);
                $entries[] = [
                    'id' => $l['.id'] ?? '',
                    'time' => $l['time'] ?? '',
                    'topics' => $l['topics'] ?? '',
                    'user' => $user_ip,
                    'message' => $message,
                ];
            }
        }

        return array_slice(array_reverse($entries), 0, 50);
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

        return (string)(int)$bytes . ' B';
    }
}
