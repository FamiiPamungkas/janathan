<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Tests;

use Fame1302\Janathan\Exceptions\RouterosConnectionException;
use Fame1302\Janathan\Services\RouterosClient;
use PHPUnit\Framework\TestCase;
use RouterOS\Client;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConnectException;

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
}
