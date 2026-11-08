#!/bin/bash
# Provisioning del nuovo VPS no-panel (PIANO-MIGRAZIONE.md, Fase 1).
# Pensato per Debian/Ubuntu LTS pulito, da lanciare come root UNA SOLA VOLTA
# su un server ancora senza nulla in produzione.
#
# ⚠️ NON eseguito/collaudato in questa sessione (nessun accesso al VPS): leggere
# ogni blocco prima di lanciarlo, non è un copia-incolla alla cieca. Pensato per
# essere eseguito a pezzi (è idempotente dove possibile, ma non universalmente).
#
# Cosa fa: Caddy + PHP-FPM 8.3 (con le estensioni richieste dall'app) + MariaDB +
# Docker (per n8n) + FFmpeg (ardy-crea-reel.php) + firewall base (ufw) + utente
# applicativo + cartelle con i permessi giusti.
#
# Cosa NON fa (volutamente, vedi PIANO-MIGRAZIONE.md): non crea ardy-config.php
# (contiene segreti, va scritto a mano sul server — vedi infra/README.md), non
# tocca DNS/Cloudflare, non importa il dump del DB (Fase 3), non configura n8n
# stesso (vedi infra/docker-compose.n8n.yml).

set -euo pipefail

APP_USER="ardy"
APP_DOMAIN="ardyagent.ardy-lab.it"
WEBROOT="/var/www/${APP_DOMAIN}"

echo "== Aggiornamento sistema =="
apt-get update && apt-get -y upgrade

echo "== Caddy =="
apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | tee /etc/apt/sources.list.d/caddy-stable.list
apt-get update
apt-get install -y caddy

echo "== PHP-FPM 8.3 + estensioni (pdo_mysql, gd, curl, mbstring, zip, intl) =="
# Debian/Ubuntu LTS "stabili" spesso hanno un PHP di default più vecchio del
# 8.3 usato in produzione (vedi .htaccess: "ea-php83"): se il repo di sistema
# non offre 8.3, aggiungere il repo Sury (Debian) o Ondrej (Ubuntu) PRIMA di
# questo blocco — verificare `apt-cache policy php8.3-fpm` prima di procedere.
apt-get install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-curl \
  php8.3-mbstring php8.3-zip php8.3-intl php8.3-xml php8.3-bcmath

echo "== MariaDB =="
apt-get install -y mariadb-server mariadb-client
# mysql_secure_installation va lanciato a mano (interattivo): imposta la root
# password, rimuove utenti anonimi/test. Poi creare DB + utente applicativo:
#   CREATE DATABASE ardyagent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE USER 'ardy'@'localhost' IDENTIFIED BY '<password-vera-non-qui>';
#   GRANT ALL PRIVILEGES ON ardyagent.* TO 'ardy'@'localhost';
#   FLUSH PRIVILEGES;

echo "== Docker (per n8n) =="
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | tee /etc/apt/sources.list.d/docker.list > /dev/null
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

echo "== FFmpeg (ardy-crea-reel.php genera reel via proc_open) =="
apt-get install -y ffmpeg

echo "== Firewall (ufw): solo SSH/HTTP/HTTPS =="
apt-get install -y ufw
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "== Utente applicativo + cartelle =="
id -u "$APP_USER" &>/dev/null || useradd -m -s /bin/bash "$APP_USER"
mkdir -p "$WEBROOT"
# Le cartelle NON in repo (vedi deploy.sh --exclude e README.md): create vuote,
# 755, popolate poi dal primo deploy / dai dati importati in Fase 3.
for d in ardy-uploads preventivi_pdf reels ardy-rate-limit; do
  mkdir -p "${WEBROOT}/${d}"
  chmod 755 "${WEBROOT}/${d}"
done
chown -R "${APP_USER}:${APP_USER}" "$WEBROOT"
# Caddy e PHP-FPM devono poter leggere/scrivere: su Debian il gruppo di www-data
# per PHP-FPM è tipicamente "www-data" — verificare `grep '^group' /etc/php/8.3/fpm/pool.d/www.conf`
# e aggiungere l'utente/gruppo corretto in ACL o chgrp, invece di allargare a 777.

echo "== Fatto. Prossimi passi manuali: =="
echo "  1. mysql_secure_installation + creazione DB/utente (vedi sopra)"
echo "  2. git clone del repo in ${WEBROOT} (come utente ${APP_USER})"
echo "  3. Scrivere ardy-config.php a mano (vedi infra/README.md e SECURITY-AUDIT.md §4)"
echo "  4. Copiare infra/Caddyfile in /etc/caddy/Caddyfile e 'caddy validate' + 'systemctl reload caddy'"
echo "  5. docker compose -f infra/docker-compose.n8n.yml up -d (dopo aver scritto infra/n8n.env)"
