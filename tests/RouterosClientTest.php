<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Tests;

use Fame1302\Janathan\Exceptions\RouterosConnectionException;
use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Services\RouterosClient;
use Fame1302\Janathan\Services\RouterosApiClient;
use PHPUnit\Framework\TestCase;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Query;

class RouterosClientTest extends TestCase
{
    private function makeClient(Client $client): RouterosClient
    {
        return new RouterosClient(host: 'test', user: 'u', pass: 'p', client: $client);
    }

    public function testConnectionFailureThrowsRouterosConnectionException(): void
    {
        // Arrange
        $client = $this->createMock(Client::class);
        $client->method('query')->willThrowException(
            new ConnectException('Unable to connect to host:port')
        );
        $sut = $this->makeClient($client);

        // Assert
        $this->expectException(RouterosConnectionException::class);

        // Act
        $sut->getHotspotUsers();
    }

    public function testTimeoutFailureHasTimeoutMessage(): void
    {
        // Arrange
        $client = $this->createMock(Client::class);
        $client->method('query')->willThrowException(
            new ClientException('Socket timeout reached')
        );
        $sut = $this->makeClient($client);

        // Act
        try {
            $sut->getHotspotUsers();
        } catch (RouterosConnectionException $e) {
            // Assert
            $this->assertStringContainsString('timed out', strtolower($e->getMessage()));
            return;
        }

        $this->fail('Expected RouterosConnectionException');
    }

    public function testTrapReplyReturnsEmptyAndUnavailable(): void
    {
        // Arrange
        $response = $this->createMock(Client::class);
        $response->method('read')->willReturn([['message' => 'no such command']]);

        $client = $this->createMock(Client::class);
        $client->method('query')->willReturn($response);

        $sut = $this->makeClient($client);

        // Act
        $users = $sut->getHotspotUsers();

        // Assert
        $this->assertSame([], $users);
        $this->assertFalse($sut->isHotspotAvailable());
    }

    public function testMonitorTrafficUsesRouterosCommandAttributes(): void
    {
        $response = $this->createMock(Client::class);
        $response->method('read')->willReturn([[
            'rx-bits-per-second' => '125000',
            'tx-bits-per-second' => '64000',
        ]]);

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('query')
            ->with($this->callback(static function (Query $query): bool {
                self::assertSame([
                    '/interface/monitor-traffic',
                    '=interface=ether1',
                    '=once',
                ], $query->getQuery());

                return true;
            }))
            ->willReturn($response);

        $result = $this->makeClient($client)->monitorInterfaceTraffic('ether1');

        self::assertSame('125000', $result['rx-bits-per-second']);
        self::assertSame('64000', $result['tx-bits-per-second']);
    }

    public function testInterfaceTrapThrowsCommandException(): void
    {
        $response = $this->createMock(Client::class);
        $response->method('read')->willReturn([['message' => 'not enough permissions']]);

        $client = $this->createMock(Client::class);
        $client->method('query')->willReturn($response);

        $this->expectException(RouterosCommandException::class);

        $this->makeClient($client)->getInterfaces();
    }

    public function testMultilineProfileScriptIsPreserved(): void
    {
        $source = ':if (false) do={' . PHP_EOL
            . '  :put "janathan-profile:v1:test";' . PHP_EOL
            . '};';
        $client = new RouterosApiClient(
            new Config(['host' => 'test', 'user' => 'u', 'pass' => 'p']),
            false
        );

        $result = $client->parseResponse([
            '!re',
            '=name=basic',
            '=on-login=' . $source,
            '!done',
        ]);

        $this->assertSame($source, $result[0]['on-login']);
    }
}
