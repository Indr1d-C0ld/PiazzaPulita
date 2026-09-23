<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Config;
use App\Sim\Clock;
use App\Sim\Crescita;
use App\Sim\Mercato;
use App\Sim\Prezzi;
use App\Sim\Rifornimento;

/**
 * Il mercato visto dal database: semina, avanzamento, ordini.
 *
 * La matematica non è qui — sta in `App\Sim\Mercato`, che è puro e si prova coi
 * numeri. Qui c'è quello che la matematica non sa: dove si trova lo stato, chi
 * ha il diritto di cambiarlo, e come si fa senza che due giocatori comprino la
 * stessa unità.
 *
 * **Lettura e scrittura seguono due strade diverse, apposta.** Per mostrare un
 * listino non si scrive niente: si legge lo stato salvato e lo si *proietta*
 * fino ad adesso con una funzione pura. Guardare una piazza non deve costare
 * una scrittura, o cento giocatori che aprono cento pagine metterebbero in
 * ginocchio il database per niente. Lo stato si persiste solo quando serve
 * davvero: quando qualcuno compra o vende, e a ogni battito.
 */
final class Listino
{
    /** @var array<int,array<string,mixed>>|null */
    private static ?array $beni = null;

    // --- Beni e parametri ----------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public static function beni(): array
    {
        if (self::$beni !== null) {
            return self::$beni;
        }
        $fuori = [];
        foreach (Database::all('SELECT * FROM beni ORDER BY ordine, id') as $r) {
            $r['prezzo_min'] = (int) $r['prezzo_min'];
            $r['prezzo_max'] = (int) $r['prezzo_max'];
            $r['ingombro']   = (int) $r['ingombro'];
            $r['quota']      = (float) $r['quota'];
            $r['spread_frazione'] = (float) $r['spread_frazione'];
            $fuori[(int) $r['id']] = $r;
        }
        return self::$beni = $fuori;
    }

    /**
     * I parametri del mercato, eventualmente visti dagli occhi di qualcuno.
     *
     * La trattativa stringe lo spread: è l'unico attributo che tocca
     * direttamente il prezzo, e si vede subito. A cento gradi si paga il 40 %
     * in meno di margine al venditore di piazza.
     *
     * @param array<string,mixed>|null $p
     * @return array{e:float,z:float,spread:float,banda_bassa:float,banda_alta:float}
     */
    public static function parametri(?array $p = null): array
    {
        $spread = (float) GameConfig::get('mercato.spread', 0.03);
        if ($p !== null) {
            $spread = Crescita::spread($spread, (float) ($p['trattativa'] ?? 0));
        }
        return [
            'e'           => (float) GameConfig::get('mercato.elasticita_offerta', 0.60),
            'z'           => (float) GameConfig::get('mercato.elasticita_domanda', 0.60),
            'spread'      => $spread,
            'banda_bassa' => (float) GameConfig::get('mercato.banda_bassa', 0.20),
            'banda_alta'  => (float) GameConfig::get('mercato.banda_alta', 5.00),
        ];
    }

    private static function seme(): int
    {
        return (int) Config::get('mondo.seme', 19841984);
    }

    // --- Prezzi di riferimento ----------------------------------------------

    /**
     * Porta tutti i prezzi di riferimento al passo corrente e li salva.
     *
     * @return int quanti passi ha recuperato il bene più indietro
     */
    public static function avanzaPrezzi(): int
    {
        $minuti  = max(1, GameConfig::int('prezzi.passo_minuti', 5));
        $ritorno = (float) GameConfig::get('prezzi.ritorno', 0.02);
        $vol     = (float) GameConfig::get('prezzi.volatilita_base', 0.010);
        $adesso  = Prezzi::passo(Clock::adesso()->getTimestamp(), $minuti);
        $seme    = self::seme();

        $max = 0;
        foreach (self::beni() as $id => $b) {
            $riga = Database::first('SELECT p0, passo FROM prezzi WHERE bene_id = ?', [$id]);
            if ($riga === null) {
                continue;
            }
            $passo = (int) $riga['passo'];
            if ($passo >= $adesso) {
                continue;
            }
            $max = max($max, $adesso - $passo);

            $n = Prezzi::avanza(
                (float) $riga['p0'], $passo, $adesso, $id,
                (float) $b['prezzo_min'], (float) $b['prezzo_max'],
                $seme, $ritorno, $vol
            );
            Database::run('UPDATE prezzi SET p0 = ?, passo = ? WHERE bene_id = ?',
                [(int) round($n['p0']), $n['passo'], $id]);
        }
        return $max;
    }

    /** @return array<int,float> prezzo di riferimento per bene, proiettato ad adesso */
    public static function riferimenti(): array
    {
        $minuti  = max(1, GameConfig::int('prezzi.passo_minuti', 5));
        $ritorno = (float) GameConfig::get('prezzi.ritorno', 0.02);
        $vol     = (float) GameConfig::get('prezzi.volatilita_base', 0.010);
        $adesso  = Prezzi::passo(Clock::adesso()->getTimestamp(), $minuti);
        $seme    = self::seme();

        $fuori = [];
        foreach (Database::all('SELECT bene_id, p0, passo FROM prezzi') as $r) {
            $id = (int) $r['bene_id'];
            $b  = self::beni()[$id] ?? null;
            if ($b === null) {
                continue;
            }
            $n = Prezzi::avanza((float) $r['p0'], (int) $r['passo'], $adesso, $id,
                (float) $b['prezzo_min'], (float) $b['prezzo_max'], $seme, $ritorno, $vol);
            $fuori[$id] = $n['p0'];
        }
        return $fuori;
    }

    // --- Lettura del listino -------------------------------------------------

    /**
     * Il listino di una piazza, proiettato ad adesso. Non scrive niente.
     *
     * @return list<array<string,mixed>>
     */
    public static function perPiazza(int $piazzaId, ?array $chi = null): array
    {
        $righe = Database::all('SELECT * FROM mercati WHERE piazza_id = ?', [$piazzaId]);
        if ($righe === []) {
            return [];
        }

        $rif  = self::riferimenti();
        $par  = self::parametri($chi);
        $beni = self::beni();
        $ora  = Clock::adesso()->getTimestamp();
        $oreR = (float) GameConfig::int('mercato.ore_rifornimento', 6);
        $oreA = (float) GameConfig::int('mercato.ore_assorbimento', 3);
        $seme = self::seme();

        $fuori = [];
        foreach ($righe as $r) {
            $beneId = (int) $r['bene_id'];
            $bene   = $beni[$beneId] ?? null;
            if ($bene === null) {
                continue;
            }
            $stato = self::proietta($r, $ora, $oreR, $oreA, $seme);
            $m = [
                'p0'         => $rif[$beneId] ?? (float) (($bene['prezzo_min'] + $bene['prezzo_max']) / 2),
                'd'          => (float) $r['mult_d'],
                'offerta'    => $stato['offerta'],
                'offerta_eq' => (float) $r['offerta_eq'],
                'domanda'    => $stato['domanda'],
                'domanda_eq' => (float) $r['domanda_eq'],
                'shock'      => $stato['shock'],
            ];

            $forn = $chi === null ? ['sconto' => 0.0, 'fornitore' => null, 'manca' => 0]
                : Fornitori::perAcquisto($chi, $bene, PHP_INT_MAX);

            $fuori[] = [
                'fornitore'  => $forn['fornitore'],
                'bene'       => $bene,
                'acquisto'   => (int) round(Mercato::prezzoAcquisto($m, $par)),
                'vendita'    => (int) round(Mercato::prezzoVendita($m, $par)),
                'offerta'    => (int) floor($stato['offerta']),
                'domanda'    => (int) floor($stato['domanda']),
                'offerta_eq' => (float) $r['offerta_eq'],
                'domanda_eq' => (float) $r['domanda_eq'],
                'riferimento'=> (int) round(($rif[$beneId] ?? 0) * (float) $r['mult_d']),
                'carichi'    => $stato['carichi'],
                'stato_m'    => $m,
            ];
        }

        usort($fuori, static fn($a, $b) => ($a['bene']['ordine'] <=> $b['bene']['ordine']));
        return $fuori;
    }

    /**
     * Proietta una riga di mercato fino a un istante. Pura rispetto al database:
     * legge una riga e restituisce dei numeri, senza scrivere.
     *
     * @param array<string,mixed> $r
     * @return array{offerta:float,domanda:float,shock:float,carichi:int}
     */
    private static function proietta(array $r, int $ora, float $oreR, float $oreA, int $seme): array
    {
        $aggA = Clock::daDb((string) $r['agg_a']);
        $da   = $aggA?->getTimestamp() ?? $ora;
        $shockFino = Clock::daDb($r['shock_fino_a'] === null ? null : (string) $r['shock_fino_a']);
        $shock = $shockFino !== null && $shockFino->getTimestamp() > $ora ? (float) $r['shock'] : 0.0;

        return Rifornimento::avanza(
            [
                'offerta'    => (float) $r['offerta'],
                'offerta_eq' => (float) $r['offerta_eq'],
                'domanda'    => (float) $r['domanda'],
                'domanda_eq' => (float) $r['domanda_eq'],
                'shock'      => $shock,
            ],
            max(0, $ora - $da),
            (int) $r['piazza_id'], (int) $r['bene_id'], $seme, $da, $oreR, $oreA
        );
    }

    // --- Avanzamento persistito ---------------------------------------------

    /** Porta tutti i mercati ad adesso e li salva. Chiamato dal battito. */
    public static function avanzaTutti(): int
    {
        $ora  = Clock::adesso()->getTimestamp();
        $oreR = (float) GameConfig::int('mercato.ore_rifornimento', 6);
        $oreA = (float) GameConfig::int('mercato.ore_assorbimento', 3);
        $seme = self::seme();
        $n = 0;

        // Si scrive solo se la riga è ancora quella letta. Un ordine che passa
        // fra la lettura e la scrittura ha già portato la riga ad adesso, con
        // dentro l'acquisto o la vendita: riscriverla col valore proiettato
        // da prima cancellava l'ordine dal mercato — la merce comprata tornava
        // sul banco e il prezzo non si muoveva, cioè il tetto al reddito del
        // mondo aveva una falla che si apriva una volta al minuto.
        foreach (Database::all('SELECT * FROM mercati') as $r) {
            $s = self::proietta($r, $ora, $oreR, $oreA, $seme);
            $n += Database::run(
                'UPDATE mercati SET offerta = ?, domanda = ?, shock = ?, agg_a = ?
                  WHERE piazza_id = ? AND bene_id = ? AND agg_a = ?',
                [round($s['offerta'], 3), round($s['domanda'], 3), round($s['shock'], 3),
                 Clock::perDb(), (int) $r['piazza_id'], (int) $r['bene_id'], $r['agg_a']]
            )->rowCount();
        }
        return $n;
    }

    // --- Ordini --------------------------------------------------------------

    /**
     * Compra o vende. Tutto dentro una transazione con la riga di mercato
     * bloccata: due giocatori che premono insieme non devono poter comprare la
     * stessa unità, e nessuno deve poter vendere in un assorbimento che un
     * altro ha appena consumato.
     *
     * @return array{ok:bool,error?:string,quantita?:int,totale?:int,medio?:int,margine?:int}
     */
    public static function ordina(int $personaggioId, int $piazzaId, int $beneId, string $verso, int $quantita): array
    {
        if (!in_array($verso, ['acquisto', 'vendita'], true)) {
            return ['ok' => false, 'error' => 'Ordine incomprensibile.'];
        }
        if ($quantita <= 0) {
            return ['ok' => false, 'error' => 'Quantità non valida.'];
        }
        $bene = self::beni()[$beneId] ?? null;
        if ($bene === null) {
            return ['ok' => false, 'error' => 'Questa merce non esiste.'];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Personaggio inesistente.'];
            }
            if ($p['arrivo_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'In viaggio non si tratta.'];
            }
            if (Legge::inCarcere($p)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Da dentro non si compra e non si vende.'];
            }
            if (Rivalita::inOspedale($p)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Con due costole rotte non si tratta.'];
            }
            if ((int) $p['piazza_id'] !== $piazzaId) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Non sei in questa piazza.'];
            }

            $r = Database::first(
                'SELECT * FROM mercati WHERE piazza_id = ? AND bene_id = ? FOR UPDATE',
                [$piazzaId, $beneId]
            );
            if ($r === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Qui questa roba non gira.'];
            }

            // Si porta il mercato ad adesso e lo si salva: da qui in poi il
            // conto è fatto su numeri che stanno nel database, non su una
            // proiezione che un altro processo potrebbe non aver visto.
            $ora  = Clock::adesso()->getTimestamp();
            $oreR = (float) GameConfig::int('mercato.ore_rifornimento', 6);
            $oreA = (float) GameConfig::int('mercato.ore_assorbimento', 3);
            $s    = self::proietta($r, $ora, $oreR, $oreA, self::seme());

            $rif = self::riferimenti();
            $par = self::parametri($p);
            $m = [
                'p0'         => $rif[$beneId] ?? (float) (($bene['prezzo_min'] + $bene['prezzo_max']) / 2),
                'd'          => (float) $r['mult_d'],
                'offerta'    => $s['offerta'],
                'offerta_eq' => (float) $r['offerta_eq'],
                'domanda'    => $s['domanda'],
                'domanda_eq' => (float) $r['domanda_eq'],
                'shock'      => $s['shock'],
            ];

            $carico = Database::first(
                'SELECT quantita, costo_totale FROM carico WHERE personaggio_id = ? AND bene_id = ?',
                [$personaggioId, $beneId]
            ) ?? ['quantita' => 0, 'costo_totale' => 0];

            $esito = $verso === 'acquisto'
                ? self::eseguiAcquisto($p, $m, $par, $bene, $carico, $quantita, $personaggioId, $beneId)
                : self::eseguiVendita($p, $m, $par, $carico, $quantita, $personaggioId, $beneId);

            if (!$esito['ok']) {
                $pdo->rollBack();
                return $esito;
            }

            Database::run(
                'UPDATE mercati SET offerta = ?, domanda = ?, shock = ?, agg_a = ?
                  WHERE piazza_id = ? AND bene_id = ?',
                [round($esito['offerta'], 3), round($esito['domanda'], 3), round($s['shock'], 3),
                 Clock::perDb(), $piazzaId, $beneId]
            );
            Database::run(
                'INSERT INTO transazioni (personaggio_id, piazza_id, bene_id, verso, quantita, prezzo_medio, totale, margine, fatto_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$personaggioId, $piazzaId, $beneId, $verso, $esito['quantita'],
                 $esito['medio'], $esito['totale'], $esito['margine'] ?? null, Clock::perDb()]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Si impara trattando. Il peso è logaritmico sul valore: un colpo
        // grosso insegna più di uno piccolo, ma non in proporzione — se no
        // basterebbe una vendita sola per diventare maestri.
        // Il territorio si tiene lavorandoci: ogni operazione lascia presenza
        // alla batteria di chi l'ha fatta, e paga il pizzo a chi comanda qui.
        // Fuori dalla transazione apposta: se il pizzo fallisce non deve
        // cancellare una compravendita già conclusa e già contabilizzata.
        Batteria::lavorato($personaggioId, $piazzaId, (int) $esito['totale']);

        $peso = Crescita::pesoValore((int) $esito['totale']);
        Organico::cresci($personaggioId, 'trattativa', $peso);
        // L'organizzazione cresce MANDANDO AVANTI un giro, non assumendo:
        // all'inizio si regge un uomo solo, e se crescesse solo assumendo il
        // tetto non si alzerebbe mai. È un vicolo cieco che si vede solo
        // giocando — sulla carta sembrava che una cosa alimentasse l'altra.
        Organico::cresci($personaggioId, 'organizzazione', $peso);
        if ($verso === 'vendita') {
            Organico::cresci($personaggioId, 'fiuto', $peso * 0.6);
            // Il rispetto si costruisce facendo girare merce, non parlandone.
            Organico::reputazione($personaggioId, min(0.8, $peso * 0.25));
        }

        return ['ok' => true, 'quantita' => $esito['quantita'], 'totale' => $esito['totale'],
                'medio' => $esito['medio'], 'margine' => $esito['margine'] ?? null,
                'fornitore' => $esito['fornitore'] ?? null];
    }

    /**
     * @param array<string,mixed> $p @param array<string,float> $m @param array<string,float> $par
     * @param array<string,mixed> $bene @param array<string,mixed> $carico
     */
    private static function eseguiAcquisto(array $p, array $m, array $par, array $bene, array $carico,
                                           int $quantita, int $personaggioId, int $beneId): array
    {
        $spazio = (int) $p['capienza'] - self::ingombroUsato($personaggioId);
        $max = Mercato::quantoPosso($m, $par, (int) $p['contante'], $spazio, (int) $bene['ingombro']);
        if ($max <= 0) {
            return ['ok' => false, 'error' => (int) $p['contante'] < 1000
                ? 'Non hai i soldi.'
                : ($spazio < (int) $bene['ingombro'] ? 'Non hai più spazio addosso.' : 'Qui non ce n\'è.')];
        }

        $q = min($quantita, $max);
        $c = Mercato::costoAcquisto($m, $par, $q);
        if ($c['quantita'] === 0) {
            return ['ok' => false, 'error' => 'Qui non ce n\'è.'];
        }

        // Il fornitore, se ce l'hai e se compri abbastanza. Sconta il prezzo di
        // piazza, non lo sostituisce: il mercato resta quello di tutti, e il
        // margine si sposta dal venditore a te senza creare denaro dal nulla.
        $forn = Fornitori::perAcquisto($p, $bene, $c['quantita']);
        if ($forn['sconto'] > 0.0) {
            $c['totale'] = (int) round($c['totale'] * (1.0 - $forn['sconto']));
            $c['medio']  = (int) round($c['totale'] / max(1, $c['quantita']));
        }

        Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$c['totale'], $personaggioId]);
        Database::run(
            'INSERT INTO carico (personaggio_id, bene_id, quantita, costo_totale) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantita = quantita + VALUES(quantita),
                                     costo_totale = costo_totale + VALUES(costo_totale)',
            [$personaggioId, $beneId, $c['quantita'], $c['totale']]
        );

        return ['ok' => true, 'quantita' => $c['quantita'], 'totale' => $c['totale'], 'medio' => $c['medio'],
                'offerta' => $c['offerta_dopo'], 'domanda' => $m['domanda'], 'margine' => null,
                'fornitore' => $forn['sconto'] > 0.0 ? (string) $forn['fornitore']['nome'] : null];
    }

    /**
     * @param array<string,mixed> $p @param array<string,float> $m @param array<string,float> $par
     * @param array<string,mixed> $carico
     */
    private static function eseguiVendita(array $p, array $m, array $par, array $carico,
                                          int $quantita, int $personaggioId, int $beneId): array
    {
        $ho = (int) $carico['quantita'];
        if ($ho <= 0) {
            return ['ok' => false, 'error' => 'Non ne hai.'];
        }
        $q = min($quantita, $ho);
        $v = Mercato::ricavoVendita($m, $par, $q);
        if ($v['quantita'] === 0) {
            return ['ok' => false, 'error' => 'Qui non ne vogliono più: la piazza è satura.'];
        }
        $q = $v['quantita'];

        // Il costo della merce venduta, a media ponderata: serve a dire se è
        // stato un guadagno. È anche il numero che `balance:report` somma per
        // sapere quanto il mondo ha davvero prodotto.
        $costoMedio = $ho > 0 ? (int) round((int) $carico['costo_totale'] / $ho) : 0;
        $costoUscito = $costoMedio * $q;
        $margine = $v['totale'] - $costoUscito;

        Database::run('UPDATE personaggi SET contante = contante + ? WHERE id = ?', [$v['totale'], $personaggioId]);
        if ($q >= $ho) {
            Database::run('DELETE FROM carico WHERE personaggio_id = ? AND bene_id = ?', [$personaggioId, $beneId]);
        } else {
            Database::run(
                'UPDATE carico SET quantita = quantita - ?, costo_totale = GREATEST(0, costo_totale - ?)
                  WHERE personaggio_id = ? AND bene_id = ?',
                [$q, $costoUscito, $personaggioId, $beneId]
            );
        }

        return ['ok' => true, 'quantita' => $q, 'totale' => $v['totale'], 'medio' => $v['medio'],
                'offerta' => $m['offerta'], 'domanda' => $v['domanda_dopo'], 'margine' => $margine];
    }

    // --- Carico --------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function carico(int $personaggioId): array
    {
        $beni = self::beni();
        $fuori = [];
        foreach (Database::all('SELECT * FROM carico WHERE quantita > 0 AND personaggio_id = ?', [$personaggioId]) as $r) {
            $b = $beni[(int) $r['bene_id']] ?? null;
            if ($b === null) {
                continue;
            }
            $q = (int) $r['quantita'];
            $fuori[] = [
                'bene'     => $b,
                'quantita' => $q,
                'costo'    => (int) $r['costo_totale'],
                'medio'    => $q > 0 ? (int) round((int) $r['costo_totale'] / $q) : 0,
                'ingombro' => $q * (int) $b['ingombro'],
            ];
        }
        usort($fuori, static fn($a, $b) => $a['bene']['ordine'] <=> $b['bene']['ordine']);
        return $fuori;
    }

    public static function ingombroUsato(int $personaggioId): int
    {
        $r = Database::first(
            'SELECT COALESCE(SUM(c.quantita * b.ingombro), 0) i
               FROM carico c JOIN beni b ON b.id = c.bene_id WHERE c.personaggio_id = ?',
            [$personaggioId]
        );
        return (int) ($r['i'] ?? 0);
    }
}
