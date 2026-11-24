# Handoff — Ardy Agent, stato al 24/11/2026

Documento di passaggio di consegne. Serve a far ripartire una sessione nuova (o una persona)
senza rileggersi le chat: cosa c'è, perché è fatto così, cosa non va toccato e cosa resta da fare.

> **Per una sessione nuova**: leggi la **Parte A** (lavoro del 23-24/11: Racconta, area cliente,
> social, grafica), poi `TODO-PROSSIMI-TASK.md` per la coda e `README.md` per il dettaglio di ogni
> pezzo. La **Parte B** è l'handoff precedente (Ardy Outreach, 17/08), ancora valido. Il codice sta
> su GitHub in `panseo/ardyagent`, ramo `main`.
>
> **Si risponde sempre in italiano** (l'utente l'ha dovuto chiedere più volte).

---

# Parte A — Racconta, area cliente, social e grafica (23-24/11/2026)

## A1. In breve

La dash principale si usava poco per raccontare i lavori. In due giorni sono nati:

1. **📸 Racconta** (`ardy-racconta.html`): mini-app da telefono. Foto + nota a voce di un **pezzo**
   (non di un cliente) → Sole scrive il capitolo → pagina pubblica su WordPress con Sole che risponde
   ai visitatori e raccoglie contatti → SEO/GEO → Facebook, Instagram, Google Business, reel. Tutto
   **manuale**: ogni canale ha il suo bottone e un'anteprima prima di pubblicare.
2. **🔑 Area cliente** su `https://ardyagent.ardy-lab.it/lavoro/`: il cliente vede il suo lavoro col
   codice `ARD-XXXX-XXXX`. È il primo passo per togliere le lavorazioni dei clienti da WordPress.
3. **Grafica nello stile dei reel di Michela** (Inter, logo bianco, frase con l'asterisco) per reel e
   copertine, con dissolvenze nei reel.

Piani e analisi: `PIANO-RACCONTA.md`, `ANALISI-AREA-CLIENTE.md`. Canvas di design delle schermate:
<https://claude.ai/artifact/11RprfUqraXfvzF3NmnNt7> (privato).

## A2. Racconta — com'è fatto

| Pezzo | File |
|---|---|
| Mini-app (lista, pezzo, capitolo, condividi) | `ardy-racconta.html` |
| API pezzi, capitoli, foto, storico social | `ardy-pezzi-api.php` |
| Sole: capitolo+scheda, correzione a voce, pacchetto SEO, testi social + frase | `ardy-pezzi-ai.php` |
| Pagina WordPress (lavori-in-corso + tag `storia-pezzo`) | `ardy-pubblica-pezzo.php` |
| Copertina 4:5 del capitolo per i social | `ardy-pezzi-copertina.php` |
| Libreria (scheda, anonimato, proiezione pubblica, SEO, social, Claude) | `ardy-pezzi-lib.php` |
| Grafica reel/copertine + montaggio con dissolvenze | `ardy-grafica-lib.php` |
| Sole sulla pagina-pezzo | `ardy-widget-lavorazione.js` + `ardy-proxy.php` (`pezzoPostId`) |

**DB** (`ardy-migrate.php`): `pezzi` (con `seo`, `pubblico_json`), `fasi.pezzo_id` (terzo proprietario
di una fase dopo cliente e progetto), `pezzi_social` (bozze e storico per capitolo e canale).

**Scelte da non disfare senza motivo:**
- **Binari separati**: un pezzo non ha alcun legame con `clienti`.
- **Sole sul sito legge solo `pubblico_json`**, l'istantanea di ciò che è online: bozze e note
  dettate non escono mai. Ogni capitolo ripubblicato aggiorna l'istantanea.
- **Anonimato in due reti**: il prompt toglie nomi/indirizzi/prezzi; `pezziAvvisiPrivacy()` (regex)
  chiede conferma prima di pubblicare se ne trova ancora (pagina, SEO, social).
- **Pagina WP**: un solo paragrafo d'apertura ("In breve"), capitoli con galleria a sinistra e testo a
  destra (solo HTML+CSS, su una riga perché `wpautop` non la spezzi), FAQ + link interni da whitelist
  in fondo, meta Yoast, commenti chiusi, immagine in evidenza tenuta ma nascosta nella pagina (Divi la
  ripeterebbe). **"Aggiorna la pagina"** rifà anche i capitoli già online con le foto già su WP.
- **Social sempre manuali**: prima si salva la bozza (`social_salva`, ritorna gli avvisi), poi anteprima,
  poi pubblicazione con gli endpoint di sempre (`ardy-pubblica-social.php`, `ardy-gbp-post.php`,
  `ardy-pubblica-reel.php`), poi l'esito. Instagram è spento di default (rifiuta foto più strette di 4:5;
  la copertina 4:5 risolve la prima foto).
- **Voce**: dettatura del browser, ripiego sul microfono della tastiera (iPhone). App nativa scartata.

## A3. Area cliente — dove siamo

- **Passo 1 fatto e online**: `ardy-area-cliente.html` + `ardy-area-cliente-api.php`, riscrittura
  `/lavoro/` in `.htaccess` e `infra/Caddyfile`. Accesso col codice (anche `/lavoro/?codice=…`), cookie
  firmato HMAC, fasi pubblicate dalla più recente, bottone WhatsApp, nessuna chat. Il codice si vede e
  si genera in dash (scheda → Lavorazione → "🔑 Area cliente"); `ardy_codice_per_sessione()` sta in `ardy-db.php`.
- **Articoli cliente su WordPress cancellati** (24/11: erano tutti di prova o consegnati). Le loro foto
  restano nella libreria media e l'area le mostra: **non svuotare la libreria media**.
- **Passo 2 scritto il 24/11** (ramo `area-cliente-passo2`): le fasi vanno solo nell'area, foto sul server
  (`ardy-fasi-foto-lib.php`), link dell'area in email/WhatsApp/Sole/dossier, social e reel ricablati, la
  dash dice "Avvia l'area del cliente". **Da collaudare** (lista nel TODO). Poi redirect dei vecchi URL e
  pulizia (passi 3-4 dell'analisi).
- Collaudo dell'area col prossimo cliente vero (nel TODO).

## A4. WordPress (fuori repo, fatto a mano)

- **Loader in Divi** (Opzioni del tema → Integrazione → codice in fondo ai post), copia in
  `wpcode-snippet-lavorazione.html`: agisce **solo** sui post col tag `storia-pezzo`. Fatto il 23/11.
- Snippet WPCode **"Ardy — schema FAQ pezzi"** (`wordpress-snippets/pezzi-schema-faq.php`): da
  verificare che sia stato creato (FAQPage nel Rich Results Test).
- Lo snippet WPCode `lavorazioni-copertina-fallback` resta: usa la prima foto come copertina.

## A5. Grafica e reel

- `ardy-grafica-lib.php` (Deny): copertina con logo bianco, indirizzo, servizi, frase con `*` allineata
  sull'inchiostro e corpo che si riduce per stare in 3 righe; foto piene; Prima/Dopo; clip con zoom lento;
  unione con `xfade` (0,7 s). Font **Inter** in `assets/fonts/` (OFL), preso da Fontsource perché Google
  Fonts dà solo WOFF.
- `ardy-crea-reel.php` usa tutto questo per **tutti** i reel (clienti, progetti, pezzi); se manca GD/Inter
  o `xfade` fallisce, ripiega sul montaggio di prima (log `ARDY REEL XFADE`).
- Stile copiato dai reel di @ardy.lab: solo l'apertura ha i testi, poi immagini pulite a tutto schermo.

## A6. Cosa resta aperto (in ordine)

1. **Passo 2 dell'area cliente**: scritto il 24/11, da collaudare dopo il deploy (lista nel TODO).
2. **Collaudi dal vivo** nel TODO: social (Google, Facebook, reel), grafica e dissolvenze sul server,
   area cliente col prossimo cliente, voce su iPhone.
3. **Reel su Instagram**: `ardy-pubblica-reel.php` considera riuscito l'invio se n8n risponde 200, senza
   leggere l'esito (a differenza dei post). Da allineare a `ardySocialInterpretaEsito()` così lo stato
   diventa "da verificare" quando Instagram non conferma.
4. Foto della bottega ad alta risoluzione al posto di `assets/racconta-bottega.jpg` (stesso nome).
5. Facoltativi: Content-Signal `ai-input=yes` su Cloudflare; trascrizione voce lato server.

## A7. Come si è lavorato (ambiente)

- **PHP non è installato sul PC di sviluppo**: `php -l` si lancia con
  `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php -l <file>`. Immagini locali già costruite:
  `php-gd` (GD+FreeType) e `php-gd-ff` (+FFmpeg) per provare grafica e reel.
- **`gh` non è installato**: si lavora su un ramo, poi merge `--no-ff` su `main` in locale e push.
  L'utente chiede il merge esplicitamente ("Sì, merge") e poi fa il deploy lui:
  `runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'`
- Le mini-app si provano nel browser integrato servendo la cartella con `python3 -m http.server` e
  simulando `fetch`; le API con PHP + MySQL in container quando serve.
- Michela usa **iPhone**, Andrea **Android**.

---

# Parte B — Ardy Outreach (handoff del 17/08/2026, ancora valido)


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

> ✅ **Allineato il 16/08.** `ardy_arricchisci_contatto` accetta ora `scope='google'`, e due tool
> nuovi (`ardy_estrai_portale_bb`, `ardy_prova_copertura_places`) ricalcano `portale_bb` e
> `places_prova`. Build e smoke test via stdio passano; **resta da fare** il collaudo dal vivo
> contro il server (vedi il paragrafo sul blocco account qui sotto — non ancora superato). Tabella
> e descrizione lasciate come traccia storica del gap.

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

**Il passo 1c di `ardy-enrich.php`.** Quando Places scopre il sito di un contatto che non ne
aveva, 1c lo visita **subito, nello stesso giro**. Sembra ridondante col passo 1 e non lo è: sui
contatti appena importati il passo 1 gira a vuoto (il sito ancora non c'è), e senza 1c l'email
finirebbe a carico dell'agente a pagamento. È il motivo per cui il giro `google` porta a casa
email e social **senza spendere**. Toglierlo sposterebbe quel costo sull'agente, in silenzio.

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

- **Provare davvero l'MCP allineato al filone B** (§3): il codice è allineato, manca il collaudo
  dal vivo contro il server — vedi i punti in `TODO-PROSSIMI-TASK.md` §*Ardy dal desktop*.
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
