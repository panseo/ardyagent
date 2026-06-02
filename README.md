# Ardy Lab — Sistema Ardy Agent

Sistema di gestione clienti, preventivi e agente AI per **Ardy Lab** (Michela Panella).

---

## 🌐 URL e accessi

| Risorsa | URL |
|---|---|
| Dashboard Michela | `https://ardyagent.ardy-lab.it/ardy-michela-app.html` |
| Chatbot pubblico | `https://ardy-lab.it/ardy-agent/` |
| VPS (WHM) | IP: `57.131.47.5` — accesso solo via WHM/cPanel |
| Database | `micoperibg_ardyagent` su `localhost` |

---

## 🗂 Struttura file

```
ardyagent.ardy-lab.it/
├── ardy-michela-app.html      # Dashboard principale Michela
├── ardy-preventivo.php        # Generatore preventivi PDF (mPDF)
├── ardy-proxy.php             # Proxy API Claude per chatbot pubblico
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
├── ardy-pubblica-lavorazione.php  # Pubblica aggiornamenti lavorazioni
├── ardy-get-fasi.php          # API fasi lavorative
├── ardy-unsubscribe.php       # Gestione unsubscribe email
├── ardy-rate-limit/           # ⚠️ NON in repo — rate limiting
├── ardy-system.txt            # Prompt sistema agente AI
├── assets/
│   └── logo.png               # Logo Ardy Lab
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

#### `preventivi`
Storico preventivi generati per ogni cliente.

```sql
id              INT AUTO_INCREMENT PRIMARY KEY
session_id      VARCHAR(255)    -- ID del lead (da tabella leads WP)
numero          VARCHAR(50)     -- es. ARD-2026-1234
tipo            VARCHAR(100)    -- tipo preventivo
oggetto         TEXT
cliente_nome    VARCHAR(255)
cliente_email   VARCHAR(255)
note            TEXT
condizioni      TEXT
voci_json       LONGTEXT        -- array voci in JSON
subtotale       DECIMAL(10,2)
grand_total     DECIMAL(10,2)
file_pdf        VARCHAR(255)    -- nome file in preventivi_pdf/
stato           VARCHAR(50)     -- bozza|inviato|accettato|rifiutato
data_emissione  VARCHAR(20)
data_scadenza   VARCHAR(20)
created_at      TIMESTAMP
updated_at      TIMESTAMP
```

#### Lead/Clienti
I lead sono gestiti tramite **WordPress** sul sito principale `ardy-lab.it`.
La dashboard li legge tramite API (session_id come chiave).

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

[Ardy Outreach — in costruzione]
        ↓ ardy-outreach.html (dashboard newsletter B2B)
        ↓ ardy-outreach-api.php
        ↓ n8n (automazione flussi — credenziali attive)
        ↓ Canali social (in integrazione)
```

---

## 📣 Canali social e integrazioni (stato attuale)

| Canale | Stato | Note |
|---|---|---|
| **Google Business** | ⏳ In attesa | Autorizzazioni Google Console richieste — in attesa approvazione |
| **n8n** | ✅ Credenziali attive | Automazione flussi pronta da collegare |
| **Instagram** | ❌ Problemi Meta | Casini da risolvere lato Meta — problema non ancora identificato |
| **Facebook** | ❌ Problemi Meta | Stesso problema Instagram |
| **WhatsApp Business** | 🔧 Da connettere | Da integrare nella dashboard e nei flussi n8n |
| **LinkedIn** | 🔧 Da connettere | Da integrare per outreach B2B |

---

## 📤 Ardy Outreach (secondo agente — in costruzione)

Dashboard separata per la gestione newsletter e outreach verso aziende potenzialmente interessate ai servizi Ardy Lab.

**File principali:**
- `ardy-outreach.html` — dashboard outreach
- `ardy-outreach-api.php` — API backend outreach
- `ardy-email-finder.php` — ricerca email aziende target
- `ardy-unsubscribe.php` — gestione unsubscribe

**Flusso previsto:**
1. Identificazione aziende target (interior design, arredamento, immobiliare)
2. Ricerca contatti email
3. Invio newsletter personalizzata tramite n8n
4. Tracciamento aperture e risposte
5. Gestione unsubscribe automatica

**Stato:** In costruzione — in attesa di risolvere problemi Meta e connessione canali social

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
| `?mode=debug` | POST | Mostra HTML grezzo (debug) |

### Struttura PDF (6 pagine)
1. **Copertina** — sfondo nero, logo tipografico "ardy lab", servizi, contatti
2. **Storia** — storia famiglia Panella (opzionale, checkbox nel form)
3. **Tecnica** — tipo intervento, oggetto, note tecniche
4. **Costi** — tabella voci, subtotal, bollo €2, spedizione, grand total
5. **Firma** — dati cliente, validità 30gg, spazio firma
6. **Grazie** — sfondo azzurro #7bb8d4

### Regime fiscale
Michela è in **regime forfettario** — IVA sempre 0%, dicitura legale automatica:
> "Operazione esente IVA ai sensi dell'art. 1 c. 54-89 L. 190/2014"

### Dati azienda fissi (hardcoded nel PHP)
```
Ardy di Michela Panella
Via Kafka 14, 00143 Roma (RM)
P.IVA: 17633931005
C.F.: PNLMHL99A48H501E
Email: ardy.documenti@gmail.com
Web: www.ardy-lab.it
```

---

## 🖥 Dashboard Michela (ardy-michela-app.html)

Single-file HTML/CSS/JS — nessun framework, nessuna dipendenza esterna.

### Funzionalità
- Lista clienti/lead con filtri per stato e ricerca
- Dettaglio cliente con note modificabili
- Cambio stato cliente (Lead → Sopralluogo → Preventivo → Acconto → Standby → Perso)
- Azioni rapide: contenuto AI, post social, proforma, email, WhatsApp, note interne
- **Generatore preventivi PDF** con form completo
- **Storico preventivi** per cliente (dal DB)
- **Libreria fasi lavorative** (localStorage) con 12 fasi predefinite
- Aggiunta manuale clienti

### Stati cliente
`LEAD` → `SOPRALLUOGO` → `PREVENTIVO` → `ACCONTO` → `STANDBY` → `PERSO`

### Libreria fasi predefinite
Categorie: Preparazione, Verniciatura, Finitura, Falegnameria, Restauro, Logistica  
Salvate in `localStorage` — le fasi custom si perdono cambiando browser.

### Variabili JS importanti
```javascript
const BASE_URL       = 'https://ardyagent.ardy-lab.it';
const UPDATE_URL     = BASE_URL + '/ardy-update-lead.php';
const PREVENTIVO_URL = '/ardy-preventivo.php';
currentLead          // oggetto lead selezionato
pvCurrentSessionId   // session_id salvato all'apertura modal preventivo
pvVoci               // array voci preventivo corrente
```

---

## ⚙️ Configurazione server

**VPS:** OVH AlmaLinux — gestito via WHM/cPanel  
**Accesso:** Solo WHM — SSH e FTP disabilitati per sicurezza  
**PHP:** Con `exec()` disabilitata → per questo si usa mPDF invece di wkhtmltopdf  
**Composer:** Installato in `/home/micoperibg/public_html/ardyagent.ardy-lab.it/`

### Installare le dipendenze dopo deploy
```bash
cd /home/micoperibg/public_html/ardyagent.ardy-lab.it
php composer.phar require mpdf/mpdf
```

### File da creare manualmente sul server (NON in repo)
- `ardy-config.php` — credenziali DB
- `ardy-gcal-token.json` — token Google Calendar
- Cartella `preventivi_pdf/` con permessi 755
- Cartella `ardy-uploads/` con permessi 755

---

## 🔄 Workflow aggiornamenti

### Dal PC Debian (repo locale su hard disk esterno)
```bash
cd /media/bebo/Archivio/progetti/ardyagent

# Modifica i file localmente
# Poi:
git add -A
git commit -m "descrizione modifica"
git push
```

### Deploy sul server
Dopo il push, caricare manualmente i file modificati via **cPanel File Manager**.

---

## 📦 Dipendenze

| Libreria | Versione | Uso |
|---|---|---|
| mPDF | ^8.3 | Generazione PDF da HTML |
| PHPMailer | locale | Invio email |

---

## 🚧 TODO / Sviluppi futuri

### Dashboard Michela
- [ ] Layout PDF da rifinire graficamente
- [ ] Pagina "I nostri lavori" con foto portfolio nel PDF
- [ ] **Proforma fatture** — generatore proforma da copiare su QuikFisco
- [ ] Invio email automatico preventivo al cliente
- [ ] Fix chatbot pubblico (`ardy-proxy.php` — errore API)
- [ ] Render AI mobile per preventivi (fase B — Stable Diffusion)

### Integrazioni social e canali
- [ ] **Google Business** — completare autorizzazioni Google Console
- [ ] **WhatsApp Business** — connettere alla dashboard e a n8n
- [ ] **Instagram** — risolvere problemi Meta
- [ ] **Facebook** — risolvere problemi Meta (stesso issue Instagram)
- [ ] **LinkedIn** — integrare per outreach B2B
- [ ] Collegare n8n ai canali social una volta risolti i problemi Meta

### Ardy Outreach
- [ ] Completare dashboard `ardy-outreach.html`
- [ ] Collegare flussi n8n per invio newsletter automatiche
- [ ] Integrare tracciamento aperture email
- [ ] Test end-to-end campagna outreach B2B

### Infrastruttura
- [ ] Migrazione server (pianificata — da definire tempistiche)
- [ ] Automatizzare deploy da GitHub al server (ora manuale via cPanel)

---

## 📝 Note sessioni precedenti

**Giugno 2026** — Costruita dashboard completa, generatore PDF con mPDF,
libreria fasi, storico preventivi su DB, fix doppio salvataggio,
bottoni sidebar, manuale utente Word.
