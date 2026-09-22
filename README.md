# Piazza Pulita

*Browsergame di commercio, rischio e territorio. **Italia, 1980-1995**, in lire.*

![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777bb4) ![MariaDB](https://img.shields.io/badge/MariaDB-11-003545) ![GPL-3.0](https://img.shields.io/badge/licenza-GPL--3.0-a5281d) ![stato](https://img.shields.io/badge/stato-completo%20(F0--F8)-2f6b46)

Trasposizione persistente e multigiocatore di **Drug Wars** (John E. Dell, 1984), riscritta
da zero: stesse ossa, altro corpo. Nove città italiane, cinquantuno piazze, dieci merci,
un mercato **unico e condiviso** che si muove per quello che fanno i giocatori — e che non
riparte mai.

> Compri dove costa poco e vendi dove pagano. Il gioco è tutto qui, e non è affatto
> semplice: **quando compri, il prezzo sale per chi viene dopo di te**; quando vendi troppo,
> crolla per tutti. La ricchezza non è una fontana, è una torta.

---

## Indice

- [L'idea, in tre minuti](#lidea-in-tre-minuti)
- [Le quattro decisioni fondanti](#le-quattro-decisioni-fondanti)
- [Il mondo](#il-mondo)
- [Il mercato: il cuore del gioco](#il-mercato-il-cuore-del-gioco)
- [Il denaro: sporco e pulito](#il-denaro-sporco-e-pulito)
- [La legge](#la-legge)
- [Il personaggio](#il-personaggio)
- [Gli altri giocatori](#gli-altri-giocatori)
- [Traguardi, classifiche, albo d'oro](#traguardi-classifiche-albo-doro)
- [Com'è fatto dentro](#comè-fatto-dentro)
- [Installazione](#installazione)
- [Prove e strumenti](#prove-e-strumenti)
- [Le fonti originali](#le-fonti-originali)
- [Licenza](#licenza)

---

## L'idea, in tre minuti

Nell'originale del 1984 i prezzi si rigeneravano **a caso a ogni turno**, e in `dopewars`
(l'unica versione multigiocatore storica) c'era un difetto più sottile: i listini erano
generati *per giocatore*, quindi due persone nella stessa piazza vedevano numeri diversi e
non si influenzavano affatto. Andava benissimo per una partita da venti minuti.

Qui il mondo non riparte, quindi quella roulette non regge: il mercato è **uno solo, vero e
condiviso**. Ogni coppia (piazza, merce) ha una **giacenza** e una **capacità di
assorbimento** che esistono davvero, e il prezzo nasce da lì:

```
compri a   P₀ · D · (S_eq / S)^ε        vendi a   P₀ · D · (A / A_eq)^ζ
```

Gli ordini si **integrano lungo la curva** in forma chiusa: comprare cento pezzi non costa
cento volte il primo, costa l'integrale — perché mentre compri, alzi il prezzo a te stesso.
Da questo discende la proprietà che tiene in piedi tutto il resto: **il mondo ha un reddito
massimo orario finito e noto** (`mondo.reddito_orario`, oggi 16 milioni di lire l'ora).
Nessuno può estrarre più di quello, perché non esiste altro da cui estrarlo.

È la risposta strutturale al mondo eterno: niente esponenziale, niente inflazione, niente
azzeramenti. La ricchezza è una torta finita da contendersi — e questo rende il
multigiocatore vero ancora prima che ci si possa mettere le mani addosso.

## Le quattro decisioni fondanti

| | |
|---|---|
| **1. Ambientazione** | Italia 1980-1995, in lire. Non un'America generica: questure e carabinieri, il bar dell'amico, il totonero, il cambiavalute di Chiasso. |
| **2. Tempo** | Reale e continuo. Niente turni: il mercato respira da cron, i viaggi durano ore e minuti veri, i debiti crescono mentre dormi. |
| **3. Mondo** | Eterno. Nessuna stagione, nessun azzeramento, nessun permadeath: chi ti prende non ti cancella. |
| **4. PvP** | Pieno, alla `dopewars`. Chiunque può colpire chiunque — e chi lo fa paga il conto più caro del gioco. |

Le decisioni 3 e 4 insieme farebbero saltare qualunque economia. Reggono per due
contrappesi dichiarati: il **tetto di reddito** (la torta finita) e il **pubblico nemico
numero N**, il profilo criminale ereditato dal door BBS del 1993, che sale con la violenza
e **non scende mai col tempo**.

---

## Il mondo

**Nove città, cinquantuno piazze**, con coordinate geografiche vere e due livelli di
distanza: fra città si ragiona in ore, fra piazze in minuti.

| Città | Carattere | Piazze | | Città | Carattere | Piazze |
|---|---|---|---|---|---|---|
| Milano | consumo | 6 | | Napoli | porto | 7 |
| Torino | consumo | 6 | | Bari | porto | 5 |
| Roma | consumo | 7 | | Palermo | porto | 5 |
| Genova | porto | 5 | | Bologna | snodo | 5 |
| Catania | snodo | 5 | | | | |

I **porti** sono dove la roba entra nel paese, le città di **consumo** dove qualcuno la
paga: la rotta più semplice del gioco è quella fra i due. Ogni piazza ha un tipo, che ne
decide il carattere e quanta polizia gira:

| Tipo | Quante | Polizia media | Com'è |
|---|---|---|---|
| Periferia | 19 | 15% | Palazzoni, poche divise, la roba arriva prima che altrove |
| Popolare | 17 | 32% | Strada viva, occhi dappertutto, nessuno che parla |
| Benestante | 10 | 71% | Vetrine e portinerie: si paga bene e si viene guardati male |
| Universitaria | 3 | 50% | Poca paura, e prezzi che reggono solo il piccolo |
| Stazione | 2 | 68% | Transito puro, e una pattuglia ogni venti metri |

**Sei modi di spostarsi**, con tempi e costi d'epoca — a piedi, mezzi pubblici, taxi,
pullman, treno, aereo (solo oltre i 300 km) — più il proprio veicolo, che non ha attese e
costa solo benzina. Milano-Roma sono 123 minuti di treno per 56.620 lire, o 81 minuti
d'aereo per 178.800. Dentro una città, dieci chilometri sono 43 minuti a piedi, 18 coi
mezzi, 10 in taxi.

Il tempo del mondo scorre **1:1** con quello vero. Si comprime solo la noia dello
spostarsi, di un fattore 3 dichiarato (`mondo.compressione_viaggio`).

La mappa è una **carta stampata d'epoca**, disegnata in SVG dal server: coste vere (da
Natural Earth, pubblico dominio, semplificate una volta sola), mare a tratteggio, rosa dei
venti, scala grafica, cartiglio. Sotto ogni città compaiono i **nomi della gente** che
sta lì; quando due etichette si pesterebbero i piedi, la scritta si scosta e resta legata
al suo punto da un filo — il punto non si muove mai, quello è geografia. I colori passano dalle variabili del tema, quindi la stessa
carta si stampa su fondo ecru di giorno e su fondo scuro di notte.

## Il mercato: il cuore del gioco

**Dieci merci su 273 nodi di mercato** (piazza × merce), divise in fasce che sono anche la
scala della progressione:

| Merce | Unità | Fascia | Prezzo di riferimento | Ingombro |
|---|---|---|---|---|
| Anfetamine | dose | bassa | 3.000 – 20.000 | 1 |
| Acidi | francobollo | bassa | 5.000 – 30.000 | 1 |
| Sigarette di contrabbando | stecca | bassa | 8.000 – 45.000 | 3 |
| Pasticche | dose | media | 15.000 – 60.000 | 1 |
| Marijuana | 10 g | media | 15.000 – 60.000 | 2 |
| Hashish | 10 g | media | 20.000 – 70.000 | 1 |
| Farmaci e morfina | fiala | media | 20.000 – 80.000 | 1 |
| Eroina | grammo | alta | 80.000 – 350.000 | 1 |
| Cocaina | grammo | alta | 120.000 – 300.000 | 1 |
| Armi | pezzo | armi | 400.000 – 2.500.000 | 5 |

Si comincia con **300.000 lire**: la cocaina è fuori portata, ed è esattamente il punto —
nel 1984 si partiva con 2.000 dollari e la cocaina ne costava 15.000, e *non potertene
permettere nemmeno una* era ciò che dava la scala al gioco. Conta il rapporto fra il
capitale e la merce più cara, non i valori assoluti.

**Come si muove il mercato, quando nessuno lo tocca:**

- i **prezzi di riferimento** fanno una passeggiata di Ornstein-Uhlenbeck a passi di cinque
  minuti, con ritorno alla media e una banda storica che non si scavalca;
- le **giacenze** tornano all'equilibrio in modo esponenziale, e sopra ci si mettono i
  **carichi a grumi**: il 12% delle ore arriva una partita che abbassa i prezzi per
  qualche ora. È l'irregolarità a creare l'occasione — ed è il motivo per cui in questo
  gioco **l'informazione vale denaro**;
- tutto è **deterministico** rispetto a (piazza, merce, ora): il battito da cron e la
  richiesta web calcolano la stessa cosa, o le giacenze divergerebbero a seconda di chi
  guarda.

**Leggere non scrive.** Il listino si ottiene proiettando lo stato salvato con una funzione
pura; si persiste solo quando qualcuno tratta davvero, e al battito. Cento pagine aperte non
devono costare cento scritture.

**Le armi sono merce**, non una statistica: si comprano al listino come tutto il resto (sei
piazze in tutta Italia le trattano), pesano cinque volte una dose, e chi le porta addosso le
perde se lo pestano.

## Il denaro: sporco e pulito

Il commercio produce **contante sporco**, che non compra niente di legale e si perde tutto
in un sequestro o in una rapina. Diventa **pulito** solo passando da un canale, e ogni
canale ha una commissione *e* una **capacità oraria** — è quest'ultima a fare il gioco:
puoi avere la cantina piena e non poterla usare.

| Canale | Commissione | Capacità | Costa |
|---|---|---|---|
| Il bar di un amico | 35% | 150.000/ora | — (si comincia con questo) |
| L'autolavaggio | 30% | 400.000/ora | 3.000.000 |
| La sala giochi | 28% | 1.200.000/ora | 9.000.000 |
| Il cantiere | 22% | 4.000.000/ora | 30.000.000 |
| Il totonero | 18% | 10.000.000/ora | 80.000.000 |
| Il cambiavalute | 12% | 30.000.000/ora | 200.000.000 |
| La società estera | 8% | 100.000.000/ora | 600.000.000 |

**L'usuraio.** Si comincia con 1.500.000 di debito e un interesse del 10% al giorno
**continuo** — `D(t) = D₀·(1+i)^(t/giorno)`, non a scatti: senza turni, uno scatto a
mezzanotte premierebbe chi paga alle 23:59. Il debito ha un tetto a tre volte, e il credito
che ti sei fatto allarga il prestito e lima l'interesse.

**Tre mezzi**, comprati col pulito, che allargano il carico e diventano anche un modo di
spostarsi:

| Mezzo | Spazio | Prezzo | In città / fuori |
|---|---|---|---|
| Una 127 di seconda mano | +120 | 2.500.000 | 26 / 75 km/h |
| Una berlina | +220 | 9.000.000 | 30 / 90 km/h |
| Un furgone | +820 | 20.000.000 | 24 / 70 km/h |

**I depositi** si affittano per piazza, si pagano a ore in contanti, e se salti l'affitto ti
sfrattano con la merce dentro. Servono a togliere il limite dell'ora: compri quando costa
poco e aspetti che la piazza si riprenda.

E un **registro** che tiene conto di ogni lira che non passa dal mercato: biglietti,
stipendi, affitti, commissioni, sequestri, pizzo.

## La legge

**Il rischio è una conseguenza, non un dado.** Ogni operazione scalda te *e* la piazza in
proporzione **superlineare** al valore mosso — `(valore/soglia)^1.5 · (1 + rischio/100)` —
e il calore decade da solo se stai fermo (dimezza in dodici ore per te, sei per la piazza).
È la soglia deterministica del door BBS del 1993, resa continua. Il calore non si legge mai
come numero: si legge tradotto in parole.

Oltre quaranta gradi si apre un **fascicolo**: un inquirente con un nome e un corpo —
questura, carabinieri o finanza — che accumula prove nel tempo vero. Lungo la strada lascia
**segnali**, e sono l'unico avvertimento che avrai: la stessa auto sotto casa, il cliente
che fa troppe domande, il telefono che fa un rumore strano, quello che ha chiesto di te al
bar.

A **cento prove** scatta il blitz: sequestro del carico, dei depositi **nella città dove
sei** (gli altri no — per questo conviene distribuire), il 60% dei contanti, e carcere a
tempo reale. Il denaro pulito e i canali sopravvivono: sono intestati.

**Cosa puoi farci:**

- **un penalista** — 4.000.000 di lire *pulite*, toglie 30 prove;
- **una busta** — 2.500.000 sporchi, toglie 18 prove, ma una volta su cinque finisce agli
  atti *come prova a tuo carico*;
- **smettere in tempo** — un fascicolo che smette di crescere si archivia. È l'unica cosa
  che rende «fermarsi» una strategia invece che una resa.

E i **posti di blocco**, che scattano solo col mezzo proprio e con la roba addosso: è il
prezzo nascosto dell'automobile, che per tutto il resto conviene.

## Il personaggio

**Cinque attributi che crescono con l'uso**, mai con punti da spendere, e con rendimenti
calanti: **trattativa** (stringe lo spread), **fiuto**, **sangue freddo** (dimezza il
rischio), **organizzazione** (quanti uomini reggi), **credito** (prestito e interesse).

**Reputazione su due assi ortogonali**, che non sono la stessa cosa detta due volte:
il **rispetto** porta gente più brava e apre i fornitori; il **timore** porta gente più
fedele.

**Un organico di persone con un nome**, competenza, lealtà e stipendio orario:

| Ruolo | Cosa fa |
|---|---|
| Corriere | Porta la roba da una parte all'altra mentre tu sei altrove |
| Vedetta | Sta in una piazza e ti avvisa: lì i controlli calano |
| Contabile | Fa girare più denaro nei canali che hai |
| Riciclatore | Tratta meglio con chi lava: la commissione scende |
| Basista | Ti dice i prezzi di una piazza dove non sei |
| Guardia | Ti sta dietro: in un controllo si perde meno roba |

Chi non viene pagato smette di volerti bene, e **il giorno che ti prendono parla**: è il
pentito, e porta trentacinque prove al tuo fascicolo.

**I fornitori** sono l'unica cosa del gioco che non si compra col denaro: si sbloccano col
rispetto, chiedono un lotto minimo, e scontano il prezzo di piazza senza sostituirlo —
quindi il margine si sposta, ma dal nulla non nasce niente.

**L'organizzazione cresce mandando avanti un giro**, non assumendo: all'inizio reggi un uomo
solo, e se crescesse solo assumendo il tetto non si alzerebbe mai.

## Gli altri giocatori

Il livello principale è quello che **non costa una riga di codice**: il mercato è condiviso,
quindi chi compra prima alza il prezzo a chi viene dopo, e chi svuota una piazza la lascia
morta per ore. Sopra ci sta quello che va scritto — colpire una persona invece di un prezzo:

- **Le mani addosso.** Due punteggi contrapposti, un tiro di danno per arma, le guardie che
  sparano e incassano, sei round al massimo: è una rissa per strada, non un duello. Chi
  perde va all'ospedale a tempo reale.
- **Il bottino è solo quello che la vittima aveva addosso** — mai il pulito, mai i canali,
  mai gli immobili. In `dopewars` si prendeva tutto: su un personaggio costruito in tre mesi
  è la fine del gioco per la vittima e, dopo un po', per il server.
- **Sotto mezzo milione non si prende niente a nessuno.** È la protezione dei nuovi arrivati,
  e non è un'immunità: aggredire un principiante resta possibile, semplicemente è
  **l'attività peggio pagata del gioco**. Il calore lo paghi lo stesso.
- **La soffiata** (2.000.000): una telefonata che porta 22 prove al fascicolo di un altro.
  Una volta su quattro la registrano, e le prove se le prende chi ha chiamato.
- **La spia** (3.000.000, e l'uomo lo perdi comunque): un tuo uomo dentro casa d'altri.
  Finché non la scoprono vedi quello che vede lei — contante, pulito, debito, calore,
  uomini, carico. Alla scoperta, quattro esiti come nell'originale: uccisa, scappata,
  voltafaccia, o semplicemente finita.
- **La rapina ai carichi**: le corse dei corrieri altrui che passano di lì si possono
  fermare. Non manda nessuno all'ospedale — è il modo di farsi male a vicenda che lascia
  tutti in piedi.

**Le batterie** si fondano con 10.000.000 di lire pulite, hanno una cassa comune da cui
preleva solo il capo, e possono **tenere una piazza**. Il territorio però non si conquista
premendo un pulsante: ogni lira movimentata lì lascia punti di presenza alla batteria di chi
l'ha movimentata, **la presenza si dimezza ogni 72 ore**, e sopra soglia la piazza passa.
Chi comanda incassa il **4%** su quello che ci trattano gli estranei. È la logica del
mercato applicata alle persone: niente dichiarazioni, solo conseguenze — e un territorio va
*tenuto*, non preso una volta.

**Ci si vede e ci si parla.** Nella piazza dove sei, chi c'è compare con la **fotografia**,
la batteria e il numero di pubblico nemico. La **chiacchiera è di piazza**: le voci si
leggono solo stando lì e si dimenticano in poche ore — non è un limite tecnico, è che
l'informazione sui prezzi altrove in questo gioco si paga, ed è il mestiere del basista.

**Il baratto è merce contro merce, mai denaro.** Uno scambio di contante sarebbe un tubo
che aggira in un colpo solo il tetto di reddito e la capacità oraria dei canali di
riciclaggio — un secondo account diventerebbe una lavanderia gratuita e infinita. Merce
contro merce sposta roba fra due carichi senza creare una lira, e il costo viaggia con la
merce, così il primo margine di chi la rivende è vero.

Sopra tutto, **la cronaca**: il notiziario del mondo, uguale per tutti. Senza, metà di quello
che succede sarebbe invisibile, e un mondo condiviso che non si vede tanto vale non averlo.

## Traguardi, classifiche, albo d'oro

**Ventiquattro obiettivi** in quattro gruppi (il mestiere, il denaro, la legge, gli altri).
Due regole: si sbloccano da **fatti già registrati** — mai da contatori scritti apposta — e
**non danno alcun vantaggio**, perché in un mondo a reddito finito un premio in denaro lo
pagherebbero gli altri giocatori senza saperlo. Sono una traccia di quello che hai fatto.

**Quattro graduatorie, non una**, perché in un mondo che non riparte mai una sola premia
soltanto chi è arrivato per primo, e a chi comincia oggi dice che è tardi:

1. **Patrimonio** — il denaro pulito. La classica, e la più lenta.
2. **Territorio** — le piazze tenute. Cambia in continuazione.
3. **Reddito degli ultimi trenta giorni** — *quella che conta*: il passato non ci pesa, ed è
   contendibile da chiunque, sempre.
4. **Longevità** — giorni senza arresti né ospedale, ma solo sopra profilo criminale 5:
   premia chi rischia e non si fa prendere, non chi non rischia.

Più un **albo d'oro** permanente per chi tiene un primato almeno un mese, col nome copiato
dentro la riga perché sopravviva anche alla cancellazione dell'account.

E poi **statistiche** vere del mondo e tue, il **profilo pubblico** con la fotografia
centrata a mano nel riquadro (che chi amministra può **togliere o sostituire** dal profilo
stesso e dall'elenco dei giocatori — una vetrina con le facce prima o poi ne ospita una che
non va bene), e un'**amministrazione** completa: piazze, calore, chi tiene
cosa, giocatori, parametri di gioco modificabili a caldo, coda della posta, registro delle
azioni. Nessuna azione dell'amministratore crea denaro, per principio: in un mondo a torta
finita, contanti regalati li pagano tutti gli altri senza accorgersene.

L'interfaccia è **carta di giornale italiana anni '80** — fondo ecru, inchiostro, rosso
cinabro, titoli in grazie, cifre in monospaziato tabellare — con luce chiara/scura salvata
sull'utente, e si **installa come app** (PWA) sul telefono.

**Si gioca col dito senza rinunciare al monitor.** Le regole per il tocco stanno sotto
`@media (pointer: coarse)`, che è la condizione onesta — non «lo schermo è stretto» ma
«chi punta è un dito»: prende il telefono e il tablet, e non può raggiungere un monitor
col mouse. Da lì i bersagli passano a 44px e le etichette minute salgono sopra i 12px.
Sotto i 40rem il **listino diventa a schede impilate**: una tabella a sette colonne su un
telefono si può solo trascinare di lato, e il listino è *la* schermata del gioco. Il markup
però resta una tabella sola — le intestazioni ripetute nelle schede sono attributi
`data-etichetta`, e un attributo che nessuna regola legge non si vede. Su monitor non
cambia una riga.

> Una scelta dichiarata: l'app **non mette in cache nessuna pagina**. Lo stato del gioco sta
> sul server ed è autoritativo; una pagina servita dalla cache mostrerebbe un mondo che non
> esiste più, e un listino vecchio di dieci minuti non è degradazione elegante — è una bugia
> su cui qualcuno prende una decisione.

---

## Com'è fatto dentro

**PHP 8.4 senza framework**, front controller unico, rotte in italiano · **MariaDB** con
migrazioni numerate · **JS vanilla + Canvas**, nessun build step · **Apache** con
`mod_rewrite`.

La simulazione è autoritativa lato server e si muove in due modi che devono concordare:
un **battito da cron** ogni minuto e un **avanzamento pigro deterministico** sulle richieste
web. Il generatore è **xorshift64** — mai moltiplicazioni a 64 bit, che in PHP traboccano in
float e rompono il determinismo in silenzio.

I moduli **puri** stanno in `src/Sim/` e non toccano il database, quindi si provano davvero:

| | |
|---|---|
| `Rng` | generatore deterministico, con riscaldamento alla semina |
| `Clock` | il tempo del mondo, iniettabile nelle prove |
| `Geo` | emisenoverso e fattore di percorso |
| `Viaggio` | sei mezzi, tempi, costi, compressione |
| `Prezzi` | la passeggiata dei riferimenti |
| `Mercato` | formazione del prezzo e ordini integrati lungo la curva |
| `Rifornimento` | il respiro delle giacenze e i carichi a grumi |
| `Denaro` | interesse continuo, riciclaggio, capacità oraria |
| `Calore` | il rischio come conseguenza |
| `Crescita` | attributi a rendimenti calanti |
| `Scontro` | le mani addosso |
| `Etichette` | da che parte scrivere un'etichetta sulla carta, e come non farle accavallare |

Sopra, `src/Game/` tiene il gioco vero (mondo, carta, listino, contabilità, logistica,
legge, organico, rivalità, batterie, chiacchiera, baratto, classifiche, obiettivi,
statistiche) e `src/Controllers/` le
settantacinque rotte dell'interfaccia.

## Installazione

Servono PHP 8.4 con `pdo_mysql`, `mbstring` e `gd` (con WebP), MariaDB, e Apache con
`mod_rewrite`.

```bash
PIZ_URL=https://esempio.tld/piazzapulita \
PIZ_ADMIN_EMAIL=tu@esempio.tld \
  sudo -E bash deploy/00-bootstrap.sh     # una volta sola: directory, database, vhost
bash deploy/01-installa.sh                # ogni volta: codice, permessi, migrazioni, mondo
php bin/console.php user:create admin tu@esempio.tld --admin
```

Poi il battito, nel crontab dell'utente che possiede l'installazione:

```
* * * * * /usr/bin/php /data/html/piazzapulita/bin/tick.php >/dev/null 2>&1
```

Le credenziali SMTP si mettono a mano in `/data/piazzapulita-config/config.php`: finché
`mail.transport` resta `log` non parte nessuna e-mail, e senza conferma dell'indirizzo non
si entra — `user:create` esiste apposta per fare il primo amministratore senza posta.

> **Le esclusioni del deploy non sono un dettaglio.** Con `--delete`, tutto quello che sta
> nella destinazione e non nel sorgente viene cancellato — e le cose che il processo web
> scrive da solo stanno soltanto lì, perché in sorgente sono escluse da git. Le fotografie
> del profilo lo erano: ogni singolo deploy le cancellava tutte, lasciando in banca dati
> riferimenti a file inesistenti, e il giocatore vedeva un rettangolo grigio. Regola: tutto
> ciò che nasce a runtime va elencato fra gli `--exclude`. Per controllare che banca dati e
> disco siano d'accordo c'è `php bin/console.php avatar:verifica [--ripara]`, da lanciare
> **dalla cartella servita**, non da quella di lavoro.

> Lo script di installazione esiste al posto di un `rsync` a mano per un motivo preciso:
> `rsync -a` include `-g`, e un utente non privilegiato che copia con `-g` rimette il proprio
> gruppo sui file, cancellando il `<utente>:www-data` con setgid che il bootstrap aveva
> impostato. Il processo web smette di poter scrivere il diario — **in silenzio**, perché
> `logger()` ripiega sul syslog e non si lamenta.

## Prove e strumenti

```bash
php tests/test_unita.php      # 266 verifiche, senza rete e senza database
bash tests/e2e_auth.sh        # iscrizione, conferma, accesso, profilo — attraverso Apache
bash tests/e2e_mondo.sh       # nascita, viaggi, arrivi, registro
bash tests/e2e_mercato.sh     # compravendita, impatto sul prezzo, fotografia
bash tests/e2e_affari.sh      # riciclaggio, usuraio, mezzi, depositi
bash tests/e2e_legge.sh       # calore, fascicolo, blitz, carcere, difese
bash tests/e2e_organico.sh    # attributi, uomini, corrieri, pentiti, fornitori
bash tests/e2e_rivalita.sh    # due giocatori veri: botte, bottino, spie, batterie, pizzo
bash tests/e2e_rifinitura.sh  # obiettivi, graduatorie, albo d'oro, admin, installabilità
bash tests/e2e_gente.sh       # vedersi, parlarsi, barattare, e la carta dell'admin
bash tests/browser_avatar.sh  # il riquadro della foto, in Chromium headless
bash tests/browser_schermi.sh # telefono e tablet: niente scorrimento laterale, 44px col dito
```

Undici suite: le unitarie sui moduli puri, nove end-to-end che girano **attraverso Apache
sull'installazione vera** (non su un simulacro), e due in un browser. Una di loro non usa `curl` ma **Chromium headless**, e c'è per un motivo
imparato sul campo: `curl` non è un browser — non applica la CSP, non impagina niente, non
esegue JavaScript. Due guasti del riquadro della fotografia sono passati esattamente da lì
senza far diventare rossa una sola verifica. Se non c'è Chromium, quella prova si salta
dicendolo, invece di fallire. Le unitarie coprono i moduli puri e il rendering di **tutte** le viste in tutti i
loro stati: una variabile dimenticata in un `<?php` diventa una pagina bianca solo quando ci
arriva un giocatore.

Per il bilanciamento ci sono tre strumenti:

```bash
php bin/console.php balance:report        # l'invariante del tetto, trattato come una prova
php bin/console.php avatar:verifica       # fotografie: banca dati contro disco
php bin/_simula_principiante.php 12 NA    # un principiante contro il motore vero
PIAZZAPULITA_CONFIG=config/config.php \
  php bin/_simula_mondo.php 12 24 cattivo # dodici giocatori veri, e tre invarianti
```

L'ultimo fa giocare N personaggi **veri** contro il motore vero — tutto attraverso il
database — e pretende che: **la cassa torni alla lira** (contante = iniziale + vendite −
acquisti + movimenti), che nessun saldo vada sotto zero, e che nessuna ora superi il tetto.
Si rifiuta di partire se il database contiene account veri, perché azzera il mondo.

`balance:report` conta anche **quanti giocatori hanno davvero venduto**: sotto tre, dichiara
la riga dell'utilizzo non misurabile invece di gridare al lupo — con un giocatore solo il
mondo risulta sempre «troppo generoso», e non è un difetto di taratura, è che non estrae
nessuno.

## Documentazione

| | |
|---|---|
| [docs/GIOCO_ORIGINALE.md](docs/GIOCO_ORIGINALE.md) | Lo studio delle fonti originali: dati esatti, tabelle di prezzo, i dieci problemi della trasposizione |
| [docs/IMPIANTO.md](docs/IMPIANTO.md) | La proposta iniziale, tenuta come traccia del ragionamento |
| [docs/DESIGN.md](docs/DESIGN.md) | Il progetto: economia, legge, personaggio, roadmap, e le tarature con i numeri misurati |

## Le fonti originali

Lo studio è stato fatto sui **sorgenti veri**, non su sintesi di terze parti: il binario di
*Drug Wars* del 1984, il sorgente C completo del door BBS del 1993 (2.405 righe, con il
`danger` e le soglie deterministiche della polizia), e `dopewars` di Ben Webb (1998-2022).

**Quei file non stanno in questo repository**: pesano, non sono nostri, e alcuni sono
software proprietario. Il §8 dello studio dice esattamente dove si scaricano.

## Licenza

**GPL-3.0** — vedi [LICENSE](LICENSE).

Il gioco è una **riscrittura originale**: non contiene codice di *Drug Wars*, di *dopewars*
o di altre versioni della serie. Da loro viene l'analisi delle meccaniche, documentata riga
per riga in `docs/GIOCO_ORIGINALE.md` con i riferimenti ai sorgenti.

---

*È finzione, e il soggetto è quello che è. Le merci del gioco sono numeri con un nome:
niente di quello che c'è qui dentro insegna, descrive o incoraggia alcunché nel mondo reale.
Progetto personale, nessun fine commerciale.*
