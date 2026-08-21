<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Support;

class Logger
{
    public static function log(string $label, mixed $value = []): void
    {
        error_log($label . ' ' . self::format($value));
    }

    /**
     * Safely render a value for error_log.
     *
     * Never dumps full objects (they can hold router credentials or huge
     * internal state) and never expands a Throwable's full backtrace — both
     * will balloon memory (print_r of an exception captures every frame's
     * args/object). Throwables get a one-line message + trace string; objects
     * are summarized by class; arrays are depth- and size-capped.
     */
    private static function format(mixed $value, int $depth = 0): string
    {
        if ($value instanceof \Throwable) {
            return $value->getMessage() . "\n" . $value->getTraceAsString();
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        if (is_array($value)) {
            if ($depth > 3) {
                return '[array:' . count($value) . ']';
            }

            $parts = [];
            $i = 0;
            foreach ($value as $key => $item) {
                if ($i++ >= 50) {
                    $parts[] = '... (' . count($value) . ' items)';
                    break;
                }
                $parts[] = $key . ' => ' . self::format($item, $depth + 1);
            }

            return '[ ' . implode(', ', $parts) . ' ]';
        }

        if (is_object($value)) {
            return '(' . get_class($value) . ')';
        }

        return gettype($value);
    }
}
