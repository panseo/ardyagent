# Ardy Lab — Task aperti & note utili

> Solo task **aperti** + note operative + verifiche residue. Tutto ciò che è fatto **e deployato**
> è rimosso (lo storico resta nei commit git). Ultima pulizia: 24/06/2026.

> ⚠️ Promemoria sempre valido: se Sole tace su **tutti** i canali insieme (WhatsApp + webchat),
> sospetta **credito Anthropic esaurito** (capitato il 21/06; si ricarica da Plans & Billing).

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
2. **Campagne con obiettivi diversi + Sole espone il piano** — es. B&B "Ardy Experience": Sole deve saper
   **esporre il piano marketing del progetto** a tutti i registrati sul CRM, sia su **WhatsApp** sia su una
   **pagina dedicata** (webchat + **codice di verifica**, come il `codice_accesso` cliente esistente).
3. **Prompt dedicato per campagna** — ogni campagna ha il suo prompt per Sole (contesto/obiettivo), così Sole
   risponde in linea con quell'iniziativa.
4. **Codice etico AI — FATTO (in codice, da deployare).** Riga "Come usiamo l'AI" (non aggressione, tutela
   privacy/sicurezza dati, mai uso fraudolento) ora in TUTTE le email (`ardy_email_codice_etico()` in
   `ardy-email.php` → footer cliente, grazie-consegna, solleciti, outreach `brevoSend`), nelle lettere cartacee
   e anteprime (`ardy-outreach.html`), e nei system prompt di Sole (`ardy-system.txt` → web+WhatsApp,
   `ardy-proxy-lavorazione.php` → chat lavorazione). ⚠️ Verificare dal vivo dopo deploy che la riga compaia in
   un'email reale e che Sole sappia esporre il codice etico se richiesto.

### 👥 Outreach — Import clienti AUTOMATICO post-Acconto
L'import **manuale** dei clienti CRM è LIVE. Resta l'**automatico**: aggiungere il cliente all'outreach
(categoria `clienti`) **dopo la fase Acconto** (firma + avvio reale), via hook nel punto del CRM dove lo
stato passa ad acconto/in lavorazione. ⚠️ Privacy/consenso: per comunicazioni di servizio ok; per marketing
serve consenso. Tenere stato/categoria distinti dai lead freddi.

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

---

## 🌐 GOOGLE BUSINESS PROFILE — post automatici delle fasi (IN ATTESA approvazione Google)
**Obiettivo**: pubblicare in automatico i post delle fasi sul profilo Google Business **Ardy di Michela
Panella**. **STATO**: form Basic API Access inviato il 17/06 (ID 3-7851000041139), in revisione ~7-10 gg lav.
Non sollecitare; attendere esito su `ardy.documenti`.
**Codice GIÀ PRONTO** (non testabile finché quota=0): `ardy-gbp.php`, `ardy-gbp-post.php`,
`ardy-gbp-check.php`, guida `ardy-gbp-post.md`. Scope `business.manage` già aggiunto, token rigenerato.
**Ad approvazione**: `ardy-gbp-check.php` verde → riabilitare il toggle Google in dashboard → POST a
`ardy-gbp-post.php` → pubblicare una fase di test e verificare sulla scheda Google.

---

## 📋 TASK DA SVILUPPARE (aperti)

### 🪑 Nuovo stato cliente "RITIRATI" — FATTO (in codice, da deployare)
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
- **Popup date all'attivazione IN_LAVORAZIONE** — ✅ FATTO (in codice, da deployare). Al click su
  IN_LAVORAZIONE, se inizio/fine lavoro sono vuote, si apre un modale che le chiede (inizio precompilato a
  oggi); "IMPOSTA DATE" le copia nei campi "Periodo del lavoro" e marca dirty (salvi tu, anti-clobber attivo),
  "SALTA" chiude senza scrivere. Serve a far scattare gli avvisi di scadenza che dipendono da quelle date.
- **Filtro sidebar default su ACCONTO/IN_LAVORAZIONE** invece di TUTTI (da decidere sull'uso reale).
- **Briefing del mattino** (opzionale): salvare data ultimo briefing per numero così il riepilogo lungo parte
  da solo al primo "buongiorno" (oggi parte quando Michela chiede "come va oggi?").
- **Widget WordPress**: il pulsante flottante dice ancora "Chatta con **Ardy**" (aria-label già "Sole") → uniformare a "Sole" nello snippet WPCode.
- **Nota settimanale "cose da fare" in dashboard** — ✅ FATTO (in codice, da deployare). Pannello
  nella home (empty state, quando nessun cliente è selezionato): mostra la nota più recente a colpo
  d'occhio + "✏️ Modifica" → editor modale → salva. Stessa fonte di Sole su WhatsApp (tabella
  `note_staff`, si legge l'ultima per id, ogni salvataggio è una riga nuova con `settimana` ISO), quindi
  resta allineata col briefing del mattino. Nuovo endpoint `ardy-nota-settimanale-api.php` (GET = ultima,
  POST `{testo}` = salva), aggiunto al `<FilesMatch>` del `.htaccess`. Niente migrazione DB (tabella già
  esistente). ⚠️ Da verificare dal vivo: aprire la dashboard senza selezionare un cliente → la nota appare;
  modificarla e salvare → ricompare aggiornata e Sole su WhatsApp legge la stessa versione. ⚠️ La home/empty
  state si rivede solo a refresh pagina (l'app non ha un bottone "home"); ampliarlo è un eventuale follow-up.
  ⚠️ Per Andrea la nota condivisa funziona solo se il suo numero è in `WA_ANDREA_NUMBER` (`ardy-config.php`).
- **Estrarre JS inline (~3.400 righe) dalla dashboard** in `ardy-michela-app.js` (CSS già esterno): win di caching, refactor delicato.

### ⚡ Reel async (`ardy-crea-reel.php`) — priorità media
Oggi monta il video in **sincrono** nella richiesta HTTP (worker FPM fino a 10 min). Non urgente (solo Michela,
no concorrenza); diventa prioritario con più utenti o se compaiono 504. Refactor: job in background
(`proc_open` detached) + polling. Quick win: (1) eliminare i 2 download ridondanti (righe ~206-217),
(2) download paralleli `curl_multi`, (3) caption Claude fuori dal path critico.

---

## ❄️ CONGELATI / PARCHEGGIATI (non ora)
- **Catalogo prezzi su Google Sheet / vendita**: niente WooCommerce → la vendita andrà su un **agente dedicato a parte**, non Sole.
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
