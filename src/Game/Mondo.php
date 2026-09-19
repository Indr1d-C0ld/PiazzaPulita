<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Geo;
use App\Sim\Viaggio;

/**
 * La geografia: nove città, quarantatré piazze.
 *
 * Città e piazze non cambiano mai durante una richiesta, quindi si leggono una
 * volta sola e restano in memoria. Sono poche decine di righe: tenerle tutte
 * costa nulla e risparmia una query per ogni distanza calcolata.
 */
final class Mondo
{
    /** @var array<int,array<string,mixed>>|null */
    private static ?array $citta = null;
    /** @var array<int,array<string,mixed>>|null */
    private static ?array $piazze = null;

    // --- Lettura -------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public static function citta(): array
    {
        if (self::$citta !== null) {
            return self::$citta;
        }
        $fuori = [];
        foreach (Database::all('SELECT * FROM citta ORDER BY lat DESC') as $r) {
            $r['lat'] = (float) $r['lat'];
            $r['lon'] = (float) $r['lon'];
            $fuori[(int) $r['id']] = $r;
        }
        return self::$citta = $fuori;
    }

    /** @return array<int,array<string,mixed>> */
    public static function piazze(): array
    {
        if (self::$piazze !== null) {
            return self::$piazze;
        }
        $fuori = [];
        foreach (Database::all('SELECT * FROM piazze ORDER BY citta_id, nome') as $r) {
            $r['lat'] = (float) $r['lat'];
            $r['lon'] = (float) $r['lon'];
            $r['citta_id'] = (int) $r['citta_id'];
            $r['polizia']  = (int) $r['polizia'];
            $fuori[(int) $r['id']] = $r;
        }
        return self::$piazze = $fuori;
    }

    /** @return array<string,mixed>|null */
    public static function piazza(int $id): ?array
    {
        return self::piazze()[$id] ?? null;
    }

    /** @return array<string,mixed>|null */
    public static function cittaDi(int $piazzaId): ?array
    {
        $p = self::piazza($piazzaId);
        return $p === null ? null : (self::citta()[$p['citta_id']] ?? null);
    }

    /** @return list<array<string,mixed>> le piazze di una città */
    public static function piazzeDi(int $cittaId): array
    {
        return array_values(array_filter(self::piazze(), static fn($p) => $p['citta_id'] === $cittaId));
    }

    public static function esiste(): bool
    {
        try {
            return self::citta() !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    // --- Distanze e viaggi ---------------------------------------------------

    /** Chilometri di percorso (non in linea d'aria) fra due piazze. */
    public static function kmFra(int $daId, int $aId): float
    {
        $a = self::piazza($daId);
        $b = self::piazza($aId);
        if ($a === null || $b === null) {
            return 0.0;
        }
        $aria = Geo::distanzaKm($a['lat'], $a['lon'], $b['lat'], $b['lon']);
        return Geo::percorsoKm($aria, (float) GameConfig::get('mondo.fattore_percorso', 1.25));
    }

    public static function stessaCitta(int $daId, int $aId): bool
    {
        $a = self::piazza($daId);
        $b = self::piazza($aId);
        return $a !== null && $b !== null && $a['citta_id'] === $b['citta_id'];
    }

    /**
     * @param array<string,mixed>|null $mezzoProprio il veicolo posseduto, se c'è
     * @return list<array{mezzo:string,minuti:int,costo:int,km:float,nota:string}>
     */
    public static function opzioniFra(int $daId, int $aId, ?array $mezzoProprio = null): array
    {
        if ($daId === $aId) {
            return [];
        }
        return Viaggio::opzioni(
            self::kmFra($daId, $aId),
            self::stessaCitta($daId, $aId),
            GameConfig::int('mondo.compressione_viaggio', Viaggio::COMPRESSIONE),
            GameConfig::int('mondo.volo_km_minimi', Viaggio::VOLO_KM_MINIMI),
            $mezzoProprio,
        );
    }

    /** @return array{mezzo:string,minuti:int,costo:int,km:float,nota:string}|null */
    public static function opzione(string $mezzo, int $daId, int $aId, ?array $mezzoProprio = null): ?array
    {
        foreach (self::opzioniFra($daId, $aId, $mezzoProprio) as $o) {
            if ($o['mezzo'] === $mezzo) {
                return $o;
            }
        }
        return null;
    }

    // --- Semina --------------------------------------------------------------

    /**
     * Carica il mondo dal file di semina. Idempotente: le righe esistenti
     * vengono riallineate, non duplicate — così correggere una coordinata
     * sbagliata è una modifica al file e un comando, non una migrazione.
     *
     * Qui l'INSERT ... ON DUPLICATE KEY resta, ma sappiamo che brucia un valore
     * di auto-incremento a ogni giro anche quando non inserisce niente. Con
     * SMALLINT e sessanta righe servono più di mille riseminature per arrivare
     * a 65.535, quindi si convive; su `beni`, che è TINYINT, non si poteva e
     * infatti è esploso in taratura (vedi SeminaMercato::semina).
     *
     * @return array{citta:int,piazze:int}
     */
    public static function semina(string $fileSeme): array
    {
        /** @var array{citta:list<array<string,mixed>>,piazze:array<string,list<array<int,mixed>>>} $dati */
        $dati = require $fileSeme;

        $nCitta = $nPiazze = 0;

        foreach ($dati['citta'] as $c) {
            Database::run(
                'INSERT INTO citta (codice, nome, lat, lon, carattere, aeroporto, nota)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE nome = VALUES(nome), lat = VALUES(lat), lon = VALUES(lon),
                     carattere = VALUES(carattere), aeroporto = VALUES(aeroporto), nota = VALUES(nota)',
                [$c['codice'], $c['nome'], $c['lat'], $c['lon'], $c['carattere'], $c['aeroporto'], $c['nota']]
            );
            $nCitta++;
        }

        $idPerCodice = [];
        foreach (Database::all('SELECT id, codice FROM citta') as $r) {
            $idPerCodice[(string) $r['codice']] = (int) $r['id'];
        }

        foreach ($dati['piazze'] as $codiceCitta => $elenco) {
            $cittaId = $idPerCodice[$codiceCitta] ?? null;
            if ($cittaId === null) {
                throw new \RuntimeException("Piazze per una città che non esiste: {$codiceCitta}");
            }
            foreach ($elenco as [$codice, $nome, $lat, $lon, $tipo, $polizia]) {
                Database::run(
                    'INSERT INTO piazze (citta_id, codice, nome, lat, lon, tipo, polizia)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE citta_id = VALUES(citta_id), nome = VALUES(nome),
                         lat = VALUES(lat), lon = VALUES(lon), tipo = VALUES(tipo), polizia = VALUES(polizia)',
                    [$cittaId, $codice, $nome, $lat, $lon, $tipo, $polizia]
                );
                $nPiazze++;
            }
        }

        self::$citta = self::$piazze = null;
        return ['citta' => $nCitta, 'piazze' => $nPiazze];
    }
}
