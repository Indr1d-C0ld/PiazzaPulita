<?php

declare(strict_types=1);

/**
 * I beni, e le regole che dicono dove girano e quanto costano.
 *
 * Le forchette vengono da docs/DESIGN.md §2.5 e conservano la proprietà
 * strutturale dell'originale del 1984: **i beni poveri sono percentualmente
 * più volatili**. È quella, e non altro, la scala di progressione travestita
 * da listino — con le sigarette si moltiplica per cinque, con la cocaina per
 * due e mezzo.
 *
 * `quota` è la frazione del tetto di reddito orario del mondo che passa da
 * questo bene. Le dieci quote sommano a 1: è l'invariante che `balance:report`
 * controlla per primo.
 *
 * `affinita` dice quali quartieri trattano il bene e con che peso. Zero
 * significa che lì quella roba non gira: è il listino parziale di dopewars,
 * che qui serve a due cose — informazione imperfetta, e il fatto che non tutte
 * le piazze valgono lo stesso viaggio.
 */

return [
    // codice, nome, unità, fascia, min, max, quota, frazione di spread, ingombro, rischio
    'beni' => [
        ['sigarette',  'Sigarette di contrabbando', 'stecca',      'bassa',   8_000,    45_000, 0.12, 0.30, 3,  5],
        ['anfetamine', 'Anfetamine',                'dose',        'bassa',   3_000,    20_000, 0.06, 0.30, 1, 18],
        ['acidi',      'Acidi',                     'francobollo', 'bassa',   5_000,    30_000, 0.07, 0.30, 1, 20],
        ['hashish',    'Hashish',                   '10 g',        'media',  20_000,    70_000, 0.12, 0.25, 1, 28],
        ['marijuana',  'Marijuana',                 '10 g',        'media',  15_000,    60_000, 0.11, 0.25, 2, 25],
        ['pasticche',  'Pasticche',                 'dose',        'media',  15_000,    60_000, 0.06, 0.25, 1, 32],
        ['farmaci',    'Farmaci e morfina',         'fiala',       'media',  20_000,    80_000, 0.06, 0.25, 1, 30],
        ['eroina',     'Eroina',                    'grammo',      'alta',   80_000,   350_000, 0.18, 0.18, 1, 70],
        ['cocaina',    'Cocaina',                   'grammo',      'alta',  120_000,   300_000, 0.17, 0.15, 1, 65],
        ['armi',       'Armi',                      'pezzo',       'armi',  400_000, 2_500_000, 0.05, 0.20, 5, 90],
    ],

    /**
     * Quanto una piazza di quel tipo assorbe di quel bene. Zero = non ci gira.
     *
     * Si legge come una fotografia sociale del periodo, ed è voluto: gli acidi
     * e le pasticche dove ci sono i giovani, i farmaci dove c'è chi soffre, le
     * armi solo dove nessuno chiama la polizia, la cocaina dove ci sono i soldi.
     */
    'affinita' => [
        //              periferia popolare centro stazione benestante universitaria
        'sigarette'  => [1.10,    1.40,    0.90,  1.50,    0.20,      0.70],
        'anfetamine' => [1.20,    1.10,    0.70,  1.30,    0.30,      1.10],
        'acidi'      => [0.70,    0.90,    0.60,  0.80,    0.30,      1.80],
        'hashish'    => [1.20,    1.30,    0.90,  1.00,    0.50,      1.50],
        'marijuana'  => [1.10,    1.20,    0.80,  0.90,    0.40,      1.80],
        'pasticche'  => [0.80,    0.90,    1.00,  0.80,    0.70,      1.90],
        'farmaci'    => [1.30,    1.20,    0.80,  1.10,    0.40,      0.70],
        'eroina'     => [1.60,    1.20,    0.60,  1.10,    0.30,      0.40],
        'cocaina'    => [0.70,    0.90,    1.20,  0.80,    1.90,      0.60],
        'armi'       => [1.80,    0.90,    0.00,  0.30,    0.00,      0.00],
    ],

    /** L'ordine dei tipi nelle righe di `affinita`. */
    'tipi' => ['periferia', 'popolare', 'centro', 'stazione', 'benestante', 'universitaria'],

    /**
     * Beni esentati dalla banda di giocabilità.
     *
     * Le armi stanno sotto le tre unità l'ora per piazza di proposito: meno di
     * un pezzo ogni ora e mezza significa che trovarne una è un evento e che
     * venderne dieci è impossibile senza una rete. Se passassero dalla potatura
     * sparirebbero dal mondo invece di essere rare.
     */
    'senza_banda' => ['armi'],

    /**
     * Tetto al numero di piazze che trattano un bene.
     *
     * Serve solo alle armi, e serve perché sono l'unico bene esentato dalla
     * potatura: senza un limite esplicito resterebbero sparse su quaranta
     * piazze a un decimo di pezzo l'ora ciascuna, che non è rarità — è assenza.
     * Sei piazze in tutta Italia, quelle con l'affinità più alta, fanno invece
     * un pezzo ogni ora e mezza: trovarne una è un evento e venderne dieci
     * richiede una rete.
     */
    'max_piazze' => ['armi' => 6],

    /**
     * Il carattere strutturale del prezzo: dove la roba ENTRA costa meno, dove
     * si CONSUMA costa di più. È questo, e non il rumore casuale, a creare le
     * rotte commerciali stabili che all'originale mancavano del tutto.
     *
     * La forbice è stretta di proposito. Fra la piazza più a buon mercato
     * (porto + periferia) e la più cara (città di consumo + quartiere
     * benestante) ci sono circa 1,6 volte: tolto lo spread e l'impatto del
     * proprio ordine, la rotta migliore del paese rende intorno al 50 %, quella
     * normale fra il 20 e il 30. È il ritmo del 1984 (§2.6), e una forbice più
     * larga lo manderebbe all'aria in due ore di gioco.
     */
    'base_carattere' => ['porto' => 0.90, 'snodo' => 1.00, 'consumo' => 1.08],

    'mult_tipo' => [
        'periferia'     => 0.93,
        'popolare'      => 1.00,
        'universitaria' => 1.02,
        'centro'        => 1.04,
        'stazione'      => 1.05,
        'benestante'    => 1.12,
    ],

    /** Ritocco per carattere di città e fascia di merce. */
    'bonus_fascia' => [
        'porto'   => ['bassa' => 0.96, 'media' => 0.94, 'alta' => 0.95, 'armi' => 0.94],
        'snodo'   => ['bassa' => 1.00, 'media' => 1.00, 'alta' => 1.00, 'armi' => 1.00],
        'consumo' => ['bassa' => 1.03, 'media' => 1.04, 'alta' => 1.05, 'armi' => 1.03],
    ],

    /** Quanto mercato assorbe ogni città rispetto alle altre. */
    'peso_citta' => [
        'RM' => 1.40, 'MI' => 1.30, 'NA' => 1.15, 'TO' => 1.00,
        'PA' => 0.90, 'BO' => 0.85, 'GE' => 0.80, 'BA' => 0.75, 'CT' => 0.65,
    ],
];
