<?php

declare(strict_types=1);

/**
 * Strumento di bilanciamento: gioca un principiante contro il motore vero e
 * misura quante lire l'ora riesce davvero a tirare fuori.
 *
 *   php bin/_simula_principiante.php [ore] [citta]
 *
 * Serve perché il conto a tavolino di docs/DESIGN.md §2.6 ancora il tetto al
 * REDDITO del principiante ma non verifica mai che esista un ordine
 * conveniente a quelle giacenze. Sono due cose diverse: il primo è aggregato,
 * il secondo è locale, e il secondo può essere impossibile mentre il primo
 * torna. Questo script guarda il secondo.
 *
 * La strategia è quella ovvia di un giocatore che impara in mezz'ora: in ogni
 * piazza vende quello che ha se ci guadagna, compra la merce con il margine di
 * rotta migliore verso una piazza raggiungibile, e ci va. Non è gioco ottimo —
 * è il pavimento, e il pavimento deve già stare sopra lo zero.
 */

$root = require __DIR__ . '/_bootstrap.php';

use App\Core\Database;
use App\Core\GameConfig;
use App\Game\Listino;
use App\Game\Mondo;
use App\Sim\Clock;
use App\Sim\Mercato;
use App\Sim\Viaggio;

$oreDaGiocare = (float) ($_SERVER['argv'][1] ?? 12);
$cittaCodice  = strtoupper($_SERVER['argv'][2] ?? 'NA');

$par  = Listino::parametri();
$beni = Listino::beni();

$cittaId = null;
foreach (Mondo::citta() as $id => $c) {
    if ($c['codice'] === $cittaCodice) { $cittaId = $id; }
}
if ($cittaId === null) { fwrite(STDERR, "Città sconosciuta.\n"); exit(1); }

$piazze = Mondo::piazzeDi($cittaId);
$qui = (int) $piazze[0]['id'];

$capitaleIniziale = App\Core\GameConfig::int('mondo.contante_iniziale', 2_000_000);
$contante = $capitaleIniziale;
$capienza = 80;
$carico   = [];           // bene_id => [q, costo]
// Si parte da ADESSO, non da una data scritta a mano: il mercato si proietta
// in avanti a partire dal suo `agg_a`, e se l'orologio della simulazione sta
// nel passato la proiezione non avanza di un secondo. Il risultato è un mercato
// congelato, senza carichi e senza occasioni — e la simulazione riporta zero
// affari senza che ci sia niente di rotto.
$partenza = new DateTimeImmutable('now');

// LO STATO DEL MERCATO SI RIMETTE A POSTO ALLA FINE.
//
// Questo strumento non è una simulazione ombra fino in fondo: gli ordini li
// scrive davvero sul mercato, perché è l'impatto sui prezzi a rendere onesta la
// misura. Il guaio è che li scrive con l'orologio della simulazione, che corre
// avanti di ore: alla fine quei nodi hanno un `agg_a` NEL FUTURO, la proiezione
// calcola un intervallo negativo e il mercato resta congelato finché il tempo
// vero non lo raggiunge. Si vedeva solo rilanciando la misura: il rendimento
// calava a ogni giro, e sembrava il gioco a peggiorare.
$istantanea = Database::all('SELECT piazza_id, bene_id, offerta, domanda, shock, agg_a FROM mercati');
register_shutdown_function(static function () use ($istantanea): void {
    foreach ($istantanea as $r) {
        Database::run(
            'UPDATE mercati SET offerta = ?, domanda = ?, shock = ?, agg_a = ?
              WHERE piazza_id = ? AND bene_id = ?',
            [$r['offerta'], $r['domanda'], $r['shock'], $r['agg_a'],
             (int) $r['piazza_id'], (int) $r['bene_id']]
        );
    }
});
Clock::fissa($partenza);
$fine = $partenza->modify('+' . (int) ($oreDaGiocare * 60) . ' minutes');

$viaggi = 0; $acquisti = 0; $vendite = 0; $speso = 0;

/** Il listino proiettato ad adesso, indicizzato per bene. */
$listino = static function (int $piazzaId): array {
    $out = [];
    foreach (Listino::perPiazza($piazzaId) as $v) { $out[(int) $v['bene']['id']] = $v; }
    return $out;
};

while (Clock::adesso() < $fine) {
    $qua = $listino($qui);

    // 1. Vendere quello che conviene vendere qui.
    foreach ($carico as $beneId => [$q, $costo]) {
        if ($q <= 0 || !isset($qua[$beneId])) { continue; }
        $m = $qua[$beneId]['stato_m'];
        $medio = (int) round($costo / max(1, $q));
        // Si vende la quantità che massimizza il guadagno, non tutto.
        $meglio = 0; $guadagnoMeglio = 0;
        for ($n = 1; $n <= $q; $n++) {
            $r = Mercato::ricavoVendita($m, $par, $n);
            if ($r['quantita'] < $n) { break; }
            $g = $r['totale'] - $medio * $n;
            if ($g > $guadagnoMeglio) { $guadagnoMeglio = $g; $meglio = $n; }
        }
        if ($meglio > 0) {
            $r = Mercato::ricavoVendita($m, $par, $meglio);
            $contante += $r['totale'];
            $carico[$beneId] = [$q - $meglio, $costo - $medio * $meglio];
            $m['domanda'] = $r['domanda_dopo'];
            $qua[$beneId]['stato_m'] = $m;
            Database::run('UPDATE mercati SET domanda = ?, agg_a = ? WHERE piazza_id = ? AND bene_id = ?',
                [round($r['domanda_dopo'], 3), Clock::perDb(), $qui, $beneId]);
            $vendite++;
        }
    }
    $carico = array_filter($carico, static fn($c) => $c[0] > 0);

    // 2. Cercare la rotta migliore: quale merce, comprata qui, rende di più in
    //    una piazza raggiungibile — al netto del biglietto e del tempo.
    $usato = 0;
    foreach ($carico as $beneId => [$q, ]) { $usato += $q * (int) $beni[$beneId]['ingombro']; }
    $spazio = $capienza - $usato;

    $migliore = null;
    foreach (Mondo::piazze() as $altraId => $altra) {
        if ((int) $altra['id'] === $qui) { continue; }
        $opz = Mondo::opzioniFra($qui, (int) $altra['id']);
        if ($opz === []) { continue; }
        // Il mezzo più veloce che ci si può permettere.
        $mezzo = null;
        foreach ($opz as $o) { if ($o['costo'] <= $contante / 4 && ($mezzo === null || $o['minuti'] < $mezzo['minuti'])) { $mezzo = $o; } }
        if ($mezzo === null || $mezzo['minuti'] > 90) { continue; }

        $la = $listino((int) $altra['id']);
        foreach ($qua as $beneId => $v) {
            if (!isset($la[$beneId])) { continue; }
            $ing = (int) $beni[$beneId]['ingombro'];
            $max = Mercato::quantoPosso($v['stato_m'], $par, $contante - $mezzo['costo'], $spazio, $ing);
            if ($max <= 0) { continue; }
            // Si prova qualche taglia e si tiene la migliore per ora impiegata.
            foreach ([$max, (int) ($max * 0.6), (int) ($max * 0.3), 5] as $n) {
                if ($n <= 0) { continue; }
                $c = Mercato::costoAcquisto($v['stato_m'], $par, $n);
                if ($c['quantita'] === 0) { continue; }
                $r = Mercato::ricavoVendita($la[$beneId]['stato_m'], $par, $c['quantita']);
                if ($r['quantita'] === 0) { continue; }
                $utile = $r['totale'] - $c['totale'] - $mezzo['costo'];
                $ore = max(0.05, $mezzo['minuti'] / 60);
                $perOra = $utile / $ore;
                if ($migliore === null || $perOra > $migliore['per_ora']) {
                    $migliore = ['per_ora' => $perOra, 'bene' => $beneId, 'n' => $c['quantita'],
                                 'costo' => $c['totale'], 'dove' => (int) $altra['id'], 'mezzo' => $mezzo];
                }
            }
        }
    }

    if ($migliore === null || $migliore['per_ora'] <= 0) {
        // Niente da fare qui: ci si sposta nella piazza più vicina e si riprova.
        $vicine = [];
        foreach (Mondo::piazzeDi((int) Mondo::piazza($qui)['citta_id']) as $p) {
            if ((int) $p['id'] !== $qui) { $vicine[(int) $p['id']] = Mondo::kmFra($qui, (int) $p['id']); }
        }
        asort($vicine);
        $dove = (int) array_key_first($vicine);
        $o = Mondo::opzione('mezzi', $qui, $dove) ?? Mondo::opzioniFra($qui, $dove)[0];
        $contante -= $o['costo']; $speso += $o['costo'];
        Clock::avanzaDi($o['minuti'] * 60);
        $qui = $dove; $viaggi++;
        continue;
    }

    // 3. Comprare e partire.
    $beneId = $migliore['bene'];
    $c = Mercato::costoAcquisto($qua[$beneId]['stato_m'], $par, $migliore['n']);
    $contante -= $c['totale'];
    $carico[$beneId] = [($carico[$beneId][0] ?? 0) + $c['quantita'], ($carico[$beneId][1] ?? 0) + $c['totale']];
    Database::run('UPDATE mercati SET offerta = ?, agg_a = ? WHERE piazza_id = ? AND bene_id = ?',
        [round($c['offerta_dopo'], 3), Clock::perDb(), $qui, $beneId]);
    $acquisti++;

    $contante -= $migliore['mezzo']['costo']; $speso += $migliore['mezzo']['costo'];
    Clock::avanzaDi($migliore['mezzo']['minuti'] * 60);
    $qui = $migliore['dove']; $viaggi++;
}

// Il carico invenduto vale quello che è costato, non di più.
$valoreCarico = 0;
foreach ($carico as [$q, $costo]) { $valoreCarico += $costo; }

$utile = $contante + $valoreCarico - $capitaleIniziale;
printf("Simulazione principiante — %.0f ore, partenza da %s\n\n", $oreDaGiocare, $cittaCodice);
printf("  contante finale   %18s\n", lire($contante));
printf("  carico invenduto  %18s\n", lire($valoreCarico));
printf("  biglietti pagati  %18s\n", lire($speso));
printf("  ----------------------------------------\n");
printf("  utile             %18s\n", lire($utile));
printf("  L'ORA             %18s\n", lire((int) round($utile / $oreDaGiocare)));
printf("\n  %d viaggi, %d acquisti, %d vendite\n", $viaggi, $acquisti, $vendite);
Clock::fissa(null);
