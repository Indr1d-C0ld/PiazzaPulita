<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Etichette su una carta: da che parte scriverle, e come non farle accavallare.
 *
 * Modulo puro: entrano rettangoli, escono rettangoli. Non sa cosa sia una
 * città né dove stia il database — ed è per questo che si può provare davvero,
 * pretendendo la proprietà («presi due blocchi qualsiasi, non si sovrappongono»)
 * invece di guardare il disegno e fidarsi.
 *
 * **Il punto non si muove mai.** Quello è geografia. Si muove soltanto la
 * scritta, e quando si muove resta legata al suo punto da un filo — è quello
 * che fanno le carte stampate da sempre.
 */
final class Etichette
{
    /** Quanto spazio si prende una riga di scritta. */
    public const RIGA = 13.0;
    /** Quanto il riquadro sborda SOPRA la riga di base del testo. */
    private const SOPRA = self::RIGA * 0.75;
    /** Lo stacco fra due blocchi che si sarebbero toccati. */
    private const ARIA = 2.0;

    /**
     * Da che parte scrivere.
     *
     * Non basta guardare se il punto sta a destra o a sinistra del foglio:
     * conta quanto è LARGA l'etichetta. Con i nomi dei giocatori sotto, il
     * blocco di Milano usciva dal bordo sinistro — il testo c'era, e non si
     * leggeva.
     */
    public static function lato(float $x, float $largo, float $foglio, float $sogliaPreferenza = 0.52): string
    {
        $sinistra = $x;
        $destra   = $foglio - $x;

        if ($largo <= $sinistra && $largo <= $destra) {
            // Ci sta da tutte e due: si scrive verso il mare aperto.
            return $x > $foglio * $sogliaPreferenza ? 'destra' : 'sinistra';
        }
        if ($largo <= $sinistra) { return 'sinistra'; }
        if ($largo <= $destra)   { return 'destra'; }
        return $destra >= $sinistra ? 'destra' : 'sinistra';
    }

    /**
     * Sposta in giù le etichette che si pestano i piedi.
     *
     * Si confrontano i RETTANGOLI, non i lati. Una prima versione raggruppava
     * per lato e confrontava solo etichette della stessa parte: Milano scriveva
     * a sinistra, Torino a destra, non si incontravano mai nel confronto — e
     * sulla carta finivano una sopra l'altra, perché puntavano l'una verso
     * l'altra.
     *
     * @param list<array{x:float,y:float,largo:float,alto:float,lato:string}> $blocchi
     * @return list<array{y:float,spostata:bool}> nello stesso ordine dei blocchi
     */
    public static function sbroglia(array $blocchi): array
    {
        $posizione = [];
        foreach ($blocchi as $i => $b) {
            $posizione[$i] = (float) $b['y'];
        }

        $ordine = array_keys($blocchi);
        usort($ordine, static fn($a, $b) => $blocchi[$a]['y'] <=> $blocchi[$b]['y']);

        $posati = [];
        foreach ($ordine as $i) {
            $b = $blocchi[$i];
            $y = $posizione[$i];

            for ($giro = 0; $giro < 50; $giro++) {
                $urto = null;
                foreach ($posati as $p) {
                    if (self::siToccano($b, $y, $p['b'], $p['y'])) { $urto = $p; break; }
                }
                if ($urto === null) { break; }

                // Si scende sotto l'ingombro di chi è già lì, RIMETTENDOCI lo
                // spazio che il riquadro si prende sopra la riga: senza quello
                // la nuova posizione ricade nello stesso urto e la spinta gira
                // a vuoto finché non finiscono i tentativi.
                $nuova = $urto['y'] + $urto['b']['alto'] + self::SOPRA + self::ARIA;
                if ($nuova <= $y) {
                    break;                        // non si sale mai: meglio fermi
                }
                $y = $nuova;
            }

            $posizione[$i] = $y;
            $posati[] = ['b' => $b, 'y' => $y];
        }

        $fuori = [];
        foreach ($blocchi as $i => $b) {
            $fuori[$i] = [
                'y'        => $posizione[$i],
                'spostata' => abs($posizione[$i] - (float) $b['y']) > 1.5,
            ];
        }
        return $fuori;
    }

    /**
     * @param array{x:float,largo:float,alto:float,lato:string} $a
     * @param array{x:float,largo:float,alto:float,lato:string} $b
     */
    private static function siToccano(array $a, float $ay, array $b, float $by): bool
    {
        [$a1, $a2] = self::estensione($a);
        [$b1, $b2] = self::estensione($b);
        if ($a2 < $b1 || $b2 < $a1) {
            return false;                        // non si incrociano in orizzontale
        }
        return ($ay - self::SOPRA) < ($by + $b['alto'])
            && ($by - self::SOPRA) < ($ay + $a['alto']);
    }

    /** @param array{x:float,largo:float,lato:string} $b @return array{0:float,1:float} */
    public static function estensione(array $b): array
    {
        return $b['lato'] === 'destra'
            ? [$b['x'], $b['x'] + $b['largo']]
            : [$b['x'] - $b['largo'], $b['x']];
    }
}
