<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

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
     * Classifica.
     *
     * In F0 il mondo non esiste ancora: non c'è patrimonio da ordinare, e
     * inventare una graduatoria finta sarebbe peggio che non averla. Si mostra
     * quello che c'è davvero — chi si è iscritto e quando — dicendolo.
     */
    public function classifica(Request $request): Response
    {
        return Response::html(view('classifica', [
            'title'  => 'Classifica',
            'righe'  => Database::all(
                "SELECT id, username, created_at, last_seen_at
                   FROM users WHERE status = 'active'
                  ORDER BY created_at LIMIT 100"
            ),
        ]));
    }

    public function statistiche(Request $request): Response
    {
        return Response::html(view('statistiche', [
            'title' => 'Statistiche',
            'dati'  => [
                'Iscritti attivi'          => self::conta("SELECT COUNT(*) n FROM users WHERE status = 'active'"),
                'In attesa di conferma'    => self::conta("SELECT COUNT(*) n FROM users WHERE status = 'pending'"),
                'Visti nelle ultime 24 ore' => self::conta(
                    "SELECT COUNT(*) n FROM users WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                ),
                'Primo iscritto'           => self::primoIscritto(),
            ],
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
