# Piano di migrazione — infrastruttura Ardy

**Data:** 2026-06-20 · **Stato:** bozza per la call con Edmondo (lun)
**Decisioni prese:** VPS (non dedicato) · **senza cPanel** · email solo-invio via Brevo ·
gestione server da terminale · Cloudflare confermato · **Backblaze B2** per media + backup.

> Obiettivo: uscire dal VPS attuale (sovradimensionato, con cPanel) verso un setup **leggero,
> economico e più sicuro**, integrando in modo naturale la **2FA dashboard (#1)** e il **backup
> off-site (#4)**. Nessuna urgenza: traffico basso fino a settembre → migrare con calma, prima di settembre.

---

## 1. Architettura target

```
                    Cloudflare (Free) + Zero Trust (Free)
                    ┌───────────────────────────────────────┐
   utenti pubblici  │  ardyagent.ardy-lab.it  (DNS-only)     │ → VPS  (webhook, chat, upload diretti)
   Michela/Andrea   │  dash.ardy-lab.it       (proxied+Access)│ → VPS  (dashboard, login 2FA email)
                    └───────────────────────────────────────┘
                                      │
                       VPS Linux (no pannello)
                       ├─ Caddy (reverse proxy + HTTPS automatico)
                       ├─ PHP-FPM 8.3  +  app Ardy
                       ├─ MariaDB (DB)
                       └─ Docker → n8n
                                      │
                       Backblaze B2 (object storage)
                       ├─ media: foto / video / reel / PDF  (upload diretti, fuori dal web server)
                       └─ backup: dump DB + file di config
```

**Perché questa forma:**
- I **media vanno su B2**, non sul disco del server → disco leggero (VPS piccolo basta), **niente più
  limite 100 MB di Cloudflare** (l'upload non passa dal web server), e i file sono **già off-site** = backup.
- La **dashboard su un sottodominio dedicato proxato** (`dash.ardy-lab.it`) → si può mettere **Cloudflare
  Access (2FA email, gratis)** su TUTTO quell'hostname, **senza** toccare webhook/chat pubblici che
  restano su `ardyagent.*` (DNS-only, upload diretti).

---

## 2. Cosa faceva cPanel → con cosa lo sostituiamo

| Funzione cPanel | Sostituto (no-panel) |
|---|---|
| Web server + PHP | **Caddy** (HTTPS automatico Let's Encrypt) + **PHP-FPM 8.3** |
| Certificati SSL | Automatici con Caddy |
| MySQL + phpMyAdmin | **MariaDB** + **Adminer** (1 file, solo quando serve) |
| Basic Auth (.htpasswd) | Resta identico (Caddy/Nginx lo supporta) — e su `dash.*` lo affianca **Cloudflare Access** |
| Cron job | **crontab** di sistema |
| DNS | Già su **Cloudflare** (nessun cambiamento) |
| Email caselle | **Nessuna casella attiva letta** (invio via Brevo). **Invio OK e autenticato**: DKIM Brevo configurato (`brevo1/brevo2._domainkey` ✅) + DMARC presente (`p=none`) → la migrazione **non tocca l'invio** (vive nei DNS Cloudflare). ⚠️ Resta solo l'**MX in ingresso** (`10 mx.ardy-lab.it` → vecchio server): alla migrazione **sostituire con Cloudflare Email Routing** (inoltro su Proton) **o rimuovere**, sennò la posta entrante rimbalza. |
| Backup | **Backblaze B2** (media + dump DB automatici) |
| Deploy | `git pull` + **`deploy.sh`** (già nel repo, da adattare) o GitHub Actions |

---

## 3. Stima costi mensili (EUR, da verificare al momento)

| Voce | Costo |
|---|---|
| VPS 4 vCPU / 8 GB / ~80 GB NVMe (Hetzner ~€8, Aruba/Serverplan ~€15–30) | **€8–30** |
| Backblaze B2 (decine di GB → ~$6/TB) | **~€1–5** |
| Cloudflare Free + Zero Trust Free (2FA inclusa) | **€0** |
| Licenza cPanel | **€0** (eliminata) |
| **Totale** | **~€10–35/mese** |

Confronto: dedicato €40–250/mese senza vantaggi reali per il tuo carico. cPanel da solo erano €15–45/mese.

---

## 4. Checklist di migrazione (per fasi, a basso rischio)

### Fase 0 — Preparazione (call con Edmondo)
- [ ] Scegliere provider VPS (Hetzner = miglior prezzo/UE; Aruba/Serverplan = IT).
- [x] Aprire account **Backblaze B2** = FATTO. ⚠️ I bucket di backup già esistono **per server**:
  `UGLmico` (Server 1) e `micoper` (Server 2). Per il nuovo VPS resta da creare il bucket **media**
  (`ardy-media`) con chiave app dedicata; per i dump DB del nuovo server si può riusare l'account B2.
- [ ] Decidere chi amministra il server nel quotidiano.

### Fase 1 — Nuovo server (nulla in produzione ancora)
- [ ] Provisioning VPS (Debian/Ubuntu LTS o AlmaLinux).
- [ ] Firewall host (csf come ora, oppure nftables/ufw) — porte 22/80/443; SSH a chiave.
- [ ] Installare Caddy + PHP-FPM 8.3 (con estensioni: pdo_mysql, gd, curl, mbstring, finfo, zip) + MariaDB + Docker.
- [ ] FFmpeg (serve a `ardy-crea-reel.php`).

### Fase 2 — App
- [ ] `git clone` del repo nella docroot.
- [ ] Ricreare **`ardy-config.php`** (non versionato) con tutte le costanti (vedi `SECURITY-AUDIT.md` §4).
- [ ] Permessi cartelle upload + `ardyHardenUploadDir` (no esecuzione script).
- [ ] n8n: portare `docker-compose.yml` con le env (vedi TODO §n8n) — segreti come variabili d'ambiente.

### Fase 3 — Dati
- [ ] Dump MySQL dal vecchio server → import in MariaDB nuova.
- [ ] Media: caricare su **B2** foto/video/reel/PDF esistenti; (passo successivo: app che legge/scrive su B2).
- [ ] Test completo su un hostname di prova prima del cutover.

### Fase 4 — Cutover (passaggio in produzione)
- [ ] Abbassare il TTL DNS su Cloudflare (qualche ora prima).
- [ ] Puntare i record DNS al nuovo IP. `ardyagent.*` resta **DNS-only**.
- [ ] Tenere il vecchio server acceso come **rete di sicurezza** per qualche giorno.
- [ ] Verifiche live: dashboard, chat pubblica, **webhook WhatsApp**, upload, preventivi PDF, n8n.

### Fase 5 — Sicurezza & ottimizzazioni (post-cutover)
- [ ] Creare `dash.ardy-lab.it` **proxato** → **Cloudflare Access** (codice email per Michela/Andrea) = **#1 fatto**.
- [ ] Spostare gli **upload diretti su B2** (così la dashboard può stare dietro Cloudflare senza limite 100 MB).
- [x] **Backup automatici off-site su B2 = #4 FATTO (24/06, sessione `ardy-infra`)** — anticipato sull'infra
  **attuale** OVH/cPanel (non si è aspettato il nuovo server): backup nativo **cPanel→B2** su **entrambi i VPS
  gemelli**, **bucket isolati per server** (Server 1 → `UGLmico`/prefisso `srv1`; Server 2 → `micoper`, chiave
  ristretta), lifecycle 30 gg + SSE-B2, destinazioni validate/abilitate. **n8n** (Server 2): volume
  `/opt/n8n/n8n_data` via rclone → `micoper/n8n/` (cron 04:00). Guida/script su repo `ardy-infra`
  (`claude/modest-cori-hc1w1l`). ⚠️ Sul **nuovo** VPS (no-panel) il backup andrà **rifatto** in chiave no-cPanel
  (dump DB cron + sync media), riusando lo stesso account/bucket B2. Dettagli e pending in `TODO-PROSSIMI-TASK.md`.
- [ ] **Email/MX**: sostituire l'MX attuale (`mx.ardy-lab.it` → vecchio server) con **Cloudflare Email Routing** (inoltro su Proton) o rimuoverlo. ✅ Invio Brevo già autenticato (DKIM/DMARC verificati) → non serve toccarlo.
- [ ] Spegnere/dismettere il vecchio VPS.

### Dati DNS rilevati (20/06, per riferimento)
- `ardyagent.ardy-lab.it` → IP origine **57.131.47.5** (DNS-only, IP esposto: da valutare se nascondere dietro CF dove non ci sono upload grandi).
- MX: `10 mx.ardy-lab.it` · SPF: `v=spf1 include:spf.webapps.net ~all` · TXT: brevo-code + 2× google-site-verification.
- **DKIM Brevo** ✅: `brevo1._domainkey`→`b1.ardy-lab-it.dkim.brevo.com`, `brevo2._domainkey`→`b2...`.
- **DMARC**: `v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com` (solo monitoraggio; in futuro valutare `quarantine`/`reject`).
- `dash.ardy-lab.it`: **non esistente** → libero per la dashboard dietro Access.

---

## 5. Rischi & rollback
- **Rollback**: finché il vecchio server è acceso, basta ripuntare il DNS indietro. Per questo si tiene
  attivo qualche giorno e si abbassa il TTL prima del cutover.
- **Punto delicato**: il **webhook WhatsApp** dopo il cambio IP — verificare subito che Meta consegni
  (il `WA_VERIFY_TOKEN` è già in `ardy-config.php`, vedi `SECURITY-AUDIT.md` §6).
- **n8n**: ricordare l'ordine env → restart → nodo (vedi TODO §n8n) e `N8N_BLOCK_ENV_ACCESS_IN_NODE=false`.

---

## 6. Nota strategica
Questa migrazione **non è solo un risparmio**: è l'occasione per chiudere *gratis e in modo pulito* le due
voci di sicurezza ancora aperte — **2FA dashboard (#1)** e **backup off-site (#4)** — che oggi, sull'infra
attuale, sarebbero forzature. Conviene farla **prima di settembre**, con calma.
