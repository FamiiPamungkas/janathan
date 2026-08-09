<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Models;

class RouterosVersion
{
    private function __construct(
        private int $major,
        private int $minor,
        private int $patch,
        private string $full
    ) {
    }

    /**
     * Parse the `version` value returned by `/system/resource/print`,
     * e.g. "6.49.17 (stable)" or "7.15.2 (stable)".
     */
    public static function fromString(?string $version): ?self
    {
        if ($version === null || $version === '') {
            return null;
        }

        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', trim($version), $m)) {
            return null;
        }

        return new self(
            (int) $m[1],
            (int) ($m[2] ?? 0),
            (int) ($m[3] ?? 0),
            trim($version)
        );
    }

    public function major(): int
    {
        return $this->major;
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function patch(): int
    {
        return $this->patch;
    }

    public function full(): string
    {
        return $this->full;
    }

    public function isV6(): bool
    {
        return $this->major === 6;
    }

    public function isV7(): bool
    {
        return $this->major === 7;
    }

    public function isAtLeast(int $major, int $minor = 0): bool
    {
        if ($this->major !== $major) {
            return $this->major > $major;
        }

        return $this->minor >= $minor;
    }

    /**
     * RouterOS v7.10+ defaults to ISO date strings (`yyyy-MM-dd`),
     * everything older (v6, v7 < 7.10) uses `MMM/dd/yyyy`.
     */
    public function dateStyle(): string
    {
        return $this->isAtLeast(7, 10) ? 'iso' : 'mmm';
    }
}
