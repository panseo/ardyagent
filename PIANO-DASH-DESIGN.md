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

---

## 1. Il modello — perché è lo stesso imbuto

| | Dash principale (clienti) | Dash design (progetti) |
|---|---|---|
| Soggetto | `clienti` (chi porta un mobile) | `progetti` (pezzo tuo) |
| Cuore | `fasi` (foto/video, stato, ordine, prezzo) | **le stesse `fasi`** |
| Output contenuti | reel, bozze social, pubblicazione WordPress | **gli stessi** |
| Fondo dell'imbuto | consegna + pagamento + solleciti | **catalogo + vendita su canali** |
| Stato | `LEAD → ACCONTO → IN_LAVORAZIONE → COMPLETATO → CONSEGNATO` | `IDEA → … → VENDUTO` (nuovo) |

Conseguenza pratica: il pezzo di software più prezioso e collaudato che hai — *fasi → reel/social*
— non si tocca e non si duplica. Cambia il soggetto a monte e il fondo a valle.

---

## 2. Architettura — stesso codebase, dati separati

### 2.1 Nuova tabella `progetti` (accanto a `clienti`, non dentro)
Niente campi "cliente" (telefono, indirizzo, sopralluogo, solleciti). Bozza dei campi:

```
progetti
  id                BIGINT PK
  slug              VARCHAR   -- per URL pubblico catalogo/lavorazione
  titolo            VARCHAR
  tipo              VARCHAR   -- lampada | mobile | complemento | restyling | prototipo
  stato             VARCHAR   -- vedi §3 (ciclo di vita nuovo)
  descrizione       TEXT      -- racconto/concept
  materiali         TEXT
  costo_produzione  DECIMAL
  prezzo_vendita    DECIMAL
  tempo_lavoro      VARCHAR   -- o ore stimate/effettive
  serie             VARCHAR   -- pezzo unico | serie limitata | replicabile
  canali_vendita    TEXT      -- JSON: dove è in vendita + link/id esterni
  woo_product_id    BIGINT NULL  -- popolato dal push (Tappa 3)
  copertina_url     VARCHAR NULL
  created_at / updated_at / deleted_at (soft delete come clienti)
```

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

---

## 3. Lo stato del progetto — il pezzo nuovo da disegnare

Il cliente fa `LEAD → ACCONTO → IN_LAVORAZIONE → COMPLETATO → CONSEGNATO` (lo pilotano i pagamenti).
Il progetto invece è pilotato dalla **pubblicazione e messa in vendita**, non dai soldi. Proposta:

```
IDEA → PROTOTIPO → IN_LAVORAZIONE → FINITO → FOTOGRAFATO → A_CATALOGO → IN_VENDITA → VENDUTO
```

Transizioni che *fanno succedere qualcosa* (come oggi IN_LAVORAZIONE apre il popup date):
- **FOTOGRAFATO** → sblocca la creazione della scheda prodotto (foto pronte).
- **A_CATALOGO** → la scheda è pubblicata nel catalogo interno/sito.
- **IN_VENDITA** → push verso Woo/canali (Tappa 3+).
- **VENDUTO** → ritiro dai canali, archiviazione.

> ⚠️ Da confermare con Ardy/Michela: questa sequenza assume **pezzo singolo**. Se si lavora a
> **lotti/serie** (es. 5 lampande uguali) lo stato deve gestire la quantità e "VENDUTO" diventa
> "stock". Decisione che riflette il modo reale di lavorare — non copiabile dal restauro.

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
- **Catawiki**: **asta selezionata**, non un listino. Niente push: candidatura di lotti a mano. Ottimo
  per il pezzo unico di pregio, **flusso manuale a sé**.
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
- Google Shopping via **feed di Woo**. Poi eventualmente **Etsy** (un canale per volta).
- **Catawiki manuale** quando c'è il pezzo unico giusto.

> Tappa 1 dà ritorno subito; vendita e marketplace vengono dopo, su decisioni che maturano
> **vendendo davvero**, non a tavolino.

---

## 6. Decisioni ancora aperte (da chiudere prima/durante Tappa 1)
- [ ] **Ciclo di vita** del progetto: la sequenza di §3 va bene a **pezzo singolo** o serve gestire
      **lotti/serie + stock**?
- [ ] **Peso di Catawiki/aste** vs prodotto replicabile a listino (cambia quanto investire sul
      "pezzo unico di pregio").
- [ ] **Serve Woo al lancio?** Realisticamente: finché ci sono 2-3 pezzi e zero traffico sul sito
      tuo, Etsy + "scrivimi per acquistare" + fattura a mano vende uguale a costo zero. Woo quando
      c'è davvero un catalogo e traffico da convertire.
- [ ] Campi definitivi di `progetti` (§2.1) — affinare su come lavori davvero.

---

## 7. Prossimo passo concreto (quando si parte)
1. Confermare il **ciclo di vita** (§3) — è il cuore della dash nuova.
2. Congelare i **campi di `progetti`** (§2.1).
3. Tappa 1: DDL in `ardy-migrate.php` → `ardy-progetti-api.php` → `ardy-design-app.html` (theming-ready)
   → agganciare il motore fasi/reel. Su branch, niente impatto sulla dash live.
