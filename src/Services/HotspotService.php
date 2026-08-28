<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use RuntimeException;
use Throwable;

readonly class HotspotService
{
    use ConnectsRouter;

    public function __construct(
        private RouterRepository         $routers,
        private RouterConnectionManager  $connections,
        private HotspotProfileRepository $profileMeta,
        private ProfileService           $profiles
    )
    {
    }

    /**
     * @param array{q?: string, profile?: string, comment?: string, status?: string} $filters
     * @return array{
     *     router: array,
     *     users: array,
     *     profiles: string[],
     *     comments: list<array{comment: string, count: int}>,
     *     filters: array{q: string, profile: string, comment: string, status: string},
     *     filtersActive: bool,
     *     hotspotAvailable: bool
     * }
     */
    public function getUsers(int $routerId, array $filters = []): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $users = $client->getHotspotUsers();
            $profileRows = $client->getHotspotProfiles();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        $normalized = $this->normalizeUserListFilters($filters);
        $built = $this->buildUsers($users);
        $comments = $this->extractCommentOptions($built);
        $built = $this->applyUserListFilters($built, $normalized);

        return [
            'router' => $router,
            'users' => $built,
            'profiles' => $this->extractNames($profileRows),
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
     * Unique non-empty comments with their user counts, excluding system users.
     *
     * @param list<array<string, mixed>> $users
     * @return list<array{comment: string, count: int}>
     */
    private function extractCommentOptions(array $users): array
    {
        $counts = [];
        foreach ($users as $u) {
            if (($u['name'] ?? '') === 'default-trial') {
                continue;
            }

            $c = trim((string)($u['comment'] ?? ''));
            if ($c !== '') {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }

        $comments = [];
        foreach ($counts as $comment => $count) {
            $comments[] = ['comment' => $comment, 'count' => $count];
        }

        usort($comments, static function (array $a, array $b): int {
            return strnatcmp(mb_strtolower($a['comment']), mb_strtolower($b['comment']));
        });

        return $comments;
    }

    /**
     * @return array|null The normalized user, or null when it does not exist.
     */
    public function getUser(int $routerId, string $id): ?array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $user = $client->getHotspotUser($id);
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        return $user === null ? null : $this->buildUser($user);
    }

    /**
     * Fetch a single user with its plaintext password for voucher printing.
     * The password is used only to build the printable voucher HTML and is
     * never stored, logged, or returned to list views.
     *
     * @return array|null {id, name, profile, comment, disabled, password}
     */
    public function getUserForPrint(int $routerId, string $id): ?array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $user = $client->getHotspotUser($id);
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user['.id'] ?? '',
            'name' => $user['name'] ?? '',
            'profile' => $user['profile'] ?? '',
            'comment' => $user['comment'] ?? '',
            'disabled' => $this->isYes($user['disabled'] ?? null),
            'password' => $user['password'] ?? '',
        ];
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function createUser(int $routerId, array $values): void
    {
        $values['comment'] = $this->applyCreationExpiry($routerId, $values['profile'] ?? '', $values['comment'] ?? '');

        $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->addHotspotUser($this->normalizeUserFields($values, false))
        );
    }

    /**
     * Bulk-create hotspot users. Each username is built as `prefix` followed
     * by random characters; each password is random characters. One router
     * connection is reused for the whole batch.
     *
     * @param array{qty: int, profile: string, prefix: string, comment: string, char_lowercase: bool, char_uppercase: bool, char_numbers: bool, name_length: int, password_length: int, password_same_as_username: bool} $values
     * @return array{created: int, failed: int, errors: string[], comment: string}
     * @throws RuntimeException When the router cannot be reached.
     */
    public function generateUsers(int $routerId, array $values): array
    {
        $charset = $this->characterCharset($values);
        $result = ['created' => 0, 'failed' => 0, 'errors' => []];
        $date = date('ymd');
        $mode = $values['password_same_as_username'] ? 'vc' : 'up';
        $seq = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $comment = $mode . '-' . $date . '-' . $seq;
        $customComment = trim($values['comment']);
        if ($customComment !== '') {
            $comment .= '-' . $customComment;
        }
        $comment = $this->applyCreationExpiry($routerId, $values['profile'], $comment);

        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            for ($i = 0; $i < $values['qty']; $i++) {
                $name = $values['prefix'] . $this->randomString($charset, $values['name_length']);
                $password = $values['password_same_as_username']
                    ? $name
                    : $this->randomString($charset, $values['password_length']);

                try {
                    $client->addHotspotUser($this->normalizeUserFields([
                        'name' => $name,
                        'profile' => $values['profile'],
                        'comment' => $comment,
                        'password' => $password,
                    ], false));
                    $result['created']++;
                } catch (RouterosCommandException $e) {
                    $result['failed']++;
                    $result['errors'][] = $name . ': ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        $result['comment'] = $comment;

        return $result;
    }

    /**
     * @param array{char_lowercase: bool, char_uppercase: bool, char_numbers: bool} $values
     * @return non-empty-string
     */
    private function characterCharset(array $values): string
    {
        $charset = '';
        if ($values['char_lowercase']) {
            $charset .= 'abcdefghijklmnopqrstuvwxyz';
        }
        if ($values['char_uppercase']) {
            $charset .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }
        if ($values['char_numbers']) {
            $charset .= '0123456789';
        }

        return $charset;
    }

    /**
     * @param non-empty-string $charset
     */
    private function randomString(string $charset, int $length): string
    {
        $out = '';
        $max = strlen($charset) - 1;

        for ($i = 0; $i < $length; $i++) {
            $out .= $charset[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function updateUser(int $routerId, string $id, array $values): void
    {
        $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->setHotspotUser($id, $this->normalizeUserFields($values, true))
        );
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function removeUser(int $routerId, string $id): void
    {
        $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->removeHotspotUser($id)
        );
    }

    /**
     * Bulk-remove hotspot users matching the given list filters (comment + the
     * other active filters). When `$includeActive` is false, only users that
     * have never connected (uptime still zero) are removed; when true, every
     * matched user is removed regardless of uptime.
     *
     * @param array{q?: string, profile?: string, comment?: string, status?: string} $filters
     * @return array{deleted: int, skipped: int}
     * @throws RuntimeException When the router cannot be reached.
     */
    public function deleteUsersByComment(int $routerId, array $filters, bool $includeActive): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $rows = $client->getHotspotUsers();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        if (!$hotspotAvailable) {
            return ['deleted' => 0, 'skipped' => 0];
        }

        $normalized = $this->normalizeUserListFilters($filters);
        $built = $this->applyUserListFilters($this->buildUsers($rows), $normalized);

        $deleted = 0;
        $skipped = 0;

        try {
            foreach ($built as $user) {
                if (($user['name'] ?? '') === 'default-trial') {
                    continue;
                }

                if (!$includeActive && empty($user['neverConnected'])) {
                    $skipped++;

                    continue;
                }

                $client->removeHotspotUser((string)($user['id'] ?? ''));
                $deleted++;
            }
        } catch (RouterosCommandException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Fetch every user matching the given list filters (comment + others),
     * each with its plaintext password, plus a map of profile metadata keyed
     * by profile name. Used to render a combined voucher sheet for printing.
     *
     * @param array{q?: string, profile?: string, comment?: string, status?: string} $filters
     * @return array{
     *     users: list<array{id: string, name: string, profile: string, comment: string, disabled: bool, password: string}>,
     *     profiles: array<string, array{name: string, color: string, price: string}>
     * }
     */
    public function getUsersForPrint(int $routerId, array $filters = []): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $rows = $client->getHotspotUsers();
            $profileRows = $client->getHotspotProfiles();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        $normalized = $this->normalizeUserListFilters($filters);
        $built = $this->buildUsers($rows);

        $profileMap = [];
        foreach ($this->profiles->mergeMeta($routerId, $profileRows, $hotspotAvailable) as $p) {
            $profileMap[(string)$p['name']] = [
                'name' => (string)$p['name'],
                'color' => (string)($p['color'] ?? ''),
                'price' => $p['price'] === null ? '' : (string)$p['price'],
            ];
        }

        $filtered = $this->applyUserListFilters($built, $normalized);

        $users = [];
        foreach ($filtered as $u) {
            if (($u['name'] ?? '') === 'default-trial') {
                continue;
            }

            $userId = $u['id'] ?? '';
            $password = '';
            foreach ($rows as $row) {
                if (($row['.id'] ?? '') === $userId) {
                    $password = (string)($row['password'] ?? '');
                    break;
                }
            }

            $users[] = [
                'id' => $userId,
                'name' => $u['name'] ?? '',
                'profile' => $u['profile'] ?? '',
                'comment' => $u['comment'] ?? '',
                'disabled' => (bool)($u['disabled'] ?? false),
                'password' => $password,
            ];
        }

        return [
            'users' => $users,
            'profiles' => $profileMap,
        ];
    }

    /**
     * Fetch the raw hotspot user records (including plaintext password) for export.
     * Reuses the same filters and default-trial exclusion as the list view.
     *
     * @param array{q?: string, profile?: string, comment?: string, status?: string} $filters
     * @return array{users: list<array<string, mixed>>}
     */
    public function getUsersForExport(int $routerId, array $filters = []): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $rows = $client->getHotspotUsers();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        $normalized = $this->normalizeUserListFilters($filters);
        $built = $this->buildUsers($rows);
        $filtered = $this->applyUserListFilters($built, $normalized);

        $users = [];
        foreach ($filtered as $u) {
            if (($u['name'] ?? '') === 'default-trial') {
                continue;
            }

            $userId = $u['id'] ?? '';
            $raw = null;
            foreach ($rows as $row) {
                if (($row['.id'] ?? '') === $userId) {
                    $raw = $row;
                    break;
                }
            }
            if ($raw === null) {
                continue;
            }

            $users[] = [
                'name' => $raw['name'] ?? '',
                'password' => $raw['password'] ?? '',
                'profile' => $raw['profile'] ?? '',
                'comment' => $raw['comment'] ?? '',
                'disabled' => $this->isYes($raw['disabled'] ?? null),
                'server' => $raw['server'] ?? '',
                'mac_address' => $raw['mac-address'] ?? '',
                'limit_bytes_in' => $raw['limit-bytes-in'] ?? '',
                'limit_bytes_out' => $raw['limit-bytes-out'] ?? '',
                'uptime' => $raw['uptime'] ?? '',
                'bytes_in' => $raw['bytes-in'] ?? 0,
                'bytes_out' => $raw['bytes-out'] ?? 0,
            ];
        }

        return ['users' => $users];
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
        $expiry = $this->parseExpiry((string)($u['comment'] ?? ''));
        $now = date('Y-m-d H:i:s');

        return [
            'id' => $u['.id'] ?? '',
            'name' => $u['name'] ?? '',
            'profile' => $u['profile'] ?? '',
            'comment' => $u['comment'] ?? '',
            'disabled' => $this->isYes($u['disabled'] ?? null),
            'uptime' => $this->formatUptime((string)($u['uptime'] ?? '')),
            'bytes_in' => $this->formatBytes((int)($u['bytes-in'] ?? 0)),
            'bytes_out' => $this->formatBytes((int)($u['bytes-out'] ?? 0)),
            'neverConnected' => $this->isUptimeZero((string)($u['uptime'] ?? '')),
            'expires_at' => $expiry,
            'expired' => $expiry !== null && $expiry <= $now,
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
     * Extract a `exp=YYYY-MM-DD HH:mm:ss` token from a user comment.
     */
    private function parseExpiry(string $comment): ?string
    {
        $pos = strpos($comment, 'exp=');
        if ($pos === false) {
            return null;
        }

        $token = substr($comment, $pos + 4, 19);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $token) === 1) {
            return $token;
        }

        return null;
    }

    /**
     * Append an `exp=` token (issue date + N days) to a comment. No-op when the
     * validity period is empty, preserving the current comment otherwise.
     */
    private function appendExpiry(string $comment, ?int $days): string
    {
        if ($days === null || $days <= 0) {
            return $comment;
        }

        $expiry = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
        $comment = trim($comment);

        return $comment === '' ? ('exp=' . $expiry) : ($comment . ' exp=' . $expiry);
    }

    /**
     * For profiles whose validity is anchored at user creation, stamp the
     * expiry into the comment before the user is added to the router.
     */
    private function applyCreationExpiry(int $routerId, string $profileName, string $comment): string
    {
        if ($profileName === '') {
            return $comment;
        }

        $meta = $this->profileMeta->findByName($routerId, $profileName);
        if ($meta === null || ($meta['start_on'] ?? '') !== 'user_creation') {
            return $comment;
        }

        $days = $this->normalizeValidityDays($meta['validity_days'] ?? null);
        if ($days === null) {
            return $comment;
        }

        return $this->appendExpiry($comment, $days);
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
     * A user is considered "never connected" when RouterOS reports no uptime
     * (empty, "0", or "0s"). Such accounts have never logged in.
     */
    private function isUptimeZero(string $raw): bool
    {
        $raw = trim($raw);

        return $raw === '' || $raw === '0' || $raw === '0s';
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

    /**
     * Fetch the list of currently connected (active) hotspot sessions.
     *
     * @return array{router: array, sessions: list<array{id: string, user: string, ip: string, mac: string, uptime: string, bytes_in: string, bytes_out: string, server: string}>, hotspotAvailable: bool}
     */
    public function getActiveUsers(int $routerId): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $rows = $client->getActiveUsers();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        $sessions = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['user'] ?? '') === '' && ($row['.id'] ?? '') === '') {
                continue;
            }
            $sessions[] = [
                'id' => $row['.id'] ?? '',
                'user' => $row['user'] ?? '',
                'ip' => $row['address'] ?? $row['ip'] ?? '',
                'mac' => $row['mac-address'] ?? '',
                'comment' => $row['comment'] ?? '',
                'uptime' => $this->formatUptime((string)($row['uptime'] ?? '')),
                'bytes_in' => $this->formatBytes((int)($row['bytes-in'] ?? 0)),
                'bytes_out' => $this->formatBytes((int)($row['bytes-out'] ?? 0)),
                'server' => $row['server'] ?? '',
            ];
        }

        return [
            'router' => $router,
            'sessions' => $sessions,
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    /**
     * Disconnect (kick) a single active hotspot session by its RouterOS `.id`.
     *
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function removeActiveUser(int $routerId, string $id): void
    {
        $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->removeActiveUser($id)
        );
    }

    public function getHosts(int $routerId): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $rows = $client->getHotspotHosts();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        $hosts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['mac-address'] ?? '') === '' && ($row['.id'] ?? '') === '') {
                continue;
            }
            $hosts[] = [
                'id' => $row['.id'] ?? '',
                'mac' => $row['mac-address'] ?? '',
                'ip' => $row['address'] ?? $row['ip'] ?? '',
                'to_address' => $row['to-address'] ?? '',
                'server' => $row['server'] ?? '',
                'authorized' => $this->isYes($row['authorized'] ?? false),
                'bypassed' => $this->isYes($row['bypassed'] ?? false),
                'comment' => $row['comment'] ?? '',
                'uptime' => $this->formatUptime((string)($row['uptime'] ?? '')),
                'idle_time' => $this->formatUptime((string)($row['idle-time'] ?? '')),
                'bytes_in' => $this->formatBytes((int)($row['bytes-in'] ?? 0)),
                'bytes_out' => $this->formatBytes((int)($row['bytes-out'] ?? 0)),
            ];
        }

        return [
            'router' => $router,
            'hosts' => $hosts,
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    public function removeHost(int $routerId, string $id): void
    {
        $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->removeHotspotHost($id)
        );
    }

    public function getCookies(int $routerId): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $rows = $client->getHotspotCookies();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        $cookies = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['mac-address'] ?? '') === '' && ($row['.id'] ?? '') === '') {
                continue;
            }
            $cookies[] = [
                'id' => $row['.id'] ?? '',
                'user' => $row['user'] ?? '',
                'mac' => $row['mac-address'] ?? '',
                'domain' => $row['domain'] ?? '',
                'expires_in' => $this->formatUptime((string)($row['expires-in'] ?? '')),
            ];
        }

        return [
            'router' => $router,
            'cookies' => $cookies,
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    public function removeCookie(int $routerId, string $id): void
    {
        $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->removeHotspotCookie($id)
        );
    }
}
