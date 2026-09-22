<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\GameConfig;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Fornitori;
use App\Game\Legge;
use App\Game\Listino;
use App\Game\Mondo;
use App\Game\Organico;
use App\Game\Personaggio;
use App\Sim\Crescita;

final class OrganicoController
{
    public function scheda(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) {
            return redirect('/inizio');
        }

        return Response::html(view('gioco/personaggio', [
            'title'    => 'Chi sei diventato',
            'p'        => $p,
            'uomini'   => Organico::uomini((int) $p['id']),
            'tetto'    => Organico::quantiNePuoi($p),
            'stipendi' => Organico::stipendiOra((int) $p['id']),
            'corse'    => Organico::corseAperte((int) $p['id']),
            'ruoli'    => Organico::RUOLI,
            'fornitori'=> Fornitori::tutti(),
            'carico'   => Listino::carico((int) $p['id']),
            'piazza'   => Mondo::piazza((int) $p['piazza_id']),
            'piazze'   => Mondo::piazze(),
            'ingaggio' => GameConfig::int('organico.ingaggio', 8),
            'inCarcere' => Legge::inCarcere($p),
            // Il prossimo che vorrà parlarti: `Fornitori::prossimo()` esisteva
            // e non lo chiamava nessuno, quindi il rispetto guadagnato non
            // aveva un traguardo visibile.
            'prossimoFornitore' => Fornitori::prossimo($p),
        ]));
    }

    public function assumi(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Organico::assumi((int) $p['id'], $request->str('ruolo'));
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? ($res['uomo']['nome'] ?? 'Qualcuno') . ' lavora per te. Competenza '
              . (int) ($res['uomo']['competenza'] ?? 0) . ', e per ora si fida.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/personaggio');
    }

    public function licenzia(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Organico::licenzia((int) $p['id'], $request->int('uomo'));
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? ($res['nome'] ?? 'Se ne') . ' è andato.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/personaggio');
    }

    public function piazza(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Organico::piazza((int) $p['id'], $request->int('uomo'), (int) $p['piazza_id']);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Adesso sta qui.' : ($res['error'] ?? 'Non si può.'));
        return redirect('/personaggio');
    }

    public function manda(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Organico::manda((int) $p['id'], $request->int('uomo'), $request->int('piazza'),
            $request->int('bene'), $request->int('quantita'));
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'È partito. Ci mette ' . \App\Sim\Viaggio::durata((int) $res['minuti'])
              . ', e la roba finisce nel deposito.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/personaggio');
    }

    /** @return array<string,mixed>|null */
    private function mio(): ?array
    {
        return Personaggio::perUtente((int) Auth::id());
    }
}
