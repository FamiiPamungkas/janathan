<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Twig\Environment;

return function (App $app): void {
    $container = $app->getContainer();

    $app->get('/', function (Request $request, Response $response) use ($container) {
        $twig = $container->get(Environment::class);
        $html = $twig->render('pages/home.twig', [
            'name' => 'Janathan',
        ]);
        $response->getBody()->write($html);
        return $response;
    })->setName('home');
};
