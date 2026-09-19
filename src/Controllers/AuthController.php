<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\AuthMail;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController
{
    // --- Iscrizione ----------------------------------------------------------

    public function showRegister(Request $request): Response
    {
        return Response::html(view('auth/register', [
            'title'       => 'Iscrizione',
            'open'        => Auth::registrationOpen(),
            'minPassword' => Auth::minPasswordLength(),
        ]));
    }

    public function register(Request $request): Response
    {
        // Freno all'iscrizione: 5 tentativi per indirizzo IP ogni 30 minuti.
        if (!RateLimiter::hit('reg:' . $request->ip(), 5, 1800)) {
            Session::flash('error', "Troppi tentativi di iscrizione da questa connessione. Riprova fra mezz'ora.");
            return redirect('/iscrizione');
        }

        $username = $request->str('username');
        $email    = $request->str('email');
        $password = $request->str('password');
        $confirm  = $request->str('password_confirm');

        if ($password !== $confirm) {
            Session::flashInput($request->all());
            Session::flash('error', 'Le due password non coincidono.');
            return redirect('/iscrizione');
        }

        $res = Auth::register($username, $email, $password, $request->ip());
        if (!$res['ok']) {
            Session::flashInput($request->all());
            Session::flash('error', $res['error'] ?? 'Iscrizione non riuscita.');
            return redirect('/iscrizione');
        }

        $sent = AuthMail::sendVerification((int) $res['user_id'], mb_strtolower(trim($email)), trim($username), (string) $res['token']);
        AuthMail::notifyAdmin((int) $res['user_id'], trim($username), mb_strtolower(trim($email)));

        Session::flash('email', mb_strtolower(trim($email)));
        if (!$sent['ok']) {
            // Non è più un fallimento: il messaggio è in coda e riparte da
            // solo. Si avverte solo perché non arriverà nello stesso minuto.
            Session::flash('warning', 'Account creato. Il messaggio di conferma e\' in coda di spedizione: '
                . 'se non arriva entro qualche minuto, usa il pulsante qui sotto per richiederne un altro.');
        }
        return redirect('/verifica-inviata');
    }

    public function verificationSent(Request $request): Response
    {
        return Response::html(view('auth/verify_sent', [
            'title' => 'Conferma in arrivo',
            'email' => (string) flash('email', ''),
        ]));
    }

    /** Apertura del collegamento ricevuto per posta. */
    public function verify(Request $request): Response
    {
        $res = Auth::verifyEmail($request->str('token'), $request->ip());

        return Response::html(view('auth/verify_result', [
            'title' => $res['ok'] ? 'Indirizzo confermato' : 'Verifica non riuscita',
            'ok'    => $res['ok'],
            'error' => $res['error'] ?? null,
            'user'  => $res['user'] ?? null,
        ]));
    }

    /** Nuovo invio del collegamento di verifica. */
    public function resend(Request $request): Response
    {
        $login = $request->str('login');

        if (!RateLimiter::hit('resend:' . $request->ip(), 5, 1800)) {
            Session::flash('error', "Troppe richieste di rinvio. Riprova fra mezz'ora.");
            return redirect('/accesso');
        }

        $row = Database::first(
            "SELECT id, username, email, status, verify_sent_at FROM users
             WHERE (username = ? OR email = ?) AND status = 'pending'",
            [$login, mb_strtolower($login)]
        );

        // Risposta identica in ogni caso: non si rivela chi è iscritto e chi no.
        if ($row !== null) {
            $lastSent = $row['verify_sent_at'] !== null ? strtotime((string) $row['verify_sent_at']) : 0;
            if (time() - $lastSent >= 120) {
                $token = Auth::issueToken((int) $row['id'], 'verify_email', $request->ip());
                AuthMail::sendVerification((int) $row['id'], (string) $row['email'], (string) $row['username'], $token);
            }
        }

        Session::flash('success', 'Se l\'account esiste ed e\' in attesa di conferma, il collegamento è stato inviato di nuovo.');
        return redirect('/accesso');
    }

    // --- Accesso -------------------------------------------------------------

    public function showLogin(Request $request): Response
    {
        return Response::html(view('auth/login', ['title' => 'Accesso']));
    }

    public function login(Request $request): Response
    {
        $login = $request->str('login');

        // Due freni: uno per IP (chi prova molti account) e uno per nome utente
        // (chi martella un account solo da più indirizzi).
        if (!RateLimiter::hit('login:ip:' . $request->ip(), 20, 900)
            || !RateLimiter::hit('login:user:' . mb_strtolower($login), 10, 900)) {
            Session::flash('error', 'Troppi tentativi di accesso. Attendi qualche minuto.');
            return redirect('/accesso');
        }

        $res = Auth::attempt($login, $request->str('password'), $request->ip());

        if (!$res['ok']) {
            Session::flashInput(['login' => $login]);
            Session::flash('error', $res['error'] ?? 'Accesso non riuscito.');
            if (!empty($res['need_verify'])) {
                Session::flash('need_verify', $login);
            }
            return redirect('/accesso');
        }

        RateLimiter::clear('login:user:' . mb_strtolower($login));
        return redirect('/strada');
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        return redirect('/');
    }
}
