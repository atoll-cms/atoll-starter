<?php

declare(strict_types=1);

namespace Atoll\Security;

use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Support\Config;
use RuntimeException;

final class SecurityManager
{
    private string $auditFile;
    private bool $sessionEnabled;
    private bool $sessionSecure;
    private string $sessionSameSite;
    private string $sessionName;

    public function __construct(
        private readonly string $rateLimitDir,
        /** @var array<string, mixed> */
        private readonly array $config,
        ?string $auditFile = null
    ) {
        $this->auditFile = $auditFile ?? dirname($this->rateLimitDir, 2) . '/content/data/security-audit.jsonl';
        $this->sessionEnabled = (bool) Config::get($this->config, 'security.session.enabled', true);
        $this->sessionSecure = (bool) Config::get($this->config, 'security.session.secure_cookie', false);
        $this->sessionSameSite = (string) Config::get($this->config, 'security.session.same_site', 'Lax');
        $this->sessionName = (string) Config::get($this->config, 'security.session.name', 'ATOLLSESSID');

        $rateLimitEnabled = (bool) Config::get($this->config, 'security.rate_limit.enabled', true);
        if ($rateLimitEnabled && !is_dir($this->rateLimitDir)) {
            mkdir($this->rateLimitDir, 0775, true);
        }

        $auditDir = dirname($this->auditFile);
        if (!is_dir($auditDir)) {
            mkdir($auditDir, 0775, true);
        }
    }

    public function applyHeaders(Response $response): Response
    {
        $csp = (string) Config::get($this->config, 'security.content_security_policy', "default-src 'self'");
        $hstsEnabled = (bool) Config::get($this->config, 'security.hsts', false);

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Content-Security-Policy', $csp);

        if ($hstsEnabled) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    public function forceHttps(Request $request): ?Response
    {
        $enabled = (bool) Config::get($this->config, 'security.force_https', false);
        if (!$enabled) {
            return null;
        }

        $https = $request->server['HTTPS'] ?? '';
        $forwardedProto = strtolower((string) ($request->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($https === 'on' || $https === '1' || $forwardedProto === 'https') {
            return null;
        }

        $host = $request->server['HTTP_HOST'] ?? 'localhost';
        return Response::redirect('https://' . $host . $request->path, 301);
    }

    public function enforceRateLimit(Request $request, string $bucket = 'global', ?int $max = null, ?int $windowSeconds = null): ?Response
    {
        if (!(bool) Config::get($this->config, 'security.rate_limit.enabled', true)) {
            return null;
        }

        $max ??= (int) Config::get($this->config, 'security.rate_limit.requests', 120);
        $windowSeconds ??= (int) Config::get($this->config, 'security.rate_limit.window_seconds', 60);

        $ip = $request->server['REMOTE_ADDR'] ?? 'unknown';
        $key = sha1($bucket . '|' . $ip);
        $file = rtrim($this->rateLimitDir, '/') . '/' . $key . '.json';

        $now = time();
        $state = [
            'count' => 0,
            'window_start' => $now,
        ];

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        if (($now - (int) $state['window_start']) >= $windowSeconds) {
            $state = ['count' => 0, 'window_start' => $now];
        }

        $state['count'] = (int) $state['count'] + 1;
        file_put_contents($file, json_encode($state));

        if ((int) $state['count'] > $max) {
            return Response::json([
                'error' => 'Too many requests',
                'retry_after' => max(1, $windowSeconds - ($now - (int) $state['window_start'])),
            ], 429);
        }

        return null;
    }

    public function csrfToken(): string
    {
        if (!$this->ensureSessionStarted()) {
            return '';
        }

        if (!isset($_SESSION['_atoll_csrf'])) {
            $_SESSION['_atoll_csrf'] = bin2hex(random_bytes(24));
        }

        return (string) $_SESSION['_atoll_csrf'];
    }

    public function validateCsrf(?string $token): bool
    {
        if (!$this->ensureSessionStarted()) {
            return false;
        }

        $current = $_SESSION['_atoll_csrf'] ?? '';
        return is_string($token) && $current !== '' && hash_equals($current, $token);
    }

    public function login(string $username, ?Request $request = null): void
    {
        if (!$this->ensureSessionStarted()) {
            throw new RuntimeException('Sessions are disabled.');
        }

        session_regenerate_id(true);
        $_SESSION['_atoll_user'] = $username;
        $_SESSION['_atoll_login_at'] = time();
        $_SESSION['_atoll_last_activity'] = time();

        $this->recordAudit('auth.login_success', [
            'user' => $username,
            'ip' => $request?->server['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }

    public function logout(?Request $request = null): void
    {
        $user = $this->currentUser();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        unset($_SESSION['_atoll_user']);
        unset($_SESSION['_atoll_login_at'], $_SESSION['_atoll_last_activity']);

        if ($user !== null) {
            $this->recordAudit('auth.logout', [
                'user' => $user,
                'ip' => $request?->server['REMOTE_ADDR'] ?? 'unknown',
            ]);
        }
    }

    public function currentUser(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $value = $_SESSION['_atoll_user'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function isAuthenticated(): bool
    {
        if (!$this->ensureSessionStarted()) {
            return false;
        }

        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }

        $ttlMinutes = (int) Config::get($this->config, 'security.session.ttl_minutes', 480);
        $lastActivity = (int) ($_SESSION['_atoll_last_activity'] ?? 0);
        $now = time();
        if ($lastActivity > 0 && $ttlMinutes > 0 && ($now - $lastActivity) > ($ttlMinutes * 60)) {
            $this->recordAudit('auth.session_expired', ['user' => $user]);
            $this->logout();
            return false;
        }

        $_SESSION['_atoll_last_activity'] = $now;
        return true;
    }

    public function isAdminIpAllowed(Request $request): bool
    {
        $allowlist = Config::get($this->config, 'security.admin_ip_allowlist', []);
        if (!is_array($allowlist) || $allowlist === []) {
            return true;
        }

        $ip = (string) ($request->server['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return false;
        }

        foreach ($allowlist as $allowed) {
            if (!is_string($allowed) || trim($allowed) === '') {
                continue;
            }
            if ($this->ipMatches($ip, trim($allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function passwordPolicyErrors(string $password): array
    {
        return self::passwordPolicyErrorsForConfig($password, $this->config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public static function passwordPolicyErrorsForConfig(string $password, array $config): array
    {
        $errors = [];
        $minLength = (int) Config::get($config, 'security.password.min_length', 12);
        $requireUpper = (bool) Config::get($config, 'security.password.require_uppercase', true);
        $requireLower = (bool) Config::get($config, 'security.password.require_lowercase', true);
        $requireDigit = (bool) Config::get($config, 'security.password.require_digit', true);
        $requireSpecial = (bool) Config::get($config, 'security.password.require_special', true);

        if (mb_strlen($password) < $minLength) {
            $errors[] = 'Password must be at least ' . $minLength . ' characters long.';
        }
        if ($requireUpper && preg_match('/[A-Z]/', $password) !== 1) {
            $errors[] = 'Password must include an uppercase letter.';
        }
        if ($requireLower && preg_match('/[a-z]/', $password) !== 1) {
            $errors[] = 'Password must include a lowercase letter.';
        }
        if ($requireDigit && preg_match('/\d/', $password) !== 1) {
            $errors[] = 'Password must include a number.';
        }
        if ($requireSpecial && preg_match('/[^a-zA-Z0-9]/', $password) !== 1) {
            $errors[] = 'Password must include a special character.';
        }

        return $errors;
    }

    public function generateTotpSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            throw new RuntimeException('TOTP secret length too small.');
        }

        return $this->base32Encode(random_bytes($bytes));
    }

    public function totpProvisioningUri(string $issuer, string $accountLabel, string $secret): string
    {
        $label = rawurlencode($issuer . ':' . $accountLabel);
        $issuerParam = rawurlencode($issuer);
        $secretParam = rawurlencode($secret);
        return "otpauth://totp/{$label}?secret={$secretParam}&issuer={$issuerParam}&algorithm=SHA1&digits=6&period=30";
    }

    public function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $currentCounter = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $counter = $currentCounter + $i;
            if ($counter < 0) {
                continue;
            }
            if (hash_equals($this->totpAtCounter($secret, $counter), $code)) {
                return true;
            }
        }

        return false;
    }

    public function recordAudit(string $event, array $context = []): void
    {
        $entry = [
            'timestamp' => date('c'),
            'event' => $event,
            'user' => $context['user'] ?? $this->currentUser(),
            'ip' => $context['ip'] ?? null,
            'context' => $context,
        ];

        file_put_contents(
            $this->auditFile,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function auditEntries(int $limit = 200): array
    {
        if (!is_file($this->auditFile)) {
            return [];
        }

        $rows = file($this->auditFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
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
    }

    private function ensureSessionStarted(): bool
    {
        if (!$this->sessionEnabled) {
            return false;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->sessionName);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $this->sessionSecure,
                'httponly' => true,
                'samesite' => $this->sessionSameSite,
            ]);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return session_status() === PHP_SESSION_ACTIVE;
    }

    private function ipMatches(string $ip, string $allowed): bool
    {
        if (!str_contains($allowed, '/')) {
            return $ip === $allowed;
        }

        [$subnet, $bitsRaw] = explode('/', $allowed, 2);
        $bits = (int) $bitsRaw;
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return (($ipLong & $mask) === ($subnetLong & $mask));
    }

    private function totpAtCounter(string $secret, int $counter): string
    {
        $binarySecret = $this->base32Decode($secret);
        $counterBin = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $counterBin, $binarySecret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $segment = substr($hash, $offset, 4);
        $value = unpack('N', $segment)[1] & 0x7FFFFFFF;
        $otp = (string) ($value % 1000000);
        return str_pad($otp, 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $len = strlen($binary); $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        for ($i = 0, $len = strlen($bits); $i < $len; $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $input): string
    {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $input = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input) ?? '');

        $bits = '';
        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $char = $input[$i];
            if (!array_key_exists($char, $alphabet)) {
                continue;
            }
            $bits .= str_pad(decbin((int) $alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        for ($i = 0, $len = strlen($bits); $i + 8 <= $len; $i += 8) {
            $binary .= chr(bindec(substr($bits, $i, 8)));
        }

        return $binary;
    }
}
