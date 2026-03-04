<?php

declare(strict_types=1);

namespace Atoll\Security;

use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Support\Config;

final class SecurityManager
{
    public function __construct(
        private readonly string $rateLimitDir,
        /** @var array<string, mixed> */
        private readonly array $config
    ) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!is_dir($this->rateLimitDir)) {
            mkdir($this->rateLimitDir, 0775, true);
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
        if ($https === 'on' || $https === '1') {
            return null;
        }

        $host = $request->server['HTTP_HOST'] ?? 'localhost';
        return Response::redirect('https://' . $host . $request->path, 301);
    }

    public function enforceRateLimit(Request $request, string $bucket = 'global', ?int $max = null, ?int $windowSeconds = null): ?Response
    {
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
        if (!isset($_SESSION['_atoll_csrf'])) {
            $_SESSION['_atoll_csrf'] = bin2hex(random_bytes(24));
        }

        return (string) $_SESSION['_atoll_csrf'];
    }

    public function validateCsrf(?string $token): bool
    {
        $current = $_SESSION['_atoll_csrf'] ?? '';
        return is_string($token) && $current !== '' && hash_equals($current, $token);
    }

    public function login(string $username): void
    {
        $_SESSION['_atoll_user'] = $username;
    }

    public function logout(): void
    {
        unset($_SESSION['_atoll_user']);
    }

    public function currentUser(): ?string
    {
        $value = $_SESSION['_atoll_user'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function isAuthenticated(): bool
    {
        return $this->currentUser() !== null;
    }
}
