<?php

declare(strict_types=1);

/**
 * Generates a production .env from .env.example for the deploy package.
 *
 * Usage:
 *   php scripts/generate-env.php <source> <target> [--base=<path>] [--url=<url>]
 *
 * --base defaults to /janathan. Pass --base= (empty) to deploy with the
 * document root pointing straight at public/.
 */

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php generate-env.php <source> <target> [--base=] [--url=]\n");
    exit(1);
}

[$source, $target] = $args;

$options = ['base' => '/janathan', 'url' => null];
foreach (array_slice($args, 2) as $arg) {
    if (preg_match('/^--base=(.*)$/', $arg, $m)) {
        $options['base'] = $m[1];
    } elseif (preg_match('/^--url=(.*)$/', $arg, $m)) {
        $options['url'] = $m[1] !== '' ? $m[1] : null;
    }
}

if (!is_file($source)) {
    fwrite(STDERR, "Source not found: {$source}\n");
    exit(1);
}

$basePath = rtrim(trim((string) $options['base']), '/');
if ($basePath !== '' && !str_starts_with($basePath, '/')) {
    $basePath = '/' . $basePath;
}

$lines = file($source, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Could not read source: {$source}\n");
    exit(1);
}

foreach ($lines as $i => $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }

    [$key] = explode('=', $line, 2);

    switch (trim($key)) {
        case 'APP_ENV':
            $lines[$i] = 'APP_ENV=production';
            break;
        case 'APP_DEBUG':
            $lines[$i] = 'APP_DEBUG=false';
            break;
        case 'APP_KEY':
            $lines[$i] = 'APP_KEY=' . bin2hex(random_bytes(32));
            break;
        case 'APP_BASE_PATH':
            $lines[$i] = 'APP_BASE_PATH=' . $basePath;
            break;
        case 'APP_URL':
            if ($options['url'] !== null) {
                $lines[$i] = 'APP_URL=' . rtrim($options['url'], '/');
            }
            break;
    }
}

file_put_contents($target, implode("\n", $lines) . "\n");

printf(
    "Wrote %s (APP_ENV=production, APP_DEBUG=false, APP_KEY set, APP_BASE_PATH=%s)\n",
    $target,
    $basePath === '' ? '(root)' : $basePath
);