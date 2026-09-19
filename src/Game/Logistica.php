<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;

/**
 * Mezzi e depositi: quanto si può portare, e dove si può lasciare.
 *
 * I 100 spazi del trench coat del 1984 diventano due cose distinte. Il **mezzo**
 * allarga quello che ti porti dietro — ed è il primo traguardo vero del gioco,
 * perché a piedi il capitale non lo impieghi nemmeno tutto. Il **deposito**
 * toglie il limite dell'ora: comprare quando costa poco e aspettare che la
 * piazza si riprenda è una strategia, e senza un posto dove mettere la roba non
 * esisterebbe.
 *
 * I mezzi si comprano col PULITO: un'auto si intesta a qualcuno. L'affitto del
 * deposito si paga sporco: il padrone di casa non fa fatture.
 */
final class Logistica
{
    /** Gli spazi che si hanno comunque: addosso più un borsone. */
    public const CAPIENZA_BASE = 80;

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $mezzi = null;

    /** @return array<string,array<string,mixed>> */
    public static function mezzi(): array
    {
        if (self::$mezzi !== null) {
            return self::$mezzi;
        }
        $f = [];
        foreach (Database::all('SELECT * FROM mezzi ORDER BY ordine') as $r) {
            $r['capienza']  = (int) $r['capienza'];
            $r['prezzo']    = (int) $r['prezzo'];
            $r['kmh_citta'] = (float) $r['kmh_citta'];
            $r['kmh_paese'] = (float) $r['kmh_paese'];
            $r['costo_km']  = (int) $r['costo_km'];
            $f[(string) $r['codice']] = $r;
        }
        return self::$mezzi = $f;
    }

    /** @param array<string,mixed> $p */
    public static function capienza(array $p): int
    {
        $m = $p['mezzo'] === null ? null : (self::mezzi()[(string) $p['mezzo']] ?? null);
        return self::CAPIENZA_BASE + ($m === null ? 0 : $m['capienza']);
    }

    /** @return array{ok:bool,error?:string} */
    public static function compraMezzo(int $personaggioId, string $codice): array
    {
        $m = self::mezzi()[$codice] ?? null;
        if ($m === null) {
            return ['ok' => false, 'error' => 'Questo mezzo non esiste.'];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Personaggio inesistente.']; }
            if ((string) $p['mezzo'] === $codice) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Ce l\'hai già.']; }
            if ((int) $p['pulito'] < $m['prezzo']) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Serve denaro pulito: ' . lire((int) $m['prezzo'])
                    . ' Un mezzo va intestato a qualcuno, e nessuno intesta niente a una borsa di contanti.'];
            }

            // Cambiare mezzo può ridurre la capienza: non si lascia il carico
            // fuori misura, si rifiuta il cambio e si dice perché.
            $vecchia = self::capienza($p);
            $nuova = self::CAPIENZA_BASE + $m['capienza'];
            $usato = Listino::ingombroUsato($personaggioId);
            if ($nuova < $usato) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Con quel mezzo non ci sta quello che hai addosso ('
                    . quantita($usato) . ' spazi contro ' . quantita($nuova) . '). Scarica prima.'];
            }

            $indietro = 0;
            if ($p['mezzo'] !== null) {
                // Il vecchio si rivende, ma di seconda mano e di fretta: metà.
                $vecchio = self::mezzi()[(string) $p['mezzo']] ?? null;
                $indietro = $vecchio === null ? 0 : (int) round($vecchio['prezzo'] * 0.5);
            }

            Database::run('UPDATE personaggi SET mezzo = ?, pulito = pulito - ? + ?, capienza = ? WHERE id = ?',
                [$codice, $m['prezzo'], $indietro, $nuova, $personaggioId]);
            Contabilita::segna($personaggioId, 'mezzo', 'pulito', -(int) $m['prezzo'], 'comprato: ' . $m['nome']);
            if ($indietro > 0) {
                Contabilita::segna($personaggioId, 'mezzo', 'pulito', $indietro, 'rivenduto il precedente, di fretta');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true];
    }

    // --- Depositi ------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function deposito(int $personaggioId, int $piazzaId): ?array
    {
        return Database::first('SELECT * FROM depositi WHERE personaggio_id = ? AND piazza_id = ?',
            [$personaggioId, $piazzaId]);
    }

    /** @return list<array<string,mixed>> */
    public static function depositi(int $personaggioId): array
    {
        $f = [];
        foreach (Database::all(
            'SELECT d.*, z.nome AS piazza, c.nome AS citta FROM depositi d
               JOIN piazze z ON z.id = d.piazza_id JOIN citta c ON c.id = z.citta_id
              WHERE d.personaggio_id = ? ORDER BY c.nome, z.nome',
            [$personaggioId]
        ) as $d) {
            $d['merce'] = self::merce((int) $d['id']);
            $d['usato'] = array_sum(array_column($d['merce'], 'ingombro'));
            $f[] = $d;
        }
        return $f;
    }

    /** @return list<array<string,mixed>> */
    public static function merce(int $depositoId): array
    {
        $beni = Listino::beni();
        $f = [];
        foreach (Database::all('SELECT * FROM deposito_merce WHERE deposito_id = ? AND quantita > 0', [$depositoId]) as $r) {
            $b = $beni[(int) $r['bene_id']] ?? null;
            if ($b === null) { continue; }
            $q = (int) $r['quantita'];
            $f[] = ['bene' => $b, 'quantita' => $q, 'costo' => (int) $r['costo_totale'],
                    'medio' => $q > 0 ? (int) round((int) $r['costo_totale'] / $q) : 0,
                    'ingombro' => $q * (int) $b['ingombro']];
        }
        usort($f, static fn($a, $b) => $a['bene']['ordine'] <=> $b['bene']['ordine']);
        return $f;
    }

    /** @return array{ok:bool,error?:string,anticipo?:int} */
    public static function apri(int $personaggioId, int $piazzaId): array
    {
        $affitto  = GameConfig::int('deposito.affitto_ora', 12_000);
        $anticipo = $affitto * max(1, GameConfig::int('deposito.anticipo_ore', 24));

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Personaggio inesistente.']; }
            if ((int) $p['piazza_id'] !== $piazzaId || $p['arrivo_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Un posto si affitta di persona.'];
            }
            if (self::deposito($personaggioId, $piazzaId) !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Qui ne hai già uno.'];
            }
            if ((int) $p['contante'] < $anticipo) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Vuole ' . lire($anticipo) . ' di anticipo, in contanti.'];
            }

            Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$anticipo, $personaggioId]);
            Database::run(
                'INSERT INTO depositi (personaggio_id, piazza_id, capienza, affitto_ora, pagato_fino_a)
                 VALUES (?, ?, ?, ?, DATE_ADD(?, INTERVAL ? HOUR))',
                [$personaggioId, $piazzaId, GameConfig::int('deposito.capienza', 5000), $affitto,
                 Clock::perDb(), GameConfig::int('deposito.anticipo_ore', 24)]
            );
            Contabilita::segna($personaggioId, 'affitto', 'sporco', -$anticipo, 'anticipo per un posto dove mettere la roba');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'anticipo' => $anticipo];
    }

    /**
     * Sposta merce fra il carico e il deposito. Verso positivo = dal carico al
     * deposito, negativo = dal deposito al carico.
     *
     * @return array{ok:bool,error?:string,quantita?:int}
     */
    public static function sposta(int $personaggioId, int $beneId, int $quantita, bool $versoDeposito): array
    {
        if ($quantita <= 0) { return ['ok' => false, 'error' => 'Quanto?']; }
        $bene = Listino::beni()[$beneId] ?? null;
        if ($bene === null) { return ['ok' => false, 'error' => 'Questa merce non esiste.']; }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null || $p['arrivo_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'In viaggio non si sposta niente.'];
            }
            $d = self::deposito($personaggioId, (int) $p['piazza_id']);
            if ($d === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Qui non hai un deposito.']; }

            $ing = (int) $bene['ingombro'];
            if ($versoDeposito) {
                $c = Database::first('SELECT * FROM carico WHERE personaggio_id = ? AND bene_id = ?', [$personaggioId, $beneId]);
                $ho = (int) ($c['quantita'] ?? 0);
                if ($ho <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non ne hai addosso.']; }
                $spazio = (int) $d['capienza'] - array_sum(array_column(self::merce((int) $d['id']), 'ingombro'));
                $q = min($quantita, $ho, $ing > 0 ? intdiv($spazio, $ing) : 0);
                if ($q <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Nel deposito non ci sta.']; }

                $medio = (int) round((int) $c['costo_totale'] / $ho);
                self::muovi($personaggioId, (int) $d['id'], $beneId, $q, $medio * $q, true);
            } else {
                $m = Database::first('SELECT * FROM deposito_merce WHERE deposito_id = ? AND bene_id = ?', [(int) $d['id'], $beneId]);
                $ho = (int) ($m['quantita'] ?? 0);
                if ($ho <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Nel deposito non ce n\'è.']; }
                $spazio = self::capienza($p) - Listino::ingombroUsato($personaggioId);
                $q = min($quantita, $ho, $ing > 0 ? intdiv($spazio, $ing) : 0);
                if ($q <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Addosso non ci sta.']; }

                $medio = (int) round((int) $m['costo_totale'] / $ho);
                self::muovi($personaggioId, (int) $d['id'], $beneId, $q, $medio * $q, false);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'quantita' => $q];
    }

    /**
     * Sposta $q unità fra carico e deposito, spostando anche il costo pagato.
     *
     * Le due tabelle si toccano con istruzioni DIVERSE per chi dà e chi riceve,
     * e non col solito `INSERT ... ON DUPLICATE KEY UPDATE q = q + VALUES(q)`
     * passando un numero negativo: le colonne `quantita` sono INT UNSIGNED e
     * MariaDB rifiuta il valore negativo sull'INSERT, anche quando la riga
     * esiste già e quel ramo non verrebbe mai eseguito. Il risultato era un
     * errore 500 su un'operazione perfettamente legittima.
     */
    private static function muovi(int $personaggioId, int $depositoId, int $beneId, int $q, int $costo, bool $versoDeposito): void
    {
        $togli = static function (string $tabella, string $colonna, int $chiave) use ($beneId, $q, $costo): void {
            Database::run(
                "UPDATE {$tabella} SET quantita = quantita - ?, costo_totale = GREATEST(0, costo_totale - ?)
                  WHERE {$colonna} = ? AND bene_id = ?",
                [$q, $costo, $chiave, $beneId]
            );
            Database::run("DELETE FROM {$tabella} WHERE {$colonna} = ? AND quantita <= 0", [$chiave]);
        };
        $metti = static function (string $tabella, string $colonna, int $chiave) use ($beneId, $q, $costo): void {
            Database::run(
                "INSERT INTO {$tabella} ({$colonna}, bene_id, quantita, costo_totale) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantita = quantita + VALUES(quantita),
                                         costo_totale = costo_totale + VALUES(costo_totale)",
                [$chiave, $beneId, $q, $costo]
            );
        };

        if ($versoDeposito) {
            $togli('carico', 'personaggio_id', $personaggioId);
            $metti('deposito_merce', 'deposito_id', $depositoId);
        } else {
            $togli('deposito_merce', 'deposito_id', $depositoId);
            $metti('carico', 'personaggio_id', $personaggioId);
        }
    }

    /**
     * Riscuote gli affitti scaduti. Chi non paga perde il posto e quello che
     * c'era dentro: è il padrone di casa che cambia la serratura, e la roba che
     * resta dentro non è roba di cui ci si possa lamentare con qualcuno.
     *
     * @return array{riscossi:int,persi:int}
     */
    public static function riscuotiAffitti(): array
    {
        $ora = Clock::adesso();
        $riscossi = 0; $persi = 0;

        foreach (Database::all('SELECT * FROM depositi WHERE pagato_fino_a <= ?', [Clock::perDb($ora)]) as $d) {
            $fino = Clock::daDb((string) $d['pagato_fino_a']);
            // Si fatturano solo le ore INTERE consumate, e `pagato_fino_a`
            // avanza esattamente di quelle. Con l'arrotondamento per eccesso un
            // battito che passa un secondo dopo la scadenza addebitava un'ora
            // intera in più: non è un buco — si finiva per aver pagato in
            // anticipo — ma i conti diventavano illeggibili, e un conto che il
            // giocatore non sa rifare è un conto di cui non si fida.
            $ore = $fino === null ? 1 : (int) floor(($ora->getTimestamp() - $fino->getTimestamp()) / 3600);
            if ($ore < 1) {
                continue;
            }
            $dovuto = $ore * (int) $d['affitto_ora'];

            $p = Database::first('SELECT contante FROM personaggi WHERE id = ?', [(int) $d['personaggio_id']]);
            if ($p !== null && (int) $p['contante'] >= $dovuto) {
                Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?',
                    [$dovuto, (int) $d['personaggio_id']]);
                Database::run('UPDATE depositi SET pagato_fino_a = DATE_ADD(pagato_fino_a, INTERVAL ? HOUR) WHERE id = ?',
                    [$ore, (int) $d['id']]);
                Contabilita::segna((int) $d['personaggio_id'], 'affitto', 'sporco', -$dovuto,
                    'affitto del deposito, ' . $ore . ' ore');
                $riscossi++;
                continue;
            }

            Contabilita::segna((int) $d['personaggio_id'], 'sfratto', 'sporco', 0,
                'deposito perso: non c\'erano ' . lire($dovuto) . ' per l\'affitto');
            Database::run('DELETE FROM depositi WHERE id = ?', [(int) $d['id']]);
            $persi++;
        }
        return ['riscossi' => $riscossi, 'persi' => $persi];
    }
}
