<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;

/**
 * I fornitori: chi ti vende sottocosto rispetto alla piazza, se ti conosce.
 *
 * Non si comprano col denaro — è l'unica cosa del gioco che non si compra. Si
 * sbloccano col **rispetto**, che si costruisce facendo girare merce e pagando
 * i debiti, e chiedono un **lotto minimo**: sotto una certa quantità non ti
 * parlano nemmeno. È la progressione di lungo periodo, quella che distingue chi
 * gioca da mesi da chi ha solo fatto un colpo grosso.
 *
 * Lo sconto si applica al prezzo d'acquisto di piazza, non lo sostituisce: il
 * mercato resta quello di tutti, e il fornitore è il tuo canale dentro quel
 * mercato. Così un fornitore non rompe l'invariante del §2.6 — muove il margine
 * dal venditore di piazza a te, non crea denaro dal nulla.
 */
final class Fornitori
{
    /** @var list<array<string,mixed>>|null */
    private static ?array $tutti = null;

    /** @return list<array<string,mixed>> */
    public static function tutti(): array
    {
        if (self::$tutti !== null) {
            return self::$tutti;
        }
        $f = [];
        foreach (Database::all('SELECT * FROM fornitori ORDER BY ordine') as $r) {
            $r['rispetto_min'] = (int) $r['rispetto_min'];
            $r['sconto']       = (float) $r['sconto'];
            $r['lotto_min']    = (int) $r['lotto_min'];
            $f[] = $r;
        }
        return self::$tutti = $f;
    }

    /**
     * Tutti i fornitori che ti parlano, per fascia.
     *
     * Si tengono TUTTI, non solo il migliore. Sembrava logico che sbloccarne
     * uno più forte rendesse inutile il precedente — non lo è: quelli grossi
     * chiedono lotti grossi, e su un ordine piccolo il fornitore da ottanta
     * pezzi non ti serve a niente mentre quello da venti sì. Tenendo solo il
     * migliore, **progredire peggiorava**: a rispetto 10 su trenta unità si
     * scontava l'8 %, a rispetto 35 non si scontava più niente.
     *
     * @param array<string,mixed> $p
     * @return array<string,list<array<string,mixed>>> fascia => fornitori
     */
    public static function miei(array $p): array
    {
        $rispetto = (float) ($p['rispetto'] ?? 0);
        $fuori = [];
        foreach (self::tutti() as $f) {
            if ($rispetto < $f['rispetto_min']) {
                continue;
            }
            $fuori[(string) $f['fascia']][] = $f;
        }
        return $fuori;
    }

    /**
     * Lo sconto che spetta a un acquisto, se spetta.
     *
     * Fra quelli che ti parlano e che accettano questa quantità, vince lo
     * sconto più alto. Se nessuno arriva alla quantità, si dice quale sarebbe
     * il primo e quanto manca: uno sconto che non si capisce come ottenere non
     * è una progressione, è una delusione.
     *
     * @param array<string,mixed> $p @param array<string,mixed> $bene
     * @return array{sconto:float,fornitore:?array<string,mixed>,manca:int}
     */
    public static function perAcquisto(array $p, array $bene, int $quantita): array
    {
        $suoi = self::miei($p)[(string) $bene['fascia']] ?? [];
        if ($suoi === []) {
            return ['sconto' => 0.0, 'fornitore' => null, 'manca' => 0];
        }

        $meglio = null;
        $piuVicino = null;
        foreach ($suoi as $f) {
            if ($quantita >= $f['lotto_min']) {
                if ($meglio === null || $f['sconto'] > $meglio['sconto']) {
                    $meglio = $f;
                }
            } elseif ($piuVicino === null || $f['lotto_min'] < $piuVicino['lotto_min']) {
                $piuVicino = $f;
            }
        }

        if ($meglio !== null) {
            return ['sconto' => (float) $meglio['sconto'], 'fornitore' => $meglio, 'manca' => 0];
        }
        return ['sconto' => 0.0, 'fornitore' => $piuVicino,
                'manca' => $piuVicino === null ? 0 : (int) $piuVicino['lotto_min'] - $quantita];
    }

    /** Il prossimo da sbloccare, per far vedere dove si sta andando. */
    public static function prossimo(array $p): ?array
    {
        $rispetto = (float) ($p['rispetto'] ?? 0);
        foreach (self::tutti() as $f) {
            if ($rispetto < $f['rispetto_min']) {
                return $f;
            }
        }
        return null;
    }
}
