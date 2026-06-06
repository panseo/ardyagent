# Ardy Lab — Sistema Ardy Agent

Sistema di gestione clienti, preventivi, agente AI e integrazioni social per **Ardy Lab** (Michela Panella).

---

## 🌐 URL e accessi

| Risorsa | URL |
|---|---|
| Dashboard Michela | `https://ardyagent.ardy-lab.it/ardy-michela-app.html` |
| Chatbot pubblico | `https://ardy-lab.it/ardy-agent/` |
| Widget lavorazione | Iniettato automaticamente su pagine categoria "Lavori in corso" (ID 102) |
| n8n Automazione | `https://n8n.ardy-lab.it` |
| VPS (WHM) | IP: `57.131.47.5` — accesso solo via WHM/cPanel |
| Database | `micoperibg_ardyagent` su `localhost` |

---

## 🗂 Struttura file

```
ardyagent.ardy-lab.it/
├── ardy-michela-app.html      # Dashboard principale Michela (CSS esterno)
├── ardy-michela-app.css       # CSS separato per la dashboard
├── ardy-preventivo.php        # Generatore preventivi PDF (mPDF)
├── ardy-proxy.php             # Proxy API Claude per chatbot pubblico
├── ardy-proxy-lavorazione.php # Proxy API Claude per widget lavorazione (con calendario)
├── ardy-widget-lavorazione.js # Widget chat contestuale per pagine lavorazione
├── ardy-verify-client.php     # Verifica identità cliente (telefono + wp_post_id)
├── ardy-whatsapp-webhook.php  # Webhook WhatsApp Cloud API
├── ardy-save-lead.php         # Salva lead dal chatbot nel DB
├── ardy-update-lead.php       # Aggiorna dati lead dalla dashboard
├── ardy-db.php                # Connessione DB condivisa
├── ardy-config.php            # ⚠️ NON in repo — credenziali DB
├── ardy-crm-api.php           # API CRM interna
├── ardy-gcal.php              # Integrazione Google Calendar
├── ardy-gcal-auth.php         # OAuth Google Calendar
├── ardy-gcal-token.json       # ⚠️ NON in repo — token OAuth
├── ardy-email-finder.php      # Ricerca email lead
├── ardy-outreach.html         # Tool outreach
├── ardy-outreach-api.php      # API outreach
├── ardy-pubblica-lavorazione.php  # Pubblica fase: pagina WP + immagine in evidenza + email cliente (NO social auto)
├── ardy-pubblica-social.php   # Pubblica sui social (passo manuale, separato) → webhook n8n
├── ardy-get-fasi.php          # API fasi lavorative
├── ardy-libreria-api.php      # API libreria fasi (DB, condivisa tra dispositivi)
├── ardy-crea-reel.php         # Genera reel MP4 9:16 dalle fasi (FFmpeg via proc_open + GD)
├── ardy-pubblica-reel.php     # Pubblica il reel sui social → webhook n8n (ramo Reels)
├── ardy-lista-musica.php      # Elenca le tracce in assets/reel-music/
├── ardy-guida-michela.html    # Guida d'uso dashboard (HTML stampabile) — linkata dalla dashboard
├── GUIDA-MICHELA.md           # Guida d'uso dashboard (versione testo)
├── ardy-unsubscribe.php       # Gestione unsubscribe email
├── ardy-rate-limit/           # ⚠️ NON in repo — rate limiting
├── ardy-system.txt            # Prompt sistema agente AI (chatbot pubblico)
├── assets/
│   ├── logo.png               # Logo Ardy Lab
│   └── reel-music/            # Tracce royalty-free per i reel (caricate sul server)
├── reels/                     # ⚠️ NON in repo — MP4 dei reel generati (auto-creata)
├── preventivi_pdf/            # ⚠️ NON in repo — PDF generati
├── ardy-uploads/              # ⚠️ NON in repo — foto lavorazioni
├── vendor/                    # ⚠️ NON in repo — dipendenze Composer
├── phpmailer/                 # Libreria PHPMailer
└── composer.json              # Dipendenze (mPDF)
```

---

## 🗄 Database

**Database:** `micoperibg_ardyagent`

### Tabelle principali

#### `clienti`
Lead e clienti con dati lavorazione.

```
id, session_id, nome, cognome, telefono, email,
servizio, mobile, zona, budget, indirizzo, stato, note,
data_followup, wp_post_id, wp_post_link, ip_address
```

Campi chiave per il widget lavorazione:
- `telefono` — usato per verifica identità cliente
- `wp_post_id` — collega il cliente al post WordPress della lavorazione

#### `preventivi`
Storico preventivi generati per ogni cliente.

```
id, session_id, numero, tipo, oggetto, cliente_nome, cliente_email,
note, condizioni, voci_json, subtotale, grand_total,
file_pdf, stato, data_emissione, data_scadenza, created_at, updated_at
```

#### `fasi`
Fasi di lavorazione pubblicate per ogni cliente (usate anche per generare il reel).

```
id, session_id, fase_nome, testo_breve, testo_generato, foto_urls (JSON), created_at
```

#### `libreria_fasi`
Libreria di frasi/fasi riutilizzabili nei preventivi. **Condivisa tra dispositivi**
(prima era in localStorage). Auto-creata e popolata con 12 default al primo avvio.

```
id (VARCHAR), nome, cat, descr, created_at, updated_at
```

---

## 🧩 Architettura sistema completa

```
[Chatbot pubblico ardy-lab.it]
        ↓ ardy-proxy.php (Claude API)
        ↓ ardy-save-lead.php
        ↓
[Database MySQL]
        ↑
[Dashboard Michela ardy-michela-app.html]
        ↓ ardy-update-lead.php (aggiorna stato/note)
        ↓ ardy-preventivo.php (genera PDF con mPDF)
        ↓ ardy-gcal.php (Google Calendar)

[Widget Lavorazione — pagine "Lavori in corso"]
        ↓ ardy-verify-client.php (verifica telefono)
        ↓ ardy-proxy-lavorazione.php (Claude API + tool calendario)
        ↓ ardy-gcal.php (prenotazione visite laboratorio)

[Pubblicazione Lavorazioni]
        ↓ ardy-pubblica-lavorazione.php
        ↓ WordPress (crea/aggiorna post + immagine in evidenza = prima foto)
        ↓ Email cliente (PHPMailer + Brevo SMTP)
        ↓ Salva la fase nella tabella `fasi` (foto incluse)
        ↓ (i social NON partono in automatico)

[Pubblicazione Social — manuale dalla dashboard]
        ↓ Michela rivede/modifica il testo → pubblica ora / salva per dopo / salta
        ↓ ardy-pubblica-social.php
        ↓ Webhook n8n → Facebook + Instagram

[Reel finale — a lavoro concluso]
        ↓ ardy-crea-reel.php (FFmpeg via proc_open + testi con GD)
        ↓   monta MP4 9:16: titolo + foto delle fasi con didascalia + Prima/Dopo
        ↓   genera caption automatica (Claude), modificabile in dashboard
        ↓ ardy-pubblica-reel.php
        ↓ Webhook n8n (ramo Reels) → Instagram Reels / Facebook

[n8n — Automazione Social]
        ↓ Webhook riceve dati da pubblica-lavorazione
        ↓ Nodo Code JS → Facebook Graph API (pagina Ardy)
        ↓ Nodo Code JS → Instagram API (ardy.lab)

[WhatsApp — In costruzione]
        ↓ ardy-whatsapp-webhook.php (riceve messaggi)
        ↓ n8n (elaborazione)
        ↓ Richiede SIM dedicata per Cloud API
```

---

## 📣 Integrazioni social e canali

| Canale | Stato | Note |
|---|---|---|
| **Facebook** | ✅ Funzionante | Pagina "Ardy" (ID: 376551605541671) — pubblica via n8n nodo Code |
| **Instagram** | ✅ Funzionante | Account "ardy.lab" (ID: 17841404189479259) — pubblica via n8n |
| **Google Business** | ⏳ In attesa | Quota API da aumentare — richiesta inviata |
| **WhatsApp Business** | 🔧 In costruzione | App ArdyagentWA creata, webhook configurato. Serve SIM dedicata per Cloud API |
| **n8n** | ✅ Attivo | `n8n.ardy-lab.it` — Docker su VPS OVH |
| **LinkedIn** | 🔧 Da connettere | Per outreach B2B |

### Token e credenziali social

| Risorsa | Valore |
|---|---|
| App Ardyagent (FB/IG) | ID: `1833344328071968` |
| App ArdyagentWA (WhatsApp) | ID: `1738050524281722` |
| Pagina Facebook "Ardy" | ID: `376551605541671` |
| Instagram "ardy.lab" | ID: `17841404189479259` |
| Business Portfolio | ID: `401717014856618` |
| Page Token permanente | Salvato nel nodo Code n8n (non scade) |
| WhatsApp Business Account | ID: `723699887462335` |
| WhatsApp Phone Number | ID: `840536135818910` (+39 351 967 7973) |

### Workflow n8n "Meta"

**Ramo post-foto** (esistente):
```
[Webhook POST]
    ↓ riceve: testo, immagini[], fase, mobile, post_link
    ↓
[Nodo Code in JavaScript]
    ↓ Pubblica su Facebook (testo → /feed)
    ↓ Pubblica su Instagram (se immagine: /media → /media_publish)
    ↓ Usa this.helpers.httpRequest (il nodo HTTP Request standard ha problemi di timeout)
```

**Ramo Reels** (Webhook1 — path `ecf74cbf-…`):
```
[Webhook1 POST]  ← da ardy-pubblica-reel.php
    ↓ riceve: tipo=reel, reel_url, caption, mobile, cliente, session_id
    ↓
[HTTP Request] POST /v21.0/{ig-id}/media
    ↓ media_type=REELS, video_url={{ $json.body.reel_url }}, caption={{ $json.body.caption }}
    ↓ → restituisce un container id
[Wait] ~60s (elaborazione video lato Instagram)
    ↓
[HTTP Request] POST /v21.0/{ig-id}/media_publish
    ↓ creation_id={{ $json.id }}
```
> Nota: `media_type` deve essere **REELS** (maiuscolo). Attenzione a non lasciare
> spazi/tab nei valori `video_url`/`caption`. Il nodo `media_publish` si tiene
> disattivato durante i test per non pubblicare davvero.

---

## 🪑 Widget Chat Lavorazione

Chat contestuale integrata nelle pagine di avanzamento lavoro su WordPress.

### File
- `ardy-widget-lavorazione.js` — widget frontend (bottone, verifica, chat)
- `ardy-proxy-lavorazione.php` — proxy Claude con tool calendario
- `ardy-verify-client.php` — verifica identità cliente

### Flusso
1. Pagina WordPress categoria "Lavori in corso" (ID 102)
2. Snippet Divi inietta il widget + box informativo
3. Cliente clicca 🪑 → schermata verifica telefono
4. Telefono verificato contro DB (`clienti.telefono` + `clienti.wp_post_id`)
5. Chat aperta → Claude risponde nel contesto della lavorazione
6. Può prenotare visita in laboratorio (tool calendario)

### Regole visite laboratorio
- Finestra: da domani a max 3 giorni
- Durata: 30 minuti
- Orari: lun-ven 9-18, sabato 9-13
- Se laboratorio chiuso (cantiere esterno): Michela blocca i giorni su Google Calendar → il sistema non propone slot
- Formula: "ma non oltre per motivi operativi"

### Prompt (in ardy-proxy-lavorazione.php)
- Spiega le fasi in modo semplice
- MAI promettere date di consegna
- Modifiche: raccogli e rimanda a Michela
- Prezzi: rimanda a Michela
- Reclami: empatia + segnala a Michela

### Snippet Divi (Opzioni tema → Integrazione → Parte inferiore post)
Inietta automaticamente:
- Box informativo dorato che spiega al cliente come usare l'assistente
- Caricamento widget JS (solo su pagine categoria 102)

---

## 📄 Generatore Preventivi PDF

**File:** `ardy-preventivo.php`
**Libreria:** mPDF (installata via Composer in `vendor/`)

### Endpoint

| Mode | Metodo | Descrizione |
|---|---|---|
| `?mode=preview` | POST | Restituisce HTML anteprima |
| `?mode=save` | POST | Genera PDF, salva su disco e nel DB |
| `?mode=download&file=X` | GET | Scarica PDF già generato |
| `?mode=lista&session_id=X` | GET | Lista preventivi di un cliente |
| `?mode=stato&id=X&stato=Y` | GET | Aggiorna stato preventivo |

### Regime fiscale
Michela è in **regime forfettario** — IVA sempre 0%, dicitura legale automatica.

### Dati azienda fissi
```
Ardy di Michela Panella
Via Kafka 14, 00143 Roma (RM)
P.IVA: 17633931005
C.F.: PNLMHL99A48H501E
Email: ardy.documenti@gmail.com
Web: www.ardy-lab.it
```

---

## 💰 Generatore Proforma

Integrato nella dashboard Michela. Genera documento proforma in formato identico a QuikFisco.

### Tre scenari
1. **Prenotazione** (€50-100) — quota non rimborsabile per riservare il lavoro
2. **Acconto 50%** — metà del preventivo, con riferimento al preventivo
3. **Saldo a consegna** — importo restante

### Funzionalità
- Selezione scenario con precompilazione automatica
- Dati cliente precompilati dal lead
- Campi CF/SDI per fatturazione
- Tabella voci editabile
- Marca da bollo €2 automatica
- IVA 0% regime forfettario
- Clausole specifiche per scenario
- Anteprima HTML in nuova finestra
- Stampa / Salva PDF dal browser
- Numerazione progressiva PRF-ANNO-NNNN

---

## 🖥 Dashboard Michela (ardy-michela-app.html)

Single-file HTML con CSS esterno (`ardy-michela-app.css`).

### Funzionalità
- Lista clienti/lead con filtri per stato e ricerca
- Dettaglio cliente con **tutti i campi modificabili** (nome, cognome, telefono, email, servizio, zona, mobile, budget, indirizzo, note, follow-up)
- Cambio stato cliente (Lead → Sopralluogo → Preventivo → Acconto → Standby → Perso)
- Azioni rapide: contenuto AI, post social, **proforma**, email, WhatsApp, note interne
- **Generatore preventivi PDF** con form completo
- **Generatore proforma** con 3 scenari
- **Storico preventivi** per cliente (dal DB)
- **Libreria fasi lavorative** (DB, **condivisa tra dispositivi**) con 12 fasi predefinite
- **Pubblicazione fasi** con foto (scatta dal telefono o galleria); la prima foto diventa l'**immagine in evidenza** del post
- **Pubblicazione social manuale**: dopo la fase, pannello per rivedere/modificare il post e scegliere *pubblica ora / salva per dopo / non pubblicare*; coda "post in attesa" (localStorage)
- **Reel finale**: a lavoro concluso monta un video 9:16 dalle fasi (titolo + didascalie + Prima/Dopo), con scelta musica, caption automatica modificabile e pubblicazione sui social
- Pulsante **❓ Guida** che apre la guida d'uso
- Aggiunta manuale clienti

### Stati cliente
`LEAD` → `SOPRALLUOGO` → `PREVENTIVO` → `ACCONTO` → `STANDBY` → `PERSO`

---

## 📱 WhatsApp (in costruzione)

### Stato attuale
- App **ArdyagentWA** creata su Meta Developers
- Business Portfolio verificato
- Webhook configurato: `https://ardyagent.ardy-lab.it/ardy-whatsapp-webhook.php`
- Token verifica: `ardy_wa_verify_2026`
- Numero Michela (+39 351 967 7973) registrato come ON_PREMISE

### Blocco attuale
Il numero di Michela è usato sull'app WhatsApp Business del telefono. Per Cloud API serve un **numero dedicato** (seconda SIM). Registrare il numero di Michela sulla Cloud API disconnetterebbe l'app dal telefono.

### Architettura prevista (da completare con SIM dedicata)
```
[Cliente scrive su WhatsApp]
    ↓ Meta Cloud API
    ↓ ardy-whatsapp-webhook.php
    ↓ n8n webhook
    ↓ Check numero in DB clienti
    ↓
    ├─ NON trovato → Modalità Lead (come chatbot sito)
    ├─ Trovato con lavorazione → Modalità Cliente (stato lavoro, visite)
    └─ Trovato senza lavorazione → Cliente passato (tratta con familiarità)
```

### Webhook (ardy-whatsapp-webhook.php)
- GET: gestisce verifica Meta (challenge)
- POST: riceve messaggi, estrae mittente/testo, inoltra a n8n
- Log in `ardy-wa-log.json` (ultimi 100 messaggi)

---

## ⚙️ Configurazione server

**VPS:** OVH AlmaLinux — gestito via WHM/cPanel
**Accesso:** Solo WHM — SSH e FTP disabilitati per sicurezza
**PHP:** In PHP-FPM (web) sono disabilitate `exec`, `shell_exec`, `system` ma **`proc_open` è attiva** → per i PDF si usa mPDF; per FFmpeg (reel) si usa `proc_open` (vedi `ardy-crea-reel.php`)
**FFmpeg:** build statico in `/usr/local/bin/ffmpeg` (johnvansickle 7.0.2). Il filtro `drawtext` NON è incluso → i testi del reel si disegnano con **GD/FreeType** (font `dejavu-sans-fonts`)
**Composer:** Installato in `/home/micoperibg/public_html/ardyagent.ardy-lab.it/`

### n8n
- Installazione: Docker
- Container network: `n8n_default` (IP: 172.18.0.2)
- Binding: `127.0.0.1:5678` (reverse proxy via Apache/Cloudflare)
- Nota: il nodo HTTP Request standard non funziona (timeout). Usare **nodo Code** con `this.helpers.httpRequest`

### DNS (Cloudflare)
- Nameserver: Cloudflare (`bradley.ns.cloudflare.com`, `surina.ns.cloudflare.com`)
- Record MX: `mx.ardy-lab.it` → IP Aruba (62.149.128.151/154/157/160)
- Email: gestita da Aruba, riceve su `marketing@ardy-lab.it`
- SMTP invio: Brevo (DKIM configurato)

### File da creare manualmente sul server (NON in repo)
- `ardy-config.php` — credenziali DB + API keys
- `ardy-gcal-token.json` — token Google Calendar
- Cartella `preventivi_pdf/` con permessi 755
- Cartella `ardy-uploads/` con permessi 755

---

## 🔄 Workflow aggiornamenti

### Dal PC Debian (repo locale su hard disk esterno)
```bash
cd /media/bebo/Archivio/progetti/ardyagent
git add -A
git commit -m "descrizione modifica"
git push origin main
```

### Deploy sul server
Dopo il push, caricare manualmente i file modificati via **cPanel File Manager**.

---

## 📦 Dipendenze

| Libreria | Versione | Uso |
|---|---|---|
| mPDF | ^8.3 | Generazione PDF da HTML |
| PHPMailer | locale | Invio email |
| Claude API | claude-sonnet-4-6 | Chatbot, widget lavorazione, generazione testi, caption reel |
| FFmpeg | 7.0.2 static | Montaggio reel video (via proc_open) |
| GD + FreeType | PHP ext | Testi/didascalie sulle slide del reel |

---

## 🚧 TODO / Sviluppi futuri

### WhatsApp
- [ ] Acquistare SIM dedicata per Cloud API
- [ ] Registrare numero sulla Cloud API
- [ ] Creare workflow n8n per WhatsApp
- [ ] Scrivere prompt WhatsApp (Modalità Lead + Modalità Cliente)
- [ ] Test end-to-end

### Dashboard Michela
- [ ] Layout PDF da rifinire graficamente
- [ ] Pagina "I nostri lavori" con foto portfolio nel PDF
- [ ] Invio email automatico preventivo al cliente
- [ ] Fix chatbot pubblico (`ardy-proxy.php` — errore API)
- [ ] Render AI mobile per preventivi (fase B — Stable Diffusion)
- [ ] Bottone rapido "Blocca giorni laboratorio" per Google Calendar

### Integrazioni social
- [ ] **Google Business** — attendere aumento quote API, poi configurare nodo n8n
- [ ] **Instagram** — collegare a pagina Ardy dopo 9 giugno (attesa 7gg Meta)
- [ ] **LinkedIn** — integrare per outreach B2B

### Ardy Outreach
- [ ] Completare dashboard `ardy-outreach.html`
- [ ] Collegare flussi n8n per invio newsletter automatiche
- [ ] Integrare tracciamento aperture email
- [ ] Test end-to-end campagna outreach B2B

### Infrastruttura
- [ ] Migrazione server (pianificata — da definire tempistiche)
- [ ] Automatizzare deploy da GitHub al server (ora manuale via cPanel)

---

## 📝 Note sessioni

**Giugno 2026 — Sessione 4**
- **Libreria fasi nel DB** (`ardy-libreria-api.php`, tabella `libreria_fasi`): condivisa tra telefono e computer; era in localStorage. Auto-creazione tabella + seeding 12 default.
- **Immagine in evidenza**: la prima foto del lavoro diventa la copertina del post WordPress (anteprima nel modulo DIVI Blog in home); non viene più duplicata nell'editor.
- **Reel finale** (`ardy-crea-reel.php`): video MP4 9:16 da tutte le fasi pubblicate — slide-titolo (mobile + logo), foto con didascalia fase, slide finale Prima/Dopo. Scelta musica da `assets/reel-music/`.
  - FFmpeg statico installato (`/usr/local/bin/ffmpeg`); chiamate via **proc_open** (exec disabilitata in FPM); testi con **GD** (drawtext non incluso nel build).
  - Montaggio robusto: una mini-clip per slide + concat (il concat di immagini mostrava una sola foto).
  - **Caption automatica** con Claude, modificabile in dashboard.
- **Pubblicazione reel** (`ardy-pubblica-reel.php`): invio `reel_url` + caption al webhook n8n; nuovo **ramo Reels** nel workflow "Meta" (Webhook1 → /media REELS → Wait → /media_publish).

**Giugno 2026 — Sessione 1**
Costruita dashboard completa, generatore PDF con mPDF, libreria fasi, storico preventivi su DB, fix doppio salvataggio, bottoni sidebar, manuale utente Word.

**Giugno 2026 — Sessione 3**
- Tasto "Scatta foto" diretto nella dashboard lavorazioni (mobile)
- **Sicurezza**: login Basic Auth su pagine/endpoint privati; hardening endpoint pubblici (save-lead, verify-client con rate limit, webhook WhatsApp con firma); anti-XSS dashboard; link disiscrizione firmato (HMAC); limiti/retry sui proxy AI; messaggi d'errore generici; fuso orario Europe/Rome
- Chatbot: telefono ora raccolto sempre; email di conferma sopralluogo al cliente
- Dashboard: campi cliente modificabili e salvabili
- Widget fasi: il nome verificato viene passato all'AI (niente più richiesta del nome già noto)
- n8n: nodo Code Meta corretto (foto su Facebook, Instagram con attesa contenitore)
- **Social manuale**: `ardy-pubblica-social.php` + pannello dashboard (pubblica ora / salva per dopo / modifica / salta)
- Guida d'uso per Michela (`GUIDA-MICHELA.md`, `ardy-guida-michela.html`, PDF) + pulsante Guida in dashboard

**Giugno 2026 — Sessione 2**
- App Ardyagent creata su Meta Developers (verifica Business completata)
- App ArdyagentWA creata per WhatsApp
- Integrazione Facebook: pagina "Ardy" pubblica da n8n via nodo Code
- Integrazione Instagram: account "ardy.lab" pubblica da n8n
- Token permanenti generati per FB/IG
- Widget chat lavorazione con verifica cliente (telefono) e prenotazione visite laboratorio (Google Calendar)
- Generatore proforma con 3 scenari (Prenotazione/Acconto/Saldo)
- CSS dashboard separato in file esterno
- Fix record MX Cloudflare/Aruba per ricezione email
- Webhook WhatsApp configurato e verificato
- Snippet Divi per box informativo + caricamento widget su pagine lavorazione
