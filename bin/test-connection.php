<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

$host = $_ENV['MIKROTIK_HOST'] ?? '192.168.88.1';
$user = $_ENV['MIKROTIK_USER'] ?? 'admin';
$pass = $_ENV['MIKROTIK_PASS'] ?? '';
$port = (int) ($_ENV['MIKROTIK_PORT'] ?? 8728);
$ssl = filter_var($_ENV['MIKROTIK_SSL'] ?? false, FILTER_VALIDATE_BOOLEAN);

echo "Testing connection to RouterOS at {$host}:{$port}...\n";

try {
    $config = new \RouterOS\Config([
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'port' => $port,
        'ssl' => $ssl,
    ]);

    $client = new \RouterOS\Client($config);
    $result = $client->query(\RouterOS\Query::create('/system/resource/print'))->all();

    echo "Connection successful!\n";
    echo "Router Identity: " . ($result[0]['identity'] ?? 'Unknown') . "\n";
    echo "Version: " . ($result[0]['version'] ?? 'Unknown') . "\n";

    $client->close();
} catch (\Exception $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
    exit(1);
}
