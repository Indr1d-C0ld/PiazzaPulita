<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;
use App\Sim\Viaggio;
use App\Support\Audit;

/**
 * Il personaggio nel mondo: dove sei, quando arrivi, quanto hai in tasca.
 *
 * Uno per account, e non si ricrea mai: in questo mondo non si azzera niente
 * (decisione 3), nemmeno le persone.
 *
 * **Lo stato sta su una riga sola.** `piazza_id` è dove sei; se `arrivo_at` non
 * è nullo, sei in viaggio *verso* quella piazza e ci arrivi a quell'ora. Niente
 * join per sapere dove si trova qualcuno — e sapere dove si trovano tutti, in
 * fretta, servirà a ogni pagina di mercato da F2 in poi.
 */
final class Personaggio
{
    /** @return array<string,mixed>|null */
    public static function perUtente(int $userId, bool $avanza = true): ?array
    {
        $p = Database::first('SELECT * FROM personaggi WHERE user_id = ?', [$userId]);
        if ($p === null) {
            return null;
        }
        if ($avanza && self::avanza((int) $p['id'])) {
            $p = Database::first('SELECT * FROM personaggi WHERE user_id = ?', [$userId]);
        }
        return $p;
    }

    /**
     * Fa arrivare chi doveva arrivare.
     *
     * Chiamata sia dal battito sia dalla richiesta web, che sono processi
     * diversi e possono capitare insieme. Non serve un lucchetto: la UPDATE è
     * condizionata su `arrivo_at IS NOT NULL`, quindi solo uno dei due la vede
     * cambiare qualcosa e solo quello scrive il diario. Chi arriva secondo
     * trova zero righe toccate e se ne va senza fare danni.
     *
     * @return bool true se questo processo ha effettivamente fatto arrivare qualcuno
     */
    public static function avanza(int $personaggioId): bool
    {
        $ora = Clock::perDb();

        $toccate = Database::run(
            'UPDATE personaggi SET arrivo_at = NULL, avanzato_at = ?
              WHERE id = ? AND arrivo_at IS NOT NULL AND arrivo_at <= ?',
            [$ora, $personaggioId, $ora]
        )->rowCount();

        if ($toccate === 0) {
            return false;
        }

        Database::run(
            'UPDATE spostamenti SET arrivato_at = ?
              WHERE personaggio_id = ? AND arrivato_at IS NULL AND arrivo_at <= ?',
            [$ora, $personaggioId, $ora]
        );
        return true;
    }

    /** Fa arrivare tutti quelli che devono. Chiamata dal battito. */
    public static function avanzaTutti(): int
    {
        $ora = Clock::perDb();
        $pronti = Database::all(
            'SELECT id FROM personaggi WHERE arrivo_at IS NOT NULL AND arrivo_at <= ?',
            [$ora]
        );
        $n = 0;
        foreach ($pronti as $r) {
            if (self::avanza((int) $r['id'])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Crea il personaggio nella città scelta, in una piazza a caso fra le sue.
     *
     * La piazza di partenza non si sceglie: si sceglie la città. Di preciso
     * dove sei sbucato lo scopri arrivando, e trovarsi allo Zen invece che a
     * Ballarò è già una differenza — la prima che il giocatore incontra.
     *
     * @return array{ok:bool,error?:string,personaggio?:array<string,mixed>}
     */
    public static function crea(int $userId, int $cittaId, ?string $ip = null): array
    {
        if (self::perUtente($userId, false) !== null) {
            return ['ok' => false, 'error' => 'Hai già un personaggio.'];
        }

        $piazze = Mondo::piazzeDi($cittaId);
        if ($piazze === []) {
            return ['ok' => false, 'error' => 'Città sconosciuta.'];
        }

        $piazza = $piazze[random_int(0, count($piazze) - 1)];
        $contante = GameConfig::int('mondo.contante_iniziale', 300_000);
        $debito   = GameConfig::int('denaro.debito_iniziale', 1_500_000);
        $tetto    = (int) round($debito * (float) GameConfig::get('denaro.tetto_debito', 3.0));

        Database::run(
            'INSERT INTO personaggi (user_id, piazza_id, contante, capienza, debito, debito_tetto, debito_agg_a)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, (int) $piazza['id'], $contante, Logistica::CAPIENZA_BASE, $debito, $tetto, Clock::perDb()]
        );
        $nuovoId = Database::lastInsertId();

        // Il canale di partenza: un amico con un bar. Lava poco e si prende
        // molto, ma senza un canale i soldi del primo giorno non servirebbero a
        // niente — e un gioco che comincia con un vicolo cieco non lo gioca
        // nessuno abbastanza a lungo da scoprire che ce n'erano di migliori.
        Database::run(
            'INSERT INTO canali_posseduti (personaggio_id, canale, agg_a) VALUES (?, ?, ?)',
            [$nuovoId, Contabilita::CANALE_BASE, Clock::perDb()]
        );
        Contabilita::segna($nuovoId, 'prestito', 'debito', $debito, 'il debito con cui si comincia');
        Audit::log('mondo.nascita', $userId, 'piazza', (int) $piazza['id'],
            ['citta' => Mondo::citta()[$cittaId]['nome'] ?? '?'], $ip);

        return ['ok' => true, 'personaggio' => self::perUtente($userId, false)];
    }

    /**
     * Mette in viaggio.
     *
     * Tutto dentro una transazione con la riga bloccata: due schede aperte sullo
     * stesso account non devono poter partire due volte, né pagare due biglietti
     * per un viaggio solo.
     *
     * @return array{ok:bool,error?:string,minuti?:int,costo?:int,arrivo?:string}
     */
    public static function parti(int $personaggioId, int $aPiazzaId, string $mezzo, ?string $ip = null): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Personaggio inesistente.'];
            }

            $ora = Clock::adesso();
            $arrivoCorrente = Clock::daDb($p['arrivo_at'] === null ? null : (string) $p['arrivo_at']);
            if ($arrivoCorrente !== null && $arrivoCorrente > $ora) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Sei già in viaggio.'];
            }
            if (Legge::inCarcere($p)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Da qui non si va da nessuna parte.'];
            }

            $daPiazzaId = (int) $p['piazza_id'];
            if ($daPiazzaId === $aPiazzaId) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Sei già lì.'];
            }
            if (Mondo::piazza($aPiazzaId) === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Questa piazza non esiste.'];
            }

            $opz = Mondo::opzione($mezzo, $daPiazzaId, $aPiazzaId, self::mezzoProprio($p));
            if ($opz === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Da qui, con quel mezzo, non ci si arriva.'];
            }
            if ((int) $p['contante'] < $opz['costo']) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Non hai i soldi per il viaggio.'];
            }

            $arrivo = $ora->modify('+' . $opz['minuti'] . ' minutes');

            Database::run(
                'UPDATE personaggi SET piazza_id = ?, arrivo_at = ?, contante = contante - ?, avanzato_at = ?
                  WHERE id = ?',
                [$aPiazzaId, Clock::perDb($arrivo), $opz['costo'], Clock::perDb($ora), $personaggioId]
            );
            Database::run(
                'INSERT INTO spostamenti (personaggio_id, da_piazza_id, a_piazza_id, mezzo, km, costo, partito_at, arrivo_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$personaggioId, $daPiazzaId, $aPiazzaId, $mezzo, $opz['km'], $opz['costo'],
                 Clock::perDb($ora), Clock::perDb($arrivo)]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Audit::log('mondo.partenza', (int) $p['user_id'], 'piazza', $aPiazzaId,
            ['mezzo' => $mezzo, 'minuti' => $opz['minuti'], 'costo' => $opz['costo']], $ip);

        // Il posto di blocco si tira DOPO la partenza e fuori dalla
        // transazione: il viaggio è cominciato comunque, e se ti fermano ti
        // fermano per strada — non è il commesso che si rifiuta di venderti il
        // biglietto.
        $blocco = Legge::postoDiBlocco($p, self::mezzoProprio($p), $mezzo);

        return ['ok' => true, 'minuti' => $opz['minuti'], 'costo' => $opz['costo'],
                'arrivo' => Clock::perDb($arrivo),
                'blocco' => $blocco['blocco'] ? ($blocco['messaggio'] ?? 'Posto di blocco.') : null];
    }

    /**
     * Il veicolo posseduto, nella forma che serve a Viaggio.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>|null
     */
    public static function mezzoProprio(array $p): ?array
    {
        return $p['mezzo'] === null ? null : (Logistica::mezzi()[(string) $p['mezzo']] ?? null);
    }

    /**
     * Lo stato da mostrare: dove sei (o dove stai andando), e quanto manca.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function stato(array $p): array
    {
        $arrivo = Clock::daDb($p['arrivo_at'] === null ? null : (string) $p['arrivo_at']);
        $inViaggio = $arrivo !== null && $arrivo > Clock::adesso();
        $mancano = $inViaggio ? max(0, $arrivo->getTimestamp() - Clock::adesso()->getTimestamp()) : 0;

        $piazza = Mondo::piazza((int) $p['piazza_id']);
        $citta  = Mondo::cittaDi((int) $p['piazza_id']);

        $da = null;
        if ($inViaggio) {
            $s = Database::first(
                'SELECT da_piazza_id, mezzo FROM spostamenti
                  WHERE personaggio_id = ? AND arrivato_at IS NULL ORDER BY id DESC LIMIT 1',
                [(int) $p['id']]
            );
            if ($s !== null) {
                $da = ['piazza' => Mondo::piazza((int) $s['da_piazza_id']), 'mezzo' => (string) $s['mezzo']];
            }
        }

        return [
            'in_viaggio'  => $inViaggio,
            'mezzo'       => self::mezzoProprio($p),
            'piazza'      => $piazza,
            'citta'       => $citta,
            'da'          => $da,
            'mancano_sec' => $mancano,
            'mancano'     => Viaggio::durata((int) ceil($mancano / 60)),
            'arrivo_at'   => $arrivo?->format('c'),
            'contante'    => (int) $p['contante'],
        ];
    }

    /** Chi altro è fermo in questa piazza adesso. @return list<array<string,mixed>> */
    public static function altriQui(int $piazzaId, int $escludi): array
    {
        return Database::all(
            'SELECT p.id, u.username
               FROM personaggi p JOIN users u ON u.id = p.user_id
              WHERE p.piazza_id = ? AND p.arrivo_at IS NULL AND p.id <> ?
                AND u.status = \'active\'
              ORDER BY u.username LIMIT 50',
            [$piazzaId, $escludi]
        );
    }
}
