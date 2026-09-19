<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Il respiro del mercato: come giacenza e assorbimento tornano all'equilibrio
 * quando nessuno li tocca, e come arrivano i carichi.
 *
 * **Il ritorno è esponenziale, non lineare.** `S + r(S*-S)dt` è instabile se il
 * passo è grande: con un server fermo mezza giornata il valore scavalca
 * l'equilibrio e oscilla. La forma esponenziale è la soluzione esatta della
 * stessa equazione e sta ferma per qualunque intervallo — cosa che serve, visto
 * che l'avanzamento è pigro e l'intervallo lo decide il giocatore arrivando.
 *
 * **I carichi sono a grumi.** Il rifornimento medio lo fa già il ritorno
 * all'equilibrio: i carichi ci mettono sopra l'irregolarità, ed è quella a
 * creare l'occasione. Una piazza appena rifornita ha prezzi bassi per qualche
 * ora, e chi lo sa per primo guadagna — è il motivo per cui in questo gioco
 * l'informazione vale denaro.
 *
 * Sono deterministici per (piazza, bene, ora): battito e avanzamento pigro
 * calcolano lo stesso carico nella stessa ora, o le giacenze divergerebbero a
 * seconda di chi guarda.
 */
final class Rifornimento
{
    /** Probabilità che in una data ora arrivi un carico. */
    private const PROB_CARICO = 0.12;
    /** Ampiezza del carico, in frazione della giacenza di equilibrio. */
    private const AMPIEZZA_MIN = 0.30;
    private const AMPIEZZA_MAX = 0.90;

    /**
     * Avanza lo stato di un mercato di $secondi.
     *
     * @param array{offerta:float,offerta_eq:float,domanda:float,domanda_eq:float,shock:float} $m
     * @return array{offerta:float,domanda:float,shock:float,carichi:int}
     */
    public static function avanza(
        array $m,
        int $secondi,
        int $piazzaId,
        int $beneId,
        int $seme,
        int $oraInizio,
        float $oreRifornimento,
        float $oreAssorbimento,
        float $shockDecadimentoOre = 4.0,
    ): array {
        if ($secondi <= 0) {
            return ['offerta' => $m['offerta'], 'domanda' => $m['domanda'], 'shock' => $m['shock'], 'carichi' => 0];
        }

        $ore = $secondi / 3600.0;

        // Ritorno esponenziale all'equilibrio: X(t) = X* + (X0 - X*)·2^(-t/T)
        $offerta = $m['offerta_eq'] + ($m['offerta'] - $m['offerta_eq']) * 2 ** (-$ore / max(0.1, $oreRifornimento));
        $domanda = $m['domanda_eq'] + ($m['domanda'] - $m['domanda_eq']) * 2 ** (-$ore / max(0.1, $oreAssorbimento));

        // I carichi: una prova per ogni ora intera attraversata.
        $carichi = 0;
        $oraDa = intdiv($oraInizio, 3600);
        $oraA  = intdiv($oraInizio + $secondi, 3600);
        for ($h = $oraDa + 1; $h <= $oraA; $h++) {
            $rng = Rng::da($seme, "carico:{$piazzaId}:{$beneId}:{$h}");
            if ($rng->reale() >= self::PROB_CARICO) {
                continue;
            }
            $offerta += $m['offerta_eq'] * $rng->fra(self::AMPIEZZA_MIN, self::AMPIEZZA_MAX);
            $carichi++;
        }

        $shock = $m['shock'] * 2 ** (-$ore / max(0.1, $shockDecadimentoOre));
        if (abs($shock) < 0.002) {
            $shock = 0.0;
        }

        return [
            'offerta' => max(0.0, $offerta),
            'domanda' => max(0.0, $domanda),
            'shock'   => $shock,
            'carichi' => $carichi,
        ];
    }
}
