<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Tests;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Services\HotspotService;
use Fame1302\Janathan\Services\ProfileMetadataCodec;
use Fame1302\Janathan\Services\ProfileService;
use Fame1302\Janathan\Services\RouterConnectionManager;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\RouterosClient;
use PHPUnit\Framework\TestCase;

final class HotspotLimitsTest extends TestCase
{
    public function testOnlySelectedExistingUsersReceiveActualLimitFields(): void
    {
        $client = $this->createMock(RouterosClient::class);
        $client->method('getHotspotUsers')->willReturn([
            ['.id' => '*1', 'name' => 'one', 'comment' => 'exp=2026-12-01'],
            ['.id' => '*2', 'name' => 'two'],
            ['.id' => '*3', 'name' => 'default-trial'],
        ]);
        $client->expects($this->once())->method('setHotspotUser')->with('*1', [
            'limit-uptime' => '2h', 'limit-bytes-total' => '1610612736',
        ]);
        $result = $this->service($client)->changeUsersLimitsByIds(1, ['*1', '*1', '*3', '*missing'], [
            'limit_uptime' => '2h', 'data_limit' => '1.5', 'data_limit_unit' => 'GB',
        ]);
        self::assertSame(['updated' => 1, 'failed' => 2], $result);
    }

    public function testUnlimitedDataKeepsUptimeAndContinuesAfterCommandFailure(): void
    {
        $client = $this->createMock(RouterosClient::class);
        $client->method('getHotspotUsers')->willReturn([
            ['.id' => '*1', 'name' => 'one'], ['.id' => '*2', 'name' => 'two'],
        ]);
        $client->expects($this->exactly(2))->method('setHotspotUser')->willReturnCallback(
            static function (string $id, array $fields): void {
                self::assertSame(['limit-bytes-total' => '0'], $fields);
                if ($id === '*1') {
                    throw new RouterosCommandException('Rejected');
                }
            }
        );
        self::assertSame(['updated' => 1, 'failed' => 1],
            $this->service($client)->changeUsersLimitsByIds(1, ['*1', '*2'], ['data_limit' => '']));
    }

    private function service(RouterosClient $client): HotspotService
    {
        $routers = $this->createMock(RouterRepository::class);
        $routers->method('find')->willReturn(['id' => 1, 'name' => 'Test', 'host' => '127.0.0.1']);
        $connections = $this->createMock(RouterConnectionManager::class);
        $connections->method('get')->with(1)->willReturn($client);
        return new HotspotService($routers, $connections,
            new ProfileService($routers, $connections, new ProfileMetadataCodec()));
    }
}
