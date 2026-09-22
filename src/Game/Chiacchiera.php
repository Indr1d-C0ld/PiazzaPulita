<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Core\RateLimiter;
use App\Sim\Clock;

/**
 * La chiacchiera di piazza: quello che si dice stando lì.
 *
 * **Si parla per strada, non al telefono.** Le voci si leggono solo stando
 * nella piazza dove sono state dette, e si dimenticano dopo poche ore. Non è
 * una limitazione tecnica: in questo gioco l'informazione sui prezzi altrove
 * si paga — è il mestiere del basista — e una posta privata a distanza zero la
 * regalerebbe a chiunque. Per dire qualcosa a qualcuno bisogna essere dove sta
 * lui, ed è una regola che si spiega da sola.
 */
final class Chiacchiera
{
    public const LUNGHEZZA_MAX = 220;

    /** @return list<array<string,mixed>> */
    public static function inPiazza(int $piazzaId, ?int $quante = null): array
    {
        $n = max(1, min(120, $quante ?? GameConfig::int('chiacchiera.quante', 40)));
        $ore = max(1, GameConfig::int('chiacchiera.ore', 8));

        $righe = Database::all(
            "SELECT c.*, u.avatar_file
               FROM chiacchiere c
               LEFT JOIN personaggi p ON p.id = c.personaggio_id
               LEFT JOIN users u ON u.id = p.user_id
              WHERE c.piazza_id = ? AND c.fatto_at >= DATE_SUB(NOW(), INTERVAL {$ore} HOUR)
              ORDER BY c.id DESC LIMIT {$n}",
            [$piazzaId]
        );
        return array_reverse($righe);
    }

    /**
     * Dice una cosa. Il freno è al minuto, non all'ora: serve a impedire il
     * muro di testo, non a impedire una conversazione.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function di(int $personaggioId, int $piazzaId, string $testo): array
    {
        $testo = trim(preg_replace('/\s+/u', ' ', $testo) ?? '');
        if ($testo === '') {
            return ['ok' => false, 'error' => 'Non hai detto niente.'];
        }
        if (mb_strlen($testo) > self::LUNGHEZZA_MAX) {
            $testo = mb_substr($testo, 0, self::LUNGHEZZA_MAX);
        }

        $tetto = max(1, GameConfig::int('chiacchiera.al_minuto', 4));
        if (!RateLimiter::hit('voce:' . $personaggioId, $tetto, 60)) {
            return ['ok' => false, 'error' => 'Stai parlando troppo in fretta. Prendi fiato.'];
        }

        Database::run(
            'INSERT INTO chiacchiere (piazza_id, personaggio_id, autore, testo, genere, fatto_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$piazzaId, $personaggioId, Rivalita::nome($personaggioId), $testo, 'voce', Clock::perDb()]
        );
        return ['ok' => true];
    }

    /** L'avviso dell'amministrazione: stesso filo, ma si vede che è altra voce. */
    public static function avviso(int $piazzaId, string $testo, string $firma = 'avviso'): void
    {
        Database::run(
            'INSERT INTO chiacchiere (piazza_id, personaggio_id, autore, testo, genere, fatto_at)
             VALUES (?, NULL, ?, ?, ?, ?)',
            [$piazzaId, $firma, mb_substr(trim($testo), 0, self::LUNGHEZZA_MAX), 'avviso', Clock::perDb()]
        );
    }

    /** Le voci si dimenticano: le toglie il battito. */
    public static function dimentica(): int
    {
        $ore = max(1, GameConfig::int('chiacchiera.ore', 8));
        return Database::run(
            "DELETE FROM chiacchiere WHERE fatto_at < DATE_SUB(NOW(), INTERVAL {$ore} HOUR) LIMIT 2000"
        )->rowCount();
    }
}
