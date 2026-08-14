<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use PDO;

/**
 * Local metadata (color, price) for MikroTik hotspot user profiles.
 *
 * RouterOS has no place for these fields, so they live in SQLite keyed by
 * (router_id, profile_id), where profile_id is the RouterOS `.id`. The name
 * is stored alongside as a fallback matcher: rows self-heal when a profile
 * is renamed (matched by id) or when `.id` values change after a backup
 * restore / netinstall (matched by name).
 */
class HotspotProfileRepository
{
    private const SELECT_COLUMNS = 'id, router_id, profile_id, name, color, price, created_at, updated_at';

    public function __construct(private PDO $pdo)
    {
    }

    public function findByProfileId(int $routerId, string $profileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM hotspot_profiles
             WHERE router_id = :router_id AND profile_id = :profile_id'
        );
        $stmt->execute(['router_id' => $routerId, 'profile_id' => $profileId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByName(int $routerId, string $name): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM hotspot_profiles
             WHERE router_id = :router_id AND name = :name'
        );
        $stmt->execute(['router_id' => $routerId, 'name' => $name]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allForRouter(int $routerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM hotspot_profiles
             WHERE router_id = :router_id'
        );
        $stmt->execute(['router_id' => $routerId]);

        return $stmt->fetchAll();
    }

    public function upsert(int $routerId, string $profileId, string $name, string $color, float $price): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hotspot_profiles (router_id, profile_id, name, color, price)
             VALUES (:router_id, :profile_id, :name, :color, :price)
             ON CONFLICT (router_id, profile_id) DO UPDATE SET
                 name = excluded.name,
                 color = excluded.color,
                 price = excluded.price,
                 updated_at = datetime(\'now\')'
        );
        $stmt->execute([
            'router_id' => $routerId,
            'profile_id' => $profileId,
            'name' => $name,
            'color' => $color,
            'price' => $price,
        ]);
    }

    /**
     * Bring a stored row in line with what the router currently reports:
     * a renamed profile keeps its id, a restored profile keeps its name.
     * If another row already tracks the given profile_id, the duplicate is
     * dropped instead of causing a unique-constraint violation.
     */
    public function heal(int $metaId, int $routerId, string $profileId, string $name): void
    {
        $check = $this->pdo->prepare(
            'SELECT id FROM hotspot_profiles
             WHERE router_id = :router_id AND profile_id = :profile_id AND id != :id'
        );
        $check->execute(['router_id' => $routerId, 'profile_id' => $profileId, 'id' => $metaId]);

        if ($check->fetch() !== false) {
            $delete = $this->pdo->prepare('DELETE FROM hotspot_profiles WHERE id = :id');
            $delete->execute(['id' => $metaId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE hotspot_profiles
             SET profile_id = :profile_id, name = :name, updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute(['profile_id' => $profileId, 'name' => $name, 'id' => $metaId]);
    }

    public function delete(int $routerId, string $profileId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM hotspot_profiles WHERE router_id = :router_id AND profile_id = :profile_id'
        );
        $stmt->execute(['router_id' => $routerId, 'profile_id' => $profileId]);
    }

    /**
     * Remove metadata whose profile no longer exists on the router.
     *
     * @param int[] $keepMetaIds Primary keys of rows matched during the merge.
     */
    public function deleteUnmatched(int $routerId, array $keepMetaIds): void
    {
        if ($keepMetaIds === []) {
            $stmt = $this->pdo->prepare('DELETE FROM hotspot_profiles WHERE router_id = :router_id');
            $stmt->execute(['router_id' => $routerId]);

            return;
        }

        $placeholders = implode(',', array_fill(0, count($keepMetaIds), '?'));
        $stmt = $this->pdo->prepare(
            'DELETE FROM hotspot_profiles
             WHERE router_id = ? AND id NOT IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$routerId], array_map('intval', $keepMetaIds)));
    }
}
