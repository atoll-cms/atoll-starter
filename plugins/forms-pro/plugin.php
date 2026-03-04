<?php

declare(strict_types=1);

return [
    'name' => 'forms-pro',
    'description' => 'Multi-step and webhook extension points',
    'version' => '0.1.0',
    'hooks' => [],
    'routes' => [
        '/forms-pro/health' => static fn (): array => ['ok' => true, 'plugin' => 'forms-pro'],
    ],
    'islands' => [],
];
