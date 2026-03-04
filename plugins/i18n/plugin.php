<?php

declare(strict_types=1);

return [
    'name' => 'i18n',
    'description' => 'Language routing and hreflang base plugin',
    'version' => '0.1.0',
    'hooks' => [
        'head:meta' => static function (?array $page): string {
            if (!is_array($page)) {
                return '';
            }
            $url = htmlspecialchars((string) ($page['url'] ?? '/'), ENT_QUOTES);
            return '<link rel="alternate" hreflang="de" href="' . $url . '">';
        },
    ],
    'routes' => [],
    'islands' => [],
];
