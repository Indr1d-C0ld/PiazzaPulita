# Piazza Pulita

*Trasposizione persistente e multigiocatore di **Drug Wars** (John E. Dell, 1984).*

![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777bb4) ![MariaDB](https://img.shields.io/badge/MariaDB-11-003545) ![GPL-3.0](https://img.shields.io/badge/licenza-GPL--3.0-a5281d)

Browsergame di commercio, rischio e territorio. **Italia, 1980-1995**, in lire.

Il cuore non è la roulette dei prezzi dell'originale, ma un **mercato vero**: ogni piazza
ha una giacenza e una capacità di assorbimento condivise, e ogni compravendita sposta il
prezzo per tutti. Ne discende che il mondo ha un **reddito massimo orario finito** — la
risposta strutturale al mondo eterno senza azzeramenti.

- Studio delle fonti originali: [docs/GIOCO_ORIGINALE.md](docs/GIOCO_ORIGINALE.md)
- Impianto della trasposizione: [docs/IMPIANTO.md](docs/IMPIANTO.md)
- Progettazione e roadmap: [docs/DESIGN.md](docs/DESIGN.md)

## Le quattro decisioni fondanti

1. Italia 1980-1995 · 2. Tempo reale continuo · 3. Mondo eterno, nessun azzeramento ·
4. PvP pieno (con il conto in calore: *pubblico nemico numero N*).

## Stato

**F0 — Fondamenta: fatta.** Accesso con conferma dell'indirizzo via Brevo, profilo,
classifica e statistiche pubbliche, pannello di amministrazione, console, battito di
manutenzione, prove unitarie e end-to-end.

**F1 — Il mondo: fatta.** Nove città e quarantatré piazze con coordinate vere, due
livelli di geografia, sei mezzi di trasporto con tempi e costi d'epoca, viaggi che
durano tempo reale, arrivi sia per avanzamento pigro sia da battito, mappa della rete
su Canvas.

**F2 — Il mercato: fatta.** Dieci beni su 273 nodi di mercato condivisi, prezzi che
nascono da giacenza e assorbimento veri, ordini che si integrano lungo la curva (comprare
alza il prezzo mentre compri), carichi a grumi, passeggiata dei prezzi di riferimento
deterministica, `balance:report` che tratta l'invariante del tetto come una prova.
Più la fotografia del profilo, con centratura manuale nel riquadro.

**F3 — Denaro e logistica: fatta.** Le due casse (sporco e pulito), sette canali di
riciclaggio con commissione e **capacità oraria**, l'usuraio con interesse continuo e
tetto, tre mezzi che allargano il carico e diventano un modo di spostarsi, i depositi con
affitto a ore e sfratto, e un registro che tiene conto di ogni lira.

**F4 — La legge: fatta.** Il calore sale con il valore di quello che muovi, più che in
proporzione, e decade se stai fermo. Oltre soglia si apre un **fascicolo** — un
inquirente con un nome, che accumula prove nel tempo vero e lascia segnali lungo la
strada — e a cento prove scatta il blitz: sequestro, depositi della città, contanti,
carcere a tempo reale. Contro cui si può fare qualcosa: un penalista (che vuole denaro
pulito), una busta (che una volta su cinque finisce agli atti), o smettere in tempo.

**F5 — Il personaggio: fatta.** Cinque attributi che crescono con l'uso e non con punti
da spendere, reputazione su due assi ortogonali (rispetto e timore), un organico di
uomini con nome, competenza, lealtà e stipendio orario — vedette, contabili,
riciclatori, guardie, basisti e **corrieri**, che portano la roba da soli mentre tu sei
altrove. Chi non viene pagato smette di volerti bene, e il giorno che ti prendono parla:
è il **pentito**. E i **fornitori**, l'unica cosa del gioco che non si compra col denaro.

Prossima: **F6 — il giro degli altri** (rivalità, soffiate, spie, batterie, territorio).

## Installazione

Serve PHP 8.4 con `pdo_mysql`, `mbstring` e `gd` (con WebP), MariaDB, e Apache con
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
`mail.transport` resta `log` non parte nessuna e-mail, e senza conferma dell'indirizzo
non si entra — `user:create` esiste apposta per fare il primo amministratore senza posta.

Lo script di installazione esiste al posto di un `rsync` a mano per un motivo preciso:
`rsync -a` include `-g`, e un utente non privilegiato che copia con `-g` rimette il
proprio gruppo sui file, cancellando il `<utente>:www-data` con setgid che il bootstrap
aveva impostato. Il processo web smette di poter scrivere il diario — in silenzio,
perché `logger()` ripiega sul syslog e non si lamenta.

## Prove

```bash
php tests/test_unita.php    # 187 verifiche: formato, geografia, viaggi, mercato, denaro, viste
bash tests/e2e_auth.sh      # iscrizione, conferma, accesso, profilo — attraverso Apache
bash tests/e2e_mondo.sh     # nascita, viaggi, arrivi, rifiuti — attraverso Apache
bash tests/e2e_mercato.sh   # compravendita, impatto, fotografia del profilo
bash tests/e2e_affari.sh    # riciclaggio, usuraio, mezzi, depositi — il ciclo intero
bash tests/e2e_legge.sh     # calore, fascicolo, blitz, carcere, difesa
bash tests/e2e_organico.sh  # attributi, uomini, corrieri, pentiti, fornitori

php bin/console.php balance:report        # l'invariante del tetto, come prova
php bin/_simula_principiante.php 12 NA    # un principiante contro il motore vero
```

## Stack

PHP 8.4 senza framework · MariaDB · Apache (`/piazzapulita/`) · JS vanilla + Canvas
(nessun build step) · simulazione autoritativa server-side, tick da cron e avanzamento
pigro deterministico. Core portato da SubSpazio/Atlantik. Mail via Brevo.

Segreti in `/data/piazzapulita-config/config.php`, fuori dal DocumentRoot. In questa
cartella di lavoro c'è anche `config/config.php`, che serve solo alle prove locali
(punta a un MariaDB usa-e-getta) ed è escluso sia da git sia dal deploy: in produzione
`Config` trova prima quello vero.

## Fonti primarie

Lo studio in [docs/GIOCO_ORIGINALE.md](docs/GIOCO_ORIGINALE.md) è stato fatto sui sorgenti
veri, non su sintesi di terze parti: il binario originale del 1984, il sorgente C completo
del door BBS del 1993, e `dopewars` di Ben Webb (1998-2022).

**Quei file non stanno in questo repository**: pesano, non sono nostri, e alcuni sono
software proprietario. Il §8 dello studio dice esattamente dove si scaricano.

## Licenza

GPL-3.0 — vedi [LICENSE](LICENSE).

Il gioco è una **riscrittura originale**: non contiene codice di *Drug Wars*, di
*dopewars* o di altre versioni della serie. Da loro viene l'analisi delle meccaniche,
documentata riga per riga in `docs/GIOCO_ORIGINALE.md` con i riferimenti ai sorgenti.
