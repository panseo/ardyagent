# Security Audit — ardyagent

**Data:** 2026-06-20 (due giri di audit nella stessa giornata)
**Ambito:** intero progetto (60+ file PHP, JS, `.htaccess`, config, deploy, snippet WordPress, n8n)
**Esito:** codebase complessivamente solida; chiusi 4 gruppi di vulnerabilità (1 critico, 1 medio
auth + 1 medio XSS + bassi), tutti verificati in produzione. Ruotato il token di verifica del webhook.

> **Struttura del documento:** §1–4 = primo giro (auth + hardening). §5 = secondo giro
> (XSS, token webhook, password). §6 = rotazione `WA_VERIFY_TOKEN`. §7 = aree pulite senza rilievi nel 2° giro.

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
