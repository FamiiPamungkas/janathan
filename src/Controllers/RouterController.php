<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\RouterosClientFactory;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\TranslationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class RouterController
{
    use RedirectsTrait;

    public function __construct(
        private readonly Environment           $twig,
        private readonly RouterRepository      $routers,
        private readonly RouterosClientFactory $clientFactory,
        private readonly FlashService $flash,
        private readonly TranslationService $translator
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $html = $this->twig->render('pages/routers/index.twig', [
            'routers' => $this->routers->all(),
        ]);
        $response->getBody()->write($html);

        return $response;
    }

    public function showCreate(Request $request, Response $response): Response
    {
        return $this->renderForm($request, $response, null, []);
    }

    public function create(Request $request, Response $response): Response
    {
        $input = $request->getParsedBody();
        $values = $this->extractValues($input);
        $errors = $this->validate($values, true);

        if ($errors !== []) {
            return $this->renderForm($request, $response, null, $errors, $values);
        }

        $this->routers->create($values);
        $this->flash->add('success', $this->translator->trans('routers.flash.added', ['name' => $values['name']]));

        return $this->redirect($response, $request, 'routers.index');
    }

    public function showEdit(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $router = $this->routers->find($id);

        if ($router === null) {
            $this->flash->add('error', $this->translator->trans('routers.flash.not_found'));
            return $this->redirect($response, $request, 'routers.index');
        }

        $credentials = $this->routers->getCredentials($id);
        $router['password'] = $credentials['password'] ?? '';

        return $this->renderForm($request, $response, $router, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $router = $this->routers->find($id);

        if ($router === null) {
            $this->flash->add('error', $this->translator->trans('routers.flash.not_found'));
            return $this->redirect($response, $request, 'routers.index');
        }

        $values = $this->extractValues($request->getParsedBody());
        $errors = $this->validate($values, true);

        if ($errors !== []) {
            return $this->renderForm($request, $response, $router, $errors, $values);
        }

        $this->routers->update($id, $values);
        $this->flash->add('success', $this->translator->trans('routers.flash.updated', ['name' => $values['name']]));

        return $this->redirect($response, $request, 'routers.index');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $router = $this->routers->find($id);

        if ($router === null) {
            $this->flash->add('error', $this->translator->trans('routers.flash.not_found'));
        } else {
            $this->routers->delete($id);

            if (isset($_SESSION['router_id']) && (int)$_SESSION['router_id'] === $id) {
                unset($_SESSION['router_id']);
            }

            $this->flash->add('success', $this->translator->trans('routers.flash.removed', ['name' => $router['name']]));
        }

        return $this->redirect($response, $request, 'routers.index');
    }

    public function connect(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $router = $this->routers->find($id);

        if ($router === null) {
            $this->flash->add('error', $this->translator->trans('routers.flash.not_found'));
            return $this->redirect($response, $request, 'routers.index');
        }

        try {
            $credentials = $this->routers->getCredentials($id);
            $client = $this->clientFactory->create($credentials);
            $client->test();
            $client->disconnect();
        } catch (\Throwable $e) {
            $this->flash->add(
                'error',
                $this->translator->trans('routers.flash.connect_error', ['name' => $router['name'], 'host' => $router['host']])
            );

            return $this->redirect($response, $request, 'routers.index');
        }

        $_SESSION['router_id'] = $id;
        $this->flash->add('success', $this->translator->trans('routers.flash.connected', ['name' => $router['name']]));

        return $this->redirect($response, $request, 'dashboard');
    }

    public function disconnect(Request $request, Response $response): Response
    {
        $name = null;

        if (!empty($_SESSION['router_id'])) {
            $active = $this->routers->find((int) $_SESSION['router_id']);
            $name = $active['name'] ?? null;
        }

        unset($_SESSION['router_id']);

        if ($name !== null) {
            $this->flash->add('success', $this->translator->trans('routers.flash.disconnected', ['name' => $name]));
        }

        return $this->redirect($response, $request, 'routers.index');
    }

    private function renderForm(
        Request  $request,
        Response $response,
        ?array   $router,
        array    $errors,
        array    $values = []
    ): Response
    {
        $html = $this->twig->render('pages/routers/form.twig', [
            'router' => $router,
            'errors' => $errors,
            'values' => $values,
            'formAction' => $router === null ? 'routers.store' : 'routers.update',
            'formParams' => $router === null ? [] : ['id' => $router['id']],
            'isEdit' => $router !== null,
            'currencies' => ['IDR', 'USD'],
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($errors !== [] ? 422 : 200);
    }

    public function testConnection(Request $request, Response $response): Response
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        $port = trim((string)($body['port'] ?? ''));
        $port = $port === '' ? 8728 : (int)$port;

        $credentials = [
            'host' => trim((string)($body['host'] ?? '')),
            'username' => trim((string)($body['username'] ?? '')),
            'password' => (string)($body['password'] ?? ''),
            'port' => $port,
            'ssl' => !empty($body['ssl']),
        ];

        $payload = ['ok' => false, 'error' => $this->translator->trans('common.unknown_error')];

        try {
            $client = $this->clientFactory->create($credentials, ['attempts' => 1, 'timeout' => 8]);
            $client->test();
            $client->disconnect();
            $payload = ['ok' => true];
        } catch (\Throwable $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }

        $response->getBody()->write(json_encode($payload));
        $response = $response->withHeader('Content-Type', 'application/json');
        $response = $response->withHeader('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array{name: string, host: string, port: string, ssl: bool, username: string, password: string, hotspot_name: string, dns_name: string, currency: string}
     */
    private function extractValues(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        $currency = trim((string)($body['currency'] ?? ''));
        if ($currency === '' || !in_array($currency, ['IDR', 'USD'], true)) {
            $currency = 'IDR';
        }

        return [
            'name' => trim((string)($body['name'] ?? '')),
            'host' => trim((string)($body['host'] ?? '')),
            'port' => trim((string)($body['port'] ?? '')),
            'ssl' => !empty($body['ssl']),
            'username' => trim((string)($body['username'] ?? '')),
            'password' => (string)($body['password'] ?? ''),
            'hotspot_name' => trim((string)($body['hotspot_name'] ?? '')),
            'dns_name' => trim((string)($body['dns_name'] ?? '')),
            'currency' => $currency,
        ];
    }

    private function validate(array $values, bool $requirePassword): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Session name is required.';
        }

        if ($values['host'] === '') {
            $errors['host'] = 'Host is required.';
        }

        if ($values['username'] === '') {
            $errors['username'] = 'Username is required.';
        }

        if ($requirePassword && $values['password'] === '') {
            $errors['password'] = 'Password is required.';
        }

        if ($values['port'] !== '') {
            $port = filter_var($values['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) {
                $errors['port'] = 'Port must be a number between 1 and 65535.';
            }
        }

        return $errors;
    }
}
