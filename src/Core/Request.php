<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Incapsula la richiesta HTTP corrente e calcola il "route path"
 * indipendentemente dal fatto che si usino URL puliti o /index.php/....
 */
final class Request
{
    private string $method;
    private string $path;
    private string $basePath;
    private bool $prettyUrls;

    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $post;
    /** @var array<string,mixed> */
    private array $json;
    /** @var array<string,mixed> */
    private array $server;

    public function __construct(bool $prettyUrls, ?string $forcedBasePath = null)
    {
        $this->prettyUrls = $prettyUrls;
        $this->server = $_SERVER;
        $this->query  = $_GET;
        $this->post   = $_POST;
        $this->json   = [];

        $ctype = strtolower((string) ($this->server['CONTENT_TYPE'] ?? ''));
        if (str_contains($ctype, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->json = $decoded;
                }
            }
        }

        $this->method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        if ($this->method === 'POST' && isset($this->post['_method'])) {
            $override = strtoupper((string) $this->post['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $this->method = $override;
            }
        }

        $scriptName = str_replace('\\', '/', (string) ($this->server['SCRIPT_NAME'] ?? ''));
        $base = $forcedBasePath ?? rtrim(dirname($scriptName), '/');
        $this->basePath = ($base === '/' || $base === '.') ? '' : $base;

        $this->path = $this->resolvePath();
    }

    private function resolvePath(): string
    {
        $pathInfo = (string) ($this->server['PATH_INFO'] ?? '');
        if ($pathInfo !== '') {
            return $this->normalize($pathInfo);
        }

        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $uri = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $uri = rawurldecode($uri);

        if ($this->basePath !== '' && str_starts_with($uri, $this->basePath)) {
            $uri = substr($uri, strlen($this->basePath));
        }
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, strlen('/index.php'));
        }

        return $this->normalize($uri);
    }

    private function normalize(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /** Prefisso per generare URL applicativi. */
    public function urlPrefix(): string
    {
        return $this->basePath . ($this->prettyUrls ? '' : '/index.php');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function wantsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->json[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->post + $this->json + $this->query;
    }

    /**
     * Voce di $_FILES per un upload singolo, o null se assente/malformata.
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        if (!is_array($f) || !isset($f['tmp_name']) || is_array($f['tmp_name'])) {
            return null;
        }
        return [
            'name'     => (string) ($f['name'] ?? ''),
            'type'     => (string) ($f['type'] ?? ''),
            'tmp_name' => (string) $f['tmp_name'],
            'error'    => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($f['size'] ?? 0),
        ];
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? '');
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
