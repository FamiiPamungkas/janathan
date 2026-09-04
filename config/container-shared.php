<?php

declare(strict_types=1);

use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\TranslationService;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$basePath = rtrim((string) config('APP_BASE_PATH', ''), '/');

return [
    'locales' => [
        'en' => 'English',
        'id' => 'Bahasa Indonesia',
    ],

    TranslationService::class => function (ContainerInterface $container) {
        return new TranslationService(
            (string) ($_SESSION['locale'] ?? 'en'),
            $container->get('locales')
        );
    },

    App::class => function (ContainerInterface $container) use ($basePath) {
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->setBasePath($basePath);
        $app->addErrorMiddleware(
            (bool) config('APP_DEBUG', false),
            true,
            true
        );
        return $app;
    },

    FlashService::class => fn (ContainerInterface $container) => new FlashService(),

    Environment::class => function (ContainerInterface $container) use ($basePath) {
        $loader = new FilesystemLoader(
            config('TEMPLATE_DIR', null) ?? __DIR__ . '/../templates'
        );
        $twig = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
        ]);

        $app = $container->get(App::class);

        $twig->addFunction(new \Twig\TwigFunction('asset', function (string $path) use ($basePath) {
            return $basePath . '/' . ltrim($path, '/');
        }));

        $twig->addFunction(new \Twig\TwigFunction('base_path', function () use ($app) {
            return $app->getBasePath();
        }));

        $twig->addFunction(new \Twig\TwigFunction('url_for', function (string $name, array $params = []) use ($app) {
            return $app->getRouteCollector()->getRouteParser()->urlFor($name, $params);
        }));

        $twig->addFunction(new \Twig\TwigFunction('path_info', function () use ($app) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $basePath = $app->getBasePath();

            if ($basePath !== '') {
                if ($path === $basePath) {
                    return '/';
                }
                if (str_starts_with($path, $basePath . '/')) {
                    $path = substr($path, strlen($basePath));
                }
            }

            return $path === '' ? '/' : $path;
        }));

        $twig->addFunction(new \Twig\TwigFunction('flash', function () use ($container) {
            return $container->get(FlashService::class)->all();
        }));

        $translator = $container->get(TranslationService::class);
        $twig->addFunction(new \Twig\TwigFunction('trans', function (string $key, array $replace = []) use ($translator) {
            return $translator->trans($key, $replace);
        }));
        $twig->addGlobal('locale', $translator->getLocale());
        $twig->addGlobal('locales', $translator->getAvailable());

        return $twig;
    },
];
