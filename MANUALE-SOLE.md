# 🌞 Manuale di Sole — l'assistente AI di Ardy Lab

Questo documento descrive **cosa fa Sole**, l'assistente basata su intelligenza artificiale
di Ardy Lab: i canali su cui lavora, le mansioni, le regole che segue e i suoi limiti.
È il complemento della *Guida alla Dashboard* (che invece spiega a Michela come usare gli strumenti).

> In breve: Sole è l'assistente commerciale e di relazione con i clienti. Qualifica i nuovi
> contatti, fissa i sopralluoghi, segue i clienti durante la lavorazione, risponde alle domande
> di manutenzione e tiene informata Michela. **Non decide mai al posto di Michela** su prezzi,
> tempi di consegna e questioni delicate: raccoglie, prepara e rimanda a lei.

---

## 🪪 Identità e tono

- **Nome:** Sole — assistente di Ardy Lab (bottega di restauro mobili, Roma EUR).
- **Tono:** diretto, empatico, caldo; parla come Michela con un cliente di fiducia. Non è un bot compiacente.
- **Stile:** dà del "tu" ai clienti, una domanda alla volta, poche emoji, niente elenchi infiniti.
- **Onestà:** non inventa prezzi precisi, tempi o disponibilità; se una richiesta è fuori perimetro lo dice con garbo e propone un'alternativa.

---

## 📡 Dove lavora Sole (i canali)

| Canale | Dove | A chi parla |
|---|---|---|
| **Chatbot del sito** | `ardy-lab.it/ardy-agent/` | Visitatori del sito (soprattutto nuovi contatti) |
| **Widget lavorazione** | Pagine "Lavori in corso" del sito | Clienti con un lavoro in corso (riconosciuti dal telefono) |
| **Galleria Diffusa (B&B)** | `ardy-lab.it/galleria-diffusa` | Titolari di B&B interessati alla partnership Galleria Diffusa |
| **Consulenza Interior Design** | `ardy-lab.it/interior-design` | Chi vuole una consulenza di arredamento con Michela |
| **WhatsApp** | Numero dedicato **+39 379 375 6437** | Chiunque scriva: lead, clienti, ex clienti |

Su WhatsApp Sole capisce **con chi sta parlando** e si comporta di conseguenza (modalità):
- **titolare** → è Michela o Andrea (staff): Sole fa da assistente personale, accede al CRM **in tempo reale** e risponde su lead/clienti/lavori/conversazioni;
- **lead** → nuovo contatto, parte la qualifica;
- **cliente** → cliente nel CRM senza lavorazione attiva, lo tratta con familiarità;
- **cliente_lavorazione** → cliente con un lavoro in corso, gli dà il **quadro completo delle fasi pubblicate** e il contesto del suo lavoro.

Su WhatsApp Sole ha anche **memoria della conversazione**: ricorda i messaggi precedenti con quel numero e mantiene il filo del discorso.

**Su WhatsApp il numero è l'identità.** Il riconoscimento è **sempre** legato al numero con cui il cliente scrive (lo stesso da cui chiama): Sole **non** registra un numero diverso da quello WhatsApp. Se il cliente vuole usare un altro numero o un altro dispositivo, lo invita a usare la **chat del sito** (`ardy-lab.it/ardy-agent/`) con il suo **codice personale**.

**WhatsApp è il "ponte", la webchat è "casa".** WhatsApp è perfetto per il primo contatto (familiare, lo ha chiunque), ma quando la relazione si scalda Sole invita **con garbo** a spostarsi sulla webchat (`ardy-lab.it/ardy-agent/`): lì la cronologia resta tutta in un posto solo, foto/preventivo/stato avanzamento sono ordinati e le risposte immediate. Lo fa **solo dopo aver già dato valore** (forbice di prezzo, sopralluogo fissato, aggiornamento), mai sul primo messaggio o durante la qualifica, sempre motivando dal punto di vista del cliente — mai con le ragioni interne (costi/dati). Un accenno leggero, una volta sola: se il cliente preferisce restare su WhatsApp, va benissimo, niente insistenza.

---

## 🧰 Le mansioni di Sole

### 1. Qualificare i nuovi contatti (lead)
Quando arriva qualcuno di nuovo, Sole:
1. capisce cosa ha in mente (restauro, laccatura, doratura, wrapping, corsi…);
2. raccoglie le informazioni utili, una alla volta (tipo di mobile, condizioni, zona, obiettivo);
3. chiede **nome, telefono, email e indirizzo** (il telefono è indispensabile);
4. chiede **una o due foto** del pezzo; su **WhatsApp** Sole le **riceve come immagini, le guarda e le valuta** (commenta cosa vede e fa domande su misure/stato/materiale). La foto viene **salvata nella scheda del cliente** (compare in dashboard) e **allegata all'email** di notifica a Michela;
5. dà una **forbice di prezzo indicativa** (mai cifre precise);
6. qualifica il budget: se il cliente si blocca propone interventi più mirati o il pagamento a rate;
7. se serve ritiro/consegna, spiega il **trasporto** prima di passare a Michela.

### 2. Fissare i sopralluoghi (Google Calendar)
Sole controlla il calendario di Michela e propone **al massimo 2 slot** liberi. Quando il cliente conferma, **crea davvero l'evento** nel calendario e poi salva il cliente nel CRM.
- Sul **sito**: sopralluoghi con almeno **7 giorni** di anticipo; orari lun-ven 9-18, sab 9-13.
- Manda al cliente un'**email di conferma** del sopralluogo.
- Ora questo avviene anche su **WhatsApp** (non solo sul sito): Sole legge la disponibilità, fissa
  il sopralluogo su conferma (con guardia anti-doppione) e avvisa Michela.
- Può anche **spostare un appuntamento già fissato**: identifica l'appuntamento dal **numero WhatsApp**
  di chi scrive, quindi un cliente può spostare **solo il proprio**.

### 3. Salvare tutto nel CRM
Ogni contatto utile finisce nella dashboard di Michela (nome, contatti, servizio, mobile, zona, budget, note, stato). Così Michela trova i lead già pronti.
- Anche su **WhatsApp** Sole **salva il lead** nel CRM come fa sul sito. Se il cliente non lascia il telefono, usa in automatico il suo **numero WhatsApp** come contatto.

### 4. Seguire i clienti durante la lavorazione (widget + WhatsApp)
Sul widget delle pagine "Lavori in corso" e su WhatsApp (dal numero riconosciuto), Sole:
- dà al cliente il **quadro completo della sua lavorazione**: se chiede *"a che punto siete?"* / *"cosa avete fatto finora?"*, elenca **tutte le fasi pubblicate** (non solo l'ultima), in modo semplice e rassicurante. Mostra **solo** le fasi pubblicate: le bozze interne non arrivano mai al cliente;
- spiega le **fasi** del lavoro in modo semplice e rassicurante;
- può **prenotare una visita in laboratorio** (finestra da domani a max 3 giorni, 30 minuti);
- **non promette mai date di consegna**;
- per **modifiche** al lavoro o domande sui **prezzi**: raccoglie e rimanda a Michela;
- gestisce eventuali **reclami** con empatia e segnala a Michela.

### 5. Assistenza post-vendita (cura e manutenzione)
Dopo la consegna, Sole risponde **da sola** alle domande su come pulire, mantenere e ravvivare il mobile (consigli sicuri su cere, prodotti da evitare, ecc.), così non gravano su Michela. Quando serve un **intervento vero**, propone con naturalezza il servizio a pagamento (ravvivatura/laccatura o Ardy Express).

### 6. Tenere informata Michela (notifiche WhatsApp)
Come una segretaria, Sole avvisa Michela su WhatsApp quando succede qualcosa di rilevante:
- un **lead** è stato salvato;
- un **sopralluogo** è stato fissato;
- un cliente segnala un **reclamo**, un **problema di pagamento**, una **richiesta di modifica** o una **richiesta fuori standard**;
- una **conversazione con un cliente si è conclusa** — quando una chat (sito o WhatsApp) resta ferma per oltre **un'ora**, parte un **riassunto della chat chiusa** (nome, contatto, canale, n° messaggi, stato cliente, ultimo messaggio). Una sola notifica per conversazione. Job automatico orario (`ardy-chiusura-sessioni.php`).

> Le notifiche arrivano sempre, anche se Michela non ha scritto di recente (modello WhatsApp approvato attivo).

### 6b. Rispondere in tempo reale a Michela/Andrea sul CRM (WhatsApp)
Quando Michela o Andrea scrivono a Sole su WhatsApp, lei legge il CRM **dal vivo in quel momento** (non una vecchia istantanea). Può quindi rispondere con sicurezza su tutto ciò che accade:
- **Chi ha scritto** nelle ultime 48h (WhatsApp + chat sito), con orario ed estratto del messaggio — utile per *"i contatti di ieri hanno risposto?"*;
- **Stato attuale di un cliente** (es. *"Tavolo Fratino che stato ha?"*) dall'elenco dei clienti attivi;
- **Fasi pubblicate di recente**, con nome cliente e nome fase;
- **Note consegna** — cosa serve/manca per consegnare un lavoro (es. *"cosa manca per la consegna di Rossi?"* → "4 bulloni M6×45…"), prese dal box "Note consegna" della scheda;
- **Preventivi da gestire** — i clienti in stato PREVENTIVO divisi in *da fare* (nessun documento ancora, o solo una bozza) e *inviato — da sollecitare risposta* (con da quanti giorni è uscito). Così al buongiorno Sole segnala queste scadenze con la specifica giusta, senza doverle chiedere;
- **Calendario** (impegni di oggi/domani), lavori urgenti, appuntamenti (sopralluoghi/ritiri/interventi/consegne), morosi.

> Sole **non** rimanda più alla dashboard per queste cose né chiede di "girarle i dati": li ha già aggiornati a ogni messaggio.

### 7. Creare una scheda cliente su dettatura di Michela (WhatsApp)
Quando Michela detta a Sole un cliente nuovo (es. *«Sole, segnami Mario Rossi, 333 1234567, vuole rilaccare una credenza, zona Prati»*), Sole raccoglie i dati, **li rilegge e chiede conferma**, e solo dopo il "sì" crea la scheda nel CRM. Stessa scheda se Michela ridetta lo stesso telefono (niente doppioni). Per ora crea **solo la scheda cliente** (il preventivo si fa dalla dashboard).

### 7b. Gestire i sopralluoghi per conto dello staff (WhatsApp) — *giu 2026*
Su richiesta di Michela o Andrea, Sole agisce sul calendario **per conto di un cliente nominato** (lo identifica per nome; se ci sono **omonimi** chiede quale). Un cliente può avere **più sopralluoghi** (1°, 2°, sopralluogo colori…):
- *«Sole, fissa ad Alberto il sopralluogo domani alle 10»* → lo **aggiunge** (anche se ne ha già altri: non è un doppione);
- *«che sopralluoghi ha Alberto?»* → li **elenca**;
- *«sposta il sopralluogo di Alberto»* → se ne ha più d'uno, Sole **chiede QUALE** prima di spostarlo.
> È lo stesso "motore" della lista **📅 Sopralluoghi** nella scheda della dashboard: ciò che Sole fa su WhatsApp compare lì, e viceversa.

### 7c. La nota settimanale "cose da fare" (WhatsApp) — *giu 2026*
Michela **e** Andrea possono dettare a Sole la **lista delle cose da fare della settimana** (sopralluoghi da prendere, materiali da ordinare, montaggi…). È **una sola lista condivisa**: Sole la **memorizza**, la **rilegge** (*«leggimi le cose da fare»*) e la **aggiorna** (*«aggiungi…»*, *«segna fatto il 3»* → Sole legge, modifica il testo intero e risalva, senza perdere le voci). Al **buongiorno del mattino** Sole la **include da sola** nel resoconto, senza doverla chiedere.

---

### 8. Presentare Galleria Diffusa ai B&B partner (pagina dedicata) — *giu 2026*
Sulla pagina `ardy-lab.it/galleria-diffusa` (linkata nella DEM ai B&B) Sole indossa il cappello
**commerciale B2B**: non tratta il titolare del B&B come un cliente di restauro, ma come un
**potenziale partner**. Spiega cos'è Galleria Diffusa (oggetti rigenerati con storia via QR code
negli ambienti del B&B, l'ospite compra dal telefono, il B&B prende una commissione), dà
**delucidazioni** sui dubbi, **invita a sentire Michela** direttamente al suo numero e può
**fissare un appuntamento in laboratorio** per vedere gli oggetti dal vivo (riusa il calendario
reale: `ottieni_disponibilita_calendario` + `fissa_appuntamento_calendario`, poi salva il partner
nel CRM). Non promette mai condizioni economiche precise: quelle le concorda Michela.
> Dove vive: pagina/loader in `wordpress-snippets/galleria-diffusa-page.html`, widget chat
> autoportante `ardy-chat-experience.js` (→ `ardy-proxy.php`), regole in `ardy-system.txt`
> (sezione "GALLERIA DIFFUSA — PARTNER B&B").

### 9. Raccogliere la consulenza di Interior Design — *lug 2026*
Michela, oltre al restauro, offre una **consulenza di interior design**. Sulla pagina
`ardy-lab.it/interior-design` (ma anche in chat normale o su WhatsApp, se il cliente la chiede)
Sole cambia percorso: niente qualifica restauro, niente forbice di prezzo sul mobile. Raccoglie
invece, una domanda alla volta, **stile preferito, colori, luce degli ambienti e budget**, insieme
ai soliti dati anagrafici (nome, telefono, email, zona), e salva il lead nel CRM con
`servizio` = "Consulenza Interior Design".

**Sulla webchat dedicata** Sole fa un passo in più: con il tool `attiva_interior_design`
**accende la sezione 🛋️ Interior Design** nella scheda del cliente in dashboard e ci scrive dentro
le preferenze raccolte — così Michela arriva al primo incontro sapendo già i gusti del cliente.
La stessa sezione Andrea e Michela possono attivarla a mano dal bottone **🛋️ Attiva Interior
Design** nella scheda (utile quando la richiesta arriva per telefono o su WhatsApp).
Sole non dà mai prezzi precisi per la consulenza: li concorda Michela a voce.
> Dove vive: pagina/loader in `wordpress-snippets/interior-design-page.html`, widget chat
> autoportante `ardy-chat-interior-design.js` (→ `ardy-proxy.php`), regole in `ardy-system.txt`
> (sezione "CONSULENZA INTERIOR DESIGN") + istruzioni sul tool dentro `ardy-proxy.php`.

---

## ✍️ Cosa scrive Sole per Michela (dalla dashboard)

Oltre a parlare con i clienti, Sole fa da "penna" per Michela: dai bottoni della dashboard genera testi già pronti, che Michela può rivedere e modificare:
- **Testo professionale** di una fase di lavorazione;
- **Post per i social** (Instagram/Facebook) e **didascalia del reel** — Michela può poi vederne
  l'**anteprima in formato Instagram**, aggiungere/togliere foto, pubblicarlo sui singoli social o
  **salvarlo "per dopo"** (le bozze restano salvate sul server, disponibili da ogni dispositivo);
- **Email** al cliente e **messaggi WhatsApp** brevi;
- **Note interne** di riepilogo;
- **Comunicazione straordinaria** al cliente (per gli imprevisti, con tono dedicato).

---

## ⚖️ La "segretaria amministrativa" (solleciti morosi)

Per i clienti che non pagano, Sole indossa un secondo cappello — più formale e fermo — e prepara i **solleciti di pagamento** su 4 livelli (dal promemoria cordiale alla bozza di diffida), con i giusti riferimenti di legge. Anche qui **decide e approva sempre Michela** prima di inviare; importi e date non vengono mai inventati. *(Dettagli operativi nella Guida alla Dashboard, sezione Morosi.)*

---

## 🛠️ Gli strumenti che Sole può usare

**Lato cliente (sito + WhatsApp):**

| Strumento | Cosa fa |
|---|---|
| `ottieni_disponibilita_calendario` | Legge gli slot liberi nel calendario di Michela |
| `fissa_appuntamento_calendario` | Crea davvero l'evento del sopralluogo |
| `sposta_appuntamento` | Sposta un sopralluogo già fissato (su WhatsApp è legato al numero di chi scrive) |
| `salva_lead_crm` | Salva/aggiorna il cliente nel CRM |
| `attiva_interior_design` | Accende la sezione Interior Design nella scheda e ci salva stile/colori/luce/budget |

Su **WhatsApp** (lato cliente) Sole usa lo stesso set di strumenti del sito, più la **ricezione e valutazione delle foto** del mobile. Il **codice di accesso**, lo strumento **`cerca_cliente`** e **`attiva_interior_design`** restano invece **solo sul sito** (su WhatsApp il riconoscimento è il numero, vedi sopra; per una richiesta di interior design arrivata su WhatsApp la sezione si attiva col bottone in dashboard).

**Lato staff (solo WhatsApp titolare — Michela/Andrea):** *giu 2026*

| Strumento | Cosa fa |
|---|---|
| `cerca_scheda_cliente` | Cerca le schede per nome (per agire sul cliente giusto / disambiguare omonimi) |
| `fissa_appuntamento_staff` | **Aggiunge** un sopralluogo a un cliente nominato (anche più d'uno) |
| `sposta_appuntamento_staff` | Sposta un sopralluogo; se il cliente ne ha più, chiede QUALE (`sopralluogo_id`) |
| `elenca_sopralluoghi_staff` | Elenca i sopralluoghi di un cliente |
| `salva_nota_settimanale` / `leggi_nota_settimanale` | La nota "cose da fare" condivisa Michela+Andrea |

> Nota: **non** esiste un tool con cui Sole manda messaggi all'altro titolare (es. "avvisa Michela"): le notifiche WhatsApp a Michela sono **automatiche** (nuovi sopralluoghi/lead via `notificaMichela`), non a comando. Scelta deliberata (vedi TODO). La creazione scheda da dettatura usa i **marker** n8n, non un tool.

---

## ✅ Regole d'oro / 🚫 Cosa NON fa

**Sole fa sempre:**
- raccoglie il **telefono** del cliente;
- propone alternative invece di dire solo "no";
- crea l'evento in calendario **prima** di dire "appuntamento fissato";
- passa a Michela tutto ciò che è delicato.

**Sole non fa mai:**
- non promette **date di consegna**;
- non dà **prezzi precisi** (solo forbici indicative);
- non concorda **modifiche** al lavoro o **sconti/dilazioni** da sola;
- non dà informazioni su un lavoro che non può verificare;
- non inventa dati (importi, tempi, disponibilità).

---

## 🧩 Appendice tecnica (dove vive ogni comportamento)

| Funzione | File / prompt |
|---|---|
| Personalità e regole generali (chatbot) | `ardy-system.txt` |
| Chatbot pubblico + tool calendario/CRM + notifiche Michela | `ardy-proxy.php` |
| Webchat Galleria Diffusa (B&B) / Consulenza Interior Design | `ardy-chat-experience.js`, `ardy-chat-interior-design.js` |
| Widget lavorazione (clienti in corso) + calendario visite | `ardy-proxy-lavorazione.php`, `ardy-widget-lavorazione.js` |
| WhatsApp — istruzioni e modalità | `ardy-whatsapp-system.txt` |
| WhatsApp — chi è il numero (lead/cliente) + riepilogo CRM live per staff | `ardy-wa-lookup.php` |
| WhatsApp — memoria conversazione | `ardy-wa-memoria.php` (WhatsApp), `ardy-web-memoria.php` (sito) |
| Conversazione cliente nella dashboard (WhatsApp + sito) | `ardy-conversazioni.php` |
| Dossier cliente (contesto per Sole) — fasi solo pubblicate lato cliente | `ardy-dossier.php` |
| Post automatici su Google Business Profile (in attesa accesso Google) | `ardy-gbp.php`, `ardy-gbp-post.php`, `ardy-gbp-check.php` |
| Notifiche WhatsApp a Michela | `ardy-notifica-michela.php` |
| Avviso a fine chat (cron orario) | `ardy-chiusura-sessioni.php` |
| Email "è pronto" (→COMPLETATO) + giornata Trasporti (consegne/ritiri) | `ardy-trasporti.php` |
| Ringraziamento alla consegna (→CONSEGNATO) | `ardy-grazie-consegna.php` |
| Footer cliente condiviso (codice + WhatsApp + social) nelle email | `ardy-email.php` |
| Solleciti morosi ("segretaria amministrativa") | `ardy-solleciti.php`, `ardy-solleciti-system.txt` |
| Testi fasi / comunicazioni / social / reel | `ardy-pubblica-lavorazione.php`, `ardy-crea-reel.php` |

> Modello AI in uso: **Claude (Sonnet)** via API Anthropic.

---

*Ardy Lab — Restauro e laccatura mobili · Roma · Assistente AI: Sole*
