<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HTTP response value object.
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    private function __construct(
        private int $status,
        private string $body,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        $r = new self($status, $body);
        $r->header('Content-Type', 'text/html; charset=UTF-8');
        return $r;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $r = new self($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $r->header('Content-Type', 'application/json; charset=UTF-8');
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $r = new self($status, '');
        $r->header('Location', $to);
        return $r;
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    public static function text(string $body, int $status = 200): self
    {
        $r = new self($status, $body);
        $r->header('Content-Type', 'text/plain; charset=UTF-8');
        return $r;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
