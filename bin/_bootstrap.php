<?php

declare(strict_types=1);

/** Bootstrap comune degli script CLI (bin/*.php). */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PIAZZAPULITA', true);

$projectRoot = dirname(__DIR__);
$GLOBALS['__project_root'] = $projectRoot;
$GLOBALS['__base_path']    = '';
$GLOBALS['__url_prefix']   = '';

require $projectRoot . '/src/autoload.php';
require $projectRoot . '/src/Support/helpers.php';

use App\Core\Config;

try {
    Config::load($projectRoot);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Errore di configurazione: ' . $e->getMessage() . "\n");
    exit(1);
}

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

return $projectRoot;
