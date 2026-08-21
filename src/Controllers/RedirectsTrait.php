<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Exceptions\RouterosConnectionException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
use Throwable;
use Twig\Environment;

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

    protected function urlFor(Request $request, string $name, array $params = []): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($name, $params);
    }

    /**
     * Redirect to the routers list when no router is selected or the stored one
     * no longer exists. Returns null when a router is active.
     */
    protected function withoutRouter(Request $request, Response $response): ?Response
    {
        if (empty($_SESSION['router_id'])) {
            return $this->redirect($response, $request, 'routers.index');
        }

        if ($this->routers->find((int)$_SESSION['router_id']) === null) {
            unset($_SESSION['router_id']);

            return $this->redirect($response, $request, 'routers.index');
        }

        return null;
    }

    /**
     * Render the generic "router unreachable" page, classifying timeout vs.
     * connection vs. other failures for a friendlier message.
     */
    protected function renderUnreachable(Request $request, Response $response, Throwable $e, string $retryRoute = 'dashboard'): Response
    {
        if ($e instanceof RouterosConnectionException) {
            $errorType = str_contains(strtolower($e->getMessage()), 'timeout') ? 'timeout' : 'connection';
        } else {
            $errorType = 'unreachable';
        }

        $html = $this->twig->render('pages/dashboard_error.twig', [
            'message' => $e->getMessage(),
            'errorType' => $errorType,
            'retryUrl' => $this->urlFor($request, $retryRoute),
        ]);
        $response->getBody()->write($html);

        return $response;
    }
}
