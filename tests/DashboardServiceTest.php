<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Tests;

use Fame1302\Janathan\Services\DashboardService;
use Fame1302\Janathan\Services\RouterConnectionManager;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\RouterosClient;
use PHPUnit\Framework\TestCase;

final class DashboardServiceTest extends TestCase
{
    public function testInitialDataExposesOnlyInterfaceSelectionFields(): void
    {
        $client = $this->baseClient();
        $client->method('getInterfaces')->willReturn([
            [
                'name' => 'ether1',
                'type' => 'ether',
                'running' => 'true',
                'disabled' => 'false',
                'mac-address' => '00:11:22:33:44:55',
            ],
            ['name' => '', 'type' => 'bridge'],
        ]);

        $data = $this->service($client)->getDashboardData(1);

        self::assertSame([[
            'name' => 'ether1',
            'type' => 'ether',
            'running' => true,
            'disabled' => false,
        ]], $data['interfaces']);
        self::assertTrue($data['interfacesAvailable']);
        self::assertSame(1, $data['router']['id']);
        self::assertFalse($data['trafficSpeed']['available']);
    }

    public function testInterfaceDiscoveryFailureIsDistinguishableFromAnEmptyList(): void
    {
        $client = $this->baseClient();
        $client->method('getInterfaces')->willThrowException(new \RuntimeException('Denied'));

        $data = $this->service($client)->getDashboardData(1);

        self::assertSame([], $data['interfaces']);
        self::assertFalse($data['interfacesAvailable']);
    }

    public function testStatsIncludeSelectedInterfaceTrafficSpeed(): void
    {
        $client = $this->baseClient();
        $client->expects($this->once())
            ->method('monitorInterfaceTraffic')
            ->with('bridge-hotspot')
            ->willReturn([
                'rx-bits-per-second' => '2500000',
                'tx-bits-per-second' => '750000',
            ]);

        $data = $this->service($client)->getStatsData(1, 'bridge-hotspot');

        self::assertSame([
            'interface' => 'bridge-hotspot',
            'available' => true,
            'rxBitsPerSecond' => 2500000,
            'txBitsPerSecond' => 750000,
        ], $data['trafficSpeed']);
    }

    private function baseClient(): RouterosClient
    {
        $client = $this->createMock(RouterosClient::class);
        $client->method('getHotspotUsers')->willReturn([]);
        $client->method('getClock')->willReturn([]);
        $client->method('getSystemResource')->willReturn([]);
        $client->method('getRouterBoard')->willReturn([]);
        $client->method('getActiveUsers')->willReturn([]);
        $client->method('getIdentity')->willReturn('Test router');
        $client->method('getHotspotLogs')->willReturn([]);
        $client->method('isHotspotAvailable')->willReturn(true);

        return $client;
    }

    private function service(RouterosClient $client): DashboardService
    {
        $routers = $this->createMock(RouterRepository::class);
        $routers->method('find')->with(1)->willReturn([
            'id' => 1,
            'name' => 'Test router',
            'host' => '127.0.0.1',
            'port' => 8728,
        ]);

        $connections = $this->createMock(RouterConnectionManager::class);
        $connections->method('get')->with(1)->willReturn($client);

        return new DashboardService($routers, $connections);
    }
}
