<?php

declare(strict_types=1);

use Fame1302\Janathan\Controllers\AuthController;
use Fame1302\Janathan\Controllers\DashboardController;
use Fame1302\Janathan\Controllers\HotspotController;
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
            $app->get('/data', DashboardController::class . ':data')->setName('dashboard.data');
            $app->get('/logs', DashboardController::class . ':logs')->setName('dashboard.logs');
        });

        $app->group('/hotspot', function (RouteCollectorProxy $app) {
            $app->get('/users', HotspotController::class . ':users')->setName('hotspot.users');
            $app->get('/users/create', HotspotController::class . ':showCreateUser')->setName('hotspot.users.create');
            $app->post('/users', HotspotController::class . ':createUser')->setName('hotspot.users.store');
            $app->get('/users/{id}/edit', HotspotController::class . ':showEditUser')->setName('hotspot.users.edit');
            $app->post('/users/{id}/edit', HotspotController::class . ':updateUser')->setName('hotspot.users.update');
            $app->post('/users/{id}/delete', HotspotController::class . ':deleteUser')->setName('hotspot.users.delete');

            $app->get('/profiles', HotspotController::class . ':profiles')->setName('hotspot.profiles');
            $app->get('/profiles/create', HotspotController::class . ':showCreate')->setName('hotspot.profiles.create');
            $app->post('/profiles', HotspotController::class . ':create')->setName('hotspot.profiles.store');
            $app->get('/profiles/{id}/edit', HotspotController::class . ':showEdit')->setName('hotspot.profiles.edit');
            $app->post('/profiles/{id}/edit', HotspotController::class . ':update')->setName('hotspot.profiles.update');
            $app->post('/profiles/{id}/delete', HotspotController::class . ':delete')->setName('hotspot.profiles.delete');
        });

        $app->get('/routers', RouterController::class . ':index')->setName('routers.index');
        $app->get('/routers/create', RouterController::class . ':showCreate')->setName('routers.create');
        $app->post('/routers', RouterController::class . ':create')->setName('routers.store');
        $app->get('/routers/{id}/edit', RouterController::class . ':showEdit')->setName('routers.edit');
        $app->post('/routers/{id}/edit', RouterController::class . ':update')->setName('routers.update');
        $app->post('/routers/{id}/delete', RouterController::class . ':delete')->setName('routers.delete');
        $app->post('/routers/{id}/connect', RouterController::class . ':connect')->setName('routers.connect');
        $app->post('/routers/disconnect', RouterController::class . ':disconnect')->setName('routers.disconnect');
    })->add(AuthMiddleware::class);
};
