<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\ProfileService;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\TranslationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Twig\Environment;

class ProfileController
{
    use RedirectsTrait;

    public function __construct(
        private readonly Environment        $twig,
        private readonly ProfileService     $profiles,
        private readonly RouterRepository   $routers,
        private readonly FlashService       $flash,
        private readonly TranslationService $translator
    )
    {
    }

    public function index(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $data = $this->profiles->getProfiles($this->routerId());
        } catch (Throwable $e) {
            return $this->renderUnreachable($request, $response, $e, 'hotspot.profiles');
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
        return $this->saveProfile($request, $response, null, 'hotspot.profiles.flash.created');
    }

    public function showEdit(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $profile = $this->profiles->getProfile($this->routerId(), $args['id']);
        } catch (Throwable $e) {
            return $this->renderUnreachable($request, $response, $e, 'hotspot.profiles');
        }

        if ($profile === null) {
            $this->flash->add('error', $this->translator->trans('hotspot.profiles.flash.not_found'));

            return $this->redirect($response, $request, 'hotspot.profiles');
        }

        return $this->renderForm($request, $response, $profile, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->saveProfile($request, $response, $args['id'], 'hotspot.profiles.flash.updated');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $this->profiles->removeProfile($this->routerId(), $args['id']);
        } catch (Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.profiles');
        }

        $this->flash->add('success', $this->translator->trans('hotspot.profiles.flash.removed'));

        return $this->redirect($response, $request, 'hotspot.profiles');
    }

    private function routerId(): int
    {
        return (int)($_SESSION['router_id'] ?? 0);
    }

    private function saveProfile(Request $request, Response $response, ?string $id, string $successKey): Response
    {
        $values = $this->extractValues($request->getParsedBody());
        $errors = $this->validate($values);

        if ($errors !== []) {
            if ($id !== null) {
                $values['id'] = $id;
            }

            return $this->renderForm($request, $response, $id === null ? null : $values, $errors, $values);
        }

        try {
            if ($id === null) {
                $this->profiles->createProfile($this->routerId(), $values);
            } else {
                $this->profiles->updateProfile($this->routerId(), $id, $values);
            }
        } catch (RouterosCommandException $e) {
            [$banner, $fieldErrors] = $this->mapRouterError($e->getMessage());
            if ($id !== null) {
                $values['id'] = $id;
            }

            return $this->renderForm($request, $response, $id === null ? null : $values, $fieldErrors + $errors, $values, $banner);
        } catch (Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.profiles');
        }

        $this->flash->add('success', $this->translator->trans($successKey, ['name' => $values['name']]));

        return $this->redirect($response, $request, 'hotspot.profiles');
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
            $pools = $this->profiles->getIpPools($this->routerId());
        } catch (Throwable $e) {
            if ($errorBanner === null) {
                $errorBanner = $e->getMessage();
            }
        }

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

    /**
     * @return array{name: string, rate_limit: string, shared_users: string, add_mac_cookie: bool, address_pool: string, color: string, price: string, prefix: string, validity_days: string, start_on: string}
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
            'color' => trim((string)($body['color'] ?? '')),
            'price' => trim((string)($body['price'] ?? '')),
            'prefix' => trim((string)($body['prefix'] ?? '')),
            'validity_days' => trim((string)($body['validity_days'] ?? '')),
            'start_on' => trim((string)($body['start_on'] ?? 'first_login')),
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

        if ($values['validity_days'] !== '') {
            if (!ctype_digit($values['validity_days']) || (int)$values['validity_days'] < 1 || (int)$values['validity_days'] > 3650) {
                $errors['validity_days'] = 'Validity must be a number of days between 1 and 3650.';
            }
        }

        if (!in_array($values['start_on'], ['first_login', 'user_creation'], true)) {
            $errors['start_on'] = 'Choose when the validity period starts.';
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

        if (mb_strlen($values['prefix']) > 100) {
            $errors['prefix'] = 'Prefix must be 100 characters or fewer.';
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
