<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$configured = config('DB_PATH', 'database/janathan.sqlite');
$dbPath = $configured;

if ($configured !== '' && !preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $configured)) {
    $dbPath = __DIR__ . '/../' . ltrim($configured, '/');
}

if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$pdo = new \PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

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

/* Migrate existing databases that predate the app preferences columns. */
$existingColumns = [];
foreach ($pdo->query('PRAGMA table_info(routers)') as $col) {
    $existingColumns[] = $col['name'];
}

$migrations = [
    'hotspot_name' => 'TEXT NOT NULL DEFAULT \'\'',
    'dns_name'     => 'TEXT NOT NULL DEFAULT \'\'',
    'currency'     => 'TEXT NOT NULL DEFAULT \'IDR\'',
];

foreach ($migrations as $column => $definition) {
    if (!in_array($column, $existingColumns, true)) {
        $pdo->exec("ALTER TABLE routers ADD COLUMN {$column} {$definition}");
    }
}

$userColumns = [];
foreach ($pdo->query('PRAGMA table_info(users)') as $col) {
    $userColumns[] = $col['name'];
}

if (!in_array('locale', $userColumns, true)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'");
}

/* Migrate existing databases that predate profile validity settings. */
$profileColumns = [];
foreach ($pdo->query('PRAGMA table_info(hotspot_profiles)') as $col) {
    $profileColumns[] = $col['name'];
}

$profileMigrations = [
    'validity_days' => 'INTEGER',
    'start_on' => "TEXT NOT NULL DEFAULT 'first_login'",
];

foreach ($profileMigrations as $column => $definition) {
    if (!in_array($column, $profileColumns, true)) {
        $pdo->exec("ALTER TABLE hotspot_profiles ADD COLUMN {$column} {$definition}");
    }
}

$stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key');
$stmt->execute(['key' => 'APP_KEY']);
if ($stmt->fetchColumn() === false) {
    $appKey = bin2hex(random_bytes(32));
    $insert = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)');
    $insert->execute(['key' => 'APP_KEY', 'value' => $appKey]);
    echo "Generated APP_KEY and stored in database.\n";
}

echo "Database ready at {$dbPath}\n";

$username = $argv[1] ?? 'janathan';

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
$stmt->execute(['username' => $username]);

if ($stmt->fetch() !== false) {
    echo "User '{$username}' already exists. Nothing to do.\n";
    exit(0);
}

$password = $argv[2] ?? '';

if ($password === '') {
    echo "Enter a password for '{$username}': ";
    $password = trim(fgets(STDIN));
}

if ($password === '') {
    echo "Password cannot be empty.\n";
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$insert = $pdo->prepare('INSERT INTO users (username, password_hash, name, role) VALUES (:username, :password_hash, :name, :role)');
$insert->execute([
    'username' => $username,
    'password_hash' => $hash,
    'name' => $username,
    'role' => 'admin',
]);

echo "Created admin user '{$username}'.\n";
echo "You can now log in at the application login page.\n";
