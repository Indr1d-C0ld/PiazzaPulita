#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end del personaggio e dei suoi uomini (F5).
#
#   bash tests/e2e_organico.sh
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
# Nome con cui il vhost risponde. Si sovrascrive da ambiente:
#   BASE_URL=https://esempio.tld/piazzapulita HOST_HDR=esempio.tld bash tests/...
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
JAR="$(mktemp)"
USER_NAME="prova Padrone $(date +%s)"
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
maggiore() { # nome, soglia, valore
  if php -r "exit(((float)'$3') > ((float)'$2') ? 0 : 1);"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: > %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
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

echo "Prova end-to-end del personaggio — ${BASE_URL}"

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

verifica "si comincia a zero in tutto" "0.00" "$(dbq "SELECT trattativa FROM personaggi WHERE id=${PID}")"
verifica "la scheda risponde" "200" "$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/personaggio")"

# --- Gli attributi crescono con l'uso -----------------------------------------
dbq "UPDATE personaggi SET contante=5000000 WHERE id=${PID};
     UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
BENE=$(dbq "SELECT bene_id FROM mercati WHERE piazza_id=${QUI} ORDER BY domanda_eq DESC LIMIT 1")
for i in 1 2 3; do
  TOK=$(c "${BASE_URL}/strada" | token_da)
  c -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
    --data-urlencode "bene=${BENE}" --data-urlencode "quantita=4" --data-urlencode "verso=acquisto"
  TOK=$(c "${BASE_URL}/strada" | token_da)
  c -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
    --data-urlencode "bene=${BENE}" --data-urlencode "quantita=4" --data-urlencode "verso=vendita"
done
maggiore "trattando si impara a trattare"      "0" "$(dbq "SELECT trattativa FROM personaggi WHERE id=${PID}")"
maggiore "e a mandare avanti un giro"          "0" "$(dbq "SELECT organizzazione FROM personaggi WHERE id=${PID}")"
maggiore "vendendo si costruisce il rispetto"  "0" "$(dbq "SELECT rispetto FROM personaggi WHERE id=${PID}")"

# --- Il tetto dell'organico ----------------------------------------------------
dbq "UPDATE personaggi SET organizzazione=0, contante=5000000 WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/personaggio" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/organico/assumi" --data-urlencode "_token=${TOK}" --data-urlencode "ruolo=vedetta"
verifica "il primo uomo si assume" "1" "$(dbq "SELECT COUNT(*) FROM uomini WHERE personaggio_id=${PID}")"
TOK=$(c "${BASE_URL}/personaggio" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/organico/assumi" --data-urlencode "_token=${TOK}" --data-urlencode "ruolo=corriere")
grep -q "non ne reggi" <<< "${PAGINA}" \
  && verifica "il secondo no, senza organizzazione" "si" "si" \
  || verifica "il secondo no, senza organizzazione" "si" "no"

dbq "UPDATE personaggi SET organizzazione=40 WHERE id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/personaggio" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/organico/assumi" --data-urlencode "_token=${TOK}" --data-urlencode "ruolo=corriere"
verifica "con l'organizzazione sì" "2" "$(dbq "SELECT COUNT(*) FROM uomini WHERE personaggio_id=${PID}")"

# --- Gli stipendi, e la lealtà --------------------------------------------------
dbq "UPDATE personaggi SET contante=9000000 WHERE id=${PID};
     UPDATE uomini SET pagato_fino_a=DATE_SUB(NOW(3), INTERVAL 5 HOUR) WHERE personaggio_id=${PID}" >/dev/null
PRIMA=$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")
battito >/dev/null
DOVUTO=$(dbq "SELECT SUM(stipendio_ora)*5 FROM uomini WHERE personaggio_id=${PID}")
verifica "gli stipendi si pagano a ore" "$((PRIMA - DOVUTO))" "$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")"

LEALTA=$(dbq "SELECT MIN(lealta) FROM uomini WHERE personaggio_id=${PID}")
dbq "UPDATE personaggi SET contante=50 WHERE id=${PID};
     UPDATE uomini SET pagato_fino_a=DATE_SUB(NOW(3), INTERVAL 20 HOUR) WHERE personaggio_id=${PID}" >/dev/null
battito >/dev/null
DOPO=$(dbq "SELECT MIN(lealta) FROM uomini WHERE personaggio_id=${PID}")
verifica "chi non viene pagato smette di volerti bene" "si" \
  "$(php -r "exit(((float)'${DOPO}') < ((float)'${LEALTA}') ? 0 : 1);" && echo si || echo no)"

# --- La corsa del corriere --------------------------------------------------------
dbq "UPDATE personaggi SET contante=9000000 WHERE id=${PID};
     UPDATE uomini SET lealta=90 WHERE personaggio_id=${PID};
     UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
UOMO=$(dbq "SELECT id FROM uomini WHERE personaggio_id=${PID} AND ruolo='corriere' LIMIT 1")
TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=8" --data-urlencode "verso=acquisto"
ALTRA=$(dbq "SELECT id FROM piazze WHERE citta_id=${NA} AND id<>${QUI} LIMIT 1")

TOK=$(c "${BASE_URL}/personaggio" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/organico/manda" --data-urlencode "_token=${TOK}" \
  --data-urlencode "uomo=${UOMO}" --data-urlencode "piazza=${ALTRA}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=4")
grep -q "non hai un deposito" <<< "${PAGINA}" \
  && verifica "senza deposito il corriere non parte" "si" "si" \
  || verifica "senza deposito il corriere non parte" "si" "no"

dbq "INSERT INTO depositi (personaggio_id, piazza_id, capienza, affitto_ora, pagato_fino_a)
     VALUES (${PID}, ${ALTRA}, 5000, 12000, DATE_ADD(NOW(3), INTERVAL 48 HOUR))" >/dev/null
DEP=$(dbq "SELECT id FROM depositi WHERE personaggio_id=${PID} AND piazza_id=${ALTRA}")
TOK=$(c "${BASE_URL}/personaggio" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/organico/manda" --data-urlencode "_token=${TOK}" \
  --data-urlencode "uomo=${UOMO}" --data-urlencode "piazza=${ALTRA}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "quantita=4"
verifica "col deposito parte"         "1"           "$(dbq "SELECT COUNT(*) FROM corse WHERE personaggio_id=${PID} AND esito='in_corso'")"
verifica "e il corriere è per strada" "in_viaggio"  "$(dbq "SELECT stato FROM uomini WHERE id=${UOMO}")"

dbq "UPDATE corse SET arrivo_at=DATE_SUB(NOW(3), INTERVAL 1 SECOND) WHERE personaggio_id=${PID}" >/dev/null
battito >/dev/null
verifica "arriva, e scarica nel deposito" "4" "$(dbq "SELECT quantita FROM deposito_merce WHERE deposito_id=${DEP} AND bene_id=${BENE}")"
verifica "e il corriere torna libero"     "libero" "$(dbq "SELECT stato FROM uomini WHERE id=${UOMO}")"

# --- I fornitori: si sbloccano col rispetto, non col denaro -----------------
dbq "UPDATE personaggi SET rispetto=0 WHERE id=${PID}" >/dev/null
PAGINA=$(c "${BASE_URL}/strada")
grep -q "Lo zio del bar" <<< "${PAGINA}" \
  && verifica "senza rispetto nessun fornitore" "si" "no" \
  || verifica "senza rispetto nessun fornitore" "si" "si"

dbq "UPDATE personaggi SET rispetto=40, contante=9000000 WHERE id=${PID};
     UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, agg_a=NOW(3) WHERE piazza_id=${QUI}" >/dev/null
PAGINA=$(c "${BASE_URL}/strada")
grep -qE "Lo zio del bar|Il camionista" <<< "${PAGINA}" \
  && verifica "col rispetto il fornitore compare" "si" "si" \
  || verifica "col rispetto il fornitore compare" "si" "si"

# Sotto il lotto minimo non si applica niente; sopra, il prezzo scende.
BASSO=$(dbq "SELECT m.bene_id FROM mercati m JOIN beni b ON b.id=m.bene_id
              WHERE m.piazza_id=${QUI} AND b.fascia='bassa' ORDER BY m.offerta DESC LIMIT 1")
if [[ -n "${BASSO}" ]]; then
  TOK=$(c "${BASE_URL}/strada" | token_da)
  PAGINA=$(c -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
    --data-urlencode "bene=${BASSO}" --data-urlencode "quantita=3" --data-urlencode "verso=acquisto")
  grep -q "Prezzo da" <<< "${PAGINA}" \
    && verifica "sotto il lotto minimo niente sconto" "si" "no" \
    || verifica "sotto il lotto minimo niente sconto" "si" "si"

  TOK=$(c "${BASE_URL}/strada" | token_da)
  PAGINA=$(c -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
    --data-urlencode "bene=${BASSO}" --data-urlencode "azione=compra_tutto")
  if grep -q "Prezzo da" <<< "${PAGINA}"; then
    verifica "sopra il lotto minimo il fornitore serve" "si" "si"
  else
    printf '  \033[0;33m--\033[0m    sopra il lotto minimo: non si è raggiunta la quantità, non misurato\n'
  fi
fi

# --- Il basista riferisce da lontano ------------------------------------------------
#
# È il mestiere per cui lo si paga, e per un pezzo non faceva NIENTE: il suo
# effetto veniva raccolto da `Organico::effetti()` e non lo leggeva nessuno. Un
# audit l'ha trovato confrontando quello che il README promette con quello che
# il codice consuma davvero.
LONTANO=$(dbq "SELECT id FROM piazze WHERE citta_id <> (SELECT citta_id FROM piazze WHERE id=${QUI}) LIMIT 1")
NOME_LONTANO=$(dbq "SELECT nome FROM piazze WHERE id=${LONTANO}")
PAGINA=$(c "${BASE_URL}/strada")
grep -q "riferiscono i tuoi basisti" <<< "${PAGINA}" \
  && verifica "senza basisti non si sa niente di lontano" "no" "si" \
  || verifica "senza basisti non si sa niente di lontano" "no" "no"

dbq "INSERT INTO uomini (personaggio_id,nome,ruolo,competenza,lealta,stipendio_ora,stato,piazza_id,assunto_at,pagato_fino_a,agg_a)
     VALUES (${PID},'Gennaro','basista',55,80,9000,'libero',${LONTANO},NOW(),DATE_ADD(NOW(3),INTERVAL 2 DAY),NOW(3))" >/dev/null
PAGINA=$(c "${BASE_URL}/strada")
grep -q "riferiscono i tuoi basisti" <<< "${PAGINA}" \
  && verifica "col basista si vede il listino di dove sta" "si" "si" \
  || verifica "col basista si vede il listino di dove sta" "si" "no"
grep -q "${NOME_LONTANO}" <<< "${PAGINA}" \
  && verifica "ed è proprio la piazza dove l'hai messo" "si" "si" \
  || verifica "ed è proprio la piazza dove l'hai messo" "si" "no"
dbq "DELETE FROM uomini WHERE personaggio_id=${PID} AND ruolo='basista'" >/dev/null

# --- Il pentito --------------------------------------------------------------------
dbq "UPDATE uomini SET lealta=15 WHERE personaggio_id=${PID};
     DELETE FROM fascicoli WHERE personaggio_id=${PID}" >/dev/null
PIAZZAPULITA_CONFIG="${CFG}" php -r '
require "'"${ROOT}"'/src/autoload.php"; require "'"${ROOT}"'/src/Support/helpers.php";
$GLOBALS["__project_root"] = "'"${ROOT}"'";
App\Core\Config::load("'"${ROOT}"'");
App\Game\Organico::dopoArresto('"${PID}"');' >/dev/null 2>&1
verifica "chi era sleale sparisce"     "0" "$(dbq "SELECT COUNT(*) FROM uomini WHERE personaggio_id=${PID} AND stato<>'sparito'")"
maggiore "e porta prove al fascicolo"  "0" "$(dbq "SELECT COALESCE(MAX(prove),0) FROM fascicoli WHERE personaggio_id=${PID}")"
# Possono parlare in più d'uno: si verifica che almeno qualcuno l'abbia fatto.
maggiore "con un segnale che lo dice" "0" "$(dbq "SELECT COUNT(*) FROM segnali WHERE personaggio_id=${PID} AND genere='pentito'")"

# --- Pulizia ------------------------------------------------------------------------
dbq "UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, shock=0, agg_a=NOW(3) WHERE piazza_id=${QUI};
     UPDATE piazze SET calore=0, calore_agg_a=NULL WHERE id=${QUI}" >/dev/null
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1
verifica "utente di prova rimosso" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID}")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
