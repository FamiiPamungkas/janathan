<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AuthController
{
    use RedirectsTrait;

    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private FlashService $flash
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user_id'])) {
            return $this->redirect($response, $request, 'home');
        }

        $html = $this->twig->render('pages/auth/login.twig');
        $response->getBody()->write($html);

        return $response;
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $user = $this->users->findByUsername($username);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->flash->add('error', 'Invalid username or password.');

            return $this->redirect($response, $request, 'login');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        return $this->redirect($response, $request, 'home');
    }

    public function logout(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
        }

        return $this->redirect($response, $request, 'login');
    }
}
