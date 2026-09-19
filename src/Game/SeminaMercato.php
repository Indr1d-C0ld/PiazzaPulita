<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;
use App\Sim\Prezzi;

/**
 * Costruisce il mercato a partire dal tetto di reddito del mondo.
 *
 * Questa classe è l'unico posto dove si decidono giacenze e assorbimenti, e li
 * decide **invertendo il tetto**, mai scrivendoli a mano:
 *
 *     A*(p,b) = quota(p,b) x R / spread_unitario(b)
 *
 * dove le quote sommano a 1. Cambiare `mondo.reddito_orario` e riseminare
 * riscala l'economia intera restando coerente. Se un giorno una piazza sembra
 * troppo povera, si tocca la sua affinità in `db/seed/beni.php` — che è un
 * peso RELATIVO — e qualcun altro perde quello che lei guadagna. Scrivere un
 * assorbimento a mano è il modo di far marcire l'invariante alla terza modifica.
 *
 * È idempotente: rilanciarla riallinea i valori di equilibrio senza toccare le
 * giacenze correnti, che sono il presente e appartengono ai giocatori.
 */
final class SeminaMercato
{
    /**
     * @param bool $conserva se vero, le giacenze correnti restano dove sono.
     *        Per difetto vengono riportate all'equilibrio nuovo: riseminare è
     *        un atto amministrativo che ridefinisce l'economia, e lasciare il
     *        presente appeso a un equilibrio che non esiste più produce listini
     *        assurdi — prezzi d'acquisto al doppio e di vendita a metà — finché
     *        il ritorno alla media non recupera, che sono ore.
     *
     * @return array{beni:int,mercati:int,piazze_vuote:int,teorico:float}
     */
    public static function semina(string $fileSeme, bool $conserva = false): array
    {
        /** @var array<string,mixed> $d */
        $d = require $fileSeme;

        $R = (float) GameConfig::int('mondo.reddito_orario', 24_000_000);
        $oreGiacenza = (float) GameConfig::int('mercato.ore_giacenza', 5);

        // --- beni e prezzi di riferimento -----------------------------------
        // NON si usa INSERT ... ON DUPLICATE KEY UPDATE qui, e il motivo non è
        // stilistico: InnoDB assegna il valore di auto-incremento PRIMA di
        // accorgersi del duplicato, e lo brucia comunque. `beni.id` è un
        // TINYINT: dopo una ventina di riseminature il contatore passa 255 e la
        // semina muore con «Out of range value for column id». È successo
        // davvero, durante la taratura, dopo sedici giri. Qui si guarda prima
        // se la riga c'è, e si inserisce solo quando serve davvero.
        $ordine = 0;
        foreach ($d['beni'] as [$codice, $nome, $unita, $fascia, $min, $max, $quota, $spreadFr, $ingombro, $rischio]) {
            $campi = [$nome, $unita, $fascia, $min, $max, $quota, $spreadFr, $ingombro, $rischio, $ordine++];
            $gia = Database::first('SELECT id FROM beni WHERE codice = ?', [$codice]);
            if ($gia !== null) {
                Database::run(
                    'UPDATE beni SET nome = ?, unita = ?, fascia = ?, prezzo_min = ?, prezzo_max = ?,
                            quota = ?, spread_frazione = ?, ingombro = ?, rischio = ?, ordine = ?
                      WHERE id = ?',
                    array_merge($campi, [(int) $gia['id']])
                );
            } else {
                Database::run(
                    'INSERT INTO beni (nome, unita, fascia, prezzo_min, prezzo_max, quota,
                                       spread_frazione, ingombro, rischio, ordine, codice)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge($campi, [$codice])
                );
            }
        }

        $beni = [];
        foreach (Database::all('SELECT * FROM beni') as $r) {
            $beni[(string) $r['codice']] = $r;
        }

        $passo = Prezzi::passo(Clock::adesso()->getTimestamp(), max(1, GameConfig::int('prezzi.passo_minuti', 5)));
        foreach ($beni as $b) {
            // Il prezzo di partenza è il centro della forchetta storica. Se
            // esiste già non si tocca: quello è il presente del mondo.
            Database::run(
                'INSERT IGNORE INTO prezzi (bene_id, p0, passo) VALUES (?, ?, ?)',
                [(int) $b['id'], (int) round(((int) $b['prezzo_min'] + (int) $b['prezzo_max']) / 2), $passo]
            );
        }

        // --- peso delle città ------------------------------------------------
        foreach ($d['peso_citta'] as $codice => $peso) {
            Database::run('UPDATE citta SET peso = ? WHERE codice = ?', [$peso, $codice]);
        }

        $citta = [];
        foreach (Database::all('SELECT * FROM citta') as $r) {
            $citta[(int) $r['id']] = $r;
        }
        $piazze = Database::all('SELECT * FROM piazze');

        // --- quote e caratteri, bene per bene --------------------------------
        $teorico = 0.0;
        $nMercati = 0;
        $toccate = [];

        foreach ($d['beni'] as [$codice, , , $fascia, $min, $max, $quotaBene, $spreadFr]) {
            $bene = $beni[$codice] ?? null;
            if ($bene === null) {
                continue;
            }
            $affinita = $d['affinita'][$codice] ?? [];
            $indiceTipo = array_flip($d['tipi']);

            // Primo giro: i pesi grezzi, per poterli normalizzare.
            $pesi = [];
            foreach ($piazze as $p) {
                $i = $indiceTipo[(string) $p['tipo']] ?? null;
                $aff = $i === null ? 0.0 : (float) ($affinita[$i] ?? 0.0);
                if ($aff <= 0.0) {
                    continue;      // qui questa roba non gira, e va detto col silenzio
                }
                $c = $citta[(int) $p['citta_id']] ?? null;
                if ($c === null) {
                    continue;
                }
                $pesi[(int) $p['id']] = $aff * (float) $c['peso'];
            }
            $somma = array_sum($pesi);
            if ($somma <= 0) {
                continue;
            }

            $spreadUnitario = (($min + $max) / 2.0) * $spreadFr;

            // --- Potatura -----------------------------------------------------
            //
            // Un bene sparso su tutte le piazze dove ha un minimo di affinità si
            // diluisce fino a non essere più un mercato: l'eroina a Brera
            // finirebbe a sette decimi di grammo l'ora, cioè un'ora e mezza di
            // attesa per vendere un grammo. Non è rarità, è un semaforo.
            //
            // Quindi: si tolgono i nodi che scenderebbero sotto la banda e si
            // ridistribuisce fra i superstiti, finché non resta nessuno sotto.
            // Il risultato è che ogni merce si concentra dove ha davvero senso —
            // e quali piazze siano non lo decide una lista scritta a mano, lo
            // decidono le affinità e i pesi delle città.
            // Tetto esplicito al numero di piazze, dove serve (le armi).
            $maxPiazze = (int) ($d['max_piazze'][$codice] ?? 0);
            if ($maxPiazze > 0 && count($pesi) > $maxPiazze) {
                arsort($pesi);
                $pesi = array_slice($pesi, 0, $maxPiazze, true);
            }

            $bandaMin = (float) GameConfig::int('mercato.banda_min', 3);
            if (!in_array($codice, (array) ($d['senza_banda'] ?? []), true)) {
                for ($giro = 0; $giro < 20 && count($pesi) > 1; $giro++) {
                    $somma = array_sum($pesi);
                    $scartati = false;
                    foreach ($pesi as $pid => $peso) {
                        $a = ($quotaBene * ($peso / $somma)) * $R / max(1.0, $spreadUnitario);
                        if ($a < $bandaMin) {
                            unset($pesi[$pid]);
                            $scartati = true;
                        }
                    }
                    if (!$scartati) {
                        break;
                    }
                    // Tolti tutti quanti: il bene è troppo raro per la banda.
                    // Si tiene almeno il nodo più forte, o sparisce dal mondo.
                    if ($pesi === []) {
                        break;
                    }
                }
                if ($pesi === []) {
                    continue;
                }
            }
            $somma = array_sum($pesi);
            if ($somma <= 0) {
                continue;
            }

            // Le piazze scartate non devono restare in tabella da una semina
            // precedente, o resterebbero mercati fantasma a quota vecchia.
            $vivi = array_keys($pesi);
            $segni = implode(',', array_fill(0, count($vivi), '?'));
            Database::run(
                "DELETE FROM mercati WHERE bene_id = ? AND piazza_id NOT IN ({$segni})",
                array_merge([(int) $bene['id']], $vivi)
            );

            foreach ($pesi as $piazzaId => $peso) {
                $p = null;
                foreach ($piazze as $x) {
                    if ((int) $x['id'] === $piazzaId) { $p = $x; break; }
                }
                $c = $citta[(int) $p['citta_id']];

                $quota = $quotaBene * ($peso / $somma);
                $domandaEq = $quota * $R / max(1.0, $spreadUnitario);
                $offertaEq = $domandaEq * $oreGiacenza;

                $mult = (float) $d['base_carattere'][(string) $c['carattere']]
                      * (float) $d['mult_tipo'][(string) $p['tipo']]
                      * (float) $d['bonus_fascia'][(string) $c['carattere']][$fascia];

                $sql = 'INSERT INTO mercati (piazza_id, bene_id, quota, mult_d, offerta_eq, domanda_eq,
                                          offerta, domanda, agg_a)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE quota = VALUES(quota), mult_d = VALUES(mult_d),
                         offerta_eq = VALUES(offerta_eq), domanda_eq = VALUES(domanda_eq)'
                     . ($conserva ? '' : ', offerta = VALUES(offerta), domanda = VALUES(domanda), agg_a = VALUES(agg_a)');
                Database::run(
                    $sql,
                    [$piazzaId, (int) $bene['id'], round($quota, 8), round($mult, 3),
                     round($offertaEq, 3), round($domandaEq, 3),
                     round($offertaEq, 3), round($domandaEq, 3), Clock::perDb()]
                );

                $teorico += $domandaEq * $spreadUnitario;
                $toccate[$piazzaId] = true;
                $nMercati++;
            }
        }

        return [
            'beni'         => count($beni),
            'mercati'      => $nMercati,
            'piazze_vuote' => count($piazze) - count($toccate),
            'teorico'      => $teorico,
        ];
    }

    /**
     * Il controllo di bilanciamento. Non sono statistiche: sono PROVE.
     *
     * @return array<string,mixed>
     */
    public static function rapporto(): array
    {
        $R = (float) GameConfig::int('mondo.reddito_orario', 24_000_000);

        // 1. Somma teorica: deve tornare il tetto. Se non torna, le quote non
        //    sommano a 1 e qualcuno ha toccato una piazza a mano.
        $teorico = 0.0;
        $perFascia = [];
        foreach (Database::all(
            'SELECT m.domanda_eq, m.quota, b.fascia, b.prezzo_min, b.prezzo_max, b.spread_frazione
               FROM mercati m JOIN beni b ON b.id = m.bene_id') as $r) {
            $su = ((int) $r['prezzo_min'] + (int) $r['prezzo_max']) / 2.0 * (float) $r['spread_frazione'];
            $v = (float) $r['domanda_eq'] * $su;
            $teorico += $v;
            $perFascia[(string) $r['fascia']] = ($perFascia[(string) $r['fascia']] ?? 0.0) + $v;
        }

        // 2. Estrazione reale: quanto i giocatori hanno davvero tirato fuori.
        //    Se supera il tetto, c'è un baco o un exploit.
        $r24 = Database::first(
            'SELECT COALESCE(SUM(margine), 0) m, COUNT(*) n FROM transazioni
              WHERE verso = \'vendita\' AND fatto_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        $reale = ((float) ($r24['m'] ?? 0)) / 24.0;

        // 2-bis. Quanti stavano davvero lavorando. Senza questo numero la riga
        //        dell'utilizzo mente: con un giocatore solo il mondo risulta
        //        sempre «troppo generoso», e non e' un difetto di taratura —
        //        e' che non c'e' nessuno che estrae. L'utilizzo si legge solo
        //        sopra una popolazione minima; sotto, quello che si guarda e'
        //        il reddito PER GIOCATORE, da confrontare con i profili del
        //        §2.6 (principiante 250 k/ora, medio 1,2 M, maturo 4,5 M).
        $attivi = (int) (Database::first(
            'SELECT COUNT(DISTINCT personaggio_id) n FROM transazioni
              WHERE verso = \'vendita\' AND fatto_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        )['n'] ?? 0);

        // 3. Assorbimento per piazza: la banda di giocabilità 3-30 unità l'ora.
        $fuoriBanda = Database::all(
            'SELECT p.nome piazza, b.nome bene, m.domanda_eq
               FROM mercati m JOIN piazze p ON p.id = m.piazza_id JOIN beni b ON b.id = m.bene_id
              WHERE (m.domanda_eq < ? OR m.domanda_eq > ?) AND b.codice <> \'armi\'
              ORDER BY m.domanda_eq',
            [GameConfig::int('mercato.banda_min', 3), GameConfig::int('mercato.banda_max', 30)]
        );

        return [
            'tetto'       => $R,
            'teorico'     => $teorico,
            'scarto'      => $R > 0 ? ($teorico - $R) / $R : 0.0,
            'per_fascia'  => $perFascia,
            'reale_ora'   => $reale,
            'vendite_24h' => (int) ($r24['n'] ?? 0),
            'utilizzo'    => $R > 0 ? $reale / $R : 0.0,
            'attivi_24h'  => $attivi,
            'per_giocatore' => $attivi > 0 ? $reale / $attivi : 0.0,
            'fuori_banda' => $fuoriBanda,
            'nodi'        => (int) (Database::first('SELECT COUNT(*) n FROM mercati')['n'] ?? 0),
        ];
    }
}
