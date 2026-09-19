<?php

declare(strict_types=1);

/**
 * Piazza Pulita — front controller unico.
 * Ogni richiesta applicativa passa da qui (PATH_INFO o RewriteRule).
 */

define('PIAZZAPULITA', true);
define('APP_START', microtime(true));

$projectRoot = __DIR__;

require $projectRoot . '/src/autoload.php';
require $projectRoot . '/src/Support/helpers.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

$GLOBALS['__project_root'] = $projectRoot;

// --- Configurazione ----------------------------------------------------------
try {
    Config::load($projectRoot);
} catch (\Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Piazza Pulita — configurazione</title>'
        . '<style>body{font:16px/1.6 system-ui,sans-serif;background:#141210;color:#d8d2c6;'
        . 'max-width:44rem;margin:4rem auto;padding:0 1.5rem}code{background:#231f1b;padding:.15em .4em;'
        . 'border-radius:3px}h1{color:#c0392b}</style>'
        . '<h1>Configurazione mancante</h1><p>Impossibile avviare l\'applicazione:</p>'
        . '<pre><code>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</code></pre>'
        . '<p>Esegui <code>sudo bash deploy/00-bootstrap.sh</code>, poi '
        . '<code>php bin/console.php migrate</code>.</p>';
    exit;
}

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

$debug = (bool) Config::get('app.debug', false);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

View::setPath($projectRoot . '/views');
Session::start();

// --- Richiesta ---------------------------------------------------------------
$forcedBase = Config::get('app.base_path');
$request = new Request(
    (bool) Config::get('app.pretty_urls', false),
    is_string($forcedBase) ? $forcedBase : null,
);
$GLOBALS['__base_path']  = $request->basePath();
$GLOBALS['__url_prefix'] = $request->urlPrefix();

// --- Instradamento -----------------------------------------------------------
$router = new Router();
require $projectRoot . '/src/routes.php';

try {
    $response = $router->dispatch($request);
} catch (\Throwable $e) {
    logger(sprintf('%s: %s @ %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()), 'error');

    $isDb = str_contains(strtolower($e->getMessage()), 'database')
        || str_contains(strtolower($e->getMessage()), 'connessione al database');

    if ($isDb) {
        $response = Response::html(view('errors/db', [
            'title'  => 'Servizio non disponibile',
            'debug'  => $debug,
            'detail' => $e->getMessage(),
        ]), 503);
    } else {
        $response = Response::html(view('errors/generic', [
            'title'   => 'Errore interno',
            'status'  => 500,
            'message' => $debug
                ? $e::class . ': ' . $e->getMessage()
                : 'Si e\' verificato un errore imprevisto. L\'incidente e\' stato registrato.',
        ]), 500);
    }
}

$response->send();
