<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\FlashService;
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
        private readonly RouterRepository $routers,
        private readonly FlashService     $flash
    ) {
    }

    public function users(Request $request, Response $response): Response
    {
        return $this->renderPlaceholder($response, 'User List');
    }

    public function profiles(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $data = $this->hotspot->getProfiles((int) $_SESSION['router_id']);
        } catch (\Throwable $e) {
            return $this->renderUnreachable($response, $e);
        }

        $html = $this->twig->render('pages/hotspot/profiles.twig', $data);
        $response->getBody()->write($html);

        return $response;
    }

    public function showCreate(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        return $this->renderForm($request, $response, null, []);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $values = $this->extractValues($request->getParsedBody());
        $errors = $this->validate($values);

        if ($errors !== []) {
            return $this->renderForm($request, $response, null, $errors, $values);
        }

        try {
            error_log("VALUES ".print_r($values,true));
            $this->hotspot->createProfile((int) $_SESSION['router_id'], $values);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.profiles');
        }

        $this->flash->add('success', 'Profile "' . $values['name'] . '" created.');

        return $this->redirect($response, $request, 'hotspot.profiles');
    }

    public function showEdit(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $profile = $this->hotspot->getProfile((int) $_SESSION['router_id'], $args['id']);
        } catch (\Throwable $e) {
            return $this->renderUnreachable($response, $e);
        }

        if ($profile === null) {
            $this->flash->add('error', 'Profile not found.');

            return $this->redirect($response, $request, 'hotspot.profiles');
        }

        return $this->renderForm($request, $response, $profile, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $values = $this->extractValues($request->getParsedBody());
        $errors = $this->validate($values);

        if ($errors !== []) {
            $values['id'] = $args['id'];

            return $this->renderForm($request, $response, $values, $errors, $values);
        }

        try {
            $this->hotspot->updateProfile((int) $_SESSION['router_id'], $args['id'], $values);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.profiles');
        }

        $this->flash->add('success', 'Profile "' . $values['name'] . '" updated.');

        return $this->redirect($response, $request, 'hotspot.profiles');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $this->hotspot->removeProfile((int) $_SESSION['router_id'], $args['id']);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.profiles');
        }

        $this->flash->add('success', 'Profile removed.');

        return $this->redirect($response, $request, 'hotspot.profiles');
    }

    private function withoutRouter(Request $request, Response $response): ?Response
    {
        if (empty($_SESSION['router_id'])) {
            return $this->redirect($response, $request, 'routers.index');
        }

        if ($this->routers->find((int) $_SESSION['router_id']) === null) {
            unset($_SESSION['router_id']);

            return $this->redirect($response, $request, 'routers.index');
        }

        return null;
    }

    private function renderUnreachable(Response $response, \Throwable $e): Response
    {
        $html = $this->twig->render('pages/dashboard_error.twig', [
            'message' => $e->getMessage(),
        ]);
        $response->getBody()->write($html);

        return $response;
    }

    private function renderForm(
        Request  $request,
        Response $response,
        ?array   $profile,
        array    $errors,
        array    $values = []
    ): Response
    {
        $html = $this->twig->render('pages/hotspot/profile_form.twig', [
            'profile' => $profile,
            'errors' => $errors,
            'values' => $values,
            'formAction' => $profile === null ? 'hotspot.profiles.store' : 'hotspot.profiles.update',
            'formParams' => $profile === null ? [] : ['id' => $profile['id']],
            'isEdit' => $profile !== null,
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($errors !== [] ? 422 : 200);
    }

    private function renderPlaceholder(Response $response, string $title): Response
    {
        $html = $this->twig->render('pages/hotspot/placeholder.twig', [
            'title' => $title,
        ]);
        $response->getBody()->write($html);

        return $response;
    }

    /**
     * @return array{name: string, rate_limit: string, shared_users: string, idle_timeout: string, session_timeout: string, keepalive_timeout: string, mac_cookie: bool, addresses_pool: string, on_login: string, on_logout: string}
     */
    private function extractValues(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        return [
            'name' => trim((string) ($body['name'] ?? '')),
            'rate_limit' => trim((string) ($body['rate_limit'] ?? '')),
            'shared_users' => trim((string) ($body['shared_users'] ?? '')),
            'idle_timeout' => trim((string) ($body['idle_timeout'] ?? '')),
            'session_timeout' => trim((string) ($body['session_timeout'] ?? '')),
            'keepalive_timeout' => trim((string) ($body['keepalive_timeout'] ?? '')),
            'mac_cookie' => !empty($body['mac_cookie']),
            'addresses_pool' => trim((string) ($body['addresses_pool'] ?? '')),
            'on_login' => trim((string) ($body['on_login'] ?? '')),
            'on_logout' => trim((string) ($body['on_logout'] ?? '')),
        ];
    }

    private function validate(array $values): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($values['name']) > 63) {
            $errors['name'] = 'Name must be 63 characters or fewer.';
        }

        if ($values['shared_users'] === '' || !ctype_digit($values['shared_users'])) {
            $errors['shared_users'] = 'Shared users must be a number.';
        } elseif ((int) $values['shared_users'] < 1 || (int) $values['shared_users'] > 255) {
            $errors['shared_users'] = 'Shared users must be between 1 and 255.';
        }

        if ($values['rate_limit'] !== '' && preg_match('/[^0-9kKmMgG.\/\\s]/', $values['rate_limit']) === 1) {
            $errors['rate_limit'] = 'Invalid rate limit format.';
        }

        foreach (['idle_timeout', 'session_timeout', 'keepalive_timeout'] as $field) {
            if ($values[$field] !== '' && preg_match('/^(none|[0-9]+[smhdw])$/i', $values[$field]) !== 1) {
                $errors[$field] = 'Use a value like 30m, 1h or none.';
            }
        }

        if ($values['addresses_pool'] !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $values['addresses_pool']) !== 1) {
            $errors['addresses_pool'] = 'Invalid pool name.';
        }

        foreach (['on_login', 'on_logout'] as $field) {
            if (strlen($values[$field]) > 4096) {
                $errors[$field] = 'Script must be 4096 characters or fewer.';
            }
        }

        return $errors;
    }
}
