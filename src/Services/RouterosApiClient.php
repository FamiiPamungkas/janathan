<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use RouterOS\Client;

/**
 * Preserve multiline RouterOS attribute values such as on-login scripts.
 * The upstream parser's expression stops at the first newline.
 */
class RouterosApiClient extends Client
{
    protected function pregResponse(string $value, ?array &$matches): void
    {
        preg_match_all('/^[=|.]([.\w-]+)=(.*)/s', $value, $matches);
    }
}
