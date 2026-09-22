<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Sim\Etichette;
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

    /** Quanti nomi si scrivono sotto una città prima di dire «e altri». */
    private const NOMI_MAX = 4;
    /** Oltre questa lunghezza un nome viene troncato: un nome lungo da solo
     *  spinge l'etichetta fuori dalla carta. */
    private const NOME_MAX = 18;
    /** Larghezza media di un carattere, per stimare l'ingombro di un'etichetta.
     *  Non serve precisione: serve sapere da che parte c'è posto. */
    private const CARATTERE = 6.3;
    private const CARATTERE_CITTA = 8.6;

    /**
     * Tutto quello che serve a disegnare, già in coordinate del riquadro.
     *
     * @param int|null $quiCittaId la città dove si trova chi guarda, se c'è
     * @param array<int,list<array{nome:string,io?:bool,nota?:string}>> $gente citta_id => chi c'è
     * @return array{vista:array{w:float,h:float},terra:list<string>,citta:list<array<string,mixed>>,
     *               scala:array{km:int,px:float},km_per_punto:float}
     */
    public static function disegno(?int $quiCittaId = null, array $gente = []): array
    {
        $base = self::$cache ??= self::calcola();

        $citta = [];
        foreach ($base['citta'] as $c) {
            $c['qui']   = $quiCittaId !== null && (int) $c['id'] === $quiCittaId;
            $tutti      = $gente[(int) $c['id']] ?? [];
            $c['nomi']  = array_map(static function (array $g): array {
                $g['nome'] = mb_strlen($g['nome']) > self::NOME_MAX
                    ? mb_substr($g['nome'], 0, self::NOME_MAX - 1) . '…'
                    : $g['nome'];
                return $g;
            }, array_slice($tutti, 0, self::NOMI_MAX));
            $c['altri'] = max(0, count($tutti) - self::NOMI_MAX);
            $citta[]    = $c;
        }

        $citta = self::sbroglia($citta);

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
     * Prepara i blocchi di scritte e li fa districare da `Sim\Etichette`.
     *
     * Qui si sa quanto è larga una scritta e quante righe ha un blocco; il
     * mestiere di non farli accavallare sta nel modulo puro, dove si può
     * provare senza database.
     *
     * @param list<array<string,mixed>> $citta
     * @return list<array<string,mixed>>
     */
    private static function sbroglia(array $citta): array
    {
        $blocchi = [];
        foreach ($citta as $i => $c) {
            $righe = 1 + ($c['nomi'] === [] ? 0 : count($c['nomi']) + ($c['altri'] > 0 ? 1 : 0));
            $citta[$i]['alto']  = $righe * Etichette::RIGA;
            $citta[$i]['largo'] = self::larghezza($c);
            $citta[$i]['lato']  = Etichette::lato((float) $c['x'], $citta[$i]['largo'], self::LARGHEZZA);
            $blocchi[$i] = [
                'x'     => (float) $c['x'],
                'y'     => (float) $c['y'],
                'largo' => $citta[$i]['largo'],
                'alto'  => $citta[$i]['alto'],
                'lato'  => $citta[$i]['lato'],
            ];
        }

        foreach (Etichette::sbroglia($blocchi) as $i => $dove) {
            $citta[$i]['etichetta'] = $dove['y'];
            $citta[$i]['spostata']  = $dove['spostata'];
        }
        return $citta;
    }

    /** Quanto è larga, in punti, la scritta più lunga del blocco. */
    private static function larghezza(array $c): float
    {
        $largo = mb_strlen((string) $c['nome']) * self::CARATTERE_CITTA;
        foreach ($c['nomi'] as $g) {
            // «○ » davanti, e la nota dopo, contano nell'ingombro.
            $testo = 2 + mb_strlen((string) $g['nome'])
                   + (empty($g['nota']) ? 0 : 3 + mb_strlen((string) $g['nota']));
            $largo = max($largo, $testo * self::CARATTERE);
        }
        return $largo + 16.0;
    }

    /**
     * Chi si può vedere sulla carta, dal punto di vista di un giocatore.
     *
     * **Non tutti.** Sapere dove sta la gente è informazione tattica, e in
     * questo gioco l'informazione su quello che succede altrove si paga — è il
     * mestiere del basista, ed è lo stesso motivo per cui la chiacchiera è di
     * piazza (§13.3). Si vede quello che si saprebbe comunque:
     *
     *   - te stesso, sempre;
     *   - chi è nella tua città: lo incroci per strada;
     *   - la tua batteria: sono i tuoi, ci si tiene in contatto;
     *   - chi hai fatto spiare: la spia serve esattamente a questo.
     *
     * Per tutti gli altri resta il conteggio, che è atmosfera e non vantaggio.
     *
     * @param array<string,mixed> $p il personaggio che guarda
     * @return array<int,list<array{nome:string,io?:bool,nota?:string}>>
     */
    public static function genteVisibile(array $p): array
    {
        $io = (int) $p['id'];
        $mia = (int) (Mondo::piazza((int) $p['piazza_id'])['citta_id'] ?? 0);
        $batteria = $p['batteria_id'] === null ? 0 : (int) $p['batteria_id'];

        $righe = Database::all(
            "SELECT p.id, u.username, z.citta_id, p.arrivo_at, p.batteria_id,
                    (SELECT COUNT(*) FROM spie s
                      WHERE s.padrone_id = ? AND s.bersaglio_id = p.id AND s.esito = 'dentro') AS spiato
               FROM personaggi p
               JOIN users u ON u.id = p.user_id
               JOIN piazze z ON z.id = p.piazza_id
              WHERE u.status = 'active'
              ORDER BY u.username",
            [$io]
        );

        $fuori = [];
        foreach ($righe as $r) {
            $suo = (int) $r['citta_id'];
            $sono = (int) $r['id'] === $io;
            $visibile = $sono
                || $suo === $mia
                || ($batteria > 0 && (int) ($r['batteria_id'] ?? 0) === $batteria)
                || (int) $r['spiato'] > 0;
            if (!$visibile) {
                continue;
            }
            $fuori[$suo][] = [
                'nome' => (string) $r['username'],
                'io'   => $sono,
                'nota' => $r['arrivo_at'] !== null ? 'in viaggio' : null,
            ];
        }

        // Il proprio nome per primo: è quello che si cerca per primo.
        foreach ($fuori as &$lista) {
            usort($lista, static fn($a, $b) => ($b['io'] ?? false) <=> ($a['io'] ?? false));
        }
        unset($lista);

        return $fuori;
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
