<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use PDO;

class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, name, role, locale, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function updateLocale(int $id, string $locale): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET locale = :locale WHERE id = :id');
        $stmt->execute(['id' => $id, 'locale' => $locale]);
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function usernameExists(string $username, int $exceptId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE username = :username AND id != :id LIMIT 1');
        $stmt->execute(['username' => $username, 'id' => $exceptId]);

        return $stmt->fetchColumn() !== false;
    }

    public function updateProfile(int $id, array $data): void
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['username', 'locale', 'password_hash'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if ($fields === []) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function create(string $username, string $passwordHash, string $name = '', string $role = 'admin'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, name, role) VALUES (:username, :password_hash, :name, :role)'
        );
        $stmt->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
            'name' => $name,
            'role' => $role,
        ]);
    }
}
