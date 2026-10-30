# Ardy Agent — istruzioni per Claude

Gestionale di Ardy Lab (bottega di restauro, Roma): PHP 8.3 + MySQL su hosting cPanel,
nessun framework. Ogni endpoint è un file `ardy-*.php`, le interfacce sono file HTML con JS
inline. L'AI (assistente "Sole") è Claude via REST diretta.

Per orientarti: `HANDOFF.md` (stato e scelte), `README.md` (com'è fatto, schema DB),
`TODO-PROSSIMI-TASK.md` (coda lavori), `MANUALE-SOLE.md` (regole dell'assistente),
`SECURITY-AUDIT.md` (audit precedenti e rilievi ancora aperti).

## Lingua e stile

- Codice commentato, documentazione, messaggi di commit e risposte all'utente: **in italiano**.
- I commenti spiegano il *perché*, non il *cosa*. I commit raccontano il problema prima della soluzione.
- **Ogni modifica aggiorna la documentazione**: `README.md` per com'è fatto, `TODO-PROSSIMI-TASK.md`
  per quel che resta. Non è opzionale: è la memoria del progetto.

## Deploy e verifica

- Non c'è CI né test automatici. L'unico controllo è `php -l` dentro `deploy.sh`: prima di
  committare lancia `php -l` su ogni `.php` toccato. Nel messaggio di commit scrivi cosa hai
  verificato e come.
- `deploy.sh` usa `rsync` **senza** `--delete` e **non legge `.gitignore`**: un file nuovo che non
  deve finire in `public_html` (log, dati, strumenti locali) va aggiunto anche alle sue `--exclude`.
- `.cpanel.yml` (pulsante cPanel) copia per estensione e **non** esegue la migrazione DB.
- Il server fa `git pull origin main`: si lavora su rami e si fa merge su `main` via PR.

## Database

- Lo schema vive **solo** in `ardy-migrate.php` (idempotente: `colExists`, `IF NOT EXISTS`).
  Mai DDL inline negli endpoint. Tabella/colonna nuova → `ardy-migrate.php` + sezione DB del README.
- `clienti.sopralluogo_at`/`gcal_event_id` sono un *mirror* del sopralluogo più vicino della
  tabella `sopralluoghi`: si aggiornano tramite `ardy-sopralluoghi-lib.php`, non a mano.

## Sicurezza (invarianti)

- Endpoint nuovo dell'area riservata → aggiungilo alla regex Basic Auth `FilesMatch` in `.htaccess`
  **e** chiama `ardyRequireAuth()` (`ardy-auth.php`) prima di ogni side-effect. Servono entrambi.
- Libreria interna non chiamabile da web → aggiungila al `FilesMatch` "Deny" in `.htaccess`.
- Guard a segreto condiviso (n8n, cron): sempre **fail-closed** se la costante manca, confronto con `hash_equals`.
- URL forniti dall'utente: solo via `ardyValidatePublicUrl()`/`ardySafeHttpGet()` (anti-SSRF).
- Nel frontend: `escHtml()` per HTML, `escJs()` per stringhe dentro attributi `onclick=`.
- Mai inviare `getMessage()` di eccezioni al client.
- Credenziali in `ardy-config.php`, `.htpasswd`, token OAuth: **non versionati**, non crearli né
  stamparli. Le credenziali Meta stanno in n8n.

## Vincoli di prodotto da non "risolvere"

- **Niente DM automatici** su Instagram/Facebook/LinkedIn: le API non lo permettono e
  l'automazione non ufficiale fa bannare l'account business che pubblica anche via n8n. Il flusso
  social è assistito: l'AI scrive la bozza, una persona invia.
- **Gate di costo in `ardy-enrich.php`** (`$agentePuoPartire`) e **passo 1c**: sembrano ridondanti,
  non lo sono. Vedi `HANDOFF.md` §5 prima di toccarli.
- L'arricchimento singolo **propone** (valore/fonte/confidenza), non scrive.
- Il server MCP (`ardy-mcp/`) non espone di proposito invio campagne né cancellazioni.
- La pubblicazione social **non** parte in automatico da `ardy-pubblica-lavorazione.php`.
- Michela è in regime forfettario: preventivi sempre con IVA 0% e dicitura legale.

## `ardy-mcp/`

Server MCP Node/TypeScript che gira sul desktop, **non** sul server (escluso da `deploy.sh`).
Build: `cd ardy-mcp && npm install && npm run build`. Quando cambia un'azione di
`ardy-outreach-api.php`, verifica se il tool MCP corrispondente va allineato.
