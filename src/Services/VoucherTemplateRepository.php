<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use PDO;

class VoucherTemplateRepository
{
    private const SELECT_COLUMNS = 'id, name, header, row, footer, created_at, updated_at';

    public const DEFAULT_TEMPLATE_PATH = __DIR__ . '/../../resources/voucher_template.html';

    public const DEFAULT_ID = 0;

    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * The built-in default template: a read-only virtual record backed by
     * resources/voucher_template.html. Never stored in the database.
     *
     * @return array{id: int, name: string, is_default: bool}
     */
    public function default(): array
    {
        return [
            'id' => self::DEFAULT_ID,
            'name' => 'Default',
            'is_default' => true,
        ];
    }

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT ' . self::SELECT_COLUMNS . ' FROM voucher_templates ORDER BY name')
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM voucher_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO voucher_templates (name, header, row, footer)
             VALUES (:name, :header, :row, :footer)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'header' => $data['header'],
            'row' => $data['row'],
            'footer' => $data['footer'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE voucher_templates
             SET name = :name, header = :header, row = :row, footer = :footer,
                 updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'header' => $data['header'],
            'row' => $data['row'],
            'footer' => $data['footer'],
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM voucher_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}