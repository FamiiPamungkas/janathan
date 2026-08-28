<?php

declare(strict_types=1);

use Fame1302\Janathan\Controllers\AuthController;
use Fame1302\Janathan\Controllers\DashboardController;
use Fame1302\Janathan\Controllers\HotspotController;
use Fame1302\Janathan\Controllers\ProfileController;
use Fame1302\Janathan\Controllers\RouterController;
use Fame1302\Janathan\Controllers\VoucherTemplateController;
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
    $app->post('/locale', AuthController::class . ':setLocale')->setName('locale.set');

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
            $app->get('/active', HotspotController::class . ':activeUsers')->setName('hotspot.active');
            $app->get('/active/data', HotspotController::class . ':activeData')->setName('hotspot.active.data');
            $app->post('/active/{id}/remove', HotspotController::class . ':removeActiveUser')->setName('hotspot.active.remove');
            $app->get('/hosts', HotspotController::class . ':hosts')->setName('hotspot.hosts');
            $app->get('/hosts/data', HotspotController::class . ':hostsData')->setName('hotspot.hosts.data');
            $app->post('/hosts/{id}/remove', HotspotController::class . ':removeHost')->setName('hotspot.hosts.remove');
            $app->get('/users/create', HotspotController::class . ':showCreateUser')->setName('hotspot.users.create');
            $app->post('/users', HotspotController::class . ':createUser')->setName('hotspot.users.store');
            $app->get('/users/{id}/edit', HotspotController::class . ':showEditUser')->setName('hotspot.users.edit');
            $app->post('/users/{id}/edit', HotspotController::class . ':updateUser')->setName('hotspot.users.update');
            $app->post('/users/{id}/delete', HotspotController::class . ':deleteUser')->setName('hotspot.users.delete');
            $app->get('/users/{id}/print', HotspotController::class . ':printUser')->setName('hotspot.users.print');
            $app->get('/users/print', HotspotController::class . ':printUsers')->setName('hotspot.users.printMany');
            $app->get('/users/export', HotspotController::class . ':exportUsers')->setName('hotspot.users.export');
            $app->post('/users/delete-by-comment', HotspotController::class . ':deleteUsersByComment')->setName('hotspot.users.deleteByComment');
            $app->get('/users/generate', HotspotController::class . ':showGenerate')->setName('hotspot.users.generate');
            $app->post('/users/generate', HotspotController::class . ':generateUsers')->setName('hotspot.users.generate.store');

            $app->get('/profiles', ProfileController::class . ':index')->setName('hotspot.profiles');
            $app->get('/profiles/create', ProfileController::class . ':showCreate')->setName('hotspot.profiles.create');
            $app->post('/profiles', ProfileController::class . ':create')->setName('hotspot.profiles.store');
            $app->get('/profiles/{id}/edit', ProfileController::class . ':showEdit')->setName('hotspot.profiles.edit');
            $app->post('/profiles/{id}/edit', ProfileController::class . ':update')->setName('hotspot.profiles.update');
            $app->post('/profiles/{id}/delete', ProfileController::class . ':delete')->setName('hotspot.profiles.delete');
        });

        $app->group('/voucher-templates', function (RouteCollectorProxy $app) {
            $app->get('', VoucherTemplateController::class . ':index')->setName('voucher_templates.index');
            $app->get('/create', VoucherTemplateController::class . ':showCreate')->setName('voucher_templates.create');
            $app->post('', VoucherTemplateController::class . ':create')->setName('voucher_templates.store');
            $app->get('/{id}/edit', VoucherTemplateController::class . ':showEdit')->setName('voucher_templates.edit');
            $app->post('/{id}/edit', VoucherTemplateController::class . ':update')->setName('voucher_templates.update');
            $app->post('/{id}/delete', VoucherTemplateController::class . ':delete')->setName('voucher_templates.delete');
            $app->get('/profiles', VoucherTemplateController::class . ':profilesJson')->setName('voucher_templates.profiles');
            $app->get('/{id}/preview-render', VoucherTemplateController::class . ':previewRender')->setName('voucher_templates.preview_render');
        });

        $app->get('/routers', RouterController::class . ':index')->setName('routers.index');
        $app->get('/routers/create', RouterController::class . ':showCreate')->setName('routers.create');
        $app->post('/routers', RouterController::class . ':create')->setName('routers.store');
        $app->get('/routers/{id}/edit', RouterController::class . ':showEdit')->setName('routers.edit');
        $app->post('/routers/{id}/edit', RouterController::class . ':update')->setName('routers.update');
        $app->post('/routers/{id}/delete', RouterController::class . ':delete')->setName('routers.delete');
        $app->post('/routers/{id}/connect', RouterController::class . ':connect')->setName('routers.connect');
        $app->post('/routers/disconnect', RouterController::class . ':disconnect')->setName('routers.disconnect');
            $app->post('/routers/test-connection', RouterController::class . ':testConnection')->setName('routers.testConnection');

            $app->get('/admin/edit', AuthController::class . ':showEdit')->setName('admin.edit');
            $app->post('/admin/edit', AuthController::class . ':updateProfile')->setName('admin.update');
        })->add(AuthMiddleware::class);
};
