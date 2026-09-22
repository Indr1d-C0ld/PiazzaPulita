#!/usr/bin/env bash
#
# Piazza Pulita — la gente: vedersi, parlarsi, barattare (F8).
#
#   bash tests/e2e_gente.sh
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
JAR_A="$(mktemp)"; JAR_B="$(mktemp)"; TMPD="$(mktemp -d)"
STAMP="$(date +%s)"
A_NOME="prova Tizio ${STAMP}"; B_NOME="prova Caio ${STAMP}"
FALLITI=0

trap 'rm -rf "${JAR_A}" "${JAR_B}" "${TMPD}"' EXIT

a() { curl -s -k -b "${JAR_A}" -c "${JAR_A}" -H "Host: ${HOST_HDR}" "$@"; }
b() { curl -s -k -b "${JAR_B}" -c "${JAR_B}" -H "Host: ${HOST_HDR}" "$@"; }
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
contiene() {
  if grep -qF "$3" <<< "$2"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (manca: %s)\n' "$1" "$3"; FALLITI=$((FALLITI + 1)); fi
}
manca() {
  if grep -qF "$3" <<< "$2"; then printf '  \033[0;31mKO\033[0m    %s (non doveva esserci: %s)\n' "$1" "$3"; FALLITI=$((FALLITI + 1))
  else printf '  \033[0;32mok\033[0m    %s\n' "$1"; fi
}

if [[ "$(dbq "SELECT 1")" != "1" ]]; then
  echo "ERRORE: non riesco a interrogare il database indicato da ${CFG}." >&2
  exit 1
fi

echo "Prova end-to-end della gente — ${BASE_URL}"

nasci() { # fn, nome
  local fn="$1" nome="$2" tok cid
  tok=$(${fn} "${BASE_URL}/iscrizione" | token_da)
  ${fn} -o /dev/null -X POST "${BASE_URL}/iscrizione" --data-urlencode "_token=${tok}" \
    --data-urlencode "username=${nome}" --data-urlencode "email=prova_$(tr -cd '[:alnum:]' <<< "${nome}")@esempio.invalid" \
    --data-urlencode "password=piazza123" --data-urlencode "password_confirm=piazza123"
  consolle user:verify "${nome}" >/dev/null 2>&1
  tok=$(${fn} "${BASE_URL}/accesso" | token_da)
  ${fn} -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${tok}" \
    --data-urlencode "login=${nome}" --data-urlencode "password=piazza123"
  cid=$(dbq "SELECT id FROM citta WHERE codice='NA'")
  tok=$(${fn} "${BASE_URL}/inizio" | token_da)
  ${fn} -o /dev/null -X POST "${BASE_URL}/inizio" --data-urlencode "_token=${tok}" --data-urlencode "citta=${cid}"
}
nasci a "${A_NOME}"; nasci b "${B_NOME}"
AID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${A_NOME}'")
BID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${B_NOME}'")
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${AID}")
ALTROVE=$(dbq "SELECT id FROM piazze WHERE id<>${QUI} LIMIT 1")
dbq "UPDATE personaggi SET piazza_id=${QUI}, arrivo_at=NULL, contante=9000000 WHERE id IN (${AID},${BID});
     DELETE FROM chiacchiere WHERE piazza_id IN (${QUI},${ALTROVE})" >/dev/null

# --- Ci si vede, con la faccia -------------------------------------------------
php -r '$im=imagecreatetruecolor(300,300); imagefilledrectangle($im,0,0,300,300,imagecolorallocate($im,150,60,50));
        imagejpeg($im,"'"${TMPD}"'/faccia.jpg",90);'
TOK=$(b "${BASE_URL}/profilo" | token_da)
b -o /dev/null -L -X POST "${BASE_URL}/profilo/foto" -F "_token=${TOK}" -F "sx=0" -F "sy=0" -F "lato=0" \
  -F "foto=@${TMPD}/faccia.jpg;type=image/jpeg"
FOTO=$(dbq "SELECT avatar_file FROM users WHERE username='${B_NOME}'")
PAGINA=$(a "${BASE_URL}/altri")
contiene "nella piazza si vede chi c'è"        "${PAGINA}" "${B_NOME}"
contiene "e si vede in faccia"                  "${PAGINA}" "img/avatar/${FOTO}"

# --- I nomi sulla carta ---------------------------------------------------------
#
# Sulla carta del giocatore si vede sé stessi e chi si potrebbe sapere comunque:
# chi è nella tua città lo incroci per strada. Chi sta dall'altra parte del
# paese no — sapere dove sta la gente è informazione tattica, e qui
# l'informazione su quello che succede altrove si paga.
CARTA=$(a "${BASE_URL}/mappa")
contiene "sulla carta si vede il proprio nome" "${CARTA}" "${A_NOME}"
contiene "segnato come «io»"                   "${CARTA}" 'tale--io'
# Sulla carta i nomi lunghi sono troncati, o sfonderebbero il foglio: si cerca
# l'inizio, non il nome intero.
contiene "e chi è nella stessa città"          "${CARTA}" "${B_NOME:0:14}"

dbq "UPDATE personaggi SET piazza_id=${ALTROVE} WHERE id=${BID}" >/dev/null
CARTA=$(a "${BASE_URL}/mappa")
manca "chi è lontano non si vede" "${CARTA}" "${B_NOME:0:14}"
contiene "ma il proprio nome sì" "${CARTA}" "${A_NOME}"
dbq "UPDATE personaggi SET piazza_id=${QUI} WHERE id=${BID}" >/dev/null

# --- La chiacchiera è DI PIAZZA -------------------------------------------------
TOK=$(a "${BASE_URL}/altri" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/altri/parla" --data-urlencode "_token=${TOK}" \
  --data-urlencode "testo=Cerco roba buona, pago bene"
verifica "la voce è agli atti" "1" "$(dbq "SELECT COUNT(*) FROM chiacchiere WHERE piazza_id=${QUI} AND personaggio_id=${AID}")"
contiene "e chi è lì la sente" "$(b "${BASE_URL}/altri")" "Cerco roba buona, pago bene"

# Da un'altra piazza non si sente: è la regola che tiene in piedi il basista.
dbq "UPDATE personaggi SET piazza_id=${ALTROVE} WHERE id=${BID}" >/dev/null
manca "da un'altra piazza non si sente" "$(b "${BASE_URL}/altri")" "Cerco roba buona, pago bene"
dbq "UPDATE personaggi SET piazza_id=${QUI} WHERE id=${BID}" >/dev/null

# --- Il baratto: merce contro merce, mai denaro ---------------------------------
dbq "DELETE FROM carico WHERE personaggio_id IN (${AID},${BID});
     DELETE FROM baratti WHERE da_id IN (${AID},${BID}) OR a_id IN (${AID},${BID});
     INSERT INTO carico (personaggio_id,bene_id,quantita,costo_totale)
       VALUES (${AID},1,20,400000),(${BID},2,20,300000)" >/dev/null
SOLDI_A=$(dbq "SELECT contante FROM personaggi WHERE id=${AID}")
SOLDI_B=$(dbq "SELECT contante FROM personaggi WHERE id=${BID}")

TOK=$(a "${BASE_URL}/altri" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/altri/baratto" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chi=${BID}" --data-urlencode "bene_dato=1" --data-urlencode "quanto_dato=8" \
  --data-urlencode "bene_chiesto=2" --data-urlencode "quanto_chiesto=5"
BAR=$(dbq "SELECT id FROM baratti WHERE da_id=${AID} ORDER BY id DESC LIMIT 1")
verifica "la proposta esiste"  "proposto" "$(dbq "SELECT stato FROM baratti WHERE id=${BAR}")"
verifica "e lascia un segnale" "1" "$(dbq "SELECT COUNT(*) FROM segnali WHERE personaggio_id=${BID} AND genere='baratto'")"

# Non si baratta quello che non si ha.
TOK=$(a "${BASE_URL}/altri" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/altri/baratto" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chi=${BID}" --data-urlencode "bene_dato=3" --data-urlencode "quanto_dato=5" \
  --data-urlencode "bene_chiesto=2" --data-urlencode "quanto_chiesto=1"
verifica "non si offre merce che non si ha" "1" "$(dbq "SELECT COUNT(*) FROM baratti WHERE da_id=${AID}")"

TOK=$(b "${BASE_URL}/altri" | token_da)
b -o /dev/null -L -X POST "${BASE_URL}/altri/baratto/rispondi" --data-urlencode "_token=${TOK}" \
  --data-urlencode "baratto=${BAR}" --data-urlencode "risposta=accetta"
verifica "accettato"                    "accettato" "$(dbq "SELECT stato FROM baratti WHERE id=${BAR}")"
verifica "la merce è passata a chi accetta" "8"  "$(dbq "SELECT quantita FROM carico WHERE personaggio_id=${BID} AND bene_id=1")"
verifica "e quella chiesta è arrivata"      "5"  "$(dbq "SELECT quantita FROM carico WHERE personaggio_id=${AID} AND bene_id=2")"
# Il costo viaggia con la merce, o il primo margine sarebbe finto.
verifica "il costo segue la merce (8 x 20.000)" "160000" "$(dbq "SELECT costo_totale FROM carico WHERE personaggio_id=${BID} AND bene_id=1")"
verifica "e quello che resta è in proporzione" "240000" "$(dbq "SELECT costo_totale FROM carico WHERE personaggio_id=${AID} AND bene_id=1")"
# LA verifica che conta: il baratto non muove una lira.
verifica "nessuna lira si è mossa (chi propone)" "${SOLDI_A}" "$(dbq "SELECT contante FROM personaggi WHERE id=${AID}")"
verifica "nessuna lira si è mossa (chi accetta)" "${SOLDI_B}" "$(dbq "SELECT contante FROM personaggi WHERE id=${BID}")"

# Non si baratta a distanza.
dbq "UPDATE personaggi SET piazza_id=${ALTROVE} WHERE id=${BID}" >/dev/null
TOK=$(a "${BASE_URL}/altri" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/altri/baratto" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chi=${BID}" --data-urlencode "bene_dato=1" --data-urlencode "quanto_dato=1" \
  --data-urlencode "bene_chiesto=2" --data-urlencode "quanto_chiesto=1"
verifica "non si baratta a distanza" "1" "$(dbq "SELECT COUNT(*) FROM baratti WHERE da_id=${AID}")"
dbq "UPDATE personaggi SET piazza_id=${QUI} WHERE id=${BID}" >/dev/null

# Le proposte scadono, e le chiude il battito.
dbq "INSERT INTO baratti (da_id,a_id,piazza_id,bene_dato,quanto_dato,bene_chiesto,quanto_chiesto,proposto_at,scade_at)
     VALUES (${AID},${BID},${QUI},1,1,2,1,NOW(3),DATE_SUB(NOW(3), INTERVAL 5 MINUTE))" >/dev/null
VECCHIO=$(dbq "SELECT id FROM baratti WHERE da_id=${AID} ORDER BY id DESC LIMIT 1")
battito
verifica "le proposte vecchie scadono da sole" "scaduto" "$(dbq "SELECT stato FROM baratti WHERE id=${VECCHIO}")"

# --- L'amministrazione ------------------------------------------------------------
consolle user:admin "${A_NOME}" >/dev/null
PAGINA=$(a "${BASE_URL}/admin/carta")
contiene "la carta globale elenca la gente" "${PAGINA}" "${B_NOME}"
contiene "e disegna la cartina"             "${PAGINA}" 'class="carta"'
# L'admin li vede tutti per nome sulla carta, anche quelli lontani.
dbq "UPDATE personaggi SET piazza_id=${ALTROVE} WHERE id=${BID}" >/dev/null
PAGINA=$(a "${BASE_URL}/admin/carta")
grep -c 'class="tale' <<< "${PAGINA}" | while read -r N; do
  [[ "${N}" -ge 2 ]] && printf '  \033[0;32mok\033[0m    e i nomi sono scritti sulla cartina\n' \
                     || printf '  \033[0;31mKO\033[0m    i nomi non sono sulla cartina\n'
done
dbq "UPDATE personaggi SET piazza_id=${QUI} WHERE id=${BID}" >/dev/null

TOK=$(a "${BASE_URL}/admin/carta" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/admin/carta/scrivi" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chi=${BID}" --data-urlencode "testo=Ti tengo d'occhio."
verifica "l'admin scrive a un giocatore" "1" \
  "$(dbq "SELECT COUNT(*) FROM segnali WHERE personaggio_id=${BID} AND genere='avviso' AND testo LIKE 'Ti tengo%'")"

TOK=$(a "${BASE_URL}/admin/carta" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/admin/carta/affiggi" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${QUI}" --data-urlencode "testo=Controlli straordinari questa notte."
verifica "e affigge un cartello in piazza" "1" \
  "$(dbq "SELECT COUNT(*) FROM chiacchiere WHERE piazza_id=${QUI} AND genere='avviso'")"
contiene "che si legge stando lì" "$(b "${BASE_URL}/altri")" "Controlli straordinari questa notte."

# Un giocatore comune non affigge niente.
TOK=$(b "${BASE_URL}/strada" | token_da)
CODE=$(b -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/admin/carta/affiggi" \
  --data-urlencode "_token=${TOK}" --data-urlencode "piazza=${QUI}" --data-urlencode "testo=comando io")
verifica "un giocatore comune non affigge" "403" "${CODE}"

# --- Le voci si dimenticano --------------------------------------------------------
dbq "UPDATE chiacchiere SET fatto_at = DATE_SUB(NOW(3), INTERVAL 3 DAY) WHERE piazza_id=${QUI}" >/dev/null
battito
verifica "le voci vecchie si dimenticano" "0" "$(dbq "SELECT COUNT(*) FROM chiacchiere WHERE piazza_id=${QUI}")"

# --- Pulizia ------------------------------------------------------------------------
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${A_NOME}" >/dev/null 2>&1
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${B_NOME}" >/dev/null 2>&1
verifica "utenti di prova rimossi" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id IN (${AID},${BID})")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
