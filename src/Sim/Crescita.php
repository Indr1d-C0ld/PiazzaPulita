<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Attributi e reputazione. Modulo puro.
 *
 * **Si cresce con l'uso, non spendendo punti.** Si diventa bravi a trattare
 * trattando, non scegliendolo da un elenco alla creazione del personaggio. È
 * una scelta di progettazione precisa: in un gioco dove il tempo è vero e il
 * mondo non riparte, un albero di talenti da compilare a freddo è una decisione
 * presa quando non si sa ancora niente — cioè la peggiore possibile.
 *
 * **I rendimenti calano.** `Δ = k / (1 + attuale/scala)`: da zero si sale in
 * fretta, da settanta quasi più. Così i primi giorni si sente la differenza
 * ogni volta, e nessuno arriva a cento per sfinimento.
 */
final class Crescita
{
    public const MASSIMO = 100.0;

    /** Quanto cresce un attributo per un'azione di peso $peso (1 = azione normale). */
    public static function passo(float $attuale, float $k, float $scala, float $peso = 1.0): float
    {
        if ($attuale >= self::MASSIMO) {
            return 0.0;
        }
        $d = $k * $peso / (1.0 + $attuale / max(1.0, $scala));
        return min($d, self::MASSIMO - $attuale);
    }

    /**
     * Il peso di un'operazione, per gli attributi che crescono col valore.
     *
     * Logaritmico, non lineare: vendere per dieci milioni insegna più che
     * venderne per uno, ma non dieci volte tanto. Altrimenti bastarebbe un
     * colpo grosso per diventare maestri.
     */
    public static function pesoValore(int $valore, int $riferimento = 500_000): float
    {
        if ($valore <= 0) {
            return 0.0;
        }
        return max(0.1, min(4.0, log10(1.0 + $valore / max(1, $riferimento)) * 2.0));
    }

    // --- Effetti degli attributi -------------------------------------------

    /** Lo spread di piazza, stretto dalla trattativa. A cento si paga il 40 % in meno. */
    public static function spread(float $base, float $trattativa): float
    {
        return $base * (1.0 - min(0.40, $trattativa / 250.0));
    }

    /** Il sangue freddo abbassa il rischio di un controllo. A cento, dimezzato. */
    public static function rischioConSangueFreddo(float $rischio, float $sangueFreddo): float
    {
        return $rischio * (1.0 - min(0.50, $sangueFreddo / 200.0));
    }

    /** Quanti uomini si reggono. */
    public static function uominiRetti(float $organizzazione, int $base, int $perGrado): int
    {
        return $base + (int) floor($organizzazione / max(1, $perGrado));
    }

    /** Il credito allarga il prestito e lima l'interesse. */
    public static function prestitoBase(int $base, float $credito): int
    {
        return (int) round($base * (1.0 + $credito / 100.0));
    }

    public static function interesse(float $base, float $credito): float
    {
        return $base * (1.0 - min(0.25, $credito / 400.0));
    }

    /** Come si dice a parole un attributo, che in decimi non dice niente. */
    public static function aParole(float $v): string
    {
        return match (true) {
            $v < 5  => 'nessuna',
            $v < 20 => 'appena accennata',
            $v < 40 => 'discreta',
            $v < 60 => 'buona',
            $v < 80 => 'notevole',
            default => 'da maestro',
        };
    }

    /** E la reputazione, che è la cosa che gli altri vedono di te. */
    public static function rispettoAParole(float $v): string
    {
        return match (true) {
            $v < 10 => 'non ti conosce nessuno',
            $v < 30 => 'qualcuno sa chi sei',
            $v < 55 => 'la tua parola vale',
            $v < 80 => 'ti si viene a cercare',
            default => 'si fa il tuo nome per darsi un tono',
        };
    }

    public static function timoreAParole(float $v): string
    {
        return match (true) {
            $v < 10 => 'nessuno ha paura di te',
            $v < 30 => 'meglio non pestarti i piedi',
            $v < 55 => 'la gente si fa da parte',
            $v < 80 => 'nessuno alza la voce con te',
            default => 'basta il nome',
        };
    }
}
