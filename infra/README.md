# infra/ — materiale per il nuovo VPS (no-cPanel)

Artefatti per la Fase 1-2 di `PIANO-MIGRAZIONE.md` (il piano resta la fonte di verità su
decisioni e stato generale; questa cartella è solo "il come" tecnico). Non deployata da
`deploy.sh`/`deploy-vps.sh` (è sé stessa — escludersi da sé non avrebbe senso) e non toccata
da `.cpanel.yml`, che resta specifico del vecchio server.

**Niente qui è stato eseguito o collaudato**: questa sessione non ha accesso al VPS, a
Cloudflare né a Backblaze B2, quindi tutto è stato scritto a tavolino a partire da
`.htaccess`, `README.md` e `TODO-PROSSIMI-TASK.md` esistenti — va validato sul server reale
prima del cutover (Fase 3 del piano), non solo rivisto a occhio.

## Cosa c'è

- **`Caddyfile`** — traduzione 1:1 di `.htaccess` per Caddy: stesso elenco di file sotto
  Basic Auth, stesse librerie interne bloccate, stessi header di sicurezza, stessa rewrite
  per le immagini vendita WooCommerce. Da validare con `caddy validate` sul VPS.
- **`setup-vps.sh`** — provisioning di un Debian 13 pulito: Caddy, PHP-FPM 8.3 + estensioni,
  MariaDB (bind-address esplicito su localhost), Docker, FFmpeg, utente applicativo **con pool
  PHP-FPM dedicato** (non quello "www" di default) e cartella `ardy-private/` (700) fuori
  web-root per config e token. Più la parte "perimetro": firewall (`ufw`, con rate-limit su
  SSH), **hardening SSH** (solo chiave, niente root via password — con controllo di sicurezza
  che si rifiuta di procedere se non trova già una chiave), **fail2ban** (ban automatico su
  brute-force SSH) e **unattended-upgrades** (patch di sicurezza Debian automatiche, senza
  riavvio automatico). Da leggere ed eseguire a pezzi, non alla cieca.
- **`backup-b2.sh`** + **`ardy-backup.env.example`** — backup off-site su Backblaze B2 (dump DB
  + `ardy-private/`), installato da `setup-vps.sh` come cron giornaliero. Richiede un remoto
  `rclone` configurato e `/root/.ardy-backup.env` scritti a mano sul server (mai in questo
  repo). Copre lo stesso ruolo del backup integrato cPanel che c'è oggi (che qui non esiste).
- **`docker-compose.n8n.yml`** + **`n8n.env.example`** — n8n sul nuovo VPS, esposto solo su
  localhost (Caddy fa reverse proxy pubblico). Copiare `n8n.env.example` → `n8n.env`
  (non versionato) prima di avviarlo.
- **`deploy-vps.sh`** — variante di `../deploy.sh` senza assunzioni cPanel (path
  configurabile via `DEPLOYPATH`). `../deploy.sh` resta quello in uso sul server attuale
  finché non si fa il cutover: non va toccato per questo lavoro.

## Cosa manca per andare live (non automatizzabile da qui)

1. **Provider VPS**: in corso su Contabo (Cloud VPS 6, Debian 13) — vedi `PIANO-MIGRAZIONE.md` §4 Fase 0.
2. **Scrivere `ardy-config.php`** vero in `~ardy/ardy-private/` sul nuovo server (non in
   docroot) — non versionato, contiene segreti: elenco costanti in `SECURITY-AUDIT.md` §4.
   Nessun valore va copiato in `infra/`. In docroot resta solo il "ponte" di una riga (stesso
   schema del server attuale, vedi README.md "Config e token fuori da public_html"). I file
   sorgente per la Fase 3 sono in `~/ardy-private/` sul server attuale, non in `public_html`.
3. **Hash della password Basic Auth** nel `Caddyfile` (`caddy hash-password --plaintext '...'`),
   al posto del placeholder che c'è ora.
4. **DNS/Cloudflare**: TTL, puntamento IP, MX (vedi §4 Fase 4 e nota email del piano) — fuori
   dalla portata di questa sessione.
5. **Dati** (Fase 3 del piano): dump MySQL dal vecchio server, media su B2 — vedi task "Media
   su Backblaze B2" in `TODO-PROSSIMI-TASK.md`.
6. **Isolamento multi-sito**: `setup-vps.sh` crea un pool PHP-FPM dedicato per il gestionale.
   Qualunque altro sito/web app che finisca su questo VPS in futuro va con lo stesso schema
   (utente + pool propri) — è il rischio trovato sul server attuale, dove due WordPress girano
   con lo stesso utente cPanel del gestionale (in migrazione altrove, non in questo repo).
7. **Backup B2**: `backup-b2.sh` è pronto ma non testato — serve il remoto `rclone` verso B2 e
   un utente MySQL dedicato a permessi ridotti (vedi il file stesso e `ardy-backup.env.example`).
   Da lanciare a mano almeno una volta per verificare che il file arrivi davvero su B2, prima di
   fidarsi del cron.

## Un rischio emerso scrivendo il Caddyfile

`ardyHardenUploadDir()` (in `ardy-net.php`) protegge le cartelle di upload scrivendoci dentro
un `.htaccess` che blocca l'esecuzione di script — **Caddy non legge i `.htaccess`**, quindi
sotto Caddy quella funzione diventa silenziosamente un no-op per la parte web-server. Il
`Caddyfile` qui replica la stessa protezione a livello di config (blocco `@uploads_exec`): va
tenuto in sync a mano se cambia l'elenco cartelle o estensioni bloccate in
`ardyHardenUploadDir()`.
