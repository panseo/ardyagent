# Security Audit — ardyagent

**Data:** 2026-06-20
**Ambito:** intero progetto (60+ file PHP, JS, `.htaccess`, config, deploy)
**Esito:** codebase complessivamente solida; chiusi 2 gruppi di vulnerabilità (1 critico, 1 medio), verificati in produzione.

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
