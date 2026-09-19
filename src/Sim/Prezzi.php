<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Il prezzo di riferimento di ogni bene: una passeggiata a ritorno verso la
 * media (Ornstein-Uhlenbeck), a passi discreti di cinque minuti.
 *
 * **Perché a passi e non continua.** Il prezzo deve poter essere calcolato da
 * chiunque, in qualunque momento, ottenendo lo stesso numero: il battito da
 * cron e la richiesta del giocatore sono processi diversi. Con passi indicizzati
 * e il rumore estratto da un generatore seminato su (seme, bene, passo), il
 * valore al passo N è una funzione pura — si itera dal punto salvato e si
 * arriva sempre allo stesso posto.
 *
 * **La volatilità non è uguale per tutti.** I beni poveri ballano di più in
 * percentuale, come nell'originale del 1984: le sigarette vanno da 8.000 a
 * 45.000 (cinque volte e mezza), la cocaina da 120.000 a 300.000 (due e
 * mezza). È quella differenza a fare la scala di progressione, e si ottiene
 * legando lo scarto per passo all'ampiezza della forchetta storica.
 */
final class Prezzi
{
    /** Oltre questo numero di passi da recuperare non si itera: si riparte dalla media. */
    private const PASSI_MAX = 4000;

    /** Il passo corrente, dall'epoca. */
    public static function passo(int $istante, int $minutiPerPasso): int
    {
        return intdiv($istante, max(1, $minutiPerPasso) * 60);
    }

    /**
     * Scarto per passo, in frazione del prezzo medio.
     *
     * Ancorato al rapporto massimo/minimo: un bene che storicamente oscilla di
     * cinque volte deve muoversi più in fretta di uno che oscilla di due e
     * mezza, o la forchetta resta un'etichetta senza conseguenze.
     */
    public static function volatilita(float $min, float $max, float $base): float
    {
        $rapporto = $max / max(1.0, $min);
        return $base * ($rapporto / 2.5);
    }

    /**
     * Porta il prezzo dal passo $daPasso al passo $aPasso.
     *
     * @return array{p0:float,passo:int}
     */
    public static function avanza(
        float $p0,
        int $daPasso,
        int $aPasso,
        int $beneId,
        float $min,
        float $max,
        int $seme,
        float $ritorno,
        float $volatilitaBase,
    ): array {
        if ($aPasso <= $daPasso) {
            return ['p0' => $p0, 'passo' => $daPasso];
        }

        $media = ($min + $max) / 2.0;
        $sigma = self::volatilita($min, $max, $volatilitaBase) * $media;

        // Server fermo per giorni: non si ripercorrono centomila passi per
        // arrivare dove la media sarebbe arrivata da sola. Si riparte da vicino
        // alla media e si fanno gli ultimi passi veri, che sono quelli che il
        // giocatore vedrà muoversi.
        if ($aPasso - $daPasso > self::PASSI_MAX) {
            $p0 = $media;
            $daPasso = $aPasso - self::PASSI_MAX;
        }

        for ($n = $daPasso + 1; $n <= $aPasso; $n++) {
            $rng = Rng::da($seme, "prezzo:{$beneId}:{$n}");
            $p0 += $ritorno * ($media - $p0) + $sigma * $rng->normale();
            // La forchetta storica è una banda, non un suggerimento.
            $p0 = max($min, min($max, $p0));
        }

        return ['p0' => $p0, 'passo' => $aPasso];
    }
}
