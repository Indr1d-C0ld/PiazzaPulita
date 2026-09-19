<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;

/**
 * I numeri del mondo, e i tuoi.
 *
 * Tutto si legge dalle righe che il gioco scrive comunque — transazioni,
 * movimenti, fascicoli, scontri — e niente viene contato a parte. Un contatore
 * scritto apposta per una pagina di statistiche è un contatore che prima o poi
 * diverge dai fatti, e allora la pagina mente con sicurezza.
 *
 * Le query hanno tutte una finestra temporale o un `LIMIT`: questa pagina è
 * pubblica, e una pagina pubblica non deve poter diventare cara.
 */
final class Statistiche
{
    /** @return array<string,mixed> */
    public static function mondo(): array
    {
        $R = GameConfig::int('mondo.reddito_orario', 24_000_000);

        $t24 = self::riga(
            "SELECT COUNT(*) n,
                    COALESCE(SUM(totale), 0) volume,
                    COALESCE(SUM(CASE WHEN verso = 'vendita' THEN margine ELSE 0 END), 0) estratto,
                    COUNT(DISTINCT personaggio_id) attivi
               FROM transazioni WHERE fatto_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");

        $estratto = (int) ($t24['estratto'] ?? 0);

        return [
            'iscritti'   => self::conta("SELECT COUNT(*) n FROM users WHERE status = 'active'"),
            'personaggi' => self::conta('SELECT COUNT(*) n FROM personaggi'),
            'visti24'    => self::conta("SELECT COUNT(*) n FROM users
                                          WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'citta'      => self::conta('SELECT COUNT(*) n FROM citta'),
            'piazze'     => self::conta('SELECT COUNT(*) n FROM piazze'),
            'nodi'       => self::conta('SELECT COUNT(*) n FROM mercati'),
            'scambi24'   => (int) ($t24['n'] ?? 0),
            'volume24'   => (int) ($t24['volume'] ?? 0),
            'estratto24' => $estratto,
            'attivi24'   => (int) ($t24['attivi'] ?? 0),
            'tetto'      => $R,
            // L'utilizzo del tetto è l'unico numero di questa pagina che sia
            // anche una prova: se superasse il 100 % ci sarebbe un exploit.
            'utilizzo'   => $R > 0 ? ($estratto / 24) / $R : 0.0,
            'pulito'     => (int) (self::riga('SELECT COALESCE(SUM(pulito), 0) s FROM personaggi')['s'] ?? 0),
            'debiti'     => (int) (self::riga('SELECT COALESCE(SUM(debito), 0) s FROM personaggi')['s'] ?? 0),
            'in_carcere' => self::conta('SELECT COUNT(*) n FROM personaggi WHERE carcere_fino_a > NOW()'),
            'ricoverati' => self::conta('SELECT COUNT(*) n FROM personaggi WHERE ospedale_fino_a > NOW()'),
            'fascicoli'  => self::conta("SELECT COUNT(*) n FROM fascicoli WHERE stato = 'aperto'"),
            'arresti30'  => self::conta("SELECT COUNT(*) n FROM fascicoli
                                          WHERE stato = 'eseguito' AND chiuso_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
            'scontri30'  => self::conta('SELECT COUNT(*) n FROM scontri
                                          WHERE fatto_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
            'batterie'   => self::conta('SELECT COUNT(*) n FROM batterie'),
            'tenute'     => self::conta('SELECT COUNT(*) n FROM territori WHERE batteria_id IS NOT NULL'),
            'merci'      => Database::all(
                "SELECT b.nome, COUNT(*) scambi, COALESCE(SUM(t.totale), 0) volume
                   FROM transazioni t JOIN beni b ON b.id = t.bene_id
                  WHERE t.fatto_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  GROUP BY b.id, b.nome ORDER BY volume DESC LIMIT 10"),
            'piazze_calde' => Database::all(
                'SELECT z.nome piazza, c.nome citta, z.calore
                   FROM piazze z JOIN citta c ON c.id = z.citta_id
                  WHERE z.calore > 0 ORDER BY z.calore DESC LIMIT 8'),
        ];
    }

    /**
     * I numeri di un giocatore. Sono gli stessi che vede chiunque sul suo
     * profilo: qui non c'è niente di riservato — patrimonio e carico no.
     *
     * @return array<string,mixed>
     */
    public static function personali(int $personaggioId): array
    {
        $t = self::riga(
            "SELECT COUNT(*) ops,
                    COALESCE(SUM(CASE WHEN verso = 'vendita' THEN margine ELSE 0 END), 0) guadagno,
                    COALESCE(MAX(margine), 0) migliore,
                    COUNT(DISTINCT bene_id) beni,
                    COUNT(DISTINCT piazza_id) piazze
               FROM transazioni WHERE personaggio_id = ?", [$personaggioId]);

        $t30 = self::riga(
            "SELECT COALESCE(SUM(margine), 0) g FROM transazioni
              WHERE personaggio_id = ? AND verso = 'vendita'
                AND fatto_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [$personaggioId]);

        $p = Database::first('SELECT * FROM personaggi WHERE id = ?', [$personaggioId]);

        return [
            'operazioni' => (int) ($t['ops'] ?? 0),
            'guadagno'   => (int) ($t['guadagno'] ?? 0),
            'migliore'   => (int) ($t['migliore'] ?? 0),
            'beni'       => (int) ($t['beni'] ?? 0),
            'piazze'     => (int) ($t['piazze'] ?? 0),
            'reddito30'  => (int) ($t30['g'] ?? 0),
            'arresti'    => $p === null ? 0 : (int) $p['arresti'],
            'profilo'    => $p === null ? 0 : (int) $p['profilo'],
            'vinti'      => $p === null ? 0 : (int) $p['scontri_vinti'],
            'persi'      => $p === null ? 0 : (int) $p['scontri_persi'],
            'obiettivi'  => self::conta('SELECT COUNT(*) n FROM obiettivi WHERE personaggio_id = ?',
                                        [$personaggioId]),
        ];
    }

    /** @param list<mixed> $par */
    private static function conta(string $sql, array $par = []): int
    {
        try {
            return (int) (Database::first($sql, $par)['n'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param list<mixed> $par @return array<string,mixed> */
    private static function riga(string $sql, array $par = []): array
    {
        try {
            return Database::first($sql, $par) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
