# Task da sviluppare — Ardy Lab

---

## TASK 1 — Michela come "capo": notifiche WhatsApp dalla AI ✅ FATTO (sessione 8)

**Implementato:** nuovo `ardy-notifica-michela.php` (libreria + endpoint protetto da `WA_LOOKUP_SECRET` per n8n). In `ardy-proxy.php`: notifica automatica consolidata a lead salvato e/o sopralluogo fissato, + nuovo tool `avvisa_michela` che Sole chiama per reclami/pagamenti/modifiche/richieste fuori standard (prompt aggiornato in `ardy-system.txt`). Dedupe persistente su file per non ripetere la stessa notifica. ⚠️ Restano da fare lato server: impostare `WA_TOKEN`/`WA_PHONE_NUMBER_ID`/`WA_MICHELA_NUMBER` in `ardy-config.php` e — per uscire dalla finestra 24h — far approvare un template Meta (`WA_TEMPLATE_NOTIFICA`). Il ramo WhatsApp (n8n) può chiamare l'endpoint per avvisare Michela riusando lo stesso codice.

**Cosa fa:**
Dopo ogni evento rilevante nelle chat (lead salvato, appuntamento fissato, cliente con dubbi/reclami/richieste strane), la AI manda automaticamente un messaggio WhatsApp a Michela (351 967 7973) come farebbe una segretaria efficiente.

**Tono:** breve, diretto, azionabile. Esempio:
> "Ciao Michela, ti aggiorno: Mario Rossi (Roma Prati) vuole un preventivo per rilaccatura divano. Ho fissato sopralluogo martedì 17/6 alle 10. Nessuna nota particolare."

**Dove si interviene:**
- `ardy-proxy.php` — aggiungere chiamata a funzione di notifica dopo `salva_lead_crm` e `fissa_appuntamento_calendario`
- `ardy-whatsapp-webhook.php` — aggiungere logica di inoltro verso numero Michela
- Nuova funzione `ardy-notifica-michela.php` (o integrata nel proxy) che chiama l'API WhatsApp Business con il messaggio di riepilogo

**Trigger da notificare:**
- Lead salvato nel CRM
- Appuntamento/sopralluogo fissato
- Cliente menziona un reclamo o insoddisfazione
- Cliente menziona un problema di pagamento
- Cliente chiede modifiche al lavoro già concordato
- Richiesta fuori standard (es. tempi urgenti, lavori particolari)

**Note tecniche:**
- Usare stesso sistema WhatsApp già presente (ardy-whatsapp-webhook.php + API Business)
- Il messaggio va da un numero "Ardy AI" a Michela — non da Michela a se stessa
- Mantenere log dei messaggi inviati per evitare duplicati nella stessa sessione

---

## 📌 TEMPLATE WHATSAPP META (da non dimenticare)

Stato attuale: le notifiche WhatsApp partono in **testo libero**, quindi funzionano solo
entro la **finestra 24h** dall'ultimo messaggio del destinatario. Per ora si sfrutta il
"saluto del mattino" di Michela a Sole (gratis, nessun template). Funziona, ma se lei
dimentica di salutare le notifiche di quel giorno si perdono.

Template da far approvare su Meta (WhatsApp Manager → Modelli messaggi, categoria **Utility**),
body con **una variabile `{{1}}`**, lingua `it`:
- [ ] **`notifica_michela`** — notifiche a Michela (Task 1). Poi in `ardy-config.php`: `define('WA_TEMPLATE_NOTIFICA','notifica_michela');`
- [ ] **`sollecito_pagamento`** — solleciti ai morosi fuori dalle 24h (Task 2). Poi: `define('WA_TEMPLATE_SOLLECITO','sollecito_pagamento');`
- [ ] **template fasi cliente** — aggiornamenti lavorazione ai clienti (Task 3, vedi sotto): es. "Ciao {{1}}, aggiornamento sul tuo {{2}}: completata la fase '{{3}}'. Guarda qui: {{4}}"

Note: categoria Utility = approvazione rapida e costo minimo (~3-4 cent/msg, solo verso
i clienti; le notifiche a Michela sono pochissime → costo trascurabile). Codice già
predisposto: basta impostare la costante quando il template è approvato.

---

## TASK 2 — Segretaria antipatica: modulo WhatsApp per clienti morosi ✅ FATTO

**Implementato:** `ardy-solleciti.php` (API: lista/crea/aggiorna/elimina/verifica/genera/invia + tabella `solleciti_pagamento` auto-creata + invio WhatsApp/email), `ardy-solleciti-system.txt` (prompt 4 livelli con riferimenti normativi), sezione dashboard (pulsante **💸 MOROSI** → modale con lista casi, form nuovo caso, verifica preventivo, generazione AI del testo modificabile, scelta canali e invio). Endpoint protetto da Basic Auth (`.htaccess`). Livelli 1-3 inviano via WA/email; livello 4 = bozza diffida da inviare a mano (stato → DIFFIDA). ⚠️ Lato server: per inviare WhatsApp ai morosi fuori dalle 24h serve un template Meta approvato (`WA_TEMPLATE_SOLLECITO`).

**Cosa fa:**
Modulo WhatsApp dedicato alla gestione dei clienti che non pagano o che trovano scuse. Tono progressivo, formale, con riferimenti normativi. Tutela Ardy senza essere volgare, ma senza fare sconti.

**Flusso in 4 livelli di escalation:**

| Livello | Quando | Tono | Azione |
|---------|--------|------|--------|
| 1 | Primo sollecito | Cordiale, ricorda la scadenza | Messaggio WA automatico |
| 2 | Dopo 7 giorni senza risposta | Fermo, cita il preventivo firmato | Messaggio WA + email |
| 3 | Dopo altri 7 giorni | Formale, cita normativa | WA + email con allegato PDF |
| 4 | Oltre 21 giorni | Diffida formale | Bozza lettera da inviare manualmente |

**Riferimenti normativi da usare:**
- Art. 1453 C.C. — risoluzione del contratto per inadempimento
- Art. 1454 C.C. — diffida ad adempiere (termine perentorio)
- D.Lgs. 231/2002 — interessi di mora (8% oltre tasso BCE per contratti commerciali)
- Art. 2 D.Lgs. 206/2005 (Codice del Consumo) — trasparenza e correttezza anche a tutela di Ardy come operatore professionale
- Eventuale richiamo al preventivo firmato come contratto vincolante (proposta + accettazione = art. 1326 C.C.)

**Storico solleciti (nuovo DB o tabella):**
```
tabella: solleciti_pagamento
- id
- session_id / telefono
- nome_cliente
- importo_dovuto
- data_scadenza
- numero_sollecito (1-4)
- data_ultimo_sollecito
- risposta_cliente (testo o null)
- stato: APERTO / PAGATO / DIFFIDA / ARCHIVIATO
- preventivo_ref (link o testo del preventivo approvato)
- note_interne
```

**Verifica preventivo:**
Prima di ogni sollecito, il modulo verifica che nel preventivo approvato ci siano:
- Importo totale chiaro
- Modalità di pagamento
- Acconto versato (e importo residuo)
- Firma/accettazione del cliente
- Data di accettazione

Se manca qualcosa, avvisa Michela PRIMA di procedere con il sollecito.

**File da creare:**
- `ardy-solleciti.php` — API per gestire solleciti (crea, aggiorna stato, genera messaggio)
- `ardy-solleciti-system.txt` — system prompt "segretaria antipatica" per Claude
- Sezione nella dashboard Michela per visualizzare e gestire i morosi

**Note:**
- Solo via WhatsApp (non chatbot pubblico)
- Michela decide quando avviare il flusso (non automatico) — inserisce numero, nome, importo, preventivo
- La AI genera il messaggio del livello corretto, Michela approva prima dell'invio
- Mantenere tono professionale anche al livello 4: Ardy deve risultare sempre dalla parte della ragione

---

## TASK 3 — Notifiche WhatsApp ai clienti nelle fasi di lavorazione

**Stato attuale:** quando si pubblica una fase (`ardy-pubblica-lavorazione.php`), il cliente riceve SOLO un'email (`inviaEmailCliente`, riga ~237). WhatsApp è usato solo in entrata (webhook → n8n), non c'è invio verso i clienti dal PHP.

**Cosa serve:** una funzione `inviaWhatsAppCliente()` accanto a `inviaEmailCliente()` che chiama la Graph API di Meta (`/{phone_number_id}/messages`).

**⚠ MURO DA SAPERE — regola delle 24 ore:**
Con WhatsApp Business API puoi mandare un messaggio libero a un cliente SOLO se lui ti ha scritto nelle ultime 24 ore. Una notifica "fase completata" arriva quasi sempre fuori da quella finestra → **obbligatorio usare un TEMPLATE pre-approvato da Meta.**

Template da far approvare (esempio):
> "Ciao {{1}}, aggiornamento sul tuo {{2}}: abbiamo completato la fase '{{3}}'. Guarda qui: {{4}}"

**Requisiti:**
1. Template approvato da Meta (collo di bottiglia: da poche ore a qualche giorno)
2. Token WhatsApp + phone_number_id nel config PHP (probabilmente già su n8n, va portato lato server)
3. Telefono cliente — già presente nel CRM (`clienti.telefono`)

**Stima:** ~mezza giornata, una volta che il template è approvato.

---

## TASK 4 — Comunicazioni straordinarie al cliente (non una fase normale) ✅ FATTO

**Implementato:** stesso endpoint `ardy-pubblica-lavorazione.php` con parametro `tipo` ('fase' | 'comunicazione'). Per le comunicazioni: blocco sul sito con bordo arancione + icona ⚠ + intestazione "Comunicazione importante"; email con oggetto/tono dedicati ("Aggiornamento importante…"); colonna `fasi.fase_tipo` (migrazione idempotente); testo generato da Claude con prompt apposito (spiega l'imprevisto, chiede approvazione se serve, niente social). Reel aggiornato per escludere le comunicazioni. Dashboard: secondo bottone **⚠ COMUNICAZIONE STRAORDINARIA** accanto a "Pubblica fase" (con conferma).

---

## TASK 4 (originale) — Comunicazioni straordinarie al cliente (non una fase normale)

**Caso d'uso reale:** durante un restauro emerge un imprevisto (es. restauro precedente pasticciato, strutturalmente solido ma esteticamente da rifare → serve ricostruire la parte mancante con stampo da stampa 3D). Va comunicato al cliente PRIMA di procedere. Non è un avanzamento, è una comunicazione importante.

**Soluzione (no sistema separato):** aggiungere un secondo bottone nella sezione Lavorazione, accanto a "Pubblica fase" → "Comunicazione straordinaria". Stesso flusso di `ardy-pubblica-lavorazione.php` ma:
- **Sul sito cliente:** blocco visivamente diverso (bordo arancione invece che oro, icona ⚠, intestazione "Comunicazione importante")
- **Email:** oggetto diverso ("Aggiornamento importante sulla tua lavorazione") e tono che spiega senza allarmare
- **DB:** colonna/campo `fase_tipo = 'comunicazione'` invece di `'fase'`, così nello storico si distingue
- Il testo lo genera Claude dalle note brevi di Michela, come per le fasi normali

**Stima:** ~mezza giornata.

---

## PROBABILI / DA VALUTARE PIÙ AVANTI

- **Filtro sidebar di default su ACCONTO** invece di TUTTI: se Michela lavora quasi sempre su lavori in corso, all'apertura la lista a sinistra mostrerebbe subito solo quelli. Da decidere in base al suo modo di lavorare reale.

---

## Note generali

- Entrambi i task sono indipendenti, possono essere sviluppati separatamente
- Task 1 è più veloce (~2-3 ore di sviluppo)
- Task 2 richiede nuovo DB + UI dashboard + logica normativa (~1 giornata)

---

# BACKLOG SICUREZZA & PERFORMANCE (checkup giugno 2026)

Già FATTI e in produzione (`main`): hardening `ardy-setup-login.php` (403 dopo setup),
anti-CSRF cambio stato preventivo (POST + header), difesa in profondità auth su
outreach/solleciti (`ardy-auth.php`), firma HMAC obbligatoria webhook WhatsApp,
fix stored XSS in `ardy-outreach.html`, rimozione information disclosure (errori
generici al client), anti-SSRF su email-finder/crea-reel (`ardy-net.php`),
informativa privacy GDPR + firma nel preventivo PDF.

## Protezioni a livello infrastruttura (server VPS)

Il server fornisce già, **a monte dell'applicazione**, diversi livelli di difesa.
Vanno tenuti presenti perché mitigano (anche se non eliminano) alcuni dei punti
applicativi qui sotto:
- **OVH Edge Network Firewall + Anti-DDoS** — filtraggio L3/L4 e mitigazione DDoS
  volumetrici all'ingresso della rete OVH.
- **Fail2ban** — ban automatico degli IP dopo tentativi ripetuti/falliti
  (brute-force su login, abusi).
- **ModSecurity (WAF)** — regole applicative contro pattern di SQLi/XSS/LFI ecc.
- **mod_hulk / HULK (cPanel)** — protezione anti brute-force sui login HTTP.

Effetto sui punti sotto: **brute-force, DoS e flood** sono già contenuti a livello
server; i rate-limit applicativi restano utili come difesa in profondità e — nel
caso del proxy — soprattutto per il **controllo costi** dell'API a pagamento, non
come unica barriera anti-abuso. La protezione applicativa va comunque mantenuta
(il WAF non conosce la logica di business né l'autorizzazione per-endpoint).

## Sicurezza — rimasti

### Priorità ALTA (da fare per primo)
- ✅ **FATTO — `ardy-proxy.php` — rate-limit basato su header falsificabili.** Nuovo
  helper `ardyClientIp()` in `ardy-net.php`: `CF-Connecting-IP`/`X-Forwarded-For` sono
  fidati **solo** se `REMOTE_ADDR` è in un range Cloudflare noto (CIDR match v4/v6 con
  `ardyIpInCidr`/`ardyIsCloudflareIp`), altrimenti si usa `REMOTE_ADDR` non falsificabile.
  Chi colpisce l'origin direttamente non può più ruotare l'IP per azzerare il rate-limit
  e far costare richieste all'API Anthropic.

### Priorità MEDIA
- **`ardy-save-lead.php` — nessun rate-limit.** Endpoint pubblico: si può riempire la
  tabella `clienti` di lead spazzatura. Fix: rate-limit per IP/sessione.
- **Upload dir eseguibili.** `ARDY_UPLOAD_DIR`, `reels/`, `preventivi_pdf/`: aggiungere un
  `.htaccess` con motore PHP disattivato (`php_flag engine off` / `RemoveHandler`) o
  spostare fuori dalla web root.
- **PII in `ardy-wa-log.json`.** Salva numero/nome/testo in chiaro e cresce con input
  webhook. Ridurre i dati loggati + rotazione/retention.

### Priorità BASSA
- **OAuth Google senza `state`** (`ardy-gcal-auth.php`): aggiungere parametro `state`
  casuale verificato (CSRF su OAuth).
- **`get_stats` SQL** (`ardy-outreach-api.php`): unica query con interpolazione
  (`WHERE categoria='$cat'`, oggi da array interno → non sfruttabile). Parametrizzare.
- **`mode=download` preventivo**: serve qualsiasi PDF della cartella a chi è dietro Basic
  Auth (no ownership). Basso rischio (utente unico), ma da legare alla sessione se servisse.

## Performance — rimasti (da audit dedicato)

### Alto impatto / basso sforzo
- **Ricerca telefono full-scan** (`ardy-wa-lookup.php`, `ardy-proxy.php`):
  `REPLACE(...) LIKE '%...'` impedisce l'uso di indici sul percorso WhatsApp.
  Fix: colonna `telefono_last9` normalizzata + indice, match esatto.
- **DDL su ogni request** (`SHOW COLUMNS`/`ALTER`/`CREATE TABLE IF NOT EXISTS` in
  `ardy-proxy.php`, `ardy-stats.php`, `ardy-pubblica-lavorazione.php`,
  `ardy-libreria-api.php`, `ardy-reel-template-api.php`): spostare in una migrazione
  one-shot, togliere dal path di richiesta.
- **`ardy-crm-api.php`**: `SELECT *` su `clienti` senza `LIMIT`. Selezionare solo le
  colonne usate + paginazione + indice su `updated_at`.
- **Quick-win** (1-2 righe): `finfo::file()` invece di `buffer(file_get_contents())` in
  `ardy-lead-foto.php`; memoizzare in `static` i system-prompt riletti da disco
  (`ardy-wa-lookup.php`, `ardy-proxy.php`).

### Da pianificare
- Cache PDF preventivo per content-hash + memoizzazione logo base64 (`ardy-preventivo.php`).
- Estrarre JS/CSS dalle HTML monolitiche + header cache/cache-busting.
- Rate-limit su APCu/Redis invece che su file (`ardy-proxy.php`).
- Unificare `dbConnect()` (mysqli) di `ardy-preventivo.php` sul PDO di `ardyDB()`.

## Termini & Condizioni / Privacy
- Aggiornare la pagina **termini e condizioni su WordPress** (ardy-lab.it), coerente con
  l'informativa GDPR ora presente nel preventivo PDF. (Testo da preparare e incollare;
  fuori da questo repo.)
