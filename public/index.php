<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$dbPath = (string) config('DB_PATH', 'database/janathan.sqlite');
if ($dbPath !== '' && !preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $dbPath)) {
    $dbPath = __DIR__ . '/../' . ltrim($dbPath, '/');
}

$needsSetup = false;

if (!file_exists($dbPath)) {
    $needsSetup = true;
} else {
    $pdo = new PDO('sqlite:' . $dbPath);
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
    if (!$tableExists) {
        $needsSetup = true;
    } else {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $needsSetup = ($count === 0);
    }
}

$app = (require __DIR__ . '/../config/bootstrap.php')($needsSetup);
$app->run();
