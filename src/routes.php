<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\ProfiloController;
use App\Controllers\RivaliController;
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
$router->get('/manifest.webmanifest', [HomeController::class, 'manifesto']);

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

// --- Il giro degli altri -----------------------------------------------------
$router->get('/altri', [RivaliController::class, 'altri'], ['active']);
$router->post('/altri/attacca', [RivaliController::class, 'attacca'], ['active', 'throttle']);
$router->post('/altri/soffiata', [RivaliController::class, 'soffiata'], ['active', 'throttle']);
$router->post('/altri/infiltra', [RivaliController::class, 'infiltra'], ['active', 'throttle']);
$router->post('/altri/rapina', [RivaliController::class, 'rapina'], ['active', 'throttle']);
$router->get('/cronaca', [RivaliController::class, 'cronaca']);
$router->get('/batteria', [RivaliController::class, 'batteria'], ['active']);
$router->post('/batteria/fonda', [RivaliController::class, 'fonda'], ['active', 'throttle']);
$router->post('/batteria/entra', [RivaliController::class, 'entra'], ['active', 'throttle']);
$router->post('/batteria/esci', [RivaliController::class, 'esci'], ['active', 'throttle']);
$router->post('/batteria/cassa', [RivaliController::class, 'cassa'], ['active', 'throttle']);

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
$router->get('/albo', [HomeController::class, 'albo']);
$router->get('/obiettivi', [HomeController::class, 'obiettivi'], ['active']);

// --- Amministrazione ---------------------------------------------------------
$router->get('/admin', [AdminController::class, 'pannello'], ['admin']);
$router->post('/admin/config', [AdminController::class, 'config'], ['admin', 'throttle']);
$router->get('/admin/utenti', [AdminController::class, 'utenti'], ['admin']);
$router->get('/admin/utente/{id}', [AdminController::class, 'utente'], ['admin']);
$router->post('/admin/utente', [AdminController::class, 'azioneUtente'], ['admin', 'throttle']);
$router->get('/admin/mondo', [AdminController::class, 'mondo'], ['admin']);
$router->post('/admin/mondo', [AdminController::class, 'azioneMondo'], ['admin', 'throttle']);
$router->get('/admin/giocatori', [AdminController::class, 'giocatori'], ['admin']);
$router->post('/admin/giocatori', [AdminController::class, 'azioneGiocatore'], ['admin', 'throttle']);
$router->get('/admin/posta', [AdminController::class, 'posta'], ['admin']);
$router->post('/admin/posta/smista', [AdminController::class, 'smista'], ['admin', 'throttle']);
$router->get('/admin/registro', [AdminController::class, 'registro'], ['admin']);
