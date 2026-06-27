# Galleria Diffusa — Piano Tecnico MVP

> Stato: **proposta da approvare** · Data: 2026-06-06 · Branch: `claude/quirky-davinci-F5LBY`
> Scope deciso con il committente: **Storytelling + Acquisto** (le altre feature sono roadmap futura).
>
> **Nome:** il progetto si chiama **Galleria Diffusa** (ex "Ardy Experience"). Pagina pubblica:
> `ardy-lab.it/galleria-diffusa`. (Il nome del file resta storico.)
>
> **Aggiornamento giu 2026 (lancio):** il collegamento oggetto → storia è **solo via QR code**
> (l'ospite inquadra e si apre la pagina). Niente altre tecnologie di tag o di "tracciabilità"
> complessa: si punta alla massima semplicità.

---

## 0. In una riga

Una **web app mobile-first** che si apre scansionando un **QR code** accanto a un
oggetto in un B&B, racconta la storia dell'oggetto (audio + testo multilingua), mostra materiali e
CO₂ risparmiata + certificato di autenticità, e permette di **comprarlo o ordinarlo su misura** con
pagamento reale. Più un **backoffice** per caricare oggetti, generare i QR e gestire ordini.

**Non è un'app da App Store.** Si apre da URL via **QR code**: zero download, zero attrito. Questo è
voluto e coerente con lo storyboard (App Clip/WebApp).

---

## 1. Cosa NON facciamo nell'MVP (e perché)

Decisioni nette, prese consapevolmente per non bruciare budget e tempo:

| Escluso dall'MVP | Perché | Quando |
|---|---|---|
| **Marketplace secondario + royalties** | Non esiste un mercato finché non hai venduto il primo pezzo. | Fase 3+ |
| **Visualizzatore AR** | Costoso, marginale per la conversione iniziale. | Fase 3 |
| **Traduzione "AI naturale" runtime** | Bastano traduzioni curate salvate in DB. L'AI serve eventualmente in fase di caricamento, non in produzione. | Caricamento (Fase 2) |
| **Green dashboard personale utente** | Richiede account utente persistenti. L'MVP è anonimo/guest. | Fase 2-3 |

Il certificato di autenticità nell'MVP = **pagina pubblica `/cert/{codice}` con dati firmati
(hash) + PDF**. Dà il valore percepito di "tracciabilità" con la massima semplicità.

---

## 2. Stack tecnico (coerente con il repo esistente)

Riutilizziamo esattamente le fondamenta già presenti, niente tecnologie estranee all'hosting:

- **Backend:** PHP 8 + PDO/MySQL (riuso di `ardy-db.php`, `ardy-config.php`).
- **Frontend:** HTML + CSS + JS vanilla mobile-first (come `ardy-michela-app`). Niente framework JS.
- **Email:** PHPMailer (già nel repo) per conferme ordine.
- **Pagamenti:** **Stripe Checkout** (redirect hosted) — PCI a carico di Stripe, zero dati carta sul
  nostro server. Webhook Stripe → PHP per confermare l'ordine.
- **QR code:** generato dal backoffice con l'URL dell'oggetto (`/o/{slug}`) e stampato su
  targhetta/etichetta accanto al pezzo. Nessun hardware da scrivere: si inquadra e basta.
- **Hosting:** lo stesso cPanel/WHM esistente. Sottocartella/sottodominio dedicato, es.
  `experience.ardy-lab.it` o path `/x/`.

Costo tech reale dell'MVP: **vicino a zero in licenze** (solo le fee Stripe per transazione).

---

## 3. Modello dati (nuove tabelle MySQL)

```sql
-- Oggetti rigenerati
CREATE TABLE ardy_x_objects (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(64) UNIQUE,        -- usato nell'URL del QR: /o/{slug}
  status        ENUM('draft','available','reserved','sold') DEFAULT 'draft',
  title         VARCHAR(160),
  bnb_id        INT NULL,                  -- in quale B&B si trova
  price_cents   INT,                       -- prezzo in centesimi
  currency      CHAR(3) DEFAULT 'EUR',
  co2_saved_kg  DECIMAL(8,2),
  materials     JSON,                      -- elenco materiali/origine
  artisan       VARCHAR(160),
  cert_code     VARCHAR(40) UNIQUE,        -- codice certificato pubblico
  cert_hash     VARCHAR(64),              -- hash firma per verifica
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Contenuti multilingua (storytelling) per oggetto
CREATE TABLE ardy_x_object_content (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  object_id   INT,
  lang        CHAR(2),                     -- 'it','en','de',...
  story_text  TEXT,
  audio_url   VARCHAR(255) NULL,
  UNIQUE KEY (object_id, lang)
);

-- Media (foto/gallery)
CREATE TABLE ardy_x_object_media (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  object_id  INT,
  url        VARCHAR(255),
  sort       INT DEFAULT 0
);

-- B&B partner
CREATE TABLE ardy_x_bnb (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(160),
  city          VARCHAR(80),
  commission_pct DECIMAL(5,2) DEFAULT 0,
  contact_email VARCHAR(160)
);

-- Ordini
CREATE TABLE ardy_x_orders (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  object_id       INT,
  bnb_id          INT NULL,                -- per calcolo commissione
  type            ENUM('buy_now','custom') DEFAULT 'buy_now',
  customer_name   VARCHAR(160),
  customer_email  VARCHAR(160),
  shipping_json   JSON,                    -- indirizzo, paese, note custom
  amount_cents    INT,
  currency        CHAR(3) DEFAULT 'EUR',
  status          ENUM('pending','paid','shipped','delivered','cancelled') DEFAULT 'pending',
  stripe_session  VARCHAR(120) NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## 4. Endpoint / file nuovi (naming coerente: prefisso `ardy-x-`)

**Frontend pubblico (ospite del B&B)**
- `ardy-x-object.html` — pagina oggetto mobile-first: foto, audio storytelling, materiali, CO₂,
  link certificato, CTA Buy Now / Custom Order. Selettore lingua.
- `ardy-x-cert.html` — pagina pubblica certificato `/cert/{code}`: verifica autenticità + PDF.

**API pubbliche**
- `ardy-x-object-api.php?slug=...&lang=...` — restituisce JSON dell'oggetto + contenuto lingua.
- `ardy-x-checkout.php` — crea sessione Stripe Checkout (buy_now) o richiesta custom → email.
- `ardy-x-stripe-webhook.php` — riceve evento `checkout.session.completed`, marca ordine `paid`,
  oggetto `sold`, invia email conferma (PHPMailer) e notifica al B&B.
- `ardy-x-cert-api.php?code=...` — dati certificato per verifica.

**Backoffice (riservato, dietro login esistente)**
- `ardy-x-admin.html` — pannello: crea/modifica oggetti, carica foto/audio, scrive testo per
  lingua, genera slug + QR code, vede stato ordini e commissioni B&B.
- `ardy-x-admin-api.php` — CRUD oggetti/contenuti/media/ordini.

Routing pulito (`/o/{slug}`, `/cert/{code}`) via `.htaccess` (già presente nel repo).

---

## 5. Flussi principali

**A. Scoperta → Storytelling (ospite)**
1. Ospite inquadra il QR code → si apre `https://experience.ardy-lab.it/o/poltrona-monti-01`.
2. `ardy-x-object.html` chiama `ardy-x-object-api.php`, rileva lingua (browser/selettore).
3. Mostra foto + player audio + storia + materiali + CO₂ + link "Vedi certificato".

**B. Acquisto immediato**
1. CTA "Buy Now" → `ardy-x-checkout.php` crea Stripe Checkout Session.
2. Redirect a Stripe (carta gestita da loro). Al successo, webhook → ordine `paid`, oggetto `sold`.
3. Email conferma all'ospite + email notifica al B&B (per commissione e logistica).

**C. Custom order**
1. CTA "Custom Order" → form (variante/colore/note + contatto).
2. Salvato come ordine `custom/pending` + email al team ARDY. Nessun pagamento immediato (prima
   serve preventivo). Si chiude poi via flusso preventivi già esistente nel repo.

**D. Certificato**
- `/cert/{code}` mostra dati oggetto + hash verificabile + PDF scaricabile. Pubblico, condivisibile.

---

## 6. Sicurezza & operatività

- Credenziali Stripe in `ardy-config.php` (già fuori dal repo via `.gitignore`).
- Webhook Stripe con verifica firma.
- Backoffice dietro il login esistente (`ardy-setup-login.php` / sessione).
- Upload media in `ardy-uploads/` (già in `.gitignore`).
- Rate limiting riusando `ardy-rate-limit/`.
- Niente dati carta mai sul nostro server.

---

## 7. Roadmap a fasi

**Fase 1 — Storytelling (settimane 1–2)**
Tabelle DB · `ardy-x-object.html` + API · pagina certificato · backoffice CRUD base · 1 oggetto demo
reale navigabile via QR. → *Dimostrabile in un B&B pilota.*

**Fase 2 — Acquisto (settimane 3–4)**
Stripe Checkout + webhook · email conferma (ospite + B&B) · custom order form · gestione stato
ordini e commissioni nel backoffice.

**Fase 3 — Solo se i numeri lo chiedono**
Account utente + green dashboard · AR preview.

---

## 8. Decisioni aperte (servono risposte prima di Fase 1)

1. **Dominio:** sottodominio `experience.ardy-lab.it` o path sotto il dominio esistente?
2. **Lingue al lancio:** IT + EN bastano per il pilota? (consiglio: sì)
3. **Audio storytelling:** voce reale registrata o voce sintetica (TTS)? Impatta i contenuti.
4. **Stripe:** account già esistente o da creare? Servono le chiavi per la Fase 2.

---

## 9. Verdetto onesto

Il progetto su carta vuole essere 6 prodotti insieme con un budget da uno. Questo piano taglia il
70% della complessità (AR/marketplace) che porta lo 0% del valore iniziale, e costruisce il 90%
del valore percepito — *"Touch to hear my story" + compralo* — in ~4 settimane sullo stack che già
hai. Tutto il resto resta nella roadmap, da fare quando ci saranno vendite reali a giustificarlo.
