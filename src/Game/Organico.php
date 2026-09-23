<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Clock;
use App\Sim\Crescita;
use App\Sim\Rng;
use App\Sim\Viaggio;

/**
 * Gli uomini che lavorano per te.
 *
 * Portano con sé le due cose rimaste indietro nelle fasi precedenti: i
 * **corrieri**, cioè i carichi in transito che in F3 non esistevano perché non
 * c'era nessuno a cui affidarli, e i **pentiti**, che in F4 non potevano
 * esistere perché non c'era nessuno che potesse parlare.
 *
 * Ogni uomo ha un nome, uno stipendio che si paga a ore, e una lealtà che cala
 * se non lo paghi o se i colleghi finiscono dentro. Un uomo sleale che viene
 * preso collabora — ed è il ponte fra la gestione del personale e il rischio,
 * cioè fra le due metà del gioco.
 */
final class Organico
{
    private const NOMI = ['Gennaro', 'Ciro', 'Salvatore', 'Rocco', 'Nino', 'Pino', 'Mimmo', 'Tonino',
        'Saverio', 'Peppe', 'Cosimo', 'Michele', 'Vincenzo', 'Carmine', 'Alfredo', 'Renato',
        'Gigi', 'Franco', 'Sandro', 'Beppe', 'Elio', 'Duilio'];
    private const SOPRANNOMI = ['\'o Biondo', 'il Muto', '\'a Scigna', 'Manolesta', 'il Contabile',
        'Baffo', 'Lampadina', 'il Sardo', 'Quattrocchi', 'Mezzanotte', 'il Geometra', 'Serpente',
        'Panza', 'il Nano', 'Radio', 'Ferro'];

    /** Cosa fa ogni ruolo, in una riga e nei numeri. */
    public const RUOLI = [
        'corriere'    => ['Corriere',    'Porta la roba da una parte all\'altra mentre tu sei altrove.'],
        'vedetta'     => ['Vedetta',     'Sta in una piazza e ti avvisa. Meno controlli, per te, lì.'],
        'contabile'   => ['Contabile',   'Fa girare più denaro nei canali che hai.'],
        'riciclatore' => ['Riciclatore', 'Tratta meglio con chi lava: la commissione scende.'],
        'basista'     => ['Basista',     'Ti dice i prezzi di una piazza dove non sei.'],
        'guardia'     => ['Guardia',     'Ti sta dietro. In un controllo si perde meno roba.'],
    ];

    // --- Lettura ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function uomini(int $personaggioId): array
    {
        $f = [];
        foreach (Database::all(
            'SELECT u.*, z.nome AS piazza FROM uomini u LEFT JOIN piazze z ON z.id = u.piazza_id
              WHERE u.personaggio_id = ? AND u.stato <> \'sparito\' ORDER BY u.ruolo, u.id',
            [$personaggioId]
        ) as $u) {
            $u['lealta'] = (float) $u['lealta'];
            $u['competenza'] = (int) $u['competenza'];
            $u['stipendio_ora'] = (int) $u['stipendio_ora'];
            $f[] = $u;
        }
        return $f;
    }

    /** @param array<string,mixed> $p */
    public static function quantiNePuoi(array $p): int
    {
        return Crescita::uominiRetti((float) $p['organizzazione'],
            GameConfig::int('organico.base', 1), GameConfig::int('organico.per_grado', 15));
    }

    /** Quanti ne ha adesso. */
    public static function quantiNeHa(int $personaggioId): int
    {
        return (int) (Database::first(
            'SELECT COUNT(*) n FROM uomini WHERE personaggio_id = ? AND stato <> \'sparito\'',
            [$personaggioId]
        )['n'] ?? 0);
    }

    /** Il costo orario di tutto l'organico. */
    public static function stipendiOra(int $personaggioId): int
    {
        return (int) (Database::first(
            'SELECT COALESCE(SUM(stipendio_ora), 0) s FROM uomini WHERE personaggio_id = ? AND stato <> \'sparito\'',
            [$personaggioId]
        )['s'] ?? 0);
    }

    /**
     * Gli effetti dell'organico, in un colpo solo.
     *
     * Un uomo conta per quanto è competente E per quanto è leale: uno bravo che
     * non ti sopporta lavora male, ed è giusto che si veda nei numeri prima che
     * nei guai.
     *
     * @return array{vedette:array<int,float>,contabile:float,riciclatore:float,guardia:float,basisti:list<int>}
     */
    public static function effetti(int $personaggioId): array
    {
        $e = ['vedette' => [], 'contabile' => 0.0, 'riciclatore' => 0.0, 'guardia' => 0.0, 'basisti' => []];
        foreach (self::uomini($personaggioId) as $u) {
            if ((string) $u['stato'] === 'dentro') {
                continue;
            }
            $peso = ($u['competenza'] / 100.0) * (max(0.0, $u['lealta']) / 100.0);
            switch ((string) $u['ruolo']) {
                case 'vedetta':
                    if ($u['piazza_id'] !== null) {
                        $e['vedette'][(int) $u['piazza_id']] = max($e['vedette'][(int) $u['piazza_id']] ?? 0, $peso);
                    }
                    break;
                case 'contabile':    $e['contabile'] += $peso; break;
                case 'riciclatore':  $e['riciclatore'] += $peso; break;
                case 'guardia':      $e['guardia'] = max($e['guardia'], $peso); break;
                case 'basista':
                    if ($u['piazza_id'] !== null) { $e['basisti'][] = (int) $u['piazza_id']; }
                    break;
            }
        }
        return $e;
    }

    // --- Assumere e licenziare ---------------------------------------------------

    /** @return array{ok:bool,error?:string,uomo?:array<string,mixed>} */
    public static function assumi(int $personaggioId, string $ruolo): array
    {
        if (!isset(self::RUOLI[$ruolo])) {
            return ['ok' => false, 'error' => 'Questo mestiere non esiste.'];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            if ($p === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Personaggio inesistente.']; }
            // La pagina non lo offre a chi è dentro; il server ora lo rifiuta.
            if (Legge::inCarcere($p)) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Da dentro non si ingaggia nessuno.']; }

            $tetto = self::quantiNePuoi($p);
            if (self::quantiNeHa($personaggioId) >= $tetto) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Più di ' . $tetto . ($tetto === 1 ? ' uomo' : ' uomini')
                    . ' non ne reggi: non sai dove metterli e non sai cosa fargli fare. '
                    . 'Cresce con l\'organizzazione, e quella cresce usandola.'];
            }

            $rng = new Rng((int) (microtime(true) * 1000) ^ $personaggioId);
            // Chi ti si offre dipende da quanto vali: il rispetto porta gente
            // migliore, il timore gente più fedele. Sono due strade diverse
            // verso la stessa cosa, ed è il punto dei due assi.
            $competenza = max(20, min(95, 35 + (int) round((float) $p['rispetto'] * 0.45) + $rng->intero(-10, 10)));
            $lealta     = max(30, min(95, 55 + (int) round((float) $p['timore'] * 0.30) + $rng->intero(-12, 12)));
            $stipendio  = (int) round(GameConfig::int('organico.stipendio_min', 18_000) * (0.7 + $competenza / 100.0));
            $anticipo   = $stipendio * GameConfig::int('organico.ingaggio', 8);

            if ((int) $p['contante'] < $anticipo) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Vuole ' . lire($anticipo) . ' di anticipo. In contanti.'];
            }

            $nome = self::NOMI[$rng->intero(0, count(self::NOMI) - 1)] . ' ' . self::SOPRANNOMI[$rng->intero(0, count(self::SOPRANNOMI) - 1)];

            Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$anticipo, $personaggioId]);
            Database::run(
                'INSERT INTO uomini (personaggio_id, nome, ruolo, competenza, lealta, stipendio_ora,
                                     piazza_id, pagato_fino_a, agg_a)
                 VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL ? HOUR), ?)',
                [$personaggioId, $nome, $ruolo, $competenza, $lealta, $stipendio,
                 $ruolo === 'corriere' ? null : (int) $p['piazza_id'],
                 Clock::perDb(), GameConfig::int('organico.ingaggio', 8), Clock::perDb()]
            );
            $id = Database::lastInsertId();
            Contabilita::segna($personaggioId, 'organico', 'sporco', -$anticipo, 'ingaggio di ' . $nome);
            self::cresci($personaggioId, 'organizzazione', 1.0);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'uomo' => Database::first('SELECT * FROM uomini WHERE id = ?', [$id])];
    }

    /** @return array{ok:bool,error?:string,nome?:string} */
    public static function licenzia(int $personaggioId, int $uomoId): array
    {
        // In fila con `manda()`: un corriere licenziato nell'istante in cui
        // partiva si portava via la corsa (la cancellazione scende a cascata)
        // e con lei il carico.
        $u = Fila::per($personaggioId, static function () use ($personaggioId, $uomoId): array|string {
            $u = Database::first('SELECT * FROM uomini WHERE id = ? AND personaggio_id = ? FOR UPDATE',
                [$uomoId, $personaggioId]);
            if ($u === null) { return 'Non lavora per te.'; }
            if ((string) $u['stato'] === 'in_viaggio') { return 'È per strada con la tua roba.'; }
            Database::run('DELETE FROM uomini WHERE id = ?', [$uomoId]);
            return $u;
        });
        if (is_string($u)) { return ['ok' => false, 'error' => $u]; }

        // Mandare via qualcuno che non ti odiava è gratis; mandare via uno che
        // già ti odiava è un rischio che ti porti dietro.
        if ((float) $u['lealta'] < 40) {
            Legge::prove($personaggioId, 8.0, 'qualcuno che hai mandato via ha parlato');
            Legge::segnale($personaggioId, 'organico',
                $u['nome'] . ' se n\'è andato con la faccia di chi ha qualcosa da raccontare.', 4);
        }
        return ['ok' => true, 'nome' => (string) $u['nome']];
    }

    /** Sposta una vedetta o un basista dove sei. */
    public static function piazza(int $personaggioId, int $uomoId, int $piazzaId): array
    {
        $u = Database::first('SELECT * FROM uomini WHERE id = ? AND personaggio_id = ?', [$uomoId, $personaggioId]);
        if ($u === null) { return ['ok' => false, 'error' => 'Non lavora per te.']; }
        // In viaggio `piazza_id` è già la destinazione: lo si metterebbe in
        // un posto dove non sei ancora arrivato.
        $p = Database::first('SELECT arrivo_at, carcere_fino_a FROM personaggi WHERE id = ?', [$personaggioId]);
        if ($p === null || $p['arrivo_at'] !== null) { return ['ok' => false, 'error' => 'Prima arriva tu.']; }
        if (Legge::inCarcere($p)) { return ['ok' => false, 'error' => 'Da dentro non lo metti da nessuna parte.']; }
        if (!in_array((string) $u['ruolo'], ['vedetta', 'basista'], true)) {
            return ['ok' => false, 'error' => 'Questo mestiere non si fa stando fermi in un posto.'];
        }
        Database::run('UPDATE uomini SET piazza_id = ? WHERE id = ?', [$piazzaId, $uomoId]);
        return ['ok' => true];
    }

    /**
     * Quello che i basisti riferiscono: il listino di una piazza dove non sei.
     *
     * **È il mestiere per cui li paghi**, ed è anche il perno dell'economia
     * dell'informazione di tutto il gioco: sapere i prezzi altrove qui costa —
     * è il motivo per cui la chiacchiera è di piazza (§13.3) e per cui la carta
     * del giocatore non mostra dove sta chiunque (§13.1.1). Senza questo, la
     * regola sarebbe una frase nei commenti e basta.
     *
     * Un basista senza piazza assegnata non riferisce niente: va messo da
     * qualche parte, ed è quella la decisione che vale i suoi soldi.
     *
     * @param array<string,mixed> $p il personaggio che chiede
     * @return list<array{piazza:array<string,mixed>,citta:array<string,mixed>,
     *                    uomo:string,listino:list<array<string,mixed>>}>
     */
    public static function rapportiDeiBasisti(array $p): array
    {
        $righe = Database::all(
            "SELECT id, nome, piazza_id, competenza FROM uomini
              WHERE personaggio_id = ? AND ruolo = 'basista' AND stato <> 'sparito'
                AND piazza_id IS NOT NULL
              ORDER BY id",
            [(int) $p['id']]
        );

        $fuori = [];
        $visti = [];
        foreach ($righe as $u) {
            $piazzaId = (int) $u['piazza_id'];
            // Due basisti nella stessa piazza non raddoppiano niente: si paga
            // due volte lo stesso rapporto, ed è giusto che si veda una volta.
            if (isset($visti[$piazzaId]) || $piazzaId === (int) $p['piazza_id']) {
                continue;
            }
            $visti[$piazzaId] = true;
            $piazza = Mondo::piazza($piazzaId);
            if ($piazza === null) {
                continue;
            }
            $fuori[] = [
                'piazza'  => $piazza,
                'citta'   => Mondo::cittaDi($piazzaId),
                'uomo'    => (string) $u['nome'],
                'listino' => Listino::perPiazza($piazzaId, $p),
            ];
        }
        return $fuori;
    }

    // --- I corrieri ---------------------------------------------------------------

    /**
     * Manda un corriere con un carico. Arriva in un deposito, non addosso a te.
     *
     * @return array{ok:bool,error?:string,minuti?:int}
     */
    public static function manda(int $personaggioId, int $uomoId, int $aPiazzaId, int $beneId, int $quantita): array
    {
        // Senza questo controllo una quantità negativa arrivava fino
        // all'INSERT della corsa, che la rifiutava con un errore 500.
        if ($quantita <= 0) {
            return ['ok' => false, 'error' => 'Quante unità, di preciso?'];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $p = Database::first('SELECT * FROM personaggi WHERE id = ? FOR UPDATE', [$personaggioId]);
            $u = Database::first('SELECT * FROM uomini WHERE id = ? AND personaggio_id = ? FOR UPDATE',
                [$uomoId, $personaggioId]);
            if ($p === null || $u === null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non lavora per te.']; }
            if ((string) $u['ruolo'] !== 'corriere') { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non è un corriere.']; }
            if ((string) $u['stato'] !== 'libero') { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non è disponibile.']; }
            if ($p['arrivo_at'] !== null) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Prima arriva tu.']; }
            if (Legge::inCarcere($p)) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Da dentro non parte niente.']; }

            $dest = Logistica::deposito($personaggioId, $aPiazzaId);
            if ($dest === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Là non hai un deposito dove scaricare. '
                    . 'Un corriere non può stare in mezzo alla strada ad aspettarti.'];
            }

            $c = Database::first('SELECT * FROM carico WHERE personaggio_id = ? AND bene_id = ?', [$personaggioId, $beneId]);
            $ho = (int) ($c['quantita'] ?? 0);
            if ($ho <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non ne hai addosso.']; }
            $q = min($quantita, $ho);
            $costo = (int) round((int) $c['costo_totale'] / $ho) * $q;

            $opz = Mondo::opzioniFra((int) $p['piazza_id'], $aPiazzaId);
            if ($opz === []) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Non si arriva.']; }
            // Il corriere viaggia col mezzo più lento fra quelli pubblici: non
            // ha la tua macchina, e non gli si paga l'aereo.
            $minuti = (int) round($opz[0]['minuti'] * (1.4 - $u['competenza'] / 250.0));

            Database::run(
                'UPDATE carico SET quantita = quantita - ?, costo_totale = GREATEST(0, costo_totale - ?)
                  WHERE personaggio_id = ? AND bene_id = ?', [$q, $costo, $personaggioId, $beneId]);
            Database::run('DELETE FROM carico WHERE personaggio_id = ? AND quantita <= 0', [$personaggioId]);
            Database::run('UPDATE uomini SET stato = \'in_viaggio\' WHERE id = ?', [$uomoId]);
            Database::run(
                'INSERT INTO corse (uomo_id, personaggio_id, da_piazza_id, a_piazza_id, bene_id, quantita,
                                    costo_totale, partito_at, arrivo_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL ? MINUTE))',
                [$uomoId, $personaggioId, (int) $p['piazza_id'], $aPiazzaId, $beneId, $q, $costo,
                 Clock::perDb(), Clock::perDb(), $minuti]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'minuti' => $minuti];
    }

    /** @return list<array<string,mixed>> */
    public static function corseAperte(int $personaggioId): array
    {
        return Database::all(
            'SELECT c.*, u.nome AS corriere, b.nome AS bene, b.unita,
                    zd.nome AS da_piazza, za.nome AS a_piazza
               FROM corse c JOIN uomini u ON u.id = c.uomo_id JOIN beni b ON b.id = c.bene_id
               JOIN piazze zd ON zd.id = c.da_piazza_id JOIN piazze za ON za.id = c.a_piazza_id
              WHERE c.personaggio_id = ? AND c.esito = \'in_corso\' ORDER BY c.arrivo_at',
            [$personaggioId]
        );
    }

    /**
     * Chiude le corse arrivate. Una corsa può anche non arrivare: dipende da
     * quanto è leale il corriere e da quanto scotti tu.
     *
     * @return array{arrivate:int,perse:int}
     */
    public static function chiudiCorse(): array
    {
        $arrivate = 0; $perse = 0;
        foreach (Database::all(
            'SELECT c.id, c.personaggio_id FROM corse c
              WHERE c.esito = \'in_corso\' AND c.arrivo_at <= ?', [Clock::perDb()]
        ) as $r) {
            // Il padrone in fila e la corsa bloccata e riletta: un rapinatore
            // può averla presa un attimo fa, e allora non va consegnata.
            $esito = Fila::per((int) $r['personaggio_id'], static fn() => self::chiudiCorsa((int) $r['id']));
            if ($esito === 'arrivata') { $arrivate++; }
            if ($esito === 'persa') { $perse++; }
        }
        return ['arrivate' => $arrivate, 'perse' => $perse];
    }

    /** @return string|null 'arrivata', 'persa', o null se non c'era più niente da chiudere */
    private static function chiudiCorsa(int $corsaId): ?string
    {
        $c = Database::first(
            'SELECT c.*, u.lealta, u.competenza, u.nome AS corriere, p.calore, p.calore_agg_a
               FROM corse c JOIN uomini u ON u.id = c.uomo_id JOIN personaggi p ON p.id = c.personaggio_id
              WHERE c.id = ? AND c.esito = \'in_corso\' FOR UPDATE', [$corsaId]);
        if ($c === null) {
            return null;
        }
        $pid = (int) $c['personaggio_id'];
        $calore = Legge::calorePersonale($c);
        // Il rischio di perdere un carico: la lealtà conta più di tutto.
        $rischio = min(0.6, (1.0 - (float) $c['lealta'] / 100.0) * 0.35 + $calore / 1500.0);

        if (random_int(1, 1000) / 1000 < $rischio) {
            $sparito = (float) $c['lealta'] < 45;
            Database::run('UPDATE corse SET esito = ?, chiusa_at = ? WHERE id = ?',
                [$sparito ? 'sparita' : 'sequestrata', Clock::perDb(), (int) $c['id']]);
            Database::run('UPDATE uomini SET stato = ? WHERE id = ?',
                [$sparito ? 'sparito' : 'libero', (int) $c['uomo_id']]);
            Legge::segnale($pid, 'corsa', $sparito
                ? $c['corriere'] . ' non è mai arrivato a destinazione. Nemmeno lui.'
                : $c['corriere'] . ' è stato fermato per strada: la roba se n\'è andata.', 4);
            if (!$sparito) {
                Legge::prove($pid, 8.0, 'un corriere fermato');
            }
            Contabilita::segna($pid, 'corsa', 'sporco', 0,
                'persa una corsa: ' . quantita((int) $c['quantita']) . ' unità, ' . lire((int) $c['costo_totale']));
            return 'persa';
        }

        $dep = Logistica::deposito($pid, (int) $c['a_piazza_id']);
        if ($dep === null) {
            // Il deposito è sparito mentre era per strada (sfratto): la
            // roba resta al corriere, che la riporta indietro come può.
            Legge::segnale($pid, 'corsa',
                $c['corriere'] . ' è arrivato e non ha trovato il deposito. Ha lasciato tutto dov\'era.', 3);
        } else {
            Database::run(
                'INSERT INTO deposito_merce (deposito_id, bene_id, quantita, costo_totale) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantita = quantita + VALUES(quantita),
                                         costo_totale = costo_totale + VALUES(costo_totale)',
                [(int) $dep['id'], (int) $c['bene_id'], (int) $c['quantita'], (int) $c['costo_totale']]
            );
        }
        Database::run('UPDATE corse SET esito = \'arrivata\', chiusa_at = ? WHERE id = ?',
            [Clock::perDb(), (int) $c['id']]);
        Database::run('UPDATE uomini SET stato = \'libero\', piazza_id = ? WHERE id = ?',
            [(int) $c['a_piazza_id'], (int) $c['uomo_id']]);
        return 'arrivata';
    }

    // --- Stipendi e lealtà ----------------------------------------------------------

    /**
     * Paga gli stipendi scaduti. Chi non viene pagato perde lealtà, e la lealtà
     * è quello che tiene chiuse le bocche.
     *
     * @return array{pagati:int,non_pagati:int}
     */
    public static function pagaStipendi(): array
    {
        $ora = Clock::adesso();
        $pagati = 0; $nonPagati = 0;
        $calo = (float) GameConfig::get('organico.lealta_calo', 0.35);

        foreach (Database::all('SELECT id, personaggio_id FROM uomini WHERE pagato_fino_a <= ? AND stato <> \'sparito\'',
            [Clock::perDb($ora)]) as $r) {
            // Il saldo si guarda e si addebita col padrone in fila: con un
            // acquisto in piazza nello stesso istante, il contante andava
            // sotto zero.
            $esito = Fila::per((int) $r['personaggio_id'],
                static fn(?array $p) => self::pagaUno((int) $r['id'], $p, $ora, $calo));
            if ($esito === true) { $pagati++; }
            if ($esito === false) { $nonPagati++; }
        }
        return ['pagati' => $pagati, 'non_pagati' => $nonPagati];
    }

    /**
     * @param array<string,mixed>|null $p il padrone, bloccato
     * @return bool|null true pagato, false non pagato, null niente da fare
     */
    private static function pagaUno(int $uomoId, ?array $p, \DateTimeImmutable $ora, float $calo): ?bool
    {
        $u = Database::first('SELECT * FROM uomini WHERE id = ? AND stato <> \'sparito\' FOR UPDATE', [$uomoId]);
        if ($u === null) {
            return null;
        }
        $fino = Clock::daDb((string) $u['pagato_fino_a']);
        $ore = $fino === null ? 1 : (int) floor(($ora->getTimestamp() - $fino->getTimestamp()) / 3600);
        if ($ore < 1) { return null; }
        $dovuto = $ore * (int) $u['stipendio_ora'];

        if ($p !== null && (int) $p['contante'] >= $dovuto) {
            Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?',
                [$dovuto, (int) $u['personaggio_id']]);
            Database::run(
                'UPDATE uomini SET pagato_fino_a = DATE_ADD(pagato_fino_a, INTERVAL ? HOUR),
                        lealta = LEAST(100, lealta + ?) WHERE id = ?',
                [$ore, min(2.0, $ore * 0.15), (int) $u['id']]
            );
            Contabilita::segna((int) $u['personaggio_id'], 'stipendi', 'sporco', -$dovuto,
                $u['nome'] . ', ' . $ore . ' ore');
            // Pagare puntuale è amministrare, e amministrare insegna.
            self::cresci((int) $u['personaggio_id'], 'organizzazione', min(1.0, $ore * 0.15));
            self::reputazione((int) $u['personaggio_id'], min(0.4, $ore * 0.06));
            return true;
        }

        Database::run('UPDATE uomini SET lealta = GREATEST(0, lealta - ?), pagato_fino_a = ? WHERE id = ?',
            [$calo * $ore, Clock::perDb($ora), (int) $u['id']]);
        Contabilita::segna((int) $u['personaggio_id'], 'stipendi', 'sporco', 0,
            'non pagato: ' . $u['nome'] . ' (' . $ore . ' ore)');
        return false;
    }

    /**
     * Quando il padrone finisce dentro, gli uomini se ne accorgono. E chi era
     * già poco leale può decidere che è il momento di parlare — è il pentito,
     * e senza organico non poteva esistere.
     */
    public static function dopoArresto(int $personaggioId): void
    {
        $calo = (float) GameConfig::int('organico.lealta_calo_arresti', 12);
        Database::run('UPDATE uomini SET lealta = GREATEST(0, lealta - ?) WHERE personaggio_id = ?',
            [$calo, $personaggioId]);

        $soglia = (float) GameConfig::int('organico.pentito_soglia', 35);
        foreach (Database::all('SELECT * FROM uomini WHERE personaggio_id = ? AND lealta < ? AND stato <> \'sparito\'',
            [$personaggioId, $soglia]) as $u) {
            Database::run('UPDATE uomini SET stato = \'sparito\' WHERE id = ?', [(int) $u['id']]);
            Legge::prove($personaggioId, (float) GameConfig::int('organico.pentito_prove', 35),
                'qualcuno dei tuoi ha cominciato a parlare');
            Legge::segnale($personaggioId, 'pentito',
                $u['nome'] . ' ha chiesto di parlare con un magistrato. Ha parlato per tre ore.', 5);
        }
    }

    // --- Crescita degli attributi ------------------------------------------------

    /** Fa crescere un attributo per l'uso che se n'è fatto. */
    public static function cresci(int $personaggioId, string $attributo, float $peso = 1.0): void
    {
        if (!in_array($attributo, ['trattativa', 'fiuto', 'sangue_freddo', 'organizzazione', 'credito'], true)) {
            return;
        }
        $p = Database::first("SELECT {$attributo} v FROM personaggi WHERE id = ?", [$personaggioId]);
        if ($p === null) { return; }

        $d = Crescita::passo((float) $p['v'],
            (float) GameConfig::get('attributi.crescita', 1.2),
            (float) GameConfig::int('attributi.scala', 20), $peso);
        if ($d <= 0.0) { return; }

        Database::run("UPDATE personaggi SET {$attributo} = LEAST(100, {$attributo} + ?) WHERE id = ?",
            [round($d, 4), $personaggioId]);
    }

    /** La reputazione si muove piano, e in due direzioni indipendenti. */
    public static function reputazione(int $personaggioId, float $rispetto = 0.0, float $timore = 0.0): void
    {
        if ($rispetto === 0.0 && $timore === 0.0) { return; }
        Database::run(
            'UPDATE personaggi SET rispetto = LEAST(100, GREATEST(0, rispetto + ?)),
                    timore = LEAST(100, GREATEST(0, timore + ?)) WHERE id = ?',
            [round($rispetto, 4), round($timore, 4), $personaggioId]
        );
    }
}
