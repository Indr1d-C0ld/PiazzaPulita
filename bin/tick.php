<?php

declare(strict_types=1);

/**
 * Piazza Pulita — battito del mondo.
 *
 * Da cron, ogni minuto:
 *   * * * * * /usr/bin/php /data/html/piazzapulita/bin/tick.php >/dev/null 2>&1
 *
 * In F0 il mondo non respira ancora: qui dentro c'e' solo la manutenzione —
 * la posta in coda e la potatura dei freni scaduti. Da F2 in poi sara' questo
 * a far rifornire le piazze e a muovere i prezzi di riferimento.
 *
 * Ogni fase sta in piedi da sola: se una cade, cade da sola, lascia detto
 * perche', e il battito prosegue. Una fase di manutenzione che salta non deve
 * poter fermare il mondo.
 */

$projectRoot = require __DIR__ . '/_bootstrap.php';

use App\Core\Database;
use App\Core\Lock;
use App\Core\Posta;
use App\Core\RateLimiter;
use App\Game\Contabilita;
use App\Game\Legge;
use App\Game\Listino;
use App\Game\Logistica;
use App\Game\Batteria;
use App\Game\Classifica;
use App\Game\Cronaca;
use App\Game\Obiettivi;
use App\Game\Organico;
use App\Game\Rivalita;
use App\Game\Personaggio;

$avvio = microtime(true);
$lock  = $projectRoot . '/storage/tick.lock';

// Un solo battito per volta: se il precedente e' ancora in corso si esce.
$fp = fopen($lock, 'c');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$id = null;
$guasti = [];

$fase = static function (string $nome, callable $f, mixed $difetto) use (&$guasti): mixed {
    try {
        return $f();
    } catch (\Throwable $e) {
        $guasti[] = $nome . ': ' . $e->getMessage();
        logger('tick: fase ' . $nome . ' — ' . $e->getMessage(), 'error');
        return $difetto;
    }
};

try {
    Database::run('INSERT INTO tick_runs (started_at, ok) VALUES (NOW(3), 0)');
    $id = Database::lastInsertId();

    $lavori = [];

    $lavori['arrivi'] = $fase('arrivi', static fn() => Personaggio::avanzaTutti(), 0);
    // Il mercato respira anche quando non lo guarda nessuno: i prezzi di
    // riferimento camminano, le giacenze tornano verso l'equilibrio, e ogni
    // tanto arriva un carico. Le pagine proiettano lo stesso conto senza
    // scrivere — questo serve perché lo stato salvato non resti mai troppo
    // indietro, e perché il mondo si muova per chi è scollegato.
    $lavori['prezzi']  = $fase('prezzi', static fn() => Listino::avanzaPrezzi(), 0);
    $lavori['mercati'] = $fase('mercati', static fn() => Listino::avanzaTutti(), 0);
    // Il denaro matura da solo: i canali lavano, il debito cresce. Va scritto
    // e non solo proiettato, o il tetto e le conseguenze non scatterebbero mai
    // per chi non apre la pagina — cioè proprio per chi sta scappando.
    $lavori['denaro']  = $fase('denaro', static fn() => Contabilita::avanzaTutti(), ['personaggi' => 0, 'pulito' => 0]);
    $lavori['affitti'] = $fase('affitti', static fn() => Logistica::riscuotiAffitti(), ['riscossi' => 0, 'persi' => 0]);
    // La legge non dorme: il calore decade, i fascicoli maturano, chi ha
    // finito esce. È qui che il rischio smette di essere una pagina da leggere
    // e diventa una cosa che succede mentre non guardi.
    $lavori['scarcerati'] = $fase('scarcerati', static fn() => Legge::scarcera(), 0);
    $lavori['fascicoli']  = $fase('fascicoli', static fn() => Legge::avanzaFascicoli(), ['cresciuti' => 0, 'blitz' => 0]);
    $lavori['raffreddati'] = $fase('raffreddati', static fn() => Legge::raffredda(), 0);
    // Gli uomini: si pagano a ore, e chi non viene pagato smette di volerti
    // bene. I corrieri arrivano — o non arrivano.
    $lavori['stipendi'] = $fase('stipendi', static fn() => Organico::pagaStipendi(), ['pagati' => 0, 'non_pagati' => 0]);
    $lavori['corse']    = $fase('corse', static fn() => Organico::chiudiCorse(), ['arrivate' => 0, 'perse' => 0]);
    // Gli altri: chi esce dall'ospedale, le spie che vengono scoperte, e il
    // territorio, che decade se non lo si tiene.
    $lavori['dimessi']   = $fase('dimessi', static fn() => Rivalita::dimetti(), 0);
    $lavori['spie']      = $fase('spie', static fn() => Rivalita::spieScoperte(), ['scoperte' => 0]);
    $lavori['territori'] = $fase('territori', static fn() => Batteria::aggiornaTerritori(), ['cambi' => 0]);

    // I primati: chi tiene una graduatoria, e da quanto. Si guarda a ogni
    // battito ma si CHIUDE un regno solo quando il primo cambia davvero — un
    // primato che cambia ogni cinque minuti non e' un primato.
    $lavori['primati'] = $fase('primati', static fn() => Classifica::aggiornaPrimati(),
                               ['cambi' => 0, 'iscritti' => 0]);

    // Gli obiettivi di chi e' stato visto da poco. Cosi' si sbloccano da soli
    // senza che nessuno debba passare dalla pagina apposta, e senza far pagare
    // una manciata di aggregati a OGNI richiesta di OGNI giocatore.
    $lavori['obiettivi'] = $fase('obiettivi', static function (): int {
        $n = 0;
        foreach (Database::all(
            "SELECT p.id FROM personaggi p JOIN users u ON u.id = p.user_id
              WHERE u.status = 'active' AND u.last_seen_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
              LIMIT 50") as $r) {
            $n += count(Obiettivi::verifica((int) $r['id']));
        }
        return $n;
    }, 0);
    $lavori['posta'] = $fase('posta', static fn() => Posta::smista(), ['tentati' => 0, 'inviati' => 0, 'rinunciati' => 0]);
    $lavori['freni'] = $fase('freni', static fn() => RateLimiter::gc(), 0);

    // Potature: solo una volta l'ora, allo scoccare del minuto zero. Non c'e'
    // ragione di rileggere tabelle intere sessanta volte per ora.
    if ((int) date('i') === 0) {
        $lavori['posta_potata']   = $fase('posta_potata', static fn() => Posta::pota(30), 0);
        $lavori['gettoni_potati'] = $fase('gettoni_potati', static fn() => Database::run(
            'DELETE FROM user_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 1000'
        )->rowCount(), 0);
        // Gli account mai confermati non servono a nessuno e occupano il nome
        // utente e l'indirizzo, impedendo a chi ha sbagliato di riprovare.
        $lavori['iscrizioni_decadute'] = $fase('iscrizioni_decadute', static fn() => Database::run(
            "DELETE FROM users WHERE status = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY) LIMIT 100"
        )->rowCount(), 0);
        $lavori['cronaca_potata'] = $fase('cronaca_potata', static fn() => Cronaca::pota(30), 0);
        $lavori['battiti_potati'] = $fase('battiti_potati', static fn() => Database::run(
            'DELETE FROM tick_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 5000'
        )->rowCount(), 0);
        // Il diario degli spostamenti serve alle statistiche, non all'eternità.
        $lavori['spostamenti_potati'] = $fase('spostamenti_potati', static fn() => Database::run(
            'DELETE FROM spostamenti WHERE arrivato_at IS NOT NULL
               AND arrivato_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 5000'
        )->rowCount(), 0);
    }

    Database::run(
        'UPDATE tick_runs SET finished_at = NOW(3), ok = ?, duration_ms = ?, tasks = ?, note = ? WHERE id = ?',
        [
            $guasti === [] ? 1 : 0,
            (int) round((microtime(true) - $avvio) * 1000),
            json_encode($lavori, JSON_UNESCAPED_UNICODE),
            $guasti === [] ? null : mb_substr(implode(' | ', $guasti), 0, 255),
            $id,
        ]
    );
} catch (\Throwable $e) {
    logger('tick fallito: ' . $e->getMessage(), 'error');
    if ($id !== null) {
        try {
            Database::run(
                'UPDATE tick_runs SET finished_at = NOW(3), ok = 0, note = ? WHERE id = ?',
                [mb_substr($e->getMessage(), 0, 255), $id]
            );
        } catch (\Throwable) {
            // Se non si riesce nemmeno a scrivere l'esito, resta il diario.
        }
    }
    exit(1);
} finally {
    Lock::liberaTutto();
    flock($fp, LOCK_UN);
    fclose($fp);
}
