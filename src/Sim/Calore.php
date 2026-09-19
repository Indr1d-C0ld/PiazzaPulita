<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Il calore: quanto scotti tu, e quanto scotta la piazza. Modulo puro.
 *
 * **Il rischio è una conseguenza, non un dado.** Nel gioco del 1984 il
 * poliziotto compariva a caso. Nel door BBS del 1993 arrivava se superavi una
 * di quattro soglie misurabili — un milione movimentato, cento unità in un
 * colpo, dieci milioni addosso, mille unità addosso — ed è l'idea migliore
 * della serie, che nessuna versione successiva ha ripreso. Qui quelle soglie
 * diventano una funzione continua.
 *
 * **L'accumulo è superlineare**: `ΔH = (valore/soglia)^1.5`. Un'operazione da
 * centomila lire vale tre centesimi di grado; una da cinque milioni ne vale
 * undici. Operare piccolo è quasi gratis, il colpo grosso si paga — e il
 * giocatore lo sa PRIMA, perché il calore si vede sempre e il conto è scritto.
 *
 * **Il decadimento è esponenziale**, non lineare: dimezza ogni N ore. Così
 * stare fermi funziona davvero, e funziona sempre allo stesso modo qualunque
 * sia il punto di partenza.
 */
final class Calore
{
    /**
     * Quanto scalda un'operazione di quel valore su quella merce.
     *
     * Il rischio della merce moltiplica: muovere sigarette per un milione non è
     * come muovere eroina per un milione, e non dev'essere lo stesso numero.
     */
    public static function daOperazione(int $valore, int $soglia, float $esponente, int $rischioBene): float
    {
        if ($valore <= 0 || $soglia <= 0) {
            return 0.0;
        }
        return ($valore / $soglia) ** $esponente * (1.0 + $rischioBene / 100.0);
    }

    /** Il calore dopo $secondi di decadimento esponenziale. */
    public static function decaduto(float $calore, int $secondi, float $dimezzamentoOre): float
    {
        if ($calore <= 0 || $secondi <= 0 || $dimezzamentoOre <= 0) {
            return max(0.0, $calore);
        }
        $c = $calore * 2 ** (-($secondi / 3600.0) / $dimezzamentoOre);
        // Sotto il centesimo di grado non è più niente: si azzera, così le
        // righe tornano pulite e i confronti non inseguono infinitesimi.
        return $c < 0.01 ? 0.0 : $c;
    }

    /**
     * Probabilità che scatti un controllo su un'azione.
     *
     * Tre fattori che si moltiplicano, perché sono davvero indipendenti: quanta
     * polizia c'è in quel quartiere, quanto scotta la piazza in questo momento,
     * e quanto scotti tu. Il profilo criminale — «pubblico nemico numero N» —
     * entra come moltiplicatore permanente: chi ha già dei precedenti viene
     * fermato più spesso, e questo non scende col tempo.
     */
    public static function rischioControllo(
        float $base,
        int $polizia,
        float $calorePiazza,
        float $calorePersonale,
        int $profilo,
    ): float {
        $p = $base
           * ($polizia / 50.0)
           * (1.0 + $calorePiazza / 40.0)
           * (1.0 + $calorePersonale / 80.0)
           * (1.0 + $profilo * 0.25);

        return max(0.0, min(0.85, $p));
    }

    /**
     * Probabilità di un posto di blocco su una tratta.
     *
     * Conta quanto sei vistoso (un furgone si vede da lontano), quanto porti, e
     * quanto scotti. A piedi o in treno non c'è posto di blocco che tenga: è il
     * prezzo nascosto del mezzo proprio, che per tutto il resto conviene.
     */
    public static function rischioBlocco(
        float $base,
        int $vistoso,
        int $ingombroPortato,
        float $calorePersonale,
        int $profilo,
        bool $mezzoProprio,
    ): float {
        if (!$mezzoProprio || $ingombroPortato <= 0) {
            return 0.0;
        }
        $p = $base
           * (1.0 + $vistoso / 4.0)
           * (1.0 + $ingombroPortato / 400.0)
           * (1.0 + $calorePersonale / 80.0)
           * (1.0 + $profilo * 0.25);

        return max(0.0, min(0.75, $p));
    }

    /** Prove raccolte in un certo tempo, a un certo calore. */
    public static function prove(float $calore, int $secondi, float $provePerOraA100): float
    {
        if ($calore <= 0 || $secondi <= 0) {
            return 0.0;
        }
        return $provePerOraA100 * ($calore / 100.0) * ($secondi / 3600.0);
    }

    /** Come si dice a parole quello che il numero dice in gradi. */
    public static function aParole(float $calore): string
    {
        return match (true) {
            $calore < 5   => 'nessuno ti guarda',
            $calore < 15  => 'qualcuno ti ha notato',
            $calore < 40  => 'si parla di te',
            $calore < 90  => 'sei sul taccuino di qualcuno',
            $calore < 200 => 'ti stanno addosso',
            default       => 'sei un problema per qualcuno che non ha fretta',
        };
    }

    /** E lo stesso per il rischio, che in percentuale non dice niente a nessuno. */
    public static function rischioAParole(float $p): string
    {
        return match (true) {
            $p < 0.01 => 'trascurabile',
            $p < 0.04 => 'basso',
            $p < 0.10 => 'concreto',
            $p < 0.25 => 'alto',
            default   => 'stai tirando la corda',
        };
    }
}
