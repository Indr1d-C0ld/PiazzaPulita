<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\GameConfig;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Avatar;
use App\Game\Baratto;
use App\Game\Batteria;
use App\Game\Chiacchiera;
use App\Game\Cronaca;
use App\Game\Listino;
use App\Game\Legge;
use App\Game\Mondo;
use App\Game\Organico;
use App\Game\Personaggio;
use App\Game\Rivalita;

final class RivaliController
{
    // --- La cronaca -------------------------------------------------------------

    public function cronaca(Request $request): Response
    {
        return Response::html(view('gioco/cronaca', [
            'title'  => 'La cronaca',
            'righe'  => Cronaca::ultime(80),
        ]));
    }

    // --- Chi c'è, e cosa si può fargli -------------------------------------------

    public function altri(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) { return redirect('/inizio'); }
        if (Legge::inCarcere($p)) { return redirect('/fascicolo'); }

        $qui = (int) $p['piazza_id'];
        $spiati = [];
        foreach (Rivalita::quiConMe((int) $p['id'], $qui) as $a) {
            if ((int) $a['spiato'] > 0) {
                $spiati[(int) $a['id']] = Rivalita::rapporto((int) $p['id'], (int) $a['id']);
            }
        }

        // La foto di chi è qui: la vetrina serve a poco se non si vede in faccia
        // nessuno.
        $presenti = Rivalita::quiConMe((int) $p['id'], $qui);
        foreach ($presenti as &$uno) {
            $uno['avatar'] = Avatar::url($uno['avatar_file'] ?? null);
        }
        unset($uno);

        return Response::html(view('gioco/altri', [
            'title'      => 'Chi c\'è',
            'p'          => $p,
            'presenti'   => $presenti,
            'voci'       => Chiacchiera::inPiazza($qui),
            'baratti'    => Baratto::aperti((int) $p['id']),
            'mioCarico'  => Listino::carico((int) $p['id']),
            'beni'       => Listino::beni(),
            'lunghezzaVoce' => Chiacchiera::LUNGHEZZA_MAX,
            'piazza'     => Mondo::piazza($qui),
            'padrone'    => Batteria::padrone($qui),
            'altri'      => $presenti,
            'spiati'     => $spiati,
            'corse'      => Rivalita::corseQui((int) $p['id'], $qui),
            'uomini'     => array_values(array_filter(Organico::uomini((int) $p['id']),
                                static fn($u) => (string) $u['stato'] === 'libero')),
            'inOspedale' => Rivalita::inOspedale($p),
            'mancano'    => Rivalita::mancaAlDimissione($p),
            'prezzi'     => [
                'spia'     => GameConfig::int('pvp.spia_prezzo', 3_000_000),
                'soffiata' => GameConfig::int('pvp.soffiata_prezzo', 2_000_000),
            ],
        ]));
    }

    // --- Parlare e barattare ------------------------------------------------------

    public function parla(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }
        if (Legge::inCarcere($p) || Rivalita::inOspedale($p)) {
            Session::flash('error', 'Da dove sei adesso non ti sente nessuno.');
            return redirect('/altri');
        }

        $res = Chiacchiera::di((int) $p['id'], (int) $p['piazza_id'], $request->str('testo'));
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Non si può.');
        }
        return redirect('/altri');
    }

    public function proponiBaratto(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Baratto::proponi(
            (int) $p['id'], $request->int('chi'),
            $request->int('bene_dato'), $request->int('quanto_dato'),
            $request->int('bene_chiesto'), $request->int('quanto_chiesto')
        );
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Proposta fatta. Adesso tocca a lui.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/altri');
    }

    public function rispondiBaratto(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $id = $request->int('baratto');
        $cosa = $request->str('risposta');
        $res = match ($cosa) {
            'accetta' => Baratto::accetta((int) $p['id'], $id),
            'ritira'  => Baratto::rifiuta((int) $p['id'], $id, true),
            default   => Baratto::rifiuta((int) $p['id'], $id),
        };
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? match ($cosa) {
                'accetta' => 'Scambio fatto: guardati il carico.',
                'ritira'  => 'Proposta ritirata.',
                default   => 'Hai detto di no.',
              }
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/altri');
    }

    public function attacca(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Rivalita::attacca((int) $p['id'], $request->int('chi'));
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Non si può.');
        } else {
            Session::flash(match ($res['esito']) {
                'vinto' => 'success',
                'perso' => 'error',
                default => 'warning',
            }, $res['racconto'] ?? 'È successo qualcosa.');
        }
        return redirect('/altri');
    }

    public function soffiata(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Rivalita::soffiata((int) $p['id'], $request->int('chi'));
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Non si può.');
        } elseif (!empty($res['ritorta'])) {
            Session::flash('error', 'La telefonata l\'hanno registrata. Adesso è una prova contro di te.');
        } else {
            Session::flash('success', 'Hai fatto una telefonata. Fra qualche ora qualcuno busserà a casa d\'altri.');
        }
        return redirect('/altri');
    }

    public function infiltra(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Rivalita::infiltra((int) $p['id'], $request->int('chi'), $request->int('uomo'));
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? ($res['nome'] ?? 'Il tuo uomo') . ' è entrato in casa d\'altri. Da adesso vedi quello che vede lui.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/altri');
    }

    public function rapina(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Rivalita::rapina((int) $p['id'], $request->int('corsa'));
        if (!$res['ok']) {
            Session::flash('error', $res['error'] ?? 'Non si può.');
        } elseif ((int) $res['unita'] > 0) {
            Session::flash('success', 'Preso il carico: ' . quantita((int) $res['unita']) . ' unità.');
        } else {
            Session::flash('warning', 'Non è andata: il corriere ti ha visto arrivare.');
        }
        return redirect('/altri');
    }

    // --- Le batterie ---------------------------------------------------------------

    public function batteria(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) { return redirect('/inizio'); }

        $mia = Batteria::di($p['batteria_id'] === null ? null : (int) $p['batteria_id']);

        return Response::html(view('gioco/batteria', [
            'title'      => $mia === null ? 'Le batterie' : (string) $mia['nome'],
            'p'          => $p,
            'mia'        => $mia,
            'membri'     => $mia === null ? [] : Batteria::membri((int) $mia['id']),
            'territori'  => $mia === null ? [] : Batteria::territoriDi((int) $mia['id']),
            'elenco'     => Batteria::elenco(),
            'sonoCapo'   => $mia !== null && (int) $mia['capo_id'] === (int) $p['id'],
            'fondazione' => GameConfig::int('batteria.fondazione', 10_000_000),
            'pizzo'      => (float) GameConfig::get('batteria.pizzo', 0.04),
        ]));
    }

    public function fonda(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Batteria::fonda((int) $p['id'], $request->str('nome'), $request->str('sigla'), $request->str('motto'));
        Session::flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'La batteria è in piedi. Adesso serve gente, e serve territorio.' : ($res['error'] ?? 'Non si può.'));
        return redirect('/batteria');
    }

    public function entra(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Batteria::entra((int) $p['id'], $request->int('batteria'));
        Session::flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Sei dentro.' : ($res['error'] ?? 'Non si può.'));
        return redirect('/batteria');
    }

    public function esci(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Batteria::esci((int) $p['id']);
        Session::flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Sei fuori.' : ($res['error'] ?? 'Non si può.'));
        return redirect('/batteria');
    }

    public function cassa(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = $request->str('verso') === 'preleva'
            ? Batteria::preleva((int) $p['id'], $request->int('importo'))
            : Batteria::versa((int) $p['id'], $request->int('importo'));

        Session::flash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Fatto.' : ($res['error'] ?? 'Non si può.'));
        return redirect('/batteria');
    }

    /** @return array<string,mixed>|null */
    private function mio(): ?array
    {
        return Personaggio::perUtente((int) Auth::id());
    }
}
