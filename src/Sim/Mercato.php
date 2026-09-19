<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Formazione del prezzo ed esecuzione degli ordini. Modulo puro: niente
 * database, niente stato, niente orologio. È il cuore del gioco e per questo
 * sta qui dentro — si prova con dei numeri, non con una sessione.
 *
 * Lo stato di un mercato è la coppia (piazza, bene) e ha due facce:
 *
 *   OFFERTA  S — quante unità sono comprabili adesso. Se cala, il prezzo
 *                d'acquisto sale come `(S_eq / S)^e`.
 *   DOMANDA  A — quanto la piazza è ancora disposta ad assorbire. Se si
 *                esaurisce, il prezzo di vendita crolla come `(A / A_eq)^z`.
 *
 * La seconda è la variabile che fa esistere il gioco. È lei a dare al mondo un
 * reddito massimo orario finito (docs/DESIGN.md §2.6), ed è lei che impedisce
 * di scaricare mille unità in una piazza sola.
 *
 * **Gli ordini si integrano, non si moltiplicano.** Comprare venti unità non
 * costa venti volte il prezzo della prima: ogni unità che prendi alza il prezzo
 * della successiva. Il conto esatto è l'integrale della curva lungo il tratto
 * che consumi, e ha forma chiusa perché la curva è una potenza. Senza questo,
 * un ordine grosso sarebbe un affare invece che un problema.
 */
final class Mercato
{
    /**
     * @param array{p0:float,d:float,offerta:float,offerta_eq:float,domanda:float,domanda_eq:float,shock:float} $m
     * @param array{e:float,z:float,spread:float,banda_bassa:float,banda_alta:float} $par
     */
    public static function prezzoAcquisto(array $m, array $par): float
    {
        $base = $m['p0'] * $m['d'] * (1 + $m['shock'])
              * ($m['offerta_eq'] / max(1.0, $m['offerta'])) ** $par['e'];

        return self::inBanda($base, $m, $par) * (1 + $par['spread']);
    }

    /** @param array<string,float> $m @param array<string,float> $par */
    public static function prezzoVendita(array $m, array $par): float
    {
        $base = $m['p0'] * $m['d'] * (1 + $m['shock'])
              * (max(0.0, $m['domanda']) / max(1.0, $m['domanda_eq'])) ** $par['z'];

        return self::inBanda($base, $m, $par) * (1 - $par['spread']);
    }

    /**
     * Quanto costa comprarne $q, con l'impatto del proprio ordine già dentro.
     *
     * @param array<string,float> $m @param array<string,float> $par
     * @return array{quantita:int,totale:int,medio:int,offerta_dopo:float}
     */
    public static function costoAcquisto(array $m, array $par, int $q): array
    {
        $q = max(0, min($q, (int) floor($m['offerta'])));
        if ($q === 0) {
            return ['quantita' => 0, 'totale' => 0, 'medio' => 0, 'offerta_dopo' => $m['offerta']];
        }

        $e   = $par['e'];
        $da  = max(1.0, $m['offerta'] - $q);   // il fondo del barile non si raschia
        $a   = max($da, $m['offerta']);
        $k   = $m['p0'] * $m['d'] * (1 + $m['shock']) * $m['offerta_eq'] ** $e;

        // integrale di x^-e fra $da e $a
        $integrale = abs($e - 1.0) < 1e-9
            ? log($a / $da)
            : ($a ** (1 - $e) - $da ** (1 - $e)) / (1 - $e);

        $medio = ($k * $integrale / $q) * (1 + $par['spread']);
        $medio = self::mediaInBanda($medio, $m, $par, +1);

        return [
            'quantita'     => $q,
            'totale'       => (int) round($medio * $q),
            'medio'        => (int) round($medio),
            'offerta_dopo' => $m['offerta'] - $q,
        ];
    }

    /**
     * Quanto rende venderne $q. Non si può vendere più di quanto la piazza
     * assorba: oltre quel punto non c'è nessuno che compra, e fingere un
     * prezzo che tende a zero sarebbe solo un modo elegante di dire la stessa
     * cosa con in più la possibilità di regalare merce per sbaglio.
     *
     * @param array<string,float> $m @param array<string,float> $par
     * @return array{quantita:int,totale:int,medio:int,domanda_dopo:float}
     */
    public static function ricavoVendita(array $m, array $par, int $q): array
    {
        $q = max(0, min($q, (int) floor($m['domanda'])));
        if ($q === 0) {
            return ['quantita' => 0, 'totale' => 0, 'medio' => 0, 'domanda_dopo' => $m['domanda']];
        }

        $z  = $par['z'];
        $a  = $m['domanda'];
        $da = max(0.0, $a - $q);
        $k  = $m['p0'] * $m['d'] * (1 + $m['shock']) / max(1.0, $m['domanda_eq']) ** $z;

        // integrale di x^z fra $da e $a
        $integrale = ($a ** (1 + $z) - $da ** (1 + $z)) / (1 + $z);

        $medio = ($k * $integrale / $q) * (1 - $par['spread']);
        $medio = self::mediaInBanda($medio, $m, $par, -1);

        return [
            'quantita'     => $q,
            'totale'       => (int) round($medio * $q),
            'medio'        => (int) round($medio),
            'domanda_dopo' => $m['domanda'] - $q,
        ];
    }

    /**
     * Il massimo comprabile con quel denaro e quello spazio.
     *
     * Non è una divisione: il prezzo sale mentre compri, quindi la risposta si
     * cerca. Ricerca binaria su un intervallo piccolo — poche decine di passi.
     *
     * @param array<string,float> $m @param array<string,float> $par
     */
    public static function quantoPosso(array $m, array $par, int $contante, int $spazio, int $ingombro): int
    {
        $tetto = min((int) floor($m['offerta']), $ingombro > 0 ? intdiv($spazio, $ingombro) : 0);
        if ($tetto <= 0 || $contante <= 0) {
            return 0;
        }
        if (self::costoAcquisto($m, $par, $tetto)['totale'] <= $contante) {
            return $tetto;
        }

        $basso = 0;
        $alto  = $tetto;
        while ($basso < $alto) {
            $mezzo = intdiv($basso + $alto + 1, 2);
            if (self::costoAcquisto($m, $par, $mezzo)['totale'] <= $contante) {
                $basso = $mezzo;
            } else {
                $alto = $mezzo - 1;
            }
        }
        return $basso;
    }

    // --- Banda -----------------------------------------------------------------

    /**
     * Nessun modello sopravvive agli estremi: il prezzo resta dentro una banda
     * intorno al riferimento, qualunque cosa succeda alle giacenze.
     *
     * @param array<string,float> $m @param array<string,float> $par
     */
    private static function inBanda(float $prezzo, array $m, array $par): float
    {
        $centro = $m['p0'] * $m['d'];
        return max($centro * $par['banda_bassa'], min($centro * $par['banda_alta'], $prezzo));
    }

    /**
     * La banda sul prezzo MEDIO di un ordine. L'integrale non si lascia
     * limitare in forma chiusa, quindi si limita il suo risultato: è la stessa
     * promessa fatta al giocatore, applicata dove si può controllare.
     *
     * @param array<string,float> $m @param array<string,float> $par
     */
    private static function mediaInBanda(float $medio, array $m, array $par, int $verso): float
    {
        $centro = $m['p0'] * $m['d'] * ($verso > 0 ? 1 + $par['spread'] : 1 - $par['spread']);
        return max($centro * $par['banda_bassa'], min($centro * $par['banda_alta'], $medio));
    }
}
