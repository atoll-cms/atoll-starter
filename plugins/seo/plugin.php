<?php

declare(strict_types=1);

return [
    'name' => 'SEO',
    'description' => 'Additional SEO helpers and diagnostics',
    'version' => '1.0.0',
    'hooks' => [
        'head:meta' => static function (?array $page): string {
            if (!is_array($page)) {
                return '';
            }

            $canonical = htmlspecialchars((string) ($page['url'] ?? '/'), ENT_QUOTES);
            return '<link rel="canonical" href="' . $canonical . '">';
        },
        'content:save' => static function (array $payload): void {
            $log = dirname(__DIR__, 2) . '/cache/seo.log';
            $line = '[' . date('c') . '] content:save ' . ($payload['collection'] ?? '') . '/' . ($payload['id'] ?? '') . "\n";
            file_put_contents($log, $line, FILE_APPEND);
        },
        'admin:menu' => static function (): array {
            return ['label' => 'SEO', 'icon' => 'search', 'route' => '/admin#seo'];
        },
        'admin:dashboard' => static function (): array {
            return [
                'id' => 'seo-health',
                'title' => 'SEO',
                'value' => 'OK',
                'text' => 'Sitemap und Robots sind aktiv.',
            ];
        },
        'auth:login' => static function (string $username): void {
            $log = dirname(__DIR__, 2) . '/cache/auth.log';
            $line = '[' . date('c') . '] auth:login ' . $username . "\n";
            file_put_contents($log, $line, FILE_APPEND);
        },
    ],
    'routes' => [
        '/seo/health' => static function (): array {
            return [
                'ok' => true,
                'service' => 'seo-plugin',
                'timestamp' => date('c'),
            ];
        },
    ],
    'islands' => [
        'SeoPreview' => 'islands/SeoPreview.js',
    ],
];
