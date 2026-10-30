Il conto che fa fede per la fattura resta la **console Google**.

---

## 🪑 Ardy Express — prezzi da validare (deployato e collaudato 28/07)

Webchat dedicata `ardy-lab.it/ardy-express` (`ardy-chat-express.js`), stima provvisoria a numero di
pezzi (fino a 10), lead salvato col tool `salva_lead_crm` esistente. Ha sostituito la vecchia tariffa
"250€/giorno" in `ardy-system.txt` (griglia valida su tutti i canali).

- [ ] **Prezzi ancora da validare con Michela**: la griglia (150→400€ per 1→10 pezzi) usa scala a
tappe partendo dai due soli punti dati da lei (1 pezzo=150€, 10 pezzi=400€); i valori intermedi
(2-9 pezzi) sono stima ragionevole ma NON confermata.

---

## 📸 Foto fasi — code aperte dal fix "foto Android" (19/07)

Fix deployato (normalizzazione lato client canvas→JPEG ~1600px in `fotoNormalizza`,
`ardy-michela-app.html`). Restano due code:

- [ ] **Modifica/ri-pubblica di una fase GIÀ pubblicata.** Oggi "FASI PUBBLICATE" è sola lettura by-design;
il flusso lavorazione **appende** senza marcatore per-fase → ri-pubblicare duplicherebbe. Intervento:
portare lo schema idempotente delle fasi-progetto (`ardy-pubblica-fase-progetto.php`: marcatori
`<!-- ardy-fase-ID -->` + `wp_pubblicata_at` azzerato) + azione "modifica/ri-pubblica" in dash.
Vera modifica al codice: richiede tempo + test. **Workaround intanto:** pubblicare una nuova fase con la foto.
- [ ] **Diagnosi log foto 17/07** (opzionale): `error_log` per `ARDY PUBBLICA IMG: ricevute=… salvate_su_wp=…`
attorno al 17/07, per confermare perché quella foto fu scartata (HEIC? > 12 MB?).

---

## 🛒 Ecommerce `object.ardy-lab.it` (Woo) + chat Sole per-oggetto

Piano in `PIANO-ECOMMERCE-OBJECT-WOO.md`. Fasi 0-2 mergiate in main (PR #45-#51): campi scheda-Sole su
`progetti`, `ardy-object-scheda.php`, chat per-oggetto (`ardy-object-proxy.php` + `ardy-object-system.txt`
+ `ardy-object-chat.js` + mu-plugin WP), push dash→Woo (`ardy-object-push.php`), foto vendita separate
(`progetto_foto_vendita`, `ardy-object-foto-api.php`, `ardy-object-img.php`), dash design a card collassabili.

**Da fare / verificare:**

- [ ] **Verifica a video** della dash a card collassabili (grossa modifica UI, non testata live).
- [ ] Test push **foto vendita** su un pezzo nuovo (auto-fill immagini su Woo).
- [ ] **B2 letture rotte (Opzione A)**: il push foto usa immagini **locali** apposta. Fix radicale del
GET B2 (SigV4) è un intervento a parte — impatta anche dash principale e articoli design.

---

## 🎨 Seguiti audit usabilità dash (05/07) — due code

Audit + interventi P1/P2/P3 fatti e deployati (vedi `AUDIT-USABILITA-DASH.md`). Restano:

- [ ] **Test-utente di validazione** — 15 min guardando Michela sui 3 compiti tipici
(`TEST-UTENTE-DASH.md`). Da qui esce la prossima lista di fix, ordinata dai fatti.
- [ ] **Theming — Fase 0 residua** (estetica, non usabilità): migrare i restanti `style=""` non-bottone
a classi/token + token spaziatura/ombra/raggi, per la dash "drop-in" con un `theme.css`
(vedi `ANALISI-CLAUDE-DESIGN.md`).

---

## 🔎 Arricchimento outreach: email non trovata su sito JS

Caso reale: B&B `giubbonarisuites-adm.com/contatti` (gruppo ADM Hospitality). Il sito viene trovato
(via Google Places) ma l'email no, né Haiku né Sonnet, anche dopo il fix "passo 1c". A occhio sulla
pagina ci sono 2 email: `Info@adm-hospitality.com` e `giubbonari @adm-hospitality.com` (spazio prima della @).

**Ipotesi da verificare (in ordine):**

1. **Sito JS-rendered**: `ardySafeHttpGet` scarica l'HTML grezzo; email iniettate via JS non compaiono
 nel sorgente. *Verifica:* logga status code + se `@adm-hospitality` è nell'HTML grezzo di `/contatti`.
2. **Email offuscate con spazio**: la regex non le cattura. `Info@adm-...` dovrebbe essere catturata
 **se** è nell'HTML grezzo (vedi punto 1).
3. **Dominio diverso / redirect / 403**: verificare quale URL ha proposto Places e cosa risponde.

**Interventi possibili:** log diagnostico del fetch; gestione offuscamento "spazio @"; fallback rendering
headless on-demand. File: `ardy-enrich.php` (`ardyEnrichScrapeSite`, `ardyEnrichExtractEmail`),
`ardy-net.php` (`ardySafeHttpGet`). Selettore modello (Haiku/Sonnet) in dash già fatto; Haiku è il default.

---

## 🗺️ STANDBY — Ricerca OSM: timeout Overpass su raggi ampi

Con raggio 1 km funziona; su raggi ampi Overpass va in timeout. **Google funziona bene.** Fix deployato
(`ardy-outreach-api.php`): riconosce il `remark` di timeout, ritenta sul mirror `overpass.kumi.systems`,
invita a ridurre il raggio (3-5 km).

**Da valutare alla ripresa:** query più leggera; chunking automatico dell'area; istanza dedicata/a
pagamento; verificare raggiungibilità del mirror dal host (vedi `ANALISI-FIREWALL-HOST.md`).

---

## 🧩 Tipi progetto dash design — due decisioni aperte

Tipo `sistema` aggiunto (26/07, deployato — toccati: `ardy-progetti-api.php` `PROGETTO_TIPI`,
`ardy-design-app.html` `TIPI`, `ardy-guida-design.html`, `PIANO-DASH-DESIGN.md`; nessuna migrazione,
`progetti.tipo` è VARCHAR). Restano aperte:

- [ ] **Il set dei tipi è misto e va ripulito.** `restyling` e `prototipo` duplicano campi esistenti
(Metodo / Stato): stesso fatto scritto in due posti che possono contraddirsi, e confonde l'AI che
legge entrambi. Toglierli è una **migrazione dati** sui progetti esistenti → serve decisione di Michela.
- [ ] **Metodo di Ardy Tower.** Oggi `Stampa 3D` (accende i moduli 3D), ma il progetto è misto
(stampa + idraulica + elettronica/IoT) e i moduli impiantistici non esistono. Da capire se servono
o se basta documentare nei documenti di riferimento.

---

## ☁️ Archivio B2 di fine ciclo — collaudo dal vivo mancante

Decisione (26/07): B2 sta *dopo* il ciclo — progetto CATALOGATO / cliente CONSEGNATO → bottone 📦
deposita copia della documentazione su B2 (mai sposta) + rilettura del manifest per verifica.

- [ ] **Collaudo dal vivo** su un progetto **fake** (crea → CATALOGATO → archivia → controlla
«rilettura di verifica riuscita ✅» → cestina). NON su Ardy Tower (prototipo reale in corso).
- [ ] **Da decidere dopo il collaudo**: se/come liberare il disco su ciò che è archiviato e verificato.
- ⚠️ **Non coperte dal rimpatrio B2**: le foto delle fasi-racconto passate da «libera spazio disco»
(senza colonna `storage`) — meno critiche, stanno già sull'articolo WP, ma considerarle prima di
svuotare il bucket.
- Le costanti `ARDY_B2_*` restano nel config (servono all'archiviazione). Il backup off-site cPanel→B2
è un'altra cosa e non si tocca.

---

## ✉️ Storico email — manca la cattura delle RISPOSTE del cliente

Deployato (PR #116, 15/08): bottone **✉️ Invia email**, ogni email inviata in `email_log` e nel Dossier.
**Resta aperto:** le risposte del cliente. Deciso: via **IMAP** su `ardy.documenti@gmail.com` (già
Reply-To). Piano pronto, tolto dal merge in attesa delle credenziali:

1. 2FA sul Gmail + **password per app** (myaccount.google.com/apppasswords).
2. In `ardy-config.php`: `ARDY_IMAP_USER`, `ARDY_IMAP_PASSWORD`.
3. Ricreare `ardy-email-inbox-sync.php` (job CLI/cron, schema di `ardy-chiusura-sessioni.php`): legge
 gli UNSEEN, aggancia il mittente via `clienti.email`, inserisce in `email_log` con
 `direzione='entrata'`. Richiede estensione PHP `imap` (cPanel → Select PHP Version).
4. In `ardy-migrate.php`: colonna `email_log.message_id` (UNIQUE, NULL ammessi).
5. Cron ogni 5 minuti.

Bloccato solo sul punto 1 (credenziali Gmail).

---

## ☁️ Media su Backblaze B2 — off-load disco (PIANIFICATO, semilavorato)

Obiettivo: togliere i media dal disco (oggi si intasa) + pre-staggiarli su B2 per la migrazione VPS
(`PIANO-MIGRAZIONE.md` Fase 3/5). Scope v1 = foto private (scheda+chat) + video/reel; fuori: foto già
su WP Media e cache PDF. Bucket `ardy-media` PRIVATO + app key ristretta; `ardy-b2.php` (API nativa curl)
+ layer `ardy-storage.php` (put/get/delete/exists); immagini restano private (serve-script = proxy dietro
Basic Auth); backfill one-shot + write path B2 + read path con fallback disco + cron sync + flip finale.

**Note:** coordinare con `ardy-archivia-persi.php` e `ardy-elimina-cliente.php` (dovranno agire anche su
B2). ⚠️ Sul nuovo VPS no-panel il backup B2 va comunque rifatto in chiave no-cPanel — questo task copre
il lato media.

---

## 🚚 Trasporti — aggiungere il WhatsApp ai 2 messaggi (oggi solo email)

Flusso LIVE solo via email ("è pronto" a COMPLETATO + messaggio data dalla giornata Trasporti).
Servono **2 template Meta approvati** (`WA_TEMPLATE_PRONTO` 1 var; `WA_TEMPLATE_TRASPORTO` 2 var:
mobile + data) + invio WA in `ardy_invia_pronto()` e `ardy_invia_avviso_trasporto()` + costanti in
`ardy-config.php`. (media)

## 📲 Template WhatsApp avanzamento fase — aggiungere codice/social (lato Meta)

`WA_TEMPLATE_FASI` a 4 var: codice personale/social non aggiungibili da PHP. Serve modificare il
template su Meta, farlo ri-approvare, poi allineare i parametri in `inviaWhatsAppCliente()`
(`ardy-pubblica-lavorazione.php`). (bassa)

---

## 👥 Accesso "dipendente" con permessi limitati (ruoli)

Quando Ardy avrà un dipendente. Lo `staff`: SOLO preventivi + schede cliente (+ fasi collegate);
resto admin-only. `ardyAuthUser()` restituisce già lo username → manca lo strato ruoli. 3 mosse:
1. Mappa utente→ruolo in `ardy-config.php` (`ARDY_RUOLI`).
2. **Muro backend**: `ardyRole()` + `ardyRequireRole('admin')` in cima a OGNI endpoint admin-only.
 Il confine vero è il check PHP su ogni endpoint, non l'.htaccess. Se ne manca uno, è un buco.
3. Cosmesi frontend: endpoint `/me` col ruolo → la dash nasconde i bottoni admin per lo `staff`.
Nuovo utente: `htpasswd -B <path> dipendente` (mai `-c`).

## 🎨 Adottare temi/layout da "Claude Design" — ANALISI PRONTA (da decidere)

Blocco vero: **363 `style="…"` inline** che scavalcano i temi. Procedura: Fase 0 (inline→classi +
ampliare i token, su branch) → un solo `theme.css` drop-in, provato su staging. Fattibilità: dashboard
**alta**, webchat **media**, WordPress/Divi **bassa**.

## Migliorie minori UX / dashboard CRM (bassa priorità)

- [ ] **Filtro sidebar default su ACCONTO/IN_LAVORAZIONE** invece di TUTTI (da decidere sull'uso reale).
- [ ] **Briefing del mattino** (opzionale): salvare data ultimo briefing per numero, così il riepilogo
lungo parte da solo al primo "buongiorno".
- [ ] **Widget WordPress**: lo snippet repo è SOLO un backup — per renderlo live va re-incollato nel
WPCode id 15243 ("Pulsante flottante ovunque") da WordPress.
- [ ] **Nota settimanale in dash** (deployata 24/06, test live pendente): aprire la dash senza cliente
selezionato → la nota appare; modificarla e salvare → ricompare aggiornata e Sole su WhatsApp legge
la stessa versione. ⚠️ Per Andrea funziona solo se il suo numero è in `WA_ANDREA_NUMBER` (`ardy-config.php`).
- [ ] **Estrarre JS inline (~3.400 righe) dalla dashboard** in `ardy-michela-app.js` (CSS già esterno):
win di caching, refactor delicato.

## ⚡ Reel async (`ardy-crea-reel.php`) — priorità media

Oggi monta il video in sincrono (worker FPM fino a 10 min). Non urgente (solo Michela, no concorrenza);
diventa prioritario con più utenti o se compaiono 504. Refactor: job in background (`proc_open` detached)
+ polling. Quick win: (1) eliminare i 2 download ridondanti (righe ~206-217), (2) download paralleli
`curl_multi`, (3) caption Claude fuori dal path critico.

---

## ⏳ DA VERIFICARE DAL VIVO / AZIONI MANUALI

- **🛋️ Consulenza Interior Design (25/07, DEPLOYATO, test sul campo NON ancora fatto).** Webchat
dedicata su `ardy-lab.it/interior-design` + sezione 🛋️ in scheda. Intervista guidata (permesso →
cinque domande una alla volta → invito foto → dati anagrafici solo alla fine) → **💬 Riepilogo di
Sole** in scheda. **Test (due giri, meglio dal telefono):**
1. *Intervista completa*: fino ai contatti (con foto via **📷 Fai la foto al tuo ambiente**). In dash
   verificare: (a) servizio = "Consulenza Interior Design", (b) sezione 🛋️ già accesa, (c) riepilogo
   sensato, (d) foto dentro la sezione (🖼️ Immagini di riferimento).
2. *Intervista saltata*: dati subito senza rispondere. L'accensione non dipende dal tool ma dal campo
   `origine` (`ardy-proxy.php`). Nel riepilogo Sole deve **dire cosa manca**, non inventarlo.
- ⚠️ **Continuare a chattare DOPO le foto** (era il punto che si rompeva, "Errore nella risposta AI") —
  risolto ridimensionando nel browser, da riconfermare dal vivo.
- Tono/ritmo domande si tara in `ardy-system.txt` (sezione "CONSULENZA INTERIOR DESIGN"), solo deploy.
- Chi abbandona a metà **non lascia scheda** (senza telefono non si crea) — voluto.
- **Azione WordPress (non coperta dal deploy):** [ ] contenuto pagina `/interior-design`: la card
  *"1 · Racconti a Sole"* riscritta va riportata a mano se non fatto (testo in
  `wordpress-snippets/interior-design-page.html`). Solo cosmetico.
- **📷 "Usa foto della scheda" nelle fasi (19/07, DEPLOYATO, da testare):** creare una fase con una foto
della scheda → verificare che compaia nella **pagina pubblicata**. File: `ardy-michela-app.html`
(`toggleFotoSchedaPicker`/`aggiungiFotoSchedaSelezionate`).
- **✦ Avvio pagina lavorazione dal box "Periodo del lavoro" (04/07, DEPLOYATO, mai provato):** cliente
su IN_LAVORAZIONE → foto nel box → **PUBBLICA AVVIO LAVORO + NOTIFICA CLIENTE** → verificare (a) post
sul sito con foto e testo Claude, (b) email + WhatsApp al cliente, (c) il box sparisce e la fase
"Avvio lavorazione" compare in "📋 Fasi pubblicate", (d) le fasi successive **aggiornano la stessa
pagina**. File: `ardy-michela-app.html` (`pubblicaAvvioLavoro`/`handleAvvioFotoUpload`/`aggiornaVisibilitaAvvio`).
- **Rimosse "Fasi previste dal sopralluogo" + badge "📐 da pianificare" (04/07, DEPLOYATO):** verifica
veloce — scheda in SOPRALLUOGO/ACCONTO con nota → né pannello template nel box Note né badge in sidebar.
- **Date sopralluogo/consegna in dashboard — test completi:**
- *Lista sopralluoghi (Fase 1)*: aggiungere/spostare/eliminare visite → eventi Google Calendar coerenti
  (niente doppioni); seconda visita coesiste; sopralluogo fissato da Sole su WhatsApp compare in lista
  (riconciliazione "pigra" alla riapertura scheda).
- *Via Sole su WhatsApp (Fase 2)* — solo PHP, NIENTE re-paste n8n: "aggiungi un 2° sopralluogo per
  Alberto giovedì alle 15" (deve AGGIUNGERE); "che sopralluoghi ha Alberto?" (elenca); "sposta il
  sopralluogo di Alberto" con 2 visite → CHIEDE quale. ⚠️ Il cliente (canale cliente) resta a UN
  sopralluogo (mirror); ampliare = eventuale follow-up.
- **Test Piano B — spostamento appuntamento su WhatsApp** (numero NON staff con sopralluogo fissato):
(a) l'evento si SPOSTA (non duplica), (b) Michela riceve "SPOSTATO" (WA + email), (c) conferma al cliente.
- **Recuperare le date perse pre-fix** (es. Margherita Mottini): reinserire a mano.
- **UX "Modifica" su Preventivo (allegato)**: l'estrazione "🔍 Leggi dati dal PDF" è solo al primo
allegato. Da decidere se serve ri-estrarre/correggere in modifica senza doppioni di fasi.
- **Template `aggiornamento_fase`** (4 var) e **`sollecito_pagamento`**: provare con caso reale/fittizio.
⚠️ Confermare che il body del template Meta abbia **esattamente 4 variabili** (altrimenti Meta rifiuta,
err 132000/132018).
- **Conoscenza di bottega di Sole** (`ardy-conoscenza-restauro.txt`): è una **v1** → Michela la rivede
e la "ardy-izza" con le sue tecniche/parole.
- **Export WPCode** (Tools → Export All → JSON): rinfrescare il backup `wordpress-snippets/` + mappa.
- **Briefing del mattino con la nota settimanale (FIX deployato, da verificare):** al "buongiorno" Sole
deve includere da sola il blocco "🗒️ COSE DA FARE" (nota più recente). Test: salutare al mattino con
una nota salvata → deve elencarla.
- **💬 Risposte WhatsApp alle notifiche → Conversazioni in dash (DEPLOYATO 04/07, PR #34, mai provato).**
PREREQUISITO: `ardy-migrate.php` ha creato `wa_messaggi.wa_msg_id` + indice `uq_wa_msg_id`.
**Test:** da un numero cliente in CRM, scrivere a Sole (+39 379 375 6437) → scheda cliente → 💬
Conversazioni → il messaggio compare (e la riga 📢 in uscita se c'era una notifica prima). Se non
compare: cercare `ARDY WA STORE` / `ARDY WEBHOOK persist` nell'`error_log` (di solito = migrazione non
eseguita); controllare `ardy-wa-log.json`.

---

## 💾 BACKUP OFF-SITE Backblaze B2 — fatto 24/06, restano 3 pending

cPanel→B2 su entrambi i VPS gemelli (Server 1 → bucket `UGLmico`/prefisso `srv1`; Server 2 → bucket
`micoper`, chiave ristretta). Lifecycle 30 giorni + SSE-B2. n8n (Server 2): rclone via
`/root/bin/n8n-backup.sh` in cron alle 04:00 → `micoper/n8n/`. Nessun impatto sull'app Ardy.

**Pending (non bloccante):**
1. [ ] Verificare che i primi backup completi abbiano popolato `UGLmico/srv1/` e `micoper/srv2/`.
2. [ ] **Cleanup sicurezza**: ruotare la app key del Server 1 (ora "All buckets") in una **ristretta
 a `UGLmico`**.
3. [ ] Fare almeno una **prova di restore** reale (account cPanel di test + volume n8n).

---

## 🌐 GOOGLE BUSINESS PROFILE — chiuso (26/07); note per il futuro

Post delle fasi sul profilo "Ardy di Michela Panella" funzionanti da entrambe le dash. Il 403 storico
era **egress IPv6** (fix: `CURL_IPRESOLVE_V4` in `ardy-gbp.php`); i 500 successivi erano **foto WEBP**
(Google vuole JPG/PNG, min 250×250, < 5 MB — come Instagram). ⚠️ **Falsa pista, non riaprirla:**
`metadata.canOperateLocalPost = false` sulla scheda NON blocca i post. Token GBP dedicato:
`ardy-gbp-auth.php` (scope solo `business.manage`, `prompt=select_account consent`) → `ardy-gbp-token.json`.
Diagnostica futura: `ardy-gbp-check.php` (sezione «Post locali»: elenca immagini, pubblica post di prova —
da cancellare a mano).

---

## 🔧 NOTE OPERATIVE (servono sempre)

**⚠️ Sole non risponde su WhatsApp ma la webchat sì → è n8n giù.** Il ramo WhatsApp è
Meta → n8n → `ardy-wa-lookup.php` → Claude; la webchat (`ardy-proxy.php`) NON passa per n8n.
Check rapido: `https://n8n.ardy-lab.it` (503/523 = giù). Sul server (root):
