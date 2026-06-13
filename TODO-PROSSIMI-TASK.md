# Ardy Lab — Task aperti & note utili

> TODO ripulito (giugno 2026): tenuti solo i task ancora aperti + le note operative
> che servono sempre. Tutto ciò che era già fatto **e testato in produzione** è stato
> rimosso (lo storico resta nei commit git).

---

## ✅ FATTO E IN PRODUZIONE — sessione 13/06/2026
Tutto deployato su `main` e provato dal vivo (salvo le rifiniture nel blocco "DA VERIFICARE").
- **Codice d'accesso `ARD-XXXX-XXXX`** capability per la chat web (anonima): generato al
  `salva_lead_crm`, salvato su `clienti.codice_accesso`, tool `cerca_cliente` (anti-bruteforce
  + data minimization). Inviato via **email di benvenuto** (logo, presentazione Sole, link
  diretto `ardy-lab.it/ardy-agent`, canale WA clienti). Deliverability: il mancato arrivo era
  la **blocklist Brevo** (non il codice). Invio email disaccoppiato dalla generazione + flag
  `codice_email_inviato` anti-doppione.
- **Chat web non "perde il filo"**: appuntamento fissato = definitivo (prompt) + **guard
  anti-doppione** in `fissa_appuntamento_calendario` (se la scheda ha già `gcal_event_id`).
- **Mini-delete cliente**: `ardy-elimina-cliente.php` + pulsante 🗑 in dashboard (hard-delete
  scheda + preventivi/fasi/wa_messaggi/solleciti + foto/reel, conferma "ELIMINA").
- **Email "aggiornamento lavorazione"** rinnovata: oggetto con "Ardy Lab —", codice + chat
  dedicata + link diretto, tono artigiano; il codice viene recuperato/generato per session.
- **Widget lavorazione**: schermata di scelta **cliente / non-cliente**. Cliente → verifica con
  **codice o telefono** (`ardy-verify-client.php` accetta entrambi). Non-cliente → chat generale
  di Sole nello stesso widget, senza dati personali. Alert pagina ampliato (in **Divi**, non WPCode).
- **Bug WhatsApp** risolto: `ardy-system.txt` (condiviso col canale WhatsApp) conteneva le
  istruzioni del tool `cerca_cliente` → Sole "recitava" `<function_calls>`. Spostato il blocco
  in `ardy-proxy.php` (solo web) + regola WhatsApp (niente tool; codice = web-only; riconoscimento
  per numero, con fallback per numero non registrato).

---

## 🔧 NOTE OPERATIVE (servono sempre)

**Deploy sul server** (da root):
```
runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'
```

**Log degli errori/eventi PHP** (utile per debug e per verificare il prompt caching):
```
/home/micoperibg/logs/ardyagent_ardy-lab_it.php.error.log
# es: grep "ARDY USAGE" <file> | tail -8   → righe in/out/cache_read/cache_write
```

**Auth degli endpoint chiamati via fetch**: NON usare `ardyRequireAuth()` negli endpoint
chiamati in fetch dal browser → su questo server l'header `Authorization` non arriva a PHP
in CGI/FPM e rifarebbe la login. Ci si affida al `.htaccess` (Basic Auth) come per gli altri.

**`session_id`**: sempre sanificato (no path traversal) prima di toccare i path file.

---

## ⏳ DA VERIFICARE (codice pronto, manca la prova)

- **Prompt caching ramo titolare (WhatsApp)** — nodo n8n aggiornato per usare `system_static`
  + `crm_context` (vedi `ardy-wa-prompt-caching-n8n.md`). ✅ Canale clienti verificato.
  ⏭️ **Manca**: prova dal numero VERO di Michela ("come va oggi? lead e urgenti") → Sole deve
  rispondere con dati reali del CRM. Se sì → A3 chiuso.
- **"Sole crea scheda da WhatsApp"** (marker `[[CREA_SCHEDA]]`) — codice + nodo n8n pronti.
  ⏭️ Prova end-to-end dal numero di Michela: dettare un cliente nuovo → conferma → "Scheda creata ✅"
  + scheda in dashboard (stato LEAD). Se errore: guardare le **Executions** del nodo Code in n8n.
- **Template `sollecito_pagamento`** — approvato e collegato. Da provare con un caso moroso vero.
- **`cerca_cliente` + codice d'accesso** — ✅ in produzione (vedi recap 13/06 sotto). Resta solo
  la **prova finale sul web**: dare il codice nella chat del sito → Sole risponde con lo stato.
  E una pulizia: togliere il log diagnostico `ARDY CODICE DIAG` da `ardy-proxy.php` (handler
  `salva_lead_crm`) quando non serve più.

---

## 🚧 BLOCCHI ESTERNI (azioni di Michela su Meta, non codice)

- **Carta di credito su Meta** → serve per sbloccare i messaggi **business→cliente/Michela
  fuori dalle 24h** (i **template**: `notifica_michela`, `sollecito_pagamento`, fasi). Senza,
  le **notifiche proattive a Michela non partono**. NB: Michela che scrive a Sole (lei inizia)
  resta GRATIS e non richiede la carta. Da fare in WhatsApp Manager / Meta Business → Fatturazione.
- **Template `aggiornamento_fase`** (notifiche fasi ai clienti) — **codice già pronto**
  (`inviaWhatsAppCliente()` in `ardy-pubblica-lavorazione.php`, 4 var: {{1}}nome {{2}}mobile
  {{3}}fase {{4}}link). Manca: creare+approvare il template su Meta, poi in `ardy-config.php`:
  `define('WA_TEMPLATE_FASI','aggiornamento_fase');`

---

## 📋 TASK DA SVILUPPARE

### ⭐ PROSSIMO — Backup & centralizzazione dei widget WordPress
Obiettivo: portare sotto git i sorgenti che oggi vivono solo in WordPress, e dove possibile
**centralizzarli** in file serviti dal nostro server (una sola fonte versionata).
Contesto raccolto il 13/06:
- Gli snippet stanno in **WPCode** (7 snippet) + il loader della pagina lavorazione sta nelle
  **integrazioni di Divi** (NON WPCode → già nel repo come `wpcode-snippet-lavorazione.html`).
- I 7 snippet WPCode (da screenshot): `performance` (php), `Chat per i corsi` (html, footer),
  `Pulsante corsi` (php), `Pulsante flottante ovunque` (php — contiene anche il loader lavorazione!),
  `Snippet yoast` (php), `Corsi dato strutturato` (php), `ardychat` (js, footer — è la **chat
  generale del sito**, punta a `ardy-proxy.php`, usa elementi `ac-*`).
- File **serviti dal nostro server** (restano in root, si deployano): es. `ardy-widget-lavorazione.js`.

Piano (lento, un passo alla volta):
1. **Backup**: creare cartella `wordpress-snippets/` (esclusa dal deploy in `deploy.sh`, e già
   fuori dal `.cpanel.yml` che copia solo i file root). Modo comodo per ottenerli: **WPCode →
   Strumenti → Esporta tutti** → un JSON → l'utente lo incolla → si splitta in file con i nomi
   giusti + README-mappa (ID/posizione/tipo).
2. **Centralizzare SOLO i widget front-end** (chat/pulsanti js/html) in file serviti dal server,
   lasciando in WordPress una riga-loader. Gli snippet **PHP** (hook/SEO/schema) restano
   backup-only, non si spostano.
⚠️ Ricorda: modificare un file nella cartella backup NON aggiorna WordPress (va ricopiato a mano),
finché non si fa la centralizzazione vera (loader → file servito).


### ⚡ Risparmio risorse — Item B: compressione dati / disco
Server 200GB condiviso con 5 domini → lo spazio e il peso DB contano. (Item A "prompt caching"
fatto: vedi sezione "Da verificare".)
- ✅ **FATTO — Foto all'upload**: nuovo helper `ardyCompressImage()` in `ardy-net.php`
  (ridimensiona a max 2000px lato lungo + ricomprime jpeg q82 / webp / png; salta le GIF
  animate; tiene l'originale se non conviene). Usato in `ardy-lead-foto.php` (disco) e
  `ardy-proxy.php` (compressione una-tantum nel ciclo validazione → alleggerisce disco,
  abbassa il costo API e tiene le foto sotto il limite 10MB di Claude). Testato: ~78% in meno
  su una foto da telefono. ⏭️ `ardy-upload-video.php` è video (no GD) → fuori da questo intervento.
- ✅ **FATTO — gzip**: blocco `mod_deflate` nel `.htaccess` (dentro `<IfModule>`) che comprime
  HTML/CSS/JS/JSON/XML/SVG. NON tocca le immagini (già compresse). Attivo al deploy.
- ✅ **FATTO — Preventivi base64 in DB**: invece del refactor rischioso (immagini su file), la
  compressione è agganciata in `parseImgDataUris()` (`ardy-preventivo.php`), punto unico da cui
  passano copertina + prima/dopo + immagine analisi → le immagini entrano in `voci_json` (e nel
  PDF) già ridotte/ricompresse (~80% in meno su foto reali). ⚠️ Nota: la **copertina** full-page
  è limitata a 2000px lato lungo (~170dpi su A4): se in stampa risultasse poco nitida, alzare
  `maxSide` solo per la copertina nella chiamata a `ardyCompressImage`.

### 🗑️ Gestione archivio cliente (versione completa)
> ✅ **Già fatto — hard-delete minimo** (per ripulire i lead di test): `ardy-elimina-cliente.php`
> (DELETE per `session_id` su clienti+preventivi+fasi+wa_messaggi+solleciti + foto e reel,
> conferma "ELIMINA", Basic Auth `.htaccess`) + pulsante 🗑 Elimina sulla scheda in dashboard.
> ⏭️ Resta da costruire la versione completa qui sotto (Libera spazio / Cestino 30gg / purga).

Contesto: i file pesanti sono **foto** (`ARDY_UPLOAD_DIR/<session>/`) e **reel**
(`reels/reel_<session>_*.mp4`). I PDF preventivo **incorporano le immagini in base64** →
cancellare le foto originali NON rompe i documenti. (Versione "leggera" PERSI già in produzione:
`ardy-archivia-persi.php` + pulsante 🧹 LIBERA SPAZIO PERSI; sposta in quarantena, Michela cancella a mano.)

Decisioni già prese con Michela:
1. **🧹 "Libera spazio" (solo immagini)** per i clienti **PAGATO**: cancella subito cartella foto +
   reel; tiene scheda/dati/preventivi+PDF/fasi/storico WA/pagina sito. Conferma forte; segna
   `foto_archiviate_at` sulla scheda.
2. **🗑️ "Elimina tutto" → CESTINO 30 giorni**: soft-delete `deleted_at` su `clienti` → vista
   Cestino con Ripristina; dopo 30gg purga DB (clienti/preventivi/fasi/wa_messaggi/solleciti) +
   tutti i file (foto, reel, PDF). NON tocca pagina WordPress né Media Library. Purga = sweep
   opportunistico (es. in `ardy-crm-api.php`, max N per load), niente cron necessario.
3. **Sicurezza/UX**: modale di conferma (per "Elimina tutto" far scrivere "ELIMINA"); endpoint
   Basic Auth; `session_id` sanificato.

Da costruire:
- *Backend* `ardy-elimina-cliente.php` (azioni `libera_spazio | cestina | ripristina | purga`),
  helper cancellazione file per session (riusa `ardy_clean_session`), colonne `deleted_at` /
  `foto_archiviate_at` auto-create.
- *API CRM* `ardy-crm-api.php`: escludere i `deleted_at` dalla lista normale; endpoint/param Cestino.
- *Dashboard*: pulsanti 🧹 (su PAGATO) e 🗑️ sulla scheda; vista Cestino; modali conferma.

### 💡 Idee da Sole (vagliate sul codice — gap reale rimasto)
> Sole, interrogata, ha proposto varie migliorie: la maggior parte **è già implementata**
> (riconoscimento cliente per telefono + storico su WhatsApp via `ardy-wa-lookup.php`/
> `ardy-wa-memoria.php`; stato lavori in mode `cliente_lavorazione`; analisi foto sulla chat
> web). Altre sono **già scartate** (ricezione foto WhatsApp = pipeline media Meta assente;
> invio preventivi automatici senza Michela = no, li controlla lei). Resta 1 idea valida:
> (la "CRM read nella chat WEB" è stata realizzata → vedi "DA VERIFICARE: `cerca_cliente`".)
- **⭐ Catalogo prezzi su Google Sheet** (alto valore, basso rischio): oggi i prezzi sono
  hardcoded in `ardy-system.txt` → cambiare = editare file + deploy. Un foglio Google letto da
  Sole (sa già leggere Calendar/Drive) farebbe aggiornare i prezzi a Michela da sola, senza
  toccare il codice. Le variazioni si rifletterebbero subito. Da progettare: foglio modello +
  endpoint/funzione che lo legge e lo inietta nel prompt (con cache breve per non rileggerlo ogni msg).

### 💡 Briefing del mattino — opzionale rimasto
Parte "lavori" e "calendario" già in produzione (riepilogo titolare con IN LAVORAZIONE, URGENTI ≤4gg,
impegni Google Calendar). ⏭️ Rimasto (opzionale): **trigger "prima risposta del giorno"** — salvare
data ultimo briefing per numero così il riepilogo lungo parte da solo al primo "buongiorno" e non a
ogni messaggio. Senza, funziona quando Michela chiede "come va oggi?".

### Migliorie minori UX (bassa priorità)
- **Popup date all'attivazione stato IN_LAVORAZIONE**: al click del bottone stato, aprire un
  modale che chiede subito `inizio_lavoro` / `fine_lavoro_prevista` (riusa campi/salvataggio
  esistenti). Tocca solo `ardy-michela-app.html/.css`.
- **Filtro sidebar di default su ACCONTO** (o IN_LAVORAZIONE) invece di TUTTI, se Michela lavora
  quasi sempre sui lavori in corso. Da decidere in base al suo uso reale.

---

## 🔒 BACKLOG SICUREZZA (rimasti — priorità bassa)
> A monte c'è già difesa infrastrutturale (OVH Anti-DDoS, Fail2ban, ModSecurity WAF, mod_hulk):
> brute-force/DoS/flood sono contenuti a livello server; i rate-limit applicativi restano come
> difesa in profondità e per il **controllo costi** dell'API a pagamento.
- **OAuth Google senza `state`** (`ardy-gcal-auth.php`): aggiungere parametro `state` casuale verificato.
- **`get_stats` SQL** (`ardy-outreach-api.php`): unica query con interpolazione (oggi da array
  interno, non sfruttabile). Parametrizzare per pulizia.
- **`mode=download` preventivo**: serve qualsiasi PDF della cartella a chi è dietro Basic Auth
  (no ownership). Basso rischio (utente unico); legare alla sessione se servisse.

---

## ⚡ BACKLOG PERFORMANCE (rimasti)

### Alto impatto / basso sforzo
- **Ricerca telefono full-scan** (`ardy-wa-lookup.php`, `ardy-proxy.php`): `REPLACE(...) LIKE '%...'`
  impedisce gli indici. Fix: colonna `telefono_last9` normalizzata + indice, match esatto.
- **DDL su ogni request** (`SHOW COLUMNS`/`ALTER`/`CREATE TABLE IF NOT EXISTS` in `ardy-proxy.php`,
  `ardy-stats.php`, `ardy-pubblica-lavorazione.php`, `ardy-libreria-api.php`,
  `ardy-reel-template-api.php`): spostare in una migrazione one-shot, togliere dal path di richiesta.
- **`ardy-crm-api.php`**: `SELECT *` su `clienti` senza `LIMIT`. Selezionare solo le colonne usate +
  paginazione + indice su `updated_at`.
- ✅ **FATTO — Quick-win** `finfo::file()` in `ardy-lead-foto.php` (serve foto + elenco): legge
  solo l'header invece di caricare l'intera immagine in memoria (`buffer(file_get_contents())`).
  Win di memoria reale sull'elenco foto (con 30 foto da 3MB evitava ~90MB caricati per richiesta).
  ⏭️ Memoize `static` dei system-prompt da disco: valutato e **saltato** — in PHP lo `static` non
  persiste fra richieste e i prompt si leggono già una sola volta per richiesta → beneficio ~nullo.

### Da pianificare
- Cache PDF preventivo per content-hash + memoizzazione logo base64 (`ardy-preventivo.php`).
- Estrarre JS/CSS dalle HTML monolitiche + header cache/cache-busting.
- Rate-limit su APCu/Redis invece che su file (`ardy-proxy.php`).
- Unificare `dbConnect()` (mysqli) di `ardy-preventivo.php` sul PDO di `ardyDB()`.

---

## 📄 FUORI REPO / OPERATIVO
- **Termini & Condizioni su WordPress** (ardy-lab.it): aggiornarli coerenti con l'informativa
  GDPR già presente nel preventivo PDF. (Testo da preparare e incollare.)
- **Import preventivi storici**: strumento pronto (`ardy-import-preventivi.php`, CSV + PDF).
  Operativo con Michela: lei mette i PDF in una cartella Google Drive → si genera il CSV
  precompilato (Claude può leggere da Drive) e si importa. Dati mancanti (telefono/email/stato)
  da farsi dare a parte.

---

## ⏸️ IDEE RIMANDATE (non urgenti)
- **Codice d'accesso su WhatsApp** (solo se capita spesso un cliente che scrive da un numero
  NON registrato): oggi su WhatsApp il numero = identità, quindi il codice serve solo a chi
  scrive da un altro numero — caso raro. Per supportarlo servirebbe un **marcatore** tipo
  `[[CERCA:ARD-XXXX]]` intercettato da n8n (Claude su WhatsApp non ha tool), + lookup per
  `codice_accesso` lato PHP. Per ora il prompt gestisce il caso con garbo (chiedi il numero di
  registrazione / passa a Michela). Costruirlo solo se la frequenza lo giustifica.

## ❌ SCENARI WhatsApp VALUTATI E SCARTATI (non riproporre)
- Foto/video su WhatsApp → attivano una fase di lavoro: **NO** (richiede pipeline media Meta assente).
- WhatsApp come "telecomando" unico della webapp: **NO**.
- Si tiene solo lo **Scenario 1** (creazione scheda da dati/PDF-template), già implementato.

---

## 💶 Nota costi (riferimento)
- **Costo dominante = API Claude per messaggio** (system grosso + riepilogo CRM). → mitigato col
  **prompt caching** (item A): chat web ✅ verificata (cache_read ~7500 token, ~0,1× di costo);
  lavorazione ✅ deployata; WhatsApp ✅ deployato, titolare da verificare.
- **Meta**: Michela↔Sole user-initiated = gratis; costi solo su template business→cliente fuori 24h
  (Utility ~3-4 cent/msg). Vedi blocco "carta di credito" sopra.
- Media Meta **scadono** → vanno scaricati subito col media ID.
