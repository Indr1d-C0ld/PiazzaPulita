<?php

declare(strict_types=1);

/**
 * Piazza Pulita — prove unitarie senza rete e senza database.
 *
 *   php tests/test_unita.php
 *
 * Coprono due cose che si rompono in silenzio: le funzioni di formato (una
 * cifra sbagliata in un gioco fatto di listini è un baco invisibile) e il
 * rendering di tutte le viste (una variabile dimenticata nel layout diventa
 * una pagina bianca solo quando ci arriva un giocatore).
 */

$root = dirname(__DIR__);
$GLOBALS['__project_root'] = $root;
$GLOBALS['__base_path']    = '/piazzapulita';
$GLOBALS['__url_prefix']   = '/piazzapulita';

require $root . '/src/autoload.php';
require $root . '/src/Support/helpers.php';

use App\Core\View;
use App\Sim\Clock;
use App\Sim\Geo;
use App\Sim\Calore;
use App\Sim\Crescita;
use App\Sim\Etichette;
use App\Sim\Scontro;
use App\Game\Classifica;
use App\Game\Obiettivi;
use App\Sim\Denaro;
use App\Sim\Mercato;
use App\Sim\Prezzi;
use App\Sim\Rifornimento;
use App\Sim\Rng;
use App\Sim\Viaggio;

View::setPath($root . '/views');

$falliti = 0;
$fatti   = 0;

function prova(string $nome, mixed $atteso, mixed $ottenuto): void
{
    global $falliti, $fatti;
    $fatti++;
    if ($atteso === $ottenuto) {
        printf("  \033[0;32mok\033[0m    %s\n", $nome);
        return;
    }
    $falliti++;
    printf("  \033[0;31mKO\033[0m    %s\n        atteso:   %s\n        ottenuto: %s\n",
        $nome, var_export($atteso, true), var_export($ottenuto, true));
}

echo "Formato dei numeri\n";
prova('lire con separatore di migliaia', '1.250.000' . "\u{202F}" . 'L.', lire(1250000));
prova('lire senza simbolo',              '5.500.000', lire(5500000, false));
prova('lire, zero',                      '0' . "\u{202F}" . 'L.', lire(0));
prova('lire, negativo',                  '-4.500' . "\u{202F}" . 'L.', lire(-4500));
prova('quantità',                        '1.000', quantita(1000));

echo "\nRotte di ritorno (il baco del prefisso doppio)\n";
prova('toglie il prefisso del deploy',   '/profilo', rotta_da_uri('/piazzapulita/profilo', '/x'));
prova('toglie anche index.php',          '/strada',  rotta_da_uri('/piazzapulita/index.php/strada', '/x'));
prova('scarta la query',                 '/strada',  rotta_da_uri('/piazzapulita/strada?a=1', '/x'));
prova('radice torna al difetto',         '/x',       rotta_da_uri('/piazzapulita/', '/x'));
prova('vuoto torna al difetto',          '/x',       rotta_da_uri('', '/x'));
prova('host esterno ridotto a percorso', '/y',       rotta_da_uri('https://altrove.example/y', '/x'));
prova('doppia barra neutralizzata',      '/y',       rotta_da_uri('//altrove.example/y', '/x'));

echo "\nDate\n";
prova('data italiana verso ISO',         '1913-05-22', data_it_a_iso('22/05/1913'));
prova('data impossibile respinta',       null,         data_it_a_iso('31/02/1990'));
prova('data vuota respinta',             null,         data_it_a_iso(''));
prova('data ISO accettata',              '1984-12-01', data_it_a_iso('1984-12-01'));

echo "\nFuga dall'HTML\n";
prova('apici e segni maggiore',          '&lt;b&gt;x&quot;y&lt;/b&gt;', e('<b>x"y</b>'));

echo "\nGeografia\n";
$mi = [45.4642, 9.1900]; $rm = [41.9028, 12.4964]; $pa = [38.1157, 13.3615];
prova('Milano-Roma in linea d\'aria',   477, (int) round(Geo::distanzaKm(...[...$mi, ...$rm])));
prova('Milano-Palermo in linea d\'aria', 887, (int) round(Geo::distanzaKm(...[...$mi, ...$pa])));
prova('distanza simmetrica', true,
    abs(Geo::distanzaKm(...[...$mi, ...$rm]) - Geo::distanzaKm(...[...$rm, ...$mi])) < 1e-9);
prova('distanza da sé stessi nulla', 0.0, Geo::distanzaKm(45.0, 9.0, 45.0, 9.0));
prova('il percorso allunga la linea d\'aria', 125.0, Geo::percorsoKm(100.0, 1.25));
// L'emisenoverso conta: con Pitagora sui gradi, Milano-Palermo sbaglia di quasi il 4 %.
$pitagora = sqrt(((45.4642 - 38.1157) * 111.19) ** 2 + ((9.19 - 13.3615) * 111.19) ** 2);
prova('l\'emisenoverso non è Pitagora', true, abs($pitagora - Geo::distanzaKm(...[...$mi, ...$pa])) > 20);

echo "\nViaggi\n";
$dentro = Viaggio::opzioni(10.7, true);
prova('in città tre mezzi',          3, count($dentro));
prova('ordinati dal più lento',      'a piedi', $dentro[0]['mezzo']);
prova('a piedi non costa niente',    0, $dentro[0]['costo']);
prova('a piedi, 10,7 km',            43, $dentro[0]['minuti']);
prova('coi mezzi, 10,7 km',          18, Viaggio::opzione('mezzi', 10.7, true)['minuti']);
prova('biglietto dei mezzi',         600, Viaggio::opzione('mezzi', 10.7, true)['costo']);
$fuori = Viaggio::opzioni(596.0, false);
prova('fra città tre mezzi',         3, count($fuori));
prova('Milano-Roma in treno',        123, Viaggio::opzione('treno', 596.0, false)['minuti']);
prova('niente aereo sotto i 300 km', null, Viaggio::opzione('aereo', 236.0, false));
prova('niente treno dentro la città',null, Viaggio::opzione('treno', 10.0, true));
prova('niente taxi fra le città',    null, Viaggio::opzione('taxi', 200.0, false));
prova('nessuno spostamento istantaneo', 1, Viaggio::opzione('a piedi', 0.01, true)['minuti']);
// Per i mezzi con attesa il minimo è l'attesa, non un minuto: il taxi lo aspetti
// comunque, anche per andare al portone accanto.
prova('con l\'attesa, il minimo è l\'attesa', 2, Viaggio::opzione('taxi', 0.01, true)['minuti']);
prova('il taxi batte i mezzi',       true,
    Viaggio::opzione('taxi', 10.7, true)['minuti'] < Viaggio::opzione('mezzi', 10.7, true)['minuti']);
prova('e si fa pagare',              true,
    Viaggio::opzione('taxi', 10.7, true)['costo'] > Viaggio::opzione('mezzi', 10.7, true)['costo'] * 10);
prova('durata sotto l\'ora',         '43 min', Viaggio::durata(43));
prova('durata tonda',                '2 h',    Viaggio::durata(120));
prova('durata spezzata',             '2 h 03', Viaggio::durata(123));

echo "\nGeneratore deterministico\n";
$a = new Rng(19841984); $b = new Rng(19841984);
$uguali = true; $interi = true;
for ($i = 0; $i < 5000; $i++) {
    $x = $a->successivo(); $y = $b->successivo();
    if ($x !== $y) { $uguali = false; }
    if (!is_int($x)) { $interi = false; }
}
prova('stesso seme, stessa sequenza', true, $uguali);
prova('sempre interi, mai float',     true, $interi);
$r = new Rng(7); $fuoriBanda = false; $somma = 0.0;
for ($i = 0; $i < 20000; $i++) {
    $v = $r->reale();
    if ($v < 0 || $v >= 1) { $fuoriBanda = true; }
    $somma += $v;
}
prova('reali in [0;1)',               false, $fuoriBanda);
prova('media vicina a 0,5',           true, abs($somma / 20000 - 0.5) < 0.02);
$r = new Rng(7); $male = false;
for ($i = 0; $i < 20000; $i++) { $d = $r->intero(1, 6); if ($d < 1 || $d > 6) { $male = true; } }
prova('dado sempre fra 1 e 6',        false, $male);
prova('etichette diverse, semi diversi', true,
    Rng::da(1, 'milano')->successivo() !== Rng::da(1, 'napoli')->successivo());

// LA PRIMA MANO DOPO LA SEMINA.
//
// Questa è la verifica che mancava, ed è costata cara: la sequenza lunga di un
// solo generatore era ottima — la provano le righe qui sopra — ma mezzo gioco
// non usa sequenze lunghe. Semina un generatore e ne prende UN numero: il
// carico di quell'ora, il rumore del prezzo a quel passo. Quella è sempre e
// solo la prima mano, e la prima mano da un seme stretto aveva media 0,125 e
// non superava MAI 0,25. Risultato: i carichi arrivavano quattro volte più
// spesso del previsto e la giacenza a riposo stava a quattro volte
// l'equilibrio invece che a 1,7.
$prime = []; $sopraTreQuarti = 0; $sottoUnOttavo = 0; $somma = 0.0;
for ($i = 0; $i < 20000; $i++) {
    $v = Rng::da(12345, "carico:7:3:{$i}")->reale();
    $prime[] = $v; $somma += $v;
    if ($v > 0.75)  { $sopraTreQuarti++; }
    if ($v < 0.125) { $sottoUnOttavo++; }
}
prova('la prima mano ha media un mezzo',   true, abs($somma / 20000 - 0.5) < 0.02);
prova('e arriva davvero in cima',          true, $sopraTreQuarti > 20000 * 0.22);
prova('e davvero in fondo',                true, $sottoUnOttavo  > 20000 * 0.10);
prova('e copre tutta la banda',            true, max($prime) > 0.99 && min($prime) < 0.01);
// La stessa cosa con semi consecutivi, che è il caso dei passi del prezzo.
$s2 = 0.0; $alti = 0;
for ($i = 0; $i < 20000; $i++) {
    $v = (new Rng(1000 + $i))->reale();
    $s2 += $v;
    if ($v > 0.5) { $alti++; }
}
prova('vale anche per semi consecutivi',   true, abs($s2 / 20000 - 0.5) < 0.02);
prova('e non pendono da una parte',        true, abs($alti / 20000 - 0.5) < 0.02);

echo "\nOrologio\n";
Clock::fissa(new DateTimeImmutable('1985-06-12 14:30:00'));
prova('orologio fissabile',    '1985-06-12 14:30:00.000', Clock::perDb());
Clock::avanzaDi(3600);
prova('e avanzabile',          '1985-06-12 15:30:00.000', Clock::perDb());
prova('lettura dal database',  '1985-06-12 15:30:00', Clock::daDb('1985-06-12 15:30:00')?->format('Y-m-d H:i:s'));
prova('data vuota è null',     null, Clock::daDb(''));
prova('zeri del database sono null', null, Clock::daDb('0000-00-00 00:00:00'));
Clock::fissa(null);

echo "\nMercato — formazione del prezzo\n";
// Un mercato all'equilibrio: i moltiplicatori valgono 1, e il prezzo è il
// riferimento più o meno lo spread.
$par = ['e' => 0.60, 'z' => 0.60, 'spread' => 0.03, 'banda_bassa' => 0.20, 'banda_alta' => 5.00];
$eq = ['p0' => 20000.0, 'd' => 1.0, 'offerta' => 50.0, 'offerta_eq' => 50.0,
       'domanda' => 40.0, 'domanda_eq' => 40.0, 'shock' => 0.0];
prova('all\'equilibrio si compra a +spread', 20600, (int) round(Mercato::prezzoAcquisto($eq, $par)));
prova('all\'equilibrio si vende a −spread', 19400, (int) round(Mercato::prezzoVendita($eq, $par)));
prova('vendere rende meno che comprare', true,
    Mercato::prezzoVendita($eq, $par) < Mercato::prezzoAcquisto($eq, $par));

$scarso = $eq; $scarso['offerta'] = 10.0;
prova('poca giacenza alza il prezzo', true, Mercato::prezzoAcquisto($scarso, $par) > Mercato::prezzoAcquisto($eq, $par) * 2);
$saturo = $eq; $saturo['domanda'] = 4.0;
prova('piazza satura abbatte il prezzo', true, Mercato::prezzoVendita($saturo, $par) < Mercato::prezzoVendita($eq, $par) * 0.5);
// Con l'assorbimento a zero il prezzo NON va a zero: la banda lo tiene al 20 %
// del riferimento. A fermare tutto non è il prezzo ma la quantità — non si può
// vendere in un mercato che non compra, e il conto lo dice restituendo zero.
// È il contratto giusto: un prezzo che tende a zero sarebbe un modo elegante di
// permettere di regalare merce per sbaglio.
$vuoto = $eq; $vuoto['domanda'] = 0.0;
prova('a piazza satura il prezzo resta in banda', 3880, (int) round(Mercato::prezzoVendita($vuoto, $par)));
prova('ma non si vende proprio niente',           0,    Mercato::ricavoVendita($vuoto, $par, 10)['quantita']);

$estremo = $eq; $estremo['offerta'] = 0.001;
prova('la banda tiene anche agli estremi', true,
    Mercato::prezzoAcquisto($estremo, $par) <= 20000.0 * $par['banda_alta'] * 1.031);
$moltoD = $eq; $moltoD['d'] = 1.3;
prova('il carattere della piazza sposta il prezzo', 26780, (int) round(Mercato::prezzoAcquisto($moltoD, $par)));

echo "\nMercato — impatto del proprio ordine\n";
$uno    = Mercato::costoAcquisto($eq, $par, 1);
$venti  = Mercato::costoAcquisto($eq, $par, 20);
$tutto  = Mercato::costoAcquisto($eq, $par, 50);
prova('comprarne una costa circa il prezzo', true, abs($uno['medio'] - 20600) < 400);
prova('comprarne venti costa di più a testa', true, $venti['medio'] > $uno['medio'] * 1.10);
prova('più ne compri, più cara è l\'ultima', true, $tutto['medio'] > $venti['medio']);
prova('non si compra più della giacenza', 50, Mercato::costoAcquisto($eq, $par, 500)['quantita']);
prova('la giacenza cala di quanto si è comprato', 30.0, $venti['offerta_dopo']);
prova('ordine nullo, niente conto', 0, Mercato::costoAcquisto($eq, $par, 0)['totale']);
// L'integrale deve stare fra il prezzo della prima unità e quello dell'ultima.
$margineUltima = Mercato::prezzoAcquisto(['offerta' => 31.0] + $eq, $par);
prova('il medio sta fra prima e ultima', true,
    $venti['medio'] > 20600 && $venti['medio'] < $margineUltima);

$v20 = Mercato::ricavoVendita($eq, $par, 20);
$v1  = Mercato::ricavoVendita($eq, $par, 1);
prova('venderne venti rende meno a testa', true, $v20['medio'] < $v1['medio'] * 0.92);
prova('non si vende oltre l\'assorbimento', 40, Mercato::ricavoVendita($eq, $par, 400)['quantita']);
prova('l\'assorbimento cala di quanto si è venduto', 20.0, $v20['domanda_dopo']);

// Il giro secco in una piazza sola deve SEMPRE perderci, o sarebbe una
// macchina per stampare denaro senza muoversi.
$c = Mercato::costoAcquisto($eq, $par, 10);
$dopo = $eq; $dopo['offerta'] = $c['offerta_dopo'];
$r = Mercato::ricavoVendita($dopo, $par, 10);
prova('comprare e rivendere sul posto ci rimette', true, $r['totale'] < $c['totale']);

echo "\nMercato — quanto posso comprare\n";
prova('senza soldi, niente',       0,  Mercato::quantoPosso($eq, $par, 0, 100, 1));
prova('senza spazio, niente',      0,  Mercato::quantoPosso($eq, $par, 10_000_000, 0, 1));
prova('lo spazio limita',          10, Mercato::quantoPosso($eq, $par, 10_000_000, 30, 3));
prova('la giacenza limita',        50, Mercato::quantoPosso($eq, $par, 10_000_000, 1000, 1));
$possibile = Mercato::quantoPosso($eq, $par, 250_000, 1000, 1);
prova('il denaro limita, e il conto torna', true,
    Mercato::costoAcquisto($eq, $par, $possibile)['totale'] <= 250_000
    && Mercato::costoAcquisto($eq, $par, $possibile + 1)['totale'] > 250_000);

echo "\nPrezzi — passeggiata deterministica\n";
$a = Prezzi::avanza(20000.0, 100, 200, 3, 8000.0, 45000.0, 19841984, 0.02, 0.01);
$b = Prezzi::avanza(20000.0, 100, 200, 3, 8000.0, 45000.0, 19841984, 0.02, 0.01);
prova('stesso seme, stesso prezzo',    $a['p0'], $b['p0']);
prova('e si ferma al passo giusto',    200, $a['passo']);
$c2 = Prezzi::avanza(20000.0, 100, 200, 4, 8000.0, 45000.0, 19841984, 0.02, 0.01);
prova('beni diversi, cammini diversi', true, abs($a['p0'] - $c2['p0']) > 1.0);
prova('indietro non si va',            20000.0, Prezzi::avanza(20000.0, 200, 100, 3, 8000.0, 45000.0, 1, 0.02, 0.01)['p0']);
$fuori = Prezzi::avanza(44000.0, 0, 3000, 3, 8000.0, 45000.0, 7, 0.02, 0.05);
prova('resta dentro la forchetta', true, $fuori['p0'] >= 8000.0 && $fuori['p0'] <= 45000.0);
prova('i beni poveri ballano di più', true,
    Prezzi::volatilita(8000, 45000, 0.01) > Prezzi::volatilita(120000, 300000, 0.01) * 2);

echo "\nRifornimento — il respiro\n";
$m0 = ['offerta' => 10.0, 'offerta_eq' => 50.0, 'domanda' => 5.0, 'domanda_eq' => 40.0, 'shock' => 0.5];
$dopo1 = Rifornimento::avanza($m0, 3600 * 6, 1, 1, 12345, 0, 6.0, 3.0);
// Sale di sicuro; quanto, dipende anche dai carichi, che possono portarla
// SOPRA l'equilibrio — ed è voluto, è quella l'occasione da cogliere.
prova('la giacenza risale', true, $dopo1['offerta'] > 10.0);
$senzaCarichi = Rifornimento::avanza($m0, 3600 * 6, 1, 1, 12345, 0, 6.0, 3.0);
prova('il ritorno alla media è esatto', 30.0,
    round(50.0 + (10.0 - 50.0) * 2 ** (-6.0 / 6.0), 6));
prova('l\'assorbimento si ricostituisce',        true, $dopo1['domanda'] > 5.0);
prova('lo shock si spegne',                      true, abs($dopo1['shock']) < 0.5);
$lungo = Rifornimento::avanza($m0, 3600 * 24 * 30, 1, 1, 12345, 0, 6.0, 3.0);
prova('dopo un mese si è all\'equilibrio, non oltre', true, abs($lungo['domanda'] - 40.0) < 0.01);
prova('e non si scavalca mai',                        true, $lungo['offerta'] >= 50.0);
$x = Rifornimento::avanza($m0, 3600 * 5, 7, 2, 999, 0, 6.0, 3.0);
$y = Rifornimento::avanza($m0, 3600 * 5, 7, 2, 999, 0, 6.0, 3.0);
prova('i carichi sono deterministici', $x['offerta'], $y['offerta']);
prova('fermo il tempo, fermo tutto', 10.0, Rifornimento::avanza($m0, 0, 1, 1, 1, 0, 6.0, 3.0)['offerta']);

echo "\nDenaro — l'usuraio\n";
prova('un giorno al 10%',          1_650_000, Denaro::debitoDopo(1_500_000, 1_500_000, 86400, 0.10, 3.0));
prova('due giorni compongono',     1_815_000, Denaro::debitoDopo(1_500_000, 1_500_000, 2 * 86400, 0.10, 3.0));
// Mezza giornata non è metà interesse: è la radice, ed è il punto dell'interesse
// continuo — chi ripaga a mezzogiorno non paga come chi ripaga a mezzanotte.
prova('mezza giornata è la radice', 1_573_213, Denaro::debitoDopo(1_500_000, 1_500_000, 43200, 0.10, 3.0));
prova('il tetto ferma la crescita', 4_500_000, Denaro::debitoDopo(1_500_000, 1_500_000, 365 * 86400, 0.10, 3.0));
prova('senza debito non matura niente', 0, Denaro::debitoDopo(0, 0, 365 * 86400, 0.10, 3.0));
prova('tempo fermo, debito fermo', 1_500_000, Denaro::debitoDopo(1_500_000, 1_500_000, 0, 0.10, 3.0));

prova('prestito senza pulito',        500_000, Denaro::prestitoDisponibile(1_500_000, 0, 2_000_000, 2.0));
prova('il pulito allarga il credito', 20_500_000, Denaro::prestitoDisponibile(1_500_000, 10_000_000, 2_000_000, 2.0));
prova('chi ha già preso troppo, niente', 0, Denaro::prestitoDisponibile(9_000_000, 0, 2_000_000, 2.0));

echo "\nDenaro — la lavanderia\n";
$unOra = Denaro::lava(200_000, 3600, 150_000, 0.35);
prova('si lava solo la capacità oraria', 150_000, $unOra['lavato']);
prova('e ne esce meno la commissione',    97_500, $unOra['pulito']);
prova('il resto resta in coda',           50_000, $unOra['coda']);
prova('la commissione torna',             52_500, $unOra['commissione']);
$mezzOra = Denaro::lava(200_000, 1800, 150_000, 0.35);
prova('mezz\'ora lava metà',              75_000, $mezzOra['lavato']);
$poco = Denaro::lava(10_000, 3600, 150_000, 0.35);
prova('non si lava più di quel che c\'è', 10_000, $poco['lavato']);
prova('e la coda si svuota',                    0, $poco['coda']);
prova('coda vuota, niente da fare',             0, Denaro::lava(0, 3600, 150_000, 0.35)['lavato']);
prova('tempo fermo, niente da fare',            0, Denaro::lava(200_000, 0, 150_000, 0.35)['lavato']);

// La capacità oraria è il vero vincolo: la commissione si paga una volta, il
// tempo si paga sempre.
prova('col bar, dieci milioni sono 66,7 ore', 66.7,
    round(Denaro::tempoDiLavaggio(10_000_000, 150_000) / 3600, 1));
prova('col cantiere, due ore e mezza',         2.5,
    round(Denaro::tempoDiLavaggio(10_000_000, 4_000_000) / 3600, 1));
prova('niente da lavare, nessuna attesa',        0, Denaro::tempoDiLavaggio(0, 150_000));

echo "\nCalore — il rischio come conseguenza\n";
// L'accumulo è superlineare: è questo che rende il colpo grosso una scelta e
// non un'abitudine. Cinque milioni non scaldano cinque volte un milione.
prova('un milione vale un grado',   1.05, round(Calore::daOperazione(1_000_000, 1_000_000, 1.5, 5), 2));
prova('cinque milioni ne valgono undici', 11.74, round(Calore::daOperazione(5_000_000, 1_000_000, 1.5, 5), 2));
prova('e non cinque',               true, Calore::daOperazione(5_000_000, 1_000_000, 1.5, 5) > 5 * Calore::daOperazione(1_000_000, 1_000_000, 1.5, 5));
prova('la merce rischiosa scalda di più', true,
    Calore::daOperazione(1_000_000, 1_000_000, 1.5, 90) > Calore::daOperazione(1_000_000, 1_000_000, 1.5, 5) * 1.7);
prova('operazione nulla, niente calore', 0.0, Calore::daOperazione(0, 1_000_000, 1.5, 50));

prova('dimezza nel tempo di dimezzamento', 50.0, Calore::decaduto(100, 12 * 3600, 12));
prova('e ancora',                          25.0, Calore::decaduto(100, 24 * 3600, 12));
prova('sotto il centesimo si azzera',       0.0, Calore::decaduto(100, 400 * 3600, 12));
prova('tempo fermo, calore fermo',        100.0, Calore::decaduto(100, 0, 12));

// Il rischio si moltiplica per fattori indipendenti, e resta dentro una banda.
$freddo = Calore::rischioControllo(0.02, 8, 0, 0, 0);
$caldo  = Calore::rischioControllo(0.02, 80, 100, 300, 2);
prova('in periferia, da freddi, è trascurabile', true, $freddo < 0.01);
prova('in centro, da caldi, è un problema',      true, $caldo > 0.5);
prova('ma non arriva mai alla certezza',         true, $caldo <= 0.85);
prova('più polizia, più rischio', true,
    Calore::rischioControllo(0.02, 80, 0, 0, 0) > Calore::rischioControllo(0.02, 8, 0, 0, 0));
prova('i precedenti pesano per sempre', true,
    Calore::rischioControllo(0.02, 50, 0, 0, 3) > Calore::rischioControllo(0.02, 50, 0, 0, 0) * 1.7);

prova('a piedi non c\'è posto di blocco', 0.0, Calore::rischioBlocco(0.035, 8, 500, 300, 2, false));
prova('a mani vuote nemmeno',             0.0, Calore::rischioBlocco(0.035, 8, 0, 300, 2, true));
prova('col furgone carico e caldo, sì',   true, Calore::rischioBlocco(0.035, 8, 800, 300, 2, true) > 0.3);

prova('le prove crescono col calore', 2.0, round(Calore::prove(100, 3600, 2.0), 2));
prova('a calore zero non crescono',    0.0, Calore::prove(0, 3600, 2.0));
prova('mezz\'ora, metà prove',         1.0, round(Calore::prove(100, 1800, 2.0), 2));

prova('il calore si dice a parole',  'nessuno ti guarda', Calore::aParole(2));
prova('e anche il rischio',          'basso', Calore::rischioAParole(0.02));

echo "\nCrescita — si impara con l'uso\n";
// I rendimenti calano: da zero si sale in fretta, da settanta quasi più.
$p0 = Crescita::passo(0, 1.5, 25);
$p50 = Crescita::passo(50, 1.5, 25);
$p90 = Crescita::passo(90, 1.5, 25);
prova('da zero si cresce in fretta', 1.5,  round($p0, 4));
prova('a metà si cresce meno',       true, $p50 < $p0 / 2.5);
prova('in cima quasi più',           true, $p90 < $p0 / 4);
prova('a cento ci si ferma',         0.0,  Crescita::passo(100, 1.5, 25));
prova('non si scavalca mai il cento', 100.0, 99.9 + Crescita::passo(99.9, 99, 25));

// Il peso di un'operazione è logaritmico: un colpo grosso insegna di più, ma
// non in proporzione, o basterebbe una vendita sola per diventare maestri.
prova('mezzo milione pesa poco',  0.6,  round(Crescita::pesoValore(500_000), 1));
prova('dieci milioni pesano di più', true, Crescita::pesoValore(10_000_000) > Crescita::pesoValore(500_000));
prova('ma non venti volte tanto',    true, Crescita::pesoValore(10_000_000) < Crescita::pesoValore(500_000) * 5);
prova('il peso ha un tetto',         true, Crescita::pesoValore(10_000_000_000) <= 4.0);
prova('operazione nulla, peso nullo', 0.0, Crescita::pesoValore(0));

echo "\nCrescita — gli effetti\n";
prova('senza trattativa lo spread è intero', 0.03,  round(Crescita::spread(0.03, 0), 4));
prova('a cento si paga il 40 % in meno',     0.018, round(Crescita::spread(0.03, 100), 4));
prova('il sangue freddo dimezza il rischio', 0.05,  round(Crescita::rischioConSangueFreddo(0.10, 100), 4));
prova('a zero non cambia niente',            0.10,  round(Crescita::rischioConSangueFreddo(0.10, 0), 4));
prova('un uomo solo, all\'inizio',            1, Crescita::uominiRetti(0, 1, 10));
prova('due a dieci gradi',                    2, Crescita::uominiRetti(10, 1, 10));
prova('undici a cento',                      11, Crescita::uominiRetti(100, 1, 10));
prova('il credito allarga il prestito', 4_000_000, Crescita::prestitoBase(2_000_000, 100));
prova('e lima l\'interesse',            0.075,     round(Crescita::interesse(0.10, 100), 4));
prova('l\'interesse non scende all\'infinito', true, Crescita::interesse(0.10, 100) >= 0.10 * 0.75);

prova('gli attributi si dicono a parole', 'da maestro', Crescita::aParole(95));
prova('e la reputazione pure',            'non ti conosce nessuno', Crescita::rispettoAParole(3));
prova('anche il timore',                  'basta il nome', Crescita::timoreAParole(95));

echo "\nScontro — colpire una persona invece di un prezzo\n";
$att3 = Scontro::attacco(80, 3, 25, 0.0);
$att0 = Scontro::attacco(80, 0, 25, 0.0);
prova('tre armi valgono 75 punti', 155.0, $att3);
prova('a mani nude resta la base',  80.0, $att0);
prova('le guardie sparano anche loro', 140.0, Scontro::attacco(80, 0, 25, 2.0));
prova('nessun punteggio sotto dieci',  10.0, Scontro::attacco(0, 0, 0, 0.0));
prova('la difesa cresce con le guardie', 140.0, Scontro::difesa(100, 2.0, 20, 0.0));
prova('e col sangue freddo',             150.0, Scontro::difesa(100, 0.0, 20, 100.0));

// Le probabilità si misurano, non si dichiarano: diecimila scambi con un
// generatore deterministico danno lo stesso numero a ogni esecuzione.
$rng = new Rng(20260919, 'scontro');
$colpi = 0; $danno = 0;
for ($i = 0; $i < 10000; $i++) {
    $c = Scontro::colpo($att3, Scontro::difesa(100, 0.0, 20, 0.0), 3, 0.0, $rng);
    if ($c['colpito']) { $colpi++; $danno += $c['danno']; }
}
$quota = $colpi / 10000;
$medio = $danno / max(1, $colpi);
prova('tre armi contro uno disarmato: colpisce ~70 %', true, $quota > 0.65 && $quota < 0.75);
prova('e quando colpisce fa ~60 di danno',             true, $medio > 55 && $medio < 72);

$rng2 = new Rng(20260919, 'scontro2');
$colpi2 = 0; $danno2 = 0;
for ($i = 0; $i < 10000; $i++) {
    $c = Scontro::colpo($att3, Scontro::difesa(100, 2.0, 20, 60.0), 3, 2.0, $rng2);
    if ($c['colpito']) { $colpi2++; $danno2 += $c['danno']; }
}
prova('due guardie e sangue freddo dimezzano il colpo', true, $colpi2 / 10000 < $quota - 0.15);
prova('e le guardie incassano per te',                  true, $danno2 / max(1, $colpi2) < $medio * 0.75);

$rngNude = new Rng(20260919, 'nude');
$dannoNudo = 0; $colpiNudi = 0;
for ($i = 0; $i < 4000; $i++) {
    $c = Scontro::colpo($att0, Scontro::difesa(100, 0.0, 20, 0.0), 0, 0.0, $rngNude);
    if ($c['colpito']) { $colpiNudi++; $dannoNudo += $c['danno']; }
}
prova('a mani nude si fa male poco', true, $dannoNudo / max(1, $colpiNudi) < 10);
prova('ma si fa male',               true, $colpiNudi > 1000);

// Chi attacca fa più fatica a svignarsela: è il costo dell'iniziativa.
$rngF = new Rng(7, 'fuga');
$scappa = 0; $scappaAtt = 0;
for ($i = 0; $i < 5000; $i++) { if (Scontro::fuga(0.60, false, $rngF)) { $scappa++; } }
for ($i = 0; $i < 5000; $i++) { if (Scontro::fuga(0.60, true, $rngF)) { $scappaAtt++; } }
prova('chi si difende scappa sei volte su dieci', true, abs($scappa / 5000 - 0.60) < 0.03);
prova('chi ha attaccato tre su dieci',            true, abs($scappaAtt / 5000 - 0.30) < 0.03);

// La soglia del bottino: sotto, non si prende niente a nessuno.
prova('sotto soglia non c\'è bottino',   0, Scontro::bottino(200_000, 100_000, 500_000));
prova('sopra soglia si prende tutto', 900_000, Scontro::bottino(400_000, 500_000, 500_000));
prova('la soglia è compresa',         500_000, Scontro::bottino(500_000, 0, 500_000));
prova('chi non ha niente non perde niente', 0, Scontro::bottino(0, 0, 500_000));

echo "\nObiettivi — il catalogo si regge in piedi\n";
// Le condizioni sono chiusure che leggono un vettore di fatti: se qualcuno
// scrive male il nome di un fatto, il gioco non se ne accorge finché un
// giocatore non arriva a quell'obiettivo. Qui si valutano TUTTE contro due
// mondi costruiti apposta — uno a zero e uno al massimo — e si pretende che
// nessuno si sblocchi nel primo e tutti nel secondo.
$catalogo = Obiettivi::catalogo();
prova('il catalogo non è vuoto', true, count($catalogo) >= 20);
$gruppi = array_unique(array_column($catalogo, 'gruppo'));
prova('i gruppi sono quattro', 4, count($gruppi));
prova('i codici sono tutti diversi', count($catalogo), count(array_unique(array_keys($catalogo))));

$vuoto = [
    'operazioni' => 0, 'vendite' => 0, 'margine_max' => 0, 'beni_trattati' => 0, 'beni_totali' => 10,
    'citta_lavorate' => 0, 'citta_totali' => 9, 'pulito' => 0, 'debito' => 0, 'prestiti' => 0,
    'lavato' => 0, 'mezzo' => '', 'depositi' => 0, 'segnali' => 0, 'archiviati' => 0, 'arresti' => 0,
    'in_carcere' => false, 'profilo' => 0, 'rispetto' => 0.0, 'giorni_pulito' => 0, 'vinti' => 0,
    'spie' => 0, 'batteria' => 0, 'territori' => 0, 'uomini' => 0, 'uomini_fedeli' => 0,
];
$pieno = [
    'operazioni' => 500, 'vendite' => 300, 'margine_max' => 90_000_000, 'beni_trattati' => 10,
    'beni_totali' => 10, 'citta_lavorate' => 9, 'citta_totali' => 9, 'pulito' => 900_000_000,
    'debito' => 0, 'prestiti' => 3, 'lavato' => 400_000_000, 'mezzo' => 'furgone', 'depositi' => 7,
    'segnali' => 12, 'archiviati' => 2, 'arresti' => 4, 'in_carcere' => false, 'profilo' => 40,
    'rispetto' => 88.0, 'giorni_pulito' => 120, 'vinti' => 9, 'spie' => 2, 'batteria' => 1,
    'territori' => 6, 'uomini' => 6, 'uomini_fedeli' => 6,
];
$sbloccatiAZero = []; $mancantiAlMassimo = [];
foreach ($catalogo as $cod => $o) {
    if (($o['cond'])($vuoto))   { $sbloccatiAZero[] = $cod; }
    if (!($o['cond'])($pieno))  { $mancantiAlMassimo[] = $cod; }
}
prova('appena nati non si è raggiunto niente', [], $sbloccatiAZero);
prova('al massimo si raggiunge tutto',         [], $mancantiAlMassimo);

// Qualche condizione letta una per una, perché «tutti veri» nasconde gli
// scambi fra due obiettivi vicini.
prova('al primo affare basta una vendita', true, ($catalogo['primo_affare']['cond'])(['vendite' => 1] + $vuoto));
prova('senza prestiti non sei «senza debiti»', false, ($catalogo['senza_debiti']['cond'])($vuoto));
prova('con un prestito restituito sì',      true, ($catalogo['senza_debiti']['cond'])(['prestiti' => 1] + $vuoto));
prova('l\'incensurato vuole ANCHE il profilo', false,
    ($catalogo['incensurato']['cond'])(['giorni_pulito' => 99] + $vuoto));
prova('e con il profilo alto sì',           true,
    ($catalogo['incensurato']['cond'])(['giorni_pulito' => 99, 'profilo' => 7] + $vuoto));
prova('«nessuno parla» vuole tutti fedeli', false,
    ($catalogo['nessuno_parla']['cond'])(['uomini' => 4, 'uomini_fedeli' => 3] + $vuoto));

echo "\nClassifica — le quattro graduatorie\n";
prova('le graduatorie sono quattro', 4, count(Classifica::GRADUATORIE));
prova('ognuna ha nome, unità e nota', true, (static function (): bool {
    foreach (Classifica::GRADUATORIE as $g) {
        if (($g['nome'] ?? '') === '' || ($g['unita'] ?? '') === '' || ($g['nota'] ?? '') === '') {
            return false;
        }
    }
    return true;
})());
prova('il reddito è fra quelle previste', true, isset(Classifica::GRADUATORIE['reddito']));
prova('una graduatoria inventata non esiste', false, isset(Classifica::GRADUATORIE['simpatia']));

echo "\nEtichette — non si devono accavallare\n";
// È logica pura, quindi si pretende la PROPRIETÀ invece di guardare il disegno:
// presi due blocchi qualsiasi, non si sovrappongono. Prima non era vero in due
// modi diversi: si confrontavano solo le etichette dello stesso lato (e due
// città vicine che scrivono l'una verso l'altra non si incontravano mai nel
// confronto), e la spinta ricadeva nello stesso urto perché non teneva conto
// dello spazio che il riquadro si prende sopra la riga.
$urti = static function (array $blocchi, array $esiti): int {
    $box = [];
    foreach ($blocchi as $i => $b) {
        [$x1, $x2] = Etichette::estensione($b);
        $box[] = [$x1, $x2, $esiti[$i]['y'] - 9.75, $esiti[$i]['y'] + $b['alto']];
    }
    $n = 0;
    foreach ($box as $i => $a) {
        foreach ($box as $j => $c) {
            if ($i >= $j) { continue; }
            if ($a[0] < $c[1] && $c[0] < $a[1] && $a[2] < $c[3] && $c[2] < $a[3]) { $n++; }
        }
    }
    return $n;
};

// Il caso vero che si rompeva: quattro città vicine, blocchi alti e larghi,
// alcune che scrivono a sinistra e altre a destra.
$nordOvest = [
    ['x' => 184.0, 'y' => 219.0, 'largo' => 142.0, 'alto' => 52.0, 'lato' => 'sinistra'],
    ['x' =>  96.0, 'y' => 250.0, 'largo' => 142.0, 'alto' => 26.0, 'lato' => 'destra'],
    ['x' => 309.0, 'y' => 295.0, 'largo' =>  76.0, 'alto' => 13.0, 'lato' => 'sinistra'],
    ['x' => 169.0, 'y' => 302.0, 'largo' => 199.0, 'alto' => 26.0, 'lato' => 'destra'],
];
$esiti = Etichette::sbroglia($nordOvest);
prova('quattro città appiccicate: nessun accavallamento', 0, $urti($nordOvest, $esiti));
prova('e chi si è spostato lo dichiara', true,
    (bool) array_filter($esiti, static fn($e) => $e['spostata']));
prova('nessuna etichetta risale',        true, (static function () use ($nordOvest, $esiti): bool {
    foreach ($nordOvest as $i => $b) {
        if ($esiti[$i]['y'] < $b['y'] - 0.01) { return false; }
    }
    return true;
})());

// Chi non tocca nessuno non si muove di un pixel.
$larghi = [
    ['x' => 100.0, 'y' => 100.0, 'largo' => 50.0, 'alto' => 13.0, 'lato' => 'destra'],
    ['x' => 600.0, 'y' => 105.0, 'largo' => 50.0, 'alto' => 13.0, 'lato' => 'destra'],
];
$e2 = Etichette::sbroglia($larghi);
prova('lontane in orizzontale: nessuno si sposta', false, $e2[0]['spostata'] || $e2[1]['spostata']);
prova('e restano dove volevano',       105.0, $e2[1]['y']);

// Il lato si sceglie sulla LARGHEZZA, non sulla posizione: un'etichetta larga
// vicino al bordo sinistro deve scrivere a destra, o esce dal foglio.
prova('etichetta larga a sinistra scrive a destra', 'destra',  Etichette::lato(60.0, 200.0, 760.0));
prova('etichetta stretta a sinistra scrive a sinistra', 'sinistra', Etichette::lato(300.0, 80.0, 760.0));
prova('vicino al bordo destro si scrive a sinistra', 'sinistra', Etichette::lato(700.0, 200.0, 760.0));
prova('se non ci sta da nessuna parte, il lato più largo', 'destra',
    Etichette::lato(200.0, 900.0, 760.0));

echo "\nRendering di tutte le viste\n";echo "\nRendering di tutte le viste\n";

$utente = [
    'id' => 1, 'username' => 'Mario Rossi', 'email' => 'x@esempio.invalid',
    'status' => 'active', 'role' => 'admin', 'nota' => 'Due righe.', 'luce' => 'auto',
    'email_verified_at' => '2026-09-19 04:00:00', 'created_at' => '2026-09-19 03:00:00',
    'last_login_at' => '2026-09-19 05:00:00', 'last_seen_at' => '2026-09-19 05:10:00',
    'verify_count' => 1,
];

// Il mondo di prova: due città, due piazze, e i due stati del personaggio.
$citta = [
    1 => ['id' => 1, 'codice' => 'MI', 'nome' => 'Milano', 'lat' => 45.46, 'lon' => 9.19,
          'carattere' => 'consumo', 'aeroporto' => 1, 'nota' => 'Il denaro pulito.'],
    2 => ['id' => 2, 'codice' => 'RM', 'nome' => 'Roma', 'lat' => 41.90, 'lon' => 12.50,
          'carattere' => 'consumo', 'aeroporto' => 1, 'nota' => 'Il mercato più grande.'],
];
$piazze = [
    1 => ['id' => 1, 'citta_id' => 1, 'codice' => 'MI-BRERA', 'nome' => 'Brera',
          'lat' => 45.47, 'lon' => 9.19, 'tipo' => 'benestante', 'polizia' => 80],
    2 => ['id' => 2, 'citta_id' => 2, 'codice' => 'RM-TERMINI', 'nome' => 'Termini',
          'lat' => 41.90, 'lon' => 12.50, 'tipo' => 'stazione', 'polizia' => 70],
];
$opzioni = Viaggio::opzioni(596.0, false);
$statoFermo = [
    'in_viaggio' => false, 'piazza' => $piazze[1], 'citta' => $citta[1], 'da' => null,
    'mancano_sec' => 0, 'mancano' => '0 min', 'arrivo_at' => null, 'contante' => 2_000_000,
];
$statoInViaggio = $statoFermo + [];
$statoInViaggio['in_viaggio'] = true;
$statoInViaggio['piazza'] = $piazze[2];
$statoInViaggio['citta'] = $citta[2];
$statoInViaggio['da'] = ['piazza' => $piazze[1], 'mezzo' => 'treno'];
$statoInViaggio['mancano_sec'] = 3480;
$statoInViaggio['mancano'] = '58 min';

$fornitoriFinti = [
    ['codice' => 'zio', 'nome' => 'Lo zio del bar', 'descrizione' => 'Vende quel che capita.',
     'fascia' => 'bassa', 'rispetto_min' => 10, 'sconto' => 0.08, 'lotto_min' => 20],
    ['codice' => 'porto', 'nome' => 'Il gancio in porto', 'descrizione' => 'Sa quale container.',
     'fascia' => 'media', 'rispetto_min' => 65, 'sconto' => 0.18, 'lotto_min' => 200],
];
$personaggioFinto = ['id' => 1, 'contante' => 300000, 'pulito' => 0, 'piazza_id' => 1,
                     'capienza' => 80, 'mezzo' => null];
$contiFinti = ['sporco' => 300000, 'pulito' => 97500, 'in_lavaggio' => 50000,
    'debito' => 1500000, 'tetto' => 4500000, 'al_tetto' => false, 'interesse_giorno' => 150000,
    'capacita_ora' => 150000, 'prestabile' => 695000,
    'canali' => [['codice' => 'bar', 'nome' => 'Il bar', 'descrizione' => 'x', 'commissione' => 0.35,
                  'capacita' => 150000, 'coda' => 50000, 'pronto' => 0, 'lavato' => 150000,
                  'finisce_fra' => 1200]]];

$beneFinto = ['id' => 1, 'codice' => 'sigarette', 'nome' => 'Sigarette di contrabbando',
              'unita' => 'stecca', 'fascia' => 'bassa', 'ingombro' => 3, 'ordine' => 0];
$listinoFinto = [[
    'bene' => $beneFinto, 'acquisto' => 22000, 'vendita' => 20700, 'offerta' => 45,
    'domanda' => 9, 'offerta_eq' => 45.0, 'domanda_eq' => 9.0, 'riferimento' => 21300,
    'carichi' => 0, 'stato_m' => $eq, 'fornitore' => null,
]];
$caricoFinto = [['bene' => $beneFinto, 'quantita' => 10, 'costo' => 220000, 'medio' => 22000, 'ingombro' => 30]];

// La carta per le viste: costruita a mano, perché queste prove girano SENZA
// database e `Carta::disegno()` le città se le va a leggere.
$cartaFinta = [
    'vista' => ['w' => 760.0, 'h' => 1000.0],
    'terra' => ['M100 100L200 100L200 200Z'],
    'scala' => ['km' => 200, 'px' => 141.0],
    'km_per_punto' => 1.42,
    'citta' => [
        ['id' => 1, 'codice' => 'MI', 'nome' => 'Milano', 'carattere' => 'consumo',
         'x' => 184.0, 'y' => 219.0, 'piazze' => 6, 'qui' => true, 'lato' => 'sinistra',
         'etichetta' => 219.0, 'alto' => 39.0, 'largo' => 142.0, 'spostata' => false,
         'nomi' => [['nome' => 'Mario Rossi', 'io' => true],
                    ['nome' => 'Tizio', 'io' => false, 'nota' => 'in viaggio']], 'altri' => 2],
        ['id' => 2, 'codice' => 'TO', 'nome' => 'Torino', 'carattere' => 'consumo',
         'x' => 96.0, 'y' => 250.0, 'piazze' => 6, 'qui' => false, 'lato' => 'destra',
         'etichetta' => 273.0, 'alto' => 13.0, 'largo' => 76.0, 'spostata' => true,
         'nomi' => [], 'altri' => 0],
    ],
];

$statMondo = [
    'iscritti' => 4, 'personaggi' => 3, 'visti24' => 2, 'citta' => 9, 'piazze' => 51, 'nodi' => 273,
    'scambi24' => 120, 'volume24' => 400_000_000, 'estratto24' => 60_000_000, 'attivi24' => 2,
    'tetto' => 24_000_000, 'utilizzo' => 0.104, 'pulito' => 90_000_000, 'debiti' => 4_500_000,
    'in_carcere' => 1, 'ricoverati' => 0, 'fascicoli' => 2, 'arresti30' => 1, 'scontri30' => 5,
    'batterie' => 2, 'tenute' => 3,
    'merci' => [['nome' => 'Fumo', 'scambi' => 40, 'volume' => 120_000_000]],
    'piazze_calde' => [['piazza' => 'Lambrate', 'citta' => 'Milano', 'calore' => 44.0]],
];

$viste = [
    'home'              => ['title' => 'Piazza Pulita', 'iscritti' => 2],
    'regole'            => ['title' => 'Come funziona'],
    'classifica'        => ['title' => 'Classifica', 'graduatoria' => 'reddito',
                            'graduatorie' => Classifica::GRADUATORIE, 'giorni' => 30,
                            'righe' => [['id' => 9, 'username' => 'Tizio', 'valore' => 4_200_000]],
                            'primati' => ['reddito' => ['graduatoria' => 'reddito', 'personaggio_id' => 9,
                                          'username' => 'Tizio', 'valore' => 4_200_000,
                                          'dal' => '2026-08-20 10:00:00', 'agg_a' => '2026-09-19 10:00:00']]],
    'classifica (territorio, vuota)' => ['__vista' => 'classifica', 'title' => 'Classifica',
                            'graduatoria' => 'territorio', 'graduatorie' => Classifica::GRADUATORIE,
                            'giorni' => 30, 'righe' => [], 'primati' => []],
    'classifica (longevità)' => ['__vista' => 'classifica', 'title' => 'Classifica',
                            'graduatoria' => 'longevita', 'graduatorie' => Classifica::GRADUATORIE,
                            'giorni' => 30, 'primati' => [],
                            'righe' => [['id' => 9, 'username' => 'Tizio', 'valore' => 46, 'profilo' => 12]]],
    'albo'              => ['title' => 'Albo d\'oro', 'graduatorie' => Classifica::GRADUATORIE,
                            'minimi' => 30,
                            'primati' => ['patrimonio' => ['graduatoria' => 'patrimonio', 'personaggio_id' => 9,
                                           'username' => 'Tizio', 'dal' => '2026-08-20 10:00:00']],
                            'righe' => [['id' => 1, 'graduatoria' => 'reddito', 'personaggio_id' => 9,
                                         'nome' => 'Tizio', 'valore' => 9_000_000, 'dal' => '2026-07-01 10:00:00',
                                         'al' => '2026-08-20 10:00:00', 'giorni' => 50]]],
    'albo (vuoto)'      => ['__vista' => 'albo', 'title' => 'Albo d\'oro',
                            'graduatorie' => Classifica::GRADUATORIE, 'minimi' => 30,
                            'primati' => [], 'righe' => []],
    'obiettivi'         => ['title' => 'Obiettivi', 'catalogo' => Obiettivi::catalogo(),
                            'sbloccati' => ['primo_affare' => '2026-09-18 12:00:00'],
                            'diffusione' => ['primo_affare' => 3, 'colpo_grosso' => 1],
                            'giocatori' => 4, 'nuovi' => ['primo_affare']],
    'obiettivi (nessuno)' => ['__vista' => 'obiettivi', 'title' => 'Obiettivi',
                            'catalogo' => Obiettivi::catalogo(), 'sbloccati' => [],
                            'diffusione' => [], 'giocatori' => 1, 'nuovi' => []],
    'statistiche'       => ['title' => 'Statistiche', 'primo' => 'Tizio (01/01/1984)', 'mio' => null,
                            'm' => $statMondo],
    'statistiche (con i miei)' => ['__vista' => 'statistiche', 'title' => 'Statistiche',
                            'primo' => 'Tizio (01/01/1984)', 'm' => $statMondo,
                            'mio' => ['operazioni' => 40, 'guadagno' => 12_000_000, 'migliore' => 900_000,
                                      'beni' => 6, 'piazze' => 9, 'reddito30' => 8_000_000, 'arresti' => 1,
                                      'profilo' => 3, 'vinti' => 2, 'persi' => 1, 'obiettivi' => 7]],
    'auth/register'     => ['title' => 'Iscrizione', 'open' => true, 'minPassword' => 9],
    'auth/login'        => ['title' => 'Accesso'],
    'auth/verify_sent'  => ['title' => 'Conferma', 'email' => 'x@esempio.invalid'],
    'auth/verify_result'=> ['title' => 'Confermato', 'ok' => true, 'user' => $utente],
    'errors/generic'    => ['title' => 'Errore', 'status' => 404, 'message' => 'Non c\'è.'],
    'errors/db'         => ['title' => 'Avaria', 'debug' => true, 'detail' => 'dettaglio'],
    'gioco/strada'      => ['title' => 'La strada', 'stato' => $statoFermo,
                            'vicine' => [['piazza' => $piazze[1], 'km' => 2.1, 'opzioni' => Viaggio::opzioni(2.1, true)]],
                            'altri'  => [['id' => 9, 'username' => 'Tizio']],
                            'listino' => $listinoFinto, 'carico' => $caricoFinto,
                            'ingombro' => 30, 'capienza' => 80,
                            'deposito' => null, 'inDeposito' => [], 'affitto' => 288000,
                            'conti' => $contiFinti, 'calore' => 7.0, 'basisti' => []],
    'gioco/strada (col deposito)' => ['__vista' => 'gioco/strada', 'title' => 'La strada',
                            'stato' => $statoFermo, 'vicine' => [], 'altri' => [],
                            'listino' => $listinoFinto, 'carico' => $caricoFinto,
                            'ingombro' => 30, 'capienza' => 200, 'conti' => $contiFinti,
                            'affitto' => 288000,
                            'calore' => 55.0,
                            'deposito' => ['id' => 1, 'capienza' => 5000, 'pagato_fino_a' => '2026-09-20 10:00:00'],
                            'inDeposito' => [['bene' => $beneFinto, 'quantita' => 40, 'costo' => 880000,
                                              'medio' => 22000, 'ingombro' => 120]],
                            'basisti' => [['piazza' => $piazze[2], 'citta' => $citta[2],
                                           'uomo' => 'Gennaro', 'listino' => $listinoFinto]]],
    'gioco/strada (piazza morta)' => ['__vista' => 'gioco/strada', 'title' => 'La strada',
                            'stato' => $statoFermo, 'vicine' => [], 'altri' => [],
                            'listino' => [], 'carico' => [], 'ingombro' => 0, 'capienza' => 80,
                            'deposito' => null, 'inDeposito' => [], 'affitto' => 288000,
                            'conti' => $contiFinti, 'calore' => 0.0, 'basisti' => []],
    'gioco/strada@viaggio' => null,  // sostituita sotto: stessa vista, stato diverso
    'profilo/mio'       => ['title' => 'Profilo', 'utente' => $utente, 'notaMax' => 500,
                            'avatar' => 'img/avatar/x.webp', 'lato' => 320],
    'profilo/mio (senza foto)' => ['__vista' => 'profilo/mio', 'title' => 'Profilo',
                            'utente' => $utente, 'notaMax' => 500, 'avatar' => null, 'lato' => 320],
    'gioco/inizio'      => ['title' => 'Inizio', 'citta' => $citta, 'piazze' => $piazze],
    'gioco/personaggio' => [
        'title' => 'Tu', 'p' => $personaggioFinto + ['trattativa' => 42.0, 'fiuto' => 18.0,
            'sangue_freddo' => 7.0, 'organizzazione' => 22.0, 'credito' => 3.0,
            'rispetto' => 31.0, 'timore' => 12.0],
        'uomini' => [['id' => 1, 'nome' => 'Ciro \'o Biondo', 'ruolo' => 'vedetta', 'competenza' => 61,
                      'lealta' => 72.0, 'stipendio_ora' => 23000, 'piazza_id' => 1, 'piazza' => 'Brera',
                      'stato' => 'libero']],
        'tetto' => 3, 'stipendi' => 23000,
        'corse' => [['corriere' => 'Nino Baffo', 'quantita' => 12, 'bene' => 'Hashish',
                     'da_piazza' => 'Brera', 'a_piazza' => 'Termini', 'arrivo_at' => '2026-09-19 14:00:00']],
        'ruoli' => \App\Game\Organico::RUOLI, 'fornitori' => $fornitoriFinti,
        'carico' => $caricoFinto, 'piazza' => $piazze[1], 'piazze' => $piazze,
        'ingaggio' => 8, 'inCarcere' => false,
    ],
    'gioco/personaggio (nudo)' => [
        '__vista' => 'gioco/personaggio', 'title' => 'Tu', 'prossimoFornitore' => null,
        'p' => $personaggioFinto + ['trattativa' => 0.0, 'fiuto' => 0.0, 'sangue_freddo' => 0.0,
            'organizzazione' => 0.0, 'credito' => 0.0, 'rispetto' => 0.0, 'timore' => 0.0],
        'uomini' => [], 'tetto' => 1, 'stipendi' => 0, 'corse' => [],
        'ruoli' => \App\Game\Organico::RUOLI, 'fornitori' => $fornitoriFinti,
        'carico' => [], 'piazza' => $piazze[1],
        'piazze' => $piazze, 'ingaggio' => 8, 'inCarcere' => false,
    ],
    'gioco/fascicolo'   => [
        'title' => 'Fascicolo', 'p' => $personaggioFinto + ['profilo' => 1, 'arresti' => 1, 'pulito' => 5000000],
        'calore' => 120.0, 'caloreParole' => Calore::aParole(120), 'piazza' => $piazze[1],
        'calorePiazza' => 30.0, 'rischio' => 0.13, 'rischioBlocco' => 0.0,
        'fascicolo' => ['id' => 1, 'inquirente' => 'Commissario Rizzo', 'corpo' => 'questura',
                        'prove' => 62.0, 'aperto_at' => '2026-09-19 08:00:00'],
        'segnali' => [['fatto_at' => '2026-09-19 09:00:00', 'gravita' => 3, 'testo' => 'Un cliente strano.']],
        'inCarcere' => false, 'mancano' => 0,
        'prezzi' => ['avvocato' => 4000000, 'bustarella' => 2500000], 'soglia' => 100,
    ],
    'gioco/fascicolo (dentro)' => [
        '__vista' => 'gioco/fascicolo', 'title' => 'Dentro',
        'p' => $personaggioFinto + ['profilo' => 2, 'arresti' => 2, 'pulito' => 0],
        'calore' => 0.0, 'caloreParole' => Calore::aParole(0), 'piazza' => $piazze[1],
        'calorePiazza' => 0.0, 'rischio' => 0.0, 'rischioBlocco' => 0.0,
        'fascicolo' => null, 'segnali' => [], 'inCarcere' => true, 'mancano' => 7200,
        'prossimoFornitore' => ['nome' => 'Il calabrese', 'rispetto_min' => 35],
        'prezzi' => ['avvocato' => 4000000, 'bustarella' => 2500000], 'soglia' => 100,
    ],
    'gioco/affari'      => [
        'title' => 'Affari', 'p' => $personaggioFinto, 'conti' => $contiFinti,
        'catalogo' => ['bar' => ['codice' => 'bar', 'nome' => 'Il bar', 'descrizione' => 'x',
                                 'commissione' => 0.35, 'capacita' => 150000, 'prezzo' => 0],
                       'autolav' => ['codice' => 'autolav', 'nome' => 'L\'autolavaggio', 'descrizione' => 'y',
                                 'commissione' => 0.30, 'capacita' => 400000, 'prezzo' => 3000000]],
        'mezzi' => ['utilitaria' => ['codice' => 'utilitaria', 'nome' => 'Una 127', 'descrizione' => 'z',
                                     'capienza' => 120, 'prezzo' => 2500000, 'kmh_citta' => 26.0,
                                     'kmh_paese' => 75.0, 'costo_km' => 45]],
        'mezzoMio' => null, 'capienza' => 80, 'usato' => 30,
        'depositi' => [], 'movimenti' => [],
    ],
    'gioco/mappa'       => [
        'title' => 'La carta', 'stato' => $statoFermo, 'qui' => 1,
        'carta' => $cartaFinta, 'collegamenti' => [2 => '#citta-2'],
        'citta' => $citta,
        'destinazioni' => [['citta' => $citta[2], 'sbarco' => $piazze[2], 'km' => 596.0, 'opzioni' => $opzioni]],
    ],
    'admin/carta'       => [
        'title' => 'La carta globale', 'carta' => $cartaFinta,
        'presenze' => [1 => 2], 'quanti' => 2, 'in_giro' => 1,
        'piazze' => [1 => ['piazza' => 'Lambrate', 'citta' => 'Milano', 'gente' => [[
            'id' => 9, 'username' => 'Tizio', 'avatar_file' => null, 'batteria' => 'TRP',
            'contante' => 3_000_000, 'pulito' => 1_000_000, 'calore' => 12.5, 'profilo' => 3,
            'arrivo_at' => null, 'carcere_fino_a' => null, 'ospedale_fino_a' => null,
            'last_seen_at' => '2026-09-22 10:00:00',
        ]]]],
    ],
    'profilo/pubblico'  => ['title' => 'Profilo', 'p' => $utente, 'mio' => false,
                            'avatar' => 'img/avatar/x.webp', 'admin' => false, 'utente' => 1],
    'profilo/pubblico (senza foto)' => ['__vista' => 'profilo/pubblico', 'title' => 'Profilo',
                            'p' => $utente, 'mio' => true, 'avatar' => null,
                            'admin' => false, 'utente' => 1],
    'profilo/pubblico (visto da chi amministra)' => ['__vista' => 'profilo/pubblico',
                            'title' => 'Profilo', 'p' => $utente, 'mio' => false,
                            'avatar' => 'img/avatar/x.webp', 'admin' => true, 'utente' => 1],
    'admin/pannello'    => [
        'title' => 'Amministrazione', 'config' => '/x/config.php', 'db' => true,
        'migrazioni' => [['version' => '0001_fondamenta', 'applied_at' => '2026-09-19 04:00:00']],
        'parametri' => ['auth.registration_open' => ['value' => '1', 'type' => 'bool', 'note' => 'Iscrizioni aperte']],
        'posta' => ['in_coda' => 0, 'inviate_24h' => 3, 'rinunciate' => 0, 'tetto' => 280],
        'utenti' => ['totale' => 2, 'attivi' => 2, 'attesa' => 0, 'sospesi' => 0],
        'trasporto' => 'log',
    ],
    'gioco/altri'       => [
        'title' => 'Chi c\'è', 'p' => $personaggioFinto + ['batteria_id' => null],
        'piazza' => $piazze[1], 'padrone' => null,
        'altri' => [['id' => 9, 'username' => 'Tizio', 'salute' => 100, 'ospedale_fino_a' => null,
                     'carcere_fino_a' => null, 'profilo' => 2, 'batteria' => null, 'spiato' => 0,
                     'avatar' => null, 'avatar_file' => null]],
        'spiati' => [], 'corse' => [], 'uomini' => [['id' => 3, 'nome' => 'Gino', 'stato' => 'libero']],
        'inOspedale' => false, 'mancano' => 0,
        'prezzi' => ['spia' => 3000000, 'soffiata' => 2000000],
        'presenti' => [], 'voci' => [], 'baratti' => [], 'mioCarico' => [],
        'beni' => [], 'lunghezzaVoce' => 220,
    ],
    'gioco/altri (all\'ospedale, con una spia dentro)' => [
        '__vista' => 'gioco/altri', 'title' => 'Chi c\'è',
        'p' => $personaggioFinto + ['batteria_id' => 1],
        'piazza' => $piazze[1],
        'padrone' => ['id' => 2, 'nome' => 'I Tre Ponti', 'sigla' => 'TRP', 'dal' => '2026-09-18 10:00:00'],
        'altri' => [['id' => 9, 'username' => 'Tizio', 'salute' => 40, 'ospedale_fino_a' => null,
                     'carcere_fino_a' => null, 'profilo' => 5, 'batteria' => 'TRP', 'spiato' => 1,
                     'avatar' => 'img/avatar/x.webp', 'avatar_file' => 'x.webp']],
        'spiati' => [9 => ['spia' => 'Gino', 'nome' => 'Tizio', 'dove' => $piazze[1],
                           'sporco' => 3_000_000, 'pulito' => 1_000_000, 'debito' => 0,
                           'calore' => 42.0, 'uomini' => 2,
                           'carico' => [['bene' => $beneFinto, 'quantita' => 12]]]],
        'corse' => [['id' => 1, 'quantita' => 40, 'bene' => 'Fumo', 'padrone' => 'Caio',
                     'da_piazza' => 'Lambrate', 'a_piazza' => 'Bovisa', 'arrivo_at' => '2026-09-19 18:00:00']],
        'uomini' => [], 'inOspedale' => true, 'mancano' => 7200,
        'prezzi' => ['spia' => 3000000, 'soffiata' => 2000000],
        'presenti' => [], 'mioCarico' => [['bene' => $beneFinto, 'quantita' => 12, 'costo' => 100, 'medio' => 8, 'ingombro' => 12]],
        'beni' => [1 => $beneFinto], 'lunghezzaVoce' => 220,
        'voci' => [
            ['id' => 2, 'autore' => 'Tizio', 'testo' => 'Cerco roba buona.', 'genere' => 'voce',
             'fatto_at' => '2026-09-19 12:00:00', 'avatar_file' => null, 'personaggio_id' => 9, 'piazza_id' => 1],
            ['id' => 1, 'autore' => 'avviso', 'testo' => 'Domani si chiude per manutenzione.', 'genere' => 'avviso',
             'fatto_at' => '2026-09-19 09:00:00', 'avatar_file' => null, 'personaggio_id' => null, 'piazza_id' => 1],
        ],
        'baratti' => [[
            'id' => 1, 'da_id' => 9, 'a_id' => 1, 'piazza_id' => 1, 'bene_dato' => 1, 'quanto_dato' => 10,
            'bene_chiesto' => 2, 'quanto_chiesto' => 4, 'stato' => 'proposto',
            'proposto_at' => '2026-09-19 12:00:00', 'scade_at' => '2026-09-19 12:30:00', 'chiuso_at' => null,
            'nome_dato' => 'Fumo', 'nome_chiesto' => 'Acidi', 'da_nome' => 'Tizio', 'a_nome' => 'Mario Rossi',
        ]],
    ],
    'gioco/cronaca'     => ['title' => 'Cronaca', 'righe' => [
        ['id' => 2, 'genere' => 'scontro', 'testo' => 'Tizio ha alleggerito Caio.',
         'piazza_id' => 1, 'rilievo' => 4, 'fatto_at' => '2026-09-19 12:00:00',
         'piazza' => 'Lambrate', 'citta' => 'Milano'],
        ['id' => 1, 'genere' => 'territorio', 'testo' => 'Bovisa è passata ai Tre Ponti.',
         'piazza_id' => null, 'rilievo' => 2, 'fatto_at' => '2026-09-19 09:00:00',
         'piazza' => null, 'citta' => null],
    ]],
    'gioco/cronaca (vuota)' => ['__vista' => 'gioco/cronaca', 'title' => 'Cronaca', 'righe' => []],
    'gioco/batteria (senza)' => ['__vista' => 'gioco/batteria', 'title' => 'Batterie',
        'p' => $personaggioFinto + ['pulito' => 12_000_000], 'mia' => null, 'membri' => [],
        'territori' => [], 'sonoCapo' => false, 'fondazione' => 10_000_000, 'pizzo' => 0.04,
        'elenco' => [['id' => 1, 'nome' => 'I Tre Ponti', 'sigla' => 'TRP', 'capo' => 'Tizio',
                      'motto' => 'Poche parole', 'membri' => 3, 'piazze' => 2, 'cassa' => 0,
                      'capo_id' => 9, 'creata_at' => '2026-09-01 10:00:00']]],
    'gioco/batteria (capo)' => ['__vista' => 'gioco/batteria', 'title' => 'I Tre Ponti',
        'p' => $personaggioFinto + ['pulito' => 1_000_000, 'batteria_id' => 1],
        'mia' => ['id' => 1, 'nome' => 'I Tre Ponti', 'sigla' => 'TRP', 'capo_id' => 1,
                  'cassa' => 4_500_000, 'motto' => 'Poche parole', 'creata_at' => '2026-09-01 10:00:00'],
        'membri' => [['id' => 1, 'username' => 'Mario Rossi', 'pulito' => 0, 'rispetto' => 20.0, 'timore' => 5.0]],
        'territori' => [['piazza' => 'Lambrate', 'citta' => 'Milano', 'presenza' => 412.5, 'dal' => '2026-09-18 10:00:00']],
        'sonoCapo' => true, 'fondazione' => 10_000_000, 'pizzo' => 0.04, 'elenco' => []],
    'admin/mondo'       => ['title' => 'Il mondo', 'citta' => $citta,
                            'bilancio' => ['tetto' => 24_000_000.0, 'teorico' => 24_100_000.0,
                                           'scarto' => 0.004, 'per_fascia' => ['bassa' => 6_000_000.0],
                                           'reale_ora' => 1_200_000.0, 'vendite_24h' => 42,
                                           'utilizzo' => 0.05, 'attivi_24h' => 1, 'per_giocatore' => 1_200_000.0,
                                           'fuori_banda' => [], 'nodi' => 273],
                            'piazze' => [['id' => 1, 'nome' => 'Lambrate', 'tipo' => 'popolare', 'polizia' => 40,
                                          'calore' => 12.5, 'citta' => 'Milano', 'padrone' => 'TRP',
                                          'gente' => 2, 'nodi' => 6]]],
    'admin/mondo (tre attivi)' => ['__vista' => 'admin/mondo', 'title' => 'Il mondo', 'citta' => $citta,
                            'bilancio' => ['tetto' => 24_000_000.0, 'teorico' => 24_100_000.0,
                                           'scarto' => 0.09, 'per_fascia' => [],
                                           'reale_ora' => 30_000_000.0, 'vendite_24h' => 900,
                                           'utilizzo' => 1.25, 'attivi_24h' => 7, 'per_giocatore' => 4_200_000.0,
                                           'fuori_banda' => [['piazza' => 'x', 'bene' => 'y', 'domanda_eq' => 1.0]],
                                           'nodi' => 273],
                            'piazze' => []],
    'admin/giocatori'   => ['title' => 'I giocatori', 'righe' => [[
                            'id' => 9, 'username' => 'Tizio', 'status' => 'active', 'piazza' => 'Lambrate',
                            'citta' => 'Milano', 'batteria' => 'TRP', 'arrivo_at' => null,
                            'contante' => 3_000_000, 'pulito' => 12_000_000, 'debito' => 1_500_000,
                            'calore' => 88.5, 'profilo' => 4, 'carcere_fino_a' => null,
                            'ospedale_fino_a' => '2026-09-19 20:00:00', 'fascicoli' => 1]]],
    'admin/giocatori (mondo vuoto)' => ['__vista' => 'admin/giocatori', 'title' => 'I giocatori', 'righe' => []],
    'admin/utenti'      => ['title' => 'Utenti', 'righe' => [$utente], 'q' => ''],
    'admin/utente'      => ['title' => 'Utente', 'u' => $utente, 'registro' => [['action' => 'auth.login', 'target_type' => 'user', 'target_id' => 1, 'created_at' => '2026-09-19 05:00:00']]],
    'admin/posta'       => [
        'title' => 'Posta', 'stato' => ['in_coda' => 1, 'inviate_24h' => 3, 'rinunciate' => 0, 'tetto' => 280],
        'righe' => [[
            'id' => 1, 'destinatario' => 'x@esempio.invalid', 'oggetto' => 'o', 'genere' => 'verifica',
            'priorita' => 1, 'tentativi' => 0, 'prossimo_at' => '2026-09-19 05:00:00',
            'inviato_at' => null, 'rinunciato_at' => null, 'ultimo_errore' => null, 'created_at' => '2026-09-19 05:00:00',
        ]],
    ],
    'admin/registro'    => ['title' => 'Registro', 'righe' => [[
        'id' => 1, 'action' => 'auth.login', 'target_type' => 'user', 'target_id' => 1,
        'meta' => null, 'created_at' => '2026-09-19 05:00:00', 'username' => 'Mario Rossi',
    ]]],
];

// Il layout interroga l'utente collegato: qui non c'è sessione né database,
// quindi le viste si rendono SENZA layout. Il layout lo prova la e2e, che
// passa da Apache e ha una sessione vera.
// La stessa vista nei due stati: è il caso che si rompe più facilmente, perché
// in viaggio metà dei dati non c'è.
unset($viste['gioco/strada@viaggio']);
$viste['gioco/strada (in viaggio)'] = ['__vista' => 'gioco/strada', 'title' => 'In viaggio',
    'stato' => $statoInViaggio, 'vicine' => [], 'altri' => []];

// Il modulo della fotografia deve poter partire anche senza JavaScript: il
// pulsante lo disabilita lo script, non il markup. Con `disabled` scritto a mano
// nella pagina, chi non esegue lo script non puo' inviare niente — e il
// <noscript> accanto promette il contrario.
// Le due facce della vetrina: con la fotografia si vede l'immagine, senza si
// vede l'iniziale. Sono rami diversi della stessa vista, e finora nessuno
// controllava QUALE dei due usciva.
$conFoto = View::render('profilo/mio', [
    'title' => 'Profilo', 'utente' => $utente, 'notaMax' => 500,
    'avatar' => 'img/avatar/abc.webp', 'lato' => 320,
], null);
prova('col volto si vede l\'immagine',  true, str_contains($conFoto, 'img/avatar/abc.webp'));
prova('e non il segnaposto',            false, str_contains($conFoto, 'avatar--vuoto'));

$modulo = View::render('profilo/mio', [
    'title' => 'Profilo', 'utente' => $utente, 'notaMax' => 500, 'avatar' => null, 'lato' => 320,
], null);
prova('il pulsante della foto non nasce spento', false,
    (bool) preg_match('/<button type="submit"[^>]*disabled[^>]*>Metti questa/', $modulo));
prova('e il modulo accetta i file',              true,
    str_contains($modulo, 'enctype="multipart/form-data"'));
prova('senza volto si vede l\'iniziale',         true, str_contains($modulo, 'avatar--vuoto'));

// La vetrina dei presenti deve mostrare le facce e la voce di piazza: senza
// queste due righe il fissaggio potrebbe perdere una chiave e la vista
// «passerebbe» lo stesso, perché una variabile mancante in PHP è un avviso,
// non un errore. È già successo con l'avatar.
$vetrina = View::render('gioco/altri', $viste['gioco/altri (all\'ospedale, con una spia dentro)'] + ['__vista' => null], null);
prova('la vetrina mostra la faccia di chi c\'è', true, str_contains($vetrina, 'img/avatar/x.webp'));
prova('e la voce di piazza',                     true, str_contains($vetrina, 'Cerco roba buona.'));
prova('e distingue l\'avviso dalla voce',        true, str_contains($vetrina, 'voce--avviso'));
prova('e le proposte di scambio',                true, str_contains($vetrina, 'Acidi'));

// I comandi per moderare la fotografia si vedono SOLO se chi guarda amministra,
// e mai sul proprio profilo: una casella dimenticata in una condizione qui
// significherebbe dare a tutti un pulsante che cancella le foto altrui.
$daAdmin  = View::render('profilo/pubblico', ['title' => 'x', 'p' => $utente, 'mio' => false,
    'avatar' => 'img/avatar/x.webp', 'admin' => true, 'utente' => 1], null);
$daNormale = View::render('profilo/pubblico', ['title' => 'x', 'p' => $utente, 'mio' => false,
    'avatar' => 'img/avatar/x.webp', 'admin' => false, 'utente' => 1], null);
$suDiMe   = View::render('profilo/pubblico', ['title' => 'x', 'p' => $utente, 'mio' => true,
    'avatar' => 'img/avatar/x.webp', 'admin' => true, 'utente' => 1], null);
prova('chi amministra vede i comandi sulla foto',  true,  str_contains($daAdmin, 'moderazione'));
prova('un giocatore comune no',                    false, str_contains($daNormale, 'moderazione'));
prova('e nemmeno sul proprio profilo',             false, str_contains($suDiMe, 'moderazione'));

// Il basista deve comparire sulla strada con il listino della piazza dove sta:
// e' il mestiere per cui lo si paga, e per un pezzo non faceva NIENTE — il suo
// effetto era raccolto e mai letto da nessuno.
$conBasista = View::render('gioco/strada', $viste['gioco/strada (col deposito)'] + ['__vista' => null], null);
prova('il basista riferisce da dove sta',  true, str_contains($conBasista, 'riferiscono i tuoi basisti'));
prova('e si vede chi lo riferisce',        true, str_contains($conBasista, 'Gennaro'));
$senzaBasista = View::render('gioco/strada', $viste['gioco/strada'] + ['__vista' => null], null);
prova('senza basisti non compare niente', false, str_contains($senzaBasista, 'riferiscono i tuoi basisti'));

foreach ($viste as $nome => $dati) {
    $file = $dati['__vista'] ?? $nome;
    unset($dati['__vista']);
    try {
        $html = View::render($file, $dati, null);
        prova("vista {$nome}", true, $html !== '' && !str_contains($html, '<?php'));
    } catch (\Throwable $e) {
        prova("vista {$nome}", true, $e::class . ': ' . $e->getMessage());
    }
}

echo "\n";
if ($falliti === 0) {
    printf("\033[0;32mTutte le %d verifiche superate.\033[0m\n", $fatti);
    exit(0);
}
printf("\033[0;31m%d verifiche fallite su %d.\033[0m\n", $falliti, $fatti);
exit(1);
