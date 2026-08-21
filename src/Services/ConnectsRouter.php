<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Exceptions\RouterosConnectionException;
use RuntimeException;
use Throwable;

/**
 * Shared RouterOS connection plumbing for the hotspot/profile services.
 *
 * Stateless on purpose: the using service owns its {@see RouterRepository},
 * {@see RouterConnectionManager} and {@see HotspotProfileRepository} fields and
 * passes them into these helpers, so this trait carries no state of its own.
 */
trait ConnectsRouter
{
    /**
     * Open (or reuse) the per-request RouterOS connection for a router.
     *
     * @return array{router: array, client: RouterosClient}
     */
    private function connect(RouterRepository $routers, RouterConnectionManager $connections, int $routerId): array
    {
        $router = $routers->find($routerId);

        if ($router === null) {
            throw new RuntimeException('The selected router no longer exists.');
        }

        return [$router, $connections->get($routerId)];
    }

    /**
     * Run a write operation against the router, surfacing connection/command
     * failures as a generic runtime exception.
     *
     * @param RouterRepository $routers
     * @param RouterConnectionManager $connections
     * @param int $routerId
     * @param callable $operation
     * @return mixed
     */
    private function write(RouterRepository $routers, RouterConnectionManager $connections, int $routerId, callable $operation): mixed
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($routers, $connections, $routerId);

        try {
            return $operation($client);
        } catch (RouterosCommandException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }
    }

    private function unreachable(array $router, Throwable $e): RuntimeException
    {
        if ($e instanceof RouterosConnectionException) {
            return new RuntimeException($e->getMessage(), 0, $e);
        }

        return new RuntimeException(
            'Cannot reach router "' . $router['name'] . '" (' . $router['host'] . ').',
            0,
            $e
        );
    }

    /**
     * @return string[]
     */
    private function extractNames(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
                $names[] = $row['name'];
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    private function normalizeValidityDays(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $days = (int)$value;

        return $days > 0 ? $days : null;
    }
}
