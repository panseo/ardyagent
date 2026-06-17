# Ardy Lab — Task aperti & note utili

> TODO ripulito: solo i task ancora **aperti** + note operative + verifiche/azioni residue.
> Tutto ciò che è fatto **e deployato** è rimosso (lo storico resta nei commit git).

---

## ▶️ STATO (16/06/2026)
CRM in attività piena, ora anche **multi-utente** (Michela + Andrea). Primo
**sopralluogo "sul campo"** fatto oggi con la scheda mobile rifinita.
- ✅ **Multi-utente Andrea** LIVE (16/06): credenziali separate `.htpasswd` + `WA_ANDREA_NUMBER` in `ardy-config.php`. Stessi permessi di Michela (dashboard + Sole su WhatsApp che lo chiama "Andrea"). Cache prompt separate per i due.
- ✅ **Root dominio** apre direttamente la dashboard (16/06): `https://ardyagent.ardy-lab.it` → dashboard (prima serviva `/ardy-michela-app.html`).
- ✅ **UX scheda mobile (sopralluogo)** (16/06): Note ingrandite + bottone **⛶ Espandi** (editor a tutto schermo), **Dati anagrafici** e **Azioni cliente** dentro toggle collassabili (chiusi di default su mobile), Session ID nascosta.
- ✅ **Monitor portali lead** in produzione (n8n ogni 60min, Gmail→Claude→WA). Portali: ProntoPro, Homedeal, Cronoshare. Instapro in attesa cambio email.
- ✅ **Cestino 30 giorni**: soft-delete con ripristino, purga automatica >30gg, modal nella dashboard.
- ✅ **Stato COMPLETATO** aggiunto tra IN_LAVORAZIONE e CONSEGNATO.
- ✅ **Restyling PDF preventivo** (16/06 sera): font **Playfair Display** per tutto il documento, colore testo **dorato scuro** (`#6b4f1e`) invece del quasi-nero, pagina **Grazie** ridisegnata (logo + "GRAZIE" + customer-care Sole) con footer link Instagram + webchat + **WhatsApp diretto** (`wa.me`) e **2 QR code** (webchat + WhatsApp), link **privacy policy + termini** sulla pagina firma. `PDF_CACHE_VER` a `2026-06-16i`.
- ✅ **Prompt WhatsApp: nudge verso webchat** (16/06 sera): sezione in `ardy-whatsapp-system.txt` che istruisce Sole a invitare con garbo il cliente sulla webchat (dopo aver dato valore, mai forzando, sempre lato-cliente).
Prossimi task per priorità: foto-cliente nelle fasi (rimandata) · briefing del mattino · backlog performance/sicurezza.

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
- **UX "Modifica" su Preventivo (allegato)** (deployato 16/06/2026, testato dal vivo — lasciato così, da
  rivedere con calma): il bottone "✏️ Modifica" apre correttamente il mini-form precompilato (oggetto,
  numero, AGGIORNA) invece del generatore a voci — bug risolto. Ma Michela si aspettava di vedere anche
  fasi/prezzi in quella schermata: non ci sono perché l'estrazione "🔍 Leggi dati dal PDF" è disponibile
  **solo al primo allegato**, non in modifica (per non duplicare le fasi già create). Da rivedere: capire
  se serve un modo per ri-estrarre/correggere prezzi anche in modifica, senza creare doppioni di fasi.
- **Prezzo per fase** (deployato 16/06/2026, da testare dal vivo): su un NUOVO allegato (non in modifica),
  dopo "🔍 Leggi dati dal PDF" verificare che la lista fasi mostri un campo prezzo editabile, precompilato
  solo se il PDF riporta un importo per voce (mai dedotto dal totale).
- **Fasi bozza da template libreria + badge "da pianificare"** (deployato 16/06/2026, da testare dal vivo):
  1. Badge "📐 da pianificare" in lista e in scheda su un cliente con nota ma senza fasi.
  2. Nel box Note, selezionare 1-2 chip template e generare le bozze → toast di conferma, badge che scompare.
  3. Pannello Lavorazione → sezione "Fasi previste": "✎ Modifica e pubblica" precompila il form, "✕" elimina la bozza.
  4. Pubblicare una bozza modificata → deve uscire dalla lista bozze e comparire come fase pubblicata.
  5. Widget pubblico lato cliente: deve mostrare SOLO le fasi pubblicate, mai le bozze.
- **PDF preventivo restyling** (deployato 16/06/2026, da vedere dal vivo): generare un preventivo e
  controllare sul PDF reso (mPDF non è renderizzabile in locale): font Playfair su tutto il documento,
  colore testo dorato leggibile, pagina Grazie coi 2 QR (webchat + WhatsApp) **scansionabili davvero**
  e i link footer corretti, link privacy/termini sulla pagina firma, **nessuna pagina bianca** prima del Grazie.
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

## 🌐 GOOGLE BUSINESS PROFILE — post automatici delle fasi (BLOCCATO su quota Google)
**Obiettivo**: pubblicare in automatico i post delle fasi di lavorazione sul profilo
Google Business **Ardy di Michela Panella** (Via Joyce 4, Roma — già **Verificata** ✅).
Stesso account Google Cloud usato per Calendar/Gmail.

**Dove siamo bloccati**: il nodo n8n è già stato creato, ma si ferma **sulla chiamata API**.
Causa quasi certa: la **Business Profile API** parte con **quota = 0** e richiede un
**form di richiesta accesso** approvato manualmente da Google (NON basta abilitarla in
Cloud Console). Richiesta inviata da ~20gg, nessuna risposta via email.

> ⚠️ Google **non ha un endpoint** per interrogare lo stato della pratica. Si verifica solo:
> (a) Cloud Console → *Business Profile API → Quotas* (se limite passa da 0 → approvata),
> (b) con una chiamata reale all'API.

**Strumento di verifica pronto**: `ardy-gbp-check.php` (dietro Basic Auth) → apre
`https://ardyagent.ardy-lab.it/ardy-gbp-check.php` e dice: ✅ quota sbloccata /
⛔ quota a zero (429) / ⚠️ manca scope `business.manage` / 403 API non abilitata.
Riusa il token di `ardy-gcal-token.json`.

**DIAGNOSI DEFINITIVA (17/06/2026)**: ⛔ **accesso NON approvato da Google.**
- Progetto Cloud: **ardy-lab** / Project Number **532339794075** (coincide col client OAuth ✅).
- `ardy-gbp-check.php` → 403 HTML del front-end Google ("does not have permission to get
  URL /v1/accounts") = richiesta respinta al cancello = progetto non in allow-list.
- Cloud Console → My Business Account Management API → Quotas → **Requests per minute = 0**
  (0 = non approvato, 300 = approvato). **Confermato quota 0.**
- Storico: form inviato 31/05/2026 (email conferma + msg 01/06 "serve progetto approvato"),
  sollecito via email a `gbp-api-support` l'11/06 → **nessuna risposta**.
- ⚠️ Il sollecito è stato mandato via **email**: l'intake ufficiale è il **form**
  `https://support.google.com/business/contact/api_default` → "Application for Basic API
  Access". L'email a gbp-api-support non è tracciata → va ri-sottomesso dal form.
- ⚠️ **NON** usare il pannello "Modifica quota → Invia richiesta" in Cloud Console: per la
  Business Profile API la richiesta quota generica viene auto-respinta, serve il form.

**CAUSA RADICE TROVATA (17/06): disallineamento di account.**
- [x] GBP "Ardy di Michela Panella" **verificato da >1 anno** → requisito 60 giorni OK.
- [x] ⚠️ La scheda GBP è di proprietà di **`a.panseo@gmail.com`** (chi fece la verifica
      un anno fa). Tutto il resto (progetto Cloud `ardy-lab`/532339794075, token OAuth,
      form API) è su **`ardy.documenti@gmail.com`**. Google richiede che chi invia il form
      sia **owner/manager della scheda** → `ardy.documenti` NON lo è → **richiesta respinta**.

**FIX (Strada A):**
- [ ] Da `a.panseo@gmail.com` (proprietario GBP) → business.google.com → scheda Ardy →
      Impostazioni profilo → Persone e accesso → **aggiungere `ardy.documenti@gmail.com`
      come Proprietario** (o Gestore). Accettare l'invito da `ardy.documenti`.
- [ ] **Ri-sottomettere il form** Basic API Access da `ardy.documenti` (ora idoneo):
      `https://support.google.com/business/contact/api_default` → "Application for Basic
      API Access". Project Number 532339794075. Use-case: pubblicazione automatica
      `localPosts` con gli aggiornamenti delle fasi di lavorazione del restauro.
- [ ] (bozza testo form preparata 17/06 — vedi chat).
- [x] Scope `https://www.googleapis.com/auth/business.manage` aggiunto in
      `ardy-gcal-auth.php` (17/06). **AZIONE MANUALE**: aprire `ardy-gcal-auth.php` nel
      browser e completare il consenso Google per rigenerare il token con il nuovo scope
      (il token attuale ha solo calendar+gmail → va rifatto).
- [x] ✅ **Confermato (ricerca 17/06): i post via API funzionano ancora nel 2026.**
      `accounts.locations.localPosts.create` è ATTIVO e non deprecato (solo
      `localPosts.reportInsights`/statistiche è stato dismesso nel 2023 → ora le metriche
      stanno nella Performance API). ⚠️ **MA** l'endpoint dei post vive ancora sul host
      **legacy** `https://mybusiness.googleapis.com/v4/accounts/{id}/locations/{id}/localPosts`,
      che NON compare nella library della console (non è tra le 4 API "moderne" già abilitate)
      ed è **proprio quello sotto access-gate/quota** → è il muro su cui si fermava il nodo
      n8n. Il codice/n8n dovrà puntare a `mybusiness.googleapis.com/v4`, NON alle API nuove.
      Conclusione: **sbloccare la quota è la strada giusta, il task è fattibile.**

**Quando sbloccato**: completare il nodo n8n / endpoint PHP per creare il `localPost`
(media foto fase + testo) alla pubblicazione di una fase lavorazione.

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

### 👤 ~~Multi-utente: accesso Andrea come Michela~~ ✅ LIVE (16/06/2026)
Credenziali separate (`.htpasswd` con utenti `michela` + `andrea`) + secondo numero WA
(`WA_ANDREA_NUMBER` in `ardy-config.php`, formato `39XXXXXXXXXX`). `ardy-wa-lookup.php`
riconosce entrambi come staff e prompt parametrizzato sul nome → cache prompt separate.
Sole chiama ciascuno per nome. Reset password: `htpasswd -B <path> <utente>` (mai `-c`).

### 📱 ~~UX scheda mobile (sopralluogo)~~ ✅ LIVE (16/06/2026)
Field-test fatto oggi durante il primo sopralluogo vero. In `ardy-michela-app.html`:
- Textarea "Note" 6 righe + bottone **⛶ Espandi** → modale a tutto schermo (`#noteEditorOverlay`).
- Toggle **▾ Dati anagrafici** (Nome…Indirizzo + Data followup) → chiuso di default su mobile (≤768px).
- Toggle **▾ Azioni cliente** (Email/WA/Genera contenuto/Note interne) → chiuso di default su mobile.
- Session ID rimossa dalla UI (resta nel DOM perché letta dal JS; era dato tecnico).
Note: il campo `clienti.note` finisce automaticamente nel **dossier interno** (`ardy-dossier.php:104`,
visibile solo a Michela/Andrea, mai al cliente) e nel **PDF preventivo** (`ardy-preventivo.php:734`).

### 🌐 ~~Root dominio = dashboard~~ ✅ LIVE (16/06/2026)
`DirectoryIndex ardy-michela-app.html` nel `.htaccess` → `https://ardyagent.ardy-lab.it/`
apre direttamente la dashboard (resta dietro Basic Auth via `FilesMatch`).

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
- **Riorganizzare i `.php` in sottocartelle** (valutato 16/06, deciso NO): in questo hosting il **path del
  file = URL pubblico**, quindi spostarli romperebbe n8n, il webhook Meta, il frontend e gli `__DIR__`
  require, e il deploy (`cp *.php`, `.cpanel.yml`) non è ricorsivo → file non deployati in silenzio. La root
  resta piatta di proposito. (L'unica mossa a rischio zero, se mai servisse: spostare i soli `.md` in `docs/`,
  non deployati né usati a runtime.)

---

## 💶 Nota costi (riferimento)
- **Costo dominante = API Claude per messaggio**. Mitigato col **prompt caching**: chat web ✅,
  lavorazione ✅, WhatsApp ✅ (incluso dossier + conoscenza in `system_static`), titolare da verificare.
- **Meta**: Michela↔Sole user-initiated = gratis; costi solo su template business→cliente fuori 24h
  (Utility ~3-4 cent/msg).
- Media Meta **scadono** → scaricarli subito col media ID.
