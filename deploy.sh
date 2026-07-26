#!/bin/bash
# Deploy Ardy Agent dal checkout git al document root del sito.
# Pensato per girare SUL server (via SSH) dentro la cartella del repo clonato,
# es. /home/micoperibg/repositories/ardyagent
#
# Uso:
#   cd ~/repositories/ardyagent
#   git pull origin main
#   ./deploy.sh
#
# Copia il contenuto del repo nel document root. NON usa --delete, quindi i
# file/cartelle NON in repo (config, credenziali, upload, vendor) restano intatti.
#
# ATTENZIONE: rsync NON legge .gitignore — copia tutto ciò che non è escluso qui
# sotto a mano. Per questo log e file di lavoro hanno le loro --exclude: senza,
# l'error_log di PHP scritto nella cartella del repo finirebbe in public_html.

set -euo pipefail

DEPLOYPATH="/home/micoperibg/public_html/ardyagent.ardy-lab.it/"
SRC="$(cd "$(dirname "$0")" && pwd)/"

echo "Deploy da: $SRC"
echo "Deploy in: $DEPLOYPATH"

# Controllo sintassi: blocca il deploy se un .php ha errori (eviterebbe un 500
# in produzione appena l'endpoint viene chiamato). vendor/ e phpmailer/ esclusi.
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
  --exclude='error_log' \
  --exclude='*.log' \
  --exclude='*.tmp' \
  --exclude='.github/' \
  "$SRC" "$DEPLOYPATH"

echo "Deploy completato."

echo "Migrazione schema DB..."
php "$DEPLOYPATH/ardy-migrate.php"
echo "Migrazione completata."
