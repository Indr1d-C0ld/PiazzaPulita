<?php

declare(strict_types=1);

/** Uso interno delle prove: rimuove un utente di prova e le sue tracce. */

require __DIR__ . '/_bootstrap.php';

use App\Core\Database;

$username = $_SERVER['argv'][1] ?? '';
if ($username === '' || !preg_match('/^prova[ _]/', $username)) {
    fwrite(STDERR, "Rifiuto: questo comando cancella solo utenti 'prova_*' o 'prova *'.\n");
    exit(1);
}

$u = Database::first('SELECT id FROM users WHERE username = ?', [$username]);
if ($u !== null) {
    // user_tokens ha la chiave esterna in cascata; audit_log no, perche' deve
    // sopravvivere alla cancellazione di un account vero.
    Database::run('DELETE FROM audit_log WHERE actor_user_id = ?', [(int) $u['id']]);
    Database::run('DELETE FROM users WHERE id = ?', [(int) $u['id']]);
}

// Gli utenti di prova sbagliati di proposito lasciano righe anche senza account.
Database::run("DELETE FROM users WHERE username LIKE 'prova %' AND email LIKE '%@esempio.invalid'");
Database::run("DELETE FROM rate_limits WHERE rkey LIKE 'reg:%' OR rkey LIKE 'login:%' OR rkey LIKE 'azioni:%' OR rkey LIKE 'resend:%'");

// Carico, spostamenti e transazioni se ne vanno in cascata con il personaggio;
// le transazioni no, perche' devono sopravvivere alla cancellazione di un conto
// vero — quindi qui si tolgono a mano.
Database::run('DELETE t FROM transazioni t LEFT JOIN personaggi p ON p.id = t.personaggio_id WHERE p.id IS NULL');

// La posta delle prove: lasciata in coda conterebbe verso il tetto giornaliero
// del provider, che e' condiviso con gli altri progetti sullo stesso account.
Database::run("DELETE FROM mail_queue WHERE destinatario LIKE '%@esempio.invalid'");
Database::run("DELETE FROM mail_queue WHERE genere = 'avviso_admin' AND oggetto LIKE '%: prova%'");
