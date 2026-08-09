<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use Fame1302\Janathan\Models\RouterosVersion;

class RouterosDate
{
    /**
     * Normalize a RouterOS date string to `Y-m-d H:i:s` (or `Y-m-d` when the
     * time part is missing), regardless of whether the router reports it as
     * `MMM/dd/yyyy` (v6 / v7 < 7.10) or ISO `yyyy-MM-dd` (v7.10+).
     *
     * Unknown or unparseable values are returned unchanged.
     */
    public static function normalize(?string $value, ?RouterosVersion $version = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        $parsed = self::parseIso($value) ?? self::parseMmm($value);

        if ($parsed === null) {
            return $value;
        }

        [$date, $time] = $parsed;

        return $time === null ? $date : $date . ' ' . $time;
    }

    /**
     * @return array{0: string, 1: ?string}|null [$date, $time]
     */
    private static function parseIso(string $value): ?array
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/', $value, $m)) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
            $time = isset($m[4]) ? self::formatTime($m[4], $m[5], $m[6] ?? '00') : null;

            return [$date, $time];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string}|null [$date, $time]
     */
    private static function parseMmm(string $value): ?array
    {
        $months = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];

        if (preg_match('/^([a-zA-Z]{3})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/', $value, $m)) {
            $month = $months[strtolower($m[1])] ?? null;

            if ($month === null) {
                return null;
            }

            $date = sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[2]);
            $time = isset($m[4]) ? self::formatTime($m[4], $m[5], $m[6] ?? '00') : null;

            return [$date, $time];
        }

        return null;
    }

    private static function formatTime(string $h, string $i, string $s): string
    {
        return sprintf('%02d:%02d:%02d', (int) $h, (int) $i, (int) $s);
    }
}
