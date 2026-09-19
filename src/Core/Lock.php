<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lucchetti consultivi sul database.
 *
 * Servono a una cosa sola: impedire che due processi tocchino lo stesso stato
 * di mercato nello stesso istante. Il battito del minuto e la richiesta del
 * giocatore sono processi diversi — il flock di bin/tick.php protegge solo i
 * battiti fra loro — e senza lucchetto possono partire dallo stesso istante di
 * avanzamento, rigenerare due volte la stessa giacenza e regalare merce dal
 * nulla. In un gioco il cui unico vincolo strutturale e' la quantita' finita
 * di merce e di denaro che il mondo produce ogni ora, questo non e' un
 * dettaglio: e' il gioco.
 *
 * Si usa GET_LOCK di MariaDB perche' vive nel database, che e' l'unica cosa
 * che tutti i processi vedono di sicuro. Il nome porta il prefisso del database
 * perche' i lucchetti sono globali al server e qui sopra girano altri siti.
 */
final class Lock
{
    /** @var list<string> lucchetti presi da questo processo, per la rete di sicurezza */
    private static array $presi = [];

    private static function nomeCompleto(string $nome): string
    {
        // GET_LOCK accetta al massimo 64 caratteri.
        return substr('piz:' . (string) Config::get('db.name', 'piazzapulita') . ':' . $nome, 0, 64);
    }

    /**
     * Prova a prendere il lucchetto. Per difetto non aspetta: chi arriva
     * secondo se ne va, non fa la coda.
     */
    public static function prendi(string $nome, int $attesaSec = 0): bool
    {
        $pieno = self::nomeCompleto($nome);
        $r = Database::first('SELECT GET_LOCK(?, ?) AS ok', [$pieno, $attesaSec]);
        $ok = $r !== null && (int) $r['ok'] === 1;
        if ($ok) {
            self::$presi[] = $pieno;
        }
        return $ok;
    }

    public static function lascia(string $nome): void
    {
        $pieno = self::nomeCompleto($nome);
        Database::run('DO RELEASE_LOCK(?)', [$pieno]);
        $i = array_search($pieno, self::$presi, true);
        if ($i !== false) {
            unset(self::$presi[$i]);
        }
    }

    /**
     * Esegue $lavoro col lucchetto in mano, e lo restituisce comunque vada —
     * anche se il lavoro solleva un'eccezione. Se il lucchetto e' occupato non
     * si esegue niente e si ottiene $seOccupato.
     */
    public static function con(string $nome, callable $lavoro, mixed $seOccupato = null, int $attesaSec = 0): mixed
    {
        if (!self::prendi($nome, $attesaSec)) {
            return $seOccupato;
        }
        try {
            return $lavoro();
        } finally {
            self::lascia($nome);
        }
    }

    /** Rete di sicurezza per gli script lunghi: libera tutto quello che resta. */
    public static function liberaTutto(): void
    {
        foreach (self::$presi as $pieno) {
            Database::run('DO RELEASE_LOCK(?)', [$pieno]);
        }
        self::$presi = [];
    }
}
