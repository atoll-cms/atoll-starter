<?php

declare(strict_types=1);

return [
    'name' => 'analytics',
    'description' => 'Privacy-first analytics integration (Plausible/Umami/custom)',
    'version' => '0.1.0',
    'hooks' => [
        'body:end' => static function (): string {
            $configPath = dirname(__DIR__, 2) . '/config.yaml';
            $config = \Atoll\Support\Config::load($configPath);
            $analytics = \Atoll\Support\Config::get($config, 'analytics', []);
            if (!is_array($analytics) || !($analytics['enabled'] ?? false)) {
                return '';
            }

            $provider = (string) ($analytics['provider'] ?? 'plausible');
            $requireConsent = (bool) ($analytics['require_consent'] ?? true);
            $consentAttr = $requireConsent ? ' data-requires-consent="1"' : '';

            if ($provider === 'plausible') {
                $domain = (string) ($analytics['domain'] ?? parse_url((string) \Atoll\Support\Config::get($config, 'base_url', ''), PHP_URL_HOST));
                $src = (string) ($analytics['src'] ?? 'https://plausible.io/js/script.js');
                if ($domain === '') {
                    return '<!-- analytics plugin: plausible domain missing -->';
                }

                return sprintf(
                    '<script defer data-domain="%s" src="%s"%s></script>',
                    htmlspecialchars($domain, ENT_QUOTES),
                    htmlspecialchars($src, ENT_QUOTES),
                    $consentAttr
                );
            }

            if ($provider === 'umami') {
                $websiteId = (string) ($analytics['website_id'] ?? '');
                $src = (string) ($analytics['src'] ?? '');
                if ($websiteId === '' || $src === '') {
                    return '<!-- analytics plugin: umami website_id/src missing -->';
                }

                return sprintf(
                    '<script defer src="%s" data-website-id="%s"%s></script>',
                    htmlspecialchars($src, ENT_QUOTES),
                    htmlspecialchars($websiteId, ENT_QUOTES),
                    $consentAttr
                );
            }

            $custom = (string) ($analytics['script'] ?? '');
            return $custom !== '' ? $custom : '<!-- analytics plugin: no script configured -->';
        },
    ],
    'routes' => [],
    'islands' => [],
];
