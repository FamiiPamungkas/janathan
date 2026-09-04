<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\FlashService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Twig\Environment;

class SetupController
{
    public function __construct(
        private Environment $twig,
        private FlashService $flash
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $html = $this->twig->render('pages/setup.twig');
        $response->getBody()->write($html);

        return $response;
    }

    public function install(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');

        $errors = [];

        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
            $errors['username'] = 'Username may only contain letters, numbers, dots, hyphens, and underscores.';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 4) {
            $errors['password'] = 'Password must be at least 4 characters.';
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            $html = $this->twig->render('pages/setup.twig', [
                'errors' => $errors,
                'values' => ['username' => $username],
            ]);
            $response->getBody()->write($html);

            return $response;
        }

        $dbPath = dirname(__DIR__, 2) . '/database/janathan.sqlite';
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        try {
            $pdo = new \PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');

            $this->createSchema($pdo);

            $appKey = bin2hex(random_bytes(32));
            $insertKey = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)');
            $insertKey->execute(['key' => 'APP_KEY', 'value' => $appKey]);

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insertUser = $pdo->prepare(
                'INSERT INTO users (username, password_hash, name, role) VALUES (:username, :password_hash, :name, :role)'
            );
            $insertUser->execute([
                'username' => $username,
                'password_hash' => $hash,
                'name' => $username,
                'role' => 'admin',
            ]);
        } catch (\Throwable $e) {
            $this->flash->add('error', 'Database error: ' . $e->getMessage());

            return $this->redirect($response, 'setup.show');
        }

        $url = '/';

        $response = $response->withHeader('Location', $url)->withStatus(302);

        return $response;
    }

    private function redirect(Response $response, string $route): Response
    {
        return $response->withHeader('Location', '/setup')->withStatus(302);
    }

    private function createSchema(\PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                name          TEXT NOT NULL DEFAULT '',
                role          TEXT NOT NULL DEFAULT 'admin',
                locale        TEXT NOT NULL DEFAULT 'en',
                created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS routers (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          TEXT NOT NULL,
                host          TEXT NOT NULL,
                port          INTEGER NOT NULL DEFAULT 8728,
                ssl           INTEGER NOT NULL DEFAULT 0,
                username      TEXT NOT NULL,
                password_enc  TEXT NOT NULL,
                hotspot_name  TEXT NOT NULL DEFAULT '',
                dns_name      TEXT NOT NULL DEFAULT '',
                currency      TEXT NOT NULL DEFAULT 'IDR',
                created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );

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

            CREATE TABLE IF NOT EXISTS voucher_templates (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL UNIQUE,
                header     TEXT NOT NULL DEFAULT '',
                row        TEXT NOT NULL DEFAULT '',
                footer     TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS settings (
                key         TEXT PRIMARY KEY,
                value       TEXT NOT NULL,
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            SQL
        );
    }
}
