<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Tests;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
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
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
            new ProfileMetadataCodec()
        );
    }
}
