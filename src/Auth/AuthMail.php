<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Posta;

/**
 * Le e-mail dell'autenticazione. Testo semplice (il Mailer non fa HTML).
 *
 * È la prima cosa che il giocatore legge di Piazza Pulita, quindi ha il tono
 * del gioco — asciutto, un po' obliquo — ma dice con chiarezza cosa fare:
 * un messaggio di conferma che si finge troppo non viene capito, e chi non
 * capisce non entra.
 */
final class AuthMail
{
    /** URL assoluto pubblico, necessario nei collegamenti delle e-mail. */
    private static function publicUrl(string $path = '/'): string
    {
        $base = rtrim((string) Config::get('app.public_url', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }

    /** @return array{ok:bool, error?:string} */
    public static function sendVerification(int $userId, string $email, string $username, string $token): array
    {
        $link = self::publicUrl('/verifica?token=' . $token);
        $ttl  = GameConfig::int('auth.verify_ttl_hours', 48);

        $subject = 'Piazza Pulita — conferma il tuo indirizzo';
        $body = <<<TXT
        PIAZZA PULITA
        Italia, anni ottanta. Il giro comincia da qui.

        {$username},

        qualcuno ha chiesto un posto a tuo nome. Per confermare che sei tu,
        apri questo collegamento:

        {$link}

        Vale {$ttl} ore. Se scade puoi chiederne un altro dalla pagina di
        accesso.

        Se non hai chiesto niente, lascia perdere questo messaggio: senza
        conferma l'account non viene attivato e sparisce da solo.

        --
        Piazza Pulita — gioco di commercio e rischio, ambientato negli anni
        ottanta. Progetto personale, nessun fine commerciale.
        Messaggio automatico: non rispondere a questo indirizzo.
        TXT;

        // Priorita' 1: è l'unica porta d'ingresso al gioco. Se l'SMTP non
        // risponde adesso il messaggio resta in coda e riparte da solo.
        $res = Posta::invia($email, $subject, $body, 'verifica', 1);

        // Il contatore segna il momento in cui il messaggio è stato PRESO IN
        // CARICO: è quello che regola il freno sui rinvii.
        Database::run(
            'UPDATE users SET verify_sent_at = NOW(), verify_count = verify_count + 1 WHERE id = ?',
            [$userId]
        );
        if (!$res['ok']) {
            logger('verifica per utente ' . $userId . ' messa in coda: ' . ($res['error'] ?? '?'), 'warning');
        }
        return $res;
    }

    /**
     * Avviso all'amministratore di una nuova iscrizione. Non deve mai bloccare
     * l'iscrizione: se fallisce, resta solo una riga di diario.
     */
    public static function notifyAdmin(int $userId, string $username, string $email): void
    {
        if (!Config::get('notify.new_registration', true)) {
            return;
        }
        $to = (string) Config::get('notify.admin_email', '');
        if ($to === '') {
            return;
        }

        $quando = fmt_dt(time());
        $body = <<<TXT
        Nuova iscrizione a Piazza Pulita.

          utente : {$username}
          e-mail : {$email}
          id     : {$userId}
          quando : {$quando}

        L'account resta 'pending' finché l'interessato non conferma
        l'indirizzo dal collegamento ricevuto.

        Console: php bin/console.php user:list
        TXT;

        // Priorita' bassa: l'amministratore può aspettare, il giocatore no.
        Posta::invia($to, "Piazza Pulita — nuova iscrizione: {$username}", $body, 'avviso_admin', 7);
        Database::run('UPDATE users SET admin_notified_at = NOW() WHERE id = ?', [$userId]);
    }
}
