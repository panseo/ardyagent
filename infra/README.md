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
- **`setup-vps.sh`** — provisioning di un Debian/Ubuntu pulito: Caddy, PHP-FPM 8.3 +
  estensioni, MariaDB, Docker, FFmpeg, firewall base, utente applicativo. Da leggere ed
  eseguire a pezzi, non alla cieca.
- **`docker-compose.n8n.yml`** + **`n8n.env.example`** — n8n sul nuovo VPS, esposto solo su
  localhost (Caddy fa reverse proxy pubblico). Copiare `n8n.env.example` → `n8n.env`
  (non versionato) prima di avviarlo.
- **`deploy-vps.sh`** — variante di `../deploy.sh` senza assunzioni cPanel (path
  configurabile via `DEPLOYPATH`). `../deploy.sh` resta quello in uso sul server attuale
  finché non si fa il cutover: non va toccato per questo lavoro.

## Cosa manca per andare live (non automatizzabile da qui)

1. **Scegliere il provider VPS** (Fase 0 del piano — Hetzner vs Aruba/Serverplan, ancora aperto).
2. **Scrivere `ardy-config.php`** sul nuovo server — non versionato, contiene segreti: elenco
   costanti in `SECURITY-AUDIT.md` §4. Nessun valore va copiato in `infra/`.
3. **Hash della password Basic Auth** nel `Caddyfile` (`caddy hash-password --plaintext '...'`),
   al posto del placeholder che c'è ora.
4. **DNS/Cloudflare**: TTL, puntamento IP, MX (vedi §4 Fase 4 e nota email del piano) — fuori
   dalla portata di questa sessione.
5. **Dati** (Fase 3 del piano): dump MySQL dal vecchio server, media su B2 — vedi task "Media
   su Backblaze B2" in `TODO-PROSSIMI-TASK.md`.

## Un rischio emerso scrivendo il Caddyfile

`ardyHardenUploadDir()` (in `ardy-net.php`) protegge le cartelle di upload scrivendoci dentro
un `.htaccess` che blocca l'esecuzione di script — **Caddy non legge i `.htaccess`**, quindi
sotto Caddy quella funzione diventa silenziosamente un no-op per la parte web-server. Il
`Caddyfile` qui replica la stessa protezione a livello di config (blocco `@uploads_exec`): va
tenuto in sync a mano se cambia l'elenco cartelle o estensioni bloccate in
`ardyHardenUploadDir()`.
