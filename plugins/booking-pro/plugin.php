<?php

declare(strict_types=1);

use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Mail\Mailer;
use Atoll\Support\Config;

$root = dirname(__DIR__, 2);
$configPath = $root . '/config.yaml';
$bookingsFile = $root . '/content/data/booking-pro-bookings.json';

$config = Config::load($configPath);
$timezone = (string) Config::get($config, 'booking_pro.timezone', Config::get($config, 'timezone', 'Europe/Berlin'));
$sessionName = (string) Config::get($config, 'security.session.name', 'ATOLLSESSID');
$slotMinutes = max(5, (int) Config::get($config, 'booking_pro.slot_minutes', 30));
$workStart = trim((string) Config::get($config, 'booking_pro.working_hours.start', '09:00'));
$workEnd = trim((string) Config::get($config, 'booking_pro.working_hours.end', '17:00'));
$weekdays = Config::get($config, 'booking_pro.weekdays', [1, 2, 3, 4, 5]);
$blackoutDates = Config::get($config, 'booking_pro.blackout_dates', []);
$adminEmail = trim((string) Config::get($config, 'booking_pro.admin_email', Config::get($config, 'smtp.from_email', 'noreply@example.com')));
$icalToken = trim((string) Config::get($config, 'booking_pro.ical_token', ''));
$baseUrl = rtrim((string) Config::get($config, 'base_url', ''), '/');

if (!is_dir(dirname($bookingsFile))) {
    mkdir(dirname($bookingsFile), 0775, true);
}

$servicesConfig = Config::get($config, 'booking_pro.services', []);
if (!is_array($servicesConfig) || $servicesConfig === []) {
    $servicesConfig = [
        'consultation' => [
            'label' => 'Consultation',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
        ],
    ];
}

$services = [];
foreach ($servicesConfig as $serviceId => $service) {
    if (!is_string($serviceId) || !is_array($service)) {
        continue;
    }

    $normalized = trim($serviceId);
    if ($normalized === '') {
        continue;
    }

    $services[$normalized] = [
        'id' => $normalized,
        'label' => trim((string) ($service['label'] ?? $normalized)),
        'duration_minutes' => max(10, (int) ($service['duration_minutes'] ?? 30)),
        'buffer_minutes' => max(0, (int) ($service['buffer_minutes'] ?? 0)),
    ];
}

if ($services === []) {
    $services = [
        'consultation' => [
            'id' => 'consultation',
            'label' => 'Consultation',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
        ],
    ];
}

$weekdays = array_values(array_filter(array_map(static fn (mixed $value): int => (int) $value, is_array($weekdays) ? $weekdays : []), static fn (int $value): bool => $value >= 1 && $value <= 7));
if ($weekdays === []) {
    $weekdays = [1, 2, 3, 4, 5];
}

$blackoutLookup = [];
foreach (is_array($blackoutDates) ? $blackoutDates : [] as $date) {
    $normalizedDate = trim((string) $date);
    if ($normalizedDate !== '') {
        $blackoutLookup[$normalizedDate] = true;
    }
}

$tz = new DateTimeZone($timezone);

$readBookings = static function () use ($bookingsFile): array {
    if (!is_file($bookingsFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($bookingsFile), true);
    return is_array($decoded) ? array_values($decoded) : [];
};

$writeBookings = static function (array $rows) use ($bookingsFile): void {
    file_put_contents(
        $bookingsFile,
        (string) json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
};

$extractInput = static function (Request $request): array {
    if ($request->isJson()) {
        return $request->json();
    }

    return array_merge($request->query, $request->post);
};

$ensureAdminSession = static function () use ($sessionName): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (session_name() !== $sessionName) {
        session_name($sessionName);
    }

    @session_start();
};

$isAdminAuthenticated = static function () use ($ensureAdminSession): bool {
    $ensureAdminSession();
    $user = $_SESSION['_atoll_user'] ?? null;
    return is_string($user) && $user !== '';
};

$normalizeDate = static function (string $date): ?string {
    $trimmed = trim($date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) !== 1) {
        return null;
    }

    return $trimmed;
};

$normalizeTime = static function (string $time): ?string {
    $trimmed = trim($time);
    if (preg_match('/^\d{2}:\d{2}$/', $trimmed) !== 1) {
        return null;
    }

    return $trimmed;
};

$resolveService = static function (string $serviceId) use ($services): ?array {
    $normalized = trim($serviceId);
    if ($normalized === '') {
        return null;
    }

    return $services[$normalized] ?? null;
};

$makeDateTime = static function (string $date, string $time) use ($tz): ?DateTimeImmutable {
    try {
        return new DateTimeImmutable($date . ' ' . $time . ':00', $tz);
    } catch (Throwable) {
        return null;
    }
};

$isDateBookable = static function (string $date) use ($normalizeDate, $makeDateTime, $weekdays, $blackoutLookup): bool {
    $normalizedDate = $normalizeDate($date);
    if ($normalizedDate === null) {
        return false;
    }

    if (isset($blackoutLookup[$normalizedDate])) {
        return false;
    }

    $start = $makeDateTime($normalizedDate, '00:00');
    if (!$start instanceof DateTimeImmutable) {
        return false;
    }

    $weekday = (int) $start->format('N');
    return in_array($weekday, $weekdays, true);
};

$bookingOverlaps = static function (DateTimeImmutable $slotStart, DateTimeImmutable $slotEnd, array $booking, callable $makeDateTime): bool {
    $status = (string) ($booking['status'] ?? '');
    if (in_array($status, ['cancelled', 'rejected'], true)) {
        return false;
    }

    $bookingDate = (string) ($booking['date'] ?? '');
    $bookingStartTime = (string) ($booking['start_time'] ?? '');
    $bookingEndTime = (string) ($booking['end_time'] ?? '');
    $bookingStart = $makeDateTime($bookingDate, $bookingStartTime);
    $bookingEnd = $makeDateTime($bookingDate, $bookingEndTime);
    if (!$bookingStart instanceof DateTimeImmutable || !$bookingEnd instanceof DateTimeImmutable) {
        return false;
    }

    return $slotStart < $bookingEnd && $slotEnd > $bookingStart;
};

$buildSlots = static function (string $date, array $service, array $bookings) use (
    $isDateBookable,
    $makeDateTime,
    $workStart,
    $workEnd,
    $slotMinutes,
    $bookingOverlaps
): array {
    if (!$isDateBookable($date)) {
        return [];
    }

    $dayStart = $makeDateTime($date, $workStart);
    $dayEnd = $makeDateTime($date, $workEnd);
    if (!$dayStart instanceof DateTimeImmutable || !$dayEnd instanceof DateTimeImmutable || $dayEnd <= $dayStart) {
        return [];
    }

    $duration = max(10, (int) ($service['duration_minutes'] ?? 30));
    $buffer = max(0, (int) ($service['buffer_minutes'] ?? 0));

    $slots = [];
    $cursor = $dayStart;

    while ($cursor < $dayEnd) {
        $slotStart = $cursor;
        $slotEnd = $slotStart->modify('+' . $duration . ' minutes');
        if (!$slotEnd instanceof DateTimeImmutable || $slotEnd > $dayEnd) {
            break;
        }

        $blocked = false;
        foreach ($bookings as $booking) {
            if (!is_array($booking)) {
                continue;
            }
            if ((string) ($booking['date'] ?? '') !== $date) {
                continue;
            }

            if ($bookingOverlaps($slotStart, $slotEnd, $booking, $makeDateTime)) {
                $blocked = true;
                break;
            }
        }

        if (!$blocked) {
            $slots[] = [
                'date' => $date,
                'start' => $slotStart->format('H:i'),
                'end' => $slotEnd->format('H:i'),
                'start_at' => $slotStart->format(DateTimeInterface::ATOM),
                'end_at' => $slotEnd->format(DateTimeInterface::ATOM),
                'service' => (string) ($service['id'] ?? ''),
            ];
        }

        $cursor = $slotStart->modify('+' . ($slotMinutes + $buffer) . ' minutes');
        if (!$cursor instanceof DateTimeImmutable) {
            break;
        }
    }

    return $slots;
};

$publicBooking = static function (array $booking): array {
    return [
        'id' => (string) ($booking['id'] ?? ''),
        'service' => (string) ($booking['service'] ?? ''),
        'service_label' => (string) ($booking['service_label'] ?? ''),
        'date' => (string) ($booking['date'] ?? ''),
        'start_time' => (string) ($booking['start_time'] ?? ''),
        'end_time' => (string) ($booking['end_time'] ?? ''),
        'status' => (string) ($booking['status'] ?? ''),
        'customer' => [
            'name' => (string) ($booking['customer']['name'] ?? ''),
            'email' => (string) ($booking['customer']['email'] ?? ''),
            'phone' => (string) ($booking['customer']['phone'] ?? ''),
            'note' => (string) ($booking['customer']['note'] ?? ''),
        ],
        'created_at' => (string) ($booking['created_at'] ?? ''),
        'updated_at' => (string) ($booking['updated_at'] ?? ''),
    ];
};

$icsEscape = static function (string $value): string {
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\\;', $value);
    $value = str_replace(',', '\\,', $value);
    $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);
    return $value;
};

$buildIcal = static function (array $bookings) use ($tz, $icsEscape): string {
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//atoll-cms//booking-pro//EN',
        'CALSCALE:GREGORIAN',
    ];

    foreach ($bookings as $booking) {
        if (!is_array($booking)) {
            continue;
        }

        $status = (string) ($booking['status'] ?? '');
        if (!in_array($status, ['pending', 'confirmed'], true)) {
            continue;
        }

        $date = (string) ($booking['date'] ?? '');
        $startTime = (string) ($booking['start_time'] ?? '');
        $endTime = (string) ($booking['end_time'] ?? '');

        try {
            $start = new DateTimeImmutable($date . ' ' . $startTime . ':00', $tz);
            $end = new DateTimeImmutable($date . ' ' . $endTime . ':00', $tz);
        } catch (Throwable) {
            continue;
        }

        $uid = (string) ($booking['id'] ?? uniqid('booking_', true));
        $summary = $icsEscape((string) ($booking['service_label'] ?? 'Booking') . ' - ' . (string) ($booking['customer']['name'] ?? 'Guest'));
        $description = $icsEscape('Status: ' . $status . '\nEmail: ' . (string) ($booking['customer']['email'] ?? ''));

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $uid . '@atoll-cms';
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART;TZID=' . $tz->getName() . ':' . $start->format('Ymd\THis');
        $lines[] = 'DTEND;TZID=' . $tz->getName() . ':' . $end->format('Ymd\THis');
        $lines[] = 'SUMMARY:' . $summary;
        $lines[] = 'DESCRIPTION:' . $description;
        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
};

$servicesPayload = [];
foreach ($services as $service) {
    $servicesPayload[] = $service;
}

return [
    'name' => 'booking-pro',
    'description' => 'Appointment booking workflow with slots, notifications, calendar UI and iCal export',
    'version' => '1.1.0',
    'hooks' => [
        'admin:menu' => static fn (): array => [
            'id' => 'booking-pro',
            'label' => 'Booking Pro',
            'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
            'route' => '/admin#booking-pro',
        ],
        'admin:dashboard' => static function () use ($readBookings): array {
            $upcoming = 0;
            $today = date('Y-m-d');
            foreach ($readBookings() as $booking) {
                if (!is_array($booking)) {
                    continue;
                }
                $status = (string) ($booking['status'] ?? '');
                if (!in_array($status, ['pending', 'confirmed'], true)) {
                    continue;
                }
                if ((string) ($booking['date'] ?? '') >= $today) {
                    $upcoming++;
                }
            }

            return [
                'id' => 'booking-pro',
                'title' => 'Booking Pro',
                'value' => (string) $upcoming,
                'text' => 'Upcoming bookings',
            ];
        },
    ],
    'routes' => [
        '/booking-pro/health' => static fn (): array => [
            'ok' => true,
            'plugin' => 'booking-pro',
            'features' => ['timeslots', 'notifications', 'calendar_ui', 'ical'],
        ],
        '/booking-pro/services' => static fn (): array => [
            'ok' => true,
            'services' => $servicesPayload,
        ],
        '/booking-pro/slots' => static function (Request $request) use ($extractInput, $resolveService, $readBookings, $buildSlots): Response {
            $input = $extractInput($request);
            $date = trim((string) ($input['date'] ?? ''));
            $serviceId = trim((string) ($input['service'] ?? 'consultation'));

            $service = $resolveService($serviceId);
            if (!is_array($service)) {
                return Response::json(['ok' => false, 'error' => 'Unknown service'], 404);
            }

            $slots = $buildSlots($date, $service, $readBookings());
            return Response::json([
                'ok' => true,
                'date' => $date,
                'service' => $service,
                'slots' => $slots,
            ]);
        },
        '/booking-pro/book' => static function (Request $request) use (
            $extractInput,
            $resolveService,
            $normalizeDate,
            $normalizeTime,
            $readBookings,
            $writeBookings,
            $buildSlots,
            $publicBooking,
            $adminEmail,
            $config,
            $baseUrl,
            $icalToken
        ): Response {
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $extractInput($request);
            $serviceId = trim((string) ($input['service'] ?? 'consultation'));
            $date = $normalizeDate((string) ($input['date'] ?? ''));
            $time = $normalizeTime((string) ($input['time'] ?? ''));
            $name = trim((string) ($input['name'] ?? ''));
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            $phone = trim((string) ($input['phone'] ?? ''));
            $note = trim((string) ($input['note'] ?? ''));

            if ($date === null || $time === null) {
                return Response::json(['ok' => false, 'error' => 'Invalid date/time'], 422);
            }
            if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return Response::json(['ok' => false, 'error' => 'name and valid email are required'], 422);
            }

            $service = $resolveService($serviceId);
            if (!is_array($service)) {
                return Response::json(['ok' => false, 'error' => 'Unknown service'], 404);
            }

            $bookings = $readBookings();
            $availableSlots = $buildSlots($date, $service, $bookings);
            $slot = null;
            foreach ($availableSlots as $candidate) {
                if ((string) ($candidate['start'] ?? '') === $time) {
                    $slot = $candidate;
                    break;
                }
            }

            if (!is_array($slot)) {
                return Response::json(['ok' => false, 'error' => 'Slot is not available'], 409);
            }

            $booking = [
                'id' => 'bkg_' . date('YmdHis') . '_' . random_int(1000, 9999),
                'service' => (string) ($service['id'] ?? ''),
                'service_label' => (string) ($service['label'] ?? ''),
                'date' => $date,
                'start_time' => (string) ($slot['start'] ?? ''),
                'end_time' => (string) ($slot['end'] ?? ''),
                'status' => 'pending',
                'customer' => [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'note' => $note,
                ],
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];

            $bookings[] = $booking;
            $writeBookings($bookings);

            $mailer = new Mailer($config);
            $customerSubject = 'Booking request received: ' . (string) ($service['label'] ?? 'Booking');
            $customerBody = "Hi {{name}},\n\nwe received your booking request for {{service}} on {{date}} at {{time}}.\nStatus: pending";
            $mailer->send($email, $customerSubject, $customerBody, [
                'name' => $name,
                'service' => (string) ($service['label'] ?? ''),
                'date' => $date,
                'time' => (string) ($slot['start'] ?? ''),
            ]);

            if ($adminEmail !== '') {
                $adminSubject = 'New booking: ' . (string) ($service['label'] ?? 'Booking');
                $adminBody = "New booking request\n\nName: {{name}}\nEmail: {{email}}\nDate: {{date}}\nTime: {{time}}\nService: {{service}}\nPhone: {{phone}}\nNote: {{note}}";
                $mailer->send($adminEmail, $adminSubject, $adminBody, [
                    'name' => $name,
                    'email' => $email,
                    'date' => $date,
                    'time' => (string) ($slot['start'] ?? ''),
                    'service' => (string) ($service['label'] ?? ''),
                    'phone' => $phone,
                    'note' => $note,
                ]);
            }

            $icalUrl = $baseUrl !== '' ? $baseUrl . '/booking-pro/ical' : '/booking-pro/ical';
            if ($icalToken !== '') {
                $icalUrl .= (str_contains($icalUrl, '?') ? '&' : '?') . 'token=' . rawurlencode($icalToken);
            }

            return Response::json([
                'ok' => true,
                'booking' => $publicBooking($booking),
                'ical_url' => $icalUrl,
            ]);
        },
        '/booking-pro/admin/bookings' => static function (Request $request) use ($isAdminAuthenticated, $readBookings, $publicBooking): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            $statusFilter = trim((string) $request->input('status', ''));
            $fromDate = trim((string) $request->input('from', ''));
            $toDate = trim((string) $request->input('to', ''));

            $rows = [];
            foreach ($readBookings() as $booking) {
                if (!is_array($booking)) {
                    continue;
                }

                $status = (string) ($booking['status'] ?? '');
                $date = (string) ($booking['date'] ?? '');

                if ($statusFilter !== '' && $status !== $statusFilter) {
                    continue;
                }
                if ($fromDate !== '' && $date < $fromDate) {
                    continue;
                }
                if ($toDate !== '' && $date > $toDate) {
                    continue;
                }

                $rows[] = $publicBooking($booking);
            }

            usort($rows, static function (array $a, array $b): int {
                $left = ($a['date'] ?? '') . ' ' . ($a['start_time'] ?? '');
                $right = ($b['date'] ?? '') . ' ' . ($b['start_time'] ?? '');
                return strcmp((string) $left, (string) $right);
            });

            return Response::json([
                'ok' => true,
                'bookings' => $rows,
            ]);
        },
        '/booking-pro/admin/bookings/update' => static function (Request $request) use ($isAdminAuthenticated, $extractInput, $readBookings, $writeBookings, $publicBooking): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $extractInput($request);
            $id = trim((string) ($input['id'] ?? ''));
            $status = trim((string) ($input['status'] ?? ''));
            if ($id === '' || !in_array($status, ['pending', 'confirmed', 'cancelled', 'completed'], true)) {
                return Response::json(['ok' => false, 'error' => 'Invalid id or status'], 422);
            }

            $bookings = $readBookings();
            $updated = null;
            foreach ($bookings as $idx => $booking) {
                if (!is_array($booking)) {
                    continue;
                }
                if ((string) ($booking['id'] ?? '') !== $id) {
                    continue;
                }

                $booking['status'] = $status;
                $booking['updated_at'] = date('c');
                $bookings[$idx] = $booking;
                $updated = $booking;
                break;
            }

            if (!is_array($updated)) {
                return Response::json(['ok' => false, 'error' => 'Booking not found'], 404);
            }

            $writeBookings($bookings);
            return Response::json([
                'ok' => true,
                'booking' => $publicBooking($updated),
            ]);
        },
        '/booking-pro/ical' => static function (Request $request) use ($icalToken, $readBookings, $buildIcal): Response {
            $token = trim((string) $request->input('token', ''));
            if ($icalToken !== '' && $token !== $icalToken) {
                return Response::text('Forbidden', 403);
            }

            $calendar = $buildIcal($readBookings());
            return Response::text($calendar)
                ->withHeader('Content-Type', 'text/calendar; charset=UTF-8')
                ->withHeader('Content-Disposition', 'inline; filename="booking-pro.ics"');
        },
    ],
    'islands' => [],
    'admin_pages' => [
        'booking-pro' => 'admin/booking-pro.html',
    ],
];
