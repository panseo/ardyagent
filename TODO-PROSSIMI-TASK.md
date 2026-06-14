# Ardy Lab — Task aperti & note utili

> TODO ripulito: tenuti solo i task ancora aperti + le note operative che servono sempre.
> Tutto ciò che è già fatto **e deployato** è stato rimosso (lo storico resta nei commit git).

---

## ✅ FATTO E DEPLOYATO — sessione 14/06/2026 (pomeriggio/sera)
Branch `claude/sharp-einstein-pmuqq3`, tutto su `main` e deployato. Testato dal vivo dove indicato.
- **Archivio implicito CONSEGNATI** (dashboard): lo stato conclusivo CONSEGNATO esce dalla lista
  TUTTI; pulsante **📦 Archivio (N)** + chip ARCHIVIO. ✅ testato.
- **🧹 Libera spazio** (`ardy-elimina-cliente.php`, azione `libera_spazio`, conferma "LIBERA"):
  cancella solo foto+reel del cliente concluso, tiene scheda/preventivi+PDF/fasi/sito; segna
  `foto_archiviate_at`. Bottone sulla scheda solo da archiviato.
- **Rimosso lo stato cliente `PAGATO`** (coincideva con CONSEGNATO; il "saldato/non moroso" è il
  modulo MOROSI). Alias legacy mantenuto per non orfanare schede già marcate.
- **Pubblicazione social per singolo social** (dashboard + nodo n8n "Meta" aggiornato): toggle FB/IG,
  campo `piattaforme`. Vedi `ardy-pubblica-social-n8n.md`.
- **BUG immagini su WordPress** ✅ risolto e testato: l'endpoint gira senza utente WP loggato →
  kses rimuoveva `<img>`/`<video>`/`style`. Aggiunto `kses_remove_filters/init_filters` attorno a
  insert/update + logging. Regola: 1ª foto = in evidenza, le altre nel corpo.
- **BUG email fasi mittente** ✅: From resta `noreply@ardy-lab.it` (DKIM Brevo) + **Reply-To**
  `ardy.documenti@gmail.com` così le risposte del cliente arrivano a Michela.
- **Ringraziamento alla consegna** (`ardy-grazie-consegna.php`): alla transizione → CONSEGNATO parte
  l'email (recensione Google + social + newsletter/disiscrizione), una sola volta (`consegnato_grazie_at`).
  Bottone **📧 Reinvia ringraziamento** sulla scheda. ✅ email + ✅ **WhatsApp testati dal vivo** (template
  Meta `ringraziamento_consegna` approvato + `WA_TEMPLATE_GRAZIE` in config).
- **Dossier cliente** (`ardy-dossier.php` + `ardy-web-memoria.php`): MD completo (anagrafica +
  preventivi + fasi + chat WA + chat web). Bottone **📄 Dossier** in dashboard. Chat web ora persistita
  (`web_messaggi`, scritta da `ardy-proxy.php`). Wiring in Sole **client-safe + compatto**: web dopo
  `cerca_cliente`, WhatsApp per numero (dossier in `system_static` → **cacheato**). ⏭️ da provare dal vivo.

### ⚙️ CONFIG DA METTERE SUL SERVER (`ardy-config.php`, a mano — non in repo)
- `define('GRAZIE_GOOGLE_REVIEW_URL', 'https://g.page/r/CRnhYaazgbV2EAE/review');` → mostra il bottone
  recensione nell'email di ringraziamento. (Link già fornito da Michela; verificare che sia in config.)
- (opz.) `GRAZIE_IG_URL` / `GRAZIE_FB_URL` (default: ardy.lab / pagina "Ardy").
- ✅ `define('WA_TEMPLATE_GRAZIE', 'ringraziamento_consegna');` → WhatsApp di ringraziamento attivo e testato.

---

## ▶️ PROSSIMA SESSIONE — da dove ripartire
Branch di lavoro: verrà assegnato uno nuovo (allineato a `main`). Dopo ogni push, deploy con il
comando nelle NOTE OPERATIVE. Ordine consigliato:
1. **Verifiche dal vivo** del blocco 14/06 sera: dossier in Sole (web: dare il codice in chat →
   risposta con contesto; WhatsApp: scrivere da numero registrato), ringraziamento con bottone
   recensione, archivio/libera-spazio su mobile.
2. **Task nuovi pronti da costruire** (vedi sotto): ~~FAQ su CONSEGNATO~~ (✅ fatto), **Cestino 30gg**,
   **Backup widget WP**, **Logo nelle email**. (Catalogo prezzi Google Sheet → ❄️ congelato, vedi sotto.)
3. **Task grande da valutare**: **Sole esperta legno/restauro + community** (richiede progettazione).

---

## 🔧 NOTE OPERATIVE (servono sempre)

**Deploy sul server** (da root):
```
runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'
```

**Log errori/eventi PHP** (debug + verifica prompt caching):
```
/home/micoperibg/logs/ardyagent_ardy-lab_it.php.error.log
# es: grep "ARDY USAGE" <file> | tail -8   → in/out/cache_read/cache_write
```

**Auth endpoint chiamati via fetch**: NON usare `ardyRequireAuth()` (in CGI/FPM l'header
`Authorization` non arriva a PHP → rifarebbe login). Affidarsi al `.htaccess` (Basic Auth).

**`session_id`**: sempre sanificato (no path traversal) prima di toccare i path file.

**n8n**: due workflow — "Meta" (ramo post-foto = social FB/IG; ramo Reels) e "WhatsApp" (webhook
`ardy-whatsapp` → nodo Code che chiama `ardy-wa-lookup.php`/`ardy-wa-memoria.php` → Claude). Il nodo
WhatsApp è già **prompt-caching ready** (`system_static` con `cache_control`).

---

## ⏳ DA VERIFICARE (codice pronto, manca la prova dal vivo)
- **Dossier in Sole** (web + WhatsApp): vedi punto 1 "Prossima sessione".
- **Prompt caching titolare (WhatsApp)**: prova dal numero VERO di Michela ("come va oggi?") → Sole
  risponde con dati reali del CRM.
- **"Sole crea scheda da WhatsApp"** (`[[CREA_SCHEDA]]`): prova end-to-end dal numero di Michela →
  "Scheda creata ✅" + scheda in dashboard (LEAD). Se errore: Executions del nodo Code in n8n.
- **Template `sollecito_pagamento`**: provare con un caso moroso vero.

---

## 🚧 BLOCCHI ESTERNI (azioni di Michela su Meta, non codice)
- **Carta di credito su Meta** → sblocca i messaggi business→cliente/Michela **fuori dalle 24h**
  (template: `notifica_michela`, `sollecito_pagamento`, fasi, ringraziamento). Senza, le notifiche
  proattive non partono. Michela che scrive a Sole resta gratis. WhatsApp Manager → Fatturazione.
- **Template Meta da creare/approvare**, poi settare in `ardy-config.php`:
  - `aggiornamento_fase` (4 var) → `WA_TEMPLATE_FASI`
  - ✅ ~~ringraziamento consegna (1 var nome) → `WA_TEMPLATE_GRAZIE`~~ — fatto: `ringraziamento_consegna` approvato e in config.

---

## 📋 TASK DA SVILUPPARE

### ✅ "Crea FAQ di questa lavorazione" (su stato CONSEGNATO) — FATTO E TESTATO DAL VIVO (14/06)
Implementato: `ardy-crea-faq.php` (azioni `genera` | `pubblica`) + sezione **❓ FAQ della lavorazione**
in dashboard (visibile solo per CONSEGNATO, accanto al Reel). Generazione + pubblicazione provate ✅.
- `genera`: Claude scrive 5-7 FAQ da mobile/servizio/fasi → anteprima **modificabile** (domanda/risposta
  editabili, rimozione singola).
- `pubblica`: accoda un blocco FAQ all'articolo WP della lavorazione (`<details>` accordion) +
  **JSON-LD `schema.org/FAQPage`** per la SEO. **Idempotente**: il blocco è delimitato da marcatori
  `ARDY_FAQ_START/END` → ripubblicando si sostituisce, non si duplica. Segna `faq_pubblicata_at` sul CRM.
- ⏭️ Resta solo da confermare il **rich result** col Google Rich Results Test sull'URL dell'articolo.

### 🆕 (DA VALUTARE — grande) Sole esperta di legno & restauro + datazione fotografica guidata
Conoscenza profonda di legno/restauro/stile + **datazione/epoca** via **rilievo fotografico guidato**
(coda di rondine fatta a mano o a macchina? fondello cassetto in massello? segni industriali? ecc.).
- **Knowledge base**: cercare in rete **fonti autorevoli** (legno, tecniche per epoca, storia del
  mobile, restauro) → archivio organizzato (schede per stile/epoca + criteri diagnostici). Progettare
  formato (MD/JSON), storage (file/DB), retrieval (mini-RAG per non gonfiare i token).
- **Flusso diagnostico guidato**: Sole conduce passo-passo (foto mirate) → ipotesi stile/epoca con
  confidenza + motivazione. Riusa l'analisi foto già nella chat web.
- ⚠️ **Accesso riservato** a **clienti** o **membri community** (community popolata dal modulo
  **Outreach**): serve registro membri + gate (web: codice/iscrizione; WhatsApp: numero registrato).
- Realismo: datazione = **stima motivata con confidenza**, non verdetto. Buon lead-magnet community.

### ⭐ Backup & centralizzazione dei widget WordPress
✅ infra `wordpress-snippets/` + `ardychat` centralizzato (`ardy-chat-site.js`).
⏭️ 1) **Export completo WPCode** (Tools → Export All → JSON) splittato in `wordpress-snippets/` (un
file per snippet) + mappa nel README. 2) **Centralizzare gli altri widget front-end** (`Chat per i
corsi` → `ardy-chat-corsi.js` + loader HTML; poi i pulsanti CTA). Gli snippet PHP restano backup-only.
⚠️ Trappola: il loader `<script src>` va in uno snippet WPCode di tipo **HTML**, MAI JavaScript.

### 🗑️ Gestione archivio cliente — resta il CESTINO 30 giorni
✅ già fatto: hard-delete (🗑 Elimina) + 🧹 Libera spazio. ⏭️ da costruire:
1. **"Elimina tutto" → CESTINO 30gg**: soft-delete `deleted_at` su `clienti` → vista Cestino con
   Ripristina; dopo 30gg purga DB + file (foto, reel, PDF). NON tocca WordPress/Media Library. Purga =
   sweep opportunistico (es. in `ardy-crm-api.php`, max N per load), niente cron.
2. *Backend* `ardy-elimina-cliente.php`: azioni `cestina | ripristina | purga` (colonna `deleted_at`
   auto-create; riusa `ardy_elimina_file_sessione`). *API CRM*: escludere `deleted_at` dalla lista.
   *Dashboard*: vista Cestino + Ripristina + modali conferma.

### ❄️ Catalogo prezzi su Google Sheet — CONGELATO (14/06)
**Congelato**: niente permessi alla vendita di prodotti (WooCommerce disattivato dal sito). La parte
vendita verrà gestita da un **agente dedicato a parte**, non da Sole. Da riprendere solo in quel contesto.
~~Oggi i prezzi sono hardcoded in `ardy-system.txt` → cambiarli = editare + deploy. Un foglio Google
letto da Sole (sa già leggere Calendar/Drive) farebbe aggiornare i prezzi a Michela da sola. Da
progettare: foglio modello + endpoint/funzione che lo legge e lo inietta nel prompt (cache breve).~~

### Logo nelle email
Header email oggi testuale ("ARDY LAB"). Mettere il logo immagine (`assets/logo.png`) in cima a TUTTE
le email (grazie, fasi, benvenuto, outreach). URL assoluto (`https://ardy-lab.it/.../logo.png`) o
base64 inline (Brevo API = no cid). Valutare un piccolo helper "header email" condiviso.

### Briefing del mattino — opzionale rimasto
⏭️ trigger "prima risposta del giorno": salvare data ultimo briefing per numero così il riepilogo
lungo parte da solo al primo "buongiorno". Senza, funziona quando Michela chiede "come va oggi?".

### Migliorie minori UX (bassa priorità)
- **Popup date all'attivazione IN_LAVORAZIONE**: al click stato, modale che chiede `inizio_lavoro`/
  `fine_lavoro_prevista`. Tocca solo `ardy-michela-app.html/.css`.
- **Filtro sidebar default su ACCONTO/IN_LAVORAZIONE** invece di TUTTI (da decidere sull'uso reale).

---

## 🔒 BACKLOG SICUREZZA (priorità bassa)
> Difesa infrastrutturale già presente (OVH Anti-DDoS, Fail2ban, ModSecurity WAF, mod_hulk).
- **OAuth Google senza `state`** (`ardy-gcal-auth.php`): aggiungere `state` casuale verificato.
- **`get_stats` SQL** (`ardy-outreach-api.php`): query con interpolazione (da array interno) → parametrizzare.
- **`mode=download` preventivo**: serve qualsiasi PDF a chi è dietro Basic Auth (no ownership). Basso rischio.

---

## ⚡ BACKLOG PERFORMANCE
### Alto impatto / basso sforzo
- **Ricerca telefono full-scan** (`ardy-wa-lookup.php`, `ardy-proxy.php`): `REPLACE(...) LIKE '%...'`
  impedisce gli indici → colonna `telefono_last9` normalizzata + indice, match esatto.
- **DDL su ogni request** (`SHOW COLUMNS`/`ALTER`/`CREATE TABLE IF NOT EXISTS` in vari endpoint):
  spostare in una migrazione one-shot, togliere dal path di richiesta.
- **`ardy-crm-api.php`**: `SELECT *` su `clienti` senza `LIMIT` → solo colonne usate + paginazione + indice.

### Da pianificare
- Cache PDF preventivo per content-hash + memoizzazione logo base64 (`ardy-preventivo.php`).
- Estrarre JS/CSS dalle HTML monolitiche + header cache/cache-busting.
- Rate-limit su APCu/Redis invece che su file (`ardy-proxy.php`).
- Unificare `dbConnect()` (mysqli) di `ardy-preventivo.php` sul PDO di `ardyDB()`.

---

## 📄 FUORI REPO / OPERATIVO
- **Termini & Condizioni su WordPress**: aggiornarli coerenti con l'informativa GDPR del preventivo PDF.
- **Import preventivi storici**: strumento pronto (`ardy-import-preventivi.php`, CSV + PDF). Michela
  mette i PDF in Google Drive → si genera il CSV precompilato e si importa.

---

## ⏸️ IDEE RIMANDATE / SCARTATE
- **Codice d'accesso su WhatsApp** (rimandato): su WA il numero = identità, il codice serve solo per
  numeri non registrati (raro). Servirebbe marker `[[CERCA:ARD-XXXX]]` + lookup per `codice_accesso`.
- **Scartati (non riproporre)**: foto/video WhatsApp che attivano fasi (pipeline media Meta assente);
  WhatsApp come "telecomando" unico della webapp. Si tiene solo Scenario 1 (creazione scheda da dati/PDF).

---

## 💶 Nota costi (riferimento)
- **Costo dominante = API Claude per messaggio**. Mitigato col **prompt caching**: chat web ✅,
  lavorazione ✅, WhatsApp ✅ (incluso il dossier in `system_static`), titolare da verificare dal vivo.
- **Meta**: Michela↔Sole user-initiated = gratis; costi solo su template business→cliente fuori 24h
  (Utility ~3-4 cent/msg).
- Media Meta **scadono** → scaricarli subito col media ID.
