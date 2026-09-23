#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end delle corse fra richieste.
#
#   bash tests/e2e_corse.sh
#
# Le altre prove fanno una cosa alla volta. Questa ne fa tante NELLO STESSO
# ISTANTE, come succede a chi gioca col telefono e col computer aperti insieme,
# o quando il battito passa mentre si clicca. Ogni blocco rifà una corsa che
# l'audit del 23/09/2026 ha dimostrato vera — denaro creato dal nulla, merce
# finita in due posti — e controlla l'invariante che la corsa rompeva:
#
#   - il lavaggio: coda + lavato resta quello messo a lavare;
#   - la cassa della batteria: si preleva al massimo quello che c'è;
#   - l'avvocato: si paga una volta, e il pulito non va sotto zero;
#   - lo scontro: il contante dei tre giocatori, sommato, non cambia;
#   - la rapina: una corsa si prende una volta sola, e non anche al deposito.
#
# Le sessioni sono vere: accessi distinti dello stesso giocatore, ognuno col
# suo cookie. Con un cookie solo le richieste le mette in fila già PHP, col
# lucchetto della sessione, e la corsa non si vede.
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
STAMP="$(date +%s)"
PASS="piazza123"
A_NAME="prova Lesto ${STAMP}";  A_MAIL="prova_lesto_${STAMP}@esempio.invalid"
B_NAME="prova Grasso ${STAMP}"; B_MAIL="prova_grasso_${STAMP}@esempio.invalid"
C_NAME="prova Svelto ${STAMP}"; C_MAIL="prova_svelto_${STAMP}@esempio.invalid"
N=10
CARTELLA="$(mktemp -d)"
FALLITI=0

trap 'rm -rf "${CARTELLA}"' EXIT

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
al_piu() {
  if php -r "exit(((float)'$3') <= ((float)'$2') ? 0 : 1);"; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: <= %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
}

if [[ "$(dbq "SELECT 1")" != "1" ]]; then
  echo "ERRORE: non riesco a interrogare il database indicato da ${CFG}." >&2
  exit 1
fi

echo "Prova end-to-end delle corse fra richieste — ${BASE_URL}"

# --- I giocatori, e tante sessioni per ciascuno --------------------------------
cc() { local jar="$1"; shift; curl -s -k -b "${jar}" -c "${jar}" -H "Host: ${HOST_HDR}" "$@"; }

accedi() { # jar, nome
  local tok
  tok=$(cc "$1" "${BASE_URL}/accesso" | token_da)
  cc "$1" -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${tok}" \
    --data-urlencode "login=$2" --data-urlencode "password=${PASS}"
}
nasci() { # sigla, nome, mail
  local jar="${CARTELLA}/$1-0" tok
  tok=$(cc "${jar}" "${BASE_URL}/iscrizione" | token_da)
  cc "${jar}" -o /dev/null -X POST "${BASE_URL}/iscrizione" --data-urlencode "_token=${tok}" \
    --data-urlencode "username=$2" --data-urlencode "email=$3" \
    --data-urlencode "password=${PASS}" --data-urlencode "password_confirm=${PASS}"
  consolle user:verify "$2" >/dev/null
  accedi "${jar}" "$2"
  tok=$(cc "${jar}" "${BASE_URL}/inizio" | token_da)
  cc "${jar}" -o /dev/null -X POST "${BASE_URL}/inizio" --data-urlencode "_token=${tok}" \
    --data-urlencode "citta=${CITTA}"
}
sessioni() { # sigla, nome — apre le sessioni 1..N-1 (la 0 c'è già)
  local i
  for ((i = 1; i < N; i++)); do
    # Il freno agli accessi è lì per chi indovina password, non per le prove.
    dbq "DELETE FROM rate_limits WHERE rkey LIKE 'login:%'" >/dev/null
    accedi "${CARTELLA}/$1-${i}" "$2"
  done
}
# Manda la stessa richiesta da tutte le sessioni di un giocatore, insieme.
# Ogni sessione prende prima il proprio gettone, così partono tutte pronte.
tutte_insieme() { # sigla, pagina-per-il-gettone, url, dati...
  local sigla="$1" pagina="$2" url="$3"; shift 3
  local i jar tok
  local -a gettoni=()
  for ((i = 0; i < N; i++)); do
    gettoni[i]=$(cc "${CARTELLA}/${sigla}-${i}" "${BASE_URL}${pagina}" | token_da)
  done
  dbq "DELETE FROM rate_limits WHERE rkey LIKE 'azioni:%'" >/dev/null
  for ((i = 0; i < N; i++)); do
    jar="${CARTELLA}/${sigla}-${i}"; tok="${gettoni[i]}"
    cc "${jar}" -o /dev/null -X POST "${BASE_URL}${url}" --data-urlencode "_token=${tok}" "$@" &
  done
  wait
}

CITTA=$(dbq "SELECT id FROM citta WHERE codice='NA'")
nasci a "${A_NAME}" "${A_MAIL}"
nasci b "${B_NAME}" "${B_MAIL}"
nasci c "${C_NAME}" "${C_MAIL}"
sessioni a "${A_NAME}"
sessioni c "${C_NAME}"
AID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${A_NAME}'")
BID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${B_NAME}'")
CID=$(dbq "SELECT p.id FROM personaggi p JOIN users u ON u.id=p.user_id WHERE u.username='${C_NAME}'")
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${AID}")
dbq "UPDATE personaggi SET piazza_id=${QUI}, arrivo_at=NULL WHERE id IN (${AID},${BID},${CID})" >/dev/null
verifica "tre giocatori in piazza" "3" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id IN (${AID},${BID},${CID}) AND piazza_id=${QUI}")"
verifica "${N} sessioni aperte per uno" "${N}" "$(ls "${CARTELLA}"/a-* | wc -l)"

# --- Il lavaggio -----------------------------------------------------------------
# Dieci minuti di coda maturata, e dieci pagine degli affari aperte insieme: il
# lavaggio va fatto una volta. Prima veniva accreditato una volta per pagina.
for giro in 1 2 3; do
  dbq "UPDATE personaggi SET pulito=0 WHERE id=${AID};
       UPDATE canali_posseduti SET coda=1000000, lavato=0, agg_a=DATE_SUB(NOW(3), INTERVAL 10 MINUTE)
        WHERE personaggio_id=${AID}" >/dev/null
  for ((i = 0; i < N; i++)); do cc "${CARTELLA}/a-${i}" -o /dev/null "${BASE_URL}/affari" & done
  battito & wait
  verifica "lavaggio, giro ${giro}: coda + lavato = messo a lavare" "1000000" \
    "$(dbq "SELECT SUM(coda + lavato) FROM canali_posseduti WHERE personaggio_id=${AID}")"
  al_piu "lavaggio, giro ${giro}: il pulito non supera il lavato" \
    "$(dbq "SELECT SUM(lavato) FROM canali_posseduti WHERE personaggio_id=${AID}")" \
    "$(dbq "SELECT pulito FROM personaggi WHERE id=${AID}")"
done

# --- La cassa della batteria ---------------------------------------------------------
dbq "UPDATE personaggi SET pulito=20000000 WHERE id=${AID}" >/dev/null
TOK=$(cc "${CARTELLA}/a-0" "${BASE_URL}/batteria" | token_da)
cc "${CARTELLA}/a-0" -o /dev/null -X POST "${BASE_URL}/batteria/fonda" --data-urlencode "_token=${TOK}" \
  --data-urlencode "nome=Corsa ${STAMP}" --data-urlencode "sigla=C${STAMP: -3}" --data-urlencode "motto="
BAT=$(dbq "SELECT id FROM batterie WHERE capo_id=${AID}")
verifica "la batteria c'è" "si" "$(if [[ -n "${BAT}" ]]; then echo si; else echo no; fi)"
dbq "UPDATE batterie SET cassa=5000000 WHERE id=${BAT}; UPDATE personaggi SET contante=0 WHERE id=${AID}" >/dev/null
tutte_insieme a /batteria /batteria/cassa --data-urlencode "verso=preleva" --data-urlencode "importo=5000000"
verifica "prelievi insieme: il capo incassa la cassa una volta" "5000000" "$(dbq "SELECT contante FROM personaggi WHERE id=${AID}")"
verifica "e la cassa resta a zero, non sotto"                   "0"       "$(dbq "SELECT cassa FROM batterie WHERE id=${BAT}")"

# --- L'avvocato ----------------------------------------------------------------------
PARCELLA=$(dbq "SELECT cvalue FROM game_config WHERE ckey='legge.avvocato_prezzo'"); PARCELLA=${PARCELLA:-4000000}
dbq "UPDATE personaggi SET pulito=${PARCELLA} WHERE id=${AID};
     INSERT INTO fascicoli (personaggio_id, inquirente, corpo, prove, agg_a)
     VALUES (${AID}, 'Commissario Prova', 'questura', 80, NOW(3))" >/dev/null
tutte_insieme a /fascicolo /fascicolo/avvocato
verifica "avvocati insieme: il pulito va a zero, non sotto" "0" "$(dbq "SELECT pulito FROM personaggi WHERE id=${AID}")"
verifica "e la parcella si paga una volta" "1" \
  "$(dbq "SELECT COUNT(*) FROM movimenti WHERE personaggio_id=${AID} AND genere='avvocato'")"
verifica "un fascicolo aperto solo" "1" "$(dbq "SELECT COUNT(*) FROM fascicoli WHERE personaggio_id=${AID} AND stato='aperto'")"
dbq "UPDATE fascicoli SET stato='archiviato', chiuso_at=NOW() WHERE personaggio_id=${AID}" >/dev/null

# --- Lo scontro ------------------------------------------------------------------------
# Due aggressori, dieci sessioni ciascuno, la stessa vittima: il bottino è uno.
# Il contante dei tre, sommato, non può cambiare — uno scontro sposta soldi, non
# ne crea.
ARMI=$(dbq "SELECT id FROM beni WHERE codice='armi'")
dbq "UPDATE personaggi SET batteria_id=NULL WHERE id=${AID};
     UPDATE personaggi SET contante=8000000, salute=100, ospedale_fino_a=NULL, carcere_fino_a=NULL WHERE id=${BID};
     UPDATE personaggi SET contante=0, salute=100, ospedale_fino_a=NULL, carcere_fino_a=NULL WHERE id IN (${AID},${CID});
     DELETE FROM carico WHERE personaggio_id IN (${AID},${BID},${CID});
     INSERT INTO carico (personaggio_id, bene_id, quantita, costo_totale) VALUES
       (${AID}, ${ARMI}, 8, 0), (${CID}, ${ARMI}, 8, 0)" >/dev/null
PRIMA=$(dbq "SELECT SUM(contante) FROM personaggi WHERE id IN (${AID},${BID},${CID})")
tutte_insieme a /altri /altri/attacca --data-urlencode "chi=${BID}" &
tutte_insieme c /altri /altri/attacca --data-urlencode "chi=${BID}" &
wait
verifica "scontri insieme: il contante dei tre non cambia" "${PRIMA}" \
  "$(dbq "SELECT SUM(contante) FROM personaggi WHERE id IN (${AID},${BID},${CID})")"
al_piu "e la vittima viene spogliata una volta" "1" \
  "$(dbq "SELECT COUNT(*) FROM scontri WHERE difensore_id=${BID} AND bottino_sporco > 0")"

# --- La rapina a una corsa ----------------------------------------------------------------
# Una corsa di B passa di qui. A ci prova da dieci sessioni, e intanto il
# battito la consegna: la merce deve finire in un posto solo.
BENE=$(dbq "SELECT id FROM beni WHERE codice <> 'armi' ORDER BY ingombro LIMIT 1")
dbq "UPDATE personaggi SET salute=100, ospedale_fino_a=NULL, carcere_fino_a=NULL, contante=10000000
      WHERE id IN (${AID},${BID},${CID});
     DELETE FROM carico WHERE personaggio_id=${AID} AND bene_id=${BENE};
     INSERT INTO depositi (personaggio_id, piazza_id, capienza, affitto_ora, pagato_fino_a)
     VALUES (${BID}, ${QUI}, 5000, 1000, DATE_ADD(NOW(), INTERVAL 1 DAY))" >/dev/null
DEP=$(dbq "SELECT id FROM depositi WHERE personaggio_id=${BID} AND piazza_id=${QUI}")
for giro in 1 2 3; do
  dbq "INSERT INTO uomini (personaggio_id, nome, ruolo, competenza, lealta, stipendio_ora, stato,
                           assunto_at, pagato_fino_a, agg_a)
       VALUES (${BID}, 'Corriere ${giro}', 'corriere', 90, 100, 1000, 'in_viaggio',
               NOW(), DATE_ADD(NOW(3), INTERVAL 2 DAY), NOW(3))" >/dev/null
  UOMO=$(dbq "SELECT id FROM uomini WHERE personaggio_id=${BID} ORDER BY id DESC LIMIT 1")
  dbq "INSERT INTO corse (uomo_id, personaggio_id, da_piazza_id, a_piazza_id, bene_id, quantita,
                          costo_totale, partito_at, arrivo_at)
       VALUES (${UOMO}, ${BID}, ${QUI}, ${QUI}, ${BENE}, 20, 200000, NOW(3), DATE_SUB(NOW(3), INTERVAL 1 SECOND))" >/dev/null
  CORSA=$(dbq "SELECT id FROM corse WHERE uomo_id=${UOMO}")
  ADDOSSO=$(dbq "SELECT COALESCE(SUM(quantita),0) FROM carico WHERE personaggio_id=${AID} AND bene_id=${BENE}")
  DENTRO=$(dbq "SELECT COALESCE(SUM(quantita),0) FROM deposito_merce WHERE deposito_id=${DEP} AND bene_id=${BENE}")
  tutte_insieme a /altri /altri/rapina --data-urlencode "corsa=${CORSA}" &
  battito &
  wait
  PRESE=$(( $(dbq "SELECT COALESCE(SUM(quantita),0) FROM carico WHERE personaggio_id=${AID} AND bene_id=${BENE}") - ADDOSSO ))
  ARRIVATE=$(( $(dbq "SELECT COALESCE(SUM(quantita),0) FROM deposito_merce WHERE deposito_id=${DEP} AND bene_id=${BENE}") - DENTRO ))
  al_piu "rapina, giro ${giro}: la merce finisce in un posto solo" "20" "$((PRESE + ARRIVATE))"
  verifica "rapina, giro ${giro}: la corsa è chiusa" "0" "$(dbq "SELECT COUNT(*) FROM corse WHERE id=${CORSA} AND esito='in_corso'")"
done

# --- Pulizia ----------------------------------------------------------------------------------
dbq "UPDATE piazze SET calore=0, calore_agg_a=NULL WHERE id=${QUI}" >/dev/null
for n in "${A_NAME}" "${B_NAME}" "${C_NAME}"; do
  PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${n}" >/dev/null 2>&1
done
verifica "utenti di prova rimossi" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id IN (${AID},${BID},${CID})")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
