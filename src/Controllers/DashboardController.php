<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\DashboardService;
use Fame1302\Janathan\Services\RouterRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class DashboardController
{
    use RedirectsTrait;

    public function __construct(
        private Environment $twig,
        private DashboardService $dashboard,
        private RouterRepository $routers
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if (empty($_SESSION['router_id'])) {
            return $this->redirect($response, $request, 'routers.index');
        }

        $routerId = (int) $_SESSION['router_id'];

        if ($this->routers->find($routerId) === null) {
            unset($_SESSION['router_id']);

            return $this->redirect($response, $request, 'routers.index');
        }

        try {
            $data = $this->dashboard->getDashboardData($routerId);
        } catch (\Throwable $e) {
            $html = $this->twig->render('pages/dashboard_error.twig', [
                'message' => $e->getMessage(),
            ]);
            $response->getBody()->write($html);

            return $response;
        }

        $html = $this->twig->render('pages/home.twig', $data);
        $response->getBody()->write($html);

        return $response;
    }
}
