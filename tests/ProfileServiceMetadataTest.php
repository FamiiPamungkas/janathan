<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Tests;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Services\HotspotProfileRepository;
use Fame1302\Janathan\Services\ProfileMetadataCodec;
use Fame1302\Janathan\Services\ProfileService;
use Fame1302\Janathan\Services\RouterConnectionManager;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\RouterosClient;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProfileServiceMetadataTest extends TestCase
{
    public function testEmbeddedMetadataIsReadFromMultilineLoginScript(): void
    {
        $pdo = $this->makePdo();
        $codec = new ProfileMetadataCodec();
        $source = str_replace(
            ['{ :put ', '; };'],
            ['{' . PHP_EOL . '  :put ', ';' . PHP_EOL . '};'],
            $codec->encode([
                'color' => '#14b8a6',
                'price' => 25000,
                'prefix' => 'vch',
                'validity_days' => 14,
                'start_on' => 'first_login',
            ])
        );

        $client = $this->createMock(RouterosClient::class);
        $client->method('getHotspotProfiles')->willReturn([
            ['.id' => '*1', 'name' => 'basic', 'on-login' => $source],
        ]);
        $client->method('isHotspotAvailable')->willReturn(true);

        $result = $this->makeService($pdo, $client)->getProfiles(1);

        $this->assertSame('#14b8a6', $result['profiles'][0]['color']);
        $this->assertSame(25000.0, $result['profiles'][0]['price']);
        $this->assertSame('vch', $result['profiles'][0]['prefix']);
        $this->assertSame(14, $result['profiles'][0]['validity_days']);
        $this->assertSame('first_login', $result['profiles'][0]['start_on']);
    }

    public function testLegacyMetadataIsWrittenToRouterAndRemovedLocally(): void
    {
        $pdo = $this->makePdo();
        $repository = new HotspotProfileRepository($pdo);
        $repository->upsert(1, '*legacy', 'basic', '#ef4444', 10000, 'old', 30, 'user_creation');

        $codec = new ProfileMetadataCodec();
        $client = $this->createMock(RouterosClient::class);
        $client->method('getHotspotProfiles')->willReturn([
            ['.id' => '*1', 'name' => 'basic', 'rate-limit' => '10M/10M', 'shared-users' => '1', 'on-login' => ''],
        ]);
        $client->method('isHotspotAvailable')->willReturn(true);
        $client->expects($this->once())
            ->method('setHotspotProfile')
            ->with('*1', $this->callback(function (array $fields) use ($codec): bool {
                return $codec->decode((string)($fields['on-login'] ?? '')) === [
                    'color' => '#ef4444',
                    'price' => 10000.0,
                    'prefix' => 'old',
                    'validity_days' => 30,
                    'start_on' => 'user_creation',
                ];
            }));

        $service = $this->makeService($pdo, $client);
        $result = $service->getProfiles(1);

        $this->assertSame('#ef4444', $result['profiles'][0]['color']);
        $this->assertSame(10000.0, $result['profiles'][0]['price']);
        $this->assertSame('old', $result['profiles'][0]['prefix']);
        $this->assertSame(30, $result['profiles'][0]['validity_days']);
        $this->assertSame('user_creation', $result['profiles'][0]['start_on']);
        $this->assertNull($repository->findByName(1, 'basic'));
    }

    public function testLegacyMetadataRemainsAvailableWhenRouterRejectsMigration(): void
    {
        $pdo = $this->makePdo();
        $repository = new HotspotProfileRepository($pdo);
        $repository->upsert(1, '*1', 'basic', '#ef4444', 10000, 'old', null, 'first_login');

        $client = $this->createMock(RouterosClient::class);
        $client->method('getHotspotProfiles')->willReturn([
            ['.id' => '*1', 'name' => 'basic', 'on-login' => ''],
        ]);
        $client->method('isHotspotAvailable')->willReturn(true);
        $client->expects($this->once())
            ->method('setHotspotProfile')
            ->willThrowException(new RouterosCommandException('permission denied'));

        $service = $this->makeService($pdo, $client);
        $result = $service->getProfiles(1);

        $this->assertSame('#ef4444', $result['profiles'][0]['color']);
        $this->assertSame('old', $result['profiles'][0]['prefix']);
        $this->assertSame(10000.0, $result['profiles'][0]['price']);
        $this->assertNotNull($repository->findByName(1, 'basic'));
    }

    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE hotspot_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                router_id INTEGER NOT NULL,
                profile_id TEXT NOT NULL,
                name TEXT NOT NULL,
                color TEXT NOT NULL DEFAULT \'\',
                price REAL NOT NULL DEFAULT 0,
                prefix TEXT NOT NULL DEFAULT \'\',
                validity_days INTEGER,
                start_on TEXT NOT NULL DEFAULT \'first_login\',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (router_id, profile_id)
            )'
        );

        return $pdo;
    }

    private function makeService(PDO $pdo, RouterosClient $client): ProfileService
    {
        $routers = $this->createMock(RouterRepository::class);
        $routers->method('find')->with(1)->willReturn([
            'id' => 1,
            'name' => 'Test router',
            'host' => '127.0.0.1',
        ]);

        $connections = $this->createMock(RouterConnectionManager::class);
        $connections->method('get')->with(1)->willReturn($client);

        return new ProfileService(
            $routers,
            $connections,
            new HotspotProfileRepository($pdo),
            new ProfileMetadataCodec()
        );
    }
}
