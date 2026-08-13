<?php

declare(strict_types=1);

use Fame1302\Janathan\Services\CryptoService;
use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\RouterosClientFactory;
use Fame1302\Janathan\Services\UserRepository;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return [
    App::class => function (ContainerInterface $container) {
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addErrorMiddleware(
            (bool) $_ENV['APP_DEBUG'],
            true,
            true
        );
        return $app;
    },

    PDO::class => function (ContainerInterface $container) {
        $configured = (string) ($_ENV['DB_PATH'] ?? 'database/janathan.sqlite');
        $path = $configured;

        if ($configured !== '' && !preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $configured)) {
            $path = __DIR__ . '/../' . ltrim($configured, '/');
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    },

    CryptoService::class => fn (ContainerInterface $container) => new CryptoService((string) ($_ENV['APP_KEY'] ?? '')),

    FlashService::class => fn (ContainerInterface $container) => new FlashService(),

    UserRepository::class => function (ContainerInterface $container) {
        return new UserRepository($container->get(PDO::class));
    },

    RouterRepository::class => function (ContainerInterface $container) {
        return new RouterRepository($container->get(PDO::class), $container->get(CryptoService::class));
    },

    RouterosClientFactory::class => fn (ContainerInterface $container) => new RouterosClientFactory(),

    Environment::class => function (ContainerInterface $container) {
        $loader = new FilesystemLoader(
            $_SERVER['TEMPLATE_DIR'] ?? $_ENV['TEMPLATE_DIR'] ?? __DIR__ . '/../templates'
        );
        $twig = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
        ]);

        $app = $container->get(App::class);

        $twig->addGlobal('app_url', rtrim((string) ($_ENV['APP_URL'] ?? ''), '/'));
        $twig->addFunction(new \Twig\TwigFunction('asset', function (string $path) {
            return '/' . ltrim($path, '/');
        }));

        $twig->addFunction(new \Twig\TwigFunction('base_path', function () use ($app) {
            return $app->getBasePath();
        }));

        $twig->addFunction(new \Twig\TwigFunction('url_for', function (string $name, array $params = []) use ($app) {
            return $app->getRouteCollector()->getRouteParser()->urlFor($name, $params);
        }));

        $twig->addFunction(new \Twig\TwigFunction('path_info', function () {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

            return $path;
        }));

        $twig->addFunction(new \Twig\TwigFunction('flash', function () use ($container) {
            return $container->get(FlashService::class)->all();
        }));

        return $twig;
    },
];
