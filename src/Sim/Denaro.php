<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * La matematica del denaro: interessi e riciclaggio. Modulo puro.
 *
 * **L'interesse è continuo, non a scatti.** Il 10 % al giorno dell'originale
 * era un gradino che scattava a ogni turno, perché i turni erano giorni. Qui il
 * tempo è vero e non ha turni: se l'interesse scattasse a mezzanotte, chi
 * ripaga alle 23:59 pagherebbe zero e chi ripaga all'una pagherebbe tutto.
 * Quindi si compone di continuo — `D(t) = D0 · (1+i)^(t/giorno)` — e il
 * giocatore vede il debito salire mentre guarda la pagina, che è anche molto
 * più onesto di una sorpresa una volta al giorno.
 *
 * **Il tetto esiste perché un numero che diverge non è tensione, è un guasto.**
 * Oltre tre volte il capitale prestato il debito smette di crescere: da lì in
 * poi non aumentano gli interessi, cominciano le conseguenze. Un giocatore che
 * si scollega una settimana deve trovare un problema serio, non una cifra
 * impagabile che gli dice solo di cambiare gioco.
 */
final class Denaro
{
    /**
     * Il debito dopo $secondi, con l'interesse composto di continuo e il tetto.
     *
     * @param int $prestato quanto è stato effettivamente prestato (la base del tetto)
     */
    public static function debitoDopo(int $debito, int $prestato, int $secondi, float $interesseGiorno, float $tetto): int
    {
        if ($debito <= 0 || $secondi <= 0) {
            return max(0, $debito);
        }
        $massimo = (int) round(max($prestato, $debito > 0 ? $prestato : 0) * $tetto);
        if ($massimo > 0 && $debito >= $massimo) {
            return $debito;      // già al tetto: non cresce più
        }

        $giorni = $secondi / 86400.0;
        $nuovo = (int) round($debito * (1.0 + $interesseGiorno) ** $giorni);

        return $massimo > 0 ? min($nuovo, $massimo) : $nuovo;
    }

    /** Quanto si può ancora farsi prestare. */
    public static function prestitoDisponibile(int $debito, int $pulito, int $base, float $suPulito): int
    {
        return max(0, (int) round($base + $pulito * $suPulito) - $debito);
    }

    /**
     * Quanto un canale riesce a lavare in un certo tempo, e cosa ne esce.
     *
     * La capacità oraria è il punto: si può avere un miliardo sporco in cantina
     * e non poterlo usare. È questo a rendere il riciclaggio una struttura da
     * costruire invece di una percentuale da subire.
     *
     * @return array{lavato:int,pulito:int,commissione:int,coda:int}
     */
    public static function lava(int $coda, int $secondi, int $capacitaOra, float $commissione): array
    {
        if ($coda <= 0 || $secondi <= 0 || $capacitaOra <= 0) {
            return ['lavato' => 0, 'pulito' => 0, 'commissione' => 0, 'coda' => max(0, $coda)];
        }

        $lavabile = (int) floor($capacitaOra * ($secondi / 3600.0));
        $lavato   = min($coda, $lavabile);
        $trattenuto = (int) round($lavato * $commissione);

        return [
            'lavato'      => $lavato,
            'pulito'      => $lavato - $trattenuto,
            'commissione' => $trattenuto,
            'coda'        => $coda - $lavato,
        ];
    }

    /** Quanto tempo ci vuole a lavare una certa cifra. In secondi. */
    public static function tempoDiLavaggio(int $importo, int $capacitaOra): int
    {
        if ($importo <= 0 || $capacitaOra <= 0) {
            return 0;
        }
        return (int) ceil($importo / $capacitaOra * 3600);
    }
}
