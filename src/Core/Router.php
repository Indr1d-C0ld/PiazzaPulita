<?php

declare(strict_types=1);

namespace App\Core;

use App\Auth\Auth;

final class Router
{
    /** @var list<array{method:string,regex:string,params:list<string>,handler:callable|array,middleware:list<string>}> */
    private array $routes = [];

    /** @param callable|array{0:class-string,1:string} $handler */
    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function add(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $params = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', static function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method'     => $method,
            'regex'      => '#^' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path   = $request->path();

        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($m);
            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = $m[$i] ?? null;
            }

            // CSRF su tutte le richieste non-GET.
            if ($method !== 'GET') {
                $token = (string) $request->input('_token', '');
                if ($token === '') {
                    $token = $request->header('X-CSRF-Token');
                }
                if (!Csrf::check($token)) {
                    return $this->fail(400, 'Sessione scaduta o token non valido. Ricarica la pagina e riprova.');
                }
            }

            foreach ($route['middleware'] as $mw) {
                $result = $this->runMiddleware($mw, $request);
                if ($result instanceof Response) {
                    return $result;
                }
            }

            return $this->invoke($route['handler'], $request, $args);
        }

        if ($pathMatched) {
            return $this->fail(405, 'Metodo non consentito per questa risorsa.');
        }
        return $this->fail(404, 'Pagina non trovata.');
    }

    private function runMiddleware(string $name, Request $request): ?Response
    {
        return match ($name) {
            // Solo per chi non ha ancora fatto accesso (login, registrazione).
            'guest' => Auth::check()
                ? Response::redirect(url('/strada'))
                : null,

            // Serve un account, in qualunque stato.
            'auth' => Auth::check()
                ? null
                : Response::redirect(url('/accesso')),

            // Serve un account con indirizzo confermato e attivo.
            'active' => (Auth::check() && Auth::status() === 'active')
                ? null
                : Response::redirect(url('/accesso')),

            'admin' => Auth::isAdmin()
                ? null
                : $this->fail(403, 'Area riservata.'),

            // Freno sulle azioni che cambiano stato. La lettura non consuma nulla.
            'throttle' => $this->throttle($request),

            default => null,
        };
    }

    private function throttle(Request $request): ?Response
    {
        if ($request->method() === 'GET') {
            return null;
        }
        $id  = Auth::id();
        $key = 'azioni:' . ($id !== null ? 'u' . $id : 'ip' . $request->ip());
        $max = \App\Core\GameConfig::int('limits.actions_per_min', 120);
        if ($max > 0 && !\App\Core\RateLimiter::hit($key, $max, 60)) {
            return $this->fail(429, 'Stai andando troppo in fretta: attendi qualche secondo e riprova.');
        }
        return null;
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function invoke(callable|array $handler, Request $request, array $args): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $handler = [new $class(), $method];
        }
        /** @var mixed $out */
        $out = $handler($request, ...array_values($args));

        if ($out instanceof Response) {
            return $out;
        }
        if (is_string($out)) {
            return Response::html($out);
        }
        if (is_array($out)) {
            return Response::json($out);
        }
        return Response::html('', 204);
    }

    private function fail(int $status, string $message): Response
    {
        $body = view('errors/generic', [
            'title'   => "Errore {$status}",
            'status'  => $status,
            'message' => $message,
        ]);
        return Response::html($body, $status);
    }
}
