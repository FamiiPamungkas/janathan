<?php

declare(strict_types=1);

/**
 * Reproduces the dashboard's query sequence against a saved router and prints
 * per-query timing + peak memory so connection/timeout/memory issues can be
 * diagnosed from the dev machine (run on Windows via Laragon PHP).
 *
 * Usage:
 *   php bin/test-dashboard-queries.php [routerId] [iterations]
 * If routerId is omitted, the first router in the DB is used.
 */

require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

use DI\ContainerBuilder;
use Fame1302\Janathan\Services\RouterConnectionManager;
use Fame1302\Janathan\Services\RouterRepository;

$container = (new ContainerBuilder())
    ->addDefinitions(__DIR__ . '/../config/container.php')
    ->build();

$repo = $container->get(RouterRepository::class);
$manager = $container->get(RouterConnectionManager::class);
$pdo = $container->get(PDO::class);

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    $row = $pdo->query('SELECT id, name, host FROM routers ORDER BY id LIMIT 1')->fetch();
    if ($row === false) {
        fwrite(STDERR, "No routers found in the database.\n");
        exit(1);
    }
    $id = (int) $row['id'];
    echo "Using router #{$id} ({$row['name']} @ {$row['host']})\n";
}

$iterations = (int) ($argv[2] ?? 5);

$queries = [
    'users'    => static fn ($c) => $c->getHotspotUsers(),
    'clock'    => static fn ($c) => $c->getClock(),
    'resource' => static fn ($c) => $c->getSystemResource(),
    'board'    => static fn ($c) => $c->getRouterBoard(),
    'active'   => static fn ($c) => $c->getActiveUsers(),
    'identity' => static fn ($c) => $c->getIdentity(),
    'logs'     => static fn ($c) => $c->getHotspotLogs(),
];

echo "Iterations: {$iterations}\n";
echo str_repeat('-', 70) . "\n";

for ($i = 1; $i <= $iterations; $i++) {
    echo "--- iteration {$i} ---\n";
    $client = $manager->get($id);
    $peakStart = memory_get_peak_usage(true);

    foreach ($queries as $name => $call) {
        $t0 = microtime(true);
        $ok = true;
        $detail = '';
        try {
            $result = $call($client);
            if (is_array($result)) {
                $detail = 'rows=' . count($result);
            } elseif (is_string($result) || $result === null) {
                $detail = 'val=' . (is_string($result) ? $result : 'null');
            } else {
                $detail = gettype($result);
            }
        } catch (Throwable $e) {
            $ok = false;
            $detail = get_class($e) . ': ' . $e->getMessage();
        }
        $ms = round((microtime(true) - $t0) * 1000, 1);
        printf("  %-10s %-4s %8.1f ms  %s\n", $name, $ok ? 'OK ' : 'ERR', $ms, $detail);
    }

    try {
        $available = $client->isHotspotAvailable();
        printf("  %-10s OK   -          hotspotAvailable=%s\n", 'hotspot', $available ? 'true' : 'false');
    } catch (Throwable $e) {
        printf("  %-10s ERR  -          %s\n", 'hotspot', $e->getMessage());
    }

    $peak = memory_get_peak_usage(true) - $peakStart;
    echo "  peak memory this iteration: " . round($peak / 1024 / 1024, 2) . " MB\n";
}

echo str_repeat('-', 70) . "\n";
echo "Done.\n";
