<?php

declare(strict_types=1);

/**
 * La geografia di Piazza Pulita: 9 città, 43 piazze.
 *
 * Le coordinate sono reali e approssimate al quartiere — servono a due cose:
 * calcolare le distanze (e quindi i tempi e i costi di viaggio) e disegnare la
 * rete sulla mappa. Un errore di qualche centinaio di metri non cambia niente;
 * la forma del paese sì, ed è quella che deve essere giusta.
 *
 * `polizia` è la presenza di forze dell'ordine in percento, sulla scala di
 * dopewars (5-90): governa i controlli, la probabilità che una rissa richiami
 * una volante, e il costo di farsi i fatti propri. `tipo` è il carattere del
 * quartiere, e in F2 deciderà quali fasce di merce ci girano e a che prezzo.
 *
 * **Ogni città ha almeno un quartiere benestante o di centro**, e non è un
 * dettaglio di colore: è un vincolo strutturale scoperto simulando. Senza uno
 * sbocco locale una città è tutta fonte e niente mercato, e chi ci nasce non
 * ha dove vendere. Nella prima versione il sud ne era privo e un principiante
 * che cominciava da Palermo guadagnava trentotto volte meno di uno che
 * cominciava da Milano — con la scelta della città presentata come «nessuna è
 * sbagliata, sono partite diverse». Erano partite diverse per modo di dire.
 *
 * I nomi sono quelli che la cronaca del periodo ha reso noti. È un gioco di
 * finzione ambientato in un'epoca precisa: cambiarli tutti si fa con una
 * passata su questo file e nient'altro.
 */

return [
    'citta' => [
        ['codice' => 'MI', 'nome' => 'Milano',  'lat' => 45.464200, 'lon' =>  9.190000, 'carattere' => 'consumo', 'aeroporto' => 1, 'nota' => 'Il denaro pulito, i prezzi alti, la gente che paga senza chiedere.'],
        ['codice' => 'TO', 'nome' => 'Torino',  'lat' => 45.070300, 'lon' =>  7.686900, 'carattere' => 'consumo', 'aeroporto' => 1, 'nota' => 'Operaia e regolare: consumo di massa, poche sorprese.'],
        ['codice' => 'GE', 'nome' => 'Genova',  'lat' => 44.405600, 'lon' =>  8.946300, 'carattere' => 'porto',   'aeroporto' => 1, 'nota' => 'Il porto: quello che entra dal mare entra da qui.'],
        ['codice' => 'BO', 'nome' => 'Bologna', 'lat' => 44.494900, 'lon' => 11.342600, 'carattere' => 'snodo',   'aeroporto' => 1, 'nota' => 'Tutti ci passano. Nessuno ci resta.'],
        ['codice' => 'RM', 'nome' => 'Roma',    'lat' => 41.902800, 'lon' => 12.496400, 'carattere' => 'consumo', 'aeroporto' => 1, 'nota' => 'Il mercato più grande del paese, e il più sorvegliato.'],
        ['codice' => 'NA', 'nome' => 'Napoli',  'lat' => 40.851800, 'lon' => 14.268100, 'carattere' => 'porto',   'aeroporto' => 1, 'nota' => 'Contrabbando, prezzi bassi, e il territorio che conta più della legge.'],
        ['codice' => 'BA', 'nome' => 'Bari',    'lat' => 41.117100, 'lon' => 16.871900, 'carattere' => 'porto',   'aeroporto' => 1, 'nota' => 'L\'Adriatico di fronte, e dall\'altra parte i Balcani.'],
        ['codice' => 'PA', 'nome' => 'Palermo', 'lat' => 38.115700, 'lon' => 13.361500, 'carattere' => 'porto',   'aeroporto' => 1, 'nota' => 'Dove le cose si decidono. La legge c\'è, ma non si sa mai da che parte.'],
        ['codice' => 'CT', 'nome' => 'Catania', 'lat' => 37.507900, 'lon' => 15.083000, 'carattere' => 'snodo',   'aeroporto' => 1, 'nota' => 'Periferia del sistema: poco controllo, poco di tutto.'],
    ],

    // citta => [codice, nome, lat, lon, tipo, polizia]
    'piazze' => [
        'MI' => [
            ['MI-CENTRALE',  'Stazione Centrale',   45.486200,  9.205000, 'stazione',     65],
            ['MI-QUARTO',    'Quarto Oggiaro',      45.515000,  9.143000, 'periferia',    15],
            ['MI-GIAMBE',    'Giambellino',         45.447800,  9.135000, 'popolare',     30],
            ['MI-BRERA',     'Brera',               45.472000,  9.188000, 'benestante',   80],
            ['MI-CORVETTO',  'Corvetto',            45.438000,  9.225000, 'popolare',     35],
            ['MI-LORENTE',   'Lorenteggio',         45.452000,  9.118000, 'periferia',    20],
        ],
        'TO' => [
            ['TO-PALAZZO',   'Porta Palazzo',       45.078000,  7.682000, 'popolare',     45],
            ['TO-BARRIERA',  'Barriera di Milano',  45.095000,  7.705000, 'periferia',    20],
            ['TO-VALLETTE',  'Le Vallette',         45.100000,  7.630000, 'periferia',    12],
            ['TO-SALVARIO',  'San Salvario',        45.056000,  7.679000, 'universitaria',50],
            ['TO-MIRAFIORI', 'Mirafiori',           45.025000,  7.625000, 'popolare',     25],
            ['TO-CROCETTA',  'Crocetta',            45.058000,  7.664000, 'benestante',   75],
        ],
        'GE' => [
            ['GE-CENTRO',    'Centro Storico',      44.410000,  8.930000, 'popolare',     40],
            ['GE-SAMPIER',   'Sampierdarena',       44.415000,  8.890000, 'popolare',     25],
            ['GE-CORNIGL',   'Cornigliano',         44.418000,  8.860000, 'periferia',    18],
            ['GE-CERTOSA',   'Certosa',             44.430000,  8.885000, 'periferia',    15],
            ['GE-ALBARO',    'Albaro',              44.393000,  8.968000, 'benestante',   72],
        ],
        'BO' => [
            ['BO-BOLOGNINA','Bolognina',            44.509000, 11.342000, 'popolare',     30],
            ['BO-PILASTRO', 'Pilastro',             44.514000, 11.390000, 'periferia',    14],
            ['BO-UNIVER',   'Zona universitaria',   44.496000, 11.352000, 'universitaria',45],
            ['BO-CORTICEL', 'Corticella',           44.535000, 11.350000, 'periferia',    18],
            ['BO-STEFANO',  'Santo Stefano',        44.488000, 11.353000, 'benestante',   68],
        ],
        'RM' => [
            ['RM-TERMINI',  'Termini',              41.901000, 12.501000, 'stazione',     70],
            ['RM-TRASTEVE', 'Trastevere',           41.887000, 12.470000, 'universitaria',55],
            ['RM-SANBASIL', 'San Basilio',          41.940000, 12.590000, 'periferia',    16],
            ['RM-TORBELLA', 'Tor Bella Monaca',     41.878000, 12.642000, 'periferia',    12],
            ['RM-QUARTICC', 'Quarticciolo',         41.890000, 12.585000, 'periferia',    18],
            ['RM-EUR',      'EUR',                  41.832000, 12.470000, 'benestante',   80],
            ['RM-OSTIA',    'Ostia',                41.732000, 12.279000, 'popolare',     35],
        ],
        'NA' => [
            ['NA-FORCELLA', 'Forcella',             40.850000, 14.262000, 'popolare',     35],
            ['NA-QUARTIER', 'Quartieri Spagnoli',   40.842000, 14.248000, 'popolare',     40],
            ['NA-SANITA',   'Sanità',               40.860000, 14.250000, 'popolare',     28],
            ['NA-SECONDIG', 'Secondigliano',        40.888000, 14.262000, 'periferia',    14],
            ['NA-SCAMPIA',  'Scampia',              40.902000, 14.248000, 'periferia',     8],
            ['NA-VOMERO',   'Vomero',               40.845000, 14.230000, 'benestante',   70],
            ['NA-POSILLIPO','Posillipo',            40.812000, 14.205000, 'benestante',   66],
        ],
        'BA' => [
            ['BA-LIBERTA',  'Libertà',              41.118000, 16.858000, 'popolare',     35],
            ['BA-SANPAOLO', 'San Paolo',            41.125000, 16.808000, 'periferia',    15],
            ['BA-JAPIGIA',  'Japigia',              41.095000, 16.890000, 'popolare',     25],
            ['BA-CARBONAR', 'Carbonara',            41.080000, 16.830000, 'periferia',    18],
            ['BA-MURAT',    'Murat',                41.125000, 16.871000, 'benestante',   66],
        ],
        'PA' => [
            ['PA-BALLARO',  'Ballarò',              38.112000, 13.360000, 'popolare',     38],
            ['PA-BORGO',    'Borgo Vecchio',        38.129000, 13.360000, 'popolare',     30],
            ['PA-ZEN',      'Zen',                  38.182000, 13.318000, 'periferia',    10],
            ['PA-BRANCACC', 'Brancaccio',           38.096000, 13.390000, 'periferia',    13],
            ['PA-LIBERTA',  'Via Libertà',          38.135000, 13.348000, 'benestante',   70],
        ],
        'CT' => [
            ['CT-SANCRIST', 'San Cristoforo',       37.498000, 15.079000, 'popolare',     30],
            ['CT-PICANELL', 'Picanello',            37.520000, 15.095000, 'popolare',     26],
            ['CT-LIBRINO',  'Librino',              37.465000, 15.045000, 'periferia',    12],
            ['CT-MONTEPO',  'Monte Po',             37.490000, 15.035000, 'periferia',    16],
            ['CT-CORSOITA', 'Corso Italia',         37.514000, 15.096000, 'benestante',   64],
        ],
    ],
];
