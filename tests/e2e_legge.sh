#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end della legge (F4).
#
#   bash tests/e2e_legge.sh
#
# Verifica la promessa del §4: chi esagera viene VISTO, AVVISATO e PRESO — in
# quest'ordine, e con la possibilità di reagire fra una cosa e l'altra.
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
# Nome con cui il vhost risponde. Si sovrascrive da ambiente:
#   BASE_URL=https://esempio.tld/piazzapulita HOST_HDR=esempio.tld bash tests/...
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
JAR="$(mktemp)"
USER_NAME="prova Latitante $(date +%s)"
USER_MAIL="prova_$(date +%s)@esempio.invalid"
USER_PASS="piazza123"
FALLITI=0

trap 'rm -f "${JAR}"' EXIT

c() { curl -s -k -b "${JAR}" -c "${JAR}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
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

echo "Prova end-to-end della legge — ${BASE_URL}"

# --- Un giocatore ------------------------------------------------------------
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
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${PID}")

verifica "si comincia freddi"        "0.000" "$(dbq "SELECT calore FROM personaggi WHERE id=${PID}")"
verifica "senza precedenti"          "0"     "$(dbq "SELECT profilo FROM personaggi WHERE id=${PID}")"
verifica "e senza fascicolo"         "0"     "$(dbq "SELECT COUNT(*) FROM fascicoli WHERE personaggio_id=${PID}")"
verifica "la pagina del fascicolo risponde" "200" "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/fascicolo")"

# --- Trattare scalda ---------------------------------------------------------
dbq "UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
dbq "UPDATE personaggi SET contante=6000000 WHERE id=${PID}" >/dev/null
BENE=$(dbq "SELECT bene_id FROM mercati WHERE piazza_id=${QUI} ORDER BY domanda_eq DESC LIMIT 1")
TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "azione=compra_tutto"
CAL=$(dbq "SELECT calore FROM personaggi WHERE id=${PID}")
verifica "comprando ci si scalda" "si" "$(php -r "exit(((float)'${CAL}') > 0 ? 0 : 1);" && echo si || echo no)"
verifica "e si scalda anche la piazza" "si" \
  "$(php -r "exit(((float)'$(dbq "SELECT calore FROM piazze WHERE id=${QUI}")') > 0 ? 0 : 1);" && echo si || echo no)"

# --- Il calore decade se si sta fermi ----------------------------------------
dbq "UPDATE personaggi SET calore=100, calore_agg_a=DATE_SUB(NOW(3), INTERVAL 12 HOUR) WHERE id=${PID}" >/dev/null
battito >/dev/null
DOPO=$(dbq "SELECT calore FROM personaggi WHERE id=${PID}")
verifica "in dodici ore si dimezza" "si" \
  "$(php -r "\$v=(float)'${DOPO}'; exit(\$v > 49 && \$v < 51 ? 0 : 1);" && echo si || echo no)"

# --- Il fascicolo si apre da solo --------------------------------------------
dbq "UPDATE personaggi SET calore=120, calore_agg_a=NOW(3) WHERE id=${PID}" >/dev/null
battito >/dev/null
verifica "oltre soglia si apre un fascicolo" "1" \
  "$(dbq "SELECT COUNT(*) FROM fascicoli WHERE personaggio_id=${PID} AND stato='aperto'")"
verifica "con un inquirente che ha un nome" "si" \
  "$([[ -n "$(dbq "SELECT inquirente FROM fascicoli WHERE personaggio_id=${PID}")" ]] && echo si || echo no)"

# --- Le prove maturano, e arrivano i segnali ---------------------------------
# Si smette appena il blitz è scattato: continuare a riscaldare il personaggio
# DOPO l'arresto vorrebbe dire misurare quello che fa la prova, non il gioco.
for i in 1 2 3 4 5 6 7 8 9 10; do
  [[ "$(dbq "SELECT stato FROM fascicoli WHERE personaggio_id=${PID} ORDER BY id DESC LIMIT 1")" == "eseguito" ]] && break
  dbq "UPDATE fascicoli SET agg_a=DATE_SUB(agg_a, INTERVAL 5 HOUR) WHERE personaggio_id=${PID};
       UPDATE personaggi SET calore=250, calore_agg_a=NOW(3) WHERE id=${PID}" >/dev/null
  battito >/dev/null
done
verifica "i segnali sono comparsi lungo la strada" "si" \
  "$([[ "$(dbq "SELECT COUNT(*) FROM segnali WHERE personaggio_id=${PID} AND genere='fascicolo'")" -ge 4 ]] && echo si || echo no)"
verifica "e il blitz è scattato" "eseguito" \
  "$(dbq "SELECT stato FROM fascicoli WHERE personaggio_id=${PID} ORDER BY id DESC LIMIT 1")"
verifica "con un arresto a carico"    "1" "$(dbq "SELECT arresti FROM personaggi WHERE id=${PID}")"
verifica "e il profilo criminale sale" "1" "$(dbq "SELECT profilo FROM personaggi WHERE id=${PID}")"
verifica "il carico è stato sequestrato" "0" "$(dbq "SELECT COUNT(*) FROM carico WHERE personaggio_id=${PID}")"
verifica "il calore è azzerato"        "0.000" "$(dbq "SELECT calore FROM personaggi WHERE id=${PID}")"

# --- Dentro non si fa niente --------------------------------------------------
verifica "si è in carcere" "si" \
  "$([[ -n "$(dbq "SELECT carcere_fino_a FROM personaggi WHERE id=${PID}")" ]] && echo si || echo no)"
DEST=$(c -o /dev/null -w '%{redirect_url}' "${BASE_URL}/strada")
verifica "la strada rimanda al fascicolo" "si" "$([[ "${DEST}" == */fascicolo ]] && echo si || echo no)"

TOK=$(c "${BASE_URL}/fascicolo" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=1" --data-urlencode "verso=acquisto")
grep -q "Da dentro non si compra" <<< "${PAGINA}" \
  && verifica "da dentro non si tratta" "si" "si" || verifica "da dentro non si tratta" "si" "no"

ALTRA=$(dbq "SELECT id FROM piazze WHERE citta_id=${NA} AND id<>${QUI} LIMIT 1")
TOK=$(c "${BASE_URL}/fascicolo" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${ALTRA}" --data-urlencode "mezzo=mezzi" --data-urlencode "torna=/strada")
grep -q "non si va da nessuna parte" <<< "${PAGINA}" \
  && verifica "e non si va da nessuna parte" "si" "si" || verifica "e non si va da nessuna parte" "si" "no"

# --- Si esce -----------------------------------------------------------------
dbq "UPDATE personaggi SET carcere_fino_a=DATE_SUB(NOW(3), INTERVAL 1 SECOND) WHERE id=${PID}" >/dev/null
battito >/dev/null
verifica "il battito scarcera" "" "$(dbq "SELECT COALESCE(carcere_fino_a,'') FROM personaggi WHERE id=${PID}")"
verifica "e la strada si riapre" "200" "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/strada")"

# --- Difendersi ---------------------------------------------------------------
# Un personaggio può avere più fascicoli nel tempo: si lavora su quello che il
# gioco considera corrente, cioè l'ultimo aperto, e gli altri si chiudono.
dbq "UPDATE fascicoli SET stato='archiviato' WHERE personaggio_id=${PID}" >/dev/null
FASC=$(dbq "SELECT id FROM fascicoli WHERE personaggio_id=${PID} ORDER BY id DESC LIMIT 1")
dbq "UPDATE fascicoli SET stato='aperto', prove=80, chiuso_at=NULL WHERE id=${FASC};
     UPDATE personaggi SET pulito=0, contante=9000000 WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/fascicolo" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/fascicolo/avvocato" --data-urlencode "_token=${TOK}")
grep -q "in denaro pulito" <<< "${PAGINA}" \
  && verifica "l'avvocato non lavora per contanti" "si" "si" \
  || verifica "l'avvocato non lavora per contanti" "si" "no"

dbq "UPDATE personaggi SET pulito=5000000 WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/fascicolo" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/fascicolo/avvocato" --data-urlencode "_token=${TOK}"
GIU=$(dbq "SELECT cvalue FROM game_config WHERE ckey='legge.avvocato_prove'")
verifica "col pulito sì, e smonta le prove" "$((80 - GIU))" \
  "$(dbq "SELECT ROUND(prove) FROM fascicoli WHERE id=${FASC}")"

# La bustarella ha due facce: o scendono, o salgono. Basta che non resti uguale.
dbq "UPDATE fascicoli SET prove=50 WHERE id=${FASC};
     UPDATE personaggi SET contante=9000000 WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/fascicolo" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/fascicolo/bustarella" --data-urlencode "_token=${TOK}"
DOPO=$(dbq "SELECT ROUND(prove) FROM fascicoli WHERE id=${FASC}")
verifica "la bustarella o funziona o si ritorce" "si" \
  "$([[ "${DOPO}" != "50" ]] && echo si || echo no)"

# --- Stare fermi paga ---------------------------------------------------------
dbq "UPDATE fascicoli SET prove=0 WHERE id=${FASC};
     UPDATE personaggi SET calore=1, calore_agg_a=NOW(3) WHERE id=${PID}" >/dev/null
battito >/dev/null
verifica "un fascicolo che smette di crescere si archivia" "archiviato" \
  "$(dbq "SELECT stato FROM fascicoli WHERE id=${FASC}")"

# --- Pulizia -------------------------------------------------------------------
dbq "UPDATE piazze SET calore=0, calore_agg_a=NULL WHERE id=${QUI};
     UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, shock=0, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1
verifica "utente di prova rimosso" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID}")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
