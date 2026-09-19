#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end del denaro e della logistica (F3).
#
#   bash tests/e2e_affari.sh
#
# Segue un ciclo economico intero: si compra, si vende, si lava, si compra un
# mezzo col pulito, si apre un deposito, ci si sposta dentro la merce, ci si fa
# prestare e si restituisce. E si verifica che il bilancio quadri.
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
# Nome con cui il vhost risponde. Si sovrascrive da ambiente:
#   BASE_URL=https://esempio.tld/piazzapulita HOST_HDR=esempio.tld bash tests/...
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
JAR="$(mktemp)"
USER_NAME="prova Ragioniere $(date +%s)"
USER_MAIL="prova_$(date +%s)@esempio.invalid"
USER_PASS="piazza123"
FALLITI=0

trap 'rm -f "${JAR}"' EXIT

c() { curl -s -k -b "${JAR}" -c "${JAR}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
# Il fuso dell'APPLICAZIONE, non quello del php.ini: `php -r` da solo gira a
# UTC, e una prova che parla col database in un fuso diverso da quello
# dell'applicazione misura sempre due ore in piu'. Succede in silenzio.
fuso() { php -r "\$c = require '${CFG}'; date_default_timezone_set(\$c['app']['timezone'] ?? 'UTC'); echo (new DateTimeImmutable())->format('P');"; }

dbq() {
  mariadb -N -B --skip-ssl \
    -u"$(php -r "\$c=require '${CFG}'; echo \$c['db']['user'];")" \
    -p"$(php -r "\$c=require '${CFG}'; echo \$c['db']['pass'];")" \
    "$(php -r "\$c=require '${CFG}'; echo \$c['db']['name'];")" \
    -e "SET time_zone='$(fuso)'; $1" 2>/dev/null
}
consolle() { PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/console.php" "$@"; }
battito()  { PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/tick.php"; }

verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
}

# Per i numeri che dipendono da quanto tempo è passato davvero.
#
# Fra l'istruzione che sposta indietro un orologio e il battito che la legge
# passa qualche secondo, e in quei secondi il bar lava altre quaranta lire. Non
# è un difetto: è che quel numero È una funzione del tempo, e pretenderlo esatto
# significa pretendere che fra due righe di script non passi tempo. Si verifica
# quindi l'ordine di grandezza, con una tolleranza larga quanto qualche secondo
# di lavorazione.
circa() { # nome, atteso, ottenuto, tolleranza
  local d=$(( $3 - $2 )); d=${d#-}
  if [[ "${d}" -le "$4" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: ~%s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
}


# Guardia: se il database non risponde, si ferma subito e lo dice.
#
# Senza questa, una variabile PIAZZAPULITA_CONFIG rimasta in ambiente fa
# interrogare un database diverso da quello su cui gira il sito: le query
# tornano vuote, ogni singola verifica fallisce, e l'elenco di venti righe
# rosse non dice da nessuna parte che il problema e' la connessione.
if [[ "$(dbq "SELECT 1")" != "1" ]]; then
  echo "ERRORE: non riesco a interrogare il database indicato da ${CFG}." >&2
  echo "Se hai PIAZZAPULITA_CONFIG in ambiente puntato a un database di sviluppo," >&2
  echo "toglilo: le prove end-to-end girano sull'installazione vera." >&2
  exit 1
fi

echo "Prova end-to-end degli affari — ${BASE_URL}"

# --- Un giocatore nuovo ------------------------------------------------------
TOK=$(c "${BASE_URL}/iscrizione" | token_da)
c -o /dev/null -X POST "${BASE_URL}/iscrizione" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=${USER_NAME}" --data-urlencode "email=${USER_MAIL}" \
  --data-urlencode "password=${USER_PASS}" --data-urlencode "password_confirm=${USER_PASS}"
consolle user:verify "${USER_NAME}" >/dev/null
TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}"
NA=$(dbq "SELECT id FROM citta WHERE codice='NA'")
TOK=$(c "${BASE_URL}/inizio" | token_da)
c -o /dev/null -X POST "${BASE_URL}/inizio" --data-urlencode "_token=${TOK}" --data-urlencode "citta=${NA}"
PID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${USER_NAME}'")

CAPITALE=$(dbq "SELECT cvalue FROM game_config WHERE ckey='mondo.contante_iniziale'")
DEBITO=$(dbq "SELECT cvalue FROM game_config WHERE ckey='denaro.debito_iniziale'")
verifica "si comincia col capitale previsto" "${CAPITALE}" "$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")"
verifica "e col debito previsto"             "${DEBITO}"   "$(dbq "SELECT debito FROM personaggi WHERE id=${PID}")"
verifica "il pulito parte da zero"           "0"           "$(dbq "SELECT pulito FROM personaggi WHERE id=${PID}")"
verifica "e si ha già un canale"             "bar"         "$(dbq "SELECT canale FROM canali_posseduti WHERE personaggio_id=${PID}")"
verifica "la pagina degli affari risponde"   "200"         "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/affari")"

# --- La lavanderia -----------------------------------------------------------
TOK=$(c "${BASE_URL}/affari" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/affari/lava" --data-urlencode "_token=${TOK}" \
  --data-urlencode "canale=bar" --data-urlencode "importo=200000"
verifica "il contante è uscito dalla tasca" "$((CAPITALE - 200000))" "$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")"
verifica "ed è entrato in coda"             "200000" "$(dbq "SELECT coda FROM canali_posseduti WHERE personaggio_id=${PID}")"

# Un'ora di bar: 150.000 lavati, meno il 35 %.
dbq "UPDATE canali_posseduti SET agg_a = DATE_SUB(NOW(3), INTERVAL 1 HOUR) WHERE personaggio_id=${PID}" >/dev/null
battito >/dev/null
circa "in un'ora se ne lavano 150.000" "150000" "$(dbq "SELECT lavato FROM canali_posseduti WHERE personaggio_id=${PID}")" 2000
circa "ne escono puliti 97.500"        "97500"  "$(dbq "SELECT pulito FROM personaggi WHERE id=${PID}")" 1500
circa "e 50.000 restano in coda"       "50000"  "$(dbq "SELECT coda FROM canali_posseduti WHERE personaggio_id=${PID}")" 2000

# --- L'usuraio ---------------------------------------------------------------
dbq "UPDATE personaggi SET debito=1500000, debito_agg_a=DATE_SUB(NOW(3), INTERVAL 2 DAY) WHERE id=${PID}" >/dev/null
battito >/dev/null
DOPO=$(dbq "SELECT debito FROM personaggi WHERE id=${PID}")
verifica "due giorni al 10% compongono (1.815.000)" "si" \
  "$([[ "${DOPO}" -ge 1815000 && "${DOPO}" -le 1815100 ]] && echo si || echo no)"

dbq "UPDATE personaggi SET debito_agg_a=DATE_SUB(NOW(3), INTERVAL 200 DAY) WHERE id=${PID}" >/dev/null
battito >/dev/null
verifica "il tetto ferma la crescita" "$(dbq "SELECT debito_tetto FROM personaggi WHERE id=${PID}")" \
  "$(dbq "SELECT debito FROM personaggi WHERE id=${PID}")"

# Si restituisce, e il debito scende.
# Si paga «tutto» e non una cifra esatta: fra l'istante in cui si scrive il
# debito e quello in cui si preme il pulsante l'interesse è già cresciuto di
# qualche lira, e un confronto esatto fallirebbe per quelle.
dbq "UPDATE personaggi SET contante=6000000, debito=1000000, debito_agg_a=NOW(3) WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/affari" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/affari/restituisci" --data-urlencode "_token=${TOK}" --data-urlencode "tutto=1"
verifica "restituendo si salda" "0" "$(dbq "SELECT debito FROM personaggi WHERE id=${PID}")"

# --- Il mezzo: serve il PULITO -----------------------------------------------
dbq "UPDATE personaggi SET contante=9000000, pulito=0 WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/affari" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/affari/mezzo" --data-urlencode "_token=${TOK}" --data-urlencode "mezzo=utilitaria")
grep -q "Serve denaro pulito" <<< "${PAGINA}" \
  && verifica "coi contanti non si compra un'auto" "si" "si" \
  || verifica "coi contanti non si compra un'auto" "si" "no"

dbq "UPDATE personaggi SET pulito=3000000 WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/affari" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/affari/mezzo" --data-urlencode "_token=${TOK}" --data-urlencode "mezzo=utilitaria"
verifica "col pulito sì"          "utilitaria" "$(dbq "SELECT mezzo FROM personaggi WHERE id=${PID}")"
verifica "e la capienza cresce"   "200"        "$(dbq "SELECT capienza FROM personaggi WHERE id=${PID}")"
circa "il pulito è stato speso"   "500000" "$(dbq "SELECT pulito FROM personaggi WHERE id=${PID}")" 2000

PAGINA=$(c "${BASE_URL}/strada")
grep -q "auto ·" <<< "${PAGINA}" \
  && verifica "l'auto compare fra i modi di spostarsi" "si" "si" \
  || verifica "l'auto compare fra i modi di spostarsi" "si" "no"

# --- Un canale migliore ------------------------------------------------------
# Si svuota la coda del bar prima di misurare: finché c'è dentro qualcosa
# continua a uscire pulito da solo, e il confronto esatto fallisce per poche
# lire arrivate nel frattempo. È il gioco che funziona, ma la prova vuole un
# numero fermo.
dbq "UPDATE canali_posseduti SET coda=0, agg_a=NOW(3) WHERE personaggio_id=${PID}" >/dev/null
dbq "UPDATE personaggi SET pulito=4000000 WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/affari" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/affari/canale" --data-urlencode "_token=${TOK}" --data-urlencode "canale=autolav"
verifica "si acquisisce un canale migliore" "2" "$(dbq "SELECT COUNT(*) FROM canali_posseduti WHERE personaggio_id=${PID}")"
verifica "pagato col pulito" "1000000" "$(dbq "SELECT pulito FROM personaggi WHERE id=${PID}")"

# --- Il deposito -------------------------------------------------------------
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${PID}")
dbq "UPDATE personaggi SET contante=4000000 WHERE id=${PID}" >/dev/null
dbq "UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/deposito/apri" --data-urlencode "_token=${TOK}"
DEP=$(dbq "SELECT id FROM depositi WHERE personaggio_id=${PID}")
verifica "si apre un deposito" "si" "$([[ -n "${DEP}" ]] && echo si || echo no)"

BENE=$(dbq "SELECT bene_id FROM mercati WHERE piazza_id=${QUI} ORDER BY domanda_eq DESC LIMIT 1")
TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=10" --data-urlencode "verso=acquisto"
ADDOSSO=$(dbq "SELECT quantita FROM carico WHERE personaggio_id=${PID} AND bene_id=${BENE}")
verifica "si è comprata merce" "si" "$([[ "${ADDOSSO:-0}" -gt 0 ]] && echo si || echo no)"

TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/deposito/sposta" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=4" --data-urlencode "verso=deposito"
verifica "quattro nel deposito" "4" "$(dbq "SELECT quantita FROM deposito_merce WHERE deposito_id=${DEP} AND bene_id=${BENE}")"
verifica "e il resto addosso" "$((ADDOSSO - 4))" "$(dbq "SELECT quantita FROM carico WHERE personaggio_id=${PID} AND bene_id=${BENE}")"

TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/deposito/sposta" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=2" --data-urlencode "verso=carico"
verifica "se ne riprendono due" "2" "$(dbq "SELECT quantita FROM deposito_merce WHERE deposito_id=${DEP} AND bene_id=${BENE}")"

# L'affitto si riscuote a ore intere.
dbq "UPDATE depositi SET pagato_fino_a = DATE_SUB(NOW(3), INTERVAL 3 HOUR) WHERE id=${DEP}" >/dev/null
PRIMA=$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")
battito >/dev/null
AFFITTO=$(dbq "SELECT cvalue FROM game_config WHERE ckey='deposito.affitto_ora'")
# Con tolleranza di un'ora: anche questo è un numero che dipende da quanto
# tempo passa davvero fra l'istruzione che sposta l'orologio e il battito.
circa "tre ore di affitto" "$((PRIMA - AFFITTO * 3))" "$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")" "${AFFITTO}"

# Chi non paga perde il posto e quel che c'era dentro.
dbq "UPDATE personaggi SET contante=10 WHERE id=${PID}" >/dev/null
dbq "UPDATE depositi SET pagato_fino_a = DATE_SUB(NOW(3), INTERVAL 5 HOUR) WHERE id=${DEP}" >/dev/null
battito >/dev/null
verifica "chi non paga viene sfrattato" "0" "$(dbq "SELECT COUNT(*) FROM depositi WHERE id=${DEP}")"
verifica "e la merce resta dentro"      "0" "$(dbq "SELECT COUNT(*) FROM deposito_merce WHERE deposito_id=${DEP}")"

# --- Il registro -------------------------------------------------------------
verifica "ogni movimento è a registro" "si" \
  "$([[ "$(dbq "SELECT COUNT(*) FROM movimenti WHERE personaggio_id=${PID}")" -ge 6 ]] && echo si || echo no)"

# --- Pulizia -----------------------------------------------------------------
dbq "UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, shock=0, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1
verifica "utente di prova rimosso" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID}")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
