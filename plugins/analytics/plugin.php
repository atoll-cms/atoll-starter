<?php

declare(strict_types=1);

return [
    'name' => 'analytics',
    'description' => 'Privacy-first analytics integration points',
    'version' => '0.1.0',
    'hooks' => [
        'body:end' => static function (): string {
            return '<!-- analytics plugin slot: add plausible/umami script by config -->';
        },
    ],
    'routes' => [],
    'islands' => [],
];
