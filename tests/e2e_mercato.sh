#!/usr/bin/env bash
#
# Piazza Pulita — prova end-to-end del mercato e della fotografia del profilo (F2).
#
#   bash tests/e2e_mercato.sh
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
# Nome con cui il vhost risponde. Si sovrascrive da ambiente:
#   BASE_URL=https://esempio.tld/piazzapulita HOST_HDR=esempio.tld bash tests/...
HOST_HDR="${HOST_HDR:-localhost}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
JAR="$(mktemp)"; TMPD="$(mktemp -d)"
USER_NAME="prova Bottegaio $(date +%s)"
USER_MAIL="prova_$(date +%s)@esempio.invalid"
USER_PASS="piazza123"
FALLITI=0

trap 'rm -rf "${JAR}" "${TMPD}"' EXIT

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

echo "Prova end-to-end del mercato — ${BASE_URL}"

# --- Il mercato dev'esserci --------------------------------------------------
verifica "dieci beni seminati" "10" "$(dbq "SELECT COUNT(*) FROM beni")"
verifica "il mercato è popolato" "si" "$([[ "$(dbq "SELECT COUNT(*) FROM mercati")" -gt 200 ]] && echo si || echo no)"
verifica "ogni bene ha un prezzo di riferimento" "0" "$(dbq "SELECT COUNT(*) FROM beni b LEFT JOIN prezzi p ON p.bene_id=b.id WHERE p.bene_id IS NULL")"
# L'invariante: le quote sommano a 1, quindi la somma teorica è il tetto.
SCARTO=$(dbq "SELECT ROUND(ABS(SUM(m.domanda_eq*(b.prezzo_min+b.prezzo_max)/2*b.spread_frazione)
                 - (SELECT cvalue FROM game_config WHERE ckey='mondo.reddito_orario'))
                 / (SELECT cvalue FROM game_config WHERE ckey='mondo.reddito_orario') * 1000)
              FROM mercati m JOIN beni b ON b.id=m.bene_id")
verifica "somma teorica = tetto (entro il 2%)" "si" "$([[ "${SCARTO:-999}" -le 20 ]] && echo si || echo no)"

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
UID_=$(dbq "SELECT id FROM users WHERE username='${USER_NAME}'")
QUI=$(dbq "SELECT piazza_id FROM personaggi WHERE id=${PID}")
BENE=$(dbq "SELECT bene_id FROM mercati WHERE piazza_id=${QUI} ORDER BY domanda_eq DESC LIMIT 1")
verifica "qui gira almeno una merce" "si" "$([[ -n "${BENE}" ]] && echo si || echo no)"

# Il mercato di questa piazza si riporta all'equilibrio prima di toccarlo.
#
# Non è per comodità: le prove comprano e vendono davvero, e due giri di fila
# lasciano l'assorbimento a zero. Il terzo non riusciva a vendere niente e la
# prova diventava rossa senza che niente fosse rotto — anzi, proprio perché il
# mercato funzionava. Una prova che dipende da quanto è stanca la piazza non
# misura il codice, misura l'ordine in cui la si è lanciata.
dbq "UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, shock=0, agg_a=NOW(3)
      WHERE piazza_id=${QUI}" >/dev/null

# --- Comprare ----------------------------------------------------------------
CONTANTE0=$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "azione=compra_tutto")
grep -q "Comprate" <<< "${PAGINA}" \
  && verifica "acquisto riuscito" "si" "si" || verifica "acquisto riuscito" "si" "no"
Q=$(dbq "SELECT quantita FROM carico WHERE personaggio_id=${PID} AND bene_id=${BENE}")
verifica "la merce è nel carico" "si" "$([[ "${Q:-0}" -gt 0 ]] && echo si || echo no)"
CONTANTE1=$(dbq "SELECT contante FROM personaggi WHERE id=${PID}")
verifica "il denaro è uscito" "si" "$([[ "${CONTANTE1}" -lt "${CONTANTE0}" ]] && echo si || echo no)"
verifica "l'acquisto è a registro" "1" "$(dbq "SELECT COUNT(*) FROM transazioni WHERE personaggio_id=${PID} AND verso='acquisto'")"

# Non si compra oltre lo spazio: il carico non può superare la capienza.
ING=$(dbq "SELECT SUM(c.quantita*b.ingombro) FROM carico c JOIN beni b ON b.id=c.bene_id WHERE c.personaggio_id=${PID}")
CAP=$(dbq "SELECT capienza FROM personaggi WHERE id=${PID}")
verifica "il carico sta nella capienza" "si" "$([[ "${ING:-0}" -le "${CAP}" ]] && echo si || echo no)"

# --- Rivendere sul posto ci rimette -----------------------------------------
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "azione=vendi_tutto")
grep -q "Vendute" <<< "${PAGINA}" \
  && verifica "vendita riuscita" "si" "si" || verifica "vendita riuscita" "si" "no"
# E se ne è venduta meno di quanta chiesta, il messaggio lo dice.
if grep -q "resta addosso" <<< "${PAGINA}"; then
  verifica "vendita parziale spiegata" "si" "si"
fi
MARGINE=$(dbq "SELECT margine FROM transazioni WHERE personaggio_id=${PID} AND verso='vendita' ORDER BY id DESC LIMIT 1")
verifica "comprare e rivendere sul posto ci rimette" "si" "$([[ "${MARGINE:-0}" -lt 0 ]] && echo si || echo no)"

# --- Rifiuti -----------------------------------------------------------------
# «Svuota» non sempre svuota: se la piazza si satura a metà vendita il resto
# resta addosso, ed è giusto così. La prova che segue vuole il carico vuoto per
# davvero, quindi lo svuota lei — altrimenti passa o fallisce a seconda di
# quanto assorbiva quella piazza in quel momento.
dbq "DELETE FROM carico WHERE personaggio_id=${PID}" >/dev/null
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "verso=vendita" --data-urlencode "quantita=99")
grep -q "Non ne hai" <<< "${PAGINA}" \
  && verifica "non si vende quel che non si ha" "si" "si" || verifica "non si vende quel che non si ha" "si" "no"

TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=250" --data-urlencode "verso=acquisto" --data-urlencode "quantita=1")
grep -qE "non esiste|non gira" <<< "${PAGINA}" \
  && verifica "merce inesistente rifiutata" "si" "si" || verifica "merce inesistente rifiutata" "si" "no"

# In viaggio non si tratta.
ALTRA=$(dbq "SELECT id FROM piazze WHERE citta_id=${NA} AND id<>${QUI} LIMIT 1")
TOK=$(c "${BASE_URL}/strada" | token_da)
c -o /dev/null -X POST "${BASE_URL}/parti" --data-urlencode "_token=${TOK}" \
  --data-urlencode "piazza=${ALTRA}" --data-urlencode "mezzo=mezzi" --data-urlencode "torna=/strada"
TOK=$(c "${BASE_URL}/strada" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/ordina" --data-urlencode "_token=${TOK}" \
  --data-urlencode "bene=${BENE}" --data-urlencode "verso=acquisto" --data-urlencode "quantita=1")
grep -q "In viaggio non si tratta" <<< "${PAGINA}" \
  && verifica "in viaggio non si tratta" "si" "si" || verifica "in viaggio non si tratta" "si" "no"
dbq "UPDATE personaggi SET arrivo_at=NULL WHERE id=${PID}" >/dev/null

# --- La fotografia del profilo ----------------------------------------------
php -r '$im=imagecreatetruecolor(900,600); imagefilledrectangle($im,0,0,900,600,imagecolorallocate($im,120,90,60)); imagejpeg($im,"'"${TMPD}"'/foto.jpg",90);'
TOK=$(c "${BASE_URL}/profilo" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/profilo/foto" -F "_token=${TOK}" -F "foto=@${TMPD}/foto.jpg" \
  -F "sx=280" -F "sy=80" -F "lato=400")
grep -q "Fotografia sul profilo" <<< "${PAGINA}" \
  && verifica "fotografia accettata" "si" "si" || verifica "fotografia accettata" "si" "no"
FILE=$(dbq "SELECT avatar_file FROM users WHERE id=${UID_}")
verifica "il nome del file è l'impronta" "si" "$([[ "${FILE}" =~ ^[0-9a-f]{64}\.webp$ ]] && echo si || echo no)"
CODE=$(c -o "${TMPD}/scaricata" -w '%{http_code}' "${BASE_URL}/assets/img/avatar/${FILE}")
verifica "si serve dal web" "200" "${CODE}"
verifica "ed è un WebP 320x320" "320x320 image/webp" \
  "$(php -r '$i=getimagesize("'"${TMPD}"'/scaricata"); echo $i[0],"x",$i[1]," ",$i["mime"];' 2>/dev/null)"

# Gli SVG no: possono contenere codice.
printf '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' > "${TMPD}/c.svg"
TOK=$(c "${BASE_URL}/profilo" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/profilo/foto" -F "_token=${TOK}" -F "foto=@${TMPD}/c.svg" -F "sx=0" -F "sy=0" -F "lato=0")
grep -q "SVG non si accettano" <<< "${PAGINA}" \
  && verifica "SVG rifiutato" "si" "si" || verifica "SVG rifiutato" "si" "no"

# Nemmeno del PHP con l'estensione giusta.
printf '<?php echo "ciao";' > "${TMPD}/c.jpg"
TOK=$(c "${BASE_URL}/profilo" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/profilo/foto" -F "_token=${TOK}" -F "foto=@${TMPD}/c.jpg" -F "sx=0" -F "sy=0" -F "lato=0")
grep -q "Serve un JPEG" <<< "${PAGINA}" \
  && verifica "PHP travestito da jpg rifiutato" "si" "si" || verifica "PHP travestito da jpg rifiutato" "si" "no"

# Un ritaglio assurdo non deve rompere niente: viene riportato dentro i bordi.
TOK=$(c "${BASE_URL}/profilo" | token_da)
PAGINA=$(c -L -X POST "${BASE_URL}/profilo/foto" -F "_token=${TOK}" -F "foto=@${TMPD}/foto.jpg" \
  -F "sx=99999" -F "sy=-500" -F "lato=99999")
grep -q "Fotografia sul profilo" <<< "${PAGINA}" \
  && verifica "ritaglio fuori bordo riportato dentro" "si" "si" || verifica "ritaglio fuori bordo riportato dentro" "si" "no"

# La vetrina pubblica la mostra.
PAGINA=$(c "${BASE_URL}/profilo/${UID_}")
grep -q 'class="avatar' <<< "${PAGINA}" \
  && verifica "la vetrina mostra il volto" "si" "si" || verifica "la vetrina mostra il volto" "si" "no"

# E si toglie, portandosi via il file.
FILE=$(dbq "SELECT avatar_file FROM users WHERE id=${UID_}")
TOK=$(c "${BASE_URL}/profilo" | token_da)
c -o /dev/null -L -X POST "${BASE_URL}/profilo/foto/togli" --data-urlencode "_token=${TOK}"
verifica "fotografia tolta dal profilo" "" "$(dbq "SELECT COALESCE(avatar_file,'') FROM users WHERE id=${UID_}")"
verifica "e il file non c'è più" "no" "$([[ -f "${ROOT}/assets/img/avatar/${FILE}" ]] && echo si || echo no)"

# --- Pulizia -----------------------------------------------------------------
# Anche il mercato si rimette com'era: queste prove girano sul mondo vero, e
# lasciare una piazza svenata sarebbe un danno ai giocatori.
dbq "UPDATE mercati SET offerta=offerta_eq, domanda=domanda_eq, shock=0, agg_a=NOW(3)
      WHERE piazza_id=${QUI}" >/dev/null
PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${USER_NAME}" >/dev/null 2>&1
verifica "utente di prova rimosso" "0" "$(dbq "SELECT COUNT(*) FROM personaggi WHERE id=${PID}")"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
