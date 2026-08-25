# Security Audit — ardyagent

**Date:** 2026-06-20 (primi due giri) · 2026-07-04 (terzo giro — difesa in profondità sui dati utente)
· 2026-08-25 (quarto giro — SSRF, XSS, coerenza auth a due livelli)
**Ambito:** intero progetto (95+ file PHP, JS, `.htaccess`, config, deploy, snippet WordPress, n8n,
`ardy-mcp` TypeScript)
**Esito:** codebase complessivamente solida; chiusi 4 gruppi di vulnerabilità nei primi due giri
(1 critico, 1 medio auth + 1 medio XSS + bassi), tutti verificati in produzione, con rotazione del
token di verifica del webhook. Il terzo giro (§8) ha esteso la **difesa in profondità** a tutti gli
endpoint dell'area riservata e completato i fix fail-closed sugli endpoint che leggono PII. Il quarto
giro (§9) — un ri-audit completo, non solo sul codice nuovo — ha trovato e corretto una **SSRF
autenticata** e uno **stored XSS** nel codice aggiunto dopo il terzo giro, oltre a colmare
l'ultimo endpoint distruttivo senza `ardyRequireAuth()` e a rendere quel guard una verifica
**reale** delle credenziali (prima controllava solo che ce ne fossero, non che fossero corrette).

> **Struttura del documento:** §1–4 = primo giro (auth + hardening). §5 = secondo giro
> (XSS, token webhook, password). §6 = rotazione `WA_VERIFY_TOKEN`. §7 = aree pulite senza rilievi nel 2° giro.
> §8 = terzo giro (2026-07-04): `ardyRequireAuth()` esteso, fail-closed su `wa-lookup`/`wa-memoria`,
> guard su `ardy-migrate`, hardening `verify-client`.
> §9 = quarto giro (2026-08-25): SSRF su `ardy-gbp-check`, stored XSS su `ardy-outreach.html`,
> `ardyRequireAuth()` con verifica password reale, `ardy-elimina-cliente`/`ardy-places-prova`
> allineati alla difesa in profondità, header di sicurezza, `composer.lock`, hardening minori.

---

## 1. Findings e fix applicati

### 🟥 CRITICO — Endpoint sensibili non realmente protetti dal Basic Auth

**Problema.** Diversi endpoint dichiaravano nei commenti di essere "Protetti da Basic Auth via .htaccess",
ma **non erano nel blocco `FilesMatch`** dell'`.htaccess` e **non chiamavano** `ardyRequireAuth()`.
Erano quindi eseguibili da chiunque, senza autenticazione.

Endpoint esposti:

| Endpoint | Rischio se chiamato senza auth |
|---|---|
| `ardy-elimina-cliente.php` | **Hard-delete** di scheda, preventivi, fasi, storico WhatsApp + file foto/reel |
| `ardy-conversazioni.php` | Storico conversazioni cliente (PII) |
| `ardy-email-cliente-api.php` | Invio email ai clienti + cancellazione bozze |
| `ardy-crea-faq.php` | Modifica articoli WordPress + chiamate Claude a pagamento |
| `ardy-fasi-bozza-api.php` | Lettura dati + scrittura foto su disco |
| `ardy-allega-preventivo.php` | Manipolazione preventivi |
| `ardy-estrai-preventivo-pdf.php` | Lettura/estrazione preventivi |
| `ardy-archivia-persi.php` | Spostamento massivo di file |

**Fix.** Aggiunti tutti e 8 al blocco Basic Auth in `.htaccess`. Verificato che siano chiamati
**solo dalla dashboard** (fetch dal browser, che invia automaticamente il Basic Auth) e che
i riferimenti incrociati PHP→PHP siano **solo commenti** (nessuna chiamata server-to-server),
quindi il cambio non rompe flussi interni.

### 🟧 MEDIO — Guard a segreto condiviso "fail-open"

**Problema.** Alcuni endpoint saltavano il controllo del segreto se `WA_LOOKUP_SECRET` non era
definito (`if (defined(...) && ... !== '')`), restando aperti in caso di config mancante:
`ardy-wa-agent.php`, `ardy-chiusura-sessioni.php`, `ardy-lead-monitor.php`, `ardy-notifica-michela.php`.
`ardy-wa-agent.php` è particolarmente sensibile (agente WhatsApp con tool).

**Fix.** Resi **fail-closed** (stesso pattern già usato da `ardy-lead-contatto.php`,
`ardy-wa-crea-scheda.php` e dal webhook): senza `WA_LOOKUP_SECRET` configurato l'endpoint HTTP
risponde 500 invece di restare aperto. La modalità CLI/cron resta esente.

### 🟨 BASSO (annotati, non bloccanti)

- `ardy-whatsapp-webhook.php`: `WA_VERIFY_TOKEN` ha un fallback hardcoded (`ardy_wa_verify_2026`).
  Impatto basso (solo verifica `subscribe`); meglio richiederlo da config.
- `ardy-setup-login.php`: campo password come `type="text"`. Il file comunque si auto-disabilita
  se `.htpasswd` esiste.

---

## 2. Verifica live in produzione (post-deploy, 2026-06-20)

| Controllo | Atteso | Risultato |
|---|---|---|
| 8 endpoint appena protetti, senza login | 401 | 401 ✅ |
| Endpoint già protetti (crm-api, preventivo, solleciti, dossier) | 401 | 401 ✅ |
| File interni/config (config, db, auth, net, system.txt) | 403 Deny | 403 ✅ |
| Endpoint pubblici (proxy, unsubscribe, verify-client, save-lead, visita) | non-401 | 200 ✅ |
| 4 guard fail-closed, senza/errato secret | 403 (non 500) | 403 ✅ |
| Webhook WhatsApp GET (token assente/errato) | 403 | 403 ✅ |
| Webhook WhatsApp POST (firma assente) | 403 | 403 ✅ |

Il `403` (anziché `500`) dei guard conferma che `WA_LOOKUP_SECRET` e `WA_APP_SECRET` sono
configurati sul server → i flussi legittimi continuano a funzionare.

---

## 3. Aree verificate senza rilievi (controlli positivi)

- **SQL injection:** prepared statements ovunque; le poche query con nome-tabella interpolato
  usano whitelist hardcoded. Nessuna SQLi.
- **Command injection:** `escapeshellarg()` su tutti i comandi FFmpeg; `basename()` sulla musica.
- **SSRF:** difesa robusta in `ardy-net.php` (`ardySafeHttpGet`): blocco IP privati/loopback/metadata,
  validazione di ogni redirect, TLS forzato, limiti su protocolli e dimensione.
- **Upload:** MIME reale via `finfo`, hardening cartelle (`ardyHardenUploadDir` → no esecuzione script),
  limiti di dimensione, `move_uploaded_file`.
- **Segreti:** confronti con `hash_equals` (timing-safe); token HMAC; `ardy-config.php`, token e CSV PII
  tutti in `.gitignore` — nessun segreto committato.
- **Chat pubblica (`ardy-proxy.php`):** rate-limit per IP (orario/giornaliero) e per sessione,
  anti-spoof via verifica IP Cloudflare; CORS ristretto (nessun wildcard).
- **OAuth Google:** `state` anti-CSRF presente nel flusso.

---

## 4. Costanti attese in `ardy-config.php` (non versionato)

Database: `ARDY_DB_HOST` `ARDY_DB_NAME` `ARDY_DB_USER` `ARDY_DB_PASS`
API/AI: `ARDY_API_KEY` `ARDY_BREVO_API_KEY`
Email/SMTP: `ARDY_SMTP_USER` `ARDY_SMTP_PASSWORD` `ARDY_MAIL_MICHELA`
Segreti: `WA_LOOKUP_SECRET` `WA_APP_SECRET` `ARDY_INTERNAL_SECRET` `ARDY_UNSUB_SECRET`
WhatsApp: `WA_TOKEN` `WA_PHONE_NUMBER_ID` `WA_VERIFY_TOKEN` `WA_MICHELA_NUMBER` `WA_ANDREA_NUMBER` `ARDY_WA_PUBLIC_NUMBER`
Template WA: `WA_TEMPLATE_FASI` `WA_TEMPLATE_GRAZIE` `WA_TEMPLATE_LANG` `WA_TEMPLATE_NOTIFICA` `WA_TEMPLATE_PRIMO_CONTATTO` `WA_TEMPLATE_SOLLECITO`
Google Calendar: `ARDY_GCAL_CLIENT_ID` `ARDY_GCAL_CLIENT_SECRET` (calendario = `primary` hardcoded; token in `ardy-gcal-token.json`)
Google Business Profile: `GBP_PARENT`
Percorsi: `ARDY_UPLOAD_DIR` `ARDY_PDF_DIR` `ARDY_RATE_LIMIT_DIR`
Rate-limit/limiti (con default nel codice): `ARDY_IP_MAX_REQUESTS_HOUR` `ARDY_IP_RATE_LIMIT_SECONDS` `ARDY_SESSION_RATE_SECONDS` `ARDY_RATE_LIMIT_TTL_HOURS` `ARDY_MAX_HISTORY_ITEMS` `ARDY_MAX_IMAGES_PER_MSG` `ARDY_MAX_MESSAGE_LENGTH`

---

## 5. Secondo giro (2026-06-20) — JS / WordPress / n8n / XSS

Coperte le aree non toccate nel primo giro: tutti i file JS, gli snippet WordPress, i file n8n
e il vettore **stored XSS** (dati cliente → dashboard).

### 🟠 MEDIO — Stored XSS in contesto stringa-JS (dashboard)

**Problema.** In `ardy-michela-app.html` (Cestino) il nome cliente — testo libero, controllabile
dal cliente via form lead o nome profilo WhatsApp — finiva dentro un handler inline:
`onclick="eliminaDefCestino('${escHtml(nome)}')"`. `escHtml()` da solo non basta in quel contesto:
il parser HTML ridecodifica `&#39;` → apice **prima** che il JS venga eseguito, riaprendo il rischio
di breakout e quindi esecuzione di codice nella sessione autenticata di Michela.

**Fix.** Aggiunta la helper **`escJs()`** (escape per stringa JS — backslash e apice — seguito
dall'escape HTML dell'attributo) e applicata ai pulsanti del Cestino. Gli altri `onclick` passano
solo ID numerici o valori già sanificati server-side (`session_id`, fase id) → non a rischio.

### 🟡 BASSO — `WA_VERIFY_TOKEN` con fallback hardcoded

**Problema.** `ardy-whatsapp-webhook.php` aveva un fallback hardcoded del token di verifica
(`ardy_wa_verify_2026`): valore pubblico nel repo, quindi indovinabile.

**Fix.** Rimosso il fallback: il token è ora richiesto da `ardy-config.php`, con confronto
**timing-safe** (`hash_equals`); se assente la GET di verifica risponde 500. Impatto reale comunque
basso (il verify token gate solo l'handshake di subscribe, non la consegna messaggi).

### 🟡 BASSO — Campo password di setup-login

**Fix.** `ardy-setup-login.php`: campo password da `type="text"` a `type="password"`
(+ `autocomplete="new-password"`).

---

## 6. Rotazione `WA_VERIFY_TOKEN` (2026-06-20)

A valle del fix §5, il token di verifica del webhook è stato **ruotato** (il vecchio valore era
ormai pubblico nella history git):

1. Nuovo valore segreto aggiunto a `ardy-config.php` sul server (`define('WA_VERIFY_TOKEN', ...)`).
2. Deploy del codice senza fallback.
3. Aggiornato lo stesso valore su **Meta → WhatsApp → Configurazione di produzione → Webhook**
   ("Verifica e salva" andato a buon fine, nessun errore).

Verifica live: token nuovo → 200; token vecchio `ardy_wa_verify_2026` → 403; senza token → 403.
La ricezione messaggi non si è mai interrotta (i POST sono validati con `WA_APP_SECRET`).

> Il valore corrente vive solo in `ardy-config.php` (non versionato) e nel pannello Meta.

---

## 7. Aree verificate senza rilievi nel 2° giro

- **Frontend chat** (`ardy-chat-site.js`, `ardy-widget-lavorazione.js`, `ardy-chat-corsi.js`,
  `wordpress-snippets/ardychat.js`): rendering messaggi sempre via `textContent`, template
  `innerHTML` statici. Nessun XSS.
- **Dashboard** (resto di `ardy-michela-app.html`): `escHtml()`/`safeUrl()` usati correttamente;
  conversazioni cliente renderizzate con escape; campi cliente via `textContent`.
- **Snippet WordPress** (`wordpress-snippets/*.php`): nessun `$_GET/$_POST`, niente `eval`/`exec`.
- **n8n** (`ardy-whatsapp-node-completo.js`, `ardy-whatsapp-workflow.json`): segreti tutti
  placeholder (`__META_WA_TOKEN__`, `__ANTHROPIC_API_KEY__`, `__WA_LOOKUP_SECRET__`).

### Note operative post-audit
- Tutti i fix dei due giri sono **deployati e verificati in produzione**.
- I 401/403/200 attesi su tutte le categorie di endpoint sono stati confermati con test live.
- Le guide utente (`ardy-guida-michela.html`, `GUIDA-UTENTE.md`, `MANUALE-SOLE.md`) non
  documentano dettagli tecnici di config/webhook → non richiedono aggiornamenti.

---

## 8. Terzo giro (2026-07-04) — difesa in profondità sui dati utente

Audit **statico** (revisione del codice) focalizzato sul trattamento dei dati dei clienti
(PII: nome, telefono, email, indirizzo, conversazioni). A differenza dei primi due giri, i fix
di questo giro sono applicati sul branch ma **non ancora verificati in produzione**: vanno
confermati con i test live post-deploy come in §2 (attesi 401 sugli endpoint riservati senza login,
non-401 sui pubblici).

L'impianto resta solido (SQLi/SSRF/HMAC/upload/segreti già a posto, vedi §3/§7). Le criticità di
questo giro non erano bug grossolani ma **incoerenze** nell'applicazione delle difese già esistenti.

| # | Gravità | Problema | Stato |
|---|---|---|---|
| 8.1 | 🟠 Media | `ardyRequireAuth()` presente solo su ~4 endpoint su ~40 dell'area riservata | ✅ risolto |
| 8.2 | 🟠 Media | Segreto `WA_LOOKUP_SECRET` "opzionale" su `wa-lookup`/`wa-memoria` (leggono PII) | ✅ risolto |
| 8.3 | 🟠 Media | `ardy-migrate.php` eseguibile pubblicamente (DDL + errori DB in chiaro) | ✅ risolto |
| 8.4 | 🟡 Bassa | `getMessage()` esposto al client (`archivia-persi`, `chiusura-sessioni`) | ✅ risolto |
| 8.5 | 🟡 Bassa | `verify-client`: match sulle ultime 7 cifre + rate-limit su `REMOTE_ADDR` | ✅ risolto |
| 8.6 | 🔵 Info | Token OAuth Google + `ardy-config.php` nella web-root | ⏳ aperto (infra) |
| 8.7 | 🔵 Info | Nessuna policy di retention/GDPR esplicita | ⏳ aperto (operativo) |

### 8.1 🟠 Difesa in profondità estesa a TUTTI gli endpoint riservati
Il primo giro (§1) aveva messo gli endpoint sensibili dietro Basic Auth nell'`.htaccess`, ma solo
~4 file chiamavano anche il guard applicativo `ardyRequireAuth()`. Se la regex `FilesMatch`
dell'`.htaccess` non scattasse (deploy errato, path diversa, file servito altrove), gli endpoint
con i dati dei clienti resterebbero aperti — un single point of failure.
**Fix.** Aggiunto `ardyRequireAuth()` a ~37 endpoint (CRM, dossier, preventivi, tutta la famiglia
`progetti-*`, sopralluoghi, trasporti, pubblicazioni, reel, ecc.). Nei file **dual-use**
(`ardy-dossier`, `ardy-grazie-consegna`, `ardy-conoscenza-appresa`, `ardy-trasporti`) il guard
scatta **solo** nel ramo endpoint diretto (`realpath(SCRIPT_FILENAME)===__FILE__`), non quando
sono inclusi come libreria da proxy/webhook. `ardy-email-finder` resta usabile da CLI (uso previsto)
ma protetto via web. Il guard è sempre **dopo** il preflight OPTIONS (CORS intatto).
Verificato con `php -l` su tutti i file toccati e con controllo che nessun endpoint pubblico
(`proxy`, `proxy-lavorazione`, `save-lead`, `verify-client`, `visita`, `unsubscribe`,
`whatsapp-webhook`, `wa-*`) sia stato impattato.

### 8.2 🟠 `wa-lookup` / `wa-memoria` resi fail-closed
Completano il fix §1-MEDIO (che aveva reso fail-closed `wa-agent`, `chiusura-sessioni`,
`lead-monitor`, `notifica-michela`). Questi due endpoint restituiscono PII — identità e contesto
del cliente e **intero storico conversazione** dato un numero — ma verificavano `WA_LOOKUP_SECRET`
solo se configurato. **Fix.** Senza il segreto configurato ora rispondono 500 invece di restare
aperti (stesso pattern fail-closed degli altri).

### 8.3 🟠 Guard su `ardy-migrate.php`
Lo script di migrazione dello schema non aveva alcun controllo di accesso e non era nell'`.htaccess`:
una semplice GET eseguiva i DDL e, sugli errori non previsti, stampava `getMessage()` (dettagli del
DB). **Fix.** Eseguibile solo da CLI (deploy) o via HTTP con il segreto condiviso; altrimenti 403.

### 8.4 🟡 Niente `getMessage()` verso il client
`ardy-archivia-persi.php` e `ardy-chiusura-sessioni.php` rimandavano al client il testo
dell'eccezione (dettagli su query/schema). **Fix.** Dettaglio solo in `error_log`, al client un
messaggio generico — uniformato al resto del codice.

### 8.5 🟡 `verify-client` irrobustito contro il brute-force
Endpoint pubblico con cui il cliente prova la propria identità per vedere la pagina di lavorazione.
**Fix.** (a) IP **reale** del client via `ardyClientIp()`: dietro Cloudflare `REMOTE_ADDR` è l'IP
dell'edge e accomunava tutti gli utenti nello stesso bucket di rate-limit; (b) match del telefono
sulle **ultime 9 cifre** invece di 7 (canonico `ardyTelefonoLast9`, ~1000× più costoso da forzare,
mantenendo la tolleranza a prefisso/spazi); (c) solo i tentativi **falliti** pesano sul limite;
(d) storage del rate-limit in `ARDY_RATE_LIMIT_DIR` (persistente) con fallback su `sys_get_temp_dir`.
Evitato di proposito un lockout per-lavorazione (rischio di griefing): il codice `ARD-XXXX-XXXX`
resta l'alternativa forte per il cliente.

### 8.6 / 8.7 🔵 Aperti (non risolvibili solo via codice)
- **8.6** — `ardy-config.php` e `ardy-gcal-token.json` (refresh token Google: Calendar + Gmail)
  stanno nella document root; oggi protetti da `.gitignore` + regola `.htaccess`, ma è lo stesso
  single-point-of-failure di 8.1. Consigliato spostarli **sopra** `public_html` e referenziarli via
  path assoluto (intervento su filesystem/`.cpanel.yml`).
- **8.7** — PII in `clienti`/`wa_messaggi`/`web_messaggi`, aggregata da `ardy-dossier`. Esistono già
  disiscrizione (`ardy-unsubscribe`) e cancellazione (`ardy-elimina-cliente`); manca una **policy di
  retention** esplicita e non risulta cifratura at-rest oltre a quella eventuale del DB. Da valutare
  in ottica GDPR (minimizzazione + retention).

---

## 9. Quarto giro (2026-08-25) — ri-audit completo

**Metodo.** Non un controllo del solo codice nuovo: ri-verificate da zero tutte le classi di
vulnerabilità dei giri precedenti (SQLi, command injection, SSRF, XSS, upload, segreti, IDOR,
CSRF, OAuth) su tutto il codebase attuale (95 file `.php`, dashboard HTML/JS, `ardy-mcp/`
TypeScript, `n8n/`, `wordpress-snippets/`), con verifica puntuale di ogni finding (lettura diretta
del codice, non solo grep) prima di considerarlo confermato. Nessuna regressione trovata sui fix
dei giri 1–3. Tutti i fix di questo giro sono stati validati con `php -l` su ogni file toccato e,
per i due finding critici, con un test isolato che riproduce lo scenario di attacco end-to-end
(vedi §9.1 e §9.2) — **non ancora verificati in produzione** (serve un deploy, come per i giri
precedenti; §2 di questo documento andrà aggiornato dopo).

| # | Severità | Problema | Stato |
|---|---|---|---|
| 9.1 | 🟠 Media | SSRF autenticata in `ardy-gbp-check.php` (bypassava `ardySafeHttpGet`) | ✅ risolto |
| 9.2 | 🟠 Media | Stored XSS in `ardy-outreach.html` (escape JS-in-attributo nell'ordine sbagliato) | ✅ risolto |
| 9.3 | 🟠 Media | `ardyRequireAuth()` verificava solo la *presenza* di credenziali, mai la password | ✅ risolto |
| 9.4 | 🟠 Media | `ardy-elimina-cliente.php` (hard-delete) senza `ardyRequireAuth()` come 2° livello | ✅ risolto |
| 9.5 | 🟠 Media | `ardy-places-prova.php` fuori dal blocco Basic Auth `.htaccess` | ✅ risolto |
| 9.6 | 🟡 Bassa | `ardy-progetti-file-api.php`: whitelist solo su estensione, niente firma binaria | ✅ risolto |
| 9.7 | 🟡 Bassa | Header di sicurezza (nosniff/X-Frame-Options/CSP minima/HSTS) assenti ovunque | ✅ risolto |
| 9.8 | 🟡 Bassa | `ardy-design-app.html`: `esc()` non escapava l'apostrofo (inconsistente, non sfruttato) | ✅ risolto |
| 9.9 | 🟡 Bassa | `ardy-gcal-auth.php`: token OAuth stampato senza `htmlspecialchars` | ✅ risolto |
| 9.10 | 🔵 Info | `osmHttpGet()` in `ardy-outreach-api.php`: redirect non limitati/ristretti a http(s) | ✅ risolto |
| 9.11 | 🔵 Info | `composer.lock` non versionato → build mPDF non riproducibile | ✅ risolto |
| 9.12 | 🟡 Bassa | Nessun rate-limit sugli endpoint AI/email costosi ma autenticati | ⏳ aperto (vedi nota) |
| 9.13 | 🔵 Info | mPDF senza hardening esplicito anti-fetch remoto (oggi non sfruttabile) | ⏳ aperto (vedi nota) |
| 9.14 | 🔵 Info | `ZipArchive` su DOCX/ODT (`ardy-progetti-ai.php`): rischio zip-bomb minimo, autenticato | ⏳ aperto (vedi nota) |

### 9.1 🟠 SSRF autenticata in `ardy-gbp-check.php`
**Problema.** Il pannello diagnostico Google Business Profile scarica l'immagine passata in
`?img=<url>` con un `curl_init()` diretto, non con `ardySafeHttpGet()` (il wrapper SSRF-safe che
tutto il resto del codebase usa): `filter_var($imgTest, FILTER_VALIDATE_URL)` accetta **qualunque
schema** (anche `file://`, `gopher://`), niente blocco IP privati/loopback/metadata, redirect
seguiti senza validazione. Un utente autenticato (o chi avesse rubato la sessione/credenziali) poteva
richiedere `?img=file:///etc/passwd` o `?img=http://169.254.169.254/...` e leggere dalla risposta
dimensioni/MIME del contenuto scaricato — fingerprint di servizi interni o dei metadata cloud, e
potenzialmente lettura di file locali a seconda della build di libcurl.

**Fix.** Sostituito il `curl_init()` diretto con `ardyValidatePublicUrl()` (solo http/https, blocca
host privati) + `ardySafeHttpGet()` (redirect validati uno a uno, niente `FOLLOWLOCATION` cieco,
limite di byte). Verificato con un endpoint che simula il download: URL `file://`/host privati ora
respinti a monte, prima di qualunque `curl_exec`.

### 9.2 🟠 Stored XSS in `ardy-outreach.html`
**Problema.** Il filtro "regione" (campo testo libero sui contatti, popolato anche da import/OSM)
veniva reso in un attributo `onclick` con `` `onclick="setRegioneFilter('${escHtml(r).replace(/'/g,"\\'")}')"` ``
— il `.replace()` per l'apice girava **dopo** `escHtml()`, quando l'apice era già diventato
l'entità `&#39;` e quindi non c'era più nulla da sostituire (operazione a vuoto). Un valore regione
come `x');alert(document.cookie);//` sopravviveva come `x&#39;);alert(...);//`: il browser
decodifica l'entità HTML dell'attributo **prima** di eseguire il JS dell'`onclick`, quindi il codice
eseguito diventava `setRegioneFilter('x');alert(document.cookie);//')` — breakout completo, XSS
nella sessione di chi apre il tool Outreach.

**Fix.** Aggiunta la funzione `escJs()` (stesso pattern già corretto e in uso in
`ardy-michela-app.html`): escape di backslash e apice **prima** del contesto JS, poi `escHtml()` per
il contesto HTML — ordine invertito rispetto al bug. Sostituite tutte e 3 le occorrenze del pattern
rotto (righe dei filtri regione in outreach, tool multi-regione, filtro campagne). **Verificato con
un test isolato** (Node, replicando escape → rendering HTML → decodifica attributo come farebbe un
browser) che il payload sopra non esegue più: dopo il fix, l'apice resta escapato come `\'` dentro
la stringa JS e il codice iniettato resta testo inerte, non eseguibile.

### 9.3 🟠 `ardyRequireAuth()` non verificava la password
**Problema.** Il guard applicativo di secondo livello (introdotto in §8.1 proprio per non dipendere
solo dal Basic Auth di `.htaccess`) controllava solo che `PHP_AUTH_USER` fosse **presente**, mai che
la password fosse corretta. Ma PHP popola `PHP_AUTH_USER`/`PHP_AUTH_PW` da qualunque header
`Authorization: Basic ...` il client invii, **indipendentemente** dal fatto che Apache l'abbia
validato — è esattamente lo scenario che il guard dichiara di coprire (regex `FilesMatch` non
combaciante, deploy errato) a restare scoperto: bastava un header Basic con credenziali qualsiasi
per superarlo.

**Fix.** `ardyRequireAuth()` ora, quando la password è disponibile a PHP (che è il caso comune),
la verifica **davvero** contro `.htpasswd` con `password_verify()` (il file è generato da
`ardy-setup-login.php` con `PASSWORD_BCRYPT`, quindi il formato combacia sempre). Se `.htpasswd`
non fosse leggibile da PHP (problema di permessi, non lo scenario che vogliamo coprire) il guard
ricade sul comportamento storico — di proposito, per non rischiare un lockout in produzione per un
problema di permessi invece che per una reale falla di auth. Quando l'utente risulta da
`REMOTE_USER` (Apache ha già validato lui stesso la password) non c'è nulla da riverificare.
**Verificato con un test isolato** (bcrypt reale, utente/password corretti → autorizzato; password
sbagliata → rifiutato; utente inesistente → rifiutato).

### 9.4 🟠 `ardy-elimina-cliente.php` senza secondo livello di difesa
L'endpoint più distruttivo del progetto (hard-delete di scheda/preventivi/fasi/storico WhatsApp +
file, e "libera spazio" che cancella foto/reel) era rimasto l'unico, tra quelli del blocco Basic
Auth, a non chiamare `ardyRequireAuth()` — nonostante §8.1 dichiari il pattern esteso a "tutti" gli
endpoint riservati. Un commento nel codice giustificava l'omissione con "su questo server
`ardyRequireAuth` non riceve l'header Authorization", affermazione non riscontrata: lo stesso guard
funziona sugli altri ~50 endpoint sullo stesso server. **Fix.** Aggiunto `require_once
ardy-auth.php` + `ardyRequireAuth()`, stesso punto (dopo il preflight CORS, prima di ogni
side-effect) degli altri endpoint.

### 9.5 🟠 `ardy-places-prova.php` fuori dal blocco Basic Auth
Chiamava `ardyRequireAuth()` ma non era nella regex `FilesMatch` dell'`.htaccess` — e, prima del fix
§9.3, `ardyRequireAuth()` da sola non era una vera barriera. Consumava budget Google Places a
pagamento senza autenticazione reale. **Fix.** Aggiunto al blocco Basic Auth in `.htaccess`.

### 9.6 🟡 Upload progetti: aggiunta verifica della firma binaria
`ardy-progetti-file-api.php` ammette un ventaglio ampio di formati (STL/OBJ/3MF/G-code/STEP/CAD/
ZIP/PDF/DOCX/ODT/RTF/TXT/MD) validando solo l'estensione dichiarata dal client — a differenza di
tutti gli altri upload del progetto, che verificano il MIME reale via `finfo`. Impatto già mitigato
(nome rigenerato server-side, cartella non eseguibile via `ardyHardenUploadDir()`), ma un file
rinominato passava senza controlli. **Fix.** Aggiunta `ardyFileSignatureOk()`: verifica i primi
byte per i formati con una firma affidabile (PDF, ZIP-based — zip/3mf/docx/odt, RTF); i formati
senza una firma universale (STL ASCII, G-code, STEP/CAD, testo semplice) restano sull'estensione,
perché un controllo firma lì darebbe solo falsi negativi.

### 9.7 🟡 Header di sicurezza globali
Nessun endpoint impostava `X-Content-Type-Options`, `X-Frame-Options` o `Strict-Transport-Security`
(solo 2 file su 95 avevano un `nosniff` locale). **Fix.** Aggiunto un blocco `mod_headers` globale
in `.htaccess`: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
`Content-Security-Policy: frame-ancestors 'self'; object-src 'none'` (solo anti-clickjacking/
anti-plugin-embedding — **niente** `script-src` restrittivo: la dashboard usa ampiamente `onclick=`
inline, una CSP che lo blocchi richiede un refactor dedicato, fuori scope di un fix di audit) e
`Strict-Transport-Security`.

### 9.8 / 9.9 🟡 Difesa in profondità XSS minori
- **9.8** — `esc()` in `ardy-design-app.html` non escapava l'apice (a differenza di `escHtml()`
  usato ovunque altrove); oggi non risultava sfruttabile in nessun punto d'uso, ma la trappola si
  sarebbe riaperta al primo riuso in un contesto ad apice singolo. Allineato a `escHtml()`.
- **9.9** — `ardy-gcal-auth.php` stampava il token OAuth di errore con `json_encode()` ma senza
  `htmlspecialchars()`; rischio basso (endpoint autenticato, `$token` è la risposta di Google, non
  input diretto dell'attaccante) ma incoerente col resto del codice. Aggiunto `htmlspecialchars()`.

### 9.10 / 9.11 🔵 Consistenza e riproducibilità
- **9.10** — `osmHttpGet()` (geocoding Nominatim/Overpass in `ardy-outreach-api.php`) usa un
  `curl_init()` dedicato invece di `ardySafeHttpGet()` — scelta corretta, perché quest'ultimo non
  supporta lo User-Agent identificativo richiesto dalla policy di Nominatim. Host sempre hardcoded,
  quindi non SSRF-sfruttabile oggi; aggiunta comunque `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS`
  ristretti a http/https e `CURLOPT_MAXREDIRS` per difesa in profondità sui redirect.
- **9.11** — `composer.lock` non era versionato (era in `.gitignore`): ogni `composer install` in
  un deploy fresco poteva risolvere una minor di mPDF diversa da quella testata, senza traccia in
  audit. **Fix.** Generato (`mpdf/mpdf v8.3.1` — nessun advisory di sicurezza noto, verificato con
  `composer audit`), tolto da `.gitignore` e versionato; `vendor/` resta ignorato.

### 9.12 – 9.14 🔵 Aperti (raccomandati, non applicati in questo giro)
Lasciati intenzionalmente fuori scope perché toccano flussi live (alcuni innescati da cron/n8n) che
non è prudente modificare alla cieca senza poter verificare il comportamento in produzione — a
differenza dei fix sopra, tutti validati con `php -l` e/o test isolato riproducibile.
- **9.12** — Gli endpoint AI/email costosi ma autenticati (`ardy-crea-faq`, `ardy-progetti-ai`,
  `ardy-preventivo` mode=ai, `ardy-email-cliente-api`, `ardy-solleciti`, ecc.) non hanno un
  rate-limit applicativo: se le credenziali dashboard trapelassero, si potrebbe generare in loop
  chiamate a pagamento o email di massa. Consigliato un cap orario per-endpoint, riusando lo stesso
  pattern file-based già in `ardy-save-lead.php`/`ardy-proxy.php` — da tarare endpoint per endpoint
  (alcuni sono anche raggiunti da cron/n8n con pattern di chiamata diversi dal click umano).
- **9.13** — mPDF non ha una configurazione esplicita che vieti il fetch di contenuti remoti
  (`isRemoteEnabled`/equivalenti). Oggi non sfruttabile: ogni campo utente che finisce in
  `WriteHTML()` passa da `sanitizeInput()` o da `parseImgDataUris()` (che accetta **solo**
  `data:image/...;base64,` per le immagini) — quindi nessun `<img src="http://...">` costruibile da
  input utente. Consigliato bloccare comunque il fetch remoto lato configurazione mPDF, così la
  protezione non dipende solo dalla disciplina futura di chi tocca `ardy-preventivo.php`.
- **9.14** — `ardy-progetti-ai.php` estrae testo da DOCX/ODT con `ZipArchive::getFromName()` (niente
  XXE — non usa `DOMDocument`/`SimpleXML`, positivo). Un archivio con una entry fortemente
  compressa potrebbe causare un picco di memoria (DoS locale). Endpoint autenticato e file limitato
  a 20 MB in ingresso: rischio basso. Consigliato un limite esplicito sulla dimensione decompressa
  prima di leggere l'entry.
