# Handoff — Ardy Outreach, stato al 17/08/2026

Documento di passaggio di consegne. Serve a far ripartire una sessione nuova (o una persona)
senza rileggersi mesi di chat: cosa c'è, perché è fatto così, cosa non va toccato e cosa
resta da fare.

> **Per una sessione nuova**: leggi questo file, poi `README.md` §*Ardy Outreach* per il
> dettaglio e `TODO-PROSSIMI-TASK.md` per la coda. Il codice sta su GitHub in
> `panseo/ardyagent`, ramo `main`.

---

## 1. Cos'è il progetto, in breve

**Ardy Lab** è il gestionale di una bottega di restauro di Roma (fondatrice Michela Panella).
PHP 8 + MySQL su hosting cPanel, nessun framework: ogni endpoint è un file `ardy-*.php`, le
interfacce sono singoli file HTML con JavaScript dentro. L'AI è Claude via REST diretta.

**Ardy Outreach** (`ardy-outreach.html` + `ardy-outreach-api.php`) è la parte per trovare e
contattare nuovi partner: antiquari, mercatini, interior designer, B&B.

Deploy: `git pull origin main && ./deploy.sh` sul server. Non c'è CI: l'unico controllo
automatico è `php -l` dentro `deploy.sh`, che blocca il deploy se un file è rotto.

---

## 2. Cosa è stato costruito di recente

Due filoni, portati avanti da due sessioni diverse. Si sono incrociati bene: il secondo ha
esteso il primo invece di scavalcarlo.

### Filone A — Canali social e agente desktop (PR #118, #119, #120)

L'idea di partenza era *"trovare i canali social di un soggetto e mandargli un messaggio"*.
**Metà si è rivelata impossibile come immaginata** — vedi §4, è il vincolo più importante di
tutto il progetto.

- **Scoperta dei profili** (`ardyEnrichExtractSocial()` in `ardy-enrich.php`): legge le icone
  social di header/footer del sito ufficiale. Gratis, e la fonte è il soggetto stesso.
  Filtro severo: scarta widget di condivisione, singoli post, gruppi, pixel di tracciamento.
  Colonne `instagram`/`facebook`/`linkedin` su `outreach_contatti`.
- **Pannello Canali social** nella scheda contatto: profili modificabili a mano, bottone
  *Scrivi* che genera un DM breve (`genera_messaggio_social`) e lo consegna col link che apre
  la chat (`ig.me/m/…`, `m.me/…`). **Non invia.**
- **Bottone *Trova solo i canali*** (`scope: 'social'`): forza la ricerca web quando il sito
  non ha le icone.
- **Server MCP** in `ardy-mcp/` (Node/TypeScript, stdio): collega Claude Code ai contatti.
  11 tool. Gira sul computer di casa, `deploy.sh` lo esclude apposta.

### Filone B — Import massivi e usabilità dei filtri (8 commit, fino a `23cf4a7`)

- **`ardy-portale-bb.php`** + azione `portale_bb`: estrae tutti i B&B di una regione da
  bed-and-breakfast.it.
- **`ardy-places-prova.php`** + azione `places_prova`: misura quanta copertura darebbe Google
  Places **prima** di spendere per l'arricchimento. Lanciabile dalla dash.
- **Scope `'google'`** nell'arricchimento: solo i passi senza agente (sito ufficiale +
  Places). Serve sugli import grossi — riempie telefono e sito di centinaia di contatti senza
  pagare una ricerca web a testa.
- **Lista dei filtrati con spunta**: si sceglie chi arricchire invece di lanciare su tutto, e
  dalla lista si entra nella scheda e si torna indietro.
- **Log del blocco chiarito**: ora dice *SALVATO* / *NON salvato* / *nulla da salvare*, non
  solo cosa ha trovato. Michela guardava 41 righe verdi senza capire se fossero finite nel
  database.
- **Conteggi dei chip corretti**: contavano su tutti i contatti ignorando gli altri filtri
  attivi, mentre la lista li rispettava — numeri veri, ma di un'altra domanda. Aggiunta anche
  la scelta fra *"ne manca almeno uno"* e *"mancano tutti insieme"* sui dati mancanti.

---

## 3. ⚠️ Il disallineamento da sistemare per primo

**Il server MCP (`ardy-mcp/`) non sa niente del filone B.** È stato scritto prima e non è
stato aggiornato. Non è rotto — funziona per quello che copre — ma è indietro:

| Manca nell'MCP | Cosa c'è nell'API |
|---|---|
| scope `'google'` | `ardy_arricchisci_contatto` conosce solo `tutto` e `social` |
| azione `portale_bb` | estrazione B&B da portale, nessun tool |
| azione `places_prova` | prova di copertura Places, nessun tool |

Se una sessione nuova mette mano all'MCP, questo è il primo lavoro sensato: allinearlo, così
dal desktop si può fare un import grosso e il giro economico senza passare dalla dash.

**E soprattutto:** l'MCP risulta `✔ Connected` su Claude Code, ma **non è mai stata fatta una
chiamata vera andata a buon fine** — l'account Anthropic si è bloccato prima della prova (vedi
§7). La prima cosa da verificare è che il Basic Auth passi: in dash lo mette il browser, qui
l'header lo costruisce il server MCP.

---

## 4. 🚧 Il vincolo che spiega metà delle scelte

**Instagram, Facebook e LinkedIn non espongono API per il messaggio diretto a freddo.** Si può
solo *rispondere* entro 24h a chi ha aperto lui la conversazione. Chi promette "DM automatici"
usa automazioni non ufficiali che violano i ToS e fanno **bloccare l'account business** — che
qui è lo stesso che pubblica su Facebook e Instagram via n8n. Perderlo significa perdere anche
la pubblicazione.

Perciò tutto il flusso social è **assistito, non automatico**: l'AI scrive la bozza e prepara
il link alla chat, una persona incolla e invia. Vale nella dash e vale nell'MCP.

È la stessa logica già presente nelle email di outreach, che usano una CTA `wa.me` invece di
scrivere per primi su WhatsApp: è il destinatario a iniziare.

**Non "risolvere" questo con l'automazione del browser.** Non è un limite tecnico da aggirare,
è la condizione per non farsi bandire.

Le due vie legittime, mai implementate, stanno in §6.

---

## 5. 🔒 Invarianti da non rompere per sbaglio

**Il gate di costo in `ardy-enrich.php`.** L'agente web si paga (~$0,02–0,07 a contatto). La
regola, riga per riga:

```php
$agentePuoPartire = $soloGoogle ? false : ($soloSocial ? !empty($ancoraMancanti) : !empty($mancantiCore));
```

- scope `tutto` → parte solo se manca un dato di contatto **vero** (`$mancantiCore`). I social
  da soli non bastano: quasi nessun contatto ha tutti e tre i profili, quindi si pagherebbe
  una ricerca a ogni singolo arricchimento.
- scope `social` → basta che manchi un canale: l'ha chiesto Michela, la spesa è voluta.
- scope `google` → mai. Senza questo freno l'agente partirebbe comunque su ogni struttura
  importata, perché `referente` resta vuoto per tutte e da solo autorizza la chiamata.

**L'arricchimento propone, non scrive.** Ritorna `{valore, fonte, confidenza}` e la conferma è
campo per campo in UI. Un profilo social sbagliato significa scrivere a un estraneo: meglio il
vuoto di un dato indovinato. (L'arricchimento **in blocco** invece salva, ed è dichiarato:
il log dice *SALVATO*.)

**Il blast radius dell'MCP.** `send_campaign` e le cancellazioni **non sono esposte** di
proposito: un fraintendimento in chat non deve poter scrivere a duecento persone né
cancellarne altrettante. L'unico tool che manda qualcosa fuori è `ardy_invia_email`, un
destinatario alla volta, con `conferma: true` verificata prima di ogni chiamata di rete.

---

## 6. Cosa resta aperto

**Sull'outreach social**

- **Stato per canale.** `stato` e `data_contatto` sono unici per contatto: un DM inviato
  "sporca" lo stesso stato dell'email. Se l'outreach social diventa un binario vero, serve una
  tabella `outreach_contatti_canali` (canale, stato, data, esito) invece delle tre colonne
  piatte.
- **DM in arrivo.** Instagram e Messenger permettono di *rispondere* entro 24h: si potrebbero
  far arrivare i DM dentro Ardy con lo stesso schema di webhook già usato per WhatsApp
  (`ardy-whatsapp-webhook.php` verifica già la firma `X-Hub-Signature-256`). Richiede i
  permessi `instagram_manage_messages` / `pages_messaging` e la app review di Meta.
- **Private reply ai commenti.** `POST /{comment-id}/private_replies` consente **un** DM a chi
  commenta un post, entro 7 giorni. È l'unico messaggio in uscita davvero legittimo, e verso
  contatti molto più caldi di un lead preso da mappa.

**Tecnico**

- **Allineare l'MCP al filone B** (§3) e provarlo davvero.
- **Paginazione.** `get_contacts` restituisce tutto senza `LIMIT`: l'MCP scarica e pagina in
  locale. Verso il migliaio di contatti va aggiunta lato API.
- **Altri canali social.** TikTok e YouTube si estrarrebbero con lo stesso metodo; esclusi
  perché sul target (antiquari/B&B) contano poco.

---

## 7. Nota storica — il blocco dell'account

Il 15/08 l'organizzazione Anthropic dell'account è finita **in coda di cancellazione** dopo un
pasticcio con due login Google sovrapposti. Sintomo: `401 OAuth access token has expired` su
ogni richiesta di Claude Code, che `/login` non risolveva. **Risolto dal supporto**: al
17/08 l'account è di nuovo in piedi e l'alert è sparito.

Resta utile ricordarlo per due motivi.

**Primo**, i due errori `401` hanno lo stesso numero e cause opposte:

| Messaggio | Chi litiga con chi | Cosa fare |
|---|---|---|
| `OAuth access token has expired` / `Please run /login` | Claude Code ↔ **Anthropic** | `/login`; se non basta, è l'account |
| `Autenticazione rifiutata (401). Controlla ARDY_USER e ARDY_PASS` | server MCP ↔ **ardy-lab.it** | rifare `claude mcp add` |

Il secondo messaggio è in italiano apposta, per distinguerli a colpo d'occhio.

**Secondo**, è venuta fuori una dipendenza che vale la pena conoscere: `ARDY_API_KEY` (in
`ardy-config.php`, non versionato) è usata da **18 file**. Se quella chiave muore si fermano
Sole in webchat e su WhatsApp, l'outreach AI, le caption social, i preventivi, le FAQ, i reel,
i solleciti. **Non** si fermano: dashboard, database, email Brevo, WhatsApp template, la
pubblicazione su Meta via n8n, la ricerca su mappa, e lo scraping dei social dalle icone (è
PHP puro). Per rimettere in moto l'AI basta sostituire il valore della chiave sul server.

---

## 8. Runbook

### Deploy

```bash
# sul server, come utente micoperibg
cd ~/repositories/ardyagent
git pull origin main
./deploy.sh
```

`deploy.sh` controlla la sintassi PHP, copia in `public_html`, poi lancia **automaticamente**
`ardy-migrate.php`. Il pulsante *Deploy HEAD Commit* di cPanel invece **non** esegue la
migrazione: in quel caso va aperto `ardy-migrate.php` nel browser.

Non vengono mai toccati: `ardy-config.php`, `.htpasswd`, `ardy-uploads/`, `preventivi_pdf/`,
`reels/`, `vendor/`, `ardy-mcp/`.

⚠️ Il repository sul server deve stare sul ramo **`main`**. C'era finito un ramo di lavoro
residuo: funzionava per caso (fast-forward fortunato), ma al primo commit divergente il
`git pull` sarebbe diventato un merge e la catena `&&` avrebbe fermato il deploy.

### Server MCP sul desktop

```bash
cd ~/ardyagent && git pull origin main
cd ardy-mcp && npm install && npm run build

claude mcp add ardy -s user \
  -e ARDY_API_URL=https://ardyagent.ardy-lab.it \
  -e ARDY_USER='<utente>' \
  -e ARDY_PASS='<password>' \
  -- node /home/bebo/ardyagent/ardy-mcp/dist/index.js
```

Gli **apici singoli** attorno a utente e password sono necessari: senza, la shell si mangia i
caratteri speciali e si finisce con un 401 incomprensibile. Verifica con `claude mcp list`.

Serve Node 18+. Sul computer di casa (`bebo@bebo`, Debian) c'è la v18.20.4.

### Credenziali

- **Utente** dashboard: `cut -d: -f1 /home/micoperibg/public_html/ardyagent.ardy-lab.it/.htpasswd`
- **Password**: non recuperabile (hash bcrypt). Sta nel gestore password del browser, oppure
  va rigenerata — e `ardy-setup-login.php` si auto-disabilita finché `.htpasswd` esiste.
- Chiavi API, DB, token WhatsApp: `ardy-config.php` sul server, non versionato.
- Credenziali Meta per pubblicare su FB/IG: dentro **n8n**, non nel repository.

---

## 9. Come è fatto il lavoro qui

Convenzioni osservate nel repository, utili da rispettare:

- **Commenti e documentazione in italiano**, e spiegano il *perché*, non il *cosa*.
- **Ogni modifica aggiorna la documentazione**: `README.md` per com'è fatto,
  `TODO-PROSSIMI-TASK.md` per quel che resta. Non è opzionale, è la memoria del progetto.
- **Le migrazioni stanno in `ardy-migrate.php`**, idempotenti (`colExists`, `IF NOT EXISTS`).
- **Niente test automatici**: si verifica a mano e si scrive nel commit cosa è stato provato.
- I messaggi di commit raccontano il problema prima della soluzione.
