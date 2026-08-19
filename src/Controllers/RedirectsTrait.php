<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

trait RedirectsTrait
{
    protected function redirect(Response $response, Request $request, string $name, array $params = []): Response
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($name, $params);

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    protected function redirectBack(Response $response, Request $request, string $fallback = 'dashboard'): Response
    {
        $referer = $request->getHeaderLine('Referer');

        if ($referer === '') {
            return $this->redirect($response, $request, $fallback);
        }

        return $response->withHeader('Location', $referer)->withStatus(302);
    }
}
