# Emergenza e ripresa — stato al 17/08/2026 (sera)

Documento scritto di corsa, mentre **un'organizzazione Anthropic** collegata al login Google
`a.panse@gmail.com` risulta di nuovo bloccata — stavolta in modo più serio del blocco del 15/08
(quello si era risolto da solo col supporto, vedi §6). Serve a due cose: rimettere in piedi la
situazione se va storto per davvero, e permettere a chiunque (o a una sessione Claude nuova) di
riprendere il lavoro da dove è rimasto.

⚠️ **Non è un blocco dell'account Google/claude.ai della persona**: lo stesso login
`a.panse@gmail.com` naviga claude.ai da browser normalmente, senza avvisi. Il problema riguarda
**una specifica organizzazione** raggiungibile con quel login — quella usata da Claude Code sul
computer di casa per il lavoro su Ardy Lab, che ha `a.panseo@protonmail.com` **come email di
recupero registrata**, non come credenziale di accesso: con quell'indirizzo non ci si logga da
nessuna parte, è solo il contatto di recupero sul file dell'organizzazione. Dettagli in §1.

Scritto in italiano piano, non in gergo: va bene leggerlo fra sei mesi o girarlo al supporto.

> Questo file **sostituisce di nuovo** `HANDOFF.md` come punto di partenza finché l'emergenza
> non è chiusa. `HANDOFF.md` resta valido per tutto il resto (architettura, invarianti,
> runbook) e non va duplicato qui: questo documento racconta solo l'emergenza e lo stato
> minimo per ripartire.

---

## 1. 🔴 URGENTE — un'organizzazione Anthropic bloccata, seconda volta

### Cronologia

- **15/08**: durante un login a Claude Code si mescolano due account Google; l'organizzazione
  finisce in coda di cancellazione ("*Your organization is scheduled for deletion*"). Sintomo:
  `401 OAuth access token has expired` su ogni richiesta, `/login` non basta.
- **16–17/08**: il supporto Anthropic risolve, l'organizzazione torna attiva. Documentato in
  `HANDOFF.md` §7 come chiuso.
- **17/08, sera**: si riprova ad accedere da Claude Code (per collaudare l'allineamento MCP di
  oggi, PR #122) e **ricompare lo stesso banner** *"Your organization is scheduled for
  deletion"* sulla pagina di login.
- Contattato di nuovo il supporto in chat. Stavolta la risposta è di **chiusura**, non di
  presa in carico: un account può essere sospeso per violazioni della Politica di utilizzo,
  violazioni dei Termini di servizio, o creazione da una posizione non supportata. Il supporto
  **non conferma quale motivo si applica** e **non può revocare la sospensione dalla chat**:
  l'unica via indicata è il modulo di ricorso su `claude.ai/restricted`, gestito dal team
  Trust & Safety / Safeguards. Ulteriori messaggi alla chat di supporto potrebbero restare
  senza risposta.
- **Il modulo di ricorso non si apre**: `claude.ai/restricted` reindirizza altrove invece di
  mostrare il form (verificato anche da qui: fetch diretto della pagina risponde 403).
- **Precisazione importante, emersa in più passaggi dopo aver mandato la mail al supporto** —
  la mail conteneva un quadro impreciso, corretto qui. Sono coinvolte **tre organizzazioni
  Anthropic**, non una:

  | Organizzazione | Come si accede | Stato al 17/08 sera | A cosa serve |
  |---|---|---|---|
  | "Principale" | login Google `a.panse@gmail.com`, da browser su claude.ai | ✅ regolare, nessun avviso | uso normale di claude.ai |
  | **Ardy Lab / Claude Code** | stesso login Google `a.panse@gmail.com`, ma da **Claude Code CLI** sul computer Debian | 🔴 **bloccata** ("*scheduled for deletion*") | far girare l'MCP e i tool `ardy_*` dal desktop |
  | Console (API billing) | login/carta con indirizzo `a.panseo`, separata dalle altre due | ✅ regolare, nessun avviso (verificato il 17/08) | genera/paga `ARDY_API_KEY`, la chiave usata **in produzione** sul server da tutte le funzioni AI di Ardy Lab (§2) |

  Punti chiave:
  - **È lo stesso login Google** (`a.panse@gmail.com`) sia per l'organizzazione "principale"
    (che funziona) sia per quella bloccata: non sono due account diversi, è lo stesso login che
    risolve verso organizzazioni diverse a seconda del contesto (browser vs Claude Code CLI).
  - `a.panseo@protonmail.com` è solo l'**email di recupero** registrata sull'organizzazione
    Ardy Lab/Claude Code — non è mai stata una credenziale di login.
  - **La Console è una terza organizzazione**, separata anche da quella bloccata, e **funziona
    regolarmente**: quindi `ARDY_API_KEY` e tutta la produzione (§2) **non sono a rischio** da
    questo blocco. L'unico impatto reale è non poter usare Claude Code (e quindi l'MCP) dal
    computer di casa.
  - La mail già inviata al supporto parlava di "the Claude account a.panseo@protonmail.com"
    come se fosse l'identità di accesso, e lasciava intendere che la produzione fosse a rischio:
    entrambe le cose sono imprecise. Un eventuale follow-up va impostato così: stesso Google
    login di sempre, organizzazione "principale" e Console regolari, **solo l'organizzazione
    Ardy Lab/Claude Code (email di recupero a.panseo@protonmail.com) risulta disabilitata** —
    sembra un problema di org-selection lato Anthropic più che una sospensione per policy,
    visto che le altre due organizzazioni sullo stesso profilo non hanno alcun avviso.

### Cosa fare, in ordine

1. Riprovare `claude.ai/restricted` **in una finestra incognito, senza nessun account Claude
   già loggato nel browser**, incollando l'URL a mano nella barra degli indirizzi (non da un
   link cliccato). Il pasticcio del 15/08 nasceva proprio da due login Google sovrapposti:
   una sessione ambigua nel browser può far ripiegare il form su un redirect invece che
   mostrarsi.
2. Se continua a reindirizzare, provare da **un altro dispositivo o rete** (es. telefono con
   dati mobili), per escludere cache/cookie locali.
3. Cercare su **support.claude.com** l'articolo su account sospesi/ricorso: il link diretto
   può cambiare, l'articolo di supporto di solito resta aggiornato.
4. Se il form si apre: compilarlo restando loggati con `a.panse@gmail.com` (è l'unico login che
   esiste), specificando che l'organizzazione colpita è quella con email di recupero
   `a.panseo@protonmail.com`, usata per collegare Claude Code al gestionale Ardy Lab dal
   computer di casa (non genera direttamente `ARDY_API_KEY`, quella è su una Console separata
   e non risulta toccata — vedi tabella sopra).
5. **Non ha aiutato** (già escluso): variabile `ANTHROPIC_API_KEY` spuria sul computer di casa
   (verificata assente), `/logout` + nuovo login, `claude auth login`, `claude doctor`. Nessuno
   di questi comandi tocca lo stato dell'organizzazione: il problema è sul lato Anthropic, non
   locale.

⏳ Come il 15/08, è ragionevole assumere che ci sia una finestra di tempo prima che una
sospensione diventi definitiva. Non aspettare: seguire i punti sopra appena possibile.

---

## 2. Cosa si ferma se la chiave API smette di funzionare

> ✅ **Al 17/08 sera non sta succedendo**: `ARDY_API_KEY` viene dalla Console Anthropic, che è
> un'organizzazione separata da quella bloccata (vedi tabella in §1) e risulta regolare. Questa
> sezione resta come riferimento — cosa succederebbe se in futuro la Console avesse lo stesso
> problema, o se andasse rigenerata la chiave per un altro motivo — non come descrizione dello
> stato attuale.

Ardy usa una chiave Anthropic (`ARDY_API_KEY`, in `ardy-config.php`, non versionato) per
**tutte** le funzioni AI. Se la chiave smettesse di funzionare, **18 file** si fermerebbero.

**Si ferma:**

| Funzione | File |
|---|---|
| Sole in webchat e su WhatsApp | `ardy-proxy.php`, `ardy-wa-agent.php` |
| Chat lavorazione cliente | `ardy-proxy-lavorazione.php` |
| Outreach: arricchimento, canali social, generazione messaggi e template | `ardy-outreach-api.php`, `ardy-enrich.php` |
| Caption social e pubblicazione | `ardy-genera-social.php`, `ardy-pubblica-lavorazione.php`, `ardy-pubblica-progetto.php` |
| Preventivi, FAQ, reel, estrazione PDF | `ardy-preventivo.php`, `ardy-crea-faq.php`, `ardy-crea-reel.php`, `ardy-estrai-preventivo-pdf.php`, `ardy-import-scheda-pdf.php` |
| Solleciti, grazie-consegna, conoscenza appresa | `ardy-solleciti.php`, `ardy-grazie-consegna.php`, `ardy-conoscenza-appresa.php` |
| Progetti AI, object proxy, unsubscribe | `ardy-progetti-ai.php`, `ardy-object-proxy.php`, `ardy-unsubscribe.php` |

**NON si ferma** (non passa da Anthropic):

- Il sito e la dashboard: si aprono e si navigano normalmente.
- Il database: clienti, contatti, preventivi, progetti — tutto intatto.
- L'invio email via **Brevo** e le lettere cartacee.
- WhatsApp Cloud API per i messaggi **template** (Meta, non Anthropic).
- Pubblicazione su Facebook/Instagram via n8n (Meta).
- Ricerca contatti su OpenStreetMap e Google Places, incluso `ardy-places-prova.php`.
- L'**estrazione B&B da bed-and-breakfast.it** (`ardy-portale-bb.php`): legge JSON-LD, niente AI.
- Lo **scraping gratuito del sito** che trova i canali social dalle icone: è codice PHP puro.

### Se serve rimettere in moto l'AI in fretta

Basta una chiave Anthropic valida, **anche di un'altra organizzazione**: si sostituisce il
valore di `ARDY_API_KEY` in `ardy-config.php` sul server e tutto riparte. Il file **non è nel
repository** (è in `.gitignore`), quindi va modificato direttamente sul server e **non viene
toccato dal deploy**.

---

## 3. Cosa NON si perde, in ogni caso

- **Il codice sta su GitHub**, in `panseo/ardyagent`, ramo `main`. Ultimi PR mergiati: #121
  (documento `HANDOFF.md`) e **#122, di oggi** (allineamento del server MCP allo scope
  `'google'` e all'import da bed-and-breakfast.it). Nessuno dei due dipende dall'account
  Anthropic per esistere — dipende però da un account **funzionante** per essere collaudato
  dal vivo, il che è esattamente ciò che il blocco impedisce.
- **Il database e i file** stanno sul server OVH/cPanel, non c'entrano niente con Anthropic.
- **La dashboard funziona**, AI compresa: outreach, import B&B, prova di copertura Places sono
  già online, e `ARDY_API_KEY` gira su una Console Anthropic **separata** dall'organizzazione
  bloccata — verificata regolare il 17/08 (§1).
- **Il server MCP** sul computer `bebo` è stato ricompilato oggi con i tool nuovi (13 in
  totale) e la build è pulita. Aspetta lì: quando l'account torna a posto, riprende a
  funzionare senza rifare nulla — **ma non è mai stata completata una chiamata vera**, né col
  blocco di agosto né con questo (vedi §4).

---

## 4. Stato esatto delle cose

### Su GitHub (`panseo/ardyagent`, ramo `main`)

| Commit | Cosa |
|---|---|
| `10af1ae` | Merge PR #122 — MCP allineato (scope `google`, `ardy_estrai_portale_bb`, `ardy_prova_copertura_places`) |
| `af5ecf2` | PR #121 — `HANDOFF.md`, documento di passaggio consegne dopo filone A+B |
| `23cf4a7` | Fine del filone import B&B / usabilità filtri (8 commit) |
| `ee9fbb7` | PR #120 — server MCP `ardy-mcp/` (versione iniziale, 11 tool) |
| `27beb46` | PR #119 — bottone "Trova solo i canali" |
| `941ee90` | PR #118 — scoperta canali social + messaggio assistito |

### Sul server (`ardyagent.ardy-lab.it`)

- Non verificato **da questa sessione** (nessun accesso SSH da qui) se il deploy è stato
  rifatto dopo `af5ecf2`/`10af1ae`. Le modifiche di quei due commit toccano solo documentazione
  e `ardy-mcp/` (escluso dal deploy) — **non c'è PHP nuovo da deployare per PR #122**, quindi
  il server non ha bisogno di un pull per questa parte specifica.
- Se serve verificare comunque: `cd ~/repositories/ardyagent && git log -1` sul server, come
  utente `micoperibg`.

### Sul computer di casa (`bebo@bebo`, Debian)

- Node **v18.20.4**.
- `ardy-mcp/` aggiornato e ricompilato oggi (`npm install && npm run build`, pulito — un solo
  warning `EBADENGINE` su `@hono/node-server` che vuole Node ≥20, dipendenza transitiva
  dell'SDK MCP, non bloccante).
- `claude mcp add ardy -s user ...` già fatto in una sessione precedente (vedi §5.3 per
  rifarlo da capo).
- ⚠️ **Non è mai stata provata una chiamata vera** ai tool MCP, né ad agosto né oggi: il
  blocco dell'account Anthropic è arrivato entrambe le volte prima di poter testare il Basic
  Auth verso `ardy-lab.it`. Resta la prima cosa da fare appena l'account torna operativo —
  vedi la checklist in `TODO-PROSSIMI-TASK.md` §*Ardy dal desktop*.

---

## 5. Come rifare tutto da zero

### 5.1 Deploy sul server

```bash
# sul server, come utente micoperibg
cd ~/repositories/ardyagent
git pull origin main
./deploy.sh
```

`deploy.sh` fa tre cose in ordine: controlla la sintassi PHP di tutti i file (e si ferma se
qualcosa è rotto), copia in `public_html`, poi lancia **automaticamente** `ardy-migrate.php`.

⚠️ Se invece si usa il pulsante *Deploy HEAD Commit* di cPanel: quello copia i file ma **non**
esegue la migrazione. In quel caso va aperto `ardy-migrate.php` nel browser.

Cosa **non** viene toccato dal deploy (e quindi non si perde mai): `ardy-config.php`,
`.htpasswd`, `ardy-uploads/`, `preventivi_pdf/`, `reels/`, `vendor/`, e `ardy-mcp/`.

### 5.2 Ricompilare il server MCP sul desktop

```bash
cd ~/ardyagent
git pull origin main
cd ardy-mcp
npm install
npm run build
```

Serve **Node 18 o superiore** (`node -v` per controllare).

### 5.3 Ricollegare il server MCP a Claude Code

```bash
claude mcp add ardy -s user \
  -e ARDY_API_URL=https://ardyagent.ardy-lab.it \
  -e ARDY_USER='<utente>' \
  -e ARDY_PASS='<password>' \
  -- node /home/bebo/ardyagent/ardy-mcp/dist/index.js
```

Gli **apici singoli** attorno a utente e password sono importanti: senza, la shell si mangia i
caratteri speciali (`$`, `!`, `&`, spazi) e si finisce con un 401 incomprensibile.

Verifica: `claude mcp list` (da terminale) o `/mcp` (dentro una sessione Claude Code già
avviata) → deve dire `✔ Connected`.

Per rifarlo da capo: `claude mcp remove ardy -s user`, poi di nuovo il comando sopra.

### 5.4 Le credenziali del Basic Auth

- **Utente**: leggibile sul server con
  `cut -d: -f1 /home/micoperibg/public_html/ardyagent.ardy-lab.it/.htpasswd`
- **Password**: **non recuperabile**, è un hash bcrypt. Se persa, va trovata nel gestore
  password del browser, oppure **cambiata** — e cambiandola cambia anche l'accesso alla
  dashboard. Lo strumento `ardy-setup-login.php` si auto-disabilita (403) finché `.htpasswd`
  esiste, quindi per rigenerarla va prima rinominato quel file.

---

## 6. Distinguere gli errori di autenticazione — sono TRE, non due

| Cosa vedi | Dove | Chi litiga con chi | Cosa fare |
|---|---|---|---|
| `API Error: 401 OAuth access token has expired. Re-authenticate to continue.` | terminale, dentro Claude Code | Claude Code ↔ **Anthropic**, token scaduto | `/login` |
| Pagina di login browser: *"Your organization is scheduled for deletion"* o banner *"Your organization is scheduled for deletion. Contact support..."* | browser, durante `/login` o `claude auth login` | Claude Code ↔ **Anthropic**, ma è l'**organizzazione**, non il token | il `/login` da solo non basta: serve il supporto (§1) |
| `Autenticazione rifiutata (401). Controlla ARDY_USER e ARDY_PASS` | risposta dei tool `ardy_*` dentro una sessione Claude | server MCP ↔ **ardy-lab.it** | rifare `claude mcp add` con le credenziali giuste |

Il terzo è scritto in italiano apposta, per riconoscerlo a colpo d'occhio. Il secondo è quello
che sta bloccando tutto in questo momento — ma solo per l'organizzazione legata al login di
Claude Code sul terminale Debian, **non** per l'account claude.ai usato normalmente da browser
(`a.panse@gmail.com`), che resta senza avvisi (vedi §1).

---

## 7. Per il resto

- **Cosa è stato costruito e cosa resta aperto**: `HANDOFF.md` (aggiornato oggi con
  l'allineamento MCP) e `TODO-PROSSIMI-TASK.md` §*Ardy dal desktop*.
- **Come funziona il progetto in generale**: `README.md`.
- Questo file **non li sostituisce**: li tiene raggiungibili se il primo problema da risolvere,
  entrando da zero, è "perché Claude Code non risponde".

---

## 8. Dove stanno le cose (nessun segreto qui dentro)

| Cosa | Dove |
|---|---|
| Chiavi API, password DB, token WhatsApp | `ardy-config.php` sul server — **non versionato**, non toccato dal deploy |
| Utente/password dashboard | `.htpasswd` nel document root (password in hash) |
| Credenziali MCP | configurazione locale di Claude Code sul computer `bebo` |
| Credenziali Meta per pubblicare su FB/IG | dentro **n8n** (`n8n.ardy-lab.it`), non nel repository |
| Documentazione completa | `README.md`, `HANDOFF.md`, `TODO-PROSSIMI-TASK.md` |
| Guida per Michela sull'outreach | `ardy-guida-outreach.html` |

---

*Se stai leggendo questo file dopo un disastro: quasi tutto è più recuperabile di quanto
sembra. Il codice è su GitHub, i dati sono sul server, e le due cose non dipendono l'una
dall'altra. La sola cosa con una scadenza vera è il ricorso sull'account Anthropic del §1 —
parti da lì, e se il modulo di ricorso non si apre, non insistere sulla chat di supporto:
hanno detto esplicitamente che non risponde più su questo.*
