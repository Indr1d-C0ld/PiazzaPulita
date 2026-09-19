<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Game\Classifica;
use App\Game\Obiettivi;
use App\Game\Personaggio;
use App\Game\Statistiche;

final class HomeController
{
    public function index(Request $request): Response
    {
        if (Auth::check() && Auth::status() === 'active') {
            return redirect('/strada');
        }

        return Response::html(view('home', [
            'title'    => 'Piazza Pulita',
            'iscritti' => self::conta("SELECT COUNT(*) n FROM users WHERE status = 'active'"),
        ]));
    }

    public function regole(Request $request): Response
    {
        return Response::html(view('regole', ['title' => 'Come funziona']));
    }

    /**
     * Sonda di servizio. Deve restare leggerissima e non toccare la sessione:
     * la interroga il controllo esterno, non un giocatore.
     */
    public function health(Request $request): Response
    {
        $db = Database::isReachable();
        return Response::json([
            'ok'       => $db,
            'servizio' => 'piazzapulita',
            'db'       => $db ? 'ok' : 'ko',
            'ora'      => date('c'),
        ], $db ? 200 : 503);
    }

    /**
     * Il manifesto dell'applicazione installabile.
     *
     * Servito da una rotta e non da un file statico per un motivo solo: dentro
     * ci vanno `start_url` e `scope`, che dipendono dal sottopercorso del
     * deploy. Un file scritto a mano funzionerebbe soltanto sulla nostra
     * installazione.
     */
    public function manifesto(Request $request): Response
    {
        $r = Response::json([
            'name'             => 'Piazza Pulita',
            'short_name'       => 'Piazza Pulita',
            'description'      => 'Commercio, rischio e territorio nell\'Italia degli anni ottanta.',
            'lang'             => 'it',
            'start_url'        => url('/strada'),
            'scope'            => url('/'),
            'display'          => 'standalone',
            'orientation'      => 'portrait-primary',
            'background_color' => '#efe9db',
            'theme_color'      => '#1c1a16',
            'icons'            => [
                ['src' => asset('img/icona-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('img/icona-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('img/icona-maskable.png'), 'sizes' => '512x512',
                 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('img/icona.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
            'shortcuts' => [
                ['name' => 'La strada', 'url' => url('/strada')],
                ['name' => 'La cronaca', 'url' => url('/cronaca')],
                ['name' => 'Classifica', 'url' => url('/classifica')],
            ],
        ]);
        return $r->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')
                 ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Le quattro graduatorie.
     *
     * Una sola, in un mondo che non riparte, premierebbe solo chi è arrivato
     * primo (DESIGN §7.1). Quella preselezionata è il **reddito**, non il
     * patrimonio: è l'unica contendibile da chiunque, sempre.
     */
    public function classifica(Request $request): Response
    {
        $g = $request->str('g', 'reddito');
        if (!isset(Classifica::GRADUATORIE[$g])) {
            $g = 'reddito';
        }

        return Response::html(view('classifica', [
            'title'        => 'Classifica · ' . Classifica::GRADUATORIE[$g]['nome'],
            'graduatoria'  => $g,
            'graduatorie'  => Classifica::GRADUATORIE,
            'righe'        => Classifica::righe($g),
            'primati'      => Classifica::primatiCorrenti(),
            'giorni'       => \App\Core\GameConfig::int('classifica.reddito_giorni', 30),
        ]));
    }

    /** L'albo d'oro: i primati chiusi che sono durati abbastanza. */
    public function albo(Request $request): Response
    {
        return Response::html(view('albo', [
            'title'       => 'Albo d\'oro',
            'graduatorie' => Classifica::GRADUATORIE,
            'righe'       => Classifica::albo(),
            'primati'     => Classifica::primatiCorrenti(),
            'minimi'      => \App\Core\GameConfig::int('albo.giorni_minimi', 30),
        ]));
    }

    public function statistiche(Request $request): Response
    {
        $mio = null;
        if (Auth::check() && Auth::status() === 'active') {
            $p = Personaggio::perUtente((int) Auth::id(), false);
            if ($p !== null) {
                $mio = Statistiche::personali((int) $p['id']);
            }
        }

        return Response::html(view('statistiche', [
            'title' => 'Statistiche',
            'm'     => Statistiche::mondo(),
            'mio'   => $mio,
            'primo' => self::primoIscritto(),
        ]));
    }

    /**
     * Gli obiettivi.
     *
     * La verifica si fa qui, aprendo la pagina: costa una manciata di
     * aggregati, e chiederli a ogni richiesta di ogni pagina sarebbe sproposito
     * per una cosa che cambia due volte al giorno. Il battito la rifà per chi è
     * stato visto di recente, così non è necessario passare di qui per
     * sbloccarli.
     */
    public function obiettivi(Request $request): Response
    {
        $p = Personaggio::perUtente((int) Auth::id(), false);
        if ($p === null) {
            return redirect('/inizio');
        }

        $nuovi = Obiettivi::verifica((int) $p['id']);

        return Response::html(view('obiettivi', [
            'title'      => 'Obiettivi',
            'catalogo'   => Obiettivi::catalogo(),
            'sbloccati'  => Obiettivi::sbloccati((int) $p['id']),
            'diffusione' => Obiettivi::diffusione(),
            'giocatori'  => self::conta('SELECT COUNT(*) n FROM personaggi'),
            'nuovi'      => $nuovi,
        ]));
    }

    private static function conta(string $sql): int
    {
        try {
            return (int) (Database::first($sql)['n'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function primoIscritto(): string
    {
        try {
            $r = Database::first("SELECT username, created_at FROM users WHERE status='active' ORDER BY created_at LIMIT 1");
            return $r === null ? '—' : $r['username'] . ' (' . fmt_date($r['created_at']) . ')';
        } catch (\Throwable) {
            return '—';
        }
    }
}
