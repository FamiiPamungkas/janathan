<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

/* Build a SQLite connection using the same path resolution as the app. */
$configured = (string) ($_ENV['DB_PATH'] ?? 'database/janathan.sqlite');
$dbPath = $configured;
if ($configured !== '' && !preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $configured)) {
    $dbPath = __DIR__ . '/../' . ltrim($configured, '/');
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$crypto = new Fame1302\Janathan\Services\CryptoService((string) ($_ENV['APP_KEY'] ?? ''));
$routers = new Fame1302\Janathan\Services\RouterRepository($pdo, $crypto);
$profiles = new Fame1302\Janathan\Services\HotspotProfileRepository($pdo);
$factory = new Fame1302\Janathan\Services\RouterosClientFactory();
$connections = new Fame1302\Janathan\Services\RouterConnectionManager($factory, $routers);
$service = new Fame1302\Janathan\Services\HotspotService($routers, $connections, $profiles);

/* Parse arguments: <router_id> [<profile>] [--remove] [--interval=60]
 *   <profile>  optional profile name or RouterOS .id to limit the action;
 *              when omitted, every profile with a validity period is handled. */
$routerId = null;
$target = null;
$remove = false;
$interval = 60;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--remove') {
        $remove = true;
        continue;
    }
    if (str_starts_with($arg, '--interval=')) {
        $interval = (int) substr($arg, strlen('--interval='));
        continue;
    }
    if (is_numeric($arg)) {
        $routerId = (int) $arg;
        continue;
    }
    if ($target === null) {
        $target = $arg;
    }
}

if ($routerId === null) {
    fwrite(STDERR, "Usage: php bin/install-expiry-scheduler.php <router_id> [<profile>] [--remove] [--interval=60]\n");
    exit(1);
}

if ($routers->find($routerId) === null) {
    fwrite(STDERR, "Router #{$routerId} not found.\n");
    exit(1);
}

/* Resolve which profiles to act on. */
$todo = [];

if ($target !== null) {
    $match = null;
    foreach ($service->getProfiles($routerId)['profiles'] as $p) {
        if ((string) $p['id'] === $target || (string) $p['name'] === $target) {
            $match = $p;
            break;
        }
    }
    if ($match === null) {
        fwrite(STDERR, "Profile '{$target}' not found on router #{$routerId}.\n");
        exit(1);
    }
    $todo[] = ['id' => (string) $match['id'], 'name' => (string) $match['name']];
} else {
    foreach ($profiles->allForRouter($routerId) as $m) {
        if (!empty($m['validity_days'])) {
            $todo[] = ['id' => (string) $m['profile_id'], 'name' => (string) $m['name']];
        }
    }
}

if ($todo === []) {
    echo $remove
        ? "No expiry schedulers to remove on router #{$routerId}.\n"
        : "No profiles with a validity period found on router #{$routerId}.\n";
    exit(0);
}

$action = $remove ? 'Removing' : 'Installing';
echo "{$action} expiry scheduler for " . count($todo) . " profile(s) on router #{$routerId}...\n";

try {
    foreach ($todo as $p) {
        if ($remove) {
            $service->removeProfileExpiryScheduler($routerId, $p['id']);
        } else {
            $service->installProfileExpiryScheduler($routerId, $p['id'], $p['name'], $interval);
        }
        echo "  - {$p['name']}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo $remove ? "Done.\n" : "Done (runs every {$interval}m).\n";
