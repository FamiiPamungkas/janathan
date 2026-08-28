<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Controllers;

use Fame1302\Janathan\Exceptions\RouterosCommandException;
use Fame1302\Janathan\Services\FlashService;
use Fame1302\Janathan\Services\HotspotService;
use Fame1302\Janathan\Services\ProfileService;
use Fame1302\Janathan\Services\RouterRepository;
use Fame1302\Janathan\Services\TranslationService;
use Fame1302\Janathan\Services\VoucherTemplateRenderer;
use Fame1302\Janathan\Services\VoucherTemplateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class HotspotController
{
    use RedirectsTrait;

    public function __construct(
        private readonly Environment               $twig,
        private readonly HotspotService            $hotspot,
        private readonly RouterRepository          $routers,
        private readonly FlashService              $flash,
        private readonly VoucherTemplateRepository $templates,
        private readonly VoucherTemplateRenderer   $voucherRenderer,
        private readonly TranslationService        $translator,
        private readonly ProfileService            $profiles
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
            return $this->renderUnreachable($request, $response, $e, 'hotspot.users');
        }

        $data['voucherTemplates'] = array_merge([$this->templates->default()], $this->templates->all());

        $deleteStats = ['total' => count($data['users']), 'neverConnected' => 0];
        foreach ($data['users'] as $u) {
            if (!empty($u['neverConnected'])) {
                $deleteStats['neverConnected']++;
            }
        }
        $data['deleteStats'] = $deleteStats;

        $html = $this->twig->render('pages/hotspot/users.twig', $data);
        $response->getBody()->write($html);

        return $response;
    }

    public function activeUsers(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $data = $this->hotspot->getActiveUsers((int)$_SESSION['router_id']);
        } catch (\Throwable $e) {
            return $this->renderUnreachable($request, $response, $e, 'hotspot.active');
        }

        $html = $this->twig->render('pages/hotspot/active.twig', $data);
        $response->getBody()->write($html);

        return $response;
    }

    public function removeActiveUser(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $this->hotspot->removeActiveUser((int)$_SESSION['router_id'], $args['id']);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.active');
        }

        $this->flash->add('success', $this->translator->trans('hotspot.active.flash.removed'));

        return $this->redirect($response, $request, 'hotspot.active');
    }

    public function activeData(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $data = $this->hotspot->getActiveUsers((int)$_SESSION['router_id']);
        } catch (\Throwable $e) {
            $payload = [
                'error' => $e->getMessage(),
                'sessions' => [],
                'updatedAt' => date('c'),
            ];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        }

        $payload = [
            'error' => null,
            'sessions' => $data['sessions'],
            'updatedAt' => date('c'),
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function hosts(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $data = $this->hotspot->getHosts((int)$_SESSION['router_id']);
        } catch (\Throwable $e) {
            return $this->renderUnreachable($request, $response, $e, 'hotspot.hosts');
        }

        $html = $this->twig->render('pages/hotspot/hosts.twig', $data);
        $response->getBody()->write($html);

        return $response;
    }

    public function removeHost(Request $request, Response $response, array $args): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $this->hotspot->removeHost((int)$_SESSION['router_id'], $args['id']);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.hosts');
        }

        $this->flash->add('success', $this->translator->trans('hotspot.hosts.flash.removed'));

        return $this->redirect($response, $request, 'hotspot.hosts');
    }

    public function hostsData(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        try {
            $data = $this->hotspot->getHosts((int)$_SESSION['router_id']);
        } catch (\Throwable $e) {
            $payload = [
                'error' => $e->getMessage(),
                'hosts' => [],
                'updatedAt' => date('c'),
            ];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(200);
        }

        $payload = [
            'error' => null,
            'hosts' => $data['hosts'],
            'updatedAt' => date('c'),
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
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
            $this->flash->add('error', $this->translator->trans('hotspot.users.flash.print_reach_error'));
            return $this->redirect($response, $request, 'hotspot.users');
        }

        if ($user === null) {
            $this->flash->add('error', $this->translator->trans('hotspot.users.flash.not_found'));
            return $this->redirect($response, $request, 'hotspot.users');
        }

        try {
            $profile = $this->profiles->getProfileByName($routerId, $user['profile']) ??
                [
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

    public function printUsers(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $routerId = (int)$_SESSION['router_id'];
        $params = $request->getQueryParams();
        $filters = [
            'q' => isset($params['q']) ? trim((string)$params['q']) : '',
            'profile' => isset($params['profile']) ? trim((string)$params['profile']) : '',
            'comment' => isset($params['comment']) ? trim((string)$params['comment']) : '',
            'status' => isset($params['status']) ? trim((string)$params['status']) : 'all',
        ];
        $templateId = (string)($params['template'] ?? '0');

        if ($filters['comment'] === '') {
            $this->flash->add('error', $this->translator->trans('hotspot.users.flash.print_comment_required'));

            return $this->redirect($response, $request, 'hotspot.users');
        }

        try {
            $result = $this->hotspot->getUsersForPrint($routerId, $filters);
        } catch (\Throwable $e) {
            $this->flash->add('error', $this->translator->trans('hotspot.users.flash.print_reach_error_many'));

            return $this->redirect($response, $request, 'hotspot.users');
        }

        if ($result['users'] === []) {
            $this->flash->add('error', $this->translator->trans('hotspot.users.flash.print_no_users'));

            return $this->redirect($response, $request, 'hotspot.users');
        }

        $useDefault = $templateId === '0' || $templateId === 'default';
        $template = $useDefault ? null : $this->templates->find((int)$templateId);

        if ($template === null) {
            $html = $this->voucherRenderer->renderUsersDefault($result['users'], $result['profiles'], $filters['comment']);
        } else {
            $html = $this->voucherRenderer->renderUsersCustom($template, $result['users'], $result['profiles'], $filters['comment']);
        }

        $html = preg_replace('#</body>#i', '<script>window.print();</script></body>', $html, 1) ?? $html;

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function exportUsers(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $routerId = (int)$_SESSION['router_id'];
        $params = $request->getQueryParams();
        $filters = [
            'q' => isset($params['q']) ? trim((string)$params['q']) : '',
            'profile' => isset($params['profile']) ? trim((string)$params['profile']) : '',
            'comment' => isset($params['comment']) ? trim((string)$params['comment']) : '',
            'status' => isset($params['status']) ? trim((string)$params['status']) : 'all',
        ];
        $format = strtolower(trim((string)($params['format'] ?? 'csv')));
        if (!in_array($format, ['csv', 'rsc'], true)) {
            $format = 'csv';
        }
        $preview = isset($params['preview']) && $params['preview'] !== '' && $params['preview'] !== '0';

        try {
            $result = $this->hotspot->getUsersForExport($routerId, $filters);
        } catch (\Throwable $e) {
            return $this->renderExportError($response, $request, 'Cannot reach the router to export users.');
        }

        $users = $result['users'];
        if ($users === []) {
            return $this->renderExportError($response, $request, 'No users to export for the selected filters.');
        }

        if ($format === 'rsc') {
            $downloadContent = $this->buildRscScript($users);
            $downloadMime = 'text/plain';
            $downloadFilename = 'hotspot-users-' . date('Ymd-His') . '.rsc';
        } else {
            $downloadContent = $this->buildCsv($users);
            $downloadMime = 'text/csv';
            $downloadFilename = 'hotspot-users-' . date('Ymd-His') . '.csv';
        }

        if ($preview) {
            $html = $this->twig->render('pages/hotspot/export_preview.twig', [
                'format' => $format,
                'formatLabel' => $format === 'rsc' ? 'Script' : 'CSV',
                'users' => $users,
                'scriptText' => $format === 'rsc' ? $downloadContent : '',
                'downloadContent' => $downloadContent,
                'downloadFilename' => $downloadFilename,
                'downloadMime' => $downloadMime,
                'backUrl' => $this->urlFor($request, 'hotspot.users'),
            ]);
            $response->getBody()->write($html);

            return $response;
        }

        $response->getBody()->write($downloadContent);

        return $response
            ->withHeader('Content-Type', $downloadMime . '; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $downloadFilename . '"')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function renderExportError(Response $response, Request $request, string $message): Response
    {
        $html = $this->twig->render('pages/hotspot/export_preview.twig', [
            'format' => '',
            'formatLabel' => '',
            'users' => [],
            'scriptText' => '',
            'downloadContent' => '',
            'downloadFilename' => '',
            'downloadMime' => '',
            'error' => $message,
            'backUrl' => $this->urlFor($request, 'hotspot.users'),
        ]);
        $response->getBody()->write($html);

        return $response;
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function buildCsv(array $users): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, [
            'name', 'password', 'profile', 'comment', 'disabled', 'server', 'mac_address',
        ]);
        foreach ($users as $u) {
            fputcsv($stream, [
                $u['name'], $u['password'], $u['profile'], $u['comment'],
                $u['disabled'] ? 'yes' : 'no', $u['server'], $u['mac_address'],
            ]);
        }
        rewind($stream);
        $csv = (string)stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function buildRscScript(array $users): string
    {
        $lines = ['# Hotspot users export - ' . date('Y-m-d H:i:s')];
        foreach ($users as $u) {
            $parts = [
                '/ip hotspot user add',
                'name=' . $this->rscValue((string)$u['name']),
                'profile=' . $this->rscValue((string)$u['profile']),
                'comment=' . $this->rscValue((string)$u['comment']),
                'disabled=' . ($u['disabled'] ? 'yes' : 'no'),
            ];

            $server = (string)$u['server'];
            if ($server !== '' && $server !== 'all' && $server !== '*0') {
                $parts[] = 'server=' . $this->rscValue($server);
            }
            if ((string)$u['mac_address'] !== '') {
                $parts[] = 'mac-address=' . (string)$u['mac_address'];
            }
            if ((string)$u['password'] !== '' && (string)$u['password'] !== '**') {
                $parts[] = 'password=' . $this->rscValue((string)$u['password']);
            }

            $line = implode(' ', $parts);
            if ((string)$u['password'] === '**') {
                $line = '# Password obscured by RouterOS - user not re-importable as-is:' . "\n" . '# ' . $line;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines) . "\n";
    }

    private function rscValue(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $escaped . '"';
    }

    public function showGenerate(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $routerId = (int)$_SESSION['router_id'];
        $profiles = [];
        $profilesWithPrefix = [];

        try {
            $profiles = $this->profiles->getProfileNames($routerId);
            $profilesWithPrefix = $this->profiles->getProfiles($routerId)['profiles'];
        } catch (\Throwable $e) {
            return $this->renderUnreachable($request, $response, $e, 'hotspot.users');
        }

        $prefixMap = [];
        foreach ($profilesWithPrefix as $p) {
            $prefixMap[$p['name']] = $p['prefix'] ?? '';
        }

        $html = $this->twig->render('pages/hotspot/generate.twig', [
            'profiles' => $profiles,
            'prefixMap' => $prefixMap,
            'errors' => [],
            'values' => [],
            'errorBanner' => null,
            'formAction' => 'hotspot.users.generate.store',
        ]);
        $response->getBody()->write($html);

        return $response;
    }

    public function generateUsers(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $values = $this->extractGenerateValues($request->getParsedBody());
        $errors = $this->validateGenerate($values);

        if ($errors !== []) {
            return $this->renderGenerateForm($request, $response, $errors, $values);
        }

        try {
            $result = $this->hotspot->generateUsers((int)$_SESSION['router_id'], $values);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirect($response, $request, 'hotspot.users.generate');
        }

        if ($result['created'] > 0) {
            $this->flash->add('success', $this->translator->trans('hotspot.users.flash.generated', ['count' => $result['created']]));
        }
        if ($result['failed'] > 0) {
            $this->flash->add('error', $this->translator->trans('hotspot.users.flash.generated_failed', [
                'count' => $result['failed'],
                'errors' => implode('; ', array_slice($result['errors'], 0, 5)),
            ]));
        }

        return $this->redirectUsers($response, $request, $values['profile'], $result['comment'] ?? null);
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

        $this->flash->add('success', $this->translator->trans('hotspot.users.flash.created', ['name' => $values['name']]));

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
            return $this->renderUnreachable($request, $response, $e, 'hotspot.users');
        }

        if ($user === null) {
            $this->flash->add('error', $this->translator->trans('hotspot.users.flash.not_found'));

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

        $this->flash->add('success', $this->translator->trans('hotspot.users.flash.updated', ['name' => $values['name']]));

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

        $this->flash->add('success', $this->translator->trans('hotspot.users.flash.removed'));

        return $this->redirectUsers($response, $request, $profile !== '' ? $profile : null);
    }

    public function deleteUsersByComment(Request $request, Response $response): Response
    {
        if (($redirect = $this->withoutRouter($request, $response)) !== null) {
            return $redirect;
        }

        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $filters = [
            'q' => isset($body['q']) ? trim((string)$body['q']) : '',
            'profile' => isset($body['profile']) ? trim((string)$body['profile']) : '',
            'comment' => isset($body['comment']) ? trim((string)$body['comment']) : '',
            'status' => isset($body['status']) ? trim((string)$body['status']) : 'all',
        ];
        $includeActive = !empty($body['include_active']);

        if ($filters['comment'] === '') {
            $this->flash->add('error', $this->translator->trans('hotspot.users.flash.delete_comment_required'));

            return $this->redirectUsers($response, $request, $filters['profile'] !== '' ? $filters['profile'] : null, $filters['comment'] !== '' ? $filters['comment'] : null);
        }

        try {
            $result = $this->hotspot->deleteUsersByComment((int)$_SESSION['router_id'], $filters, $includeActive);
        } catch (\Throwable $e) {
            $this->flash->add('error', $e->getMessage());

            return $this->redirectUsers($response, $request, $filters['profile'] !== '' ? $filters['profile'] : null, $filters['comment']);
        }

        if ($result['deleted'] > 0) {
            $message = $this->translator->trans('hotspot.users.flash.deleted_by_comment', [
                'count' => $result['deleted'],
                'comment' => $filters['comment'],
            ]);
            if (!$includeActive && $result['skipped'] > 0) {
                $message .= ' ' . $this->translator->trans('hotspot.users.flash.deleted_skipped', [
                        'count' => $result['skipped'],
                    ]);
            }
            $this->flash->add('success', $message);
        } else {
            $this->flash->add('info', $this->translator->trans('hotspot.users.flash.deleted_none'));
        }

        return $this->redirectUsers($response, $request, $filters['profile'] !== '' ? $filters['profile'] : null, null);
    }

    private function renderUserForm(
        Request  $request,
        Response $response,
        ?array   $user,
        array    $errors,
        array    $values = [],
        ?string  $errorBanner = null
    ): Response
    {
        $profiles = [];

        try {
            $profiles = $this->profiles->getProfileNames((int)$_SESSION['router_id']);
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

    private function renderGenerateForm(
        Request  $request,
        Response $response,
        array    $errors,
        array    $values = []
    ): Response
    {
        $routerId = (int)$_SESSION['router_id'];
        $profiles = [];
        $prefixMap = [];

        try {
            $profiles = $this->profiles->getProfileNames($routerId);
            foreach ($this->profiles->getProfiles($routerId)['profiles'] as $p) {
                $prefixMap[$p['name']] = $p['prefix'] ?? '';
            }
        } catch (\Throwable $e) {
            return $this->renderUnreachable($request, $response, $e, 'hotspot.users');
        }

        $html = $this->twig->render('pages/hotspot/generate.twig', [
            'profiles' => $profiles,
            'prefixMap' => $prefixMap,
            'errors' => $errors,
            'values' => $values,
            'errorBanner' => null,
            'formAction' => 'hotspot.users.generate.store',
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($errors !== [] ? 422 : 200);
    }

    private function redirectUsers(Response $response, Request $request, ?string $profile, ?string $comment = null): Response
    {
        $url = \Slim\Routing\RouteContext::fromRequest($request)->getRouteParser()->urlFor('hotspot.users');
        $query = [];

        if ($profile !== null && $profile !== '') {
            $query['profile'] = $profile;
        }
        if ($comment !== null && $comment !== '') {
            $query['comment'] = $comment;
        }

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
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
     * @return array{qty: int, profile: string, prefix: string, comment: string, char_lowercase: bool, char_uppercase: bool, char_numbers: bool, name_length: int, password_length: int, password_same_as_username: bool}
     */
    private function extractGenerateValues(mixed $body): array
    {
        $body = is_array($body) ? $body : [];

        $nameLength = (int)($body['name_length'] ?? 6);
        $passwordLength = (int)($body['password_length'] ?? 4);

        return [
            'qty' => (int)($body['qty'] ?? 0),
            'profile' => trim((string)($body['profile'] ?? '')),
            'prefix' => trim((string)($body['prefix'] ?? '')),
            'comment' => trim((string)($body['comment'] ?? '')),
            'char_lowercase' => !empty($body['char_lowercase']),
            'char_uppercase' => !empty($body['char_uppercase']),
            'char_numbers' => !empty($body['char_numbers']),
            'name_length' => $nameLength < 1 ? 1 : $nameLength,
            'password_length' => $passwordLength < 1 ? 1 : $passwordLength,
            'password_same_as_username' => !empty($body['password_same_as_username']),
        ];
    }

    private function validateGenerate(array $values): array
    {
        $errors = [];

        if ($values['qty'] < 1) {
            $errors['qty'] = 'Quantity must be at least 1.';
        } elseif ($values['qty'] > 1000) {
            $errors['qty'] = 'Quantity cannot exceed 1000.';
        }

        if ($values['profile'] === '') {
            $errors['profile'] = 'Profile is required.';
        }

        if (!$values['char_lowercase'] && !$values['char_uppercase'] && !$values['char_numbers']) {
            $errors['character'] = $this->translator->trans('hotspot.generate.char_set_required');
        }

        $lengths = [4, 6, 8, 10, 12, 16, 20, 24];
        if (!in_array($values['name_length'], $lengths, true)) {
            $errors['name_length'] = 'Select a name length.';
        }
        if (!$values['password_same_as_username'] && !in_array($values['password_length'], $lengths, true)) {
            $errors['password_length'] = 'Select a password length.';
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
}
