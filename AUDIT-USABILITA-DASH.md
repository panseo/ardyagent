# Audit — Usabilità della dashboard principale (`ardy-michela-app.html`)

> Domanda (05/07/2026): la dash principale risulta forse un po' troppo confusionaria.
> Analizzare l'usabilità e dire dove sta l'attrito, con priorità e interventi concreti.
>
> Metodo: ispezione euristica (Nielsen) del solo `ardy-michela-app.html` (6.696 righe) +
> `ardy-michela-app.css`, con misure oggettive prese dal codice. Nessuna intervista utente:
> è un'analisi esperta, non un test di usabilità sul campo (vedi §7).

---

## TL;DR (la versione in tre righe)
- **La dash non è "mal progettata", è *sovraccarica***: tutto è visibile, tutto ha lo stesso peso
  visivo, quindi niente guida l'occhio. I numeri lo dicono: **201 bottoni**, **237 `onclick`**,
  **12 stati** di pipeline, **461 `style=""` inline**, **~15 colori di bordo** diversi senza una
  legenda. La confusione è **densità + assenza di gerarchia**, non funzioni sbagliate.
- **Tre attriti dominano** (il resto è di contorno): (1) **tipografia minuscola e monospace**
  (il 78% del testo inline è ≤12px, 42 occorrenze a 10-9px); (2) **codice-colore incoerente** —
  i bottoni usano hex fuori palette invece dei token semantici, e i colori non vogliono dire
  niente di preciso; (3) **sidebar stipata** con 11 azioni prima ancora della lista clienti, di
  cui le più importanti (Morosi, Trasporti) **nascoste dietro un ingranaggio ⚙︎**.
- **La buona notizia**: quasi tutti gli interventi ad alto impatto sono **a basso rischio** e
  **non toccano la logica** (JS/handler invariati). Sono lavoro di CSS/gerarchia. E si incastrano
  con la "Fase 0 theming-ready" già decisa in `ANALISI-CLAUDE-DESIGN.md`: **stesso lavoro, doppio
  ritorno** (dash più leggibile *e* pronta per un tema).

---

## 1. Cosa vede l'utente quando apre la dash — la mappa reale

Layout a due colonne: **sidebar** (lista clienti + strumenti) a sinistra, **main** (dettaglio
cliente) a destra. All'avvio il main è vuoto ("SELEZIONA UN CLIENTE" + card note settimanali).

**Nella sola sidebar, prima di vedere un cliente, ci sono 11 controlli impilati:**

| Riga | Controlli |
|---|---|
| 1 | Titolo "Clienti / Lead" **+** campo Cerca **+** bottone 📦 Archivio — *tre cose in una riga* |
| 2 | `+ NUOVO` · `LIBRERIA` · `❓ GUIDA` · `🗒️ DA FARE` · `⚙︎` — *5 bottoni* |
| 3 | (dietro ⚙︎) `💸 MOROSI` · `🚚 TRASPORTI` · `📥 PDF` · `📚 CONOSCENZA` · `🧹 LIBERA SPAZIO` · `🗑 CESTINO` — *6 bottoni nascosti* |
| 4 | `🔍 RICERCA AVANZATA ▾` (apre 12 chip di filtro stato) |
| 5 | La lista clienti vera e propria |

**Il pannello dettaglio** (quando selezioni un cliente) impila: nome + 4 bottoni azione
(Dossier, Reinvia ringraziamento, Libera spazio, Cestino) → toggle stato → **6 sezioni a
fisarmonica** (👤 Dati e contatto, 💬 Conversazione, 📑 Preventivi, 🪵 Lavorazione, 🎬 Reel,
❓ FAQ), di cui *Lavorazione* contiene **un'altra fisarmonica annidata** ("🔨 Crea e pubblica
nuova fase"). Fisarmonica dentro fisarmonica.

**La pipeline ha 12 stati**: `LEAD · SOPRALLUOGO · PREVENTIVO · ACCONTO · RITIRATI ·
IN_LAVORAZIONE · COMPLETATO · CONSEGNATO · STANDBY · PERSO · ARCHIVIO (+ TUTTI)`.

---

## 2. I numeri (misurati dal codice, non a sensazione)

| Metrica | Valore | Perché conta |
|---|---:|---|
| Bottoni `<button>` | **201** | Superficie d'azione enorme in un file solo |
| Handler `onclick` | **237** | Idem, e ogni click è una scelta da fare |
| `style=""` inline | **461** | Scavalcano i token → incoerenza *e* niente tema (vedi `ANALISI-CLAUDE-DESIGN.md`) |
| Elementi modale/overlay | **75** | Molti flussi vivono in popup che coprono il contesto |
| Stati pipeline | **12** | Alto carico cognitivo; alcuni si sovrappongono (vedi §4) |
| `font-size` inline ≤12px | **151 su 193** (~78%) | Testo piccolo ovunque; 42 occorrenze a **10px o 9px** |
| Colori di bordo bottone distinti | **~15** hex | "Arcobaleno" senza significato semantico condiviso |
| Righe del file HTML | **6.696** | Un unico file vivo — vincolo pratico, va toccato a piccoli blocchi |

> I token semantici **esistono già e sono buoni** (`--red --green --blue --accent --surface`…),
> ma l'HTML li ignora usando hex letterali inline (`#8b6f3e`, `#3e6f8b`, `#6f5e8b`, `#d9534f`…).
> Il problema non è la mancanza di un sistema: è che **il sistema non viene usato**.

---

## 3. Diagnosi per euristiche — dove nasce la "confusione"

### 3.1 🔴 ALTA — Assenza di gerarchia visiva ("tutto urla, quindi niente urla")
Quasi ogni bottone è lo stesso oggetto: outline sottile 1px, sfondo trasparente, testo
monospace 10-11px. Non c'è distinzione tra **azione primaria** (es. "Aggiorna stato", "Crea
fase") e **azione rara/distruttiva** (es. "Libera spazio"). L'occhio non ha un punto d'ingresso.
- *Sintomo*: 201 bottoni quasi identici; il colore del bordo cambia ma non segue una regola.
- *Euristica*: "Estetica e design minimalista" + "Riconoscimento invece di ricordo".

### 3.2 🔴 ALTA — Codice-colore incoerente e senza legenda
I bordi usano ~15 hex diversi (oro, verde, blu, viola, 3 rossi diversi, 3 grigi diversi…).
Una legenda colore **esiste solo per i pallini di stato** (● sta per iniziare / nei tempi /
ritardo / da pianificare), **non per i bottoni**. Quindi il viola di "Conoscenza" e il viola di
"Ardy Design" e il blu di "Dossier" e il blu di "PDF" non comunicano una categoria: sono
decorazione. L'utente impara a memoria le posizioni, non legge i colori.
- *Euristica*: "Coerenza e standard".

### 3.3 🔴 ALTA — Tipografia troppo piccola e monospace ovunque
Il 78% del testo inline è ≤12px, con 42 punti a 9-10px, tutto in font monospace (`DM Mono`).
Il monospace è ottimo per codice/numeri, faticoso per etichette e testo di lettura. Per un uso
quotidiano e operativo (Michela/Andrea, non sviluppatori) è **il singolo fattore che più fa
percepire la dash come "densa e tecnica"**.
- *Euristica*: accessibilità/leggibilità (WCAG: 10px è sotto ogni raccomandazione).

### 3.4 🟠 MEDIA — Scoperta delle funzioni: strumenti importanti nascosti
`💸 MOROSI` (solleciti) e `🚚 TRASPORTI` (giornata consegne) sono **operazioni ricorrenti** ma
stanno dietro l'ingranaggio ⚙︎, insieme a operazioni rare (`🧹 Libera spazio`, `🗑 Cestino`,
`📚 Conoscenza`). Frequenza d'uso e collocazione non coincidono: il quotidiano è nascosto quanto
l'eccezionale.
- *Euristica*: "Visibilità dello stato/azioni" + legge di Fitts (le cose frequenti devono essere in vista).

### 3.5 🟠 MEDIA — Sidebar sovraccarica prima del contenuto
11 controlli sopra la lista clienti, con la **prima riga che impila titolo + ricerca +
archivio** (il campo Cerca ha `min-width:0` e `font-size:10px`: si comprime fino a diventare
minuscolo). La lista clienti — il vero contenuto della sidebar — inizia dopo tre "strati" di UI.
- *Euristica*: "Estetica e design minimalista".

### 3.6 🟠 MEDIA — Duplicazione delle azioni (dov'è la fonte di verità?)
Alcune azioni compaiono in **due posti**, il che fa dubitare se siano la stessa cosa:
- **Libera spazio**: nella sidebar (⚙︎ → `🧹 LIBERA SPAZIO`, agisce sui PERSI in blocco) *e*
  nel dettaglio cliente (`🧹 Libera spazio`, sul singolo cliente). Stesso nome, ambito diverso.
- **Cestino**: nella sidebar (⚙︎ → lista cestino) *e* nel dettaglio (`🗑 Cestino`, cestina questo).
- **Archivio**: raggiungibile sia dal bottone `📦 Archivio` in cima sia dal chip filtro `📦 ARCHIVIO`.

Non è un bug, ma senza etichette che distinguano "questo cliente" da "tutti", genera esitazione.
- *Euristica*: "Coerenza"; "Prevenzione dell'errore" (i due "Libera spazio" hanno effetti diversi).

### 3.7 🟡 BASSA — Fisarmoniche annidate e profondità
"Lavorazione → Crea e pubblica nuova fase" è una fisarmonica dentro una fisarmonica: per arrivare
all'azione servono più aperture. Con 6 sezioni + sotto-sezioni, il pannello dettaglio è lungo e
richiede molto scroll/click per orientarsi.
- *Euristica*: "Flessibilità ed efficienza d'uso".

### 3.8 🟡 BASSA — Molti flussi in modale (75 overlay)
Preventivo, dossier, import PDF, morosi, trasporti, conoscenza, editor note… vivono in popup a
tutto schermo. Ogni modale toglie il contesto sottostante; concatenarli (es. dettaglio → dossier
→ preventivo) fa perdere il filo di "dov'ero".
- *Euristica*: "Visibilità dello stato del sistema".

---

## 4. Nota a parte — i 12 stati della pipeline

12 stati sono tanti e alcuni si accavallano concettualmente:
- `CONSEGNATO` e `ARCHIVIO` (l'archivio *è* l'insieme dei CONSEGNATO fuori lista) — un chip di
  filtro che duplica uno stato genera l'ambiguità "sono cose diverse?".
- `STANDBY`, `PERSO`, `RITIRATI` sono più "attributi/parcheggi" che tappe dell'imbuto: mescolati
  agli stati del funnel (LEAD→…→CONSEGNATO) allungano la barra dei filtri a 12 chip.

Non propongo di toccare la macchina a stati (è logica di business collaudata, rischio alto). Ma
in **UI** si può **raggruppare** i filtri in "Attivi" (funnel) vs "Chiusi/Parcheggio"
(CONSEGNATO/ARCHIVIO/PERSO/STANDBY), riducendo la barra vista di default. Vedi §5.

---

## 5. Interventi consigliati — per rapporto impatto/rischio

Ordinati per **massimo beneficio, minimo rischio**. I primi tre non toccano la logica JS.

### Priorità 1 — 🎯 Alto impatto, 🟢 basso rischio (solo CSS/gerarchia)
1. **Scala tipografica leggibile**: portare il testo di lettura/etichette a **13-14px**, riservare
   il monospace 10-11px a numeri/prezzi/ID. Un solo intervento, cambia radicalmente la percezione
   di "densità". *Rischio*: nullo (nessun handler toccato); solo verificare che non rompa il
   layout su mobile (già c'è `@media 768px`).
2. **Gerarchia dei bottoni in 3 livelli**: una classe `.btn-primary` (piena, accent oro) per
   l'azione principale di ogni contesto, `.btn-secondary` (outline) per il resto, `.btn-danger`
   (rosso) **solo** per distruttivo (Cestino, Elimina, Libera spazio). Sostituire gli hex inline
   con i **token già esistenti** (`--accent`, `--red`, `--border`). *Rischio*: basso; è la
   "Fase 0 theming-ready" di `ANALISI-CLAUDE-DESIGN.md` fatta con criterio d'uso.
3. **Una legenda-colore che vale davvero**, o niente colore: se il colore dei bottoni deve
   significare qualcosa, ridurlo a **3-4 categorie** (primario / neutro / pericoloso / link ad
   altra app) e renderlo coerente; altrimenti togliere il colore ai bordi e lasciare solo la
   gerarchia del punto 2. *Rischio*: nullo.

### Priorità 2 — 🎯 Alto impatto, 🟡 rischio medio (piccola riorg. di markup)
4. **Riordinare la sidebar per frequenza d'uso**: promuovere `Morosi` e `Trasporti` fuori
   dall'ingranaggio (sono quotidiani); lasciare dietro ⚙︎ solo le operazioni rare/di manutenzione
   (Libera spazio, Cestino, Conoscenza, PDF). Dare al campo **Cerca una riga tutta sua**, non
   spartita col titolo e l'archivio. *Rischio*: medio (sposta markup, ma gli `onclick` restano).
5. **Raggruppare i 12 filtri** in "Attivi" / "Chiusi" con un default che mostra solo gli attivi.
   Chiarire la relazione CONSEGNATO↔ARCHIVIO con un'etichetta ("Archivio = consegnati, fuori
   lista"). *Rischio*: medio (solo presentazione dei chip, `setFilter` invariato).

### Priorità 3 — 🎯 Medio impatto, 🟡 rischio medio (chiarezza, non funzioni)
6. **Disambiguare le azioni duplicate**: rinominare per ambito — es. sidebar "🧹 Libera spazio
   (clienti persi)" vs dettaglio "🧹 Libera spazio (questo cliente)". Idem Cestino. *Rischio*: basso.
7. **Ridurre l'annidamento**: "Crea nuova fase" non dentro una seconda fisarmonica ma come azione
   diretta della sezione Lavorazione. *Rischio*: medio (tocca markup della sezione).

### Fuori scope di questo audit (annotati, non consigliati ora)
- Rifattorizzare la macchina a 12 stati (logica di business, rischio alto).
- Spezzare il file monolitico da 6.696 righe (utile in generale, ma è un progetto a sé).
- Sostituire i 75 modali con pannelli in-linea (grosso lavoro, valutare caso per caso).

---

## 6. Il filo che lega tutto (la vera raccomandazione)
La dash **non ha troppe funzioni** — ha **troppa uniformità**. Ogni elemento compete per
l'attenzione con lo stesso peso. Gli interventi di **Priorità 1** (tipografia + gerarchia bottoni
+ colore coerente) sono **quasi tutto il beneficio percepito** a **quasi zero rischio**, perché
non toccano una riga di logica: cambiano solo *quanto forte parla* ogni cosa. E sono esattamente
la **Fase 0 "theming-ready"** già messa a piano: farli ora significa **una dash più leggibile
oggi e pronta per un tema domani**, con un solo investimento.

Sequenza consigliata: **P1 → verifica su staging → P2 → P3**, a piccoli blocchi su questo branch,
`node --check` sul JS a ogni giro (il file è unico e vivo).

---

## 7. Limiti onesti di questo audit
- È un'**ispezione euristica esperta**, non un test con utenti reali. Dice *dove è probabile*
  l'attrito, non *quanto* Michela/Andrea inciampino davvero.
- **Prossimo passo per validare**: 15 minuti guardando l'utente reale eseguire 3 compiti tipici
  senza aiuto — *"segna un cliente come moroso e mandagli il sollecito"*, *"crea e pubblica una
  fase di lavorazione"*, *"trova i lavori da consegnare questa settimana"*. Dove esita o clicca la
  cosa sbagliata, lì c'è la conferma (o la smentita) di questa analisi. Il test batte l'opinione.

---

## 8. Prossimo passo concreto (quando si riprende)
- [x] Decidere se partire dalla **Priorità 1** — sì.
- [x] Introdurre scala tipografica (`--fs-xs/sm/base/md`) + sistema bottoni a 4 categorie
      semantiche nei token (`.btn` neutro · `.btn--primary` · `.btn--danger` · `.btn--link`,
      con `.btn--sm/--block/--grow`). Fatto in `ardy-michela-app.css`.
- [x] Migrare gli `style=""` dei bottoni della **prima schermata** ai token: link header,
      riga azioni sidebar (con `+ NUOVO` come unica azione primaria), pannello strumenti (⚙︎),
      header del pannello dettaglio (Dossier=link, Cestino=danger), link-app dell'empty state.
      Verificato a vista con render Chromium (sidebar pulita, nessun errore JS a runtime).
- [x] **Blocco 2** — barre-toggle e azioni statiche del dettaglio: aggiunte le varianti
      `.btn--accent` (emphasis oro non-pieno) e `.btn--menu` (barra a tutta larghezza); migrati
      `RICERCA AVANZATA`, `📦 Archivio`, la barra `🔄 Aggiorna stato` (accent) e i due `➕ Aggiungi`
      (primary). Verificato con render Chromium.
- [ ] **Prossimo blocco** — i bottoni generati da stringhe JS nei modali (cestino, morosi,
      trasporti, conoscenza): ~12 istanze, stesso sistema di classi, ma vanno toccate dentro i
      template literal → giro dedicato e prudente.
- [ ] (Opzionale) 15 min di test-utente sui 3 compiti del §7 per dare priorità basata sui dati.
