<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;

/**
 * Il baratto: merce contro merce, mai denaro.
 *
 * **Perché mai denaro.** Uno scambio libero di contante fra due personaggi
 * sarebbe un tubo che aggira in un colpo solo i due freni su cui poggia tutta
 * l'economia: il tetto di reddito orario del mondo (§2.6) e la capacità oraria
 * dei canali di riciclaggio (§5), che è il vincolo vero della progressione. Chi
 * volesse riciclare userebbe un secondo account come canale gratuito e infinito.
 * Merce contro merce no: sposta roba fra due carichi senza creare una lira, e
 * resta utile davvero — ti libera di quello che in questa piazza non assorbe
 * nessuno, e che altrove vale.
 *
 * **Il costo viaggia con la merce.** Quando dieci unità passano di mano, passa
 * anche la quota di costo che avevano addosso: se no il margine di chi vende
 * dopo sarebbe finto, e il registro non tornerebbe più.
 */
final class Baratto
{
    /** @return array{ok:bool,error?:string,id?:int} */
    public static function proponi(
        int $daId, int $aId, int $beneDato, int $quantoDato, int $beneChiesto, int $quantoChiesto
    ): array {
        if ($daId === $aId) {
            return ['ok' => false, 'error' => 'Con te stesso non si baratta.'];
        }
        if ($quantoDato <= 0 || $quantoChiesto <= 0) {
            return ['ok' => false, 'error' => 'Quante unità, di preciso?'];
        }
        if ($beneDato === $beneChiesto) {
            return ['ok' => false, 'error' => 'La stessa merce per la stessa merce non è uno scambio.'];
        }

        $a = Database::first('SELECT * FROM personaggi WHERE id = ?', [$daId]);
        $b = Database::first('SELECT * FROM personaggi WHERE id = ?', [$aId]);
        if ($a === null || $b === null) {
            return ['ok' => false, 'error' => 'Non c\'è nessuno con quel nome.'];
        }
        if ((int) $a['piazza_id'] !== (int) $b['piazza_id'] || $a['arrivo_at'] !== null || $b['arrivo_at'] !== null) {
            return ['ok' => false, 'error' => 'Si baratta di persona: dovete essere nella stessa piazza.'];
        }
        foreach ([$a, $b] as $chi) {
            if (Legge::inCarcere($chi) || Rivalita::inOspedale($chi)) {
                return ['ok' => false, 'error' => 'Uno dei due non è in condizione di trattare.'];
            }
        }

        $beni = Listino::beni();
        if (!isset($beni[$beneDato], $beni[$beneChiesto])) {
            return ['ok' => false, 'error' => 'Questa merce non esiste.'];
        }
        if (self::quanteNeHa($daId, $beneDato) < $quantoDato) {
            return ['ok' => false, 'error' => 'Non hai addosso quello che offri.'];
        }

        $aperti = (int) (Database::first(
            "SELECT COUNT(*) n FROM baratti WHERE da_id = ? AND stato = 'proposto'", [$daId])['n'] ?? 0);
        if ($aperti >= max(1, GameConfig::int('baratto.max_aperti', 5))) {
            return ['ok' => false, 'error' => 'Hai già troppe proposte in giro. Aspetta una risposta.'];
        }

        $minuti = max(1, GameConfig::int('baratto.minuti', 30));
        Database::run(
            'INSERT INTO baratti (da_id, a_id, piazza_id, bene_dato, quanto_dato, bene_chiesto,
                                  quanto_chiesto, proposto_at, scade_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL ? MINUTE))',
            [$daId, $aId, (int) $a['piazza_id'], $beneDato, $quantoDato, $beneChiesto, $quantoChiesto,
             Clock::perDb(), Clock::perDb(), $minuti]
        );

        Legge::segnale($aId, 'baratto', Rivalita::nome($daId) . ' ti propone uno scambio: '
            . quantita($quantoDato) . ' ' . mb_strtolower((string) $beni[$beneDato]['nome'])
            . ' per ' . quantita($quantoChiesto) . ' ' . mb_strtolower((string) $beni[$beneChiesto]['nome']) . '.', 1);

        return ['ok' => true, 'id' => Database::lastInsertId()];
    }

    /**
     * Accetta: è il punto delicato, e sta tutto in una transazione con le due
     * righe bloccate. Fra la proposta e la risposta passano minuti, e in quei
     * minuti uno dei due può aver venduto la merce, essere partito, o essere
     * finito dentro: si ricontrolla tutto adesso, non ci si fida di prima.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function accetta(int $chiAccetta, int $barattoId): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $b = Database::first('SELECT * FROM baratti WHERE id = ? FOR UPDATE', [$barattoId]);
            if ($b === null || (int) $b['a_id'] !== $chiAccetta) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Questa proposta non è per te.'];
            }
            if ((string) $b['stato'] !== 'proposto') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Questa proposta non è più in piedi.'];
            }
            $scade = Clock::daDb((string) $b['scade_at']);
            if ($scade !== null && $scade <= Clock::adesso()) {
                Database::run("UPDATE baratti SET stato = 'scaduto', chiuso_at = ? WHERE id = ?",
                    [Clock::perDb(), $barattoId]);
                $pdo->commit();
                return ['ok' => false, 'error' => 'Era scaduta.'];
            }

            // Le due righe si bloccano SEMPRE nello stesso ordine (id
            // crescente): due baratti incrociati che si accettano nello stesso
            // istante, bloccati in ordine diverso, si aspetterebbero a vicenda
            // per sempre.
            $ids = [(int) $b['da_id'], (int) $b['a_id']];
            sort($ids);
            $righe = [];
            foreach ($ids as $id) {
                $righe[$id] = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$id]);
            }
            $da = $righe[(int) $b['da_id']] ?? null;
            $a  = $righe[(int) $b['a_id']] ?? null;
            if ($da === null || $a === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Uno dei due non c\'è più.'];
            }
            if ((int) $da['piazza_id'] !== (int) $a['piazza_id']
                || $da['arrivo_at'] !== null || $a['arrivo_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Non siete più nella stessa piazza.'];
            }
            foreach ([$da, $a] as $chi) {
                if (Legge::inCarcere($chi) || Rivalita::inOspedale($chi)) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'Uno dei due non è in condizione di trattare.'];
                }
            }

            $qd = (int) $b['quanto_dato']; $qc = (int) $b['quanto_chiesto'];
            $bd = (int) $b['bene_dato'];   $bc = (int) $b['bene_chiesto'];

            if (self::quanteNeHa((int) $da['id'], $bd) < $qd) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Chi ha proposto non ha più quella merce.'];
            }
            if (self::quanteNeHa((int) $a['id'], $bc) < $qc) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Non hai la merce che ti è stata chiesta.'];
            }

            // Lo spazio: si guarda quello che resta DOPO lo scambio, perché
            // ognuno dei due prima si libera e poi si carica.
            $beni = Listino::beni();
            $ingD = (int) $beni[$bd]['ingombro']; $ingC = (int) $beni[$bc]['ingombro'];
            $spazioDa = Logistica::capienza($da) - Listino::ingombroUsato((int) $da['id']) + $qd * $ingD;
            $spazioA  = Logistica::capienza($a)  - Listino::ingombroUsato((int) $a['id'])  + $qc * $ingC;
            if ($spazioDa < $qc * $ingC) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Chi ha proposto non ha spazio per quello che chiede.'];
            }
            if ($spazioA < $qd * $ingD) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Non hai spazio addosso per quella roba.'];
            }

            self::sposta((int) $da['id'], (int) $a['id'], $bd, $qd);
            self::sposta((int) $a['id'], (int) $da['id'], $bc, $qc);

            Database::run("UPDATE baratti SET stato = 'accettato', chiuso_at = ? WHERE id = ?",
                [Clock::perDb(), $barattoId]);
            // Le altre proposte aperte della stessa persona sulla stessa merce
            // possono essere diventate impossibili: le si lascia scadere da
            // sole, ma chi le ha fatte va avvisato adesso.
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        $beni = Listino::beni();
        Legge::segnale((int) $b['da_id'], 'baratto',
            Rivalita::nome($chiAccetta) . ' ha accettato lo scambio: ' . quantita((int) $b['quanto_chiesto'])
            . ' ' . mb_strtolower((string) $beni[(int) $b['bene_chiesto']]['nome']) . ' sono tuoi.', 1);

        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public static function rifiuta(int $chi, int $barattoId, bool $ritira = false): array
    {
        $campo = $ritira ? 'da_id' : 'a_id';
        $stato = $ritira ? 'ritirato' : 'rifiutato';
        $n = Database::run(
            "UPDATE baratti SET stato = ?, chiuso_at = ?
              WHERE id = ? AND {$campo} = ? AND stato = 'proposto'",
            [$stato, Clock::perDb(), $barattoId, $chi]
        )->rowCount();
        return $n > 0
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'Quella proposta non è più in piedi.'];
    }

    /** Le proposte che riguardano una persona, ancora in piedi. @return list<array<string,mixed>> */
    public static function aperti(int $personaggioId): array
    {
        return Database::all(
            "SELECT b.*, bd.nome AS nome_dato, bc.nome AS nome_chiesto,
                    ud.username AS da_nome, ua.username AS a_nome
               FROM baratti b
               JOIN beni bd ON bd.id = b.bene_dato
               JOIN beni bc ON bc.id = b.bene_chiesto
               JOIN personaggi pd ON pd.id = b.da_id JOIN users ud ON ud.id = pd.user_id
               JOIN personaggi pa ON pa.id = b.a_id  JOIN users ua ON ua.id = pa.user_id
              WHERE b.stato = 'proposto' AND (b.da_id = ? OR b.a_id = ?) AND b.scade_at > NOW(3)
              ORDER BY b.id DESC LIMIT 30",
            [$personaggioId, $personaggioId]
        );
    }

    /** Le proposte scadute le chiude il battito. */
    public static function scadute(): int
    {
        return Database::run(
            "UPDATE baratti SET stato = 'scaduto', chiuso_at = ?
              WHERE stato = 'proposto' AND scade_at <= ? LIMIT 500",
            [Clock::perDb(), Clock::perDb()]
        )->rowCount();
    }

    private static function quanteNeHa(int $personaggioId, int $beneId): int
    {
        return (int) (Database::first(
            'SELECT quantita FROM carico WHERE personaggio_id = ? AND bene_id = ?',
            [$personaggioId, $beneId]
        )['quantita'] ?? 0);
    }

    /**
     * Sposta merce da un carico all'altro, portandosi dietro la quota di costo.
     * Senza il costo, chi riceve avrebbe merce «gratis» e il primo margine che
     * realizza sarebbe finto.
     */
    private static function sposta(int $daId, int $aId, int $beneId, int $quanto): void
    {
        $r = Database::first(
            'SELECT quantita, costo_totale FROM carico WHERE personaggio_id = ? AND bene_id = ?',
            [$daId, $beneId]
        ) ?? ['quantita' => 0, 'costo_totale' => 0];

        $q = (int) $r['quantita'];
        $costo = (int) $r['costo_totale'];
        $quota = $q > 0 ? (int) round($costo * ($quanto / $q)) : 0;

        Database::run(
            'UPDATE carico SET quantita = quantita - ?, costo_totale = GREATEST(0, costo_totale - ?)
              WHERE personaggio_id = ? AND bene_id = ?',
            [$quanto, $quota, $daId, $beneId]
        );
        Database::run('DELETE FROM carico WHERE personaggio_id = ? AND bene_id = ? AND quantita <= 0',
            [$daId, $beneId]);
        Database::run(
            'INSERT INTO carico (personaggio_id, bene_id, quantita, costo_totale) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantita = quantita + VALUES(quantita),
                                     costo_totale = costo_totale + VALUES(costo_totale)',
            [$aId, $beneId, $quanto, $quota]
        );
    }
}
