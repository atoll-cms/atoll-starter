<?php

declare(strict_types=1);

return [
    'name' => 'i18n',
    'description' => 'Language helpers, hreflang tags and URL prefix strategy',
    'version' => '0.1.0',
    'hooks' => [
        'head:meta' => static function (?array $page): string {
            if (!is_array($page)) {
                return '';
            }

            $config = \Atoll\Support\Config::load(dirname(__DIR__, 2) . '/config.yaml');
            $i18n = \Atoll\Support\Config::get($config, 'i18n', []);
            if (!is_array($i18n)) {
                return '';
            }

            $locales = $i18n['locales'] ?? ['de'];
            if (!is_array($locales) || $locales === []) {
                $locales = ['de'];
            }
            $defaultLocale = (string) ($i18n['default_locale'] ?? $locales[0] ?? 'de');
            $prefixDefault = (bool) ($i18n['prefix_default_locale'] ?? false);
            $baseUrl = rtrim((string) \Atoll\Support\Config::get($config, 'base_url', ''), '/');

            $currentUrl = (string) ($page['url'] ?? '/');
            if ($currentUrl === '') {
                $currentUrl = '/';
            }

            $tags = [];
            foreach ($locales as $locale) {
                if (!is_string($locale) || $locale === '') {
                    continue;
                }
                $path = ltrim($currentUrl, '/');
                if ($locale === $defaultLocale && !$prefixDefault) {
                    $localizedPath = '/' . $path;
                } else {
                    $localizedPath = '/' . trim($locale, '/') . ($path !== '' ? '/' . $path : '');
                }
                $localizedPath = preg_replace('#//+#', '/', $localizedPath) ?: '/';
                $href = ($baseUrl !== '' ? $baseUrl : '') . $localizedPath;
                $tags[] = '<link rel="alternate" hreflang="' . htmlspecialchars($locale, ENT_QUOTES) . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
            }

            if ($tags === []) {
                return '';
            }

            $fallback = ($baseUrl !== '' ? $baseUrl : '') . $currentUrl;
            $tags[] = '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($fallback, ENT_QUOTES) . '">';

            return implode("\n", $tags);
        },
    ],
    'routes' => [],
    'islands' => [],
];
