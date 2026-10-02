<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

final class ProfileMetadataCodec
{
    private const MARKER_PREFIX = 'janathan-profile:v1:';

    /**
     * Store profile metadata as a guarded RouterOS command inside on-login.
     * The command is never executed, but remains readable from the profile.
     */
    public function encode(array $values): string
    {
        $payload = [
            'color' => (string)($values['color'] ?? ''),
            'price' => $this->normalizePrice($values['price'] ?? 0),
            'prefix' => (string)($values['prefix'] ?? ''),
            'validity_days' => $this->normalizeValidityDays($values['validity_days'] ?? null),
            'start_on' => ($values['start_on'] ?? '') === 'user_creation'
                ? 'user_creation'
                : 'first_login',
        ];

        $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return ':if (false) do={ :put "' . self::MARKER_PREFIX . $encoded . '"; };';
    }

    /**
     * @return array{color: string, price: float, prefix: string, validity_days: int|null, start_on: string}|null
     */
    public function decode(string $source): ?array
    {
        $prefix = preg_quote(self::MARKER_PREFIX, '~');
        $pattern = '~:put\s+"' . $prefix . '([^"]*)"\s*;?~';

        if (preg_match_all($pattern, $source, $matches) < 1) {
            return null;
        }

        foreach ($matches[1] as $encoded) {
            $json = base64_decode($encoded, true);
            if ($json === false) {
                continue;
            }

            try {
                $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($payload)
                || !is_string($payload['color'] ?? null)
                || !is_string($payload['prefix'] ?? null)
                || !array_key_exists('price', $payload)
                || !array_key_exists('validity_days', $payload)
                || !is_string($payload['start_on'] ?? null)
            ) {
                continue;
            }

            $price = $payload['price'];
            if ((!is_int($price) && !is_float($price) && !is_string($price)) || !is_numeric((string)$price) || (float)$price < 0) {
                continue;
            }

            $validityDays = $payload['validity_days'];
            if ($validityDays !== null) {
                if (is_int($validityDays)) {
                    $validityDays = $validityDays > 0 ? $validityDays : null;
                } elseif (is_string($validityDays) && ctype_digit($validityDays)) {
                    $validityDays = (int)$validityDays;
                    $validityDays = $validityDays > 0 ? $validityDays : null;
                } else {
                    continue;
                }
            }

            if (!in_array($payload['start_on'], ['first_login', 'user_creation'], true)) {
                continue;
            }

            return [
                'color' => $payload['color'],
                'price' => (float)$price,
                'prefix' => $payload['prefix'],
                'validity_days' => $validityDays,
                'start_on' => $payload['start_on'],
            ];
        }

        return null;
    }

    public function append(string $source, array $values): string
    {
        $source = trim($this->remove($source));
        $marker = $this->encode($values);

        return $source === '' ? $marker : $marker . PHP_EOL . PHP_EOL . $source;
    }

    private function remove(string $source): string
    {
        $prefix = preg_quote(self::MARKER_PREFIX, '~');
        $pattern = '~[ \t]*:if\s*\(\s*false\s*\)\s*do\s*=\s*\{\s*:put\s+"'
            . $prefix
            . '[^"]*"\s*;\s*\}\s*;?[ \t]*(?:\r?\n|$)~i';

        return (string)preg_replace($pattern, '', $source);
    }

    private function normalizePrice(mixed $value): float
    {
        $value = trim((string)$value);

        return $value === '' ? 0.0 : max(0.0, (float)$value);
    }

    private function normalizeValidityDays(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $days = (int)$value;

        return $days > 0 ? $days : null;
    }
}
