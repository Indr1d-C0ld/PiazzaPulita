<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;
use App\Sim\Crescita;
use App\Sim\Denaro;

/**
 * Le due casse e l'usuraio.
 *
 * Il denaro del commercio entra SPORCO: ingombra, si perde, e non compra niente
 * che duri. Diventa pulito solo passando da un canale, che si prende la sua
 * percentuale e — soprattutto — ne lava una certa quantità **all'ora**. È la
 * capacità oraria, non la commissione, a fare il gioco: si può avere un
 * miliardo in cantina e non poterlo usare.
 *
 * Come il mercato, il riciclaggio avanza in modo pigro: leggere la pagina non
 * scrive niente, si proietta e basta. Si persiste quando si tocca qualcosa e a
 * ogni battito.
 */
final class Contabilita
{
    /** Il canale che si ha comunque, dal primo giorno. */
    public const CANALE_BASE = 'bar';

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $catalogo = null;

    /** @return array<string,array<string,mixed>> */
    public static function catalogo(): array
    {
        if (self::$catalogo !== null) {
            return self::$catalogo;
        }
        $f = [];
        foreach (Database::all('SELECT * FROM canali ORDER BY ordine') as $r) {
            $r['commissione'] = (float) $r['commissione'];
            $r['capacita']    = (int) $r['capacita'];
            $r['prezzo']      = (int) $r['prezzo'];
            $f[(string) $r['codice']] = $r;
        }
        return self::$catalogo = $f;
    }

    // --- Lettura -------------------------------------------------------------

    /**
     * Il quadro completo, proiettato ad adesso. Non scrive.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function stato(array $p): array
    {
        $ora = Clock::adesso();
        $canali = [];
        $inArrivo = 0;

        foreach (Database::all(
            'SELECT cp.*, c.nome, c.descrizione, c.commissione, c.capacita, c.ordine
               FROM canali_posseduti cp JOIN canali c ON c.codice = cp.canale
              WHERE cp.personaggio_id = ? ORDER BY c.ordine',
            [(int) $p['id']]
        ) as $r) {
            $da = Clock::daDb((string) $r['agg_a'])?->getTimestamp() ?? $ora->getTimestamp();
            $l = Denaro::lava((int) $r['coda'], max(0, $ora->getTimestamp() - $da),
                (int) $r['capacita'], (float) $r['commissione']);
            $inArrivo += $l['pulito'];
            $canali[] = [
                'codice'      => (string) $r['canale'],
                'nome'        => (string) $r['nome'],
                'descrizione' => (string) $r['descrizione'],
                'commissione' => (float) $r['commissione'],
                'capacita'    => (int) $r['capacita'],
                'coda'        => $l['coda'],
                'pronto'      => $l['pulito'],
                'lavato'      => (int) $r['lavato'],
                'finisce_fra' => Denaro::tempoDiLavaggio($l['coda'], (int) $r['capacita']),
            ];
        }

        $debito = self::debitoProiettato($p, $ora->getTimestamp());
        $capacitaTotale = array_sum(array_column($canali, 'capacita'));

        return [
            'sporco'     => (int) $p['contante'],
            'pulito'     => (int) $p['pulito'] + $inArrivo,
            'in_lavaggio'=> array_sum(array_column($canali, 'coda')),
            'debito'     => $debito,
            'tetto'      => (int) $p['debito_tetto'],
            'al_tetto'   => (int) $p['debito_tetto'] > 0 && $debito >= (int) $p['debito_tetto'],
            'interesse_giorno' => (int) round($debito * (float) GameConfig::get('denaro.interesse_giorno', 0.10)),
            'canali'     => $canali,
            'capacita_ora' => $capacitaTotale,
            'prestabile' => Denaro::prestitoDisponibile($debito, (int) $p['pulito'],
                Crescita::prestitoBase(GameConfig::int('denaro.prestito_base', 2_000_000), (float) ($p['credito'] ?? 0)),
                (float) GameConfig::get('denaro.prestito_su_pulito', 2.0)),
        ];
    }

    /** @param array<string,mixed> $p */
    private static function debitoProiettato(array $p, int $ora): int
    {
        $debito = (int) $p['debito'];
        if ($debito <= 0) {
            return 0;
        }
        $da = Clock::daDb($p['debito_agg_a'] === null ? null : (string) $p['debito_agg_a'])?->getTimestamp() ?? $ora;
        $tetto = (int) $p['debito_tetto'];
        // Il tetto è già assoluto: si passa 1 come base perché la funzione lo
        // moltiplichi per il tetto e ritrovi lo stesso numero.
        return Denaro::debitoDopo($debito, $tetto > 0 ? $tetto : $debito, max(0, $ora - $da),
            Crescita::interesse((float) GameConfig::get('denaro.interesse_giorno', 0.10), (float) ($p['credito'] ?? 0)),
            $tetto > 0 ? 1.0 : 99.0);
    }

    // --- Avanzamento ---------------------------------------------------------

    /** Porta canali e debito ad adesso, e scrive. @return array{pulito:int,lavato:int} */
    public static function avanza(int $personaggioId): array
    {
        $p = Database::first('SELECT * FROM personaggi WHERE id = ?', [$personaggioId]);
        if ($p === null) {
            return ['pulito' => 0, 'lavato' => 0];
        }
        $ora = Clock::adesso();
        $oraTs = $ora->getTimestamp();
        $pulito = 0;
        $lavatoTot = 0;
        // Un contabile fa girare di più, un riciclatore tratta meglio: sono i
        // due modi di migliorare la lavanderia senza comprarne una nuova.
        $eff = Organico::effetti($personaggioId);

        foreach (Database::all(
            'SELECT cp.*, c.capacita, c.commissione FROM canali_posseduti cp
               JOIN canali c ON c.codice = cp.canale WHERE cp.personaggio_id = ?',
            [$personaggioId]
        ) as $r) {
            if ((int) $r['coda'] <= 0) {
                Database::run('UPDATE canali_posseduti SET agg_a = ? WHERE personaggio_id = ? AND canale = ?',
                    [Clock::perDb($ora), $personaggioId, $r['canale']]);
                continue;
            }
            $da = Clock::daDb((string) $r['agg_a'])?->getTimestamp() ?? $oraTs;
            $l = Denaro::lava((int) $r['coda'], max(0, $oraTs - $da),
                (int) round((int) $r['capacita'] * (1.0 + min(0.6, $eff['contabile'] * 0.3))),
                max(0.02, (float) $r['commissione'] * (1.0 - min(0.35, $eff['riciclatore'] * 0.25))));
            if ($l['lavato'] <= 0) {
                continue;
            }
            Database::run(
                'UPDATE canali_posseduti SET coda = ?, lavato = lavato + ?, agg_a = ?
                  WHERE personaggio_id = ? AND canale = ?',
                [$l['coda'], $l['lavato'], Clock::perDb($ora), $personaggioId, $r['canale']]
            );
            $pulito += $l['pulito'];
            $lavatoTot += $l['lavato'];
        }

        if ($pulito > 0) {
            Database::run('UPDATE personaggi SET pulito = pulito + ? WHERE id = ?', [$pulito, $personaggioId]);
            self::segna($personaggioId, 'lavaggio', 'pulito', $pulito,
                'usciti puliti ' . lire($pulito) . ' da ' . lire($lavatoTot));
        }

        // Il debito matura da solo, e va scritto: se restasse solo proiettato,
        // il tetto e le conseguenze non scatterebbero mai per chi non apre la
        // pagina — cioè proprio per chi sta scappando.
        $nuovo = self::debitoProiettato($p, $oraTs);
        if ($nuovo !== (int) $p['debito'] || $p['debito_agg_a'] === null) {
            Database::run('UPDATE personaggi SET debito = ?, debito_agg_a = ? WHERE id = ?',
                [$nuovo, Clock::perDb($ora), $personaggioId]);
        }

        return ['pulito' => $pulito, 'lavato' => $lavatoTot];
    }

    /** @return array{personaggi:int,pulito:int} */
    public static function avanzaTutti(): array
    {
        $n = 0; $tot = 0;
        foreach (Database::all(
            'SELECT DISTINCT p.id FROM personaggi p
              LEFT JOIN canali_posseduti c ON c.personaggio_id = p.id
             WHERE c.coda > 0 OR p.debito > 0'
        ) as $r) {
            $e = self::avanza((int) $r['id']);
            $n++;
            $tot += $e['pulito'];
        }
        return ['personaggi' => $n, 'pulito' => $tot];
    }

    // --- Azioni --------------------------------------------------------------

    /** @return array{ok:bool,error?:string,importo?:int} */
    public static function metti(int $personaggioId, string $canale, int $importo): array
    {
        if ($importo <= 0) {
            return ['ok' => false, 'error' => 'Quanto vuoi lavare?'];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Personaggio inesistente.']; }

            $mio = Database::first('SELECT * FROM canali_posseduti WHERE personaggio_id = ? AND canale = ?',
                [$personaggioId, $canale]);
            if ($mio === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Questo canale non è tuo.']; }

            $importo = min($importo, (int) $p['contante']);
            if ($importo <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non hai contanti da lavare.']; }

            Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$importo, $personaggioId]);
            Database::run('UPDATE canali_posseduti SET coda = coda + ? WHERE personaggio_id = ? AND canale = ?',
                [$importo, $personaggioId, $canale]);
            self::segna($personaggioId, 'lavaggio', 'sporco', -$importo,
                'messi a lavare in ' . (self::catalogo()[$canale]['nome'] ?? $canale));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'importo' => $importo];
    }

    /** @return array{ok:bool,error?:string} */
    public static function compraCanale(int $personaggioId, string $canale): array
    {
        $c = self::catalogo()[$canale] ?? null;
        if ($c === null) { return ['ok' => false, 'error' => 'Questo canale non esiste.']; }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Personaggio inesistente.']; }
            if (Database::first('SELECT 1 x FROM canali_posseduti WHERE personaggio_id = ? AND canale = ?',
                    [$personaggioId, $canale]) !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Ce l\'hai già.'];
            }
            if ((int) $p['pulito'] < $c['prezzo']) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Serve denaro pulito: ' . lire((int) $c['prezzo'])
                    . ' Un canale non si compra coi contanti in una borsa.'];
            }

            Database::run('UPDATE personaggi SET pulito = pulito - ? WHERE id = ?', [$c['prezzo'], $personaggioId]);
            Database::run('INSERT INTO canali_posseduti (personaggio_id, canale, agg_a) VALUES (?, ?, ?)',
                [$personaggioId, $canale, Clock::perDb()]);
            self::segna($personaggioId, 'canale', 'pulito', -(int) $c['prezzo'], 'acquisito ' . $c['nome']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string,importo?:int} */
    public static function prestito(int $personaggioId, int $importo): array
    {
        if ($importo <= 0) { return ['ok' => false, 'error' => 'Quanto vuoi?']; }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Personaggio inesistente.']; }

            $debito = self::debitoProiettato($p, Clock::adesso()->getTimestamp());
            $puo = Denaro::prestitoDisponibile($debito, (int) $p['pulito'],
                Crescita::prestitoBase(GameConfig::int('denaro.prestito_base', 2_000_000), (float) ($p['credito'] ?? 0)),
                (float) GameConfig::get('denaro.prestito_su_pulito', 2.0));
            if ($puo <= 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Ti guarda e scuote la testa. Hai già preso abbastanza.'];
            }
            $importo = min($importo, $puo);
            $nuovoDebito = $debito + $importo;
            $tetto = max((int) $p['debito_tetto'], (int) round($nuovoDebito * (float) GameConfig::get('denaro.tetto_debito', 3.0)));

            Database::run(
                'UPDATE personaggi SET contante = contante + ?, debito = ?, debito_tetto = ?, debito_agg_a = ? WHERE id = ?',
                [$importo, $nuovoDebito, $tetto, Clock::perDb(), $personaggioId]
            );
            self::segna($personaggioId, 'prestito', 'sporco', $importo, 'presi dall\'usuraio');
            self::segna($personaggioId, 'prestito', 'debito', $importo, 'nuovo debito');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'importo' => $importo];
    }

    /** @return array{ok:bool,error?:string,importo?:int,saldato?:bool} */
    public static function restituisci(int $personaggioId, int $importo): array
    {
        if ($importo <= 0) { return ['ok' => false, 'error' => 'Quanto vuoi dargli?']; }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Personaggio inesistente.']; }

            $debito = self::debitoProiettato($p, Clock::adesso()->getTimestamp());
            if ($debito <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non devi niente a nessuno.']; }

            $importo = min($importo, $debito, (int) $p['contante']);
            if ($importo <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non hai contanti.']; }

            $resto = $debito - $importo;
            Database::run(
                'UPDATE personaggi SET contante = contante - ?, debito = ?, debito_agg_a = ?,
                        debito_tetto = IF(? = 0, 0, debito_tetto) WHERE id = ?',
                [$importo, $resto, Clock::perDb(), $resto, $personaggioId]
            );
            self::segna($personaggioId, 'restituzione', 'sporco', -$importo, 'dati all\'usuraio');
            self::segna($personaggioId, 'restituzione', 'debito', -$importo, 'debito ridotto');
            // Pagare i debiti è l'unico modo di costruirsi un credito, qui
            // come altrove. E chi paga si fa anche rispettare.
            Organico::cresci($personaggioId, 'credito', Crescita::pesoValore($importo, 1_000_000));
            Organico::reputazione($personaggioId, min(1.5, $importo / 2_000_000));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'importo' => $importo, 'saldato' => $resto <= 0];
    }

    // --- Registro ------------------------------------------------------------

    public static function segna(int $personaggioId, string $genere, string $cassa, int $importo, ?string $nota = null): void
    {
        try {
            Database::run(
                'INSERT INTO movimenti (personaggio_id, genere, cassa, importo, nota, fatto_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$personaggioId, $genere, $cassa, $importo, $nota, Clock::perDb()]
            );
        } catch (\Throwable $e) {
            logger('movimento non registrato (' . $genere . '): ' . $e->getMessage(), 'warning');
        }
    }

    /** @return list<array<string,mixed>> */
    public static function movimenti(int $personaggioId, int $quanti = 60): array
    {
        return Database::all(
            'SELECT * FROM movimenti WHERE personaggio_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $quanti)),
            [$personaggioId]
        );
    }
}
