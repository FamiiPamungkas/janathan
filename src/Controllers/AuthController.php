<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\TranslationService;
use Fame1302\Janathan\Services\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Twig\Environment;

class AuthController
{
    use RedirectsTrait;

    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private FlashService $flash,
        private TranslationService $translator
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user_id'])) {
            return $this->redirect($response, $request, 'dashboard');
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
            $this->flash->add('error', $this->translator->trans('auth.invalid_credentials'));

            return $this->redirect($response, $request, 'login');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['locale'] = $user['locale'] ?? 'en';

        return $this->redirect($response, $request, 'dashboard');
    }

    public function setLocale(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $locale = strtolower(trim((string) ($body['locale'] ?? '')));
        $available = $this->translator->getAvailable();

        if (!array_key_exists($locale, $available)) {
            $locale = 'en';
        }

        $_SESSION['locale'] = $locale;

        if (!empty($_SESSION['user_id'])) {
            $this->users->updateLocale((int) $_SESSION['user_id'], $locale);
        }

        return $this->redirectBack($response, $request);
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

    public function showEdit(Request $request, Response $response): Response
    {
        $user = $this->users->find((int) $_SESSION['user_id']);

        if ($user === null) {
            return $this->redirect($response, $request, 'login');
        }

        $html = $this->twig->render('pages/admin/edit.twig', [
            'user' => $user,
            'locales' => $this->translator->getAvailable(),
        ]);
        $response->getBody()->write($html);

        return $response;
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $id = (int) $_SESSION['user_id'];
        $user = $this->users->find($id);

        if ($user === null) {
            return $this->redirect($response, $request, 'login');
        }

        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $locale = strtolower(trim((string) ($body['locale'] ?? '')));

        $errors = [];

        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif ($this->users->usernameExists($username, $id)) {
            $errors['username'] = 'That username is already taken.';
        }

        $available = $this->translator->getAvailable();
        if (!array_key_exists($locale, $available)) {
            $locale = $user['locale'] ?? 'en';
        }

        if ($errors !== []) {
            $html = $this->twig->render('pages/admin/edit.twig', [
                'user' => $user,
                'locales' => $available,
                'values' => ['username' => $username, 'locale' => $locale],
                'errors' => $errors,
            ]);
            $response->getBody()->write($html);

            return $response;
        }

        $data = [
            'username' => $username,
            'locale' => $locale,
        ];

        if ($password !== '') {
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $this->users->updateProfile($id, $data);

        if ($locale !== ($user['locale'] ?? 'en')) {
            $_SESSION['locale'] = $locale;
        }

        $this->flash->add('success', $this->translator->trans('auth.account_updated'));

        return $this->redirect($response, $request, 'admin.edit');
    }
}
