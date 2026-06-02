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

## 🧩 Architettura sistema

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
```

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

- [ ] Layout PDF da rifinire graficamente
- [ ] Pagina "I nostri lavori" con foto portfolio nel PDF
- [ ] Fix chatbot pubblico (ardy-proxy.php — errore API)
- [ ] Invio email automatico preventivo al cliente
- [ ] Migrazione server (pianificata)
- [ ] Render AI mobile per preventivi (fase B — Stable Diffusion)

---

## 📝 Note sessioni precedenti

**Giugno 2026** — Costruita dashboard completa, generatore PDF con mPDF,
libreria fasi, storico preventivi su DB, fix doppio salvataggio,
bottoni sidebar, manuale utente Word.
