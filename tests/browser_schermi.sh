#!/usr/bin/env bash
#
# Piazza Pulita — le pagine su schermo stretto e col dito.
#
#   bash tests/browser_schermi.sh
#
# Verifica tre cose che nessuna prova con curl può vedere, perché dipendono
# dall'impaginazione e dalle media query:
#
#   1. nessuna pagina esce di lato (lo scorrimento orizzontale della PAGINA è
#      il difetto che rende un sito inusabile in mano);
#   2. col dito i bersagli arrivano a 44px;
#   3. su monitor NON cambia niente: il listino resta una tabella vera.
#
# Il puntatore grosso non si può emulare da riga di comando, quindi per il
# punto 2 si rende il foglio con `@media (pointer: coarse)` reso sempre vero:
# si misurano le stesse dichiarazioni, non la query. La query è una riga sola e
# si legge a occhio; le dichiarazioni no.
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
HOST_HDR="${HOST_HDR:-localhost}"
CFG="${PIAZZAPULITA_CONFIG:-/data/piazzapulita-config/config.php}"
PORTA="${PORTA:-8097}"
FALLITI=0
PAGINE="strada affari altri personaggio classifica statistiche"

CHROME="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
if [[ -z "${CHROME}" ]]; then
  echo "Chromium non installato: prova saltata (non è un fallimento)."
  exit 0
fi

BANCO="$(mktemp -d)"; JAR="$(mktemp)"
NOME="prova Schermi $(date +%s)"
trap 'kill %1 2>/dev/null; rm -rf "${BANCO}" "${JAR}"' EXIT

c() { curl -s -k -b "${JAR}" -c "${JAR}" -H "Host: ${HOST_HDR}" "$@"; }
token_da() { grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
consolle() { PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/console.php" "$@"; }
verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
}

echo "Prova delle pagine su schermo stretto — Chromium headless"

# --- Un giocatore con qualcosa dentro: una pagina vuota non si rompe mai ------
TOK=$(c "${BASE_URL}/iscrizione" | token_da)
c -o /dev/null -X POST "${BASE_URL}/iscrizione" --data-urlencode "_token=${TOK}" \
  --data-urlencode "username=${NOME}" --data-urlencode "email=prova_sch$(date +%s)@esempio.invalid" \
  --data-urlencode "password=piazza123" --data-urlencode "password_confirm=piazza123"
consolle user:verify "${NOME}" >/dev/null 2>&1
TOK=$(c "${BASE_URL}/accesso" | token_da)
c -o /dev/null -X POST "${BASE_URL}/accesso" --data-urlencode "_token=${TOK}" \
  --data-urlencode "login=${NOME}" --data-urlencode "password=piazza123"
CITTA=$(php -r '$c=require "'"${CFG}"'"; $p=new PDO("mysql:host={$c["db"]["host"]};dbname={$c["db"]["name"]}",$c["db"]["user"],$c["db"]["pass"]);
  echo $p->query("SELECT id FROM citta WHERE codice=\"NA\"")->fetchColumn();')
TOK=$(c "${BASE_URL}/inizio" | token_da)
c -o /dev/null -X POST "${BASE_URL}/inizio" --data-urlencode "_token=${TOK}" --data-urlencode "citta=${CITTA}"

mkdir -p "${BANCO}/piazzapulita"
cp -r "${ROOT}/assets" "${BANCO}/piazzapulita/"
# Il foglio «come lo vede un dito»: le stesse dichiarazioni, senza la query.
sed -e 's/@media (pointer: coarse)/@media all/' \
    -e 's/@media (max-width: 40rem), (pointer: coarse)/@media all/' \
    "${ROOT}/assets/css/piazzapulita.css" > "${BANCO}/piazzapulita/assets/css/dito.css"

cat > "${BANCO}/piazzapulita/misura.js" <<'JS'
window.addEventListener('load', () => setTimeout(() => {
  const V = document.documentElement.clientWidth;
  let piccoli = 0;
  document.querySelectorAll('a, button, input, select').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.width && r.height && Math.min(r.width, r.height) < 40) piccoli++;
  });
  // Si guarda una cella ETICHETTATA: la prima cella della scheda è il nome
  // della merce, che per progetto sta a tutta larghezza e resta `block`.
  const td = document.querySelector('table.tabella--schede td[data-etichetta]');
  document.title = JSON.stringify({
    trabocca: document.documentElement.scrollWidth > V + 1,
    piccoli: piccoli,
    celle: td ? getComputedStyle(td).display : 'nessuna'
  });
}, 350));
JS

for P in ${PAGINE}; do
  c "${BASE_URL}/${P}" | sed 's#</body>#<script src="/piazzapulita/misura.js"></script></body>#' \
    > "${BANCO}/piazzapulita/${P}.html"
  cp "${BANCO}/piazzapulita/${P}.html" "${BANCO}/piazzapulita/${P}_dito.html"
  sed -i 's#assets/css/piazzapulita\.css#assets/css/dito.css#' "${BANCO}/piazzapulita/${P}_dito.html"
done

php -S "127.0.0.1:${PORTA}" -t "${BANCO}" >/dev/null 2>&1 &
sleep 1

misura() { # file, larghezza, campo
  "${CHROME}" --headless --disable-gpu --no-sandbox --window-size="$2",900 --virtual-time-budget=4500 \
    --dump-dom "http://127.0.0.1:${PORTA}/piazzapulita/$1.html" 2>/dev/null \
    | sed -n 's/.*<title>\(.*\)<\/title>.*/\1/p' | head -1 | sed -e 's/&quot;/"/g' \
    | php -r '$e=json_decode(stream_get_contents(STDIN),true); $v=$e[$argv[1]] ?? "?";
              echo is_bool($v) ? ($v ? "si" : "no") : $v;' "$3"
}

# 1. Nessuna pagina esce di lato, né stretta né larga.
for P in ${PAGINE}; do
  verifica "«${P}» non esce di lato su schermo stretto" "no" "$(misura "${P}" 500 trabocca)"
done
verifica "né su monitor" "no" "$(misura strada 1280 trabocca)"

# 2. Col dito i bersagli crescono. Su /strada, senza le regole del tocco, ce ne
#    sono una settantina sotto i 40px: sono i bottoni d'ordine, uno per merce.
SENZA=$(misura strada 500 piccoli)
CON=$(misura strada_dito 500 piccoli)
verifica "col dito restano pochi bersagli piccoli (erano ${SENZA})" "si" \
  "$([[ "${CON}" -le 10 ]] && echo si || echo no)"
verifica "e col dito sono meno di prima" "si" "$([[ "${CON}" -lt "${SENZA}" ]] && echo si || echo no)"

# 3. Il monitor non si tocca: il listino resta una TABELLA, non schede.
verifica "su monitor il listino resta una tabella" "table-cell" "$(misura strada 1280 celle)"
verifica "e su schermo stretto diventa a schede" "flex" "$(misura strada 500 celle)"

PIAZZAPULITA_CONFIG="${CFG}" php "${ROOT}/bin/_cleanup_test_user.php" "${NOME}" >/dev/null 2>&1

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
