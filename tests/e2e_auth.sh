#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end del giro di autenticazione, attraverso Apache.
#
#   bash tests/e2e_auth.sh
#
# Cosa fa: iscrive un utente di prova, legge il gettone di verifica dal diario
# (il trasporto e' forzato a 'log' per la durata della prova), conferma
# l'indirizzo, accede, apre la strada, modifica il profilo, esce. Alla fine
# cancella l'utente di prova e ripristina il trasporto. Non invia nessuna
# e-mail reale.
#
set -uo pipefail

# NOTA sul modo in cui si controllano le pagine.
#
# Si usa `grep -q PAT <<< "${PAGINA}"`, non `echo "${PAGINA}" | grep -q PAT`.
# Con pipefail attivo la seconda forma e' una trappola: grep -q esce appena
# trova, echo si prende un SIGPIPE e la pipeline restituisce 141 anche quando
# la stringa c'era. E' un errore che non si vede finche' le pagine sono piccole.

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
# Nome con cui il vhost risponde. Si sovrascrive da ambiente:
#   BASE_URL=https://esempio.tld/piazzapulita HOST_HDR=esempio.tld bash tests/...
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="/data/piazzapulita-config/config.php"
JAR="$(mktemp)"
# Nome con spazio e password esattamente al minimo consentito: la prova passa
# per gli stessi casi limite che useranno i giocatori.
USER_NAME="prova Ferro $(date +%s)"
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

verifica() { # nome, atteso, ottenuto
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

echo "Prova end-to-end autenticazione — ${BASE_URL}"
echo "  utente di prova: ${USER_NAME}"

# Trasporto e-mail forzato a 'log' per la durata della prova: una prova non
# deve poter svegliare la casella di nessuno.
php -r '
$f = "'"${CFG}"'";
$c = require $f;
file_put_contents("/tmp/piazzapulita-transport.bak", $c["mail"]["transport"]);
$c["mail"]["transport"] = "log";
file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n");
' || { echo "impossibile forzare il trasporto a log"; exit 1; }

ripristina() {
  php -r '
  $f = "'"${CFG}"'";
  $c = require $f;
  $c["mail"]["transport"] = trim((string) @file_get_contents("/tmp/piazzapulita-transport.bak")) ?: "log";
  file_put_contents($f, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n");
  @unlink("/tmp/piazzapulita-transport.bak");
  '
}
trap 'ripristina; rm -f "${JAR}"' EXIT

# Il processo web non vede subito il file riscritto: opcache lo ricontrolla
# ogni due secondi. Senza questa attesa l'iscrizione parte col trasporto
# VECCHIO, il messaggio non finisce nel diario, e la prova cerca un gettone
# che non e' mai stato scritto.
sleep 3


# 0. Sonda di servizio
CODE=$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/health")
verifica "sonda /health risponde" "200" "${CODE}"

# 1. Pagina di iscrizione + gettone CSRF
TOK=$(c "${BASE_URL}/iscrizione" | token_da)
verifica "gettone CSRF presente nel modulo" "si" "$([[ -n "${TOK}" ]] && echo si || echo no)"

# 2. CSRF mancante => rifiuto
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/iscrizione" \
  --data-urlencode "username=${USER_NAME}" --data-urlencode "email=${USER_MAIL}" \
  --data-urlencode "password=${USER_PASS}" --data-urlencode "password_confirm=${USER_PASS}")
verifica "POST senza CSRF respinto" "400" "${CODE}"

# 3. Iscrizione vera
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/iscrizione" \
  --data-urlencode "_token=${TOK}" --data-urlencode "username=${USER_NAME}" \
  --data-urlencode "email=${USER_MAIL}" --data-urlencode "password=${USER_PASS}" \
  --data-urlencode "password_confirm=${USER_PASS}")
verifica "iscrizione accettata (redirect)" "302" "${CODE}"

STATO=$(dbq "SELECT status FROM users WHERE username='${USER_NAME}'")
verifica "account creato in stato pending" "pending" "${STATO}"
verifica "nome utente con spazio accettato" "si" "$([[ "${USER_NAME}" == *" "* && -n "${STATO}" ]] && echo si || echo no)"

# 3b. Password sotto il minimo respinta
TOK=$(c "${BASE_URL}/iscrizione" | token_da)
c -o /dev/null -X POST "${BASE_URL}/iscrizione" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=prova corta $(date +%s)" --data-urlencode "email=corta_$(date +%s)@esempio.invalid" \
  --data-urlencode "password=otto8car" --data-urlencode "password_confirm=otto8car"
PAGINA=$(c "${BASE_URL}/iscrizione")
grep -q "almeno 9 caratteri" <<< "${PAGINA}" \
  && verifica "password di 8 caratteri respinta" "si" "si" \
  || verifica "password di 8 caratteri respinta" "si" "no"

# 4. Accesso negato prima della conferma
TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}"
PAGINA=$(c -L "${BASE_URL}/strada")
grep -q "Bentornato" <<< "${PAGINA}" \
  && verifica "strada irraggiungibile senza conferma" "si" "si" \
  || verifica "strada irraggiungibile senza conferma" "si" "no"

# 5. Gettone di verifica dalla coda di posta.
# La posta non parte al momento dell'iscrizione: entra in coda e la spedisce il
# battito. Qui la coda la si smista a mano, altrimenti la prova aspetterebbe il
# cron del minuto e passerebbe o no secondo il momento in cui la si lancia.
php "${ROOT}/bin/console.php" mail:smista >/dev/null 2>&1

# Il collegamento si legge dalla RIGA IN CODA, non dal diario: il diario lo
# scrive chi ha spedito davvero, e se ha fatto in tempo il cron del sito la riga
# finisce nel diario dell'installazione, non in quello di questa cartella. Si
# fallivano cinque verifiche per una corsa fra due processi, non per un baco.
LINK=$(dbq "SELECT corpo FROM mail_queue WHERE destinatario='${USER_MAIL}' AND genere='verifica'
            ORDER BY id DESC LIMIT 1" | grep -o 'verifica?token=[0-9a-f]\{64\}' | head -1)
verifica "collegamento di verifica generato" "si" "$([[ -n "${LINK}" ]] && echo si || echo no)"

PAGINA=$(c "${BASE_URL}/${LINK}")
grep -q "Sei dentro" <<< "${PAGINA}" \
  && verifica "verifica dell'indirizzo riuscita" "si" "si" \
  || verifica "verifica dell'indirizzo riuscita" "si" "no"

# 6. Doppio clic sul collegamento: non deve dare errore
PAGINA=$(c "${BASE_URL}/${LINK}")
grep -q "Sei dentro" <<< "${PAGINA}" \
  && verifica "doppio clic sul collegamento non da' errore" "si" "si" \
  || verifica "doppio clic sul collegamento non da' errore" "si" "no"

# 7. Accesso e area di gioco
TOK=$(c "${BASE_URL}/accesso" | token_da)
CODE=$(c -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${USER_NAME}" --data-urlencode "password=${USER_PASS}")
verifica "accesso riuscito (redirect)" "302" "${CODE}"

# Entrati, la prima cosa che si incontra è la scelta della città: senza un
# personaggio non c'è strada. È un cancello aggiunto in F1, ed è il genere di
# cosa che fa diventare rossa una prova che parla d'altro.
PAGINA=$(c -L "${BASE_URL}/strada")
grep -q "Da dove cominci" <<< "${PAGINA}" \
  && verifica "area di gioco raggiungibile (scelta della città)" "si" "si" \
  || verifica "area di gioco raggiungibile (scelta della città)" "si" "no"

# 8. Area di amministrazione negata a un giocatore comune
CODE=$(c -o /dev/null -w '%{http_code}' "${BASE_URL}/admin")
verifica "amministrazione negata a un giocatore" "403" "${CODE}"

# 9. Profilo: scrittura della nota
PAGINA=$(c "${BASE_URL}/profilo")
TOK=$(token_da <<< "${PAGINA}")
c -o /dev/null -X POST "${BASE_URL}/profilo/nota" --data-urlencode "_token=${TOK}" \
  --data-urlencode "nota=Due righe di prova."
NOTA=$(dbq "SELECT nota FROM users WHERE username='${USER_NAME}'")
verifica "nota del profilo salvata" "Due righe di prova." "${NOTA}"

# 10. Uscita
PAGINA=$(c "${BASE_URL}/profilo")
TOK=$(token_da <<< "${PAGINA}")
c -o /dev/null -X POST "${BASE_URL}/esci" --data-urlencode "_token=${TOK}"
PAGINA=$(c -L "${BASE_URL}/strada")
grep -q "Bentornato" <<< "${PAGINA}" \
  && verifica "uscita effettuata" "si" "si" \
  || verifica "uscita effettuata" "si" "no"

# --- Pulizia -----------------------------------------------------------------
php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1

echo
if [[ "${FALLITI}" -eq 0 ]]; then
  printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else
  printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"
  exit 1
fi
