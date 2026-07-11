# Ardy Lab — Task aperti & note utili

> Solo task **aperti** + note operative + verifiche residue. Tutto ciò che è fatto **e deployato**
> è rimosso (lo storico resta nei commit git). Ultima pulizia: 04/07/2026.

> ⚠️ Promemoria sempre valido: se Sole tace su **tutti** i canali insieme (WhatsApp + webchat),
> sospetta **credito Anthropic esaurito** (capitato il 21/06; si ricarica da Plans & Billing).

---

## 🛒 APERTO — Ecommerce `object.ardy-lab.it` (Woo) + chat Sole per-oggetto (09/07/2026)

Costruito in sessione `ardy-ecommerce-woocommerce`. Piano completo in **`PIANO-ECOMMERCE-OBJECT-WOO.md`**.
WP+Woo dedicato su `object.ardy-lab.it` (Cloudflare arancione + Full strict; Origin cert `*.ardy-lab.it`).

**Fatto (mergiato in main, PR #45-#51):**
- Fase 0 — `progetti`: campi scheda-Sole (`storia/cura/faq_pubbliche/dimensioni/scheda_sole_pubblica`) +
  slug univoco; endpoint pubblico `ardy-object-scheda.php` (whitelist).
- Fase 1 — chat Sole per-oggetto: `ardy-object-proxy.php` + `ardy-object-system.txt` + `ardy-object-chat.js`
  (contesto lato server, CORS `object.ardy-lab.it`), lib condivisa `ardy-object-lib.php`. Snippet WP di
  iniezione: `wordpress-snippets/object-chat-inject.php` (installato come mu-plugin sul WP negozio).
- Fase 2 — push dash→Woo `ardy-object-push.php` (REST Woo, CK/CS in `ardy-config.php`), pulsante in dash.
- Prezzo confermabile in chat; **foto vendita separate** (tabella `progetto_foto_vendita`, `ardy-object-foto-api.php`,
  `ardy-object-img.php`) — Modulo 1 (galleria→ardy-lab.it) intoccato.
- Usabilità dash design: stati rinominati (VERSIONE_FINALE/REALIZZAZIONE/FOTO), attributi `metodo`/
  `disponibilita`, **UI a card collassabili rivelate per fase** + gating per metodo.

**Da fare / verificare:**
- ⏳ **Verifica a video** della dash a card collassabili (grossa modifica UI, non testata live) — vedi
  checklist di fine sessione. Poi eventuale ritocco layout.
- ⏳ Test push **foto vendita** su un pezzo nuovo (auto-fill immagini su Woo).
- ⚠️ **B2 letture rotte (Opzione A)**: il push foto usa immagini **locali** apposta. Fix radicale del GET
  B2 (SigV4) resta un intervento a parte — impatta anche dash principale e articoli design.
- Aggiornare la nota vecchia qui sotto in "CONGELATI" (già ribaltata: Woo **sì**, chat **è Sole**).

---

## 🎨 APERTO — Seguiti dell'audit usabilità dash (05/07/2026)
Audit + interventi P1/P2/P3 **fatti e deployati** (vedi `AUDIT-USABILITA-DASH.md`): linguaggio
bottoni coerente, sidebar per frequenza, filtri raggruppati, azioni duplicate disambiguate, menu ☰
per i link app su mobile. Restano due code, non urgenti:
- [ ] **Test-utente di validazione** — 15 min guardando Michela sui 3 compiti tipici
      (`TEST-UTENTE-DASH.md`). Batte l'analisi a tavolino: da qui esce la prossima lista di fix,
      ordinata dai fatti.
- [ ] **Theming — Fase 0 residua** (lavoro estetico, non usabilità): migrare i restanti `style=""`
      **non-bottone** (layout griglie/modali) a classi/token e aggiungere token spaziatura/ombra/raggi,
      così la dash diventa "drop-in" per un `theme.css` (vedi `ANALISI-CLAUDE-DESIGN.md`, aggiornato).

---

## 🔎 APERTO — Arricchimento outreach: email non trovata su sito JS (prossima sessione)
Caso reale: B&B **`giubbonarisuites-adm.com/contatti`** (gruppo ADM Hospitality). L'arricchimento
trova il **sito** (via Google Places) ma **NON l'email**, né con Haiku né con Sonnet — anche dopo il
fix "passo 1c" (visita il sito appena Google lo trova). Sulla pagina `/contatti` a occhio ci sono 2
email: `Info@adm-hospitality.com` e `giubbonari @adm-hospitality.com` (con uno spazio prima della @).

**Ipotesi da verificare (in ordine):**
1. **Sito JS-rendered**: `ardySafeHttpGet` scarica l'HTML grezzo; se le email sono iniettate via
   JavaScript non compaiono nel sorgente → lo scraping non le vede. *Verifica:* logga status code +
   se la stringa `@adm-hospitality` è presente nell'HTML grezzo di `/contatti`.
2. **Email offuscate con spazio** (`giubbonari @adm-...`): la regex non le cattura. `Info@adm-...`
   (senza spazio) invece dovrebbe essere catturata **se** è nell'HTML grezzo (vedi punto 1).
3. **Dominio diverso / redirect / 403**: il sito da Places potrebbe non essere quello giusto, o
   bloccare il fetch. *Verifica:* quale URL ha proposto Places e cosa risponde.

**Possibili interventi (da valutare costi):** log diagnostico del fetch; gestire l'offuscamento
"spazio @"; fallback con rendering headless solo on-demand. File coinvolti: `ardy-enrich.php`
(`ardyEnrichScrapeSite`, `ardyEnrichExtractEmail`), `ardy-net.php` (`ardySafeHttpGet`).

> Selettore modello AI (Haiku/Sonnet) in dash già fatto e deployato; Haiku è il default economico.

---

## 🗺️ STANDBY — Ricerca OSM: timeout Overpass su raggi ampi (deciso di rimandare)
**Confermato:** con **raggio 1 km la ricerca OSM funziona**; su raggi ampi (es. 10 km su Roma) Overpass
va in **timeout** e il tool mostrava "0 trovati / Servizio non raggiungibile". **Google funziona bene.**
Fix già deployato (`ardy-outreach-api.php`): riconosce il `remark` di timeout, ritenta sul mirror
`overpass.kumi.systems`, e dà un messaggio che invita a ridurre il raggio (3–5 km).

**Workaround attuale:** usare raggi piccoli (1–5 km) per quartiere, o la fonte **Google** per zone ampie.

**Da valutare alla ripresa (se serve coprire aree grandi su OSM):**
- query più leggera (solo `node`? togliere `tourism=bed_and_breakfast`, raro, e tenere `guest_house`);
- "chunking" automatico dell'area in più celle piccole con merge dei risultati;
- istanza Overpass dedicata/a pagamento, oppure preferire Google per i raggi grandi;
- verificare se il mirror kumi è raggiungibile dal host (firewall — vedi `ANALISI-FIREWALL-HOST.md`).

---

## ⏰ DA CONTROLLARE SUBITO (prossima sessione) — i 2 CRON sono davvero attivi?
La sessione 25/06 ha deployato briefing mattutino + rollover nota (vedi sotto). Michela ha **impostato i cron
di corsa** ma NON ha fatto in tempo a verificarli. **Primo task: confermare che siano attivi e che girino.**

I due cron previsti (server, fuso Europe/Rome; `<SEGRETO>` = `WA_LOOKUP_SECRET`):
```
0 6 * * 1   curl -s -H "X-Ardy-Secret: <SEGRETO>" https://ardyagent.ardy-lab.it/ardy-rollover-nota.php   >/dev/null 2>&1
0 9 * * 1-5 curl -s -H "X-Ardy-Secret: <SEGRETO>" https://ardyagent.ardy-lab.it/ardy-briefing-mattino.php >/dev/null 2>&1
```
**Come verificarlo:**
- **Diretto** (serve accesso server): `crontab -l` dell'utente giusto, oppure la UI **cPanel → Cron Jobs**.
  ⚠️ Attenzione all'utente: l'app gira come `micoperibg` ma il cron potrebbe essere su un altro utente/root —
  controllare dove è stato messo. Il comando deve avere l'header `X-Ardy-Secret` col valore reale.
- **Indiretto** (anche dalla sessione web, senza SSH): (a) il **briefing**: Michela ha ricevuto l'email delle
  ~9:00 in un giorno feriale? (b) il **rollover**: lunedì dopo le 06:00 dev'esserci in `note_staff` una riga
  nuova con `settimana` = ISO della settimana corrente e `created_at` ~06:00 (lo si vede dalla nota in dashboard:
  data "agg." aggiornata al lunedì). Se manca, il cron del rollover non è partito.
- **Test manuale** (sempre ok per provare l'endpoint a mano): aggiungere `?force=1&secret=<SEGRETO>` all'URL.

---

## 💾 BACKUP OFF-SITE Backblaze B2 — ✅ FATTO (sessione `ardy-infra`, 24/06/2026)
Chiude il punto **#4 (backup off-site)** del `PIANO-MIGRAZIONE.md` — fatto **ora, sull'infra attuale**
OVH/cPanel, senza aspettare il cutover sul nuovo VPS.
- **cPanel → B2** configurato su **entrambi i VPS gemelli OVH (WHM/cPanel)** con **bucket dedicati e
  isolati per server**: Server 1 → bucket `UGLmico` (prefisso `srv1`); Server 2 → bucket `micoper`
  (chiave ristretta). Lifecycle **30 giorni** + **SSE-B2** attivi; destinazioni **validate e abilitate**
  (`disabled:0`).
- **n8n** (solo Server 2): backup del volume `/opt/n8n/n8n_data` (SQLite) via **rclone**, script
  `/root/bin/n8n-backup.sh` in **cron alle 04:00** → `micoper/n8n/`.
- Guida + script committati su repo **`ardy-infra`** (branch `claude/modest-cori-hc1w1l`).
- **Nessun impatto sull'app Ardy**: backup separati, niente da toccare nel codice.

**Pending (non bloccante):**
1. Verificare che i primi backup completi abbiano popolato `UGLmico/srv1/` e `micoper/srv2/` (upload in corso).
2. **Cleanup sicurezza**: ruotare la app key del Server 1 (ora "All buckets") in una **ristretta a `UGLmico`**
   per isolamento totale.
3. Fare almeno una **prova di restore** reale (account cPanel di test + volume n8n).

---

## 🔧 NOTE OPERATIVE (servono sempre)

**⚠️ Sole non risponde su WhatsApp ma la webchat sì → è n8n giù.** Il ramo WhatsApp è
Meta → n8n → `ardy-wa-lookup.php` → Claude; la webchat (`ardy-proxy.php`) NON passa per n8n.
Check rapido: aprire `https://n8n.ardy-lab.it` (503/523 = giù). Sul server (root via SSH/WHM):
```
docker ps | grep -i n8n          # il container è n8n_app, espone SOLO 127.0.0.1:5678
systemctl is-active docker
docker start n8n_app             # se è giù ma Docker è su
```

**⚠️ firewalld DISABILITATO di proposito (19/06/2026) — non riattivarlo.** Con firewalld attivo il
daemon Docker non parte (nftables `el9_8` vs firewalld `el9_7`, passthrough rotta). Soluzione applicata:
`systemctl stop firewalld && systemctl disable firewalld`. Se Docker non parte dopo un reboot/aggiornamento,
verificare `systemctl is-enabled firewalld` = `disabled`.

**✅ FIREWALL HOST = csf LIVE.** Firewall host = **`cpanel-csf`** (fork cPanel; installare SOLO via
`yum install cpanel-csf`, mai il tarball — ConfigServer ha chiuso). Config + runbook in
**`ANALISI-FIREWALL-HOST.md`**. Punti critici:
- **Docker/n8n**: csf con `DOCKER=1` + `DOCKER_DEVICE/NETWORK4` sul bridge reale `br-b118407a7c22`
  (172.18.0.0/16) **e** `ETH_DEVICE_SKIP="br-b118407a7c22"`. ⚠️ Se la rete `n8n_default` viene ricreata,
  il nome bridge cambia → aggiornare quei valori e `csf -r`.
- **Cloudflare** in `csf.allow` (mai bannare CF). **Fail2ban** disabilitato (LFD di csf lo rimpiazza). **rpcbind** off.
- Egress aperto per ora → tightening è un follow-up (occhio alla porta SMTP di Brevo).

**Deploy sul server** (da root):
```
runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'
```
**⚠️ Schema DB = `ardy-migrate.php`** (eseguito da `deploy.sh` dopo l'rsync). Unico posto dove si
creano/alterano tabelle e colonne (niente DDL negli endpoint). Idempotente. **Nuova tabella/colonna → qui.**

**Log errori PHP**: `/home/micoperibg/logs/ardyagent_ardy-lab_it.php.error.log`
(es. `grep "ARDY USAGE" <file> | tail -8` per token in/out/cache).

**Auth endpoint via fetch**: NON usare `ardyRequireAuth()` (in CGI/FPM l'header `Authorization` non
arriva → rifarebbe login). Affidarsi al `.htaccess` (Basic Auth).
> ⚠️ Un endpoint "protetto via .htaccess" lo è SOLO se il nome è **elencato** nel blocco `<FilesMatch>`.
> Ogni nuovo endpoint riservato va aggiunto a quella regex e testato con `curl` senza credenziali (deve dare 401).

**⚠️ Git — lineage di `main`**: la storia BUONA parte dal root `98b352f`. Esiste una vecchia lineage
orfana (root `b49606b`, i "v2.0…") da NON rifondere. Se git dà *"refusing to merge unrelated histories"*,
quel branch è sulla lineage vecchia → riparti da `origin/main`. Chiudere sessione: branchare da
`origin/main` aggiornato e fast-forward su `main`.
> ✅ **Cleanup branch fatto (21/06/2026):** i vecchi branch `claude/*` sono stati cancellati. Prima della
> pulizia, verificato per CONTENUTO che il loro lavoro fosse già in `main`; recuperato il solo file unico
> utile, `ARDY-EXPERIENCE-PIANO-TECNICO.md` (da `quirky-davinci`). Scartato `ardy-wa-ricevi-pdf.php`
> (da `quote-data-extraction`, feature WhatsApp-PDF incompleta) per scelta. ⚠️ La cancellazione branch NON
> è possibile dalla sessione web (proxy git nega il delete dei ref ≠ branch di sessione, 403; il GitHub MCP
> non espone delete-branch) → va fatta dalla UI GitHub o con `gh`/`git push --delete` da una macchina con i permessi.

**Outreach — Google Places**: chiave in `ardy-config.php` = `ARDY_GOOGLE_PLACES_KEY` (Places API New,
ristretta per IP del VPS — le chiamate escono in IPv4 forzato). Tetto giornaliero `ARDY_PLACES_DAILY_CAP`
(default 500; `0` = illimitato). Numero WhatsApp di Sole nelle email/lettere = `ARDY_WA_PUBLIC_NUMBER`
(fallback `393793756437`). Mittente lettere cartacee = costante `ARDY_MITTENTE` in `ardy-outreach.html`.

**`session_id`**: sempre sanificato (no path traversal) prima di toccare i path file.

**PDF preventivo**: cache per content-hash (`PDF_CACHE_VER` in `ardy-preventivo.php`). Se cambi layout/CSS, **bumpa `PDF_CACHE_VER`**.

---

## 🧭 OUTREACH — ROADMAP (idee 21/06, da progettare)
La direzione che vogliamo dare allo strumento, da affrontare per prossimi step:

1. **Pipeline lead — evoluzioni** (la v1 è live: vista Pipeline + promozione a Cliente/Partner). Idee:
   drag&drop tra fasi; campo "valore/nota trattativa"; collegamento del lead alla **campagna d'origine**;
   notifica a Michela quando un lead passa a "Risposto".
2. **Campagne con obiettivi diversi + Sole espone il piano** — es. B&B "Galleria Diffusa": Sole deve saper
   **esporre il piano marketing del progetto** a tutti i registrati sul CRM, sia su **WhatsApp** sia su una
   **pagina dedicata** (webchat + **codice di verifica**, come il `codice_accesso` cliente esistente).
3. **Prompt dedicato per campagna** — ogni campagna ha il suo prompt per Sole (contesto/obiettivo), così Sole
   risponde in linea con quell'iniziativa.
4. **Codice etico AI — ✅ DEPLOYATO 25/06.** Riga "Come usiamo l'AI" (non aggressione, tutela
   privacy/sicurezza dati, mai uso fraudolento) ora in TUTTE le email (`ardy_email_codice_etico()` in
   `ardy-email.php` → footer cliente, grazie-consegna, solleciti, outreach `brevoSend`), nelle lettere cartacee
   e anteprime (`ardy-outreach.html`), e nei system prompt di Sole (`ardy-system.txt` → web+WhatsApp,
   `ardy-proxy-lavorazione.php` → chat lavorazione). ⚠️ Verificare dal vivo dopo deploy che la riga compaia in
   un'email reale e che Sole sappia esporre il codice etico se richiesto.

### 👥 Outreach — Import clienti AUTOMATICO post-Acconto — ✅ DEPLOYATO 25/06
L'import **manuale** dei clienti CRM era già LIVE. Aggiunto l'**automatico**: quando un cliente entra per la
prima volta in uno stato "impegnato" (`ACCONTO`, `RITIRATI`, `IN_LAVORAZIONE`, `COMPLETATO`, `CONSEGNATO`,
`PAGATO` — gestisce anche il salto diretto Acconto→Ritirati/Lavorazione), viene aggiunto ai contatti outreach
in **categoria `clienti`** con **stato `cliente`** (NON `da_contattare`), così resta **distinto dai lead freddi**
e **fuori dalle campagne cold** (che targetizzano solo `da_contattare`) → privacy ok: è servizio/riattivazione,
non cold-marketing. Hook in `ardy-update-lead.php` (riusa `$statoVecchio` già letto), logica in nuova lib
condivisa `ardy-outreach-lib.php` (`ardy_outreach_aggiungi_cliente()`), **idempotente** (dedup per email/nome
come l'import manuale, richiede email). Lib aggiunta al blocco deny del `.htaccess` (interna, non API).
**Nota:** l'import *manuale* continua a usare stato `da_contattare` (azione esplicita di Michela); l'*automatico*
usa `cliente` di proposito. ⚠️ Da verificare dal vivo: portare un cliente con email su ACCONTO → compare in
Outreach categoria "clienti" con badge "cliente"; ri-salvare/altre transizioni NON creano doppioni.

### 🔌 Outreach — Altre fonti dati (VIES / P.IVA) — NOTA
Portali aziendali IT quasi tutti gated → niente scraping. Vie aperte utili: **VIES** (ec.europa.eu, gratis,
API senza chiave: P.IVA → ragione sociale + indirizzo ufficiale) **da integrare col futuro campo Partita IVA**;
OpenCorporates (tier gratuito); Registro Imprese scheda gratuita indicizzata. I portali gated si attingono già
via il passo **Claude web search** dell'agente Arricchimento. Google Places + web search coprono il grosso.
INI-PEC non automatizzabile (captcha) → PEC via API a pagamento, eventuale task a sé.

### 🪶 Outreach — migliorie minori (quando capita)
- **QR code** (wa.me/sito) sulla lettera cartacea; template "cartaceo" dedicato; tuning offset busta a finestra.
- **"🔄 Rigenera / varianti"** nel generatore "Crea con AI".
- Dedup contatti più "furbo" (normalizzazione: togliere Srl/Snc, accenti, spazi) per beccare le varianti di nome.

---

## ⏳ DA VERIFICARE DAL VIVO / AZIONI MANUALI
- **✦ Avvio pagina lavorazione dal box "Periodo del lavoro" (04/07, da testare) — DEPLOYATO, mai provato dal vivo.**
  Nel box "📅 Periodo del lavoro" (compare passando un cliente a IN_LAVORAZIONE), sotto le date c'è ora una
  sezione **"✦ Avvia la pagina lavorazione"**: foto (SCATTA FOTO / DALLA GALLERIA) + bottone **PUBBLICA AVVIO
  LAVORO + NOTIFICA CLIENTE**. Riusa lo stesso endpoint delle fasi (`ardy-pubblica-lavorazione.php`, `fase_nome`
  fisso "Avvio lavorazione"): crea il **primo** post WordPress della lavorazione, invia email + WhatsApp al
  cliente, e il box sparisce da solo una volta creato il post (da lì in poi si aggiorna con "🔨 Crea e pubblica
  nuova fase" più sotto). Nessuna modifica al backend. **Test da fare:** mettere un cliente reale/di prova su
  IN_LAVORAZIONE → scattare/scegliere una foto nel nuovo box → pubblicare → verificare (a) il post compare sul
  sito con foto e testo generato da Claude, (b) email + WhatsApp arrivano al cliente, (c) il box sparisce e la
  fase "Avvio lavorazione" compare in "📋 Fasi pubblicate", (d) una fase pubblicata dopo dal modulo sotto
  **aggiorna la stessa pagina** (non ne crea una seconda). File: `ardy-michela-app.html` (box date + JS
  `pubblicaAvvioLavoro`/`handleAvvioFotoUpload`/`aggiornaVisibilitaAvvio`).
- **Rimosse "Fasi previste dal sopralluogo" + badge "📐 da pianificare" (04/07) — DEPLOYATO, verifica veloce dopo
  deploy.** Su richiesta di Michela: tolto dal box Note il pannello "📐 Fasi previste dal sopralluogo (dalla
  libreria)" (generava bozze fasi da template scelti durante il sopralluogo — giudicato ridondante) e il badge
  sidebar "📐 da pianificare" che gli era collegato. Restano invariati il semaforo giallo "Date da pianificare"
  (date lavoro non impostate) e le altre due vie per creare bozze fasi (💾 SALVA IN BOZZA, estrazione da
  preventivo PDF). **Verifica dopo deploy:** aprire una scheda in SOPRALLUOGO/ACCONTO con una nota → non deve
  comparire più né il pannello template nel box Note né il badge "da pianificare" in sidebar.
- **Date sopralluogo/consegna in dashboard (nuovo) — test dopo deploy:**
  - *Sopralluoghi (lista, Fase 1)*: ✅ deployato; aggiungi/sposta/elimina ci sono e **rispondono bene**
    (test rapido ok). Restano i test funzionali completi: nella scheda, sezione "📅 Sopralluoghi" → **Aggiungi** una visita
    (data/ora + etichetta) e verificare che su **Google Calendar** compaia l'evento; **sposta** una
    visita (cambia data/ora + 💾) → l'evento si sposta, niente doppione; **elimina** (🗑) → l'evento
    sparisce anche dal calendario. Aggiungere una **seconda** visita allo stesso cliente (es. "2°
    sopralluogo") → devono coesistere. Verificare che un sopralluogo fissato da **Sole su WhatsApp**
    compaia poi nella lista (riconciliazione "pigra" alla riapertura scheda).
  - *Passo 2 — Data di consegna*: impostare il campo "📦 Data di consegna" su una scheda con email,
    salvare → verificare che al cliente arrivi l'**email di conferma consegna** (riusa il modulo
    Trasporti, guard "una sola email per data": ri-salvando la stessa data NON deve re-inviare).
  - *Sopralluoghi via Sole su WhatsApp (Fase 2)* — solo PHP, NIENTE re-paste n8n: da chat staff provare
    "aggiungi un 2° sopralluogo per Alberto giovedì alle 15" (deve AGGIUNGERE, non dire "ha già un
    appuntamento"); "che sopralluoghi ha Alberto?" (li elenca); "sposta il sopralluogo di Alberto" quando
    ne ha 2 → Sole deve CHIEDERE quale. Verificare che le visite aggiunte da Sole compaiano anche nella
    lista in dashboard, e viceversa. ⚠️ Nota: il cliente che prenota da sé (canale cliente) resta a UN
    sopralluogo (mirror); compare nella lista via riconciliazione — ampliarlo è un eventuale follow-up.
- **Test Piano B — spostamento appuntamento su WhatsApp** (da numero NON staff con sopralluogo già fissato):
  verificare (a) l'evento Google Calendar si SPOSTA (non ne crea uno nuovo), (b) Michela riceve notifica
  "SPOSTATO" (WA + email), (c) al cliente arriva la conferma del nuovo orario.
- **Recuperare le date perse pre-fix** (es. Margherita Mottini): reinserire a mano (il fix anti-clobber evita
  che ricapiti ma non ripristina i dati persi).
- **UX "Modifica" su Preventivo (allegato)**: l'estrazione "🔍 Leggi dati dal PDF" è solo al primo allegato,
  non in modifica. Da decidere se serve un modo per ri-estrarre/correggere prezzi in modifica senza doppioni di fasi.
- **Template `aggiornamento_fase`** (4 var) e **`sollecito_pagamento`**: provare con caso reale/fittizio.
  ⚠️ Confermare che il body del template Meta `aggiornamento_fase` abbia **esattamente 4 variabili** (altrimenti
  Meta rifiuta, err 132000/132018).
- **Conoscenza di bottega di Sole** (`ardy-conoscenza-restauro.txt`): è una **v1** → Michela la rivede e la
  "ardy-izza" con le sue tecniche/parole.
- **Export WPCode** (Tools → Export All → JSON): rinfrescare il backup `wordpress-snippets/` + mappa.
- **Briefing del mattino con la nota settimanale (FIX deployato, da verificare dal vivo):** al "buongiorno"
  Sole deve ora includere da sola il blocco "🗒️ COSE DA FARE QUESTA SETTIMANA" (la nota più recente),
  senza che Michela la chieda. Fix: blocco aggiunto in `ardy_riepilogo_settimana()` + istruzione briefing
  in `ardy-wa-lookup.php`. Test: salutare Sole al mattino con una nota salvata → deve elencarla nel resoconto.
- **💬 Risposte WhatsApp alle notifiche → in dash nelle Conversazioni (DEPLOYATO 04/07, PR #34, mai provato dal
  vivo).** Quando il cliente risponde su WhatsApp a una notifica della dash (inizio lavoro / fase / grazie /
  sollecito), la risposta viene ora salvata dal **webhook** in `wa_messaggi` (nuova lib `ardy-wa-store.php`,
  idempotente per `wa_msg_id` + dedup morbido vs n8n) e compare nella scheda cliente → 💬 Conversazioni, **anche
  se Sole non risponde**. Le notifiche in uscita sono registrate come `role=assistant` (riga 📢) per dare
  contesto. **PREREQUISITO:** `ardy-migrate.php` deve aver creato la colonna `wa_messaggi.wa_msg_id` + indice
  `uq_wa_msg_id` (verifica in phpMyAdmin o `php ardy-migrate.php` → riga `OK/skip wa_messaggi.wa_msg_id`).
  **Test dal vivo:** da un numero che è un cliente in CRM, scrivere a Sole (+39 379 375 6437) un testo
  riconoscibile → aprire la scheda di quel cliente → 💬 Conversazioni → deve comparire il messaggio in arrivo
  (e, se prima è stata inviata una notifica, anche la riga 📢 in uscita). Se non compare: cercare
  `ARDY WA STORE` / `ARDY WEBHOOK persist` nell'`error_log` (di solito = migrazione non eseguita); controllare
  in `ardy-wa-log.json` se il messaggio è arrivato al webhook.

---

## 🌐 GOOGLE BUSINESS PROFILE — post automatici delle fasi (ANCORA IN ATTESA — check dal vivo 04/07 negativo)
**Obiettivo**: pubblicare i post delle fasi sul profilo Google Business **Ardy di Michela Panella**.
**STATO REALE (04/07):** nonostante l'attesa dei 7-10 gg lav. fosse scaduta, `ardy-gbp-check.php` in
produzione ha dato **403 "ACCESSO ALLA BUSINESS PROFILE API NON CONCESSO"** — risposta HTML generica di
Google (non un errore JSON dell'API), cioè la richiesta è respinta **prima** di arrivare al servizio: il
progetto Cloud (Project Number ricavato dal client_id OAuth: **532339794075**) **non è nell'allow-list**
della Business Profile API. Il form Basic API Access (17/06, ID 3-7851000041139) o non è ancora stato
lavorato da Google, oppure è stato inviato per/da un progetto Cloud diverso da quello 532339794075.
**Aggiornamento (04/07, risposta support Google via email, operatore "Ravi"):** NON è (solo) questione di
attendere l'approvazione — è un passo MANUALE da fare in Cloud Console. Il supporto dice: l'endpoint
**"Google My Business API"** non è *enabled* nel progetto Cloud (è un'API privata, visibile solo dopo che
l'account è stato "provisionato" da Google).
**Aggiornamento (04/07, verificato in console):** il progetto Cloud è **`ardy-lab`** (Project Number
532339794075), account loggato **`ardy.documenti@gmail.com`**. **"Google My Business API" risulta GIÀ
presente** nella dashboard delle API del progetto (badge "Privato") — quindi il passo "Enable" non è il
problema. Nonostante questo, `ardy-gbp-check.php` dava ancora 403 "non in allow-list": il blocco è quindi
sul **provisioning dell'account** lato Google (non sull'enablement dell'API).
**Fatto (04/07):** risposta inviata alla mail di supporto Google (operatore "Ravi") con i dati per il
provisioning:
- Google account: **ardy.documenti@gmail.com**
- Cloud project: **ardy-lab** (project number 532339794075)
- Request ID originale: **3-7851000041139**
**Aggiornamento (11/07, precisazione):** filtrando le quote per servizio (`mybusiness.googleapis.com`) nella
pagina progetto-wide di IAM & Admin → Quote, le **8 quote di Google My Business API risultano popolate e
sane** (es. 250.000 requests/day, 3.000 V4 General Requests/minuto, ecc.) — "0%"/"0" in quella vista sono
solo **utilizzo attuale** (zero chiamate riuscite finora), NON il limite. Quindi non è un problema di quota
numerica: il blocco resta il **gate di allow-list** a monte (stesso che dava 403 prima di arrivare al
servizio). Il pulsante "Modifica quote" in Console **non è la via giusta** per questa API privata.
**Ri-testato `ardy-gbp-check.php` l'11/07 (15:19):** **stesso identico 403** "ACCESSO NON CONCESSO" di
prima — nessun cambiamento nonostante l'API risulti enabled e le quote popolate. Conferma che l'unico
sblocco possibile è il provisioning manuale lato Google, non azionabile da Cloud Console.
**Fatto (11/07, pomeriggio):** inviato il sollecito di follow-up allo stesso thread di Ravi (dati già
forniti il 04/07: account `ardy.documenti@gmail.com`, progetto `ardy-lab` / 532339794075).
**Da fare (prossima sessione):**
1. Controllare la mail (anche SPAM) di `ardy.documenti@gmail.com` per la risposta al sollecito.
2. Se continua il silenzio oltre metà/fine luglio, valutare un ulteriore sollecito o un canale diverso
   (community/forum Business Profile API, se esiste un contatto più diretto).
3. Ri-lanciare `ardy-gbp-check.php` periodicamente (nessuna altra azione utile lato Cloud Console/codice
   finché Google non fa il provisioning), finché non dà verde ("QUOTA SBLOCCATA").

**Lato codice (già pronto, riabilitare SOLO a check verde):** il toggle Google nel pannello social
(`ardy-michela-app.html`, `socialDestHtml`) è stato **ri-disattivato** dopo l'esito negativo del check —
era stato riabilitato per errore in base a un annuncio poi smentito dal test dal vivo. `inviaSocial()` è
già cablato per spedire in parallelo a `ardy-pubblica-social.php` (Facebook/Instagram, via n8n) e
`ardy-gbp-post.php` (Google, diretto — nessuna modifica al nodo n8n); basta rimettere `false` nell'ultimo
argomento del `tog('google', …)` in `socialDestHtml` per riattivarlo. Fix di sicurezza già applicato e
valido a prescindere dall'esito: `ardy-gbp-post.php`/`ardy-gbp-check.php` ora protetti da Basic Auth in
`.htaccess` (prima erano esposti senza protezione), `ardy-gbp.php` (lib) nel deny.
**Ad approvazione confermata:** riabilitare il toggle (vedi sopra) → pubblicare una fase di test →
verificare che il post compaia davvero sulla scheda Google (non solo `success:true`).

---

## 📋 TASK DA SVILUPPARE (aperti)

### ☁️ Media su Backblaze B2 — off-load disco + semilavorato migrazione (PIANIFICATO 24/06)
**Obiettivo doppio:** togliere i media dal disco del server attuale (oggi si "intasa") **e**, nello stesso
gesto, pre-staggiare i media su B2 così che alla migrazione sul nuovo VPS (vedi `PIANO-MIGRAZIONE.md`
Fase 3/5) **non** si debba ri-trasferire tutto al cutover — la nuova app punta allo stesso bucket. È la
Fase 5 del piano ("upload diretti su B2") anticipata sull'infra attuale, come già fatto per il backup off-site.

**Decisioni prese (24/06):**
- **Scope v1 = foto private (scheda + chat) + video/reel** (i veri mangia-disco). Restano **fuori**: le foto
  di fase **già pubblicate su WP Media Library** (pubbliche, gestite da WP) e — per ora — la cache PDF
  preventivi (rigenerabile; eventuale fase 2).
- **Semilavorato = sync continuo via cron** (backfill iniziale + cron che tiene B2 allineato al disco):
  a fine migrazione è sempre aggiornato e funge anche da **backup media off-site continuo**.

**Architettura (privacy invariata):**
- Bucket **`ardy-media` PRIVATO** + app key **ristretta a quel bucket** (creazione manuale in console B2 —
  prerequisito infra, non da codice). Riusa l'account B2 esistente.
- **B2 via API nativa** in un piccolo `ardy-b2.php` (curl: `b2_authorize_account` → token in cache ~24h →
  `b2_upload_file`/`b2_download_file_by_name`/`b2_delete`). Niente SDK pesante (composer ha solo mpdf),
  coerente con lo stile del repo. (Alternativa S3-compatibile scartata: servirebbe SigV4/aws-sdk.)
- **Le immagini restano private**: gli script che oggi le servono (`ardy-lead-foto.php`, immagini chat in
  `ardy-proxy.php`, ecc.) diventano **proxy** che leggono da B2 e streammano dietro Basic Auth → **zero URL
  pubblici, zero modifiche al frontend**.
- **Layer di astrazione `ardy-storage.php`** (`put/get/delete/exists`) così i chiamanti non sanno se è disco
  o B2 → migrazione incrementale e reversibile.

**Fasi (a basso rischio, reversibili):**
1. Console B2: creare bucket `ardy-media` privato + app key ristretta → costanti in `ardy-config.php`
   (`ARDY_B2_KEY_ID`, `ARDY_B2_APP_KEY`, `ARDY_B2_BUCKET`, `ARDY_B2_BUCKET_ID`, flag `ARDY_B2_ENABLED` per
   rollout graduale). Aggiungere `ardy-b2.php`/`ardy-storage.php` al deny `.htaccess` (interne, non API).
2. `ardy-b2.php` (auth+cache token) + `ardy-storage.php` (astrazione disco/B2).
3. **Backfill** one-shot: sincronizza i media esistenti (`ARDY_UPLOAD_DIR/<session>/`, `lavorazioni/`, reel)
   su B2 con manifest. = il "semilavorato".
4. **Write path**: i nuovi upload (foto scheda/chat, video, reel) scrivono su B2; opzionale dual-write su
   disco per i primi giorni di sicurezza.
5. **Read path**: i serve-script provano B2 → **fallback a disco** (niente si rompe in transizione).
6. **Cron sync** continuo (allinea disco→B2, riconcilia eventuali delta) + **flip** a B2-only e reclaim del
   disco quando stabile.

**Note/attenzioni:** i reel sono intermedi (poi vanno su social/WP) — valutare se off-loadarli o cancellarli
post-pubblicazione; coordinare con `ardy-archivia-persi.php` (oggi sposta foto/reel dei PERSI in
`_da_liberare/`) e con `ardy-elimina-cliente.php` (cancella `ARDY_UPLOAD_DIR/<session>`) perché dovranno
agire anche su B2. ⚠️ Sul nuovo VPS no-panel il backup B2 va comunque rifatto in chiave no-cPanel (dump DB
cron + sync media) — questo task copre proprio il lato media.

### 🪑 Nuovo stato cliente "RITIRATI" — ✅ DEPLOYATO 25/06
Stato per i mobili **già prelevati e in laboratorio**, ma con **lavori non ancora avviati** (limbo tra
ACCONTO e IN_LAVORAZIONE). Implementato: posizione **tra ACCONTO e IN_LAVORAZIONE** nel flusso e nel filtro
sidebar; badge teal (`.stato-RITIRATI`); mostra **Preventivi + Lavorazione** (può già preparare le fasi in
bozza). Toccati: `ardy-michela-app.html` (chip filtro, `const stati`, `statiPrev`, `statiLav`,
`FASI_BADGE_STATES`, dropdown import), `.css` (badge), e le whitelist/descrizioni stati lato Sole/import
(`ardy-wa-crea-scheda.php`, `ardy-import-scheda-pdf.php`, `ardy-import-preventivi.php`, `ardy-wa-agent.php`,
`ardy-proxy.php`). Nessuna migrazione DB (`clienti.stato` è stringa libera).
**Follow-up rinviato (deciso):** promemoria "in giacenza da N giorni" (richiede colonna data + logica). Il
segnale-scadenza (`faseSegnale`) resta escluso per RITIRATI perché i lavori non sono avviati (niente date).
⚠️ Da verificare dal vivo dopo deploy: settare un cliente su RITIRATI, controllare badge/filtro e che
compaiano sia Preventivi sia Lavorazione.


### 🚚 Trasporti — aggiungere il WhatsApp ai 2 messaggi (oggi solo email)
Il flusso consegne/ritiri è LIVE solo via email: "è pronto" automatico al passaggio a COMPLETATO + messaggio
con la data dalla "giornata Trasporti". Manca il **WhatsApp**: servono **2 template Meta approvati**
(es. `WA_TEMPLATE_PRONTO` 1 var, `WA_TEMPLATE_TRASPORTO` 2 var: mobile + data). Poi agganciare l'invio WA in
`ardy_invia_pronto()` e `ardy_invia_avviso_trasporto()` (punti predisposti) + costanti in `ardy-config.php`. (media)

### 📲 Template WhatsApp avanzamento fase — aggiungere codice/social (lato Meta)
Il WA di avanzamento usa il template Meta `WA_TEMPLATE_FASI` a 4 var (nome · mobile · fase · link): codice
personale/social/spiegazione non si possono aggiungere da PHP. Vanno aggiunti **modificando il template su Meta**
e rifacendolo approvare, poi allineare i parametri in `inviaWhatsAppCliente()` (`ardy-pubblica-lavorazione.php`).
Quei dati ci sono già nell'email e nella pagina lavorazione. (bassa)

### 🎨 Adottare temi/layout da "Claude Design" — ANALISI PRONTA (da decidere)
Analisi completa in **`ANALISI-CLAUDE-DESIGN.md`**. Blocco vero: **363 `style="…"` inline** nel dashboard
scavalcano i temi. Procedura: Fase 0 (inline→classi + ampliare i token, su branch) → poi un solo `theme.css`
drop-in da Claude, provato su staging. Fattibilità: dashboard **alta**, webchat **media**, WordPress/Divi **bassa**.

### 👥 Accesso "dipendente" con permessi limitati (ruoli)
Quando Ardy avrà un dipendente. Deciso: lo `staff` può SOLO preventivi + schede cliente (+ fasi collegate); il
resto admin-only. `ardyAuthUser()` già restituisce lo username → manca lo strato ruoli. 3 mosse:
1. Mappa utente→ruolo in `ardy-config.php` (es. `define('ARDY_RUOLI', ['michela'=>'admin','andrea'=>'admin','dipendente'=>'staff']);`).
2. **Muro backend**: `ardyRole()` + `ardyRequireRole('admin')` in cima a OGNI endpoint admin-only (CRM, stats,
   solleciti, grazie-consegna, outreach, email-finder, elimina-cliente, import-*, dossier, gcal). Il confine vero
   è il check PHP su ogni endpoint, non l'`.htaccess`. Se ne manca uno, è un buco.
3. Cosmesi frontend: endpoint `/me` col ruolo → la dashboard nasconde i bottoni admin per lo `staff`.
Nuovo utente: `htpasswd -B <path> dipendente` (mai `-c`).

### Migliorie minori UX / dashboard CRM (bassa priorità)
- **Filtro sidebar default su ACCONTO/IN_LAVORAZIONE** invece di TUTTI (da decidere sull'uso reale).
- **Briefing del mattino** (opzionale): salvare data ultimo briefing per numero così il riepilogo lungo parte
  da solo al primo "buongiorno" (oggi parte quando Michela chiede "come va oggi?").
- **Widget WordPress** — ✅ FATTO nel backup repo (`wordpress-snippets/pulsante-flottante-ovunque.php`:
  testo del pulsante ora "Chatta con **Sole**", aria-label già "Sole"). ⚠️ Lo snippet repo è SOLO un backup:
  per renderlo live va re-incollato nel WPCode id 15243 ("Pulsante flottante ovunque") da WordPress.
- **Nota settimanale "cose da fare" in dashboard** — ✅ FATTO (DEPLOYATO 24/06, test live pendente). Pannello
  nella home (empty state, quando nessun cliente è selezionato): mostra la nota più recente a colpo
  d'occhio + "✏️ Modifica" → editor modale → salva. Stessa fonte di Sole su WhatsApp (tabella
  `note_staff`, si legge l'ultima per id, ogni salvataggio è una riga nuova con `settimana` ISO), quindi
  resta allineata col briefing del mattino. Nuovo endpoint `ardy-nota-settimanale-api.php` (GET = ultima,
  POST `{testo}` = salva), aggiunto al `<FilesMatch>` del `.htaccess`. Niente migrazione DB (tabella già
  esistente). ⚠️ Da verificare dal vivo: aprire la dashboard senza selezionare un cliente → la nota appare;
  modificarla e salvare → ricompare aggiornata e Sole su WhatsApp legge la stessa versione.
  **Accesso da ovunque (25/06):** aggiunto pulsante **"🗒️ DA FARE"** nella barra laterale (accanto a GUIDA/⚙︎)
  che apre l'editor della nota anche con una scheda cliente aperta (prima viveva solo nella home/empty state e
  per rivederla serviva ricaricare la pagina). Ricarica la versione più recente prima di aprire (riprende anche
  le modifiche fatte da Sole/Andrea su WhatsApp). Aggiunto anche un cache-buster `?v=` al link del CSS.
  ⚠️ Per Andrea la nota condivisa funziona solo se il suo numero è in `WA_ANDREA_NUMBER` (`ardy-config.php`).
- **Estrarre JS inline (~3.400 righe) dalla dashboard** in `ardy-michela-app.js` (CSS già esterno): win di caching, refactor delicato.

### ⚡ Reel async (`ardy-crea-reel.php`) — priorità media
Oggi monta il video in **sincrono** nella richiesta HTTP (worker FPM fino a 10 min). Non urgente (solo Michela,
no concorrenza); diventa prioritario con più utenti o se compaiono 504. Refactor: job in background
(`proc_open` detached) + polling. Quick win: (1) eliminare i 2 download ridondanti (righe ~206-217),
(2) download paralleli `curl_multi`, (3) caption Claude fuori dal path critico.

---

## ❄️ CONGELATI / PARCHEGGIATI (non ora)
- **Catalogo prezzi su Google Sheet / vendita**: ~~niente WooCommerce → la vendita andrà su un **agente dedicato a parte**, non Sole.~~ **RIBALTATO (09/07/2026):** WooCommerce **sì** (`object.ardy-lab.it`) e la chat prodotto **è Sole**. Fatto — vedi la sezione in cima e `PIANO-ECOMMERCE-OBJECT-WOO.md`.
  - ⮑ **Scongelato/evoluto in `PIANO-DASH-DESIGN.md`** (25/06): dash separata per i progetti interni di design
    (prototipi/lampade/mobili/restyling) → fasi → contenuti → vendita. Deciso: stesso codebase + dati separati
    (`progetti` + `fasi.progetto_id`), la dash NON vende (master contenuti), Woo master commercio via push a
    senso unico, un canale per volta. Tappa 1 (progetti + dash + fasi) è autoconsistente.
  - ⮑ **Filiera mappata (25/06)**: stampa 3D = prototipo+produzione (no on-demand); prodotto replicabile
    a serie, stock fuori dalla dash (Woo/Etsy); ciclo di vita finisce a A CATALOGO (no VENDUTO); prototipo
    tracciato v1/v2/v3 con iterazioni promuovibili a contenuto; "file congelato" = transizione manuale con
    snapshot STL+profilo+scheda; render/CAD/schede a livello progetto. Tutto in `PIANO-DASH-DESIGN.md` §3.
  - ⮑ **Materiali/costi (25/06)**: `materiali` testo pubblico + BOM `progetto_materiali` interna (filamento/
    stampa/elettrico/ferramenta/finitura/imballo/manodopera); costi filamento/ore digitati da OrcaSlicer (no
    integrazione Moonraker, stampante in LAN); manodopera €50/h default in config; scarto stampa 10% default;
    margine = prezzo − costo. Campi DB approvati. In coda (dopo Tappa 1): serve Woo al lancio?, CE/sicurezza
    lampade, tariffa oraria macchina. Vedi `PIANO-DASH-DESIGN.md` §2.5.
  - ⮑ **Tappa 1 AVVIATA (25/06, su branch)**: fondazione pronta — DDL (`progetti`, `progetto_materiali`,
    `progetto_iterazioni`, `fasi.progetto_id`) in `ardy-migrate.php`; `ardy-progetti-api.php` (CRUD + BOM/
    costi/margine + iterazioni + stato + congela-file); `ardy-design-app.html` (dash gemella theming-ready,
    pipeline stato, costi live, iterazioni). Endpoint nel `.htaccess`. ⏭️ **Resta**: wiring reel/social/WP
    sulle fasi di progetto (estendere `ardy-crea-reel.php`/`ardy-pubblica-social.php` ad accettare
    `progetto_id`, la colonna c'è già). ⚠️ Da testare dal vivo dopo il deploy (migrate crea le tabelle).
  - ⮑ **Fasi-contenuto progetto (25/06)**: `ardy-progetti-fasi-api.php` (CRUD fasi via progetto_id +
    upload/serve foto, riusa gli helper immagine) + editor completo nella dash. Foto su disco
    (`ARDY_UPLOAD_DIR/progetti/<id>/fasi/<faseid>/`); il DB salva solo i nomi → **seam pronto per la
    migrazione su Backblaze B2** (cambiano solo path scrittura + serving).
  - ⮑ **Reel da progetto (25/06)**: `ardy-crea-reel.php` ha un ramo `progetto_id` che legge le foto da
    disco (decisione A; foto progetto dietro Basic Auth, non scaricabili via HTTP). Ramo cliente invariato
    (helper `reelLeggiFoto`). Pulsante "Crea reel" nella dash design. ⏭️ Resta: clone publish WP/social
    sulle fasi di progetto (estendere `ardy-pubblica-lavorazione`/`ardy-pubblica-social` con `progetto_id`,
    SENZA guscio cliente — è contenuto di brand). Invio a catalogo a fine ciclo (Tappa 2/3).
- **Conoscenza Sole — FASE 2**: datazione fotografica guidata + community + eventuale mini-RAG se la knowledge base cresce troppo.
- **BIMI (logo avatar mittente Gmail)**: serve DMARC enforcement + VMC (certificato ~1.000+€/anno + marchio registrato). Decisione commerciale.
- **Codice d'accesso su WhatsApp**: su WA il numero = identità; il codice serve solo per numeri non registrati (raro). Servirebbe marker `[[CERCA:ARD-XXXX]]`.

---

## 📄 FUORI REPO / OPERATIVO
- **Import preventivi storici**: strumento pronto (`ardy-import-preventivi.php`, CSV + PDF). Michela mette i PDF in Drive → CSV precompilato → import.
- **Documenti legali**: `termini-privacy-wordpress.md` + `GUIDA-UTENTE.md` in revisione legale. Aggiornare le date alla pubblicazione effettiva.
- **Riorganizzare i `.php` in sottocartelle**: deciso NO (path file = URL pubblico; spostarli romperebbe n8n/webhook/require/deploy). Root piatta di proposito.

---

## 💶 Nota costi (riferimento)
- **Costo dominante = API Claude per messaggio**, mitigato col **prompt caching** (web ✅, lavorazione ✅, WhatsApp ✅).
- **Outreach AI**: arricchimento ~$0,05-0,10 a contatto (Google ~$0,03 + Claude web search); generatore template ~1 chiamata. Tetto Google giornaliero come rete di sicurezza.
- **Meta**: Michela↔Sole user-initiated = gratis; costi solo su template business→cliente fuori 24h (~3-4 cent/msg). Media Meta scadono → scaricarli subito col media ID.
