<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\HotspotService;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\VoucherTemplateRenderer;
use Fame1302\Janathan\Services\VoucherTemplateRepository;
use Fame1302\Janathan\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class HotspotController
{
    use RedirectsTrait;

    public function __construct(
        private readonly Environment             $twig,
        private readonly HotspotService          $hotspot,
        private readonly RouterRepository        $routers,
        private readonly FlashService            $flash,
        private readonly VoucherTemplateRepository $templates,
        private readonly VoucherTemplateRenderer $voucherRenderer
    )
    {
    }

    public function users(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $params = $request->getQueryParams();
        $filters = [
            'q' => isset($params['q']) ? trim((string)$params['q']) : '',
            'profile' => isset($params['profile']) ? trim((string)$params['profile']) : '',
            'comment' => isset($params['comment']) ? trim((string)$params['comment']) : '',
            'status' => isset($params['status']) ? trim((string)$params['status']) : 'all',
        ];

        try {
            $data = $this->hotspot->getUsers((int)$_SESSION['router_id'], $filters);
        } catch (\Throwable $e) {
            return $this->renderUnreachable($response, $e);
        }

        $data['voucherTemplates'] = array_merge([$this->templates->default()], $this->templates->all());

        $html = $this->twig->render('pages/hotspot/users.twig', $data);
        $response->getBody()->write($html);

        return $response;
    }

    public function printUser(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $routerId = (int)$_SESSION['router_id'];
        $id = (string)$args['id'];
        $params = $request->getQueryParams();
        $templateId = (string)($params['template'] ?? '0');

        try {
            $user = $this->hotspot->getUserForPrint($routerId, $id);
        } catch (\Throwable $e) {
            $this->flash->add('error', 'Cannot reach the router to print this user.');
            return $this->redirect($response, $request, 'hotspot.users');
        }

        if ($user === null) {
            $this->flash->add('error', 'User not found.');
            return $this->redirect($response, $request, 'hotspot.users');
        }

        try {
            $profile = $this->hotspot->getProfileByName($routerId, $user['profile']) ?? [
                'name' => $user['profile'],
                'color' => '',
                'price' => '',
            ];
        } catch (\Throwable $e) {
            $profile = [
                'name' => $user['profile'],
                'color' => '',
                'price' => '',
            ];
        }

        $useDefault = $templateId === '0' || $templateId === 'default';
        $template = $useDefault ? null : $this->templates->find((int)$templateId);

        if ($template === null) {
            $html = $this->voucherRenderer->renderDefaultUser($user, $profile);
        } else {
            $html = $this->voucherRenderer->renderCustomUser($template, $user, $profile);
        }

        $html = preg_replace('#</body>#i', '<script>window.print();</script></body>', $html, 1) ?? $html;

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function showCreateUser(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $params = $request->getQueryParams();
        $defaults = [];
        if (isset($params['profile']) && trim((string)$params['profile']) !== '') {
            $defaults['profile'] = trim((string)$params['profile']);
        }

        return $this->renderUserForm($request, $response, null, [], $defaults);
    }

    public function createUser(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $values = $this->extractUserValues($request->getParsedBody());
        $errors = $this->validateUser($values, false);

        if ($errors !== []) {
            return $this->renderUserForm($request, $response, null, $errors, $values);
        }

        try {
            $this->hotspot->createUser((int)$_SESSION['router_id'], $values);
        } catch (RouterosCommandException $e) {
            [$banner, $fieldErrors] = $this->mapUserRouterError($e->getMessage());

            return $this->renderUserForm($request, $response, null, $fieldErrors + $errors, $values, $banner);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirectUsers($response, $request, $values['profile'] ?? null);
        }

        $this->flash->add('success', 'User "' . $values['name'] . '" created.');

        return $this->redirectUsers($response, $request, $values['profile'] ?? null);
    }

    public function showEditUser(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $user = $this->hotspot->getUser((int)$_SESSION['router_id'], $args['id']);
        } catch (\Throwable $e) {
            return $this->renderUnreachable($response, $e);
        }

        if ($user === null) {
            $this->flash->add('error', 'User not found.');

            return $this->redirect($response, $request, 'hotspot.users');
        }

        return $this->renderUserForm($request, $response, $user, []);
    }

    public function updateUser(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $values = $this->extractUserValues($request->getParsedBody());
        $errors = $this->validateUser($values, true);

        if ($errors !== []) {
            $values['id'] = $args['id'];

            return $this->renderUserForm($request, $response, $values, $errors, $values);
        }

        try {
            $this->hotspot->updateUser((int)$_SESSION['router_id'], $args['id'], $values);
        } catch (RouterosCommandException $e) {
            [$banner, $fieldErrors] = $this->mapUserRouterError($e->getMessage());
            $values['id'] = $args['id'];

            return $this->renderUserForm($request, $response, $values, $fieldErrors + $errors, $values, $banner);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.users');
        }

        $this->flash->add('success', 'User "' . $values['name'] . '" updated.');

        return $this->redirectUsers($response, $request, $values['profile'] ?? null);
    }

    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $body = $request->getParsedBody();
        $profile = is_array($body) ? trim((string)($body['profile'] ?? '')) : '';

        try {
            $this->hotspot->removeUser((int)$_SESSION['router_id'], $args['id']);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirectUsers($response, $request, $profile !== '' ? $profile : null);
        }

        $this->flash->add('success', 'User removed.');

        return $this->redirectUsers($response, $request, $profile !== '' ? $profile : null);
    }

    public function profiles(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $data = $this->hotspot->getProfiles((int)$_SESSION['router_id']);
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
//            error_log("VALUES ".print_r($values,true));
            $this->hotspot->createProfile((int)$_SESSION['router_id'], $values);
        } catch (RouterosCommandException $e) {
            [$banner, $fieldErrors] = $this->mapRouterError($e->getMessage());

            return $this->renderForm($request, $response, null, $fieldErrors + $errors, $values, $banner);
        } catch (\Throwable $e) {
            error_log(print_r($e->getMessage(), true));
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
            $profile = $this->hotspot->getProfile((int)$_SESSION['router_id'], $args['id']);
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
            $this->hotspot->updateProfile((int)$_SESSION['router_id'], $args['id'], $values);
        } catch (RouterosCommandException $e) {
            [$banner, $fieldErrors] = $this->mapRouterError($e->getMessage());
            $values['id'] = $args['id'];

            return $this->renderForm($request, $response, $values, $fieldErrors + $errors, $values, $banner);
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
            $this->hotspot->removeProfile((int)$_SESSION['router_id'], $args['id']);
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

        if ($this->routers->find((int)$_SESSION['router_id']) === null) {
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
        array    $values = [],
        ?string  $errorBanner = null
    ): Response
    {
        $pools = [];

        try {
            $pools = $this->hotspot->getIpPools((int)$_SESSION['router_id']);
        } catch (\Throwable $e) {
            if ($errorBanner === null) {
                $errorBanner = $e->getMessage();
            }
        }

        Logger::log("VALUES ", $values);
        Logger::log("PROFILE ", $profile);
        $html = $this->twig->render('pages/hotspot/profile_form.twig', [
            'profile' => $profile,
            'errors' => $errors,
            'values' => $values,
            'pools' => $pools,
            'errorBanner' => $errorBanner,
            'formAction' => $profile === null ? 'hotspot.profiles.store' : 'hotspot.profiles.update',
            'formParams' => $profile === null ? [] : ['id' => $profile['id']],
            'isEdit' => $profile !== null,
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($errors !== [] ? 422 : 200);
    }

    private function renderUserForm(
        Request  $request,
        Response $response,
        ?array   $user,
        array    $errors,
        array    $values = [],
        ?string  $errorBanner = null
    ): Response {
        $profiles = [];

        try {
            $profiles = $this->hotspot->getProfileNames((int)$_SESSION['router_id']);
        } catch (\Throwable $e) {
            if ($errorBanner === null) {
                $errorBanner = $e->getMessage();
            }
        }

        $listProfile = $values['profile'] ?? ($user['profile'] ?? null);
        $html = $this->twig->render('pages/hotspot/user_form.twig', [
            'user' => $user,
            'errors' => $errors,
            'values' => $values,
            'profiles' => $profiles,
            'errorBanner' => $errorBanner,
            'formAction' => $user === null ? 'hotspot.users.store' : 'hotspot.users.update',
            'formParams' => $user === null ? [] : ['id' => $user['id']],
            'isEdit' => $user !== null,
            'listProfile' => is_string($listProfile) && $listProfile !== '' ? $listProfile : null,
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($errors !== [] ? 422 : 200);
    }

    private function redirectUsers(Response $response, Request $request, ?string $profile): Response
    {
        $url = \Slim\Routing\RouteContext::fromRequest($request)->getRouteParser()->urlFor('hotspot.users');
        if ($profile !== null && $profile !== '') {
            $url .= '?profile=' . rawurlencode($profile);
        }

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    /**
     * @return array{name: string, password: string, profile: string, comment: string, disabled: bool}
     */
    private function extractUserValues(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        return [
            'name' => trim((string)($body['name'] ?? '')),
            'password' => (string)($body['password'] ?? ''),
            'profile' => trim((string)($body['profile'] ?? '')),
            'comment' => trim((string)($body['comment'] ?? '')),
            'disabled' => !empty($body['disabled']),
        ];
    }

    private function validateUser(array $values, bool $isEdit): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($values['name']) > 247) {
            $errors['name'] = 'Name must be 247 characters or fewer.';
        }

        if (!$isEdit && $values['password'] === '') {
            $errors['password'] = 'Password is required.';
        } elseif ($values['password'] !== '' && mb_strlen($values['password']) > 247) {
            $errors['password'] = 'Password must be 247 characters or fewer.';
        }

        if ($values['profile'] === '') {
            $errors['profile'] = 'Profile is required.';
        }

        if (mb_strlen($values['comment']) > 4096) {
            $errors['comment'] = 'Comment must be 4096 characters or fewer.';
        }

        return $errors;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function mapUserRouterError(string $message): array
    {
        $map = [
            'name' => 'name',
            'password' => 'password',
            'profile' => 'profile',
            'comment' => 'comment',
            'disabled' => 'disabled',
        ];

        if (preg_match("/unknown parameter ['\"]?([a-z0-9-]+)['\"]?/i", $message, $m) === 1) {
            $attr = strtolower($m[1]);
            if (isset($map[$attr])) {
                return [
                    $message,
                    [$map[$attr] => 'Router rejected this field (' . $attr . '). ' . $message],
                ];
            }
        }

        return [$message, []];
    }

    /**
     * @return array{name: string, rate_limit: string, shared_users: string, add_mac_cookie: bool, address_pool: string, on_login: string, on_logout: string, color: string, price: string}
     */
    private function extractValues(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        return [
            'name' => trim((string)($body['name'] ?? '')),
            'rate_limit' => trim((string)($body['rate_limit'] ?? '')),
            'shared_users' => trim((string)($body['shared_users'] ?? '')),
            'add_mac_cookie' => !empty($body['add_mac_cookie']),
            'address_pool' => trim((string)($body['address_pool'] ?? '')),
            'on_login' => trim((string)($body['on_login'] ?? '')),
            'on_logout' => trim((string)($body['on_logout'] ?? '')),
            'color' => trim((string)($body['color'] ?? '')),
            'price' => trim((string)($body['price'] ?? '')),
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
        } elseif ((int)$values['shared_users'] < 1 || (int)$values['shared_users'] > 255) {
            $errors['shared_users'] = 'Shared users must be between 1 and 255.';
        }

        if ($values['rate_limit'] !== '' && preg_match('/[^0-9kKmMgG.\/\\s]/', $values['rate_limit']) === 1) {
            $errors['rate_limit'] = 'Invalid rate limit format.';
        }

        if ($values['address_pool'] !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $values['address_pool']) !== 1) {
            $errors['address_pool'] = 'Invalid pool name.';
        }

        foreach (['on_login', 'on_logout'] as $field) {
            if (strlen($values[$field]) > 4096) {
                $errors[$field] = 'Script must be 4096 characters or fewer.';
            }
        }

        if ($values['color'] !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $values['color']) !== 1) {
            $errors['color'] = 'Color must be a hex value like #14b8a6.';
        }

        if ($values['price'] !== '') {
            if (!is_numeric($values['price']) || (float)$values['price'] < 0) {
                $errors['price'] = 'Price must be a number of 0 or more.';
            } elseif ((float)$values['price'] > 999999999) {
                $errors['price'] = 'Price is too large.';
            }
        }

        return $errors;
    }

    /**
     * Map a RouterOS trap message to a top-of-form banner plus, when possible,
     * a per-field error. Currently only matches `unknown parameter <attr>`
     * replies, which name the offending attribute explicitly.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function mapRouterError(string $message): array
    {
        $map = [
            'name' => 'name',
            'shared-users' => 'shared_users',
            'rate-limit' => 'rate_limit',
            'address-pool' => 'address_pool',
            'mac-cookie' => 'add_mac_cookie',
            'add-mac-cookie' => 'add_mac_cookie',
            'on-login' => 'on_login',
            'on-logout' => 'on_logout',
        ];

        if (preg_match("/unknown parameter ['\"]?([a-z0-9-]+)['\"]?/i", $message, $m) === 1) {
            $attr = strtolower($m[1]);
            if (isset($map[$attr])) {
                return [
                    $message,
                    [$map[$attr] => 'Router rejected this field (' . $attr . '). ' . $message],
                ];
            }
        }

        return [$message, []];
    }
}
