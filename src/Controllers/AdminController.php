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
use App\Game\Mondo;
use App\Game\SeminaMercato;
use App\Sim\Clock;
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

    // --- Il mondo ---------------------------------------------------------------

    /**
     * La plancia del mondo: le piazze, quanto scottano, chi le tiene.
     *
     * Serve a vedere in una schermata quello che altrimenti si legge solo
     * interrogando il database a mano — e a rimettere a posto una piazza che
     * una prova o un baco hanno lasciato in uno stato assurdo.
     */
    public function mondo(Request $request): Response
    {
        return Response::html(view('admin/mondo', [
            'title'   => 'Il mondo',
            'piazze'  => Database::all(
                'SELECT z.id, z.nome, z.tipo, z.polizia, z.calore, c.nome citta,
                        b.sigla AS padrone,
                        (SELECT COUNT(*) FROM personaggi p WHERE p.piazza_id = z.id) AS gente,
                        (SELECT COUNT(*) FROM mercati m WHERE m.piazza_id = z.id) AS nodi
                   FROM piazze z JOIN citta c ON c.id = z.citta_id
                   LEFT JOIN territori t ON t.piazza_id = z.id
                   LEFT JOIN batterie b ON b.id = t.batteria_id
                  ORDER BY c.nome, z.nome'),
            'bilancio' => SeminaMercato::rapporto(),
            'citta'    => Mondo::citta(),
        ]));
    }

    /**
     * Le azioni sul mondo. Tutte POST, tutte nel registro.
     *
     * Non c'è niente che crei denaro: un amministratore che regala contanti in
     * un mondo a reddito orario finito li toglie a tutti gli altri senza che
     * nessuno se ne accorga. Qui si ripara, non si premia.
     */
    public function azioneMondo(Request $request): Response
    {
        $cosa = $request->str('azione');
        $id   = $request->int('piazza');

        $esito = match ($cosa) {
            'raffredda' => (static function () use ($id): string {
                Database::run('UPDATE piazze SET calore = 0, calore_agg_a = NULL WHERE id = ?', [$id]);
                return 'Piazza raffreddata.';
            })(),
            'raffredda_tutto' => (static function (): string {
                $n = Database::run('UPDATE piazze SET calore = 0, calore_agg_a = NULL WHERE calore > 0')->rowCount();
                return $n . ' piazze raffreddate.';
            })(),
            'equilibrio' => (static function () use ($id): string {
                Database::run(
                    'UPDATE mercati SET offerta = offerta_eq, domanda = domanda_eq, shock = 0, agg_a = ?
                      WHERE piazza_id = ?', [Clock::perDb(), $id]);
                return 'Mercato della piazza riportato all\'equilibrio.';
            })(),
            default => null,
        };

        if ($esito === null) {
            Session::flash('error', 'Azione sconosciuta.');
            return redirect('/admin/mondo');
        }

        Audit::log('admin.mondo.' . $cosa, Auth::id(), 'piazza', $id ?: null, [], $request->ip());
        Session::flash('success', $esito);
        return redirect('/admin/mondo');
    }

    // --- I giocatori -------------------------------------------------------------

    public function giocatori(Request $request): Response
    {
        return Response::html(view('admin/giocatori', [
            'title'  => 'I giocatori',
            'righe'  => Database::all(
                "SELECT p.*, u.username, u.status, z.nome AS piazza, c.nome AS citta, b.sigla AS batteria,
                        (SELECT COUNT(*) FROM fascicoli f WHERE f.personaggio_id = p.id AND f.stato = 'aperto') AS fascicoli
                   FROM personaggi p
                   JOIN users u ON u.id = p.user_id
                   JOIN piazze z ON z.id = p.piazza_id
                   JOIN citta c ON c.id = z.citta_id
                   LEFT JOIN batterie b ON b.id = p.batteria_id
                  ORDER BY p.pulito DESC LIMIT 200"),
        ]));
    }

    /**
     * Le azioni su un personaggio.
     *
     * Stessa regola del mondo: si rimette in piedi chi è rimasto incastrato,
     * non si regala niente. La cancellazione del personaggio c'è perché a
     * volte un giocatore vuole ricominciare, ed è l'unica cosa qui dentro che
     * distrugge davvero qualcosa: chiede conferma dal modulo.
     */
    public function azioneGiocatore(Request $request): Response
    {
        $id   = $request->int('id');
        $cosa = $request->str('azione');

        $p = Database::first(
            'SELECT p.id, u.username FROM personaggi p JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$id]);
        if ($p === null) {
            Session::flash('error', 'Personaggio inesistente.');
            return redirect('/admin/giocatori');
        }

        $esito = match ($cosa) {
            'libera' => (static function () use ($id): string {
                Database::run('UPDATE personaggi SET carcere_fino_a = NULL WHERE id = ?', [$id]);
                return 'Uscito dal carcere.';
            })(),
            'dimetti' => (static function () use ($id): string {
                Database::run('UPDATE personaggi SET ospedale_fino_a = NULL, salute = 100 WHERE id = ?', [$id]);
                return 'Dimesso dall\'ospedale.';
            })(),
            'raffredda' => (static function () use ($id): string {
                Database::run('UPDATE personaggi SET calore = 0, calore_agg_a = NULL WHERE id = ?', [$id]);
                return 'Calore azzerato.';
            })(),
            'archivia' => (static function () use ($id): string {
                $n = Database::run(
                    "UPDATE fascicoli SET stato = 'archiviato', chiuso_at = NOW()
                      WHERE personaggio_id = ? AND stato = 'aperto'", [$id])->rowCount();
                return $n . ' fascicoli archiviati.';
            })(),
            'cancella' => (static function () use ($id, $request): ?string {
                if ($request->str('conferma') !== 'cancella') {
                    return null;
                }
                Database::run('DELETE FROM personaggi WHERE id = ?', [$id]);
                return 'Personaggio cancellato: il giocatore ricomincia dalla scelta della città.';
            })(),
            default => null,
        };

        if ($esito === null) {
            Session::flash('error', $cosa === 'cancella'
                ? 'Per cancellare bisogna scrivere «cancella» nella casella di conferma.'
                : 'Azione sconosciuta.');
            return redirect('/admin/giocatori');
        }

        Audit::log('admin.giocatore.' . $cosa, Auth::id(), 'personaggio', $id,
            ['username' => $p['username']], $request->ip());
        Session::flash('success', $esito);
        return redirect('/admin/giocatori');
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
