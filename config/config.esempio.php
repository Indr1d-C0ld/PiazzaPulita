<?php

declare(strict_types=1);

/**
 * ESEMPIO di configurazione. Non è quella vera e non va usata così com'è.
 *
 * `Config` cerca, in quest'ordine:
 *   1. la variabile d'ambiente PIAZZAPULITA_CONFIG
 *   2. /etc/piazzapulita/config.php
 *   3. /data/piazzapulita-config/config.php      <- quella di produzione
 *   4. <progetto>/config/config.php              <- solo per lo sviluppo
 *
 * `deploy/00-bootstrap.sh` scrive la numero 3 da solo, con una password di
 * database generata sul momento. Questo file serve a due cose: far vedere la
 * forma, e permettere di lavorare in locale contro un database usa-e-getta.
 */

return [
    'app' => [
        'name'        => 'Piazza Pulita',
        'env'         => 'sviluppo',
        'debug'       => true,
        'timezone'    => 'Europe/Rome',
        'pretty_urls' => true,
        'base_path'   => '',
        'public_url'  => 'http://localhost:8099',
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'piazzapulita',
        'user'    => 'piazzapulita',
        'pass'    => 'CAMBIAMI',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'session_name' => 'piazzapulita_sess',
        'session_ttl'  => 60 * 60 * 8,
    ],

    // 'log' scrive in storage/logs invece di spedire: è il modo giusto di
    // lavorare finché non si hanno credenziali SMTP vere.
    'mail' => [
        'transport'   => 'log',
        'smtp_host'   => 'smtp.esempio.tld',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',
        'smtp_user'   => 'CAMBIAMI',
        'smtp_pass'   => 'CAMBIAMI',
        'from_email'  => 'noreply@esempio.tld',   // dev'essere un mittente verificato
        'from_name'   => 'Piazza Pulita',
        'timeout'     => 15,
    ],

    'notify' => [
        'new_registration' => true,
        'admin_email'      => '',
    ],

    'mondo' => [
        // Il seme fissa tutto ciò che è pseudocasuale e deterministico.
        // Cambiarlo dopo l'avvio significa cambiare mondo: non farlo.
        'seme' => 19841984,
    ],
];
