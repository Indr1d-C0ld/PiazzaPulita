<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Il combattimento fra giocatori. Modulo puro.
 *
 * Lo scheletro è quello di dopewars (§4.6 dello studio): due punteggi, due
 * estrazioni contrapposte, il danno che somma un tiro per arma. Quello che
 * cambia è tutto il resto del contorno.
 *
 * In dopewars chi vinceva si prendeva **tutto**: contante, banca, merce, armi.
 * In una partita da venti minuti va benissimo; su un personaggio costruito in
 * tre mesi è la fine del gioco per la vittima e, dopo un po', per il server.
 * Qui si prende solo ciò che la vittima aveva **addosso** — il denaro pulito è
 * intestato, i canali e gli immobili pure — e **sotto una certa soglia non c'è
 * niente da prendere**: aggredire chi ha poco resta possibile e diventa
 * semplicemente l'attività peggio pagata del gioco (docs/DESIGN.md §0.1b).
 */
final class Scontro
{
    /**
     * Il punteggio d'attacco: le armi che porti addosso, più le guardie che ti
     * stanno dietro (che sparano anche loro).
     */
    public static function attacco(int $base, int $armi, int $perArma, float $guardie): float
    {
        return max(10.0, $base + $armi * $perArma + $guardie * 30.0);
    }

    /**
     * La difesa: le guardie, e il sangue freddo di chi le comanda. Un uomo che
     * non si agita para meglio.
     */
    public static function difesa(int $base, float $guardie, int $perGuardia, float $sangueFreddo): float
    {
        return max(10.0, $base + $guardie * $perGuardia + $sangueFreddo / 2.0);
    }

    /**
     * Un solo scambio di colpi. Deterministico rispetto ai tiri che riceve,
     * così si può provare senza inseguire il caso.
     *
     * @return array{colpito:bool,danno:int}
     */
    public static function colpo(float $attacco, float $difesa, int $armi, float $guardieDifesa, Rng $rng): array
    {
        $colpito = $rng->fra(0, $attacco) > $rng->fra(0, $difesa);
        if (!$colpito) {
            return ['colpito' => false, 'danno' => 0];
        }

        // A mani nude si fa male lo stesso, ma poco: è la differenza fra una
        // rissa e una sparatoria.
        $tiri = max(1, $armi);
        $danno = 0;
        for ($i = 0; $i < $tiri; $i++) {
            $danno += $rng->intero($armi > 0 ? 8 : 2, $armi > 0 ? 34 : 12);
        }
        // Le guardie incassano per te.
        $danno = (int) round($danno * (1.0 - min(0.55, $guardieDifesa * 0.5)));

        return ['colpito' => true, 'danno' => max(1, $danno)];
    }

    /** Chi attacca fa più fatica a svignarsela: è il costo dell'iniziativa. */
    public static function fuga(float $base, bool $staAttaccando, Rng $rng): bool
    {
        return $rng->reale() < ($staAttaccando ? $base / 2.0 : $base);
    }

    /**
     * Quanto vale davvero prendersi quello che l'altro ha addosso.
     *
     * Sotto la soglia si restituisce zero e non si tocca niente: la vittima non
     * perde, l'aggressore non guadagna, e il calore lo paga lo stesso.
     */
    public static function bottino(int $sporco, int $valoreMerce, int $soglia): int
    {
        $totale = $sporco + $valoreMerce;
        return $totale < $soglia ? 0 : $totale;
    }
}
