<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\RouterosClientFactory;
use Fame1302\Janathan\Services\RouterRepository;
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
        private readonly FlashService $flash
    )
    {
        error_log("-- CONSTRUCT ROUTER CONTROLLER --");
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
        $this->flash->add('success', 'Router "' . $values['name'] . '" added.');

        return $this->redirect($response, $request, 'routers.index');
    }

    public function showEdit(Request $request, Response $response, array $args): Response
    {
        $router = $this->routers->find((int)$args['id']);

        if ($router === null) {
            $this->flash->add('error', 'Router not found.');
            return $this->redirect($response, $request, 'routers.index');
        }

        return $this->renderForm($request, $response, $router, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $router = $this->routers->find($id);

        if ($router === null) {
            $this->flash->add('error', 'Router not found.');
            return $this->redirect($response, $request, 'routers.index');
        }

        $values = $this->extractValues($request->getParsedBody());
        $errors = $this->validate($values, false);

        if ($errors !== []) {
            return $this->renderForm($request, $response, $router, $errors, $values);
        }

        $this->routers->update($id, $values);
        $this->flash->add('success', 'Router "' . $values['name'] . '" updated.');

        return $this->redirect($response, $request, 'routers.index');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $router = $this->routers->find($id);

        if ($router === null) {
            $this->flash->add('error', 'Router not found.');
        } else {
            $this->routers->delete($id);

            if (isset($_SESSION['router_id']) && (int)$_SESSION['router_id'] === $id) {
                unset($_SESSION['router_id']);
            }

            $this->flash->add('success', 'Router "' . $router['name'] . '" removed.');
        }

        return $this->redirect($response, $request, 'routers.index');
    }

    public function connect(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $router = $this->routers->find($id);
        error_log((string)$id);

        if ($router === null) {
            $this->flash->add('error', 'Router not found.');
            return $this->redirect($response, $request, 'routers.index');
        }

        try {
            $credentials = $this->routers->getCredentials($id);
            $client = $this->clientFactory->create($credentials);
            $client->test();
            $client->disconnect();
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $this->flash->add(
                'error',
                'Cannot connect to "' . $router['name'] . '" (' . $router['host'] . '). Check that the router is reachable and the credentials are correct.'
            );

            return $this->redirect($response, $request, 'routers.index');
        }

        $_SESSION['router_id'] = $id;
        $this->flash->add('success', 'Connected to "' . $router['name'] . '".');

        return $this->redirect($response, $request, 'home');
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
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($errors !== [] ? 422 : 200);
    }

    /**
     * @return array{name: string, host: string, port: string, ssl: bool, username: string, password: string}
     */
    private function extractValues(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        return [
            'name' => trim((string)($body['name'] ?? '')),
            'host' => trim((string)($body['host'] ?? '')),
            'port' => trim((string)($body['port'] ?? '')),
            'ssl' => !empty($body['ssl']),
            'username' => trim((string)($body['username'] ?? '')),
            'password' => (string)($body['password'] ?? ''),
        ];
    }

    private function validate(array $values, bool $requirePassword): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Name is required.';
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
