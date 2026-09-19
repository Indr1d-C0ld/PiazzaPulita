<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Calore;
use App\Sim\Clock;

/**
 * Le batterie e il territorio.
 *
 * **Una piazza non si conquista premendo un pulsante**: si controlla stando e
 * lavorando. Ogni lira movimentata lì lascia punti di presenza alla batteria di
 * chi l'ha movimentata, e la presenza decade — quindi il territorio va tenuto,
 * non preso una volta. Chi comanda incassa una quota sulle compravendite
 * altrui e paga uno spread più stretto ai propri.
 *
 * È la stessa logica del mercato applicata alle persone: niente dichiarazioni,
 * solo conseguenze di quello che si è fatto davvero.
 */
final class Batteria
{
    /** @return array<string,mixed>|null */
    public static function di(?int $batteriaId): ?array
    {
        if ($batteriaId === null) {
            return null;
        }
        return Database::first('SELECT * FROM batterie WHERE id = ?', [$batteriaId]);
    }

    /** @return list<array<string,mixed>> */
    public static function membri(int $batteriaId): array
    {
        return Database::all(
            'SELECT p.id, p.pulito, p.rispetto, p.timore, u.username
               FROM personaggi p JOIN users u ON u.id = p.user_id
              WHERE p.batteria_id = ? ORDER BY u.username',
            [$batteriaId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function elenco(): array
    {
        return Database::all(
            'SELECT b.*, u.username AS capo,
                    (SELECT COUNT(*) FROM personaggi m WHERE m.batteria_id = b.id) AS membri,
                    (SELECT COUNT(*) FROM territori t WHERE t.batteria_id = b.id) AS piazze
               FROM batterie b
               JOIN personaggi c ON c.id = b.capo_id JOIN users u ON u.id = c.user_id
              ORDER BY piazze DESC, membri DESC'
        );
    }

    // --- Fondare, entrare, uscire -------------------------------------------

    /** @return array{ok:bool,error?:string,id?:int} */
    public static function fonda(int $personaggioId, string $nome, string $sigla, string $motto): array
    {
        $nome  = trim($nome);
        $sigla = mb_strtoupper(trim($sigla));

        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 48) {
            return ['ok' => false, 'error' => 'Il nome deve avere da 3 a 48 caratteri.'];
        }
        if (!preg_match('/^[A-Z0-9]{2,5}$/u', $sigla)) {
            return ['ok' => false, 'error' => 'La sigla è da 2 a 5 caratteri, lettere e cifre.'];
        }

        $prezzo = GameConfig::int('batteria.fondazione', 10_000_000);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Personaggio inesistente.']; }
            if ($p['batteria_id'] !== null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Sei già in una batteria.']; }
            if ((int) $p['pulito'] < $prezzo) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Fondarne una costa ' . lire($prezzo)
                    . ' in denaro pulito. Una batteria è un\'impresa, non una colletta.'];
            }
            if (Database::first('SELECT 1 x FROM batterie WHERE nome = ? OR sigla = ?', [$nome, $sigla]) !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Nome o sigla già presi.'];
            }

            Database::run('UPDATE personaggi SET pulito = pulito - ? WHERE id = ?', [$prezzo, $personaggioId]);
            Database::run('INSERT INTO batterie (nome, sigla, capo_id, motto) VALUES (?, ?, ?, ?)',
                [$nome, $sigla, $personaggioId, $motto === '' ? null : mb_substr($motto, 0, 160)]);
            $id = Database::lastInsertId();
            Database::run('UPDATE personaggi SET batteria_id = ? WHERE id = ?', [$id, $personaggioId]);
            Contabilita::segna($personaggioId, 'batteria', 'pulito', -$prezzo, 'fondazione di ' . $nome);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        Cronaca::scrivi('batteria', 'Si è messa insieme una batteria nuova: ' . $nome . ' (' . $sigla . ').', null, 3);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array{ok:bool,error?:string} */
    public static function entra(int $personaggioId, int $batteriaId): array
    {
        $p = Database::first('SELECT batteria_id FROM personaggi WHERE id = ?', [$personaggioId]);
        if ($p === null) { return ['ok' => false, 'error' => 'Personaggio inesistente.']; }
        if ($p['batteria_id'] !== null) { return ['ok' => false, 'error' => 'Sei già in una batteria.']; }

        $b = self::di($batteriaId);
        if ($b === null) { return ['ok' => false, 'error' => 'Questa batteria non esiste.']; }

        Database::run('UPDATE personaggi SET batteria_id = ? WHERE id = ?', [$batteriaId, $personaggioId]);
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public static function esci(int $personaggioId): array
    {
        $p = Database::first('SELECT batteria_id FROM personaggi WHERE id = ?', [$personaggioId]);
        if ($p === null || $p['batteria_id'] === null) {
            return ['ok' => false, 'error' => 'Non sei in nessuna batteria.'];
        }
        $b = self::di((int) $p['batteria_id']);
        if ($b !== null && (int) $b['capo_id'] === $personaggioId) {
            $altri = (int) (Database::first('SELECT COUNT(*) n FROM personaggi WHERE batteria_id = ? AND id <> ?',
                [(int) $p['batteria_id'], $personaggioId])['n'] ?? 0);
            if ($altri > 0) {
                return ['ok' => false, 'error' => 'Sei il capo: prima passa la mano o restano tutti per strada.'];
            }
            // Ultimo rimasto: la batteria si scioglie, e la cassa torna a lui.
            if ((int) $b['cassa'] > 0) {
                Database::run('UPDATE personaggi SET contante = contante + ? WHERE id = ?',
                    [(int) $b['cassa'], $personaggioId]);
                Contabilita::segna($personaggioId, 'batteria', 'sporco', (int) $b['cassa'], 'cassa di ' . $b['nome']);
            }
            Database::run('UPDATE personaggi SET batteria_id = NULL WHERE id = ?', [$personaggioId]);
            Database::run('DELETE FROM batterie WHERE id = ?', [(int) $p['batteria_id']]);
            Cronaca::scrivi('batteria', $b['nome'] . ' non esiste più.', null, 2);
            return ['ok' => true];
        }

        Database::run('UPDATE personaggi SET batteria_id = NULL WHERE id = ?', [$personaggioId]);
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public static function versa(int $personaggioId, int $importo): array
    {
        if ($importo <= 0) { return ['ok' => false, 'error' => 'Quanto?']; }
        $p = Database::first('SELECT batteria_id, contante FROM personaggi WHERE id = ?', [$personaggioId]);
        if ($p === null || $p['batteria_id'] === null) { return ['ok' => false, 'error' => 'Non sei in nessuna batteria.']; }
        $importo = min($importo, (int) $p['contante']);
        if ($importo <= 0) { return ['ok' => false, 'error' => 'Non hai contanti.']; }

        Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$importo, $personaggioId]);
        Database::run('UPDATE batterie SET cassa = cassa + ? WHERE id = ?', [$importo, (int) $p['batteria_id']]);
        Contabilita::segna($personaggioId, 'batteria', 'sporco', -$importo, 'versati in cassa');
        return ['ok' => true];
    }

    /** Solo il capo preleva: una cassa comune senza una mano sola è una rissa. */
    public static function preleva(int $personaggioId, int $importo): array
    {
        if ($importo <= 0) { return ['ok' => false, 'error' => 'Quanto?']; }
        $p = Database::first('SELECT batteria_id FROM personaggi WHERE id = ?', [$personaggioId]);
        if ($p === null || $p['batteria_id'] === null) { return ['ok' => false, 'error' => 'Non sei in nessuna batteria.']; }
        $b = self::di((int) $p['batteria_id']);
        if ($b === null || (int) $b['capo_id'] !== $personaggioId) {
            return ['ok' => false, 'error' => 'Dalla cassa prende solo il capo.'];
        }
        $importo = min($importo, (int) $b['cassa']);
        if ($importo <= 0) { return ['ok' => false, 'error' => 'La cassa è vuota.']; }

        Database::run('UPDATE batterie SET cassa = cassa - ? WHERE id = ?', [$importo, (int) $b['id']]);
        Database::run('UPDATE personaggi SET contante = contante + ? WHERE id = ?', [$importo, $personaggioId]);
        Contabilita::segna($personaggioId, 'batteria', 'sporco', $importo, 'prelevati dalla cassa');
        return ['ok' => true];
    }

    // --- Il territorio --------------------------------------------------------

    /**
     * Registra il lavoro fatto in una piazza. Chiamata a ogni compravendita.
     *
     * Il pizzo si incassa qui: se la piazza è di un'altra batteria, una quota
     * dell'operazione finisce nella loro cassa. Non è una tassa astratta — è la
     * ragione per cui il territorio vale la pena di essere tenuto.
     *
     * @return int quanto pizzo è stato pagato
     */
    public static function lavorato(int $personaggioId, int $piazzaId, int $valore): int
    {
        if ($valore <= 0) {
            return 0;
        }
        $p = Database::first('SELECT batteria_id FROM personaggi WHERE id = ?', [$personaggioId]);
        $mia = $p === null ? null : ($p['batteria_id'] === null ? null : (int) $p['batteria_id']);

        if ($mia !== null) {
            $punti = $valore * (float) GameConfig::get('territorio.per_lira', 0.0000012);
            Database::run(
                'INSERT INTO presenze (piazza_id, batteria_id, punti, agg_a) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE punti = punti + VALUES(punti), agg_a = VALUES(agg_a)',
                [$piazzaId, $mia, round($punti, 3), Clock::perDb()]
            );
        }

        $t = Database::first('SELECT * FROM territori WHERE piazza_id = ?', [$piazzaId]);
        if ($t === null || $t['batteria_id'] === null || (int) $t['batteria_id'] === $mia) {
            return 0;
        }

        $pizzo = (int) round($valore * (float) GameConfig::get('batteria.pizzo', 0.04));
        if ($pizzo <= 0) {
            return 0;
        }
        Database::run('UPDATE personaggi SET contante = GREATEST(0, contante - ?) WHERE id = ?',
            [$pizzo, $personaggioId]);
        Database::run('UPDATE batterie SET cassa = cassa + ? WHERE id = ?', [$pizzo, (int) $t['batteria_id']]);
        Contabilita::segna($personaggioId, 'pizzo', 'sporco', -$pizzo, 'a chi comanda in questa piazza');
        return $pizzo;
    }

    /**
     * Fa decadere le presenze e riassegna le piazze. Chiamata dal battito.
     *
     * @return array{cambi:int}
     */
    public static function aggiornaTerritori(): array
    {
        $ora = Clock::adesso();
        $dim = (float) GameConfig::int('territorio.dimezzamento_ore', 72);
        $soglia = (float) GameConfig::int('territorio.soglia', 150);
        $cambi = 0;

        // 1. Il decadimento: il territorio va tenuto, non preso una volta.
        foreach (Database::all('SELECT * FROM presenze WHERE punti > 0') as $r) {
            $da = Clock::daDb((string) $r['agg_a'])?->getTimestamp() ?? $ora->getTimestamp();
            $p = Calore::decaduto((float) $r['punti'], max(0, $ora->getTimestamp() - $da), $dim);
            Database::run('UPDATE presenze SET punti = ?, agg_a = ? WHERE piazza_id = ? AND batteria_id = ?',
                [round($p, 3), Clock::perDb($ora), (int) $r['piazza_id'], (int) $r['batteria_id']]);
        }
        Database::run('DELETE FROM presenze WHERE punti <= 0.01');

        // 2. Chi comanda: la batteria con più presenza, se supera la soglia.
        foreach (Database::all('SELECT id, nome FROM piazze') as $z) {
            $piazzaId = (int) $z['id'];
            $top = Database::first(
                'SELECT p.batteria_id, p.punti, b.nome, b.sigla FROM presenze p
                   JOIN batterie b ON b.id = p.batteria_id
                  WHERE p.piazza_id = ? ORDER BY p.punti DESC LIMIT 1', [$piazzaId]);

            $nuovo = ($top !== null && (float) $top['punti'] >= $soglia) ? (int) $top['batteria_id'] : null;
            $t = Database::first('SELECT * FROM territori WHERE piazza_id = ?', [$piazzaId]);
            $vecchio = $t === null ? null : ($t['batteria_id'] === null ? null : (int) $t['batteria_id']);

            if ($t === null) {
                Database::run('INSERT INTO territori (piazza_id, batteria_id, presenza, dal, agg_a) VALUES (?, ?, ?, ?, ?)',
                    [$piazzaId, $nuovo, $top === null ? 0 : round((float) $top['punti'], 3),
                     $nuovo === null ? null : Clock::perDb($ora), Clock::perDb($ora)]);
                continue;
            }

            Database::run('UPDATE territori SET batteria_id = ?, presenza = ?, agg_a = ?,
                                  dal = IF(? <=> batteria_id, dal, ?) WHERE piazza_id = ?',
                [$nuovo, $top === null ? 0 : round((float) $top['punti'], 3), Clock::perDb($ora),
                 $nuovo, $nuovo === null ? null : Clock::perDb($ora), $piazzaId]);

            if ($nuovo !== $vecchio) {
                $cambi++;
                Cronaca::scrivi('territorio', $nuovo === null
                    ? $z['nome'] . ' non è più di nessuno.'
                    : $z['nome'] . ' adesso è roba di ' . $top['nome'] . ' (' . $top['sigla'] . ').',
                    $piazzaId, 3);
            }
        }
        return ['cambi' => $cambi];
    }

    /** @return array<string,mixed>|null chi comanda in una piazza */
    public static function padrone(int $piazzaId): ?array
    {
        return Database::first(
            'SELECT b.*, t.presenza, t.dal FROM territori t JOIN batterie b ON b.id = t.batteria_id
              WHERE t.piazza_id = ?', [$piazzaId]);
    }

    /** @return list<array<string,mixed>> */
    public static function territoriDi(int $batteriaId): array
    {
        return Database::all(
            'SELECT z.nome AS piazza, c.nome AS citta, t.presenza, t.dal
               FROM territori t JOIN piazze z ON z.id = t.piazza_id JOIN citta c ON c.id = z.citta_id
              WHERE t.batteria_id = ? ORDER BY c.nome, z.nome', [$batteriaId]);
    }
}
