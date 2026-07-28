# Ardy Lab — Sistema Ardy Agent

Sistema di gestione clienti, preventivi, agente AI e integrazioni social per **Ardy Lab** (Michela Panella).

---

## 🌐 URL e accessi

| Risorsa | URL |
|---|---|
| Dashboard (Michela + Andrea) | `https://ardyagent.ardy-lab.it` — la root del dominio apre direttamente la dashboard (`DirectoryIndex ardy-michela-app.html`) |
| Chatbot pubblico | `https://ardy-lab.it/ardy-agent/` |
| Widget lavorazione | Iniettato automaticamente su pagine categoria "Lavori in corso" (slug `lavori-in-corso`) |
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
├── ardy-chat-site.js          # Chat generale del sito /ardy-agent/ (servita dal server; in WPCode solo loader)
├── ardy-chat-corsi.js         # Chat in "modalità corso" /ardy-agent/?corso= (servita dal server; in WPCode solo loader)
├── ardy-chat-experience.js    # Webchat "Galleria Diffusa" per i B&B partner /galleria-diffusa (servita dal server; in WPCode solo loader)
├── ardy-chat-interior-design.js # Webchat "Consulenza Interior Design" /interior-design (servita dal server; in WPCode solo loader)
├── ardy-widget-lavorazione.js # Widget chat contestuale per pagine lavorazione
├── ardy-verify-client.php     # Verifica identità cliente (telefono + wp_post_id)
├── ardy-whatsapp-webhook.php  # Webhook WhatsApp Cloud API (estrae il media id delle foto; salva ogni messaggio in arrivo in wa_messaggi via ardy-wa-store → risposte visibili in dash anche senza n8n)
├── ardy-wa-agent.php          # Cervello WhatsApp lato cliente: loop agentico con tool calendario/lead/sposta + email + ricezione/valutazione foto
├── ardy-wa-crea-scheda.php    # Crea la scheda lead nel CRM da WhatsApp (usa il numero WA se manca il telefono)
├── ardy-sanitize.php          # Rete anti-sbrodolatura: ripulisce eventuale sintassi tool trapelata come testo
├── ardy-fasi-bozza-api.php    # API bozze fasi di lavorazione; `mode:'salva'` = bozza completa con foto (salva senza pubblicare)
├── ardy-save-lead.php         # Salva lead dal chatbot nel DB
├── ardy-update-lead.php       # Aggiorna dati lead dalla dashboard (no invio email: la conferma consegna è manuale via Trasporti)
├── ardy-sopralluoghi-api.php  # API sopralluoghi MULTIPLI per cliente (lista/salva/elimina) — dashboard
├── ardy-sopralluoghi-lib.php  # Logica condivisa sopralluoghi (calendario + "mirror") usata da API e da Sole WhatsApp
├── ardy-db.php                # Connessione DB condivisa
├── ardy-config.php            # ⚠️ NON in repo — credenziali DB
├── ardy-crm-api.php           # API CRM interna
├── ardy-dossier.php           # Dossier cliente in Markdown (clienti+preventivi+fasi+chat WA+chat web) per Sole/Michela
├── ardy-web-memoria.php       # Libreria: persistenza chat web (tabella web_messaggi) — usata da proxy e dossier
├── ardy-wa-store.php          # Libreria: salvataggio IDEMPOTENTE in wa_messaggi (webhook + n8n + notifiche dash). Dedup per wa_msg_id (riconsegne Meta) o per contenuto (no doppioni n8n)
├── ardy-conoscenza-appresa.php # Autoapprendimento di Sole: distilla conoscenza anonima dalle fasi (tabella conoscenza_appresa) — libreria + endpoint dashboard
├── ardy-grazie-consegna.php   # Ringraziamento alla consegna: email (Brevo) + WhatsApp (template) — recensione/social/newsletter
├── ardy-trasporti.php         # Consegne/ritiri: email "è pronto" (→COMPLETATO) + giornata Trasporti (libreria + endpoint dashboard)
├── ardy-import-preventivi.php # Import temporaneo preventivi storici da CSV (migrazione una-tantum)
├── ardy-import-scheda-pdf.php # Importa una scheda cliente da un PDF (estrazione AI) → dashboard
├── ardy-template-scheda-cliente.html # Modello PDF "Scheda Cliente" (etichette fisse, fillable)
├── ardy-gcal.php              # Integrazione Google Calendar
├── ardy-gcal-auth.php         # OAuth Google Calendar
├── ardy-gcal-token.json       # ⚠️ NON in repo — token OAuth
├── ardy-email-finder.php      # Ricerca email lead
├── ardy-outreach.html         # Tool outreach
├── ardy-outreach-api.php      # API outreach
├── ardy-pubblica-lavorazione.php  # Pubblica fase: pagina WP + immagine in evidenza + email cliente (NO social auto)
├── ardy-pubblica-social.php   # Pubblica sui social (passo manuale, separato) → webhook n8n
├── ardy-social-bozze.php      # Bozze post social "salva per dopo" persistite in DB (tabella social_bozze) — multi-dispositivo
├── ardy-social-foto.php       # Carica una foto del post social → URL pubblico WP Media (serve a IG/FB via API)
├── ardy-conversazioni.php     # Conversazione unificata di un cliente (WA + chat sito) per la scheda; aggiorna il marker "conversazione vista"
├── ardy-get-fasi.php          # API fasi lavorative
├── ardy-libreria-api.php      # API libreria fasi (DB, condivisa tra dispositivi)
├── ardy-crea-reel.php         # Genera reel MP4 9:16 dalle fasi (FFmpeg via proc_open + GD)
├── ardy-pubblica-reel.php     # Pubblica il reel sui social → webhook n8n (ramo Reels)
├── ardy-reel-template-api.php # API libreria template di stile reel (DB)
├── ardy-lista-musica.php      # Elenca le tracce in assets/reel-music/
├── ardy-guida-michela.html    # Guida d'uso dashboard (HTML stampabile) — linkata dalla dashboard
├── ardy-migrate.php           # Migrazione schema DB (idempotente) — UNICO posto con DDL, girato da deploy.sh
├── ardy-health.php            # Cruscotto salute produzione (DB, integrazioni, token Google, errori PHP, disco) — HTML semaforo + JSON (?format=json) per monitor esterni; linkato dalla dashboard (🩺 STATO)
├── MANUALE-SOLE.md            # Mansionario dell'assistente AI Sole (canali, mansioni, regole)
├── ardy-notifica-michela.php  # Notifiche WhatsApp a Michela (Sole "segretaria") — libreria + endpoint n8n
├── ardy-chiusura-sessioni.php # Cron orario: notifica Michela alla "chiusura" chat (inattive >1h, web+WA)
├── ardy-solleciti.php         # Solleciti clienti morosi (4 livelli) + invio WA/email — "segretaria antipatica"
├── ardy-solleciti-system.txt  # Prompt AI "segretaria antipatica" per i solleciti
├── ardy-unsubscribe.php       # Gestione unsubscribe email
├── ardy-rate-limit/           # ⚠️ NON in repo — rate limiting
├── ardy-system.txt            # Prompt sistema agente AI (chatbot pubblico)
├── wordpress-snippets/        # Backup degli snippet WPCode/Divi (NON deployata) — vedi README interno
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

> **Schema gestito da `ardy-migrate.php`** (girato da `deploy.sh` al deploy). Tutte le tabelle e
> colonne elencate qui sotto sono create/alterate lì, una volta sola — non più con DDL inline ad ogni
> request HTTP. Migrazione idempotente. Nuova tabella/colonna → aggiungerla a `ardy-migrate.php`.

### Tabelle principali

#### `clienti`
Lead e clienti con dati lavorazione.

```
id, session_id, nome, cognome, telefono, email,
servizio, mobile, zona, budget, indirizzo, stato, note,
data_followup, wp_post_id, wp_post_link, ip_address,
sopralluogo_at (data/ora reale del sopralluogo), gcal_event_id (id evento Google Calendar)
```
> `sopralluogo_at` + `gcal_event_id` salvano la data vera dell'appuntamento e
> l'id dell'evento. ⚠️ Da giu 2026 un cliente può avere **più sopralluoghi** (tabella `sopralluoghi`):
> questi due campi su `clienti` restano come **"mirror" del sopralluogo PIÙ VICINO** (allineati
> automaticamente da `ardy-sopralluoghi-lib.php`), così i flussi che leggono ancora i vecchi campi
> (es. tool cliente su WhatsApp, riepiloghi) continuano a funzionare. Vedi la tabella `sopralluoghi`.

Altre colonne (gestite da `ardy-migrate.php`): `inizio_lavoro`, `fine_lavoro_prevista` (date dell'intero
lavoro → pallini in lista), `note_consegna` (promemoria consegna), `deleted_at` (cestino soft-delete),
`conversazione_letta_at` (marker "conversazione vista": quando Michela/Andrea apre la chat del cliente
si salva NOW() → spegne il badge **💬 ha risposto** finché il cliente non riscrive).

Consulenza Interior Design *(lug 2026)*: `interior_design_attivo` (0/1 — accende la sezione dedicata
nella scheda), `interior_design_attivato_da` (`sole` se l'ha attivata Sole dalla webchat, `manuale` se
Andrea/Michela dal bottone in dashboard), `interior_design_attivato_at`, più i dati raccolti in chat:
`interior_design_stile`, `interior_design_colori`, `interior_design_luce`, `interior_design_budget`,
`interior_design_note`.

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
id, session_id, fase_nome, fase_tipo ('fase'|'comunicazione'), testo_breve, testo_generato,
foto_urls (JSON), video_urls (JSON), created_at
```
> `fase_tipo='comunicazione'` distingue le **comunicazioni straordinarie** (imprevisti segnalati
> al cliente prima di procedere) dalle normali fasi di avanzamento. Le comunicazioni non entrano nel reel.

#### `sopralluoghi`  *(giu 2026)*
**Più sopralluoghi per cliente** (1°, 2°, sopralluogo colori…), ognuno con la sua data/ora ed evento
Google Calendar. Gestita da `ardy-sopralluoghi-lib.php` (logica condivisa) via `ardy-sopralluoghi-api.php`
(dashboard, sezione "📅 Sopralluoghi" nella scheda) e dai tool staff di Sole su WhatsApp.

```
id, session_id, data_ora, etichetta, note, gcal_event_id, created_at, updated_at
```
> La lib fa anche: **mirror** (riallinea `clienti.sopralluogo_at`/`gcal_event_id` al sopralluogo più
> vicino dopo ogni modifica) e **riconciliazione "pigra"** (un sopralluogo fissato dal canale cliente,
> presente solo nei vecchi campi, viene inserito come riga alla prima lettura → compare nella lista).

#### `note_staff`  *(giu 2026)*
**Nota settimanale "cose da fare"** condivisa tra Michela e Andrea: la dettano a Sole su WhatsApp
(tool `salva_nota_settimanale`/`leggi_nota_settimanale`), Sole la memorizza e la rilegge/aggiorna. Ogni
salvataggio è una riga nuova (storico per settimana); si legge sempre la più recente.

```
id, settimana ('YYYY-Www'), testo, created_at
```

#### `solleciti_pagamento`
Casi di clienti morosi gestiti dalla "segretaria antipatica".

```
id, session_id, telefono, nome_cliente, email, importo_dovuto, data_scadenza,
numero_sollecito (0-4), data_ultimo_sollecito, risposta_cliente,
stato (APERTO/PAGATO/DIFFIDA/ARCHIVIATO), preventivo_ref, note_interne, created_at, updated_at
```

#### `libreria_fasi`
Libreria di frasi/fasi riutilizzabili nei preventivi. **Condivisa tra dispositivi**
(prima era in localStorage). Popolata con 12 default alla prima lettura se la tabella è vuota.

```
id (VARCHAR), nome, cat, descr, created_at, updated_at
```

#### `reel_template`
Libreria di **template di stile** per il reel (durate, slide attive, musica).
Popolata con 4 preset (Classico, Veloce, Cinematico, Solo foto) alla prima lettura se la tabella è vuota.

```
id (VARCHAR), nome, sec_foto, sec_titolo, sec_finale,
mostra_titolo, mostra_didascalie, mostra_finale, musica_default, created_at, updated_at
```

#### `social_bozze`
Post social messi in attesa con "🕒 Salva per dopo". **Persistiti in DB** (prima vivevano solo nel
localStorage del browser → si perdevano cambiando dispositivo). Gestiti da `ardy-social-bozze.php`,
visibili da ogni dispositivo e da entrambi gli utenti.

```
id (VARCHAR, 'sp_...'), session_id, payload (JSON: testo, immagini[], piattaforme[], fase, mobile…), created_at
```
> Le immagini nel payload sono **URL pubblici** della WP Media Library (le foto aggiunte a mano passano
> da `ardy-social-foto.php`): è il formato che Instagram/Facebook richiedono per la pubblicazione via API.

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

[WhatsApp]
        ↓ ardy-whatsapp-webhook.php (riceve messaggi)
        ↓ n8n (elaborazione)
        ↓ SIM dedicata per Cloud API
```

---

## 📣 Integrazioni social e canali

| Canale | Stato | Note |
|---|---|---|
| **Facebook** | ✅ Funzionante | Pagina "Ardy" (ID: 376551605541671) — pubblica via n8n nodo Code |
| **Instagram** | ✅ Funzionante | Account "ardy.lab" (ID: 17841404189479259) — pubblica via n8n |
| **Google Business** | ⏳ In attesa | Quota API da aumentare — richiesta inviata |
| **WhatsApp Business** | ✅ Attivo | Cloud API col numero eSIM +39 379 375 6437. Webhook → n8n → Claude. (sessione 7) |
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
1. Pagina WordPress categoria "Lavori in corso" (slug `lavori-in-corso`)
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
- Caricamento widget JS (solo su pagine categoria "lavori-in-corso")

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
Via James Joyce 4, 00143 Roma (RM)
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

## 💸 Solleciti clienti morosi ("segretaria antipatica")

Modulo per gestire chi non paga. Pulsante **💸 MOROSI** in sidebar → modale dedicata.

**File:** `ardy-solleciti.php` (API + DB + AI + invio) · `ardy-solleciti-system.txt` (prompt)

### Flusso
1. Michela inserisce il caso (nome, telefono, email, importo, scadenza, rif. preventivo, note). Opzionale: `session_id` del cliente per collegare il preventivo.
2. **🔍 Verifica**: controlla il preventivo collegato (totale, stato accettato, condizioni di pagamento) e ricorda di confermare a mano firma/accettazione/acconto.
3. **✍️ Genera**: l'AI scrive il messaggio del livello scelto (testo modificabile prima dell'invio).
4. **✦ Invia**: WhatsApp e/o email; il livello e la data vengono registrati.

### 4 livelli di escalation
| Liv. | Tono | Canale | Riferimenti |
|---|---|---|---|
| 1 | Cordiale (promemoria) | WhatsApp | — |
| 2 | Fermo | WA + email | art. 1326 c.c. (contratto) |
| 3 | Formale | WA + email (PDF preventivo) | art. 1453 c.c., D.Lgs. 231/2002 (mora) |
| 4 | Diffida formale | **Invio manuale** (racc. A/R / PEC) | art. 1454 c.c. — stato → DIFFIDA |

> Michela approva sempre il testo prima dell'invio. Il livello 4 non parte in automatico:
> genera la bozza di diffida da inviare a mano.

### Config WhatsApp (in `ardy-config.php`)
Riusa `WA_TOKEN` / `WA_PHONE_NUMBER_ID`. Per scrivere al moroso fuori dalla finestra 24h
serve un **template Meta approvato**: `define('WA_TEMPLATE_SOLLECITO', '...')` (body 1 var `{{1}}`).
Senza template, l'invio WA libero funziona solo se il cliente ha scritto nelle ultime 24h.

## ⏰ Job pianificati (cron)

### Notifica a Michela a chiusura chat — `ardy-chiusura-sessioni.php`
Le chat cliente↔Sole non hanno un evento di "chiusura": questo job considera
**chiusa** una conversazione ferma da **>1 ora** (su `web_messaggi` e `wa_messaggi`,
solo sessioni attive nelle ultime 24h) e manda a Michela una notifica WhatsApp con
i dati essenziali (nome, contatto, canale, n° messaggi, stato CRM, ultimo messaggio).
Una sola notifica per sessione, grazie al dedupe di `notificaMichela()`
(chiave `chat-chiusa:<canale>:<id>:<ultimo_msg>`). Soglie regolabili con le costanti
`ARDY_CHIUSURA_IDLE_MIN` (60) e `ARDY_CHIUSURA_LOOKBACK_H` (24) in cima al file.

Da CLI salta il controllo del segreto; via HTTP è protetto da `WA_LOOKUP_SECRET`
(`?secret=` o header `X-Ardy-Secret`). Richiede la config WhatsApp di
`ardy-notifica-michela.php` (incl. `WA_TEMPLATE_NOTIFICA` per scrivere fuori dalle 24h).

**Cron (ogni ora, come utente del sito):**
```cron
0 * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/micoperibg/public_html/ardyagent.ardy-lab.it/ardy-chiusura-sessioni.php >/dev/null 2>&1
```
Installazione: `sudo -u micoperibg crontab -e` (o append non interattivo). Test a mano:
`sudo -u micoperibg /opt/cpanel/ea-php83/root/usr/bin/php .../ardy-chiusura-sessioni.php`
→ stampa `{"success":true,"esaminate":N,"inviate":M}`.

## 🖥 Dashboard Michela (ardy-michela-app.html)

Single-file HTML con CSS esterno (`ardy-michela-app.css`).

### Funzionalità
- Lista clienti/lead con **semaforo lavorazione** (pallino + testo, regola 4gg: 🟠 sta per
  iniziare · 🔴 fine lavoro/ritardo · 🟢 nei tempi · 🟡 date da pianificare) e **filtri di stato in
  toggle "🔍 Ricerca avanzata"** con legenda colori + ricerca testo. Lo stato conclusivo
  **CONSEGNATO** è trattato come **archivio implicito**: esce dalla lista normale e si
  richiama col pulsante **📦 Archivio** (in cima alla lista) o dal chip ARCHIVIO
- **Badge 💬 ha risposto**: sui clienti che hanno scritto (WA o chat sito) nelle ultime 48h e di
  cui non è ancora stata aperta la conversazione. Calcolato in `ardy-crm-api.php` (2 query aggregate
  su `web_messaggi`/`wa_messaggi` vs marker `conversazione_letta_at`); si spegne aprendo la sezione 💬
- Dettaglio cliente con **tutti i campi modificabili** (nome, cognome, telefono, email, servizio, zona, mobile, budget, indirizzo, note)
- Cambio stato cliente sotto toggle **"🔄 Aggiorna stato"** (mostra lo stato attuale)
- Azioni rapide: contenuto AI, post social, **proforma**, email, WhatsApp, note interne
- **🧹 Libera spazio** (solo su clienti archiviati CONSEGNATO): a lavoro concluso cancella
  dal server **foto + reel** del cliente per recuperare spazio, tenendo scheda/preventivi+PDF/fasi/
  pagina sito; segna `foto_archiviate_at` e il bottone diventa "Spazio liberato"
- **🛋️ Interior Design** *(lug 2026)*: sezione della scheda **spenta di default**. Si accende in due
  modi — Sole la attiva dalla webchat dedicata `ardy-lab.it/interior-design` (tool
  `attiva_interior_design`, solo canale web) su richiesta del cliente, oppure Andrea/Michela premono
  **🛋️ Attiva Interior Design** in alto nella scheda. Dentro: stile preferito, colori, luce degli
  ambienti, budget e note, tutti modificabili (si salvano col bottone SALVA MODIFICHE). Una riga in
  cima dice **chi** l'ha attivata e **quando**; **✕ Disattiva** la richiude senza perdere i dati.
  In lista compare il badge **🛋️ interior**, e i dati finiscono anche nel Dossier
- **📄 Dossier**: apre il quadro completo del cliente in Markdown (anagrafica, preventivi, fasi,
  chat WhatsApp + web) da `ardy-dossier.php` — copia/scarica. Lo stesso dossier (client-safe, senza
  note interne) alimenta il contesto di Sole su web e WhatsApp
- **🤝 Ringraziamento alla consegna**: al passaggio a CONSEGNATO parte in automatico l'email al
  cliente (recensione Google + social + newsletter); bottone **📧 Reinvia ringraziamento**
- **Generatore preventivi PDF** con form completo
- **Generatore proforma** con 3 scenari
- **Storico preventivi** per cliente (dal DB)
- **Libreria fasi lavorative** (DB, **condivisa tra dispositivi**) con 12 fasi predefinite
- **Periodo del lavoro** (date inizio/fine, riferite all'intero lavoro) + elenco **📋 Fasi
  pubblicate** (sola lettura: titolo + data) caricato da `ardy-get-fasi.php`
- **✦ Avvia la pagina lavorazione** *(lug 2026)*: dentro il box "Periodo del lavoro", finché il
  cliente non ha ancora un `wp_post_id`, un mini-form con foto (scatta/galleria) + bottone
  **PUBBLICA AVVIO LAVORO + NOTIFICA CLIENTE**. Chiama lo stesso `ardy-pubblica-lavorazione.php`
  delle fasi con `fase_nome` fisso `"Avvio lavorazione"` (nessuna modifica backend), quindi crea il
  primo post WP + notifica email/WhatsApp; il box si nasconde da solo appena esiste un `wp_post_id`,
  perché da lì in poi si aggiorna con "🔨 Crea e pubblica nuova fase"
- **📅 Sopralluoghi** *(giu 2026)*: nella scheda, lista di N visite per cliente (data/ora + etichetta),
  ognuna **un evento Google Calendar**. Aggiungi / sposta (💾) / elimina (🗑) si salvano da soli via
  `ardy-sopralluoghi-api.php` (NON passano dal bottone SALVA). Sincronizzata con i sopralluoghi che
  Sole fissa su WhatsApp (mirror + riconciliazione, vedi tabella `sopralluoghi`)
- **📦 Data di consegna ed email di conferma**: gestite **solo** dal modulo Trasporti
  (`ardy-trasporti.php`, scrive su `clienti.trasporto_data`; l'email al cliente parte su azione manuale,
  `mode=conferma`). NB: "trasporto" = ritiro **o** consegna. *(Il vecchio campo "Data di consegna" nella
  scheda e l'invio automatico dell'email al salvataggio sono stati rimossi — lug 2026.)*
- **Pubblicazione fasi** con foto (scatta dal telefono o galleria) sotto il bottone collassabile
  **🔨 Crea e pubblica nuova fase**; la prima foto diventa l'**immagine in evidenza** del post
- **💾 Salva in bozza** delle fasi ("scatta ora, pubblica la sera"): nel form della fase il pulsante
  **💾 SALVA IN BOZZA** apre/salva una fase con due righe di testo e fino a **6 foto** **senza**
  pubblicare né notificare il cliente (`ardy-fasi-bozza-api.php`, `mode:'salva'`). La sera si riapre
  con **✎ Modifica e pubblica**: **tornano nel form anche foto e video**, si rifinisce nome/note e si
  preme PUBBLICA (testo AI + pagina + notifica cliente, come prima). Nella lista bozze c'è il badge
  **📷N/🎥N**; finché è bozza il cliente non vede nulla
- **Pubblicazione social manuale**: dopo la fase, pannello per rivedere/modificare il post (*pubblica
  ora / salva per dopo / non pubblicare*). **Selezione per singolo social**: le icone FB/IG sono toggle
  (default entrambi, deselezionabili fino a uno solo; Google disattivo) → il campo `piattaforme` viaggia
  al webhook n8n (vedi `ardy-pubblica-social-n8n.md`)
- **👁 Anteprima Instagram + 🖼 gestione foto**: dal composer (e dalle bozze in attesa) si vede il post
  in mockup formato IG (1:1, carosello, caption) e si possono **➕ aggiungere** / **✕ togliere** foto. Le
  foto aggiunte passano da `ardy-social-foto.php` → URL pubblico WP Media (richiesto da IG/FB via API)
- **Coda "🕒 post in attesa" persistita sul server** (`ardy-social-bozze.php`, tabella `social_bozze`):
  i post "salva per dopo" non sono più nel localStorage del browser → **visibili da ogni dispositivo e
  da entrambi gli utenti**. Per ognuno: ✏ Modifica (testo **+ foto**), 👁 Anteprima, 📲 Pubblica (sui
  social scelti), 🗑 Elimina. Migrazione una-tantum delle bozze rimaste nel vecchio localStorage
- **Reel finale**: a lavoro concluso monta un video 9:16 dalle fasi (titolo + didascalie + Prima/Dopo), con scelta **template di stile**, musica, caption automatica modificabile e pubblicazione sui social
- **Libreria template reel** (DB): preset di stile (durate, slide attive, musica) creabili/modificabili dal pannello "⚙ Template"
- Pulsante **❓ Guida** che apre la guida d'uso
- Aggiunta manuale clienti

### Stati cliente
`LEAD` → `SOPRALLUOGO` → `PREVENTIVO` → `ACCONTO` → `IN_LAVORAZIONE` → `CONSEGNATO` (concluso → 📦 Archivio)
+ stati laterali `STANDBY` (in pausa) e `PERSO`.
> Lo stato **PAGATO** è stato rimosso (coincideva con CONSEGNATO): il "saldato / non moroso" si
> gestisce dal modulo **💸 MOROSI** (`solleciti_pagamento.stato`, asse separato). 'PAGATO' resta
> riconosciuto solo come alias legacy d'archivio per eventuali schede già marcate così.

---

## 🎨 Dash Design (ardy-design-app.html)

Dashboard **gemella** della dash Michela per i **progetti interni di design** (prototipi, lampade,
piccoli mobili, complementi, restyling) — dal concept al catalogo. Vedi il piano completo in
**`PIANO-DASH-DESIGN.md`** e il ponte ecommerce in **`PIANO-ECOMMERCE-OBJECT-WOO.md`**.

Single-file HTML che **riusa lo stesso CSS** (`ardy-michela-app.css`), lo stesso login
(`ardy-auth.php`), lo stesso DB e — soprattutto — lo **stesso motore fasi → reel/social/WordPress**.
La differenza col CRM: il soggetto non è un **cliente** ma un **progetto tuo**, e il fondo dell'imbuto
non è "consegna + saldo" ma **"prodotto a catalogo"** (stock/ordini/venduto vivono su Woo/Etsy, **non**
nella dash).

### Interazione con la dash principale
- **Navigazione**: la dash Michela ha in header il bottone **🎨 DESIGN** (`ardy-design-app.html`); la
  dash design ha il ritorno **← Dash principale** (`ardy-michela-app.html`). Stesso header anche per
  📣 OUTREACH → le tre dashboard sono un unico sistema con login condiviso.
- **Motore condiviso, non duplicato**: la tabella `fasi` ha una colonna **`progetto_id` nullable** —
  una fase appartiene *o* a un cliente *o* a un progetto. Così `ardy-crea-reel.php`,
  `ardy-pubblica-progetto.php`, `ardy-pubblica-fase-progetto.php` ramificano su `progetto_id` senza
  duplicare il codice reel/social (decisione 25/06: una colonna nullable invece di una tabella gemella).
- **Aggancio dati**: nessuno. I progetti (`progetti`) stanno **accanto** ai clienti (`clienti`), non
  dentro — niente campi cliente (telefono, indirizzo, solleciti).

### Ciclo di vita del progetto (≠ stati cliente)
`IDEA → PROGETTAZIONE → PROTOTIPO → VERSIONE_FINALE [⏟ congela file] → SCHEDA_PRODOTTO →
CATALOGATO` (terminale). Pilotato dalla **maturazione del prodotto**, non dai pagamenti. Man mano che
avanzi, **compaiono i moduli** di quella fase (barra pipeline cliccabile nel dettaglio).
**Ogni fase è pubblicabile**: galleria, documenti, articolo e fasi-racconto sono attivi già da
`IDEA` — l'idea nasce con del materiale (la foto del pezzo da recuperare, un render, gli appunti da
cui esce il racconto), si annuncia subito e progettazione/prototipo si raccontano mentre accadono (revisione lug 2026:
`REALIZZAZIONE` duplicava `PROTOTIPO`/`VERSIONE_FINALE` ed è stato tolto; `FOTO` → `SCHEDA_PRODOTTO`,
`A_CATALOGO` → `CATALOGATO` = pezzo correttamente esposto in vetrina).

| Modulo (sblocco) | A cosa serve | Endpoint |
|---|---|---|
| **Galleria** (IDEA) | Immagini del progetto (Modulo 1, → `ardy-lab.it`): `prima` (com'era / riferimento) · `render` · `foto` finite | `ardy-progetti-galleria-api.php` |
| **Documenti** (IDEA) | Materiale di riferimento (PDF/DOCX/ODT/RTF/TXT/MD) da cui l'AI scrive: testo estratto **una volta** e salvato | `ardy-progetti-file-api.php` (cat. `doc`) + `ardy-progetti-ai.php` (`leggi_doc`) |
| **Articolo** (IDEA) | Testo di brand riscritto con **✨ AI** (Claude), da rivedere prima di pubblicare | `ardy-progetti-ai.php` |
| **Social** (IDEA) | Articolo e singole fasi → **FB/IG/Google**, toggle per canale come nella dash clienti; caption AI a parte. Solo su ciò che è già su WP (i social scaricano le immagini da URL pubblici) | `ardy-pubblica-social.php` + `ardy-gbp-post.php` |
| **Fasi-racconto** (IDEA) | Binario **pubblico**: le stesse `fasi` della dash Michela → reel/social/WP | `ardy-progetti-fasi-api.php` |
| **File CAD/STL** (PROGETTAZIONE) | Archivio file tecnici dietro Basic Auth | `ardy-progetti-file-api.php` |
| **Iterazioni** (PROTOTIPO) | Binario **R&D interno**: v1/v2/v3 con note "qui non torna" | `ardy-progetti-api.php` (`iter_*`) |
| **Distinta / costi** (PROTOTIPO) | **BOM** interna → `costo_produzione` = Σ righe × (1+scarto%); mostra il **margine** | `ardy-progetti-api.php` (`mat_*`) |
| **Versione finale** (VERSIONE_FINALE) | **Congela file**: snapshot STL + profilo OrcaSlicer + scheda | `ardy-progetti-api.php` (`congela`) |
| **Scheda prodotto / Sole** (SCHEDA_PRODOTTO) | Contenuti pubblici del pezzo in vendita (teaser, storia, FAQ, slug) | `ardy-progetti-api.php` (`save`) |
| **Foto vendita** (SCHEDA_PRODOTTO) | Foto professionali del pezzo finito (Modulo 2, → Woo) | `ardy-object-foto-api.php` / `ardy-object-img.php` |
| **Push catalogo** (CATALOGATO) | Crea/aggiorna il prodotto **WooCommerce** in bozza | `ardy-object-push.php` |
| **Archivio** (CATALOGATO) | A ciclo chiuso deposita la documentazione su **B2** (copia, non sposta) + scheda Markdown | `ardy-archivia-b2.php` |

### Costi e margine (uso interno)
- **Distinta (BOM)** in `progetto_materiali`: categorie `filamento | stampa | legno | elettrico |
  ferramenta | finitura | imballo | manodopera`. Manodopera default **€50,00/h** (`ARDY_DESIGN_COSTO_ORARIO`),
  override per riga; grammi/ore da **OrcaSlicer** digitati a mano (no integrazione Moonraker per ora).
- **`scarto_pct`** (default 10%) = % fallimenti stampa: senza, il margine sarebbe una bugia.
- **Salva tutto**: barra fissa in fondo al dettaglio con indicatore *dirty* + `beforeunload`; salva in
  un colpo campi progetto + scheda **e** le righe della distinta (`mode:'mat_save_batch'`, una transazione).

### Versione Premium
Alcuni pezzi hanno una variante **standard** e una **premium**: modellata come **progetto-copia
agganciato** (`progetti.variante` + `parent_id`), non doppia distinta. `mode:'duplica'` copia scheda +
BOM; slug/Woo/WP/media **ripartono da zero** (è un prodotto a sé). In dash: link padre⇄figlia, badge ✨.

### Ponte ecommerce "object" (chat prodotto → Woo)
Layer separato per la **vendita**: `ardy-object-push.php` fa un push **a senso unico** dash → Woo
(stesso slug, salva `woo_product_id`, Woo non riscrive mai la dash). `ardy-object-scheda.php` espone una
**proiezione PUBBLICA** whitelisted del progetto (via `ardy-object-lib.php`) che alimenta la chat di Sole
sul negozio (`ardy-object-proxy.php`) — **costi, BOM, margine, STL e iterazioni R&D non escono mai** da
lì. Guida d'uso: `ardy-guida-design.html`.

---

## 📣 Ardy Outreach (ardy-outreach.html)

Dashboard per il **cold-outreach B2B**: costruire liste di contatti per verticale, arricchirli e mandare
**campagne email** (via Brevo). Terza app dello stesso sistema (header condiviso con Michela e Design,
stesso login). API: `ardy-outreach-api.php` (routing per `action`); tabelle `outreach_contatti` e
`outreach_template`.

### Funzionalità
- **📇 Contatti / 📊 Pipeline**: lista contatti per **categoria** e **stato** (`da_contattare` = lead
  freddo target campagne, `cliente`, `partner`, ecc.), con filtri e azioni di massa.
- **🔎 Ricerca contatti** per verticale — target predefiniti: **antiquari, mercatini, interior designer,
  B&B**. Fonte **Google Places** (`ardy-places.php`) con fallback OpenStreetMap; i risultati si salvano
  come contatti (`save_leads`).
- **✨ Arricchimento** (`enrich_contact`): dato un contatto incompleto (spesso solo nome + indirizzo),
  l'agente `ardy-enrich.php` prova a completare email/telefono/sito. `ardy-email-finder.php` (da CLI)
  visita i siti dei contatti senza email e ne cerca una.
- **📝 Template email** generati/riscritti con AI (`genera_template`), salvati in `outreach_template`
  (`save_template` / `init_templates`).
- **✉ Invio**: singola email (`send_email`) o **campagna** di massa (`send_campaign`) via Brevo, con
  **unsubscribe** (`unsubscribe`) e link di disiscrizione (`ardy-unsubscribe.php`).
- **🤝 Promozione stato**: un contatto può diventare **partner** (`promote_partner`) o **cliente**
  (`promote_client`).

### Interazione con la dash principale (CRM → Outreach)
Quando un lead **diventa reale** — passa a uno stato "impegnato" (es. **Acconto**) in `ardy-update-lead.php`
— viene **agganciato automaticamente** ai contatti outreach come `cliente` (via
`ardy_outreach_aggiungi_cliente` in `ardy-outreach-lib.php`). È comunicazione di servizio/riattivazione,
tenuta **distinta** dai lead freddi `da_contattare` che le campagne targetizzano. L'aggancio è idempotente
(dedup per email/nome), best-effort (non blocca il salvataggio del lead se fallisce) e richiede un'email.
Import manuale in blocco dei clienti CRM disponibile anche dal bottone **⬇ IMPORTA CLIENTI CRM**
(`import_clients`).

---

## 📱 WhatsApp (ATTIVO — Piano B)

### Stato attuale
- App **ArdyagentWA** creata su Meta Developers
- Business Portfolio verificato
- Webhook configurato: `https://ardyagent.ardy-lab.it/ardy-whatsapp-webhook.php`
- Token verifica: `ardy_wa_verify_2026`
- Numero Sole (+39 379 375 6437) registrato

### Piano B LIVE — Sole ha strumenti VERI sui clienti
Sui clienti (NON sullo staff) Sole gira con lo **stesso loop agentico del sito** (file
`ardy-wa-agent.php`) e può davvero:
- **leggere la disponibilità** del calendario Google e **fissare il sopralluogo** su conferma
  (guardia anti-doppione, persiste la scheda, notifica Michela);
- **salvare il lead nel CRM** (`ardy-wa-crea-scheda.php`); se il cliente non lascia il telefono
  usa in automatico il suo **numero WhatsApp**;
- **spostare un appuntamento già fissato** (tool `sposta_appuntamento`), identificandolo dal numero
  WhatsApp del mittente (un cliente può spostare **solo il PROPRIO** appuntamento);
- inviare le **email** come il sito: notifica a Michela, conferma del sopralluogo al cliente,
  email di benvenuto col **codice di accesso** al lead.

Una **rete di sicurezza** (`ardy-sanitize.php`) ripulisce eventuale sintassi tool che trapelasse come testo.

### Staff (titolare) — Sole ha i tool _staff_  *(giu 2026)*
> ⚠️ Aggiornamento: lo staff **non** è più "single-shot senza tool". Il ramo titolare di n8n inoltra a
> `ardy-wa-agent.php` con flag **`staff:true`**: Sole ha un set di tool _staff_ che agiscono **per conto
> di un cliente NOMINATO nel CRM** (identificato per nome, con **disambiguazione omonimi** via `session_id`).
> Era il bug del "caso Alberto": prima Sole *recitava* la chiamata al tool e si bloccava.

Tool staff (in `ardy-wa-agent.php`, ramo `$staff`; prompt in `ardy-wa-lookup.php` → `ardy_wa_titolare_istruzioni`):
- `ottieni_disponibilita_calendario`, `cerca_scheda_cliente`;
- **Creazione scheda**: `crea_scheda_cliente` — crea/aggiorna la scheda **sincrona** (server-to-server verso
  `ardy-wa-crea-scheda.php`, upsert deterministico per telefono/nome → niente doppioni) e ritorna il
  `session_id`, così Sole **crea e fissa l'appuntamento nello stesso giro** riusando quel `session_id` con
  `fissa_appuntamento_staff`, senza aspettare nessuna "sincronizzazione";
- **Appuntamenti MULTIPLI** (`tipo`: `sopralluogo` | `consegna` | `ritiro`): `fissa_appuntamento_staff`
  (AGGIUNGE sempre un appuntamento, niente anti-doppione; una **consegna** non è un sopralluogo →
  Sole passa `tipo:"consegna"`), `sposta_appuntamento_staff` (se il cliente ha più eventi, Sole
  **chiede QUALE** via `sopralluogo_id`), `elenca_sopralluoghi_staff`. Usano `ardy-sopralluoghi-lib.php`
  (stesso motore della dashboard, tabella `sopralluoghi.tipo` VARCHAR — nessuna migrazione per nuovi tipi);
- **Nota settimanale**: `salva_nota_settimanale` / `leggi_nota_settimanale` (tabella `note_staff`,
  condivisa Michela+Andrea). Sole legge → modifica il testo intero → risalva.

Il solo marker n8n rimasto lato staff è il **contatto lead a freddo** (`[[CONTATTA_LEAD]]`). La creazione
scheda **non** usa più il marker `[[CREA_SCHEDA]]`: era la causa della confusione (la scheda veniva creata
dopo il turno e i tool non la vedevano ancora → Sole inventava un "problema di sincronizzazione").

> ⚠️ **Gotcha tool a zero argomenti**: un tool senza parametri (es. `leggi_nota_settimanale`) torna con
> `input {}`; `json_decode(assoc)` lo rende un **array PHP vuoto** che, rispedito ad Anthropic, diventa
> `[]` → **400 "Input should be an object"** e blocca la conversazione. Fix in `ardy-wa-agent.php`: prima
> di accodare il turno assistant, gli `input` di `tool_use` vuoti vengono riportati a `(object)[]`.
> `waCallAnthropic` ora **logga** gli errori Anthropic e **ritenta** i transitori (429/529/5xx).

### Ricezione FOTO LIVE
Se il cliente manda una **foto del mobile** su WhatsApp, Sole la **riceve come immagine, la guarda
e la valuta** (commenta cosa vede, fa domande su misure/stato/materiale). La foto viene **salvata
nella scheda del cliente** (compare in dashboard) e **allegata all'email** di notifica a Michela.
Catena: `ardy-whatsapp-webhook.php` estrae il media id → n8n lo inoltra → `ardy-wa-agent.php`
scarica da Meta, comprime, salva e allega.

### Regola "il numero è l'identità"
Su WhatsApp il riconoscimento è **sempre** legato al numero con cui il cliente scrive (lo stesso
da cui chiama). Sole **non** registra un numero diverso da quello WhatsApp. Se il cliente vuole
usare un altro numero o un altro dispositivo, usa la **chat del sito**
(`https://ardy-lab.it/ardy-agent/`) con il suo **codice personale**.

### Architettura 
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

**VPS:** OVH AlmaLinux 9 — gestito via WHM/cPanel
**Accesso:** WHM + **SSH come root (autenticazione a CHIAVE, no password)**. FTP disabilitato.
**Firewall host:** `cpanel-csf` v16.20 (fork cPanel) **LIVE** dal 19/06/2026 (firewalld resta disabled,
csf non ne dipende). Fail2ban rimpiazzato da LFD. Dettagli in `ANALISI-FIREWALL-HOST.md`.
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

### Config WhatsApp per notifiche a Michela (in `ardy-config.php`)
Necessaria per `ardy-notifica-michela.php` (Task 1 — Sole avvisa Michela su WhatsApp):
```php
define('WA_TOKEN',           '...');             // token permanente Cloud API (Bearer)
define('WA_PHONE_NUMBER_ID', '1151535311377293'); // numero "Sole" (+39 379 375 6437)
define('WA_MICHELA_NUMBER',  '393519677973');    // numero di Michela, formato internazionale
// Opzionali:
define('WA_TEMPLATE_NOTIFICA', '');              // template Meta approvato (body 1 var {{1}}) — aggira la finestra 24h
define('WA_TEMPLATE_LANG',     'it');
```

### Config Ringraziamento alla consegna (in `ardy-config.php`)
Per `ardy-grazie-consegna.php` (email + WhatsApp di ringraziamento alla transizione → CONSEGNATO):
```php
define('GRAZIE_GOOGLE_REVIEW_URL', '');  // link "lascia una recensione" su Google Maps (vuoto = bottone nascosto)
define('GRAZIE_IG_URL', 'https://www.instagram.com/ardy.lab/');   // opzionale (default ardy.lab)
define('GRAZIE_FB_URL', 'https://www.facebook.com/376551605541671'); // opzionale (default pagina "Ardy")
define('WA_TEMPLATE_GRAZIE', '');        // template Meta approvato (body 1 var {{1}}=nome). Senza, niente WhatsApp.
```
> L'**email** funziona subito (Brevo, con link recensione/social + disiscrizione firmata).
> Il **WhatsApp** parte solo con un template Meta approvato (fuori dalla finestra 24h serve il template).

### Secret interno per chiamate server→server (in `ardy-config.php`)
```php
define('ARDY_INTERNAL_SECRET', '...'); // stringa casuale lunga
```
> Usato da `ardy-proxy.php` quando chiama `ardy-save-lead.php` (header `X-Ardy-Internal`):
> marca la richiesta come interna ed esente dal rate-limit pubblico dell'endpoint
> (15/ora, 50/giorno per IP). Senza il secret il salvataggio funziona comunque, ma le
> chiamate del proxy ricadono sotto lo stesso limite per IP.

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

### Deploy sul server (via git — attivo)
Il sito si aggiorna con **git pull + deploy**, non più via cPanel File Manager.

**Setup (già fatto, sessione 7):**
- Accesso SSH come **root** al VPS (`ssh root@57.131.47.5`).
- **Deploy key** read-only sul repo GitHub (chiave `~micoperibg/.ssh/github_ardyagent`).
- Repo clonato in `/home/micoperibg/repositories/ardyagent` (fuori dal document root).
- Le operazioni girano come utente `micoperibg` (proprietà file corretta) via `runuser`.

**Aggiornare il sito dopo un push su `main`:**
```bash
runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'
```

`deploy.sh` fa un `rsync` selettivo nel document root **senza `--delete`**: i file NON
in repo (`ardy-config.php`, `ardy-gcal-token.json`, `ardy-uploads/`, `preventivi_pdf/`,
`reels/`, `vendor/`, `ardy-rate-limit/`) restano intatti. In alternativa, `.cpanel.yml`
permette il deploy push-button da cPanel (richiede Jailed Shell).

**Migrazioni schema DB:** dopo l'rsync, `deploy.sh` esegue **`ardy-migrate.php`** — l'**unico**
posto dove si creano/alterano tabelle e colonne. Prima ogni endpoint faceva DDL
(`SHOW COLUMNS`/`ALTER`/`CREATE TABLE IF NOT EXISTS`) ad ogni request HTTP; ora gira tutto una
volta sola al deploy. È idempotente (IF NOT EXISTS + try/catch su 1050/1060 + `colExists`/`indexExists`),
quindi rieseguirlo è sicuro. **Nuova tabella o colonna → aggiungerla a `ardy-migrate.php`**, mai
inline negli endpoint.

### Monitoraggio (`ardy-health.php`)
Cruscotto che verifica in un colpo solo le parti che possono rompersi **in silenzio**:
DB + tabella core, config integrazioni (WhatsApp/GBP/GCal/Brevo), token Google
(presenza `refresh_token` + freschezza `access_token`), cartelle scrivibili, errori PHP
di oggi (coda del log), spazio disco. Ogni check è isolato in try/catch.

- **Da dashboard:** menu **☰ → 🩺 STATO** (semaforo HTML, auto-refresh 60s).
- **Monitor esterno:** `ardy-health.php?format=json` — keyword `"status":"ok"`, HTTP **503**
  sui guasti veri (rosso), **200** altrimenti. Protetto da Basic Auth: nel monitor
  (UptimeRobot/Better Stack) imposta le credenziali admin. Nessuna chiamata di rete esterna,
  sicuro da colpire ogni pochi minuti.
- **Nota:** il conteggio "errori PHP di oggi" è grezzo — `error_log()` è usato anche per
  eventi informativi (es. `ARDY WA AGENT`), quindi un numero > 0 non è di per sé un guasto.

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

### WhatsApp ✅ ATTIVO (sessione 7)
- [x] SIM dedicata (eSIM) per Cloud API
- [x] Numero registrato sulla Cloud API → **+39 379 375 6437** (Phone Number ID `1151535311377293`, WABA "Ardy lab" `1235593451848137`)
- [x] Workflow n8n per WhatsApp (Webhook `ardy-whatsapp` → nodo Code: lookup → Claude → invio Cloud API)
- [x] Prompt WhatsApp (`ardy-whatsapp-system.txt`, modalità Lead/Cliente/Cliente_lavorazione)
- [x] Test end-to-end OK
- [x] **Memoria conversazione** (storico per numero) — `ardy-wa-memoria.php` (tabella `wa_messaggi`); il nodo Code recupera lo storico, lo passa a Claude e salva la nuova coppia (sessione 7)
- [x] Impostare `WA_APP_SECRET` in `ardy-config.php` (verifica firma webhook)
- [ ] Spostare token/chiavi dal nodo Code alle credenziali/variabili n8n
- [ ] Gestire messaggi non testuali (foto inviate dal cliente)
- [ ] **Inbox WhatsApp nella dashboard** — Michela legge le conversazioni Sole↔cliente (dati già in `wa_messaggi`), può mettere Sole **in pausa** per una chat, **rispondere manualmente** (invio via Cloud API, token in `ardy-config.php`) e riattivare Sole. Il nodo n8n deve controllare il flag di pausa prima di rispondere. NB: finestra 24h per i messaggi liberi.

### Dashboard Michela
- [x] **Preventivo PDF avanzato** (`ardy-preventivo.php` + pannello dashboard):
  - **Opzioni a pacchetto**: più alternative per lo stesso lavoro, ognuna con le sue voci
    e il suo totale; una pagina costi per opzione. Una sola opzione = preventivo singolo.
  - **Copertina** immagine unica a tutta pagina (full-bleed, `background-size:cover`) +
    **Prima/Dopo per opzione** (in testa alla pagina dell'opzione). Immagini ridotte lato
    client e inviate in base64 (impaginazione mPDF-friendly, niente `object-fit`).
  - **Analisi degli interventi con AI**: `mode=ai` (Claude) scrive il testo descrittivo
    della pagina "Dettaglio Tecnico" da un prompt; modificabile.
  - **Bozza modificabile** (✏️ riapre dal payload in `voci_json` LONGTEXT), si **blocca** 🔒
    passando a Inviato/Accettato; **🗑** elimina le bozze. Modifica = UPDATE per `prev_id`
    (niente più doppioni).
- [ ] Layout PDF da rifinire graficamente (interruzioni pagina per opzione, ecc.)
- [ ] Pagina "I nostri lavori" con foto portfolio nel PDF
- [ ] Invio email automatico preventivo al cliente
- [ ] Fix chatbot pubblico (`ardy-proxy.php` — errore API)
- [ ] Render AI mobile per preventivi (fase B — Stable Diffusion)
- [ ] Bottone rapido "Blocca giorni laboratorio" per Google Calendar

### Integrazioni social
- [ ] **Google Business** — attendere aumento quote API, poi configurare nodo n8n
- [ ] **LinkedIn** — integrare per outreach B2B

### Ardy Outreach
- [ ] Completare dashboard `ardy-outreach.html`
- [ ] Collegare flussi n8n per invio newsletter automatiche
- [ ] Integrare tracciamento aperture email
- [ ] Test end-to-end campagna outreach B2B

### Infrastruttura
- [ ] Migrazione server (pianificata — da definire tempistiche)
- [ ] Automatizzare deploy da GitHub al server (ora manuale via cPanel)
- [ ] Deploy via git sul server

### Performance
- [ ] **Reel asincrono** (`ardy-crea-reel.php`, priorità media) — oggi monta il video in **sincrono**
  nella richiesta HTTP (`set_time_limit(600)` occupa un worker FPM fino a 10 min, download foto in serie
  fino a 40, I/O pesante src→norm→clip→raw→final, attesa API caption 60s). Non urgente (lo usa solo
  Michela dalla dashboard, niente concorrenza); da fare se compaiono 504/timeout. → job in **background**
  (`proc_open` detached) + **polling** stato dalla dashboard. Quick win a parte: rimuovere i 2 download
  ridondanti prima/dopo, scaricare le foto in parallelo (`curl_multi`), caption fuori dal path critico.

### Sicurezza (priorità bassa)
- [ ] **Prompt injection caption reel** (`ardy-crea-reel.php` → `generaCaptionReel`): `$mobile` e i nomi
  fase finiscono grezzi nel prompt a Claude. Rischio basso (endpoint dietro Basic Auth, `$mobile` dal DB,
  caption rivista a mano prima di pubblicare). Hardening: delimitare i dati non fidati con tag
  (`<dati_cliente>…</dati_cliente>`) + istruzione a trattarli come dati.

### Dashboard / Lavorazioni
- [x] **Caricamento video delle lavorazioni** — nelle fasi si possono caricare anche video (oltre alle foto). Upload multipart via `ardy-upload-video.php` → Media Library WP; incorporati come `<video>` nel post e salvati in `fasi.video_urls` (sessione 7)
- [ ] Autenticare il dominio `ardy-lab.it` su Brevo (DKIM/SPF) ed evitare il mittente Gmail (deliverability)

---

## 📝 Note sessioni

**Luglio 2026 — Foto multiple, promesse senza date e promemoria datati di Sole**
- **Più foto dello stesso pezzo.** Un lead della webchat ha mandato le foto dello stesso mobile una alla volta e Sole ha dato una valutazione completa a ogni scatto (pedante). La causa era l'interfaccia: `ardy-chat-site.js` non impostava `multiple` sull'`<input id="ac-file-input">` (che vive nella pagina WordPress), quindi si poteva scegliere **una foto per volta**. Ora il flag è forzato da JS — la pagina WP non si tocca, il file è servito da noi — e sotto le anteprime compare il conteggio ("3 foto pronte — inviale insieme").
- **Regola "PIÙ FOTO DELLO STESSO PEZZO"** in `ardy-system.txt` (dentro *Valutazione tecnica*, quindi vale **anche su WhatsApp**, dove le foto arrivano separate per forza): nel dubbio è lo stesso oggetto, **una sola** valutazione sulla prima e poi solo l'incremento, riepilogo unico alla fine, mai rimproverare chi le manda una alla volta. Passo 3 riscritto (invito a mandarle insieme) + regola di **brevità** nel `TONO`.
- ⚠️ **Sole non dà più date.** Nuova sezione *PROMESSE E TEMPI* in `ardy-system.txt`: mai "ti chiama lunedì" né "a fine agosto" — l'agenda di Michela non la gestisce lei. Si dice "ti faccio ricontattare al più presto"; l'unica data nominabile è uno slot confermato da `fissa_appuntamento_calendario`.
- **Nuovo tool `ricorda_a_michela`** (`ardy-promemoria-lib.php`, agganciato a **`ardy-proxy.php` e `ardy-wa-agent.php`** — quindi la regola può stare nel prompt condiviso senza che WhatsApp "reciti" un tool inesistente). Quando il cliente rimanda il lavoro ("lo farei a settembre"), Sole registra una riga in **`todo_datati`** con il suo evento Google Calendar, datata **poco prima** del momento indicato: ricompare da sola in dashboard, sul calendario e nel **briefing del mattino** di quel giorno. Validazione difensiva: date passate o oltre ~18 mesi rifiutate (anno sbagliato del modello), ISO 8601 e cifre singole normalizzate, dedupe su testo+giorno, gcal opzionale (se Google non risponde il promemoria resta comunque in dashboard).
- **Data odierna nel system prompt** (`## OGGI`, sia proxy sia wa-agent): serviva al buffer dei sopralluoghi e soprattutto a datare i promemoria — senza, il modello indovina giorno e anno.
- **"Cose da fare questa settimana" → "Promemoria"** in tutta l'interfaccia (dashboard, modale, briefing email, prompt staff su WhatsApp, manuale): nell'uso reale è una lista generale (cose da fare, da comprare, da ricordare) e non riguarda la settimana in corso. **Nomi tecnici invariati** — tabella `note_staff`, endpoint `ardy-nota-settimanale-api.php`, tool `leggi_nota_settimanale`/`salva_nota_settimanale`: rinominarli sarebbe una migrazione senza guadagno.

**Luglio 2026 — Consulenza Interior Design (webchat dedicata + sezione in dashboard)**
- **Webchat dedicata** `ardy-chat-interior-design.js` sulla pagina `ardy-lab.it/interior-design` (contenuto pagina in `wordpress-snippets/interior-design-page.html`, loader di una riga in WPCode Site Wide Footer). Widget autoportante sullo stampo di Galleria Diffusa: riusa `ardy-proxy.php`, si auto-limita all'URL `/interior-design`, espone `window.ardyIdOpen()` per i CTA della pagina.
- **Sole in modalità interior design**: nuova sezione in `ardy-system.txt`, strutturata come **intervista guidata** — *Passo 0* chiede il **permesso** prima di qualsiasi domanda (mai partire a razzo), *Passi 1-5* una domanda per messaggio in ordine fisso (ambiente → stile → colori → luce → budget, + bonus facoltativa sul recupero dell'esistente), invito **una tantum** a mandare immagini di riferimento, *Passo 6* riepilogo e **solo lì** i dati anagrafici su richiesta esplicita, *Passo 7* salvataggio con `servizio` = "Consulenza Interior Design". Le istruzioni sul tool stanno nell'appendice **solo-web** di `ardy-proxy.php`, non nel documento condiviso con WhatsApp (dove il tool non esiste).
- **Su mobile la chat si apre a tutto schermo** (`@media(max-width:600px)`: pannello a `inset:0`, `100dvh`, messaggi in `flex:1`, safe-area per notch/gesture bar). Mentre è aperta il toggle si nasconde e il `body` non scorre più sotto; niente focus automatico sull'input, altrimenti la tastiera coprirebbe subito il messaggio che chiede il permesso.
- **Grassetto markdown reso davvero**: Sole scrive `**così**` e il widget lo converte in `<strong>` costruendo **nodi DOM** (mai `innerHTML`) — prima gli asterischi finivano letterali a schermo.
  > Entrambi i comportamenti (fullscreen mobile + grassetto) sono stati allineati anche su **`ardy-chat-experience.js`** (Galleria Diffusa), che condivide la stessa struttura. Chi tocca uno dei due widget tenga presente che sono gemelli.
- ⚠️ **Le immagini si ridimensionano NEL BROWSER prima dell'invio** (lato lungo 1600px, JPEG q82, EXIF applicato via `createImageBitmap({imageOrientation:'from-image'})` per non ruotare le verticali). Non è un'ottimizzazione, è ciò che tiene in piedi la conversazione: `ardyCompressImage` agisce **solo sul messaggio corrente**, mentre la `history` rispedita a ogni turno viaggia verso l'API così com'è. Con le foto originali (4-8MB, +33% in base64) si sfonda il **limite di 5MB per immagine** dell'API → il primo messaggio con foto passa e il **secondo** fa fallire la chiamata ("Errore nella risposta AI"). Inoltre le immagini restano solo negli **ultimi 2 messaggi** che ne contengono: nei più vecchi diventano una nota testuale, così la richiesta non cresce all'infinito.
- **Upload immagini nel widget** (📎 galleria + 📷 fotocamera, max 4 per messaggio, anteprime rimovibili) + CTA esplicita **"📷 Fai la foto al tuo ambiente"** che apre direttamente la fotocamera. La CTA compare **solo su `(pointer: coarse)`** — telefoni e tablet: su desktop l'attributo `capture` è ignorato e aprirebbe il selettore file, rendendo l'invito falso. il cliente allega ispirazioni o la stanza com'è. Nessuna modifica PHP — `ardy-proxy.php` già valida il MIME reale, comprime e salva in `ARDY_UPLOAD_DIR/<session_id>/`, che è **la stessa cartella letta da `ardy-lead-foto.php`**: le foto compaiono quindi da sole in **📷 Foto della scheda** in dashboard, oltre che allegate all'email a Michela. Le immagini restano nella `history` in base64, così Sole continua a "vederle" nei messaggi successivi.
  > Conseguenza voluta dell'anagrafica in coda: chi abbandona a metà intervista **non lascia una scheda** (senza telefono non si crea). Scelta deliberata — non chiedere i contatti a freddo, in cambio chi arriva in fondo è un lead caldo. Non è però un buco nero: la chat resta in `web_messaggi` (salvata da `ardy-proxy.php`) e `ardy-chiusura-sessioni.php` notifica comunque Michela con «💬 Chat conclusa — (non in CRM)», perché la sua query parte dai messaggi e non richiede una riga in `clienti`.
- ⚠️ **Accensione DETERMINISTICA dalla webchat**: il widget dichiara `origine: 'interior-design'` nel POST e il proxy, appena `salva_lead_crm` va a buon fine, accende la sezione e forza `servizio = "Consulenza Interior Design"` **senza aspettare il tool**. La provenienza è una certezza, la chiamata al tool è una speranza: se il cliente salta l'intervista e detta subito i dati (o il modello non chiama il tool), Michela vedrebbe altrimenti un lead generico con la sezione spenta. Idempotente — `COALESCE` non sovrascrive dati raccolti né chi/quando ha attivato per primo.
- **Un solo campo: il Riepilogo di Sole** (`interior_design_note`). I quattro campi separati (stile/colori/luce/budget) sono stati **rimossi dall'interfaccia** a favore del racconto discorsivo: dice le stesse cose in forma leggibile e si compila **anche quando l'intervista resta a metà**, mentre le caselle singole restavano vuote. Il tool `attiva_interior_design` ha ora un unico parametro `riepilogo` (obbligatorio) e **sostituisce** il testo a ogni chiamata — quindi va chiamato una volta sola a fine intervista, riscrivendolo intero se serve aggiornarlo.
  > Le colonne `interior_design_stile/colori/luce/budget` **restano in `ardy-migrate.php` e non si droppano**: nessuno le scrive più, ma le schede raccolte prima le hanno ancora e il **dossier le mostra se valorizzate**, così nessun dato già preso sparisce.
- **Foto di riferimento visibili nella sezione**: `ardy-lead-foto.php` legge la stessa cartella in cui il proxy salva gli allegati della chat, quindi le immagini compaiono sia in *📷 Foto della scheda* sia — ripetute apposta — sotto *🖼️ Immagini di riferimento del cliente* dentro l'accordion 🛋️. Per una consulenza di arredamento le foto sono il materiale principale: farle cercare in un altro accordion significa non farle trovare.
- **Nuovo tool `attiva_interior_design`** (`ardy-proxy.php`, canale web): accende `clienti.interior_design_attivo` e scrive stile/colori/luce/budget/note. Richiamabile più volte — ogni chiamata aggiunge senza sovrascrivere (`COALESCE`); traccia `attivato_da='sole'` + `attivato_at` alla prima attivazione.
- **Dashboard**: accordion **🛋️ Interior Design** nella scheda, nascosto finché non è attivo, con i campi modificabili e la riga "chi/quando l'ha attivata". Bottone **🛋️ Attiva Interior Design** in alto per Andrea/Michela (attivazione `manuale`, tracciata in `ardy-update-lead.php`), **✕ Disattiva** che nasconde senza perdere i dati, badge **🛋️ interior** in lista.
- **Dossier**: blocco "Consulenza Interior Design" in `ardy-dossier.php` (client-safe: sono i gusti del cliente stesso) → finisce anche nel contesto di Sole.
- **DB** (`ardy-migrate.php`): 8 colonne `interior_design_*` su `clienti`.

**Luglio 2026 — Preventivi nelle scadenze (briefing Sole + badge dashboard)**
- **Briefing del mattino**: nuovo blocco **🧾 PREVENTIVI DA GESTIRE** in `ardy-briefing-lib.php` (`ardy_riepilogo_settimana`). Elenca i clienti fermi in stato `PREVENTIVO` distinguendo **✍️ preventivo DA FARE** (nessun documento o solo bozza) da **📤 preventivo INVIATO — da sollecitare risposta** (con "inviato da Ng"). Così Sole cita queste scadenze nel buongiorno su WhatsApp e nell'email del briefing, con la specifica giusta. Difensivo: se la tabella `preventivi` manca, i clienti risultano "da fare".
- **Dashboard**: la card cliente (stato `PREVENTIVO`) mostra un badge del preventivo — **📤 preventivo inviato** / **📝 preventivo da fare** (e ✓ accettato / ✗ rifiutato). Lo stato dell'ultimo preventivo è esposto da `ardy-crm-api.php` (`Preventivo_stato`, query aggregata per `session_id`) e reso da `preventivoBadge()` in `ardy-michela-app.html`.

**Giugno 2026 — Sessione 19/06 (rotazione chiavi, backlog sicurezza, migrazione DDL, indice clienti)**
- **Chiavi sensibili ruotate**: Token Meta/WhatsApp, API key Anthropic e `WA_LOOKUP_SECRET` rigenerati (erano trapelati in un export del workflow n8n) e aggiornati in `ardy-config.php` + nodo n8n. Verificato dal vivo su WhatsApp.
- **Backlog sicurezza chiuso** (4 fix): `state` anti-CSRF nell'OAuth Google (`ardy-gcal-auth.php`); query `get_stats` parametrizzata (`ardy-outreach-api.php`); ownership check sul download PDF preventivo (`ardy-preventivo.php`); delimitatori anti prompt-injection nella caption reel (`ardy-crea-reel.php`).
- **DDL fuori dal path HTTP** (`ardy-migrate.php`): tutti i `CREATE TABLE`/`ALTER`/`SHOW COLUMNS` che giravano ad **ogni richiesta** in ~20 endpoint sono centralizzati in un'unica migrazione idempotente, eseguita da `deploy.sh` al deploy. Le funzioni `ensure*` negli endpoint sono ora no-op; il seeding default di `libreria_fasi`/`reel_template` resta (DML), condizionato su tabella vuota.
- **Lista clienti più leggera** (`ardy-crm-api.php`): i due `SELECT *` su `clienti` ora prendono solo le colonne usate (`ARDY_CLIENTI_COLS`) + indice composito `idx_clienti_deleted_updated` su `(deleted_at, updated_at)`.
- **Pulizia repo**: rimosso `GUIDA-MICHELA.md` (duplicato di `ardy-guida-michela.html`).

**Giugno 2026 — Sessione 18/06 bis (autoapprendimento di Sole dalle fasi)**
- **Conoscenza appresa dai lavori** (`ardy-conoscenza-appresa.php`): Michela seleziona dalle fasi pubblicate, preme **🧠 Distilla** → Claude estrae conoscenza di bottega **generica e anonimizzata** (tecniche/materiali/accorgimenti, niente nomi/indirizzi/prezzi/pezzi identificabili; dati fase delimitati come non-istruzioni = anti prompt-injection). Michela rivede/corregge la proposta e **salva**: solo allora entra in Sole. Storage in **blocco DB separato** (tabella `conoscenza_appresa`, attiva/disattiva/modifica/elimina) — distinto da `ardy-conoscenza-restauro.txt`.
- **Iniezione**: il blocco attivo finisce nel `system_static` cacheato di Sole accanto alla conoscenza di bottega, sia su web (`ardy-proxy.php`) sia su WhatsApp lato cliente (`ardy-wa-lookup.php`). Il path caldo della chat non fa DDL (la tabella `conoscenza_appresa` è creata da `ardy-migrate.php` al deploy).
- **Dashboard**: bottone **📚 CONOSCENZA** (in ⚙︎ Strumenti) → modale con selezione fasi + proposta editabile + gestione blocchi appresi. Endpoint dietro Basic Auth (`.htaccess`).

**Giugno 2026 — Sessione 18/06 (email cliente complete, avviso fine chat, consegne/ritiri)**
- **Email avanzamento lavorazione** arricchita: spiega al cliente cosa può fare nella pagina (segue fasi/foto), codice personale sempre presente (con fallback all'email di benvenuto), link social.
- **Footer cliente condiviso** (codice + WhatsApp + social) centralizzato in `ardy-email.php` (`ardy_email_footer_cliente()` + helper) e applicato a **tutte** le email da Sole: benvenuto (`ardy-proxy.php`/`ardy-wa-agent.php`), lavorazione, ringraziamento, email libera (`ardy-email-cliente-api.php`, ora HTML), solleciti (`ardy-solleciti.php`, social solo livelli 1-2).
- **Avviso a fine chat** (`ardy-chiusura-sessioni.php`): cron orario che notifica Michela su WhatsApp quando una conversazione (web/WA) resta ferma >1h, con dati essenziali. Dedupe via `notificaMichela`. Cron `0 * * * *` come utente del sito.
- **Consegne/ritiri** (`ardy-trasporti.php`): email **"è pronto"** automatica alla transizione → COMPLETATO (guard `trasporto_pronto_at`) + **giornata Trasporti** in dashboard (bottone 🚚 TRASPORTI: assegni i COMPLETATO a una data e li avvisi via email, guard `trasporto_avviso_data`). Nuove colonne `trasporto_data/_pronto_at/_avviso_data`. Solo email per ora; WhatsApp predisposto (servono template Meta).

**Giugno 2026 — Sessione 17/06 (ha risposto, bozze social sul server, anteprima IG + foto, fix date)**
- **Indicatore 💬 ha risposto** in lista: badge sui clienti che hanno scritto (WA/sito) nelle ultime
  48h e non ancora "letti". `ardy-crm-api.php` calcola il flag con 2 query aggregate; marker
  `clienti.conversazione_letta_at` aggiornato da `ardy-conversazioni.php` all'apertura della chat.
- **Bozze social "salva per dopo" → server** (`ardy-social-bozze.php`, tabella `social_bozze`): prima
  solo in localStorage (si perdevano cambiando dispositivo). Ora multi-dispositivo/multi-utente, con
  modifica/elimina/pubblica sui singoli social + migrazione una-tantum dal vecchio localStorage.
- **👁 Anteprima Instagram + 🖼 gestione foto** (composer e bozze): mockup formato IG e ➕/✕ foto. Le
  foto aggiunte passano da `ardy-social-foto.php` → URL pubblico WP Media (richiesto da IG/FB via API).
- **Fix date azzerate (anti-clobber)**: `saveLead` non invia più `inizio_lavoro`/`fine_lavoro_prevista`
  vuote, così un salvataggio non azzera una data già in DB (clobbering da tab/dispositivo non aggiornato).
- **Git**: `main` riallineato alla lineage attiva (root `98b352f`); vecchia lineage orfana (`b49606b`)
  da non rifondere — nota operativa nel TODO. Nuovi endpoint dietro Basic Auth nel `.htaccess`.

**Giugno 2026 — Sessione 16/06 (multi-utente Andrea, root dominio, UX mobile sopralluogo)**
- **Multi-utente LIVE**: Andrea entra come Michela. Credenziali separate in `.htpasswd`
  (`michela` + `andrea`) e secondo numero WA in `ardy-config.php` (`WA_ANDREA_NUMBER`).
  `ardy-wa-lookup.php` riconosce entrambi come staff con prompt parametrizzato sul nome
  → cache prompt separata per ciascuno (`ardy_wa_titolare_istruzioni($datiSeparati, $nome)`).
  Sole chiama ciascuno per nome.
- **Root dominio → dashboard**: aggiunto `DirectoryIndex ardy-michela-app.html` in `.htaccess`.
  `https://ardyagent.ardy-lab.it/` apre direttamente la dashboard (resta dietro Basic Auth).
- **UX scheda mobile (sopralluogo)** — primo test sul campo OK:
  - Textarea Note 6 righe + bottone **⛶ Espandi** → modale fullscreen (`#noteEditorOverlay`).
  - Toggle **▾ Dati anagrafici** (Nome…Indirizzo) e **▾ Azioni cliente**
    (Email/WA/Genera contenuto/Note interne) chiusi di default su mobile (≤768px).
  - Session ID rimossa dalla UI (resta nel DOM, popolata dal JS).

**Giugno 2026 — Sessione 15/06 (attività piena, audit, template WA, ProntoPro)**
- **CRM in attività piena**: WhatsApp Cloud API attivo, carta Meta inserita, tutti e 4 i template
  approvati (`ringraziamento_consegna`, `aggiornamento_fase`, `sollecito_pagamento`, `notifica_michela`)
  e collegati in `ardy-config.php` (`WA_TEMPLATE_*` + `WA_APP_SECRET`). Test `ringraziamento_consegna` OK.
- **Audit performance** (`ardy-crea-reel.php`): confermato che la generazione reel è sincrona
  (worker FPM bloccato fino a 10 min, download foto in serie, 5 passaggi I/O, caption API sincrona).
  Bassa urgenza (uso singolo), registrato nel backlog performance con quick win e refactor asincrono.
- **Audit sicurezza** (`ardy-crea-reel.php`): `escapeshellarg()` su tutti i parametri FFmpeg ✅,
  sanitizzazione input ✅, SSRF via `ardySafeHttpGet` (con validazione host + redirect + protocolli) ✅.
  Rischio residuo basso: prompt injection via `$mobile` nella caption — registrato nel backlog sicurezza.
- **Progettazione Monitor ProntoPro + Funnel lead**: architettura completa nel TODO. Monitor: n8n ogni
  60 min legge email Gmail ProntoPro, Claude classifica (zona max 30km, tipo lavoro), notifica Michela
  su WA solo per lead ≥3/5. Funnel: Michela detta i dati a Sole su WA → scheda CRM → primo contatto
  al lead con conferma obbligatoria di Michela + link webchat personalizzato + tracciamento esito.

**Giugno 2026 — Sessione 14/06 sera (archivio, dossier, ringraziamento, single-social, fix WP)**
- **Archivio implicito CONSEGNATI** + **🧹 Libera spazio** (`ardy-elimina-cliente.php` azione `libera_spazio`) + **rimozione stato `PAGATO`** (ridondante con CONSEGNATO; il "saldato" è il modulo MOROSI).
- **Pubblicazione per singolo social**: toggle FB/IG in dashboard + nodo n8n "Meta" col gate `wantFB`/`wantIG` (vedi `ardy-pubblica-social-n8n.md`).
- **Fix immagini su WordPress**: `kses_remove_filters/init_filters` attorno a insert/update in `ardy-pubblica-lavorazione.php` (senza utente WP loggato kses rimuoveva img/video/style) + logging. **Reply-To** `ardy.documenti@gmail.com` sulle email fasi.
- **Ringraziamento alla consegna** (`ardy-grazie-consegna.php`): email automatica → CONSEGNATO (recensione Google + social + newsletter/disiscrizione), guard `consegnato_grazie_at`, bottone **📧 Reinvia**. WhatsApp pronto (serve `WA_TEMPLATE_GRAZIE`).
- **Dossier cliente** (`ardy-dossier.php`): MD completo (anagrafica + preventivi + fasi + chat WA + **chat web** ora persistita via `ardy-web-memoria.php`/`web_messaggi`). Bottone **📄 Dossier**. Iniettato in Sole **client-safe + compatto**: web dopo `cerca_cliente`, WhatsApp per numero (dossier in `system_static` → **cacheato**, nessuna modifica n8n).

**Giugno 2026 — Sessione 11 (Sole crea scheda da WhatsApp — Scenario 1)**
- **`ardy-wa-crea-scheda.php`**: nuovo endpoint server-to-server (stesso `WA_LOOKUP_SECRET` del lookup). In modalità titolare, Michela detta a Sole un cliente nuovo → Sole raccoglie i campi, li ripete, e **dopo conferma** emette un marker `[[CREA_SCHEDA]]{...json...}`. n8n intercetta il marker e POSTa il JSON all'endpoint, che fa l'**upsert** in `clienti` (`session_id` deterministico `wa-…` per telefono/nome → niente doppioni) e ritorna un riepilogo. Solo scheda cliente (no preventivo). Campi: nome, cognome, telefono, email, indirizzo, zona, servizio, mobile, stato, note.
- **Prompt titolare** aggiornato in `ardy-wa-lookup.php` (`ardy_wa_prompt_titolare`): istruzioni raccolta → conferma esplicita → marker.
- **`ardy-wa-crea-scheda-n8n.md`**: snippet del nodo Code n8n pronto da incollare (estrae il marker, chiama l'endpoint, ripulisce il messaggio per Michela).

**Giugno 2026 — Sessione 9 (modalità titolare WhatsApp + spostamento sopralluoghi)**
- **Modalità titolare**: `ardy-wa-lookup.php` riconosce il numero di Michela (`WA_MICHELA_NUMBER`) → `mode=titolare`. Sole le fa da assistente personale con un **riepilogo operativo dal CRM** (nuovi lead 7gg, quadro per stato, lavori in corso, sopralluoghi fissati, fasi, morosi). Niente flusso lead.
- **Spostamento sopralluoghi**: la data vera dell'appuntamento e l'id evento Google ora si salvano nel CRM (`sopralluogo_at`, `gcal_event_id`, auto-creati). Nuove funzioni in `ardy-gcal.php` (`gcal_is_slot_free`, `gcal_update_event`) e nuovo tool **`sposta_appuntamento`** in `ardy-proxy.php`: Sole verifica la disponibilità, sposta l'evento, aggiorna il CRM e avvisa Michela. Risolve anche la confusione sulle date nei riepiloghi (ora legge la data reale).
- **Fix nuovo cliente da dashboard**: la creazione manuale ora genera un `session_id` e usa `ardy-save-lead.php` (INSERT) invece di `ardy-update-lead.php` (era "session_id mancante").

**Giugno 2026 — Sessione 8 (notifiche a Michela + segretaria antipatica)**
- **Task 1 fatto**: Sole avvisa Michela su WhatsApp come una segretaria. Nuovo `ardy-notifica-michela.php` con doppio uso: libreria (`notificaMichela()` / `ardy_wa_send_michela()`, dedupe persistente su file) ed **endpoint HTTP** protetto da `WA_LOOKUP_SECRET`, così anche il ramo WhatsApp via n8n può avvisare Michela riusando lo stesso codice (invio via Cloud API).
- `ardy-proxy.php`: dopo lead salvato e/o sopralluogo fissato parte una **notifica consolidata** (riepilogo nome/telefono/servizio/mobile/zona/budget/appuntamento/note). Aggiunto il tool **`avvisa_michela`** che Sole chiama per reclami, problemi di pagamento, richieste di modifica e richieste fuori standard; prompt aggiornato in `ardy-system.txt`.
- Config nuova in `ardy-config.php`: `WA_TOKEN`, `WA_PHONE_NUMBER_ID`, `WA_MICHELA_NUMBER` (+ opzionali `WA_TEMPLATE_NOTIFICA`/`WA_TEMPLATE_LANG`). ⚠️ Per scrivere a Michela fuori dalla finestra 24h serve un **template Meta approvato**.
- **Task 2 fatto**: modulo gestione clienti morosi. Nuovo `ardy-solleciti.php` (tabella `solleciti_pagamento` auto-creata; API lista/crea/aggiorna/elimina/verifica/genera/invia) + `ardy-solleciti-system.txt` (prompt "segretaria antipatica", 4 livelli con riferimenti normativi: artt. 1326/1453/1454 c.c., D.Lgs. 231/2002).
- Dashboard: pulsante **💸 MOROSI** → modale con form nuovo caso, lista filtrabile per stato, verifica preventivo collegato, generazione AI del testo (modificabile), scelta canali WhatsApp/email e invio. Livelli 1-3 inviano; livello 4 = bozza diffida da inviare a mano (stato → DIFFIDA).
- `.htaccess`: aggiunto `ardy-solleciti.php` alle pagine protette da Basic Auth.
- ⚠️ Per inviare WhatsApp ai morosi fuori dalla finestra 24h serve un **template Meta approvato** (`WA_TEMPLATE_SOLLECITO` in `ardy-config.php`); riusa `WA_TOKEN`/`WA_PHONE_NUMBER_ID`.
- **Contesto fattuale all'agente** (rinforzo): in fase di generazione l'AI riceve preventivo (numero/totale/stato/condizioni), **fasi di lavorazione** (prova del lavoro svolto) e **storico WhatsApp** del cliente. Importi e date restano **deterministici** (li fissa il codice, l'AI non li ricalcola). La verifica distingue **bloccanti** (importo mancante, preventivo non accettato → fermano la generazione, con possibilità di "Genera comunque") da **avvisi** (da controllare a mano).
- **Task 4 fatto — Comunicazioni straordinarie**: secondo bottone **⚠ COMUNICAZIONE STRAORDINARIA** nella sezione lavorazione. Stesso endpoint `ardy-pubblica-lavorazione.php` con `tipo='comunicazione'`: blocco arancione + icona ⚠ sul sito, email con oggetto/tono dedicati, colonna `fasi.fase_tipo`, prompt Claude apposito, niente social; il reel esclude le comunicazioni.

**Giugno 2026 — Sessione 7 (deploy via git sul server)**
- **Deploy automatizzato via git** (chiuso il TODO storico). Prima i file si caricavano a mano via cPanel File Manager.
- Accesso SSH come **root** al VPS funzionante (la shell dell'utente cPanel `micoperibg` resta disabilitata; si opera con `runuser -u micoperibg`).
- Creata **Deploy key read-only** sul repo GitHub (`~micoperibg/.ssh/github_ardyagent`); repo clonato in `/home/micoperibg/repositories/ardyagent` (fuori dal document root).
- Aggiunti `deploy.sh` (rsync selettivo, niente `--delete`, preserva config/upload/vendor) e `.cpanel.yml` (deploy push-button cPanel di riserva).
- Aggiornamento sito = `runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'`.
- **Video nelle lavorazioni**: nuovo `ardy-upload-video.php` (upload multipart, max 150 MB, MIME reale, → WP Media Library). Dashboard con pulsanti 🎥/🎬 che caricano subito; i video finiscono nel post WP come `<video>` e in `fasi.video_urls`. Reel non impattato (usa solo le foto).
- **Rebranding assistente "Ardy" → "Sole"** (solo il nome della persona AI; "Ardy Lab/Express/School", dominio e account social restano). Aggiornati: `ardy-system.txt`, `ardy-whatsapp-system.txt`, `ardy-proxy-lavorazione.php`, `ardy-widget-lavorazione.js`, `ardy-guida-michela.html`, `ardy-stats.php`, oggetto email lead in `ardy-proxy.php`. ⚠️ Il pulsante sul sito WordPress ("Chatta/Parla con Ardy") è uno snippet WPCode **fuori da questo repo**: va aggiornato a "Sole" a mano sul sito.
- **Sezione cura/manutenzione post-lavoro** nei prompt (`ardy-system.txt` + `ardy-proxy-lavorazione.php`): Sole risponde da sola alle domande di manutenzione/pulizia/ravvivatura dei clienti soddisfatti (così non gravano su Michela) e, quando serve un intervento vero, propone il servizio a pagamento (ravvivatura/laccatura / Ardy Express).
- **WhatsApp Cloud API ATTIVO** col numero dedicato eSIM **+39 379 375 6437** (Phone Number ID `1151535311377293`, WABA "Ardy lab" `1235593451848137`, token permanente da Utente di sistema). Flusso: Meta → `ardy-whatsapp-webhook.php` → n8n (Webhook `ardy-whatsapp`, nodo Code per evitare il timeout dell'HTTP Request) → `ardy-wa-lookup.php` (classifica numero + restituisce system prompt) → Claude → invio risposta via Cloud API. Test end-to-end OK. Segreto `WA_LOOKUP_SECRET` in `ardy-config.php`.

**Giugno 2026 — Sessione 6 (primo lead: fix mail + calendario + foto scheda)**
- **Primo lead reale** arrivato dal widget AI (Giulia Di Fazio). Emersi alcuni bug dopo il ripristino del server.
- **Fix mail notifiche lead (causa: firewall):** dopo il ripristino del server, in `/etc/sysconfig/nftables.conf` (servizio `nftables` attivo al boot) era rimasto un blocco di **SMTP blocking** che **dirottava tutto l'SMTP in uscita di PHP-FPM sul mail server locale (Exim)**. PHP-FPM (utente `micoperibg`) finiva contro Exim → certificato sbagliato (`vps-...ovh-net`) → `SSL certificate verify failed` → mail a Michela e conferme cliente non inviate. Root era esente, quindi da CLI sembrava tutto ok. **Soluzione:** rimosse le 2 righe `redirect` dal file di boot (persistente) e dalle regole live nft — senza reboot.
- **Agente: forzata la creazione evento calendario** (`ardy-system.txt`): il prompt proponeva gli slot ma non chiamava mai `fissa_appuntamento_calendario`, quindi l'evento non veniva creato (i dati appuntamento finivano solo nel CRM). Rinforzato il Passo 7 e la sezione GESTIONE CALENDARIO con il flusso obbligatorio dei tool (disponibilità → fissa appuntamento → salva CRM).
- **Dashboard — "Foto della scheda"** (`ardy-lead-foto.php` + `ardy-michela-app.html`): nuova sezione nel dettaglio cliente, **visibile in ogni stato** (LEAD, SOPRALLUOGO, ecc.). Mostra le foto inviate dal cliente in chat e permette di **aggiungerne di nuove** (scatto o galleria), salvate in `ARDY_UPLOAD_DIR/<sessione>/`. Nuovo endpoint protetto da Basic Auth (`.htaccess`), con controllo MIME reale e niente path-traversal.
- **Da fare prossima sessione:** deploy via git sul server, caricamento **video** nelle lavorazioni, autenticazione dominio Brevo (DKIM).


**Giugno 2026 — Sessione 5 (SEO sito + integrazione AI agent)**
- **Prompt agente pubblico** (`ardy-system.txt`): aggiunta sezione *APERTURA — PRIMO MESSAGGIO E ROUTING* con saluto a **doppia modalità**:
  - Nuovo contatto/lead → processo di qualifica esistente
  - Cliente esistente → riconoscimento (nome + telefono) e rimando alla chat dedicata sulla pagina del lavoro
- **Integrazione sito (snippet WPCode sul sito WordPress ardy-lab.it, non in questo repo):**
  - Pulsante flottante "Chatta con Ardy" → `/ardy-agent/`, su tutto il sito **tranne** pagine `/lavori-in-corso/` (chat cliente dedicata) e `/project/` (corsi)
  - Pulsante flottante contestuale sui corsi → `/ardy-agent/?corso=<nome>`
  - Pagina `/ardy-agent/` in "modalità corso" quando arriva `?corso=`: intestazione/suggerimenti dedicati e avvio chat già sul corso (il nome corso viene inviato come primo messaggio al proxy)
- **SEO (sito WordPress):**
  - Schema **LocalBusiness** via filtro Yoast (`wpseo_schema_organization`) — unica entità, no doppioni (rimosso vecchio snippet `#localbusiness` da Integrazioni Divi)
  - Schema **Course** sui 9 corsi (CPT `project`), `provider` collegato all'@id Yoast
  - Title/meta home + **nome canonico "Ardy Lab"**
  - Rimosso `noindex` dal CPT `project` (corsi) + richiesta indicizzazione in Search Console
  - Cloudflare: regola "Skip" per IP proprio (audit/test); Googlebot verificato passa regolarmente

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
