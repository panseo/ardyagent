# Ardy Lab — Task aperti & note utili

> TODO ripulito: solo i task ancora **aperti** + note operative + verifiche/azioni residue.
> Tutto ciò che è fatto **e deployato** è rimosso (lo storico resta nei commit git).

---

## ▶️ STATO (15/06/2026 — sera)
CRM in attività piena. WhatsApp Cloud API attivo, tutti i template approvati e collegati.
- ✅ **Monitor portali lead** in produzione (n8n ogni 60min, Gmail→Claude→WA a Michela). Portali: ProntoPro, Homedeal, Cronoshare. Instapro in attesa cambio email.
- ✅ **Cestino 30 giorni**: soft-delete con ripristino, purga automatica >30gg, modal nella dashboard.
- ✅ **Stato COMPLETATO** aggiunto tra IN_LAVORAZIONE e CONSEGNATO.
Prossimi task per priorità: UX minori · briefing del mattino · backlog performance/sicurezza.

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

**PDF preventivo**: la cache è per content-hash (`PDF_CACHE_VER` in `ardy-preventivo.php`). Se cambi
il layout/CSS del PDF, **bumpa `PDF_CACHE_VER`** per invalidare le cache esistenti.

---

## ⏳ DA VERIFICARE DAL VIVO / AZIONI MANUALI
- ✅ **Template `ringraziamento_consegna`** testato (15/06/2026) con cliente fittizio + reinvio → WA arrivato.
- **Template `aggiornamento_fase`** (4 var): pubblica una fase sul cliente fittizio con numero reale →
  verifica che arrivi il WA con nome/mobile/fase/link correttamente compilati.
- **Template `sollecito_pagamento`**: provare con un caso moroso vero (o fittizio).
- **Dossier in Sole** (web: dare il codice in chat → risposta con contesto; WhatsApp: scrivere da
  numero registrato).
- **Prompt caching titolare (WhatsApp)**: dal numero VERO di Michela ("come va oggi?") → Sole risponde
  con dati reali del CRM.
- **"Sole crea scheda da WhatsApp"** (`[[CREA_SCHEDA]]`): end-to-end dal numero di Michela → "Scheda
  creata ✅" + scheda in dashboard (LEAD). Se errore: Executions del nodo Code in n8n.
- **FAQ lavorazione**: confermare il **rich result** col Google Rich Results Test sull'URL dell'articolo.
- **Conoscenza di bottega di Sole** (`ardy-conoscenza-restauro.txt`): è una **v1** → Michela la rivede
  e la "ardy-izza" con le sue tecniche/parole. Prova dal vivo: chiedere a Sole cura del legno /
  riconoscere uno stile → competente ma concisa, ipotesi come "stima", niente prezzi inventati.
- **Export WPCode** (Tools → Export All → JSON): rinfrescare il backup `wordpress-snippets/` + mappa.

---

## 🚧 BLOCCHI ESTERNI (azioni di Michela su Meta, non codice)
- ✅ **Carta di credito su Meta inserita (15/06/2026)** → sbloccati i messaggi business→cliente fuori dalle 24h.
- ✅ **Template Meta tutti APPROVATI (15/06/2026)**: `ringraziamento_consegna` (Marketing, 1 var),
  `aggiornamento_fase` (Utility, 4 var), `sollecito_pagamento` (Utility, 1 var), `notifica_michela`
  (Utility, 1 var). Codice di invio già implementato per tutti.
- ✅ **`ardy-config.php` completo (15/06/2026)**: tutti e 4 i `WA_TEMPLATE_*` definiti + `WA_APP_SECRET`
  impostato (verifica firma webhook). Nessun collante mancante.
- ⚠️ **Verifica conteggio variabili `aggiornamento_fase`**: il codice manda **4 var** ({{1}} nome ·
  {{2}} mobile · {{3}} fase · {{4}} link). Confermare che il body del template Meta abbia esattamente
  4 variabili — se diverso Meta rifiuta (err 132000/132018). Verificare col test "pubblica fase" sopra.

---

## 📋 TASK DA SVILUPPARE (aperti)

### 🎯 ~~Funnel lead a pagamento~~ ✅ FATTO (15/06/2026)
Flusso: Sole segnala → Michela risponde su ProntoPro → se il lead non risponde → Michela
detta i dati a Sole (`[[CREA_SCHEDA]]` + `[[CONTATTA_LEAD]]`) → WA con link webchat
personalizzata → lead riconosciuto per nome. Template Meta `primo_contatto_lead` (Marketing,
3 var). **Bonus (15/06 sera):** se il lead risponde direttamente sul WhatsApp invece di
cliccare il link, `ardy-wa-lookup.php` lo riconosce (`mode=lead_portale`, lookup su
`primo_contatto_wa_at`) e Sole prosegue la conversazione lì senza riqualificare.
Tracciamento delivery/read dai webhook Meta = miglioria futura.

### 🗑️ ~~Cestino 30 giorni~~ ✅ FATTO (15/06/2026)
### 🔔 ~~Monitor portali lead~~ ✅ FATTO (15/06/2026) — `ardy-lead-monitor.php` + n8n ogni 60min

### 👤 Multi-utente: accesso Andrea come Michela — codice ✅ FATTO (16/06/2026)
Scelta: **credenziali separate + secondo numero WA**. Codice già pronto:
`ardy-wa-lookup.php` riconosce anche `WA_ANDREA_NUMBER` come staff (stessi
permessi della titolare, prompt parametrizzato sul nome → cache separata per
ciascuno). Restano **2 azioni server-side** da fare a mano:
1. **`.htpasswd`** (`/home/micoperibg/public_html/ardyagent.ardy-lab.it/.htpasswd`):
   aggiungere riga `andrea:<bcrypt>` (es. `htpasswd -B .htpasswd andrea`).
   `.htaccess` accetta già qualsiasi utente valido (`Require valid-user`).
2. **`ardy-config.php`** (NON in repo): aggiungere
   `define('WA_ANDREA_NUMBER', '39XXXXXXXXXX');` col numero WhatsApp di Andrea
   (formato internazionale senza `+`).
Test dal vivo: Andrea scrive "come va oggi?" da WA → Sole risponde col briefing
chiamandolo per nome ("Andrea") e accetta i marker `[[CREA_SCHEDA]]` / `[[CONTATTA_LEAD]]`.

### Briefing del mattino — opzionale
⏭️ trigger "prima risposta del giorno": salvare data ultimo briefing per numero così il riepilogo
lungo parte da solo al primo "buongiorno". Senza, funziona quando Michela chiede "come va oggi?".

### Migliorie minori UX (bassa priorità)
- **Popup date all'attivazione IN_LAVORAZIONE**: al click stato, modale che chiede `inizio_lavoro`/
  `fine_lavoro_prevista`. Tocca solo `ardy-michela-app.html/.css`.
- **Filtro sidebar default su ACCONTO/IN_LAVORAZIONE** invece di TUTTI (da decidere sull'uso reale).

### Widget WordPress — cosa resta
Chat centralizzate ✅ (`ardy-chat-site.js`, `ardy-chat-corsi.js`). I **pulsanti CTA**
(`pulsante-flottante-ovunque`, `pulsante-corsi`) **restano backup-only**: la loro logica di visibilità
è PHP server-side (categoria 102, mappa slug corso) → centralizzarli spezzerebbe in due, non conviene.
Mini-pendenza: il flottante dice ancora "Chatta con **Ardy**" (aria-label già "Sole") → da uniformare
a "Sole" quando si tocca lo snippet in WPCode.

---

## ❄️ CONGELATI / PARCHEGGIATI (non ora)
- **Catalogo prezzi su Google Sheet**: niente permessi vendita (WooCommerce off) → la vendita andrà su
  un **agente dedicato a parte**, non Sole. Riprendere solo in quel contesto.
- **Conoscenza Sole — FASE 2**: datazione fotografica guidata + community + eventuale **mini-RAG** (se
  la knowledge base cresce troppo per stare cacheata). Lead-magnet, ma non ora.
- **BIMI (logo come avatar mittente in Gmail)** — bassissima priorità: serve DMARC in enforcement
  (`p=quarantine`/`reject`) + TXT `default._bimi` + logo **SVG Tiny P/S** + **VMC** (certificato a
  pagamento ~1.000+ €/anno + marchio registrato; Gmail lo mostra **solo** col VMC). Decisione commerciale.

---

## 🔒 BACKLOG SICUREZZA (priorità bassa)
> Difesa infrastrutturale già presente (OVH Anti-DDoS, Fail2ban, ModSecurity WAF, mod_hulk).
- **OAuth Google senza `state`** (`ardy-gcal-auth.php`): aggiungere `state` casuale verificato.
- **`get_stats` SQL** (`ardy-outreach-api.php`): query con interpolazione (da array interno) → parametrizzare.
- **`mode=download` preventivo**: serve qualsiasi PDF a chi è dietro Basic Auth (no ownership). Basso rischio.
- **Prompt injection caption reel** (`ardy-crea-reel.php`, `generaCaptionReel`): `$mobile` (e nomi
  fase) interpolati grezzi nel prompt a Claude. Rischio basso — endpoint dietro Basic Auth (di fatto solo
  Michela), `$mobile` arriva dal DB e la caption è **rivista a mano** prima di pubblicare. Hardening
  (difesa in profondità): delimitare i dati non fidati con tag (es. `<dati_cliente>…</dati_cliente>`) +
  istruzione a trattarli come dati, non istruzioni. ~10 righe.

---

## ⚡ BACKLOG PERFORMANCE
### Alto impatto / basso sforzo (aperti)
- **Ricerca telefono full-scan** (`ardy-wa-lookup.php`, `ardy-proxy.php`): `REPLACE(...) LIKE '%...'`
  impedisce gli indici → colonna `telefono_last9` normalizzata + indice, match esatto.
- **DDL su ogni request** (`SHOW COLUMNS`/`ALTER`/`CREATE TABLE IF NOT EXISTS` in vari endpoint):
  spostare in una migrazione one-shot, togliere dal path di richiesta.
- **`ardy-crm-api.php`**: `SELECT *` su `clienti` senza `LIMIT` → solo colonne usate + paginazione + indice.

### Da pianificare / decisi
- **Reel async (`ardy-crea-reel.php`) — priorità media**. Oggi monta il video in **sincrono** dentro
  la richiesta HTTP: `set_time_limit(600)` tiene un worker FPM occupato fino a 10 min, foto scaricate
  in serie (fino a `MAX_FOTO=40`), I/O pesante (src→norm→clip→raw→final) e attesa API caption (60s) a
  fine pipeline. Non urgente: lo usa **solo Michela** dalla dashboard (no concorrenza); diventa
  prioritario se più utenti usano la dashboard o se compaiono **504**/"Errore nella finalizzazione del
  reel". Refactor: job in **background** (`proc_open` detached) → risposta immediata con job-id +
  **polling** dello stato dalla dashboard (tocca anche il JS). Quick win indipendenti, a basso rischio:
  (1) eliminare i **2 download ridondanti** prima/dopo (righe ~206-217 ri-scaricano foto già prese nel
  ciclo) riusando i file già scaricati; (2) **download paralleli** con `curl_multi`; (3) caption Claude
  fuori dal path critico.
- **Estrarre JS inline (~3.400 righe) dalla dashboard** in `ardy-michela-app.js` (CSS già esterno).
  Win di caching ma refactor delicato su HTML live → task a sé, da testare a fondo.
- ❌ ~~Rate-limit su APCu/Redis~~ — scartato: dipende dal server, guadagno piccolo, rischio medio.
- ❌ ~~mysqli→PDO in `ardy-preventivo.php`~~ — scartato: solo coerenza, zero performance, flusso
  preventivi business-critical.

---

## 📄 FUORI REPO / OPERATIVO
- **Import preventivi storici**: strumento pronto (`ardy-import-preventivi.php`, CSV + PDF). Michela
  mette i PDF in Google Drive → si genera il CSV precompilato e si importa.
- **Documenti legali**: `termini-privacy-wordpress.md` + `GUIDA-UTENTE.md` pubblicati e in revisione
  legale. Aggiornare le date alla pubblicazione effettiva quando il legale conferma.

---

## ⏸️ IDEE RIMANDATE / SCARTATE
- **Codice d'accesso su WhatsApp** (rimandato): su WA il numero = identità, il codice serve solo per
  numeri non registrati (raro). Servirebbe marker `[[CERCA:ARD-XXXX]]` + lookup per `codice_accesso`.
- **Scartati (non riproporre)**: foto/video WhatsApp che attivano fasi (pipeline media Meta assente);
  WhatsApp come "telecomando" unico della webapp. Si tiene solo Scenario 1 (creazione scheda da dati/PDF).

---

## 💶 Nota costi (riferimento)
- **Costo dominante = API Claude per messaggio**. Mitigato col **prompt caching**: chat web ✅,
  lavorazione ✅, WhatsApp ✅ (incluso dossier + conoscenza in `system_static`), titolare da verificare.
- **Meta**: Michela↔Sole user-initiated = gratis; costi solo su template business→cliente fuori 24h
  (Utility ~3-4 cent/msg).
- Media Meta **scadono** → scaricarli subito col media ID.
