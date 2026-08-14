<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Support;

class Logger
{
    public static function log(string $label, mixed $value): void
    {
        if (is_scalar($value) || $value === null) {
            $message = $label . ' ' . (string)$value;
        } else {
            $message = $label . "\n" . print_r($value, true);
        }

        error_log($message);
    }
}
