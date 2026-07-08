# Piano — Ecommerce `object.ardy-lab.it` (WooCommerce) + chat Sole per-oggetto

> Stato: **proposta da approvare** · Data: 2026-07-08 · Branch: `claude/ardy-ecommerce-woocommerce-5ve2xq`
>
> **Brief (08/07/2026):** ecommerce dei pezzi di design su **WooCommerce**, su un **terzo livello
> dedicato `object.ardy-lab.it`**. Per **ogni oggetto in vendita** una **webchat con Sole** che dà
> informazioni su *quel* pezzo. Le informazioni vengono da **"ardy design"** (la dash progetti), che
> va **strutturata** per alimentare la chat.
>
> **Decisioni prese col committente (08/07/2026):**
> 1. `object.ardy-lab.it` = **installazione WordPress+WooCommerce dedicata** sul sottodominio,
>    separata dal sito Divi principale.
> 2. Sole prende le info **da ardy design via API** (fonte unica di verità); non fa scraping della
>    pagina Woo e non duplica i dati dentro Woo.
> 3. Primo passo = **questo piano**, prima del codice.

---

## 0. In una riga

WooCommerce dedicato su `object.ardy-lab.it` per vendere i pezzi di design; su ogni scheda prodotto
un **widget chat di Sole** che risponde sul singolo oggetto leggendo — lato server — una **scheda
pubblica** ricavata dalla dash `progetti` (ardy design). I dati sensibili del progetto (costi, BOM,
margini, file STL, iterazioni R&D) **non escono mai**.

---

## 1. Cosa esiste già (e si riusa quasi tutto)

Questo piano **non parte da zero**: quasi tutti i pezzi ci sono, mancano tre innesti.

| Pezzo | File | Stato | Ruolo nel nuovo flusso |
|---|---|---|---|
| **Dash design (ardy design)** | `ardy-design-app.html` | ✅ Costruita | Fonte di verità dei contenuti prodotto |
| **Modello progetti** | tabella `progetti` (`ardy-migrate.php`) | ✅ Ha già `slug`, `descrizione`, `materiali` (pubblico), `scheda_tecnica`, `prezzo_vendita`, `woo_product_id`, `canali_vendita` | Base della scheda-Sole e del push Woo |
| **API progetti** | `ardy-progetti-api.php` | ✅ CRUD (dietro Basic Auth) | Da NON esporre pubblico; nasce un endpoint pubblico separato |
| **Pattern chat contestuale** | `ardy-widget-lavorazione.js` + `ardy-proxy-lavorazione.php` | ✅ In produzione | Template quasi identico della chat per-oggetto |
| **Proxy con allowlist CORS** | `ardy-proxy.php` (`$allowedOrigins`) | ✅ | Pattern CORS da riusare per il nuovo origin |
| **Architettura di vendita** | `PIANO-DASH-DESIGN.md` | ✅ Decisa | *Dash = master contenuti · Woo = master commercio · push a senso unico dash→Woo* |

**Mancano tre innesti (il 100% del lavoro nuovo):**
1. **Governance + campi pubblici** su `progetti` (la "scheda-Sole").
2. **Chat per-oggetto**: widget JS sulla scheda Woo + proxy Sole dedicato.
3. **Ponte push dash → Woo** (la colonna `woo_product_id` esiste, ma nulla la popola ancora).

> ⚠️ **Nota di allineamento.** In `TODO-PROSSIMI-TASK.md` c'è una decisione vecchia:
> *"niente WooCommerce → la vendita andrà su un agente dedicato a parte, **non Sole**"*. Il brief
> dell'08/07 **la ribalta**: WooCommerce **sì**, e la chat prodotto **è Sole**. A piano approvato,
> aggiornare quella nota.

---

## 2. Architettura

```
┌─────────────────────────────┐        push a senso unico        ┌────────────────────────────────┐
│  ARDY DESIGN (dash progetti)│  ───────────────────────────────▶ │  WooCommerce — object.ardy-lab.it │
│  DB micoperibg_ardyagent    │   ardy-object-push.php (dash→Woo) │  WP+Woo dedicato (no Divi)        │
│  tabella `progetti`         │   crea/aggiorna prodotto + slug   │  catalogo · carrello · pagamento  │
│  = FONTE DI VERITÀ          │   salva woo_product_id ↩          │  /prodotto/{slug}                 │
└──────────────┬──────────────┘                                   └────────────────┬─────────────────┘
               │                                                                    │ scheda prodotto
               │ scheda-Sole (SOLO campi pubblici)                                  │ + widget chat (JS)
               ▼                                                                    ▼
┌─────────────────────────────┐        legge lato server          ┌────────────────────────────────┐
│ ardy-object-scheda.php      │ ◀──────────────────────────────── │ ardy-object-chat.js (widget)     │
│ (public, read-only,         │       ardy-object-proxy.php       │ POST {slug, message, history}    │
│  whitelist di campi)        │ ─────────────────────────────────▶│ (CORS: object.ardy-lab.it)       │
└─────────────────────────────┘   costruisce il system prompt     └────────────────────────────────┘
        MAI: costi, BOM, margine, STL, iterazioni R&D
```

Tre superfici, un'unica fonte: la dash resta padrona dei contenuti, Woo del commercio, e Sole legge
una **proiezione pubblica** dei dati — mai il DB grezzo.

---

## 3. Strutturare "ardy design" — la scheda-Sole (governance dati)

Il cuore del "va strutturato per questo": decidere **quali campi del progetto sono pubblici** (Sole
può usarli) e **quali non escono mai**.

### 3.1 Campi che Sole PUÒ usare (già esistenti su `progetti`)
- `titolo`, `tipo`
- `descrizione` — racconto/concept
- `materiali` — **già documentato come "descrizione PUBBLICA per la listing"**
- `scheda_tecnica` — dimensioni/finiture (da curare: vedi §3.3)
- `prezzo_vendita` — *prezzo di listino* (pubblico sulla scheda Woo → Sole può **confermarlo**, non trattare/scontare; vedi §8)
- `copertina_url` + galleria (`progetto_galleria`, solo render/foto finite già pensate per l'articolo WP)

### 3.2 Campi nuovi da aggiungere a `progetti` (DDL in `ardy-migrate.php`, idempotente)
Per dare a Sole materiale ricco senza forzare tutto dentro `scheda_tecnica`:

| Campo | Tipo | Uso |
|---|---|---|
| `storia` | `TEXT NULL` | Il racconto del pezzo (origine, ispirazione, processo) — cuore delle risposte "raccontami questo oggetto" |
| `cura` | `TEXT NULL` | Manutenzione/pulizia/uso corretto (es. lampada: attacco, lampadina consigliata, no acqua) |
| `faq_pubbliche` | `TEXT NULL` (JSON `[{q,a}]`) | Q&A curate a mano dallo staff → risposte deterministiche su domande frequenti |
| `dimensioni` | `VARCHAR(160) NULL` | Misure sintetiche strutturate (LxPxH, peso) per risposte precise |
| `scheda_sole_pubblica` | `TINYINT(1) DEFAULT 0` | **Interruttore**: solo se `=1` la scheda-Sole è servita (evita che pezzi in bozza finiscano in chat) |

> Nessun nuovo dato di stock/quantità: lo **stock vive su Woo** (deciso in `PIANO-DASH-DESIGN.md`).
> Alla domanda "è disponibile?" Sole rimanda alla scheda del negozio, non inventa disponibilità.

### 3.3 Campi che Sole NON riceve MAI (whitelist, non blacklist)
La scheda-Sole si costruisce con un **elenco esplicito di campi ammessi**; tutto il resto è escluso
per costruzione. In chiaro, restano dentro la dash e **non transitano** dall'endpoint pubblico:
- `costo_produzione`, `scarto_pct`, `prezzo_vendita` come *costo*, **margine** (prezzo − costo)
- tabella `progetto_materiali` (BOM: filamento, ore stampa, ferramenta, manodopera…)
- `file_snapshot`, `cad_urls`, file STL/CAD, `render_urls` interni, `progetto_file`
- `progetto_iterazioni` (note R&D: "qui non torna, rifare")
- qualsiasi campo `*_at` operativo, `deleted_at`, `session_id`

### 3.4 Come si serve — `ardy-object-scheda.php` (nuovo, pubblico, read-only)
- `GET /ardy-object-scheda.php?slug={slug}` → JSON con **solo** i campi §3.1 + §3.2.
- Pubblico (**niente Basic Auth**), quindi **fuori** dal `<FilesMatch>` protetto del `.htaccess`.
- Serve solo progetti con `scheda_sole_pubblica = 1` e `deleted_at IS NULL`.
- Rate-limit per IP (riuso del pattern `ardy-rate-limit/` già in `ardy-proxy*.php`).
- **Non riusare `ardy-progetti-api.php`** (è CRUD dietro auth): file separato = superficie pubblica
  minima e verificabile a colpo d'occhio.

---

## 4. Nuovi file (naming coerente: prefisso `ardy-object-`)

| File | Dove gira | Auth | Ruolo |
|---|---|---|---|
| `ardy-object-scheda.php` | ardyagent | **Pubblico** | Scheda-Sole read-only (whitelist §3) |
| `ardy-object-proxy.php` | ardyagent | Pubblico + rate-limit | Chat Sole per-oggetto (clone di `ardy-proxy-lavorazione.php`) |
| `ardy-object-chat.js` | servito da ardyagent, iniettato su Woo | — | Widget chat sulla scheda prodotto |
| `ardy-object-system.txt` | ardyagent | — | Prompt di sistema Sole "commesso del negozio" (parte statica cacheabile) |
| `ardy-object-push.php` | ardyagent | Basic Auth (dash) | Push a senso unico dash → Woo (REST API Woo) |

Endpoint pubblici (`scheda`, `proxy`, `chat.js`) **non** vanno nel `<FilesMatch>` Basic-Auth di riga
80 del `.htaccess`; `ardy-object-push.php` **sì** (è azione da dash).

---

## 5. WooCommerce & dominio

- **Installazione**: WordPress+WooCommerce **dedicato** su `object.ardy-lab.it` (sottodominio nuovo su
  cPanel/WHM, DB WP separato). Non tocca il sito Divi principale né la dash.
- **Slug condiviso = chiave di join.** Lo `slug` del progetto diventa lo slug del prodotto Woo:
  URL `https://object.ardy-lab.it/prodotto/{slug}`. Così il widget conosce già lo `slug` e la
  scheda-Sole si risolve **senza mappe extra**. Il push salva anche `woo_product_id` su `progetti`
  (ridondanza utile per update mirati).
- **Iniezione del widget**: uno snippet minimo (functions.php figlio o mu-plugin) che, sulla pagina
  prodotto, stampa il `<div id="ardy-object-chat" data-slug="{slug}">` e carica `ardy-object-chat.js`
  da `ardyagent.ardy-lab.it`. Il tema Woo resta standard; il widget è additivo (come il widget
  lavorazione sul sito).

---

## 6. La chat per-oggetto (widget + proxy)

Clone diretto di `ardy-widget-lavorazione.js` / `ardy-proxy-lavorazione.php`, con tre differenze:

1. **Origine del contesto = server, non DOM.** Il widget manda **solo** `{slug, message, history}`.
   È il **proxy** a chiamare `ardy-object-scheda.php?slug=…` e a costruire il contesto. Motivo: il
   client non deve poter iniettare "dati oggetto" falsi nel prompt, e la scheda ricca (storia, cura,
   FAQ) è più di quanto ci sia nel DOM.
2. **CORS.** Il proxy usa l'**allowlist** stile `ardy-proxy.php` aggiungendo
   `https://object.ardy-lab.it` (l'hai notato: i proxy vecchi erano cablati sul dominio principale —
   `ardy-proxy-lavorazione.php` ha `Access-Control-Allow-Origin: https://ardy-lab.it` fisso, da non
   copiare così).
3. **System prompt "commesso", non "avanzamento lavori".** Niente calendario/visite/verifica cliente:
   qui Sole informa e invoglia all'acquisto, senza spingere.

**Struttura del prompt (caching-friendly, come già nel repo):**
- **Parte STATICA** (`ardy-object-system.txt`): identità Sole, tono, codice etico AI, regole di
  vendita (§8) → prefisso cacheabile condiviso da tutte le conversazioni.
- **Parte VOLATILE** (dopo): la scheda-Sole del singolo oggetto (titolo, storia, materiali, scheda
  tecnica, dimensioni, cura, FAQ, prezzo di listino) → non invalida il prefisso cacheato.

Riuso *as-is*: rate limiting per IP + limite giornaliero, `ardy-sanitize.php` (anti-sbrodolatura tool),
limiti input anti-injection (`mb_substr`), `ardy-net.php`.

---

## 7. Push dash → Woo (a senso unico)

- Da un pulsante nella dash design ("Pubblica su negozio") → `ardy-object-push.php`.
- Usa la **REST API di WooCommerce** (consumer key/secret in `ardy-config.php`, fuori dal repo).
- Mappa: `titolo→name`, `slug→slug`, `descrizione/storia→description`, `materiali/scheda_tecnica→
  short_description o attributi`, `prezzo_vendita→regular_price`, `copertina/galleria→images`.
- Crea o aggiorna (`woo_product_id` presente → update). Salva `woo_product_id` di ritorno su `progetti`.
- **A senso unico**: Woo non riscrive mai la dash. Lo stock/ordini restano su Woo (come deciso).
- Idempotente e riprovabile; nessun DDL qui (i campi ci sono già + i nuovi §3.2 in `ardy-migrate.php`).

---

## 8. Sicurezza & privacy

- **Whitelist, non blacklist** (§3.3): la scheda-Sole ammette campi per elenco esplicito → un campo
  interno nuovo non "trapela" per dimenticanza.
- **Interruttore `scheda_sole_pubblica`**: solo i pezzi marcati pubblici finiscono in chat.
- **Prezzo**: pubblico sulla scheda Woo, quindi Sole **può confermarlo**; ma **non** tratta, non
  sconta, non promette. (Diverso dalla regola "prezzi MAI" del proxy lavorazione, che vale sui lavori
  su commessa dei clienti — qui è un prodotto a listino.) *Da confermare, vedi §10.*
- **CORS** con allowlist esplicita del solo `object.ardy-lab.it` (+ ardyagent per test).
- **Anti prompt-injection**: input troncati; `ardy-sanitize.php`; il contesto oggetto è iniettato dal
  server, non dal client.
- **Credenziali Woo REST** in `ardy-config.php` (già in `.gitignore`).
- **Rate limit** riusando `ardy-rate-limit/`.
- Nessun dato di pagamento tocca i nostri server (li gestisce Woo/il gateway).

---

## 9. Flussi principali

**A. Il pezzo diventa prodotto (staff)**
Progetto in dash → compili storia/cura/FAQ/dimensioni, marchi `scheda_sole_pubblica=1` → "Pubblica su
negozio" → `ardy-object-push.php` crea il prodotto Woo con lo stesso slug e salva `woo_product_id`.

**B. Cliente sulla scheda prodotto**
Apre `object.ardy-lab.it/prodotto/{slug}` → lo snippet Woo carica `ardy-object-chat.js` col `data-slug`
→ clic sulla bolla → il widget manda `{slug, message}` a `ardy-object-proxy.php`.

**C. Sole risponde**
Il proxy legge `ardy-object-scheda.php?slug=…`, monta system statico + scheda oggetto, chiama Claude,
sanifica, risponde. Sole racconta il pezzo, materiali, cura, conferma il prezzo di listino, invoglia
all'acquisto; per stock/spedizioni rimanda alla scheda/checkout Woo.

**D. Acquisto**
Standard WooCommerce (carrello + gateway). Fuori dallo scope della chat.

---

## 10. Roadmap a fasi

**Fase 0 — Strutturare ardy design (fondazione dati)** ✅ *FATTA (vedi §13)*
DDL nuovi campi §3.2 in `ardy-migrate.php` · UI nella dash design per compilarli · endpoint pubblico
`ardy-object-scheda.php` con whitelist + `scheda_sole_pubblica`. → *Testabile subito con curl.*

**Fase 1 — Chat per-oggetto**
`ardy-object-proxy.php` (clone lavorazione, CORS nuovo, contesto server-side) · `ardy-object-system.txt`
· `ardy-object-chat.js` · snippet WP di iniezione. → *Un pezzo demo chattabile.*

**Fase 2 — Negozio + push**
WP+Woo su `object.ardy-lab.it` · `ardy-object-push.php` (REST Woo) · pulsante in dash · primo prodotto
reale pubblicato con chat attiva.

**Fase 3 — Rifiniture**
FAQ curate per i best-seller · eventuale apprendimento delle domande ricorrenti (riuso pattern
`ardy-conoscenza-appresa.php`) · feed marketplace da Woo (già previsto in `PIANO-DASH-DESIGN.md`).

---

## 11. Decisioni aperte (servono prima di Fase 1–2)

1. **Prezzo in chat**: Sole conferma il prezzo di listino (consigliato, è pubblico) o rimanda sempre
   alla scheda? (§8)
2. **Lingue**: la chat parte solo IT o serve anche EN al lancio? (impatta system + eventuale campo
   lingua nella scheda-Sole)
3. **Tema Woo**: tema standard/gratuito o serve coerenza brand col sito Divi? (impatta Fase 2)
4. **Credenziali Woo REST**: chi crea la key nel pannello Woo una volta installato.
5. **Conversazioni**: le chat prodotto vanno salvate (come `web_messaggi`) per analisi, o restano
   effimere? (privacy + valore dati)

---

## 12. Verdetto onesto

Il grosso c'è già: la dash design, il modello `progetti`, il pattern di chat contestuale collaudato e
l'architettura di vendita "dash contenuti / Woo commercio". Il lavoro vero è **tre innesti mirati** —
la scheda-Sole pubblica (con governance a whitelist), la chat per-oggetto (clone del widget
lavorazione con contesto lato server e CORS nuovo) e il push dash→Woo. La scelta di far leggere Sole
**da ardy design via API** è quella che tiene una sola fonte di verità e, soprattutto, tiene **fuori
dalla chat costi, margini e file** — che è il rischio numero uno quando la stessa entità è insieme
"progetto interno" e "prodotto in vendita".

---

## 13. Stato implementazione — Fase 0 (fondazione dati)

Implementata sul branch `claude/ardy-ecommerce-woocommerce-5ve2xq`. Solo backend dati + UI dash:
nessuna dipendenza da WooCommerce, testabile con `curl`.

**Cosa è stato fatto**
- **`ardy-migrate.php`** — nuove colonne su `progetti`: `storia`, `dimensioni`, `cura`,
  `faq_pubbliche` (JSON `[{q,a}]`), `scheda_sole_pubblica` (TINYINT, interruttore). Più un
  **indice UNIQUE** `idx_progetti_slug` su `slug`. Idempotente (colExists/indexExists).
- **`ardy-progetti-api.php`** — il `save` accetta e persiste i nuovi campi; **genera lo `slug`**
  dal titolo se mancante (univoco, non lo cambia se già presente → non rompe URL/aggancio Woo);
  le FAQ si salvano come JSON `[{q,a}]` da testo "Domanda | Risposta" per riga.
- **`ardy-object-scheda.php`** *(nuovo, pubblico, read-only)* — `GET ?slug=…` restituisce SOLO i
  campi whitelisted (slug, titolo, tipo, descrizione, storia, materiali, scheda_tecnica, dimensioni,
  cura, faq, prezzo_vendita, copertina_url). Serve solo progetti con `scheda_sole_pubblica = 1` e non
  eliminati. Costi/BOM/margine/file/iterazioni **non transitano**. CORS allowlist + rate-limit per IP.
- **`ardy-design-app.html`** — nel form progetto un blocco **"🟡 Scheda-Sole"**: storia, dimensioni,
  cura, FAQ, slug (con anteprima URL) e checkbox **"Visibile a Sole"** (`scheda_sole_pubblica`).

**Come testare dopo il deploy** (il migrate gira da `deploy.sh`)
1. In dash design: apri un progetto, compila la Scheda-Sole, spunta "Visibile a Sole", salva.
2. Verifica lo slug generato (mostrato sotto il campo) e la proiezione pubblica:
   ```bash
   curl -s "https://ardyagent.ardy-lab.it/ardy-object-scheda.php?slug=LO-SLUG" | jq
   ```
   Deve tornare `success:true` con i soli campi pubblici; con `scheda_sole_pubblica=0` → `404`.

**Prossimo (Fase 1):** `ardy-object-proxy.php` + `ardy-object-system.txt` + `ardy-object-chat.js`
(chat per-oggetto che legge questa scheda lato server) e lo snippet WP di iniezione su Kadence.
