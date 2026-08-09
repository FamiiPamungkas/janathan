<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Fame1302\Janathan\Middleware\CsrfMiddleware;
use Slim\App;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/container.php');

$container = $containerBuilder->build();
$app = $container->get(App::class);

$routes = require __DIR__ . '/../routes/web.php';
$routes($app);

$app->add(CsrfMiddleware::class);

return $app;
