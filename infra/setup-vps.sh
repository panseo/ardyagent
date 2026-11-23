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
#
# Isolamento (sessione "sicurezza" dell'08/11, vedi TODO-PROSSIMI-TASK.md
# "Controllo protezioni VPS"): sul server attuale pigmentombg.com e
# omnialga.com girano con lo stesso utente cPanel del gestionale — un
# WordPress compromesso potrebbe leggere ardy-private/. Qui si crea un utente
# e un pool PHP-FPM dedicati SOLO al gestionale: ogni altro sito/web app che
# finisce su questo VPS va fatto con lo stesso schema (utente + pool propri),
# mai aggiunto a quello di ardy.

set -euo pipefail

APP_USER="ardy"
APP_DOMAIN="ardyagent.ardy-lab.it"
WEBROOT="/var/www/${APP_DOMAIN}"
PRIVATE_DIR="/home/${APP_USER}/ardy-private"

echo "== Aggiornamento sistema =="
apt-get update && apt-get -y upgrade

echo "== Aggiornamenti di sicurezza automatici (unattended-upgrades) =="
# Sul server attuale cPanel/WHM se ne occupa in parte da solo; su un VPS
# "nudo" va abilitato esplicitamente, altrimenti le patch di sicurezza Debian
# restano a installazione manuale. NON abilita il riavvio automatico
# (Automatic-Reboot resta "false" nel default del pacchetto): un riavvio da
# solo potrebbe interrompere l'app senza preavviso — i riavvii restano manuali.
apt-get install -y unattended-upgrades apt-listchanges
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

echo "== Caddy =="
apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | tee /etc/apt/sources.list.d/caddy-stable.list
apt-get update
apt-get install -y caddy

echo "== PHP-FPM 8.3 + estensioni (pdo_mysql, gd, curl, mbstring, zip, intl) =="
# Debian 13 (Trixie) ha PHP 8.3 nativo nei repo di sistema — nessun repo di
# terze parti necessario (a differenza di Debian 12/Bookworm, che ha 8.2 di
# default e richiederebbe Sury). Se si finisce comunque su una base diversa,
# verificare prima `apt-cache policy php8.3-fpm`.
apt-get install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-curl \
  php8.3-mbstring php8.3-zip php8.3-intl php8.3-xml php8.3-bcmath

echo "== MariaDB =="
apt-get install -y mariadb-server mariadb-client
# bind-address esplicito: su Debian è già il default di pacchetto, ma lo
# scriviamo a scopo (il server attuale lo ha confermato esplicitamente,
# "MariaDB solo su 127.0.0.1:3306" — TODO-PROSSIMI-TASK.md "Controllo
# protezioni VPS") invece di fidarsi di un default che potrebbe cambiare.
cat > /etc/mysql/mariadb.conf.d/99-ardy-bind.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
EOF
systemctl restart mariadb
# mysql_secure_installation va lanciato a mano (interattivo): imposta la root
# password, rimuove utenti anonimi/test. Poi creare DB + utente applicativo:
#   CREATE DATABASE ardyagent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE USER 'ardy'@'localhost' IDENTIFIED BY '<password-vera-non-qui>';
#   GRANT ALL PRIVILEGES ON ardyagent.* TO 'ardy'@'localhost';
#   FLUSH PRIVILEGES;
# Per il backup (vedi sezione più sotto) creare anche un utente a permessi
# ridotti, es.:
#   CREATE USER 'ardy_backup'@'localhost' IDENTIFIED BY '<altra-password>';
#   GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON ardyagent.* TO 'ardy_backup'@'localhost';

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
ufw limit 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
# "limit" invece di "allow" su SSH: ufw rifiuta temporaneamente un IP che fa
# troppi tentativi di connessione in poco tempo (6 in 30s) — una prima difesa
# anche prima che fail2ban entri in gioco.

echo "== SSH: solo chiave, niente root via password =="
# ⚠️ ESEGUIRE SOLO DOPO aver verificato che l'accesso via chiave funziona
# (aprire una seconda sessione SSH di prova PRIMA di lanciare questo blocco —
# se la chiave non funziona e si disabilita la password, ci si chiude fuori).
# Replica quanto già confermato sul server attuale (TODO-PROSSIMI-TASK.md
# "Controllo protezioni VPS": PermitRootLogin without-password + PasswordAuthentication no).
if [ ! -s /root/.ssh/authorized_keys ]; then
  echo "ERRORE: /root/.ssh/authorized_keys è vuoto o assente." >&2
  echo "Non disabilito l'accesso via password per evitare di restare fuori — " >&2
  echo "aggiungi prima la tua chiave pubblica, poi rilancia questo blocco." >&2
  exit 1
fi
cat > /etc/ssh/sshd_config.d/99-ardy-hardening.conf <<'EOF'
PermitRootLogin prohibit-password
PasswordAuthentication no
PubkeyAuthentication yes
EOF
sshd -t
systemctl reload ssh

echo "== Fail2ban: ban automatico su tentativi di brute-force SSH =="
# Sul server attuale questo ruolo lo fa csf+lfd (ConfigServer Firewall);
# fail2ban è l'equivalente leggero per un VPS senza pannello.
apt-get install -y fail2ban
cat > /etc/fail2ban/jail.local <<'EOF'
[DEFAULT]
bantime = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
backend = systemd
EOF
systemctl enable --now fail2ban

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

# Cartella privata fuori web-root per ardy-config.php vero + token Google —
# stesso schema già in uso sul server attuale (README.md "Config e token
# fuori da public_html", SECURITY-AUDIT.md §8.6): in $WEBROOT/ardy-config.php
# resta solo un "ponte" di una riga che fa require_once verso qui.
mkdir -p "$PRIVATE_DIR"
chmod 700 "$PRIVATE_DIR"
chown "${APP_USER}:${APP_USER}" "$PRIVATE_DIR"

echo "== Pool PHP-FPM dedicato (non quello 'www' di default) =="
# Gira come $APP_USER, non come www-data: ardy-rate-limit/ (rate-limit +
# tetto di spesa ardy-costcap.php) e ardy-private/ restano leggibili/scrivibili
# solo da questo utente, senza allargare permessi a www-data. socket.group è
# "caddy" (l'utente con cui gira il pacchetto Caddy su Debian) così Caddy può
# connettersi senza condividere l'utente.
cat > /etc/php/8.3/fpm/pool.d/ardyagent.conf <<EOF
[ardyagent]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php/php8.3-fpm-ardyagent.sock
listen.owner = ${APP_USER}
listen.group = caddy
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
EOF
systemctl restart php8.3-fpm

echo "== Backup off-site su Backblaze B2 =="
# Sul server attuale lo fa il backup integrato cPanel (tutta la home, quindi
# anche ardy-private/) verso B2. Qui non c'è cPanel: infra/backup-b2.sh (DB +
# ardy-private/) via cron, stesso bucket/account B2 già in uso
# (PIANO-MIGRAZIONE.md Fase 0 — B2 già aperto). Credenziali NON in questo
# script: vanno scritte a mano in /root/.ardy-backup.env (vedi
# infra/ardy-backup.env.example) e nella config rclone (`rclone config`).
apt-get install -y rclone
mkdir -p /root/bin
cp "$(dirname "$0")/backup-b2.sh" /root/bin/ardy-backup.sh
chmod 700 /root/bin/ardy-backup.sh
cat > /etc/cron.d/ardy-backup <<EOF
0 4 * * * root /root/bin/ardy-backup.sh >> /var/log/ardy-backup.log 2>&1
EOF

echo "== Fatto. Prossimi passi manuali: =="
echo "  1. mysql_secure_installation + creazione DB/utente (vedi sopra)"
echo "  2. git clone del repo in ${WEBROOT} (come utente ${APP_USER})"
echo "  3. Scrivere il vero ardy-config.php in ${PRIVATE_DIR}/ (vedi SECURITY-AUDIT.md §4 e §8.6,"
echo "     README.md 'Config e token fuori da public_html') + il ponte di una riga in"
echo "     ${WEBROOT}/ardy-config.php. In Fase 3 i file si prendono da ~/ardy-private/ sul"
echo "     server attuale, NON da public_html (lì c'è solo il ponte)."
echo "  4. Copiare infra/Caddyfile in /etc/caddy/Caddyfile e 'caddy validate' + 'systemctl reload caddy'"
echo "  5. docker compose -f infra/docker-compose.n8n.yml up -d (dopo aver scritto infra/n8n.env)"
echo "  6. Qualunque altro sito/web app su questo VPS: utente + pool PHP-FPM propri, MAI quello"
echo "     di ${APP_USER} — vedi nota isolamento in testa allo script."
echo "  7. rclone config (remoto B2) + scrivere /root/.ardy-backup.env (vedi"
echo "     infra/ardy-backup.env.example) + un giro a mano di /root/bin/ardy-backup.sh"
echo "     per verificare che il backup arrivi davvero su B2 prima di fidarsi del cron."
