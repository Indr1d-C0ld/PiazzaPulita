<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;
use App\Sim\Rng;
use App\Sim\Scontro;

/**
 * Colpire una persona invece di un prezzo.
 *
 * L'attrito principale fra giocatori non è qui: è nel mercato condiviso, dove
 * chi compra prima alza il prezzo a chi viene dopo e chi svuota una piazza la
 * lascia secca per ore. Quello non costa una riga di codice. Qui c'è il resto —
 * l'aggressione, la spia, la soffiata — cioè le cose che vanno scritte.
 *
 * Tre regole che vengono dalla decisione 4 e dal suo prezzo (§0.1b):
 *   - si prende solo ciò che la vittima aveva ADDOSSO;
 *   - sotto una soglia non c'è niente da prendere;
 *   - attaccare costa calore, profilo criminale permanente, e può far
 *     intervenire la polizia — tanto più quanto è sorvegliata la piazza.
 */
final class Rivalita
{
    private const DISCOPERTA = [
        'uccisa'      => 'non è più tornato a casa.',
        'scappata'    => 'è sparito prima che lo prendessero.',
        'voltafaccia' => 'ha cambiato padrone, e adesso racconta di te.',
    ];

    // --- Lo scontro ------------------------------------------------------------

    /**
     * @return array{ok:bool,error?:string,esito?:string,racconto?:string,bottino?:int,unita?:int}
     */
    public static function attacca(int $attaccanteId, int $difensoreId): array
    {
        if ($attaccanteId === $difensoreId) {
            return ['ok' => false, 'error' => 'Con te stesso no.'];
        }

        $a = Database::first('SELECT * FROM personaggi WHERE id = ?', [$attaccanteId]);
        $d = Database::first('SELECT * FROM personaggi WHERE id = ?', [$difensoreId]);
        if ($a === null || $d === null) {
            return ['ok' => false, 'error' => 'Non c\'è nessuno con quel nome.'];
        }
        if (Legge::inCarcere($a) || self::inOspedale($a)) {
            return ['ok' => false, 'error' => 'Non sei in condizione.'];
        }
        if ((int) $a['piazza_id'] !== (int) $d['piazza_id'] || $a['arrivo_at'] !== null || $d['arrivo_at'] !== null) {
            return ['ok' => false, 'error' => 'Non è qui.'];
        }
        if (Legge::inCarcere($d) || self::inOspedale($d)) {
            return ['ok' => false, 'error' => 'È già fuori gioco. Non c\'è gusto e non c\'è guadagno.'];
        }
        if ($a['batteria_id'] !== null && (int) $a['batteria_id'] === (int) ($d['batteria_id'] ?? 0)) {
            return ['ok' => false, 'error' => 'È uno dei tuoi.'];
        }

        $piazzaId = (int) $a['piazza_id'];
        $rng = new Rng((int) (microtime(true) * 1000) ^ $attaccanteId ^ ($difensoreId << 8));

        $armiA = self::armiAddosso($attaccanteId);
        $armiD = self::armiAddosso($difensoreId);
        $guardieA = Organico::effetti($attaccanteId)['guardia'];
        $guardieD = Organico::effetti($difensoreId)['guardia'];

        $base    = GameConfig::int('pvp.attacco_base', 80);
        $perArma = GameConfig::int('pvp.per_arma', 25);
        $baseD   = GameConfig::int('pvp.difesa_base', 100);
        $perG    = GameConfig::int('pvp.per_guardia', 20);

        $attA = Scontro::attacco($base, $armiA, $perArma, $guardieA);
        $difA = Scontro::difesa($baseD, $guardieA, $perG, (float) $a['sangue_freddo']);
        $attD = Scontro::attacco($base, $armiD, $perArma, $guardieD);
        $difD = Scontro::difesa($baseD, $guardieD, $perG, (float) $d['sangue_freddo']);

        $saluteA = (int) $a['salute'];
        $saluteD = (int) $d['salute'];
        $dato = 0; $preso = 0;
        $esito = 'niente';

        // Si scambiano colpi finché uno non cade o non scappa. Pochi round: è
        // una rissa per strada, non un duello.
        for ($round = 1; $round <= 6; $round++) {
            $c = Scontro::colpo($attA, $difD, $armiA, $guardieD, $rng);
            if ($c['colpito']) {
                $saluteD -= $c['danno'];
                $dato += $c['danno'];
            }
            if ($saluteD <= 0) { $esito = 'vinto'; break; }

            $r = Scontro::colpo($attD, $difA, $armiD, $guardieA, $rng);
            if ($r['colpito']) {
                $saluteA -= $r['danno'];
                $preso += $r['danno'];
            }
            if ($saluteA <= 0) { $esito = 'perso'; break; }

            // Chi le sta prendendo prova ad andarsene.
            if ($saluteD < 35 && Scontro::fuga((float) GameConfig::get('pvp.fuga', 0.60), false, $rng)) {
                $esito = 'fuga';
                break;
            }
        }

        $saluteA = max(1, min(100, $saluteA));
        $saluteD = max(0, min(100, $saluteD));

        // --- Il bottino --------------------------------------------------------
        $bottino = 0; $unita = 0;
        if ($esito === 'vinto') {
            [$bottino, $unita] = self::spoglia($difensoreId, $attaccanteId);
        }

        // --- Le conseguenze ----------------------------------------------------
        $ospedaleOre = GameConfig::int('pvp.ospedale_ore', 6);
        Database::run('UPDATE personaggi SET salute = ?, scontri_vinti = scontri_vinti + ? WHERE id = ?',
            [$saluteA, $esito === 'vinto' ? 1 : 0, $attaccanteId]);
        Database::run('UPDATE personaggi SET salute = ?, scontri_persi = scontri_persi + ? WHERE id = ?',
            [$saluteD === 0 ? 100 : $saluteD, $esito === 'vinto' ? 1 : 0, $difensoreId]);

        if ($esito === 'vinto') {
            Database::run('UPDATE personaggi SET ospedale_fino_a = DATE_ADD(?, INTERVAL ? HOUR) WHERE id = ?',
                [Clock::perDb(), $ospedaleOre, $difensoreId]);
        } elseif ($esito === 'perso') {
            Database::run('UPDATE personaggi SET ospedale_fino_a = DATE_ADD(?, INTERVAL ? HOUR), salute = 100 WHERE id = ?',
                [Clock::perDb(), $ospedaleOre, $attaccanteId]);
        }

        // Il conto: calore, profilo criminale permanente, timore.
        Legge::scalda($attaccanteId, $piazzaId, GameConfig::int('pvp.calore', 60) * 1_000_000 / 100, 90);
        Database::run('UPDATE personaggi SET profilo = profilo + 1 WHERE id = ?', [$attaccanteId]);
        Organico::reputazione($attaccanteId, 0.0, $esito === 'vinto' ? 8.0 : 3.0);
        if ($esito === 'perso' || $esito === 'fuga') {
            Organico::reputazione($difensoreId, 0.0, 5.0);
        }
        Legge::prove($attaccanteId, 14.0, 'una rissa in strada');

        $racconto = self::racconto($esito, $armiA, $armiD, $bottino, $unita);

        Database::run(
            'INSERT INTO scontri (attaccante_id, difensore_id, piazza_id, esito, danno_dato, danno_preso,
                                  bottino_sporco, bottino_unita, racconto, fatto_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$attaccanteId, $difensoreId, $piazzaId, $esito, min(255, $dato), min(255, $preso),
             $bottino, $unita, $racconto, Clock::perDb()]
        );

        $nomeA = self::nome($attaccanteId);
        $nomeD = self::nome($difensoreId);
        Legge::segnale($difensoreId, 'aggressione', $nomeA . ' te le ha date. ' . $racconto, 5);
        Cronaca::scrivi('scontro', $nomeA . ' ha affrontato ' . $nomeD . '. ' . $racconto, $piazzaId, 4);

        // La polizia non guarda sempre, ma quanto guarda dipende dal quartiere.
        $piazza = Mondo::piazza($piazzaId);
        if ($piazza !== null && random_int(1, 100) <= (int) $piazza['polizia']) {
            $c = Legge::controllaIn($attaccanteId, $piazzaId);
            if (!empty($c['controllo'])) {
                $racconto .= ' ' . ($c['messaggio'] ?? '');
            }
        }

        return ['ok' => true, 'esito' => $esito, 'racconto' => $racconto,
                'bottino' => $bottino, 'unita' => $unita];
    }

    /** @return array{0:int,1:int} contante e unità prese */
    private static function spoglia(int $vittimaId, int $vincitoreId): array
    {
        $v = Database::first('SELECT contante, mezzo FROM personaggi WHERE id = ?', [$vittimaId]);
        if ($v === null) {
            return [0, 0];
        }
        $carico = Listino::carico($vittimaId);
        $valoreMerce = array_sum(array_column($carico, 'costo'));
        $unita = array_sum(array_column($carico, 'quantita'));

        $soglia = GameConfig::int('pvp.bottino_minimo', 500_000);
        if (Scontro::bottino((int) $v['contante'], (int) $valoreMerce, $soglia) === 0) {
            // Non c'era niente da prendere: non si toglie nulla a nessuno.
            return [0, 0];
        }

        $sporco = (int) $v['contante'];
        Database::run('UPDATE personaggi SET contante = 0 WHERE id = ?', [$vittimaId]);
        Database::run('UPDATE personaggi SET contante = contante + ? WHERE id = ?', [$sporco, $vincitoreId]);
        Contabilita::segna($vittimaId, 'rapina', 'sporco', -$sporco, 'te li hanno presi');
        Contabilita::segna($vincitoreId, 'rapina', 'sporco', $sporco, 'presi a qualcuno');

        // La merce passa di mano solo per quanto ci sta addosso al vincitore.
        $spazio = Logistica::capienza(Database::first('SELECT * FROM personaggi WHERE id = ?', [$vincitoreId]) ?? [])
                - Listino::ingombroUsato($vincitoreId);
        $prese = 0;
        foreach ($carico as $c) {
            $ing = (int) $c['bene']['ingombro'];
            $quante = $ing > 0 ? min((int) $c['quantita'], intdiv(max(0, $spazio), $ing)) : 0;
            Database::run('DELETE FROM carico WHERE personaggio_id = ? AND bene_id = ?',
                [$vittimaId, (int) $c['bene']['id']]);
            if ($quante <= 0) {
                continue;   // quello che non ci sta resta per strada
            }
            Database::run(
                'INSERT INTO carico (personaggio_id, bene_id, quantita, costo_totale) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantita = quantita + VALUES(quantita),
                                         costo_totale = costo_totale + VALUES(costo_totale)',
                [$vincitoreId, (int) $c['bene']['id'], $quante, $c['medio'] * $quante]
            );
            $spazio -= $quante * $ing;
            $prese += $quante;
        }

        return [$sporco, $prese];
    }

    private static function racconto(string $esito, int $armiA, int $armiD, int $bottino, int $unita): string
    {
        $arma = $armiA > 0 ? 'Ha tirato fuori qualcosa.' : 'A mani nude.';
        return match ($esito) {
            'vinto' => $arma . ($bottino > 0
                ? ' L\'ha lasciato a terra e gli ha preso ' . lire($bottino)
                  . ($unita > 0 ? ' e ' . quantita($unita) . ' unità' : '') . '.'
                : ' L\'ha lasciato a terra, e non aveva niente addosso.'),
            'perso' => $arma . ' È finita male: è rimasto lui per terra.',
            'fuga'  => $arma . ' Ma l\'altro se l\'è data a gambe.',
            default => $arma . ' Si sono presi a botte senza concludere niente.',
        };
    }

    private static function armiAddosso(int $personaggioId): int
    {
        $r = Database::first(
            'SELECT COALESCE(c.quantita, 0) q FROM carico c JOIN beni b ON b.id = c.bene_id
              WHERE c.personaggio_id = ? AND b.codice = \'armi\'', [$personaggioId]);
        return (int) ($r['q'] ?? 0);
    }

    /** @param array<string,mixed> $p */
    public static function inOspedale(array $p): bool
    {
        $fino = Clock::daDb(($p['ospedale_fino_a'] ?? null) === null ? null : (string) $p['ospedale_fino_a']);
        return $fino !== null && $fino > Clock::adesso();
    }

    /** @param array<string,mixed> $p */
    public static function mancaAlDimissione(array $p): int
    {
        $fino = Clock::daDb(($p['ospedale_fino_a'] ?? null) === null ? null : (string) $p['ospedale_fino_a']);
        return $fino === null ? 0 : max(0, $fino->getTimestamp() - Clock::adesso()->getTimestamp());
    }

    public static function dimetti(): int
    {
        return Database::run(
            'UPDATE personaggi SET ospedale_fino_a = NULL, salute = 100
              WHERE ospedale_fino_a IS NOT NULL AND ospedale_fino_a <= ?', [Clock::perDb()]
        )->rowCount();
    }

    // --- La soffiata -------------------------------------------------------------

    /** @return array{ok:bool,error?:string,ritorta?:bool} */
    public static function soffiata(int $daId, int $controId): array
    {
        if ($daId === $controId) {
            return ['ok' => false, 'error' => 'Su te stesso no.'];
        }
        $prezzo = GameConfig::int('pvp.soffiata_prezzo', 2_000_000);
        $p = Database::first('SELECT contante FROM personaggi WHERE id = ?', [$daId]);
        if ($p === null || (int) $p['contante'] < $prezzo) {
            return ['ok' => false, 'error' => 'Serve ' . lire($prezzo) . ' in contanti: '
                . 'una telefonata anonima costa poco, una che venga presa sul serio no.'];
        }
        if (Database::first('SELECT id FROM personaggi WHERE id = ?', [$controId]) === null) {
            return ['ok' => false, 'error' => 'Non c\'è nessuno con quel nome.'];
        }

        Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$prezzo, $daId]);
        Contabilita::segna($daId, 'soffiata', 'sporco', -$prezzo, 'una telefonata');

        $prove = (float) GameConfig::int('pvp.soffiata_prove', 22);
        $ritorta = random_int(1, 1000) / 1000 < (float) GameConfig::get('pvp.soffiata_ritorno', 0.25);

        if ($ritorta) {
            // Hanno risalito la telefonata: le prove sono tue.
            Legge::prove($daId, $prove, 'una soffiata risalita al mittente');
            Legge::segnale($daId, 'soffiata',
                'La telefonata l\'hanno registrata, e hanno riconosciuto la voce.', 5);
        } else {
            Legge::prove($controId, $prove, 'una soffiata');
            Legge::segnale($controId, 'soffiata',
                'Qualcuno ha parlato di te a chi non doveva. Non sai chi.', 4);
        }

        Database::run(
            'INSERT INTO soffiate (da_id, contro_id, costo, prove, ritorta, fatto_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$daId, $controId, $prezzo, $prove, $ritorta ? 1 : 0, Clock::perDb()]
        );
        return ['ok' => true, 'ritorta' => $ritorta];
    }

    // --- La spia --------------------------------------------------------------------

    /** @return array{ok:bool,error?:string,nome?:string} */
    public static function infiltra(int $padroneId, int $bersaglioId, int $uomoId): array
    {
        if ($padroneId === $bersaglioId) {
            return ['ok' => false, 'error' => 'Su te stesso no.'];
        }
        $u = Database::first('SELECT * FROM uomini WHERE id = ? AND personaggio_id = ? AND stato = \'libero\'',
            [$uomoId, $padroneId]);
        if ($u === null) {
            return ['ok' => false, 'error' => 'Non hai quest\'uomo, o non è libero.'];
        }
        if (Database::first("SELECT id FROM spie WHERE padrone_id = ? AND bersaglio_id = ? AND esito = 'dentro'",
                [$padroneId, $bersaglioId]) !== null) {
            return ['ok' => false, 'error' => 'Ne hai già uno dentro.'];
        }
        $prezzo = GameConfig::int('pvp.spia_prezzo', 3_000_000);
        $p = Database::first('SELECT contante FROM personaggi WHERE id = ?', [$padroneId]);
        if ($p === null || (int) $p['contante'] < $prezzo) {
            return ['ok' => false, 'error' => 'Infiltrare qualcuno costa ' . lire($prezzo) . ' in contanti.'];
        }

        Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$prezzo, $padroneId]);
        Database::run('DELETE FROM uomini WHERE id = ?', [$uomoId]);   // passa dall'altra parte
        Database::run(
            'INSERT INTO spie (padrone_id, bersaglio_id, nome, messa_at, agg_a) VALUES (?, ?, ?, ?, ?)',
            [$padroneId, $bersaglioId, (string) $u['nome'], Clock::perDb(), Clock::perDb()]
        );
        Contabilita::segna($padroneId, 'spia', 'sporco', -$prezzo, (string) $u['nome'] . ', messo dentro');
        return ['ok' => true, 'nome' => (string) $u['nome']];
    }

    /** Quello che la spia vede. @return array<string,mixed>|null */
    public static function rapporto(int $padroneId, int $bersaglioId): ?array
    {
        $s = Database::first("SELECT * FROM spie WHERE padrone_id = ? AND bersaglio_id = ? AND esito = 'dentro'",
            [$padroneId, $bersaglioId]);
        if ($s === null) {
            return null;
        }
        $b = Database::first(
            'SELECT p.*, u.username FROM personaggi p JOIN users u ON u.id = p.user_id WHERE p.id = ?',
            [$bersaglioId]);
        if ($b === null) {
            return null;
        }
        return [
            'spia'     => (string) $s['nome'],
            'nome'     => (string) $b['username'],
            'dove'     => Mondo::piazza((int) $b['piazza_id']),
            'sporco'   => (int) $b['contante'],
            'pulito'   => (int) $b['pulito'],
            'debito'   => (int) $b['debito'],
            'calore'   => Legge::calorePersonale($b),
            'carico'   => Listino::carico((int) $b['id']),
            'uomini'   => Organico::quantiNeHa((int) $b['id']),
        ];
    }

    /**
     * Le spie si scoprono, prima o poi. Quattro esiti, come nell'originale.
     *
     * @return array{scoperte:int}
     */
    public static function spieScoperte(): array
    {
        $ora = Clock::adesso();
        $perOra = (float) GameConfig::get('pvp.spia_scoperta_ora', 0.04);
        $n = 0;

        foreach (Database::all("SELECT * FROM spie WHERE esito = 'dentro'") as $s) {
            $da = Clock::daDb((string) $s['agg_a'])?->getTimestamp() ?? $ora->getTimestamp();
            $ore = max(0.0, ($ora->getTimestamp() - $da) / 3600.0);
            Database::run('UPDATE spie SET agg_a = ? WHERE id = ?', [Clock::perDb($ora), (int) $s['id']]);
            if ($ore <= 0) {
                continue;
            }
            // Probabilità composta sull'intervallo: stare fermi non salva.
            if (random_int(1, 1_000_000) / 1_000_000 >= 1.0 - (1.0 - $perOra) ** $ore) {
                continue;
            }

            $esiti = array_keys(self::DISCOPERTA);
            $esito = $esiti[random_int(0, count($esiti) - 1)];
            Database::run('UPDATE spie SET esito = ?, scoperta_at = ? WHERE id = ?',
                [$esito, Clock::perDb($ora), (int) $s['id']]);
            $n++;

            Legge::segnale((int) $s['padrone_id'], 'spia',
                $s['nome'] . ' è stato scoperto: ' . self::DISCOPERTA[$esito], 4);
            Legge::segnale((int) $s['bersaglio_id'], 'spia',
                'C\'era uno di un altro dentro casa tua. Adesso non c\'è più.', 4);

            if ($esito === 'voltafaccia') {
                // Passato dall'altra parte: adesso racconta di chi l'aveva mandato.
                Legge::prove((int) $s['padrone_id'], 25.0, 'la tua spia ha cambiato padrone');
            }
        }
        return ['scoperte' => $n];
    }

    // --- Rapinare una corsa ------------------------------------------------------------

    /**
     * Colpire la merce in transito di un altro. Non si colpisce la persona: si
     * colpisce un carico su una rotta nota, ed è il modo di farsi male a
     * vicenda che non manda nessuno all'ospedale.
     *
     * @return array{ok:bool,error?:string,unita?:int}
     */
    public static function rapina(int $rapinatoreId, int $corsaId): array
    {
        $c = Database::first(
            'SELECT c.*, u.nome AS corriere FROM corse c JOIN uomini u ON u.id = c.uomo_id
              WHERE c.id = ? AND c.esito = \'in_corso\'', [$corsaId]);
        if ($c === null) {
            return ['ok' => false, 'error' => 'Quella corsa non è più per strada.'];
        }
        if ((int) $c['personaggio_id'] === $rapinatoreId) {
            return ['ok' => false, 'error' => 'È roba tua.'];
        }
        $r = Database::first('SELECT * FROM personaggi WHERE id = ?', [$rapinatoreId]);
        if ($r === null || Legge::inCarcere($r) || self::inOspedale($r)) {
            return ['ok' => false, 'error' => 'Non sei in condizione.'];
        }
        // Si può colpire solo una rotta che passa da dove sei.
        if ((int) $r['piazza_id'] !== (int) $c['da_piazza_id'] && (int) $r['piazza_id'] !== (int) $c['a_piazza_id']) {
            return ['ok' => false, 'error' => 'Quella rotta non passa di qui.'];
        }

        $armi = self::armiAddosso($rapinatoreId);
        $riesce = random_int(1, 1000) / 1000 < min(0.85, 0.35 + $armi * 0.12 + (float) $r['sangue_freddo'] / 400);

        Legge::scalda($rapinatoreId, (int) $r['piazza_id'], 25_000_000, 80);
        Legge::prove($rapinatoreId, 10.0, 'una rapina su strada');

        if (!$riesce) {
            Legge::segnale($rapinatoreId, 'rapina', 'Il corriere ti ha visto arrivare e ha cambiato strada.', 2);
            return ['ok' => true, 'unita' => 0];
        }

        Database::run('UPDATE corse SET esito = \'sequestrata\', chiusa_at = ? WHERE id = ?',
            [Clock::perDb(), $corsaId]);
        Database::run('UPDATE uomini SET stato = \'libero\' WHERE id = ?', [(int) $c['uomo_id']]);

        $spazio = Logistica::capienza($r) - Listino::ingombroUsato($rapinatoreId);
        $bene = Listino::beni()[(int) $c['bene_id']] ?? null;
        $ing = $bene === null ? 1 : (int) $bene['ingombro'];
        $quante = min((int) $c['quantita'], $ing > 0 ? intdiv(max(0, $spazio), $ing) : 0);

        if ($quante > 0) {
            $medio = (int) round((int) $c['costo_totale'] / max(1, (int) $c['quantita']));
            Database::run(
                'INSERT INTO carico (personaggio_id, bene_id, quantita, costo_totale) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantita = quantita + VALUES(quantita),
                                         costo_totale = costo_totale + VALUES(costo_totale)',
                [$rapinatoreId, (int) $c['bene_id'], $quante, $medio * $quante]
            );
        }

        $nomeR = self::nome($rapinatoreId);
        Legge::segnale((int) $c['personaggio_id'], 'rapina',
            $c['corriere'] . ' è stato fermato per strada. Non erano poliziotti.', 5);
        Cronaca::scrivi('rapina', 'Un carico è stato preso per strada. Si fa il nome di ' . $nomeR . '.',
            (int) $c['da_piazza_id'], 3);
        Organico::reputazione($rapinatoreId, 0.0, 4.0);

        return ['ok' => true, 'unita' => $quante];
    }

    // --- Aiutanti -----------------------------------------------------------------------

    public static function nome(int $personaggioId): string
    {
        $r = Database::first('SELECT u.username FROM personaggi p JOIN users u ON u.id = p.user_id WHERE p.id = ?',
            [$personaggioId]);
        return (string) ($r['username'] ?? 'qualcuno');
    }

    /** Chi altro è qui, con quello che si può fare. @return list<array<string,mixed>> */
    public static function quiConMe(int $personaggioId, int $piazzaId): array
    {
        return Database::all(
            'SELECT p.id, u.username, p.salute, p.ospedale_fino_a, p.carcere_fino_a, p.profilo,
                    b.sigla AS batteria,
                    (SELECT COUNT(*) FROM spie s WHERE s.padrone_id = ? AND s.bersaglio_id = p.id
                      AND s.esito = \'dentro\') AS spiato
               FROM personaggi p JOIN users u ON u.id = p.user_id
               LEFT JOIN batterie b ON b.id = p.batteria_id
              WHERE p.piazza_id = ? AND p.arrivo_at IS NULL AND p.id <> ? AND u.status = \'active\'
              ORDER BY u.username LIMIT 40',
            [$personaggioId, $piazzaId, $personaggioId]
        );
    }

    /** Le corse altrui che passano da qui. @return list<array<string,mixed>> */
    public static function corseQui(int $personaggioId, int $piazzaId): array
    {
        return Database::all(
            'SELECT c.id, c.quantita, b.nome AS bene, u.username AS padrone,
                    zd.nome AS da_piazza, za.nome AS a_piazza, c.arrivo_at
               FROM corse c JOIN beni b ON b.id = c.bene_id
               JOIN personaggi p ON p.id = c.personaggio_id JOIN users u ON u.id = p.user_id
               JOIN piazze zd ON zd.id = c.da_piazza_id JOIN piazze za ON za.id = c.a_piazza_id
              WHERE c.esito = \'in_corso\' AND c.personaggio_id <> ?
                AND (c.da_piazza_id = ? OR c.a_piazza_id = ?)
              ORDER BY c.arrivo_at LIMIT 20',
            [$personaggioId, $piazzaId, $piazzaId]
        );
    }
}
