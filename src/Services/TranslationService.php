<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

class TranslationService
{
    private string $locale;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * @param array<string, string> $available map of locale code => native label
     */
    public function __construct(string $locale, private readonly array $available)
    {
        $this->locale = $this->normalize($locale);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $this->normalize($locale);
    }

    public function getAvailable(): array
    {
        return $this->available;
    }

    public function trans(string $key, array $replace = []): string
    {
        $value = $this->lookup($this->locale, $key);

        if ($value === null && $this->locale !== 'en') {
            $value = $this->lookup('en', $key);
        }

        if ($value === null) {
            return $key;
        }

        foreach ($replace as $token => $replacement) {
            $value = str_replace('{' . $token . '}', (string) $replacement, $value);
        }

        return $value;
    }

    private function normalize(string $locale): string
    {
        $locale = strtolower(trim($locale));

        if (array_key_exists($locale, $this->available)) {
            return $locale;
        }

        $base = substr($locale, 0, 2);

        return array_key_exists($base, $this->available) ? $base : 'en';
    }

    private function lookup(string $locale, string $key): ?string
    {
        $messages = $this->load($locale);
        $segments = explode('.', $key);
        $node = $messages;

        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }

    private function load(string $locale): array
    {
        if (!isset($this->cache[$locale])) {
            $file = __DIR__ . '/../../resources/lang/' . $locale . '.php';
            $this->cache[$locale] = is_file($file) ? (require $file) : [];
        }

        return $this->cache[$locale];
    }
}
