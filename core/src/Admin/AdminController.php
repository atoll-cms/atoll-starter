<?php

declare(strict_types=1);

namespace Atoll\Admin;

use Atoll\Backup\BackupManager;
use Atoll\Cache\CacheManager;
use Atoll\Content\ContentRepository;
use Atoll\Content\Page;
use Atoll\Content\ValidationException;
use Atoll\Hooks\HookManager;
use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Media\MediaManager;
use Atoll\Plugins\PluginManager;
use Atoll\Security\SecurityManager;
use Atoll\Support\Config;
use Atoll\Support\PackageInstaller;
use Atoll\Support\Yaml;
use RuntimeException;

final class AdminController
{
    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $adminRoots
     */
    public function __construct(
        private readonly string $root,
        private readonly string $configPath,
        private array $config,
        private readonly array $adminRoots,
        private readonly HookManager $hooks,
        private readonly SecurityManager $security,
        private readonly ContentRepository $content,
        private readonly CacheManager $cache,
        private readonly PluginManager $plugins,
        private readonly BackupManager $backup,
        private readonly MediaManager $media
    ) {
    }

    public function serveSpa(): Response
    {
        $index = $this->resolveAdminFile('index.html');
        if (!is_file($index)) {
            return Response::html('<h1>Admin panel missing</h1>', 500);
        }

        $html = (string) file_get_contents($index);
        $csrf = $this->security->csrfToken();
        $injected = str_replace('__ATOLL_CSRF__', htmlspecialchars($csrf, ENT_QUOTES), $html);

        return Response::html($injected);
    }

    public function serveAsset(string $relativePath): ?Response
    {
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '') {
            return null;
        }

        $file = $this->resolveAdminFile($relativePath);
        if (!is_file($file)) {
            return null;
        }

        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'js' => 'application/javascript; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };

        return Response::text((string) file_get_contents($file))
            ->withHeader('Content-Type', $contentType);
    }

    public function handleApi(Request $request): Response
    {
        $endpoint = '/' . trim(substr($request->path, strlen('/admin/api')), '/');
        if ($endpoint === '/auth/login' && $request->method === 'POST') {
            return $this->login($request);
        }

        if ($endpoint === '/auth/logout' && $request->method === 'POST') {
            $this->security->logout($request);
            return Response::json(['ok' => true]);
        }

        if (!$this->security->isAuthenticated()) {
            return Response::json(['error' => 'Unauthenticated'], 401);
        }

        if ($request->method === 'POST') {
            $token = $request->header('x-csrf-token', $request->input('_csrf', ''));
            if (!$this->security->validateCsrf($token)) {
                return Response::json(['error' => 'Invalid CSRF token'], 419);
            }
        }

        $requiredPermission = $this->permissionForEndpoint($endpoint, $request->method);
        if ($requiredPermission !== null && !$this->security->hasPermission($requiredPermission)) {
            $this->security->recordAudit('auth.access_denied_permission', [
                'user' => $this->security->currentUser(),
                'endpoint' => $endpoint,
                'method' => $request->method,
                'required_permission' => $requiredPermission,
            ]);
            return Response::json(['error' => 'Forbidden'], 403);
        }

        return match (true) {
            $endpoint === '/me' && $request->method === 'GET' => Response::json([
                'ok' => true,
                'user' => $this->security->currentUser(),
                'csrf' => $this->security->csrfToken(),
                'security' => [
                    'twofa_enabled' => $this->isCurrentUserTwoFactorEnabled(),
                    'role' => $this->security->currentUserRole(),
                    'permissions' => $this->security->currentUserPermissions(),
                ],
            ]),
            $endpoint === '/menu' && $request->method === 'GET' => $this->adminMenu(),
            $endpoint === '/dashboard/widgets' && $request->method === 'GET' => $this->dashboardWidgets(),
            $endpoint === '/collections' && $request->method === 'GET' => Response::json([
                'ok' => true,
                'collections' => $this->content->collections(),
            ]),
            $endpoint === '/collection/meta' && $request->method === 'GET' => $this->collectionMeta($request),
            $endpoint === '/collection/meta/save' && $request->method === 'POST' => $this->saveCollectionMeta($request),
            $endpoint === '/entries' && $request->method === 'GET' => $this->entries($request),
            $endpoint === '/entry' && $request->method === 'GET' => $this->entry($request),
            $endpoint === '/entry/revisions' && $request->method === 'GET' => $this->entryRevisions($request),
            $endpoint === '/entry/revision' && $request->method === 'GET' => $this->entryRevision($request),
            $endpoint === '/entry/save' && $request->method === 'POST' => $this->saveEntry($request),
            $endpoint === '/entry/revision/restore' && $request->method === 'POST' => $this->restoreEntryRevision($request),
            $endpoint === '/entry/delete' && $request->method === 'POST' => $this->deleteEntry($request),
            $endpoint === '/forms/submissions' && $request->method === 'GET' => $this->formSubmissions($request),
            $endpoint === '/forms/submissions/status' && $request->method === 'POST' => $this->updateFormSubmissionStatus($request),
            $endpoint === '/forms/submissions/export' && $request->method === 'GET' => $this->exportFormSubmissions($request),
            $endpoint === '/redirects' && $request->method === 'GET' => $this->redirects(),
            $endpoint === '/redirects/save' && $request->method === 'POST' => $this->saveRedirects($request),
            $endpoint === '/cache/clear' && $request->method === 'POST' => $this->clearCache(),
            $endpoint === '/backup/status' && $request->method === 'GET' => $this->backupStatus(),
            $endpoint === '/backup/create' && $request->method === 'POST' => $this->createBackup(),
            $endpoint === '/plugins' && $request->method === 'GET' => $this->pluginsList(),
            $endpoint === '/plugin-registry' && $request->method === 'GET' => $this->pluginRegistry(),
            $endpoint === '/plugin-page' && $request->method === 'GET' => $this->pluginPage($request),
            $endpoint === '/theme-registry' && $request->method === 'GET' => $this->themeRegistry(),
            $endpoint === '/marketplace/orders' && $request->method === 'GET' => $this->marketplaceOrders($request),
            $endpoint === '/marketplace/purchase' && $request->method === 'POST' => $this->marketplacePurchase($request),
            $endpoint === '/marketplace/license/verify' && $request->method === 'POST' => $this->marketplaceLicenseVerify($request),
            $endpoint === '/plugins/toggle' && $request->method === 'POST' => $this->togglePlugin($request),
            $endpoint === '/plugins/install' && $request->method === 'POST' => $this->installPlugin($request),
            $endpoint === '/plugins/update' && $request->method === 'POST' => $this->updatePlugin($request),
            $endpoint === '/plugins/update-all' && $request->method === 'POST' => $this->updateAllPlugins(),
            $endpoint === '/plugins/uninstall' && $request->method === 'POST' => $this->uninstallPlugin($request),
            $endpoint === '/themes' && $request->method === 'GET' => $this->themes(),
            $endpoint === '/themes/install' && $request->method === 'POST' => $this->installTheme($request),
            $endpoint === '/themes/activate' && $request->method === 'POST' => $this->activateTheme($request),
            $endpoint === '/themes/uninstall' && $request->method === 'POST' => $this->uninstallTheme($request),
            $endpoint === '/media/upload' && $request->method === 'POST' => $this->uploadMedia($request),
            $endpoint === '/media/list' && $request->method === 'GET' => $this->listMedia($request),
            $endpoint === '/media/meta' && $request->method === 'GET' => $this->mediaMeta($request),
            $endpoint === '/media/meta/save' && $request->method === 'POST' => $this->saveMediaMeta($request),
            $endpoint === '/media/transform' && $request->method === 'POST' => $this->transformMedia($request),
            $endpoint === '/security/audit' && $request->method === 'GET' => $this->securityAudit($request),
            $endpoint === '/security/mixed-content/scan' && $request->method === 'GET' => $this->securityMixedContentScan($request),
            $endpoint === '/security/2fa/setup' && $request->method === 'POST' => $this->setupTwoFactor($request),
            $endpoint === '/security/2fa/disable' && $request->method === 'POST' => $this->disableTwoFactor($request),
            $endpoint === '/settings' && $request->method === 'GET' => $this->settings(),
            $endpoint === '/settings/save' && $request->method === 'POST' => $this->saveSettings($request),
            $endpoint === '/users' && $request->method === 'GET' => $this->users(),
            $endpoint === '/users/save' && $request->method === 'POST' => $this->saveUsers($request),
            default => Response::json(['error' => 'Not found', 'endpoint' => $endpoint], 404),
        };
    }

    private function login(Request $request): Response
    {
        if (!$this->security->isAdminIpAllowed($request)) {
            $this->security->recordAudit('auth.login_denied_ip', [
                'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
            ]);
            return Response::json(['error' => 'Login from this IP is not allowed'], 403);
        }

        $rateLimit = $this->security->enforceRateLimit($request, 'admin-login', 10, 60);
        if ($rateLimit !== null) {
            return $rateLimit;
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $otp = trim((string) $request->input('otp', ''));

        $users = Config::get($this->config, 'users', []);
        if (!is_array($users)) {
            return Response::json(['error' => 'Auth config missing'], 500);
        }

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            if (($user['username'] ?? null) !== $username) {
                continue;
            }

            if (array_key_exists('enabled', $user) && !(bool) $user['enabled']) {
                $this->security->recordAudit('auth.login_denied_disabled', [
                    'user' => $username,
                    'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
                ]);
                return Response::json(['error' => 'Account is disabled'], 403);
            }

            $hash = (string) ($user['password_hash'] ?? '');
            if ($hash !== '' && password_verify($password, $hash)) {
                $twoFactorSecret = (string) ($user['twofa_secret'] ?? '');
                $role = $this->security->normalizeRole((string) ($user['role'] ?? 'owner'));
                if ($twoFactorSecret !== '' && !$this->security->verifyTotp($twoFactorSecret, $otp)) {
                    $this->security->recordAudit('auth.login_failed_2fa', [
                        'user' => $username,
                        'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
                    ]);
                    return Response::json(['error' => 'Invalid 2FA code'], 401);
                }

                $this->security->login($username, $request);
                $this->hooks->run('auth:login', $username, $request);
                return Response::json([
                    'ok' => true,
                    'user' => $username,
                    'csrf' => $this->security->csrfToken(),
                    'security' => [
                        'twofa_enabled' => $twoFactorSecret !== '',
                        'role' => $role,
                        'permissions' => $this->security->currentUserPermissions(),
                    ],
                ]);
            }
        }

        $this->security->recordAudit('auth.login_failed_password', [
            'user' => $username,
            'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
        ]);
        return Response::json(['error' => 'Invalid credentials'], 401);
    }

    private function entries(Request $request): Response
    {
        $collection = (string) $request->input('collection', 'pages');
        $items = $this->content->listCollection($collection, true);

        return Response::json([
            'ok' => true,
            'entries' => array_map(static fn ($p) => $p->toArray(), $items),
        ]);
    }

    private function adminMenu(): Response
    {
        $items = [];
        $seen = [];
        foreach ($this->hooks->run('admin:menu', $this->security->currentUser()) as $result) {
            if (is_array($result) && array_is_list($result)) {
                foreach ($result as $entry) {
                    $this->appendAdminMenuItem($items, $seen, $entry);
                }
                continue;
            }

            $this->appendAdminMenuItem($items, $seen, $result);
        }

        return Response::json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    private function dashboardWidgets(): Response
    {
        $widgets = [];
        $counter = 0;
        $this->appendDashboardWidget($widgets, $this->backupDashboardWidget(), $counter);
        foreach ($this->hooks->run('admin:dashboard', $this->security->currentUser()) as $result) {
            if (is_array($result) && array_is_list($result)) {
                foreach ($result as $widget) {
                    $this->appendDashboardWidget($widgets, $widget, $counter);
                }
                continue;
            }

            $this->appendDashboardWidget($widgets, $result, $counter);
        }

        return Response::json([
            'ok' => true,
            'widgets' => $widgets,
        ]);
    }

    private function entry(Request $request): Response
    {
        $collection = (string) $request->input('collection', 'pages');
        $id = (string) $request->input('id', 'index');
        $entry = $this->content->getById($collection, $id);
        if ($entry === null) {
            return Response::json(['error' => 'Entry not found'], 404);
        }

        return Response::json(['ok' => true, 'entry' => $entry->toArray()]);
    }

    private function entryRevisions(Request $request): Response
    {
        $collection = trim((string) $request->input('collection', 'pages'));
        $id = trim((string) $request->input('id', 'index'));
        $limit = max(1, min(200, (int) $request->input('limit', 20)));

        if ($collection === '' || $id === '') {
            return Response::json(['error' => 'Missing collection or id'], 422);
        }

        return Response::json([
            'ok' => true,
            'revisions' => $this->content->listRevisions($collection, $id, $limit),
        ]);
    }

    private function entryRevision(Request $request): Response
    {
        $collection = trim((string) $request->input('collection', 'pages'));
        $id = trim((string) $request->input('id', 'index'));
        $revisionId = trim((string) $request->input('revision', ''));
        if ($collection === '' || $id === '' || $revisionId === '') {
            return Response::json(['error' => 'Missing collection, id or revision'], 422);
        }

        $revision = $this->content->getRevision($collection, $id, $revisionId);
        if ($revision === null) {
            return Response::json(['error' => 'Revision not found'], 404);
        }

        $current = $this->content->getById($collection, $id);

        return Response::json([
            'ok' => true,
            'revision' => $revision,
            'current' => $current === null ? null : [
                'id' => $current->id,
                'collection' => $current->collection,
                'slug' => $current->slug,
                'url' => $current->url,
                'frontmatter' => $current->data,
                'markdown' => $current->markdown,
            ],
        ]);
    }

    private function saveEntry(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $collection = (string) ($payload['collection'] ?? 'pages');
        $id = (string) ($payload['id'] ?? 'index');
        $beforeEntry = $this->content->getById($collection, $id);
        $frontmatter = $payload['frontmatter'] ?? [];
        $markdown = (string) ($payload['markdown'] ?? '');

        if (!is_array($frontmatter)) {
            return Response::json(['error' => 'frontmatter must be object'], 422);
        }

        $savePayload = [
            'collection' => $collection,
            'id' => $id,
            'frontmatter' => $frontmatter,
            'markdown' => $markdown,
            'user' => $this->security->currentUser(),
        ];
        $hookErrors = [];

        foreach ($this->hooks->run('admin:entry:before_save', $savePayload, $request) as $result) {
            if (!is_array($result)) {
                continue;
            }

            $nextFrontmatter = $result['frontmatter'] ?? null;
            if (is_array($nextFrontmatter)) {
                $savePayload['frontmatter'] = $nextFrontmatter;
            }

            $nextMarkdown = $result['markdown'] ?? null;
            if (is_string($nextMarkdown)) {
                $savePayload['markdown'] = $nextMarkdown;
            }

            $errors = $result['errors'] ?? null;
            if (is_array($errors)) {
                foreach ($errors as $field => $message) {
                    if (!is_string($field) || trim($field) === '') {
                        continue;
                    }
                    $hookErrors[$field] = is_string($message) ? $message : 'invalid';
                }
            }
        }

        if ($hookErrors !== []) {
            return Response::json([
                'error' => 'Validation failed',
                'fields' => $hookErrors,
            ], 422);
        }

        try {
            $file = $this->content->save(
                (string) $savePayload['collection'],
                (string) $savePayload['id'],
                (array) $savePayload['frontmatter'],
                (string) $savePayload['markdown'],
                (string) $savePayload['user']
            );
        } catch (ValidationException $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'fields' => $e->errors(),
            ], 422);
        }
        $this->cache->invalidateByDependencies([$file]);

        $afterEntry = $this->content->getById($collection, $id);
        $autoRedirect = $this->createAutoSlugRedirect($beforeEntry, $afterEntry);

        return Response::json([
            'ok' => true,
            'file' => $file,
            'auto_redirect' => $autoRedirect,
        ]);
    }

    private function restoreEntryRevision(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $collection = trim((string) ($payload['collection'] ?? 'pages'));
        $id = trim((string) ($payload['id'] ?? 'index'));
        $revisionId = trim((string) ($payload['revision'] ?? ''));
        if ($collection === '' || $id === '' || $revisionId === '') {
            return Response::json(['error' => 'Missing collection, id or revision'], 422);
        }

        $file = $this->content->restoreRevision($collection, $id, $revisionId, $this->security->currentUser());
        if ($file === null) {
            return Response::json(['error' => 'Revision not found'], 404);
        }

        $this->cache->invalidateByDependencies([$file]);
        $this->security->recordAudit('content.revision_restore', [
            'user' => $this->security->currentUser(),
            'collection' => $collection,
            'id' => $id,
            'revision' => $revisionId,
            'file' => $file,
        ]);

        return Response::json(['ok' => true, 'file' => $file]);
    }

    private function collectionMeta(Request $request): Response
    {
        $collection = trim((string) $request->input('collection', 'pages'));
        if ($collection === '') {
            return Response::json(['error' => 'Missing collection'], 422);
        }

        return Response::json([
            'ok' => true,
            'collection' => $collection,
            'meta' => $this->content->collectionMeta($collection),
        ]);
    }

    private function saveCollectionMeta(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $collection = trim((string) ($payload['collection'] ?? ''));
        $meta = $payload['meta'] ?? null;

        if ($collection === '') {
            return Response::json(['error' => 'Missing collection'], 422);
        }
        if (!is_array($meta)) {
            return Response::json(['error' => 'meta must be object'], 422);
        }

        try {
            $file = $this->content->saveCollectionMeta($collection, $meta);
        } catch (ValidationException $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'fields' => $e->errors(),
            ], 422);
        }

        $this->cache->clear();

        $this->security->recordAudit('content.collection_meta_save', [
            'user' => $this->security->currentUser(),
            'collection' => $collection,
            'file' => $file,
        ]);

        return Response::json([
            'ok' => true,
            'collection' => $collection,
            'file' => $file,
            'meta' => $this->content->collectionMeta($collection),
        ]);
    }

    private function deleteEntry(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $collection = (string) ($payload['collection'] ?? 'pages');
        $id = (string) ($payload['id'] ?? '');

        if ($id === '') {
            return Response::json(['error' => 'Missing id'], 422);
        }

        $deleted = $this->content->delete($collection, $id);
        $this->cache->clear();

        return Response::json(['ok' => $deleted]);
    }

    private function formSubmissions(Request $request): Response
    {
        $filter = $this->formSubmissionFilterFromRequest($request);
        $allForms = $this->formSubmissionNames();
        $rows = $this->loadFilteredSubmissions($filter);

        return Response::json([
            'ok' => true,
            'forms' => $allForms,
            'filter' => $filter,
            'submissions' => $rows,
            'count' => count($rows),
        ]);
    }

    private function updateFormSubmissionStatus(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $form = trim((string) ($payload['form'] ?? ''));
        $id = trim((string) ($payload['id'] ?? ''));
        $status = $this->normalizeSubmissionStatus((string) ($payload['status'] ?? ''));
        if ($form === '' || $id === '') {
            return Response::json(['error' => 'Missing form or id'], 422);
        }
        if ($status === '') {
            return Response::json(['error' => 'Invalid status'], 422);
        }

        $rows = $this->loadFormSubmissions($form);
        $exists = false;
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            return Response::json(['error' => 'Submission not found'], 404);
        }

        $statusMap = $this->loadSubmissionStatusMap($form);
        $statusMap[$id] = $status;
        $this->writeSubmissionStatusMap($form, $statusMap);

        $this->security->recordAudit('forms.submission_status', [
            'user' => $this->security->currentUser(),
            'form' => $form,
            'id' => $id,
            'status' => $status,
        ]);

        return Response::json([
            'ok' => true,
            'form' => $form,
            'id' => $id,
            'status' => $status,
        ]);
    }

    private function exportFormSubmissions(Request $request): Response
    {
        $filter = $this->formSubmissionFilterFromRequest($request);
        $rows = $this->loadFilteredSubmissions($filter);
        $csv = fopen('php://temp', 'r+');
        if ($csv === false) {
            return Response::json(['error' => 'Could not create export buffer'], 500);
        }

        $payloadKeys = [];
        foreach ($rows as $row) {
            $payload = $row['payload'] ?? [];
            if (!is_array($payload)) {
                continue;
            }
            foreach (array_keys($payload) as $key) {
                if (is_string($key) && trim($key) !== '') {
                    $payloadKeys[$key] = true;
                }
            }
        }
        $payloadColumns = array_keys($payloadKeys);
        sort($payloadColumns);

        $headers = ['form', 'id', 'timestamp', 'status', 'ip', ...$payloadColumns];
        fputcsv($csv, $headers, ',', '"', '\\');

        foreach ($rows as $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $record = [
                (string) ($row['form'] ?? ''),
                (string) ($row['id'] ?? ''),
                (string) ($row['timestamp'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['ip'] ?? ''),
            ];
            foreach ($payloadColumns as $column) {
                $value = $payload[$column] ?? '';
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $record[] = (string) $value;
            }
            fputcsv($csv, $record, ',', '"', '\\');
        }

        rewind($csv);
        $content = (string) stream_get_contents($csv);
        fclose($csv);

        $suffix = $filter['form'] === 'all' ? 'all' : $filter['form'];
        $filename = 'submissions-' . $suffix . '-' . date('Ymd-His') . '.csv';

        return Response::text($content)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * @return array{form:string,status:string,q:string,date_from:string,date_to:string,limit:int}
     */
    private function formSubmissionFilterFromRequest(Request $request): array
    {
        $form = trim((string) $request->input('name', 'all'));
        if ($form === '') {
            $form = 'all';
        }

        $status = strtolower(trim((string) $request->input('status', 'all')));
        if (!in_array($status, ['all', 'new', 'in-progress', 'done'], true)) {
            $status = 'all';
        }

        $q = trim((string) $request->input('q', ''));
        $dateFrom = trim((string) $request->input('date_from', ''));
        $dateTo = trim((string) $request->input('date_to', ''));
        $limit = (int) $request->input('limit', 500);
        $limit = max(1, min(5000, $limit));

        return [
            'form' => $form,
            'status' => $status,
            'q' => $q,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function formSubmissionNames(): array
    {
        $rows = [];
        $submissionDir = $this->root . '/content/forms-submissions';
        foreach (glob($submissionDir . '/*.jsonl') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($name !== '') {
                $rows[] = $name;
            }
        }

        $formsDir = $this->root . '/content/forms';
        foreach (glob($formsDir . '/*.yaml') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($name !== '') {
                $rows[] = $name;
            }
        }
        foreach (glob($formsDir . '/*.yml') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($name !== '') {
                $rows[] = $name;
            }
        }

        $rows = array_values(array_unique($rows));
        sort($rows);

        return $rows;
    }

    /**
     * @param array{form:string,status:string,q:string,date_from:string,date_to:string,limit:int} $filter
     * @return array<int, array<string, mixed>>
     */
    private function loadFilteredSubmissions(array $filter): array
    {
        $form = $filter['form'];
        $forms = $form === 'all' ? $this->formSubmissionNames() : [$form];
        $rows = [];
        foreach ($forms as $name) {
            foreach ($this->loadFormSubmissions($name) as $row) {
                $rows[] = $row;
            }
        }

        $statusFilter = $filter['status'];
        $query = mb_strtolower($filter['q']);
        $dateFromTs = $this->parseDateStart($filter['date_from']);
        $dateToTs = $this->parseDateEnd($filter['date_to']);

        $rows = array_values(array_filter($rows, static function (array $row) use ($statusFilter, $query, $dateFromTs, $dateToTs): bool {
            if ($statusFilter !== 'all' && (string) ($row['status'] ?? 'new') !== $statusFilter) {
                return false;
            }

            $timestamp = strtotime((string) ($row['timestamp'] ?? ''));
            if ($dateFromTs !== null && ($timestamp === false || $timestamp < $dateFromTs)) {
                return false;
            }
            if ($dateToTs !== null && ($timestamp === false || $timestamp > $dateToTs)) {
                return false;
            }

            if ($query !== '') {
                $haystack = mb_strtolower((string) ($row['id'] ?? '') . "\n" . (string) ($row['form'] ?? '') . "\n" . json_encode($row['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }

            return true;
        }));

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
        });

        return array_slice($rows, 0, $filter['limit']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadFormSubmissions(string $form): array
    {
        $file = $this->root . '/content/forms-submissions/' . $form . '.jsonl';
        if (!is_file($file)) {
            return [];
        }

        $statusMap = $this->loadSubmissionStatusMap($form);
        $rows = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $index => $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $id = trim((string) ($decoded['id'] ?? ''));
            if ($id === '') {
                $seed = (string) ($decoded['timestamp'] ?? '') . '|' . json_encode($decoded['payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $id = substr(sha1($form . '|' . $index . '|' . $seed), 0, 16);
            }

            $status = $this->normalizeSubmissionStatus((string) ($statusMap[$id] ?? ($decoded['status'] ?? 'new')));
            if ($status === '') {
                $status = 'new';
            }

            $rows[] = [
                'id' => $id,
                'form' => $form,
                'timestamp' => (string) ($decoded['timestamp'] ?? ''),
                'ip' => (string) ($decoded['ip'] ?? ''),
                'status' => $status,
                'payload' => is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function loadSubmissionStatusMap(string $form): array
    {
        $file = $this->root . '/content/forms-submissions/' . $form . '.status.yaml';
        if (!is_file($file)) {
            return [];
        }

        $parsed = Yaml::parse((string) file_get_contents($file));
        if (!is_array($parsed)) {
            return [];
        }

        $rows = [];
        foreach ($parsed as $id => $status) {
            if (!is_string($id)) {
                continue;
            }
            $normalized = $this->normalizeSubmissionStatus((string) $status);
            if ($normalized === '') {
                continue;
            }
            $rows[$id] = $normalized;
        }

        ksort($rows);
        return $rows;
    }

    /**
     * @param array<string, string> $map
     */
    private function writeSubmissionStatusMap(string $form, array $map): void
    {
        $file = $this->root . '/content/forms-submissions/' . $form . '.status.yaml';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        ksort($map);
        file_put_contents($file, Yaml::dump($map));
    }

    private function normalizeSubmissionStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['new', 'in-progress', 'done'], true) ? $status : '';
    }

    private function parseDateStart(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value . ' 00:00:00');
        return $ts === false ? null : $ts;
    }

    private function parseDateEnd(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value . ' 23:59:59');
        return $ts === false ? null : $ts;
    }

    private function redirects(): Response
    {
        return Response::json([
            'ok' => true,
            'redirects' => $this->loadRedirectRules(),
        ]);
    }

    private function saveRedirects(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $incoming = $payload['redirects'] ?? [];
        if (!is_array($incoming)) {
            return Response::json(['error' => 'redirects must be a list'], 422);
        }

        try {
            $rules = $this->normalizeRedirectRules($incoming);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $this->writeRedirectRules($rules);
        $this->cache->clear();
        $this->security->recordAudit('redirects.save', [
            'user' => $this->security->currentUser(),
            'count' => count($rules),
        ]);

        return Response::json([
            'ok' => true,
            'redirects' => $rules,
        ]);
    }

    private function clearCache(): Response
    {
        $this->cache->clear();
        return Response::json(['ok' => true]);
    }

    private function backupStatus(): Response
    {
        return Response::json([
            'ok' => true,
            'status' => $this->backup->schedulerStatus(),
        ]);
    }

    private function createBackup(): Response
    {
        $result = $this->backup->create();
        if (($result['ok'] ?? false) === true) {
            $this->security->recordAudit('backup.create', [
                'user' => $this->security->currentUser(),
                'file' => $result['file'] ?? null,
                'partial' => (bool) ($result['partial'] ?? false),
                'errors' => $result['errors'] ?? [],
                'uploads' => $result['uploads'] ?? [],
            ]);
        }

        return Response::json($result);
    }

    /**
     * @return array{id:string,title:string,value:string,text:string}
     */
    private function backupDashboardWidget(): array
    {
        $status = $this->backup->schedulerStatus();
        $enabled = (bool) ($status['enabled'] ?? false);
        $due = (bool) ($status['due'] ?? false);
        $lastSuccess = trim((string) ($status['last_success_at'] ?? ''));
        $lastError = trim((string) ($status['last_error'] ?? ''));
        $nextDue = trim((string) ($status['next_due_at'] ?? ''));
        $frequency = trim((string) ($status['frequency'] ?? 'daily'));
        $time = trim((string) ($status['time'] ?? '03:00'));
        $weekday = (int) ($status['weekday'] ?? 1);
        $target = trim((string) ($status['last_target'] ?? 'local'));

        $value = 'Disabled';
        if ($enabled) {
            if ($lastError !== '') {
                $value = 'Error';
            } elseif ($due) {
                $value = 'Due';
            } elseif ($lastSuccess !== '') {
                $value = 'Healthy';
            } else {
                $value = 'Waiting';
            }
        }

        $parts = [];
        $parts[] = $enabled
            ? sprintf('Schedule: %s %s%s', $frequency, $time, $frequency === 'weekly' ? (' (weekday ' . $weekday . ')') : '')
            : 'Schedule disabled';

        if ($lastSuccess !== '') {
            $parts[] = 'Last success: ' . $lastSuccess;
            $parts[] = 'Target: ' . ($target !== '' ? $target : 'local');
        } else {
            $parts[] = 'Last success: none yet';
        }

        if ($nextDue !== '') {
            $parts[] = 'Next due: ' . $nextDue;
        }

        if ($lastError !== '') {
            $parts[] = 'Last error: ' . $lastError;
        }

        return [
            'id' => 'backup-scheduler',
            'title' => 'Backup Scheduler',
            'value' => $value,
            'text' => implode(' | ', $parts),
        ];
    }

    private function pluginsList(): Response
    {
        $rows = $this->plugins->list();
        $installs = $this->loadPluginInstallState();
        $registry = PackageInstaller::loadRegistry($this->root . '/content/data/plugin-registry.json');
        $registryById = [];
        foreach ($registry as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string) ($entry['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $registryById[$id] = $entry;
        }

        foreach ($rows as &$row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $install = $installs[$id] ?? [];
            $registryEntry = $registryById[$id] ?? [];
            $row['install'] = is_array($install) ? $install : [];
            $row['registry_version'] = is_array($registryEntry) ? trim((string) ($registryEntry['version'] ?? '')) : '';
            $row['update_supported'] = $this->pluginHasUpdateSource($id, is_array($install) ? $install : [], is_array($registryEntry) ? $registryEntry : []);

            $installedVersion = trim((string) ($row['version'] ?? ''));
            $registryVersion = trim((string) ($row['registry_version'] ?? ''));
            $row['update_available'] = $registryVersion !== ''
                && $installedVersion !== ''
                && version_compare($registryVersion, $installedVersion, '>');
        }
        unset($row);

        return Response::json([
            'ok' => true,
            'plugins' => $rows,
        ]);
    }

    private function togglePlugin(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $id = (string) ($payload['id'] ?? '');
        $active = (bool) ($payload['active'] ?? false);
        if ($id === '') {
            return Response::json(['error' => 'Missing plugin id'], 422);
        }

        $this->plugins->toggle($id, $active);

        return Response::json([
            'ok' => true,
            'message' => 'Plugin state updated. Reload app to apply hook and route changes.',
        ]);
    }

    private function uploadMedia(Request $request): Response
    {
        $file = $request->files['file'] ?? null;
        if (!is_array($file)) {
            return Response::json(['error' => 'Missing file upload'], 422);
        }

        return Response::json($this->media->upload($file));
    }

    private function listMedia(Request $request): Response
    {
        $limit = (int) $request->input('limit', 200);
        return Response::json($this->media->list($limit));
    }

    private function mediaMeta(Request $request): Response
    {
        $file = trim((string) $request->input('file', ''));
        if ($file === '') {
            return Response::json(['error' => 'Missing file'], 422);
        }

        $result = $this->media->meta($file);
        $status = (bool) ($result['ok'] ?? false) ? 200 : 422;
        return Response::json($result, $status);
    }

    private function saveMediaMeta(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $file = trim((string) ($payload['file'] ?? ''));
        $meta = $payload['meta'] ?? [];
        if ($file === '') {
            return Response::json(['error' => 'Missing file'], 422);
        }
        if (!is_array($meta)) {
            return Response::json(['error' => 'meta must be object'], 422);
        }

        $result = $this->media->saveMeta($file, $meta);
        $status = (bool) ($result['ok'] ?? false) ? 200 : 422;
        if ($status === 200) {
            $this->security->recordAudit('media.meta_save', [
                'user' => $this->security->currentUser(),
                'file' => $file,
            ]);
        }

        return Response::json($result, $status);
    }

    private function transformMedia(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $file = trim((string) ($payload['file'] ?? ''));
        if ($file === '') {
            return Response::json(['error' => 'Missing file'], 422);
        }

        $result = $this->media->transform($file, is_array($payload) ? $payload : []);
        $status = (bool) ($result['ok'] ?? false) ? 200 : 422;
        return Response::json($result, $status);
    }

    private function settings(): Response
    {
        return Response::json([
            'ok' => true,
            'settings' => [
                'name' => Config::get($this->config, 'name', 'atoll-cms'),
                'base_url' => Config::get($this->config, 'base_url', ''),
                'updater' => Config::get($this->config, 'updater', []),
                'appearance' => Config::get($this->config, 'appearance', []),
                'smtp' => Config::get($this->config, 'smtp', []),
                'backup' => Config::get($this->config, 'backup', []),
                'security' => Config::get($this->config, 'security', []),
            ],
        ]);
    }

    private function saveSettings(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $settings = $payload['settings'] ?? null;

        if (!is_array($settings)) {
            return Response::json(['error' => 'settings must be object'], 422);
        }

        $previousTheme = (string) Config::get($this->config, 'appearance.theme', 'default');

        foreach (['name', 'base_url', 'updater', 'appearance', 'smtp', 'backup', 'security'] as $key) {
            if (array_key_exists($key, $settings)) {
                $this->config[$key] = $settings[$key];
            }
        }

        Config::save($this->configPath, $this->config);
        $currentTheme = (string) Config::get($this->config, 'appearance.theme', 'default');
        if ($currentTheme !== $previousTheme) {
            $this->cache->clear();
        }

        return Response::json(['ok' => true]);
    }

    private function users(): Response
    {
        $users = Config::get($this->config, 'users', []);
        if (!is_array($users)) {
            return Response::json(['ok' => true, 'users' => []]);
        }

        $rows = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $username = trim((string) ($user['username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $rows[] = [
                'username' => $username,
                'role' => $this->security->normalizeRole((string) ($user['role'] ?? 'owner')),
                'enabled' => !array_key_exists('enabled', $user) || (bool) $user['enabled'],
                'twofa_enabled' => is_string($user['twofa_secret'] ?? null) && (string) $user['twofa_secret'] !== '',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['username'] ?? ''), (string) ($b['username'] ?? '')));
        return Response::json(['ok' => true, 'users' => $rows]);
    }

    private function saveUsers(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $incomingUsers = $payload['users'] ?? [];
        $create = $payload['create'] ?? null;
        if (!is_array($incomingUsers)) {
            return Response::json(['error' => 'users must be list'], 422);
        }

        $existing = Config::get($this->config, 'users', []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $incomingByUsername = [];
        foreach ($incomingUsers as $row) {
            if (!is_array($row)) {
                continue;
            }
            $username = trim((string) ($row['username'] ?? ''));
            if ($username === '') {
                continue;
            }
            $incomingByUsername[$username] = $row;
        }

        $nextUsers = [];
        foreach ($existing as $user) {
            if (!is_array($user)) {
                continue;
            }
            $username = trim((string) ($user['username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $incoming = $incomingByUsername[$username] ?? null;
            if (is_array($incoming)) {
                $user['role'] = $this->security->normalizeRole((string) ($incoming['role'] ?? ($user['role'] ?? 'owner')));
                $user['enabled'] = (bool) ($incoming['enabled'] ?? (!array_key_exists('enabled', $user) || (bool) $user['enabled']));
            } else {
                $user['role'] = $this->security->normalizeRole((string) ($user['role'] ?? 'owner'));
                $user['enabled'] = !array_key_exists('enabled', $user) || (bool) $user['enabled'];
            }

            $nextUsers[] = $user;
        }

        if (is_array($create)) {
            $createUsername = trim((string) ($create['username'] ?? ''));
            if ($createUsername !== '') {
                if (preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $createUsername) !== 1) {
                    return Response::json(['error' => 'Username must be 3-32 chars (a-z, 0-9, . _ -).'], 422);
                }

                foreach ($nextUsers as $user) {
                    if (!is_array($user)) {
                        continue;
                    }
                    if ((string) ($user['username'] ?? '') === $createUsername) {
                        return Response::json(['error' => 'User already exists.'], 422);
                    }
                }

                $password = (string) ($create['password'] ?? '');
                $passwordErrors = SecurityManager::passwordPolicyErrorsForConfig($password, $this->config);
                if ($passwordErrors !== []) {
                    return Response::json(['error' => implode(' ', $passwordErrors)], 422);
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($passwordHash) || $passwordHash === '') {
                    return Response::json(['error' => 'Could not hash password.'], 500);
                }

                $nextUsers[] = [
                    'username' => $createUsername,
                    'password_hash' => $passwordHash,
                    'role' => $this->security->normalizeRole((string) ($create['role'] ?? 'editor')),
                    'enabled' => (bool) ($create['enabled'] ?? true),
                ];
            }
        }

        $enabledOwners = 0;
        foreach ($nextUsers as $user) {
            if (!is_array($user)) {
                continue;
            }
            $enabled = !array_key_exists('enabled', $user) || (bool) $user['enabled'];
            $role = $this->security->normalizeRole((string) ($user['role'] ?? 'owner'));
            if ($enabled && $role === 'owner') {
                $enabledOwners++;
            }
        }
        if ($enabledOwners < 1) {
            return Response::json(['error' => 'At least one enabled owner is required.'], 422);
        }

        $this->config['users'] = $nextUsers;
        Config::save($this->configPath, $this->config);

        $this->security->recordAudit('auth.users_saved', [
            'user' => $this->security->currentUser(),
            'count' => count($nextUsers),
        ]);

        return Response::json(['ok' => true]);
    }

    private function pluginRegistry(): Response
    {
        $file = $this->root . '/content/data/plugin-registry.json';
        $registry = PackageInstaller::loadRegistry($file);
        $licenses = PackageInstaller::loadLicenses($this->root);
        $installState = $this->loadPluginInstallState();
        $pluginState = [];
        foreach ($this->plugins->list() as $plugin) {
            $pluginId = (string) ($plugin['id'] ?? '');
            if ($pluginId === '') {
                continue;
            }
            $pluginState[$pluginId] = [
                'installed' => true,
                'active' => (bool) ($plugin['active'] ?? false),
                'version' => (string) ($plugin['version'] ?? ''),
            ];
        }

        foreach ($registry as &$entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $storedLicense = trim((string) ($licenses['plugins'][$id] ?? ''));
            $requiresLicense = (bool) ($entry['requires_license'] ?? false);
            $entry['installed'] = (bool) ($pluginState[$id]['installed'] ?? false);
            $entry['active'] = (bool) ($pluginState[$id]['active'] ?? false);
            $installedVersion = trim((string) ($pluginState[$id]['version'] ?? ''));
            $registryVersion = trim((string) ($entry['version'] ?? ''));
            $entry['installed_version'] = $installedVersion;
            $entry['update_available'] = $registryVersion !== ''
                && $installedVersion !== ''
                && version_compare($registryVersion, $installedVersion, '>');
            $entry['update_supported'] = $this->pluginHasUpdateSource(
                $id,
                is_array($installState[$id] ?? null) ? $installState[$id] : [],
                $entry
            );
            $entry['has_license'] = $storedLicense !== '';
            if ($requiresLicense && $storedLicense !== '') {
                $verification = PackageInstaller::verifyMarketplaceLicense($this->root, 'plugins', $id, $storedLicense);
                $entry['license_valid'] = (bool) ($verification['valid'] ?? false);
                $entry['license_reason'] = (string) ($verification['reason'] ?? '');
            }
        }
        unset($entry);

        return Response::json([
            'ok' => true,
            'registry' => $registry,
        ]);
    }

    private function themeRegistry(): Response
    {
        $file = $this->root . '/content/data/theme-registry.json';
        $registry = PackageInstaller::loadRegistry($file);
        $licenses = PackageInstaller::loadLicenses($this->root);
        $themes = $this->availableThemes();

        foreach ($registry as &$entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $storedLicense = trim((string) ($licenses['themes'][$id] ?? ''));
            $requiresLicense = (bool) ($entry['requires_license'] ?? false);
            $entry['installed'] = isset($themes[$id]);
            $entry['active'] = (bool) ($themes[$id]['active'] ?? false);
            $entry['has_license'] = $storedLicense !== '';
            if ($requiresLicense && $storedLicense !== '') {
                $verification = PackageInstaller::verifyMarketplaceLicense($this->root, 'themes', $id, $storedLicense);
                $entry['license_valid'] = (bool) ($verification['valid'] ?? false);
                $entry['license_reason'] = (string) ($verification['reason'] ?? '');
            }
        }
        unset($entry);

        return Response::json([
            'ok' => true,
            'registry' => $registry,
        ]);
    }

    private function marketplaceOrders(Request $request): Response
    {
        $limit = (int) $request->input('limit', 200);
        $limit = max(1, min(1000, $limit));

        return Response::json([
            'ok' => true,
            'orders' => PackageInstaller::marketplaceOrders($this->root, $limit),
        ]);
    }

    private function marketplacePurchase(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $kind = trim((string) ($payload['kind'] ?? ''));
        $id = trim((string) ($payload['id'] ?? ''));
        $buyerEmail = trim((string) ($payload['buyer_email'] ?? ''));
        $buyerName = trim((string) ($payload['buyer_name'] ?? ''));

        try {
            $result = PackageInstaller::purchaseMarketplaceItem($this->root, $kind, $id, $buyerEmail, $buyerName);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $this->security->recordAudit('marketplace.purchase', [
            'user' => $this->security->currentUser(),
            'kind' => $kind,
            'id' => $id,
            'buyer_email' => $buyerEmail,
            'order_id' => $result['order']['order_id'] ?? null,
        ]);

        return Response::json([
            'ok' => true,
            'purchase' => $result,
        ]);
    }

    private function marketplaceLicenseVerify(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $kind = trim((string) ($payload['kind'] ?? ''));
        $id = trim((string) ($payload['id'] ?? ''));
        $licenseKey = trim((string) ($payload['license_key'] ?? ''));

        try {
            $verification = PackageInstaller::verifyMarketplaceLicense($this->root, $kind, $id, $licenseKey);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json([
            'ok' => true,
            'verification' => $verification,
        ]);
    }

    private function pluginPage(Request $request): Response
    {
        $view = trim((string) $request->input('view', ''));
        if ($view === '') {
            return Response::json(['error' => 'Missing plugin view id'], 422);
        }

        $page = $this->plugins->adminPage($view);
        if ($page === null) {
            return Response::json(['error' => 'Plugin admin page not found'], 404);
        }

        $raw = (string) $request->input('raw', '0');
        if (in_array(strtolower($raw), ['1', 'true', 'yes'], true)) {
            return Response::text((string) file_get_contents($page['path']))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        return Response::json([
            'ok' => true,
            'view' => $view,
            'plugin' => $page['plugin'],
            'title' => $page['title'],
            'url' => '/admin/api/plugin-page?view=' . rawurlencode($view) . '&raw=1',
        ]);
    }

    private function installPlugin(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $source = trim((string) ($payload['source'] ?? ''));
        $id = trim((string) ($payload['id'] ?? ''));
        $force = (bool) ($payload['force'] ?? false);
        $enable = (bool) ($payload['enable'] ?? true);
        $licenseKey = trim((string) ($payload['license_key'] ?? ''));

        try {
            $result = $id !== ''
                ? PackageInstaller::installPluginFromRegistry(
                    $this->root,
                    $id,
                    $force,
                    $enable,
                    $this->config,
                    $licenseKey !== '' ? $licenseKey : null
                )
                : PackageInstaller::installPlugin($this->root, $source, $force, $enable, $this->config);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $installedId = trim((string) ($result['id'] ?? $id));
        if ($installedId !== '') {
            $this->rememberPluginInstallSource(
                $installedId,
                $id !== '' ? 'registry' : 'source',
                $id !== '' ? $id : $source
            );
        }

        $this->security->recordAudit('plugin.install', [
            'user' => $this->security->currentUser(),
            'plugin' => $result['id'] ?? $id,
        ]);

        return Response::json([
            'ok' => true,
            'installed' => $result,
            'message' => 'Plugin installed. Reload app to apply hook and route changes.',
        ]);
    }

    private function updatePlugin(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return Response::json(['error' => 'Missing plugin id'], 422);
        }

        try {
            $result = $this->performPluginUpdate($id);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $this->cache->clear();
        $this->security->recordAudit('plugin.update', [
            'user' => $this->security->currentUser(),
            'plugin' => $id,
            'source_type' => $result['source_type'] ?? '',
            'source' => $result['source'] ?? '',
        ]);

        return Response::json([
            'ok' => true,
            'updated' => $result,
            'message' => 'Plugin updated. Reload app to apply hook and route changes.',
        ]);
    }

    private function updateAllPlugins(): Response
    {
        $pluginIds = [];
        foreach ($this->plugins->list() as $plugin) {
            $id = trim((string) ($plugin['id'] ?? ''));
            if ($id !== '') {
                $pluginIds[] = $id;
            }
        }

        $updated = [];
        $errors = [];
        foreach ($pluginIds as $id) {
            try {
                $updated[] = $this->performPluginUpdate($id);
            } catch (RuntimeException $e) {
                $errors[] = [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($updated !== []) {
            $this->cache->clear();
        }
        $this->security->recordAudit('plugin.update_all', [
            'user' => $this->security->currentUser(),
            'updated' => count($updated),
            'errors' => count($errors),
        ]);

        return Response::json([
            'ok' => $errors === [],
            'updated' => $updated,
            'errors' => $errors,
        ], $errors === [] ? 200 : 207);
    }

    private function uninstallPlugin(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return Response::json(['error' => 'Missing plugin id'], 422);
        }

        $manifests = $this->plugins->all();
        if (!isset($manifests[$id])) {
            return Response::json(['error' => 'Plugin not found: ' . $id], 404);
        }

        $blocking = [];
        foreach ($manifests as $pluginId => $manifest) {
            if ($pluginId === $id) {
                continue;
            }
            if (!(bool) ($manifest['active'] ?? false)) {
                continue;
            }
            if (in_array($id, $this->pluginDependenciesFromManifest($manifest), true)) {
                $blocking[] = $pluginId;
            }
        }
        if ($blocking !== []) {
            return Response::json([
                'error' => 'Plugin is required by active plugins: ' . implode(', ', $blocking),
            ], 422);
        }

        $path = $this->pluginInstallPath($id);
        if (!is_dir($path) && !is_link($path)) {
            return Response::json(['error' => 'Plugin files missing: ' . $id], 404);
        }

        try {
            $this->assertPluginPathInsideSitePlugins($path);
            $this->deleteFilesystemPath($path);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $state = $this->loadPluginStateFile();
        unset($state[$id]);
        $this->savePluginStateFile($state);
        $this->removePluginInstallSource($id);
        $this->removeStoredPluginLicense($id);

        $this->cache->clear();
        $this->security->recordAudit('plugin.uninstall', [
            'user' => $this->security->currentUser(),
            'plugin' => $id,
        ]);

        return Response::json([
            'ok' => true,
            'removed' => $id,
            'message' => 'Plugin uninstalled. Reload app to apply hook and route changes.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function performPluginUpdate(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new RuntimeException('Missing plugin id.');
        }

        $plugins = $this->plugins->all();
        $plugin = $plugins[$id] ?? null;
        if (!is_array($plugin)) {
            throw new RuntimeException('Plugin not installed: ' . $id);
        }
        $active = (bool) ($plugin['active'] ?? false);
        $path = $this->pluginInstallPath($id);
        if (!is_dir($path) && !is_link($path)) {
            throw new RuntimeException('Plugin files missing: ' . $id);
        }

        $source = $this->resolvePluginUpdateSource($id);
        $rollbackRoot = rtrim($this->root, '/') . '/cache/plugin-rollbacks';
        if (!is_dir($rollbackRoot)) {
            mkdir($rollbackRoot, 0775, true);
        }
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable) {
            $suffix = (string) mt_rand(1000, 9999);
        }
        $backupPath = $rollbackRoot . '/' . $id . '-' . date('Ymd-His') . '-' . $suffix;
        if (!@rename($path, $backupPath)) {
            throw new RuntimeException('Could not prepare rollback backup for plugin: ' . $id);
        }

        try {
            if (($source['type'] ?? '') === 'source') {
                $install = PackageInstaller::installPlugin(
                    $this->root,
                    (string) ($source['source'] ?? ''),
                    false,
                    $active,
                    $this->config,
                    $id
                );
            } else {
                $install = PackageInstaller::installPluginFromRegistry(
                    $this->root,
                    $id,
                    false,
                    $active,
                    $this->config
                );
            }
        } catch (RuntimeException $e) {
            if (file_exists($path) || is_link($path)) {
                $this->deleteFilesystemPath($path);
            }
            if (!@rename($backupPath, $path)) {
                throw new RuntimeException('Plugin update failed and rollback could not be restored: ' . $e->getMessage());
            }
            throw new RuntimeException('Plugin update failed, previous version restored: ' . $e->getMessage());
        }

        $this->deleteFilesystemPath($backupPath);
        $this->rememberPluginInstallSource($id, (string) ($source['type'] ?? 'registry'), (string) ($source['source'] ?? $id));

        return [
            'id' => $id,
            'source_type' => $source['type'] ?? 'registry',
            'source' => $source['source'] ?? $id,
            'active' => $active,
            'installed' => $install,
        ];
    }

    private function themes(): Response
    {
        $activeTheme = (string) Config::get($this->config, 'appearance.theme', 'default');
        $rows = $this->availableThemes();
        foreach ($rows as &$row) {
            $row['active'] = ($row['id'] ?? '') === $activeTheme;
        }
        unset($row);

        return Response::json([
            'ok' => true,
            'themes' => array_values($rows),
            'active' => $activeTheme,
        ]);
    }

    private function installTheme(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $source = trim((string) ($payload['source'] ?? ''));
        $id = trim((string) ($payload['id'] ?? ''));
        $force = (bool) ($payload['force'] ?? false);
        $licenseKey = trim((string) ($payload['license_key'] ?? ''));

        try {
            $result = $id !== ''
                ? PackageInstaller::installThemeFromRegistry(
                    $this->root,
                    $id,
                    $force,
                    $this->config,
                    $licenseKey !== '' ? $licenseKey : null
                )
                : PackageInstaller::installTheme($this->root, $source, $force, $this->config);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $this->security->recordAudit('theme.install', [
            'user' => $this->security->currentUser(),
            'theme' => $result['id'] ?? $id,
        ]);

        return Response::json(['ok' => true, 'installed' => $result]);
    }

    private function activateTheme(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return Response::json(['error' => 'Missing theme id'], 422);
        }

        $themes = $this->availableThemes();
        if (!isset($themes[$id])) {
            return Response::json(['error' => 'Theme not found: ' . $id], 404);
        }

        $appearance = Config::get($this->config, 'appearance', []);
        $appearance = is_array($appearance) ? $appearance : [];
        $appearance['theme'] = $id;
        $this->config['appearance'] = $appearance;
        Config::save($this->configPath, $this->config);
        $this->cache->clear();

        $this->security->recordAudit('theme.activate', [
            'user' => $this->security->currentUser(),
            'theme' => $id,
        ]);

        return Response::json(['ok' => true, 'active' => $id]);
    }

    private function uninstallTheme(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return Response::json(['error' => 'Missing theme id'], 422);
        }

        $themes = $this->availableThemes();
        if (!isset($themes[$id])) {
            return Response::json(['error' => 'Theme not found: ' . $id], 404);
        }

        $activeTheme = (string) Config::get($this->config, 'appearance.theme', 'default');
        if ($activeTheme === $id) {
            return Response::json(['error' => 'Active theme cannot be uninstalled'], 422);
        }

        $source = (string) ($themes[$id]['source'] ?? 'site');
        if ($source !== 'site') {
            return Response::json(['error' => 'Built-in core themes cannot be uninstalled'], 422);
        }

        $themePath = rtrim($this->root, '/') . '/themes/' . $id;
        if (!is_dir($themePath) && !is_link($themePath)) {
            return Response::json(['error' => 'Theme files missing: ' . $id], 404);
        }

        try {
            $this->assertThemePathInsideSiteThemes($themePath);
            $this->deleteFilesystemPath($themePath);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $this->cache->clear();
        $this->security->recordAudit('theme.uninstall', [
            'user' => $this->security->currentUser(),
            'theme' => $id,
        ]);

        return Response::json(['ok' => true, 'removed' => $id]);
    }

    /**
     * @return array<string, array{id:string,source:string,preview?:string,active?:bool}>
     */
    private function availableThemes(): array
    {
        $rows = [];

        $siteDirs = glob($this->root . '/themes/*', GLOB_ONLYDIR) ?: [];
        foreach ($siteDirs as $dir) {
            $id = basename($dir);
            $rows[$id] = [
                'id' => $id,
                'source' => 'site',
                'preview' => $this->resolveThemePreview($dir, $id, 'site'),
            ];
        }

        $coreDirs = glob($this->coreThemesDir() . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($coreDirs as $dir) {
            $id = basename($dir);
            if (!isset($rows[$id])) {
                $rows[$id] = [
                    'id' => $id,
                    'source' => 'core',
                    'preview' => $this->resolveThemePreview($dir, $id, 'core'),
                ];
            }
        }

        ksort($rows);
        return $rows;
    }

    private function coreThemesDir(): string
    {
        $configured = Config::get($this->config, 'core.path', 'core');
        $corePath = is_string($configured) && $configured !== '' ? $configured : 'core';
        if (!str_starts_with($corePath, '/')) {
            $corePath = $this->root . '/' . ltrim($corePath, '/');
        }

        return rtrim($corePath, '/') . '/themes';
    }

    private function pluginInstallPath(string $id): string
    {
        return rtrim($this->root, '/') . '/plugins/' . $id;
    }

    private function assertPluginPathInsideSitePlugins(string $pluginPath): void
    {
        $pluginsRoot = realpath(rtrim($this->root, '/') . '/plugins');
        $parent = realpath(dirname($pluginPath));
        if ($pluginsRoot === false || $parent === false || $parent !== $pluginsRoot) {
            throw new RuntimeException('Invalid plugin path.');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, string>
     */
    private function pluginDependenciesFromManifest(array $manifest): array
    {
        $raw = $manifest['requires_plugins'] ?? $manifest['dependencies'] ?? [];
        $rows = [];

        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw) ?: [];
        }

        if (is_array($raw)) {
            if (!array_is_list($raw)) {
                foreach ($raw as $pluginId => $constraint) {
                    if (is_string($pluginId) && trim($pluginId) !== '') {
                        $rows[] = trim($pluginId);
                    } elseif (is_string($constraint) && trim($constraint) !== '') {
                        $rows[] = trim($constraint);
                    }
                }
            } else {
                foreach ($raw as $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $rows[] = trim($value);
                    }
                }
            }
        }

        $rows = array_values(array_unique($rows));
        sort($rows);
        return $rows;
    }

    /**
     * @return array<string, bool>
     */
    private function loadPluginStateFile(): array
    {
        $file = $this->root . '/content/data/plugins.yaml';
        if (!is_file($file)) {
            return [];
        }

        $parsed = Yaml::parse((string) file_get_contents($file));
        if (!is_array($parsed)) {
            return [];
        }

        $rows = [];
        foreach ($parsed as $id => $active) {
            if (!is_string($id) || trim($id) === '') {
                continue;
            }
            $rows[$id] = (bool) $active;
        }

        ksort($rows);
        return $rows;
    }

    /**
     * @param array<string, bool> $state
     */
    private function savePluginStateFile(array $state): void
    {
        $file = $this->root . '/content/data/plugins.yaml';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        ksort($state);
        file_put_contents($file, Yaml::dump($state));
    }

    private function pluginInstallStateFile(): string
    {
        return $this->root . '/content/data/plugin-installs.yaml';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadPluginInstallState(): array
    {
        $file = $this->pluginInstallStateFile();
        if (!is_file($file)) {
            return [];
        }

        $parsed = Yaml::parse((string) file_get_contents($file));
        if (!is_array($parsed)) {
            return [];
        }

        $rows = [];
        foreach ($parsed as $id => $row) {
            if (!is_string($id) || trim($id) === '' || !is_array($row)) {
                continue;
            }
            $rows[$id] = $row;
        }

        ksort($rows);
        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $state
     */
    private function savePluginInstallState(array $state): void
    {
        $file = $this->pluginInstallStateFile();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        ksort($state);
        file_put_contents($file, Yaml::dump($state));
    }

    private function rememberPluginInstallSource(string $id, string $type, string $source): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }

        $type = strtolower(trim($type));
        if (!in_array($type, ['registry', 'source'], true)) {
            $type = 'source';
        }

        $source = trim($source);
        $state = $this->loadPluginInstallState();
        $state[$id] = [
            'type' => $type,
            'source' => $source,
            'updated_at' => date('c'),
        ];
        $this->savePluginInstallState($state);
    }

    private function removePluginInstallSource(string $id): void
    {
        $state = $this->loadPluginInstallState();
        unset($state[$id]);
        $this->savePluginInstallState($state);
    }

    /**
     * @param array<string, mixed> $install
     * @param array<string, mixed> $registryEntry
     */
    private function pluginHasUpdateSource(string $id, array $install, array $registryEntry): bool
    {
        $type = strtolower(trim((string) ($install['type'] ?? '')));
        $source = trim((string) ($install['source'] ?? ''));

        if ($type === 'source' && $source !== '') {
            return true;
        }
        if ($type === 'registry') {
            return true;
        }
        if ($registryEntry !== []) {
            return true;
        }

        return is_dir($this->pluginInstallPath($id));
    }

    /**
     * @return array{type:string,source:string}
     */
    private function resolvePluginUpdateSource(string $id): array
    {
        $installs = $this->loadPluginInstallState();
        $install = $installs[$id] ?? [];
        $type = strtolower(trim((string) ($install['type'] ?? '')));
        $source = trim((string) ($install['source'] ?? ''));

        if ($type === 'source' && $source !== '') {
            return [
                'type' => 'source',
                'source' => $source,
            ];
        }

        $registry = PackageInstaller::loadRegistry($this->root . '/content/data/plugin-registry.json');
        foreach ($registry as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string) ($entry['id'] ?? '')) === $id) {
                return [
                    'type' => 'registry',
                    'source' => $id,
                ];
            }
        }

        if ($type === 'registry' || $type === '') {
            throw new RuntimeException('No update source found in registry for plugin: ' . $id);
        }

        throw new RuntimeException('No update source configured for plugin: ' . $id);
    }

    private function removeStoredPluginLicense(string $id): void
    {
        $licenses = PackageInstaller::loadLicenses($this->root);
        if (!is_array($licenses['plugins'] ?? null)) {
            return;
        }
        if (!array_key_exists($id, $licenses['plugins'])) {
            return;
        }

        unset($licenses['plugins'][$id]);
        $file = $this->root . '/content/data/licenses.yaml';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, Yaml::dump([
            'plugins' => $licenses['plugins'],
            'themes' => is_array($licenses['themes'] ?? null) ? $licenses['themes'] : [],
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function createAutoSlugRedirect(?Page $before, ?Page $after): ?array
    {
        if ($before === null || $after === null) {
            return null;
        }

        $from = $this->normalizeRedirectPath($before->url);
        $to = $this->normalizeRedirectPath($after->url);
        if ($from === '' || $to === '' || $from === $to) {
            return null;
        }

        $rules = $this->loadRedirectRules();
        foreach ($rules as $rule) {
            $existingFrom = $this->normalizeRedirectPath((string) ($rule['from'] ?? ''));
            if ($existingFrom !== $from) {
                continue;
            }

            $existingTo = $this->normalizeRedirectPath((string) ($rule['to'] ?? ''));
            if ($existingTo === $to && (int) ($rule['status'] ?? 301) === 301) {
                return [
                    'created' => false,
                    'from' => $from,
                    'to' => $to,
                    'status' => 301,
                    'reason' => 'exists',
                ];
            }

            return [
                'created' => false,
                'from' => $from,
                'to' => $to,
                'status' => 301,
                'reason' => 'conflict',
            ];
        }

        $rules[] = [
            'id' => $this->createRedirectId($from, $to),
            'from' => $from,
            'to' => $to,
            'status' => 301,
            'auto' => true,
        ];

        try {
            $normalized = $this->normalizeRedirectRules($rules);
            $this->writeRedirectRules($normalized);
            $this->cache->clear();
            $this->security->recordAudit('redirect.auto_slug', [
                'user' => $this->security->currentUser(),
                'from' => $from,
                'to' => $to,
            ]);
        } catch (RuntimeException) {
            return [
                'created' => false,
                'from' => $from,
                'to' => $to,
                'status' => 301,
                'reason' => 'invalid',
            ];
        }

        return [
            'created' => true,
            'from' => $from,
            'to' => $to,
            'status' => 301,
            'reason' => 'slug_changed',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRedirectRules(): array
    {
        $file = $this->root . '/content/data/redirects.yaml';
        if (!is_file($file)) {
            return [];
        }

        $parsed = Yaml::parse((string) file_get_contents($file));
        if (!is_array($parsed)) {
            return [];
        }

        $rows = [];
        foreach ($parsed as $rule) {
            if (is_array($rule)) {
                $rows[] = $rule;
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function writeRedirectRules(array $rules): void
    {
        $file = $this->root . '/content/data/redirects.yaml';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, Yaml::dump(array_values($rules)));
    }

    /**
     * @param array<int, mixed> $incoming
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRedirectRules(array $incoming): array
    {
        $normalized = [];
        $fromSeen = [];

        foreach ($incoming as $index => $raw) {
            if (!is_array($raw)) {
                throw new RuntimeException('Redirect entry #' . ($index + 1) . ' must be an object.');
            }
            $rule = $this->normalizeRedirectRule($raw, $index);
            if ($rule === null) {
                continue;
            }

            $from = (string) $rule['from'];
            $to = (string) $rule['to'];
            if (isset($fromSeen[$from])) {
                throw new RuntimeException("Duplicate redirect source '{$from}'.");
            }
            if ($from === $to) {
                throw new RuntimeException("Redirect loop detected for '{$from}'.");
            }
            if (!str_contains($from, '*') && !str_contains($to, '*') && isset($fromSeen[$to])) {
                $otherTo = (string) ($fromSeen[$to]['to'] ?? '');
                if ($otherTo === $from) {
                    throw new RuntimeException("Redirect loop between '{$from}' and '{$to}'.");
                }
            }

            $fromSeen[$from] = $rule;
            $normalized[] = $rule;
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeRedirectRule(array $raw, int $index): ?array
    {
        $from = $this->normalizeRedirectPath((string) ($raw['from'] ?? ''));
        $to = $this->normalizeRedirectPath((string) ($raw['to'] ?? ''));

        if ($from === '' && $to === '') {
            return null;
        }
        if ($from === '' || $to === '') {
            throw new RuntimeException('Redirect entry #' . ($index + 1) . ' requires both from and to.');
        }
        if (str_contains($to, '*')) {
            throw new RuntimeException("Redirect target '{$to}' must not contain wildcard '*'.");
        }
        if (str_contains($to, '$1') && !str_contains($from, '*')) {
            throw new RuntimeException("Redirect '{$from}' uses \$1 without wildcard source.");
        }

        $status = (int) ($raw['status'] ?? 301);
        if (!in_array($status, [301, 302], true)) {
            throw new RuntimeException("Redirect '{$from}' has invalid status {$status}.");
        }

        return [
            'id' => trim((string) ($raw['id'] ?? '')) !== ''
                ? trim((string) $raw['id'])
                : $this->createRedirectId($from, $to),
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'auto' => (bool) ($raw['auto'] ?? false),
        ];
    }

    private function normalizeRedirectPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            $parsed = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    private function createRedirectId(string $from, string $to): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $from . '-' . $to));
        $slug = trim($slug, '-');
        $prefix = $slug !== '' ? substr($slug, 0, 32) : 'redirect';
        return $prefix . '-' . substr(sha1($from . '|' . $to), 0, 8);
    }

    private function resolveThemePreview(string $themeDir, string $themeId, string $source): ?string
    {
        $metaFile = rtrim($themeDir, '/') . '/theme.yaml';
        if (is_file($metaFile)) {
            $meta = Yaml::parse((string) file_get_contents($metaFile));
            $configured = (string) ($meta['preview'] ?? $meta['screenshot'] ?? $meta['thumbnail'] ?? '');
            $configured = ltrim(trim($configured), '/');
            if ($configured !== '' && is_file($themeDir . '/' . $configured)) {
                $prefix = $source === 'core' ? '/core/themes/' : '/themes/';
                return $prefix . rawurlencode($themeId) . '/' . str_replace('\\', '/', $configured);
            }
        }

        $candidates = [
            'assets/preview.webp',
            'assets/preview.png',
            'assets/preview.jpg',
            'assets/screenshot.webp',
            'assets/screenshot.png',
            'assets/screenshot.jpg',
            'assets/thumbnail.webp',
            'assets/thumbnail.png',
            'assets/thumbnail.jpg',
        ];

        foreach ($candidates as $relative) {
            if (is_file($themeDir . '/' . $relative)) {
                $prefix = $source === 'core' ? '/core/themes/' : '/themes/';
                return $prefix . rawurlencode($themeId) . '/' . $relative;
            }
        }

        return null;
    }

    private function assertThemePathInsideSiteThemes(string $themePath): void
    {
        $themesRoot = realpath(rtrim($this->root, '/') . '/themes');
        $parent = realpath(dirname($themePath));
        if ($themesRoot === false || $parent === false || $parent !== $themesRoot) {
            throw new RuntimeException('Invalid theme path.');
        }
    }

    private function deleteFilesystemPath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException('Unable to remove file: ' . basename($path));
            }
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_link($child) || is_file($child)) {
                if (!@unlink($child)) {
                    throw new RuntimeException('Unable to remove file: ' . $entry);
                }
                continue;
            }

            if (is_dir($child)) {
                $this->deleteFilesystemPath($child);
            }
        }

        if (!@rmdir($path)) {
            throw new RuntimeException('Unable to remove directory: ' . basename($path));
        }
    }

    private function securityAudit(Request $request): Response
    {
        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min(500, $limit));

        return Response::json([
            'ok' => true,
            'entries' => $this->security->auditEntries($limit),
        ]);
    }

    private function securityMixedContentScan(Request $request): Response
    {
        $limit = (int) $request->input('limit', 200);
        $limit = max(1, min(1000, $limit));
        $findings = [];
        $scannedFiles = 0;

        $roots = [
            $this->root . '/config.yaml',
            $this->root . '/content',
            $this->root . '/templates',
            $this->root . '/themes',
            $this->root . '/plugins',
        ];
        $extensions = ['yaml', 'yml', 'md', 'twig', 'php', 'css', 'js', 'json', 'html', 'txt', 'xml'];

        foreach ($roots as $root) {
            if (count($findings) >= $limit) {
                break;
            }

            if (is_file($root)) {
                $scannedFiles += $this->scanFileForMixedContent($root, $findings, $limit);
                continue;
            }

            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (count($findings) >= $limit) {
                    break;
                }
                if (!$file->isFile()) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                if (
                    str_contains($path, '/node_modules/')
                    || str_contains($path, '/vendor/')
                    || str_contains($path, '/cache/')
                    || str_contains($path, '/backups/')
                ) {
                    continue;
                }

                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === '' || !in_array($ext, $extensions, true)) {
                    continue;
                }

                $size = $file->getSize();
                if (is_int($size) && $size > 1024 * 512) {
                    continue;
                }

                $scannedFiles += $this->scanFileForMixedContent($path, $findings, $limit);
            }
        }

        return Response::json([
            'ok' => true,
            'scanned_files' => $scannedFiles,
            'count' => count($findings),
            'findings' => $findings,
        ]);
    }

    private function setupTwoFactor(Request $request): Response
    {
        $currentUser = $this->security->currentUser();
        if ($currentUser === null) {
            return Response::json(['error' => 'Unauthenticated'], 401);
        }

        $payload = $request->isJson() ? $request->json() : $request->post;
        $secret = strtoupper(trim((string) ($payload['secret'] ?? '')));
        $code = trim((string) ($payload['code'] ?? ''));
        if ($secret === '') {
            $secret = $this->security->generateTotpSecret();
        }

        $issuer = (string) Config::get($this->config, 'name', 'atoll-cms');
        $uri = $this->security->totpProvisioningUri($issuer, $currentUser, $secret);

        if ($code === '') {
            return Response::json([
                'ok' => true,
                'pending' => true,
                'secret' => $secret,
                'otpauth' => $uri,
            ]);
        }

        if (!$this->security->verifyTotp($secret, $code)) {
            return Response::json(['error' => 'Invalid authenticator code'], 422);
        }

        $updated = $this->updateUser($currentUser, static function (array $user) use ($secret): array {
            $user['twofa_secret'] = $secret;
            return $user;
        });
        if (!$updated) {
            return Response::json(['error' => 'Could not update user settings'], 500);
        }

        $this->security->recordAudit('auth.2fa_enabled', ['user' => $currentUser]);

        return Response::json([
            'ok' => true,
            'pending' => false,
            'twofa_enabled' => true,
        ]);
    }

    private function disableTwoFactor(Request $request): Response
    {
        $currentUser = $this->security->currentUser();
        if ($currentUser === null) {
            return Response::json(['error' => 'Unauthenticated'], 401);
        }

        $updated = $this->updateUser($currentUser, static function (array $user): array {
            unset($user['twofa_secret']);
            return $user;
        });
        if (!$updated) {
            return Response::json(['error' => 'Could not update user settings'], 500);
        }

        $this->security->recordAudit('auth.2fa_disabled', ['user' => $currentUser]);
        return Response::json(['ok' => true, 'twofa_enabled' => false]);
    }

    private function isCurrentUserTwoFactorEnabled(): bool
    {
        $username = $this->security->currentUser();
        if ($username === null) {
            return false;
        }

        $users = Config::get($this->config, 'users', []);
        if (!is_array($users)) {
            return false;
        }

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            if (($user['username'] ?? null) !== $username) {
                continue;
            }
            return is_string($user['twofa_secret'] ?? null) && $user['twofa_secret'] !== '';
        }

        return false;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function updateUser(string $username, callable $mutator): bool
    {
        $users = Config::get($this->config, 'users', []);
        if (!is_array($users)) {
            return false;
        }

        $changed = false;
        foreach ($users as $idx => $user) {
            if (!is_array($user)) {
                continue;
            }
            if (($user['username'] ?? null) !== $username) {
                continue;
            }

            $users[$idx] = $mutator($user);
            $changed = true;
            break;
        }

        if (!$changed) {
            return false;
        }

        $this->config['users'] = $users;
        Config::save($this->configPath, $this->config);
        return true;
    }

    /**
     * @param array<int, array{id:string,label:string,route:string,icon:string}> $items
     * @param array<string, bool> $seen
     */
    private function appendAdminMenuItem(array &$items, array &$seen, mixed $candidate): void
    {
        if (!is_array($candidate)) {
            return;
        }

        $label = trim((string) ($candidate['label'] ?? ''));
        $route = trim((string) ($candidate['route'] ?? ''));
        if ($label === '' || $route === '') {
            return;
        }

        $id = trim((string) ($candidate['id'] ?? ''));
        if ($id === '') {
            $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $label));
            $slug = trim($slug, '-');
            $id = $slug !== '' ? $slug : ('menu-' . (count($items) + 1));
        }

        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;

        $items[] = [
            'id' => $id,
            'label' => $label,
            'route' => $route,
            'icon' => trim((string) ($candidate['icon'] ?? '')),
        ];
    }

    /**
     * @param array<int, array{id:string,title:string,value:string,text:string}> $widgets
     */
    private function appendDashboardWidget(array &$widgets, mixed $candidate, int &$counter): void
    {
        if (is_string($candidate)) {
            $text = trim($candidate);
            if ($text === '') {
                return;
            }
            $counter++;
            $widgets[] = [
                'id' => 'widget-' . $counter,
                'title' => 'Plugin Widget',
                'value' => '',
                'text' => $text,
            ];
            return;
        }

        if (!is_array($candidate)) {
            return;
        }

        $title = trim((string) ($candidate['title'] ?? $candidate['label'] ?? ''));
        $value = trim((string) ($candidate['value'] ?? ''));
        $text = trim((string) ($candidate['text'] ?? $candidate['description'] ?? ''));
        if ($title === '' && $value === '' && $text === '') {
            return;
        }
        if ($title === '') {
            $title = 'Plugin Widget';
        }

        $id = trim((string) ($candidate['id'] ?? ''));
        if ($id === '') {
            $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title));
            $slug = trim($slug, '-');
            $counter++;
            $id = $slug !== '' ? $slug : ('widget-' . $counter);
        }

        $widgets[] = [
            'id' => $id,
            'title' => $title,
            'value' => $value,
            'text' => $text,
        ];
    }

    private function permissionForEndpoint(string $endpoint, string $method): ?string
    {
        $method = strtoupper(trim($method));
        return match ($endpoint) {
            '/me', '/menu', '/dashboard/widgets' => 'dashboard.read',
            '/collections', '/collection/meta', '/entries', '/entry', '/entry/revisions', '/entry/revision' => 'content.read',
            '/entry/save', '/collection/meta/save', '/entry/revision/restore' => 'content.write',
            '/entry/delete' => 'content.delete',
            '/forms/submissions', '/forms/submissions/export' => 'forms.read',
            '/forms/submissions/status' => 'forms.write',
            '/redirects' => 'redirects.read',
            '/redirects/save' => 'redirects.write',
            '/backup/status' => 'dashboard.read',
            '/cache/clear', '/backup/create' => 'ops.manage',
            '/plugins', '/plugin-registry', '/plugin-page' => 'plugins.read',
            '/plugins/toggle', '/plugins/install', '/plugins/update', '/plugins/update-all', '/plugins/uninstall' => 'plugins.manage',
            '/themes', '/theme-registry' => 'themes.read',
            '/themes/install', '/themes/activate', '/themes/uninstall' => 'themes.manage',
            '/marketplace/orders', '/marketplace/license/verify' => 'marketplace.read',
            '/marketplace/purchase' => 'marketplace.write',
            '/media/list', '/media/meta' => 'media.read',
            '/media/upload', '/media/transform', '/media/meta/save' => 'media.write',
            '/security/audit', '/security/mixed-content/scan' => 'security.read',
            '/security/2fa/setup', '/security/2fa/disable' => 'security.self',
            '/settings' => 'settings.read',
            '/settings/save' => 'settings.write',
            '/users' => 'users.read',
            '/users/save' => 'users.write',
            default => null,
        };
    }

    /**
     * @param array<int, array{file:string,line:int,urls:array<int,string>,snippet:string}> $findings
     */
    private function scanFileForMixedContent(string $path, array &$findings, int $limit): int
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return 0;
        }

        $relative = str_starts_with($path, $this->root . '/')
            ? substr($path, strlen($this->root) + 1)
            : $path;

        foreach ($lines as $index => $line) {
            if (count($findings) >= $limit) {
                break;
            }

            $urls = $this->security->mixedContentFindings((string) $line);
            if ($urls === []) {
                continue;
            }

            $findings[] = [
                'file' => str_replace('\\', '/', (string) $relative),
                'line' => $index + 1,
                'urls' => $urls,
                'snippet' => trim((string) $line),
            ];
        }

        return 1;
    }

    private function resolveAdminFile(string $relative): string
    {
        $relative = ltrim($relative, '/');
        foreach ($this->adminRoots as $root) {
            $candidate = rtrim($root, '/') . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
