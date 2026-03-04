<?php

declare(strict_types=1);

namespace Atoll\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server,
        public readonly array $cookies,
        public readonly string $rawBody
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . ltrim($path, '/'),
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            $_COOKIE,
            (string) file_get_contents('php://input')
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $default;
    }

    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');
        return str_contains(strtolower((string) $contentType), 'application/json');
    }

    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if ($this->isJson()) {
            $payload = $this->json();
            return $payload[$key] ?? $default;
        }

        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }
}
