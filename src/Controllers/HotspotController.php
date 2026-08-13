<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class HotspotController
{
    public function __construct(
        private readonly Environment $twig
    ) {
    }

    public function users(Request $request, Response $response): Response
    {
        return $this->renderPlaceholder($response, 'User List');
    }

    public function profiles(Request $request, Response $response): Response
    {
        return $this->renderPlaceholder($response, 'Profile List');
    }

    private function renderPlaceholder(Response $response, string $title): Response
    {
        $html = $this->twig->render('pages/hotspot/placeholder.twig', [
            'title' => $title,
        ]);
        $response->getBody()->write($html);

        return $response;
    }
}
