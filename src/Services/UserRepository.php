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
        $stmt = $this->pdo->prepare('SELECT id, username, name, role, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
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
