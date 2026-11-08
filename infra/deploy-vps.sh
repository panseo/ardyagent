#!/bin/bash
# Deploy Ardy Agent sul nuovo VPS no-panel (PIANO-MIGRAZIONE.md, Fase 2).
# Variante di ../deploy.sh senza alcuna assunzione cPanel: percorso configurabile,
# nessun riferimento a /home/micoperibg. Finché il cutover (Fase 4) non è fatto,
# ../deploy.sh resta lo script in uso sul server attuale — non va toccato per
# questo lavoro di preparazione.
#
# Uso (sul VPS, dentro il checkout git):
#   cd ~/repositories/ardyagent
#   git pull origin main
#   DEPLOYPATH=/var/www/ardyagent.ardy-lab.it ./infra/deploy-vps.sh
#
# Stesso principio di ../deploy.sh: rsync SENZA --delete (i file non in repo —
# config, credenziali, upload — restano intatti) e NON legge .gitignore (le
# --exclude sotto sono a mano, tenerle in sync con quelle di ../deploy.sh).

set -euo pipefail

DEPLOYPATH="${DEPLOYPATH:?Imposta DEPLOYPATH, es. DEPLOYPATH=/var/www/ardyagent.ardy-lab.it ./infra/deploy-vps.sh}"
SRC="$(cd "$(dirname "$0")/.." && pwd)/"

echo "Deploy da: $SRC"
echo "Deploy in: $DEPLOYPATH"

echo "Controllo sintassi PHP..."
find "$SRC" -name '*.php' -not -path '*/vendor/*' -not -path '*/phpmailer/*' -print0 \
  | xargs -0 -n1 php -l >/dev/null
echo "Sintassi OK."

rsync -av \
  --exclude='.git/' \
  --exclude='.gitignore' \
  --exclude='deploy.sh' \
  --exclude='.cpanel.yml' \
  --exclude='ardy-config.php' \
  --exclude='ardy-gcal-token.json' \
  --exclude='ardy-gbp-location.json' \
  --exclude='ardy-uploads/' \
  --exclude='preventivi_pdf/' \
  --exclude='reels/' \
  --exclude='vendor/' \
  --exclude='ardy-rate-limit/' \
  --exclude='ardy-wa-log.json' \
  --exclude='wordpress-snippets/' \
  --exclude='ardy-mcp/' \
  --exclude='n8n/' \
  --exclude='infra/' \
  --exclude='CLAUDE.md' \
  --exclude='error_log' \
  --exclude='*.log' \
  --exclude='*.tmp' \
  --exclude='.github/' \
  "$SRC" "$DEPLOYPATH"

echo "Deploy completato."

echo "Migrazione schema DB..."
php "$DEPLOYPATH/ardy-migrate.php"
echo "Migrazione completata."

# A differenza di cPanel (che riavvia PHP-FPM da solo tra un deploy e l'altro
# nell'uso normale), qui PHP-FPM è gestito da systemd: se opcache ha la cache
# dei file attiva con validate_timestamps=0, serve un reload esplicito.
# Scommentare se necessario, dopo aver verificato il nome del servizio:
# systemctl reload php8.3-fpm
