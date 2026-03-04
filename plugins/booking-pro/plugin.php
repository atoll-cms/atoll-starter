<?php

declare(strict_types=1);

return [
    'name' => 'booking-pro',
    'description' => 'Appointment booking workflow with timeslots and iCal export',
    'version' => '1.0.0',
    'hooks' => [],
    'routes' => [
        '/booking-pro/health' => static fn (): array => [
            'ok' => true,
            'plugin' => 'booking-pro',
            'features' => ['timeslots', 'notifications', 'ical'],
        ],
    ],
    'islands' => [],
];
