<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Tempi e costi di percorrenza. Modulo puro: niente database, niente stato.
 *
 * **La compressione.** Un minuto reale vale tre minuti di viaggio
 * (`mondo.compressione_viaggio`). È una scelta di giocabilità dichiarata, non
 * un orologio diverso: il tempo del mondo resta quello vero (Clock), si accorcia
 * soltanto la noia dello spostarsi. Senza compressione attraversare Milano coi
 * mezzi costerebbe tre quarti d'ora di attesa vera, e il gioco sarebbe una
 * sala d'aspetto.
 *
 * Con il fattore a 3 i numeri cadono dove servono al bilanciamento del §2.6:
 * il salto fra due piazze vicine sta sotto i dieci minuti, attraversare la
 * città ne costa venti, e la tratta Milano-Roma in treno poco più di due ore.
 *
 * **Le velocità sono d'epoca.** 110 km/h effettivi in treno sono il rapido
 * degli anni ottanta sulle direttrici principali, non l'alta velocità.
 */
final class Viaggio
{
    /** Minuti di viaggio per minuto reale. */
    public const COMPRESSIONE = 3;

    /**
     * mezzo => [ambito, km/h, attesa in minuti reali, costo fisso, costo al km]
     *
     * L'attesa non è compressa: aspettare il treno è aspettare, e mettere in
     * conto un'ora vera per l'aereo è il modo onesto di dire che l'aeroporto
     * è fuori città e che al banco ti chiedono un documento.
     */
    private const MEZZI = [
        'a piedi' => ['citta',  5.0,  0,     0,    0],
        'mezzi'   => ['citta', 14.0,  3,   600,    0],
        'taxi'    => ['citta', 28.0,  2,  2000, 1200],
        'pullman' => ['paese', 70.0, 20,     0,   60],
        'treno'   => ['paese',110.0, 15,     0,   95],
        'aereo'   => ['paese',450.0, 55,     0,  300],
    ];

    /** Sotto questa distanza l'aereo non ha senso e non viene offerto. */
    public const VOLO_KM_MINIMI = 300;

    /**
     * Le opzioni di viaggio per una tratta.
     *
     * @return list<array{mezzo:string,minuti:int,costo:int,km:float,nota:string}>
     *         ordinate dalla più lenta alla più veloce
     */
    public static function opzioni(
        float $km,
        bool $stessaCitta,
        int $compressione = self::COMPRESSIONE,
        int $voloKmMinimi = self::VOLO_KM_MINIMI,
        ?array $mezzoProprio = null,
    ): array {
        $ambito = $stessaCitta ? 'citta' : 'paese';
        $fuori  = [];

        // Il mezzo proprio non sta nel listino: è tuo, e cambia tutto. Porta a
        // destinazione senza aspettare, costa solo benzina, e soprattutto non
        // passa da una stazione. In F4 sarà anche l'unica cosa che un posto di
        // blocco può fermare.
        if ($mezzoProprio !== null) {
            $velocita = (float) ($stessaCitta ? $mezzoProprio['kmh_citta'] : $mezzoProprio['kmh_paese']);
            if ($velocita > 0) {
                $fuori[] = [
                    'mezzo'  => 'auto',
                    'minuti' => self::minuti($km, $velocita, 0, $compressione),
                    'costo'  => (int) round($km * (int) $mezzoProprio['costo_km']),
                    'km'     => round($km, 2),
                    'nota'   => (string) $mezzoProprio['nome'] . ' — la tua. Solo benzina, e nessuno a cui dire dove vai.',
                ];
            }
        }

        foreach (self::MEZZI as $mezzo => [$suo, $velocita, $attesa, $fisso, $alKm]) {
            if ($suo !== $ambito) {
                continue;
            }
            if ($mezzo === 'aereo' && $km < $voloKmMinimi) {
                continue;
            }

            $fuori[] = [
                'mezzo'  => $mezzo,
                'minuti' => self::minuti($km, $velocita, $attesa, $compressione),
                'costo'  => (int) round($fisso + $alKm * $km),
                'km'     => round($km, 2),
                'nota'   => self::nota($mezzo),
            ];
        }

        usort($fuori, static fn(array $a, array $b) => $b['minuti'] <=> $a['minuti']);
        return $fuori;
    }

    /** Una sola opzione, o null se quel mezzo non serve questa tratta. */
    public static function opzione(string $mezzo, float $km, bool $stessaCitta, int $compressione = self::COMPRESSIONE, int $voloKmMinimi = self::VOLO_KM_MINIMI, ?array $mezzoProprio = null): ?array
    {
        foreach (self::opzioni($km, $stessaCitta, $compressione, $voloKmMinimi, $mezzoProprio) as $o) {
            if ($o['mezzo'] === $mezzo) {
                return $o;
            }
        }
        return null;
    }

    private static function minuti(float $km, float $velocita, int $attesa, int $compressione): int
    {
        $viaggio = $km / $velocita * 60.0;
        // Un minuto è il minimo: nessuno spostamento è istantaneo, nemmeno
        // fra due portoni della stessa via.
        return max(1, (int) round($viaggio / max(1, $compressione) + $attesa));
    }

    private static function nota(string $mezzo): string
    {
        return match ($mezzo) {
            'a piedi' => 'Non costa niente e non lascia niente.',
            'mezzi'   => 'Tram e metropolitana. Un biglietto, nessun nome.',
            'taxi'    => 'Veloce e caro. Il tassista la faccia te la guarda.',
            'pullman' => 'Lento, scomodo, economico. Nessuno controlla niente.',
            'treno'   => 'La scelta normale. In stazione però c\'è sempre qualcuno.',
            'aereo'   => 'Il più veloce, e l\'unico dove il tuo nome finisce su un elenco.',
            default   => '',
        };
    }

    /** "2 h 15" / "18 min" — per l'interfaccia. */
    public static function durata(int $minuti): string
    {
        if ($minuti < 60) {
            return $minuti . ' min';
        }
        $ore = intdiv($minuti, 60);
        $resto = $minuti % 60;
        return $resto === 0 ? $ore . ' h' : sprintf('%d h %02d', $ore, $resto);
    }
}
