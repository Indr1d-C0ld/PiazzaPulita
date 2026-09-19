<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    /**
     * I flash che erano in attesa all'inizio di questa richiesta.
     * Fotografati all'avvio ma NON ancora tolti dalla sessione: vengono
     * scartati solo se qualcuno li legge davvero. Vedi consumaFlash().
     *
     * @var array<string,mixed>|null
     */
    private static ?array $flashInArrivo = null;
    private static bool $flashConsumati = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $name = (string) Config::get('security.session_name', 'piazzapulita_sess');
        $ttl  = (int) Config::get('security.session_ttl', 0);
        $https = (($_SERVER['HTTPS'] ?? '') === 'on')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $ttl,
            'path'     => self::cookiePath(),
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        if (!isset($_SESSION['_born'])) {
            $_SESSION['_born'] = $now;
        } elseif ($now - (int) $_SESSION['_born'] > 1800) {
            // Rotazione periodica dell'id. NON distruttiva (false): il vecchio
            // id resta valido finche' il GC non lo raccoglie, cosi' una
            // richiesta gia' in volo o un client che tarda a salvare il nuovo
            // cookie (tipico su mobile: tab sospese, cambio rete) non resta
            // orfano di sessione.
            session_regenerate_id(false);
            $_SESSION['_born'] = $now;
        }

        self::apriFlash();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        // false = non elimina subito la vecchia sessione: evita che un client
        // lento a persistere il nuovo cookie (frequente su mobile) si ritrovi
        // senza sessione appena dopo il login. Contro la session fixation
        // basta che l'id cambi; il vecchio id sara' raccolto dal GC.
        session_regenerate_id(false);
        $_SESSION['_born'] = time();
    }

    /** Ambito del cookie di sessione: la sottocartella del deploy, non tutto il dominio. */
    private static function cookiePath(): string
    {
        $base = Config::get('app.base_path');
        if (!is_string($base) || $base === '') {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $base = rtrim(dirname($script), '/');
        }
        $base = '/' . trim($base, '/');
        return $base === '/' ? '/' : $base . '/';
    }

    public static function flush(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    // --- Flash ---------------------------------------------------------------

    /**
     * Fotografa i flash in attesa senza toglierli dalla sessione.
     *
     * Prima il ciclo avanzava a ogni avvio di sessione, comprese le chiamate
     * di sfondo: il polling della radio, che parte ogni sei secondi, poteva
     * consumare il messaggio destinato alla pagina e farlo sparire — circa
     * una azione su venti restava senza la propria conferma. Ora un flash si
     * scarta solo quando qualcuno lo legge davvero, e una risposta JSON non
     * legge nulla.
     */
    private static function apriFlash(): void
    {
        self::$flashInArrivo = $_SESSION['_flash_next'] ?? [];
        self::$flashConsumati = false;
    }

    /**
     * Toglie dalla sessione esattamente i flash fotografati all'avvio — non
     * quelli scritti durante questa richiesta, che sono per la prossima.
     */
    private static function consumaFlash(): void
    {
        if (self::$flashConsumati) {
            return;
        }
        self::$flashConsumati = true;
        foreach (array_keys(self::$flashInArrivo ?? []) as $k) {
            unset($_SESSION['_flash_next'][$k]);
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        self::consumaFlash();
        return self::$flashInArrivo[$key] ?? $default;
    }

    public static function hasFlash(string $key): bool
    {
        self::consumaFlash();
        return isset(self::$flashInArrivo[$key]);
    }

    /** Conserva i vecchi input per il re-render dei form dopo un errore. */
    public static function flashInput(array $data): void
    {
        unset($data['password'], $data['password_confirm'], $data['_token']);
        $_SESSION['_flash_next']['_old'] = $data;
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        self::consumaFlash();
        return self::$flashInArrivo['_old'][$key] ?? $default;
    }
}
