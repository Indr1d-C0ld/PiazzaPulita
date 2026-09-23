<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;

/**
 * Mette in fila tutto quello che tocca le tasche di un giocatore.
 *
 * **La regola è una sola: prima si blocca la riga del personaggio, poi il
 * resto.** La riga di `personaggi` fa da lucchetto per tutto quello che è suo —
 * contante, pulito, carico, depositi, uomini, cassa della batteria vista da lui.
 * Chi la tiene ha il diritto di leggere un numero e riscriverlo; chi non la
 * tiene aspetta.
 *
 * Il gioco gira in due processi che non si parlano: il battito, ogni minuto, e
 * le pagine, a ogni clic. Un giocatore con il telefono e il computer aperti ne
 * aggiunge un terzo. Senza questa fila, due di loro potevano leggere lo stesso
 * saldo, fare ognuno il suo conto e scrivere ognuno il proprio risultato: il
 * lavaggio accreditato due volte, la cassa della batteria prelevata due volte,
 * lo stesso bottino preso da due aggressori. Tutte cose provate, non supposte.
 *
 * Quando i personaggi sono più d'uno si bloccano in ordine di id crescente:
 * due scontri incrociati che li prendessero in ordine opposto si
 * aspetterebbero a vicenda per sempre.
 *
 * Se c'è già una transazione aperta, la fila ci entra dentro invece di aprirne
 * un'altra: così un'azione può chiamarne un'altra senza chiedersi chi comanda.
 */
final class Fila
{
    /**
     * @template T
     * @param callable(array<string,mixed>|null):T $fn riceve la riga fresca, bloccata
     * @return T
     */
    public static function per(int $personaggioId, callable $fn): mixed
    {
        return self::perTutti([$personaggioId],
            static fn(array $righe) => $fn($righe[$personaggioId] ?? null));
    }

    /**
     * @template T
     * @param list<int> $ids
     * @param callable(array<int,array<string,mixed>|null>):T $fn riceve le righe fresche per id
     * @return T
     */
    public static function perTutti(array $ids, callable $fn): mixed
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $pdo = Database::pdo();
        $mia = !$pdo->inTransaction();
        if ($mia) {
            $pdo->beginTransaction();
        }
        try {
            $righe = [];
            foreach ($ids as $id) {
                $righe[$id] = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$id]);
            }
            $esito = $fn($righe);
            if ($mia) {
                $pdo->commit();
            }
            return $esito;
        } catch (\Throwable $e) {
            if ($mia && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
