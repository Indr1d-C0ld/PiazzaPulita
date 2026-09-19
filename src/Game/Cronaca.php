<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Clock;

/**
 * Il notiziario del mondo: quello che è successo, uguale per tutti.
 *
 * Senza, metà di quello che accade sarebbe invisibile — e un mondo condiviso
 * che non si vede è un mondo che tanto vale non avere. È anche il posto dove
 * gli shock del mercato smettono di essere numeri e diventano fatti, come le
 * frasi del 1984 («COPS MADE A BIG COKE BUST!») da cui viene l'idea.
 */
final class Cronaca
{
    public static function scrivi(string $genere, string $testo, ?int $piazzaId = null, int $rilievo = 1): void
    {
        try {
            Database::run(
                'INSERT INTO cronaca (genere, testo, piazza_id, rilievo, fatto_at) VALUES (?, ?, ?, ?, ?)',
                [$genere, mb_substr($testo, 0, 255), $piazzaId, max(1, min(5, $rilievo)), Clock::perDb()]
            );
        } catch (\Throwable $e) {
            logger('cronaca non scritta: ' . $e->getMessage(), 'warning');
        }
    }

    /** @return list<array<string,mixed>> */
    public static function ultime(int $quante = 60, int $rilievoMin = 1): array
    {
        return Database::all(
            'SELECT c.*, p.nome AS piazza, ci.nome AS citta
               FROM cronaca c LEFT JOIN piazze p ON p.id = c.piazza_id
               LEFT JOIN citta ci ON ci.id = p.citta_id
              WHERE c.rilievo >= ? ORDER BY c.id DESC LIMIT ' . max(1, min(200, $quante)),
            [$rilievoMin]
        );
    }

    /** Potatura: il giornale di sei mesi fa non lo legge nessuno. */
    public static function pota(int $giorni = 30): int
    {
        return Database::run('DELETE FROM cronaca WHERE fatto_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 2000',
            [max(1, $giorni)])->rowCount();
    }
}
