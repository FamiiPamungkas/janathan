<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Fame1302\Janathan\Middleware\CsrfMiddleware;
use Fame1302\Janathan\Middleware\LocaleMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;

require __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

return function (bool $needsSetup): App {
    $containerBuilder = new ContainerBuilder();

    if ($needsSetup) {
        // Setup mode: DB-free definitions only (Twig + Flash). No PDO/
        // repositories, no CSRF/Locale middleware.
        $containerBuilder->addDefinitions(__DIR__ . '/container-setup.php');
    } else {
        $containerBuilder->addDefinitions(__DIR__ . '/container.php');
    }

    /** @var ContainerInterface $container */
    $container = $containerBuilder->build();
    $app = $container->get(App::class);

    $routes = $needsSetup
        ? require __DIR__ . '/../routes/setup.php'
        : require __DIR__ . '/../routes/web.php';
    $routes($app);

    if (!$needsSetup) {
        $app->add(CsrfMiddleware::class);
        $app->add(LocaleMiddleware::class);
    }

    return $app;
};
