<?php

declare(strict_types=1);

use Fame1302\Janathan\Controllers\SetupController;
use Slim\App;
use Slim\Routing\RouteContext;

return function (App $app): void {
    $app->get('/', function ($request, $res) {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('setup.show');
        return $res->withHeader('Location', $url)->withStatus(302);
    })->setName('home');
    $app->get('/setup', SetupController::class . ':show')->setName('setup.show');
    $app->post('/setup', SetupController::class . ':install')->setName('setup.install');
};
