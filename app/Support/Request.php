<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Immutable-ish HTTP request abstraction over PHP superglobals.
 */
final class Request
{
    private string $correlationId;

    public function __construct(
        private readonly array $server,
        private readonly array $get,
        private readonly array $post,
        private readonly array $files,
        private readonly array $cookies,
        private readonly string $rawBody = '',
    ) {
        $this->correlationId = bin2hex(random_bytes(8));
    }

    /** Capture the current PHP request globals. */
    public static function capture(): self
    {
        return new self(
            $_SERVER,
            $_GET,
            $_POST,
            $_FILES,
            $_COOKIE,
            file_get_contents('php://input') ?: ''
        );
    }

    /** Build a synthetic request (used by the test runner). */
    public static function synthetic(
        string $method,
        string $uri,
        array $get = [],
        array $post = [],
        array $server = [],
        array $cookies = [],
        array $files = [],
    ): self {
        $server = array_merge([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'SERVER_NAME' => 'localhost',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'synthetic-test-client',
            'HTTPS' => '',
            'REQUEST_TIME' => time(),
        ], $server);

        return new self($server, $get, $post, $files, $cookies);
    }

    /**
     * Access a single uploaded file by its field name.
     * Returns the standard $_FILES structure or null if not present.
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'])) {
            return null;
        }
        return $file;
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        // HTML form method spoofing: _method=PUT|PATCH|DELETE on POST bodies.
        if ($method === 'POST') {
            $spoofed = strtoupper((string) ($this->post['_method'] ?? ''));
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }

        return $method;
    }

    public function realMethod(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    /**
     * Normalized request path: leading slash, no trailing slash (except root),
     * URL-decoded, and never contains "..".
     */
    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            return '/';
        }
        $path = rawurldecode($path);
        if (str_contains($path, "\0") || str_contains($path, '..')) {
            return '/';
        }
        $path = '/' . trim($path, '/');
        return $path === '//' ? '/' : $path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function queryAll(): array
    {
        return $this->get;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /** True when the key was present in the POST body (even with an empty value). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post);
    }

    public function post(): array
    {
        return $this->post;
    }

    public function files(): array
    {
        return $this->files;
    }

    public function cookie(string $key): ?string
    {
        $value = $this->cookies[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function ip(): string
    {
        // Only trust direct REMOTE_ADDR; no spoofable X-Forwarded-For by default.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        return isset($this->server[$name]) ? (string) $this->server[$name] : null;
    }

    public function isSecure(): bool
    {
        return !empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off';
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept') ?? '';
        return str_contains($accept, 'application/json')
            || str_starts_with($this->path(), '/api/');
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function fullUrl(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = (string) ($this->server['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . ($this->server['REQUEST_URI'] ?? '/');
    }
}
