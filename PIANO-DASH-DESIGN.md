# Piano — Dash Design (progetti interni → contenuti → vendita)

> Brief (25/06/2026): una dash separata per i **progetti interni di design** — prototipi,
> lampade, piccoli mobili, complementi d'arredo, restyling di vecchi pezzi — che poi vanno
> messi in vendita, con un catalogo da costruire. Si riusano dalla dash principale le **fasi
> di lavoro** con tutto il seguito (WordPress, social, reel). Lo **stato** è diverso. Non ci
> sono clienti, ci sono **progetti**. Obiettivo: seguire le fasi di lavoro, trasformarle in
> contenuti per sito e social, fare brand awareness e **vendere** i prodotti (portali
> specializzati, Etsy, Catawiki, Amazon, Google Shopping, ecommerce WooCommerce).

---

## TL;DR
- La dash design è lo **stesso imbuto** della dash principale, con due differenze: il soggetto
  non è un cliente ma un **progetto tuo**, e il fondo dell'imbuto non è "consegna + saldo" ma
  **"prodotto a catalogo + in vendita"**. Tutto ciò che sta in mezzo (fasi con foto/video →
  reel/social/WordPress) **esiste già** e si riusa.
- **Architettura decisa**: stesso codebase, stesso DB, stesso login. Nuova tabella `progetti`
  accanto a `clienti`; le `fasi` si riusano aggiungendo una colonna `progetto_id` nullable;
  nuovo file app `ardy-design-app.html`. Massimo riuso del motore reel/social, zero duplicazione.
- **Vendita — principio guida (deciso)**: *la dash NON vende*. È il master di **contenuti +
  ciclo di vita del progetto**. WooCommerce (quando servirà) è il master di **catalogo +
  commercio**, alimentato da un **push a senso unico** dash → Woo. I marketplace pendono da Woo
  (feed/plugin); Catawiki resta un flusso manuale a sé.
- **Sequenza**: prima la macchina di contenuti (Tappa 1, autoconsistente), poi catalogo, poi il
  ponte Woo **solo quando esistono davvero catalogo replicabile e traffico** — non "perché ci
  vuole l'ecommerce".
- **Filiera reale (definita 25/06, vedi §3)**: la stampa 3D è **prototipo *e* produzione** (no
  print-on-demand per ora). Il prodotto è **replicabile a serie**, lo **stock vive su Woo/Etsy,
  non nella dash**. Il ciclo di vita finisce a *"prodotto pronto + file congelato + scheda + foto"*
  → **A CATALOGO**, non "venduto". Due binari dentro al progetto: **R&D** (interno) e **racconto**
  (pubblico).

---

## 1. Il modello — perché è lo stesso imbuto

| | Dash principale (clienti) | Dash design (progetti) |
|---|---|---|
| Soggetto | `clienti` (chi porta un mobile) | `progetti` (pezzo tuo) |
| Cuore | `fasi` (foto/video, stato, ordine, prezzo) | **le stesse `fasi`** |
| Output contenuti | reel, bozze social, pubblicazione WordPress | **gli stessi** |
| Fondo dell'imbuto | consegna + pagamento + solleciti | **a catalogo** (stock/vendita fuori dalla dash) |
| Stato | `LEAD → ACCONTO → IN_LAVORAZIONE → COMPLETATO → CONSEGNATO` | `IDEA → … → A CATALOGO` (vedi §3) |
| Binari interni | uno solo (le fasi sono già "racconto") | **due**: R&D interno + racconto pubblico |

Conseguenza pratica: il pezzo di software più prezioso e collaudato che hai — *fasi → reel/social*
— non si tocca e non si duplica. Cambia il soggetto a monte e il fondo a valle.

---

## 2. Architettura — stesso codebase, dati separati

### 2.1 Nuova tabella `progetti` (accanto a `clienti`, non dentro)
Niente campi "cliente" (telefono, indirizzo, sopralluogo, solleciti). Bozza dei campi:

```
progetti
  id                 BIGINT PK
  slug               VARCHAR   -- per URL pubblico catalogo/lavorazione
  titolo             VARCHAR
  tipo               VARCHAR   -- lampada | mobile | complemento | restyling | prototipo
  stato              VARCHAR   -- vedi §3 (ciclo di vita nuovo)
  descrizione        TEXT      -- racconto/concept
  materiali          TEXT      -- descrizione PUBBLICA per la listing ("PLA riciclato, ottone…")
  scarto_pct         DECIMAL   -- % scarto/fallimenti stampa, default 10 (vedi §2.5)
  costo_produzione   DECIMAL   -- CALCOLATO: somma BOM × (1 + scarto_pct) (vedi §2.5)
  prezzo_vendita     DECIMAL   -- prezzo suggerito per la listing (NON lo gestisce la dash dopo)
  tempo_lavoro       VARCHAR
  -- binario R&D / "verità del prodotto" (artefatti a livello progetto, opzione A):
  scheda_tecnica     TEXT      -- dimensioni, finiture, e per lampada: attacco, V, cavo, peso…
  file_congelato_at  DATETIME NULL  -- quando hai premuto "congela" (vedi §3)
  file_snapshot      TEXT NULL -- JSON snapshot: STL + profilo stampa + scheda al congelamento
  render_urls        TEXT NULL -- JSON: render
  cad_urls           TEXT NULL -- JSON: file CAD/STL
  -- canali / catalogo:
  canali_vendita     TEXT      -- JSON: dove è pubblicato + link/id esterni (annunci)
  woo_product_id     BIGINT NULL  -- popolato dal push (Tappa 3)
  copertina_url      VARCHAR NULL
  created_at / updated_at / deleted_at (soft delete come clienti)
```
> **Nessun campo stock/quantità/venduto**: lo stock vive su Woo/Etsy (deciso 25/06). La dash si
> ferma a "prodotto pronto + pubblicato". `prezzo_vendita` è solo un *suggerimento* per la listing.

> **`serie`**: non serve un campo "pezzo unico vs serie" — la stampa 3D rende tutto **replicabile**
> per natura (deciso 25/06). Eventuali edizioni limitate sono una scelta di marketing, non un vincolo
> del modello.

### 2.2 Riuso delle `fasi` — colonna `progetto_id` nullable (deciso)
Oggi `fasi` è legata al cliente via `session_id`. Si aggiunge `progetto_id BIGINT NULL`: una fase
appartiene **o** a un cliente **o** a un progetto. Riuso totale di `ardy-crea-reel.php`,
`ardy-fasi-bozza-api.php`, `ardy-pubblica-social.php`, `ardy-pubblica-lavorazione.php`.

> Scartata la tabella `fasi_progetto` gemella: più "pulita" sulla carta, ma duplica il motore
> reel/social — il modo migliore per ritrovarsi tra sei mesi con due versioni divergenti. Una
> colonna nullable è il prezzo giusto. DDL nuovo va in `ardy-migrate.php` (idempotente), come
> tutto il resto.

### 2.3 Nuova app `ardy-design-app.html`
Gemella di `ardy-michela-app.html`: stesso CSS/token (`ardy-michela-app.css`), stesso login
(`ardy-auth.php`), stessi endpoint fasi/reel riusati. Endpoint nuovi specifici dei progetti
(`ardy-progetti-api.php`, ecc.) usano `ardy-db.php` e le librerie esistenti.

> Nota tecnica per dopo: i 363 `style=""` inline della dash principale (vedi `ANALISI-CLAUDE-DESIGN.md`)
> non vanno **ereditati** nella nuova app — partire theming-ready (classi/token) è un'occasione gratis.

### 2.4 Due binari dentro al progetto (definito 25/06)
Il restauro ha un solo binario: le fasi *nascono già* per essere pubblicate. Il design ne ha **due**:
- **Binario R&D (interno)**: iterazioni di prototipo (v1, v2, v3…) con foto + **note di iterazione**
  ("qui non torna, rifare"), più gli artefatti tecnici a livello progetto (CAD, render, scheda, file
  congelato). Serve a *ricordarti cosa hai cambiato*, non a pubblicare.
- **Binario racconto (pubblico)**: le **fasi** riusate dalla dash principale → reel/social/WordPress.

Le iterazioni R&D restano interne *di default*, ma una iterazione deve poter essere **"promossa" a
contenuto** (i timelapse di stampa, il "v1 sbagliato vs v3 giusto" sono ottimo materiale social):
così il registro R&D alimenta anche la brand awareness senza doppio lavoro.

> Implementazione probabile: una tabella `progetto_iterazioni` (v_num, note, foto, cad_ref, data,
> `promossa_a_fase_id` NULL) per il binario R&D; le `fasi` (via `progetto_id`, §2.2) per il racconto.

### 2.5 Materiali e costi (definito 25/06)
Due cose con lo stesso nome, da tenere separate:
- **Materiali pubblici** = testo descrittivo per la listing → campo `materiali` su `progetti`.
- **Costi interni** = una piccola **distinta (BOM)** per sapere il margine → tabella `progetto_materiali`.

```
progetto_materiali           -- distinta costi (interna)
  id              BIGINT PK
  progetto_id     BIGINT FK
  categoria       VARCHAR   -- filamento | stampa | elettrico | ferramenta | finitura | imballo | manodopera
  voce            VARCHAR   -- es. "PLA nero bobina X", "kit E27 cavo tessile"
  qta             DECIMAL   -- grammi / ore / pezzi
  unita           VARCHAR   -- g | h | pz
  costo_unitario  DECIMAL   -- €/g, €/h, €/pz
  costo_riga      DECIMAL   -- qta × costo_unitario (calcolato)
  note            TEXT NULL
```

Regole:
- **`costo_produzione`** (su `progetti`) = Σ `costo_riga` × `(1 + scarto_pct/100)`. La dash mostra il
  **margine** = `prezzo_vendita − costo_produzione`.
- **`scarto_pct`** = % fallimenti stampa, **default 10**, modificabile per progetto (senza, il margine
  è una bugia).
- **Manodopera**: contata nel costo. Tariffa oraria **default €50,00/h** in config
  (es. `ARDY_DESIGN_COSTO_ORARIO = 50.00`), modificabile sulla singola riga (`categoria='manodopera'`,
  `unita='h'`). Da fissare ancora: tariffa oraria **macchina/stampa** (energia+ammortamento, numero
  più piccolo, `categoria='stampa'`).
- **Cattura da OrcaSlicer (no integrazione)**: le righe **filamento** (grammi) e **stampa** (ore) si
  pre-compilano coi numeri che Orca dà a ogni stampa; le altre voci a mano. La porta su **Moonraker/
  Klipper** (API live) resta aperta per dopo — ora impraticabile (stampante in LAN, dash su hosting).
- Lo **snapshot del file congelato** (`file_snapshot`, §2.1/§3) include il **profilo OrcaSlicer** +
  grammi/tempo del momento → "con cosa ho prodotto questo lotto" è risposto del tutto.

---

## 3. Il ciclo di vita del progetto (definito 25/06)

Il cliente fa `LEAD → ACCONTO → IN_LAVORAZIONE → COMPLETATO → CONSEGNATO` (lo pilotano i pagamenti).
Il progetto è pilotato dalla **maturazione del prodotto**, non dai soldi, e **finisce a catalogo**
(stock/ordini/venduto vivono su Woo/Etsy, fuori dalla dash). Sequenza reale:

```
IDEA → PROGETTAZIONE (CAD/render)
     → PROTOTIPO  ⟲ loop tracciato: v1, v2, v3… (foto + note iterazione)   [binario R&D]
     → [⏟ FILE CONGELATO]  ← bottone manuale "a naso": scatta lo snapshot (STL + profilo + scheda)
     → PRODUZIONE (stampi il lotto — la stampa 3D è anche metodo di produzione, no on-demand per ora)
     → FOTOGRAFIA
     → A CATALOGO / PUBBLICATO  ← stato terminale, con link all'annuncio
        └─ da qui: Woo/Etsy. La dash NON segue stock/ordini/venduto.
```

Transizioni che *fanno succedere qualcosa* (come oggi IN_LAVORAZIONE apre il popup date):
- **FILE CONGELATO** → la transizione più importante: separa "sto progettando" da "sto producendo".
  Non è un gate (il sistema non giudica se è pronto): è un **bottone manuale** che, al click,
  **scatta lo snapshot** del file STL + profilo di stampa + scheda tecnica di quella versione
  (`file_snapshot`), così sai con cosa hai prodotto quel lotto anche se il CAD poi va avanti.
- **FOTOGRAFIA** → sblocca la creazione della scheda prodotto (foto pronte).
- **A CATALOGO** → scheda pubblicata + (Tappa 3+) push verso Woo. Stato terminale lato dash.

> Decisioni che questa sequenza **chiude** (erano aperte in §6): è **serie replicabile + stock fuori
> dalla dash** (non pezzo singolo); Catawiki/aste → **marginale** (il prodotto è da listino, non da
> asta); niente stato `VENDUTO` nella dash.

> ⚠️ **Scenario non-software da tenere a mente**: la lampada è un **prodotto elettrico**. Venderne
> *in serie* in UE chiama in causa **sicurezza/marcatura CE** — non è codice, ma è un blocco che può
> frenare il "metti in vendita". Da chiarire prima della Tappa 3, non dopo.

---

## 4. Vendita e catalogo — il principio (deciso) e i canali

### 4.1 Tre cose da non confondere
1. **Master dei contenuti** (foto fasi, racconto, reel, descrizione) → **la dash**. Sua ragione d'essere.
2. **Master del catalogo** (record canonico: titolo, prezzo, stock) → **WooCommerce**, quando servirà.
3. **Motore di commercio** (carrello, pagamenti, ordini, spedizioni, IVA, resi) → **mai la dash**.

> Costruire il punto 3 nella dash per vendere pochi pezzi/mese è malpractice: riscriveresti Woo
> fatto peggio. Scartato.

### 4.2 Push a senso unico dash → Woo (uccide il doppio inserimento)
Dal progetto+fasi la dash crea su Woo un **prodotto in bozza** già pieno (foto migliori,
descrizione dal racconto, prezzo suggerito): **una chiamata REST, mai sync di ritorno**. Su Woo si
dà l'ok e si pubblica; da lì **Woo possiede prezzo, stock e ordini** e la dash non ci pensa più.
La dash resta dov'è il lavoro e la storia; Woo è la cassa.

### 4.3 Canali — realtà, non lista della spesa
- **WooCommerce**: API REST pulita → ponte diretto fattibile (Tappa 3).
- **Etsy**: il più sensato per partire (pubblico giusto per design/restyling, foto e racconto contano).
- **Catawiki**: **asta selezionata**, non un listino. Marginale per te (il prodotto stampato è da
  listino, non da asta); resta un **flusso manuale a sé**, occasionale, per l'eventuale pezzo di pregio.
- **Google Shopping**: gioco di **feed + ads a pagamento**. Solo dopo, via feed di Woo, con budget.
- **Amazon**: **no** per pezzi di design unici/restyling. Commodity + burocrazia handmade. Sbagliato per te.

### 4.4 Il collo di bottiglia non è il software
Vendere design non fallisce per mancanza di integrazioni, ma per **foto deboli, racconto debole,
nessun pubblico, prezzo posizionato male**. L'energia di sviluppo va nella **macchina di contenuti**
(che la dash già sa fare), non nel reinventare un checkout.

---

## 5. Roadmap a tappe indipendenti

### Tappa 1 — `progetti` + dash design + fasi su progetto  *(autoconsistente, zero dipendenze esterne)*
- DDL: tabella `progetti` + colonna `fasi.progetto_id` in `ardy-migrate.php`.
- `ardy-progetti-api.php` (CRUD progetti) riusando `ardy-db.php`.
- `ardy-design-app.html` (gemella, theming-ready) con lista progetti + scheda + fasi.
- Riuso del motore reel/social/WordPress sulle fasi del progetto.
- **Valore immediato**: racconti il lavoro e produci contenuti da subito. Vende ancora "a mano".

### Tappa 2 — Scheda prodotto + catalogo interno
- Pagina prodotto pubblica (anche solo sul tuo sito) generata dal progetto: foto fasi, racconto,
  prezzo. È il catalogo, prima ancora di "vendere online".

### Tappa 3 — Ponte WooCommerce  *(solo quando esistono catalogo replicabile + traffico)*
- Push a senso unico: crea prodotto in bozza su Woo via REST, foto + descrizione + prezzo.
- `woo_product_id` salvato su `progetti`. Da lì Woo è il master commerciale.

### Tappa 4 — Feed / marketplace
- **Etsy** come primo marketplace (un canale per volta). Google Shopping via **feed di Woo**, con budget ads.
- **Catawiki manuale**, marginale, solo per l'eventuale pezzo di pregio.

> Tappa 1 dà ritorno subito; vendita e marketplace vengono dopo, su decisioni che maturano
> **vendendo davvero**, non a tavolino.

---

## 6. Decisioni — chiuse il 25/06 e ancora aperte

### Chiuse (filiera mappata insieme il 25/06)
- [x] **Stampa 3D** = prototipo **e** produzione (no print-on-demand per ora).
- [x] **Prodotto replicabile a serie**; **stock fuori dalla dash** (vive su Woo/Etsy).
- [x] **Ciclo di vita** lato dash finisce a **A CATALOGO** (no `VENDUTO`) — §3.
- [x] **Prototipo tracciato** (loop v1/v2/v3 con note), con iterazioni **promuovibili a contenuto** — §2.4.
- [x] **File congelato** = transizione di stato esplicita, manuale "a naso", con snapshot — §3.
- [x] **Render/CAD/schede** = artefatti **a livello progetto** (opzione A), non dentro le fasi — §2.1/§2.4.
- [x] **Catawiki/aste** → marginale; il prodotto è da **listino**.
- [x] **Materiali vs costi** separati: `materiali` (testo pubblico) + BOM `progetto_materiali` (interna) — §2.5.
- [x] **Manodopera** contata nel costo, tariffa default **€50,00/h** (config, override per riga).
- [x] **Scarto stampa** = campo `scarto_pct`, default **10%** modificabile.
- [x] **Costi da OrcaSlicer** (grammi/ore digitati), **niente integrazione Moonraker** per ora.
- [x] **Campi DB** `progetti` + `progetto_materiali` (§2.1/§2.5) — approvati il 25/06.

### Ancora aperte (in coda, non bloccano la Tappa 1)
- [ ] **Serve Woo al lancio?** — *in coda* (dopo). Etsy + vendita a mano regge il lancio a costo zero.
- [ ] **CE / sicurezza elettrica** delle lampade — *task da affrontare dopo*, prima della vendita in serie.
- [ ] **Tariffa oraria macchina/stampa** (energia+ammortamento) — numero da fissare per la riga `stampa`.
- [ ] Campi della tabella `progetto_iterazioni` (§2.4) — ultimo affinamento prima del DDL.

---

## 7. Prossimo passo concreto (quando si parte)
1. ✅ Ciclo di vita confermato (§3) — il cuore della dash nuova è mappato.
2. Affinare i **campi di `progetti`** (§2.1) e `progetto_iterazioni` (§2.4): ultimo giro prima del DDL.
3. Tappa 1: DDL in `ardy-migrate.php` → `ardy-progetti-api.php` → `ardy-design-app.html` (theming-ready)
   → agganciare il motore fasi/reel. Su branch, niente impatto sulla dash live.
