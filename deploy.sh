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
# Copia SOLO i file versionati nel document root. NON usa --delete, quindi i
# file/cartelle NON in repo (config, credenziali, upload, vendor) restano intatti.

set -euo pipefail

DEPLOYPATH="/home/micoperibg/public_html/ardyagent.ardy-lab.it/"
SRC="$(cd "$(dirname "$0")" && pwd)/"

echo "Deploy da: $SRC"
echo "Deploy in: $DEPLOYPATH"

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
  "$SRC" "$DEPLOYPATH"

echo "Deploy completato."

echo "Migrazione schema DB..."
php "$DEPLOYPATH/ardy-migrate.php"
echo "Migrazione completata."
