#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end del mondo (F1), attraverso Apache.
#
#   bash tests/e2e_mondo.sh
#
# Nasce un personaggio, si sposta dentro la città e fra le città, arriva sia
# per avanzamento pigro sia per battito, e si verifica che i rifiuti rifiutino.
# L'utente di prova viene cancellato alla fine.
#
# L'autenticazione la prova e2e_auth.sh: qui l'account si attiva da console,
# così il giro della posta non entra in mezzo a una prova che parla d'altro.
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
# Nome con cui il vhost risponde. Si sovrascrive da ambiente:
#   BASE_URL=https://esempio.tld/piazzapulita HOST_HDR=esempio.tld bash tests/...
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
JAR="$(mktemp)"
USER_NAME="prova Viandante $(date +%s)"
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

verifica() {
  if [[ "$2" == "$3" ]]; then
    printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else
    printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"
    FALLITI=$((FALLITI + 1))
  fi
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

echo "Prova end-to-end del mondo — ${BASE_URL}"
echo "  utente di prova: ${USER_NAME}"

# --- Il mondo dev'esserci ----------------------------------------------------
# Non si scrive qui quante piazze ci sono: il numero cambia quando il mondo
# cresce, e una prova che diventa rossa per una modifica legittima altrove è
# una prova che poi si smette di guardare. Si verifica la FORMA: nove città, e
# ognuna con almeno una periferia (da cui la roba entra) e almeno uno sbocco
# (dove si vende), che è il vincolo strutturale scoperto simulando.
verifica "nove città seminate" "9" "$(dbq "SELECT COUNT(*) FROM citta")"
verifica "almeno quaranta piazze" "si" \
  "$([[ "$(dbq "SELECT COUNT(*) FROM piazze")" -ge 40 ]] && echo si || echo no)"
verifica "ogni città ha una periferia" "0" \
  "$(dbq "SELECT COUNT(*) FROM citta c WHERE NOT EXISTS (SELECT 1 FROM piazze p WHERE p.citta_id=c.id AND p.tipo='periferia')")"
verifica "ogni città ha uno sbocco" "0" \
  "$(dbq "SELECT COUNT(*) FROM citta c WHERE NOT EXISTS (SELECT 1 FROM piazze p WHERE p.citta_id=c.id AND p.tipo IN ('benestante','centro','stazione'))")"

# --- Un account attivo -------------------------------------------------------
TOK=$(c "${BASE_URL}/iscrizione" | token_da)
c -o /dev/null -X POST "${BASE_URL}/iscrizione" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=${USER_NAME}" --data-urlencode "email=${USER_MAIL}" \
  --data-urlencode "password=${USER_PASS}" --data-urlencode "password_confirm=${USER_PASS}"
consolle user:verify "${USER_NAME}" >/dev/null
TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}"

# --- Senza personaggio si finisce alla scelta della città --------------------
DEST=$(c -o /dev/null -w '%{redirect_url}' "${BASE_URL}/strada")
verifica "senza personaggio si va a /inizio" "si" "$([[ "${DEST}" == */inizio ]] && echo si || echo no)"

# --- Nascita -----------------------------------------------------------------
NA=$(dbq "SELECT id FROM citta WHERE codice='NA'")
TOK=$(c "${BASE_URL}/inizio" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/inizio" --data-urlencode "_token=${TOK}" --data-urlencode "citta=${NA}")
grep -q "Sei sceso a" <<< "${PAGINA}" \
  && verifica "personaggio creato a Napoli" "si" "si" \
  || verifica "personaggio creato a Napoli" "si" "no"

PID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${USER_NAME}'")
CITTA_NASCITA=$(dbq "SELECT c.codice FROM personaggi p JOIN piazze z ON z.id=p.piazza_id JOIN citta c ON c.id=z.citta_id WHERE p.id=${PID}")
verifica "nato nella città scelta" "NA" "${CITTA_NASCITA}"
CAPITALE=$(dbq "SELECT cvalue FROM game_config WHERE ckey='mondo.contante_iniziale'")
verifica "contante iniziale come da configurazione" "${CAPITALE}" "$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")"

# Il personaggio è uno solo e non si rifà.
TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -X POST "${BASE_URL}/inizio" --data-urlencode "_token=${TOK}" --data-urlencode "citta=1"
verifica "niente secondo personaggio" "1" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID}")"

# --- Spostamento dentro la città ---------------------------------------------
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${PID}")
ALTRA=$(dbq "SELECT id FROM piazze WHERE citta_id=${NA} AND id<>${QUI} LIMIT 1")
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${ALTRA}" --data-urlencode "mezzo=mezzi" --data-urlencode "torna=/strada")
grep -q "In viaggio verso" <<< "${PAGINA}" \
  && verifica "partenza accettata" "si" "si" \
  || verifica "partenza accettata" "si" "no"
verifica "biglietto pagato"   "$((CAPITALE - 600))" "$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")"
verifica "spostamento a diario" "1" "$(dbq "SELECT COUNT(*) FROM spostamenti WHERE personaggio_id=${PID}")"
# Il biglietto deve lasciare una riga nel REGISTRO, non solo nel diario dei
# viaggi: senza, il contante cala e il registro non lo spiega, e chi prova a far
# tornare i conti trova un buco che vale esattamente i viaggi fatti. E' successo:
# scoperto facendo giocare dodici personaggi e chiedendo che le casse tornassero
# alla lira.
verifica "e il biglietto e' nel registro" "600" \
  "$(dbq "SELECT COALESCE(-SUM(importo),0) FROM movimenti WHERE personaggio_id=${PID} AND genere='viaggio'")"

# Partire due volte non si può.
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${QUI}" --data-urlencode "mezzo=mezzi" --data-urlencode "torna=/strada")
grep -q "Sei già in viaggio" <<< "${PAGINA}" \
  && verifica "seconda partenza rifiutata" "si" "si" \
  || verifica "seconda partenza rifiutata" "si" "no"

# L'interfaccia dati risponde col conto alla rovescia.
grep -q '"in_viaggio":true' <<< "$(c "${BASE_URL}/api/stato")" \
  && verifica "/api/stato dice che sei in viaggio" "si" "si" \
  || verifica "/api/stato dice che sei in viaggio" "si" "no"

# --- Arrivo per avanzamento pigro --------------------------------------------
dbq "UPDATE personaggi SET arrivo_at=DATE_SUB(NOW(3), INTERVAL 1 SECOND) WHERE id=${PID}" >/dev/null
dbq "UPDATE spostamenti SET arrivo_at=DATE_SUB(NOW(3), INTERVAL 1 SECOND) WHERE personaggio_id=${PID} AND arrivato_at IS NULL" >/dev/null
c -o /dev/null "${BASE_URL}/strada"
verifica "arrivato (avanzamento pigro)" "1" "$(dbq "SELECT arrivo_at IS NULL FROM personaggi WHERE id=${PID}")"
verifica "spostamento chiuso a diario"  "1" "$(dbq "SELECT arrivato_at IS NOT NULL FROM spostamenti WHERE personaggio_id=${PID} ORDER BY id DESC LIMIT 1")"

# --- Viaggio fra città, e arrivo per battito ---------------------------------
RM=$(dbq "SELECT id FROM piazze WHERE codice='RM-TERMINI'")
TOK=$(c "${BASE_URL}/mappa" | token_da)
c -o /dev/null -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${RM}" --data-urlencode "mezzo=treno" --data-urlencode "torna=/mappa"
MIN=$(dbq "SELECT TIMESTAMPDIFF(MINUTE,partito_at,arrivo_at) FROM spostamenti WHERE personaggio_id=${PID} ORDER BY id DESC LIMIT 1")
verifica "Napoli-Roma in treno, 58 minuti" "58" "${MIN}"

dbq "UPDATE personaggi SET arrivo_at=DATE_SUB(NOW(3), INTERVAL 1 SECOND) WHERE id=${PID}" >/dev/null
dbq "UPDATE spostamenti SET arrivo_at=DATE_SUB(NOW(3), INTERVAL 1 SECOND) WHERE personaggio_id=${PID} AND arrivato_at IS NULL" >/dev/null
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/tick.php"
verifica "arrivato (battito)" "1" "$(dbq "SELECT arrivo_at IS NULL FROM personaggi WHERE id=${PID}")"
verifica "ora è a Roma"       "RM" "$(dbq "SELECT c.codice FROM personaggi p JOIN piazze z ON z.id=p.piazza_id JOIN citta c ON c.id=z.citta_id WHERE p.id=${PID}")"

# --- I rifiuti ---------------------------------------------------------------
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${PID}")
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${QUI}" --data-urlencode "mezzo=mezzi" --data-urlencode "torna=/strada")
grep -q "Sei già lì" <<< "${PAGINA}" \
  && verifica "non si parte per dove si è" "si" "si" \
  || verifica "non si parte per dove si è" "si" "no"

NAP=$(dbq "SELECT id FROM piazze WHERE codice='NA-FORCELLA'")
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${NAP}" --data-urlencode "mezzo=aereo" --data-urlencode "torna=/strada")
grep -q "non ci si arriva" <<< "${PAGINA}" \
  && verifica "niente aereo sotto i 300 km" "si" "si" \
  || verifica "niente aereo sotto i 300 km" "si" "no"

TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=99999" --data-urlencode "mezzo=treno" --data-urlencode "torna=/strada")
grep -q "Questa piazza non esiste" <<< "${PAGINA}" \
  && verifica "piazza inesistente rifiutata" "si" "si" \
  || verifica "piazza inesistente rifiutata" "si" "no"

dbq "UPDATE personaggi SET contante=100 WHERE id=${PID}" >/dev/null
PA=$(dbq "SELECT id FROM piazze WHERE codice='PA-BALLARO'")
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${PA}" --data-urlencode "mezzo=treno" --data-urlencode "torna=/strada")
grep -q "Non hai i soldi" <<< "${PAGINA}" \
  && verifica "senza soldi non si viaggia" "si" "si" \
  || verifica "senza soldi non si viaggia" "si" "no"
verifica "e non si è pagato niente" "100" "$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")"

# --- La mappa ----------------------------------------------------------------
PAGINA=$(c "${BASE_URL}/mappa")
grep -q 'id="mappa"' <<< "${PAGINA}" \
  && verifica "la mappa ha la tela" "si" "si" \
  || verifica "la mappa ha la tela" "si" "no"
grep -q 'data-citta' <<< "${PAGINA}" \
  && verifica "la mappa porta i dati delle città" "si" "si" \
  || verifica "la mappa porta i dati delle città" "si" "no"

# --- Pulizia -----------------------------------------------------------------
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1
verifica "utente di prova rimosso" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID}")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then
  printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else
  printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"
  exit 1
fi
