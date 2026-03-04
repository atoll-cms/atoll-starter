<?php

declare(strict_types=1);

return [
    'name' => 'shop',
    'description' => 'Minimal commerce plugin scaffold',
    'version' => '0.1.0',
    'hooks' => [],
    'routes' => [
        '/shop/health' => static fn (): array => ['ok' => true, 'plugin' => 'shop'],
    ],
    'islands' => [],
];
