# Documento di progettazione

> Trasposizione di *Drug Wars* (John E. Dell, 1984) in browsergame persistente e
> multigiocatore. Italia, 1980-1995.
>
> Presuppone lo studio delle fonti in [`GIOCO_ORIGINALE.md`](GIOCO_ORIGINALE.md) e
> l'impianto in [`IMPIANTO.md`](IMPIANTO.md). Redatto il 19/09/2026.

---

## 0. Le quattro decisioni fondanti (prese con l'utente)

1. **Ambientazione: Italia, 1980-1995.** Le piazze delle città italiane, la lira, i bar
   e l'edilizia come lavatrici, la questura e i pentiti come avversari.
2. **Tempo reale continuo.** Niente turni. Il mercato respira su tick da cron, gli
   spostamenti durano minuti o ore reali, gli interessi maturano di continuo.
3. **Mondo eterno, nessun azzeramento.** Un solo mondo che non riparte mai. Classifica
   storica continua.
4. **PvP pieno.** Chiunque può colpire chiunque; chi vince si prende quello che l'altro
   aveva addosso.

### 0.1 Le due tensioni che queste decisioni creano, e come le paghiamo

Le decisioni 2, 3 e 4 insieme sono la ricetta storica del browsergame che muore: crescita
esponenziale senza fine corsa, e veterani che coltivano i nuovi arrivati. Vanno tenute —
sono le decisioni dell'utente — e vanno pagate con due scelte strutturali.

**(a) Il mondo ha un reddito massimo per ora, e non è negoziabile.**
Ogni piazza assorbe solo una certa quantità di merce all'ora (§2.1, la variabile `A`).
Il denaro che l'insieme dei giocatori può estrarre dal mondo in un'ora è quindi
**limitato superiormente e noto**: è la somma, su tutte le piazze e tutti i beni, di
`assorbimento × margine`. Non esiste esponenziale, perché non esiste una funzione che
cresca oltre quel tetto. La ricchezza dei giocatori è una **torta finita da contendersi**,
non una fontana. Chi cresce lo fa togliendo agli altri, o allargando la torta
(conquistando piazze, aprendo canali, creando domanda), il che costa.

Questo è il singolo punto tecnico più importante del progetto. Se un giorno qualcuno
propone di "far rigenerare i prezzi come nell'originale", la risposta è no, e il motivo
è questo paragrafo.

**(b) Uccidere ha un prezzo, e il prezzo si chiama calore.**
Il PvP resta pieno. Ma il door BBS del 1993 aveva già la risposta giusta (§3.2a dello
studio): **pubblico nemico numero N**. Ogni omicidio alza in modo permanente il tuo
profilo presso la legge; a profilo alto la questura non ti manda una pattuglia, ti manda
un fascicolo, e i posti di blocco diventano una costante. Il veterano che si diverte a
sparare ai nuovi arrivati finisce braccato, e questo non è un limite morale: è
l'economia del gioco che si difende.

Inoltre, e questa è una precisazione necessaria alla decisione 4:

- **Si saccheggia ciò che l'altro aveva addosso**: contante sporco, merce, armi, veicolo.
- **Non si saccheggia** il denaro pulito, gli immobili, i canali di riciclaggio: sono
  intestati, non stanno in una borsa. La vittima resta in piedi sulle fondamenta,
  ripulita di tutto il resto.
- **La morte non cancella il personaggio**: significa ospedale e convalescenza in tempo
  reale, e aver perso tutto il circolante. Coerente con la decisione 3, che dice che
  in questo mondo non si azzera niente — nemmeno le persone.
- **I nuovi arrivati non si proteggono con una regola: si proteggono col conto.**
  Deciso il 19/09/2026: nessuna immunità esplicita, nessuna soglia sotto la quale non si
  può essere attaccati. Chi ha poco **non ha bottino** — ammazzarlo non rende niente — e
  il calore si paga per intero lo stesso (§4.4). Cacciare i principianti resta possibile
  e diventa semplicemente l'attività peggio pagata del gioco. È la soluzione più elegante
  perché non contraddice la decisione 4: non toglie nulla a nessuno, mette solo il prezzo
  giusto su una cosa che costa e non rende.

---

## 1. Il mondo

### 1.1 Due livelli di geografia

| Livello | Cos'è | Tempo di spostamento | Ruolo |
|---|---|---|---|
| **Città** | 9 città italiane | da 40 minuti a 4 ore reali | dove nascono i differenziali di prezzo veri |
| **Piazza** | 4-7 per città, 43 in tutto | da 3 a 25 minuti reali | dove si compra e si vende |

**I tempi di percorrenza sono compressi 1:3** (`mondo.compressione_viaggio`): un minuto
reale vale tre minuti di viaggio. È una scelta di giocabilità dichiarata, non un orologio
diverso — il tempo del mondo resta quello vero (decisione 2), si accorcia soltanto la
noia dello spostarsi. Senza compressione attraversare Milano coi mezzi costerebbe tre
quarti d'ora di attesa vera e il gioco sarebbe una sala d'aspetto. Le attese (il treno,
il check-in) **non** sono compresse: aspettare è aspettare.

| Mezzo | Ambito | Velocità | Attesa | Costo |
|---|---|---:|---:|---|
| a piedi | città | 5 km/h | — | niente, e non lascia niente |
| mezzi pubblici | città | 14 km/h | 3 min | 600 L |
| taxi | città | 28 km/h | 2 min | 2.000 + 1.200/km |
| pullman | paese | 70 km/h | 20 min | 60 L/km |
| treno | paese | 110 km/h | 15 min | 95 L/km |
| aereo | paese, oltre 300 km | 450 km/h | 55 min | 300 L/km |

Le velocità sono d'epoca: 110 km/h effettivi in treno sono il rapido degli anni ottanta
sulle direttrici principali, non l'alta velocità. Ne escono un salto fra piazze vicine
sotto i dieci minuti, una traversata di Milano in venti, Napoli-Roma in treno in
cinquantotto minuti e Milano-Palermo in tre ore e mezza — o in un'ora e tre quarti
volando, per trecentotrentamila lire e il proprio nome su un elenco.

Le città hanno **carattere economico strutturale**: un bene è a buon mercato dove entra
nel paese e caro dove si consuma. È questo, e non il rumore casuale, a creare le rotte
commerciali stabili — la cosa che all'originale mancava del tutto.

| Città | Carattere | Piazze (indicative) |
|---|---|---|
| **Milano** | grande consumo, denaro pulito, prezzi alti | Centrale, Quarto Oggiaro, Giambellino, Brera, Corvetto |
| **Torino** | operaia, consumo di massa | Barriera di Milano, Le Vallette, San Salvario, Porta Palazzo |
| **Genova** | porto: ingresso merce via mare | Centro Storico, Sampierdarena, Cornigliano |
| **Bologna** | snodo, università, novità | Bolognina, Pilastro, Zona universitaria |
| **Roma** | il mercato più grande e più sorvegliato | Tor Bella Monaca, San Basilio, Trastevere, EUR, Termini |
| **Napoli** | contrabbando, prezzi bassi, controllo del territorio fortissimo | Forcella, Secondigliano, Quartieri Spagnoli, Sanità |
| **Bari** | porto adriatico: rotta balcanica | San Paolo, Japigia, Libertà |
| **Palermo** | capitale del traffico, legge presente e imprevedibile | Ballarò, Zen, Brancaccio |
| **Catania** | periferia del sistema, poco sorvegliata | Librino, San Cristoforo, Picanello |

Più tre **valichi**, che non sono piazze ma servizi: **Ventimiglia** e **Chiasso** (uscita
del denaro), **Trieste** (rotta dell'est). E il mare: Gioia Tauro e Brindisi come punti
d'ingresso dei carichi grossi, accessibili solo a chi ha i contatti.

*(I nomi dei quartieri sono quelli che la cronaca del periodo ha reso noti. Sono
rinominabili in blocco da `game_config` se si preferisce un velo di finzione.)*

### 1.2 Il calendario: ere che ritornano

Il mondo non finisce, ma non deve nemmeno stagnare. Il periodo 1980-1995 viene usato
come **serie di regimi economici che si alternano ciclicamente**, ciascuno di alcune
settimane reali, annunciati dalla cronaca:

| Regime | Effetti sul mercato |
|---|---|
| **L'ondata** | domanda di eroina altissima, margini enormi, calore altissimo, morti per strada |
| **La stretta** | grande operazione di polizia: assorbimento ridotto, controlli triplicati, prezzi impazziti |
| **Gli anni della polvere** | la cocaina diventa il bene dei ricchi: Milano e Roma pagano il doppio |
| **Il porto aperto** | rotta balcanica o marittima spalancata: offerta abbondante, prezzi a terra |
| **Le mani pulite** | il denaro pulito si fa sospettoso: riciclaggio più caro e più lento, immobili svalutati |
| **La guerra** | i clan NPC si scannano: territori instabili, piazze che chiudono e riaprono |

Il regime è **pubblico e leggibile**: chi legge la cronaca e si posiziona prima guadagna.
È la versione strutturale degli shock testuali del 1984.

### 1.3 La lira

Valuta di gioco: **lire italiane**. Capitale iniziale **2.000.000**, debito iniziale con
l'usuraio **5.500.000** (le stesse proporzioni dell'originale). Il milione è il primo
traguardo, il miliardo è la fine del gioco che non arriva mai.

---

## 2. Il mercato — il cuore

### 2.1 Stato di una coppia (piazza, bene)

| Campo | Significato |
|---|---|
| `offerta` S | unità acquistabili adesso |
| `offerta_eq` S\* | giacenza di equilibrio |
| `domanda` A | **capacità residua di assorbimento**: quanto la piazza è ancora disposta a comprare |
| `domanda_eq` A\* | assorbimento di equilibrio |
| `mult` D | carattere strutturale della piazza per quel bene |
| `shock`, `shock_fino_a` | perturbazione in corso |
| `agg_a` | istante dell'ultimo avanzamento (per l'avanzamento pigro) |

`A` è la variabile che fa esistere il gioco. È il tetto di cui al §0.1a.

### 2.2 Prezzo di riferimento

Per ogni bene, una passeggiata a ritorno verso la media (Ornstein-Uhlenbeck a passi):

```
P₀(t+Δ) = P₀(t) + θ·(μ·k_regime − P₀(t))·Δ + σ·√Δ·N(0,1)
```

- `μ` = centro della forchetta storica del bene;
- `σ` tarata perché la **volatilità percentuale sia maggiore sui beni poveri**, come
  nell'originale (§5 dello studio): è la scala di progressione travestita da listino;
- `k_regime` = moltiplicatore dell'era in corso (§1.2).

### 2.3 Formazione del prezzo

```
p_acquisto = P₀ · D · (S*/max(S,1))^ε · (1+shock) · (1+m)
p_vendita  = P₀ · D · (A/max(A*,1))^ζ · (1+shock) · (1−m)
```

- `ε, ζ ≈ 0,6`;
- `m` = spread, ridotto da *trattativa*, reputazione, controllo della piazza;
- entrambi vincolati alla banda `[0,2 ; 5,0] × P₀·D`.

Un ordine non si valuta a prezzo costante: si integra lungo la curva, perché comprare
fa salire il prezzo mentre compri. In pratica, per un ordine di `q` unità:

```
costo(q) = Σ_{i=0}^{q-1}  p_acquisto(S − i)
```

calcolato in forma chiusa dove possibile, a blocchi altrimenti. Il giocatore vede il
**prezzo medio effettivo** prima di confermare: nessuna sorpresa, ma nessuno sconto.

### 2.4 Il respiro (tick)

```
S ← S + r_S·(S* − S)·Δ + carichi − consumo
A ← A + r_A·(A* − A)·Δ
shock ← shock · decadimento
```

Il rifornimento arriva **a carichi**, non a filo: ampiezza e cadenza diverse per piazza.
Una piazza appena rifornita ha prezzi bassi per qualche ora, e chi lo sa per primo
guadagna. Il tick gira da cron; le richieste web fanno **avanzamento pigro
deterministico** solo su ciò che serve, come in Atlantik.

### 2.5 I beni

Forchette in lire, costruite conservando la proprietà strutturale dell'originale (i beni
poveri sono percentualmente più volatili):

| Bene | Unità | Min | Max | max/min | Ingombro | Rischio |
|---|---|---:|---:|---:|---:|---|
| Sigarette di contrabbando | stecca | 8.000 | 45.000 | 5,6 | 3 | minimo |
| Anfetamine | dose | 3.000 | 20.000 | 6,7 | 1 | basso |
| Acidi | francobollo | 5.000 | 30.000 | 6,0 | 1 | basso |
| Marijuana | 10 g | 15.000 | 60.000 | 4,0 | 2 | medio |
| Hashish | 10 g | 20.000 | 70.000 | 3,5 | 1 | medio |
| Pasticche | dose | 15.000 | 60.000 | 4,0 | 1 | medio |
| Farmaci e morfina | fiala | 20.000 | 80.000 | 4,0 | 1 | medio |
| Eroina | grammo | 80.000 | 350.000 | 4,4 | 1 | alto |
| Cocaina | grammo | 120.000 | 300.000 | 2,5 | 1 | alto |
| Armi | pezzo | 400.000 | 2.500.000 | 6,3 | 5 | altissimo |

Le sigarette sono l'equivalente dei *ludes*: il bene d'ingresso, ingombrante, a margine
percentuale alto e rischio quasi nullo. Le armi sono il bene di fine gioco: poche,
pesanti, e trasportarle è quasi una confessione.

**Sull'unità di misura.** Hashish e marijuana erano all'etto, e l'etto non regge: al §2.6
si vedrà che un bene il cui valore unitario è troppo alto viene assorbito da una piazza
meno di una volta all'ora, e un mercato che si muove di un'unità all'ora non è un mercato,
è un semaforo. Sono passati alla decina di grammi. La regola generale è al §2.6: **ogni
bene va tagliato in un'unità che una piazza assorba fra le 3 e le 30 volte l'ora.**
Hashish e marijuana valgono uguale ma ingombrano diverso — la seconda è voluminosa — e
questo basta a renderle due beni distinti invece che due nomi per la stessa cosa.

### 2.6 Il tetto di reddito del mondo

È il numero più importante del gioco, quindi si ricava, non si sceglie a naso.

**Il valore: `R = 16.000.000 di lire l'ora`** (ritarato il 19/09/2026; era 24 milioni —
vedi §2.6.1). Tanto produce il mondo intero, per tutti i giocatori messi insieme: 384
milioni al giorno, circa 11,5 miliardi al mese. Nessuno può estrarre di più, perché non
esiste niente da cui estrarlo.

#### Da dove viene

L'àncora è una domanda sola: *in quanto tempo un giocatore nuovo arriva al primo milione
pulito?* Il milione non è un numero tondo a caso — nel 1985 era all'incirca lo stipendio
mensile netto di un operaio. Il primo traguardo del gioco è **guadagnare in qualche ora di
strada quello che si guadagnava in un mese di lavoro onesto**, ed è lì che deve stare.

La risposta scelta è **quattro-cinque ore di gioco attivo**, distribuite su due o tre
giorni. Da lì si scende:

| Passaggio | Conto | Risultato |
|---|---|---|
| Un milione *pulito* ne richiede di sporchi | ÷ 0,75 (commissione dei canali da principiante) | 1.333.000 |
| In 5,4 ore attive | ÷ 5,4 | **≈ 250.000 L/ora** per un principiante a piedi |
| Carico pieno di un principiante | 80 spazi ÷ 3 = 26 stecche × 26.500 | 689.000 L impegnati |
| Margine per carico | 26 × 7.950 | 206.700 L |
| Carichi all'ora, viaggi compresi | ≈ 1,2 | 248.000 L/ora ✔ |

Il margine del 30 % per viaggio non è arbitrario: è **esattamente il ritmo di
capitalizzazione dell'originale**. Nel 1984 si andava da 2.000 a 50 milioni in trenta
turni, cioè ×1,38 a turno. Conservare quel 30 % conserva la sensazione del gioco di
partenza; quello che cambia è che il turno non è più un giorno di calendario ma una
mezz'ora vera, e questo solo basta a trasformare uno sprint di trenta turni in una
maratona.

Da lì si sale ai profili superiori e si controlla che il mondo li regga:

| Profilo | Reddito lordo | Quota di R | Il mondo ne regge |
|---|---:|---:|---:|
| Principiante — a piedi, fascia bassa | 250.000 L/ora | 1,0 % | tantissimi |
| Medio — utilitaria, fascia media, rete minima | 1.200.000 L/ora | 5,0 % | 7 sulla sua fascia |
| Maturo — furgone, depositi, organico, fascia alta | 4.500.000 L/ora | 18,8 % | **meno di due** |

L'ultima riga è la verifica che conta, ed è quella che rende sensata la decisione 4 (PvP
pieno) dentro la decisione 3 (mondo eterno): **in cima non c'è posto per tutti.** La
fascia alta del mondo non regge due padroni a pieno regime. Non serve una regola che lo
imponga: lo dice l'aritmetica, e il conflitto che ne nasce è il contenuto del gioco.

Controprova sul lato opposto, la miscela realistica di una sera viva — sei principianti,
quattro giocatori medi, due maturi — usa il **66 %** del mondo: si sente stretto, si
litiga per i carichi, ma nessuno resta a secco. È la temperatura giusta.

#### Il debito come canone di presenza

L'interesse del 10 % al giorno sul debito iniziale di 5.500.000 fa **550.000 lire al
giorno**: a ritmo da principiante, **un'ora e quaranta di gioco al giorno solo per stare
in pari**. Sotto quella soglia il debito guadagna terreno; sopra, si cresce. Non è una
regola di presenza obbligata scritta da qualche parte — è una conseguenza dei numeri, ed è
il modo onesto di dire al giocatore quanto costa il posto che occupa. Col tetto a tre
volte il capitale (§5.1) saltare qualche giorno è caro, non letale.

#### Come il tetto diventa configurazione

**`R` è l'unica manopola.** Le giacenze e gli assorbimenti di ogni piazza non si scrivono
a mano: si *derivano*, invertendo il tetto.

```
A*(p,b) = quota(p,b) × R / spread_unitario(b)
```

dove le quote — per fascia, poi per bene, poi per piazza — sommano a 1. Cambiare `R`
riscala l'economia intera restando coerente; scrivere gli assorbimenti a mano farebbe
marcire l'invariante alla terza modifica. Se un giorno qualcuno propone di ritoccare
l'assorbimento di una singola piazza «perché è troppo povera», la risposta è: si tocca la
sua *quota*, che è relativa, e qualcun altro perde quello che lei guadagna.

Quote per fascia, e assorbimento che ne risulta:

| Fascia | Quota | Lire/ora | Beni | Unità assorbite all'ora, per piazza |
|---|---:|---:|---|---:|
| Bassa — sigarette, anfetamine, acidi | 25 % | 6,0 M | 3 | 11 · 17 · 16 |
| Media — hashish, marijuana, pasticche, farmaci | 35 % | 8,4 M | 4 | 9 · 9 · 10 · 6 |
| Alta — eroina, cocaina | 35 % | 8,4 M | 2 | 5 · 7 |
| Armi | 5 % | 1,2 M | 1 | 0,7 |

L'ultima colonna è il collaudo di giocabilità, ed è la **banda 3-30 unità l'ora**: sotto,
il mercato è un semaforo e un carico non si smaltisce mai; sopra, si può scaricare tutto
in una piazza sola e il vincolo sparisce. Tutti i beni ci stanno dentro tranne le armi,
che ne stanno fuori apposta: **meno di un pezzo all'ora per piazza** significa che
trovarne una è un evento, e venderne dieci è impossibile senza una rete.

#### La cosa che è venuta fuori facendo i conti

Non era prevista, ed è la scoperta migliore di questa calibrazione. **Il vincolo che lega
il giocatore cambia tre volte**, e ogni volta la risposta è diversa:

1. **A piedi, sei legato dallo spazio.** 80 spazi sono 26 stecche; i due milioni di
   partenza non riesci nemmeno a impiegarli. La risposta è *un mezzo*.
2. **Con l'utilitaria, sei legato dall'assorbimento.** 200 spazi sono 66 stecche, ma una
   piazza ne prende 11 all'ora: per smaltire un carico ti servono sei ore-piazza, e non
   esistono abbastanza piazze in un'ora di guida. La risposta non è un furgone più grande
   — è *salire di fascia*, dove ogni unità vale venti volte tanto.
3. **In fascia alta, sei legato dal capitale.** Un furgone di cocaina sono 180 milioni, e
   non li hai. La risposta è *il tempo, il credito e l'organizzazione*.

Tre prigioni diverse con tre chiavi diverse, e nessuna scritta a mano: escono tutte e tre
dalla stessa aritmetica. È la stessa struttura che regge il gioco del 1984 — dove il
vincolo passa dal denaro allo spazio (§5.3 dello studio) — con un gradino in più nel
mezzo, messo lì dal fatto che il mercato adesso è vero e si stanca.

#### Il tetto non cresce con i giocatori

Tentazione da respingere: far crescere `R` con la popolazione. Se il mondo si allarga
quando arriva gente, la scarsità sparisce e si torna alla fontana. La pressione
demografica **è** il contenuto.

L'unica crescita ammessa è quella *guadagnata*: rotte aperte, funzionari comprati, canali
d'importazione conquistati. Si modella come un moltiplicatore limitato,

```
R_effettivo = R_base × (1 + espansione)        con espansione ∈ [0 ; 0,30]
```

che dà alla comunità un obiettivo collettivo senza sfondare l'invariante. Facoltativo in
F2; se costa, si rimanda.

#### Verifica

`balance:report` deve stampare tre righe e trattarle come prove, non come statistiche:

1. **Somma teorica** — Σ A\*(p,b) × spread(b) su tutti i nodi, che deve tornare `R`
   entro il 2 %. Se non torna, le quote non sommano a 1 e qualcuno ha toccato una piazza
   a mano.
2. **Estrazione reale** — quanto i giocatori hanno davvero tirato fuori nelle ultime 24
   ore, dal registro delle transazioni. **Se supera `R`, c'è un baco o un exploit**, e il
   rapporto deve gridare: è l'unico invariante del gioco che non ammette eccezioni.
3. **Utilizzo** — il rapporto fra le due. Sotto il 15 % per un mese il mondo è troppo
   generoso e va stretto; sopra il 60 % per una settimana è ostile a chi arriva e va
   allargato.

Chiavi in `game_config`, modificabili a caldo dal pannello:

| Chiave | Valore | Significato |
|---|---:|---|
| `mondo.reddito_orario` | `16000000` | il tetto, in lire l'ora |
| `mondo.espansione_max` | `0.30` | quanto la comunità può allargare la torta |
| `mercato.margine_viaggio` | `0.30` | il ritmo del 1984, da cui discendono gli spread |
| `mercato.banda_min` / `banda_max` | `3` / `30` | la banda di assorbimento per piazza |

**Un'ultima onestà.** Il numero è sopravvissuto alla prima prova seria — il motore
costruito, un giocatore automatico che ci gioca contro, e la media che cade dove il
conto diceva. Restano fuori due cose: i giocatori veri, e gli **altri** giocatori. Un
solo simulatore non contende niente a nessuno, e tutto il §0.1a parla di quello che
succede quando si è in tanti. `R` resta una riga di `game_config` proprio perché
correggerlo dovrà costare dieci secondi.


#### 2.6.1 La ritaratura del 19/09/2026: da 24 a 16 milioni

**Perché.** Corretto il baco della prima mano del generatore pseudocasuale
(`src/Sim/Rng.php`: un generatore seminato per una sola estrazione restituiva numeri con
media 0,125 e mai sopra 0,25), il mercato ha cominciato a respirare come progettato — i
carichi arrivano il 12% delle ore invece del 48%, e la giacenza a riposo sta a 1,6 volte
l'equilibrio invece che a 4. A quel punto il principiante è risultato **molto sopra il
bersaglio**: mediana di 841.000 lire nella prima ora sulle nove città, contro le 250.000
di progetto. Il numero vecchio (160-300 k) era falsato da due bachi contemporaneamente:
quello del generatore, e lo strumento di misura che si avvelenava il mercato da solo
scrivendo `agg_a` nel futuro.

**Abbassare R da solo non funziona, ed è stato misurato.** La potatura di banda di
`mercato:semina` toglie i nodi che scenderebbero sotto l'assorbimento minimo e
**ridistribuisce la loro quota sui superstiti**: il mercato diventa più magro, ma i nodi
rimasti restano grassi, e il giocatore guadagna come prima. A `R = 6.000.000` con la banda
ferma a 3 restavano **24 nodi su 273** e il principiante guadagnava *di più* di prima. A
`R = 16.000.000` con la banda a 3, la potatura lascia **cinque piazze senza nemmeno un
bene** e il tetto reale scende a 12,16 milioni invece dei 16 chiesti. **La banda va
abbassata insieme a R**: da 3 a 2 unità l'ora.

**Le tre tarature misurate** (mediana della prima ora, tutte e nove le città, simulatore
del principiante su un mondo che respira da 96 ore):

| taratura | nodi | mediana | città peggiore | città sotto 100 k |
|---|---|---|---|---|
| `R = 24 M`, banda 3 | 273 | 841 k | 295 k | 0 |
| **`R = 16 M`, banda 2** | **273** | **584 k** | **214 k** | **0** |
| `R = 10 M`, banda 1 | 327 | 411 k | −1 k | 3 su 9 |

**La mediana non arriva a 250.000 e non ci può arrivare abbassando il tetto**, perché il
reddito della prima ora dipende dalla città molto più che da R: nella stessa taratura Roma
dà 1,55 milioni l'ora e Catania 214.000, sette volte meno. Un taglio abbastanza profondo da
portare Roma sul bersaglio ammazza Bari, Palermo e Bologna — a `R = 10 M` tre città su
nove diventano invivibili per chi comincia.

#### 2.6.2 La dispersione fra città: guardata, e lasciata stare

Sembrava la questione aperta da risolvere subito. **Non lo era**, e la misura lo dice: i
sette volte sono un fenomeno **della prima ora**, e si chiudono da soli col procedere della
partita.

| ore giocate | Roma | Milano | Catania |
|---|---|---|---|
| 1 | 1.541.525 | 609.181 | 202.240 |
| 3 | 1.402.256 | 736.162 | 556.904 |
| 6 | 1.000.844 | 920.841 | 855.505 |
| 12 | **764.911** | **793.692** | **714.786** |

Su dodici ore la forbice fra tutte e nove le città scende a **2,3 volte**, con otto su nove
strette fra 680.000 e 795.000 lire l'ora. E conta *come* converge: Roma **scende** mentre
il giocatore le consuma l'assorbimento, Catania **sale** mentre impara a uscire dalla
città. Non è una toppa — è la torta finita e il mercato condiviso del §0.1a che fanno
esattamente quello per cui esistono. La geografia è già uniforme (quattro piazze-fonte e
uno sbocco quasi ovunque; due sbocchi e una piazza in più solo a Milano, Napoli e Roma, che
sono le grandi), e `/inizio` dichiara già carattere, piazze e polizia di ogni città, quindi
la scelta iniziale non è cieca.

**Nota di metodo, che vale per tutti i numeri di questa sezione.** Il simulatore del
principiante è **deterministico**: tre esecuzioni sulla stessa città danno lo stesso
risultato alla lira. Quindi ogni cifra qui sopra — ritaratura a 16 milioni compresa — è *una
traiettoria di una sola strategia avida contro un solo stato del mercato*: un campione del
pavimento, non una distribuzione. Si vede dov'è il limite guardando Palermo, che a sei ore
fa 96.754 lire contro le 843.946 di Catania pur avendo la stessa identica composizione:
con lo strumento di adesso non si distingue una vera stranezza di quella città da una
traiettoria che imbocca male. Il lavoro che renderebbe affidabile ogni taratura futura non
è sulla geografia: è **far misurare allo strumento una distribuzione** — più strategie e
più stati di mercato — invece di un numero solo.

**Valutato il 19/09/2026 e deciso di NON farlo adesso**, perché renderebbe più preciso il
numero del *giocatore finto*, che non è il bersaglio. C'è anche una trappola: le strategie
da confrontare le sceglierebbe chi scrive lo strumento, quindi la varianza misurata sarebbe
quella delle nostre supposizioni su come si gioca, servita con l'aria di un intervallo di
confidenza. Meglio un numero secco etichettato per quello che è. E la decisione che quel
numero informa — `R` — sono **due righe di `game_config`** modificabili a caldo in dieci
secondi: per una cosa così reversibile, stringere le barre d'errore è sproporzionato.

Torna necessario in due casi, e conviene riconoscerli: **(1)** quando ci sono giocatori
veri e nasce una discussione su un numero, perché lì serve un metro condiviso; **(2)**
prima di qualunque modifica che *non* sia una chiave di configurazione — ritagliare la
tabella dei beni, rifare la geografia, cambiare le fasce. Quelle non si annullano in dieci
secondi. Se servisse una versione minima e onesta: tenere **ferma** la strategia e variare
solo lo stato del mondo (semi diversi, ore di respiro diverse), che misura la varianza del
gioco invece della nostra, e basterebbe a dire se Palermo è una stranezza vera.

E resta valido quello che il §9 dice da sempre: il bilanciamento vero si fa con i giocatori
veri. Tutto il resto si misura contro un giocatore finto.

Nota finale: il bersaglio delle 250.000 lire l'ora descrive **la prima ora**, quando il
giocatore è legato al capitale (300.000 lire). Passata quella, il vincolo cambia e il
reddito sale da solo: è la progressione, non uno sbilanciamento.
### 2.7 L'informazione

- **Sul posto**: prezzi esatti e in tempo reale.
- **Da lontano**: quotazioni riportate da un contatto, con ritardo ed errore funzione di
  quanto lo paghi e di quanto si fida. Non vedi mai tutto il paese in un colpo d'occhio.
- **La cronaca**: notiziario pubblico e ritardato che annuncia sequestri, carichi,
  arresti, cambi di regime.
- **La spia** presso un altro giocatore (§6.2).

Con informazione imperfetta la strategia ottima di dopewars (§4.8 dello studio) smette
di funzionare, ed è esattamente lo scopo.

---

## 3. Logistica

| Anello | Capacità | Velocità | Rischio |
|---|---:|---|---|
| Addosso | 20 | massima | perquisizione personale |
| Borsone | 60 | alta | controllo |
| Utilitaria | 200 | alta | posto di blocco |
| Berlina | 300 | alta | posto di blocco, ma meno sospetta |
| Furgone | 900 | media | posto di blocco, molto vistoso |
| Corriere (uomo) | 150 | lenta | può sparire, può parlare |
| Deposito | 5.000+ | fermo | razzia (rivali), perquisizione (legge) |

Il trench coat da 100 spazi del 1984 diventa questa catena. I depositi si affittano, si
pagano, e **concentrarli è un errore**: la razzia del deposito del door BBS (§3.2d) vale
qui quanto valeva là, e i rivali che sanno dove tieni la roba la vengono a prendere.

I carichi in transito sono **oggetti del mondo**: esistono su una rotta, con un orario di
arrivo, e possono essere intercettati (§6.2).

---

## 4. La legge

### 4.1 Calore

Due contatori a decadimento esponenziale:

- **Calore personale** `H_g` — tuo, ti segue ovunque.
- **Calore di piazza** `H_p` — del luogo, prodotto da chiunque. Si può **bruciare una
  piazza** a un rivale: è PvP economico senza sparare un colpo.

Incremento superlineare sul valore dell'operazione:

```
ΔH = k · (valore/soglia)^α        con α ≈ 1,5
```

Operare piccolo è quasi gratis, il colpo grosso si paga. È la soglia deterministica del
door del 1993 (§3.2b) resa continua, con la stessa promessa: **il rischio è una
conseguenza delle tue scelte, non un dado**. Il giocatore vede il proprio calore sempre,
e vede la soglia che sta per superare prima di superarla.

### 4.2 Presenza di polizia

Per piazza, dal 5 % al 90 % come in dopewars. Governa frequenza dei controlli, probabilità
che una rissa richiami una volante, costo della corruzione.

### 4.3 I fascicoli

Superata una soglia di calore personale si **apre un fascicolo**: un inquirente NPC con
nome, che accumula prove in tempo reale.

- Il giocatore **vede i segnali**: la stessa auto sotto casa, il cliente che fa domande,
  il corriere che non risponde, la telefonata che fa un rumore strano.
- Può reagire: corrompere, pagare un avvocato, stare fermo, spostare i depositi, cambiare
  città, sacrificare qualcuno.
- A maturazione: perquisizione, sequestro, **arresto**. Si perde merce, contante sporco
  non nascosto, uomini; si sta dentro per un tempo reale (ore o giorni, secondo il peso).
  Il carcere del door BBS, convertito da anni a ore.
- Con l'avvocato giusto e le prove giuste, un fascicolo si **archivia**. È uno degli
  obiettivi più belli da sbloccare.

### 4.4 Pubblico nemico numero N

Il profilo criminale personale, ereditato dal `danger` del 1993 e reso permanente. Sale
con la violenza, e non scende con il tempo: scende solo con la latitanza vera o con un
processo vinto. A profilo alto: posti di blocco sistematici, un inquirente dedicato, e
nessun fornitore serio che voglia parlare con te.

È il conto della decisione 4, ed è scritto in chiaro nella scheda del personaggio.

### 4.6 Come si misura, in pratica

I numeri con cui il §4 è stato acceso, verificati sui profili del §2.6:

| Profilo | Calore prodotto | Equilibrio | Cosa vuol dire |
|---|---:|---:|---|
| Principiante, 300.000 a operazione | 0,4 gradi/ora | **7** | nessuno ti guarda; fascicolo mai |
| Medio, 1.500.000 | 5,7 gradi/ora | **99** | ti stanno addosso; fascicolo aperto, blitz in ~50 ore |
| Maturo, 5.000.000 in fascia alta | 37 gradi/ora | **639** | fascicolo che matura in ~8 ore: o ci si difende o si cade |

L'equilibrio è il punto dove l'accumulo pareggia il decadimento — e si sposta in giù da
solo appena si rallenta, perché il dimezzamento è esponenziale. **Stare fermi funziona
davvero**, e funziona allo stesso modo da qualunque altezza si parta: è l'unica ragione
per cui «smettere in tempo» è una strategia e non una resa.

Il rischio di un controllo, per confronto: a Scampia da freddi è lo 0,3 %; a Brera da
freddi il 3 %; a Brera con la piazza a 40 e te a 80 è il 13 %; a Brera con tutto caldo e
due arresti alle spalle è l'80 %. Il numero è sempre visibile sulla pagina del fascicolo,
tradotto in parole, **prima** di agire.

### 4.5 I pentiti

Ogni uomo dell'organico ha **lealtà**, che cala se non lo paghi, se i colleghi muoiono,
se sta dentro, se qualcuno gli offre di più. Un uomo sleale che finisce in mano agli
inquirenti **collabora**, e il fascicolo fa un salto. È il ponte fra gestione del
personale e rischio, e il miglior gancio narrativo del periodo scelto.

---

## 5. Denaro sporco e denaro pulito

Il secondo sistema economico, che l'originale non aveva, e che nel mondo eterno è
indispensabile perché è il **pozzo proporzionale al reddito**.

- Il commercio produce **contante sporco**: ingombra fisicamente, si nasconde, si perde
  nei sequestri e nelle rapine, non compra niente di legale, **non conta in classifica**.
- Il **riciclaggio** lo converte in pulito attraverso canali, ognuno con commissione,
  capacità oraria e contributo al calore:

| Canale | Commissione | Capacità | Calore |
|---|---:|---|---|
| Il bar | 35 % | bassa | nullo |
| L'autolavaggio | 30 % | bassa | nullo |
| La sala giochi | 28 % | media | basso |
| Il cantiere | 22 % | alta | medio |
| Il totonero | 18 % | alta | medio |
| Il cambiavalute a Chiasso | 12 % | altissima | alto |
| La società estera | 8 % | illimitata | altissimo |

- Il pulito compra ciò che dura: immobili, veicoli, avvocati, protezioni, canali nuovi —
  e punteggio.

Gli altri pozzi permanenti: stipendi dell'organico, affitti dei depositi, pizzo sulle
piazze altrui, corruzione ricorrente (un funzionario pagato una volta non è pagato per
sempre), cauzioni e parcelle, manutenzione dei mezzi, merce che si deteriora.

### 5.1 L'usuraio

Il debito iniziale è il motore di apertura, come nel 1984: **10 % al giorno**, maturato
di continuo — e di continuo per davvero, `D(t) = D₀·(1+i)^(t/giorno)`, non a scatti a
mezzanotte. In un mondo senza turni uno scatto giornaliero significherebbe che chi ripaga
alle 23:59 non paga niente e chi ripaga all'una paga tutto.

**Il debito iniziale è sceso da 5.500.000 a 1.500.000 lire** quando il capitale di
partenza è passato a 300.000 (§2.6, verifica sul campo): la cifra del documento era
tarata su un capitale iniziale sei volte più alto, e lasciarla avrebbe messo il giocatore
diciotto volte sotto dal primo minuto. Con 1.500.000 l'interesse è di 150.000 al giorno,
cioè poco più di mezz'ora di gioco per stare in pari, e il debito si estingue in due o
tre giorni di gioco normale — oppure si allarga di proposito, che è il vero uso
dell'usuraio: farsi prestare per comprare il mezzo prima di potersela permettere. Con una correzione necessaria al tempo reale: **l'interesse si ferma a tre
volte il capitale**. Oltre, il debito non cresce più — cominciano le conseguenze. Prima
le minacce, poi gli uomini che si presentano al deposito, poi la gamba. Un numero che
va all'infinito mentre il giocatore è al lavoro non è tensione, è un bug con la cravatta.

---

## 6. Il personaggio e gli altri

### 6.1 Il personaggio

- **Attributi che crescono con l'uso**: *trattativa* (spread), *fiuto* (qualità e durata
  dell'informazione), *sangue freddo* (fughe, controlli, interrogatori), *organizzazione*
  (quanti uomini reggi), *credito* (condizioni con fornitori e usurai).
- **Reputazione su due assi ortogonali**: quanto ti **rispettano** (contratti, prezzi,
  reclutamento) e quanto ti **temono** (racket, deterrenza — ma anche calore). Si possono
  giocare in modo opposto, e sono due gioconi diversi.
- **Contatti e fornitori**: si sbloccano per merito e reputazione, dal tizio del
  parcheggio al canale d'importazione con lotti minimi, anticipi e obblighi. È la
  progressione di lungo periodo.
- **Organico**: vedetta, corriere, guardia, contabile, avvocato, riciclatore, basista.
  Nome, competenza, lealtà, stipendio, storia personale. Muoiono, vengono arrestati,
  tradiscono.

### 6.1.1 Come cresce, in pratica

Gli attributi salgono con l'uso e con rendimenti calanti: da zero si guadagna un grado
e mezzo per operazione, da novanta quasi niente. Ne esce questa curva sull'organico —
cioè su quanti uomini si riesce a tenere insieme — a due operazioni e mezza l'ora:

| Uomini | Organizzazione | Ore di gioco attivo |
|---:|---:|---:|
| 2 | 10 | 6 |
| 3 | 20 | 13 |
| 4 | 30 | 21 |
| 5 | 40 | 32 |
| 6 | 50 | 44 |

**L'organizzazione non cresce assumendo.** Sembrava ovvio che assumere insegnasse a
gestire, e invece era un vicolo cieco: all'inizio si regge un uomo solo, quindi se
crescesse solo assumendo il tetto non si alzerebbe mai. Cresce mandando avanti un giro —
comprando, vendendo, e pagando puntuali gli stipendi. È il genere di cosa che sulla carta
sembra che si alimenti da sola e che si vede solo giocandoci.

**I fornitori si tengono tutti, non solo il migliore.** Stessa lezione: sostituire il
fornitore piccolo con quello grande appena si sblocca sembrava naturale, ma quelli grandi
chiedono lotti grandi — e su un ordine da trenta unità il fornitore da ottanta non serve
a niente. Tenendo solo il migliore, *progredire peggiorava*. Ora vince, fra quelli che ti
parlano, il migliore **fra quelli che accettano quella quantità**.

### 6.2 Il multigiocatore

**Attrito economico** (il livello principale, e non costa una riga di codice dedicata:
emerge dal §2): la corsa al carico appena arrivato, il crollo del prezzo dove il rivale
deve vendere, la piazza bruciata di calore, il fornitore preso in esclusiva, la guerra
dei prezzi.

**Attrito sporco**:
- **Soffiata** (da dopewars): dirigi gli inquirenti su un giocatore preciso. Costa, e se
  scoperta ti si ritorce contro.
- **Spia**: un tuo uomo infiltrato ti mostra i suoi conti, finché non lo scoprono —
  quattro esiti possibili, compreso il voltafaccia, come nell'originale.
- **Rapina del carico**: si colpisce la merce in transito su una rotta nota. Si perde un
  carico, non una vita.
- **Corruzione contesa**: lo stesso funzionario può essere pagato da due giocatori. Vince
  chi offre di più, e l'altro lo scopre nel modo peggiore.

**Violenza diretta** (decisione 4, PvP pieno): chiunque può attaccare chiunque dove si
trova. Scheletro di combattimento derivato da dopewars (§4.6 dello studio): valori di
attacco e difesa, tiri contrapposti, armi che sommano danno, guardie del corpo che
assorbono. Bottino: **tutto ciò che la vittima aveva addosso** — contante sporco, merce,
armi, veicolo. Non il pulito, non gli immobili, non i canali (§0.1b). La morte porta a
ospedale e convalescenza in tempo reale, non alla cancellazione del personaggio.

Il conto si paga in §4.4.

**Batterie** (clan): cassa comune, depositi condivisi, avvocato di gruppo, canale di
riciclaggio migliore.

**Territorio**: una piazza si controlla con presenza e investimento, non con un pulsante.
Chi la controlla incassa una percentuale sulle transazioni altrui, ha spread migliore e
riceve preavviso sui blitz. Le guerre fra batterie si dichiarano, costano, e si vincono
spostando il controllo.

---

## 7. Le sezioni

| Sezione | Contenuto |
|---|---|
| **Piazza** | listino del luogo dove sei: compra, vendi, servizi |
| **Mappa** | città e piazze su Canvas: controllo, calore, spostamenti in corso |
| **Magazzino** | depositi, carichi in transito, mezzi |
| **Organico** | uomini, ruoli, lealtà, stipendi |
| **Contabilità** | sporco/pulito, canali, debiti, spese fisse, bilancio |
| **Rete** | contatti, fornitori, informatori, quotazioni riportate |
| **Batteria** | clan: cassa, membri, territori, guerre |
| **Cronaca** | il notiziario del mondo condiviso |
| **Fascicolo** | cosa sa di te la legge, e cosa puoi farci |
| **Profilo** | personaggio, attributi, reputazione, storia |
| **Classifica** | patrimonio pulito, territorio, longevità, colpi |
| **Statistiche** | i numeri del mondo e i tuoi |
| **Obiettivi** | onorificenze e traguardi |
| **Amministrazione** | mondo, giocatori, parametri a caldo, registro azioni |

### 7.1 La classifica, nel mondo eterno

Quattro graduatorie distinte, perché una sola in un mondo che non riparte premia solo chi
è arrivato primo:

1. **Patrimonio** — denaro pulito consolidato. La classifica classica.
2. **Territorio** — piazze controllate, da soli o in batteria. Cambia in continuazione.
3. **Reddito del mese** — quanto hai estratto negli ultimi 30 giorni reali. **È quella
   che conta davvero**: è contendibile da chiunque, sempre.
4. **Longevità** — giorni senza arresti né ricoveri, a profilo criminale alto.

Più un **albo d'oro** permanente per chi ha tenuto un primato almeno un mese.

---

## 8. Architettura tecnica

Linea SubSpazio → Atlantik, che ha già dato prova sul campo.

- **PHP 8.4** senza framework, front controller unico, rotte in italiano.
- **MariaDB**, database e utente dedicati, migrazioni numerate in `db/`.
- **Core portato da Atlantik**: `Config`, `Database`, `Router`, `Session`, `Csrf`, `View`,
  `Mailer`, `Posta`, `RateLimiter`, `GameConfig`, `Audit`, `Lock`.
- **JS vanilla + Canvas**, nessun *build step*.
- **Tick da cron** (`bin/tick.php`, ogni minuto) + **avanzamento pigro deterministico**
  sulle richieste web.
- **Rng xorshift64** come in Atlantik — mai moltiplicazioni a 64 bit in PHP, traboccano
  in float e rompono il determinismo.
- **Mail Brevo** riusando il mittente verificato del forum; verifica dell'indirizzo
  obbligatoria alla registrazione, avviso all'amministratore.
- **Nomi utente con spazi ammessi** e password da 9 caratteri, come in Atlantik.
- **Console** `bin/console.php`: `migrate`, `status`, `user:*`, `mail:test`, `market:*`,
  `balance:report`.
- **Prove**: unitarie sui moduli puri (il mercato è matematica, si testa davvero) più
  end-to-end su autenticazione, mercato e amministrazione.
- Percorsi: codice in `/data/html/<nome>`, segreti in `/data/<nome>-config/config.php`,
  messa in opera con `deploy/00-bootstrap.sh` idempotente da eseguire a mano con sudo —
  nessuna modifica ai vhost, `conf-available` + `a2enconf`.

### 8.1 I moduli puri (`src/Sim/`)

| Modulo | Responsabilità |
|---|---|
| `Rng` | xorshift64 deterministico |
| `Clock` | tempo del mondo, avanzamento pigro |
| `Prezzi` | passeggiata OU dei riferimenti, regimi |
| `Mercato` | stato (piazza,bene), formazione del prezzo, esecuzione degli ordini con impatto |
| `Rifornimento` | carichi, consumo NPC, rigenerazione di `S` e `A` |
| `Calore` | accumulo superlineare, decadimento, soglie |
| `Indagine` | fascicoli, prove, segnali, maturazione |
| `Trasporto` | rotte, tempi, intercettazioni, posti di blocco |
| `Riciclaggio` | canali, capacità oraria, commissioni |
| `Scontro` | combattimento e bottino |
| `Territorio` | controllo, pizzo, contesa |
| `Cronaca` | generazione del notiziario |

**`Mercato` si scrive per primo e con la massima cura**: è puro, è testabile, ed è il
gioco.

---

## 9. Roadmap a fasi

| Fase | Contenuto | Verifica di fine fase |
|---|---|---|
| **F0** ✔ | Fondamenta: core portato, autenticazione con verifica Brevo, profilo, scheletro admin, console, migrazioni | prova e2e di registrazione, verifica, accesso — **fatta il 19/09/2026** |
| **F1** ✔ | Mondo: città, piazze, geografia a due livelli, viaggi in tempo reale, clock e tick, mappa Canvas | un giocatore si muove fra 9 città e 43 piazze, il tempo scorre — **fatta il 19/09/2026** |
| **F2** ✔ | **Mercato**: `Prezzi`, `Mercato`, `Rifornimento`, ordini con impatto, listino di piazza, compravendita | prove unitarie sul motore e simulazione di un principiante contro il motore vero — **fatta il 19/09/2026** |
| **F3** ✔ | Denaro e logistica: depositi, mezzi, sporco/pulito, riciclaggio, usuraio, spese fisse | un giocatore completa un ciclo economico intero e il bilancio quadra — **fatta il 19/09/2026** (i carichi affidati a terzi slittano a F5, con l'organico) |
| **F4** ✔ | Legge: calore, controlli, posti di blocco, fascicoli, perquisizioni, arresti, carcere, avvocati, corruzione | un giocatore che esagera viene visto, avvisato, e preso — **fatta il 19/09/2026** (i pentiti slittano a F5, con l'organico) |
| **F5** ✔ | Personaggio: attributi, reputazione a due assi, organico con lealtà, pentiti, corrieri, fornitori | la progressione è leggibile su 20 ore di gioco simulato — **fatta il 19/09/2026** |
| **F6** ✔ | Multigiocatore: PvP e bottino, pubblico nemico, rapine ai carichi, soffiate, spie, batterie, territorio, cronaca | due account che si fanno la guerra in tutti i modi previsti — **fatta il 19/09/2026** |
| **F7** ✔ | Rifinitura: obiettivi, quattro classifiche, albo d'oro, statistiche, admin completo, PWA, `balance:report` | un giro di bilanciamento con il rapporto che conferma il tetto di reddito — **fatta il 19/09/2026** |

---

## 10. Questioni aperte

1. ~~Il nome.~~ **Deciso il 19/09/2026: *Piazza Pulita*** — la piazza di spaccio, il
   riciclaggio e il modo di dire, tutti e tre nella stessa insegna.
2. **Quanto in là spingere il realismo dei nomi di quartiere** (§1.1).
3. ~~Il tetto di reddito orario del mondo.~~ **Calibrato il 19/09/2026 a 24 milioni**
   (§2.6), **ritarato lo stesso giorno a 16 milioni** dopo la correzione del generatore
   pseudocasuale (§2.6.1). Da riverificare con giocatori veri.
5. ~~La dispersione del reddito fra le città.~~ **Guardata il 19/09/2026 e lasciata
   stare** (§2.6.2): la forbice è un fenomeno della prima ora e si chiude da sola in una
   sessione (2,3× su dodici ore). Quello che resta da fare non è sulla geografia ma sullo
   **strumento di misura**, che oggi restituisce una traiettoria deterministica invece di
   una distribuzione.
4. ~~Protezione dei nuovi arrivati.~~ **Decisa il 19/09/2026 con F6: la soglia di
   bottino, senza immunità.** Sotto `pvp.bottino_minimo` (mezzo milione fra contante e
   merce addosso) **non si prende niente a nessuno**: la vittima non perde una lira e
   l'aggressore incassa zero, mentre calore e profilo criminale li paga pieni. Cacciare i
   principianti resta possibile — la decisione 4 dice PvP pieno e non la si contraddice —
   ed è semplicemente l'attività peggio pagata del gioco. La prova end-to-end la misura
   come invariante (`tests/e2e_rivalita.sh`), non come intenzione.

---

## 11. F6 — il giro degli altri

Fin qui il multigiocatore c'era già, ma solo come **conseguenza**: il mercato è condiviso,
quindi chi compra prima alza il prezzo a chi viene dopo e chi vende troppo lo fa crollare
a tutti. È l'attrito che non costa una riga di codice, ed è il livello principale (§6.2).
F6 aggiunge quello che va scritto: colpire una persona invece di un prezzo.

**Lo scontro** (`Sim/Scontro`, modulo puro). Scheletro di dopewars: due punteggi, due
estrazioni contrapposte, il danno che somma un tiro per arma. Le armi sono **merce
addosso**, non una statistica — si comprano al listino come tutto il resto, e chi le porta
le perde se lo pestano. Le guardie dell'organico sparano e incassano. Sei round al
massimo: è una rissa per strada, non un duello. Misurato: tre armi contro un disarmato
colpiscono il 70 % delle volte per ~64 di danno; contro due guardie e sangue freddo 60
scendono al 49 % per 38.

Quello che cambia rispetto all'originale è **il bottino**. In dopewars chi vinceva si
prendeva tutto: contante, banca, merce, armi. In una partita da venti minuti va benissimo;
su un personaggio costruito in tre mesi è la fine del gioco per la vittima e, dopo un po',
per il server. Qui si prende **solo ciò che la vittima aveva addosso** — il pulito è
intestato, i canali e gli immobili pure — e sotto la soglia non c'è niente da prendere
(§10.4). Il prezzo lo paga il §4.4: ogni aggressione vale un punto di **profilo criminale**,
e quello non scende col tempo.

**La soffiata** (2 milioni): una telefonata che porta 22 prove al fascicolo di un altro.
Una volta su quattro la registrano, e le prove se le prende chi ha chiamato.

**La spia**: un proprio uomo mandato dentro casa d'altri (3 milioni, e l'uomo lo si perde
comunque). Finché non la scoprono si vede quello che vede lei — contante, pulito, debito,
calore, uomini, carico. Quando la scoprono, quattro esiti come nell'originale: uccisa,
scappata, voltafaccia, o semplicemente finita.

**La rapina al carico**: le corse dei corrieri altrui che passano per la piazza si possono
fermare. Non manda nessuno all'ospedale — è il modo di farsi male a vicenda che lascia
tutti in piedi — ma scotta lo stesso.

**Le batterie e il territorio.** Fondare costa 10 milioni **puliti**; la cassa comune è
contante e ne preleva solo il capo. Il territorio **non si conquista premendo un
pulsante**: ogni lira movimentata in una piazza lascia punti di presenza alla batteria di
chi l'ha movimentata, la presenza si dimezza ogni 72 ore, e sopra i 150 punti la piazza
passa. Chi comanda incassa il 4 % su quello che ci trattano gli estranei. È la stessa
logica del mercato applicata alle persone: niente dichiarazioni, solo conseguenze di quello
che si è fatto davvero — e un territorio va **tenuto**, non preso una volta.

**La cronaca**: il notiziario del mondo, uguale per tutti. Senza, metà di quello che
succede sarebbe invisibile, e un mondo condiviso che non si vede tanto vale non averlo.

---

## 12. F7 — la rifinitura

**Le quattro graduatorie** (§7.1) sono in piedi, e quella preselezionata è il **reddito**
degli ultimi trenta giorni, non il patrimonio: è l'unica su cui il passato non pesa, e
quindi l'unica contendibile da chiunque, sempre. La longevità conta i giorni senza arresti
né ospedale **ma solo sopra profilo criminale 5**: senza quella soglia la vincerebbe chi
non ha mai fatto niente, che è l'esatto contrario di quello che dovrebbe premiare.

**L'albo d'oro** chiude il discorso dal lato opposto: un primato perso sparisce dalla
classifica, e senza un albo il tempo passato in cima si cancellerebbe nel momento in cui
qualcuno ti supera. Ci si entra tenendo una graduatoria per almeno trenta giorni, e il
**nome ci resta scritto dentro come copia**, perché l'albo deve sopravvivere alla
cancellazione dell'account — come il registro delle azioni. Il battito guarda i primati a
ogni giro ma **chiude un regno solo quando il primo cambia davvero**: un primato che cambia
ogni cinque minuti non è un primato.

**Gli obiettivi** (24, in quattro gruppi) hanno due regole che valgono per tutti:

1. Si sbloccano da **fatti già registrati** — transazioni, movimenti, fascicoli, scheda —
   e mai da contatori scritti apposta. Se un obiettivo avesse bisogno di un contatore suo,
   vorrebbe dire che misura qualcosa che il gioco non stava già facendo.
2. **Non danno vantaggi.** In un mondo a reddito orario finito (§2.6) un premio in denaro
   lo pagherebbero gli altri giocatori senza saperlo. Sono una traccia, e basta.

Il catalogo sta nel **codice** e non in una tabella: un obiettivo è una condizione, e una
condizione dentro il database diventa presto una lingua di programmazione scritta male.
La verifica costa una manciata di aggregati, quindi si fa aprendo la pagina e dal battito
per chi è stato visto negli ultimi dieci minuti — non a ogni richiesta.

**Le statistiche** leggono tutto dalle righe che il gioco scrive comunque. L'unico numero
che è anche una prova è l'utilizzo del tetto: sopra il 100 % non è una statistica
interessante, è un exploit.

**`balance:report` sa contare i giocatori.** Prima diceva sempre «troppo generoso», perché
con un solo giocatore l'utilizzo è vicino a zero — e non era un difetto di taratura: era
che non estraeva nessuno. Adesso, sotto tre giocatori attivi, dichiara la riga non
misurabile e mostra invece il **reddito per giocatore**, da confrontare con i profili del
§2.6 (250 k / 1,2 M / 4,5 M l'ora). È il numero con cui si fa la taratura vera.

**L'amministrazione** ha la plancia del mondo (piazze, calore, chi le tiene, l'invariante
del tetto) e quella dei giocatori. Nessuna azione crea denaro: un amministratore che regala
contanti in un mondo a torta finita li toglie a tutti gli altri senza che nessuno se ne
accorga. Si ripara, non si premia.

**Installabile (PWA)**, con una scelta dichiarata: **le pagine non si mettono in cache,
mai**. Lo stato di questo gioco sta sul server ed è autoritativo; una pagina servita dalla
cache mostrerebbe un mondo che non esiste più, e un listino vecchio di dieci minuti non è
degradazione elegante — è una bugia su cui qualcuno prende una decisione. Si tiene da parte
solo l'immutabile (fogli di stile, script, icone) più una pagina che dice che non c'è linea.
