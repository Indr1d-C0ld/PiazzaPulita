<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            // Header di sicurezza di base.
            $defaults = [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options'        => 'SAMEORIGIN',
                'Referrer-Policy'        => 'same-origin',
                'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; "
                    . "style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; "
                    . "form-action 'self'; frame-ancestors 'self'",
            ];
            foreach ($defaults as $name => $value) {
                if (!isset($this->headers[$name])) {
                    header("{$name}: {$value}");
                }
            }
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->body;
    }
}
