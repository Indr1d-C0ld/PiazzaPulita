# Drug Wars — studio delle fonti originali

> Documento di analisi preliminare al progetto di trasposizione in browsergame
> persistente e multigiocatore. Redatto il 19/09/2026.
>
> **Metodo**: nessuna sintesi di terze parti è stata usata come fonte di dati numerici.
> Tutti i valori riportati qui sono stati estratti direttamente da:
> 1. l'eseguibile originale `DRUGWAR.EXE` (John E. Dell, 1984), via estrazione delle
>    stringhe dal binario BASIC compilato;
> 2. il sorgente C completo del door BBS `DOPEWARS.C` (1993, 2405 righe);
> 3. il sorgente C completo di `dopewars` 1.6.2 di Ben Webb (1998-2022, ~14.000 righe),
>    l'unica versione storica con vero multigiocatore in rete.
>
> Le copie di lavoro sono in `fonti/`. Le enciclopedie in rete (Wikipedia, Break Into
> Chat) sono state usate **solo** per la cronologia e la genealogia delle versioni, mai
> per i numeri: dove la voce di Wikipedia e il sorgente divergono, qui vale il sorgente
> (e la divergenza è segnalata).

---

## Nota sul contenuto

Drug Wars è un artefatto del 1984: un esercizio scolastico di simulazione economica a
cui il diciassettenne John E. Dell diede il tema più provocatorio che gli venisse in
mente. Il gioco che ne è uscito è, sotto la vernice, una **macchina di arbitraggio
pura**: comprare dove costa poco, vendere dove costa tanto, con un orologio che scade e
un creditore che ti fiata sul collo. La stessa struttura formale regge *Taipan!* (1979,
oppio a Hong Kong), *Star Trader* (1974, merci fra sistemi stellari) e — vent'anni dopo —
il commercio di *Grand Theft Auto: Chinatown Wars*.

Le fonti primarie contengono materiale datato: il door BBS del 1993 in particolare è
scritto in un finto slang afroamericano da caricatura che oggi è semplicemente razzista,
e il testo del 1984 ha la goliardia scolastica del suo tempo. Questo studio riporta il
contenuto com'è, perché serve capire *cosa* facevano quelle versioni; il gioco che
costruiremo non ne eredita né il registro né le caricature. Le sostanze sono, nel
modello, **beni con un prezzo, una massa e un rischio associato**: nessuna fonte
originale contiene, e il nostro gioco non conterrà, nulla di operativo sul mondo reale.

---

## 1. Genealogia

| Anno | Titolo | Autore | Piattaforma | Apporto |
|---|---|---|---|---|
| 1974 | *Star Trader* | Dave Kaufman | BASIC | archetipo: merci, rotte, prezzi variabili |
| 1979 | *Taipan!* | Art Canfil | Apple II | l'ispirazione dichiarata da Dell |
| **1984** | **Drug Wars** | **John E. Dell** | **IBM PC (BASIC compilato)** | **il gioco: 6 sostanze, 6 quartieri, 30 giorni, strozzino** |
| ~1990 | *Dope Wars* | "Happy Hacker Foundation" | DOS | interfaccia ANSI, più quartieri |
| 1991-93 | *Dopewars* (door BBS) | anonimo (sorgente in `fonti/`) | DOS door | riscrittura in C, "danger", prigione, stash raid |
| 1992 | *Drug Lord* | Fred Bulback | DOS | clone con varianti |
| 1995 | *PimpWars* | — | TI-82 | prima ibridazione RPG |
| 1998 | *Drugwars* | Michael Swain | TI-83 | la diffusione di massa scolastica |
| 1999 | *DrugWars 2: International* | JJR Software | TI-83 | elementi RPG espliciti, combattimenti |
| 1998-2005 | *Dope Wars for Windows* | Beermat Software | Win32 | la versione più scaricata in assoluto (6,5 M) |
| **1998-2022** | **dopewars** | **Ben Webb** | **Unix/Win/GTK, GPL** | **client/server, PvP, spie, soffiate: l'unico multigiocatore vero** |
| 2008 | *Dope Wars* | Zynga | MySpace/Facebook | prima trasposizione social/persistente (chiusa nel 2009) |
| 2009 | *GTA: Chinatown Wars* | Rockstar | NDS | il commercio come minigioco dentro un mondo aperto |

Due rami contano davvero per noi: **il 1984** (la forma canonica, minima e perfetta) e
**dopewars di Webb** (l'unico che abbia affrontato il problema "e se ci fossero altri
giocatori?", e le cui soluzioni sono istruttive anche dove falliscono).

---

## 2. Il gioco del 1984 — dati esatti

Estratti da `fonti/originali/x/drugwars/drugwar.exe`. Il testo delle istruzioni,
integrale:

> *This is a game of buying, selling, and fighting. The object of the game is to pay off
> your debt to the loan shark. Then, make as much money as you can in a 1 month period.
> If you deal too heavily in drugs, you might run into the police !! Your main drug
> stash will be in the Bronx. (It's a nice neighborhood)*

### 2.1 Le sei sostanze e le loro forchette di prezzo

Tabella dichiarata dal gioco stesso nella schermata di istruzioni:

| Sostanza | Prezzo minimo | Prezzo massimo | Rapporto max/min |
|---|---:|---:|---:|
| Cocaine | 15 000 | 30 000 | 2,0× |
| Heroin | 5 000 | 14 000 | 2,8× |
| Acid | 1 000 | 4 500 | 4,5× |
| Weed | 300 | 900 | 3,0× |
| Speed | 70 | 250 | 3,6× |
| Ludes | 10 | 60 | 6,0× |

Due osservazioni che contano per la progettazione:

- **Il rapporto max/min cresce al calare del prezzo**. I beni poveri sono *più* volatili
  in percentuale, i beni ricchi meno. È questo, e non altro, a rendere giocabile
  l'inizio partita: con 2 000 $ compri ludes a 10 e le rivendi a 60, moltiplicando per 6
  il capitale in due giorni. Con la cocaina il massimo teorico è ×2.
- **Le forchette non si sovrappongono**. Ogni sostanza vive in una sua fascia di
  ricchezza: è una scala di progressione mascherata da listino.

### 2.2 I luoghi

Sei quartieri di New York: **Bronx, Ghetto, Central Park, Manhattan, Coney Island,
Brooklyn**. Più la **Subway**, che non è un luogo ma lo schermo di transito dove
avvengono gli eventi casuali. Nel 1984 i quartieri sono **puramente nominali**: non
hanno né una presenza di polizia diversa né un listino diverso. Spostarsi costa un
giorno; il prezzo viene ritirato a caso, ovunque, con la stessa distribuzione.

La sola asimmetria geografica è lo **stash nel Bronx**: il deposito dove scaricare la
merce che non entra nel trench coat sta in un quartiere fisso, e questo crea l'unica
rotta obbligata del gioco.

### 2.3 Stato del giocatore

| Voce | Valore iniziale |
|---|---|
| Cash | 2 000 $ |
| Debito con lo strozzino | 5 500 $ (2 000 nel door BBS) |
| Banca | 0 |
| Trench coat (capienza) | 100 unità |
| Armi | 0 |
| Salute | integra; si muore al decimo colpo incassato |
| Durata | 30 giorni |

Interesse sul debito: **10 % al giorno, composto**. Interesse bancario: **5 % al
giorno**. Un debito di 5 500 $ lasciato correre 30 giorni diventa 95 969 $ — la
pressione è esponenziale e il giocatore lo capisce entro il terzo giorno.

### 2.4 Le armi

Quattro, offerte casualmente durante i viaggi (non esiste un'armeria nel 1984):
**Saturday Night Special, Ruger, .38 Special, Baretta**. Servono solo a poter scegliere
"fight" contro la polizia.

### 2.5 La polizia

Un solo antagonista: **Officer Hardass**, con un numero variabile di *deputies*.
L'incontro è casuale. Le opzioni sono *run* o *fight*; senza arma si può solo scappare.
Uccidendo Hardass si trova denaro "on Officer Hardass' carcas". Dopo uno scontro con
feriti compare il medico, che ricuce a pagamento.

### 2.6 Gli eventi casuali — l'elenco completo del 1984

Testuale, dal binario:

1. `YOU WERE MUGGED IN THE SUBWAY !!` — perdita di contante.
2. `COPS MADE A BIG COKE BUST !! PRICES ARE OUTRAGEOUS !!` — cocaina al rialzo.
3. `COLOMBIAN FREIGHTER DUSTED THE COAST GUARD !! WEED PRICES HAVE BOTTOMED OUT !!` — erba al ribasso.
4. `POLICE DOGS CHASE YOU FOR n BLOCKS !! YOU DROPPED SOME DRUGS !!` — perdita di merce.
5. `YOUR MAMA MADE SOME BROWNIES AND USED YOUR WEED !! THEY WERE GREAT !!` — perdita di erba.
6. `PIGS ARE SELLING CHEAP HEROIN FROM LAST WEEKS RAID !!` — eroina al ribasso.
7. `YOU FIND n UNITS OF x ON A DEAD DUDE IN THE SUBWAY !!` — merce gratis.
8. `THERE IS SOME WEED THAT SMELLS LIKE PARAQUAT HERE !! WILL YOU SMOKE IT ?` — se accetti, **muori** (`YOU HALUCINATE FOR THREE DAYS... THEN YOU DIE BECAUSE YOUR BRAIN HAS DISINTEGRATED`).
9. `RIVAL DRUG DEALERS RAIDED A PHARMACY AND ARE SELLING CHEAP LUDES !!!`
10. `ADDICTS ARE BUYING HEROIN AT OUTRAGEOUS PRICES !!`
11. `THE MARKET HAS BEEN FLOODED WITH CHEAP HOME MADE ACID !!!`
12. Offerta di un trench coat più capiente, a pagamento.
13. Offerta di un'arma, a pagamento.

Gli eventi 2-3, 6, 9-11 sono **shock di prezzo mirati su una singola sostanza**: sono
il meccanismo che trasforma la passeggiata casuale in occasione riconoscibile. Il
giocatore impara a memoria le frasi e sa, leggendole, se è il momento di svuotare lo
stash.

### 2.7 Il punteggio

`ON A SCALE OF 1 TO 100 YOUR RATING IS ...`: il patrimonio finale in milioni × 2, con
50 000 000 $ = 100/100. Classifica su file `BEST.WAR` con nome e rating.

---

## 3. Il door BBS del 1993 — dati esatti

`fonti/originali/x/dopewars/DOPEWARS.C`, 2405 righe di C per Turbo C, con musica da
PC speaker (*Axel F*, *Battery* dei Metallica, *Back in Black*). È una **riscrittura
completa**, ambientata a Washington DC, che aggiunge cinque meccaniche che il 1984 non
aveva e che sono, tutte e cinque, direttamente riusabili.

### 3.1 Dati

```c
drug_name[6] = {"Coke","Smack","Dust","Acid","Herb","Rocks"};
city_name[6] = {"Rockville","Northwest","Southeast","Arlington","The Mall","Alexandria"};
gun_name[6]  = {"AK-47","9mm pistol","Uzi SMG","45 automatic","Beretta","12 gauge pump"};
```

Prezzi (riga 312, `randomize_price`): Coke 15 001-30 000 · Smack 5 001-15 000 ·
Dust 5 001-10 000 · Acid 1 001-5 000 · Herb 101-250 · Rocks 26-75.

Stato iniziale (riga 287): cash 2 000, debito 5 000, tasche 100, salute 100,
giorni 30, **danger 500**.

### 3.2 Le cinque aggiunte

**(a) Il "danger" — pubblico nemico numero N.** Un contatore che parte da 500 e
*scende* ogni volta che uccidi un poliziotto. Il numero di agenti che ti affrontano è
`1d((501 − danger)/10 + 1) + 1`: a danger 500 ne arriva uno, a danger 1 ne arrivano
fino a 51. È la prima **spirale di reputazione criminale** della serie: più sei
temibile, più il mondo ti si rivolta contro. Il gioco te lo dice in faccia, lampeggiando:
`You is public enemy number %u!`

**(b) La polizia con innesco deterministico.** Riga 1432: gli sbirri arrivano **se e
solo se** almeno una di queste è vera:

```c
last_cost   >= 1 000 000    /* hai movimentato un milione in un colpo */
last_volume >= 100          /* hai movimentato 100 unità in un colpo  */
cash        >= 10 000 000   /* giri con dieci milioni addosso         */
total       >= 1 000        /* giri con mille unità addosso           */
```

Questa è la meccanica più intelligente di tutta la serie, e nessuna versione successiva
l'ha ripresa: **il rischio non è casuale, è una conseguenza misurabile del tuo
comportamento**. Il giocatore può scegliere di restare sotto soglia, spezzettando le
operazioni. È esattamente il calore di settore che in Atlantik governa la reazione
alleata, applicato al mercato.

**(c) La prigione.** Se ti prendono: `1d10` **anni** (`days_used += random*365`), armi
sequestrate, conto in banca azzerato, merce persa, danger che risale. Superati 20 anni
totali il personaggio si ritira d'ufficio (`retired()`). Vinnie, con grazia, non applica
interessi durante la detenzione.

**(d) Lo stash raid.** Se il deposito supera 100 unità e tu non sei in città, il 5 % dei
turni te lo dimezzano. **Un limite morbido all'accumulo**, e la ragione per distribuire
la merce.

**(e) Le bollette.** Ogni 30 giorni, 3 000 $ di spese fisse; se non li hai, Vinnie te li
presta d'ufficio e il debito riparte. Il primo **pozzo di denaro** ricorrente della
serie.

Altri dettagli del door: la metropolitana costa 3 $ e se non li hai fai l'autostop
aspettando un passaggio; la banca può essere rapinata (1 % se hai più di un milione);
il cappotto si strappa (−10 tasche); le armi vengono rubate; il contabile di Vinnie
sbaglia i conti e ti dimezza il debito (1 %).

---

## 4. dopewars di Ben Webb (1998-2022) — dati e algoritmi esatti

`fonti/dopewars/`. È il riferimento tecnico: GPL, ancora manutenuto, con architettura
client/server e un protocollo testuale documentato. Le costanti sono tutte
sovrascrivibili da file di configurazione — il gioco è di fatto un **motore
parametrico**, e la mod `example-igneous` inclusa nella distribuzione lo riconfigura
con 87 località, 25 sostanze, 17 armi e partita infinita.

### 4.1 Costanti globali (`src/dopewars.c`)

| Parametro | Valore | Riga |
|---|---:|---|
| `StartCash` | 2 000 | 107 |
| `StartDebt` | 5 500 | 107 |
| `DebtInterest` | 10 %/giorno | 105 |
| `BankInterest` | 5 %/giorno | 105 |
| `NumTurns` | 31 | 226 |
| `PlayerArmor` | 100 | 228 |
| `BitchArmor` | 50 | 228 |
| `Prices.Spy` | 20 000 | 204 |
| `Prices.Tipoff` | 10 000 | 204 |
| `Bitch.MinPrice / MaxPrice` | 50 000 / 150 000 | 207 |
| capienza iniziale | 100 | 882 |
| capienza per "bitch" | +10 | 3545 (serverside) |
| trench coat in modalità antiquariato | 200-300 $ | serverside:64 |
| data d'inizio | 1º dicembre 1984 | 86 |

### 4.2 Le dodici sostanze (`DefaultDrug[]`, riga 717)

| Sostanza | Min | Max | Può crollare | Può impennare |
|---|---:|---:|:-:|:-:|
| Acid | 1 000 | 4 400 | sì | — |
| Cocaine | 15 000 | 29 000 | — | sì |
| Hashish | 480 | 1 280 | sì | — |
| Heroin | 5 500 | 13 000 | — | sì |
| Ludes | 11 | 60 | sì | — |
| MDA | 1 500 | 4 400 | — | — |
| Opium | 540 | 1 250 | — | sì |
| PCP | 1 000 | 2 500 | — | — |
| Peyote | 220 | 700 | — | — |
| Shrooms | 630 | 1 300 | — | — |
| Speed | 90 | 250 | — | sì |
| Weed | 315 | 890 | sì | — |

Moltiplicatore di impennata: **×4**. Divisore di crollo: **÷4** (`DefaultDrugs`, riga 749).

### 4.3 Le otto località (`DefaultLocation[]`, riga 740)

| Località | Presenza di polizia | Min sostanze in listino | Max |
|---|---:|---:|---:|
| Bronx | 10 % | 7 | 12 |
| Ghetto | 5 % | 8 | 12 |
| Central Park | 15 % | 6 | 12 |
| Manhattan | 90 % | 4 | 10 |
| Coney Island | 20 % | 6 | 12 |
| Brooklyn | 70 % | 4 | 11 |
| Queens | 50 % | 6 | 12 |
| Staten Island | 20 % | 6 | 12 |

Qui i quartieri **finalmente si differenziano**, su due assi: rischio e profondità del
listino. Manhattan è sorvegliatissima e offre poca merce; il Ghetto è sguarnito e ha
tutto. Servizi fissi: strozzino nel Bronx, banca nel Bronx, armeria e pub altrove
(`DEFLOANSHARK`, `DEFBANK`, `DEFGUNSHOP`, `DEFROUGHPUB`).

### 4.4 Armi e poliziotti

```
Baretta                 3 000 $   4 spazi   danno 5
.38 Special             3 500 $   4 spazi   danno 9
Ruger                   2 900 $   4 spazi   danno 4
Saturday Night Special  3 100 $   4 spazi   danno 7
```

Tre livelli di polizia, che si presentano in ordine man mano che ne uccidi:

| Agente | Armatura | Arm. vice | Pen. att. | Pen. dif. | Vice min | Vice max | Arma | Armi/agente | Armi/vice |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Officer Hardass | 4 | 3 | 30 | 30 | 2 | 8 | 0 | 1 | 1 |
| Officer Bob | 15 | 4 | 30 | 20 | 4 | 10 | 0 | 2 | 1 |
| Agent Smith | 50 | 6 | 20 | 20 | 6 | 18 | 1 | 3 | 2 |

### 4.5 L'algoritmo dei prezzi (`GenerateDrugsHere`, serverside.c:3196)

```
NumEvents = 0
se 1d100 < 70            → NumEvents = 1
se 1d100 < 40 e già 1    → NumEvents = 2
se 1d100 <  5 e già 2    → NumEvents = 3

per ogni evento: scegli una sostanza a caso fra quelle "Cheap" o "Expensive";
    Expensive → prezzo = U(min,max) × 4
    Cheap     → prezzo = U(min,max) ÷ 4

poi: NumRandom = U(Location.MinDrug, Location.MaxDrug)
     riempi le restanti posizioni con prezzo = U(min,max);
     le sostanze non estratte NON sono in vendita qui (prezzo 0)
```

**Il punto cruciale, e il difetto centrale di dopewars come gioco multigiocatore**:
questa funzione prende `Player *To` come argomento. I prezzi sono **generati per
giocatore**, non per località. Due giocatori nello stesso quartiere nello stesso
momento vedono due listini diversi, e nessuna delle loro compravendite muove di un
centesimo il prezzo dell'altro. Non c'è mercato: c'è un generatore di numeri casuali
personale. Tutto il multigiocatore di dopewars è sovrastruttura sociale (chat, risse,
spie) appoggiata su **economie separate e non comunicanti**.

### 4.6 Combattimento (`GetFightRatings` :2616, `Fire` :2842)

```
AttackRating = 80 + Σ (danno_arma_i × numero_armi_i)   − penalità se poliziotto
DefendRating = 100 − 5 × numero_bitches                 − penalità se poliziotto
                     (minimo 10 per entrambi)

colpito se  U(0,AttackRating) > U(0,DefendRating)
danno = Σ U(0, danno_arma_i)  per ogni arma posseduta,  × 100 / armatura_difensore
```

L'armatura è un **divisore percentuale**: giocatore 100 (danno pieno), "bitch" 50 (danno
doppio — le guardie del corpo muoiono in fretta), Agent Smith 50 (idem), Hardass 4
(danno ×25: praticamente invulnerabile al primo incontro).

Fuga: probabilità **60 %**, dimezzata a 30 % se eri tu ad attaccare. Chi fugge da uno
scontro con la polizia ha il 30 % di "dimenticare" un omicidio commesso (`CopIndex--`).
Quando uno scontro finisce, con probabilità `100 − PolicePresence` compare il medico, al
prezzo `U(50 000, 150 000) × salute / 500`.

Alla morte del difensore l'attaccante **saccheggia tutto**: contante + banca − debito,
più armi e merce (i poliziotti non saccheggiano). Perdere una guardia del corpo
significa perdere con lei una parte casuale del carico.

### 4.7 Le meccaniche multigiocatore di dopewars

- **Incontro**: arrivando in un quartiere dove c'è un altro giocatore, scegli
  *Attack* o *Evade*. Chi attacca ha metà probabilità di fuga e richiama la polizia con
  probabilità pari alla `PolicePresence` locale.
- **Spia** (20 000 $, costa una guardia): infiltri un tuo uomo presso un avversario e ne
  vedi inventario e conti in tempo reale. Dal quarto turno in poi la spia può essere
  scoperta, con probabilità `10 + turni_trascorsi` per cento: viene uccisa, torturata,
  scappa o **passa al nemico** (quattro esiti, `Discover[]`).
- **Soffiata** (10 000 $, costa una guardia): mandi la polizia addosso a un giocatore
  preciso. Se lo ammazzano o lo derubano, tu ne ricavi un uomo.
- **Chat** pubblica e privata, punteggi condivisi, metaserver per elencare le partite.

Sono, tutte, **meccaniche di attrito sociale**, non economiche. Restano ottime idee: la
soffiata e la spia sono le due cose migliori mai aggiunte a Drug Wars, e si trasferiscono
di peso in un mondo persistente.

### 4.8 L'IA di riferimento (`AIPlayer.c:446`)

L'avversario automatico di dopewars gioca così: calcola per ogni sostanza lo scarto fra
prezzo corrente e **prezzo medio teorico** `(min+max)/2`; vende tutto ciò che è sopra
media, compra col contante residuo ciò che è più sotto media, lasciando 10 spazi liberi
per le armi e 300 $ di riserva. Poi salta in un quartiere a caso.

È la strategia ottima del gioco originale, e si scrive in venti righe. **Questo è il
problema**: un gioco la cui strategia ottima sta in venti righe non può reggere un
mondo persistente. Il nostro lavoro è, in buona sostanza, rendere quelle venti righe
insufficienti.

---

## 5. Anatomia: perché il gioco del 1984 funziona

Spogliato del tema, Drug Wars è questo:

1. **Un capitale che deve crescere esponenzialmente** perché il debito cresce
   esponenzialmente (10 % composto). Non basta guadagnare: bisogna raddoppiare.
2. **Un moltiplicatore per turno** dato dalla forchetta dei prezzi: se compri al minimo
   e vendi al massimo, moltiplichi per il rapporto max/min della sostanza (da 2,0 a 6,0).
3. **Un vincolo di capienza** (100 spazi) che rende il moltiplicatore **decrescente con
   la ricchezza**: a inizio partita il vincolo è il denaro, a fine partita è lo spazio.
   È questo scambio di vincolo, e non altro, a dare la curva al gioco.
4. **Un rumore** che rende il fondo del mercato riconoscibile ma non prevedibile.
5. **Un rischio** che scala con la ricchezza (nel door BBS, in modo esplicito e
   deterministico).
6. **Un orologio** che chiude la partita prima che l'esponenziale diventi assurdo.

Il ciclo per turno, nella sua forma minima:

```
arrivi → leggi il listino → (eventi) → vendi ciò che hai comprato altrove
       → compri ciò che qui è al ribasso → scegli dove andare → un giorno passa
       → il debito cresce del 10 %
```

Sette decisioni per turno, trenta turni, una partita da venti minuti. È perfetto per
quello che è. E non regge dieci minuti in un mondo persistente.

---

## 6. Cosa si rompe nella trasposizione — l'elenco dei problemi veri

Ogni voce qui sotto è un problema che va risolto con una decisione di progetto, non con
una configurazione.

1. **L'esponenziale non ha più un fine corsa.** Trenta turni al 10 % sono un fattore 17;
   trecento turni sono un fattore 2,6 × 10¹². Senza il limite dei 30 giorni, qualunque
   economia compone fino all'assurdo. *Servono stagioni, o pozzi di denaro che scalino
   col patrimonio, o entrambi.*

2. **Il mercato non esiste.** In tutte le versioni storiche il prezzo è un'estrazione
   casuale che nessuna compravendita influenza. Con molti giocatori questo significa
   denaro gratis illimitato e nessuna competizione. *È la cosa numero uno da
   riprogettare: prezzo come funzione di una giacenza condivisa che le azioni dei
   giocatori consumano e reintegrano.*

3. **Chi gioca di più vince, sempre.** Il ciclo è lineare nel numero di azioni. In un
   browsergame persistente questo premia la presenza ossessiva. *Serve un vincolo
   rigenerativo (tempo reale di viaggio, capienza, calore, personale) che renda
   decrescente il rendimento della sessione lunga.*

4. **La strategia ottima è banale** (vedi 4.8: venti righe). *Serve informazione
   imperfetta — prezzi non tutti visibili da ovunque — e servono decisioni che non si
   riducano a "confronta col prezzo medio".*

5. **Il rischio è aneddotico.** Un incontro casuale col poliziotto è un evento isolato
   senza memoria. *La legge deve diventare un avversario con stato: indagini che
   maturano, calore che si accumula per quartiere e per persona, informatori.*

6. **Il PvP di dopewars è grief puro.** Chi uccide si prende tutto: patrimonio, merce,
   armi. In una partita da venti minuti è accettabile; su un personaggio costruito in
   tre mesi è la fine del gioco per la vittima e per il server. *Il PvP va spostato
   dall'omicidio all'attrito: mercato, territorio, soffiate, furto di carichi, con
   perdite dolorose ma non totali.*

7. **Non c'è niente da fare oltre a commerciare.** Trenta turni non annoiano; trecento
   sì. *Serve la stratificazione: personale, mezzi, immobili, territorio, riciclaggio,
   contratti, obiettivi.*

8. **Il denaro sporco non esiste.** Nel 1984 il contante è contante. È l'assenza più
   vistosa rispetto alla realtà che il gioco cita, ed è anche, da progettista, un regalo:
   *la distinzione fra denaro sporco e denaro pulito è un intero secondo sistema
   economico gratis, con i suoi costi, i suoi tempi e il suo rischio.*

9. **Il trench coat è un limite piatto.** 100 spazi, +10 per guardia. *Diventa la
   catena logistica: tasche, borsone, auto, furgone, deposito, con rischi e velocità
   diversi.*

10. **Lo spazio non è uno spazio.** Sei quartieri completamente connessi, salto
    istantaneo, costo uniforme. *Con una mappa vera — distanze, tempi, posti di blocco,
    quartieri controllati da qualcuno — la geografia diventa una risorsa contesa.*

---

## 7. Inventario delle meccaniche — cosa portiamo, cosa buttiamo

| # | Meccanica | Fonte | Destino |
|---|---|---|---|
| 1 | Arbitraggio fra piazze | 1984 | **cuore**, riscritto su mercato condiviso |
| 2 | Forchette di prezzo per bene, più volatili in basso | 1984 | **tenuta**, è la curva di progressione |
| 3 | Debito a interesse composto | 1984 | **tenuta** come motore di apertura |
| 4 | Banca a interesse | 1984 | **trasformata** in riciclaggio e conti |
| 5 | Capienza del trench coat | 1984 | **trasformata** in logistica a più livelli |
| 6 | Stash fisso in un quartiere | 1984 | **trasformato** in depositi multipli, rischiosi |
| 7 | Shock di prezzo annunciati a testo | 1984 | **tenuti**, diventano notizie del mondo |
| 8 | Eventi di strada (scippo, cani, ritrovamenti) | 1984 | **tenuti**, riscritti |
| 9 | L'erba al paraquat che ti uccide | 1984 | **tenuto** come easter egg, senza morte secca |
| 10 | Punteggio = patrimonio, scala 1-100 | 1984 | **trasformato** in classifica multipla |
| 11 | `danger` / pubblico nemico | door 1993 | **tenuto ed esteso**: è il calore |
| 12 | Innesco della polizia su soglie di operazione | door 1993 | **tenuto**: rischio come conseguenza, non come dado |
| 13 | Prigione a tempo | door 1993 | **tenuta**, a tempo reale |
| 14 | Razzia del deposito in tua assenza | door 1993 | **tenuta** |
| 15 | Bollette periodiche | door 1993 | **tenute ed estese**: stipendi, affitti, pizzo |
| 16 | Presenza di polizia per quartiere | dopewars | **tenuta** |
| 17 | Listino parziale per quartiere | dopewars | **tenuto**: informazione imperfetta |
| 18 | Guardie del corpo che portano carico | dopewars | **trasformate** in personale con ruoli e lealtà |
| 19 | Spia infiltrata, con rischio di scoperta e voltafaccia | dopewars | **tenuta**, è ottima |
| 20 | Soffiata alla polizia contro un giocatore | dopewars | **tenuta**, è ottima |
| 21 | Armeria, pub, medico | dopewars | **tenuti** come servizi di quartiere |
| 22 | Combattimento a tiri contrapposti | dopewars | **ridimensionato**: raro, circoscritto, non letale per default |
| 23 | Saccheggio totale del morto | dopewars | **eliminato** |
| 24 | Prezzi generati per giocatore | dopewars | **eliminato**, è il difetto d'origine |
| 25 | Turni discreti da 1 giorno | tutte | **eliminati** a favore del tempo reale |

---

## 8. Fonti

- `fonti/originali/x/drugwars/drugwar.exe` — Drug Wars, John E. Dell, 1984 (binario;
  testo estratto in questo documento).
- `fonti/originali/x/dopewars/DOPEWARS.C` — door BBS, 1993, 2405 righe.
- `fonti/originali/x/dopewarssrc/` — Dopewars per Windows/Pocket PC, Jennifer Glover, 2001.
- `fonti/originali/x/dlord/DRUGLORD.EXE` — Drug Lord, 1997 (binario).
- `fonti/dopewars/` — dopewars 1.6.2, Ben Webb, GPL, clone del repository ufficiale.
- `fonti/wikipedia_drugwars_en.txt`, `fonti/bbs_drugwars_wiki.txt` — solo cronologia.
- Archivio originale: <http://www.bbsdocumentary.com/library/PROGRAMS/DOORS/DOPEWARS/>

### Divergenze riscontrate fra enciclopedie e sorgenti

- Wikipedia dà il debito iniziale a 5 500 $ (corretto per dopewars) ma il door BBS del
  1993 usa 5 000 $ e il testo del 1984 non lo dichiara.
- Wikipedia attribuisce al 1984 "quattro scelte: farsi arrestare, scappare, combattere,
  continuare a spacciare": il binario del 1984 contiene solo `WILL YOU RUN OR FIGHT ?`
  e `WILL YOU RUN ?`. Le quattro opzioni sono di versioni successive.
- Wikipedia dice "dopo 10 colpi il giocatore muore": nel 1984 esiste un indicatore
  `DAMAGE`; in dopewars la salute è percentuale e il danno dipende dalle armi.
