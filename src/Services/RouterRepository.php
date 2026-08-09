<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use PDO;

class RouterRepository
{
    private const SELECT_COLUMNS = 'id, name, host, port, ssl, username, created_at, updated_at';

    public function __construct(
        private PDO $pdo,
        private CryptoService $crypto
    ) {
    }

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT ' . self::SELECT_COLUMNS . ' FROM routers ORDER BY name')
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM routers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO routers (name, host, port, ssl, username, password_enc)
             VALUES (:name, :host, :port, :ssl, :username, :password_enc)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'ssl' => !empty($data['ssl']) ? 1 : 0,
            'username' => $data['username'],
            'password_enc' => $this->crypto->encrypt($data['password']),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $passwordSql = '';
        $params = [
            'id' => $id,
            'name' => $data['name'],
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'ssl' => !empty($data['ssl']) ? 1 : 0,
            'username' => $data['username'],
        ];

        if (isset($data['password']) && $data['password'] !== '') {
            $passwordSql = ', password_enc = :password_enc';
            $params['password_enc'] = $this->crypto->encrypt($data['password']);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE routers
             SET name = :name, host = :host, port = :port, ssl = :ssl, username = :username'
            . $passwordSql
            . ', updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM routers WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Return connection credentials for a router with the password decrypted.
     */
    public function getCredentials(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, host, port, ssl, username, password_enc FROM routers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['password'] = $this->crypto->decrypt($row['password_enc']);
        unset($row['password_enc']);

        return $row;
    }
}
