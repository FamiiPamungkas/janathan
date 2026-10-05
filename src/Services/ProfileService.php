<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Support\Logger;
use RuntimeException;
use Throwable;

readonly class ProfileService
{
    use ConnectsRouter;

    public function __construct(
        private RouterRepository $routers,
        private RouterConnectionManager  $connections,
        private ProfileMetadataCodec     $metadata
    )
    {
    }

    /**
     * @return array|null {id, name, rate_limit, shared_users, color, price, prefix, validity_days, start_on}
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

    public function getRateLimitByName(int $routerId, string $name): string
    {
        /** @var $client RouterosClient */
        [$router, $client] = $this->connect($this->routers, $this->connections, $routerId);

        try {
            $profiles = $client->getHotspotProfiles();
        } catch (Throwable $e) {
            throw $this->unreachable($router, $e);
        }

        foreach ($profiles as $profile) {
            if (($profile['name'] ?? '') === $name) {
                return (string)($profile['rate-limit'] ?? '');
            }
        }

        return '';
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
            'profiles' => $this->mergeMeta($routerId, $profiles),
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

        $meta = $this->metadata->decode((string)($profile['on-login'] ?? ''));

        $built = $this->buildProfile($profile);
        $this->applyMetadata($built, $meta);

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

        try {
            $this->removeProfileExpiryScheduler($routerId, $id);
        } catch (Throwable $e) {
            // Scheduler removal is best-effort; the profile is already gone.
        }
    }

    /**
     * Merge RouterOS profiles with embedded metadata.
     *
     * @return array<int, array{id: string, name: string, rate_limit: string, shared_users: string, color: string, price: float|null, prefix: string, validity_days: int|null, start_on: string}>
     */
    public function mergeMeta(int $routerId, array $profiles): array
    {
        $rows = [];

        foreach ($profiles as $p) {
            $profileId = (string)($p['.id'] ?? '');
            $name = (string)($p['name'] ?? '');

            $embedded = $this->metadata->decode((string)($p['on-login'] ?? ''));

            $rows[] = [
                'id' => $profileId,
                'name' => $name,
                'rate_limit' => $p['rate-limit'] ?? '-',
                'shared_users' => $p['shared-users'] ?? '-',
                'color' => (string)($embedded['color'] ?? ''),
                'price' => $embedded !== null ? (float)$embedded['price'] : null,
                'prefix' => (string)($embedded['prefix'] ?? ''),
                'validity_days' => $embedded['validity_days'] ?? null,
                'start_on' => (string)($embedded['start_on'] ?? 'first_login'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $profile
     * @param array{color: string, price: float, prefix: string, validity_days: int|null, start_on: string}|null $meta
     */
    private function applyMetadata(array &$profile, ?array $meta): void
    {
        $profile['color'] = (string)($meta['color'] ?? '');
        $profile['price'] = $meta !== null ? (string)(float)$meta['price'] : '';
        $profile['prefix'] = (string)($meta['prefix'] ?? '');
        $profile['validity_days'] = $meta !== null && $meta['validity_days'] !== null
            ? (string)$meta['validity_days']
            : '';
        $profile['start_on'] = (string)($meta['start_on'] ?? 'first_login');
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
     * RouterOS snippet that reads `/system clock` into local vars `year`,
     * `month`, `day`, `hour`, `minute`, `second` (handling both v7
     * `2026-08-21` and v6 `aug/21/2026` date formats).
     */
    private function routerDateParseRoutine(): string
    {
        return <<<'ROS'
:local clockDate [/system clock get date];
:local clockTime [/system clock get time];
:local year 0; :local month 0; :local day 0;
:if ([:pick $clockDate 4 5] = "-") do={
  :set year [:tonum [:pick $clockDate 0 4]];
  :set month [:tonum [:pick $clockDate 5 7]];
  :set day [:tonum [:pick $clockDate 8 10]];
} else={
  :local monthNames {"jan";"feb";"mar";"apr";"may";"jun";"jul";"aug";"sep";"oct";"nov";"dec"};
  :set month ([:find $monthNames [:pick $clockDate 0 3]] + 1);
  :set day [:tonum [:pick $clockDate 4 6]];
  :set year [:tonum [:pick $clockDate 7 11]];
};
:local hour [:tonum [:pick $clockTime 0 2]];
:local minute [:tonum [:pick $clockTime 3 5]];
:local second [:tonum [:pick $clockTime 6 8]];
ROS;
    }

    /**
     * Build the `on-login` script for a profile. The guarded metadata marker is
     * always included; first-login profiles additionally stamp `exp=` onto the
     * user (only if not already present, so later logins never reset the window).
     */
    private function buildOnLoginScript(array $values): string
    {
        $metadata = $this->metadata->encode($values);
        $days = $this->normalizeValidityDays($values['validity_days'] ?? null);
        if ($days === null || $this->normalizeStartOn($values['start_on'] ?? 'first_login') !== 'first_login') {
            return $metadata;
        }

        $routine = $this->routerDateParseRoutine();

        return $metadata . PHP_EOL . PHP_EOL . <<<ROS
# janathan: stamp expiry at first login
:local uid [/ip hotspot user find where name="\$user"];
:local currentComment [/ip hotspot user get \$uid comment];
:if ([:typeof [:find \$currentComment "exp="]] = "nil") do={
{$routine}
  :local validityDays {$days};
  :local expiryDay (\$day + \$validityDays);
  :local daysInMonth 30;
  :local overflow true;
  :while (\$overflow) do={
    :if (\$month = 2) do={
      :if ((\$year mod 4) = 0 && ((\$year mod 100) != 0 || (\$year mod 400) = 0)) do={ :set daysInMonth 29; } else={ :set daysInMonth 28; };
    } else={
      :if (\$month = 4 || \$month = 6 || \$month = 9 || \$month = 11) do={ :set daysInMonth 30; } else={ :set daysInMonth 31; };
    };
    :if (\$expiryDay <= \$daysInMonth) do={
        :set overflow false;
    } else={
        :set expiryDay (\$expiryDay - \$daysInMonth);
        :set month (\$month + 1);
        :if (\$month > 12) do={ :set month 1; :set year (\$year + 1); };
    };
  };
  :local expiryText (\$year . "-" . [:pick [:tostr (100 + \$month)] 1 3] . "-" . [:pick [:tostr (100 + \$expiryDay)] 1 3] . " " . [:pick [:tostr (100 + \$hour)] 1 3] . ":" . [:pick [:tostr (100 + \$minute)] 1 3] . ":" . [:pick [:tostr (100 + \$second)] 1 3]);
  /ip hotspot user set \$uid comment=(\$currentComment . " exp=" . \$expiryText);
};
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
:local nowDateNum ((\$year * 10000) + (\$month * 100) + \$day);
:local nowTimeNum ((\$hour * 10000) + (\$minute * 100) + \$second);
:foreach uid in=[/ip hotspot user find where profile="{$profileName}" and disabled=no] do={
  :local commentText [/ip hotspot user get \$uid comment];
  :local expPos [:find \$commentText "exp="];
  :if ([:typeof \$expPos] = "num") do={
    :local expValue [:pick \$commentText (\$expPos + 4) 9999];
    :local expDate [:pick \$expValue 0 19];
    :local expDateNum [:tonum ([:pick \$expDate 0 4] . [:pick \$expDate 5 7] . [:pick \$expDate 8 10])];
    :local expTimeNum [:tonum ([:pick \$expDate 11 13] . [:pick \$expDate 14 16] . [:pick \$expDate 17 19])];
    :if ((\$expDateNum < \$nowDateNum) || ((\$expDateNum = \$nowDateNum) && (\$expTimeNum < \$nowTimeNum))) do={
      /ip hotspot user set \$uid disabled=yes;
      :local userName [/ip hotspot user get \$uid name];
      :local activeIds [/ip hotspot active find where user="\$userName"];
      :foreach activeId in=\$activeIds do={ /ip hotspot active remove \$activeId; };
    };
  };
};
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
