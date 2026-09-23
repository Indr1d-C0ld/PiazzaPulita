<?php

declare(strict_types=1);

/**
 * Piazza Pulita — console amministrativa.
 *
 *   php bin/console.php status
 *   php bin/console.php migrate
 *   php bin/console.php user:create <username> <email> [--admin]
 *                                                       crea un account gia' attivo
 *   php bin/console.php user:list
 *   php bin/console.php user:verify  <username|email>   conferma a mano un account
 *   php bin/console.php user:admin   <username>         promuove ad amministratore
 *   php bin/console.php user:unadmin <username>         riporta a giocatore comune
 *   php bin/console.php user:passwd  <username>         reimposta la password
 *   php bin/console.php user:rename  <vecchio> <nuovo>  cambia il nome d'accesso
 *   php bin/console.php user:delete  <username>         cancella un account (conferma richiesta)
 *   php bin/console.php mail:test    <indirizzo>        prova il trasporto SMTP
 *   php bin/console.php mail:coda                       stato della coda di spedizione
 *   php bin/console.php mail:smista  [quanti]           tenta i messaggi in attesa
 *   php bin/console.php config:list
 *   php bin/console.php config:set   <chiave> <valore> [tipo]
 *   php bin/console.php mondo:semina                    carica citta' e piazze
 *   php bin/console.php mondo:stato                     la geografia in una schermata
 *   php bin/console.php mondo:tratte [citta]            tempi e costi da una citta'
 *   php bin/console.php dove         <username>         dov'e' un giocatore
 *   php bin/console.php personaggio:cancella <username>  ricomincia da capo (conferma)
 *   php bin/console.php audit                           coerenza: parametri, foto, orfani, battito
  php bin/console.php avatar:verifica [--ripara]      fotografie: banca dati contro disco
  php bin/console.php mercato:semina [--conserva]     costruisce il mercato dal tetto
 *   php bin/console.php mercato:stato [piazza]          il listino di una piazza
 *   php bin/console.php balance:report                  il controllo di bilanciamento
 */

$projectRoot = require __DIR__ . '/_bootstrap.php';

use App\Auth\Auth;
use App\Cli\Migrator;
use App\Core\Config;
use App\Core\Database;
use App\Core\GameConfig;
use App\Core\Mailer;
use App\Core\Posta;
use App\Game\Batteria;
use App\Game\Listino;
use App\Game\Mondo;
use App\Game\Personaggio;
use App\Game\SeminaMercato;
use App\Sim\Viaggio;

$argv = $_SERVER['argv'];
$cmd  = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function err(string $s): void { fwrite(STDERR, $s . "\n"); }

function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    if ($hidden) {
        shell_exec('stty -echo 2>/dev/null');
        $v = trim((string) fgets(STDIN));
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
        return $v;
    }
    return trim((string) fgets(STDIN));
}

/** @return array<string,mixed>|null */
function findUser(string $needle): ?array
{
    return Database::first(
        'SELECT * FROM users WHERE username = ? OR email = ? OR id = ?',
        [Auth::normalizeUsername($needle), mb_strtolower($needle), ctype_digit($needle) ? (int) $needle : 0]
    );
}

try {
    switch ($cmd) {
        case 'migrate':
            out('Migrazioni:');
            foreach ((new Migrator($projectRoot . '/db/migrations'))->migrate() as $line) {
                out($line);
            }
            out('Fatto.');
            break;

        case 'status':
            out('Piazza Pulita — stato');
            out('  config      : ' . Config::sourceFile());
            out('  database    : ' . (Database::isReachable() ? 'raggiungibile' : 'NON raggiungibile'));
            $ver = Database::all('SELECT version FROM schema_migrations ORDER BY version');
            out('  migrazioni  : ' . count($ver) . ' applicate' . (count($ver) ? ' (ultima: ' . end($ver)['version'] . ')' : ''));
            out('  utenti      : ' . (int) (Database::first('SELECT COUNT(*) n FROM users')['n'] ?? 0)
                . ' (attivi: ' . (int) (Database::first("SELECT COUNT(*) n FROM users WHERE status='active'")['n'] ?? 0)
                . ', in attesa: ' . (int) (Database::first("SELECT COUNT(*) n FROM users WHERE status='pending'")['n'] ?? 0) . ')');
            $p = Posta::stato();
            out('  posta       : ' . $p['in_coda'] . ' in coda, ' . $p['inviate_24h'] . '/' . $p['tetto'] . ' nelle 24 h'
                . ($p['rinunciate'] > 0 ? ', ' . $p['rinunciate'] . ' rinunciate' : ''));
            out('  trasporto   : ' . (string) Config::get('mail.transport', 'log')
                . ' via ' . (string) Config::get('mail.smtp_host', '—'));
            out('  avviso admin: ' . (string) Config::get('notify.admin_email', '—'));
            $t = Database::first('SELECT started_at, ok FROM tick_runs ORDER BY id DESC LIMIT 1');
            out('  ultimo tick : ' . ($t === null ? 'mai' : fmt_dt($t['started_at'], true) . ($t['ok'] ? ' (ok)' : ' (FALLITO)')));
            break;

        case 'user:list':
            $rows = Database::all('SELECT id, username, email, status, role, created_at, last_login_at FROM users ORDER BY id');
            if ($rows === []) {
                out('Nessun utente iscritto.');
                break;
            }
            printf("%-4s %-22s %-32s %-10s %-10s %s\n", 'ID', 'UTENTE', 'E-MAIL', 'STATO', 'RUOLO', 'ISCRITTO');
            foreach ($rows as $r) {
                printf("%-4d %-22s %-32s %-10s %-10s %s\n", $r['id'], $r['username'], $r['email'],
                    $r['status'], $r['role'], fmt_date($r['created_at']));
            }
            break;

        case 'user:create':
            // Serve a fare il PRIMO amministratore: senza, l'unico modo di
            // entrare sarebbe la registrazione web, che richiede una posta
            // funzionante — e la posta la si configura dal pannello, che sta
            // dietro all'account che non hai ancora. Un cane che si morde la
            // coda, e questo comando e' il morso che si evita.
            //
            // L'account nasce gia' attivo e con l'indirizzo confermato: chi ha
            // accesso alla riga di comando del server ha gia' dimostrato tutto
            // quello che la conferma via e-mail serve a dimostrare.
            $nome  = Auth::normalizeUsername($args[0] ?? '');
            $posta = mb_strtolower(trim($args[1] ?? ''));
            $admin = in_array('--admin', $args, true);

            if ($e = Auth::validateUsername($nome)) {
                throw new RuntimeException($e);
            }
            if (!filter_var($posta, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Indirizzo e-mail non valido.');
            }
            if (Database::first('SELECT id FROM users WHERE username = ? OR email = ?', [$nome, $posta]) !== null) {
                throw new RuntimeException('Esiste gia\' un account con questo nome o indirizzo.');
            }

            $pw = prompt("Password per {$nome}: ", true);
            $min = Auth::minPasswordLength();
            if (mb_strlen($pw) < $min) {
                throw new RuntimeException("Password troppo corta (minimo {$min}).");
            }

            Database::run(
                'INSERT INTO users (username, email, password_hash, status, role, email_verified_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$nome, $posta, Auth::hashPassword($pw), 'active', $admin ? 'admin' : 'player']
            );
            $nuovo = Database::lastInsertId();
            \App\Support\Audit::log('admin.user_create', null, 'user', $nuovo,
                ['username' => $nome, 'ruolo' => $admin ? 'admin' : 'player', 'via' => 'console']);
            out("Creato #{$nuovo}: {$nome} <{$posta}> — " . ($admin ? 'amministratore' : 'giocatore') . ', attivo.');
            break;

        case 'user:verify':
            $u = findUser($args[0] ?? '') ?? throw new RuntimeException('Utente non trovato.');
            Database::run("UPDATE users SET status='active', email_verified_at=COALESCE(email_verified_at, NOW()) WHERE id = ?", [$u['id']]);
            out("Confermato: {$u['username']}");
            break;

        case 'user:admin':
        case 'user:unadmin':
            $u = findUser($args[0] ?? '') ?? throw new RuntimeException('Utente non trovato.');
            $ruolo = $cmd === 'user:admin' ? 'admin' : 'player';
            Database::run('UPDATE users SET role = ? WHERE id = ?', [$ruolo, $u['id']]);
            out("{$u['username']} -> {$ruolo}");
            break;

        case 'user:passwd':
            $u = findUser($args[0] ?? '') ?? throw new RuntimeException('Utente non trovato.');
            $pw = prompt("Nuova password per {$u['username']}: ", true);
            $min = Auth::minPasswordLength();
            if (mb_strlen($pw) < $min) {
                throw new RuntimeException("Password troppo corta (minimo {$min}).");
            }
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($pw), $u['id']]);
            out('Password aggiornata.');
            break;

        case 'user:rename':
            $u = findUser($args[0] ?? '') ?? throw new RuntimeException('Utente non trovato.');
            $nuovo = Auth::normalizeUsername($args[1] ?? '');
            if ($e = Auth::validateUsername($nuovo)) {
                throw new RuntimeException($e);
            }
            if (Database::first('SELECT id FROM users WHERE username = ? AND id <> ?', [$nuovo, $u['id']]) !== null) {
                throw new RuntimeException('Nome gia\' in uso.');
            }
            Database::run('UPDATE users SET username = ? WHERE id = ?', [$nuovo, $u['id']]);
            out("{$u['username']} -> {$nuovo}");
            break;

        case 'user:delete':
            $u = findUser($args[0] ?? '') ?? throw new RuntimeException('Utente non trovato.');
            if (prompt("Cancellare definitivamente «{$u['username']}»? (scrivi SI) ") !== 'SI') {
                out('Annullato.');
                break;
            }
            // Il personaggio se ne va in cascata con l'account: se comandava una
            // batteria, prima la si passa di mano.
            foreach (Database::all('SELECT id FROM personaggi WHERE user_id = ?', [$u['id']]) as $pp) {
                Batteria::primaDiSparire((int) $pp['id']);
            }
            Database::run('DELETE FROM users WHERE id = ?', [$u['id']]);
            out('Cancellato.');
            break;

        case 'mail:test':
            $to = $args[0] ?? throw new RuntimeException('Indica un indirizzo.');
            $r = Mailer::send($to, 'Piazza Pulita — prova di trasporto',
                "Se leggi questo messaggio, l'SMTP funziona.\n\nInviato il " . fmt_dt(time(), true) . ".");
            out($r['ok'] ? 'Inviato (trasporto: ' . Config::get('mail.transport') . ').'
                         : 'FALLITO: ' . ($r['error'] ?? '?'));
            break;

        case 'mail:coda':
            $p = Posta::stato();
            out("in coda      : {$p['in_coda']}");
            out("inviate 24 h : {$p['inviate_24h']} (tetto {$p['tetto']})");
            out("rinunciate   : {$p['rinunciate']}");
            foreach (Database::all(
                'SELECT id, destinatario, genere, tentativi, prossimo_at, ultimo_errore FROM mail_queue
                  WHERE inviato_at IS NULL AND rinunciato_at IS NULL ORDER BY priorita, id LIMIT 20') as $m) {
                out(sprintf('  #%-5d %-30s %-12s tent.%d  prossimo %s  %s',
                    $m['id'], $m['destinatario'], $m['genere'], $m['tentativi'],
                    fmt_dt($m['prossimo_at']), (string) ($m['ultimo_errore'] ?? '')));
            }
            break;

        case 'mail:smista':
            $e = Posta::smista(isset($args[0]) ? max(1, (int) $args[0]) : null);
            out("tentati {$e['tentati']}, inviati {$e['inviati']}, rinunciati {$e['rinunciati']}");
            break;

        case 'config:list':
            foreach (GameConfig::all() as $k => $v) {
                printf("%-28s %-8s %s\n", $k, $v['type'], $v['value']);
            }
            break;

        case 'config:set':
            $k = $args[0] ?? throw new RuntimeException('Indica la chiave.');
            $v = $args[1] ?? throw new RuntimeException('Indica il valore.');
            $t = $args[2] ?? 'string';
            GameConfig::set($k, $v, $t);
            out("{$k} = {$v} ({$t})");
            break;

        case 'mondo:semina':
            $e = Mondo::semina($projectRoot . '/db/seed/mondo.php');
            out("Seminate {$e['citta']} citta' e {$e['piazze']} piazze.");
            break;

        case 'mondo:stato':
            if (!Mondo::esiste()) {
                out('Il mondo non e\' ancora stato seminato: php bin/console.php mondo:semina');
                break;
            }
            foreach (Mondo::citta() as $id => $c) {
                $piazze = Mondo::piazzeDi($id);
                $pol = count($piazze) === 0 ? 0 : array_sum(array_column($piazze, 'polizia')) / count($piazze);
                printf("%-3s %-9s %-8s %2d piazze  polizia media %2d%%\n",
                    $c['codice'], $c['nome'], $c['carattere'], count($piazze), (int) round($pol));
                foreach ($piazze as $p) {
                    printf("      %-22s %-14s %3d%%\n", $p['nome'], $p['tipo'], $p['polizia']);
                }
            }
            $n = (int) (Database::first('SELECT COUNT(*) n FROM personaggi')['n'] ?? 0);
            $v = (int) (Database::first('SELECT COUNT(*) n FROM personaggi WHERE arrivo_at IS NOT NULL')['n'] ?? 0);
            out("\npersonaggi: {$n} (in viaggio adesso: {$v})");
            break;

        case 'mondo:tratte':
            $codice = strtoupper($args[0] ?? 'MI');
            $da = null;
            foreach (Mondo::citta() as $id => $c) {
                if ($c['codice'] === $codice) { $da = $id; break; }
            }
            if ($da === null) {
                throw new RuntimeException("Citta' sconosciuta: {$codice}");
            }
            $partenza = Mondo::piazzeDi($da)[0];
            out("Da {$partenza['nome']} (" . Mondo::citta()[$da]['nome'] . "):\n");
            foreach (Mondo::citta() as $id => $c) {
                if ($id === $da) { continue; }
                $arrivo = Mondo::piazzeDi($id)[0];
                $riga = sprintf('  %-9s ', $c['nome']);
                foreach (Mondo::opzioniFra((int) $partenza['id'], (int) $arrivo['id']) as $o) {
                    $riga .= sprintf('%-8s %8s %11s   ', $o['mezzo'], Viaggio::durata($o['minuti']), lire($o['costo']));
                }
                out($riga);
            }
            break;

        case 'personaggio:cancella':
            // Operazione distruttiva, quindi sta qui e non in una riga di SQL
            // improvvisata: mostra prima cosa sparisce, chiede conferma, e
            // lascia una traccia nel registro. Il conto resta: sparisce solo il
            // personaggio, e alla prossima visita si riparte dalla scelta della
            // citta'. E' quello che serve quando un giocatore chiede di
            // ricominciare, e capitera' piu' di una volta.
            $u = findUser($args[0] ?? '') ?? throw new RuntimeException('Utente non trovato.');
            $p = Database::first('SELECT * FROM personaggi WHERE user_id = ?', [(int) $u['id']]);
            if ($p === null) {
                out("{$u['username']} non ha un personaggio: non c'e' niente da cancellare.");
                break;
            }

            $dove = Database::first(
                'SELECT z.nome, c.nome AS citta FROM piazze z JOIN citta c ON c.id = z.citta_id WHERE z.id = ?',
                [(int) $p['piazza_id']]
            );
            $conteggi = [];
            foreach (['carico', 'spostamenti', 'transazioni'] as $t) {
                $conteggi[$t] = (int) (Database::first(
                    "SELECT COUNT(*) n FROM {$t} WHERE personaggio_id = ?", [(int) $p['id']]
                )['n'] ?? 0);
            }

            out("Personaggio #{$p['id']} di {$u['username']}");
            out('  dove     : ' . ($dove['nome'] ?? '?') . ', ' . ($dove['citta'] ?? '?'));
            out('  contante : ' . lire((int) $p['contante']));
            out('  creato   : ' . fmt_dt($p['creato_at']));
            foreach ($conteggi as $t => $n) {
                out(sprintf('  %-12s %d righe', $t . ' :', $n));
            }

            if (prompt('Cancellare? Il conto resta, il personaggio no. (scrivi SI) ') !== 'SI') {
                out('Annullato.');
                break;
            }

            // carico e spostamenti se ne vanno in cascata; le transazioni no,
            // perche' il registro deve sopravvivere alla cancellazione di un
            // personaggio — ma quelle di questo non servono piu' a nessuno.
            Database::run('DELETE FROM transazioni WHERE personaggio_id = ?', [(int) $p['id']]);
            Batteria::primaDiSparire((int) $p['id']);
            Database::run('DELETE FROM personaggi WHERE id = ?', [(int) $p['id']]);
            \App\Support\Audit::log('admin.personaggio_cancellato', null, 'user', (int) $u['id'],
                ['personaggio' => (int) $p['id'], 'dove' => $dove['citta'] ?? '?', 'via' => 'console']);

            out('Cancellato. Al prossimo accesso ' . $u['username'] . ' risceglie la citta\'.');
            break;

        case 'audit':
            // Un audit a mano si fa una volta e si dimentica; un comando si
            // rilancia. Qui stanno le verifiche che non guardano il GIOCO — per
            // quelle ci sono `balance:report` e le prove — ma la COERENZA fra le
            // parti: configurazione contro codice, disco contro banca dati,
            // righe orfane. Sono tutte cose che non rompono niente subito, e
            // proprio per questo marciscono in silenzio.
            $rilievi = 0;
            out('Audit della coerenza — ' . fmt_dt(time()));

            out('');
            out('1. PARAMETRI DI GIOCO (la tabella contro il codice)');
            $inTabella = [];
            foreach (Database::all('SELECT ckey FROM game_config') as $c) {
                $inTabella[(string) $c['ckey']] = true;
            }
            $letti = [];
            foreach (['src', 'bin', 'views'] as $d) {
                $dir = ($GLOBALS['__project_root'] ?? dirname(__DIR__)) . '/' . $d;
                if (!is_dir($dir)) { continue; }
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
                    if ($f->getExtension() !== 'php') { continue; }
                    if (preg_match_all('/GameConfig::(?:get|int|bool)\(\s*[\x27"]([a-z0-9_.]+)[\x27"]/',
                            (string) file_get_contents($f->getPathname()), $m)) {
                        foreach ($m[1] as $k) { $letti[$k] = true; }
                    }
                }
            }
            $morte  = array_diff(array_keys($inTabella), array_keys($letti));
            $assenti = array_diff(array_keys($letti), array_keys($inTabella));
            printf("   in tabella %d · letti dal codice %d\n", count($inTabella), count($letti));
            foreach ($morte as $k) {
                $rilievi++;
                out('   MORTA (nessuno la legge: cambiarla non fa niente): ' . $k);
            }
            foreach ($assenti as $k) {
                out('   assente dalla tabella (si usa il valore di ripiego): ' . $k);
            }
            if (!$morte && !$assenti) { out('   tutto corrisponde.'); }

            out('');
            out('2. FOTOGRAFIE (banca dati contro disco)');
            $dirFoto = ($GLOBALS['__project_root'] ?? dirname(__DIR__)) . '/assets/img/avatar';
            $appese = 0;
            foreach (Database::all("SELECT username, avatar_file FROM users
                                     WHERE avatar_file IS NOT NULL AND avatar_file <> ''") as $u) {
                if (!is_file($dirFoto . '/' . basename((string) $u['avatar_file']))) {
                    $appese++; $rilievi++;
                    out('   APPESA: ' . $u['username'] . ' → file mancante');
                }
            }
            printf("   %s · %d riferimenti appesi\n", $dirFoto, $appese);

            out('');
            out('3. RIGHE ORFANE');
            $orfane = [
                'batterie senza capo'      => 'SELECT COUNT(*) n FROM batterie b
                                                LEFT JOIN personaggi p ON p.id = b.capo_id WHERE p.id IS NULL',
                'territori senza batteria' => 'SELECT COUNT(*) n FROM territori
                                                WHERE batteria_id IS NOT NULL AND batteria_id NOT IN (SELECT id FROM batterie)',
                'carico senza personaggio' => 'SELECT COUNT(*) n FROM carico c
                                                LEFT JOIN personaggi p ON p.id = c.personaggio_id WHERE p.id IS NULL',
                'baratti eterni'           => "SELECT COUNT(*) n FROM baratti
                                                WHERE stato = 'proposto' AND scade_at < DATE_SUB(NOW(), INTERVAL 1 DAY)",
                'voci mai dimenticate'     => 'SELECT COUNT(*) n FROM chiacchiere
                                                WHERE fatto_at < DATE_SUB(NOW(), INTERVAL 7 DAY)',
                'personaggi senza utente'  => 'SELECT COUNT(*) n FROM personaggi p
                                                LEFT JOIN users u ON u.id = p.user_id WHERE u.id IS NULL',
            ];
            foreach ($orfane as $che => $sql) {
                $n = (int) (Database::first($sql)['n'] ?? 0);
                if ($n > 0) { $rilievi++; printf("   %-28s %d  ← da guardare\n", $che, $n); }
                else        { printf("   %-28s %d\n", $che, $n); }
            }

            out('');
            out('4. IL BATTITO');
            $ultimo = Database::first('SELECT started_at, ok, duration_ms FROM tick_runs ORDER BY id DESC LIMIT 1');
            if ($ultimo === null) {
                $rilievi++; out('   MAI ESEGUITO: il mondo è fermo. Manca la riga di cron?');
            } else {
                $eta = time() - strtotime((string) $ultimo['started_at']);
                printf("   ultimo %s (%d s fa, %d ms, %s)\n", fmt_dt($ultimo['started_at']), $eta,
                    (int) $ultimo['duration_ms'], ((int) $ultimo['ok']) === 1 ? 'ok' : 'FALLITO');
                if ($eta > 300) { $rilievi++; out('   FERMO DA PIÙ DI CINQUE MINUTI.'); }
                if (((int) $ultimo['ok']) !== 1) { $rilievi++; }
            }
            $falliti = (int) (Database::first('SELECT COUNT(*) n FROM tick_runs
                               WHERE ok = 0 AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')['n'] ?? 0);
            printf("   battiti falliti nelle 24 h: %d\n", $falliti);

            out('');
            out($rilievi === 0 ? 'Nessun rilievo.' : $rilievi . ' rilievi da guardare.');
            break;

        case 'avatar:verifica':
            // Le fotografie stanno su disco e il loro nome in banca dati: se le
            // due cose divergono, il giocatore vede un rettangolo grigio e non
            // capisce perché. È successo davvero — un `rsync --delete` senza
            // l'esclusione giusta le portava via tutte a ogni deploy — quindi
            // adesso c'è un comando che lo dice, e volendo rimedia.
            $ripara = in_array('--ripara', $args, true);
            $dir = ($GLOBALS['__project_root'] ?? dirname(__DIR__)) . '/assets/img/avatar';
            $righe = Database::all(
                "SELECT id, username, avatar_file FROM users WHERE avatar_file IS NOT NULL AND avatar_file <> ''");
            $appesi = [];
            foreach ($righe as $u) {
                if (!is_file($dir . '/' . basename((string) $u['avatar_file']))) {
                    $appesi[] = $u;
                }
            }
            // Si guarda l'installazione DA CUI SI VIENE LANCIATI: dalla
            // cartella di lavoro non è quella servita dal web. Il percorso si
            // stampa apposta, così si vede subito se si sta guardando la
            // cartella sbagliata.
            out('Fotografie del profilo — ' . $dir);
            out('  registrate in banca dati : ' . count($righe));
            out('  file presenti sul disco  : ' . count(glob($dir . '/*.webp') ?: []));
            if ($appesi === []) {
                out('  nessun riferimento appeso.');
                break;
            }
            out('  RIFERIMENTI APPESI       : ' . count($appesi));
            foreach ($appesi as $u) {
                out('    ' . $u['username'] . ' → ' . $u['avatar_file']);
            }
            if (!$ripara) {
                out('');
                out('  Il file non si può ricostruire: chi lo aveva deve ricaricarlo.');
                out('  Con --ripara si toglie il riferimento, così al suo posto');
                out('  torna l\'iniziale invece di un rettangolo vuoto.');
                break;
            }
            foreach ($appesi as $u) {
                Database::run('UPDATE users SET avatar_file = NULL, avatar_hash = NULL WHERE id = ?',
                    [(int) $u['id']]);
            }
            out('  ' . count($appesi) . ' riferimenti tolti.');
            break;

        case 'mercato:semina':
            $e = SeminaMercato::semina($projectRoot . '/db/seed/beni.php', in_array('--conserva', $args, true));
            out("Seminati {$e['beni']} beni su {$e['mercati']} nodi di mercato.");
            if ($e['piazze_vuote'] > 0) {
                out("  attenzione: {$e['piazze_vuote']} piazze senza nemmeno un bene.");
            }
            out('  somma teorica: ' . lire((int) round($e['teorico'])) . ' l\'ora');
            break;

        case 'mercato:stato':
            $codice = strtoupper($args[0] ?? '');
            $piazza = null;
            foreach (Mondo::piazze() as $p) {
                if ($codice === '' || $p['codice'] === $codice) { $piazza = $p; break; }
            }
            if ($piazza === null) {
                throw new RuntimeException("Piazza sconosciuta: {$codice}");
            }
            $c = Mondo::cittaDi((int) $piazza['id']);
            out("{$piazza['nome']} ({$c['nome']}, {$piazza['tipo']}, polizia {$piazza['polizia']}%)\n");
            printf("%-26s %12s %12s %9s %9s %8s\n", 'BENE', 'COMPRI A', 'VENDI A', 'GIACENZA', 'ASSORBE', 'RIFER.');
            foreach (Listino::perPiazza((int) $piazza['id']) as $v) {
                printf("%-26s %12s %12s %9s %9s %8s\n",
                    $v['bene']['nome'] . ' (' . $v['bene']['unita'] . ')',
                    lire($v['acquisto']), lire($v['vendita']),
                    quantita($v['offerta']), quantita($v['domanda']),
                    quantita($v['riferimento']));
            }
            break;

        case 'balance:report':
            $r = SeminaMercato::rapporto();
            out('Bilanciamento del mondo — ' . fmt_dt(time()));
            out('');
            out('1. SOMMA TEORICA (deve tornare il tetto entro il 2%)');
            out('   tetto    : ' . lire((int) $r['tetto']) . " l'ora");
            out('   teorico  : ' . lire((int) round($r['teorico'])) . " l'ora");
            printf("   scarto   : %+.2f%%   %s\n", $r['scarto'] * 100,
                abs($r['scarto']) <= 0.02 ? 'OK' : '*** FUORI TOLLERANZA: le quote non sommano a 1 ***');
            out('   nodi     : ' . $r['nodi']);
            foreach ($r['per_fascia'] as $f => $v) {
                printf("     %-6s %14s   %5.1f%%\n", $f, lire((int) round($v)), $v / max(1, $r['teorico']) * 100);
            }
            out('');
            out('2. ESTRAZIONE REALE (non deve MAI superare il tetto)');
            out('   ultime 24 h : ' . quantita($r['vendite_24h']) . ' vendite');
            out('   reale       : ' . lire((int) round($r['reale_ora'])) . " l'ora");
            printf("   %s\n", $r['reale_ora'] > $r['tetto']
                ? '*** SUPERA IL TETTO: c\'e\' un baco o un exploit ***'
                : 'entro il tetto');
            out('');
            out('3. UTILIZZO');
            out('   giocatori che hanno venduto nelle ultime 24 h: ' . $r['attivi_24h']);
            if ($r['attivi_24h'] < 3) {
                printf("   %.1f%%  non misurabile: servono almeno tre giocatori attivi\n", $r['utilizzo'] * 100);
                out('          (con uno solo il mondo risulta sempre «troppo generoso»,');
                out('           e non e\' un difetto di taratura: e\' che non estrae nessuno)');
            } else {
                printf("   %.1f%%  %s\n", $r['utilizzo'] * 100, match (true) {
                    $r['utilizzo'] > 0.60 => 'ostile a chi arriva: il tetto va alzato',
                    $r['utilizzo'] < 0.15 => 'troppo generoso: se dura un mese, va abbassato',
                    default               => 'temperatura giusta',
                });
            }
            if ($r['attivi_24h'] > 0) {
                out('   per giocatore: ' . lire((int) round($r['per_giocatore'])) . " l'ora  " . match (true) {
                    $r['per_giocatore'] > 4_500_000 => '(sopra il profilo maturo: abbassare R)',
                    $r['per_giocatore'] < 150_000   => '(sotto il principiante: alzare R)',
                    default                         => '(dentro i profili del §2.6)',
                });
            }
            out('');
            out('4. BANDA DI GIOCABILITA\' (3-30 unita\' l\'ora per piazza, armi escluse)');
            if ($r['fuori_banda'] === []) {
                out('   tutti i nodi in banda.');
            } else {
                out('   ' . count($r['fuori_banda']) . ' nodi fuori banda:');
                foreach (array_slice($r['fuori_banda'], 0, 12) as $f) {
                    printf("     %-22s %-26s %6.1f\n", $f['piazza'], $f['bene'], (float) $f['domanda_eq']);
                }
            }
            break;

        case 'dove':
            $u = findUser($args[0] ?? '') ?? throw new RuntimeException('Utente non trovato.');
            $p = Personaggio::perUtente((int) $u['id']);
            if ($p === null) {
                out("{$u['username']} non ha ancora un personaggio.");
                break;
            }
            $s = Personaggio::stato($p);
            out(sprintf('%s: %s, %s%s — in tasca %s',
                $u['username'],
                $s['piazza']['nome'] ?? '?',
                $s['citta']['nome'] ?? '?',
                $s['in_viaggio'] ? " (in viaggio, arriva fra {$s['mancano']})" : '',
                lire($s['contante'])
            ));
            break;

        default:
            // L'elenco dei comandi e' il commento in testa a questo file: cosi'
            // non puo' divergere dall'aiuto stampato.
            $doc = (string) file_get_contents(__FILE__);
            if (preg_match('#/\*\*(.*?)\*/#s', $doc, $m) === 1) {
                foreach (preg_split('/\R/', $m[1]) ?: [] as $riga) {
                    out(rtrim(preg_replace('/^\s*\*\s?/', '', $riga) ?? $riga));
                }
            }
            if ($cmd !== 'help') {
                err("Comando sconosciuto: {$cmd}");
                exit(1);
            }
            break;
    }
} catch (\Throwable $e) {
    err('Errore: ' . $e->getMessage());
    exit(1);
}
