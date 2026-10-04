<?php

declare(strict_types=1);

namespace Atoll\Installer;

use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Security\SecurityManager;
use Atoll\Support\Config;
use Atoll\Support\Yaml;

final class InstallerController
{
    public function __construct(
        private readonly string $root,
        private readonly string $configPath
    ) {
    }

    public function handle(Request $request, bool $alreadyConfigured): Response
    {
        if ($alreadyConfigured) {
            $body = '<h1>atoll-cms ist bereits installiert</h1>'
                . '<p><a href="/admin">Zum Admin</a> · <a href="/">Zur Website</a></p>';
            return Response::html($this->pageShell('Installer', $body), 200);
        }

        if ($request->method === 'POST') {
            return $this->submit($request);
        }

        return Response::html($this->renderForm([], $this->requirementsReport()), 200);
    }

    private function submit(Request $request): Response
    {
        $requirements = $this->requirementsReport();
        if (($requirements['ok'] ?? false) !== true) {
            $errors = array_map(
                static fn (array $check): string => (string) ($check['message'] ?? 'Systemvoraussetzung nicht erfüllt.'),
                array_values(array_filter(
                    $requirements['checks'] ?? [],
                    static fn (array $check): bool => (bool) ($check['required'] ?? false) && !(bool) ($check['ok'] ?? false)
                ))
            );
            return Response::html($this->renderForm($errors, $requirements), 422);
        }

        $name = trim((string) ($request->post['name'] ?? 'atoll-cms'));
        $baseUrl = trim((string) ($request->post['base_url'] ?? 'http://localhost:8080'));
        $timezone = trim((string) ($request->post['timezone'] ?? 'Europe/Berlin'));
        $username = trim((string) ($request->post['admin_username'] ?? 'admin'));
        $password = (string) ($request->post['admin_password'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors[] = 'Site-Name ist erforderlich.';
        }
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Base URL muss eine gueltige URL sein.';
        }
        if ($username === '' || preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username) !== 1) {
            $errors[] = 'Admin-Benutzer muss 3-32 Zeichen lang sein (a-z, 0-9, . _ -).';
        }

        $defaultConfig = $this->defaultConfig($name, $baseUrl, $timezone, $username, '');
        $passwordErrors = SecurityManager::passwordPolicyErrorsForConfig($password, $defaultConfig);
        $errors = array_merge($errors, $passwordErrors);

        if ($errors !== []) {
            return Response::html($this->renderForm($errors, $requirements), 422);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $config = $this->defaultConfig($name, $baseUrl, $timezone, $username, $hash);

        Config::save($this->configPath, $config);
        $this->ensureProjectFiles();

        return Response::redirect('/admin', 302);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultConfig(
        string $name,
        string $baseUrl,
        string $timezone,
        string $username,
        string $passwordHash
    ): array {
        return [
            'name' => $name,
            'base_url' => $baseUrl,
            'environment' => 'prod',
            'timezone' => $timezone,
            'core' => [
                'path' => 'core',
            ],
            'updater' => [
                'channel' => 'stable',
                'manifest_url' => 'https://raw.githubusercontent.com/atoll-cms/atoll-updates/main/channels/stable.json',
                'public_key' => 'config/updater-public.pem',
                'require_signature' => true,
                'timeout_seconds' => 15,
            ],
            'cache' => [
                'enabled' => true,
                'ttl' => 3600,
            ],
            'content' => [
                'index' => [
                    'enabled' => true,
                    'driver' => 'sqlite',
                    'path' => 'cache/content-index.sqlite',
                ],
                'revisions' => [
                    'enabled' => true,
                    'max_per_entry' => 20,
                ],
            ],
            'backup' => [
                'schedule' => [
                    'enabled' => false,
                    'frequency' => 'daily',
                    'time' => '03:00',
                    'weekday' => 1,
                ],
                'targets' => [
                    'local' => [
                        'enabled' => true,
                    ],
                    's3' => [
                        'enabled' => false,
                        'endpoint' => '',
                        'region' => 'eu-central-1',
                        'bucket' => '',
                        'access_key' => '',
                        'secret_key' => '',
                        'prefix' => 'atoll-backups',
                        'path_style' => true,
                    ],
                    'sftp' => [
                        'enabled' => false,
                        'host' => '',
                        'port' => 22,
                        'username' => '',
                        'password' => '',
                        'private_key_file' => '',
                        'public_key_file' => '',
                        'passphrase' => '',
                        'path' => '/backups/atoll',
                    ],
                ],
            ],
            'security' => [
                'force_https' => false,
                'hsts' => false,
                'mixed_content_check' => [
                    'enabled' => true,
                ],
                'content_security_policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'",
                'rate_limit' => [
                    'enabled' => true,
                    'requests' => 120,
                    'window_seconds' => 60,
                ],
                'admin_ip_allowlist' => [],
                'session' => [
                    'enabled' => true,
                    'ttl_minutes' => 480,
                    'secure_cookie' => false,
                    'same_site' => 'Lax',
                    'name' => 'ATOLLSESSID',
                ],
                'password' => [
                    'min_length' => 12,
                    'require_uppercase' => true,
                    'require_lowercase' => true,
                    'require_digit' => true,
                    'require_special' => true,
                ],
                'forms' => [
                    'anti_spam' => [
                        'min_seconds' => 2,
                        'timestamp_field' => '_atoll_ts',
                        'require_timestamp' => false,
                        'max_links' => 3,
                        'blocked_phrases' => [],
                        'disposable_domains' => [],
                        'email_field' => 'email',
                        'require_user_agent' => false,
                    ],
                    'captcha' => [
                        'enabled' => false,
                        'provider' => 'turnstile',
                        'site_key' => '',
                        'secret' => '',
                        'token_field' => 'captcha_token',
                        'minimum_score' => 0.5,
                    ],
                ],
            ],
            'smtp' => [
                'driver' => 'mail',
                'host' => 'localhost',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'sendmail_path' => '',
                'from_email' => 'noreply@example.com',
                'from_name' => $name,
                'api' => [
                    'postmark' => [
                        'token' => '',
                        'endpoint' => 'https://api.postmarkapp.com/email',
                    ],
                    'mailgun' => [
                        'domain' => '',
                        'api_key' => '',
                        'endpoint' => '',
                    ],
                    'ses' => [
                        'region' => 'eu-central-1',
                        'access_key' => '',
                        'secret_key' => '',
                        'session_token' => '',
                        'endpoint' => '',
                    ],
                ],
            ],
            'seo' => [
                'default_title' => $name,
                'default_description' => 'Modern flat-file CMS for PHP.',
                'default_image' => '/assets/images/og-default.jpg',
                'robots' => "User-agent: *\nAllow: /",
            ],
            'users' => [
                [
                    'username' => $username,
                    'password_hash' => $passwordHash,
                    'role' => 'owner',
                    'enabled' => true,
                ],
            ],
            'features' => [
                'seo_core' => true,
                'forms_core' => true,
                'media_core' => true,
                'backup_core' => true,
                'redirects_core' => true,
            ],
            'appearance' => [
                'theme' => 'default',
            ],
            'plugins' => [
                'defaults_enabled' => true,
            ],
            'analytics' => [
                'enabled' => false,
                'provider' => 'plausible',
                'domain' => '',
                'src' => 'https://plausible.io/js/script.js',
                'require_consent' => true,
            ],
            'i18n' => [
                'locales' => ['de', 'en'],
                'default_locale' => 'de',
                'prefix_default_locale' => false,
            ],
            'forms_pro' => [
                'webhooks' => [],
            ],
        ];
    }

    private function ensureProjectFiles(): void
    {
        $requiredDirs = [
            $this->root . '/cache',
            $this->root . '/cache/rate-limit',
            $this->root . '/backups',
            $this->root . '/content/data',
            $this->root . '/content/forms',
            $this->root . '/content/forms-submissions',
        ];
        foreach ($requiredDirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $pluginsState = $this->root . '/content/data/plugins.yaml';
        if (!is_file($pluginsState)) {
            file_put_contents($pluginsState, Yaml::dump([]));
        }

        $themeRegistry = $this->root . '/content/data/theme-registry.json';
        if (!is_file($themeRegistry)) {
            file_put_contents($themeRegistry, json_encode([
                [
                    'id' => 'business',
                    'name' => 'Business Theme',
                    'type' => 'official',
                    'source' => 'https://github.com/atoll-cms/atoll-theme-business/archive/refs/heads/main.zip',
                    'description' => 'Official external theme for corporate/service websites',
                    'preview' => 'https://raw.githubusercontent.com/atoll-cms/atoll-theme-business/main/assets/preview.png',
                ],
                [
                    'id' => 'editorial',
                    'name' => 'Editorial Theme',
                    'type' => 'official',
                    'source' => 'https://github.com/atoll-cms/atoll-theme-editorial/archive/refs/heads/main.zip',
                    'description' => 'Official external theme for docs/blog style sites',
                    'preview' => 'https://raw.githubusercontent.com/atoll-cms/atoll-theme-editorial/main/assets/preview.png',
                ],
                [
                    'id' => 'portfolio',
                    'name' => 'Portfolio Theme',
                    'type' => 'official',
                    'source' => 'https://github.com/atoll-cms/atoll-theme-portfolio/archive/refs/heads/main.zip',
                    'description' => 'Official external theme for visual showcase sites',
                    'preview' => 'https://raw.githubusercontent.com/atoll-cms/atoll-theme-portfolio/main/assets/preview.png',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    /**
     * @param array<int, string> $errors
     */
    private function renderForm(array $errors = [], ?array $requirements = null): string
    {
        $requirements ??= $this->requirementsReport();
        $errorsHtml = '';
        if ($errors !== []) {
            $items = array_map(
                static fn (string $e): string => '<li>' . htmlspecialchars($e, ENT_QUOTES) . '</li>',
                $errors
            );
            $errorsHtml = '<ul class="errors">' . implode('', $items) . '</ul>';
        }

        $checksHtml = '';
        $checks = $requirements['checks'] ?? [];
        if (is_array($checks) && $checks !== []) {
            $items = [];
            foreach ($checks as $check) {
                if (!is_array($check)) {
                    continue;
                }
                $label = htmlspecialchars((string) ($check['label'] ?? 'Check'), ENT_QUOTES);
                $message = htmlspecialchars((string) ($check['message'] ?? ''), ENT_QUOTES);
                $ok = (bool) ($check['ok'] ?? false);
                $required = (bool) ($check['required'] ?? false);
                $class = $ok ? 'ok' : ($required ? 'fail' : 'warn');
                $prefix = $ok ? 'OK' : ($required ? 'Fehlt' : 'Optional');
                $items[] = '<li class="' . $class . '"><strong>' . $prefix . ':</strong> ' . $label . ' <span>' . $message . '</span></li>';
            }
            if ($items !== []) {
                $checksHtml = '<div class="checks"><h2>System-Checks</h2><ul>' . implode('', $items) . '</ul></div>';
            }
        }

        $body = <<<HTML
<h1>atoll-cms Installation</h1>
<p>Einmalige Einrichtung fuer diese Instanz.</p>
{$errorsHtml}
{$checksHtml}
<form method="post" class="installer-form">
  <label>Site Name<input name="name" required value="atoll-cms"></label>
  <label>Base URL<input name="base_url" required value="http://localhost:8080"></label>
  <label>Timezone<input name="timezone" required value="Europe/Berlin"></label>
  <label>Admin Username<input name="admin_username" required value="admin"></label>
  <label>Admin Passwort<input name="admin_password" type="password" required></label>
  <button type="submit">Installieren</button>
</form>
HTML;

        return $this->pageShell('atoll Installer', $body);
    }

    private function pageShell(string $title, string $body): string
    {
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<style>body{font-family:ui-sans-serif,system-ui,sans-serif;background:#0d1b1e;color:#f1f6f6;padding:2rem}a{color:#f6c06f}.installer-form,.checks,.errors{max-width:560px}.installer-form{background:#13292d;border:1px solid #2d4c52;border-radius:12px;padding:1rem}.installer-form label{display:block;margin:.7rem 0}.installer-form input{width:100%;padding:.6rem;border-radius:8px;border:1px solid #2d4c52;background:#0f2226;color:#f1f6f6}.installer-form button{margin-top:1rem;padding:.7rem 1rem;border:0;border-radius:8px;background:#f59e0b;color:#1e1300;font-weight:700}.errors{background:#3b1515;border:1px solid #7f2f2f;border-radius:12px;padding:.8rem 1.2rem}.checks{margin:1rem 0;background:#13292d;border:1px solid #2d4c52;border-radius:12px;padding:.8rem 1rem}.checks h2{margin:0 0 .6rem;font-size:1rem}.checks ul{margin:0;padding-left:1.2rem;display:grid;gap:.35rem}.checks li.ok{color:#85efac}.checks li.warn{color:#f6d079}.checks li.fail{color:#fca5a5}.checks li span{opacity:.85}</style>'
            . '</head><body>' . $body . '</body></html>';
    }

    /**
     * @return array{
     *   ok:bool,
     *   checks:array<int, array{label:string,ok:bool,required:bool,message:string}>
     * }
     */
    private function requirementsReport(): array
    {
        $checks = [];
        $checks[] = [
            'label' => 'PHP >= 8.2',
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'required' => true,
            'message' => 'Aktuell: ' . PHP_VERSION,
        ];
        $checks[] = [
            'label' => 'Extension: mbstring',
            'ok' => extension_loaded('mbstring'),
            'required' => true,
            'message' => extension_loaded('mbstring') ? 'vorhanden' : 'mbstring fehlt',
        ];
        $checks[] = [
            'label' => 'Extension: json',
            'ok' => extension_loaded('json'),
            'required' => true,
            'message' => extension_loaded('json') ? 'vorhanden' : 'json fehlt',
        ];

        $hasImageExt = extension_loaded('gd') || extension_loaded('imagick');
        $checks[] = [
            'label' => 'Extension: gd oder imagick',
            'ok' => $hasImageExt,
            'required' => true,
            'message' => $hasImageExt
                ? 'Bildverarbeitung aktiv (' . (extension_loaded('imagick') ? 'imagick' : 'gd') . ')'
                : 'weder gd noch imagick gefunden',
        ];

        $checks[] = [
            'label' => 'Schreibrechte: Projektordner',
            'ok' => is_dir($this->root) && is_writable($this->root),
            'required' => true,
            'message' => is_writable($this->root) ? 'ok' : 'nicht schreibbar',
        ];
        $checks[] = [
            'label' => 'Extension: zip (Backups)',
            'ok' => extension_loaded('zip'),
            'required' => false,
            'message' => extension_loaded('zip') ? 'vorhanden' : 'optional, aber empfohlen fuer ZIP-Backups',
        ];

        $ok = true;
        foreach ($checks as $check) {
            if (($check['required'] ?? false) && !($check['ok'] ?? false)) {
                $ok = false;
                break;
            }
        }

        return [
            'ok' => $ok,
            'checks' => $checks,
        ];
    }
}
