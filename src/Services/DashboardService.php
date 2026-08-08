<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

class DashboardService
{
    /**
     * Return data for the dashboard home page.
     *
     * Currently returns placeholder data so the UI can be built and reviewed
     * without a reachable router. Swap these values for RouterOS API calls
     * (RouterosClient) when live data is wired up.
     */
    public function getDashboardData(): array
    {
        return [
            'demo' => true,

            'router' => [
                'identity' => 'HomeLab Router',
                'model' => 'MikroTik RB951Ui-2HnD',
                'version' => 'RouterOS 6.49.10 (stable)',
                'uptime' => '3d 14h 22m',
                'cpu' => 32,
                'memory' => 64,
                'hdd' => 50,
                'freeMemory' => '48.1 MiB',
                'totalMemory' => '128 MiB',
                'freeHdd' => '64.2 MiB',
                'totalHdd' => '128 MiB',
            ],

            'stats' => [
                'activeSessions' => 24,
                'totalUsers' => 148,
                'todayConnections' => 86,
                'trafficToday' => '4.2 GB',
            ],

            'sessions' => [
                [
                    'id' => 1,
                    'user' => 'budi',
                    'ip' => '192.168.88.24',
                    'mac' => '8C:89:A5:12:34:01',
                    'uptime' => '2h 14m',
                    'download' => '1.2 GB',
                    'upload' => '384 MB',
                    'server' => 'hotspot1',
                    'status' => 'Active',
                ],
                [
                    'id' => 2,
                    'user' => 'sinta',
                    'ip' => '192.168.88.31',
                    'mac' => '4C:5E:0C:AB:12:34',
                    'uptime' => '1h 02m',
                    'download' => '612 MB',
                    'upload' => '205 MB',
                    'server' => 'hotspot1',
                    'status' => 'Active',
                ],
                [
                    'id' => 3,
                    'user' => 'agus',
                    'ip' => '192.168.88.12',
                    'mac' => 'D8:6C:63:44:55:66',
                    'uptime' => '45m',
                    'download' => '356 MB',
                    'upload' => '128 MB',
                    'server' => 'hotspot1',
                    'status' => 'Active',
                ],
                [
                    'id' => 4,
                    'user' => 'rina',
                    'ip' => '192.168.88.40',
                    'mac' => '5C:CF:7F:12:34:56',
                    'uptime' => '3h 40m',
                    'download' => '2.4 GB',
                    'upload' => '810 MB',
                    'server' => 'hotspot1',
                    'status' => 'Active',
                ],
                [
                    'id' => 5,
                    'user' => 'tono',
                    'ip' => '192.168.88.18',
                    'mac' => '00:0C:29:AA:BB:CC',
                    'uptime' => '12m',
                    'download' => '42 MB',
                    'upload' => '18 MB',
                    'server' => 'hotspot1',
                    'status' => 'Active',
                ],
                [
                    'id' => 6,
                    'user' => 'dewi',
                    'ip' => '192.168.88.27',
                    'mac' => '3C:6A:9D:87:65:43',
                    'uptime' => '5h 12m',
                    'download' => '3.1 GB',
                    'upload' => '1.1 GB',
                    'server' => 'hotspot1',
                    'status' => 'Active',
                ],
                [
                    'id' => 7,
                    'user' => 'joko',
                    'ip' => '192.168.88.55',
                    'mac' => '9C:EB:E8:11:22:33',
                    'uptime' => '30m',
                    'download' => '210 MB',
                    'upload' => '96 MB',
                    'server' => 'hotspot1',
                    'status' => 'Active',
                ],
                [
                    'id' => 8,
                    'user' => 'maya',
                    'ip' => '192.168.88.9',
                    'mac' => '34:23:87:AA:BB:CC',
                    'uptime' => '1h 55m',
                    'download' => '890 MB',
                    'upload' => '310 MB',
                    'server' => 'hotspot1',
                    'status' => 'Active',
                ],
            ],
        ];
    }
}
