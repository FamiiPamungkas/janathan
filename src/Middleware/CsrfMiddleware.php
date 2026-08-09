<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use Twig\Environment;

class CsrfMiddleware
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $token = $_SESSION['csrf_token'];
        $this->twig->addGlobal('csrf_token', $token);
        $request = $request->withAttribute('csrf_token', $token);

        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = $request->getParsedBody();
            $submitted = is_array($body) ? ($body['csrf_token'] ?? '') : '';

            if (!is_string($submitted) || !hash_equals($token, $submitted)) {
                $response = new SlimResponse();

                return $response->withStatus(419);
            }
        }

        return $handler->handle($request);
    }
}
