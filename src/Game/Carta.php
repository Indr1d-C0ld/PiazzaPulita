<?php

declare(strict_types=1);

namespace App\Game;

use App\Sim\Geo;

/**
 * La cartina: dalle coordinate vere ai punti di una carta disegnata.
 *
 * **Perché una carta e non più uno schema di rete.** Fino a F7 la mappa era un
 * diagramma da orario ferroviario: onesto, perché una costa disegnata male è
 * peggio di nessuna costa. Adesso la costa non è disegnata male — viene da
 * Natural Earth (pubblico dominio), semplificata una volta sola in
 * `db/geo/italia.json` — e allora tanto vale che il mondo abbia la faccia che
 * gli spetta: una cartina stampata, come quelle piegate nel cruscotto.
 *
 * Qui dentro non c'è niente di grafico: solo la proiezione da gradi a punti e
 * il riquadro che contiene tutto. Il disegno sta nella vista, dove deve stare.
 */
final class Carta
{
    /** Il riquadro del disegno, in unità SVG. */
    public const LARGHEZZA = 760.0;
    public const ALTEZZA   = 1000.0;
    private const MARGINE  = 34.0;

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /**
     * Tutto quello che serve a disegnare, già in coordinate del riquadro.
     *
     * @param int|null $quiCittaId la città dove si trova chi guarda, se c'è
     * @return array{vista:array{w:float,h:float},terra:list<string>,citta:list<array<string,mixed>>,
     *               scala:array{km:int,px:float},km_per_punto:float}
     */
    public static function disegno(?int $quiCittaId = null): array
    {
        $base = self::$cache ??= self::calcola();

        $citta = [];
        foreach ($base['citta'] as $c) {
            $c['qui'] = $quiCittaId !== null && (int) $c['id'] === $quiCittaId;
            $citta[] = $c;
        }

        // NON `$base + [...]`: l'unione di array in PHP tiene la chiave di
        // SINISTRA, quindi la lista appena costruita verrebbe buttata via e il
        // segnaposto «sei qui» non comparirebbe mai. Si assegna, e si vede.
        $fuori = $base;
        $fuori['citta'] = $citta;
        return $fuori;
    }

    /** @return array<string,mixed> */
    private static function calcola(): array
    {
        $anelli = self::costa();

        // Si proietta tutto rispetto al centro geografico dell'Italia: la
        // correzione del coseno tiene le proporzioni oneste, che su una carta
        // si vedono subito se mancano (lo Stivale diventa grasso).
        $latRif = 42.0; $lonRif = 12.5;
        $proietta = static function (float $lat, float $lon) use ($latRif, $lonRif): array {
            [$x, $y] = Geo::proietta($lat, $lon, $latRif, $lonRif);
            return [$x, -$y];                 // il nord sta in alto, la y cresce in giù
        };

        $punti = [];
        foreach ($anelli as $anello) {
            $p = [];
            foreach ($anello as [$lon, $lat]) {
                $p[] = $proietta((float) $lat, (float) $lon);
            }
            $punti[] = $p;
        }

        // Il riquadro: si prende l'estensione vera e la si porta dentro il
        // rettangolo del disegno, con lo stesso fattore sulle due assi.
        $xs = []; $ys = [];
        foreach ($punti as $p) {
            foreach ($p as [$x, $y]) { $xs[] = $x; $ys[] = $y; }
        }
        $minX = min($xs); $maxX = max($xs); $minY = min($ys); $maxY = max($ys);
        $scalaX = (self::LARGHEZZA - 2 * self::MARGINE) / max(1e-6, $maxX - $minX);
        $scalaY = (self::ALTEZZA   - 2 * self::MARGINE) / max(1e-6, $maxY - $minY);
        $k = min($scalaX, $scalaY);
        $offX = (self::LARGHEZZA - ($maxX - $minX) * $k) / 2 - $minX * $k;
        $offY = (self::ALTEZZA   - ($maxY - $minY) * $k) / 2 - $minY * $k;

        $verso = static fn(array $xy): array => [
            round($xy[0] * $k + $offX, 1),
            round($xy[1] * $k + $offY, 1),
        ];

        $terra = [];
        foreach ($punti as $p) {
            $d = '';
            foreach ($p as $i => $xy) {
                [$x, $y] = $verso($xy);
                $d .= ($i === 0 ? 'M' : 'L') . $x . ' ' . $y;
            }
            $terra[] = $d . 'Z';
        }

        $citta = [];
        foreach (Mondo::citta() as $id => $c) {
            [$x, $y] = $verso($proietta((float) $c['lat'], (float) $c['lon']));
            $citta[] = [
                'id'        => (int) $id,
                'codice'    => (string) $c['codice'],
                'nome'      => (string) $c['nome'],
                'carattere' => (string) $c['carattere'],
                'x'         => $x,
                'y'         => $y,
                'piazze'    => count(Mondo::piazzeDi((int) $id)),
                // Su quale lato scrivere il nome: verso il mare aperto, così
                // l'etichetta non finisce sopra la terra o sopra un'altra città.
                'lato'      => $x > self::LARGHEZZA * 0.52 ? 'destra' : 'sinistra',
            ];
        }

        // Quanti punti fa un chilometro: serve alla scala grafica.
        $kmPerPunto = 1.0 / $k;
        $kmScala = 200;

        return [
            'vista'        => ['w' => self::LARGHEZZA, 'h' => self::ALTEZZA],
            'terra'        => $terra,
            'citta'        => $citta,
            'km_per_punto' => $kmPerPunto,
            'scala'        => ['km' => $kmScala, 'px' => round($kmScala / $kmPerPunto, 1)],
        ];
    }

    /**
     * Il profilo delle coste, letto una volta sola.
     *
     * @return list<list<array{0:float,1:float}>>
     */
    private static function costa(): array
    {
        $file = ($GLOBALS['__project_root'] ?? dirname(__DIR__, 2)) . '/db/geo/italia.json';
        if (!is_readable($file)) {
            logger('cartina: manca db/geo/italia.json, la carta resterà senza coste', 'warning');
            return [];
        }
        $dati = json_decode((string) file_get_contents($file), true);
        return is_array($dati['anelli'] ?? null) ? $dati['anelli'] : [];
    }
}
