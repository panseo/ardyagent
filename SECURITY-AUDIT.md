# Security Audit — ardyagent

**Date:** 2026-06-20 (primi due giri) · 2026-07-04 (terzo giro — difesa in profondità sui dati utente)
**Ambito:** intero progetto (60+ file PHP, JS, `.htaccess`, config, deploy, snippet WordPress, n8n)
**Esito:** codebase complessivamente solida; chiusi 4 gruppi di vulnerabilità nei primi due giri
(1 critico, 1 medio auth + 1 medio XSS + bassi), tutti verificati in produzione, con rotazione del
token di verifica del webhook. Il terzo giro (§8) ha esteso la **difesa in profondità** a tutti gli
endpoint dell'area riservata e completato i fix fail-closed sugli endpoint che leggono PII.

> **Struttura del documento:** §1–4 = primo giro (auth + hardening). §5 = secondo giro
> (XSS, token webhook, password). §6 = rotazione `WA_VERIFY_TOKEN`. §7 = aree pulite senza rilievi nel 2° giro.
> §8 = terzo giro (2026-07-04): `ardyRequireAuth()` esteso, fail-closed su `wa-lookup`/`wa-memoria`,
> guard su `ardy-migrate`, hardening `verify-client`.

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
