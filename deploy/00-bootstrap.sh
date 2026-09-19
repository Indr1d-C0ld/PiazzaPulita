#!/usr/bin/env bash
#
# Piazza Pulita — bootstrap dell'ambiente su Balthasar.
# Da eseguire UNA VOLTA con sudo:   sudo bash deploy/00-bootstrap.sh
#
# Idempotente: puo' essere rilanciato senza danni. Non tocca i vhost esistenti
# (usa conf-available/a2enconf), fa backup di cio' che sostituisce e verifica la
# configurazione Apache con apache2ctl configtest PRIMA del reload.
#
# Cosa fa:
#   1. rende /data/html/piazzapulita scrivibile dal proprietario, gruppo www-data (setgid)
#   2. crea /data/piazzapulita-config/ per i segreti (fuori dal DocumentRoot)
#   3. crea database e utente MariaDB piz_piazzapulita con password generata
#   4. scrive /data/piazzapulita-config/config.php se non esiste
#   5. installa e abilita la conf Apache
#
set -euo pipefail

OWNER_USER="${PIZ_OWNER:-$(logname 2>/dev/null || echo "${SUDO_USER:-root}")}"
OWNER_GROUP="www-data"
PROJECT_DIR="/data/html/piazzapulita"
CONFIG_DIR="/data/piazzapulita-config"
CONFIG_FILE="${CONFIG_DIR}/config.php"
DB_NAME="piz_piazzapulita"
DB_USER="piz_piazzapulita"
APACHE_CONF_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/apache-piazzapulita.conf"
APACHE_CONF_DST="/etc/apache2/conf-available/piazzapulita.conf"
STAMP="$(date +%Y%m%d-%H%M%S)"

# Valori dell'installazione. Si passano da ambiente:
#   PIZ_URL=https://esempio.tld/piazzapulita PIZ_ADMIN_EMAIL=io@esempio.tld \
#     sudo -E bash deploy/00-bootstrap.sh
PIZ_URL="${PIZ_URL:-http://localhost/piazzapulita}"
PIZ_ADMIN_EMAIL="${PIZ_ADMIN_EMAIL:-}"

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()  { printf '    \033[0;32m%s\033[0m\n' "$*"; }
warn(){ printf '    \033[0;33m%s\033[0m\n' "$*"; }

if [[ "${EUID}" -ne 0 ]]; then
  echo "Questo script va eseguito con sudo." >&2
  exit 1
fi

# --- 1. Directory di progetto ------------------------------------------------
say "1/5  Directory di progetto ${PROJECT_DIR}"
mkdir -p "${PROJECT_DIR}"
chown -R "${OWNER_USER}:${OWNER_GROUP}" "${PROJECT_DIR}"
chmod 2775 "${PROJECT_DIR}"
ok "$(stat -c '%U:%G %a' "${PROJECT_DIR}")  ${PROJECT_DIR}"

# --- 2. Directory dei segreti ------------------------------------------------
say "2/5  Directory di configurazione ${CONFIG_DIR}"
mkdir -p "${CONFIG_DIR}"
chown "${OWNER_USER}:${OWNER_GROUP}" "${CONFIG_DIR}"
chmod 2750 "${CONFIG_DIR}"
ok "$(stat -c '%U:%G %a' "${CONFIG_DIR}")  ${CONFIG_DIR}"

# --- 3. Database -------------------------------------------------------------
say "3/5  Database MariaDB ${DB_NAME}"
DB_EXISTS="$(mariadb -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}';")"
USER_EXISTS="$(mariadb -N -B -e "SELECT COUNT(*) FROM mysql.user WHERE User='${DB_USER}' AND Host='localhost';")"

if [[ -f "${CONFIG_FILE}" ]]; then
  # Config gia' presente: NON si tocca la password, si riusa quella del file.
  DB_PASS="$(php -r '$c=require "'"${CONFIG_FILE}"'"; echo $c["db"]["pass"] ?? "";')"
  if [[ -z "${DB_PASS}" ]]; then
    echo "Config presente ma senza password DB leggibile: intervento manuale richiesto." >&2
    exit 1
  fi
  warn "config.php gia' presente: riuso la password esistente (nessuna rigenerazione)."
else
  DB_PASS="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 28)"
fi

mariadb -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mariadb -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mariadb -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mariadb -e "FLUSH PRIVILEGES;"
[[ "${DB_EXISTS}" == "0" ]] && ok "database creato" || ok "database gia' esistente (conservato)"
[[ "${USER_EXISTS}" == "0" ]] && ok "utente creato" || ok "utente gia' esistente (password riallineata al config)"

# --- 4. File di configurazione ----------------------------------------------
say "4/5  ${CONFIG_FILE}"
if [[ -f "${CONFIG_FILE}" ]]; then
  ok "gia' presente: lasciato intatto"
else
  cat > "${CONFIG_FILE}" <<PHPEOF
<?php

declare(strict_types=1);

/**
 * Piazza Pulita — configurazione con i segreti. FUORI dal DocumentRoot.
 * Generato da deploy/00-bootstrap.sh il ${STAMP}.
 */

return [
    'app' => [
        'name'        => 'Piazza Pulita',
        'env'         => 'production',
        'debug'       => false,
        'timezone'    => 'Europe/Rome',
        'pretty_urls' => true,
        'base_path'   => null,
        // L'indirizzo pubblico con cui il gioco si presenta nelle e-mail.
        'public_url'  => '${PIZ_URL}',
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => '${DB_NAME}',
        'user'    => '${DB_USER}',
        'pass'    => '${DB_PASS}',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'session_name' => 'piazzapulita_sess',
        'session_ttl'  => 60 * 60 * 8,
    ],

    // Trasporto e-mail: 'log' finche' non si compilano le credenziali Brevo.
    // Le chiavi reali sono le stesse di SubSpazio e Atlantik (stesso account).
    'mail' => [
        'transport'   => 'log',   // 'smtp' dopo aver messo le credenziali Brevo
        'smtp_host'   => 'smtp-relay.brevo.com',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',
        'smtp_user'   => 'CAMBIAMI',
        'smtp_pass'   => 'CAMBIAMI',
        'from_email'  => 'CAMBIAMI',   // deve essere un mittente verificato in Brevo
        'from_name'   => 'Piazza Pulita',
        'timeout'     => 15,
    ],

    'notify' => [
        'new_registration' => true,
        // Dove arrivano gli avvisi di nuova iscrizione. Vuoto = nessun avviso.
        'admin_email'      => '${PIZ_ADMIN_EMAIL}',
    ],

    'mondo' => [
        // Seme del mondo: fissa tutto cio' che e' pseudocasuale e deterministico.
        // Cambiarlo dopo l'avvio significa cambiare mondo: non farlo.
        'seme' => ${RANDOM}${RANDOM},
    ],
];
PHPEOF
  chown "${OWNER_USER}:${OWNER_GROUP}" "${CONFIG_FILE}"
  chmod 0640 "${CONFIG_FILE}"
  ok "creato ($(stat -c '%U:%G %a' "${CONFIG_FILE}"))"
fi

# --- 5. Apache ---------------------------------------------------------------
say "5/5  Configurazione Apache"
if [[ ! -f "${APACHE_CONF_SRC}" ]]; then
  echo "Manca ${APACHE_CONF_SRC}" >&2
  exit 1
fi
if [[ -f "${APACHE_CONF_DST}" ]]; then
  cp -a "${APACHE_CONF_DST}" "${APACHE_CONF_DST}.bak-${STAMP}"
  ok "backup: ${APACHE_CONF_DST}.bak-${STAMP}"
fi
install -m 0644 -o root -g root "${APACHE_CONF_SRC}" "${APACHE_CONF_DST}"
a2enmod rewrite >/dev/null 2>&1 || true
a2enconf piazzapulita >/dev/null
if apache2ctl configtest 2>&1 | tail -1 | grep -q "Syntax OK"; then
  systemctl reload apache2
  ok "configtest OK, apache2 ricaricato"
else
  warn "configtest FALLITO: nessun reload eseguito. Output:"
  apache2ctl configtest || true
  exit 1
fi

say "Fatto."
cat <<SUMMARY
    Progetto : ${PROJECT_DIR}          (${OWNER_USER}:${OWNER_GROUP}, 2775)
    Segreti  : ${CONFIG_FILE}
    Database : ${DB_NAME} / utente ${DB_USER} @localhost
    URL      : ${PIZ_URL}/

    La password del DB e' SOLO dentro ${CONFIG_FILE} (0640). Non compare nei log.

    Prossimi passi (da utente normale, non root):
      rsync -a --delete --exclude fonti/ --exclude config/ --exclude .git \\
            /data/claude/PiazzaPulita/ ${PROJECT_DIR}/
      php ${PROJECT_DIR}/bin/console.php migrate
      php ${PROJECT_DIR}/bin/console.php status

    Battito (manutenzione posta e freni), nel crontab di ${OWNER_USER}:
      * * * * * /usr/bin/php ${PROJECT_DIR}/bin/tick.php >/dev/null 2>&1
SUMMARY
