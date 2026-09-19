<?php

declare(strict_types=1);

/**
 * Strumento di collaudo: fa giocare N personaggi VERI contro il motore vero e
 * verifica gli invarianti che solo un mondo popolato può rompere.
 *
 *   PIAZZAPULITA_CONFIG=config/config.php php bin/_simula_mondo.php 12 24 cattivo
 *                                                                   │  │   └ anche prestiti,
 *                                                                   │  │     riciclaggio, uomini,
 *                                                                   │  │     batterie e botte
 *                                                                   │  └ ore di mondo
 *                                                                   └ quanti giocatori
 *
 * A differenza di `_simula_principiante.php`, che è una simulazione **ombra**
 * (calcola i prezzi ma tiene i soldi per conto suo), qui passa tutto dal
 * database: `Listino::ordina`, `Personaggio::parti`, `Contabilita`, `Legge`,
 * `Rivalita`. È l'unico modo di vedere i bachi che nascono dalla persistenza e
 * dalla contesa, invece che dalla matematica.
 *
 * I tre invarianti:
 *
 *   1. LA CASSA TORNA. Per ogni giocatore, il contante finale dev'essere
 *      esattamente quello iniziale più le vendite meno gli acquisti più i
 *      movimenti registrati. Se non torna, da qualche parte nascono o
 *      spariscono lire — ed è così che si è scoperto che il biglietto del
 *      viaggio non finiva nel registro.
 *   2. NIENTE SALDI NEGATIVI, su nessuna cassa e su nessun magazzino.
 *   3. IL TETTO TIENE: nessuna ora sopra il reddito orario del mondo (§2.6).
 *
 * ATTENZIONE: questo strumento AZZERA il mondo su cui gira. Si rifiuta di
 * partire se nel database c'è anche un solo account vero.
 */

$root = require __DIR__ . '/_bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\GameConfig;
use App\Game\Batteria;
use App\Game\Contabilita;
use App\Game\Legge;
use App\Game\Listino;
use App\Game\Mondo;
use App\Game\Obiettivi;
use App\Game\Organico;
use App\Game\Personaggio;
use App\Game\Rivalita;
use App\Sim\Clock;

$argomenti = array_slice($_SERVER['argv'], 1);
$quanti  = max(1, (int) ($argomenti[0] ?? 8));
$ore     = max(1, (int) ($argomenti[1] ?? 24));
$cattivo = in_array('cattivo', $argomenti, true);

// --- La sicura ------------------------------------------------------------------
$veri = Database::all("SELECT username FROM users WHERE username NOT LIKE 'sim%'");
if ($veri !== []) {
    fwrite(STDERR, "RIFIUTO: questo database ha account veri (" . count($veri) . ", p.es. «"
        . $veri[0]['username'] . "»).\n"
        . "Questo strumento azzera il mondo: va lanciato solo sul database usa-e-getta.\n"
        . "  PIAZZAPULITA_CONFIG=config/config.php php bin/_simula_mondo.php\n");
    exit(1);
}

printf("Simulazione del mondo — %d giocatori, %d ore%s\n", $quanti, $ore, $cattivo ? ', mondo cattivo' : '');
printf("database: %s\n\n", Config::get('db.name', '?'));

// --- Si azzera e si popola --------------------------------------------------------
Database::run('SET FOREIGN_KEY_CHECKS=0');
foreach (['obiettivi', 'albo', 'primati', 'scontri', 'spie', 'soffiate', 'presenze', 'territori',
          'batterie', 'cronaca', 'segnali', 'fascicoli', 'corse', 'uomini', 'deposito_merce',
          'depositi', 'canali_posseduti', 'carico', 'transazioni', 'movimenti', 'spostamenti',
          'personaggi', 'users'] as $t) {
    Database::run("DELETE FROM {$t}");
}
Database::run('SET FOREIGN_KEY_CHECKS=1');
Database::run('UPDATE mercati SET offerta = offerta_eq, domanda = domanda_eq, shock = 0, agg_a = NOW(3)');
Database::run('UPDATE piazze SET calore = 0, calore_agg_a = NULL');

$citta = array_values(Mondo::citta());
$giocatori = [];
for ($i = 0; $i < $quanti; $i++) {
    Database::run(
        "INSERT INTO users (username, email, password_hash, status, role, created_at, email_verified_at)
         VALUES (?, ?, '', 'active', 'player', NOW(), NOW())",
        ['sim' . $i, 'sim' . $i . '@esempio.invalid']
    );
    // In «cattivo» si mettono in tre per città: se non si incontrano mai, il
    // PvP e il territorio non vengono esercitati affatto.
    $c = $cattivo ? $citta[intdiv($i, 3) % count($citta)] : $citta[$i % count($citta)];
    $res = Personaggio::crea(Database::lastInsertId(), (int) $c['id']);
    $p = $res['personaggio'];
    $giocatori[] = [
        'id' => (int) $p['id'], 'nome' => 'sim' . $i, 'citta' => (string) $c['nome'],
        'iniziale' => (int) $p['contante'],
        'viaggi' => 0, 'ordini' => 0, 'scontri' => 0, 'prestito' => false, 'batteria' => false,
    ];
}

// --- Si gioca -----------------------------------------------------------------------
$partenza = new DateTimeImmutable('2026-09-20 08:00:00');
Clock::fissa($partenza);
$fine = $partenza->modify('+' . $ore . ' hours');
$estrattoPerOra = [];
$giri = 0;

while (Clock::adesso() < $fine) {
    $giri++;
    foreach ($giocatori as &$g) {
        $p = Database::first('SELECT * FROM personaggi WHERE id = ?', [$g['id']]);
        if ($p === null || Legge::inCarcere($p) || Rivalita::inOspedale($p) || $p['arrivo_at'] !== null) {
            continue;
        }
        $qui = (int) $p['piazza_id'];

        // Vende quello che ha, se ci guadagna.
        foreach (Listino::carico($g['id']) as $c) {
            $bid = (int) $c['bene']['id'];
            foreach (Listino::perPiazza($qui, $p) as $r) {
                if ((int) $r['bene']['id'] !== $bid) { continue; }
                $medio = (int) round($c['costo'] / max(1, $c['quantita']));
                if ((int) $r['vendita'] > $medio * 1.08
                    && !empty(Listino::ordina($g['id'], $qui, $bid, 'vendita', (int) $c['quantita'])['ok'])) {
                    $g['ordini']++;
                }
            }
        }

        // Compra la merce col margine di rotta migliore verso una piazza vicina.
        $p = Database::first('SELECT * FROM personaggi WHERE id = ?', [$g['id']]);
        $vicine = [];
        foreach (Mondo::piazzeDi((int) Mondo::cittaDi($qui)['id']) as $z) {
            if ((int) $z['id'] !== $qui) { $vicine[] = (int) $z['id']; }
        }
        if ($vicine === []) { continue; }
        $meta = $vicine[array_rand($vicine)];

        $qua = []; $la = [];
        foreach (Listino::perPiazza($qui, $p) as $r)  { $qua[(int) $r['bene']['id']] = $r; }
        foreach (Listino::perPiazza($meta, $p) as $r) { $la[(int) $r['bene']['id']]  = $r; }

        $migliore = null; $margine = 1.10;
        foreach ($qua as $bid => $r) {
            if (!isset($la[$bid]) || (int) $r['acquisto'] <= 0 || $r['offerta'] < 1) { continue; }
            $m = (float) $la[$bid]['vendita'] / max(1, (float) $r['acquisto']);
            if ($m > $margine) { $margine = $m; $migliore = $bid; }
        }
        if ($migliore !== null && !empty(Listino::ordina($g['id'], $qui, $migliore, 'acquisto', 10_000)['ok'])) {
            $g['ordini']++;
        }

        if ($cattivo) {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ?', [$g['id']]);
            if (!$g['prestito'] && (int) $p['debito'] === 0) {
                $g['prestito'] = true;
                Contabilita::prestito($g['id'], 1_500_000);
            }
            if ((int) $p['contante'] > 800_000) {
                Contabilita::metti($g['id'], 'bar', (int) ((int) $p['contante'] * 0.4));
            }
            if ($giri % 12 === 0 && (int) $p['contante'] > 2_000_000) {
                Organico::assumi($g['id'], ['vedetta', 'contabile', 'riciclatore', 'guardia'][$giri % 4]);
            }
            if (!$g['batteria'] && (int) $p['pulito'] >= GameConfig::int('batteria.fondazione', 10_000_000)) {
                if (!empty(Batteria::fonda($g['id'], 'Batteria ' . $g['nome'],
                        strtoupper(substr($g['nome'], 0, 4)), '')['ok'])) {
                    $g['batteria'] = true;
                }
            }
            if ($giri % 9 === 0) {
                $altri = Rivalita::quiConMe($g['id'], $qui);
                if ($altri !== [] && !empty(Rivalita::attacca($g['id'], (int) $altri[0]['id'])['ok'])) {
                    $g['scontri']++;
                }
            }
        }

        if (!empty(Personaggio::parti($g['id'], $meta, 'mezzi')['ok'])) { $g['viaggi']++; }
    }
    unset($g);

    // Il battito, come in produzione.
    Personaggio::avanzaTutti();
    Legge::avanzaFascicoli();
    Contabilita::avanzaTutti();
    Rivalita::dimetti();

    $ora = Clock::adesso()->format('Y-m-d H');
    $estrattoPerOra[$ora] = (int) (Database::first(
        "SELECT COALESCE(SUM(margine),0) m FROM transazioni
          WHERE verso='vendita' AND DATE_FORMAT(fatto_at, '%Y-%m-%d %H') = ?", [$ora])['m'] ?? 0);

    Clock::avanzaDi(300);
}

$esito = 0;
$riga = static function (bool $ok, string $t) use (&$esito): void {
    if (!$ok) { $esito = 1; }
    printf("  %s  %s\n", $ok ? "\033[0;32mok\033[0m" : "\033[0;31mKO\033[0m", $t);
};

// --- 1. La cassa torna ----------------------------------------------------------------
echo "\n1. LA CASSA TORNA (contante = iniziale + vendite - acquisti + movimenti)\n";
$rotte = 0;
foreach ($giocatori as $g) {
    $p = Database::first('SELECT contante, pulito FROM personaggi WHERE id = ?', [$g['id']]);
    $t  = (int) (Database::first("SELECT COALESCE(SUM(CASE WHEN verso='vendita' THEN totale ELSE -totale END),0) s
                                    FROM transazioni WHERE personaggio_id = ?", [$g['id']])['s'] ?? 0);
    $mS = (int) (Database::first("SELECT COALESCE(SUM(importo),0) s FROM movimenti
                                   WHERE personaggio_id = ? AND cassa='sporco'", [$g['id']])['s'] ?? 0);
    $mP = (int) (Database::first("SELECT COALESCE(SUM(importo),0) s FROM movimenti
                                   WHERE personaggio_id = ? AND cassa='pulito'", [$g['id']])['s'] ?? 0);
    $dS = (int) $p['contante'] - ($g['iniziale'] + $t + $mS);
    $dP = (int) $p['pulito'] - $mP;
    if ($dS !== 0 || $dP !== 0) {
        $rotte++;
        printf("      %-6s sporco %+s   pulito %+s\n", $g['nome'],
            number_format($dS, 0, ',', '.'), number_format($dP, 0, ',', '.'));
    }
}
$riga($rotte === 0, $rotte === 0
    ? 'tutte e ' . count($giocatori) . ' le casse tornano alla lira'
    : "{$rotte} casse non tornano");

// --- 2. Saldi negativi -------------------------------------------------------------------
echo "\n2. NIENTE SALDI NEGATIVI\n";
foreach ([['personaggi', 'contante < 0', 'contante'], ['personaggi', 'pulito < 0', 'pulito'],
          ['personaggi', 'debito < 0', 'debito'], ['carico', 'quantita < 0', 'carico'],
          ['mercati', 'offerta < 0 OR domanda < 0', 'nodi di mercato'],
          ['batterie', 'cassa < 0', 'casse delle batterie']] as [$tab, $dove, $eti]) {
    $n = (int) (Database::first("SELECT COUNT(*) n FROM {$tab} WHERE {$dove}")['n'] ?? 0);
    $riga($n === 0, $n === 0 ? "nessun {$eti} negativo" : "{$n} {$eti} negativi");
}

// --- 3. Il tetto ---------------------------------------------------------------------------
echo "\n3. IL TETTO TIENE\n";
$R = GameConfig::int('mondo.reddito_orario', 16_000_000);
$sopra = 0; $max = 0; $tot = 0;
foreach ($estrattoPerOra as $v) {
    $tot += $v; $max = max($max, $v);
    if ($v > $R) { $sopra++; }
}
$n = max(1, count($estrattoPerOra));
printf("  ore osservate %d · punta %s l'ora (%.1f%% del tetto) · media %s\n", $n,
    number_format($max, 0, ',', '.'), $R > 0 ? $max / $R * 100 : 0,
    number_format((int) ($tot / $n), 0, ',', '.'));
$riga($sopra === 0, $sopra === 0 ? 'nessuna ora sopra il tetto' : "{$sopra} ore SOPRA IL TETTO");

// --- 4. Com'è andata ----------------------------------------------------------------------
echo "\n4. COM'È ANDATA\n";
foreach ($giocatori as $g) {
    $p = Database::first('SELECT * FROM personaggi WHERE id = ?', [$g['id']]);
    $guad = (int) (Database::first("SELECT COALESCE(SUM(margine),0) m FROM transazioni
                                     WHERE personaggio_id=? AND verso='vendita'", [$g['id']])['m'] ?? 0);
    printf("  %-6s %-10s %13s in tasca %13s puliti  %s l'ora  calore %5.1f  %3d ordini %2d scontri\n",
        $g['nome'], mb_substr($g['citta'], 0, 10),
        number_format((int) $p['contante'], 0, ',', '.'),
        number_format((int) $p['pulito'], 0, ',', '.'),
        number_format((int) ($guad / $ore), 0, ',', '.'),
        (float) $p['calore'], $g['ordini'], $g['scontri']);
}
foreach ($giocatori as $g) { Obiettivi::verifica($g['id']); }
printf("\n  transazioni %d · fascicoli %d · obiettivi %d · scontri %d\n",
    (int) Database::first('SELECT COUNT(*) n FROM transazioni')['n'],
    (int) Database::first('SELECT COUNT(*) n FROM fascicoli')['n'],
    (int) Database::first('SELECT COUNT(*) n FROM obiettivi')['n'],
    (int) Database::first('SELECT COUNT(*) n FROM scontri')['n']);

exit($esito);
