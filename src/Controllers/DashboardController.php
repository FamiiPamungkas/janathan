<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\DashboardService;
use Fame1302\Janathan\Services\RouterRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Twig\Environment;

class DashboardController
{
    use RedirectsTrait;

    public function __construct(
        private readonly Environment      $twig,
        private readonly DashboardService $dashboard,
        private readonly RouterRepository $routers
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

    public function data(Request $request, Response $response): Response
    {
        if (empty($_SESSION['router_id'])) {
            return $this->json($response, ['error' => 'No router selected.'], 401);
        }

        try {
            $data = $this->dashboard->getDashboardData((int) $_SESSION['router_id']);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 502);
        }

        return $this->json($response, $data);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response = new SlimResponse($status);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
