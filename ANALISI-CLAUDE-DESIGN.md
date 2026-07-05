# Analisi — Adottare temi/layout da "Claude Design" su Ardy Agent

> Domanda (18/06/2026): voglio usare molti dei layout che vedo in Claude Design.
> Cosa possiamo fare NOI per favorire l'inserimento di un tema generato da Claude,
> quali istruzioni dare, qual è la procedura più snella, con quali rischi e fattibilità.

---

## TL;DR (la versione in tre righe)
- Il fattore che rende un tema "drop-in" è avere **un solo strato di stile** (design token + classi
  componente). Il dashboard **ha già** un buon sistema di token (`:root` in `ardy-michela-app.css`),
  ma **363 `style="…"` inline** nell'HTML (più stili inline generati dal JS) **scavalcano** qualsiasi
  tema: è questo l'attrito numero uno.
- Procedura più snella: **prima** spostiamo gli stili inline su classi/token (lavoro incrementale,
  a basso rischio), **poi** diamo a Claude un "contratto" (lista token + nomi classi + vincoli) e ci
  facciamo restituire **un solo `theme.css` drop-in** che ridefinisce token e componenti, senza
  toccare l'HTML/JS.
- Fattibilità: **dashboard = alta** (è roba nostra), **widget webchat = media**, **sito WordPress/Divi
  = bassa** (lì il tema è Divi, non sostituibile con un artifact Claude; al massimo CSS mirato).

> **Aggiornamento (05/07/2026) — parte della Fase 0 è già fatta.** Con l'audit di usabilità
> (`AUDIT-USABILITA-DASH.md`) è stato introdotto uno **strato di componenti bottone** in
> `ardy-michela-app.css` (`.btn` neutro · `.btn--primary/--accent/--danger/--link/--menu`, con
> `.btn--sm/--block/--grow`) e una **scala tipografica** (`--fs-xs/sm/base/md`). **Tutti gli
> `style=""` inline dei bottoni** (header, sidebar, dettaglio, modali JS) sono stati migrati a
> queste classi/token. Restano da migrare gli inline **non-bottone** (layout di griglie/modali):
> quelli sono l'attrito di theming ancora aperto, ma il numero è molto sotto i 363 di partenza e
> il grosso della gerarchia visiva ora passa da un unico strato. Le fasi 1-2 (contratto di tema +
> `theme.css` su staging) diventano più vicine.

---

## 1. Dove si applicherebbe un tema (3 superfici diverse, NON una sola)

| Superficie | File | Controllo nostro | Tematizzabile da Claude? |
|---|---|---|---|
| **Dashboard interna** (Michela/Andrea) | `ardy-michela-app.html` + `ardy-michela-app.css` | Totale | **Sì, alta** (con prep) |
| **Widget webchat** (clienti) | `ardy-chat-site.js`, `ardy-chat-corsi.js` | Totale (UI iniettata da JS) | **Sì, media** |
| **Widget pagina lavorazione** (clienti) | `ardy-widget-lavorazione.js` | Totale | Sì, media |
| **Sito pubblico** (ardy-lab.it) | WordPress + tema **Divi** | Parziale (è Divi) | **No / solo CSS override** |
| **PDF preventivo** | `ardy-preventivo.php` (mPDF) | Totale, ma è print-CSS | Caso a parte (non "tema UI") |

> Conseguenza pratica: **"adottare un tema" non è un'azione sola**. Conviene iniziare dalla
> **dashboard** (massimo controllo, massimo beneficio quotidiano per Michela) e trattare le altre
> superfici come progetti separati.

---

## 2. Cosa rende un tema "drop-in" (e dove siamo oggi)

Un tema si innesta facilmente quando **tutto lo stile passa da un unico strato**:
1. **Design token** (colori, spaziature, raggi, ombre, font) in `:root`.
2. **Classi componente** (`.btn-pubblica`, `.card`, `.badge`, …) che usano SOLO quei token.
3. **Zero stile "cablato" nel markup**.

Stato attuale di Ardy:
- ✅ **Token presenti e semantici**: `ardy-michela-app.css` ha già `--bg --surface --surface2 --border
  --accent --accent2 --text --text-dim --text-mid --red --green --blue --orange --yellow`.
  Questa è una base ottima: un tema "colori" si potrebbe già fare ridefinendo questi token.
- ✅ **CSS esterno** (1.337 righe) già separato dall'HTML.
- ⚠️ **363 `style="…"` inline** in `ardy-michela-app.html` + stili inline creati dal JS
  (`el.style.cssText = …`). Gli stili inline hanno **specificità massima**: un `theme.css` NON riesce
  a sovrascriverli → il tema verrebbe "skinnato a metà", incoerente. **Questo è il vero blocco.**
- ⚠️ Manca un **token di spaziatura/raggio/ombra/tipografia** dedicato (oggi i valori sono spesso
  letterali nel CSS): un buon tema Claude vuole anche `--space-*`, `--radius-*`, `--shadow-*`, scala
  tipografica. Da introdurre per sfruttare davvero i layout.

---

## 3. Procedura più snella (consigliata, per fasi)

### Fase 0 — Prep "theming-ready" (la facciamo noi, una volta)
1. **Estrarre gli stili inline → classi/token** in `ardy-michela-app.html` (e negli `style.cssText`
   del JS). Incrementale, per blocchi, testando a vista. È il 70% del lavoro ma sblocca tutto.
2. **Ampliare i token**: aggiungere scala di spaziatura/raggio/ombra/tipografia in `:root` e usarli
   nel CSS al posto dei valori letterali.
3. **Congelare un "contratto di tema"**: un piccolo documento (mezza pagina) con:
   - l'elenco dei **token** (nome + significato),
   - l'inventario delle **classi componente** (con screenshot),
   - i **vincoli** (vedi Fase 1).

### Fase 1 — Istruzioni a Claude Design (il "contratto")
Quando chiediamo un tema, diamo SEMPRE a Claude:
- **Screenshot** delle schermate attuali (stato di partenza).
- Il **`:root` attuale** + l'**inventario classi** (il contratto della Fase 0).
- Vincoli espliciti:
  - "Restituisci **un solo file `theme.css` drop-in**: ridefinisce SOLO i token in `:root` e gli stili
    delle classi componente esistenti. **Non rinominare classi/id, non cambiare l'HTML, non aggiungere
    librerie/build step**."
  - "**Vanilla CSS**, nessun framework (no Tailwind/Bootstrap), nessun `@import` esterno a runtime."
  - "Tema **scuro**, font già in uso (DM Sans / Bebas Neue / DM Mono) o alternative web-safe."
  - "**Contrasto AA**, mobile-first, niente regressioni di leggibilità."
  - "Mantieni i **nomi dei token** esistenti; se ne servono di nuovi, **aggiungili** senza rimuovere i
    vecchi."
- Output desiderato: `theme.css` + una nota di cosa è cambiato.

### Fase 2 — Applicazione e verifica
1. Applicare su **branch** dedicato.
2. Deploy su una **copia di staging** (es. `ardyagent.ardy-lab.it/preview/`) protetta da Basic Auth,
   NON sul dashboard live.
3. **Eyeball** su desktop + mobile; check rapido che gli `onclick`/handler funzionino (le classi/id
   non devono essere cambiate).
4. Se ok → promuovere; altrimenti iterare passando a Claude gli screenshot dei punti rotti.

> Per il **widget webchat** lo stesso schema, ma il "contratto" sono le regole CSS iniettate dal JS:
> conviene prima spostare quegli stili in un `<style>` unico/classi, poi farsi dare il tema.

---

## 4. Rischi (onesti) e mitigazioni

| Rischio | Impatto | Mitigazione |
|---|---|---|
| **Stili inline scavalcano il tema** (363 inline + JS) | Alto — tema incoerente "a chiazze" | Fase 0: migrare inline → classi PRIMA di chiedere il tema |
| **Claude rinomina classi/id** → si rompono gli handler JS | Alto | Vincolo esplicito "non rinominare nulla"; review del diff; test click |
| **File HTML unico e vivo** (≈268 KB, ~3.400 righe JS inline) | Medio | Lavorare a piccoli blocchi su branch + staging; `node --check` sul JS |
| **WordPress/Divi**: un artifact Claude non è un tema Divi | Medio | Non promettere "tema" sul sito: solo CSS override mirati, o pagina custom fuori da Divi |
| **Nessuna preview locale** per WP/PDF | Medio | Staging dedicato; il PDF si verifica solo sul reso (mPDF non gira in locale) |
| **Tema "bello" ma fuori brand** (dorato/legno Ardy) | Basso/Medio | Dare a Claude la palette brand come vincolo, non come suggerimento |
| **Drift**: il tema generato diverge dal contratto col tempo | Basso | Tenere il contratto come fonte di verità; ogni tema nuovo lo rispetta |

---

## 5. Fattibilità — verdetto per superficie

- **Dashboard (interna)** — **ALTA**, a una condizione: fare prima la Fase 0 (inline→classi + token
  estesi). Dopo, cambiare "pelle" diventa davvero un drop-in di `theme.css`, e si possono tenere
  **più temi** selezionabili (anche un toggle chiaro/scuro o temi stagionali) con costo marginale.
- **Widget webchat / pagina lavorazione (clienti)** — **MEDIA**: fattibile, ma prima va consolidato
  lo stile iniettato dal JS in classi. Beneficio alto (è ciò che vede il cliente).
- **Sito WordPress (Divi)** — **BASSA** come "tema Claude": Divi è il tema. Realistico solo CSS mirato
  o pagine custom servite fuori da Divi. Non è il posto giusto per "adottare un layout Claude".
- **PDF** — fuori scope "tema UI" (è print-CSS, già curato con `PDF_CACHE_VER`).

### Raccomandazione
Partire SOLO dalla **dashboard**:
1. Fase 0 (prep theming-ready) — è il vero investimento, ma a basso rischio e utile comunque.
2. Poi chiedere a Claude **1 `theme.css`** col contratto sopra, applicarlo su staging.
Così validiamo il modello "tema drop-in" su una superficie controllata, e solo dopo decidiamo se
estenderlo al widget cliente. Il sito WordPress resta un capitolo a parte (CSS override).

---

## 6. Prossimo passo concreto (quando si riprende)
- [ ] Decidere la superficie pilota (proposta: **dashboard**).
- [ ] Fase 0: estrarre gli stili inline → classi/token (incrementale, su branch) + ampliare i token
      (spaziatura/raggio/ombra/tipografia).
- [ ] Scrivere il "contratto di tema" (token + inventario classi + screenshot + vincoli).
- [ ] Predisporre una **staging** dietro Basic Auth per provare i temi senza toccare il live.
- [ ] Chiedere a Claude il primo `theme.css` e iterare su staging.
