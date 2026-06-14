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

## ✅ FATTO E IN PRODUZIONE — sessione 14/06/2026
Tutto deployato su `main`. Lato dashboard sono migliorie UI testate dal vivo da Michela.
- **Centralizzazione chat sito (`ardychat`)**: estratta in `ardy-chat-site.js` servito dal nostro
  server; in WPCode resta solo un loader `<script src=...>` in uno snippet **HTML** (NON JavaScript
  — vedi trappola in `wordpress-snippets/README.md`). Da ora la chat del sito si modifica nel file
  + deploy, niente più copia-incolla in WordPress.
- **Fotocamera nella chat web**: bottone 📷 dedicato (input `capture=environment`) accanto
  all'allega, in `ardy-chat-site.js`.
- **Dashboard — sezione lavorazione riordinata**:
  - **📋 Fasi pubblicate** (sola lettura: titolo + data + n.foto + link al sito), via
    `ardy-get-fasi.php` (prima orfano). Risolve la fase che "spariva" dalla dash (era solo
    mancanza di un elenco; il dato era già salvato).
  - **🔨 Crea e pubblica nuova fase**: form collassabile, bottone primario in cima.
  - **📲 Post social in attesa**: lista compatta (mobile: info impilate 📲/titolo/icone/data) con
    toggle ✏ Modifica; icone brand FB/IG/Google (Google in grigio = non ancora attivo).
  - **📅 Periodo del lavoro**: date inizio/fine etichettate come "intero lavoro" (erano già
    salvate sul cliente, non sulla fase).
- **Dashboard — sidebar clienti**:
  - **Semaforo lavorazione** (pallino + testo, regola 4gg, solo ACCONTO/IN_LAVORAZIONE):
    🟠 sta per iniziare · 🔴 fine lavoro/ritardo · 🟢 nei tempi · 🟡 date da pianificare.
    NB: rosso = **fine lavoro**, non consegna (la consegna può avvenire molto dopo, non si traccia).
  - **Filtri di stato** spostati in toggle **🔍 Ricerca avanzata** + legenda colori.
- **Scheda cliente**: cambio stato sotto toggle **🔄 Aggiorna stato — attuale: X**.
- **Pulizia log**: rimossi i diagnostici temporanei `ARDY CODICE DIAG` (proxy) e `ARDY FASE DIAG`
  (pubblica-lavorazione).

---

## ✅ FATTO E IN PRODUZIONE — sessione 14/06 (pomeriggio)
Branch `claude/sharp-einstein-pmuqq3`, mergeato su `main`. Da deployare/verificare.
- **Archivio implicito CONSEGNATI**: lo stato conclusivo CONSEGNATO esce dalla lista TUTTI;
  pulsante **📦 Archivio (N)** sempre visibile + chip ARCHIVIO. (Dettagli in "Archivio clienti
  CONSEGNATI" sotto.)
- **🧹 Libera spazio**: azione `libera_spazio` in `ardy-elimina-cliente.php` (conferma "LIBERA"):
  cancella solo foto+reel del cliente concluso, tiene scheda/preventivi+PDF/fasi/sito; segna
  `foto_archiviate_at`. Bottone sulla scheda solo da archiviato.
- **Rimosso lo stato cliente `PAGATO`** (coincideva con CONSEGNATO; il "saldato/non moroso" è il
  modulo MOROSI). Alias legacy mantenuto solo per non orfanare eventuali schede già marcate.
- **Pubblicazione social per singolo social** ✅ **COMPLETO** (dashboard + n8n): toggle FB/IG
  (default entrambi, deselezionabili fino a uno). Campo `piattaforme` al webhook; nodo Code n8n
  "Meta" (ramo post-foto) aggiornato col gate `wantFB`/`wantIG`. Vedi `ardy-pubblica-social-n8n.md`.

---

## 🐞 BUG (segnalati da Michela 14/06)
1. ✅ **Email fasi — mittente**: tutto il sistema invia da `noreply@ardy-lab.it` (dominio
   autenticato Brevo/DKIM). Scelta con Michela: **tenere From=noreply** (consegna) e aggiungere
   **Reply-To = `ardy.documenti@gmail.com`** così le risposte del cliente arrivano alla casella
   reale. Fatto in `ardy-pubblica-lavorazione.php` (`addReplyTo`). ⏭️ Se serve, replicare il
   Reply-To anche su proxy/solleciti/outreach (oggi solo From).
2. ⏳ **Immagini non pubblicate su WordPress** — fix probabile applicato, **da verificare sul vivo**:
   l'endpoint gira senza utente WP loggato → **kses** rimuoveva `<video>`/`style` e poteva alterare
   gli `<img>`. Aggiunto `kses_remove_filters()`/`kses_init_filters()` attorno a insert/update +
   **logging** (`ARDY PUBBLICA IMG: ricevute=… salvate_su_wp=…` e `SIDELOAD ERROR`). Regola attesa:
   **1ª foto = in evidenza**, le altre nel corpo. ⏭️ Michela: pubblica una fase di test con foto →
   se ancora non si vedono, leggere il log per capire se è l'upload (`media_handle_sideload`) o WP.

---

## ▶️ PROSSIMA SESSIONE — da dove ripartire
Branch di lavoro: `claude/sharp-einstein-pmuqq3` (allineato a `main`). Dopo ogni push, deploy con il
comando nelle NOTE OPERATIVE qui sotto.

0. **Chiudere i 2 BUG sopra** (email mittente fasi + immagini su WordPress) — priorità alta.

1. **Verifiche sul vivo** di quanto fatto il 14/06 (Michela deve solo guardare):
   - Sidebar: i pallini semaforo e il toggle "Ricerca avanzata" si comportano come atteso?
   - Scheda cliente: toggle "Aggiorna stato"; sezione lavorazione (fasi pubblicate, form fase
     collassabile, social compatti) ok da mobile e desktop?
2. **"Altre cose" sulla sidebar/scheda** che Michela aveva in mente (le dirà lei) — continuare le
   rifiniture UI.
3. **Feature da costruire** (decise ma non fatte):
   - ✅ **Archivio clienti CONSEGNATI** — FATTO (vedi sezione omonima più sotto): archivio implicito
     per stato CONSEGNATO/PAGATO + pulsante/chip 📦 Archivio nella sidebar.
   - **Centralizzazione widget WP**: backup export WPCode + `Chat per i corsi` → file servito.
   - **Catalogo prezzi su Google Sheet**, **Gestione archivio cliente completa** (Cestino 30gg).
4. **Da verificare/pulire** ancora aperti: vedi blocco "DA VERIFICARE" qui sotto.

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
  (Log diagnostico `ARDY CODICE DIAG` in `ardy-proxy.php` ✅ rimosso.)

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

### 🆕 Ringraziamento alla consegna (email + WhatsApp)
✅ **FATTO — codice** (`ardy-grazie-consegna.php`): alla transizione di stato **→ CONSEGNATO**
(`ardy-update-lead.php` rileva il cambio) parte un **ringraziamento** al cliente, **una sola volta**
(guard `consegnato_grazie_at`, auto-creata):
- **Email** (Brevo) ✅ funziona subito: testo caldo + **bottone recensione Google** + link **social**
  (IG/FB) + nota **newsletter** con **link di disiscrizione firmato** (HMAC, riusa `ardy-unsubscribe.php`).
  From `noreply@ardy-lab.it`, Reply-To `ardy.documenti@gmail.com`.
- **WhatsApp** ⏳: codice pronto, ma serve un **template Meta approvato** (`WA_TEMPLATE_GRAZIE`,
  1 var {{1}}=nome) per scrivere fuori dalla finestra 24h. Senza, l'email parte e il WA viene saltato.
- Endpoint anche per **test/reinvio manuale** (POST `session_id`, `force`), Basic Auth + `X-Ardy-Internal`.

⏭️ **Serve da Michela** (config in `ardy-config.php`):
1. **`GRAZIE_GOOGLE_REVIEW_URL`** = link recensione Google Maps di Ardy Lab (senza, il bottone è nascosto).
2. (opz.) confermare/aggiornare `GRAZIE_IG_URL` / `GRAZIE_FB_URL` (default: ardy.lab / pagina "Ardy").
3. Creare+approvare il **template Meta** `aggiornamento`/`grazie_consegna` e settare `WA_TEMPLATE_GRAZIE`.
⏭️ (opz.) bottone in dashboard per **reinviare** il ringraziamento manualmente.
⏭️ **Logo in alto nell'email**: oggi l'header è testuale ("ARDY LAB"). Mettere il logo immagine
   (`assets/logo.png`) in cima — idealmente in TUTTE le email (grazie, fasi, benvenuto, outreach)
   per coerenza. Nota: nelle email il logo va come URL assoluto (`https://ardy-lab.it/.../logo.png`)
   o inline base64 (no allegati cid se via Brevo API). Valutare un piccolo helper header email condiviso.

### 🆕 Dossier cliente in Markdown (contesto completo per Sole)
Obiettivo: per ogni cliente un **MD** che raccoglie **tutto**: anagrafica/servizio, **preventivo(i)**,
**registrazioni chat**, **fasi**, note, stato → quadro completo e immediato per **Sole** (web + WhatsApp).

✅ **FATTO — generatore** (`ardy-dossier.php`): funzione `ardy_genera_dossier($db,$session)` +
endpoint HTTP. Legge `clienti` + `preventivi` (con voci da `voci_json`) + `fasi` + chat
**WhatsApp** (`wa_messaggi`, match per ultime 9 cifre del telefono). On-the-fly (sempre fresco),
con troncamento dei testi lunghi per il budget token. `?format=md|json`, `?save=1` → scrive
`dossier/<session>.md`. Protetto da Basic Auth (`.htaccess`) **+** header `X-Ardy-Internal`
(`ARDY_INTERNAL_SECRET`) per le chiamate server-to-server (Sole). Read-only.

⏭️ **Da fare (prossimi passi):**
1. **Persistere la chat WEB**: oggi il proxy web è **stateless** → la chat web NON è salvata, quindi
   nel dossier manca. Aggiungere una tabella (es. `web_messaggi` per `session_id`) e farla scrivere
   da `ardy-proxy.php` (come `wa-memoria` per WhatsApp). Poi includerla nel dossier.
2. **Wiring nel prompt di Sole**: iniettare il dossier nel system (con **prompt caching**, come
   `crm_context`) — su **web** solo dopo `cerca_cliente` (identità verificata col codice) per non
   esporre dati in chat anonima; su **WhatsApp** per numero registrato. Attenzione ai token.
3. **Dashboard**: bottone **📄 Dossier** sulla scheda (apre/scarica l'MD) — comodo per Michela.
⚠️ **Privacy/accesso**: dati personali → niente dossier in chat anonima e mai dati di altri clienti.

### 🆕 "Crea FAQ di questa lavorazione" (su stato CONSEGNATO)
Quando la lavorazione passa a **CONSEGNATO**, nella scheda compare — accanto al **Reel** — un
nuovo bottone **"Crea FAQ di questa lavorazione"**:
- Genera con Claude un set di **FAQ** pertinenti al lavoro svolto (dalle fasi/servizio/mobile).
- **Pubblica in automatico** come **aggiornamento dell'articolo WordPress** della lavorazione
  (riusa `ardy-pubblica-lavorazione.php` / API WP) + **dati strutturati FAQ** (`schema.org/FAQPage`,
  JSON-LD nel post) per la SEO.
- Da costruire: endpoint `ardy-crea-faq.php` (genera FAQ + aggiorna post WP + inietta JSON-LD),
  bottone in `acc-reel`/sezione lavorazione visibile solo per CONSEGNATO, anteprima modificabile
  prima della pubblicazione (come per la caption del reel).
- Sinergia col **Dossier**: le stesse FAQ possono arricchire il contesto di Sole.

### 🆕 (DA VALUTARE — grande) Sole esperta di legno & restauro + datazione fotografica guidata
Visione: dare a Sole **conoscenza profonda** di legno, restauro del mobile, riconoscimento dello
**stile** e **datazione/epoca** quasi certa, tramite un **rilievo fotografico guidato** del mobile
(la chat chiede e analizza dettagli diagnostici: incastri a **coda di rondine** — fatti a mano o a
macchina? **fondello del cassetto in massello** o compensato? **segni di lavorazione industriale**?
tipo di legno, ferramenta, patina, ecc.).
- **Pipeline knowledge**: cercare in rete **fonti autorevoli** (legno, tecniche di lavorazione per
  epoca, storia del mobile, restauro) e costruire un **archivio organizzato** (knowledge base) da
  conservare e usare (es. schede per stile/epoca + criteri diagnostici). Da progettare: formato
  (MD/JSON per epoca-stile), storage (file nel repo o tabella DB), e **retrieval** (iniettare nel
  prompt solo le schede pertinenti; valutare un mini-RAG per non gonfiare i token).
- **Flusso diagnostico guidato**: Sole conduce passo-passo (richieste di foto mirate dei punti
  diagnostici) → ipotesi di stile/epoca con livello di confidenza + motivazione sui dettagli.
  Riusa l'analisi foto già presente nella chat web.
- ⚠️ **Accesso riservato**: NON per tutti. Solo per **clienti** o **registrati alla community**.
  La community va **popolata dal modulo Outreach** (`ardy-outreach.html`/`ardy-outreach-api.php`) →
  serve un registro "membri community" (riuso/estensione delle tabelle outreach) e un **gate** di
  accesso a questa feature (in chat web: verifica codice cliente o iscrizione; su WhatsApp: numero
  registrato). Definire prima il modello di membership, poi la knowledge base, poi il flusso guidato.
- Note realismo: la datazione "quasi certa" da sole foto è ambiziosa → impostare come **stima
  motivata** con confidenza, non verdetto; utile anche come lead-magnet per la community.

### ⭐ Backup & centralizzazione dei widget WordPress
Obiettivo: portare sotto git i sorgenti che oggi vivono solo in WordPress, e dove possibile
**centralizzarli** in file serviti dal nostro server (una sola fonte versionata).
✅ **Già fatto**: infra `wordpress-snippets/` (esclusa dal deploy) + `ardychat` centralizzato in
`ardy-chat-site.js` (vedi recap 14/06 e `wordpress-snippets/README.md`).

Contesto: gli snippet stanno in **WPCode** (7) + il loader pagina lavorazione è nelle **integrazioni
di Divi** (già nel repo come `wpcode-snippet-lavorazione.html`). I 7 snippet WPCode: `performance`
(php), `Chat per i corsi` (html), `Pulsante corsi` (php), `Pulsante flottante ovunque` (php — contiene
anche il loader lavorazione!), `Snippet yoast` (php), `Corsi dato strutturato` (php), `ardychat`
(js → **centralizzato ✅**).

⏭️ **Da fare (prossimi passi, uno alla volta):**
1. **Backup completo via export WPCode**: WPCode → Tools → Export All → JSON → splittarlo in
   `wordpress-snippets/` (un file per snippet, nomi/estensioni giusti) + aggiornare la mappa nel
   README. (Oggi c'è solo il backup manuale di `ardychat.js`.)
2. **Centralizzare gli altri widget front-end** (stesso schema di `ardychat`): `Chat per i corsi`
   → `ardy-chat-corsi.js` + loader **HTML**; poi valutare i pulsanti CTA (`Pulsante corsi`,
   `Pulsante flottante ovunque`). Gli snippet **PHP** (SEO/schema/hook) restano **backup-only**.
⚠️ Promemoria trappola: il loader `<script src>` va in uno snippet WPCode di tipo **HTML**, MAI
JavaScript (altrimenti errore di sintassi e il file non si carica). Vedi README.



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
> ✅ **Già fatto — 🧹 "Libera spazio" (punto 1 sotto)** (sessione 14/06): nuova azione
> `libera_spazio` in `ardy-elimina-cliente.php` (conferma "LIBERA") che cancella **solo** foto +
> reel della sessione, **tiene** scheda/preventivi+PDF/fasi/storico WA/pagina sito, e segna
> `foto_archiviate_at` (colonna auto-creata). Pulsante **🧹 Libera spazio** sulla scheda, visibile
> solo per i clienti **archiviati (CONSEGNATO/PAGATO)**; diventa "🧹 Spazio liberato" (disabilitato)
> una volta fatto. `ardy-crm-api.php` espone `foto_archiviate_at`.
> ⏭️ Resta da costruire: **Cestino 30gg / purga** (punti 2-3 sotto).

Contesto: i file pesanti sono **foto** (`ARDY_UPLOAD_DIR/<session>/`) e **reel**
(`reels/reel_<session>_*.mp4`). I PDF preventivo **incorporano le immagini in base64** →
cancellare le foto originali NON rompe i documenti. (Versione "leggera" PERSI già in produzione:
`ardy-archivia-persi.php` + pulsante 🧹 LIBERA SPAZIO PERSI; sposta in quarantena, Michela cancella a mano.)

Decisioni già prese con Michela:
1. ✅ **FATTO — 🧹 "Libera spazio" (solo immagini)** per i clienti conclusi (CONSEGNATO/PAGATO):
   cancella subito cartella foto + reel; tiene scheda/dati/preventivi+PDF/fasi/storico WA/pagina
   sito. Conferma forte ("LIBERA"); segna `foto_archiviate_at` sulla scheda. (Esteso a CONSEGNATO
   oltre a PAGATO perché è l'utente a premere il bottone a lavoro consegnato.)
2. **🗑️ "Elimina tutto" → CESTINO 30 giorni**: soft-delete `deleted_at` su `clienti` → vista
   Cestino con Ripristina; dopo 30gg purga DB (clienti/preventivi/fasi/wa_messaggi/solleciti) +
   tutti i file (foto, reel, PDF). NON tocca pagina WordPress né Media Library. Purga = sweep
   opportunistico (es. in `ardy-crm-api.php`, max N per load), niente cron necessario.
3. **Sicurezza/UX**: modale di conferma (per "Elimina tutto" far scrivere "ELIMINA"); endpoint
   Basic Auth; `session_id` sanificato.

Da costruire (resta il Cestino):
- *Backend* `ardy-elimina-cliente.php` — azioni ✅ `libera_spazio` fatta · ⏭️ `cestina | ripristina
  | purga` da fare (colonna `deleted_at` auto-create; helper file già pronto `ardy_elimina_file_sessione`).
- *API CRM* `ardy-crm-api.php`: escludere i `deleted_at` dalla lista normale; endpoint/param Cestino.
- *Dashboard*: ✅ pulsante 🧹 fatto · ⏭️ vista Cestino + Ripristina; modali conferma.

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

### Archivio clienti CONSEGNATI (dalla sidebar)
> ✅ **FATTO (14/06, sessione corrente)** — scelto l'**archivio implicito per stato** (no nuovo
> flag, coerente col futuro Cestino che userà `deleted_at`, concetto separato). Solo lato dashboard
> (`ardy-michela-app.html`), nessuna modifica backend:
> - `ARCHIVE_STATES`: lo stato conclusivo **CONSEGNATO esce dalla vista TUTTI** (lista "attivi"),
>   così premerlo archivia il cliente senza intasare la lista. ('PAGATO' resta nell'array solo come
>   alias legacy — vedi nota sotto.)
>
> **Rimosso lo stato cliente `PAGATO`** (14/06): coincideva con CONSEGNATO ed era trattato in modo
> identico ovunque; il "saldato / non moroso" è già gestito dal modulo **💸 MOROSI**
> (`solleciti_pagamento.stato`, asse separato — da NON toccare). Tolto da selettore stato, chip
> filtro e guida; lasciato riconosciuto come alias legacy nei raggruppamenti d'archivio così le
> eventuali schede già marcate PAGATO non diventano orfane (riaprendole si ri-taggano a CONSEGNATO).
> ⏭️ Se in DB ci sono schede PAGATO da convertire in blocco, serve un piccolo `UPDATE clienti SET
> stato='CONSEGNATO' WHERE stato='PAGATO'` (non fatto: probabilmente zero record).
> - Pulsante **📦 Archivio (N)** sempre visibile in cima alla lista (compare solo se N>0) +
>   chip **📦 ARCHIVIO** nella Ricerca avanzata → mostrano solo i conclusi. Ripremendo si torna a TUTTI.
> - Toast "📦 Salvato e spostato in Archivio" quando si salva uno stato d'archivio.
> - Guida (`ardy-guida-michela.html`) aggiornata.
> ⏭️ Eventuale evoluzione: contatore separato CONSEGNATO vs PAGATO; coerenza col Cestino 30gg.

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
