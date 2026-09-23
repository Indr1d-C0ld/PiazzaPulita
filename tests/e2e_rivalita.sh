#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end del giro degli altri (F6).
#
#   bash tests/e2e_rivalita.sh
#
# Due giocatori veri nella stessa piazza, attraverso Apache: aggressione con
# bottino, la soglia sotto la quale non si prende niente a nessuno, la soffiata,
# la spia, la batteria e il territorio con il pizzo.
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
JAR_A="$(mktemp)"; JAR_B="$(mktemp)"
STAMP="$(date +%s)"
A_NAME="prova Grosso ${STAMP}";  A_MAIL="prova_a_${STAMP}@esempio.invalid"
B_NAME="prova Piccolo ${STAMP}"; B_MAIL="prova_b_${STAMP}@esempio.invalid"
PASS="piazza123"
FALLITI=0

trap 'rm -f "${JAR_A}" "${JAR_B}"' EXIT

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
maggiore() {
  if php -r "exit(((float)'$3') > ((float)'$2') ? 0 : 1);"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: > %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
}

if [[ "$(dbq "SELECT 1")" != "1" ]]; then
  echo "ERRORE: non riesco a interrogare il database indicato da ${CFG}." >&2
  echo "Se hai PIAZZAPULITA_CONFIG in ambiente puntato a un database di sviluppo," >&2
  echo "toglilo: le prove end-to-end girano sull'installazione vera." >&2
  exit 1
fi

echo "Prova end-to-end del giro degli altri — ${BASE_URL}"

# --- Due giocatori, stessa piazza ---------------------------------------------
nasci() { # jar-fn, nome, mail
  local fn="$1" nome="$2" mail="$3" tok
  tok=$(${fn} "${BASE_URL}/iscrizione" | token_da)
  ${fn} -o /dev/null -X POST "${BASE_URL}/iscrizione" --data-urlencode "_token=${tok}" \
    --data-urlencode "username=${nome}" --data-urlencode "email=${mail}" \
    --data-urlencode "password=${PASS}" --data-urlencode "password_confirm=${PASS}"
  consolle user:verify "${nome}" >/dev/null
  tok=$(${fn} "${BASE_URL}/accesso" | token_da)
  ${fn} -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${tok}" \
    --data-urlencode "login=${nome}" --data-urlencode "password=${PASS}"
  tok=$(${fn} "${BASE_URL}/inizio" | token_da)
  ${fn} -o /dev/null -X POST "${BASE_URL}/inizio" --data-urlencode "_token=${tok}" \
    --data-urlencode "citta=${CITTA}"
}
CITTA=$(dbq "SELECT id FROM citta WHERE codice='NA'")
nasci a "${A_NAME}" "${A_MAIL}"
nasci b "${B_NAME}" "${B_MAIL}"
AID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${A_NAME}'")
BID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${B_NAME}'")
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${AID}")
dbq "UPDATE personaggi SET piazza_id=${QUI}, arrivo_at=NULL WHERE id IN (${AID},${BID})" >/dev/null

verifica "la pagina degli altri risponde" "200" "$(a -o /dev/null -w '%{http_code}' "${BASE_URL}/altri")"
a "${BASE_URL}/altri" | grep -q "${B_NAME}" \
  && verifica "e ci si vede a vicenda" "si" "si" || verifica "e ci si vede a vicenda" "si" "no"

# --- Chi non ha niente non perde niente ---------------------------------------
# È la regola del §0.1b: sotto la soglia si mena e basta. Qui si misura che la
# vittima spiantata non perda una lira, e che l'aggressore non incassi.
ARMI=$(dbq "SELECT id FROM beni WHERE codice='armi'")
arma() { # quante armi addosso a chi
  dbq "INSERT INTO carico (personaggio_id, bene_id, quantita, costo_totale) VALUES ($2, ${ARMI}, $1, 0)
       ON DUPLICATE KEY UPDATE quantita=VALUES(quantita)" >/dev/null
}
dbq "UPDATE personaggi SET contante=0, salute=100 WHERE id=${BID};
     UPDATE personaggi SET contante=1000000, salute=100 WHERE id=${AID}" >/dev/null
arma 3 "${AID}"
PRIMA_A=$(dbq "SELECT contante FROM personaggi WHERE id=${AID}")
TOK=$(a "${BASE_URL}/altri" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/altri/attacca" --data-urlencode "_token=${TOK}" --data-urlencode "chi=${BID}"
verifica "chi è spiantato non perde niente" "0" "$(dbq "SELECT contante FROM personaggi WHERE id=${BID}")"
verifica "e chi l'ha pestato non incassa"   "${PRIMA_A}" "$(dbq "SELECT contante FROM personaggi WHERE id=${AID}")"
maggiore "ma il profilo criminale sale"     "0" "$(dbq "SELECT profilo FROM personaggi WHERE id=${AID}")"
maggiore "e il calore pure"                 "0" "$(dbq "SELECT calore FROM personaggi WHERE id=${AID}")"
maggiore "lo scontro è agli atti"           "0" "$(dbq "SELECT COUNT(*) FROM scontri WHERE attaccante_id=${AID}")"
maggiore "e sul giornale"                   "0" "$(dbq "SELECT COUNT(*) FROM cronaca WHERE genere='scontro'")"

# --- Sopra soglia il bottino c'è ----------------------------------------------
dbq "UPDATE personaggi SET contante=8000000, salute=100, ospedale_fino_a=NULL WHERE id=${BID};
     UPDATE personaggi SET contante=0, salute=100, ospedale_fino_a=NULL WHERE id=${AID};
     DELETE FROM carico WHERE personaggio_id=${BID};
     DELETE FROM uomini WHERE personaggio_id=${BID}" >/dev/null
arma 3 "${AID}"
for i in 1 2 3 4 5; do
  if [[ "$(dbq "SELECT COALESCE(ospedale_fino_a,'') FROM personaggi WHERE id=${BID}")" != "" ]]; then break; fi
  dbq "UPDATE personaggi SET salute=100, ospedale_fino_a=NULL WHERE id=${AID}" >/dev/null
  TOK=$(a "${BASE_URL}/altri" | token_da)
  a -o /dev/null -L -X POST "${BASE_URL}/altri/attacca" --data-urlencode "_token=${TOK}" --data-urlencode "chi=${BID}"
done
VINTO=$(dbq "SELECT COUNT(*) FROM scontri WHERE attaccante_id=${AID} AND esito='vinto'")
if [[ "${VINTO}" -gt 0 ]]; then
  maggiore "chi vince si prende il contante altrui" "0" "$(dbq "SELECT contante FROM personaggi WHERE id=${AID}")"
  verifica "e la vittima resta senza"                "0" "$(dbq "SELECT contante FROM personaggi WHERE id=${BID}")"
  verifica "e finisce all'ospedale" "si" \
    "$(if [[ "$(dbq "SELECT COALESCE(ospedale_fino_a,'') FROM personaggi WHERE id=${BID}")" != "" ]]; then echo si; else echo no; fi)"
  DOVE=$(b -o /dev/null -L -w '%{url_effective}' "${BASE_URL}/strada")
  verifica "e dall'ospedale non si va da nessuna parte" "si" \
    "$(if [[ "${DOVE}" == *"/altri" ]]; then echo si; else echo "no (${DOVE})"; fi)"
else
  printf '  \033[0;33m--\033[0m    cinque assalti tutti andati male: bottino non misurato\n'
fi

# --- La soffiata ---------------------------------------------------------------
dbq "UPDATE personaggi SET contante=5000000, salute=100, ospedale_fino_a=NULL WHERE id=${AID};
     UPDATE personaggi SET salute=100, ospedale_fino_a=NULL WHERE id=${BID};
     DELETE FROM fascicoli WHERE personaggio_id IN (${AID},${BID})" >/dev/null
TOK=$(a "${BASE_URL}/altri" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/altri/soffiata" --data-urlencode "_token=${TOK}" --data-urlencode "chi=${BID}"
verifica "la telefonata è agli atti" "1" "$(dbq "SELECT COUNT(*) FROM soffiate WHERE da_id=${AID} AND contro_id=${BID}")"
maggiore "e costa"                   "0" "$(dbq "SELECT costo FROM soffiate WHERE da_id=${AID} AND contro_id=${BID}")"
maggiore "le prove finiscono su qualcuno" "0" \
  "$(dbq "SELECT COALESCE(SUM(prove),0) FROM fascicoli WHERE personaggio_id IN (${AID},${BID})")"

# Si soffia su chi è qui: la vetrina la offre così, e ora anche il server.
ALTROVE=$(dbq "SELECT id FROM piazze WHERE id <> ${QUI} LIMIT 1")
dbq "UPDATE personaggi SET piazza_id=${ALTROVE} WHERE id=${BID}" >/dev/null
TOK=$(a "${BASE_URL}/altri" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/altri/soffiata" --data-urlencode "_token=${TOK}" --data-urlencode "chi=${BID}"
verifica "su chi non è qui non si soffia" "1" "$(dbq "SELECT COUNT(*) FROM soffiate WHERE da_id=${AID} AND contro_id=${BID}")"
dbq "UPDATE personaggi SET piazza_id=${QUI} WHERE id=${BID}" >/dev/null

# --- La spia --------------------------------------------------------------------
dbq "UPDATE personaggi SET contante=9000000 WHERE id=${AID}" >/dev/null
dbq "INSERT INTO uomini (personaggio_id, nome, ruolo, competenza, lealta, stipendio_ora, stato,
                         assunto_at, pagato_fino_a, agg_a)
     VALUES (${AID}, 'Gennaro', 'vedetta', 40, 80, 5000, 'libero',
             NOW(), DATE_ADD(NOW(3), INTERVAL 2 DAY), NOW(3))" >/dev/null
UOMO=$(dbq "SELECT id FROM uomini WHERE personaggio_id=${AID} ORDER BY id DESC LIMIT 1")
TOK=$(a "${BASE_URL}/altri" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/altri/infiltra" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chi=${BID}" --data-urlencode "uomo=${UOMO}"
verifica "la spia è dentro"        "1" "$(dbq "SELECT COUNT(*) FROM spie WHERE padrone_id=${AID} AND bersaglio_id=${BID} AND esito='dentro'")"
verifica "e non è più un tuo uomo" "0" "$(dbq "SELECT COUNT(*) FROM uomini WHERE id=${UOMO}")"
dbq "UPDATE personaggi SET contante=4321000 WHERE id=${BID}" >/dev/null
a "${BASE_URL}/altri" | grep -q "4.321.000" \
  && verifica "e riferisce quello che vede" "si" "si" || verifica "e riferisce quello che vede" "si" "no"

# --- La batteria e il territorio -------------------------------------------------
dbq "UPDATE personaggi SET pulito=12000000 WHERE id=${AID}" >/dev/null
TOK=$(a "${BASE_URL}/batteria" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/batteria/fonda" --data-urlencode "_token=${TOK}" \
  --data-urlencode "nome=Prova ${STAMP}" --data-urlencode "sigla=P${STAMP: -3}" --data-urlencode "motto=per prova"
BAT=$(dbq "SELECT id FROM batterie WHERE capo_id=${AID}")
verifica "la batteria è in piedi" "si" "$(if [[ -n "${BAT}" ]]; then echo si; else echo no; fi)"
verifica "e costa denaro pulito"  "2000000" "$(dbq "SELECT pulito FROM personaggi WHERE id=${AID}")"

# Entrare è chiedere: fino al sì del capo si resta fuori. Prima si entrava da
# soli, e bastava per non pagare il pizzo a chi comanda la piazza.
TOK=$(b "${BASE_URL}/batteria" | token_da)
b -o /dev/null -L -X POST "${BASE_URL}/batteria/entra" --data-urlencode "_token=${TOK}" --data-urlencode "batteria=${BAT}"
verifica "chiedere non basta per entrare" "1" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE batteria_id=${BAT}")"
verifica "la domanda arriva al capo"     "1" "$(dbq "SELECT COUNT(*) FROM batteria_domande WHERE personaggio_id=${BID} AND batteria_id=${BAT}")"
a "${BASE_URL}/batteria" | grep -q "Chi chiede di entrare" \
  && verifica "e il capo la vede" "si" "si" || verifica "e il capo la vede" "si" "no"
TOK=$(b "${BASE_URL}/batteria" | token_da)
verifica "solo il capo risponde" "0" "$(b -o /dev/null -L -X POST "${BASE_URL}/batteria/domanda" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chi=${BID}" --data-urlencode "esito=si" >/dev/null; dbq "SELECT COUNT(*) FROM personaggi WHERE id=${BID} AND batteria_id=${BAT}")"
TOK=$(a "${BASE_URL}/batteria" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/batteria/domanda" --data-urlencode "_token=${TOK}" \
  --data-urlencode "chi=${BID}" --data-urlencode "esito=si"
verifica "e ci si entra quando dice sì" "2" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE batteria_id=${BAT}")"
verifica "e la domanda si chiude"      "0" "$(dbq "SELECT COUNT(*) FROM batteria_domande WHERE personaggio_id=${BID}")"

# Il capo con qualcuno dentro non esce: prima passa la mano. Prima il modo di
# passarla non c'era, e il capo restava capo per sempre.
TOK=$(a "${BASE_URL}/batteria" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/batteria/esci" --data-urlencode "_token=${TOK}"
verifica "il capo non esce lasciando gli altri" "${BAT}" "$(dbq "SELECT COALESCE(batteria_id,0) FROM personaggi WHERE id=${AID}")"
TOK=$(a "${BASE_URL}/batteria" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/batteria/capo" --data-urlencode "_token=${TOK}" --data-urlencode "membro=${BID}"
verifica "passa la mano"                 "${BID}" "$(dbq "SELECT capo_id FROM batterie WHERE id=${BAT}")"
TOK=$(b "${BASE_URL}/batteria" | token_da)
b -o /dev/null -L -X POST "${BASE_URL}/batteria/capo" --data-urlencode "_token=${TOK}" --data-urlencode "membro=${AID}"
verifica "e se la riprende"              "${AID}" "$(dbq "SELECT capo_id FROM batterie WHERE id=${BAT}")"

# Il territorio si tiene lavorandoci: una compravendita vera deve lasciare punti.
dbq "UPDATE personaggi SET contante=5000000, salute=100, ospedale_fino_a=NULL, carcere_fino_a=NULL WHERE id=${AID};
     UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
BENE=$(dbq "SELECT bene_id FROM mercati WHERE piazza_id=${QUI} ORDER BY domanda_eq DESC LIMIT 1")
TOK=$(a "${BASE_URL}/strada" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=4" --data-urlencode "verso=acquisto"
maggiore "lavorare lascia presenza" "0" "$(dbq "SELECT COALESCE(punti,0) FROM presenze WHERE piazza_id=${QUI} AND batteria_id=${BAT}")"

# Sopra soglia la piazza passa, e allora gli estranei pagano il pizzo.
dbq "UPDATE presenze SET punti=100000, agg_a=NOW(3) WHERE piazza_id=${QUI} AND batteria_id=${BAT}" >/dev/null
battito
verifica "sopra soglia la piazza è tua" "${BAT}" "$(dbq "SELECT COALESCE(batteria_id,0) FROM territori WHERE piazza_id=${QUI}")"

# Il capo manda via B: da estraneo, B torna a pagare il pizzo — anche con una
# domanda d'ingresso appena fatta, che non conta finché non è accolta.
TOK=$(a "${BASE_URL}/batteria" | token_da)
a -o /dev/null -L -X POST "${BASE_URL}/batteria/caccia" --data-urlencode "_token=${TOK}" --data-urlencode "membro=${BID}"
verifica "il capo manda via uno dei suoi" "0" "$(dbq "SELECT COALESCE(batteria_id,0) FROM personaggi WHERE id=${BID}")"
TOK=$(b "${BASE_URL}/batteria" | token_da)
b -o /dev/null -L -X POST "${BASE_URL}/batteria/entra" --data-urlencode "_token=${TOK}" --data-urlencode "batteria=${BAT}"
dbq "UPDATE personaggi SET contante=5000000 WHERE id=${BID};
     UPDATE batterie SET cassa=0 WHERE id=${BAT}" >/dev/null
TOK=$(b "${BASE_URL}/strada" | token_da)
b -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=4" --data-urlencode "verso=acquisto"
maggiore "e gli estranei pagano il pizzo" "0" "$(dbq "SELECT cassa FROM batterie WHERE id=${BAT}")"

# --- La cronaca ------------------------------------------------------------------
verifica "il giornale risponde" "200" "$(a -o /dev/null -w '%{http_code}' "${BASE_URL}/cronaca")"

# --- Pulizia ----------------------------------------------------------------------
dbq "DELETE FROM territori WHERE piazza_id=${QUI};
     DELETE FROM presenze WHERE piazza_id=${QUI};
     UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, shock=0, agg_a=NOW(3) WHERE piazza_id=${QUI};
     UPDATE piazze SET calore=0, calore_agg_a=NULL WHERE id=${QUI}" >/dev/null
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${A_NAME}" >/dev/null 2>&1
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${B_NAME}" >/dev/null 2>&1
verifica "utenti di prova rimossi" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id IN (${AID},${BID})")"
verifica "batteria di prova rimossa" "0" "$(dbq "SELECT COUNT(*) FROM batterie WHERE id=${BAT}")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
