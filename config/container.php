<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteParserInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return [
    App::class => function (ContainerInterface $container) {
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addErrorMiddleware(
            (bool)$_ENV['APP_DEBUG'],
            true,
            true
        );
        return $app;
    },

    Environment::class => function (ContainerInterface $container) {
        $loader = new FilesystemLoader(
            $_SERVER['TEMPLATE_DIR'] ?? $_ENV['TEMPLATE_DIR'] ?? __DIR__ . '/../templates'
        );
        $twig = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
        ]);

        $app = $container->get(App::class);

        $twig->addFunction(new \Twig\TwigFunction('asset', function (string $path) {
            return '/' . ltrim($path, '/');
        }));

        $twig->addFunction(new \Twig\TwigFunction('base_path', function () use ($app) {
            return $app->getBasePath();
        }));

        $twig->addFunction(new \Twig\TwigFunction('url_for', function (string $name, array $params = []) use ($app) {
            return $app->getRouteCollector()->getRouteParser()->urlFor($name, $params);
        }));

        return $twig;
    },
];
