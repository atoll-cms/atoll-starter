<?php

declare(strict_types=1);

use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Mail\Mailer;
use Atoll\Support\Config;

$root = dirname(__DIR__, 2);
$configPath = $root . '/config.yaml';
$subscribersFile = $root . '/content/data/newsletter-subscribers.json';
$campaignLogFile = $root . '/content/data/newsletter-campaigns.jsonl';

$config = Config::load($configPath);
$sessionName = (string) Config::get($config, 'security.session.name', 'ATOLLSESSID');
$doubleOptIn = (bool) Config::get($config, 'newsletter.double_opt_in', false);
$sendWelcome = (bool) Config::get($config, 'newsletter.send_welcome', false);

$ensureDataDir = static function () use ($subscribersFile, $campaignLogFile): void {
    foreach ([dirname($subscribersFile), dirname($campaignLogFile)] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
};
$ensureDataDir();

$readSubscribers = static function () use ($subscribersFile): array {
    if (!is_file($subscribersFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($subscribersFile), true);
    return is_array($decoded) ? array_values($decoded) : [];
};

$writeSubscribers = static function (array $rows) use ($subscribersFile): void {
    file_put_contents(
        $subscribersFile,
        (string) json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
};

$appendCampaignLog = static function (array $entry) use ($campaignLogFile): void {
    file_put_contents(
        $campaignLogFile,
        (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND
    );
};

$readCampaignLog = static function (int $limit = 100) use ($campaignLogFile): array {
    if (!is_file($campaignLogFile)) {
        return [];
    }

    $rows = file($campaignLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if ($rows === []) {
        return [];
    }

    $rows = array_slice($rows, -$limit);
    $entries = [];
    foreach ($rows as $row) {
        $decoded = json_decode($row, true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }

    return array_reverse($entries);
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

$normalizeEmail = static fn (mixed $email): string => strtolower(trim((string) $email));

$extractInput = static function (Request $request): array {
    if ($request->isJson()) {
        return $request->json();
    }

    return array_merge($request->query, $request->post);
};

$publicSubscriber = static function (array $row): array {
    return [
        'id' => (string) ($row['id'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'status' => (string) ($row['status'] ?? 'subscribed'),
        'tags' => array_values(array_filter(
            is_array($row['tags'] ?? null) ? $row['tags'] : [],
            static fn (mixed $v): bool => is_string($v) && trim($v) !== ''
        )),
        'source' => (string) ($row['source'] ?? 'site'),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'confirmed_at' => (string) ($row['confirmed_at'] ?? ''),
        'unsubscribed_at' => (string) ($row['unsubscribed_at'] ?? ''),
    ];
};

$upsertSubscriber = static function (array $rows, array $input) use ($normalizeEmail, $doubleOptIn): array {
    $email = $normalizeEmail($input['email'] ?? '');
    $name = trim((string) ($input['name'] ?? ''));
    $tags = $input['tags'] ?? [];
    if (!is_array($tags)) {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $tags))));
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'Invalid email'];
    }

    $now = date('c');
    foreach ($rows as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }

        if ($normalizeEmail($row['email'] ?? '') !== $email) {
            continue;
        }

        $row['name'] = $name !== '' ? $name : (string) ($row['name'] ?? '');
        $row['tags'] = array_values(array_unique(array_filter(array_map(static fn ($v) => trim((string) $v), $tags), static fn (string $v): bool => $v !== '')));
        $row['status'] = $doubleOptIn ? 'pending' : 'subscribed';
        if (!$doubleOptIn) {
            $row['confirmed_at'] = $now;
        }
        $row['unsubscribed_at'] = null;
        $rows[$idx] = $row;

        return ['ok' => true, 'rows' => $rows, 'subscriber' => $row, 'created' => false];
    }

    $row = [
        'id' => 'sub_' . date('YmdHis') . '_' . random_int(1000, 9999),
        'email' => $email,
        'name' => $name,
        'status' => $doubleOptIn ? 'pending' : 'subscribed',
        'tags' => array_values(array_unique(array_filter(array_map(static fn ($v) => trim((string) $v), $tags), static fn (string $v): bool => $v !== ''))),
        'source' => trim((string) ($input['source'] ?? 'site')) ?: 'site',
        'created_at' => $now,
        'confirmed_at' => $doubleOptIn ? null : $now,
        'unsubscribed_at' => null,
    ];

    $rows[] = $row;

    return ['ok' => true, 'rows' => $rows, 'subscriber' => $row, 'created' => true];
};

return [
    'name' => 'newsletter',
    'description' => 'Newsletter subscribers, campaign dispatch and DSGVO-ready opt-in flow',
    'version' => '0.1.0',
    'hooks' => [
        'admin:menu' => static fn (): array => [
            'id' => 'newsletter',
            'label' => 'Newsletter',
            'icon' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
            'route' => '/admin#newsletter',
        ],
        'admin:dashboard' => static function () use ($readSubscribers): array {
            $subscribed = 0;
            foreach ($readSubscribers() as $row) {
                if (is_array($row) && (string) ($row['status'] ?? '') === 'subscribed') {
                    $subscribed++;
                }
            }

            return [
                'id' => 'newsletter',
                'title' => 'Newsletter',
                'value' => (string) $subscribed,
                'text' => 'Aktive Newsletter-Abonnenten',
            ];
        },
    ],
    'routes' => [
        '/newsletter/health' => static fn (): array => [
            'ok' => true,
            'plugin' => 'newsletter',
            'features' => ['subscribe', 'unsubscribe', 'campaigns'],
        ],
        '/newsletter/subscribe' => static function (Request $request) use (
            $extractInput,
            $readSubscribers,
            $writeSubscribers,
            $upsertSubscriber,
            $publicSubscriber,
            $sendWelcome,
            $config,
            $doubleOptIn
        ): Response {
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $extractInput($request);
            $rows = $readSubscribers();
            $result = $upsertSubscriber($rows, $input);
            if (($result['ok'] ?? false) !== true) {
                return Response::json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Invalid payload')], 422);
            }

            $nextRows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            $subscriber = is_array($result['subscriber'] ?? null) ? $result['subscriber'] : [];
            $writeSubscribers($nextRows);

            if ($sendWelcome && !$doubleOptIn && is_array($subscriber)) {
                $mailer = new Mailer($config);
                $mailer->send(
                    (string) ($subscriber['email'] ?? ''),
                    'Willkommen zum Newsletter',
                    "Hallo {{name}},\n\nvielen Dank fuer deine Anmeldung zum atoll Newsletter.",
                    ['name' => (string) ($subscriber['name'] ?? 'there')]
                );
            }

            return Response::json([
                'ok' => true,
                'status' => $doubleOptIn ? 'pending' : 'subscribed',
                'subscriber' => $publicSubscriber($subscriber),
            ]);
        },
        '/newsletter/unsubscribe' => static function (Request $request) use ($extractInput, $readSubscribers, $writeSubscribers): Response {
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $extractInput($request);
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            if ($email === '') {
                return Response::json(['ok' => false, 'error' => 'Missing email'], 422);
            }

            $rows = $readSubscribers();
            $changed = false;
            foreach ($rows as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strtolower((string) ($row['email'] ?? '')) !== $email) {
                    continue;
                }

                $rows[$idx]['status'] = 'unsubscribed';
                $rows[$idx]['unsubscribed_at'] = date('c');
                $changed = true;
            }

            if ($changed) {
                $writeSubscribers($rows);
            }

            return Response::json(['ok' => true, 'changed' => $changed]);
        },
        '/newsletter/status' => static function (Request $request) use ($readSubscribers, $publicSubscriber): Response {
            $email = strtolower(trim((string) $request->input('email', '')));
            if ($email === '') {
                return Response::json(['ok' => false, 'error' => 'Missing email'], 422);
            }

            foreach ($readSubscribers() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strtolower((string) ($row['email'] ?? '')) !== $email) {
                    continue;
                }

                return Response::json(['ok' => true, 'subscriber' => $publicSubscriber($row)]);
            }

            return Response::json(['ok' => true, 'subscriber' => null]);
        },
        '/newsletter/admin/subscribers' => static function () use ($isAdminAuthenticated, $readSubscribers, $publicSubscriber): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            $rows = [];
            foreach ($readSubscribers() as $row) {
                if (is_array($row)) {
                    $rows[] = $publicSubscriber($row);
                }
            }

            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['email'] ?? ''), (string) ($b['email'] ?? '')));

            return Response::json([
                'ok' => true,
                'subscribers' => $rows,
            ]);
        },
        '/newsletter/admin/campaign/send' => static function (Request $request) use (
            $isAdminAuthenticated,
            $extractInput,
            $readSubscribers,
            $appendCampaignLog,
            $config
        ): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $extractInput($request);
            $subject = trim((string) ($input['subject'] ?? ''));
            $body = trim((string) ($input['body'] ?? ''));
            $dryRun = (bool) ($input['dry_run'] ?? true);
            $segment = trim((string) ($input['segment'] ?? 'all'));

            if ($subject === '' || $body === '') {
                return Response::json(['ok' => false, 'error' => 'subject and body are required'], 422);
            }

            $tagFilter = null;
            if (str_starts_with($segment, 'tag:')) {
                $tagFilter = trim(substr($segment, 4));
            }

            $targets = [];
            foreach ($readSubscribers() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((string) ($row['status'] ?? '') !== 'subscribed') {
                    continue;
                }
                if (is_string($tagFilter) && $tagFilter !== '') {
                    $tags = is_array($row['tags'] ?? null) ? $row['tags'] : [];
                    if (!in_array($tagFilter, $tags, true)) {
                        continue;
                    }
                }
                $targets[] = $row;
            }

            $sent = 0;
            $failed = 0;
            if (!$dryRun) {
                $mailer = new Mailer($config);
                foreach ($targets as $row) {
                    $ok = $mailer->send((string) ($row['email'] ?? ''), $subject, $body, [
                        'name' => (string) ($row['name'] ?? ''),
                        'email' => (string) ($row['email'] ?? ''),
                    ]);
                    if ($ok) {
                        $sent++;
                    } else {
                        $failed++;
                    }
                }
            }

            $logEntry = [
                'id' => 'cmp_' . date('YmdHis') . '_' . random_int(1000, 9999),
                'created_at' => date('c'),
                'subject' => $subject,
                'segment' => $segment,
                'dry_run' => $dryRun,
                'target_count' => count($targets),
                'sent' => $sent,
                'failed' => $failed,
                'sample_recipients' => array_slice(array_map(static fn (array $row): string => (string) ($row['email'] ?? ''), $targets), 0, 10),
            ];
            $appendCampaignLog($logEntry);

            return Response::json([
                'ok' => true,
                'campaign' => $logEntry,
            ]);
        },
        '/newsletter/admin/campaign/log' => static function () use ($isAdminAuthenticated, $readCampaignLog): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            return Response::json([
                'ok' => true,
                'entries' => $readCampaignLog(120),
            ]);
        },
    ],
    'islands' => [],
    'admin_pages' => [
        'newsletter' => 'admin/newsletter.html',
    ],
];
