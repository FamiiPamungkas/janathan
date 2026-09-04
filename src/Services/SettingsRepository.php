<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use PDO;

class SettingsRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : null;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, datetime(\'now\'))
             ON CONFLICT(key) DO UPDATE SET value = :value2, updated_at = datetime(\'now\')'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $value,
            'value2' => $value,
        ]);
    }
}
