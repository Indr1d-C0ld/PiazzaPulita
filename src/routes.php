<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\ProfiloController;
use App\Controllers\AffariController;
use App\Controllers\LeggeController;
use App\Controllers\MercatoController;
use App\Controllers\MondoController;
use App\Controllers\OrganicoController;
use App\Core\Router;

/** @var Router $router */

// --- Pubbliche ---------------------------------------------------------------
$router->get('/', [HomeController::class, 'index']);
$router->get('/health', [HomeController::class, 'health']);
$router->get('/regole', [HomeController::class, 'regole']);

// --- Iscrizione e verifica dell'indirizzo ------------------------------------
$router->get('/iscrizione', [AuthController::class, 'showRegister'], ['guest']);
$router->post('/iscrizione', [AuthController::class, 'register'], ['guest', 'throttle']);
$router->get('/verifica-inviata', [AuthController::class, 'verificationSent']);
$router->get('/verifica', [AuthController::class, 'verify']);
$router->post('/rinvia-verifica', [AuthController::class, 'resend'], ['throttle']);

// --- Accesso -----------------------------------------------------------------
$router->get('/accesso', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/accesso', [AuthController::class, 'login'], ['guest', 'throttle']);
$router->post('/esci', [AuthController::class, 'logout'], ['auth']);

// --- Il mondo (area di gioco) ------------------------------------------------
// `/strada` e' dove sei adesso: da F2 in poi qui sotto compare il listino.
$router->get('/inizio', [MondoController::class, 'inizio'], ['active']);
$router->post('/inizio', [MondoController::class, 'nasci'], ['active', 'throttle']);
$router->get('/strada', [MondoController::class, 'strada'], ['active']);
$router->get('/mappa',  [MondoController::class, 'mappa'], ['active']);
$router->post('/parti', [MondoController::class, 'parti'], ['active', 'throttle']);
$router->get('/api/stato', [MondoController::class, 'stato'], ['active']);

// --- Il mercato --------------------------------------------------------------
$router->post('/ordina', [MercatoController::class, 'ordina'], ['active', 'throttle']);

// --- Gli affari: casse, usuraio, canali, mezzi, depositi ---------------------
$router->get('/affari', [AffariController::class, 'contabilita'], ['active']);
$router->post('/affari/lava', [AffariController::class, 'lava'], ['active', 'throttle']);
$router->post('/affari/canale', [AffariController::class, 'compraCanale'], ['active', 'throttle']);
$router->post('/affari/mezzo', [AffariController::class, 'compraMezzo'], ['active', 'throttle']);
$router->post('/affari/prestito', [AffariController::class, 'prestito'], ['active', 'throttle']);
$router->post('/affari/restituisci', [AffariController::class, 'restituisci'], ['active', 'throttle']);
$router->post('/deposito/apri', [AffariController::class, 'apriDeposito'], ['active', 'throttle']);
$router->post('/deposito/sposta', [AffariController::class, 'spostaMerce'], ['active', 'throttle']);

// --- Il personaggio e i suoi uomini ------------------------------------------
$router->get('/personaggio', [OrganicoController::class, 'scheda'], ['active']);
$router->post('/organico/assumi', [OrganicoController::class, 'assumi'], ['active', 'throttle']);
$router->post('/organico/licenzia', [OrganicoController::class, 'licenzia'], ['active', 'throttle']);
$router->post('/organico/piazza', [OrganicoController::class, 'piazza'], ['active', 'throttle']);
$router->post('/organico/manda', [OrganicoController::class, 'manda'], ['active', 'throttle']);

// --- La legge ----------------------------------------------------------------
$router->get('/fascicolo', [LeggeController::class, 'fascicolo'], ['active']);
$router->post('/fascicolo/avvocato', [LeggeController::class, 'avvocato'], ['active', 'throttle']);
$router->post('/fascicolo/bustarella', [LeggeController::class, 'bustarella'], ['active', 'throttle']);

// --- Profilo -----------------------------------------------------------------
$router->get('/profilo', [ProfiloController::class, 'mio'], ['active']);
$router->post('/profilo/nota', [ProfiloController::class, 'nota'], ['active', 'throttle']);
$router->post('/profilo/foto', [ProfiloController::class, 'caricaAvatar'], ['active', 'throttle']);
$router->post('/profilo/foto/togli', [ProfiloController::class, 'togliAvatar'], ['active', 'throttle']);
$router->post('/profilo/luce', [ProfiloController::class, 'luce'], ['auth', 'throttle']);
$router->get('/profilo/{id}', [ProfiloController::class, 'mostra'], ['active']);

// --- Classifica e statistiche (pubbliche: sono la vetrina del mondo) ---------
$router->get('/classifica', [HomeController::class, 'classifica']);
$router->get('/statistiche', [HomeController::class, 'statistiche']);

// --- Amministrazione ---------------------------------------------------------
$router->get('/admin', [AdminController::class, 'pannello'], ['admin']);
$router->post('/admin/config', [AdminController::class, 'config'], ['admin', 'throttle']);
$router->get('/admin/utenti', [AdminController::class, 'utenti'], ['admin']);
$router->get('/admin/utente/{id}', [AdminController::class, 'utente'], ['admin']);
$router->post('/admin/utente', [AdminController::class, 'azioneUtente'], ['admin', 'throttle']);
$router->get('/admin/posta', [AdminController::class, 'posta'], ['admin']);
$router->post('/admin/posta/smista', [AdminController::class, 'smista'], ['admin', 'throttle']);
$router->get('/admin/registro', [AdminController::class, 'registro'], ['admin']);
