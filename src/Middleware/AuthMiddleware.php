<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Middleware;

use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use Slim\Routing\RouteContext;
use Twig\Environment;

class AuthMiddleware
{
    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private RouterRepository $routers
    ) {
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $user = null;

        if (!empty($_SESSION['user_id'])) {
            $user = $this->users->find((int) $_SESSION['user_id']);
        }

        if ($user === null) {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('login');
            $response = new SlimResponse();

            return $response->withHeader('Location', $url)->withStatus(302);
        }

        $activeRouter = null;
        if (!empty($_SESSION['router_id'])) {
            $activeRouter = $this->routers->find((int) $_SESSION['router_id']);
        }

        $this->twig->addGlobal('current_user', $user);
        $this->twig->addGlobal('routers', $this->routers->all());
        $this->twig->addGlobal('active_router', $activeRouter);

        $request = $request->withAttribute('user', $user);

        return $handler->handle($request);
    }
}
