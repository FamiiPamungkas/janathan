<?php

declare(strict_types=1);

use Fame1302\Janathan\Controllers\AuthController;
use Fame1302\Janathan\Controllers\DashboardController;
use Fame1302\Janathan\Controllers\RouterController;
use Fame1302\Janathan\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Slim\Routing\RouteContext;

return function (App $app): void {
    $app->get('/login', AuthController::class . ':showLogin')->setName('login');
    $app->post('/login', AuthController::class . ':login')->setName('login.post');
    $app->post('/logout', AuthController::class . ':logout')->setName('logout');

    $app->group('', function (RouteCollectorProxy $app) {
        $app->get('/', function (Request $request, Response $response) {
            $name = empty($_SESSION['router_id']) ? 'routers.index' : 'dashboard';
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($name);

            return $response->withHeader('Location', $url)->withStatus(302);
        })->setName('home');

        $app->group('/dashboard', function (RouteCollectorProxy $app) {
            $app->get('', DashboardController::class . ':index')->setName('dashboard');
        });

        $app->get('/routers', RouterController::class . ':index')->setName('routers.index');
        $app->get('/routers/create', RouterController::class . ':showCreate')->setName('routers.create');
        $app->post('/routers', RouterController::class . ':create')->setName('routers.store');
        $app->get('/routers/{id}/edit', RouterController::class . ':showEdit')->setName('routers.edit');
        $app->post('/routers/{id}/edit', RouterController::class . ':update')->setName('routers.update');
        $app->post('/routers/{id}/delete', RouterController::class . ':delete')->setName('routers.delete');
        $app->post('/routers/{id}/connect', RouterController::class . ':connect')->setName('routers.connect');
    })->add(AuthMiddleware::class);
};
