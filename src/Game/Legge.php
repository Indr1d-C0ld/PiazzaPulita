<?php

declare(strict_types=1);

namespace App\Game;

use App\Core\Database;
use App\Core\GameConfig;
use App\Sim\Calore;
use App\Sim\Clock;
use App\Sim\Crescita;
use App\Sim\Rng;
use App\Sim\Viaggio;

/**
 * La legge: il calore, i controlli, i fascicoli, il carcere.
 *
 * Non è un avversario che compare a caso: è un processo con memoria. Il calore
 * sale con quello che muovi, si vede sempre, e decade se stai fermo. Superata
 * una soglia si apre un **fascicolo** — un inquirente con un nome, che accumula
 * prove nel tempo vero e lascia segnali lungo la strada. Il blitz non è una
 * sorpresa: è la fine di qualcosa che si vedeva arrivare e contro cui si poteva
 * fare qualcosa.
 */
final class Legge
{
    private const GRADI = ['Commissario', 'Vice questore', 'Sostituto procuratore', 'Maresciallo', 'Capitano', 'Colonnello'];
    private const COGNOMI = [
        'Amato', 'Barbagallo', 'Cortese', 'De Luca', 'Ferrante', 'Gallo', 'Iacobelli', 'Lombardo',
        'Mancuso', 'Nardi', 'Orlando', 'Patanè', 'Quaranta', 'Rizzo', 'Salvatore', 'Terranova',
        'Vitale', 'Zappalà', 'Bellini', 'Casadei', 'Donadoni', 'Esposito',
    ];

    // --- Calore ---------------------------------------------------------------

    /** @param array<string,mixed> $p */
    public static function calorePersonale(array $p): float
    {
        $da = Clock::daDb($p['calore_agg_a'] === null ? null : (string) $p['calore_agg_a']);
        return Calore::decaduto((float) $p['calore'],
            max(0, Clock::adesso()->getTimestamp() - ($da?->getTimestamp() ?? Clock::adesso()->getTimestamp())),
            (float) GameConfig::int('calore.dimezzamento_ore', 12));
    }

    /** @param array<string,mixed> $piazza */
    public static function calorePiazza(array $piazza): float
    {
        $da = Clock::daDb(($piazza['calore_agg_a'] ?? null) === null ? null : (string) $piazza['calore_agg_a']);
        return Calore::decaduto((float) ($piazza['calore'] ?? 0),
            max(0, Clock::adesso()->getTimestamp() - ($da?->getTimestamp() ?? Clock::adesso()->getTimestamp())),
            (float) GameConfig::int('calore.dimezzamento_piazza', 6));
    }

    /**
     * Scalda il giocatore e la piazza per un'operazione.
     *
     * Le due cose sono separate apposta: il calore personale ti segue ovunque,
     * quello della piazza resta lì e lo subisce chiunque ci passi. Si può
     * **bruciare una piazza** a un rivale senza sparare un colpo, ed è una
     * forma di conflitto che in F6 diventerà deliberata.
     */
    public static function scalda(int $personaggioId, int $piazzaId, int $valore, int $rischioBene): float
    {
        $soglia = GameConfig::int('calore.soglia_valore', 1_000_000);
        $esp    = (float) GameConfig::get('calore.esponente', 1.5);
        $g = Calore::daOperazione($valore, $soglia, $esp, $rischioBene);
        if ($g <= 0.0) {
            return 0.0;
        }

        // Il calore si scrive decaduto più il nuovo, cioè come valore assoluto:
        // va letto e scritto con le righe bloccate, o il battito che lo fa
        // decadere nello stesso istante lo riscrive col valore di prima e il
        // calore di questa operazione sparisce.
        Fila::per($personaggioId, static function (?array $p) use ($piazzaId, $g): void {
            if ($p !== null) {
                Database::run('UPDATE personaggi SET calore = ?, calore_agg_a = ? WHERE id = ?',
                    [round(self::calorePersonale($p) + $g, 3), Clock::perDb(), (int) $p['id']]);
            }
            $z = Database::first('SELECT calore, calore_agg_a FROM piazze WHERE id = ? FOR UPDATE', [$piazzaId]);
            if ($z !== null) {
                Database::run('UPDATE piazze SET calore = ?, calore_agg_a = ? WHERE id = ?',
                    [round(self::calorePiazza($z) + $g, 3), Clock::perDb(), $piazzaId]);
            }
        });
        return $g;
    }

    // --- Controlli -------------------------------------------------------------

    /**
     * Tira per un controllo, leggendo da sé lo stato aggiornato.
     *
     * Legge le righe fresche invece di ricevere quelle in memoria perché il
     * calore è appena cambiato: `scalda()` scrive, e la copia che il chiamante
     * ha in mano è già vecchia di una riga.
     *
     * @return array{controllo:bool,sequestrato?:int,valore?:int,messaggio?:string}
     */
    public static function controllaIn(int $personaggioId, int $piazzaId): array
    {
        $p = Database::first('SELECT * FROM personaggi WHERE id = ?', [$personaggioId]);
        $z = Database::first('SELECT * FROM piazze WHERE id = ?', [$piazzaId]);
        if ($p === null || $z === null) {
            return ['controllo' => false];
        }
        return self::controlla($p, $z);
    }

    /**
     * Tira per un controllo dopo un'operazione in piazza.
     *
     * @param array<string,mixed> $p @param array<string,mixed> $piazza
     * @return array{controllo:bool,sequestrato?:int,valore?:int,messaggio?:string}
     */
    public static function controlla(array $p, array $piazza): array
    {
        if (self::inCarcere($p)) {
            return ['controllo' => false];
        }
        $eff = Organico::effetti((int) $p['id']);
        $rischio = self::rischioControllo($p, $piazza, $eff);

        if (random_int(1, 1_000_000) / 1_000_000 >= $rischio) {
            return ['controllo' => false];
        }

        $carico = Listino::carico((int) $p['id']);
        if ($carico === []) {
            self::segnale((int) $p['id'], 'controllo',
                'Documenti, in ' . $piazza['nome'] . '. Non avevi niente addosso, e ti hanno lasciato andare.', 1);
            return ['controllo' => true, 'sequestrato' => 0, 'valore' => 0,
                    'messaggio' => 'Controllo in strada. Tasche vuote, nessun problema.'];
        }

        // Si perde una parte del carico, non tutto: un controllo di strada non
        // è una perquisizione. Quanto, dipende da quanto scotti — e da chi hai
        // dietro: una guardia in gamba ne salva una parte.
        $quota = min(0.9, 0.25 + self::calorePersonale($p) / 400.0);
        $quota *= (1.0 - min(0.40, $eff['guardia'] * 0.45));
        [$unita, $valore] = self::sequestraCarico((int) $p['id'], $quota);

        // Cavarsela insegna qualcosa: il sangue freddo cresce quando serve.
        Organico::cresci((int) $p['id'], 'sangue_freddo', 1.5);
        self::prove((int) $p['id'], 6.0, 'un controllo in strada finito male');
        self::segnale((int) $p['id'], 'controllo',
            'Fermato in ' . $piazza['nome'] . '. Ti hanno preso ' . quantita($unita) . ' unità.', 3);

        return ['controllo' => true, 'sequestrato' => $unita, 'valore' => $valore,
                'messaggio' => 'Controllo in strada: ti hanno preso ' . quantita($unita)
                    . ' unità, per un valore di ' . lire($valore) . '.'];
    }

    /**
     * La probabilità di un controllo dopo un'operazione, per QUESTO giocatore
     * in QUESTA piazza. Sta in un punto solo perché la usa anche la pagina del
     * fascicolo: prima la pagina la ricalcolava per conto suo senza sangue
     * freddo e senza vedette, e mostrava un rischio più alto di quello vero —
     * chi pagava una vedetta non vedeva a cosa servisse.
     *
     * @param array<string,mixed> $p @param array<string,mixed> $piazza
     * @param array<string,mixed>|null $eff Organico::effetti(), se già in mano
     */
    public static function rischioControllo(array $p, array $piazza, ?array $eff = null): float
    {
        $rischio = Calore::rischioControllo(
            (float) GameConfig::get('legge.controllo_base', 0.02),
            (int) $piazza['polizia'],
            self::calorePiazza($piazza),
            self::calorePersonale($p),
            (int) $p['profilo'],
        );
        // Il sangue freddo e una vedette in quella piazza abbassano il rischio.
        // Sono due cose diverse: uno è quello che sei diventato, l'altra è
        // qualcuno che hai messo lì e che paghi.
        $rischio = Crescita::rischioConSangueFreddo($rischio, (float) ($p['sangue_freddo'] ?? 0));
        $eff ??= Organico::effetti((int) $p['id']);
        $vedetta = $eff['vedette'][(int) $piazza['id']] ?? 0.0;
        return $rischio * (1.0 - min(0.45, $vedetta * 0.5));
    }

    /**
     * La probabilità di un posto di blocco viaggiando col proprio mezzo.
     *
     * @param array<string,mixed> $p @param array<string,mixed> $mezzo
     */
    public static function rischioBlocco(array $p, array $mezzo): float
    {
        return Crescita::rischioConSangueFreddo(Calore::rischioBlocco(
            (float) GameConfig::get('legge.blocco_base', 0.035),
            (int) $mezzo['vistoso'],
            Listino::ingombroUsato((int) $p['id']),
            self::calorePersonale($p),
            (int) $p['profilo'],
            true,
        ), (float) ($p['sangue_freddo'] ?? 0));
    }

    /**
     * Tira per un posto di blocco su una tratta.
     *
     * Solo con un mezzo proprio: in treno o a piedi non c'è niente da fermare.
     * È il prezzo nascosto dell'auto, che per tutto il resto conviene.
     *
     * @param array<string,mixed> $p @param array<string,mixed>|null $mezzo
     * @return array{blocco:bool,sequestrato?:int,valore?:int,messaggio?:string}
     */
    public static function postoDiBlocco(array $p, ?array $mezzo, string $mezzoScelto): array
    {
        if ($mezzoScelto !== 'auto' || $mezzo === null) {
            return ['blocco' => false];
        }
        $rischio = self::rischioBlocco($p, $mezzo);
        if (random_int(1, 1_000_000) / 1_000_000 >= $rischio) {
            return ['blocco' => false];
        }

        [$unita, $valore] = self::sequestraCarico((int) $p['id'], 1.0);
        Organico::cresci((int) $p['id'], 'sangue_freddo', 2.0);
        self::prove((int) $p['id'], 10.0, 'un posto di blocco');
        self::segnale((int) $p['id'], 'blocco',
            'Posto di blocco. Hanno guardato nel bagagliaio e hanno trovato tutto.', 4);

        return ['blocco' => true, 'sequestrato' => $unita, 'valore' => $valore,
                'messaggio' => 'Posto di blocco: hanno svuotato il bagagliaio. Persi ' . quantita($unita)
                    . ' unità, ' . lire($valore) . '.'];
    }

    /** @return array{0:int,1:int} unità e valore di costo perduti */
    private static function sequestraCarico(int $personaggioId, float $quota): array
    {
        // Carico letto e svuotato col personaggio in fila: una vendita nello
        // stesso istante poteva far scendere la quantità sotto quella da
        // togliere, e la colonna senza segno faceva fallire tutto con un 500.
        return Fila::per($personaggioId, static fn() => self::sequestraInFila($personaggioId, $quota));
    }

    /** @return array{0:int,1:int} */
    private static function sequestraInFila(int $personaggioId, float $quota): array
    {
        $unita = 0; $valore = 0;
        foreach (Listino::carico($personaggioId) as $c) {
            $q = (int) ceil($c['quantita'] * $quota);
            if ($q <= 0) {
                continue;
            }
            $costo = $c['medio'] * $q;
            $unita += $q;
            $valore += $costo;
            if ($q >= $c['quantita']) {
                Database::run('DELETE FROM carico WHERE personaggio_id = ? AND bene_id = ?',
                    [$personaggioId, (int) $c['bene']['id']]);
            } else {
                Database::run(
                    'UPDATE carico SET quantita = quantita - ?, costo_totale = GREATEST(0, costo_totale - ?)
                      WHERE personaggio_id = ? AND bene_id = ?',
                    [$q, $costo, $personaggioId, (int) $c['bene']['id']]
                );
            }
        }
        if ($valore > 0) {
            Contabilita::segna($personaggioId, 'sequestro', 'sporco', 0,
                'sequestrate ' . quantita($unita) . ' unità, valevano ' . lire($valore));
        }
        return [$unita, $valore];
    }

    // --- Fascicoli --------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function fascicolo(int $personaggioId): ?array
    {
        return Database::first(
            "SELECT * FROM fascicoli WHERE personaggio_id = ? AND stato = 'aperto' ORDER BY id DESC LIMIT 1",
            [$personaggioId]
        );
    }

    /** Aggiunge prove al fascicolo aperto, aprendone uno se serve. */
    public static function prove(int $personaggioId, float $quante, string $perche): void
    {
        $f = self::fascicolo($personaggioId) ?? self::apri($personaggioId, $perche);
        Database::run('UPDATE fascicoli SET prove = LEAST(999, prove + ?) WHERE id = ?',
            [round($quante, 2), (int) $f['id']]);
    }

    /** @return array<string,mixed> */
    private static function apri(int $personaggioId, string $perche): array
    {
        $rng = new Rng((int) (microtime(true) * 1000) ^ $personaggioId);
        $corpo = ['questura', 'carabinieri', 'finanza'][$rng->intero(0, 2)];
        $nome = self::GRADI[$rng->intero(0, count(self::GRADI) - 1)] . ' '
              . self::COGNOMI[$rng->intero(0, count(self::COGNOMI) - 1)];

        // Un fascicolo aperto per persona, e lo garantisce il database (la
        // colonna `aperto` della migrazione 0014): due fatti nello stesso
        // istante — un controllo in strada mentre il battito apre per troppo
        // calore — ne aprivano due, e due fascicoli maturano due blitz.
        $n = Database::run(
            'INSERT IGNORE INTO fascicoli (personaggio_id, inquirente, corpo, agg_a) VALUES (?, ?, ?, ?)',
            [$personaggioId, $nome, $corpo, Clock::perDb()]
        )->rowCount();
        if ($n === 0) {
            $gia = self::fascicolo($personaggioId);
            if ($gia !== null) {
                return $gia;
            }
        }
        $id = Database::lastInsertId();

        self::segnale($personaggioId, 'fascicolo',
            'Qualcuno ha aperto una cartella col tuo nome sopra. Non sai chi, non ancora.', 2);

        return ['id' => $id, 'prove' => 0.0, 'inquirente' => $nome, 'corpo' => $corpo];
    }

    /**
     * Fa maturare i fascicoli aperti, emette i segnali, esegue i blitz.
     *
     * @return array{cresciuti:int,blitz:int,aperti:int}
     */
    public static function avanzaFascicoli(): array
    {
        $ora = Clock::adesso();
        $tasso = (float) GameConfig::get('legge.prove_ora', 2.0);
        $soglia = (float) GameConfig::int('legge.prove_blitz', 100);
        $cresciuti = 0; $blitz = 0; $aperti = 0;

        // Prima: si apre un fascicolo a chi scotta abbastanza e non ne ha uno.
        // È il passaggio che fa esistere tutto il resto — senza, la soglia
        // sarebbe una riga di configurazione che nessuno legge, e il calore un
        // numero senza conseguenze.
        $sogliaApertura = (float) GameConfig::int('legge.soglia_fascicolo', 40);
        foreach (Database::all(
            "SELECT p.id, p.calore, p.calore_agg_a FROM personaggi p
              WHERE p.calore > 0 AND p.carcere_fino_a IS NULL
                AND NOT EXISTS (SELECT 1 FROM fascicoli f
                                 WHERE f.personaggio_id = p.id AND f.stato = 'aperto')"
        ) as $r) {
            if (self::calorePersonale($r) < $sogliaApertura) {
                continue;
            }
            self::apri((int) $r['id'], 'troppo calore addosso');
            $aperti++;
        }

        foreach (Database::all(
            "SELECT f.*, p.calore, p.calore_agg_a, p.profilo, p.carcere_fino_a
               FROM fascicoli f JOIN personaggi p ON p.id = f.personaggio_id
              WHERE f.stato = 'aperto'"
        ) as $f) {
            $da = Clock::daDb((string) $f['agg_a'])?->getTimestamp() ?? $ora->getTimestamp();
            $calore = self::calorePersonale($f);
            $nuove = Calore::prove($calore, max(0, $ora->getTimestamp() - $da), $tasso);
            $prove = (float) $f['prove'] + $nuove;

            // Si AGGIUNGE, non si riscrive: fra la lettura qui sopra e questa
            // riga un avvocato può aver smontato trenta prove, o un controllo
            // averne portate sei. Riscrivere il valore letto le cancellava — e
            // l'avvocato era stato pagato quattro milioni per niente.
            Database::run('UPDATE fascicoli SET prove = LEAST(999, GREATEST(0, prove + ?)), agg_a = ? WHERE id = ?',
                [round($nuove, 2), Clock::perDb($ora), (int) $f['id']]);
            $prove = (float) (Database::first('SELECT prove FROM fascicoli WHERE id = ?', [(int) $f['id']])['prove'] ?? $prove);
            $cresciuti++;

            self::segnaliDiSoglia((int) $f['personaggio_id'], (float) $f['prove'], $prove, (string) $f['inquirente']);

            if ($prove >= $soglia && $f['carcere_fino_a'] === null) {
                self::blitz((int) $f['personaggio_id'], (int) $f['id'], $prove);
                $blitz++;
                continue;
            }

            // Un fascicolo che smette di crescere si archivia: il calore è
            // decaduto, l'avvocato ha lavorato, o semplicemente si è stati
            // fermi. È la ricompensa per aver smesso in tempo, ed è l'unica
            // cosa che rende «stare fermi» una strategia invece che una resa.
            if ($prove <= 0.5 && $calore < 5.0) {
                Database::run("UPDATE fascicoli SET stato = 'archiviato', chiuso_at = NOW() WHERE id = ?",
                    [(int) $f['id']]);
                self::segnale((int) $f['personaggio_id'], 'fascicolo',
                    'Il fascicolo di ' . $f['inquirente'] . ' è finito in fondo a un armadio. Per adesso.', 1);
            }
        }
        return ['cresciuti' => $cresciuti, 'blitz' => $blitz, 'aperti' => $aperti];
    }

    /** I segnali compaiono passando certe soglie, una volta sola. */
    private static function segnaliDiSoglia(int $personaggioId, float $prima, float $dopo, string $chi): void
    {
        $tappe = [
            25 => ['Una macchina che non avevi mai visto, parcheggiata dove parcheggi tu.', 2],
            50 => ['Un cliente che faceva troppe domande. Non era un cliente.', 3],
            75 => ['Il telefono fa un rumore che prima non faceva. Forse è solo il telefono.', 4],
            90 => ['Qualcuno ha chiesto di te al bar. Ha lasciato detto che ripassa.', 5],
        ];
        foreach ($tappe as $s => [$testo, $gravita]) {
            if ($prima < $s && $dopo >= $s) {
                self::segnale($personaggioId, 'fascicolo', $testo, $gravita);
            }
        }
    }

    /** Il blitz: perquisizione, sequestro, arresto. */
    private static function blitz(int $personaggioId, int $fascicoloId, float $prove): void
    {
        // Col personaggio bloccato: il sequestro è una quota del contante di
        // ADESSO, e il carico non deve poter cambiare mentre lo si svuota.
        Fila::per($personaggioId, static function (?array $p) use ($personaggioId, $fascicoloId, $prove): void {
            if ($p !== null && !self::inCarcere($p)) {
                self::eseguiBlitz($p, $personaggioId, $fascicoloId, $prove);
            }
        });
    }

    /** @param array<string,mixed> $p la riga bloccata */
    private static function eseguiBlitz(array $p, int $personaggioId, int $fascicoloId, float $prove): void
    {

        [$unita, $valore] = self::sequestraCarico($personaggioId, 1.0);

        // I depositi nella città dove sei: quelli li trovano. Gli altri no —
        // non ancora, e questa è la ragione per cui conviene distribuire.
        $citta = Database::first('SELECT citta_id FROM piazze WHERE id = ?', [(int) $p['piazza_id']]);
        $depositiPersi = 0;
        if ($citta !== null) {
            foreach (Database::all(
                'SELECT d.id FROM depositi d JOIN piazze z ON z.id = d.piazza_id
                  WHERE d.personaggio_id = ? AND z.citta_id = ?',
                [$personaggioId, (int) $citta['citta_id']]
            ) as $d) {
                Database::run('DELETE FROM depositi WHERE id = ?', [(int) $d['id']]);
                $depositiPersi++;
            }
        }

        // Il contante sporco che gira: quello se ne va. Il pulito no — è
        // intestato, e serve un altro tipo di processo per toccarlo.
        $quota = (float) GameConfig::get('legge.sequestro_quota', 0.60);
        $presi = (int) round((int) $p['contante'] * $quota);
        if ($presi > 0) {
            Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$presi, $personaggioId]);
            Contabilita::segna($personaggioId, 'sequestro', 'sporco', -$presi, 'sequestro in perquisizione');
        }

        $ore = GameConfig::int('legge.carcere_base_ore', 4)
             + (int) round($prove * (float) GameConfig::get('legge.carcere_per_prova', 0.04))
             + (int) $p['profilo'] * GameConfig::int('legge.carcere_per_profilo', 3);

        Database::run(
            'UPDATE personaggi SET carcere_fino_a = DATE_ADD(?, INTERVAL ? HOUR),
                    arresti = arresti + 1, profilo = profilo + 1, calore = 0, calore_agg_a = ?
              WHERE id = ?',
            [Clock::perDb(), $ore, Clock::perDb(), $personaggioId]
        );
        Database::run("UPDATE fascicoli SET stato = 'eseguito', chiuso_at = NOW() WHERE id = ?", [$fascicoloId]);
        // La longevità riparte da adesso: è la graduatoria di chi rischia e non
        // si fa prendere, e adesso ti hanno preso.
        Classifica::azzeraLongevita($personaggioId);

        // Finire dentro fa due cose alla reputazione: il timore sale — hai
        // retto — e gli uomini si spaventano. Chi era già poco leale parla.
        Organico::reputazione($personaggioId, 0.0, 6.0);
        Organico::dopoArresto($personaggioId);

        self::segnale($personaggioId, 'blitz', sprintf(
            'Sono venuti all\'alba. Sequestrate %s unità e %s in contanti%s. %d ore dentro.',
            quantita($unita), lire($presi),
            $depositiPersi > 0 ? ', e hanno trovato ' . quantita($depositiPersi) . ' depositi' : '',
            $ore
        ), 5);
    }

    // --- Difendersi ------------------------------------------------------------

    /** @return array{ok:bool,error?:string,prove?:float} */
    public static function avvocato(int $personaggioId): array
    {
        $f = self::fascicolo($personaggioId);
        if ($f === null) {
            return ['ok' => false, 'error' => 'Non hai un fascicolo aperto. Un avvocato serve quando serve.'];
        }
        $prezzo = GameConfig::int('legge.avvocato_prezzo', 4_000_000);
        $giu = (float) GameConfig::int('legge.avvocato_prove', 30);
        // Saldo controllato e addebitato sotto lo stesso lucchetto: due clic
        // insieme passavano entrambi il controllo e mandavano il pulito sotto
        // zero.
        return Fila::per($personaggioId, static function (?array $p) use ($personaggioId, $f, $prezzo, $giu): array {
            if ($p === null || (int) $p['pulito'] < $prezzo) {
                return ['ok' => false, 'error' => 'La parcella è ' . lire($prezzo) . ', in denaro pulito. '
                    . 'Un penalista non lavora per contanti in una busta.'];
            }
            Database::run('UPDATE personaggi SET pulito = pulito - ? WHERE id = ?', [$prezzo, $personaggioId]);
            Database::run('UPDATE fascicoli SET prove = GREATEST(0, prove - ?) WHERE id = ?', [$giu, (int) $f['id']]);
            Contabilita::segna($personaggioId, 'avvocato', 'pulito', -$prezzo, 'parcella');
            self::segnale($personaggioId, 'difesa', 'Il tuo avvocato ha smontato qualcosa. Non tutto.', 1);
            $ora = Database::first('SELECT prove FROM fascicoli WHERE id = ?', [(int) $f['id']]);
            return ['ok' => true, 'prove' => (float) ($ora['prove'] ?? 0)];
        });
    }

    /** @return array{ok:bool,error?:string,andata?:bool} */
    public static function bustarella(int $personaggioId): array
    {
        $f = self::fascicolo($personaggioId);
        if ($f === null) {
            return ['ok' => false, 'error' => 'Non c\'è niente da comprare: nessuno si sta occupando di te.'];
        }
        $prezzo = GameConfig::int('legge.bustarella_prezzo', 2_500_000);
        $pagata = Fila::per($personaggioId, static function (?array $p) use ($personaggioId, $prezzo): bool {
            if ($p === null || (int) $p['contante'] < $prezzo) {
                return false;
            }
            Database::run('UPDATE personaggi SET contante = contante - ? WHERE id = ?', [$prezzo, $personaggioId]);
            Contabilita::segna($personaggioId, 'bustarella', 'sporco', -$prezzo, 'a qualcuno che forse ascolta');
            return true;
        });
        if (!$pagata) {
            return ['ok' => false, 'error' => 'Servono ' . lire($prezzo) . ' in contanti.'];
        }

        // Il rischio è il punto: comprare qualcuno che non si lascia comprare
        // non è denaro sprecato, è denaro che si trasforma in prove.
        $male = random_int(1, 1000) / 1000 < (float) GameConfig::get('legge.bustarella_rischio', 0.22);
        if ($male) {
            Database::run('UPDATE fascicoli SET prove = LEAST(999, prove + ?) WHERE id = ?', [15, (int) $f['id']]);
            self::segnale($personaggioId, 'difesa',
                'Ha preso la busta, l\'ha guardata, e l\'ha messa in una cartella. La tua.', 5);
            return ['ok' => true, 'andata' => false];
        }

        Database::run('UPDATE fascicoli SET prove = GREATEST(0, prove - ?) WHERE id = ?',
            [GameConfig::int('legge.bustarella_prove', 18), (int) $f['id']]);
        self::segnale($personaggioId, 'difesa', 'Un paio di fogli non sono mai stati protocollati.', 1);
        return ['ok' => true, 'andata' => true];
    }

    // --- Carcere ----------------------------------------------------------------

    /** @param array<string,mixed> $p */
    public static function inCarcere(array $p): bool
    {
        $fino = Clock::daDb(($p['carcere_fino_a'] ?? null) === null ? null : (string) $p['carcere_fino_a']);
        return $fino !== null && $fino > Clock::adesso();
    }

    /** @param array<string,mixed> $p */
    public static function mancanoAlleggerimento(array $p): int
    {
        $fino = Clock::daDb(($p['carcere_fino_a'] ?? null) === null ? null : (string) $p['carcere_fino_a']);
        return $fino === null ? 0 : max(0, $fino->getTimestamp() - Clock::adesso()->getTimestamp());
    }

    /** Scarcera chi ha finito. @return int quanti */
    public static function scarcera(): int
    {
        $n = Database::run(
            'UPDATE personaggi SET carcere_fino_a = NULL WHERE carcere_fino_a IS NOT NULL AND carcere_fino_a <= ?',
            [Clock::perDb()]
        )->rowCount();
        return $n;
    }

    // --- Segnali -----------------------------------------------------------------

    public static function segnale(int $personaggioId, string $genere, string $testo, int $gravita = 1): void
    {
        try {
            Database::run(
                'INSERT INTO segnali (personaggio_id, genere, testo, gravita, fatto_at) VALUES (?, ?, ?, ?, ?)',
                [$personaggioId, $genere, mb_substr($testo, 0, 220), max(1, min(5, $gravita)), Clock::perDb()]
            );
        } catch (\Throwable $e) {
            logger('segnale non registrato: ' . $e->getMessage(), 'warning');
        }
    }

    /** @return list<array<string,mixed>> */
    public static function segnali(int $personaggioId, int $quanti = 30): array
    {
        return Database::all(
            'SELECT * FROM segnali WHERE personaggio_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $quanti)),
            [$personaggioId]
        );
    }

    /** Il calore decade da solo: qui si scrive, così le pagine non devono. */
    public static function raffredda(): int
    {
        $n = 0;
        // Scrive solo se nessuno ha toccato la riga dopo la lettura: chi l'ha
        // toccata (`scalda`) l'ha già portata ad adesso, col calore nuovo
        // dentro. Senza questa condizione il battito riscriveva il valore
        // letto prima, e il calore dell'ultima operazione spariva.
        foreach (Database::all('SELECT id, calore, calore_agg_a FROM personaggi WHERE calore > 0') as $r) {
            $n += Database::run('UPDATE personaggi SET calore = ?, calore_agg_a = ? WHERE id = ? AND calore_agg_a <=> ?',
                [round(self::calorePersonale($r), 3), Clock::perDb(), (int) $r['id'], $r['calore_agg_a']])->rowCount();
        }
        foreach (Database::all('SELECT id, calore, calore_agg_a FROM piazze WHERE calore > 0') as $r) {
            $n += Database::run('UPDATE piazze SET calore = ?, calore_agg_a = ? WHERE id = ? AND calore_agg_a <=> ?',
                [round(self::calorePiazza($r), 3), Clock::perDb(), (int) $r['id'], $r['calore_agg_a']])->rowCount();
        }
        return $n;
    }
}
