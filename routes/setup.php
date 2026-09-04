<?php

declare(strict_types=1);

use Fame1302\Janathan\Controllers\SetupController;
use Slim\App;

return function (App $app): void {
    $app->get('/', fn ($req, $res) => $res->withHeader('Location', '/setup')->withStatus(302));
    $app->get('/setup', SetupController::class . ':show')->setName('setup.show');
    $app->post('/setup', SetupController::class . ':install')->setName('setup.install');
};
