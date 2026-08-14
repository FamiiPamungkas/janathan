<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Support\Logger;
use RuntimeException;
use Throwable;

readonly class HotspotService
{
    public function __construct(
        private RouterRepository      $routers,
        private RouterosClientFactory $clientFactory
    )
    {
    }

    /**
     * @param array{q?: string, profile?: string, comment?: string, status?: string} $filters
     * @return array{
     *     router: array,
     *     users: array,
     *     profiles: string[],
     *     comments: string[],
     *     filters: array{q: string, profile: string, comment: string, status: string},
     *     filtersActive: bool,
     *     hotspotAvailable: bool
     * }
     */
    public function getUsers(int $routerId, array $filters = []): array
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $users = $client->getHotspotUsers();
            $profileRows = $client->getHotspotProfiles();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        $normalized = $this->normalizeUserListFilters($filters);
        $built = $this->buildUsers($users);
        $comments = $this->extractCommentOptions($built);
        $built = $this->applyUserListFilters($built, $normalized);

        return [
            'router' => $router,
            'users' => $built,
            'profiles' => $this->extractProfileNames($profileRows),
            'comments' => $comments,
            'filters' => $normalized,
            'filtersActive' => $normalized['q'] !== ''
                || $normalized['profile'] !== ''
                || $normalized['comment'] !== ''
                || $normalized['status'] !== 'all',
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    /**
     * @param array{q?: string, profile?: string, comment?: string, status?: string} $filters
     * @return array{q: string, profile: string, comment: string, status: string}
     */
    private function normalizeUserListFilters(array $filters): array
    {
        $q = trim((string)($filters['q'] ?? ''));
        $profile = trim((string)($filters['profile'] ?? ''));
        $comment = trim((string)($filters['comment'] ?? ''));
        $status = strtolower(trim((string)($filters['status'] ?? 'all')));

        if (!in_array($status, ['all', 'enabled', 'disabled'], true)) {
            $status = 'all';
        }

        return [
            'q' => $q,
            'profile' => $profile,
            'comment' => $comment,
            'status' => $status,
        ];
    }

    /**
     * @param list<array<string, mixed>> $users
     * @param array{q: string, profile: string, comment: string, status: string} $filters
     * @return list<array<string, mixed>>
     */
    private function applyUserListFilters(array $users, array $filters): array
    {
        $q = $filters['q'];
        $qLower = $q !== '' ? mb_strtolower($q) : '';
        $profile = $filters['profile'];
        $comment = $filters['comment'];
        $status = $filters['status'];

        return array_values(array_filter(
            $users,
            static function (array $u) use ($qLower, $profile, $comment, $status): bool {
                if (($u['name'] ?? '') === 'default-trial') {
                    return false;
                }

                if ($profile !== '' && ($u['profile'] ?? '') !== $profile) {
                    return false;
                }

                if ($comment !== '' && (string)($u['comment'] ?? '') !== $comment) {
                    return false;
                }

                if ($status === 'enabled' && !empty($u['disabled'])) {
                    return false;
                }

                if ($status === 'disabled' && empty($u['disabled'])) {
                    return false;
                }

                if ($qLower !== '') {
                    $name = mb_strtolower((string)($u['name'] ?? ''));
                    $userComment = mb_strtolower((string)($u['comment'] ?? ''));
                    if (!str_contains($name, $qLower) && !str_contains($userComment, $qLower)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * Unique non-empty comments for the filter select, excluding system users.
     *
     * @param list<array<string, mixed>> $users
     * @return string[]
     */
    private function extractCommentOptions(array $users): array
    {
        $comments = [];
        foreach ($users as $u) {
            if (($u['name'] ?? '') === 'default-trial') {
                continue;
            }

            $c = trim((string)($u['comment'] ?? ''));
            if ($c !== '') {
                $comments[] = $c;
            }
        }

        $comments = array_values(array_unique($comments));
        sort($comments, SORT_NATURAL | SORT_FLAG_CASE);

        return $comments;
    }

    /**
     * @return string[]
     */
    private function extractProfileNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
                $names[] = $row['name'];
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @return array|null The normalized user, or null when it does not exist.
     */
    public function getUser(int $routerId, string $id): ?array
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $user = $client->getHotspotUser($id);
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        return $user === null ? null : $this->buildUser($user);
    }

    /**
     * @return string[] Sorted unique profile names for form selects.
     */
    public function getProfileNames(int $routerId): array
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $rows = $client->getHotspotProfiles();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        return $this->extractProfileNames($rows);
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function createUser(int $routerId, array $values): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->addHotspotUser($this->normalizeUserFields($values, false)));
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function updateUser(int $routerId, string $id, array $values): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->setHotspotUser($id, $this->normalizeUserFields($values, true)));
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function removeUser(int $routerId, string $id): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->removeHotspotUser($id));
    }

    /**
     * @return array{router: array, profiles: array, hotspotAvailable: bool}
     */
    public function getProfiles(int $routerId): array
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $profiles = $client->getHotspotProfiles();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        return [
            'router' => $router,
            'profiles' => $this->buildProfiles($profiles),
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    /**
     * @return array|null The normalized profile, or null when it does not exist.
     */
    public function getProfile(int $routerId, string $id): ?array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($routerId);

        try {
            $profile = $client->getHotspotProfile($id);
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        return $profile === null ? null : $this->buildProfile($profile);
    }

    /**
     * @return string[] Sorted, unique pool names from the router.
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function getIpPools(int $routerId): array
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $rows = $client->getIpPools();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }

        $names = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
                $names[] = $row['name'];
            }
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function createProfile(int $routerId, array $values): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->addHotspotProfile($this->normalizeFields($values))
        );
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function updateProfile(int $routerId, string $id, array $values): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->setHotspotProfile($id, $this->normalizeFields($values))
        );
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function removeProfile(int $routerId, string $id): void
    {
        $this->write($routerId, fn(RouterosClient $client) => $client->removeHotspotProfile($id));
    }

    private function write(int $routerId, callable $operation): void
    {
        [$router, $client] = $this->connect($routerId);

        try {
            $operation($client);
        } catch (RouterosCommandException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return array{router: array, client: RouterosClient}
     */
    private function connect(int $routerId): array
    {
        $router = $this->routers->find($routerId);

        if ($router === null) {
            throw new RuntimeException('The selected router no longer exists.');
        }

        return [$router, $this->clientFactory->create($this->routers->getCredentials($routerId))];
    }

    private function unreachable(array $router, Throwable $e): RuntimeException
    {
        error_log("ERROR " . $e->getMessage());
        return new RuntimeException(
            'Cannot reach router "' . $router['name'] . '" (' . $router['host'] . ').',
            0,
            $e
        );
    }

    private function buildUsers(array $users): array
    {
        $rows = [];

        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }
            $rows[] = $this->buildUserListRow($u);
        }

        return $rows;
    }

    private function buildUserListRow(array $u): array
    {
        return [
            'id' => $u['.id'] ?? '',
            'name' => $u['name'] ?? '',
            'profile' => $u['profile'] ?? '',
            'comment' => $u['comment'] ?? '',
            'disabled' => $this->isYes($u['disabled'] ?? null),
            'uptime' => $this->formatUptime((string)($u['uptime'] ?? '')),
            'bytes_in' => $this->formatBytes((int)($u['bytes-in'] ?? 0)),
            'bytes_out' => $this->formatBytes((int)($u['bytes-out'] ?? 0)),
        ];
    }

    private function buildUser(array $u): array
    {
        return [
            'id' => $u['.id'] ?? '',
            'name' => $u['name'] ?? '',
            'profile' => $u['profile'] ?? '',
            'comment' => $u['comment'] ?? '',
            'disabled' => $this->isYes($u['disabled'] ?? null),
        ];
    }

    /**
     * Format RouterOS duration (e.g. 1h2m3s, 1d3h) as HH:MM:SS, or "Nd HH:MM:SS" when ≥ 1 day.
     */
    private function formatUptime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i', $raw, $m) !== 1) {
            return $raw;
        }

        $hasUnit = ($m[1] ?? '') !== '' || ($m[2] ?? '') !== '' || ($m[3] ?? '') !== ''
            || ($m[4] ?? '') !== '' || ($m[5] ?? '') !== '';
        if (!$hasUnit && $raw !== '0s' && $raw !== '0') {
            return $raw;
        }

        $seconds = (int)($m[1] ?? 0) * 604800
            + (int)($m[2] ?? 0) * 86400
            + (int)($m[3] ?? 0) * 3600
            + (int)($m[4] ?? 0) * 60
            + (int)($m[5] ?? 0);

        $days = intdiv($seconds, 86400);
        $rem = $seconds % 86400;
        $hours = intdiv($rem, 3600);
        $rem %= 3600;
        $minutes = intdiv($rem, 60);
        $secs = $rem % 60;

        $clock = sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);

        return $days >= 1 ? $days . 'd ' . $clock : $clock;
    }

    private function formatBytes(int|float $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GiB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MiB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        return (string)(int)$bytes . ' B';
    }

    private function isYes(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $v = strtolower(trim((string)$value));

        return $v === 'true' || $v === 'yes' || $v === '1';
    }

    /**
     * @param bool $isUpdate When true, omit blank password so RouterOS keeps the existing one.
     */
    private function normalizeUserFields(array $values, bool $isUpdate): array
    {
        $fields = [
            'name' => $values['name'],
            'profile' => $values['profile'],
            'comment' => $values['comment'] ?? '',
            'disabled' => !empty($values['disabled']) ? 'yes' : 'no',
        ];

        $password = $values['password'] ?? '';
        if ($password !== '' || !$isUpdate) {
            $fields['password'] = $password;
        }

        return $fields;
    }

    private function buildProfiles(array $profiles): array
    {
        $rows = [];

        foreach ($profiles as $p) {
            $rows[] = [
                'id' => $p['.id'] ?? '',
                'name' => $p['name'] ?? '',
                'rate_limit' => $p['rate-limit'] ?? '-',
                'shared_users' => $p['shared-users'] ?? '-',
            ];
        }

        return $rows;
    }

    private function buildProfile(array $p): array
    {
        Logger::log("ADD MAC COOKIE ", $p['add-mac-cookie']);
        return [
            'id' => $p['.id'] ?? '',
            'name' => $p['name'] ?? '',
            'rate_limit' => $p['rate-limit'] ?? '',
            'shared_users' => $p['shared-users'] ?? '1',
            'add_mac_cookie' => $p['add-mac-cookie'],
            'address_pool' => $p['address-pool'] ?? '',
            'on_login' => $p['on-login'] ?? '',
            'on_logout' => $p['on-logout'] ?? '',
        ];
    }

    /**
     * Map form input to RouterOS attribute names. Blank rate limit becomes
     * `unlimited`; other RouterOS attributes not set by the form keep their
     * own defaults (e.g. idle/session/keepalive timeouts default to `none`).
     */
    private function normalizeFields(array $values): array
    {
        $fields = [
            'name' => $values['name'],
            'shared-users' => $values['shared_users'] === '' ? '1' : $values['shared_users'],
            'rate-limit' => $values['rate_limit'],
            'add-mac-cookie' => !empty($values['add_mac_cookie']) ? 'yes' : 'no',
            'on-login' => $values['on_login'],
            'on-logout' => $values['on_logout'],
        ];

        if (($values['address_pool'] ?? '') !== '') {
            $fields['address-pool'] = $values['address_pool'];
        }

        return $fields;
    }
}
