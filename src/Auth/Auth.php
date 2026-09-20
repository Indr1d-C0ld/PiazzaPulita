<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Session;
use App\Support\Audit;

/**
 * Autenticazione: iscrizione, verifica dell'indirizzo, accesso, uscita.
 *
 * Regola d'ingresso: nessuno entra senza aver confermato l'indirizzo e-mail.
 * L'utente resta 'pending' finché non apre il collegamento ricevuto.
 */
final class Auth
{
    private const SESSION_KEY = 'uid';

    /** @var array<string,mixed>|null cache per richiesta */
    private static ?array $cached = null;
    private static bool $resolved = false;

    // --- Stato ---------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$cached;
        }
        self::$resolved = true;

        $id = Session::get(self::SESSION_KEY);
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return self::$cached = null;
        }

        $row = Database::first(
            // Le colonne si elencano a mano per non tirarsi dietro
            // `password_hash` a ogni richiesta. Il prezzo è che una colonna
            // nuova va aggiunta ANCHE qui, e dimenticarlo non rompe niente in
            // modo visibile: `avatar_file` mancava, quindi la fotografia si
            // caricava, si salvava e si serviva — ma sul proprio profilo non
            // compariva mai, perché il valore arrivava sempre nullo.
            'SELECT id, username, email, status, role, email_verified_at, created_at, last_login_at,
                    nota, luce, avatar_file
             FROM users WHERE id = ?',
            [(int) $id]
        );

        if ($row === null || in_array((string) $row['status'], ['banned', 'suspended'], true)) {
            Session::forget(self::SESSION_KEY);
            return self::$cached = null;
        }

        return self::$cached = $row;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u === null ? null : (int) $u['id'];
    }

    public static function status(): string
    {
        $u = self::user();
        return $u === null ? 'guest' : (string) $u['status'];
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && (string) $u['role'] === 'admin';
    }

    public static function isVerified(): bool
    {
        $u = self::user();
        return $u !== null && $u['email_verified_at'] !== null;
    }

    // --- Regole --------------------------------------------------------------

    public static function minPasswordLength(): int
    {
        return max(6, GameConfig::int('auth.min_password_length', 9));
    }

    public static function registrationOpen(): bool
    {
        return GameConfig::bool('auth.registration_open', true);
    }

    public static function hashPassword(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]);
        }
        return password_hash($plain, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    /**
     * Ripulisce un nome utente prima di validarlo o cercarlo: toglie gli spazi
     * ai bordi e riduce a una sola ogni sequenza di spaziatura, comprese quelle
     * insidiose (tabulazione, spazio unificatore U+00A0). Senza questo passaggio
     * "Mario  Rossi" e "Mario Rossi" sarebbero due account distinti e
     * indistinguibili a occhio.
     */
    public static function normalizeUsername(string $u): string
    {
        $u = preg_replace('/[\p{Z}\s]+/u', ' ', $u) ?? $u;
        return trim($u);
    }

    /**
     * Nome utente: lettere (accentate comprese), cifre, spazio, apostrofo,
     * punto, trattino, underscore. Da 3 a 32 caratteri, e deve cominciare e
     * finire con una lettera o una cifra: così "Mario Rossi" va bene, " -_ " no.
     */
    public static function validateUsername(string $u): ?string
    {
        $u = self::normalizeUsername($u);

        if (mb_strlen($u) < 3 || mb_strlen($u) > 32) {
            return 'Il nome utente deve avere da 3 a 32 caratteri.';
        }
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\x27_-]*[\p{L}\p{N}]$/u', $u)) {
            return 'Il nome utente puo\' contenere lettere, cifre, spazi, apostrofo, punto, trattino '
                . 'e underscore, e deve cominciare e finire con una lettera o una cifra.';
        }
        return null;
    }

    // --- Registrazione -------------------------------------------------------

    /**
     * Crea l'account in stato 'pending' e restituisce il gettone di verifica in chiaro
     * (che esiste solo in questo istante: in tabella ne va l'hash).
     *
     * @return array{ok:bool, error?:string, user_id?:int, token?:string}
     */
    public static function register(string $username, string $email, string $password, ?string $ip = null): array
    {
        $username = self::normalizeUsername($username);
        $email    = mb_strtolower(trim($email));

        if (!self::registrationOpen()) {
            return ['ok' => false, 'error' => 'Le iscrizioni sono momentaneamente chiuse.'];
        }
        if ($err = self::validateUsername($username)) {
            return ['ok' => false, 'error' => $err];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Indirizzo e-mail non valido.'];
        }
        $min = self::minPasswordLength();
        if (mb_strlen($password) < $min) {
            return ['ok' => false, 'error' => "La password deve avere almeno {$min} caratteri."];
        }

        $clash = Database::first('SELECT id, username, email FROM users WHERE username = ? OR email = ?', [$username, $email]);
        if ($clash !== null) {
            return ['ok' => false, 'error' => (string) $clash['username'] === $username
                ? 'Questo nome utente è già in uso.'
                : 'Esiste già un account con questo indirizzo e-mail.'];
        }

        Database::run(
            'INSERT INTO users (username, email, password_hash, status) VALUES (?, ?, ?, ?)',
            [$username, $email, self::hashPassword($password), 'pending']
        );
        $userId = Database::lastInsertId();

        $token = self::issueToken($userId, 'verify_email', $ip);
        Audit::log('auth.register', $userId, 'user', $userId, ['username' => $username], $ip);

        return ['ok' => true, 'user_id' => $userId, 'token' => $token];
    }

    /** Genera un gettone monouso, ne salva l'hash e restituisce il valore in chiaro. */
    public static function issueToken(int $userId, string $kind, ?string $ip = null): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl   = max(1, GameConfig::int('auth.verify_ttl_hours', 48));

        // Un solo gettone vivo per tipo: i precedenti vengono invalidati.
        Database::run(
            'UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND kind = ? AND used_at IS NULL',
            [$userId, $kind]
        );
        Database::run(
            'INSERT INTO user_tokens (user_id, kind, token_hash, expires_at, created_ip)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)',
            [$userId, $kind, hash('sha256', $token), $ttl, $ip !== null ? @inet_pton($ip) ?: null : null]
        );
        return $token;
    }

    /**
     * Consuma il gettone di verifica e attiva l'account.
     *
     * @return array{ok:bool, error?:string, user?:array<string,mixed>}
     */
    public static function verifyEmail(string $token, ?string $ip = null): array
    {
        $token = trim($token);
        if ($token === '' || !ctype_xdigit($token)) {
            return ['ok' => false, 'error' => 'Collegamento di verifica non valido.'];
        }

        $row = Database::first(
            'SELECT t.id, t.user_id, t.used_at, t.expires_at, u.status, u.username, u.email
             FROM user_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.kind = ?',
            [hash('sha256', $token), 'verify_email']
        );

        if ($row === null) {
            return ['ok' => false, 'error' => 'Collegamento di verifica non valido.'];
        }
        if ($row['used_at'] !== null) {
            // Già usato: se l'account è attivo non è un errore, è un doppio clic.
            if ((string) $row['status'] === 'active') {
                return ['ok' => true, 'user' => $row];
            }
            return ['ok' => false, 'error' => 'Questo collegamento è già stato utilizzato.'];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'Il collegamento è scaduto. Richiedine uno nuovo dalla pagina di accesso.'];
        }

        Database::run('UPDATE user_tokens SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
        Database::run(
            "UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ? AND status = 'pending'",
            [(int) $row['user_id']]
        );
        Audit::log('auth.verify_email', (int) $row['user_id'], 'user', (int) $row['user_id'], [], $ip);

        return ['ok' => true, 'user' => $row];
    }

    // --- Accesso -------------------------------------------------------------

    /**
     * @return array{ok:bool, error?:string, need_verify?:bool, user?:array<string,mixed>}
     */
    public static function attempt(string $login, string $password, ?string $ip = null): array
    {
        // Normalizzato come in registrazione: la spaziatura digitata a caso non
        // deve impedire l'accesso. Un indirizzo e-mail non contiene spazi,
        // quindi il passaggio è innocuo anche quando si entra con quello.
        $login = self::normalizeUsername($login);
        $row = Database::first(
            'SELECT id, username, email, password_hash, status, role FROM users WHERE username = ? OR email = ?',
            [$login, mb_strtolower($login)]
        );

        // Confronto sempre eseguito anche senza utente: nessuna differenza di tempo
        // fra "utente inesistente" e "password errata".
        $hash = (string) ($row['password_hash'] ?? '$2y$12$' . str_repeat('x', 53));
        $okPw = password_verify($password, $hash);

        if ($row === null || !$okPw) {
            Audit::log('auth.login_failed', $row === null ? null : (int) $row['id'], 'user', null, ['login' => $login], $ip);
            return ['ok' => false, 'error' => 'Nome utente o password non corretti.'];
        }

        $status = (string) $row['status'];
        if ($status === 'pending') {
            return ['ok' => false, 'need_verify' => true,
                    'error' => 'Devi prima confermare il tuo indirizzo e-mail: controlla la posta.'];
        }
        if ($status === 'suspended') {
            return ['ok' => false, 'error' => 'Account sospeso.'];
        }
        if ($status === 'banned') {
            return ['ok' => false, 'error' => 'Account revocato.'];
        }

        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), (int) $row['id']]);
        }

        Session::regenerate();
        Session::put(self::SESSION_KEY, (int) $row['id']);
        self::$resolved = false;
        self::$cached = null;

        Database::run(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ?, last_seen_at = NOW() WHERE id = ?',
            [$ip !== null ? @inet_pton($ip) ?: null : null, (int) $row['id']]
        );
        Audit::log('auth.login', (int) $row['id'], 'user', (int) $row['id'], [], $ip);

        return ['ok' => true, 'user' => $row];
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id !== null) {
            Audit::log('auth.logout', $id, 'user', $id);
        }
        Session::forget(self::SESSION_KEY);
        Session::flush();
        self::$resolved = false;
        self::$cached = null;
    }
}
