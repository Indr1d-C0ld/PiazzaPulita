<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Batteria;
use App\Game\Carta;
use App\Game\Contabilita;
use App\Game\Legge;
use App\Game\Listino;
use App\Game\Logistica;
use App\Game\Mondo;
use App\Game\Personaggio;
use App\Game\Rivalita;
use App\Sim\Geo;

final class MondoController
{
    /** Il veicolo del giocatore, letto una volta e passato a chi calcola i viaggi. */
    private ?array $mezzo = null;

    /**
     * La strada: dove sei adesso.
     *
     * È il fulcro del gioco — da F2 in poi qui sotto comparirà il listino. In
     * Da F2 c'è il listino, da F3 il deposito.
     */
    public function strada(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) {
            return redirect('/inizio');
        }

        Database::run('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [(int) Auth::id()]);

        // Da dentro non c'è strada: si vede il fascicolo e si aspetta.
        if (Legge::inCarcere($p)) {
            return redirect('/fascicolo');
        }
        // Dall'ospedale nemmeno, e la pagina che lo spiega è quella degli altri.
        if (Rivalita::inOspedale($p)) {
            return redirect('/altri');
        }

        $stato = Personaggio::stato($p);
        $qui   = (int) $p['piazza_id'];

        $this->mezzo = Personaggio::mezzoProprio($p);
        $carico   = Listino::carico((int) $p['id']);
        $ingombro = Listino::ingombroUsato((int) $p['id']);
        $deposito = $stato['in_viaggio'] ? null : Logistica::deposito((int) $p['id'], $qui);

        return Response::html(view('gioco/strada', [
            'title'   => $stato['in_viaggio']
                ? 'In viaggio verso ' . ($stato['piazza']['nome'] ?? '?')
                : (string) ($stato['piazza']['nome'] ?? 'La strada'),
            'stato'   => $stato,
            'vicine'  => $stato['in_viaggio'] ? [] : $this->vicine($qui),
            'altri'   => $stato['in_viaggio'] ? [] : Personaggio::altriQui($qui, (int) $p['id']),
            'padrone' => $stato['in_viaggio'] ? null : Batteria::padrone($qui),
            'listino' => $stato['in_viaggio'] ? [] : Listino::perPiazza($qui, $p),
            'carico'  => $carico,
            'ingombro'=> $ingombro,
            'capienza'=> Logistica::capienza($p),
            'deposito'=> $deposito,
            'inDeposito' => $deposito === null ? [] : Logistica::merce((int) $deposito['id']),
            'affitto' => \App\Core\GameConfig::int('deposito.affitto_ora', 12000)
                         * max(1, \App\Core\GameConfig::int('deposito.anticipo_ore', 24)),
            'conti'   => Contabilita::stato($p),
            'calore'  => Legge::calorePersonale($p),
        ]));
    }

    /** Scelta della città di partenza. */
    public function inizio(Request $request): Response
    {
        if (Personaggio::perUtente((int) Auth::id(), false) !== null) {
            return redirect('/strada');
        }
        if (!Mondo::esiste()) {
            return Response::html(view('errors/generic', [
                'title'   => 'Il mondo non c\'è ancora',
                'status'  => 503,
                'message' => 'La geografia non è stata caricata. Se sei l\'amministratore: '
                           . 'php bin/console.php mondo:semina',
            ]), 503);
        }

        return Response::html(view('gioco/inizio', [
            'title'  => 'Da dove cominci',
            'citta'  => Mondo::citta(),
            'piazze' => Mondo::piazze(),
        ]));
    }

    public function nasci(Request $request): Response
    {
        $res = Personaggio::crea((int) Auth::id(), $request->int('citta'), $request->ip());
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Non è andata.');
            return redirect('/inizio');
        }
        $piazza = Mondo::piazza((int) $res['personaggio']['piazza_id']);
        Session::flash('success', 'Sei sceso a ' . ($piazza['nome'] ?? '?') . '. Da qui si comincia.');
        return redirect('/strada');
    }

    /** La mappa: la rete delle città e, dentro, le piazze. */
    public function mappa(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) {
            return redirect('/inizio');
        }

        if (Legge::inCarcere($p)) {
            return redirect('/fascicolo');
        }

        $stato = Personaggio::stato($p);
        $qui   = (int) $p['piazza_id'];
        $this->mezzo = Personaggio::mezzoProprio($p);

        // La proiezione la fa il server: la vista deve disegnare dei punti,
        // non sapere di geodesia.
        $cittaQui = (int) (Mondo::piazza($qui)['citta_id'] ?? 0);
        $destinazioni = $stato['in_viaggio'] ? [] : $this->destinazioni($qui);

        // Dalla carta si va alla riga del viaggio: cliccare una città porta
        // dove si decide come arrivarci, che è l'unica cosa che si può fare.
        $collegamenti = [];
        foreach ($destinazioni as $d) {
            $collegamenti[(int) $d['citta']['id']] = '#citta-' . (int) $d['citta']['id'];
        }

        return Response::html(view('gioco/mappa', [
            'title'      => 'La carta',
            'stato'      => $stato,
            'carta'      => Carta::disegno($stato['in_viaggio'] ? null : $cittaQui,
                                           Carta::genteVisibile($p)),
            'collegamenti' => $collegamenti,
            'citta'      => Mondo::citta(),
            'qui'        => $qui,
            'destinazioni' => $destinazioni,
        ]));
    }

    public function parti(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) {
            return redirect('/inizio');
        }

        $res = Personaggio::parti(
            (int) $p['id'],
            $request->int('piazza'),
            $request->str('mezzo'),
            $request->ip()
        );

        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Non si parte.');
            return redirect(rotta_da_uri($request->str('torna'), '/strada'));
        }

        $dove = Mondo::piazza($request->int('piazza'));
        Session::flash('success', sprintf(
            'In viaggio verso %s. Arrivo fra %s, biglietto %s.',
            $dove['nome'] ?? '?',
            \App\Sim\Viaggio::durata((int) $res['minuti']),
            lire((int) $res['costo'])
        ));
        if (!empty($res['blocco'])) {
            Session::flash('error', (string) $res['blocco']);
        }
        return redirect('/strada');
    }

    /** Polling del conto alla rovescia: leggero, niente layout, niente viste. */
    public function stato(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) {
            return Response::json(['ok' => false], 404);
        }
        $s = Personaggio::stato($p);
        return Response::json([
            'ok'          => true,
            'in_viaggio'  => $s['in_viaggio'],
            'mancano_sec' => $s['mancano_sec'],
            'piazza'      => $s['piazza']['nome'] ?? null,
            'contante'    => $s['contante'],
        ]);
    }

    // --- Aiutanti ------------------------------------------------------------

    /**
     * Le piazze della stessa città, ordinate per vicinanza, con l'opzione di
     * viaggio più economica e la più veloce. Sono le destinazioni che si usano
     * cento volte al giorno: devono stare a un clic.
     *
     * @return list<array<string,mixed>>
     */
    private function vicine(int $daId): array
    {
        $qui = Mondo::piazza($daId);
        if ($qui === null) {
            return [];
        }

        $fuori = [];
        foreach (Mondo::piazzeDi($qui['citta_id']) as $p) {
            if ((int) $p['id'] === $daId) {
                continue;
            }
            $opz = Mondo::opzioniFra($daId, (int) $p['id'], $this->mezzo);
            if ($opz === []) {
                continue;
            }
            $fuori[] = ['piazza' => $p, 'km' => $opz[0]['km'], 'opzioni' => $opz];
        }

        usort($fuori, static fn($a, $b) => $a['km'] <=> $b['km']);
        return $fuori;
    }

    /**
     * Le altre città, con la piazza più vicina di ciascuna come punto di
     * sbarco e le opzioni per arrivarci.
     *
     * @return list<array<string,mixed>>
     */
    private function destinazioni(int $daId): array
    {
        $qui = Mondo::piazza($daId);
        if ($qui === null) {
            return [];
        }

        $fuori = [];
        foreach (Mondo::citta() as $cittaId => $c) {
            if ($cittaId === $qui['citta_id']) {
                continue;
            }
            // Si sbarca dove si sbarca: la stazione se c'è, altrimenti la
            // piazza più vicina. Da lì ci si sposta dentro la città.
            $piazze = Mondo::piazzeDi($cittaId);
            usort($piazze, static function ($a, $b) use ($daId) {
                $sa = $a['tipo'] === 'stazione' ? 0 : 1;
                $sb = $b['tipo'] === 'stazione' ? 0 : 1;
                return $sa <=> $sb ?: Mondo::kmFra($daId, (int) $a['id']) <=> Mondo::kmFra($daId, (int) $b['id']);
            });
            $sbarco = $piazze[0];
            $opz = Mondo::opzioniFra($daId, (int) $sbarco['id'], $this->mezzo);
            if ($opz === []) {
                continue;
            }
            $fuori[] = ['citta' => $c, 'sbarco' => $sbarco, 'km' => $opz[0]['km'], 'opzioni' => $opz];
        }

        usort($fuori, static fn($a, $b) => $a['km'] <=> $b['km']);
        return $fuori;
    }
}
