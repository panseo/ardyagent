# Ardy Lab — Task aperti & note utili

> TODO ripulito: solo i task ancora **aperti** + note operative + verifiche/azioni residue.
> Tutto ciò che è fatto **e deployato** è rimosso (lo storico resta nei commit git).

---

## ✅ ~~PRIORITÀ ALTA — SICUREZZA~~ FATTO (19/06/2026)

- ✅ **Chiavi sensibili ruotate** (19/06): Token Meta/WhatsApp, API key Anthropic e
  `WA_LOOKUP_SECRET` rigenerati e aggiornati in `ardy-config.php` + nodo n8n. Verificato
  dal vivo su WhatsApp — Sole risponde correttamente con tutte e tre le nuove chiavi.
  - **Hardening futuro** → vedi task dedicato sotto (priorità media).

---

## ✅ AUDIT DI SICUREZZA COMPLETO — FATTO (20/06/2026)

Audit dell'intero progetto in due giri + verifica live in produzione. Dettagli completi in
**`SECURITY-AUDIT.md`**. Sintesi:

- ✅ **CRITICO — gap Basic Auth chiuso**: 8 endpoint sensibili dichiaravano (nei commenti) di
  essere protetti dal `.htaccess` ma **non erano nel blocco `FilesMatch`** → pubblici. Aggiunti
  tutti: `elimina-cliente` (hard-delete!), `conversazioni` (PII), `email-cliente-api`, `crea-faq`,
  `fasi-bozza-api`, `allega-preventivo`, `estrai-preventivo-pdf`, `archivia-persi`. Verificato 401
  in produzione. (Sono chiamati solo via fetch dalla dashboard → coerente con la nota auth sotto.)
- ✅ **MEDIO — guard "fail-closed"**: `wa-agent`, `chiusura-sessioni`, `lead-monitor`,
  `notifica-michela` non saltano più il controllo se `WA_LOOKUP_SECRET` manca (prima fail-open).
- ✅ **MEDIO — stored XSS dashboard**: nome cliente in contesto stringa-JS nei pulsanti del Cestino
  → nuova helper `escJs()`.
- ✅ **BASSO — `WA_VERIFY_TOKEN`**: rimosso il fallback hardcoded pubblico; ora da config, confronto
  `hash_equals`. **Token ruotato** (config server + Meta) il 20/06.
- ✅ **BASSO — setup-login**: campo password `text` → `password`.
- ✅ Verificate senza rilievi: SQLi (prepared ovunque), SSRF (`ardy-net.php`), upload (finfo +
  hardening), command injection (escapeshellarg), JS frontend, snippet WordPress, segreti n8n
  (placeholder), rate-limit chat, CORS, OAuth state.

---

## ▶️ STATO (17/06/2026)
CRM in attività piena, **multi-utente** (Michela + Andrea). Focus 17/06: rendere Sole
**completa su WhatsApp** (canale obbligato) verso clienti e staff.
- ✅ **Piano B — tool veri su WhatsApp lato cliente** LIVE (17/06): nuovo cervello PHP
  `ardy-wa-agent.php` con loop agentico (come il sito). Sole sui clienti WhatsApp ora LEGGE la
  disponibilità del calendario, FISSA il sopralluogo su conferma (guardia anti-doppione +
  persistenza scheda + notifica Michela) e SALVA la scheda lead (`ardy-wa-crea-scheda.php`,
  con numero WhatsApp in automatico se manca il telefono). n8n declassato a tubo: instrada
  `titolare`→Claude diretto (intoccato), clienti→agente. Tool ancora SOLO-web: `cerca_cliente`,
  codice accesso, `sposta_appuntamento`. Rollback = rimettere il blocco vecchio nel nodo n8n.
  Workflow versionato (segreti rimossi) in `n8n/ardy-whatsapp-workflow.json`.
- ✅ **Multi-utente Andrea** LIVE (16/06): credenziali separate `.htpasswd` + `WA_ANDREA_NUMBER` in `ardy-config.php`. Stessi permessi di Michela (dashboard + Sole su WhatsApp che lo chiama "Andrea"). Cache prompt separate per i due.
- ✅ **Root dominio** apre direttamente la dashboard (16/06): `https://ardyagent.ardy-lab.it` → dashboard (prima serviva `/ardy-michela-app.html`).
- ✅ **UX scheda mobile (sopralluogo)** (16/06): Note ingrandite + bottone **⛶ Espandi** (editor a tutto schermo), **Dati anagrafici** e **Azioni cliente** dentro toggle collassabili (chiusi di default su mobile), Session ID nascosta.
- ✅ **Monitor portali lead** in produzione (n8n ogni 60min, Gmail→Claude→WA). Portali: ProntoPro, Homedeal, Cronoshare. Instapro in attesa cambio email.
- ✅ **Cestino 30 giorni**: soft-delete con ripristino, purga automatica >30gg, modal nella dashboard.
- ✅ **Stato COMPLETATO** aggiunto tra IN_LAVORAZIONE e CONSEGNATO.
- ✅ **Restyling PDF preventivo** (16/06 sera): font **Playfair Display** per tutto il documento, colore testo **dorato scuro** (`#6b4f1e`) invece del quasi-nero, pagina **Grazie** ridisegnata (logo + "GRAZIE" + customer-care Sole) con footer link Instagram + webchat + **WhatsApp diretto** (`wa.me`) e **2 QR code** (webchat + WhatsApp), link **privacy policy + termini** sulla pagina firma. `PDF_CACHE_VER` a `2026-06-16i`.
- ✅ **Prompt WhatsApp: nudge verso webchat** (16/06 sera): sezione in `ardy-whatsapp-system.txt` che istruisce Sole a invitare con garbo il cliente sulla webchat (dopo aver dato valore, mai forzando, sempre lato-cliente).
**Interventi 17/06 (fatti + deployati):**
- ✅ **Sole staff in tempo reale**: corretto il framing "fotografia statica" → i dati sono letti
  DAL VIVO dal CRM ad ogni messaggio. Riepilogo titolare arricchito con **conversazioni 48h**
  (chi ha scritto, WA+sito), **clienti attivi con stato attuale**, **fasi con nome cliente**,
  **note consegna**. (`ardy-wa-lookup.php`)
- ✅ **Sole clienti — quadro lavorazione completo**: dà tutte le fasi **pubblicate** (non solo
  l'ultima). Bug privacy risolto: dossier per-cliente + `ultima_fase` filtrano solo le pubblicate
  (niente bozze al cliente). (`ardy-dossier.php`, `ardy-whatsapp-system.txt`)
- ✅ **Vista 💬 Conversazione** nella scheda cliente (accordion lazy-load, WA+sito unificati).
  (`ardy-conversazioni.php`, dashboard)
- ✅ **Box 📦 Note consegna** nella scheda + badge in lista + letto da Sole (riepilogo + dossier).
  Colonna `clienti.note_consegna`. Svuotando la nota il badge sparisce.
- ✅ **Indicatore 💬 "ha risposto"** in lista (testato, no anomalie): badge sui clienti che hanno
  scritto (WA/sito) nelle ultime 48h e non ancora "letti". Marker `clienti.conversazione_letta_at`
  (aggiornato all'apertura della chat). (`ardy-crm-api.php`, `ardy-conversazioni.php`, dashboard)
- ✅ **Bozze social "salva per dopo" sul server** (testato): da localStorage a DB
  (`ardy-social-bozze.php`, tabella `social_bozze`) → multi-dispositivo + multi-utente, con
  modifica/elimina/pubblica sui singoli social e migrazione una-tantum dal vecchio localStorage.
- ✅ **👁 Anteprima Instagram + 🖼 gestione foto** (testato, "funziona bene"): mockup formato IG e
  ➕/✕ foto, sia nel composer sia nelle bozze in attesa. Foto aggiunte → URL pubblico WP via
  `ardy-social-foto.php` (richiesto da IG/FB). Endpoint nuovi dietro Basic Auth nel `.htaccess`.
- ✅ **Fix date azzerate (anti-clobber)** (testato): `saveLead` non invia inizio/fine lavoro vuote →
  un salvataggio non azzera più una data già in DB.
- ✅ **Git**: `main` riallineato alla lineage attiva (root `98b352f`); vecchia lineage orfana
  (`b49606b`) da non rifondere (vedi NOTE OPERATIVE). Cleanup dei vecchi branch `claude/*` da fare a mano.

**Interventi 18/06 (fatti + deployati + testati):**
- ✅ **Ricezione foto su WhatsApp (Piano B foto)** LIVE: il cliente manda una foto del mobile → Sole
  la **scarica da Meta** (media id → URL firmato → byte, via `WA_TOKEN`), la **vede e valuta**, e la
  **salva nella cartella della scheda** → compare in dashboard + allegata all'email a Michela.
  (`ardy-whatsapp-webhook.php` estrae `media_id/mime/caption`; n8n li inoltra; `ardy-wa-agent.php`
  scarica/comprime/allega). **Fix chiave**: l'agente non includeva `ardy-net.php` → `ardyCompressImage()`
  era undefined → Fatal error a runtime (Sole diceva "vedo solo testo"/"non vedo immagini"). Risolto col
  `require_once ardy-net.php`. Testato dal vivo: foto salvata in dashboard ✅.
- ✅ **Regola identità numero su WhatsApp** (solo prompt, niente codice): su WhatsApp il numero di
  riconoscimento è SEMPRE quello con cui il cliente scrive; Sole non registra un numero diverso (per un
  altro numero/dispositivo → webchat + codice). Evita schede orfane e tiene saldo il riconoscimento
  automatico (lookup per `telefono_last9` del numero WhatsApp). (`ardy-whatsapp-system.txt`)

Prossimi task per priorità: accesso "dipendente" (permessi limitati) · programmazione data/ora post social (cron/n8n) · briefing del mattino · backlog performance/sicurezza.

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

**⚠️ firewalld DISABILITATO di proposito (19/06/2026) — non riattivarlo.** Dopo un aggiornamento
notturno (nftables salito a `el9_8`, firewalld fermo a `el9_7`) il daemon Docker non parte più con
firewalld attivo: `failed to create NAT chain DOCKER: COMMAND_FAILED: INVALID_IPV: 'ipv4' is not a
valid backend or is unavailable` (la passthrough di firewalld è rotta dal disallineamento versioni;
`dnf update firewalld` = "Nothing to do", nessuna fix nei repo). Soluzione applicata: `systemctl stop
firewalld && systemctl disable firewalld`. **Se Docker non parte dopo un reboot/aggiornamento**,
verificare che firewalld non sia tornato su (`systemctl is-enabled firewalld` deve dare `disabled`).

**✅ FIREWALL HOST = csf LIVE (19/06/2026).** Il firewall host definitivo è **`cpanel-csf` v16.20**
(fork cPanel; il vecchio ConfigServer ha chiuso 31/08/2025 → installare SOLO via `yum install
cpanel-csf`, mai il tarball). `firewalld` resta `disabled` di proposito; **csf non ne dipende**.
Config + decisioni + runbook completo in **`ANALISI-FIREWALL-HOST.md`**. Punti critici da ricordare:
- **Docker/n8n**: csf è configurato con `DOCKER=1` + `DOCKER_DEVICE/NETWORK4` sul bridge **reale**
  `br-b118407a7c22` (172.18.0.0/16) **e** `ETH_DEVICE_SKIP="br-b118407a7c22"` (l'hop docker-proxy→
  container è OUTPUT, non coperto da DOCKER=1). ⚠️ Se la rete `n8n_default` viene **ricreata**, il nome
  bridge cambia → aggiornare quei 2 valori e `csf -r`.
- **Cloudflare**: i range CF sono in `csf.allow` (solo ardy-lab.it passa da CF) → mai bannare CF.
- **Fail2ban DISABILITATO** (rimpiazzato da LFD di csf). **rpcbind** disabilitato (111 chiuso).
- Egress (`TCP_OUT/UDP_OUT`) lasciato **aperto** per ora → tightening è un follow-up (occhio alla
  porta SMTP di Brevo prima di chiudere). Follow-up: `mod_remoteip` + opzione Cerber per l'IP reale.

**Deploy sul server** (da root):
```
runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'
```
**⚠️ Schema DB = `ardy-migrate.php`** (eseguito da `deploy.sh` dopo l'rsync). È l'**unico** posto dove
si creano/alterano tabelle e colonne: niente più DDL negli endpoint (giravano ad ogni request HTTP).
Idempotente e rieseguibile (IF NOT EXISTS + try/catch su 1050/1060 + `colExists`/`indexExists`).
**Nuova tabella o colonna → aggiungerla qui**, non con `SHOW COLUMNS`/`ALTER` inline nell'endpoint.

**Log errori/eventi PHP** (debug + verifica prompt caching):
```
/home/micoperibg/logs/ardyagent_ardy-lab_it.php.error.log
# es: grep "ARDY USAGE" <file> | tail -8   → in/out/cache_read/cache_write
```

**Auth endpoint chiamati via fetch**: NON usare `ardyRequireAuth()` (in CGI/FPM l'header
`Authorization` non arriva a PHP → rifarebbe login). Affidarsi al `.htaccess` (Basic Auth).
> ⚠️ **CONSEGUENZA (causa del bug critico chiuso il 20/06):** un endpoint "protetto via .htaccess"
> lo è SOLO se il suo nome è **effettivamente elencato** nel blocco `<FilesMatch ...>` del
> `.htaccess`. Un commento "Protetto da Basic Auth" NON protegge nulla. **Ogni nuovo endpoint
> riservato va aggiunto a quella regex** (e va testato con un `curl` senza credenziali: deve dare 401).

**⚠️ Git — lineage di `main` (evita l'errore "unrelated histories")**: la storia BUONA di
`main` parte dal root-commit **`98b352f`**. Esiste anche una **vecchia lineage orfana** (root
`b49606b`, i "v2.0…") scollegata da quella attuale: NON va più rifusa. Se un comando git dà
*"refusing to merge unrelated histories"* o `git merge-base <branch> main` non trova antenati
comuni, quel branch è sulla lineage vecchia → **non fonderlo**, riparti da `origin/main`.
Regola pratica per chiudere una sessione: branchare da `origin/main` aggiornato e fast-forward
indietro su `main`. I vecchi branch `claude/*` orfani sono da cancellare (cleanup, non bloccante).

**`session_id`**: sempre sanificato (no path traversal) prima di toccare i path file.

**n8n**: due workflow — "Meta" (ramo post-foto = social FB/IG; ramo Reels) e "WhatsApp" (webhook
`ardy-whatsapp` → nodo Code che chiama `ardy-wa-lookup.php`/`ardy-wa-memoria.php` → Claude). Il nodo
WhatsApp è già **prompt-caching ready** (`system_static` con `cache_control`).

**PDF preventivo**: la cache è per content-hash (`PDF_CACHE_VER` in `ardy-preventivo.php`). Se cambi
il layout/CSS del PDF, **bumpa `PDF_CACHE_VER`** per invalidare le cache esistenti.

---

## ⏳ DA VERIFICARE DAL VIVO / AZIONI MANUALI
> Le novità del 17/06 (ha risposto · bozze social sul server · anteprima IG + foto · fix date) sono
> state **testate dal vivo, nessuna anomalia** → spostate nello STATO sopra come ✅.
- **Test Piano B — spostamento appuntamento su WhatsApp** (da fare, da numero NON staff): da un
  numero che ha già un sopralluogo fissato, chiedere a Sole di spostarlo. Verificare: (a) l'evento
  Google Calendar si SPOSTA (non ne crea uno nuovo), (b) Michela riceve la notifica "SPOSTATO"
  (WhatsApp + email a `ardy.documenti@gmail.com`), (c) al cliente arriva la conferma del nuovo
  orario. NB: deploy richiesto di `ardy-wa-agent.php` + `ardy-whatsapp-system.txt`. Il resto di
  Piano B (disponibilità · prenotazione · salva-scheda · email) è già stato testato OK il 17/06.
- **Recuperare le date perse pre-fix**: i clienti a cui le date si erano azzerate (es. Margherita
  Mottini) vanno reinseriti a mano — il fix anti-clobber evita che ricapiti ma non ripristina i dati persi.
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

## 🌐 GOOGLE BUSINESS PROFILE — post automatici delle fasi (IN ATTESA approvazione Google)
**Obiettivo**: pubblicare in automatico i post delle fasi di lavorazione sul profilo
Google Business **Ardy di Michela Panella** (già Verificata), col nodo n8n / endpoint PHP.

**STATO (17/06): domanda di accesso INVIATA, in attesa di Google.**
- Diagnosi del blocco: non era codice/scope/progetto. Era stata inviata una *quota request*
  (pannello Cloud Console, auto-respinta) invece della *domanda di accesso* (form). Idoneità OK:
  scheda verificata >1 anno, `ardy.documenti@gmail.com` = **proprietario principale**, progetto
  `ardy-lab`/**532339794075** coincide col client OAuth.
- ✅ **Form Basic API Access inviato il 17/06** da `ardy.documenti` → **ID 3-7851000041139**.
  In revisione **~7-10 giorni lavorativi**. NON sollecitare via email; attendere l'esito su `ardy.documenti`.
- I post via API funzionano ancora nel 2026 (`localPosts.create`), ma sull'host **legacy**
  `mybusiness.googleapis.com/v4` (sotto access-gate) — non tra le 4 API "moderne" già abilitate.
- Verifica sblocco: `ardy-gbp-check.php` (verde quando quota 0→300) o Console → Quotas.

**Codice GIÀ PRONTO** (non testabile finché quota=0): `ardy-gbp.php` (helper localPost + cache
account/location), `ardy-gbp-post.php` (endpoint POST), `ardy-gbp-check.php` (diagnostica),
guida `ardy-gbp-post.md`. Scope `business.manage` aggiunto in `ardy-gcal-auth.php` e token già rigenerato.

**Ad approvazione ottenuta:**
- [ ] `ardy-gbp-check.php` verde → riabilitare il toggle Google in `ardy-michela-app.html` e far
      partire la POST a `ardy-gbp-post.php` (stesso payload dei social).
- [ ] Pubblicare una fase di test e verificare il post sulla scheda Google.
- [ ] (eventuale) override `GBP_PARENT` in `ardy-config.php` se la scheda risolta non è quella giusta.

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

### 🔐 Hardening n8n — segreti in variabili d'ambiente (priorità media)
Oggi `WA_TOKEN`, `ANTHROPIC_KEY` e `WA_LOOKUP_SECRET` stanno nel codice del nodo Code → escono
in chiaro negli export del workflow (causa del task sicurezza del 17/06). Soluzione: spostarli
nelle variabili d'ambiente di n8n (`$env.NOME`) definite nel `.env` sul server. Così gli export
contengono solo `$env.WA_TOKEN` — un riferimento, mai il valore.
**Lavoro:** (1) aggiungere 3 righe al `.env` di n8n sul server via SSH; (2) sostituire le 3
costanti nel nodo Code con `$env.NOME`; (3) aggiornare il file versionato
`n8n/ardy-whatsapp-node-completo.js` e il JSON del workflow. ~15 min.

### 🔥 ~~Firewall host — sostituto di firewalld~~ ✅ FATTO/LIVE (19/06/2026)
**csf (`cpanel-csf` v16.20) installato e LIVE.** Scelta = opzione 2 (csf), motivata e documentata in
**`ANALISI-FIREWALL-HOST.md`** (decisione + foto superficie + runbook + esito + follow-up). firewalld
resta `disabled` di proposito (csf non ne dipende). Fail2ban disabilitato (LFD lo rimpiazza), rpcbind
off, range Cloudflare whitelistati, Docker/n8n risolto (DOCKER=1 + ETH_DEVICE_SKIP sul bridge reale).
Dettagli operativi nelle NOTE OPERATIVE sopra.

**Follow-up rimasti (non bloccanti):** (1) ✅ `mod_remoteip` — **già attivo e auto-gestito da cPanel**
(`cloudflare.conf`), verificato dal vivo: i domlog di ardy-lab.it mostrano l'IP reale, non Cloudflare.
Resta solo da confermare in WP-admin che **Cerber** mostri IP reali (default REMOTE_ADDR, già corretto).
(2) **egress tightening** (`TCP_OUT/UDP_OUT` oggi aperti → restringere testando le uscite di Sole,
occhio alla porta SMTP Brevo); (3) `RESTRICT_SYSLOG="3"` in csf.conf. **Server gemello**: stessa
procedura RPM (il tarball ConfigServer è morto dal 31/08/2025).


### 🧠 ~~Autoapprendimento di Sole dalle fasi di lavoro~~ ✅ FATTO (18/06 bis, da testare dal vivo)
Sole impara dai lavori veri. In dashboard, bottone **📚 CONOSCENZA** (⚙︎ Strumenti) → modale:
si selezionano le **fasi pubblicate**, **🧠 Distilla** chiama Claude che estrae conoscenza di
bottega **generica e anonimizzata** (no nomi/indirizzi/prezzi/pezzi identificabili; dati fase
delimitati come non-istruzioni = anti prompt-injection). Michela rivede/corregge la proposta e
**salva** → entra in Sole. Storage **blocco DB separato** (`conoscenza_appresa`, attiva/modifica/
elimina), distinto da `ardy-conoscenza-restauro.txt`. Iniezione nel `system_static` cacheato sia
web (`ardy-proxy.php`) sia WhatsApp lato cliente (`ardy-wa-lookup.php`). Endpoint dietro Basic Auth.
**Da testare dal vivo:** (a) seleziona 1-2 fasi → 🧠 Distilla → la proposta NON contiene dati cliente;
(b) salva → compare tra i blocchi attivi; (c) in chat (web/WA) Sole usa il linguaggio appreso senza
citare clienti; (d) disattiva/elimina un blocco → Sole se ne dimentica al messaggio successivo.
**Scelte fatte:** cosa impara = tecniche generalizzate anonime (v1, espandibile a casi-esempio anonimi);
aggiornamento = manuale on-demand con selezione fasi; storage = blocco DB separato.


### 🚚 Trasporti — aggiungere il WhatsApp ai 2 messaggi (oggi solo email)
Il flusso consegne/ritiri è LIVE **solo via email** (18/06): messaggio **"è pronto"** automatico al
passaggio a COMPLETATO + messaggio con la **data** dalla "giornata Trasporti" in dashboard
(`ardy-trasporti.php`). Manca il **WhatsApp**: come per fasi/grazie servono **2 template Meta approvati**
(es. `WA_TEMPLATE_PRONTO` 1 var, `WA_TEMPLATE_TRASPORTO` 2 var: mobile + data). Una volta approvati,
agganciare l'invio WA in `ardy_invia_pronto()` e `ardy_invia_avviso_trasporto()` (punti già predisposti)
e definire le costanti in `ardy-config.php`. (priorità media)

### 📲 Template WhatsApp avanzamento fase — aggiungere codice/social (lato Meta)
Il messaggio WhatsApp di avanzamento lavorazione usa il **template Meta `WA_TEMPLATE_FASI`** a 4
variabili (nome · mobile · fase · link): codice personale, social e spiegazione **non** si possono
aggiungere dal codice PHP. Per averli anche su WhatsApp va **modificato il template su Meta Business
Manager** (nuove variabili o testo fisso nel body/footer) e rifatto **approvare**, poi allineare i
parametri in `inviaWhatsAppCliente()` (`ardy-pubblica-lavorazione.php`). Oggi quei dati ci sono già
nell'**email** di avanzamento e nella **pagina lavorazione** linkata. (priorità bassa)

### 🎨 Adottare temi/layout da "Claude Design" — ANALISI PRONTA (da decidere)
Voglio usare i layout di Claude Design. **Analisi completa di fattibilità/rischi/procedura** in
**`ANALISI-CLAUDE-DESIGN.md`**. In sintesi: il dashboard ha già i design-token (`:root` in
`ardy-michela-app.css`) ma **363 `style="…"` inline** scavalcano qualsiasi tema → vero blocco.
Procedura snella: **Fase 0** (migrare inline→classi + ampliare i token, su branch) → poi dare a Claude
un "contratto" (token + classi + vincoli) e farsi restituire **un solo `theme.css` drop-in**, provato
su **staging** dietro Basic Auth. Fattibilità: dashboard **alta**, widget webchat **media**, sito
WordPress/Divi **bassa** (solo CSS override). Superficie pilota consigliata: **dashboard**.

### 🗂️ ~~Bozze fasi di lavorazione CON foto~~ ✅ FATTO (18/06, da testare dal vivo)
**Esigenza (Michela, 18/06):** mentre lavora apre una nuova fase, scrive due righe, allega fino a
**6 foto** della fase e **salva in bozza** — senza pubblicare né notificare. La sera, con calma,
rivede le foto, rifinisce il prompt/note e **pubblica** (testo AI + pagina + notifica cliente).

**Implementato (3 file, nessuna regressione sul publish):**
1. **`ardy-fasi-bozza-api.php`** — nuovo `mode:'salva'` = upsert di UNA bozza completa:
   - input: `session_id`, `id` (opz., per update), `fase_nome`, `testo_breve`, `prezzo`,
     `immagini` (base64, ≤6), `video_urls`;
   - persiste le foto su disco in cartella dedicata `ARDY_UPLOAD_DIR/{session}/fasi-bozza/{id}/`
     con le stesse regole del publish (MIME jpeg/png/webp, max size, `ardyCompressImage`),
     mette gli URL in `foto_urls`; merge con le foto già presenti;
   - `id` presente + `stato='bozza'` → UPDATE; assente → INSERT (calcola `ordine` come oggi);
   - GET lista: aggiungere `testo_breve`, `foto_urls`, `video_urls` ai campi tornati.
2. **`ardy-michela-app.html`** (dashboard):
   - nuovo pulsante **💾 SALVA IN BOZZA** accanto a "PUBBLICA";
   - `modificaFaseBozza()` ricarica anche **foto/video** nel form (foto come anteprime + reimmesse
     in `lavImmagini` ri-fetchandole come base64, same-origin → poi il PUBBLICA parte identico a oggi);
   - aggiornare i contatori foto/video.
3. **`ardy-pubblica-lavorazione.php`** — alla pubblicazione di una bozza (`fase_id` presente),
   **pulire la cartella temporanea** `fasi-bozza/{id}` (le foto definitive le mette già lui su WP).
   Resto del publish invariato → email/WhatsApp/social intatti.

Il "PUBBLICA" non cambia comportamento (riceve sempre base64 dal form): si aggiunge solo la persistenza
nelle bozze + il ricarico. Il widget pubblico mostra già solo `stato='pubblicata'`, quindi finché è
bozza il cliente non vede nulla.

**Riflessione PARCHEGGIATA — video nelle bozze:** i video sono già su WP a monte (sono URL), quindi
vengono conservati/ricaricati "gratis"; ma il flusso veloce di bozza nasce per le **foto**. Da valutare
con calma se il video ha senso in questo flusso "scatta e salva" o se tenerlo solo alla pubblicazione.

**Da testare dal vivo:** (a) nome+note+foto → 💾 SALVA IN BOZZA → compare in lista col badge 📷;
(b) "✎ Modifica e pubblica" → foto/video tornano nel form; (c) PUBBLICA → esce dalle bozze, compare
tra le pubblicate, cliente notificato e foto sulla pagina WP; (d) cartella `fasi-bozza/{id}` ripulita.

### 📷 ~~Gestione foto anche nelle bozze social in attesa~~ ✅ FATTO (17/06, da testare dal vivo)
Nell'editor "✏ Modifica" di un post in attesa ora ci sono **➕ Aggiungi foto** e **✕** su ogni
miniatura, come nel composer. Le modifiche foto si salvano **subito** sul server (upload su WP via
`ardy-social-foto.php` → URL pubblico; bozza aggiornata via `ardy-social-bozze.php`), aggiornando
solo la galleria in-place così l'editor resta aperto. Test: apri Modifica su una bozza → aggiungi/
togli foto → chiudi e riapri (anche da altro dispositivo) → le foto persistono; poi Anteprima/Pubblica.

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

### 👥 Accesso "dipendente" con permessi limitati (ruoli) — DA FARE
Quando Ardy avrà un dipendente: creare un accesso con permessi ristretti. Decisione presa
(17/06): **il dipendente (`staff`) può SOLO fare preventivi + schede cliente** (e le fasi di
lavorazione collegate); **tutto il resto è admin-only**.

Architettura: l'auth è Basic Auth (`.htpasswd` + `.htaccess` con `Require valid-user` → oggi
tutto-o-niente). `ardyAuthUser()` (`ardy-auth.php`) **già restituisce lo username** → manca solo
lo strato ruoli. 3 mosse:
1. **Mappa utente→ruolo** in `ardy-config.php` (non in repo), es.
   `define('ARDY_RUOLI', ['michela'=>'admin','andrea'=>'admin','dipendente'=>'staff']);`
2. **Muro backend** (la parte che conta): in `ardy-auth.php` aggiungere `ardyRole()` +
   `ardyRequireRole('admin')`, e metterlo in cima a OGNI endpoint admin-only — CRM, stats,
   solleciti, grazie-consegna, outreach, email-finder, elimina-cliente, import-preventivi,
   import-scheda-pdf, dossier, gcal. ⚠️ Con Basic Auth il realm è unico: il confine vero è il
   check PHP su ogni endpoint, non l'`.htaccess`. Se ne manca uno, è un buco.
3. **Cosmesi frontend**: endpoint `/me` che ritorna il ruolo → la dashboard
   (`ardy-michela-app.html`) nasconde i bottoni admin per lo `staff` (UX, non sicurezza).
Nuovo utente Basic Auth: aggiungere riga al `.htpasswd` (`htpasswd -B <path> dipendente`, mai `-c`).

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
- **🆕 Indicatore "ha risposto" in lista** (implementato 17/06, **da testare dal vivo**):
  badge verde `💬 ha risposto` sui clienti che hanno **scritto di recente** (finestra **48h**) e di
  cui **non hai ancora aperto la chat**. Decisioni prese: finestra 48h; spegnimento **all'apertura
  della conversazione** (accordion 💬). Implementazione:
  - `ardy-crm-api.php`: 2 query aggregate (`web_messaggi` per session_id, `wa_messaggi` per ultime 9
    cifre tel) sull'ultimo `role='user'` entro 48h; flag `ha_risposto`/`ultimo_msg_at` esposti.
    Nuova colonna idempotente `clienti.conversazione_letta_at` (marker "vista").
  - `ardy-conversazioni.php`: aprendo la chat fa `UPDATE clienti SET conversazione_letta_at=NOW()` →
    il badge si spegne al prossimo reload (e subito lato client).
  - `ardy-michela-app.html`: badge in `renderList`; spegnimento immediato in `caricaConversazione`.
  Test dal vivo: far scrivere un cliente (WA/sito) → badge in lista; aprire l'accordion 💬 → badge via.
  Eventuale ritocco: finestra 24h vs 48h; aggiungere spegnimento anche al cambio stato (ora solo apertura chat).
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

## 🔒 ~~BACKLOG SICUREZZA~~ ✅ FATTO (19/06/2026, deployato)
> Difesa infrastrutturale già presente (OVH Anti-DDoS, Fail2ban, ModSecurity WAF, mod_hulk).
- ✅ **OAuth Google `state` anti-CSRF** (`ardy-gcal-auth.php`): `state` casuale in sessione,
  verificato con `hash_equals` al callback.
- ✅ **`get_stats` SQL parametrizzato** (`ardy-outreach-api.php`): prepared statement con `:cat`.
- ✅ **`mode=download` preventivo con ownership check** (`ardy-preventivo.php`): verifica in DB che
  il PDF appartenga a un preventivo prima di servirlo (403 altrimenti).
- ✅ **Prompt injection caption reel** (`ardy-crea-reel.php`, `generaCaptionReel`): dati lavoro
  delimitati con `<dati_lavoro>…</dati_lavoro>` + istruzione a trattarli come riferimento, non istruzioni.

---

## ⚡ BACKLOG PERFORMANCE
### ~~Alto impatto / basso sforzo~~ ✅ FATTO (19/06/2026, deployato)
- ✅ **DDL fuori dal path di richiesta**: tutti i `SHOW COLUMNS`/`ALTER`/`CREATE TABLE IF NOT EXISTS`
  centralizzati in **`ardy-migrate.php`** (eseguito una volta al deploy da `deploy.sh`). Nessun DDL
  gira più su request HTTP; le funzioni `ensure*` negli endpoint sono no-op. Migrazione idempotente
  (IF NOT EXISTS + try/catch su 1050/1060, `colExists`/`indexExists`).
- ✅ **`ardy-crm-api.php`**: i due `SELECT *` su `clienti` ora selezionano solo le colonne usate
  (costante `ARDY_CLIENTI_COLS`) + indice composito `idx_clienti_deleted_updated` su
  `(deleted_at, updated_at)`. Paginazione non necessaria allo stato attuale (volumi bassi).

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
