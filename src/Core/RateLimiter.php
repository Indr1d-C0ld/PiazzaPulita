<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rate limiter a finestra fissa, persistito su tabella `rate_limits`.
 * Degrada in modo permissivo se il DB non e' raggiungibile.
 */
final class RateLimiter
{
    /**
     * Registra un tentativo. Ritorna true se l'azione e' consentita,
     * false se la soglia e' stata superata nella finestra corrente.
     */
    public static function hit(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $key = substr($key, 0, 180);

        try {
            $pdo = Database::pdo();
            $pdo->beginTransaction();

            $row = Database::first(
                'SELECT hits, reset_at FROM rate_limits WHERE rkey = ? FOR UPDATE',
                [$key]
            );

            $now = time();

            if ($row === null || strtotime((string) $row['reset_at']) <= $now) {
                Database::run(
                    'REPLACE INTO rate_limits (rkey, hits, reset_at) VALUES (?, 1, ?)',
                    [$key, date('Y-m-d H:i:s', $now + $windowSeconds)]
                );
                $pdo->commit();
                return true;
            }

            $hits = (int) $row['hits'] + 1;
            Database::run('UPDATE rate_limits SET hits = ? WHERE rkey = ?', [$hits, $key]);
            $pdo->commit();

            return $hits <= $maxAttempts;
        } catch (\Throwable $e) {
            if (Database::pdo()->inTransaction()) {
                Database::pdo()->rollBack();
            }
            return true; // fail-open: non blocchiamo gli utenti per un problema di DB
        }
    }

    public static function clear(string $key): void
    {
        try {
            Database::run('DELETE FROM rate_limits WHERE rkey = ?', [substr($key, 0, 180)]);
        } catch (\Throwable) {
            // ignora
        }
    }

    /** Pulizia delle righe scadute (chiamata dal tick). */
    public static function gc(): int
    {
        try {
            return Database::run('DELETE FROM rate_limits WHERE reset_at < NOW()')->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }
}
