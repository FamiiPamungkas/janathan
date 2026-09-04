<?php

declare(strict_types=1);

use Fame1302\Janathan\Services\CryptoService;
use Fame1302\Janathan\Services\HotspotProfileRepository;
use Fame1302\Janathan\Services\RouterConnectionManager;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\RouterosClientFactory;
use Fame1302\Janathan\Services\SettingsRepository;
use Fame1302\Janathan\Services\UserRepository;
use Fame1302\Janathan\Services\VoucherTemplateRepository;
use Fame1302\Janathan\Services\VoucherTemplateRenderer;
use Psr\Container\ContainerInterface;

$shared = require __DIR__ . '/container-shared.php';

return array_merge($shared, [
    PDO::class => function (ContainerInterface $container) {
        $configured = (string) config('DB_PATH', 'database/janathan.sqlite');
        $path = $configured;

        if ($configured !== '' && !preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $configured)) {
            $path = __DIR__ . '/../' . ltrim($configured, '/');
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS hotspot_profiles (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                router_id     INTEGER NOT NULL,
                profile_id    TEXT NOT NULL,
                name          TEXT NOT NULL,
                color         TEXT NOT NULL DEFAULT '',
                price         REAL NOT NULL DEFAULT 0,
                prefix        TEXT NOT NULL DEFAULT '',
                validity_days INTEGER,
                start_on      TEXT NOT NULL DEFAULT 'first_login',
                created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (router_id, profile_id)
            );
            SQL
        );

        $columns = $pdo->query("PRAGMA table_info(hotspot_profiles)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('prefix', $columns, true)) {
            $pdo->exec('ALTER TABLE hotspot_profiles ADD COLUMN prefix TEXT NOT NULL DEFAULT \'\'');
        }

        $profileColumns = $pdo->query("PRAGMA table_info(hotspot_profiles)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('validity_days', $profileColumns, true)) {
            $pdo->exec('ALTER TABLE hotspot_profiles ADD COLUMN validity_days INTEGER');
        }
        if (!in_array('start_on', $profileColumns, true)) {
            $pdo->exec('ALTER TABLE hotspot_profiles ADD COLUMN start_on TEXT NOT NULL DEFAULT \'first_login\'');
        }

        $userColumns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('locale', $userColumns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'");
        }

        $pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (
                key         TEXT PRIMARY KEY,
                value       TEXT NOT NULL,
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            SQL
        );

        return $pdo;
    },

    SettingsRepository::class => function (ContainerInterface $container) {
        return new SettingsRepository($container->get(PDO::class));
    },

    CryptoService::class => function (ContainerInterface $container) {
        $appKey = '';

        try {
            $pdo = $container->get(PDO::class);
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='settings'");
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key');
                $stmt->execute(['key' => 'APP_KEY']);
                $appKey = $stmt->fetchColumn() ?: '';
            }
        } catch (\Throwable) {
            // settings table may not exist during early boot
        }

        if ($appKey === '') {
            $appKey = (string) config('APP_KEY', '');
        }

        return new CryptoService($appKey);
    },

    UserRepository::class => function (ContainerInterface $container) {
        return new UserRepository($container->get(PDO::class));
    },

    RouterRepository::class => function (ContainerInterface $container) {
        return new RouterRepository($container->get(PDO::class), $container->get(CryptoService::class));
    },

    VoucherTemplateRepository::class => function (ContainerInterface $container) {
        return new VoucherTemplateRepository($container->get(PDO::class));
    },

    VoucherTemplateRenderer::class => fn (ContainerInterface $container) => new VoucherTemplateRenderer(),

    HotspotProfileRepository::class => function (ContainerInterface $container) {
        return new HotspotProfileRepository($container->get(PDO::class));
    },

    RouterosClientFactory::class => fn (ContainerInterface $container) => new RouterosClientFactory(),

    RouterConnectionManager::class => fn (ContainerInterface $container) => new RouterConnectionManager(
        $container->get(RouterosClientFactory::class),
        $container->get(RouterRepository::class)
    ),
]);
