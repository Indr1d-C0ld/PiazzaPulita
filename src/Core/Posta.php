<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Coda della posta in uscita.
 *
 * Il Mailer sa parlare SMTP; questa classe sa cosa fare quando l'SMTP non
 * risponde. Prima non lo sapeva nessuno: il messaggio andava perduto e
 * l'utente restava fuori dal gioco, perche' senza conferma dell'indirizzo non
 * si entra (audit A6).
 *
 * Regole:
 *   - ogni messaggio entra in coda PRIMA di essere tentato: se il processo
 *     muore a meta', il messaggio resta;
 *   - si tenta subito; se non riesce, si riprova con attesa crescente
 *     (1, 5, 15, 60, 180, 360 minuti) fino al numero massimo di tentativi;
 *   - il tetto giornaliero del provider si rispetta contando gli invii
 *     riusciti nelle ultime 24 ore, non sperando che bastino.
 */
final class Posta
{
    /** Attese fra un tentativo e l'altro, in minuti. */
    private const ATTESE = [1, 5, 15, 60, 180, 360];

    /**
     * Trasporto alternativo, SOLO per le prove automatiche.
     *
     * Serve perche' la configurazione vera punta a un relay che manda posta
     * davvero: una prova non deve poter svegliare la casella di nessuno. Si
     * puo' impostare unicamente da riga di comando.
     *
     * @var null|callable(string,string,string):array{ok:bool,error?:string}
     */
    private static $trasporto = null;

    public static function trasportoDiProva(?callable $f): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Il trasporto di prova esiste solo da riga di comando.');
        }
        self::$trasporto = $f;
    }

    /** @return array{ok:bool, error?:string} */
    private static function consegna(string $a, string $oggetto, string $corpo): array
    {
        if (self::$trasporto !== null) {
            return (self::$trasporto)($a, $oggetto, $corpo);
        }
        return Mailer::send($a, $oggetto, $corpo);
    }

    /**
     * Mette in coda e tenta subito.
     *
     * @return array{ok:bool, id:int, error?:string} ok=true se e' gia' partito
     */
    public static function invia(string $destinatario, string $oggetto, string $corpo, string $genere = 'generico', int $priorita = 5): array
    {
        $id = self::accoda($destinatario, $oggetto, $corpo, $genere, $priorita);
        $r  = self::tenta($id);
        return ['ok' => $r['ok'], 'id' => $id] + ($r['ok'] ? [] : ['error' => $r['error'] ?? 'invio rimandato']);
    }

    /** Mette in coda soltanto: partira' col prossimo battito. */
    public static function accoda(string $destinatario, string $oggetto, string $corpo, string $genere = 'generico', int $priorita = 5): int
    {
        Database::run(
            'INSERT INTO mail_queue (destinatario, oggetto, corpo, genere, priorita) VALUES (?, ?, ?, ?, ?)',
            [$destinatario, $oggetto, $corpo, $genere, max(1, min(9, $priorita))]
        );
        return Database::lastInsertId();
    }

    /**
     * Tenta un messaggio della coda.
     *
     * @return array{ok:bool, error?:string, rinunciato?:bool}
     */
    public static function tenta(int $id): array
    {
        $m = Database::first('SELECT * FROM mail_queue WHERE id = ?', [$id]);
        if ($m === null || $m['inviato_at'] !== null || $m['rinunciato_at'] !== null) {
            return ['ok' => false, 'error' => 'niente da fare'];
        }

        if (self::tettoRaggiunto()) {
            // Non e' un errore del messaggio: si riprova piu' tardi senza
            // consumare un tentativo.
            Database::run(
                'UPDATE mail_queue SET prossimo_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE),
                        ultimo_errore = ? WHERE id = ?',
                ['tetto giornaliero del provider raggiunto', $id]
            );
            return ['ok' => false, 'error' => 'tetto giornaliero raggiunto'];
        }

        $res = self::consegna((string) $m['destinatario'], (string) $m['oggetto'], (string) $m['corpo']);

        if ($res['ok']) {
            Database::run('UPDATE mail_queue SET inviato_at = NOW(), tentativi = tentativi + 1, ultimo_errore = NULL WHERE id = ?', [$id]);
            return ['ok' => true];
        }

        $tentativi = (int) $m['tentativi'] + 1;
        $max = max(1, GameConfig::int('mail.max_tentativi', 6));
        $errore = mb_substr((string) ($res['error'] ?? 'errore sconosciuto'), 0, 255);

        if ($tentativi >= $max) {
            Database::run(
                'UPDATE mail_queue SET tentativi = ?, rinunciato_at = NOW(), ultimo_errore = ? WHERE id = ?',
                [$tentativi, $errore, $id]
            );
            logger("posta: rinuncia dopo {$tentativi} tentativi verso {$m['destinatario']} — {$errore}", 'error');
            return ['ok' => false, 'error' => $errore, 'rinunciato' => true];
        }

        $attesa = self::ATTESE[min($tentativi - 1, count(self::ATTESE) - 1)];
        Database::run(
            'UPDATE mail_queue SET tentativi = ?, prossimo_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), ultimo_errore = ? WHERE id = ?',
            [$tentativi, $attesa, $errore, $id]
        );
        logger("posta: tentativo {$tentativi} fallito verso {$m['destinatario']}, riprovo fra {$attesa} minuti — {$errore}", 'warning');
        return ['ok' => false, 'error' => $errore];
    }

    /**
     * Svuota un po' di coda. Chiamata dal battito.
     *
     * @return array{tentati:int,inviati:int,rinunciati:int}
     */
    public static function smista(?int $quanti = null): array
    {
        $quanti ??= max(1, GameConfig::int('mail.per_battito', 5));
        $righe = Database::all(
            'SELECT id FROM mail_queue
              WHERE inviato_at IS NULL AND rinunciato_at IS NULL AND prossimo_at <= NOW()
              ORDER BY priorita, id LIMIT ' . (int) $quanti
        );

        $esito = ['tentati' => 0, 'inviati' => 0, 'rinunciati' => 0];
        foreach ($righe as $r) {
            $x = self::tenta((int) $r['id']);
            $esito['tentati']++;
            if ($x['ok']) {
                $esito['inviati']++;
            } elseif (!empty($x['rinunciato'])) {
                $esito['rinunciati']++;
            }
        }
        return $esito;
    }

    /** Invii riusciti nelle ultime 24 ore. */
    public static function inviate24h(): int
    {
        $r = Database::first('SELECT COUNT(*) n FROM mail_queue WHERE inviato_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        return $r === null ? 0 : (int) $r['n'];
    }

    public static function tettoRaggiunto(): bool
    {
        $tetto = GameConfig::int('mail.tetto_24h', 280);
        return $tetto > 0 && self::inviate24h() >= $tetto;
    }

    /** @return array{in_coda:int,inviate_24h:int,rinunciate:int,tetto:int} */
    public static function stato(): array
    {
        $coda = Database::first('SELECT COUNT(*) n FROM mail_queue WHERE inviato_at IS NULL AND rinunciato_at IS NULL');
        $rin  = Database::first('SELECT COUNT(*) n FROM mail_queue WHERE rinunciato_at IS NOT NULL');
        return [
            'in_coda'     => $coda === null ? 0 : (int) $coda['n'],
            'inviate_24h' => self::inviate24h(),
            'rinunciate'  => $rin === null ? 0 : (int) $rin['n'],
            'tetto'       => GameConfig::int('mail.tetto_24h', 280),
        ];
    }

    /** Potatura: i messaggi vecchi non servono piu' a nessuno. */
    public static function pota(int $giorni = 30): int
    {
        return Database::run(
            'DELETE FROM mail_queue WHERE (inviato_at IS NOT NULL OR rinunciato_at IS NOT NULL)
               AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1000',
            [max(1, $giorni)]
        )->rowCount();
    }
}
