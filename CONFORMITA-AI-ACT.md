# Conformità AI Act (Reg. UE 2024/1689) — Ardy Lab

> **Nota:** documento tecnico-organizzativo interno, **non un parere legale**.
> Fotografa come il sistema "Ardy/Sole" si colloca rispetto all'AI Act e cosa
> fare entro il **2 agosto 2026**. Per la firma finale far validare da un legale.
>
> Ultimo aggiornamento: 27/06/2026 · Titolare: Ardy di Michela Panella (P.IVA 17633931005)

---

## TL;DR

**Siamo sostanzialmente in regola.** Il sistema AI di Ardy Lab ("Sole") è a
**rischio limitato** (limited risk): **non** è vietato e **non** è ad alto
rischio. Gli unici obblighi che ci riguardano sono **leggeri**:

1. **Trasparenza** (art. 50): dire chiaramente che si parla con un'AI. → Già fatto, va solo rafforzato nel primo messaggio.
2. **Alfabetizzazione AI** (art. 4): chi usa il sistema deve sapere come funziona. → Basta una nota interna (vedi sotto), già pronta in fondo.

Nessun obbligo di registrazione UE, valutazione di conformità, marcatura CE,
DPIA AI o sistema di gestione del rischio: quelli valgono solo per i sistemi
**ad alto rischio**, che non è il nostro caso.

---

## 1. Le date dell'AI Act (e quale ci riguarda)

| Data | Cosa diventa applicabile | Ci riguarda? |
|---|---|---|
| 1 ago 2024 | Entrata in vigore | — |
| **2 feb 2025** | Pratiche **vietate** (art. 5) + **alfabetizzazione AI** (art. 4) | ✅ Sì (art. 4) — già applicabile |
| 2 ago 2025 | Obblighi sui **modelli GPAI** + governance + sanzioni | Riguarda **Anthropic** (fornitore del modello), non noi |
| **2 ago 2026** | **Obblighi di trasparenza (art. 50)** + sistemi ad alto rischio Allegato III | ✅ Sì (art. 50) — **questa è la nostra scadenza** |
| 2 ago 2027 | Alto rischio Allegato I (prodotti regolamentati) | No |

> La "scadenza del 2 agosto" che conta per Ardy Lab è il **2 agosto 2026**:
> da quel giorno scattano gli obblighi di **trasparenza** dell'art. 50.

---

## 2. Classificazione del nostro sistema

**Cos'è:** "Sole", assistente AI conversazionale basato sul modello **Claude di
Anthropic** (un modello GPAI), presente su due superfici:
- **Assistenza** su `ardy-lab.it`: chatbot sito + widget lavorazioni + WhatsApp +
  chat corsi. Usi: informazioni, qualifica lead, supporto clienti, bozze
  testi/email, generazione reel/post.
- **Vendita** su `object.ardy-lab.it` (ecommerce WooCommerce dedicato): widget
  chat per-oggetto sulla scheda prodotto (`ardy-object-chat.js` +
  `ardy-object-proxy.php`), che racconta il pezzo, conferma il prezzo di listino
  e accompagna all'acquisto senza pressioni. **In programma:** consigli di
  prodotti correlati (recommender) — vedi §3, punto trasparenza recommender.

**Ruoli ai sensi dell'AI Act:**
- **Anthropic** = fornitore del modello GPAI (ha i suoi obblighi dal 2 ago 2025, non nostri).
- **Ardy Lab** = **deployer** (utilizzatore) e, avendo messo "Sole" a disposizione del pubblico sotto il proprio nome, anche **provider del sistema AI** a valle. In entrambi i ruoli, per un sistema a rischio limitato, gli obblighi si riducono alla **trasparenza**.

**Livello di rischio: LIMITATO.** Verifica voce per voce:

| Categoria AI Act | Si applica? | Perché |
|---|---|---|
| Pratica **vietata** (art. 5) — manipolazione subliminale, social scoring, ecc. | ❌ No | Sole non fa nulla di tutto questo |
| **Alto rischio** (Allegato III) — credito, lavoro/selezione personale, biometria, istruzione, servizi essenziali, giustizia | ❌ No | Restauro mobili + vendita oggetti design: nessun ambito dell'Allegato III (un recommender di prodotti su un ecommerce **non** è alto rischio) |
| Riconoscimento **emozioni** / categorizzazione **biometrica** | ❌ No | Non usato |
| **Decisioni automatizzate** con effetti giuridici | ❌ No | Prezzi/tempi/accordi sempre confermati da una persona (già scritto nei Termini §3.2 e Privacy §4) |
| **Rischio limitato** → trasparenza (art. 50) | ✅ **Sì** | È un chatbot che interagisce con persone |

---

## 3. Cosa dobbiamo fare entro il 2 agosto 2026

### ✅ Già a posto
- **Disclosure "è un'AI"**: i Termini §3.1 e la Privacy §4 dichiarano che Sole è un assistente virtuale basato su AI (Anthropic). Il prompt di Sole contiene un "CODICE ETICO AI" e dichiara la natura artificiale se richiesto.
- **Niente decisioni automatizzate vincolanti**: dichiarato nei Termini §3.2 e Privacy §4 (coerente anche con l'art. 22 GDPR).
- **Catena fornitore**: il modello è Anthropic, fornitore GPAI che gestisce i propri obblighi a monte.
- **[Trasparenza — art. 50(1)] FATTO.** Sole dichiara di essere un'AI nel **primo messaggio** su tutti i canali:
  - Chat sito `/ardy-agent/`: apertura "Sono Sole, l'assistente AI virtuale di Ardy Lab" + intestazione "Assistente Ardy Lab" + footer "Sole è un assistente AI — le informazioni vengono poi verificate da Michela" (copre anche il "no decisioni solo-AI").
  - Widget lavorazione, WhatsApp, chat corsi, Ardy Express, interior design, Galleria Diffusa: disclosure nel messaggio di benvenuto (`ardy-widget-lavorazione.js`, `ardy-whatsapp-system.txt`, `ardy-chat-corsi.js`, `ardy-chat-express.js`, `ardy-chat-interior-design.js`, `ardy-chat-experience.js`).
  - Prompt di sistema: regola di trasparenza esplicita in `ardy-system.txt` ("dichiara sempre di essere un'AI nel primo messaggio; non spacciarti per una persona").
  - Pulsante flottante: "Chatta con Sole, l'assistente AI".
  - **Ecommerce `object.ardy-lab.it`**: widget scheda prodotto — intestazione "assistente AI di Ardy", apertura "Sono Sole, l'assistente virtuale (AI) di Ardy Lab" e regola di trasparenza in `ardy-object-system.txt`.
  - **Corretto il 30/07/2026:** il saluto hardcoded del widget Galleria Diffusa
    (`ardy-chat-experience.js`) diceva solo "Sono Sole di Ardy Lab", senza la
    dicitura AI — unico canale rimasto scoperto, individuato controllando la
    webchat dal vivo. Ora allineato agli altri.

### 🔧 Da fare (poco lavoro)

1. **[Contenuti generati — art. 50(2) e 50(4)] Etichettatura contenuti AI.**
   - **Reel** (`ardy-crea-reel.php`): sono montaggi di **foto reali** delle
     lavorazioni, non immagini sintetiche di persone → l'obbligo "deepfake" non
     scatta. Nessuna azione obbligatoria; se in futuro si generassero immagini
     sintetiche realistiche, andranno marcate come "generato con AI".
   - **Post/FAQ/email** redatti da AI: per un'attività commerciale non rientrano
     nei "contenuti su temi di interesse pubblico" dell'art. 50(4). Nessun
     obbligo stretto; resta buona prassi la revisione umana prima della pubblicazione (già nel flusso: pubblicazione social è passo manuale).

2. **[Alfabetizzazione AI — art. 4] Nota interna.** Già obbligatorio da feb 2025.
   Per una micro-impresa basta che chi usa il sistema (Michela, Andrea) conosca
   limiti e uso corretto. → Vedi **Allegato A** in fondo: stamparlo/firmarlo e
   conservarlo. Fatto.

3. **[Documentazione] Tenere questo file aggiornato** come traccia delle scelte
   di conformità (utile in caso di domande del Garante/autorità).

4. **[Ecommerce — Termini & Privacy propri] ✅ PUBBLICATE.** `object.ardy-lab.it` ha
   ora le sue pagine legali online, verificate live il 30/07/2026:
   - https://object.ardy-lab.it/privacy-policy/ (agg. 14/07/2026)
   - https://object.ardy-lab.it/termini-e-condizioni/ (agg. 14/07/2026)
   - https://object.ardy-lab.it/rimborso_reso/ (agg. 14/07/2026)
   - https://object.ardy-lab.it/cookie-policy/ (CookieYes, 14/07/2026)

   Contenuto allineato alla bozza **`termini-privacy-object-ecommerce.md`**, incluse
   le clausole AI Act (assistente Sole dichiarato AI, nessuna decisione automatizzata
   solo-AI, fornitore Anthropic dichiarato, trasferimento extra-UE con garanzie
   adeguate). Non risulta se sia avvenuta la revisione legale formale — verificare
   con Michela.

   **Refusi minori da correggere sul sito (non bloccanti per il 2 agosto):**
   - Termini §8.1 dice ancora "Modalità e modulo: v. **Pagina 3**" — residuo del
     documento multi-pagina originale; sul sito live la pagina si chiama
     "**Rimborso e reso**", non "Pagina 3". Aggiornare il link/testo.
   - Cookie Policy: la sezione "Tipi di cookie utilizzati" non mostra l'elenco (probabile
     tabella CookieYes generata via JS, non visibile in un fetch statico) — controllare
     a occhio nel browser che la tabella sia effettivamente popolata.
   - Data della Cookie Policy in inglese ("July 14, 2026") mentre il resto del sito è
     in italiano — dettaglio estetico.

5. **[Trasparenza recommender — quando si attiveranno i consigli] DA FARE al
   momento giusto.** Oggi Sole parla **solo del singolo oggetto** in scheda; NON
   consiglia ancora altri prodotti. Quando aggiungerete i **consigli di prodotti
   correlati**, indicate in modo sintetico la logica ("suggerimenti generati
   automaticamente in base a X — es. materiale/stile/prezzo simile"), in una riga
   nella chat o nella pagina. Resta rischio limitato: nessun obbligo pesante, solo
   trasparenza. (Nota: la trasparenza dei sistemi di raccomandazione è materia
   soprattutto del **DSA**, che per un piccolo negozio ha obblighi minimi.)

> **Stato al 30/07/2026:** trasparenza (art. 50) **implementata e in produzione** su
> tutti i canali di assistenza **e sull'ecommerce** `object.ardy-lab.it`, incluse le
> **Termini & Privacy dedicate del negozio** (punto 4, verificate live). Corretto anche
> un gap nel prompt del lead da portale che rischiava di far partire una sessione
> webchat senza dichiarazione AI (vedi `ardy-proxy.php`). Restano solo: adempimenti
> organizzativi (firma Allegato A), conferma della revisione legale formale delle
> pagine ecommerce, e i tre refusi minori elencati al punto 4.

### ❌ NON serve (sono obblighi solo per l'alto rischio)
- Valutazione di conformità / marcatura CE
- Registrazione nella banca dati UE
- Sistema di gestione del rischio, log automatici, sorveglianza umana formalizzata
- Valutazione d'impatto sui diritti fondamentali (FRIA)

---

## 4. Note collaterali (NON AI Act, ma emerse nell'analisi)

- **Outreach a freddo + email finder** (`ardy-email-finder.php`, `ardy-enrich.php`,
  `ardy-outreach-*`): raccolta email da siti e invio di email commerciali a
  contatti B2B. Questo **non** è un tema AI Act, ma **GDPR/ePrivacy**: verificare
  base giuridica (legittimo interesse B2B), opt-out funzionante (c'è
  `ardy-unsubscribe.php`) e niente invii a indirizzi personali senza consenso.
  Da valutare a parte con il legale.
- L'Informativa Privacy è già aggiornata e coerente con l'uso dell'AI: ottima base.
- **Ecommerce `object.ardy-lab.it` (norme consumatori/GDPR, NON AI Act).** Uno
  shop che vende a consumatori aggiunge obblighi propri, da mettere nelle sue
  pagine legali: **diritto di recesso 14 giorni** (artt. 52 ss. Cod. Consumo),
  **informazioni precontrattuali** (caratteristiche, prezzo totale IVA inclusa,
  spese e tempi di spedizione, identità del venditore), **resi e garanzia legale
  di conformità**, condizioni di **pagamento** (gateway usato) e **cookie del
  checkout**. Il regime forfettario (no IVA) va indicato come già nei Termini
  principali. Da rifinire col legale insieme al punto 4 della §3.

---

## Allegato A — Nota di alfabetizzazione AI (art. 4) — Ardy Lab

**Uso dell'AI in Ardy Lab.** Utilizziamo un assistente virtuale ("Sole") basato
sul modello Claude di Anthropic per: rispondere a clienti e lead su sito e
WhatsApp, qualificare richieste, preparare bozze di testi/email e generare
materiale promozionale.

**Limiti che conosciamo e rispettiamo:**
- L'AI può sbagliare: prezzi, tempi e impegni sono **sempre** confermati da una persona.
- Nessuna decisione con effetti giuridici è presa solo dall'AI.
- I dati delle persone sono trattati solo per le finalità dichiarate nell'Informativa Privacy; non si chiedono dati sensibili non necessari.
- Sole dichiara di essere un'AI; non si spaccia per umano.
- I contenuti generati dall'AI sono rivisti prima della pubblicazione.

Persone formate: Michela Panella (Titolare), Andrea (collaboratore).

Data: 30/07/2026
Firma (Titolare): ____________________ Michela Panella

> Nota: firma solo la Titolare, in quanto responsabile dell'attività — Andrea,
> collaboratore, resta indicato come persona formata ma non deve controfirmare.
> La data sopra segna quando il documento è stato preparato/verificato; la firma
> resta da raccogliere da Michela.
