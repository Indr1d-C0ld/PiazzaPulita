# Trasposizione — impianto proposto

> Come si porta Drug Wars in un mondo persistente e multigiocatore su interfaccia web.
> **Documento superato**: le decisioni marcate qui sotto come **[DA DECIDERE]** sono
> state prese il 19/09/2026 e sono riportate al §0 di [`DESIGN.md`](DESIGN.md), che è
> il documento di riferimento. Questo resta come traccia del ragionamento che ha
> portato a quelle domande.
>
> Presuppone lo studio in [`GIOCO_ORIGINALE.md`](GIOCO_ORIGINALE.md), in particolare
> l'elenco dei dieci problemi al §6 e l'inventario delle meccaniche al §7.

---

## 0. Il principio

Il gioco del 1984 è un **generatore di numeri casuali con un vincolo di capienza**. La
versione persistente deve essere un **mercato vero**: un luogo dove il prezzo esiste
prima di te, non per te, e dove la tua compravendita lo sposta per tutti gli altri.

Tutto il resto — personale, territorio, polizia, riciclaggio — esiste per dare peso a
quella singola trasformazione. Se il mercato è finto, nessuna sovrastruttura lo salva:
è esattamente l'errore che dopewars ha commesso nel 1998 (§4.5 dello studio).

Tre regole di progetto che ne discendono:

1. **Ogni prezzo è pubblico, condiviso e conseguenza di qualcosa.** Mai un `rand()` che
   decide il prezzo che vedi tu.
2. **Ogni guadagno ha un attrito.** Tempo, spazio, calore, personale, commissione,
   pizzo. Il denaro deve poter essere perso tanto quanto guadagnato.
3. **Ogni rischio è leggibile in anticipo.** Il giocatore deve poter sapere di stare
   esagerando *prima* che la porta venga giù. Il door BBS del 1993 lo faceva con quattro
   soglie numeriche; noi lo faremo meglio, ma non in modo meno onesto.

---

## 1. Il motore economico

### 1.1 Lo stato di un mercato

L'unità elementare è la coppia **(piazza, bene)**. Non esiste un "prezzo del bene": ne
esistono tanti quante sono le piazze, e sono legati fra loro solo da un riferimento
globale lento e dal fatto che i giocatori possono spostare merce.

Stato memorizzato per ogni coppia:

| Campo | Significato |
|---|---|
| `offerta` S | unità effettivamente acquistabili adesso |
| `offerta_eq` S* | giacenza di equilibrio (quanto ne gira normalmente qui) |
| `domanda` A | capacità residua di assorbimento: quanto il quartiere è ancora disposto a comprare |
| `domanda_eq` A* | assorbimento di equilibrio |
| `moltiplicatore` D | carattere strutturale della piazza per quel bene (fonte o sbocco) |
| `shock` | perturbazione temporanea in corso, con scadenza |

E, globalmente per ogni bene, un **prezzo di riferimento** `P₀` che compie una
passeggiata a ritorno verso la media (Ornstein-Uhlenbeck):

```
P₀(t+Δ) = P₀(t) + θ·(μ − P₀(t))·Δ + σ·√Δ·N(0,1)
```

dove `μ` è il centro della forchetta storica del bene (le stesse forchette del 1984, che
si sono dimostrate ben calibrate) e `σ` è tarata in modo che la volatilità percentuale
sia **maggiore per i beni poveri**, come nell'originale: è quella la scala di
progressione.

### 1.2 Il prezzo

```
prezzo_acquisto = P₀ · D · (S*/max(S,1))^ε · (1+shock) · (1 + margine)
prezzo_vendita  = P₀ · D · (A/max(A*,1))^ζ · (1+shock) · (1 − margine)
```

con `ε, ζ ≈ 0,6` (elasticità) e `margine` lo spread denaro-lettera, ridotto dalla tua
abilità di trattativa, dalla reputazione e dal controllo della piazza. Entrambi i prezzi
vincolati a una banda (es. da 0,2× a 5× il riferimento) perché nessun modello
sopravvive agli estremi.

**Conseguenze immediate, tutte volute:**

- comprare fa **salire** il prezzo d'acquisto: un ordine grosso si auto-penalizza;
- vendere fa **scendere** il prezzo di vendita: scaricare 500 unità in una piazza ne
  fa crollare il mercato per ore;
- due giocatori sulla stessa piazza si ostacolano davvero — e possono accordarsi, o
  farsi la guerra dei prezzi;
- la piazza piccola non regge il volume del giocatore ricco: **crescere significa
  necessariamente distribuirsi**, cioè avere una rete, cioè avere personale.

### 1.3 Il respiro del mercato (tick)

A ogni tick (cron, ogni pochi minuti), per ogni coppia:

```
S ← S + tasso_rifornimento·(S* − S)·Δ + carichi_in_arrivo − consumo_npc
A ← A + tasso_recupero·(A* − A)·Δ
shock ← shock · decadimento
```

Il rifornimento non è liscio: arriva a **carichi**, con cadenza casuale e ampiezza
diversa per piazza. Una piazza appena rifornita ha prezzi bassi e li avrà per qualche
ora — questa è l'occasione, ed è la ragione per cui l'informazione vale denaro.

Come in Atlantik, il tick fa il lavoro pesante e la richiesta web fa **avanzamento
pigro deterministico** sullo stato che le serve, così una piazza che nessuno guarda non
costa nulla.

### 1.4 L'informazione come risorsa

Nell'originale vedi il listino solo dove sei. Lo teniamo, e ci costruiamo sopra:

- **Sul posto**: prezzi esatti, in tempo reale.
- **Altrove**: quello che ti raccontano. Un contatto in una piazza ti manda quotazioni
  con un ritardo e un errore che dipendono da quanto lo paghi e da quanto si fida.
- **Il giornale / la radio**: notizie pubbliche, ritardate, che annunciano gli shock
  (sequestri, carichi, arresti) — gli eventi testuali del 1984 diventano un notiziario
  del mondo condiviso da tutti.
- **La spia** (da dopewars): infiltrare un uomo presso un altro giocatore, vederne le
  mosse, rischiando che venga scoperto e voltato.

Con informazione imperfetta la strategia dell'IA di dopewars (§4.8) smette di
funzionare: non puoi confrontare col prezzo medio ciò che non sai.

---

## 2. Il rischio: la legge come avversario con memoria

### 2.1 Calore

Due contatori, entrambi con decadimento esponenziale:

- **Calore personale** `H_g`: cresce con il valore e il volume delle tue operazioni,
  con le violenze, con gli uomini arrestati che parlano.
- **Calore di piazza** `H_p`: cresce per tutto ciò che accade lì, da chiunque. Un
  giocatore può **bruciare una piazza** agli altri — una forma di PvP economica pulita.

L'incremento non è lineare: `ΔH ∝ (valore/soglia)^α` con `α>1`. Operare piccolo è quasi
gratis; fare il colpo grosso si paga. È la soglia del door del 1993 (§3.2b) resa
continua, e con essa la promessa che il rischio è **conseguenza**, non dado.

### 2.2 Presenza di polizia

Per quartiere, come in dopewars (dal 5 % del Ghetto al 90 % di Manhattan). Determina la
frequenza dei controlli, la probabilità che una rissa richiami una volante, il costo
delle contromisure.

### 2.3 Le indagini

La novità vera. Quando il calore personale supera una soglia, si **apre un fascicolo**:
un inquirente NPC con un nome, che accumula prove nel tempo reale, non per estrazione.

- Il giocatore **vede i segnali** prima del blitz: un'auto sempre uguale sotto casa, un
  cliente che fa troppe domande, un corriere che non risponde.
- Può reagire: corrompere, pagare un avvocato, stare fermo, spostare i depositi,
  cambiare piazza, sacrificare qualcuno.
- Se il fascicolo matura: perquisizione, sequestro, arresto. **Nessuna morte
  istantanea**: si perde merce, denaro sporco non nascosto, uomini, e si finisce in
  custodia per un tempo reale — la prigione del door BBS, riscritta in ore invece che
  in anni.

### 2.4 I pentiti

Ogni uomo del tuo organico ha **lealtà**. Cala se non lo paghi, se muoiono i colleghi, se
sta dentro, se qualcuno gli offre di più. Un uomo sleale che finisce in mano agli
inquirenti **collabora**: porta prove, e il fascicolo fa un salto. È il ponte fra
gestione del personale e rischio, ed è il miglior gancio narrativo disponibile.

---

## 3. Denaro sporco e denaro pulito

Il sistema economico secondario che l'originale non aveva.

- Il commercio produce **contante sporco**: ingombra, si nasconde, si perde nei
  sequestri, non compra niente di legale, **non conta per la classifica**.
- Il **riciclaggio** lo converte in denaro pulito, tramite canali con: una commissione
  (dal 10 % al 40 %), una **capacità oraria** (non puoi lavare un milione in un minuto),
  e un contributo al calore. Canali: il bar, l'autolavaggio, l'edilizia, le scommesse,
  il cambiavalute, la società estera — ognuno con un profilo diverso di
  costo/capacità/rischio, ognuno da acquisire e da difendere.
- Il denaro pulito compra ciò che dura: immobili, veicoli, avvocati, protezioni,
  posizioni — e, appunto, punteggio.

Questo risolve da solo tre problemi: dà un pozzo permanente e proporzionale al
patrimonio (l'inflazione da esponenziale si smorza), dà un motivo per costruire
un'organizzazione, e dà alla classifica una metrica che non si gonfia in una notte.

---

## 4. Logistica: dal trench coat alla catena

I 100 spazi del 1984 diventano una catena con più anelli, ognuno con capacità, velocità
e rischio propri:

| Anello | Capacità | Velocità | Rischio |
|---|---|---|---|
| Addosso | minima | massima | perquisizione personale |
| Borsone / zaino | bassa | alta | controllo |
| Auto | media | alta | posto di blocco |
| Furgone | alta | media | posto di blocco, più vistoso |
| Corriere (uomo) | media | lenta | può sparire, può parlare |
| Deposito | altissima | ferma | razzia (dai rivali), perquisizione (dalla legge) |

I depositi non sono più uno solo nel Bronx: sono tanti, con affitto, e **concentrarli è
un errore** — la razzia del deposito del door BBS (§3.2d) vale qui quanto valeva là.

---

## 5. Il personaggio: il gioco di ruolo

- **Attributi** che crescono con l'uso, non con punti da spendere: *trattativa*
  (spread migliore), *fiuto* (informazione più accurata e più a lungo), *sangue freddo*
  (fuga, controlli, interrogatori), *organizzazione* (quanti uomini reggi), *credito*
  (condizioni con fornitori e strozzini).
- **Reputazione** su due assi distinti: quanto ti **rispettano** (contratti, prezzi,
  reclutamento) e quanto ti **temono** (racket, deterrenza, ma anche calore). Sono
  ortogonali e si possono giocare in modi opposti.
- **Contatti e fornitori**: si sbloccano per merito e per reputazione, dal tizio del
  parcheggio al canale d'importazione con lotti minimi, anticipi e obblighi. È qui la
  progressione a lungo termine.
- **Organico**: vedetta, corriere, guardia, contabile, avvocato, riciclatore, basista.
  Nome, competenza, lealtà, stipendio, storia. Muoiono, vengono arrestati, tradiscono.
- **Obiettivi e onorificenze**: il sistema achievements richiesto, agganciato a gesta
  concrete (la prima piazza controllata, il milione lavato, l'indagine archiviata, la
  latitanza superata).

---

## 6. Il multigiocatore

### 6.1 Attrito economico — il livello principale

Non serve sparare per farsi male. Si compete sul mercato: chi arriva primo al carico,
chi fa crollare il prezzo dove l'altro deve vendere, chi brucia una piazza di calore,
chi si accaparra un fornitore in esclusiva, chi taglia i prezzi per far saltare i conti
al rivale. Tutto questo emerge **gratis** dal motore del §1, senza una riga di codice
dedicata al PvP.

### 6.2 Attrito sporco

- **Soffiata** (da dopewars): dirigi l'attenzione degli inquirenti su un giocatore
  preciso. Costa, e se scoperta ti si ritorce contro.
- **Spia**: vedi i suoi conti, finché non la scoprono.
- **Rapina di un carico**: si colpisce la merce in transito, non la persona. Si perde
  un carico, non una vita.
- **Corruzione contesa**: lo stesso funzionario può essere pagato da entrambi; vince
  chi offre di più, e l'altro lo scopre a sue spese.

### 6.3 Clan e territorio

- **Batterie** (gruppi di giocatori): cassa comune, depositi condivisi, avvocato di
  gruppo, canale di riciclaggio migliore.
- **Controllo delle piazze**: si conquista con presenza e investimento, non con un
  pulsante "attacca". Chi controlla incassa una percentuale sulle transazioni altrui,
  ha spread migliore, e riceve preavviso sui blitz.
- **Guerre**: si dichiarano, hanno un costo, e si vincono spostando il controllo, non
  azzerando i conti del nemico.

### 6.4 Violenza

Circoscritta, con conseguenze pesanti su entrambi i lati, mai saccheggio totale
(§6 punto 6 dello studio). Il combattimento a tiri contrapposti di dopewars è un buon
scheletro (§4.6) ma va: reso raro, reso rifiutabile, reso costoso in calore, e privato
del bottino integrale. **[DA DECIDERE]** quanta violenza diretta ammettere.

---

## 7. Sezioni dell'interfaccia

Come negli altri progetti: profilo, classifica, statistiche, obiettivi, amministrazione,
registrazione con verifica e-mail via Brevo.

| Sezione | Contenuto |
|---|---|
| **Piazza** | il listino del luogo dove sei: compra, vendi, servizi |
| **Mappa** | città, quartieri, controllo, calore, spostamenti in corso |
| **Magazzino** | depositi, carichi in transito, mezzi |
| **Organico** | uomini, ruoli, lealtà, stipendi |
| **Contabilità** | sporco/pulito, canali di riciclaggio, debiti, spese fisse |
| **Rete** | contatti, fornitori, informatori, quotazioni riportate |
| **Batteria** | clan: cassa, membri, territori, guerre |
| **Cronaca** | il notiziario del mondo: shock, arresti, guerre, record |
| **Fascicolo** | cosa sa di te la legge, e cosa puoi farci |
| **Profilo** | personaggio, attributi, reputazione, storia |
| **Classifica** | patrimonio pulito, territorio, longevità, colpi |
| **Statistiche** | i numeri del mondo e i tuoi |
| **Obiettivi** | onorificenze e traguardi |
| **Amministrazione** | mondo, giocatori, parametri a caldo, registro azioni |

---

## 8. Architettura tecnica

Identica alla linea SubSpazio → Atlantik, che ha già dato prova:

- **PHP 8.4** senza framework, front controller unico, rotte in italiano.
- **MariaDB**, database e utente dedicati, migrazioni numerate in `db/`.
- **Core portato** da Atlantik: `Config`, `Database`, `Router`, `Session`, `Csrf`,
  `View`, `Mailer`, `RateLimiter`, `GameConfig`, `Audit`, `Lock`.
- **JS vanilla + Canvas**, nessuno *build step* (la mappa della città è un Canvas).
- **Tick da cron** + avanzamento pigro deterministico sulle richieste web.
- **Generatore pseudocasuale xorshift64** come in Atlantik — *mai* moltiplicazioni a
  64 bit in PHP, traboccano in float e rompono il determinismo.
- **Mail Brevo** riusando il mittente verificato del forum, verifica obbligatoria alla
  registrazione più avviso all'amministratore.
- **Console CLI** `bin/console.php` (migrate, status, user:\*, mail:test, market:\*,
  balance:report) e `bin/tick.php`.
- **Prove**: unitarie sui moduli puri del mercato (sono matematica: si testano davvero)
  più prove end-to-end sui flussi di autenticazione e amministrazione.
- Percorsi: codice in `/data/html/<nome>`, segreti in `/data/<nome>-config/config.php`,
  messa in opera con uno script `deploy/00-bootstrap.sh` idempotente da eseguire a mano
  con sudo (niente modifiche ai vhost: `conf-available` + `a2enconf`).

**Il modulo da scrivere per primo e con più cura è il mercato** (`src/Sim/Mercato.php`):
è puro, è testabile, ed è il gioco. Tutto il resto gli gira intorno.

---

## 9. Le decisioni fondanti da prendere

1. **[DA DECIDERE] Ambientazione.** Fedeltà al 1984 newyorkese, oppure Italia degli anni
   '80-'90, oppure città contemporanea inventata. Cambia tutto il vestito e parte del
   contenuto (le istituzioni, i canali di riciclaggio, i nomi, la cronaca).
2. **[DA DECIDERE] Modello di tempo.** Tempo reale continuo, turni globali, o punti
   azione. Determina il ritmo di gioco e il tipo di giocatore che il gioco premia.
3. **[DA DECIDERE] Struttura della partita.** Mondo eterno, stagioni con azzeramento e
   albo d'oro, permadeath con eredità parziale, o combinazioni.
4. **[DA DECIDERE] Quanta violenza diretta fra giocatori.**
5. **[DA DECIDERE] Il nome del gioco.**

Prese queste, si scrive `DESIGN.md` con la roadmap a fasi F0…Fn sul modello di Atlantik.
