<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\DashboardService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class HomeController
{
    public function __construct(
        private Environment $twig,
        private DashboardService $dashboard,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $html = $this->twig->render('pages/home.twig', $this->dashboard->getDashboardData());
        $response->getBody()->write($html);
        return $response;
    }
}
