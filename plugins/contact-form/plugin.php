<?php

declare(strict_types=1);

return [
    'name' => 'Contact Form Tools',
    'description' => 'Adds contact form related admin entries and demo route',
    'version' => '1.0.0',
    'hooks' => [
        'admin:menu' => static function (): array {
            return ['label' => 'Forms', 'icon' => 'mail', 'route' => '/admin#forms'];
        },
        'admin:dashboard' => static function (): array {
            return [
                'id' => 'forms-health',
                'title' => 'Forms',
                'value' => 'Live',
                'text' => 'Kontaktformular-Tools sind aktiv.',
            ];
        },
    ],
    'routes' => [
        '/contact-form/ping' => static fn (): array => ['ok' => true, 'plugin' => 'contact-form'],
    ],
    'islands' => [
        'ContactForm' => 'islands/ContactForm.js',
    ],
];
