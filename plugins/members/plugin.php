<?php

declare(strict_types=1);

use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Support\Config;

$root = dirname(__DIR__, 2);
$membersFile = $root . '/content/data/members.json';
$sessionsFile = $root . '/content/data/members-sessions.json';
$configPath = $root . '/config.yaml';
$cookieName = 'ATOLL_MEMBER_SESS';

$ensureDataDir = static function () use ($membersFile, $sessionsFile): void {
    foreach ([dirname($membersFile), dirname($sessionsFile)] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
};
$ensureDataDir();

$readJsonArray = static function (string $file): array {
    if (!is_file($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
};

$writeJsonArray = static function (string $file, array $payload): void {
    file_put_contents(
        $file,
        (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
};

$config = Config::load($configPath);

$sessionName = (string) Config::get($config, 'security.session.name', 'ATOLLSESSID');
$sessionSecure = (bool) Config::get($config, 'security.session.secure_cookie', false);
$sessionSameSite = (string) Config::get($config, 'security.session.same_site', 'Lax');
$registrationEnabled = (bool) Config::get($config, 'members.registration.enabled', true);
$passwordMinLength = max(8, (int) Config::get($config, 'members.password_min_length', 10));
$ttlSeconds = max(900, (int) Config::get($config, 'members.session_ttl_seconds', 60 * 60 * 24 * 14));

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

$normalizeEmail = static fn (mixed $value): string => strtolower(trim((string) $value));

$publicMember = static function (array $member): array {
    return [
        'id' => (string) ($member['id'] ?? ''),
        'email' => (string) ($member['email'] ?? ''),
        'name' => (string) ($member['name'] ?? ''),
        'role' => (string) ($member['role'] ?? 'member'),
        'status' => (string) ($member['status'] ?? 'active'),
        'created_at' => (string) ($member['created_at'] ?? ''),
        'last_login_at' => (string) ($member['last_login_at'] ?? ''),
    ];
};

$extractInput = static function (Request $request): array {
    if ($request->isJson()) {
        return $request->json();
    }

    return array_merge($request->query, $request->post);
};

$readMembers = static fn () => $readJsonArray($membersFile);
$writeMembers = static fn (array $members) => $writeJsonArray($membersFile, array_values($members));
$readSessions = static fn () => $readJsonArray($sessionsFile);
$writeSessions = static fn (array $sessions) => $writeJsonArray($sessionsFile, array_values($sessions));

$findMemberByEmail = static function (array $members, string $email) use ($normalizeEmail): ?array {
    $needle = $normalizeEmail($email);
    foreach ($members as $member) {
        if (!is_array($member)) {
            continue;
        }
        if ($normalizeEmail($member['email'] ?? '') === $needle) {
            return $member;
        }
    }

    return null;
};

$findMemberById = static function (array $members, string $id): ?array {
    $needle = trim($id);
    if ($needle === '') {
        return null;
    }

    foreach ($members as $member) {
        if (!is_array($member)) {
            continue;
        }
        if ((string) ($member['id'] ?? '') === $needle) {
            return $member;
        }
    }

    return null;
};

$nextMemberId = static fn (): string => 'mem_' . date('YmdHis') . '_' . random_int(1000, 9999);

$buildCookie = static function (string $token) use ($cookieName, $ttlSeconds, $sessionSecure, $sessionSameSite): string {
    $parts = [
        $cookieName . '=' . rawurlencode($token),
        'Path=/',
        'Max-Age=' . $ttlSeconds,
        'HttpOnly',
        'SameSite=' . $sessionSameSite,
    ];

    if ($sessionSecure) {
        $parts[] = 'Secure';
    }

    return implode('; ', $parts);
};

$clearCookie = static function () use ($cookieName, $sessionSecure, $sessionSameSite): string {
    $parts = [
        $cookieName . '=',
        'Path=/',
        'Max-Age=0',
        'HttpOnly',
        'SameSite=' . $sessionSameSite,
    ];

    if ($sessionSecure) {
        $parts[] = 'Secure';
    }

    return implode('; ', $parts);
};

$memberFromRequest = static function (Request $request) use (
    $cookieName,
    $readSessions,
    $writeSessions,
    $readMembers,
    $findMemberById
): ?array {
    $token = trim(rawurldecode((string) ($request->cookies[$cookieName] ?? '')));
    if ($token === '') {
        return null;
    }

    $sessions = $readSessions();
    if (!is_array($sessions) || $sessions === []) {
        return null;
    }

    $now = time();
    $changed = false;
    $activeSession = null;
    $activeIdx = null;

    foreach ($sessions as $idx => $session) {
        if (!is_array($session)) {
            $changed = true;
            continue;
        }

        $expiresAt = strtotime((string) ($session['expires_at'] ?? ''));
        if ($expiresAt <= 0 || $expiresAt < $now) {
            unset($sessions[$idx]);
            $changed = true;
            continue;
        }

        if ((string) ($session['token'] ?? '') === $token) {
            $activeSession = $session;
            $activeIdx = $idx;
        }
    }

    if ($activeSession === null || $activeIdx === null) {
        if ($changed) {
            $writeSessions(array_values($sessions));
        }

        return null;
    }

    $sessions[$activeIdx]['last_seen_at'] = date('c');
    $writeSessions(array_values($sessions));

    $members = $readMembers();
    $member = $findMemberById($members, (string) ($activeSession['member_id'] ?? ''));
    if (!is_array($member)) {
        return null;
    }

    if ((string) ($member['status'] ?? 'active') !== 'active') {
        return null;
    }

    return $member;
};

$renderAuthPage = static function (string $mode, string $redirect): string {
    $title = $mode === 'register' ? 'Mitgliedskonto erstellen' : 'Mitglied anmelden';
    $button = $mode === 'register' ? 'Registrieren' : 'Anmelden';

    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . htmlspecialchars($title, ENT_QUOTES)
        . '</title><style>body{margin:0;font:16px/1.45 system-ui;background:#07131a;color:#e2ecf2;display:grid;place-items:center;min-height:100vh}form{width:min(460px,92vw);background:#0e1f2a;border:1px solid #1d3442;border-radius:14px;padding:1.2rem}h1{margin-top:0;font-size:1.25rem}label{display:grid;gap:.35rem;margin-bottom:.7rem}input{border-radius:8px;border:1px solid #284756;background:#0b1822;color:#e2ecf2;padding:.58rem .62rem}button{border:0;border-radius:8px;background:#f59e0b;color:#111;padding:.62rem .8rem;font-weight:700;cursor:pointer}small,a{color:#8eb2c4}#msg{min-height:1.2rem;margin:.45rem 0 .7rem}</style></head><body><form id="auth-form"><h1>'
        . htmlspecialchars($title, ENT_QUOTES)
        . '</h1>'
        . ($mode === 'register'
            ? '<label>Name<input name="name" autocomplete="name"></label>'
            : '')
        . '<label>E-Mail<input name="email" type="email" required autocomplete="email"></label><label>Passwort<input name="password" type="password" required autocomplete="current-password"></label><div id="msg"></div><button type="submit">'
        . htmlspecialchars($button, ENT_QUOTES)
        . '</button><p><small>'
        . ($mode === 'register'
            ? 'Schon registriert? <a href="/members/login?redirect=' . rawurlencode($redirect) . '">Jetzt anmelden</a>'
            : 'Noch kein Konto? <a href="/members/register?redirect=' . rawurlencode($redirect) . '">Jetzt registrieren</a>')
        . '</small></p></form><script>const form=document.getElementById("auth-form");const msg=document.getElementById("msg");form.addEventListener("submit",async(e)=>{e.preventDefault();msg.textContent="";const fd=new FormData(form);const body=Object.fromEntries(fd.entries());try{const r=await fetch("/members/' . $mode . '",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)});const j=await r.json();if(!r.ok){throw new Error(j.error||"Fehler")}window.location.href=' . json_encode($redirect) . ';}catch(err){msg.textContent=err.message||"Fehler";msg.style.color="#fca5a5";}});</script></body></html>';
};

return [
    'name' => 'members',
    'description' => 'Mitgliederbereiche mit Login/Registrierung und Rollen',
    'version' => '0.1.0',
    'hooks' => [
        'admin:menu' => static fn (): array => [
            'id' => 'members',
            'label' => 'Members',
            'icon' => 'M5.121 17.804A9.969 9.969 0 0112 15c2.386 0 4.578.835 6.294 2.23M15 11a3 3 0 11-6 0 3 3 0 016 0zm6 1a9 9 0 11-18 0 9 9 0 0118 0z',
            'route' => '/admin#members',
        ],
        'admin:dashboard' => static function () use ($readMembers): array {
            $members = $readMembers();
            $active = 0;
            foreach ($members as $member) {
                if (is_array($member) && (string) ($member['status'] ?? 'active') === 'active') {
                    $active++;
                }
            }

            return [
                'id' => 'members',
                'title' => 'Members',
                'value' => (string) $active,
                'text' => 'Aktive Mitgliederkonten',
            ];
        },
        'page:before_render' => static function (array $payload, Request $request) use ($memberFromRequest, $publicMember): array {
            $page = $payload['page'] ?? null;
            if (!is_array($page)) {
                return [];
            }

            $membersOnly = (bool) ($page['members_only'] ?? false);
            $access = strtolower(trim((string) ($page['access'] ?? '')));
            if (!$membersOnly && !in_array($access, ['member', 'members'], true)) {
                return [];
            }

            $member = $memberFromRequest($request);
            if (!is_array($member)) {
                return ['response' => Response::redirect('/members/login?redirect=' . rawurlencode($request->path), 302)];
            }

            return ['payload' => ['member' => $publicMember($member)]];
        },
    ],
    'routes' => [
        '/members/health' => static fn (): array => [
            'ok' => true,
            'plugin' => 'members',
            'features' => ['register', 'login', 'roles', 'gated_pages'],
        ],
        '/members/login' => static function (Request $request) use (
            $extractInput,
            $readMembers,
            $writeMembers,
            $readSessions,
            $writeSessions,
            $normalizeEmail,
            $publicMember,
            $buildCookie,
            $renderAuthPage,
            $nextMemberId,
            $findMemberByEmail,
            $ttlSeconds
        ): Response {
            if ($request->method === 'GET') {
                $redirect = trim((string) $request->input('redirect', '/'));
                if ($redirect === '' || !str_starts_with($redirect, '/')) {
                    $redirect = '/';
                }

                return Response::html($renderAuthPage('login', $redirect));
            }

            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET, POST');
            }

            $input = $extractInput($request);
            $email = $normalizeEmail($input['email'] ?? '');
            $password = (string) ($input['password'] ?? '');

            if ($email === '' || $password === '') {
                return Response::json(['ok' => false, 'error' => 'email and password are required'], 422);
            }

            $members = $readMembers();
            $member = $findMemberByEmail($members, $email);
            if (!is_array($member) || !password_verify($password, (string) ($member['password_hash'] ?? ''))) {
                return Response::json(['ok' => false, 'error' => 'Invalid credentials'], 401);
            }

            if ((string) ($member['status'] ?? 'active') !== 'active') {
                return Response::json(['ok' => false, 'error' => 'Account inactive'], 403);
            }

            foreach ($members as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((string) ($row['id'] ?? '') === (string) ($member['id'] ?? '')) {
                    $members[$idx]['last_login_at'] = date('c');
                }
            }
            $writeMembers($members);

            $sessions = $readSessions();
            $now = time();
            $sessions = array_values(array_filter($sessions, static function (mixed $session) use ($now): bool {
                if (!is_array($session)) {
                    return false;
                }
                $expiresAt = strtotime((string) ($session['expires_at'] ?? ''));
                return $expiresAt > $now;
            }));

            $token = bin2hex(random_bytes(24));
            $sessions[] = [
                'id' => $nextMemberId(),
                'token' => $token,
                'member_id' => (string) ($member['id'] ?? ''),
                'created_at' => date('c'),
                'last_seen_at' => date('c'),
                'expires_at' => date('c', $now + $ttlSeconds),
            ];
            $writeSessions($sessions);

            return Response::json([
                'ok' => true,
                'member' => $publicMember($member),
            ])->withHeader('Set-Cookie', $buildCookie($token));
        },
        '/members/register' => static function (Request $request) use (
            $extractInput,
            $registrationEnabled,
            $normalizeEmail,
            $passwordMinLength,
            $readMembers,
            $writeMembers,
            $findMemberByEmail,
            $nextMemberId,
            $renderAuthPage
        ): Response {
            if ($request->method === 'GET') {
                $redirect = trim((string) $request->input('redirect', '/'));
                if ($redirect === '' || !str_starts_with($redirect, '/')) {
                    $redirect = '/';
                }

                return Response::html($renderAuthPage('register', $redirect));
            }

            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET, POST');
            }

            if (!$registrationEnabled) {
                return Response::json(['ok' => false, 'error' => 'Public registration is disabled'], 403);
            }

            $input = $extractInput($request);
            $email = $normalizeEmail($input['email'] ?? '');
            $name = trim((string) ($input['name'] ?? ''));
            $password = (string) ($input['password'] ?? '');

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return Response::json(['ok' => false, 'error' => 'Invalid email'], 422);
            }

            if (mb_strlen($password) < $passwordMinLength) {
                return Response::json(['ok' => false, 'error' => 'Password too short (min ' . $passwordMinLength . ' chars)'], 422);
            }

            $members = $readMembers();
            if ($findMemberByEmail($members, $email) !== null) {
                return Response::json(['ok' => false, 'error' => 'Email already exists'], 409);
            }

            $members[] = [
                'id' => $nextMemberId(),
                'email' => $email,
                'name' => $name,
                'role' => 'member',
                'status' => 'active',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => date('c'),
                'last_login_at' => null,
            ];
            $writeMembers($members);

            return Response::json(['ok' => true]);
        },
        '/members/logout' => static function (Request $request) use ($cookieName, $readSessions, $writeSessions, $clearCookie): Response {
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $token = trim(rawurldecode((string) ($request->cookies[$cookieName] ?? '')));
            $sessions = $readSessions();
            if ($token !== '') {
                $sessions = array_values(array_filter(
                    $sessions,
                    static fn (mixed $row): bool => is_array($row) && (string) ($row['token'] ?? '') !== $token
                ));
                $writeSessions($sessions);
            }

            return Response::json(['ok' => true])->withHeader('Set-Cookie', $clearCookie());
        },
        '/members/me' => static function (Request $request) use ($memberFromRequest, $publicMember): Response {
            $member = $memberFromRequest($request);
            return Response::json([
                'ok' => true,
                'member' => is_array($member) ? $publicMember($member) : null,
            ]);
        },
        '/members/admin/list' => static function () use ($isAdminAuthenticated, $readMembers, $publicMember): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }

            $rows = [];
            foreach ($readMembers() as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $rows[] = $publicMember($member);
            }

            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['email'] ?? ''), (string) ($b['email'] ?? '')));

            return Response::json([
                'ok' => true,
                'members' => $rows,
            ]);
        },
        '/members/admin/create' => static function (Request $request) use (
            $isAdminAuthenticated,
            $extractInput,
            $normalizeEmail,
            $readMembers,
            $writeMembers,
            $findMemberByEmail,
            $nextMemberId,
            $passwordMinLength,
            $publicMember
        ): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $extractInput($request);
            $email = $normalizeEmail($input['email'] ?? '');
            $name = trim((string) ($input['name'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            $role = trim((string) ($input['role'] ?? 'member'));
            if (!in_array($role, ['member', 'editor', 'admin'], true)) {
                $role = 'member';
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return Response::json(['ok' => false, 'error' => 'Invalid email'], 422);
            }
            if (mb_strlen($password) < $passwordMinLength) {
                return Response::json(['ok' => false, 'error' => 'Password too short (min ' . $passwordMinLength . ' chars)'], 422);
            }

            $members = $readMembers();
            if ($findMemberByEmail($members, $email) !== null) {
                return Response::json(['ok' => false, 'error' => 'Email already exists'], 409);
            }

            $member = [
                'id' => $nextMemberId(),
                'email' => $email,
                'name' => $name,
                'role' => $role,
                'status' => 'active',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => date('c'),
                'last_login_at' => null,
            ];

            $members[] = $member;
            $writeMembers($members);

            return Response::json([
                'ok' => true,
                'member' => $publicMember($member),
            ]);
        },
        '/members/admin/update' => static function (Request $request) use (
            $isAdminAuthenticated,
            $extractInput,
            $readMembers,
            $writeMembers,
            $passwordMinLength,
            $publicMember
        ): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $extractInput($request);
            $id = trim((string) ($input['id'] ?? ''));
            if ($id === '') {
                return Response::json(['ok' => false, 'error' => 'Missing id'], 422);
            }

            $members = $readMembers();
            $updated = null;
            foreach ($members as $idx => $member) {
                if (!is_array($member) || (string) ($member['id'] ?? '') !== $id) {
                    continue;
                }

                $name = trim((string) ($input['name'] ?? $member['name'] ?? ''));
                $role = trim((string) ($input['role'] ?? $member['role'] ?? 'member'));
                $status = trim((string) ($input['status'] ?? $member['status'] ?? 'active'));
                if (!in_array($role, ['member', 'editor', 'admin'], true)) {
                    $role = 'member';
                }
                if (!in_array($status, ['active', 'disabled'], true)) {
                    $status = 'active';
                }

                $member['name'] = $name;
                $member['role'] = $role;
                $member['status'] = $status;

                $password = (string) ($input['password'] ?? '');
                if ($password !== '') {
                    if (mb_strlen($password) < $passwordMinLength) {
                        return Response::json(['ok' => false, 'error' => 'Password too short (min ' . $passwordMinLength . ' chars)'], 422);
                    }
                    $member['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $members[$idx] = $member;
                $updated = $member;
                break;
            }

            if (!is_array($updated)) {
                return Response::json(['ok' => false, 'error' => 'Member not found'], 404);
            }

            $writeMembers($members);

            return Response::json([
                'ok' => true,
                'member' => $publicMember($updated),
            ]);
        },
        '/members/admin/delete' => static function (Request $request) use ($isAdminAuthenticated, $extractInput, $readMembers, $writeMembers): Response {
            if (!$isAdminAuthenticated()) {
                return Response::json(['ok' => false, 'error' => 'Forbidden'], 403);
            }
            if ($request->method !== 'POST') {
                return Response::json(['ok' => false, 'error' => 'Method not allowed'], 405)->withHeader('Allow', 'POST');
            }

            $input = $extractInput($request);
            $id = trim((string) ($input['id'] ?? ''));
            if ($id === '') {
                return Response::json(['ok' => false, 'error' => 'Missing id'], 422);
            }

            $members = $readMembers();
            $before = count($members);
            $members = array_values(array_filter(
                $members,
                static fn (mixed $member): bool => is_array($member) && (string) ($member['id'] ?? '') !== $id
            ));
            if (count($members) === $before) {
                return Response::json(['ok' => false, 'error' => 'Member not found'], 404);
            }

            $writeMembers($members);
            return Response::json(['ok' => true]);
        },
    ],
    'islands' => [],
    'admin_pages' => [
        'members' => 'admin/members.html',
    ],
];
