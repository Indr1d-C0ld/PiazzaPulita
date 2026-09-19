<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Posta;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Support\Audit;

final class AdminController
{
    public function pannello(Request $request): Response
    {
        $migrazioni = [];
        try {
            $migrazioni = Database::all('SELECT version, applied_at FROM schema_migrations ORDER BY version');
        } catch (\Throwable) {
            // schema_migrations non esiste ancora: lo dira' la pagina.
        }

        return Response::html(view('admin/pannello', [
            'title'      => 'Amministrazione',
            'config'     => Config::sourceFile(),
            'db'         => Database::isReachable(),
            'migrazioni' => $migrazioni,
            'parametri'  => GameConfig::all(),
            'posta'      => self::statoPosta(),
            'utenti'     => [
                'totale'   => self::conta('SELECT COUNT(*) n FROM users'),
                'attivi'   => self::conta("SELECT COUNT(*) n FROM users WHERE status='active'"),
                'attesa'   => self::conta("SELECT COUNT(*) n FROM users WHERE status='pending'"),
                'sospesi'  => self::conta("SELECT COUNT(*) n FROM users WHERE status IN ('suspended','banned')"),
            ],
            'trasporto'  => (string) Config::get('mail.transport', 'log'),
        ]));
    }

    /** Modifica a caldo di un parametro di gioco, con riga nel registro. */
    public function config(Request $request): Response
    {
        $chiave = $request->str('chiave');
        $valore = $request->str('valore');
        $tipo   = $request->str('tipo', 'string');

        if ($chiave === '' || !in_array($tipo, ['string', 'int', 'float', 'bool', 'json'], true)) {
            Session::flash('error', 'Parametro non valido.');
            return redirect('/admin');
        }

        $prima = GameConfig::get($chiave);
        GameConfig::set($chiave, $valore, $tipo);
        Audit::log('admin.config', Auth::id(), 'config', null,
            ['chiave' => $chiave, 'prima' => $prima, 'dopo' => $valore], $request->ip());

        Session::flash('success', "Parametro «{$chiave}» aggiornato.");
        return redirect('/admin');
    }

    public function utenti(Request $request): Response
    {
        $q = $request->str('q');
        if ($q !== '') {
            $righe = Database::all(
                'SELECT id, username, email, status, role, created_at, last_login_at, last_seen_at
                   FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 200',
                ['%' . $q . '%', '%' . $q . '%']
            );
        } else {
            $righe = Database::all(
                'SELECT id, username, email, status, role, created_at, last_login_at, last_seen_at
                   FROM users ORDER BY id DESC LIMIT 200'
            );
        }

        return Response::html(view('admin/utenti', [
            'title' => 'Utenti',
            'righe' => $righe,
            'q'     => $q,
        ]));
    }

    public function utente(Request $request, string $id): Response
    {
        $u = Database::first('SELECT * FROM users WHERE id = ?', [(int) $id]);
        if ($u === null) {
            return Response::html(view('errors/generic', [
                'title' => 'Utente inesistente', 'status' => 404,
                'message' => 'Nessun utente con questo identificativo.',
            ]), 404);
        }

        return Response::html(view('admin/utente', [
            'title'    => 'Utente ' . $u['username'],
            'u'        => $u,
            'registro' => Database::all(
                'SELECT action, target_type, target_id, created_at FROM audit_log
                  WHERE actor_user_id = ? ORDER BY id DESC LIMIT 50',
                [(int) $id]
            ),
        ]));
    }

    /**
     * Azioni sull'account di un giocatore.
     *
     * L'amministratore non può toccare se stesso con le azioni distruttive:
     * chiudersi fuori dal proprio pannello è un errore che si fa una volta
     * sola, e poi si rimedia solo da riga di comando.
     */
    public function azioneUtente(Request $request): Response
    {
        $id    = $request->int('id');
        $cosa  = $request->str('azione');
        $io    = (int) Auth::id();

        $u = Database::first('SELECT id, username, status, role FROM users WHERE id = ?', [$id]);
        if ($u === null) {
            Session::flash('error', 'Utente inesistente.');
            return redirect('/admin/utenti');
        }

        $suDiMe = ($id === $io);
        $vietateSuDiMe = ['sospendi', 'revoca', 'degrada'];
        if ($suDiMe && in_array($cosa, $vietateSuDiMe, true)) {
            Session::flash('error', 'Non puoi applicare questa azione al tuo stesso account.');
            return redirect('/admin/utente/' . $id);
        }

        $esito = match ($cosa) {
            'conferma' => self::esegui(
                "UPDATE users SET status='active', email_verified_at=COALESCE(email_verified_at, NOW()) WHERE id = ?",
                $id, 'Indirizzo confermato a mano.'
            ),
            'sospendi' => self::esegui("UPDATE users SET status='suspended' WHERE id = ?", $id, 'Account sospeso.'),
            'revoca'   => self::esegui("UPDATE users SET status='banned' WHERE id = ?", $id, 'Account revocato.'),
            'riattiva' => self::esegui("UPDATE users SET status='active' WHERE id = ?", $id, 'Account riattivato.'),
            'promuovi' => self::esegui("UPDATE users SET role='admin' WHERE id = ?", $id, 'Promosso ad amministratore.'),
            'degrada'  => self::esegui("UPDATE users SET role='player' WHERE id = ?", $id, 'Riportato a giocatore.'),
            default    => null,
        };

        if ($esito === null) {
            Session::flash('error', 'Azione sconosciuta.');
            return redirect('/admin/utente/' . $id);
        }

        Audit::log('admin.utente.' . $cosa, $io, 'user', $id, ['username' => $u['username']], $request->ip());
        Session::flash('success', $esito);
        return redirect('/admin/utente/' . $id);
    }

    public function posta(Request $request): Response
    {
        return Response::html(view('admin/posta', [
            'title' => 'Posta in uscita',
            'stato' => self::statoPosta(),
            'righe' => Database::all(
                'SELECT id, destinatario, oggetto, genere, priorita, tentativi, prossimo_at,
                        inviato_at, rinunciato_at, ultimo_errore, created_at
                   FROM mail_queue ORDER BY id DESC LIMIT 100'
            ),
        ]));
    }

    public function smista(Request $request): Response
    {
        $e = Posta::smista(20);
        Audit::log('admin.posta.smista', Auth::id(), null, null, $e, $request->ip());
        Session::flash('success', "Tentati {$e['tentati']}, inviati {$e['inviati']}, rinunciati {$e['rinunciati']}.");
        return redirect('/admin/posta');
    }

    public function registro(Request $request): Response
    {
        return Response::html(view('admin/registro', [
            'title' => 'Registro delle azioni',
            'righe' => Database::all(
                'SELECT a.id, a.action, a.target_type, a.target_id, a.meta, a.created_at, u.username
                   FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id
                  ORDER BY a.id DESC LIMIT 200'
            ),
        ]));
    }

    // --- Aiutanti ------------------------------------------------------------

    private static function esegui(string $sql, int $id, string $messaggio): string
    {
        Database::run($sql, [$id]);
        return $messaggio;
    }

    private static function conta(string $sql): int
    {
        try {
            return (int) (Database::first($sql)['n'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array{in_coda:int,inviate_24h:int,rinunciate:int,tetto:int} */
    private static function statoPosta(): array
    {
        try {
            return Posta::stato();
        } catch (\Throwable) {
            return ['in_coda' => 0, 'inviate_24h' => 0, 'rinunciate' => 0, 'tetto' => 0];
        }
    }
}
