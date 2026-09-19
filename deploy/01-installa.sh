#!/usr/bin/env bash
#
# Piazza Pulita — installazione del codice in /data/html/piazzapulita.
# Da eseguire da utente normale (NON root), dopo 00-bootstrap.sh:
#
#   bash deploy/01-installa.sh
#
# Perché serve uno script invece di un rsync a mano: `rsync -a` include `-g`,
# e un utente non privilegiato che copia con -g rimette il PROPRIO gruppo sui
# file. Il bootstrap aveva impostato <utente>:www-data con il setgid, così che
# il processo web possa scrivere il diario; il primo `rsync -a` ha rimesso
# il gruppo dell'utente e il diario ha smesso di essere scrivibile — in silenzio,
# perché logger() ripiega sul syslog e non si lamenta. Qui i permessi si
# ripristinano ogni volta, subito dopo la copia.
#
set -euo pipefail

SORGENTE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DESTINAZIONE="${1:-/data/html/piazzapulita}"
GRUPPO="www-data"

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()  { printf '    \033[0;32m%s\033[0m\n' "$*"; }

if [[ "${EUID}" -eq 0 ]]; then
  echo "Questo script va eseguito da utente normale, non con sudo." >&2
  exit 1
fi
if [[ ! -d "${DESTINAZIONE}" ]]; then
  echo "Manca ${DESTINAZIONE}: esegui prima 'sudo bash deploy/00-bootstrap.sh'." >&2
  exit 1
fi

say "Copia del codice in ${DESTINAZIONE}"
rsync -rlptD --delete \
  --exclude 'fonti/' \
  --exclude 'config/' \
  --exclude '.git/' \
  --exclude 'storage/logs/*.log' \
  --exclude 'storage/*.lock' \
  "${SORGENTE}/" "${DESTINAZIONE}/"
ok "$(find "${DESTINAZIONE}" -type f | wc -l) file"

say "Permessi"
# Il gruppo www-data e il setgid sulle directory: così tutto quello che nasce
# qui dentro resta leggibile dal processo web, e scrivibile dove serve.
#
# Si tocca SOLO quello che è nostro: il diario lo crea il processo web, quindi
# appartiene a www-data, e chgrp su un file altrui fallisce anche quando il
# gruppo è già quello giusto. Senza il filtro `-user`, con `set -e`, lo script
# moriva proprio sul file che dimostra che i permessi funzionano.
MIO="$(id -un)"
find "${DESTINAZIONE}" -user "${MIO}" -exec chgrp "${GRUPPO}" {} +
find "${DESTINAZIONE}" -user "${MIO}" -type d -exec chmod 2775 {} +
find "${DESTINAZIONE}" -user "${MIO}" -type f -exec chmod 0664 {} +
find "${DESTINAZIONE}/bin" "${DESTINAZIONE}/deploy" "${DESTINAZIONE}/tests" \
     -user "${MIO}" -name '*.sh' -exec chmod 0775 {} + 2>/dev/null || true
ok "$(stat -c '%U:%G %a' "${DESTINAZIONE}")  ${DESTINAZIONE}"
ok "$(stat -c '%U:%G %a' "${DESTINAZIONE}/storage/logs")  storage/logs"

# La configurazione la sceglie Config, che cerca /data/piazzapulita-config/
# PRIMA di config/config.php. Se in ambiente c'è PIAZZAPULITA_CONFIG — e c'è
# ogni volta che si sta sviluppando sul database usa-e-getta — le migrazioni
# finirebbero là invece che in produzione. Qui si toglie di mezzo.
unset PIAZZAPULITA_CONFIG

say "Migrazioni, mondo e mercato"
php "${DESTINAZIONE}/bin/console.php" migrate
php "${DESTINAZIONE}/bin/console.php" mondo:semina
php "${DESTINAZIONE}/bin/console.php" mercato:semina --conserva

say "Stato"
php "${DESTINAZIONE}/bin/console.php" status
