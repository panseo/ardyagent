# 📖 Guida alla Dashboard — Ardy Lab

Una guida semplice per usare ogni giorno la tua dashboard.
Niente termini tecnici: solo cosa vedi e cosa succede quando clicchi.

---

## 🔑 1. Come entrare

Apri nel browser:

> **https://ardyagent.ardy-lab.it**

(dalla **root** ti porta direttamente alla dashboard — niente più `/ardy-michela-app.html`.)

Il computer ti chiederà **utente e password** (quelli che abbiamo impostato).
Inseriscili una volta e sei dentro. È la "serratura" che protegge i dati dei clienti:
nessuno può entrare senza la password.

👤 **Due utenti**: Michela e Andrea entrano ciascuno con le proprie credenziali (utenti separati
in `.htpasswd`). Stessa dashboard, stesse cose visibili. Anche su WhatsApp Sole riconosce chi
le sta scrivendo dal numero (`WA_MICHELA_NUMBER` / `WA_ANDREA_NUMBER` in `ardy-config.php`) e
chiama ciascuno per nome.

💡 *Consiglio: salva la pagina tra i preferiti e segnati utente e password in un posto sicuro.*

---

## 🗺️ 2. Com'è fatta la dashboard

Lo schermo è diviso in due parti:

- **A sinistra → la lista dei clienti** (i contatti arrivati dal sito o aggiunti a mano)
- **A destra → la scheda del cliente** che hai selezionato

Per aprire un cliente, **cliccaci sopra** nella lista a sinistra. La sua scheda compare a destra.

### La colonna di sinistra contiene:
- 🔍 **Cerca**: scrivi un nome, una zona o un servizio per trovare un cliente al volo
- **+ NUOVO**: aggiungi un cliente a mano (vedi punto 10)
- **LIBRERIA**: gestisci le frasi pronte per le lavorazioni (vedi punto 9)
- **❓ GUIDA**: riapre questa guida in qualsiasi momento
- **🔍 RICERCA AVANZATA**: cliccalo per aprire **i filtri** (TUTTI, LEAD, SOPRALLUOGO, ...) che
  mostrano solo i clienti in quello stato, con la **legenda dei colori** (vedi sotto)

### 🚦 I pallini colorati accanto ai clienti
Accanto ai lavori **in corso** (stati Acconto / In lavorazione / Completato) vedi un pallino e una scritta che
ti dice "a colpo d'occhio" come sta il lavoro, in base alle **date** che hai messo:
- 🟠 **Arancio — "sta per iniziare"**: l'inizio lavoro è entro 4 giorni (o oggi)
- 🔴 **Rosso — "fine lavoro / in ritardo"**: la fine lavoro prevista è entro 4 giorni, oggi, o già passata
- 🟢 **Verde — "nei tempi"**: il lavoro è iniziato e c'è ancora margine alla fine prevista
- 🟡 **Giallo — "date da pianificare"**: il lavoro è in corso ma **non hai ancora messo le date** —
  apri la scheda e compilale (servono per i promemoria!)

> Nota: "fine lavoro" è quando finisci di lavorarci, **non** la consegna (puoi consegnare anche dopo).

Oltre ai pallini, accanto al cliente possono comparire dei **badge**:
- **💬 ha risposto** — il cliente ti ha **scritto di recente** (WhatsApp o chat del sito, nelle ultime 48 ore) e **non hai ancora aperto la sua conversazione**. Così vedi a colpo d'occhio chi aspetta una risposta. Sparisce quando apri la scheda e guardi la sua conversazione (la sezione 💬).
- **📐 da pianificare** — c'è una nota di sopralluogo ma non hai ancora generato le fasi;
- **📦 consegna** — hai scritto qualcosa nel box "Note consegna" (qualcosa da procurare/sistemare prima di consegnare). Sparisce quando svuoti quel box.

---

## 🏷️ 3. Gli stati del cliente

Ogni cliente ha uno **stato**, che racconta a che punto è la trattativa.
In alto nella scheda c'è il bottone **"🔄 Aggiorna stato — attuale: …"**: ti mostra subito lo stato
di adesso; **cliccalo** per aprire i bottoni e sceglierne un altro (poi ricordati di **Salvare**).

| Stato | Significa |
|---|---|
| **LEAD** | Nuovo contatto, primo interesse |
| **SOPRALLUOGO** | Va fatto/è stato fissato un sopralluogo |
| **PREVENTIVO** | Gli hai mandato (o stai facendo) un preventivo |
| **ACCONTO** | Ha pagato l'acconto → il lavoro parte! |
| **IN LAVORAZIONE** | Il lavoro è in corso in laboratorio |
| **COMPLETATO** | Lavoro finito in bottega, da consegnare al cliente |
| **CONSEGNATO** | Consegnato al cliente (scatta il ringraziamento) |
| **STANDBY** | In pausa, da risentire più avanti |
| **PERSO** | Trattativa chiusa senza accordo |

👉 Cambiare stato è utile anche per **ritrovare** i clienti con i filtri di sinistra.

---

## ✏️ 4. Modificare i dati di un cliente

Nella scheda a destra trovi i campi: Nome, Cognome, Telefono, Email, Servizio,
Zona, Mobile, Budget, Indirizzo, Note, Data follow-up.

**Sono tutti modificabili.** Se un cliente ha sbagliato l'email, o manca il telefono,
clicca nel campo e correggilo.

📱 **Sopralluogo dal telefono.** Quando apri la scheda da mobile, i campi anagrafici
(Nome, Cognome, Telefono, Email, Servizio, Zona, Mobile, Budget, Indirizzo, Data follow-up)
e i bottoni delle Azioni cliente partono **chiusi** dentro due toggle:

- **▾ Dati anagrafici** — un tap per aprirlo e modificare i campi.
- **▾ Azioni cliente** — un tap per i bottoni Email/WA/Genera contenuto/Note interne.

Le **Note** e le **Foto della scheda** restano sempre in vista perché sono quello che ti
serve scrivere/scattare sul posto.

📝 **Editor Note a tutto schermo.** Accanto all'etichetta "Note" trovi il bottone **⛶ Espandi**:
apre un editor a pieno schermo dove scrivere comodamente gli appunti del sopralluogo
(misure, ipotesi prezzo, materiali, scadenze). Cliccando **✓ APPLICA** il testo torna nella
scheda. Le note finiscono automaticamente nel **dossier interno** (Sole le ricorda quando
ti aiuta) e nel **PDF preventivo** — non vengono mai mostrate al cliente.

📦 **Note consegna.** Sotto le Note c'è un secondo box, **"📦 Note consegna"**, per annotare
cosa serve o manca per consegnare un lavoro (es. *"mancano 4 bulloni M6×45 e 2 M6×70 con dadi
e rondelle"*). Funziona come le Note (anche col bottone ⛶ Espandi). Quando ci scrivi qualcosa,
sul cliente compare in lista un badge verde **📦 consegna**; **Sole le legge**, quindi puoi
chiederle su WhatsApp *"cosa manca per la consegna di Rossi?"*. Quando hai procurato tutto,
**svuota il box e salva**: il badge sparisce e per Sole quel cliente è a posto per la consegna.

💬 **Conversazione.** Nella scheda cliente c'è la sezione a fisarmonica **"💬 Conversazione"**:
aprila per leggere lo **storico dei messaggi** scambiati con quel cliente (WhatsApp + chat del
sito) in ordine cronologico, con data e ora. Utile per sapere al volo se e cosa ti ha scritto.

⚠️ **Importante:** dopo ogni modifica compare in basso la barra **"Modifiche non salvate"**.
Clicca **SALVA MODIFICHE** per registrarle. Se cambi cliente senza salvare, ti avvisa.

💡 *Il telefono è importante: serve per il sopralluogo e per far riconoscere il cliente
quando segue la sua lavorazione online.*

---

## ⚡ 5. Azioni rapide (scrive l'AI per te)

Sotto i dati del cliente ci sono sei bottoni. Ognuno apre una finestra dove
**l'intelligenza artificiale ti scrive un testo già pronto**, che puoi copiare e usare:

| Bottone | Cosa ti prepara |
|---|---|
| ✍️ **Genera contenuto** | Un testo professionale sul lavoro di restauro |
| 📱 **Post social** | Un post pronto per Instagram/Facebook |
| 📄 **Proforma** | Apre il generatore di proforma (vedi sotto) |
| 📧 **Email cliente** | Una email cordiale già scritta |
| 💬 **Messaggio WA** | Un messaggio WhatsApp breve |
| 📋 **Note interne** | Un riepilogo del cliente, per te |

Come funziona: clicchi il bottone → (puoi aggiungere istruzioni) → **GENERA CON AI** →
copi il testo. L'AI usa i dati del cliente per personalizzarlo.

---

## 📄 6. Preventivi e Proforma

### Preventivo PDF
Apri **+ PREVENTIVO PDF** sulla scheda di un cliente: i dati del cliente si compilano
da soli. Poi:

1. **Opzioni** — Puoi proporre **più alternative** per lo stesso lavoro (es. "Restauro
   completo" e "Ravvivatura"). Ogni opzione ha **le sue voci** e **il suo totale**: usa
   **+ AGGIUNGI OPZIONE**. Se lasci una sola opzione (senza nome), esce un preventivo
   singolo come sempre.
2. **Voci** — Dentro ogni opzione aggiungi le righe (descrizione, quantità, prezzo, sconto)
   con **+ AGGIUNGI VOCE**, oppure pescale dalla **📚 LIBRERIA**. Il totale dell'opzione
   si aggiorna da solo.
3. **Immagini** —
   - **Copertina**: una foto/render che diventa la **prima pagina a tutta pagina** del PDF.
   - **Prima / Dopo**: dentro **ogni opzione** carichi la foto *prima* e il render *dopo*;
     finiscono in testa alla pagina di quell'opzione.
4. **Analisi degli interventi** — Scrivi due parole nel box (es. "restauro conservativo,
   pulitura, ritocco foglia oro") e premi **✨ Scrivi con AI**: l'assistente scrive il
   testo professionale che va nella pagina "Dettaglio Tecnico". Puoi **modificarlo** a mano.
5. **👁 Anteprima** (non salva e non costa nulla) per controllare l'impaginazione → poi
   **⬇ Genera PDF**: il PDF viene salvato nello **Storico** come **bozza** e scaricato.

### Modificare una bozza (e quando si blocca)
- Finché è **BOZZA**, il preventivo si può **riaprire e correggere**: nello Storico clicca
  **✏️ Modifica**, cambia quello che vuoi e rigenera (sovrascrive lo stesso preventivo).
- Quando lo sposti su **Inviato** o **Accettato** diventa **definitivo** 🔒 e **non si
  modifica più**.
- Il **🗑** sulle bozze serve a cancellarne una (utile per eliminare doppioni).

### Importa scheda da PDF (📥 PDF)
Per un **cliente nuovo** (non ancora a sistema) puoi partire da un PDF compilato sul
**modello "Scheda Cliente"**: pulsante **📥 PDF** in alto a sinistra → carica il PDF →
**🔍 Estrai** (l'AI legge i dati) → controlli/correggi → **✦ Crea scheda** (oppure
**📄 + Preventivo Ardy** per creare anche il preventivo). ⚠️ Il PDF deve avere **testo
digitale** (non una foto/scansione).

### Proforma
Il bottone **📄 Proforma** apre un generatore con **3 scenari pronti**:
1. **Prenotazione** (€50–100, per riservare il lavoro)
2. **Acconto 50%**
3. **Saldo a consegna**

Scegli lo scenario, i dati del cliente si compilano da soli, e generi il documento.

### Storico preventivi
Per ogni cliente vedi l'elenco dei preventivi già fatti, con il loro stato
(bozza, inviato, accettato, rifiutato), il pulsante **✏️ Modifica** (solo sulle bozze),
il **🗑** e **⬇ PDF** per riscaricarli.

---

## 🪵 7. Pubblicare una fase di lavorazione

Questa è la parte che aggiorna il cliente **e** pubblica sui social.
👉 Compare nella scheda **solo quando il cliente è in stato ACCONTO** (cioè il lavoro è partito).

All'apertura vedi, nell'ordine:
- **📅 Periodo del lavoro**: le date di **inizio** e **fine** lavoro (valgono per *tutto* il lavoro,
  non per la singola fase). Compilale: servono ai pallini colorati nella lista.
- **🔨 Crea e pubblica nuova fase**: il bottone (in evidenza). **Cliccalo per aprire il modulo.**
- **📋 Fasi pubblicate**: l'elenco delle fasi che hai già pubblicato (solo titolo e data), così
  vedi sempre a che punto sei. Per rivederle per intero c'è il link "→ Vedi tutte sul sito".

**Come si pubblica una fase** (dopo aver aperto **🔨 Crea e pubblica nuova fase**):
1. Scrivi il **nome della fase** (es. "Sverniciatura") e il **mobile**
2. Scrivi **2-3 righe** su cosa hai fatto — l'AI le trasforma in un testo professionale
3. Aggiungi le **foto**:
   - 📷 **SCATTA FOTO** → apre la fotocamera del telefono, scatti sul momento
   - 🖼️ **DALLA GALLERIA** → scegli foto già fatte
4. Premi **✦ PUBBLICA FASE + NOTIFICA CLIENTE**

**Cosa succede SUBITO e in automatico quando pubblichi:**
- 📝 Si crea/aggiorna la **pagina della lavorazione** sul sito
- 🖼️ La **prima foto in assoluto** del lavoro diventa la **copertina** della pagina:
  è quella che si vede come anteprima nella sezione lavorazioni della home del sito
- ✉️ Il **cliente riceve un'email** con l'aggiornamento

**I social NON partono da soli.** Subito dopo, compare un riquadro
**"📲 Vuoi pubblicare sui social?"** con il testo del post **già pronto e modificabile**.
Da lì scegli tu:
- **📲 PUBBLICA ORA** → pubblica subito su Facebook e Instagram (con la foto)
- **🕒 SALVA PER DOPO** → lo metti da parte e lo pubblichi quando vuoi
- **✕ NON PUBBLICARE** → niente social, solo il cliente è stato aggiornato

👉 Puoi **modificare il testo** prima di pubblicare: cambia parole, hashtag,
quello che vuoi, poi premi Pubblica.

**Le foto del post le decidi tu.** Nel riquadro "Vuoi pubblicare sui social?" trovi:
- **➕ Aggiungi foto** → carichi una o più foto in più (oltre a quelle della fase)
- **✕** su ogni miniatura → toglie quella foto dal post
- **👁 Anteprima Instagram** → ti mostra **come verrà il post** in formato Instagram (foto quadrata,
  più foto si sfogliano con le frecce, testo sotto) **prima** di pubblicarlo

**Su quali social esce.** Le icone **Facebook / Instagram** sono interruttori: accendi solo quelli su
cui vuoi pubblicare *quel* post (puoi anche fare solo Instagram, o solo Facebook).

### Post social in attesa
Se hai scelto "Salva per dopo", i post messi da parte compaiono nel riquadro
**"🕒 Post social in attesa"** (in fondo alla sezione lavorazione), come **elenco compatto**:
per ognuno vedi 📲, il titolo, le **icone dei social** (Facebook, Instagram; Google grigio = non
ancora attivo) e la data. Per ciascuno puoi:
- **✏ Modifica** → cambi il **testo** e gestisci le **foto** (➕ Aggiungi foto / ✕ togli foto), poi **💾 Salva modifica**
- **👁 Anteprima** → vedi come verrà in formato Instagram prima di pubblicarlo
- **📲 Pubblica** → lo mandi sui social accesi per quel post (Facebook e/o Instagram)
- **🗑** → lo elimini

✅ *I post "in attesa" sono salvati **sul server**, non più solo nel browser: li ritrovi da
**qualsiasi dispositivo** (telefono e computer) e li vede anche Andrea. Se modifichi testo o foto, la
modifica resta salvata anche se chiudi e riapri.*

💡 *Da telefono funziona benissimo: sei in laboratorio, scatti, pubblichi (o rimandi).*

### ⚠️ Comunicazione straordinaria
Sotto al pulsante normale c'è un secondo bottone arancione, **⚠ COMUNICAZIONE STRAORDINARIA**.
Usalo quando durante un lavoro emerge un **imprevisto da segnalare al cliente prima di
procedere** (es. un danno nascosto, una parte da ricostruire): non è un avanzamento normale.
Scrivi 2-3 righe su cosa è successo, l'AI prepara un messaggio chiaro e rassicurante, e il
cliente lo riceve come **avviso speciale** — email con oggetto dedicato e, sulla pagina della
lavorazione, un riquadro arancione con l'icona ⚠. Ti viene chiesta una **conferma** prima
dell'invio. Queste comunicazioni **non vanno sui social** e **non entrano nel reel**.

---

## 🎬 8. Il Reel finale della lavorazione

Quando il lavoro è finito e hai pubblicato tutte le fasi, puoi creare in un clic
un **video verticale (reel)** che racconta tutta la lavorazione — pronto per
Instagram e Facebook.

👉 Il riquadro **"🎬 Reel finale della lavorazione"** compare in fondo alla
sezione lavorazione (quando c'è già almeno una fase pubblicata).

**Come si fa:**
1. Scegli uno **stile (template)** dal primo menù (es. Classico, Veloce, Cinematico)
2. (Facoltativo) scegli una **musica**, oppure lascia quella del template
3. Premi **🎬 CREA REEL** → attendi un minuto: il video si monta da solo

💡 *Col bottone **⚙ Template** puoi creare i tuoi stili (quanto dura ogni foto,
mostrare o no titolo/didascalie/Prima-Dopo, musica predefinita). Sono condivisi
tra telefono e computer, come la libreria fasi.*

**Cosa monta il video:**
- una **schermata iniziale** con il nome del mobile e il logo
- **tutte le foto** delle fasi, con scritto sopra il nome della fase
- una **schermata finale "Prima → Dopo"**

**Quando è pronto** vedi l'anteprima del video, il link per **scaricarlo**, e una
**didascalia già scritta dall'AI** (con hashtag) che puoi **modificare**.

- **📲 PUBBLICA REEL SUI SOCIAL** → invia il reel a Instagram/Facebook
- oppure **⬇ Scarica il reel** e caricalo a mano quando preferisci

💡 *La didascalia è modificabile prima di pubblicare: cambia parole o hashtag come vuoi.*

---

## ❓ 9. Le FAQ della lavorazione (per farti trovare su Google)

Quando un lavoro è **CONSEGNATO**, in fondo alla scheda compare il riquadro
**"❓ FAQ della lavorazione"**. Serve a creare delle **domande e risposte**
(quelle che un cliente cerca su Google su un lavoro simile) e a **pubblicarle
sull'articolo** del lavoro: aiutano il sito a farsi trovare (SEO).

**Come si fa:**
1. Premi **❓ CREA FAQ DI QUESTA LAVORAZIONE** → l'AI scrive 5-7 domande e risposte
   partendo dal mobile, dal servizio e dalle fasi.
2. **Rivedi e modifica** ogni domanda/risposta; puoi anche **rimuoverne** qualcuna
   (🗑) o rigenerarle.
3. Premi **📤 PUBBLICA LE FAQ SULL'ARTICOLO** → le aggiunge in fondo alla pagina del
   lavoro (a fisarmonica) con i dati per Google.

💡 *Se rifai le FAQ e ripubblichi, la nuova versione **sostituisce** quella vecchia
sull'articolo (niente doppioni). Se le hai già pubblicate, il riquadro te lo dice
("✓ FAQ già pubblicate il …") e il bottone diventa **🔄 Rigenera / aggiorna FAQ**.*

---

## 📚 10. La Libreria delle fasi

Sono **frasi pronte** per le lavorazioni più comuni (così non riscrivi tutto ogni volta).
- Quando pubblichi una fase, puoi premere **📚 SCEGLI DA LIBRERIA** e prenderne una
- Col bottone **LIBRERIA** (in alto a sinistra) puoi aggiungerne di nuove o eliminarle

💡 *La libreria è **condivisa tra telefono e computer**: una frase che aggiungi o
modifichi da un dispositivo la ritrovi anche sull'altro.*

---

## ➕ 11. Aggiungere un cliente a mano

Non tutti arrivano dal sito. Per inserirne uno tu:
1. Clicca **+ NUOVO** (in alto a sinistra)
2. Compila almeno nome o cognome (gli altri campi quando vuoi)
3. Salva → compare nella lista come gli altri

---

## 🤖 12. Da dove arrivano i clienti "da soli"

Due strumenti automatici riempiono la dashboard senza che tu faccia niente:

- **Il chatbot del sito**: i visitatori chiacchierano con "Sole", l'assistente.
  Quando lasciano i contatti, **il cliente compare qui** e **tu ricevi un'email** di avviso.
  Il chatbot può anche **fissare un sopralluogo** nel tuo Google Calendar.

- **Il widget sulle pagine di lavorazione**: il cliente, sulla pagina del suo mobile,
  inserisce il telefono per farsi riconoscere e può **chiederti come procede** o
  **prenotare una visita in laboratorio**.

Tu non devi fare nulla per questi: lavorano da soli e ti portano i contatti già pronti.

💡 **Sole ti avvisa su WhatsApp.** Quando arriva un nuovo contatto, viene fissato un
sopralluogo, oppure un cliente segnala un reclamo, un problema di pagamento o una richiesta
particolare, Sole ti manda **un messaggio WhatsApp di riepilogo**, come una segretaria.
Gli avvisi arrivano sempre, anche se non hai scritto a Sole di recente.

---

## 💸 13. Clienti che non pagano (Morosi)

Il pulsante **💸 MOROSI** (in alto a sinistra, sotto a GUIDA) apre la gestione dei clienti
che non hanno saldato. È la tua "segretaria ferma ma corretta": prepara i solleciti al posto
tuo, con il tono giusto e i riferimenti di legge, ma **decidi e approvi sempre tu** prima di inviare.

**Come si fa:**
1. **Aggiungi il caso**: scegli il cliente dal menù a tendina (nome, telefono, email e
   collegamento al preventivo si compilano da soli) e indica **importo dovuto** e **scadenza**.
   Puoi anche inserirlo del tutto a mano.
2. **🔍 Verifica** (consigliato): controlla che ci sia un preventivo accettato e che il lavoro
   risulti svolto. Se manca qualcosa di importante ti avvisa **prima** di sollecitare.
3. **✍️ Genera**: scegli il **livello** e l'AI scrive il messaggio (4 livelli, sempre più fermi).
4. **Rivedi il testo** (puoi modificarlo), scegli **WhatsApp e/o Email** e premi **Invia**.

| Livello | Quando | Tono |
|---|---|---|
| **1** | Primo promemoria | Cordiale, ricorda la scadenza |
| **2** | Dopo qualche giorno senza risposta | Fermo, richiama l'accordo |
| **3** | Ancora niente | Formale, cita la normativa (+ PDF preventivo via email) |
| **4** | Situazione grave | **Diffida formale** — da inviare a mano (raccomandata/PEC) |

Ogni caso ha uno **stato** (Aperto, Pagato, Diffida, Archiviato) che cambi quando il cliente
paga o la pratica si chiude.

⚠️ *Il **livello 4 (diffida)** non parte da solo: l'AI prepara la bozza, ma la invii tu a mano.
Per le diffide, una volta fattela controllare da un commercialista o avvocato.*

💡 *I solleciti via WhatsApp partono anche se il cliente non ti ha scritto di recente
(il modello approvato è già attivo). L'**email** è comunque sempre inclusa dal livello 2 in su.*

---

## 🗑️ 14. Cestino (eliminare e recuperare clienti)

Quando vuoi togliere un cliente dalla lista, **non viene cancellato subito**: va nel **cestino**
e ci resta per **30 giorni**. Se ti accorgi che ti serve, puoi ripristinarlo.

**Come funziona:**
1. Apri la scheda del cliente → clicca **🗑 Cestino** → conferma nel popup
2. Il cliente sparisce dalla lista (ma non è cancellato)
3. Per recuperarlo: clicca **🗑 CESTINO** nella colonna di sinistra (in basso)
4. Si apre la lista dei cestinati con i **giorni rimasti** → clicca **↩ Ripristina**

Se non lo ripristini entro 30 giorni, viene cancellato definitivamente in automatico
(scheda, preventivi, fasi, foto e reel).

Da lì puoi anche fare **✕ Elimina** per cancellarlo subito senza aspettare i 30 giorni
(ti chiede di scrivere ELIMINA per sicurezza).

---

## 🔔 15. Monitor lead automatico (ProntoPro e altri portali)

Il sistema controlla automaticamente **ogni ora** le email dei portali (ProntoPro, Homedeal,
Cronoshare) sulla casella `ardy.documenti@gmail.com`.

Quando arriva una richiesta interessante per te (restauro, verniciatura, laccatura in zona Roma),
**ti arriva un WhatsApp** con il riepilogo:

> 🔔 *ProntoPro*: Verniciatura tavolo · Roma, Grottaferrata ⭐⭐⭐⭐⭐

Le richieste non pertinenti (montaggio Ikea, falegnameria generica, zone troppo lontane,
tappezzeria) vengono scartate automaticamente. Tu valuti solo quelle buone.

Non devi fare nulla per attivarlo: è già attivo e gira da solo.

---

## 📲 16. Contattare un lead da portale (tramite Sole su WhatsApp)

Se hai acquistato un lead su ProntoPro e non ti risponde dalla chat del portale, puoi
usare Sole per contattarlo direttamente via WhatsApp:

1. **Scrivi a Sole** (dal tuo numero, su WhatsApp): "Sole, segna un cliente nuovo:
   Mario Rossi, 333 1234567, vuole verniciare un tavolo, zona Grottaferrata"
2. Sole ti ripete i dati e ti chiede conferma → dì **sì**
3. La scheda viene creata nel CRM
4. Poi dì a Sole: "contattalo" → Sole ti mostra cosa manderebbe e **aspetta il tuo OK**
5. Confermi → parte un WhatsApp al lead con un link alla chat del sito

Da qui il lead ha **due strade**, entrambe funzionano:
- **Risponde direttamente sul WhatsApp** → Sole continua la conversazione lì, lo riconosce
  già per nome e sa cosa ha chiesto. È la strada più naturale.
- **Clicca il link** → arriva nella chat del sito (webchat), Sole lo riconosce uguale e
  riprende da dove ha lasciato.

In entrambi i casi il lead **non deve rispiegare tutto da capo**.

---

## ✅ In due righe

1. **Entri** con utente e password
2. **Clicchi un cliente** a sinistra, **lo gestisci** a destra
3. **Cambi lo stato** man mano che la trattativa avanza
4. **Pubblichi le fasi** con le foto quando lavori → cliente e social aggiornati da soli
5. **A lavoro finito**, crei il **reel** con tutte le fasi e lo pubblichi sui social

Per qualsiasi dubbio o se qualcosa non funziona, segnalalo: si sistema. 🙂

---

*Ardy Lab — Restauro e laccatura mobili · Roma*
