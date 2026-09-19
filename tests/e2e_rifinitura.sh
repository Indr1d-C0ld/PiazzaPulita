#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end della rifinitura (F7).
#
#   bash tests/e2e_rifinitura.sh
#
# Obiettivi che si sbloccano da fatti veri, le quattro graduatorie, l'albo
# d'oro, le statistiche, l'amministrazione del mondo e dei giocatori, e
# l'installabilità (manifesto e service worker).
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
JAR="$(mktemp)"
STAMP="$(date +%s)"
USER_NAME="prova Primo ${STAMP}"
USER_MAIL="prova_${STAMP}@esempio.invalid"
PASS="piazza123"
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
battito()  { PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/tick.php" >/dev/null 2>&1; }

verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
}
maggiore() {
  if php -r "exit(((float)'$3') > ((float)'$2') ? 0 : 1);"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: > %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
}
contiene() { # nome, pagina, ago
  if grep -qF "$3" <<< "$2"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (manca: %s)\n' "$1" "$3"; FALLITI=$((FALLITI + 1)); fi
}

if [[ "$(dbq "SELECT 1")" != "1" ]]; then
  echo "ERRORE: non riesco a interrogare il database indicato da ${CFG}." >&2
  echo "Se hai PIAZZAPULITA_CONFIG in ambiente puntato a un database di sviluppo," >&2
  echo "toglilo: le prove end-to-end girano sull'installazione vera." >&2
  exit 1
fi

echo "Prova end-to-end della rifinitura — ${BASE_URL}"

# --- Le pagine pubbliche rispondono anche senza sessione -----------------------
for U in /classifica "/classifica?g=patrimonio" "/classifica?g=territorio" \
         "/classifica?g=longevita" "/classifica?g=inventata" /albo /statistiche; do
  verifica "risponde ${U}" "200" "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}${U}")"
done

# --- Installabilità -------------------------------------------------------------
MANIFESTO=$(c "${BASE_URL}/manifest.webmanifest")
verifica "il manifesto è servito come manifesto" "application/manifest+json; charset=utf-8" \
  "$(c -o /dev/null -w '%{content_type}' "${BASE_URL}/manifest.webmanifest")"
contiene "e conosce il sottopercorso del deploy" "${MANIFESTO}" '"scope":"/piazzapulita/"'
contiene "e parte dalla strada"                  "${MANIFESTO}" '"start_url":"/piazzapulita/strada"'
verifica "il service worker c'è" "200" "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/sw.js")"
verifica "e la pagina senza linea pure" "200" "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/offline.html")"
SW=$(c "${BASE_URL}/sw.js")
grep -q "caches.match(BASE + 'offline.html')" <<< "${SW}" \
  && verifica "che ripiega sulla pagina di cortesia" "si" "si" \
  || verifica "che ripiega sulla pagina di cortesia" "si" "no"
# La regola che conta: le PAGINE non si mettono in cache mai.
grep -q "c.put(req, r.clone())" <<< "${SW}" \
  && verifica "mette in cache solo gli asset" "si" \
       "$(if grep -A2 "startsWith(BASE + 'assets/')" <<< "${SW}" | grep -q respondWith; then echo si; else echo no; fi)" \
  || verifica "mette in cache solo gli asset" "si" "no"

# --- Un giocatore vero ----------------------------------------------------------
TOK=$(c "${BASE_URL}/iscrizione" | token_da)
c -o /dev/null -X POST "${BASE_URL}/iscrizione" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=${USER_NAME}" --data-urlencode "email=${USER_MAIL}" \
  --data-urlencode "password=${PASS}" --data-urlencode "password_confirm=${PASS}"
consolle user:verify "${USER_NAME}" >/dev/null
TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${PASS}"
NA=$(dbq "SELECT id FROM citta WHERE codice='NA'")
TOK=$(c "${BASE_URL}/inizio" | token_da)
c -o /dev/null -X POST "${BASE_URL}/inizio" --data-urlencode "_token=${TOK}" --data-urlencode "citta=${NA}"
PID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${USER_NAME}'")
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${PID}")

verifica "la longevità parte alla nascita" "1" \
  "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID} AND pulito_dal IS NOT NULL")"

# --- Gli obiettivi si sbloccano da fatti veri ------------------------------------
PAGINA=$(c "${BASE_URL}/obiettivi")
contiene "la pagina degli obiettivi si apre" "${PAGINA}" "Quello che hai fatto"
verifica "appena nati non si è fatto niente" "0" \
  "$(dbq "SELECT COUNT(*) FROM obiettivi WHERE personaggio_id=${PID}")"

dbq "UPDATE personaggi SET contante=5000000 WHERE id=${PID};
     UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
BENE=$(dbq "SELECT bene_id FROM mercati WHERE piazza_id=${QUI} ORDER BY domanda_eq DESC LIMIT 1")
TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=6" --data-urlencode "verso=acquisto"
# Comprare e rivendere nella STESSA piazza perde sempre: fra i due prezzi c'e'
# lo spread, ed e' il gioco che funziona. Per avere un margine positivo senza
# far viaggiare il personaggio si azzera il costo del carico — che e' esattamente
# lo stato in cui arriva la merce presa a qualcun altro in una rapina.
dbq "UPDATE carico SET costo_totale=0 WHERE personaggio_id=${PID} AND bene_id=${BENE}" >/dev/null
TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "azione=vendi_tutto"
maggiore "la vendita lascia un margine" "0" \
  "$(dbq "SELECT COALESCE(SUM(margine),0) FROM transazioni WHERE personaggio_id=${PID} AND verso='vendita'")"

PAGINA=$(c "${BASE_URL}/obiettivi")
verifica "vendere sblocca il primo affare" "1" \
  "$(dbq "SELECT COUNT(*) FROM obiettivi WHERE personaggio_id=${PID} AND codice='primo_affare'")"
contiene "e la pagina lo annuncia" "${PAGINA}" "Il primo affare"
verifica "e lascia un segnale" "1" \
  "$(dbq "SELECT COUNT(*) FROM segnali WHERE personaggio_id=${PID} AND genere='obiettivo'")"

dbq "UPDATE personaggi SET pulito=2000000 WHERE id=${PID}" >/dev/null
c -o /dev/null "${BASE_URL}/obiettivi"
verifica "il primo milione pulito si accorge da solo" "1" \
  "$(dbq "SELECT COUNT(*) FROM obiettivi WHERE personaggio_id=${PID} AND codice='primo_milione'")"
verifica "ma non si sblocca due volte" "1" \
  "$(dbq "SELECT COUNT(*) FROM obiettivi WHERE personaggio_id=${PID} AND codice='primo_affare'")"

# Il battito li verifica anche per chi non apre quella pagina.
dbq "UPDATE personaggi SET pulito=150000000 WHERE id=${PID};
     UPDATE users SET last_seen_at=NOW() WHERE username='${USER_NAME}'" >/dev/null
battito
verifica "il battito sblocca senza passare dalla pagina" "1" \
  "$(dbq "SELECT COUNT(*) FROM obiettivi WHERE personaggio_id=${PID} AND codice='cento_milioni'")"
verifica "e i rari finiscono sul giornale" "1" \
  "$(dbq "SELECT COUNT(*) FROM cronaca WHERE genere='obiettivo' AND testo LIKE '%${STAMP}%'")"

# --- Le graduatorie ---------------------------------------------------------------
# Si cerca il COLLEGAMENTO AL PROFILO con l'identificativo, non il nome: il
# nome di chi e' collegato sta anche nella testata di ogni pagina, e cercarlo
# li' farebbe passare la verifica sempre, anche a classifica vuota.
IO="profilo/${PID}\""
PAGINA=$(c "${BASE_URL}/classifica?g=patrimonio")
contiene "il patrimonio mette in classifica chi ha soldi" "${PAGINA}" "${IO}"
PAGINA=$(c "${BASE_URL}/classifica?g=reddito")
contiene "e il reddito chi ha venduto" "${PAGINA}" "${IO}"
PAGINA=$(c "${BASE_URL}/classifica?g=inventata")
contiene "una graduatoria inventata ripiega sul reddito" "${PAGINA}" "Reddito"

# La longevità vuole ANCHE il profilo criminale: chi non ha mai fatto niente
# non deve vincere la classifica di chi rischia e non si fa prendere.
PAGINA=$(c "${BASE_URL}/classifica?g=longevita")
grep -qF "${IO}" <<< "${PAGINA}" \
  && verifica "senza profilo criminale non si è longevi" "no" "si" \
  || verifica "senza profilo criminale non si è longevi" "no" "no"
dbq "UPDATE personaggi SET profilo=9, pulito_dal=DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE id=${PID}" >/dev/null
PAGINA=$(c "${BASE_URL}/classifica?g=longevita")
contiene "con il profilo alto sì" "${PAGINA}" "${IO}"

# --- I primati e l'albo d'oro -----------------------------------------------------
battito
verifica "il battito assegna il primato di patrimonio" "${PID}" \
  "$(dbq "SELECT COALESCE(personaggio_id,0) FROM primati WHERE graduatoria='patrimonio'")"

# Un regno lungo che finisce: deve entrare nell'albo.
dbq "UPDATE primati SET dal=DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE graduatoria='patrimonio';
     UPDATE personaggi SET pulito=0 WHERE id=${PID}" >/dev/null
battito
verifica "un regno di 40 giorni entra nell'albo" "1" \
  "$(dbq "SELECT COUNT(*) FROM albo WHERE graduatoria='patrimonio' AND personaggio_id=${PID}")"
maggiore "con i giorni giusti" "30" \
  "$(dbq "SELECT giorni FROM albo WHERE graduatoria='patrimonio' AND personaggio_id=${PID}")"
PAGINA=$(c "${BASE_URL}/albo")
contiene "e si vede nell'albo" "${PAGINA}" "${USER_NAME}"

# Un regno breve invece non ci entra: l'albo non è un elenco di passaggi.
dbq "UPDATE personaggi SET pulito=90000000 WHERE id=${PID}" >/dev/null
battito
PRIMA=$(dbq "SELECT COUNT(*) FROM albo")
dbq "UPDATE personaggi SET pulito=0 WHERE id=${PID}" >/dev/null
battito
verifica "un regno di pochi minuti no" "${PRIMA}" "$(dbq "SELECT COUNT(*) FROM albo")"

# --- Le statistiche ----------------------------------------------------------------
PAGINA=$(c "${BASE_URL}/statistiche")
contiene "le statistiche dicono il tetto del mondo" "${PAGINA}" "reddito massimo orario"
contiene "e i numeri di chi le guarda"               "${PAGINA}" "Come stai andando"

# --- L'amministrazione ---------------------------------------------------------------
consolle user:admin "${USER_NAME}" >/dev/null
verifica "il pannello risponde"    "200" "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/admin")"
PAGINA=$(c "${BASE_URL}/admin/mondo")
contiene "la plancia del mondo elenca le piazze" "${PAGINA}" "piazze in"
contiene "e mostra l'invariante del tetto"       "${PAGINA}" "Somma teorica"
PAGINA=$(c "${BASE_URL}/admin/giocatori")
contiene "l'elenco dei giocatori c'è" "${PAGINA}" "${IO}"

dbq "UPDATE piazze SET calore=99, calore_agg_a=NOW(3) WHERE id=${QUI}" >/dev/null
TOK=$(c "${BASE_URL}/admin/mondo" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/admin/mondo" --data-urlencode "_token=${TOK}" \
  --data-urlencode "azione=raffredda" --data-urlencode "piazza=${QUI}"
verifica "l'amministratore può raffreddare una piazza" "0.000" \
  "$(dbq "SELECT calore FROM piazze WHERE id=${QUI}")"

dbq "UPDATE personaggi SET carcere_fino_a=DATE_ADD(NOW(3), INTERVAL 5 HOUR) WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/admin/giocatori" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/admin/giocatori" --data-urlencode "_token=${TOK}" \
  --data-urlencode "azione=libera" --data-urlencode "id=${PID}"
verifica "e tirare fuori qualcuno dal carcere" "0" \
  "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID} AND carcere_fino_a IS NOT NULL")"

# La cancellazione vuole la parola scritta: è l'unica azione che distrugge.
TOK=$(c "${BASE_URL}/admin/giocatori" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/admin/giocatori" --data-urlencode "_token=${TOK}" \
  --data-urlencode "azione=cancella" --data-urlencode "id=${PID}"
verifica "senza conferma non si cancella niente" "1" \
  "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID}")"
verifica "e l'azione resta nel registro" "1" \
  "$(dbq "SELECT COUNT(*) FROM audit_log WHERE action='admin.mondo.raffredda'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)")"

# --- Pulizia --------------------------------------------------------------------------
dbq "UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, shock=0, agg_a=NOW(3) WHERE piazza_id=${QUI};
     UPDATE piazze SET calore=0, calore_agg_a=NULL WHERE id=${QUI};
     DELETE FROM albo WHERE personaggio_id=${PID};
     DELETE FROM primati WHERE personaggio_id=${PID}" >/dev/null
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1
verifica "utente di prova rimosso" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID}")"
verifica "e l'albo non tiene righe orfane di prova" "0" \
  "$(dbq "SELECT COUNT(*) FROM albo WHERE nome='${USER_NAME}'")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
