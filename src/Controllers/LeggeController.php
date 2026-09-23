<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Legge;
use App\Game\Mondo;
use App\Game\Personaggio;
use App\Sim\Calore;

final class LeggeController
{
    public function fascicolo(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) {
            return redirect('/inizio');
        }

        $calore = Legge::calorePersonale($p);
        $piazza = Mondo::piazza((int) $p['piazza_id']);
        $mezzo  = Personaggio::mezzoProprio($p);
        // La riga fresca della piazza, col calore scritto: `Mondo::piazza()`
        // viene da una copia in memoria che il calore non lo segue.
        $caldo  = Database::first('SELECT * FROM piazze WHERE id = ?', [(int) $p['piazza_id']]);

        return Response::html(view('gioco/fascicolo', [
            'title'     => 'Il fascicolo',
            'p'         => $p,
            'calore'    => $calore,
            'caloreParole' => Calore::aParole($calore),
            'piazza'    => $piazza,
            'calorePiazza' => $caldo === null ? 0.0 : Legge::calorePiazza($caldo),
            'rischio'   => $caldo === null ? 0.0 : Legge::rischioControllo($p, $caldo),
            'rischioBlocco' => $mezzo === null ? 0.0 : Legge::rischioBlocco($p, $mezzo),
            'fascicolo' => Legge::fascicolo((int) $p['id']),
            'segnali'   => Legge::segnali((int) $p['id'], 25),
            'inCarcere' => Legge::inCarcere($p),
            'mancano'   => Legge::mancanoAlleggerimento($p),
            'prezzi'    => [
                'avvocato'   => GameConfig::int('legge.avvocato_prezzo', 4_000_000),
                'bustarella' => GameConfig::int('legge.bustarella_prezzo', 2_500_000),
            ],
            'soglia'    => GameConfig::int('legge.prove_blitz', 100),
        ]));
    }

    public function avvocato(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) { return redirect('/inizio'); }

        $res = Legge::avvocato((int) $p['id']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Il tuo avvocato ha lavorato. Restano ' . number_format((float) $res['prove'], 0, ',', '.')
              . ' prove su quel tavolo.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/fascicolo');
    }

    public function bustarella(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) { return redirect('/inizio'); }

        $res = Legge::bustarella((int) $p['id']);
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Non si può.');
        } elseif (!empty($res['andata'])) {
            Session::flash('success', 'Qualche foglio non è mai stato protocollato.');
        } else {
            Session::flash('error', 'Ha preso la busta e l\'ha messa agli atti. Quella busta adesso è una prova.');
        }
        return redirect('/fascicolo');
    }
}
