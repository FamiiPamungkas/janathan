<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use RuntimeException;
use Throwable;

readonly class ProfileService
{
    use ConnectsRouter;

    public function __construct(
        private RouterRepository         $routers,
        private RouterConnectionManager  $connections,
        private HotspotProfileRepository $profileMeta
    )
    {
    }

    /**
     * @return array|null {id, name, rate_limit, shared_users, color, price}
     */
    public function getProfileByName(int $routerId, string $name): ?array
    {
        $profiles = $this->getProfiles($routerId)['profiles'];

        foreach ($profiles as $profile) {
            if (($profile['name'] ?? '') === $name) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @return string[] Sorted unique profile names for form selects.
     */
    public function getProfileNames(int $routerId): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $rows = $client->getHotspotProfiles();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        return $this->extractNames($rows);
    }

    /**
     * @return array{router: array, profiles: array, hotspotAvailable: bool}
     */
    public function getProfiles(int $routerId): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $profiles = $client->getHotspotProfiles();
            $hotspotAvailable = $client->isHotspotAvailable();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        return [
            'router' => $router,
            'profiles' => $this->mergeMeta($routerId, $profiles, $hotspotAvailable),
            'hotspotAvailable' => $hotspotAvailable,
        ];
    }

    /**
     * @return array|null The normalized profile, or null when it does not exist.
     */
    public function getProfile(int $routerId, string $id): ?array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $profile = $client->getHotspotProfile($id);
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        if ($profile === null) {
            return null;
        }

        $built = $this->buildProfile($profile);

        $meta = $this->profileMeta->findByProfileId($routerId, $id);
        if ($meta === null) {
            $meta = $this->profileMeta->findByName($routerId, $built['name']);
            if ($meta !== null) {
                $this->profileMeta->heal((int)$meta['id'], $routerId, $id, $built['name']);
            }
        }

        $built['color'] = (string)($meta['color'] ?? '');
        $built['price'] = $meta !== null ? (string)(float)$meta['price'] : '';
        $built['prefix'] = (string)($meta['prefix'] ?? '');
        $built['validity_days'] = $meta !== null ? (string)($meta['validity_days'] ?? '') : '';
        $built['start_on'] = $meta !== null ? (string)($meta['start_on'] ?? 'first_login') : 'first_login';

        return $built;
    }

    /**
     * @return string[] Sorted, unique pool names from the router.
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function getIpPools(int $routerId): array
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $rows = $client->getIpPools();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        return $this->extractNames($rows);
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function createProfile(int $routerId, array $values): void
    {
        $values['on_login'] = $this->buildOnLoginScript($values);
        $values['on_logout'] = '';

        $newId = $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->addHotspotProfile($this->normalizeFields($values))
        );

        if (is_string($newId) && $newId !== '') {
            $this->saveProfileMeta($routerId, $newId, $values);
            if ($this->normalizeValidityDays($values['validity_days'] ?? null) !== null) {
                try {
                    $this->installProfileExpiryScheduler($routerId, $newId, (string)$values['name']);
                } catch (Throwable $e) {
                    // Scheduler install is best-effort; never fail the profile save.
                }
            }
        }
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function updateProfile(int $routerId, string $id, array $values): void
    {
        $values['on_login'] = $this->buildOnLoginScript($values);
        $values['on_logout'] = '';

        $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->setHotspotProfile($id, $this->normalizeFields($values))
        );

        $this->saveProfileMeta($routerId, $id, $values);

        $days = $this->normalizeValidityDays($values['validity_days'] ?? null);
        try {
            if ($days !== null) {
                $this->installProfileExpiryScheduler($routerId, $id, (string)$values['name']);
            } else {
                $this->removeProfileExpiryScheduler($routerId, $id);
            }
        } catch (Throwable $e) {
            // Scheduler (un)install is best-effort; never fail the profile save.
        }
    }

    /**
     * @throws RuntimeException When the router cannot be reached or rejects the command.
     */
    public function removeProfile(int $routerId, string $id): void
    {
        $this->write(
            $this->routers,
            $this->connections,
            $routerId,
            fn(RouterosClient $client) => $client->removeHotspotProfile($id)
        );

        $this->profileMeta->delete($routerId, $id);

        try {
            $this->removeProfileExpiryScheduler($routerId, $id);
        } catch (Throwable $e) {
            // Scheduler removal is best-effort; the profile is already gone.
        }
    }

    /**
     * Merge RouterOS profiles with their local metadata. Meta rows are matched
     * by profile_id first and by name second; mismatches are healed so rows
     * survive both renames and `.id` changes (backup restore / netinstall).
     * Rows that match nothing are cleaned up, but only when the hotspot menu
     * is genuinely readable — an empty list from an unavailable router must
     * never wipe stored metadata.
     *
     * @return array<int, array{id: string, name: string, rate_limit: string, shared_users: string, color: string, price: float|null, prefix: string}>
     */
    public function mergeMeta(int $routerId, array $profiles, bool $hotspotAvailable): array
    {
        $byId = [];
        $byName = [];
        foreach ($this->profileMeta->allForRouter($routerId) as $m) {
            $byId[(string)$m['profile_id']] = $m;
            $byName[(string)$m['name']] = $m;
        }

        $rows = [];
        $matchedIds = [];

        foreach ($profiles as $p) {
            $profileId = (string)($p['.id'] ?? '');
            $name = (string)($p['name'] ?? '');

            $meta = $byId[$profileId] ?? null;
            if ($meta === null && $name !== '') {
                $meta = $byName[$name] ?? null;
            }

            if ($meta !== null) {
                if ((string)$meta['profile_id'] !== $profileId || (string)$meta['name'] !== $name) {
                    $this->profileMeta->heal((int)$meta['id'], $routerId, $profileId, $name);
                }
                $matchedIds[] = (int)$meta['id'];
            }

            $rows[] = [
                'id' => $profileId,
                'name' => $name,
                'rate_limit' => $p['rate-limit'] ?? '-',
                'shared_users' => $p['shared-users'] ?? '-',
                'color' => (string)($meta['color'] ?? ''),
                'price' => $meta !== null ? (float)$meta['price'] : null,
                'prefix' => (string)($meta['prefix'] ?? ''),
            ];
        }

        if ($hotspotAvailable) {
            $this->profileMeta->deleteUnmatched($routerId, $matchedIds);
        }

        return $rows;
    }

    /**
     * Persist the local (SQLite) half of a profile: voucher color and price.
     */
    private function saveProfileMeta(int $routerId, string $profileId, array $values): void
    {
        $this->profileMeta->upsert(
            $routerId,
            $profileId,
            (string)$values['name'],
            (string)($values['color'] ?? ''),
            $this->normalizePrice($values['price'] ?? ''),
            (string)($values['prefix'] ?? ''),
            $this->normalizeValidityDays($values['validity_days'] ?? null),
            $this->normalizeStartOn($values['start_on'] ?? 'first_login')
        );
    }

    private function normalizeStartOn(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));

        return $value === 'user_creation' ? 'user_creation' : 'first_login';
    }

    private function normalizePrice(mixed $price): float
    {
        $price = trim((string)$price);

        return $price === '' ? 0.0 : (float)$price;
    }

    /**
     * RouterOS snippet that reads `/system clock` into local vars `jy`, `jm`,
     * `jd`, `jhh`, `jmm`, `jss` (handling both v7 `2026-08-21` and v6
     * `aug/21/2026` date formats) and defines a `jpad` zero-padding helper.
     */
    private function routerDateParseRoutine(): string
    {
        return <<<'ROS'
:local jclkdate [/system clock get date]
:local jclktime [/system clock get time]
:local jy 0; :local jm 0; :local jd 0
:if ([:pick $jclkdate 4 5] = "-") do={
  :set jy [:tonum [:pick $jclkdate 0 4]]
  :set jm [:tonum [:pick $jclkdate 5 7]]
  :set jd [:tonum [:pick $jclkdate 8 10]]
} else={
  :local jml {"jan";"feb";"mar";"apr";"may";"jun";"jul";"aug";"sep";"oct";"nov";"dec"}
  :set jm ([:find $jml [:pick $jclkdate 0 3]] + 1)
  :set jd [:tonum [:pick $jclkdate 4 6]]
  :set jy [:tonum [:pick $jclkdate 7 11]]
}
:local jhh [:tonum [:pick $jclktime 0 2]]
:local jmm [:tonum [:pick $jclktime 3 5]]
:local jss [:tonum [:pick $jclktime 6 8]]
:local jpad do={ :return [:pick [:tostr (100 + $1)] 1 3] }
ROS;
    }

    /**
     * Build the `on-login` script for a profile. When the profile is in
     * `first_login` mode with a validity period, the script stamps `exp=` onto
     * the user the first time they log in (only if not already present, so
     * later logins never reset the window). Returns '' otherwise.
     */
    private function buildOnLoginScript(array $values): string
    {
        $days = $this->normalizeValidityDays($values['validity_days'] ?? null);
        if ($days === null || $this->normalizeStartOn($values['start_on'] ?? 'first_login') !== 'first_login') {
            return '';
        }

        $routine = $this->routerDateParseRoutine();

        return <<<ROS
# janathan: stamp expiry at first login
:local juid [/ip hotspot user find name=\$user]
:local jcur [/ip hotspot user get \$juid comment]
:if ([:find \$jcur "exp="] = -1) do={
{$routine}
  :local jn {$days}
  :local jd2 (\$jd + \$jn)
  :local jdim 30
  :while (true) do={
    :if (\$jm = 2) do={
      :if ((\$jy mod 4) = 0 && ((\$jy mod 100) != 0 || (\$jy mod 400) = 0)) do={ :set jdim 29 } else={ :set jdim 28 }
    } else={
      :if (\$jm = 4 || \$jm = 6 || \$jm = 9 || \$jm = 11) do={ :set jdim 30 } else={ :set jdim 31 }
    }
    :if (\$jd2 <= \$jdim) do={ :break }
    :set jd2 (\$jd2 - \$jdim)
    :set jm (\$jm + 1)
    :if (\$jm > 12) do={ :set jm 1; :set jy (\$jy + 1) }
  }
  :local jexp (\$jy . "-" . [\$jpad \$jm] . "-" . [\$jpad \$jd2] . " " . [\$jpad \$jhh] . ":" . [\$jpad \$jmm] . ":" . [\$jpad \$jss])
  /ip hotspot user set \$juid comment=(\$jcur . " exp=" . \$jexp)
}
ROS;
    }

    /**
     * Deterministic name for a profile's expiry scheduler/script on the router,
     * derived from the profile's RouterOS `.id` (e.g. *2 -> janathan-expire-2).
     */
    private function scheduleName(string $profileId): string
    {
        return 'janathan-expire-' . ltrim($profileId, '*');
    }

    /**
     * Build the per-profile router expiry-enforcement script: disables any
     * hotspot user of the given profile whose `exp=` token is in the past and
     * kicks the active session.
     */
    private function buildProfileExpiryScript(string $profileName): string
    {
        $routine = $this->routerDateParseRoutine();
        $profileName = str_replace('"', '', $profileName);

        return <<<ROS
# janathan: disable expired users of profile {$profileName}
{$routine}
:local jnow (\$jy . "-" . [\$jpad \$jm] . "-" . [\$jpad \$jd] . " " . [\$jpad \$jhh] . ":" . [\$jpad \$jmm] . ":" . [\$jpad \$jss])
:foreach ju in=[/ip hotspot user find where profile="{$profileName}"] do={
  :local jc [/ip hotspot user get \$ju comment]
  :local jp [:find \$jc "exp="]
  :if (\$jp >= 0) do={
    :local je [:pick \$jc (\$jp + 4) 9999]
    :local jec [:pick \$je 0 19]
    :if (\$jec < \$jnow) do={
      /ip hotspot user set \$ju disabled=yes
      :local jun [/ip hotspot user get \$ju name]
      :local jaids [/ip hotspot active find user=\$jun]
      :foreach jaid in=\$jaids do={ /ip hotspot active remove \$jaid }
    }
  }
}
ROS;
    }

    /**
     * Install (or update) the per-profile expiry scheduler that disables users
     * of the given profile past their `exp=` token. Idempotent: re-running
     * updates the script. Best-effort from callers (catches its own errors).
     *
     * @throws RuntimeException When the router cannot be reached.
     */
    public function installProfileExpiryScheduler(
        int $routerId, string $profileId, string $profileName, int $intervalMinutes = 60
    ): void
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);
        $name = $this->scheduleName($profileId);
        $body = $this->buildProfileExpiryScript($profileName);
        $interval = $intervalMinutes . 'm';
        $comment = 'Monitor Profile ' . $profileName;

        try {
            $scripts = $client->query('/system/script/print', ['name' => $name]);
            if (isset($scripts[0]['.id'])) {
                $client->writeQuery('/system/script/set', [
                    '.id' => $scripts[0]['.id'],
                    'source' => $body,
                    'policy' => 'read,write',
                    'comment' => $comment,
                ]);
            } else {
                $client->writeQuery('/system/script/add', [
                    'name' => $name,
                    'source' => $body,
                    'policy' => 'read,write',
                    'comment' => $comment,
                ]);
            }

            $schedulers = $client->query('/system/scheduler/print', ['name' => $name]);
            if (isset($schedulers[0]['.id'])) {
                $client->writeQuery('/system/scheduler/set', [
                    '.id' => $schedulers[0]['.id'],
                    'on-event' => $name,
                    'interval' => $interval,
                    'policy' => 'read,write',
                    'comment' => $comment,
                ]);
            } else {
                $client->writeQuery('/system/scheduler/add', [
                    'name' => $name,
                    'on-event' => $name,
                    'interval' => $interval,
                    'policy' => 'read,write',
                    'comment' => $comment,
                ]);
            }
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }
    }

    /**
     * Remove a profile's expiry scheduler and its backing script.
     *
     * @throws RuntimeException When the router cannot be reached.
     */
    public function removeProfileExpiryScheduler(int $routerId, string $profileId): void
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);
        $scriptName = $this->scheduleName($profileId);

        try {
            $schedulers = $client->query('/system/scheduler/print', ['name' => $scriptName]);
            foreach ($schedulers as $s) {
                if (isset($s['.id'])) {
                    $client->query('/system/scheduler/remove', ['.id' => $s['.id']]);
                }
            }

            $scripts = $client->query('/system/script/print', ['name' => $scriptName]);
            foreach ($scripts as $s) {
                if (isset($s['.id'])) {
                    $client->query('/system/script/remove', ['.id' => $s['.id']]);
                }
            }
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }
    }

    private function buildProfile(array $p): array
    {
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
