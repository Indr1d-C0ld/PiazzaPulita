<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Contabilita;
use App\Game\Listino;
use App\Game\Logistica;
use App\Game\Mondo;
use App\Game\Personaggio;

/**
 * Gli affari: le due casse, l'usuraio, i canali, i mezzi, i depositi.
 *
 * Sta tutto in una pagina sola perché è tutto la stessa decisione: quanto del
 * denaro che hai fatto riesci davvero a tenere, e in che cosa lo trasformi.
 */
final class AffariController
{
    public function contabilita(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id());
        if ($p === null) {
            return redirect('/inizio');
        }

        // Prima di mostrare i numeri si porta avanti quello che matura da solo:
        // altrimenti la pagina direbbe una cosa e l'azione successiva ne farebbe
        // un'altra, e il giocatore avrebbe ragione a non fidarsi.
        Contabilita::avanza((int) $p['id']);
        $p = Personaggio::perUtente((int) Auth::id());

        return Response::html(view('gioco/affari', [
            'title'      => 'Gli affari',
            'p'          => $p,
            'conti'      => Contabilita::stato($p),
            'catalogo'   => Contabilita::catalogo(),
            'mezzi'      => Logistica::mezzi(),
            'mezzoMio'   => Personaggio::mezzoProprio($p),
            'rivendita'  => Logistica::rivendita($p),
            'capienza'   => Logistica::capienza($p),
            'usato'      => Listino::ingombroUsato((int) $p['id']),
            'depositi'   => Logistica::depositi((int) $p['id']),
            'movimenti'  => Contabilita::movimenti((int) $p['id'], 40),
        ]));
    }

    public function lava(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $importo = $request->str('tutto') === '1' ? (int) $p['contante'] : $request->int('importo');
        $res = Contabilita::metti((int) $p['id'], $request->str('canale'), $importo);

        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? lire((int) $res['importo']) . ' messi a lavare: escono puliti col tempo, non subito.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/affari');
    }

    public function compraCanale(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Contabilita::compraCanale((int) $p['id'], $request->str('canale'));
        $nome = Contabilita::catalogo()[$request->str('canale')]['nome'] ?? 'il canale';
        Session::flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? $nome . ': è tuo. Adesso ne lavi di più, e più in fretta.' : ($res['error'] ?? 'Non si può.'));
        return redirect('/affari');
    }

    public function compraMezzo(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Logistica::compraMezzo((int) $p['id'], $request->str('mezzo'));
        $nome = Logistica::mezzi()[$request->str('mezzo')]['nome'] ?? 'il mezzo';
        Session::flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? $nome . ': adesso ci porti molto di più, e ci vai da solo.' : ($res['error'] ?? 'Non si può.'));
        return redirect('/affari');
    }

    public function prestito(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Contabilita::prestito((int) $p['id'], $request->int('importo'));
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Ti conta ' . lire((int) $res['importo']) . ' senza guardarti in faccia. Il '
              . percento(Contabilita::tassoEffettivo($p)) . ' al giorno corre da adesso.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/affari');
    }

    public function restituisci(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $importo = $request->str('tutto') === '1' ? (int) $p['contante'] : $request->int('importo');
        $res = Contabilita::restituisci((int) $p['id'], $importo);
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? (!empty($res['saldato'])
                ? 'Saldato. Ti dà una pacca sulla spalla che non è affetto.'
                : lire((int) $res['importo']) . ' dati; il resto corre ancora.')
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/affari');
    }

    // --- Depositi ------------------------------------------------------------

    public function apriDeposito(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $res = Logistica::apri((int) $p['id'], (int) $p['piazza_id']);
        $dove = Mondo::piazza((int) $p['piazza_id'])['nome'] ?? '?';
        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Un posto a ' . $dove . '. Anticipo di ' . lire((int) $res['anticipo']) . ', e la chiave è tua.'
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/strada');
    }

    public function spostaMerce(Request $request): Response
    {
        $p = $this->mio();
        if ($p === null) { return redirect('/inizio'); }

        $verso = $request->str('verso') === 'deposito';
        $res = Logistica::sposta((int) $p['id'], $request->int('bene'), $request->int('quantita'), $verso);

        Session::flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? quantita((int) $res['quantita']) . ($verso ? ' nel deposito.' : ' addosso.')
            : ($res['error'] ?? 'Non si può.'));
        return redirect('/strada');
    }

    /** @return array<string,mixed>|null */
    private function mio(): ?array
    {
        return Personaggio::perUtente((int) Auth::id());
    }
}
