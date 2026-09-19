<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Game\Avatar;
use App\Support\Audit;

final class ProfiloController
{
    /** Massima lunghezza della nota personale. */
    private const NOTA_MAX = 500;

    public function mio(Request $request): Response
    {
        $u = Auth::user();
        return Response::html(view('profilo/mio', [
            'title'   => 'Il tuo profilo',
            'utente'  => $u,
            'notaMax' => self::NOTA_MAX,
            'avatar'  => Avatar::url($u['avatar_file'] ?? null),
            'lato'    => Avatar::LATO,
        ]));
    }

    /**
     * Carica la fotografia del profilo.
     *
     * Il ritaglio arriva dal riquadro come rettangolo in pixel dell'immagine
     * originale. Non ci si fida: Avatar lo riporta dentro i bordi, e se è
     * assurdo lo ricalcola centrato.
     */
    public function caricaAvatar(Request $request): Response
    {
        $res = Avatar::carica(
            (int) Auth::id(),
            $request->file('foto') ?? [],
            $request->int('sx'),
            $request->int('sy'),
            $request->int('lato'),
            (string) ($GLOBALS['__project_root'] ?? '')
        );

        if ($res['ok']) {
            Audit::log('profilo.avatar', Auth::id(), 'user', Auth::id(), [], $request->ip());
            Session::flash('success', 'Fotografia sul profilo.');
        } else {
            Session::flash('error', $res['error'] ?? 'Caricamento non riuscito.');
        }
        return redirect('/profilo');
    }

    public function togliAvatar(Request $request): Response
    {
        Avatar::togli((int) Auth::id(), (string) ($GLOBALS['__project_root'] ?? ''));
        Audit::log('profilo.avatar_tolto', Auth::id(), 'user', Auth::id(), [], $request->ip());
        Session::flash('success', 'Fotografia tolta.');
        return redirect('/profilo');
    }

    /**
     * Profilo pubblico di un altro giocatore.
     *
     * Visibile a chi ha un account attivo, non al mondo: in questo gioco sapere
     * chi c'è in giro è già informazione, e l'informazione si paga.
     */
    public function mostra(Request $request, string $id): Response
    {
        $riga = Database::first(
            "SELECT id, username, nota, created_at, last_seen_at, role, avatar_file
               FROM users WHERE id = ? AND status = 'active'",
            [(int) $id]
        );
        if ($riga === null) {
            return Response::html(view('errors/generic', [
                'title'   => 'Nessuno con questo nome',
                'status'  => 404,
                'message' => 'Questo profilo non esiste, o non e\' piu\' in circolazione.',
            ]), 404);
        }

        return Response::html(view('profilo/pubblico', [
            'title'  => $riga['username'],
            'p'      => $riga,
            'mio'    => Auth::id() === (int) $riga['id'],
            'avatar' => Avatar::url($riga['avatar_file'] ?? null),
        ]));
    }

    public function nota(Request $request): Response
    {
        $nota = mb_substr($request->str('nota'), 0, self::NOTA_MAX);
        $id   = (int) Auth::id();

        Database::run('UPDATE users SET nota = ? WHERE id = ?', [$nota === '' ? null : $nota, $id]);
        Audit::log('profilo.nota', $id, 'user', $id, [], $request->ip());

        Session::flash('success', 'Profilo aggiornato.');
        return redirect('/profilo');
    }

    /**
     * Luce della pagina: carta (giorno) o notte.
     *
     * Sta sull'utente e non in un cookie perché deve seguirlo fra i
     * dispositivi: chi gioca di notte dal telefono e di giorno dal lavoro non
     * deve reimpostarla ogni volta. Accetta anche utenti non ancora
     * confermati — è una preferenza di lettura, non un privilegio.
     */
    public function luce(Request $request): Response
    {
        $luce = $request->str('luce');
        if (!in_array($luce, ['carta', 'notte', 'auto'], true)) {
            $luce = 'auto';
        }
        Database::run('UPDATE users SET luce = ? WHERE id = ?', [$luce, (int) Auth::id()]);

        return redirect(rotta_da_uri($request->str('torna'), '/profilo'));
    }
}
