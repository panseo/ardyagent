#!/bin/bash
# Backup off-site su Backblaze B2 per il nuovo VPS (PIANO-MIGRAZIONE.md, Fase 5).
# Copiato da infra/setup-vps.sh in /root/bin/ardy-backup.sh e girato da root via
# cron (/etc/cron.d/ardy-backup, 04:00) — stesso orario del backup n8n sul
# server attuale (/root/bin/n8n-backup.sh), per coerenza.
#
# Sul server attuale questo ruolo lo fa il backup integrato cPanel (tutta la
# home, ardy-private/ incluso) verso B2. Qui non c'è cPanel: questo script
# copre DB + ardy-private/ (config, token) — i soli due pezzi che non sono già
# altrove (il codice è in git, i media vanno su B2 per conto proprio, vedi
# TODO-PROSSIMI-TASK.md "Media su Backblaze B2").
#
# ⚠️ NON eseguito/collaudato in questa sessione (nessun accesso al VPS né a
# B2): prima di fidarsi del cron, lanciarlo a mano e verificare che il file
# compaia davvero nel bucket.
#
# Richiede, scritti a mano sul server (NON in questo repo):
#   - /root/.ardy-backup.env — vedi infra/ardy-backup.env.example
#   - un utente MySQL a permessi ridotti per il dump (vedi commento in
#     infra/setup-vps.sh, sezione MariaDB)
#   - un remoto rclone configurato (`rclone config`) verso B2

set -euo pipefail

ENV_FILE="/root/.ardy-backup.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "ERRORE: $ENV_FILE mancante — vedi infra/ardy-backup.env.example" >&2
  exit 1
fi
# shellcheck source=/dev/null
source "$ENV_FILE"
: "${ARDY_DB_NAME:?manca ARDY_DB_NAME in $ENV_FILE}"
: "${ARDY_DB_BACKUP_USER:?manca ARDY_DB_BACKUP_USER in $ENV_FILE}"
: "${ARDY_DB_BACKUP_PASS:?manca ARDY_DB_BACKUP_PASS in $ENV_FILE}"
: "${RCLONE_REMOTE:?manca RCLONE_REMOTE in $ENV_FILE (nome del remoto rclone verso B2)}"
: "${B2_BUCKET:?manca B2_BUCKET in $ENV_FILE}"
: "${B2_PREFIX:?manca B2_PREFIX in $ENV_FILE (es. ardyagent-vps)}"
PRIVATE_DIR="${ARDY_PRIVATE_DIR:-/home/ardy/ardy-private}"

STAMP="$(date +%Y%m%d-%H%M%S)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "Dump DB ${ARDY_DB_NAME}..."
mysqldump --single-transaction --routines --triggers \
  -u "$ARDY_DB_BACKUP_USER" -p"$ARDY_DB_BACKUP_PASS" "$ARDY_DB_NAME" \
  | gzip > "${TMP_DIR}/ardyagent-db-${STAMP}.sql.gz"

echo "Copia dump su B2..."
rclone copy "${TMP_DIR}/ardyagent-db-${STAMP}.sql.gz" \
  "${RCLONE_REMOTE}:${B2_BUCKET}/${B2_PREFIX}/db/"

if [ -d "$PRIVATE_DIR" ]; then
  echo "Sync ardy-private/ (config, token) su B2..."
  rclone sync "$PRIVATE_DIR" "${RCLONE_REMOTE}:${B2_BUCKET}/${B2_PREFIX}/ardy-private/"
else
  echo "ATTENZIONE: $PRIVATE_DIR non trovata, salto il backup di config/token." >&2
fi

echo "Backup completato (${STAMP})."
# Retention: lasciata al lifecycle del bucket B2 (30 giorni, come gli altri
# bucket di backup già in uso — PIANO-MIGRAZIONE.md §4 Fase 0), non a questo
# script. Se il bucket non ha lifecycle impostato, i dump si accumulano.
