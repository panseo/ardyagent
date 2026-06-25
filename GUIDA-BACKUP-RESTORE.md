# Guida — Backup & Ripristino su Backblaze B2

**Data:** 2026-06-23 · **Stato:** operativa (da applicare ORA sull'infra attuale)
**Contesto:** la migrazione è **rimandata**, ma la **connessione a Backblaze B2 si fa subito** come
copia **off-site** (sicurezza #4). Tutto gira sul server attuale (cPanel, utente `micoperibg`).

> **Modello "due gemelli" = due ambienti INDIPENDENTI.** Ogni server fa il **proprio** backup in un
> **prefisso B2 dedicato** (es. `srv-prod/`, `srv-staging/`) e ripristina **solo dal proprio prefisso**.
> Niente sincronizzazione incrociata: un server non legge mai i backup dell'altro (se non per un
> recupero manuale e consapevole). Questo evita di sovrascrivere per errore i dati di produzione.

---

## 0. Cosa salviamo (e cosa NO)

| Voce | Dove | In backup? | Perché |
|---|---|---|---|
| **Codice app** | repo Git | ❌ | è già su GitHub, si ripristina con `git clone` + `deploy.sh` |
| **Database** | MySQL `micoperibg_ardyagent` | ✅ | il cuore: clienti, preventivi, fasi, chat, sopralluoghi… |
| **Media/upload** | `ardy-uploads/`, `preventivi_pdf/`, `reels/` (sotto docroot) | ✅ | foto/video/reel/PDF generati, NON in git |
| **Config & token** | `ardy-config.php`, `ardy-gcal-token.json`, `ardy-gbp-location.json` | ✅ | credenziali e token NON in git, irripetibili |
| **n8n** | container `n8n_app` (volume Docker) | ✅ (da root) | workflow/credenziali automazioni |
| **WordPress** | DB + `wp-content/uploads` del sito | ➖ opzionale | se vuoi coprire anche il sito (vedi §8) |

Percorsi reali (utente `micoperibg`):

```
DOCROOT = /home/micoperibg/public_html/ardyagent.ardy-lab.it
DATI    = $DOCROOT/ardy-uploads  $DOCROOT/preventivi_pdf  $DOCROOT/reels
CONFIG  = $DOCROOT/ardy-config.php  $DOCROOT/ardy-gcal-token.json  $DOCROOT/ardy-gbp-location.json
DB      = micoperibg_ardyagent   (host localhost; credenziali dentro ardy-config.php)
```

---

## 1. Backblaze B2 — account, bucket, chiavi

1. Crea account su **backblaze.com** → sezione **B2 Cloud Storage**.
2. Crea **un solo bucket** condiviso dai due gemelli, **Private**:
   - Nome: `ardy-backup` (i nomi bucket sono globali: se occupato usa `ardy-backup-<qualcosa>`).
   - **Encryption**: abilita SSE-B2 (lato server, gratis).
   - **Object Lock**: lascialo OFF per ora (vedi nota ransomware in §9 se vuoi alzarlo dopo).
3. **Lifecycle rules** sul bucket (Settings → Lifecycle): scegli
   **"Keep prior versions for this many days" = 30**. Così le versioni vecchie/eliminate restano
   recuperabili 30 giorni e poi B2 le pota da solo (point-in-time recovery + costi sotto controllo).
4. **App Key dedicata** (Account → Application Keys → *Add a New Application Key*):
   - Name: `ardy-backup-key`
   - **Allow access to bucket**: SOLO `ardy-backup` (non "All").
   - **Type of access**: **Read and Write**.
   - Salva subito **keyID** e **applicationKey** (la key si vede UNA volta).

> Una sola chiave per entrambi i gemelli va bene perché scrivono in prefissi diversi. Se vuoi
> isolamento totale, crea due chiavi (una per server) — ma con un solo bucket la chiave resta comunque
> a livello bucket, quindi il vero isolamento lo dà il **prefisso** + la disciplina dello script.

---

## 2. rclone — installazione (senza root) e configurazione

`rclone` è il coltellino svizzero per B2: un singolo binario, gira benissimo in user-space su cPanel.

```bash
# come utente micoperibg
cd ~
curl -O https://downloads.rclone.org/rclone-current-linux-amd64.zip
unzip rclone-current-linux-amd64.zip
mkdir -p ~/bin
cp rclone-*-linux-amd64/rclone ~/bin/rclone
chmod 755 ~/bin/rclone
rm -rf rclone-*-linux-amd64*
# assicurati che ~/bin sia nel PATH (di solito lo è); altrimenti usa il path pieno ~/bin/rclone
~/bin/rclone version
```

Configura il remote B2 **senza interazione** (backend nativo `b2`):

```bash
~/bin/rclone config create ardyb2 b2 \
  account "IL_TUO_keyID" \
  key "LA_TUA_applicationKey" \
  hard_delete false
# verifica:
~/bin/rclone lsd ardyb2:ardy-backup
```

> `hard_delete false` = quando un file cambia/sparisce, B2 ne tiene la **versione precedente** (poi
> potata dalla lifecycle a 30gg). È ciò che rende il `sync` dei media un vero backup e non uno specchio
> distruttivo.

I segreti **non vanno nel repo**. rclone li scrive in `~/.config/rclone/rclone.conf` (chmod 600).
Le credenziali DB le mettiamo in un file env separato (vedi §3).

---

## 3. File di ambiente (segreti fuori dal repo)

Crea `~/.ardy-backup.env` (chmod 600). **Diverso su ogni gemello**: cambia solo `ARDY_ENV`.

```bash
cat > ~/.ardy-backup.env <<'EOF'
# Identità di QUESTO ambiente: determina il prefisso B2. UNICO per server!
#   produzione -> srv-prod   |   staging/secondo -> srv-staging
ARDY_ENV="srv-prod"

# Percorsi
DOCROOT="/home/micoperibg/public_html/ardyagent.ardy-lab.it"

# Database (gli stessi valori che stanno in ardy-config.php)
DB_HOST="localhost"
DB_NAME="micoperibg_ardyagent"
DB_USER="micoperibg_xxxxx"
DB_PASS="xxxxxxxx"

# Remote rclone e bucket
B2_REMOTE="ardyb2:ardy-backup"

# Ritenzione dump DB (giorni) lato remoto
DB_KEEP_DAYS=30
EOF
chmod 600 ~/.ardy-backup.env
```

> Le credenziali DB le trovi in `ardy-config.php` (`ARDY_DB_USER`, `ARDY_DB_PASS`, `ARDY_DB_NAME`).
> In alternativa puoi creare un `~/.my.cnf` (chmod 600) e far leggere a `mysqldump` la password da lì,
> evitando di duplicarla — vedi nota nello script.

Struttura risultante su B2:

```
ardy-backup/
├── srv-prod/
│   ├── db/ardy-db-YYYYMMDD-HHMM.sql.gz   (dump datati, potati a DB_KEEP_DAYS)
│   ├── config/ardy-config.php  ...        (ultima copia, con versioni precedenti)
│   └── media/ardy-uploads|preventivi_pdf|reels/  (sync con versioni)
└── srv-staging/
    └── …  (identico, ambiente indipendente)
```

---

## 4. Script di BACKUP — `~/bin/ardy-backup.sh`

```bash
cat > ~/bin/ardy-backup.sh <<'SCRIPT'
#!/bin/bash
# Ardy — backup su Backblaze B2 (ambiente indipendente, prefisso = $ARDY_ENV)
set -euo pipefail

source "$HOME/.ardy-backup.env"
RCLONE="$HOME/bin/rclone"
STAMP="$(date +%Y%m%d-%H%M)"
DEST="$B2_REMOTE/$ARDY_ENV"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

log(){ echo "[$(date '+%F %T')] $*"; }

# 1) DUMP DATABASE -> gzip -> upload datato
log "Dump DB $DB_NAME ..."
mysqldump --single-transaction --quick --routines --triggers \
  -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  | gzip -9 > "$TMP/ardy-db-$STAMP.sql.gz"
# (alternativa senza password in chiaro: crea ~/.my.cnf e usa `mysqldump --defaults-file=~/.my.cnf $DB_NAME`)
log "Upload dump ..."
"$RCLONE" copy "$TMP/ardy-db-$STAMP.sql.gz" "$DEST/db/" --no-traverse

# 2) CONFIG & TOKEN (file singoli, fuori dal repo)
log "Backup config/token ..."
for f in ardy-config.php ardy-gcal-token.json ardy-gbp-location.json; do
  [ -f "$DOCROOT/$f" ] && "$RCLONE" copy "$DOCROOT/$f" "$DEST/config/" --no-traverse || true
done

# 3) MEDIA (sync: efficiente, trasferisce solo le differenze; versioni tenute da B2)
log "Sync media ..."
for d in ardy-uploads preventivi_pdf reels; do
  [ -d "$DOCROOT/$d" ] && "$RCLONE" sync "$DOCROOT/$d" "$DEST/media/$d" \
      --fast-list --transfers 8 --checkers 16 || true
done

# 4) POTATURA dump DB vecchi sul remoto (i media li pota la lifecycle del bucket)
log "Potatura dump > $DB_KEEP_DAYS giorni ..."
"$RCLONE" delete "$DEST/db/" --min-age "${DB_KEEP_DAYS}d"

log "Backup COMPLETATO -> $DEST"
SCRIPT
chmod 700 ~/bin/ardy-backup.sh
```

Primo run a mano (verifica che fili tutto):

```bash
~/bin/ardy-backup.sh
~/bin/rclone tree ardyb2:ardy-backup/srv-prod --level 2
```

---

## 5. Pianificazione (cron)

Su cPanel: **Cron Jobs** dalla UI, oppure `crontab -e` come `micoperibg`:

```cron
# Backup Ardy su B2 — ogni notte alle 03:30 (orario server)
30 3 * * * /home/micoperibg/bin/ardy-backup.sh >> /home/micoperibg/logs/ardy-backup.log 2>&1
```

Consiglio: dopo qualche giorno controlla il log e aggiungi un **alert** (es. una riga finale nello
script che manda un'email via Brevo se l'uscita è ≠ 0, oppure un ping a healthchecks.io). Un backup di
cui non sai se gira è un backup che non hai.

---

## 6. Verifica del backup (fallo, non darlo per scontato)

```bash
# elenco dump DB disponibili
~/bin/rclone lsl ardyb2:ardy-backup/srv-prod/db/

# scarica l'ultimo dump e controlla che sia integro
~/bin/rclone copy "ardyb2:ardy-backup/srv-prod/db/$(~/bin/rclone lsf ardyb2:ardy-backup/srv-prod/db/ | sort | tail -1)" /tmp/
gzip -t /tmp/ardy-db-*.sql.gz && echo "dump OK (gzip integro)"

# conteggio file media remoti vs locali (devono avvicinarsi)
~/bin/rclone size ardyb2:ardy-backup/srv-prod/media
du -sh ~/public_html/ardyagent.ardy-lab.it/{ardy-uploads,preventivi_pdf,reels}
```

---

## 7. RIPRISTINO — `~/bin/ardy-restore.sh`

> ⚠️ Il restore **sovrascrive** dati. Esegui i passi uno per volta, leggendo gli echo. Lo script
> ripristina **solo dal prefisso del proprio ambiente** (`$ARDY_ENV`): se devi ripristinare su questo
> server i dati dell'ALTRO gemello, è un'operazione **manuale e consapevole** (vedi §8).

```bash
cat > ~/bin/ardy-restore.sh <<'SCRIPT'
#!/bin/bash
# Ardy — ripristino da Backblaze B2 (prefisso = $ARDY_ENV del PROPRIO ambiente)
# Uso: ardy-restore.sh db        -> ripristina SOLO il database (ultimo dump)
#      ardy-restore.sh media     -> ripristina SOLO i media
#      ardy-restore.sh config    -> ripristina SOLO config/token
set -euo pipefail
source "$HOME/.ardy-backup.env"
RCLONE="$HOME/bin/rclone"
SRC="$B2_REMOTE/$ARDY_ENV"
WHAT="${1:-}"
log(){ echo "[$(date '+%F %T')] $*"; }

case "$WHAT" in
  db)
    LAST="$($RCLONE lsf "$SRC/db/" | sort | tail -1)"
    [ -z "$LAST" ] && { echo "Nessun dump trovato in $SRC/db"; exit 1; }
    log "Scarico $LAST ..."
    $RCLONE copy "$SRC/db/$LAST" /tmp/ --no-traverse
    echo ">>> STAI PER SOVRASCRIVERE il DB $DB_NAME con $LAST"
    read -r -p ">>> Scrivi 'SI' per procedere: " ok; [ "$ok" = "SI" ] || exit 1
    gunzip -c "/tmp/$LAST" | mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"
    log "DB ripristinato da $LAST"
    ;;
  media)
    echo ">>> Ripristino media in $DOCROOT (i file remoti vincono; i locali in più restano)"
    read -r -p ">>> Scrivi 'SI' per procedere: " ok; [ "$ok" = "SI" ] || exit 1
    for d in ardy-uploads preventivi_pdf reels; do
      $RCLONE copy "$SRC/media/$d" "$DOCROOT/$d" --transfers 8 --checkers 16 || true
    done
    log "Media ripristinati"
    ;;
  config)
    echo ">>> Ripristino config/token in $DOCROOT (SOVRASCRIVE i file esistenti)"
    read -r -p ">>> Scrivi 'SI' per procedere: " ok; [ "$ok" = "SI" ] || exit 1
    $RCLONE copy "$SRC/config/" "$DOCROOT/" --include "ardy-config.php" \
      --include "ardy-gcal-token.json" --include "ardy-gbp-location.json"
    log "Config/token ripristinati"
    ;;
  *)
    echo "Uso: $0 {db|media|config}"; exit 2;;
esac
SCRIPT
chmod 700 ~/bin/ardy-restore.sh
```

**Disaster recovery completo (server nuovo/pulito), ordine consigliato:**

```bash
# 1. Codice dal repo
cd ~/repositories && git clone <repo> ardyagent && cd ardyagent
./deploy.sh                     # popola il docroot (crea le tabelle via ardy-migrate.php)
# 2. Riporta i segreti, poi i dati
~/bin/ardy-restore.sh config    # ardy-config.php + token
~/bin/ardy-restore.sh db        # database (l'import sovrascrive le tabelle vuote)
~/bin/ardy-restore.sh media     # foto/video/reel/PDF
# 3. Verifica: dashboard, chat, webhook WhatsApp, preventivi PDF
```

> Nota: `deploy.sh` esegue `ardy-migrate.php` (schema). Se ripristini un dump che è già lo schema
> completo va benissimo: `ardy-migrate.php` è idempotente e non rifà DDL già presente.

---

## 8. I due gemelli — regole d'oro

1. **Un prefisso per server, mai condiviso.** Su prod `ARDY_ENV="srv-prod"`, sull'altro
   `ARDY_ENV="srv-staging"`. È l'unica differenza nei `.ardy-backup.env`.
2. **Ogni server ripristina dal SUO prefisso.** Lo script `ardy-restore.sh` legge `$ARDY_ENV`: non
   può toccare i backup dell'altro per sbaglio.
3. **Promozione/clonazione consapevole** (es. portare i dati di prod su staging per un test): è
   un'operazione manuale, fuori dallo script automatico. Esempio:
   ```bash
   # sul server STAGING, voglio i dati di PROD: leggo esplicitamente l'altro prefisso
   ~/bin/rclone copy ardyb2:ardy-backup/srv-prod/db/<dump> /tmp/
   gunzip -c /tmp/<dump> | mysql ... micoperibg_ardyagent   # DB di staging
   ~/bin/rclone copy ardyb2:ardy-backup/srv-prod/media "$DOCROOT/..."  # media
   ```
   Fallo solo quando **vuoi** che staging diventi una copia di prod. Mai automatizzarlo nel cron.
4. **Stessa app key OK** (scrivono in prefissi diversi). Se vuoi audit/isolamento più forte, fai due
   app key (una per server) e tienine traccia.

---

## 9. Sicurezza, costi, note

- **Segreti**: `rclone.conf` e `~/.ardy-backup.env` sono chmod 600, **fuori dal repo**. Lo script di
  backup contiene la password DB solo se NON usi `~/.my.cnf`: valuta `~/.my.cnf` per non averla in due posti.
- **Costi**: B2 ~ $6/TB-mese di storage; download (restore) ~ $0.01/GB ma i primi 3×storage/giorno sono
  gratis. Decine di GB ⇒ pochi euro/mese. La lifecycle a 30gg tiene a bada le versioni.
- **Crittografia client-side (opzionale)**: se vuoi che B2 non veda mai i dati in chiaro, crea un remote
  `crypt` sopra `ardyb2` (`rclone config` → tipo `crypt` → remote `ardyb2:ardy-backup`, passphrase
  custodita a parte) e punta gli script a `cryptb2:`. **Attenzione**: senza la passphrase i backup sono
  irrecuperabili — conservala in un password manager, NON sul server.
- **Anti-ransomware (opzionale, dopo)**: attiva **Object Lock** sul bucket (governance/compliance) così
  un attaccante con la chiave non può cancellare i backup entro la finestra di lock.
- **n8n (da root, opzionale ma consigliato)**: i workflow/credenziali stanno nel volume del container
  `n8n_app`. Backup tipico (da root):
  ```bash
  # esporta il volume in un tar, poi caricalo su B2 con rclone (configurato anche per root, o copia il file)
  docker run --rm -v n8n_data:/data -v /root/n8n-bk:/bk alpine \
    tar czf /bk/n8n-$(date +%F).tgz -C /data .
  ```
  (Il nome volume reale lo vedi con `docker volume ls | grep n8n`.) Restore: `tar xzf` nel volume a
  container fermo. ⚠️ vedi `ANALISI-FIREWALL-HOST.md` per i vincoli Docker/csf prima di toccare n8n.
- **WordPress (opzionale)**: se vuoi coprire anche il sito, aggiungi al backup un dump del suo DB e un
  `rclone sync` di `wp-content/uploads`. Tienilo in un sotto-prefisso `wp/` per non mischiarlo con Ardy.

---

## 10. Checklist rapida

- [ ] Bucket `ardy-backup` (Private, SSE-B2, lifecycle 30gg versioni)
- [ ] App key ristretta al bucket (Read+Write), keyID/appKey salvati
- [ ] `rclone` installato in `~/bin`, remote `ardyb2` creato e testato (`rclone lsd`)
- [ ] `~/.ardy-backup.env` compilato con `ARDY_ENV` UNICO per server (chmod 600)
- [ ] `~/bin/ardy-backup.sh` e `~/bin/ardy-restore.sh` creati (chmod 700)
- [ ] Primo backup a mano OK + verifica integrità dump (`gzip -t`)
- [ ] Cron notturno attivo con log in `~/logs/ardy-backup.log`
- [ ] **Prova di restore** su un DB/cartella di test (un backup non testato non è un backup)
- [ ] Stesso giro sul secondo gemello con `ARDY_ENV` diverso

---

## 11. VPS con WHM (AlmaLinux ~200 GB) — usa il backup NATIVO di cPanel verso B2

Il server è un **VPS con WHM/cPanel su AlmaLinux** (accesso **root** via WHM). Qui la strada migliore
**non** sono gli script rclone delle sezioni 2-7, ma il **backup integrato di cPanel** verso una
destinazione **"S3 Compatible"** — e **Backblaze B2 espone un endpoint S3-compatibile**. Vantaggi:
copre **interi account cPanel** (file + DB + email + config + cron), ha **scheduling e ritenzione**
nativi, e si **ripristina dalla UI di WHM**. Gli script rclone restano utili come copia *granulare*
solo-app (vedi fondo sezione).

### 11.1 Recupera l'endpoint S3 di B2
Nel pannello Backblaze, apri il bucket `ardy-backup` → voce **Endpoint**, es.
`s3.us-west-004.backblazeb2.com`. La parte `us-west-004` è la **region**. La tua **app key**:
il `keyID` fa da **Access Key ID** e l'`applicationKey` da **Secret Access Key**.

> ⚠️ Per l'uso S3-compatibile la app key deve poter **listare i bucket**: crea la chiave **senza**
> restringerla a un singolo bucket (oppure assicurati che il transport funzioni col bucket fissato —
> alcune versioni di cPanel richiedono `listBuckets`). Se la validazione in WHM fallisce, è quasi
> sempre questo.

### 11.2 Abilita i backup (WHM, come root)
1. WHM → **Backup → Backup Configuration**.
2. **Enable Backups: On**. Tipo: **Compressed** (o Incremental se vuoi più frequenza e meno spazio).
3. **Schedule & Retention**: scegli i giorni (es. Daily) e quante copie tenere (es. 7). Lo storage
   locale serve come *staging* prima dell'upload: con 200 GB sei larghissimo.
4. **Select Users**: almeno l'account **`micoperibg`** (l'app Ardy). Puoi includere tutti.

### 11.3 Crea la destinazione B2 (S3 Compatible)
WHM → **Backup → Backup Configuration → Additional Destinations** → tipo **S3 Compatible** → *Create*:

| Campo | Valore |
|---|---|
| Destination Name | `B2-ardy` |
| Bucket | `ardy-backup` |
| **Backup Directory** | **`srv-prod`** ← prefisso UNICO di questo server (l'altro gemello userà `srv-staging`) |
| Access Key ID | il **keyID** B2 |
| Secret Access Key | l'**applicationKey** B2 |
| S3 Host / Endpoint | `s3.us-west-004.backblazeb2.com` (il tuo) |
| Region | `us-west-004` (la tua) |
| Timeout | lascia il default |

Poi **Validate Destination**: deve dare verde. Spunta **Transfer System Backups** se vuoi anche i
backup di sistema. Conviene tenere **"Save backups locally too"** finché non ti fidi del transport.

### 11.4 Verifica e ripristino
- **Verifica:** WHM → **Backup → Backup User Selection / Backup Restoration**, oppure lancia a mano
  `/usr/local/cpanel/bin/backup --force` (root) e controlla `/var/cpanel/logs/cpbackup/`. Dopo il run,
  nel bucket B2 deve comparire `ardy-backup/srv-prod/...`.
- **Ripristino di un account:** WHM → **Backup → Backup Restoration** → scegli utente e data → Restore.
  In alternativa per un singolo file/DB: scarica l'archivio da B2 ed estrai solo ciò che serve.

### 11.5 I due gemelli con WHM
Stesso bucket, **Backup Directory diverso** per server: `srv-prod` su uno, `srv-staging` sull'altro.
Ognuno ripristina dal **proprio** prefisso. È l'unica accortezza per non mischiare i due ambienti.

### 11.6 Quando servono ANCORA gli script rclone (sez. 2-7)
Il backup cPanel è "a livello account" (granularità: l'intero utente, una volta al giorno). Tieni gli
script rclone se vuoi **in più**:
- backup **più frequente del solo DB** (es. ogni ora) tra un backup cPanel e l'altro;
- una copia **solo-media** indipendente dal formato proprietario cPanel, leggibile/ripristinabile ovunque;
- backup di **n8n** (volume Docker), che il backup cPanel **non** include — vedi §9.

In pratica: **WHM→B2 come backup principale** dell'account, **rclone** come rete di sicurezza mirata
su DB/media/n8n. Entrambi scrivono nello stesso bucket, in prefissi distinti per server.
