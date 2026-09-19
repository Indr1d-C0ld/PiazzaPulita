<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;

/**
 * Gli obiettivi.
 *
 * Il catalogo sta qui e non in una tabella: un obiettivo è una **condizione**,
 * e una condizione dentro il database diventa presto una lingua di
 * programmazione scritta male. Nel database finiscono solo quelli sbloccati.
 *
 * Due regole che valgono per tutti:
 *
 * 1. **Si sbloccano con fatti già registrati**, non con contatori scritti
 *    apposta. Tutto quello che serve sta nelle transazioni, nei movimenti, nei
 *    fascicoli e nella scheda del personaggio: se un obiettivo avesse bisogno
 *    di un contatore suo, vorrebbe dire che misura qualcosa che il gioco non
 *    stava già facendo.
 * 2. **Non danno vantaggi.** In un mondo a reddito orario finito (§2.6) un
 *    premio in denaro lo pagherebbero gli altri giocatori senza saperlo. Sono
 *    una traccia di quello che hai fatto, e basta.
 */
final class Obiettivi
{
    /**
     * Il catalogo.
     *
     * `raro` è quello che finisce sul giornale del mondo: le cose che quasi
     * nessuno fa, non quelle che fanno tutti nella prima ora.
     *
     * @return array<string,array{nome:string,testo:string,gruppo:string,raro:bool,cond:callable(array<string,mixed>):bool}>
     */
    public static function catalogo(): array
    {
        return [
            // --- Il mestiere ---------------------------------------------------
            'primo_affare' => [
                'nome' => 'Il primo affare', 'gruppo' => 'Il mestiere', 'raro' => false,
                'testo' => 'Vendere qualcosa, una volta.',
                'cond' => static fn(array $f) => $f['vendite'] >= 1,
            ],
            'cento_affari' => [
                'nome' => 'Cento viaggi', 'gruppo' => 'Il mestiere', 'raro' => false,
                'testo' => 'Chiudere cento compravendite.',
                'cond' => static fn(array $f) => $f['operazioni'] >= 100,
            ],
            'colpo_grosso' => [
                'nome' => 'Il colpo grosso', 'gruppo' => 'Il mestiere', 'raro' => false,
                'testo' => 'Guadagnare più di cinque milioni con una sola vendita.',
                'cond' => static fn(array $f) => $f['margine_max'] >= 5_000_000,
            ],
            'listino_intero' => [
                'nome' => 'Il listino intero', 'gruppo' => 'Il mestiere', 'raro' => true,
                'testo' => 'Aver trattato almeno una volta tutte e dieci le merci.',
                'cond' => static fn(array $f) => $f['beni_trattati'] >= $f['beni_totali'],
            ],
            'giro_italia' => [
                'nome' => 'Il giro d\'Italia', 'gruppo' => 'Il mestiere', 'raro' => true,
                'testo' => 'Aver lavorato in tutte e nove le città.',
                'cond' => static fn(array $f) => $f['citta_lavorate'] >= $f['citta_totali'],
            ],
            'fornitore' => [
                'nome' => 'Uno che ti conosce', 'gruppo' => 'Il mestiere', 'raro' => false,
                'testo' => 'Arrivare a venti di rispetto: il primo fornitore vuole parlarti.',
                'cond' => static fn(array $f) => $f['rispetto'] >= 20.0,
            ],

            // --- Il denaro -----------------------------------------------------
            'primo_milione' => [
                'nome' => 'Il primo milione pulito', 'gruppo' => 'Il denaro', 'raro' => false,
                'testo' => 'Avere un milione di lire pulite in cassa.',
                'cond' => static fn(array $f) => $f['pulito'] >= 1_000_000,
            ],
            'cento_milioni' => [
                'nome' => 'Nove zeri', 'gruppo' => 'Il denaro', 'raro' => true,
                'testo' => 'Avere cento milioni puliti in cassa.',
                'cond' => static fn(array $f) => $f['pulito'] >= 100_000_000,
            ],
            'senza_debiti' => [
                'nome' => 'Non devo niente a nessuno', 'gruppo' => 'Il denaro', 'raro' => false,
                'testo' => 'Restituire tutto all\'usuraio dopo aver preso almeno un prestito.',
                'cond' => static fn(array $f) => $f['prestiti'] >= 1 && $f['debito'] === 0,
            ],
            'lavanderia' => [
                'nome' => 'La lavanderia', 'gruppo' => 'Il denaro', 'raro' => false,
                'testo' => 'Far passare cinquanta milioni per i canali.',
                'cond' => static fn(array $f) => $f['lavato'] >= 50_000_000,
            ],
            'flotta' => [
                'nome' => 'Il furgone', 'gruppo' => 'Il denaro', 'raro' => false,
                'testo' => 'Comprarsi il mezzo più grande.',
                'cond' => static fn(array $f) => $f['mezzo'] === 'furgone',
            ],
            'catena' => [
                'nome' => 'La catena di depositi', 'gruppo' => 'Il denaro', 'raro' => true,
                'testo' => 'Tenere aperti cinque depositi insieme.',
                'cond' => static fn(array $f) => $f['depositi'] >= 5,
            ],

            // --- La legge ------------------------------------------------------
            'primo_segnale' => [
                'nome' => 'L\'auto sotto casa', 'gruppo' => 'La legge', 'raro' => false,
                'testo' => 'Accorgersi che qualcuno ha cominciato a guardarti.',
                'cond' => static fn(array $f) => $f['segnali'] >= 1,
            ],
            'archiviato' => [
                'nome' => 'Archiviato', 'gruppo' => 'La legge', 'raro' => true,
                'testo' => 'Far chiudere un fascicolo senza essere preso. Si smette in tempo.',
                'cond' => static fn(array $f) => $f['archiviati'] >= 1,
            ],
            'dentro_e_fuori' => [
                'nome' => 'Dentro e fuori', 'gruppo' => 'La legge', 'raro' => false,
                'testo' => 'Uscire dal carcere e rimettersi a lavorare.',
                'cond' => static fn(array $f) => $f['arresti'] >= 1 && !$f['in_carcere'],
            ],
            'recidivo' => [
                'nome' => 'Recidivo', 'gruppo' => 'La legge', 'raro' => false,
                'testo' => 'Farsi prendere tre volte. Non è un complimento.',
                'cond' => static fn(array $f) => $f['arresti'] >= 3,
            ],
            'incensurato' => [
                'nome' => 'L\'incensurato', 'gruppo' => 'La legge', 'raro' => true,
                'testo' => 'Trenta giorni senza arresti né ospedale, con il profilo criminale sopra cinque.',
                'cond' => static fn(array $f) => $f['giorni_pulito'] >= 30 && $f['profilo'] >= 5,
            ],

            // --- Gli altri -----------------------------------------------------
            'primo_scontro' => [
                'nome' => 'Le mani addosso', 'gruppo' => 'Gli altri', 'raro' => false,
                'testo' => 'Vincere uno scontro con un altro giocatore.',
                'cond' => static fn(array $f) => $f['vinti'] >= 1,
            ],
            'pubblico_nemico' => [
                'nome' => 'Pubblico nemico', 'gruppo' => 'Gli altri', 'raro' => true,
                'testo' => 'Arrivare a venti di profilo criminale. Da qui non si torna indietro.',
                'cond' => static fn(array $f) => $f['profilo'] >= 20,
            ],
            'la_spia' => [
                'nome' => 'Uno dentro casa', 'gruppo' => 'Gli altri', 'raro' => false,
                'testo' => 'Tenere una spia infiltrata da qualche altra parte.',
                'cond' => static fn(array $f) => $f['spie'] >= 1,
            ],
            'batteria' => [
                'nome' => 'La batteria', 'gruppo' => 'Gli altri', 'raro' => false,
                'testo' => 'Entrare in una batteria, o fondarne una.',
                'cond' => static fn(array $f) => $f['batteria'] > 0,
            ],
            'padrone_di_casa' => [
                'nome' => 'Padroni di casa', 'gruppo' => 'Gli altri', 'raro' => true,
                'testo' => 'Essere in una batteria che tiene tre piazze insieme.',
                'cond' => static fn(array $f) => $f['territori'] >= 3,
            ],
            'organico' => [
                'nome' => 'La squadra', 'gruppo' => 'Gli altri', 'raro' => false,
                'testo' => 'Avere cinque uomini che lavorano per te.',
                'cond' => static fn(array $f) => $f['uomini'] >= 5,
            ],
            'nessuno_parla' => [
                'nome' => 'Nessuno parla', 'gruppo' => 'Gli altri', 'raro' => true,
                'testo' => 'Avere almeno tre uomini, tutti sopra novanta di lealtà.',
                'cond' => static fn(array $f) => $f['uomini'] >= 3 && $f['uomini_fedeli'] === $f['uomini'],
            ],
        ];
    }

    /**
     * Verifica e sblocca. Ritorna i codici sbloccati ADESSO (di solito nessuno).
     *
     * Costa una manciata di aggregati, quindi si chiama dove ha senso — sulla
     * pagina degli obiettivi e dal battito per chi è stato visto di recente —
     * e non a ogni richiesta.
     *
     * @return list<string>
     */
    public static function verifica(int $personaggioId): array
    {
        $catalogo = self::catalogo();
        $gia = self::sbloccati($personaggioId);
        $restano = array_diff(array_keys($catalogo), array_keys($gia));
        if ($restano === []) {
            return [];
        }

        $fatti = self::fatti($personaggioId);
        if ($fatti === null) {
            return [];
        }

        $nuovi = [];
        foreach ($restano as $codice) {
            $o = $catalogo[$codice];
            try {
                if (!($o['cond'])($fatti)) {
                    continue;
                }
            } catch (\Throwable $e) {
                logger('obiettivo ' . $codice . ' non valutabile: ' . $e->getMessage(), 'warning');
                continue;
            }

            // La chiave primaria fa da guardia: se due processi arrivano
            // insieme, uno dei due perde e va bene così.
            try {
                Database::run(
                    'INSERT INTO obiettivi (personaggio_id, codice, sbloccato_at) VALUES (?, ?, ?)',
                    [$personaggioId, $codice, Clock::perDb()]
                );
            } catch (\Throwable) {
                continue;
            }
            $nuovi[] = $codice;

            Legge::segnale($personaggioId, 'obiettivo', 'Obiettivo raggiunto: ' . $o['nome'] . '.', 1);
            if ($o['raro'] && GameConfig::bool('obiettivi.in_cronaca', true)) {
                Cronaca::scrivi('obiettivo',
                    Rivalita::nome($personaggioId) . ' — ' . mb_strtolower($o['nome']) . '.', null, 2);
            }
        }

        return $nuovi;
    }

    /** @return array<string,string> codice => quando */
    public static function sbloccati(int $personaggioId): array
    {
        $fuori = [];
        foreach (Database::all('SELECT codice, sbloccato_at FROM obiettivi WHERE personaggio_id = ?',
                               [$personaggioId]) as $r) {
            $fuori[(string) $r['codice']] = (string) $r['sbloccato_at'];
        }
        return $fuori;
    }

    /** Quanti ne hanno sbloccato gli altri: serve a dire quanto è raro. @return array<string,int> */
    public static function diffusione(): array
    {
        $fuori = [];
        foreach (Database::all('SELECT codice, COUNT(*) n FROM obiettivi GROUP BY codice') as $r) {
            $fuori[(string) $r['codice']] = (int) $r['n'];
        }
        return $fuori;
    }

    /**
     * I fatti su cui si giudica. Tutto già registrato altrove.
     *
     * @return array<string,mixed>|null
     */
    private static function fatti(int $personaggioId): ?array
    {
        $p = Database::first('SELECT * FROM personaggi WHERE id = ?', [$personaggioId]);
        if ($p === null) {
            return null;
        }

        $t = Database::first(
            "SELECT COUNT(*) ops,
                    SUM(verso = 'vendita') vendite,
                    COALESCE(MAX(margine), 0) margine_max,
                    COUNT(DISTINCT bene_id) beni
               FROM transazioni WHERE personaggio_id = ?", [$personaggioId]) ?? [];

        $citta = Database::first(
            'SELECT COUNT(DISTINCT z.citta_id) n FROM transazioni t JOIN piazze z ON z.id = t.piazza_id
              WHERE t.personaggio_id = ?', [$personaggioId]);

        $lavato = Database::first(
            "SELECT COALESCE(SUM(importo), 0) s FROM movimenti
              WHERE personaggio_id = ? AND genere = 'lavaggio' AND cassa = 'pulito'", [$personaggioId]);
        $prestiti = Database::first(
            "SELECT COUNT(*) n FROM movimenti WHERE personaggio_id = ? AND genere = 'prestito' AND importo > 0",
            [$personaggioId]);

        $uomini = Database::first(
            "SELECT COUNT(*) n, SUM(lealta >= 90) fedeli FROM uomini
              WHERE personaggio_id = ? AND stato <> 'sparito'", [$personaggioId]);

        $bat = $p['batteria_id'] === null ? 0 : (int) $p['batteria_id'];
        $terr = $bat === 0 ? 0 : (int) (Database::first(
            'SELECT COUNT(*) n FROM territori WHERE batteria_id = ?', [$bat])['n'] ?? 0);

        $pulitoDal = Clock::daDb(($p['pulito_dal'] ?? null) === null ? null : (string) $p['pulito_dal']);
        $giorniPulito = $pulitoDal === null ? 0
            : (int) floor((Clock::adesso()->getTimestamp() - $pulitoDal->getTimestamp()) / 86400);

        return [
            'operazioni'     => (int) ($t['ops'] ?? 0),
            'vendite'        => (int) ($t['vendite'] ?? 0),
            'margine_max'    => (int) ($t['margine_max'] ?? 0),
            'beni_trattati'  => (int) ($t['beni'] ?? 0),
            'beni_totali'    => (int) (Database::first('SELECT COUNT(*) n FROM beni')['n'] ?? 10),
            'citta_lavorate' => (int) ($citta['n'] ?? 0),
            'citta_totali'   => (int) (Database::first('SELECT COUNT(*) n FROM citta')['n'] ?? 9),
            'pulito'         => (int) $p['pulito'],
            'debito'         => (int) $p['debito'],
            'prestiti'       => (int) ($prestiti['n'] ?? 0),
            'lavato'         => (int) ($lavato['s'] ?? 0),
            'mezzo'          => (string) ($p['mezzo'] ?? ''),
            'depositi'       => (int) (Database::first('SELECT COUNT(*) n FROM depositi WHERE personaggio_id = ?',
                                       [$personaggioId])['n'] ?? 0),
            'segnali'        => (int) (Database::first('SELECT COUNT(*) n FROM segnali WHERE personaggio_id = ?',
                                       [$personaggioId])['n'] ?? 0),
            'archiviati'     => (int) (Database::first(
                                       "SELECT COUNT(*) n FROM fascicoli WHERE personaggio_id = ? AND stato = 'archiviato'",
                                       [$personaggioId])['n'] ?? 0),
            'arresti'        => (int) $p['arresti'],
            'in_carcere'     => Legge::inCarcere($p),
            'profilo'        => (int) $p['profilo'],
            'rispetto'       => (float) $p['rispetto'],
            'giorni_pulito'  => $giorniPulito,
            'vinti'          => (int) $p['scontri_vinti'],
            'spie'           => (int) (Database::first(
                                       "SELECT COUNT(*) n FROM spie WHERE padrone_id = ? AND esito = 'dentro'",
                                       [$personaggioId])['n'] ?? 0),
            'batteria'       => $bat,
            'territori'      => $terr,
            'uomini'         => (int) ($uomini['n'] ?? 0),
            'uomini_fedeli'  => (int) ($uomini['fedeli'] ?? 0),
        ];
    }
}
