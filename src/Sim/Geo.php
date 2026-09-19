<?php

declare(strict_types=1);

namespace App\Sim;

/** Distanze sulla superficie terrestre. Modulo puro: niente database, niente stato. */
final class Geo
{
    /** Raggio medio terrestre, in chilometri. */
    private const RAGGIO_KM = 6371.0;

    /**
     * Distanza in linea d'aria fra due punti (formula dell'emisenoverso).
     *
     * L'emisenoverso e non il teorema di Pitagora sulle differenze di grado:
     * fra Milano e Palermo un grado di longitudine vale 9 km meno che al nord,
     * e ignorarlo sbaglia la tratta più lunga del gioco di quasi il 4 %.
     */
    public static function distanzaKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $la1 = deg2rad($lat1);
        $la2 = deg2rad($lat2);
        $dLa = $la2 - $la1;
        $dLo = deg2rad($lon2 - $lon1);

        $h = sin($dLa / 2) ** 2 + cos($la1) * cos($la2) * sin($dLo / 2) ** 2;

        return 2 * self::RAGGIO_KM * asin(min(1.0, sqrt($h)));
    }

    /**
     * Distanza effettivamente percorsa, che non è mai quella in linea d'aria:
     * strade e binari girano intorno alle cose. Il fattore è in `game_config`
     * (`mondo.fattore_percorso`) perché è una manopola di bilanciamento, non
     * una costante fisica.
     */
    public static function percorsoKm(float $lineaAria, float $fattore = 1.25): float
    {
        return $lineaAria * $fattore;
    }

    /**
     * Proiezione equirettangolare centrata su una latitudine di riferimento,
     * per disegnare la mappa. Restituisce coordinate in chilometri rispetto al
     * punto dato, con la x già corretta per la convergenza dei meridiani.
     *
     * @return array{0:float,1:float} [x verso est, y verso nord]
     */
    public static function proietta(float $lat, float $lon, float $latRif, float $lonRif): array
    {
        $x = deg2rad($lon - $lonRif) * cos(deg2rad($latRif)) * self::RAGGIO_KM;
        $y = deg2rad($lat - $latRif) * self::RAGGIO_KM;
        return [$x, $y];
    }
}
