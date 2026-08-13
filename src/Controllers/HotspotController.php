<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\HotspotService;
use Fame1302\Janathan\Services\RouterRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class HotspotController
{
    use RedirectsTrait;

    public function __construct(
        private readonly Environment      $twig,
        private readonly HotspotService   $hotspot,
        private readonly RouterRepository $routers
    ) {
    }

    public function users(Request $request, Response $response): Response
    {
        return $this->renderPlaceholder($response, 'User List');
    }

    public function profiles(Request $request, Response $response): Response
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
            $data = $this->hotspot->getProfiles($routerId);
        } catch (\Throwable $e) {
            $html = $this->twig->render('pages/dashboard_error.twig', [
                'message' => $e->getMessage(),
            ]);
            $response->getBody()->write($html);

            return $response;
        }

        $html = $this->twig->render('pages/hotspot/profiles.twig', $data);
        $response->getBody()->write($html);

        return $response;
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
