# Audit di sicurezza — Sistema Ardy Agent

**Data:** 2026-07-04
**Ambito:** applicazione PHP in `ardyagent.ardy-lab.it`

> ⚠️ **Stato fix (aggiornamento):** i problemi #1–#4 sono stati **risolti** nello stesso branch.
> - **#2** `ardyRequireAuth()` aggiunto a tutti gli endpoint riservati (≈37 file). Nei file dual-use (`ardy-dossier`, `ardy-grazie-consegna`, `ardy-conoscenza-appresa`, `ardy-trasporti`) il guard scatta **solo** nel ramo endpoint diretto, non quando sono inclusi come libreria dal proxy/webhook. `ardy-email-finder` resta libero da CLI (uso previsto) ma protetto via web.
> - **#3** `ardy-wa-lookup.php` e `ardy-wa-memoria.php` resi **fail-closed** sul segreto.
> - **#1** `ardy-migrate.php` ora eseguibile solo da CLI o con segreto condiviso.
> - **#4** rimossa l'esposizione di `getMessage()` in `ardy-archivia-persi` e `ardy-chiusura-sessioni`.
> - **#5 / #6 / #7** (verifica cliente, token/config fuori web-root, retention) restano **aperti**: richiedono scelte operative/infrastrutturali, non solo codice.
 (endpoint API, proxy AI, webhook WhatsApp, dashboard riservata) con particolare attenzione al trattamento dei **dati degli utenti** (clienti/lead: nome, telefono, email, indirizzo, conversazioni).

> Nota di metodo: l'audit è statico (lettura del codice del repo). Non ho eseguito test dinamici sul server di produzione. Le priorità sono indicative; la verifica sul campo va fatta su `ardyagent.ardy-lab.it`.

---

## Sintesi

L'impianto di sicurezza è **complessivamente buono e ben ragionato**: query parametrizzate ovunque, protezione SSRF, anti-spoofing dell'IP, verifica firma HMAC sul webhook WhatsApp, hardening delle cartelle di upload, segreti fuori dal repo, rate limiting sugli endpoint pubblici. I commenti nel codice mostrano che molte scelte di sicurezza sono state fatte consapevolmente.

Le criticità principali non sono errori grossolani ma **incoerenze nell'applicazione delle difese**: la stessa protezione è presente in alcuni file e assente in altri equivalenti. Sono queste incoerenze a creare i rischi maggiori sui dati dei clienti.

| # | Gravità | Problema |
|---|---------|----------|
| 1 | 🟠 Media | `ardy-migrate.php` eseguibile pubblicamente senza autenticazione |
| 2 | 🟠 Media | Guard `ardyRequireAuth()` (difesa in profondità) applicato in modo incoerente sugli endpoint PII |
| 3 | 🟠 Media | Segreto WhatsApp "opzionale": PII esposta se la costante non è configurata |
| 4 | 🟡 Bassa | Messaggi di errore che espongono dettagli interni (DB/schema) |
| 5 | 🟡 Bassa | Verifica cliente basata sulle ultime 7 cifre del telefono (enumerabile) |
| 6 | 🔵 Info | Token OAuth Google in chiaro nella web-root |
| 7 | 🔵 Info | Rate-limit "fail-open" per scelta; CSRF; retention dati |

---

## Punti di forza (da mantenere)

- **SQL injection:** tutte le query sensibili usano PDO con prepared statement e binding. I pochi punti con SQL dinamico (`ardy-progetti-api.php`, `ardy-outreach-api.php`, `ardy-conoscenza-appresa.php`, `ardy-elimina-cliente.php`) costruiscono solo **placeholder di conteggio fisso** o fanno **cast a intero** — nessuna concatenazione di stringhe utente. ✅
- **SSRF:** `ardyValidatePublicUrl()` in `ardy-net.php` valida schema/host, blocca gli IP privati (incl. `169.254.169.254`) e **ri-valida ad ogni redirect**. ✅
- **Anti-spoofing IP:** `ardyClientIp()` si fida di `CF-Connecting-IP`/`X-Forwarded-For` **solo** se la connessione arriva da un edge Cloudflare noto, altrimenti usa `REMOTE_ADDR`. ✅
- **Webhook WhatsApp:** verifica **obbligatoria** della firma `X-Hub-Signature-256` (HMAC con `WA_APP_SECRET`), fail-closed se il secret manca; token di verifica senza fallback hardcoded. ✅
- **Upload:** whitelist di estensioni + sniffing MIME reale con `finfo`, `.htaccess` con `RemoveHandler`/`Deny` sugli script nelle cartelle di upload (`ardyHardenUploadDir`), nomi su disco randomizzati. ✅
- **Segreti:** `ardy-config.php`, `ardy-gcal-token.json`, CSV di import sono gitignored; **nessuna chiave API hardcoded** trovata nel repo. ✅
- **Password:** `ardy-setup-login.php` usa `password_hash()` bcrypt, minimo 8 caratteri, e si **auto-disabilita** (403) una volta creato il `.htpasswd`. ✅
- **Privacy nei log:** il webhook WhatsApp **maschera il numero** (ultime 4 cifre) e non logga nome/testo. ✅
- **Disiscrizione:** `ardy-unsubscribe.php` protegge con token HMAC per-email (non si può disiscrivere l'indirizzo altrui). ✅

---

## Dettaglio delle criticità

### 1. 🟠 `ardy-migrate.php` eseguibile pubblicamente

`ardy-migrate.php` non ha **alcun controllo di accesso**: nessun guard CLI (`php_sapi_name()`), nessun segreto, e **non è incluso** né nel blocco Basic Auth né nel blocco "file interni" dell'`.htaccess`. Il file esegue i DDL **direttamente all'inclusione** (nessun `REQUEST_METHOD`), quindi una semplice GET a
`https://ardyagent.ardy-lab.it/ardy-migrate.php`
lancia le migrazioni e stampa l'output. Su errori non previsti (diversi da 1050/1060) stampa `$e->getMessage()`, con **divulgazione di dettagli su schema/DB**.

Impatto: le operazioni sono idempotenti (danno limitato), ma restano esecuzione non autorizzata di operazioni sullo schema e information disclosure.

**Rimedio:** aggiungere in testa un guard, es.
```php
if (php_sapi_name() !== 'cli') {
    $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
    if (!defined('WA_LOOKUP_SECRET') || !hash_equals(WA_LOOKUP_SECRET, (string)$sent)) {
        http_response_code(403); exit('Non autorizzato');
    }
}
```
e/o inserire `ardy-migrate.php` nel `FilesMatch` degli script interni dell'`.htaccess`. Non stampare mai `getMessage()` verso il client.

---

### 2. 🟠 Guard di difesa in profondità applicato in modo incoerente

`ardy-auth.php` è nato **esattamente** per il caso in cui il Basic Auth dell'`.htaccess` non venisse applicato (deploy errato, `FilesMatch` che non combacia, file servito da un'altra path): in quel caso `ardyRequireAuth()` rifiuta comunque. Ma il guard è chiamato **solo in ~10 endpoint**. Endpoint che espongono i dati più sensibili dei clienti **non lo chiamano**:

| Endpoint senza `ardyRequireAuth()` | Dato esposto se il Basic Auth non scatta |
|---|---|
| `ardy-crm-api.php` | **Lista completa clienti** (nome, telefono, email, indirizzo, note) |
| `ardy-dossier.php` | **Dossier completo** cliente: anagrafica + preventivi + chat WA + chat web |
| `ardy-update-lead.php` | Modifica dati cliente |
| `ardy-preventivo.php` | Preventivi (PII + importi) |
| `ardy-conversazioni.php` / `ardy-crea-faq.php` | Conversazioni |
| `ardy-stats.php`, `ardy-libreria-api.php`, `ardy-progetti-api.php`, `ardy-sopralluoghi-api.php`, `ardy-trasporti.php`, `ardy-email-cliente-api.php` (parziale) | Dati operativi/clienti |

Oggi questi file dipendono da **un solo strato** (la regex `FilesMatch` dell'`.htaccess`). Un errore in quella regex o nel deploy renderebbe **l'intero CRM leggibile da chiunque**.

**Rimedio:** aggiungere `require_once __DIR__.'/ardy-auth.php'; ardyRequireAuth();` in **tutti** gli endpoint dell'area riservata (è lo stesso pattern già usato in `ardy-solleciti.php`, `ardy-outreach-api.php`, `ardy-import-*`). Costo minimo, elimina il single point of failure.

---

### 3. 🟠 Segreto WhatsApp "opzionale" → PII pubblica se non configurato

`ardy-wa-lookup.php` e `ardy-wa-memoria.php` applicano il controllo del segreto **solo se la costante esiste**:
```php
if (defined('WA_LOOKUP_SECRET') && WA_LOOKUP_SECRET !== '') { ...verifica... }
```
Se `WA_LOOKUP_SECRET` non fosse definito o fosse vuoto in `ardy-config.php`, **entrambi gli endpoint diventano completamente aperti**:
- `ardy-wa-lookup.php`: dato un numero, restituisce **identità e contesto del cliente** (nome, cognome, servizio, stato).
- `ardy-wa-memoria.php`: dato un numero, restituisce **l'intero storico della conversazione**.

È un'incoerenza con `ardy-wa-crea-scheda.php`, che sullo stesso segreto è **fail-closed** (rifiuta se non configurato, commento esplicito "niente write aperti").

**Rimedio:** rendere fail-closed anche lookup e memoria — se `WA_LOOKUP_SECRET` non è configurato, rispondere 500/403 e non restituire dati. Questi endpoint leggono PII: non devono mai degradare in "aperto".

---

### 4. 🟡 Divulgazione di messaggi di errore

Alcuni endpoint rimandano al client `$e->getMessage()`:
- `ardy-migrate.php:35`
- `ardy-archivia-persi.php:129` (`'Errore: ' . $e->getMessage()`)
- `ardy-chiusura-sessioni.php:125,162`

Espone dettagli su query/schema/DB. La maggior parte del codice fa già la cosa giusta (`error_log()` + messaggio generico al client, es. `ardy-save-lead.php`, `ardy-verify-client.php`).

**Rimedio:** uniformare: dettaglio in `error_log`, al client solo un messaggio generico.

---

### 5. 🟡 Verifica cliente sulle ultime 7 cifre del telefono

`ardy-verify-client.php` autorizza un cliente a vedere la propria pagina di lavorazione confrontando le **ultime 7 cifre** del telefono con `wp_post_id`. È un identificatore relativamente **enumerabile/indovinabile**, e il rate limit associato è **fail-open** (20 tentativi/10 min per IP, e se la scrittura del file di conteggio fallisce **non blocca**). Esiste già un'alternativa più robusta: il **codice di accesso** `ARD-XXXX-XXXX`.

**Rimedio:** preferire il codice di accesso dove possibile; in subordine, alzare le cifre confrontate o abbassare la soglia del rate limit. Valutare un lockout più stringente sul ramo "telefono".

---

### 6. 🔵 Token OAuth Google nella web-root

`ardy-gcal-token.json` (con `refresh_token` Google: Calendar + Gmail) sta nella document root. Oggi è protetto da `.gitignore` + regola `.htaccess` che nega i `.json`. È lo **stesso single point of failure** del punto 2: se quella regola `.htaccess` non venisse applicata, il refresh token — che dà **accesso persistente a Gmail e Calendar** — sarebbe scaricabile.

**Rimedio:** spostare il file **fuori dalla web-root** (es. una cartella sopra `public_html`) e referenziarlo via path assoluto. Vale anche per `ardy-config.php`.

---

### 7. 🔵 Note trasversali (info)

- **Rate-limit fail-open:** per scelta esplicita (non bloccare i clienti veri), ma sotto pressione disco le protezioni anti-abuso svaniscono. Trade-off accettabile, da documentare/monitorare.
- **CSRF:** l'area riservata è dietro Basic Auth (credenziali inviate automaticamente dal browser). Il rischio è **mitigato** perché gli endpoint di scrittura accettano solo `Content-Type: application/json` (che forza un preflight CORS che fallisce cross-origin). Da confermare che **nessun** endpoint di scrittura accetti form-encoded/GET.
- **Dati personali / GDPR:** PII salvata in `clienti`, `wa_messaggi`, `web_messaggi`; `ardy-dossier.php` la aggrega tutta; i CSV di import contengono PII (gitignored). Esistono già disiscrizione (`ardy-unsubscribe`) e cancellazione (`ardy-elimina-cliente`) — buona base. Manca una **policy di retention** esplicita e non risulta cifratura at-rest oltre a quella eventuale del DB. Da valutare in ottica GDPR (minimizzazione + retention).

---

## Priorità consigliata

1. **Subito (bassa fatica, alto impatto):** #2 (aggiungere `ardyRequireAuth()` a tutti gli endpoint riservati) e #3 (fail-closed su wa-lookup/wa-memoria). Chiudono i due scenari in cui i dati dei clienti diventano pubblici.
2. **A breve:** #1 (guard su `ardy-migrate.php`) e #4 (non esporre `getMessage()`).
3. **Quando possibile:** #6 (token/config fuori web-root), #5 (verifica cliente), #7 (retention/CSRF review).
