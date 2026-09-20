#!/usr/bin/env bash
#
# Piazza Pulita — prova del riquadro di centratura, in un browser vero.
#
#   bash tests/browser_avatar.sh
#
# PERCHE' ESISTE. Tutte le altre prove parlano al server con curl, e curl non è
# un browser: non applica la CSP, non impagina niente, non esegue JavaScript.
# Due bachi di fila sono passati esattamente da lì:
#
#   1. la CSP vietava `blob:`, quindi l'anteprima non si caricava mai;
#   2. lo script misurava la cornice MENTRE era ancora `hidden`, quindi la
#      scala dell'immagine veniva zero: il riquadro compariva vuoto, e nel campo
#      del ritaglio finiva `Infinity`.
#
# Nessuna prova esistente poteva vederli. Questa carica lo script VERO e il
# markup VERO in Chromium headless, simula la scelta di una fotografia e guarda
# cosa succede davvero ai pixel.
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${BASE_URL:-https://127.0.0.1/piazzapulita}"
HOST_HDR="${HOST_HDR:-localhost}"
PORTA="${PORTA:-8099}"
FALLITI=0

CHROME="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
if [[ -z "${CHROME}" ]]; then
  echo "Chromium non installato: prova saltata (non è un fallimento)."
  echo "  apt install chromium   # per eseguirla"
  exit 0
fi

BANCO="$(mktemp -d)"
trap 'kill %1 2>/dev/null; rm -rf "${BANCO}"' EXIT

verifica() {
  if [[ "$2" == "$3" ]]; then printf '  \033[0;32mok\033[0m    %s\n' "$1"
  else printf '  \033[0;31mKO\033[0m    %s (atteso: %s, ottenuto: %s)\n' "$1" "$2" "$3"; FALLITI=$((FALLITI + 1)); fi
}

echo "Prova del riquadro della fotografia — Chromium headless"

# --- Il banco: script vero, markup vero, CSP vera ------------------------------
mkdir -p "${BANCO}/js"
cp "${ROOT}/assets/js/avatar.js" "${BANCO}/js/"
cp "${ROOT}/assets/css/piazzapulita.css" "${BANCO}/"

# Il modulo si estrae dalla VISTA, non si riscrive: se un giorno cambia lì,
# cambia anche qui, e la prova non misura un fossile.
# L'ancoraggio è `data-ritaglio` e basta: la riga del <form> contiene un tag PHP,
# quindi un `[^>]*` si fermerebbe sul `?>` prima di arrivare all'attributo.
awk '/data-ritaglio/,/<\/form>/' "${ROOT}/views/profilo/mio.php" \
  | sed -e 's/<?=[^?]*?>//g' -e 's/<?php[^?]*?>//g' > "${BANCO}/modulo.html"
if ! grep -q 'type="file"' "${BANCO}/modulo.html"; then
  echo "  ERRORE: non riesco a estrarre il modulo da views/profilo/mio.php" >&2
  exit 1
fi

# La CSP si prende da quella SERVITA DAVVERO, così la prova vede anche una
# regressione dell'intestazione invece di una copia ferma nel tempo.
CSP=$(curl -s -k -D - -o /dev/null -H "Host: ${HOST_HDR}" "${BASE_URL}/regole" \
      | grep -i '^content-security-policy:' | sed 's/^[^:]*: *//' | tr -d '\r')
if [[ -z "${CSP}" ]]; then
  echo "  ERRORE: il sito non risponde, non posso leggere la CSP vera." >&2
  exit 1
fi
printf '  CSP in prova: %s\n' "${CSP}"

cat > "${BANCO}/router.php" <<PHP
<?php
header('Content-Security-Policy: ' . <<<'CSP'
${CSP}
CSP);
return false;
PHP

{
  echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>banco</title>'
  echo '<link rel="stylesheet" href="piazzapulita.css"></head><body>'
  echo '<main style="max-width:40rem;margin:2rem auto">'
  cat "${BANCO}/modulo.html"
  echo '</main><pre id="esito">in corso</pre>'
  echo '<script src="js/avatar.js"></script><script src="js/prova.js"></script>'
  echo '</body></html>'
} > "${BANCO}/prova.html"

cat > "${BANCO}/js/prova.js" <<'JS'
(async () => {
  const scrivi = (o) => { document.getElementById('esito').textContent = JSON.stringify(o); };
  try {
    const box = document.querySelector('[data-ritaglio]');
    const input = box.querySelector('input[type=file]');
    const pulsante = box.querySelector('button[type=submit]');
    const spentoPrima = pulsante.disabled;

    // Una fotografia 1200x800: il quadrato giusto è 800, spostato di 200 a destra.
    const c = document.createElement('canvas');
    c.width = 1200; c.height = 800;
    const g = c.getContext('2d');
    g.fillStyle = '#8a6320'; g.fillRect(0, 0, 1200, 800);
    g.fillStyle = '#e9e4d8'; g.fillRect(500, 150, 200, 200);
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.9));
    const dt = new DataTransfer();
    dt.items.add(new File([blob], 'ritratto.jpg', { type: 'image/jpeg' }));
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));

    await new Promise(r => setTimeout(r, 900));

    const cornice = box.querySelector('.cornice');
    const img = cornice.querySelector('img');
    const w = img.getBoundingClientRect().width;
    const h = img.getBoundingClientRect().height;
    const F = cornice.clientWidth;
    scrivi({
      spentoPrima: spentoPrima,
      corniceVisibile: !cornice.hidden,
      comandiVisibili: !box.querySelector('[data-comandi]').hidden,
      attivoDopo: !pulsante.disabled,
      caricata: img.complete && img.naturalWidth > 0,
      copre: F > 0 && w >= F - 1 && h >= F - 1,
      larghezza: Math.round(w),
      avviso: (box.querySelector('[data-avviso]').textContent || '').trim(),
      sx: box.querySelector('[name=sx]').value,
      sy: box.querySelector('[name=sy]').value,
      lato: box.querySelector('[name=lato]').value
    });
  } catch (e) { scrivi({ eccezione: String(e) }); }
})();
JS

php -S "127.0.0.1:${PORTA}" -t "${BANCO}" "${BANCO}/router.php" >/dev/null 2>&1 &
sleep 1

ESITO=$("${CHROME}" --headless --disable-gpu --no-sandbox --window-size=1280,900 \
        --virtual-time-budget=8000 --dump-dom "http://127.0.0.1:${PORTA}/prova.html" 2>/dev/null \
        | sed -n 's/.*<pre id="esito">\(.*\)<\/pre>.*/\1/p' \
        | sed -e 's/&quot;/"/g' -e 's/&amp;/\&/g')

if [[ -z "${ESITO}" || "${ESITO}" == "in corso" ]]; then
  echo "  KO    il banco non ha prodotto un esito (Chromium non è partito?)"
  exit 1
fi
val() { php -r '$e=json_decode($argv[1],true); $v=$e[$argv[2]] ?? "?"; echo is_bool($v)?($v?"si":"no"):$v;' "${ESITO}" "$1"; }

verifica "lo script disabilita il pulsante finché non c'è un'anteprima" "si" "$(val spentoPrima)"
verifica "scegliendo una foto la cornice si apre"                       "si" "$(val corniceVisibile)"
verifica "e compaiono i comandi"                                        "si" "$(val comandiVisibili)"
verifica "l'anteprima si carica davvero"                                "si" "$(val caricata)"
verifica "e non resta larga zero"                                       "si" "$([[ "$(val larghezza)" -gt 0 ]] && echo si || echo no)"
verifica "l'immagine copre tutta la cornice"                            "si" "$(val copre)"
verifica "il pulsante torna attivo"                                     "si" "$(val attivoDopo)"
verifica "nessun avviso d'errore"                                       ""   "$(val avviso)"
# Il ritaglio di una 1200x800 è il quadrato di lato 800, centrato in orizzontale.
verifica "il ritaglio è il quadrato del lato corto" "800" "$(val lato)"
verifica "centrato in orizzontale"                  "200" "$(val sx)"
verifica "e attaccato in alto"                      "0"   "$(val sy)"

echo
if [[ "${FALLITI}" -eq 0 ]]; then printf '\033[0;32mTutte le verifiche superate.\033[0m\n'
else printf '\033[0;31m%d verifiche fallite.\033[0m\n' "${FALLITI}"; exit 1; fi
