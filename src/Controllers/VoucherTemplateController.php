<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\HotspotService;
use Fame1302\Janathan\Services\VoucherTemplateRepository;
use Fame1302\Janathan\Services\VoucherTemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class VoucherTemplateController
{
    use RedirectsTrait;

    public function __construct(
        private readonly Environment $twig,
        private readonly VoucherTemplateRepository $templates,
        private readonly VoucherTemplateRenderer $renderer,
        private readonly HotspotService $hotspot,
        private readonly FlashService $flash
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $html = $this->twig->render('pages/voucher_templates/index.twig', [
            'templates' => array_merge([$this->templates->default()], $this->templates->all()),
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
        $errors = $this->validate($values);

        if ($errors !== []) {
            return $this->renderForm($request, $response, null, $errors, $values);
        }

        try {
            $this->templates->create($values);
        } catch (\PDOException $e) {
            $errors['name'] = 'A template with this name already exists.';
            return $this->renderForm($request, $response, null, $errors, $values);
        }

        $this->flash->add('success', 'Voucher template "' . $values['name'] . '" created.');

        return $this->redirect($response, $request, 'voucher_templates.index');
    }

    public function showEdit(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        if ($id === VoucherTemplateRepository::DEFAULT_ID) {
            $this->flash->add('error', 'The default voucher template is read-only.');
            return $this->redirect($response, $request, 'voucher_templates.index');
        }

        $template = $this->templates->find($id);

        if ($template === null) {
            $this->flash->add('error', 'Voucher template not found.');
            return $this->redirect($response, $request, 'voucher_templates.index');
        }

        return $this->renderForm($request, $response, $template, []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        if ($id === VoucherTemplateRepository::DEFAULT_ID) {
            $this->flash->add('error', 'The default voucher template is read-only.');
            return $this->redirect($response, $request, 'voucher_templates.index');
        }

        $template = $this->templates->find($id);

        if ($template === null) {
            $this->flash->add('error', 'Voucher template not found.');
            return $this->redirect($response, $request, 'voucher_templates.index');
        }

        $values = $this->extractValues($request->getParsedBody());
        $errors = $this->validate($values);

        if ($errors !== []) {
            return $this->renderForm($request, $response, $template, $errors, $values);
        }

        try {
            $this->templates->update($id, $values);
        } catch (\PDOException $e) {
            $errors['name'] = 'A template with this name already exists.';
            return $this->renderForm($request, $response, $template, $errors, $values);
        }

        $this->flash->add('success', 'Voucher template "' . $values['name'] . '" updated.');

        return $this->redirect($response, $request, 'voucher_templates.index');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        if ($id === VoucherTemplateRepository::DEFAULT_ID) {
            $this->flash->add('error', 'The default voucher template is read-only.');
            return $this->redirect($response, $request, 'voucher_templates.index');
        }

        $template = $this->templates->find($id);

        if ($template === null) {
            $this->flash->add('error', 'Voucher template not found.');
        } else {
            $this->templates->delete($id);
            $this->flash->add('success', 'Voucher template "' . $template['name'] . '" removed.');
        }

        return $this->redirect($response, $request, 'voucher_templates.index');
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        $template = $this->resolveTemplate($args['id'], $request, $response);

        if ($template === null) {
            return $this->redirect($response, $request, 'voucher_templates.index');
        }

        $profiles = [];
        $errorBanner = null;

        if (!empty($_SESSION['router_id'])) {
            try {
                $data = $this->hotspot->getProfiles((int)$_SESSION['router_id']);
                foreach ($data['profiles'] as $profile) {
                    $profiles[] = [
                        'name' => (string)$profile['name'],
                        'color' => (string)$profile['color'],
                        'price' => $profile['price'] !== null ? number_format((float)$profile['price'], 0, '.', '') : '',
                    ];
                }
            } catch (\Throwable $e) {
                $errorBanner = 'Could not load profiles from the router: ' . $e->getMessage();
            }
        }

        $html = $this->twig->render('pages/voucher_templates/preview.twig', [
            'template' => $template,
            'profiles' => $profiles,
            'errorBanner' => $errorBanner,
        ]);
        $response->getBody()->write($html);

        return $response;
    }

    public function previewRender(Request $request, Response $response, array $args): Response
    {
        $template = $this->resolveTemplate($args['id'], $request, $response);

        if ($template === null) {
            return $this->redirect($response, $request, 'voucher_templates.index');
        }

        $params = $request->getQueryParams();
        $raw = (string)($params['profiles'] ?? '[]');
        $decoded = json_decode($raw, true);
        $profiles = [];

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = trim((string)($item['name'] ?? ''));
                $color = trim((string)($item['color'] ?? ''));
                $price = trim((string)($item['price'] ?? ''));

                if ($name === '') {
                    continue;
                }

                if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                    $color = '';
                }

                if ($price === '' || !is_numeric($price) || (float)$price < 0) {
                    $price = '';
                }

                $profiles[] = [
                    'name' => $name,
                    'color' => $color,
                    'price' => $price !== '' ? number_format((float)$price, 0, '.', '') : '0',
                ];
            }
        }

        $isDefault = (int)$args['id'] === VoucherTemplateRepository::DEFAULT_ID;
        $html = $isDefault
            ? $this->renderer->renderDefault($profiles)
            : $this->renderer->renderCustom($template, $profiles);

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function resolveTemplate(string $id, Request $request, Response $response): ?array
    {
        $intId = (int)$id;

        if ($intId === VoucherTemplateRepository::DEFAULT_ID) {
            return $this->templates->default();
        }

        $template = $this->templates->find($intId);

        if ($template === null) {
            $this->flash->add('error', 'Voucher template not found.');
        }

        return $template;
    }

    private function renderForm(
        Request  $request,
        Response $response,
        ?array   $template,
        array    $errors,
        array    $values = []
    ): Response
    {
        $html = $this->twig->render('pages/voucher_templates/form.twig', [
            'template' => $template,
            'errors' => $errors,
            'values' => $values,
            'formAction' => $template === null ? 'voucher_templates.store' : 'voucher_templates.update',
            'formParams' => $template === null ? [] : ['id' => $template['id']],
            'isEdit' => $template !== null,
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($errors !== [] ? 422 : 200);
    }

    /**
     * @return array{name: string, header: string, row: string, footer: string}
     */
    private function extractValues(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        return [
            'name' => trim((string)($body['name'] ?? '')),
            'header' => (string)($body['header'] ?? ''),
            'row' => (string)($body['row'] ?? ''),
            'footer' => (string)($body['footer'] ?? ''),
        ];
    }

    private function validate(array $values): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors['name'] = 'Name is required.';
        }

        return $errors;
    }
}