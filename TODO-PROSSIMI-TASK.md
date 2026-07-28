# Ardy Lab — Task aperti & note utili

> Solo task **aperti** + note operative + verifiche residue. Tutto ciò che è fatto **e deployato**
> è rimosso (lo storico resta nei commit git). Ultima pulizia: 26/07/2026 · ultimo aggiornamento: 28/07/2026.

> ⚠️ Promemoria sempre valido: se Sole tace su **tutti** i canali insieme (WhatsApp + webchat),
> sospetta **credito Anthropic esaurito** (capitato il 21/06; si ricarica da Plans & Billing).

---

## 🚀 APERTURA SESSIONE — riprendi da qui (fine sessione 28/07/2026)

Sessione partita da **un lead vero della webchat**: la cliente ha mandato più foto dello stesso
mobile una alla volta e Sole ha dato una valutazione completa a ogni scatto (pedante); poi, avendo
lei detto che il lavoro era per settembre, Sole ha risposto *«dirò a Michela di chiamarti a fine
agosto»* — una data che non poteva promettere, e che non risultava da nessuna parte.

**Tutto deployato il 28/07, ma NON ancora provato dal vivo.** Michela proverà con calma.

**Da fare per prime, alla riapertura — i tre collaudi:**

1. **Foto multiple sulla webchat** (`/ardy-agent/`): il 📎 deve permettere di selezionare **più
   foto insieme**, e sotto le anteprime deve comparire il conteggio («3 foto pronte — inviale
   insieme»). Poi, mandandone comunque una alla volta: Sole deve valutare per intero **solo la
   prima** e sulle successive aggiungere una riga solo se lo scatto mostra qualcosa di nuovo.
   ⚠️ Il `multiple` è forzato da JS in `ardy-chat-site.js` perché l'`<input id="ac-file-input">`
   vive nella pagina WordPress: se un domani qualcuno rifà quella pagina, ricontrollare qui.
2. **Promemoria datato** (`ricorda_a_michela`): dire a Sole «il lavoro lo farei a settembre» e
   verificare che (a) **non risponda con una data**, (b) compaia una riga nelle **📅 cose da fare
   con data** in dashboard e il relativo evento su Google Calendar, (c) rispunti nel **briefing
   del mattino** di quel giorno. Vale su webchat **e** WhatsApp.
3. **Rinomina «Promemoria»**: controllare l'etichetta in dashboard (card, modale, bottone in
   sidebar), nell'email del briefing e su WhatsApp («leggimi il promemoria»). Il bottone in
   sidebar ora dice **🗒️ PROMEMORIA**: se in sidebar sta stretto, accorciarlo.
4. **Titoli editoriali dei post** — pubblica una fase di lavorazione nuova (mai pubblicata prima)
   e un progetto nuovo su Creazione: il titolo del post WordPress non deve più uscire come
   «Lavorazione — [mobile]» / «Creazione — [nome]» ma un titolo scritto da Claude sul mobile/pezzo
   reale. Se la chiamata AI fallisce, il vecchio formato resta come fallback — non deve mai uscire
   un post senza titolo.

**In sospeso dalla sessione precedente (26/07), non ancora collaudato:**

4. **Collaudo archivio 📦** — su un progetto **fake** (non su Ardy Tower, prototipo reale in corso)
   portalo a CATALOGATO, archivia, controlla che finisca con «rilettura di verifica riuscita ✅»,
   poi cestinalo.
5. **Occhio ai vecchi articoli con immagini WEBP**: si riparano premendo 🖼️ *Aggiorna le immagini
   dell'articolo*, che ora ricarica l'allegato convertito in JPEG. Da fare quando un articolo
   pubblicato prima del 26/07 deve andare sui social.

---

## 🪑 NOTA — Ardy Express: webchat dedicata + griglia prezzi (deployato e collaudato 28/07/2026)

Nuova landing/webchat per il servizio Ardy Express (manutenzione a domicilio): pagina
`ardy-lab.it/ardy-express` con widget dedicato `ardy-chat-express.js` (stampo di
`ardy-chat-interior-design.js`). Sole chiede quali mobili/quanti, interno o esterno, zona e foto
facoltative, poi dà una stima provvisoria in base al numero di pezzi (fino a 10), ricordando
sempre che il costo si conferma con un sopralluogo. Integrazione CRM leggera: nessuna nuova
tabella/colonna, il lead si salva col tool `salva_lead_crm` già esistente.

⚠️ Ha **sostituito la vecchia tariffa "250€/giorno"** nel prompt condiviso (`ardy-system.txt`):
ora è una griglia a numero di pezzi, valida su **tutti i canali** (WhatsApp, sito generico,
landing dedicata).

- [ ] **Prezzi ancora da validare con Michela**: la griglia (150→400€ per 1→10 pezzi) usa scala a
  tappe partendo dai due soli punti dati da lei (1 pezzo=150€, 10 pezzi=400€); i valori intermedi
  (2-9 pezzi) sono una stima ragionevole ma NON confermata — rivedere con lei prima di
  considerarli definitivi.

---

## ✍️ NOTA — Titoli editoriali per i post (deployato 28/07/2026, da collaudare)

- **Il titolo era un template fisso**, mai scritto da nessuno: `'Lavorazione — ' . $mobile` in
  `ardy-pubblica-lavorazione.php` e `'Creazione — ' . $titolo` in `ardy-pubblica-progetto.php`,
  impostati **una sola volta** alla creazione del post (le fasi successive si accodano, il titolo
  resta). La dashboard non aveva — e continua a non avere — un campo per modificarlo prima di
  pubblicare: due mobili con lo stesso nome interno uscivano con titolo identico.
- **Ora un titolo vero, scritto da Claude**, con lo stesso pattern già in uso per il testo delle
  fasi e le caption social (`generaTestoFase`/`generaTestoSocial` in `ardy-pubblica-lavorazione.php`,
  `ardy-progetti-ai.php`): nuove funzioni `generaTitoloLavorazione()` e `generaTitoloProgetto()`,
  stesso file dei rispettivi publish, usano il materiale già scritto (mobile/fase/testo della prima
  fase; nome interno/testo di presentazione del progetto). **Fallback automatico** al vecchio
  formato se la chiamata fallisce o il risultato torna vuoto/troppo lungo.
- Genera il titolo **solo alla creazione** del post (stesso punto di prima): pubblicare altre fasi
  sullo stesso lavoro/progetto non lo tocca.

---

## 🗒️ NOTA — Promemoria, promesse e foto di Sole (deployato 28/07/2026, da collaudare)

- **Le due liste non sono la stessa cosa.** Il **Promemoria** (tabella `note_staff`, ex «cose da
  fare questa settimana») è la lista **libera e senza date**; le **📅 voci con data e ora**
  (`todo_datati`) occupano uno slot su Google Calendar. Sole ora scrive nella seconda col tool
  **`ricorda_a_michela`**. Il rinomina ha toccato **solo le etichette**: tabella `note_staff`,
  endpoint `ardy-nota-settimanale-api.php` e tool `leggi/salva_nota_settimanale` sono invariati —
  chi legge il codice non si spaventi del disallineamento tra nome tecnico e nome a schermo.
- **Il tool sta su entrambi i canali** (`ardy-proxy.php` e `ardy-wa-agent.php`, sia lato cliente
  sia lato staff): è quello che permette di tenere la regola in `ardy-system.txt`, che è condiviso
  con WhatsApp. Se un domani si aggiunge un tool a un canale solo, l'istruzione va nell'appendice
  di quel canale, **non** nel documento condiviso (altrimenti Sole recita la sintassi del tool).
- **Data odierna nel system prompt** (`## OGGI`): aggiunta ora su entrambi i canali. Non c'era, e
  senza il modello indovinava giorno e anno — un promemoria datato sarebbe finito nel passato.
- **n8n non c'entra con i prompt.** Verificato: il workflow WhatsApp ha due nodi (Webhook + Code) e
  **zero testo di prompt**; tutto il system arriva da `ardy-wa-lookup.php`, che assembla
  `ardy-whatsapp-system.txt` + `ardy-system.txt` lato server. Le modifiche ai prompt viaggiano
  quindi col deploy, mai da n8n.

---

## 📣 NOTA — Social dalla dash design: com'è fatto (deployato e verificato 26/07/2026)

L'**articolo** del progetto e ogni **fase-racconto** si pubblicano su Facebook/Instagram/**Google**
con lo stesso motore del binario clienti (`ardy-pubblica-social.php` → n8n → Graph API per Meta,
`ardy-gbp-post.php` per Google, in parallelo). La caption è a parte (`ardy-progetti-ai.php` mode
`genera_social`: corta, con hashtag — tolti in automatico su Google).

- **Stesso blocco, non «simile»**: sempre in vista (niente bottone che apre un form), toggle +
  caption + i tre bottoni **✨ Rigenera / 👁 Anteprima / 📲 Pubblica**, stessa anteprima Instagram,
  e la caption dell'articolo che si scrive da sola all'apertura del modulo — come `ensureFaseSocial()`
  sulle fasi cliente. `socialDestHtml`/`toggleSocialDest` sono copiati tali e quali da
  `ardy-michela-app.html`: se si tocca uno, si tocchi anche l'altro.
- **Nessun canale preselezionato** e piattaforme lette dal DOM senza default FB/IG di riserva: non si
  pubblica per sbaglio dove non si voleva. Non si può scendere a zero canali.
- **Esito per-canale**: se Google fallisce e Meta no, il messaggio lo dice invece di un errore
  generico che farebbe ripetere anche l'invio riuscito.
- **Regola**: il blocco 📲 compare solo su ciò che è **già su WordPress** — FB/IG/Google devono
  *scaricare* l'immagine da un URL pubblico, e le nostre foto stanno dietro Basic Auth. Quegli URL
  si salvano in `fasi.foto_wp_urls` / `progetti.wp_immagini`. Se mancano (articoli vecchi), il
  pannello lo dice e indica 🖼️ *Aggiorna le immagini dell'articolo*.

---

## 🧩 APERTO — Tipi progetto dash design: aggiunto `sistema` (26/07/2026)

Nato da Ardy Tower (serra idroponica modulare a torri, ricircolo + IoT + agente AI "ArdyBio-Agronomist"):
un progetto che **non è arredo** e che nei tipi esistenti non aveva casa. Aggiunta la voce **`sistema`**
= *apparato funzionale, più componenti che lavorano insieme (impianto, elettronica/IoT, automazione)*.
Regge anche i prossimi progetti tecnici fuori perimetro, senza inventare un tipo ogni volta.

- **Perché il nome conta**: `tipo` non è solo un'etichetta di dash — finisce nel contesto passato all'AI
  (`ardy-progetti-ai.php` → `Tipo: $tipo`) quando genera **articolo** e **scheda prodotto**. Un contenitore
  generico tipo "sperimentale" all'AI non direbbe nulla; `sistema` le dice che sta raccontando un impianto,
  non un oggetto d'arredo.
- **Toccati** (4 punti, la lista è hardcodata in più file — se si aggiunge un tipo, vanno tutti):
  `ardy-progetti-api.php` (`PROGETTO_TIPI`, whitelist server) · `ardy-design-app.html` (`TIPI`, la tendina) ·
  `ardy-guida-design.html` (elenco + tip che spiega a Michela quando usarlo) · `PIANO-DASH-DESIGN.md` (schema).
- **Nessuna migrazione**: `progetti.tipo` è `VARCHAR(40)` senza vincolo enum → il valore nuovo passa da subito.
- ⏳ **Da deployare** (merge in `main` fatto, deploy sul server no).

**Considerazioni emerse, da decidere:**
- [ ] **Il set dei tipi è misto e va ripulito.** `lampada · mobile · complemento · sistema` dicono *cosa è*
      il pezzo; `restyling` e `prototipo` invece **duplicano campi che esistono già**: `restyling` è anche un
      **Metodo** (`PROGETTO_METODI`), `prototipo` è anche una **fase** del ciclo di vita (`PROGETTO_STATI`).
      Averli fra i tipi vuol dire che lo stesso fatto è scritto in due posti che possono contraddirsi (un
      progetto tipo=`prototipo` ma stato=`CATALOGATO`), e confonde l'AI che legge entrambi nel contesto.
      Toglierli però è una **migrazione dati** sui progetti esistenti (vanno riassegnati a un tipo vero) →
      non fatto, serve decisione di Michela.
- [ ] **Metodo di Ardy Tower.** Oggi è `Stampa 3D`, che accende i moduli 3D (archivio file, iterazioni,
      versione finale). Corretto per le torri stampate, ma il progetto è **misto** (stampa + idraulica +
      elettronica/IoT) e i moduli per la parte impiantistica non esistono. Da capire se servono davvero o se
      basta documentare quella parte nei documenti di riferimento — è un lavoro a sé, non un problema di naming.

---

## ☁️ APERTO — Archivio B2 di fine ciclo: collaudo dal vivo mancante (26/07/2026)

**Decisione (26/07)**: B2 non sta più *dentro* il lavoro (offload spento, rimpatriato tutto su
disco — 42/42 file, zero errori), sta *dopo*: a ciclo chiuso — progetto **CATALOGATO**, cliente
**CONSEGNATO** — un bottone 📦 deposita copia della documentazione su B2 (mai sposta: sul disco
non si cancella niente), poi **rilegge il manifest appena scritto** per verificare che l'archivio
sia davvero recuperabile.

- ⏳ **Non ancora collaudato dal vivo**: nessun 📦 è mai stato premuto su un progetto vero. Farlo
  su un progetto **fake** (creane uno, portalo a CATALOGATO, archivia, controlla «rilettura di
  verifica riuscita ✅», poi cestinalo) — non su Ardy Tower, che è un prototipo reale in corso.
- ⏳ **Da decidere dopo il collaudo**: se e quando liberare il disco su ciò che è archiviato **e
  verificato** (oggi «libera spazio cliente» esiste già lato clienti ed è indipendente; per i
  progetti non c'è ancora).
- ⚠️ **Non coperte dal rimpatrio B2**: le foto delle fasi-racconto passate da «libera spazio disco»
  (non hanno colonna `storage`). Sono le meno critiche — quelle foto stanno già sull'articolo
  WordPress — ma vanno considerate prima di svuotare il bucket.
- Le costanti `ARDY_B2_*` restano nel config: servono all'archiviazione. Il bucket non si svuota,
  cambia solo cosa ci finisce e quando. Nota costi: il backup off-site di cPanel su B2 (§ più
  sotto) è un'altra cosa e non si tocca.

---

## 🗑️ RIMOSSO — Monitor lead portali (22/07/2026)

Il modulo **Monitor lead** (leggeva le email dei portali ProntoPro/Homedeal/Cronoshare/Instapro/
Habitissimo via Gmail, classificava con Claude e avvisava Michela su WA) è stato **eliminato**:
di fatto inutile finché non esistono connettori diretti ai portali lead.

- **Codice rimosso:** `ardy-lead-monitor.php` (endpoint chiamato ogni 60 min da n8n).
- **⚠️ DA FARE A MANO su n8n (non fatto dal codice):** disattivare/eliminare il workflow n8n
  **"Lead Monitor"** (il nodo Schedule ogni ora + HTTP Request verso `ardy-lead-monitor.php`).
  Finché resta attivo continuerà a colpire un URL ora inesistente (404, innocuo ma inutile).
- **Effetti collaterali Gmail:** l'etichetta `lead-processato` e i messaggi già marcati letti
  restano; nessuna azione necessaria.
- **NON toccato (feature diversa):** il **Funnel / primo contatto lead** — `ardy-lead-contatto.php`,
  `ardy-save-lead.php`, `ardy-update-lead.php`, `ardy-lead-foto.php` e il CRM restano attivi.

---

## 📸 APERTO — Foto fasi: seguiti dopo il fix "foto Android" (19/07/2026)

**Contesto (già fatto e deployato):** le foto pubblicate da **Android** sparivano in silenzio (testo sì,
foto no), mentre da **iPhone** arrivavano. Causa: il server accetta solo JPG/PNG/WEBP ≤ 12 MB e scartava
(`continue`) il resto; iOS converte da sé lo scatto in JPEG leggero alla selezione, Android inviava il file
originale (spesso **HEIC/HEIF** o **JPEG > 12 MB**). Fix: normalizzazione lato client (canvas → JPEG ~1600px)
**prima** dell'upload, applicata a `handleFotoUpload` (fase), `handleAvvioFotoUpload` (avvio),
`handleSocialImgAdd`/`handlePendingImgAdd` (social), `handleSchedaFotoUpload` (foto scheda). File:
`ardy-michela-app.html` (helper `fotoNormalizza`). Da qui sono emerse due code, **da valutare**:

- [ ] **Modifica/ri-pubblica di una fase-lavorazione GIÀ pubblicata.** Oggi la lista "FASI PUBBLICATE"
      è **sola lettura** by-design (`caricaFasiPubblicate` in `ardy-michela-app.html`): non si può rientrare
      per, es., aggiungere una foto persa a un blocco già online (caso reale: fase *"Carteggiatura manuale
      grana fine"* del 17/07, foto non arrivata). Il flusso **lavorazione** (`ardy-pubblica-lavorazione.php`)
      **appende** ogni fase senza marcatore per-fase → ri-pubblicare **duplicherebbe** il blocco. Intervento:
      portare qui lo stesso schema **idempotente** già presente nelle fasi-**progetto**
      (`ardy-pubblica-fase-progetto.php`: marcatori `<!-- ardy-fase-ID -->` + `wp_pubblicata_at` azzerato al
      salvataggio → sostituzione invece di duplicato) e aggiungere in dash un'azione "modifica/ri-pubblica"
      sulle fasi pubblicate. Vera modifica al codice: richiede tempo + test a video.
      **Workaround intanto:** pubblicare una **nuova fase** con la foto (col fix, ora passa).
- [ ] **Diagnosi log foto 17/07** (opzionale, conferma tecnica): controllare `error_log` del server per
      `ARDY PUBBLICA IMG: ricevute=… salvate_su_wp=…` e gli errori sideload/mime attorno al 17/07, per
      confermare *perché* quella foto fu scartata (HEIC? > 12 MB?). Utile solo se si vuole la prova.

---

## 🛒 APERTO — Ecommerce `object.ardy-lab.it` (Woo) + chat Sole per-oggetto (09/07/2026)

Costruito in sessione `ardy-ecommerce-woocommerce`. Piano completo in **`PIANO-ECOMMERCE-OBJECT-WOO.md`**.
WP+Woo dedicato su `object.ardy-lab.it` (Cloudflare arancione + Full strict; Origin cert `*.ardy-lab.it`).

**Fatto (mergiato in main, PR #45-#51):**
- Fase 0 — `progetti`: campi scheda-Sole (`storia/cura/faq_pubbliche/dimensioni/scheda_sole_pubblica`) +
  slug univoco; endpoint pubblico `ardy-object-scheda.php` (whitelist).
- Fase 1 — chat Sole per-oggetto: `ardy-object-proxy.php` + `ardy-object-system.txt` + `ardy-object-chat.js`
  (contesto lato server, CORS `object.ardy-lab.it`), lib condivisa `ardy-object-lib.php`. Snippet WP di
  iniezione: `wordpress-snippets/object-chat-inject.php` (installato come mu-plugin sul WP negozio).
- Fase 2 — push dash→Woo `ardy-object-push.php` (REST Woo, CK/CS in `ardy-config.php`), pulsante in dash.
- Prezzo confermabile in chat; **foto vendita separate** (tabella `progetto_foto_vendita`, `ardy-object-foto-api.php`,
  `ardy-object-img.php`) — Modulo 1 (galleria→ardy-lab.it) intoccato.
- Usabilità dash design: stati rinominati (VERSIONE_FINALE/REALIZZAZIONE/FOTO), attributi `metodo`/
  `disponibilita`, **UI a card collassabili rivelate per fase** + gating per metodo.

**Da fare / verificare:**
- ⏳ **Verifica a video** della dash a card collassabili (grossa modifica UI, non testata live) — vedi
  checklist di fine sessione. Poi eventuale ritocco layout.
- ⏳ Test push **foto vendita** su un pezzo nuovo (auto-fill immagini su Woo).
- ⚠️ **B2 letture rotte (Opzione A)**: il push foto usa immagini **locali** apposta. Fix radicale del GET
  B2 (SigV4) resta un intervento a parte — impatta anche dash principale e articoli design.
- Aggiornare la nota vecchia qui sotto in "CONGELATI" (già ribaltata: Woo **sì**, chat **è Sole**).

---

## 🎨 APERTO — Seguiti dell'audit usabilità dash (05/07/2026)
Audit + interventi P1/P2/P3 **fatti e deployati** (vedi `AUDIT-USABILITA-DASH.md`): linguaggio
bottoni coerente, sidebar per frequenza, filtri raggruppati, azioni duplicate disambiguate, menu ☰
per i link app su mobile. Restano due code, non urgenti:
- [ ] **Test-utente di validazione** — 15 min guardando Michela sui 3 compiti tipici
      (`TEST-UTENTE-DASH.md`). Batte l'analisi a tavolino: da qui esce la prossima lista di fix,
      ordinata dai fatti.
- [ ] **Theming — Fase 0 residua** (lavoro estetico, non usabilità): migrare i restanti `style=""`
      **non-bottone** (layout griglie/modali) a classi/token e aggiungere token spaziatura/ombra/raggi,
      così la dash diventa "drop-in" per un `theme.css` (vedi `ANALISI-CLAUDE-DESIGN.md`, aggiornato).

---

## 🔎 APERTO — Arricchimento outreach: email non trovata su sito JS (prossima sessione)
Caso reale: B&B **`giubbonarisuites-adm.com/contatti`** (gruppo ADM Hospitality). L'arricchimento
trova il **sito** (via Google Places) ma **NON l'email**, né con Haiku né con Sonnet — anche dopo il
fix "passo 1c" (visita il sito appena Google lo trova). Sulla pagina `/contatti` a occhio ci sono 2
email: `Info@adm-hospitality.com` e `giubbonari @adm-hospitality.com` (con uno spazio prima della @).

**Ipotesi da verificare (in ordine):**
1. **Sito JS-rendered**: `ardySafeHttpGet` scarica l'HTML grezzo; se le email sono iniettate via
   JavaScript non compaiono nel sorgente → lo scraping non le vede. *Verifica:* logga status code +
   se la stringa `@adm-hospitality` è presente nell'HTML grezzo di `/contatti`.
2. **Email offuscate con spazio** (`giubbonari @adm-...`): la regex non le cattura. `Info@adm-...`
   (senza spazio) invece dovrebbe essere catturata **se** è nell'HTML grezzo (vedi punto 1).
3. **Dominio diverso / redirect / 403**: il sito da Places potrebbe non essere quello giusto, o
   bloccare il fetch. *Verifica:* quale URL ha proposto Places e cosa risponde.

**Possibili interventi (da valutare costi):** log diagnostico del fetch; gestire l'offuscamento
"spazio @"; fallback con rendering headless solo on-demand. File coinvolti: `ardy-enrich.php`
(`ardyEnrichScrapeSite`, `ardyEnrichExtractEmail`), `ardy-net.php` (`ardySafeHttpGet`).

> Selettore modello AI (Haiku/Sonnet) in dash già fatto e deployato; Haiku è il default economico.

---

## 🗺️ STANDBY — Ricerca OSM: timeout Overpass su raggi ampi (deciso di rimandare)
**Confermato:** con **raggio 1 km la ricerca OSM funziona**; su raggi ampi (es. 10 km su Roma) Overpass
va in **timeout** e il tool mostrava "0 trovati / Servizio non raggiungibile". **Google funziona bene.**
Fix già deployato (`ardy-outreach-api.php`): riconosce il `remark` di timeout, ritenta sul mirror
`overpass.kumi.systems`, e dà un messaggio che invita a ridurre il raggio (3–5 km).

**Workaround attuale:** usare raggi piccoli (1–5 km) per quartiere, o la fonte **Google** per zone ampie.

**Da valutare alla ripresa (se serve coprire aree grandi su OSM):**
- query più leggera (solo `node`? togliere `tourism=bed_and_breakfast`, raro, e tenere `guest_house`);
- "chunking" automatico dell'area in più celle piccole con merge dei risultati;
- istanza Overpass dedicata/a pagamento, oppure preferire Google per i raggi grandi;
- verificare se il mirror kumi è raggiungibile dal host (firewall — vedi `ANALISI-FIREWALL-HOST.md`).

---

## ⏰ DA CONTROLLARE SUBITO (prossima sessione) — i 2 CRON sono davvero attivi?
La sessione 25/06 ha deployato briefing mattutino + rollover nota (vedi sotto). Michela ha **impostato i cron
di corsa** ma NON ha fatto in tempo a verificarli. **Primo task: confermare che siano attivi e che girino.**

I due cron previsti (server, fuso Europe/Rome; `<SEGRETO>` = `WA_LOOKUP_SECRET`):
```
0 6 * * 1   curl -s -H "X-Ardy-Secret: <SEGRETO>" https://ardyagent.ardy-lab.it/ardy-rollover-nota.php   >/dev/null 2>&1
0 9 * * 1-5 curl -s -H "X-Ardy-Secret: <SEGRETO>" https://ardyagent.ardy-lab.it/ardy-briefing-mattino.php >/dev/null 2>&1
```
**Come verificarlo:**
- **Diretto** (serve accesso server): `crontab -l` dell'utente giusto, oppure la UI **cPanel → Cron Jobs**.
  ⚠️ Attenzione all'utente: l'app gira come `micoperibg` ma il cron potrebbe essere su un altro utente/root —
  controllare dove è stato messo. Il comando deve avere l'header `X-Ardy-Secret` col valore reale.
- **Indiretto** (anche dalla sessione web, senza SSH): (a) il **briefing**: Michela ha ricevuto l'email delle
  ~9:00 in un giorno feriale? (b) il **rollover**: lunedì dopo le 06:00 dev'esserci in `note_staff` una riga
  nuova con `settimana` = ISO della settimana corrente e `created_at` ~06:00 (lo si vede dalla nota in dashboard:
  data "agg." aggiornata al lunedì). Se manca, il cron del rollover non è partito.
- **Test manuale** (sempre ok per provare l'endpoint a mano): aggiungere `?force=1&secret=<SEGRETO>` all'URL.

### ⚠️ Segreto ruotato ≠ aggiornato ovunque — da tenere d'occhio (13/07/2026)
Scoperto risolvendo il workflow n8n **"Lead Monitor"**: andava in **403 ogni ora** (visibile nelle sue
Executions) perché il suo nodo **HTTP Request** aveva l'`X-Ardy-Secret` **hardcoded col valore
pre-rotazione**. Il ramo WhatsApp invece è sopravvissuto perché il suo Code node legge il segreto dalla
**variabile d'ambiente n8n** (`process.env.WA_LOOKUP_SECRET`), aggiornata alla rotazione.
- **Fix Lead Monitor**: incollato il valore attuale di `WA_LOOKUP_SECRET` nell'header (letterale). Provato
  a usare l'expression `{{ $env.WA_LOOKUP_SECRET }}` ma **questo n8n la blocca** negli HTTP Request
  ("access to env vars denied"): funziona solo nei Code node → per gli HTTP Request serve il valore a mano.
  Verificato live: `ok:true`, 3 lead processati.
- **Ancora da verificare (SOLO se si notano anomalie)**: i 3 endpoint schedulati che condividono lo stesso
  segreto — `ardy-rollover-nota.php`, `ardy-briefing-mattino.php`, `ardy-chiusura-sessioni.php` — potrebbero
  avere ancora il segreto **vecchio** nella riga cron (o nel workflow n8n, per chiusura-sessioni).
- **Decisione (13/07)**: **crons lasciati come stanno.** Non testarli chiamandoli a mano: girano su GET e
  fanno subito il lavoro vero (mandano WhatsApp a Michela). Verifica sicura = confronto **visivo** del valore
  `X-Ardy-Secret` in **cPanel → Cron Jobs** col segreto attuale. Si riprende solo se: il briefing del mattino
  non arriva, il rollover del lunedì non crea la riga in `note_staff`, o mancano le notifiche di chiusura chat.
- **Regola generale**: alla prossima rotazione di `WA_LOOKUP_SECRET`, aggiornare **tutti** i punti che lo
  portano a mano — nodi n8n HTTP Request (Lead Monitor, ecc.) **e** righe cron — oltre alla env var n8n e a
  `ardy-config.php`. I Code node n8n (ramo WhatsApp) si allineano da soli via env var.

---

## 💾 BACKUP OFF-SITE Backblaze B2 — ✅ FATTO (sessione `ardy-infra`, 24/06/2026)
Chiude il punto **#4 (backup off-site)** del `PIANO-MIGRAZIONE.md` — fatto **ora, sull'infra attuale**
OVH/cPanel, senza aspettare il cutover sul nuovo VPS.
- **cPanel → B2** configurato su **entrambi i VPS gemelli OVH (WHM/cPanel)** con **bucket dedicati e
  isolati per server**: Server 1 → bucket `UGLmico` (prefisso `srv1`); Server 2 → bucket `micoper`
  (chiave ristretta). Lifecycle **30 giorni** + **SSE-B2** attivi; destinazioni **validate e abilitate**
  (`disabled:0`).
- **n8n** (solo Server 2): backup del volume `/opt/n8n/n8n_data` (SQLite) via **rclone**, script
  `/root/bin/n8n-backup.sh` in **cron alle 04:00** → `micoper/n8n/`.
- Guida + script committati su repo **`ardy-infra`** (branch `claude/modest-cori-hc1w1l`).
- **Nessun impatto sull'app Ardy**: backup separati, niente da toccare nel codice.

**Pending (non bloccante):**
1. Verificare che i primi backup completi abbiano popolato `UGLmico/srv1/` e `micoper/srv2/` (upload in corso).
2. **Cleanup sicurezza**: ruotare la app key del Server 1 (ora "All buckets") in una **ristretta a `UGLmico`**
   per isolamento totale.
3. Fare almeno una **prova di restore** reale (account cPanel di test + volume n8n).

---

## 🔧 NOTE OPERATIVE (servono sempre)

**⚠️ Sole non risponde su WhatsApp ma la webchat sì → è n8n giù.** Il ramo WhatsApp è
Meta → n8n → `ardy-wa-lookup.php` → Claude; la webchat (`ardy-proxy.php`) NON passa per n8n.
Check rapido: aprire `https://n8n.ardy-lab.it` (503/523 = giù). Sul server (root via SSH/WHM):
```
docker ps | grep -i n8n          # il container è n8n_app, espone SOLO 127.0.0.1:5678
systemctl is-active docker
docker start n8n_app             # se è giù ma Docker è su
```

**⚠️ firewalld DISABILITATO di proposito (19/06/2026) — non riattivarlo.** Con firewalld attivo il
daemon Docker non parte (nftables `el9_8` vs firewalld `el9_7`, passthrough rotta). Soluzione applicata:
`systemctl stop firewalld && systemctl disable firewalld`. Se Docker non parte dopo un reboot/aggiornamento,
verificare `systemctl is-enabled firewalld` = `disabled`.

**✅ FIREWALL HOST = csf LIVE.** Firewall host = **`cpanel-csf`** (fork cPanel; installare SOLO via
`yum install cpanel-csf`, mai il tarball — ConfigServer ha chiuso). Config + runbook in
**`ANALISI-FIREWALL-HOST.md`**. Punti critici:
- **Docker/n8n**: csf con `DOCKER=1` + `DOCKER_DEVICE/NETWORK4` sul bridge reale `br-b118407a7c22`
  (172.18.0.0/16) **e** `ETH_DEVICE_SKIP="br-b118407a7c22"`. ⚠️ Se la rete `n8n_default` viene ricreata,
  il nome bridge cambia → aggiornare quei valori e `csf -r`.
- **Cloudflare** in `csf.allow` (mai bannare CF). **Fail2ban** disabilitato (LFD di csf lo rimpiazza). **rpcbind** off.
- Egress aperto per ora → tightening è un follow-up (occhio alla porta SMTP di Brevo).

**Deploy sul server** (da root):
```
runuser -u micoperibg -- bash -c 'cd ~/repositories/ardyagent && git pull origin main && ./deploy.sh'
```
**⚠️ Schema DB = `ardy-migrate.php`** (eseguito da `deploy.sh` dopo l'rsync). Unico posto dove si
creano/alterano tabelle e colonne (niente DDL negli endpoint). Idempotente. **Nuova tabella/colonna → qui.**

**Log errori PHP**: `/home/micoperibg/logs/ardyagent_ardy-lab_it.php.error.log`
(es. `grep "ARDY USAGE" <file> | tail -8` per token in/out/cache).

**Auth endpoint via fetch**: NON usare `ardyRequireAuth()` (in CGI/FPM l'header `Authorization` non
arriva → rifarebbe login). Affidarsi al `.htaccess` (Basic Auth).
> ⚠️ Un endpoint "protetto via .htaccess" lo è SOLO se il nome è **elencato** nel blocco `<FilesMatch>`.
> Ogni nuovo endpoint riservato va aggiunto a quella regex e testato con `curl` senza credenziali (deve dare 401).

**⚠️ Git — lineage di `main`**: la storia BUONA parte dal root `98b352f`. Esiste una vecchia lineage
orfana (root `b49606b`, i "v2.0…") da NON rifondere. Se git dà *"refusing to merge unrelated histories"*,
quel branch è sulla lineage vecchia → riparti da `origin/main`. Chiudere sessione: branchare da
`origin/main` aggiornato e fast-forward su `main`.
> ✅ **Cleanup branch fatto (21/06/2026):** i vecchi branch `claude/*` sono stati cancellati. Prima della
> pulizia, verificato per CONTENUTO che il loro lavoro fosse già in `main`; recuperato il solo file unico
> utile, `ARDY-EXPERIENCE-PIANO-TECNICO.md` (da `quirky-davinci`). Scartato `ardy-wa-ricevi-pdf.php`
> (da `quote-data-extraction`, feature WhatsApp-PDF incompleta) per scelta. ⚠️ La cancellazione branch NON
> è possibile dalla sessione web (proxy git nega il delete dei ref ≠ branch di sessione, 403; il GitHub MCP
> non espone delete-branch) → va fatta dalla UI GitHub o con `gh`/`git push --delete` da una macchina con i permessi.

**Outreach — Google Places**: chiave in `ardy-config.php` = `ARDY_GOOGLE_PLACES_KEY` (Places API New,
ristretta per IP del VPS — le chiamate escono in IPv4 forzato). Tetto giornaliero `ARDY_PLACES_DAILY_CAP`
(default 500; `0` = illimitato). Numero WhatsApp di Sole nelle email/lettere = `ARDY_WA_PUBLIC_NUMBER`
(fallback `393793756437`). Mittente lettere cartacee = costante `ARDY_MITTENTE` in `ardy-outreach.html`.

**`session_id`**: sempre sanificato (no path traversal) prima di toccare i path file.

**PDF preventivo**: cache per content-hash (`PDF_CACHE_VER` in `ardy-preventivo.php`). Se cambi layout/CSS, **bumpa `PDF_CACHE_VER`**.

---

## 🧭 OUTREACH — ROADMAP (idee 21/06, da progettare)
La direzione che vogliamo dare allo strumento, da affrontare per prossimi step:

1. **Pipeline lead — evoluzioni** (la v1 è live: vista Pipeline + promozione a Cliente/Partner). Idee:
   drag&drop tra fasi; campo "valore/nota trattativa"; collegamento del lead alla **campagna d'origine**;
   notifica a Michela quando un lead passa a "Risposto".
2. **Campagne con obiettivi diversi + Sole espone il piano** — es. B&B "Galleria Diffusa": Sole deve saper
   **esporre il piano marketing del progetto** a tutti i registrati sul CRM, sia su **WhatsApp** sia su una
   **pagina dedicata** (webchat + **codice di verifica**, come il `codice_accesso` cliente esistente).
3. **Prompt dedicato per campagna** — ogni campagna ha il suo prompt per Sole (contesto/obiettivo), così Sole
   risponde in linea con quell'iniziativa.
4. **Codice etico AI — ✅ DEPLOYATO 25/06.** Riga "Come usiamo l'AI" (non aggressione, tutela
   privacy/sicurezza dati, mai uso fraudolento) ora in TUTTE le email (`ardy_email_codice_etico()` in
   `ardy-email.php` → footer cliente, grazie-consegna, solleciti, outreach `brevoSend`), nelle lettere cartacee
   e anteprime (`ardy-outreach.html`), e nei system prompt di Sole (`ardy-system.txt` → web+WhatsApp,
   `ardy-proxy-lavorazione.php` → chat lavorazione). ⚠️ Verificare dal vivo dopo deploy che la riga compaia in
   un'email reale e che Sole sappia esporre il codice etico se richiesto.

### 🔌 Outreach — Altre fonti dati (VIES / P.IVA) — NOTA
Portali aziendali IT quasi tutti gated → niente scraping. Vie aperte utili: **VIES** (ec.europa.eu, gratis,
API senza chiave: P.IVA → ragione sociale + indirizzo ufficiale) **da integrare col futuro campo Partita IVA**;
OpenCorporates (tier gratuito); Registro Imprese scheda gratuita indicizzata. I portali gated si attingono già
via il passo **Claude web search** dell'agente Arricchimento. Google Places + web search coprono il grosso.
INI-PEC non automatizzabile (captcha) → PEC via API a pagamento, eventuale task a sé.

### 🪶 Outreach — migliorie minori (quando capita)
- **QR code** (wa.me/sito) sulla lettera cartacea; template "cartaceo" dedicato; tuning offset busta a finestra.
- **"🔄 Rigenera / varianti"** nel generatore "Crea con AI".
- Dedup contatti più "furbo" (normalizzazione: togliere Srl/Snc, accenti, spazi) per beccare le varianti di nome.

---

## ⏳ DA VERIFICARE DAL VIVO / AZIONI MANUALI
- **🛋️ Consulenza Interior Design (25/07, DEPLOYATO, test sul campo NON ancora fatto).** Nuovo servizio:
  webchat dedicata con Sole su `ardy-lab.it/interior-design` + sezione 🛋️ nella scheda cliente. Sole conduce
  una **intervista guidata** (chiede il permesso → cinque domande una alla volta: ambiente, stile, colori,
  luce, budget → invito alle foto → **dati anagrafici solo alla fine**), poi scrive in scheda il
  **💬 Riepilogo di Sole**, unico campo della sezione insieme alle foto del cliente.
  **Test da fare (due giri, meglio dal telefono):**
  1. *Intervista completa*: rispondere a tutte le domande, mandare una foto col pulsante
     **📷 Fai la foto al tuo ambiente**, arrivare fino ai contatti. Poi in dashboard verificare
     (a) servizio = "Consulenza Interior Design", (b) sezione 🛋️ **già accesa** senza intervento manuale,
     (c) riepilogo scritto e sensato, (d) foto visibili **dentro la sezione** (🖼️ Immagini di riferimento)
     oltre che in Scheda operativa.
  2. *Intervista saltata*: dettare subito nome/telefono/email senza rispondere alle domande. Deve arrivare
     comunque tutto giusto: l'accensione **non** dipende dal tool ma dal campo `origine` mandato dal widget
     (`ardy-proxy.php`, blocco dopo `salva_lead_crm`). Nel riepilogo Sole deve **dire cosa manca**, non inventarlo.
  - ⚠️ **Continuare a chattare DOPO aver mandato foto**: era il punto che si rompeva ("Errore nella risposta AI",
    foto originali in history oltre il limite 5MB/immagine dell'API). Risolto ridimensionando nel browser
    (1600px, JPEG q82) — da riconfermare dal vivo.
  - Se il **tono o il ritmo** delle domande non convince, si tara in `ardy-system.txt` (sezione
    "CONSULENZA INTERIOR DESIGN") **senza toccare codice**: solo deploy.
  - Nota: chi abbandona a metà intervista **non lascia scheda** (senza telefono non si crea) — voluto. Resta
    comunque la notifica WhatsApp "💬 Chat conclusa — (non in CRM)" dal job orario.
  - File: `ardy-chat-interior-design.js`, `ardy-proxy.php`, `ardy-system.txt`, `ardy-michela-app.html`,
    `ardy-dossier.php`, `ardy-migrate.php`. PR #92-#100.
  - **Azioni WordPress (NON coperte dal deploy — `wordpress-snippets/` è solo backup):**
    - ✅ snippet WPCode **"Pulsante flottante ovunque"**: aggiunto `/interior-design` alle esclusioni
      (il CTA verde copriva il toggle del widget e portava fuori pagina). Verificato dal vivo.
    - [ ] **contenuto pagina** `/interior-design`: la card *"1 · Racconti a Sole"* è stata riscritta dopo
      il primo incolla (ora dice "cinque domande", che si possono allegare foto e che i **contatti si
      chiedono solo alla fine**). Se non è stata riportata a mano, la pagina promette un flusso diverso da
      quello reale. Testo aggiornato in `wordpress-snippets/interior-design-page.html`. Solo cosmetico.
- **📷 "Usa foto della scheda" nelle fasi (19/07, DEPLOYATO, da testare sul campo).** Nel form "Crea e pubblica
  nuova fase" il pulsante **USA FOTO DELLA SCHEDA** apre le foto della scheda come miniature selezionabili; le
  scelte vengono scaricate, normalizzate (`fotoNormalizza`) e aggiunte a `lavImmagini`. **Test:** creare una
  fase includendo una foto della scheda → verificare che compaia nella **pagina di lavorazione pubblicata**
  (non solo in anteprima). File: `ardy-michela-app.html` (`toggleFotoSchedaPicker`/`aggiungiFotoSchedaSelezionate`).
- **✦ Avvio pagina lavorazione dal box "Periodo del lavoro" (04/07, da testare) — DEPLOYATO, mai provato dal vivo.**
  Nel box "📅 Periodo del lavoro" (compare passando un cliente a IN_LAVORAZIONE), sotto le date c'è ora una
  sezione **"✦ Avvia la pagina lavorazione"**: foto (SCATTA FOTO / DALLA GALLERIA) + bottone **PUBBLICA AVVIO
  LAVORO + NOTIFICA CLIENTE**. Riusa lo stesso endpoint delle fasi (`ardy-pubblica-lavorazione.php`, `fase_nome`
  fisso "Avvio lavorazione"): crea il **primo** post WordPress della lavorazione, invia email + WhatsApp al
  cliente, e il box sparisce da solo una volta creato il post (da lì in poi si aggiorna con "🔨 Crea e pubblica
  nuova fase" più sotto). Nessuna modifica al backend. **Test da fare:** mettere un cliente reale/di prova su
  IN_LAVORAZIONE → scattare/scegliere una foto nel nuovo box → pubblicare → verificare (a) il post compare sul
  sito con foto e testo generato da Claude, (b) email + WhatsApp arrivano al cliente, (c) il box sparisce e la
  fase "Avvio lavorazione" compare in "📋 Fasi pubblicate", (d) una fase pubblicata dopo dal modulo sotto
  **aggiorna la stessa pagina** (non ne crea una seconda). File: `ardy-michela-app.html` (box date + JS
  `pubblicaAvvioLavoro`/`handleAvvioFotoUpload`/`aggiornaVisibilitaAvvio`).
- **Rimosse "Fasi previste dal sopralluogo" + badge "📐 da pianificare" (04/07) — DEPLOYATO, verifica veloce dopo
  deploy.** Su richiesta di Michela: tolto dal box Note il pannello "📐 Fasi previste dal sopralluogo (dalla
  libreria)" (generava bozze fasi da template scelti durante il sopralluogo — giudicato ridondante) e il badge
  sidebar "📐 da pianificare" che gli era collegato. Restano invariati il semaforo giallo "Date da pianificare"
  (date lavoro non impostate) e le altre due vie per creare bozze fasi (💾 SALVA IN BOZZA, estrazione da
  preventivo PDF). **Verifica dopo deploy:** aprire una scheda in SOPRALLUOGO/ACCONTO con una nota → non deve
  comparire più né il pannello template nel box Note né il badge "da pianificare" in sidebar.
- **Date sopralluogo/consegna in dashboard (nuovo) — test dopo deploy:**
  - *Sopralluoghi (lista, Fase 1)*: ✅ deployato; aggiungi/sposta/elimina ci sono e **rispondono bene**
    (test rapido ok). Restano i test funzionali completi: nella scheda, sezione "📅 Sopralluoghi" → **Aggiungi** una visita
    (data/ora + etichetta) e verificare che su **Google Calendar** compaia l'evento; **sposta** una
    visita (cambia data/ora + 💾) → l'evento si sposta, niente doppione; **elimina** (🗑) → l'evento
    sparisce anche dal calendario. Aggiungere una **seconda** visita allo stesso cliente (es. "2°
    sopralluogo") → devono coesistere. Verificare che un sopralluogo fissato da **Sole su WhatsApp**
    compaia poi nella lista (riconciliazione "pigra" alla riapertura scheda).
  - *Sopralluoghi via Sole su WhatsApp (Fase 2)* — solo PHP, NIENTE re-paste n8n: da chat staff provare
    "aggiungi un 2° sopralluogo per Alberto giovedì alle 15" (deve AGGIUNGERE, non dire "ha già un
    appuntamento"); "che sopralluoghi ha Alberto?" (li elenca); "sposta il sopralluogo di Alberto" quando
    ne ha 2 → Sole deve CHIEDERE quale. Verificare che le visite aggiunte da Sole compaiano anche nella
    lista in dashboard, e viceversa. ⚠️ Nota: il cliente che prenota da sé (canale cliente) resta a UN
    sopralluogo (mirror); compare nella lista via riconciliazione — ampliarlo è un eventuale follow-up.
- **Test Piano B — spostamento appuntamento su WhatsApp** (da numero NON staff con sopralluogo già fissato):
  verificare (a) l'evento Google Calendar si SPOSTA (non ne crea uno nuovo), (b) Michela riceve notifica
  "SPOSTATO" (WA + email), (c) al cliente arriva la conferma del nuovo orario.
- **Recuperare le date perse pre-fix** (es. Margherita Mottini): reinserire a mano (il fix anti-clobber evita
  che ricapiti ma non ripristina i dati persi).
- **UX "Modifica" su Preventivo (allegato)**: l'estrazione "🔍 Leggi dati dal PDF" è solo al primo allegato,
  non in modifica. Da decidere se serve un modo per ri-estrarre/correggere prezzi in modifica senza doppioni di fasi.
- **Template `aggiornamento_fase`** (4 var) e **`sollecito_pagamento`**: provare con caso reale/fittizio.
  ⚠️ Confermare che il body del template Meta `aggiornamento_fase` abbia **esattamente 4 variabili** (altrimenti
  Meta rifiuta, err 132000/132018).
- **Conoscenza di bottega di Sole** (`ardy-conoscenza-restauro.txt`): è una **v1** → Michela la rivede e la
  "ardy-izza" con le sue tecniche/parole.
- **Export WPCode** (Tools → Export All → JSON): rinfrescare il backup `wordpress-snippets/` + mappa.
- **Briefing del mattino con la nota settimanale (FIX deployato, da verificare dal vivo):** al "buongiorno"
  Sole deve ora includere da sola il blocco "🗒️ COSE DA FARE QUESTA SETTIMANA" (la nota più recente),
  senza che Michela la chieda. Fix: blocco aggiunto in `ardy_riepilogo_settimana()` + istruzione briefing
  in `ardy-wa-lookup.php`. Test: salutare Sole al mattino con una nota salvata → deve elencarla nel resoconto.
- **💬 Risposte WhatsApp alle notifiche → in dash nelle Conversazioni (DEPLOYATO 04/07, PR #34, mai provato dal
  vivo).** Quando il cliente risponde su WhatsApp a una notifica della dash (inizio lavoro / fase / grazie /
  sollecito), la risposta viene ora salvata dal **webhook** in `wa_messaggi` (nuova lib `ardy-wa-store.php`,
  idempotente per `wa_msg_id` + dedup morbido vs n8n) e compare nella scheda cliente → 💬 Conversazioni, **anche
  se Sole non risponde**. Le notifiche in uscita sono registrate come `role=assistant` (riga 📢) per dare
  contesto. **PREREQUISITO:** `ardy-migrate.php` deve aver creato la colonna `wa_messaggi.wa_msg_id` + indice
  `uq_wa_msg_id` (verifica in phpMyAdmin o `php ardy-migrate.php` → riga `OK/skip wa_messaggi.wa_msg_id`).
  **Test dal vivo:** da un numero che è un cliente in CRM, scrivere a Sole (+39 379 375 6437) un testo
  riconoscibile → aprire la scheda di quel cliente → 💬 Conversazioni → deve comparire il messaggio in arrivo
  (e, se prima è stata inviata una notifica, anche la riga 📢 in uscita). Se non compare: cercare
  `ARDY WA STORE` / `ARDY WEBHOOK persist` nell'`error_log` (di solito = migrazione non eseguita); controllare
  in `ardy-wa-log.json` se il messaggio è arrivato al webhook.

---

## 🌐 GOOGLE BUSINESS PROFILE — post automatici delle fasi (✅ SBLOCCATO 20/07 — era egress IPv6)
**Obiettivo**: pubblicare i post delle fasi sul profilo Google Business **Ardy di Michela Panella**.
**✅ RISOLTO (20/07):** il 403 persistente NON era mai un problema di Google/allowlist/account: il server OVH
usciva su **IPv6** e l'API Business (privata) rifiuta l'egress IPv6. Forzato **IPv4** (`CURL_IPRESOLVE_V4`) in
`ardy-gbp.php` → `ardy-gbp-check.php` "Quarto tentativo — forza IPv4" dà **200** (egress `57.131.47.5`). Toggle
Google **riabilitato** in `ardy-michela-app.html`. ⏭️ Resta solo: pubblicare una **fase di test** e verificare
che il post compaia sulla scheda. (Cronistoria completa del debug più sotto.)
**STATO REALE (04/07):** nonostante l'attesa dei 7-10 gg lav. fosse scaduta, `ardy-gbp-check.php` in
produzione ha dato **403 "ACCESSO ALLA BUSINESS PROFILE API NON CONCESSO"** — risposta HTML generica di
Google (non un errore JSON dell'API), cioè la richiesta è respinta **prima** di arrivare al servizio: il
progetto Cloud (Project Number ricavato dal client_id OAuth: **532339794075**) **non è nell'allow-list**
della Business Profile API. Il form Basic API Access (17/06, ID 3-7851000041139) o non è ancora stato
lavorato da Google, oppure è stato inviato per/da un progetto Cloud diverso da quello 532339794075.
**Aggiornamento (04/07, risposta support Google via email, operatore "Ravi"):** NON è (solo) questione di
attendere l'approvazione — è un passo MANUALE da fare in Cloud Console. Il supporto dice: l'endpoint
**"Google My Business API"** non è *enabled* nel progetto Cloud (è un'API privata, visibile solo dopo che
l'account è stato "provisionato" da Google).
**Aggiornamento (04/07, verificato in console):** il progetto Cloud è **`ardy-lab`** (Project Number
532339794075), account loggato **`ardy.documenti@gmail.com`**. **"Google My Business API" risulta GIÀ
presente** nella dashboard delle API del progetto (badge "Privato") — quindi il passo "Enable" non è il
problema. Nonostante questo, `ardy-gbp-check.php` dava ancora 403 "non in allow-list": il blocco è quindi
sul **provisioning dell'account** lato Google (non sull'enablement dell'API).
**Fatto (04/07):** risposta inviata alla mail di supporto Google (operatore "Ravi") con i dati per il
provisioning:
- Google account: **ardy.documenti@gmail.com**
- Cloud project: **ardy-lab** (project number 532339794075)
- Request ID originale: **3-7851000041139**
**Aggiornamento (11/07, precisazione):** filtrando le quote per servizio (`mybusiness.googleapis.com`) nella
pagina progetto-wide di IAM & Admin → Quote, le **8 quote di Google My Business API risultano popolate e
sane** (es. 250.000 requests/day, 3.000 V4 General Requests/minuto, ecc.) — "0%"/"0" in quella vista sono
solo **utilizzo attuale** (zero chiamate riuscite finora), NON il limite. Quindi non è un problema di quota
numerica: il blocco resta il **gate di allow-list** a monte (stesso che dava 403 prima di arrivare al
servizio). Il pulsante "Modifica quote" in Console **non è la via giusta** per questa API privata.
**Ri-testato `ardy-gbp-check.php` l'11/07 (15:19):** **stesso identico 403** "ACCESSO NON CONCESSO" di
prima — nessun cambiamento nonostante l'API risulti enabled e le quote popolate. Conferma che l'unico
sblocco possibile è il provisioning manuale lato Google, non azionabile da Cloud Console.
**Fatto (11/07, pomeriggio):** inviato il sollecito di follow-up allo stesso thread di Ravi (dati già
forniti il 04/07: account `ardy.documenti@gmail.com`, progetto `ardy-lab` / 532339794075).
**Risposta Google ricevuta (13/07, 2 giorni dopo il sollecito dell'11/07) — possibile causa reale trovata:** l'operatore
elenca **8 API diverse** della "famiglia" Business Profile da abilitare (non solo "Google My Business
API" v4, quella dei post — già confermata enabled). `ardy-gbp-check.php` chiama per primo un'API
**diversa**: `mybusinessaccountmanagement.googleapis.com` (**"My Business Account Management API"**) — MAI
verificata come enabled finora. Ipotesi: è quella mancante a causare il 403 persistente, non il
provisioning dell'account. Servono almeno queste due (oltre a quella già enabled), usate dal codice
(`ardy-gbp.php` → `gbp_get_parent()`):
- **My Business Account Management API** (v1, risolve gli account)
- **My Business Business Information API** (v1, risolve le location)
Nota: la mail contiene un placeholder non compilato `<emailaddedtotheGoogleGroup>` (bug del loro
template — non hanno detto quale email hanno aggiunto al gruppo di accesso) e un avviso su Google
Workspace che non si applica (l'account è un Gmail normale, non Workspace).
**Ipotesi ESCLUSA (~14/07, verificata in console):** sia **"My Business Account Management API"** sia **"My
Business Business Information API"** risultano **già "API abilitata"** (badge verde). Non era quello il
problema — si torna alla conclusione originale: il blocco è il **gate di allow-list/provisioning
dell'account** lato Google, indipendente dall'enablement delle API in Cloud Console (tutte e 3 le API
necessarie sono enabled, eppure `ardy-gbp-check.php` continua a dare 403).
**Conferma da screenshot email (rivisto 15/07):** ricevuto lo screenshot integrale della mail di Ravi
(datata **13/07 07:28 PM**). Contenuto = quanto già annotato: 8 API della famiglia Business Profile, con
**"Google My Business API 4.9"** in cima che include le feature FoodMenus / Media / Reviews / LocalPosts,
più My Business Account Management / Lodging / Place Actions / Notifications / Verifications / Business
Information e Business Profile Performance API. **Lead principale ora più netto:** la mail dice
testualmente di *fare login con l'email `<emailaddedtotheGoogleGroup>`* e cercare l'API nella "API
Library" — cioè Google ha aggiunto **una specifica email a un Google Group** di accesso, e il
provisioning dell'API privata è legato a QUELL'identità. Se l'email aggiunta al gruppo ≠
`ardy.documenti@gmail.com` (l'account con cui operiamo e con cui `ardy-gbp-check.php` gira via OAuth), il
403 persisterebbe anche con tutte le API enabled: staremmo bussando con l'identità sbagliata. Il
placeholder `<emailaddedtotheGoogleGroup>` non compilato è quindi il **buco informativo chiave**, non un
dettaglio secondario.
**Scoperta (15/07, ricontrollata la casella `ardy.documenti@gmail.com` via connettore Gmail):** nel thread
del caso **[4-4300000041395]** c'è una mail di Ravi del **06/07** mai registrata in questo log:
*«we confirmed that your project 532339794075 __has been allowlisted__, and access has been granted to
the account ardy.documenti@gmail.com»* — quindi (a) l'email aggiunta al gruppo È `ardy.documenti@gmail.com`
(placeholder di fatto risolto), (b) Google considera l'allowlist già fatta dal 06/07. **Contraddizione
aperta:** il re-test dell'11/07 (15:19), 5 giorni DOPO quella conferma, dava ancora lo stesso 403 →
o la propagazione non è mai avvenuta, o l'allowlist è registrata su qualcosa di sbagliato. È questo il
punto da mettere davanti a Google. Due anomalie di casella notate: l'intero thread del caso è **nel
Cestino** di Gmail (recuperarlo per non perderlo dopo 30 gg) e la mail del 13/07 (screenshot) **non è in
questa casella** — il sollecito dell'11/07 è quindi partito da un altro indirizzo/form (verificare da
quale, per tenere un solo filo col supporto).
**Fatto (15/07):** creata **bozza di risposta in Gmail** (`ardy.documenti@gmail.com`, reply al thread del
caso 4-4300000041395, in inglese, firma Michela) pronta da inviare. Contenuto: 3 API già enabled (badge
verde), allowlist confermata da loro il 06/07 eppure 403 identico all'11/07 (prima chiamata =
`GET mybusinessaccountmanagement.googleapis.com/v1/accounts` via OAuth di ardy.documenti), richiesta di
confermare quale email è stata aggiunta al Google Group (placeholder non compilato) e di ri-verificare la
propagazione dell'allowlist; nota che l'account è Gmail normale (avviso Workspace non applicabile).
**Scoperta (15/07, Andrea in console):** la **My Business Lodging API NON è abilitata** (pulsante "Abilita"
ancora visibile). Non può essere la causa del 403 — l'enablement è per singolo servizio, il check fallisce
alla prima chiamata verso `mybusinessaccountmanagement` (già enabled), e il codice non usa Lodging (è per
hotel/B&B) — ma Ravi l'ha elencata tra le API "that must be enabled": abilitarla toglie al supporto
l'appiglio "non avete seguito le istruzioni". Da verificare/abilitare anche le altre 4 mai controllate:
**Place Actions, Notifications, Verifications, Business Profile Performance**.
**Fatto (15/07):** Andrea ha **abilitato tutte le 8 API dell'elenco di Ravi** (le mancanti incluse). In
attesa di propagazione, poi re-test.
**Ipotesi ESCLUSA — verifica app OAuth / consent screen (15/07, verificata in console):** il warning giallo
"La tua app deve essere verificata" **non c'entra col 403**. Stato pubblicazione = **"In produzione"**
(quindi refresh token NON scadono a 7 gg, nessuna trappola modalità Testing), tipo utente Esterno, **1/100
utenti** (ampio margine). Il warning + schermata "app non verificata" derivano solo dallo scope sensibile
`business.manage` non approvato formalmente, ma **il tetto di 100 utenti non blocca** e per l'utente
proprietario l'app funziona: infatti Calendar/Gmail girano e il token si rinnova. Il consenso OAuth è sano,
non è quello il blocco. Non avviare la verifica formale (processo lungo, inutile per uso proprio).
**RE-TEST DECISIVO (16/07 05:04):** rilanciato `ardy-gbp-check.php` DOPO aver abilitato tutte e 8 le API →
**403 IDENTICO**. Corpo grezzo confermato: `<title>Error 403 (Forbidden)!!1</title>`, *«Your client does
not have permission to get URL /v1/accounts from this server»*, `content-type: text/html` (front-end
Google, non l'API), progetto `532339794075`. **Chiude ogni ipotesi lato nostro:** l'enablement non era la
causa, l'OAuth è sano, e nonostante l'allowlist confermata da Ravi il 06/07 (10 gg fa) la richiesta è
respinta a monte. **Non è più questione di propagazione — il blocco è interamente lato Google.**
**RETTIFICA "secondo thread" (16/07): NON esiste — è tutto UN solo thread** [4-4300000041395] dentro
`ardy.documenti@gmail.com`. L'ipotesi di una seconda casella era sbagliata: le mail del 13 e 15/07 non si
trovavano solo perché il thread era finito nel **Cestino** (ora ripristinato in Posta in arrivo). Verificato
sui dettagli del messaggio: mail 15/07 15:26 = *da* googlebusinessprofile-support@google.com *a*
`ardy.documenti@gmail.com`, oggetto Re: [4-4300000041395]. Cronologia reale del thread: 11/07 sollecito
(SENT) → 13/07 Ravi elenca 8 API → 14/07 nostra risposta "già tutto enabled, 403 resta" (SENT) → 15/07
10:41 nostra 2ª risposta (SENT, era la bozza generata qui) → **15/07 15:26 Ravi: «Please share screenshots
of the error»** (ULTIMO messaggio, da riscontrare). Nessuna bozza in sospeso residua (verificato).
**Fatto (16/07):** creata **nuova bozza in Gmail** (`ardy.documenti`, reply all'ultimo msg di Ravi
15/07 15:26, inglese, firma Michela) pronta: descrive lo screenshot del check (GET
`mybusinessaccountmanagement/v1/accounts` → 403 HTML "Forbidden / your client does not have permission",
project 532339794075), ricorda che il 16/07 il 403 persiste con tutte le 8 API enabled e allowlist
confermata il 06/07, chiede di ri-verificare la propagazione dell'allowlist e quale email è nel Google
Group. **Manca solo: Andrea/Michela allega lo screenshot del check del 16/07 e invia.**
**Scambio 16/07 (inviato + risposte Ravi):** inviata risposta con screenshot — prima **in italiano**
(05:15), Ravi ha chiesto di tradurre l'errore in inglese (08:44), re-inviata **in inglese** con screenshot
(10:21). **Ravi ha ribattuto (16/07 19:07) con una risposta-fotocopia sull'OAuth:** «ogni richiesta deve
avere un token OAuth 2.0; service account o token generati da terzi non supportati; fai OAuth con l'account
che gestisce la scheda business + refresh token». **Non pertinente al nostro caso:** usiamo GIÀ OAuth utente
+ refresh token (no service account), e il 403 è **HTML front-end** (non 401 né JSON PERMISSION_DENIED) →
è gate di PROGETTO/allowlist, non di token. Ravi sta rispondendo col copione senza guardare l'evidenza.
**Fatto (16/07):** creata **nuova bozza** (reply al msg OAuth di Ravi, inglese) che: conferma OAuth utente +
refresh token (no service account, stesso token che fa girare Calendar/Gmail), spiega che un 403-HTML non è
un errore di token (sarebbe 401/JSON) ma un gate di progetto, e chiede di **escalare + ri-verificare la
propagazione dell'allowlist per 532339794075**. Pronta da rivedere e inviare (nessun allegato).
**VERIFICATO ✅ (17/07):** l'account `ardy.documenti@gmail.com` **gestisce la scheda Google "Ardy di Michela
Panella"** (4,7★, 23 recensioni, Via Joyce 4 Roma) — schermata "La tua attività su Google" + badge *"Il
profilo di questa attività è gestito da te"*, loggati come ardy.documenti. Quindi NESSUN disallineamento di
identità: lo stesso account possiede il progetto Cloud 532339794075 **e** gestisce la scheda. Il "muro
successivo" temuto non esiste → l'unica spiegazione residua del 403-HTML è l'allowlist non propagata lato
Google. (Frase aggiunta alla bozza di risposta a Ravi per blindare l'argomento.)
**INVIATO ✅ (17/07):** risposta a Ravi spedita (thread 4-4300000041395) — conferma OAuth utente + refresh
token, stesso account per progetto Cloud e scheda business, 8 API enabled, allowlist confermata il 06/07;
chiede di escalare la verifica dell'allowlist sul backend. **Palla a Google.**
**Pronta al cassetto (17/07):** email di **escalation L2** in `ardy-gbp-escalation-L2.md` — da inviare SOLO
se Ravi rimbalza di nuovo con un altro copione (chiede cose già fornite / non affronta la natura HTML del
403). Chiede escalation a engineering + verifica backend dell'allowlist.
**PIANO B — da fare SUBITO in parallelo (17/07):** post pubblico pronto in `ardy-gbp-planB-forum.md` per
attaccare il blocco da un canale dove rispondono i Googler del backend, scavalcando il supporto L1. Dove:
Google Issue Tracker (componente "Business Profile APIs") + Stack Overflow tag `google-my-business-api`.
Privacy: pubblicare project number 532339794075 sì, Gmail/ID caso NO (fornire in privato). Da postare Andrea.
**🎯 SVOLTA (20/07) — la diagnosi si ribalta: NON è il progetto, è l'account ardy.documenti.**
Ravi (18/07) ha aggiunto **`a.panseo@gmail.com`** al Google Group come secondo account di test. Test fatto
via **OAuth 2.0 Playground** (con le credenziali del client "Ardy lab social" 532339794075-kg0asdq…, redirect
playground aggiunto, consenso fresco solo scope `business.manage`, login come **a.panseo**):
- `GET mybusinessaccountmanagement/v1/accounts` → **HTTP 200 OK** con dati reali (account personale "Andrea
  Panella" + location group "Kok cucine e living").
- `GET mybusinessbusinessinformation/v1/accounts/109239154951108936988/locations` → **200**, tra le location
  c'è **"Ardy di Michela Panella"** (`locations/12362250276127196060`).
→ **Il progetto 532339794075 È allowlisted e FUNZIONA** (a.panseo lo prova). Il 403 di `ardy-gbp-check.php`
è **specifico dell'account ardy.documenti**, il cui provisioning/aggancio al gruppo è rotto (nonostante Ravi
lo dia per attivo). Coerente col fatto che per ardy.documenti lo scope business.manage non veniva mai
concesso, mentre per a.panseo (aggiunto oggi, consenso fresco) sì. **a.panseo = account COMPLETO** (API ok +
gestisce la scheda Ardy).
**Nota codice:** `ardy-gbp.php` → `gbp_get_access_token()` ritorna `gcal_get_access_token()` = token
condiviso di ardy.documenti (`ardy-gcal-token.json`). Non c'è flusso separato (`ardy-gbp-auth.php` non
esiste). Per usare a.panseo servirebbe un token OAuth separato (solo business.manage) + relativo auth/refresh.
**COLPO DI SCENA (20/07): ANCHE ardy.documenti dà 200 dal Playground!** Rifatto lo stesso test loggato come
`ardy.documenti` (consenso fresco, solo scope `business.manage`) → **HTTP 200 OK** (account personale "Ardy",
`accounts/102932459424802940851`). Quindi né il progetto né l'account erano il problema: **la causa vera è
il TOKEN del codice.** `ardy-gbp-check.php` riusava il token condiviso di Calendar/Gmail
(`ardy-gcal-token.json`), che **non ha mai avuto davvero `business.manage`**: quello scope, in bundle con
Calendar/Gmail su app non verificata, veniva scartato (in "autorizzazioni" non compariva "Google Business").
Richiesto **da solo** viene concesso → 200.

**✅ FIX IMPLEMENTATO (20/07) — token GBP dedicato (branch google-business-block):**
- **`ardy-gbp-auth.php`** (nuovo): flusso OAuth col SOLO scope `business.manage`, salva `ardy-gbp-token.json`.
- **`ardy-gbp.php`**: `gbp_get_access_token()` ora legge/rinnova `ardy-gbp-token.json` (non più il token gcal);
  client via `ARDY_GBP_CLIENT_*` con fallback `ARDY_GCAL_CLIENT_*`.
- **`ardy-gbp-check.php`**: usa il token dedicato; messaggi d'errore puntano a `ardy-gbp-auth.php`.
- **`.htaccess`**: `ardy-gbp-auth.php` sotto Basic Auth; `ardy-gbp-token.json` già coperto dal deny `.json`.

**DEPLOY (Andrea) — passi:**
1. In `ardy-config.php` aggiungere il client "Ardy lab social" (quello provato nel Playground, che ha lo
   scope + il redirect `ardy-gbp-auth.php` registrati):
   `define('ARDY_GBP_CLIENT_ID', '532339794075-kg0asdq…apps.googleusercontent.com');`
   `define('ARDY_GBP_CLIENT_SECRET', 'GOCSPX-…');` (il secret NUOVO generato nel Playground).
2. Deployare i file modificati/nuovi.
3. Aprire **una volta** `https://ardyagent.ardy-lab.it/ardy-gbp-auth.php` → consenso come **ardy.documenti**
   (scope business.manage) → "Token Business Profile salvato!".
4. Aprire `ardy-gbp-check.php` → deve dare **verde** ("QUOTA SBLOCCATA").
5. Ad account verde: riabilitare il toggle Google (vedi sotto) e pubblicare una fase di test.

**⏳ STATO DEBUG (20/07 ~16:00) — fix deployato ma check ancora 403. Da chiudere:**
Fatto finora: (a) `ARDY_GBP_CLIENT_ID/SECRET` messi in `ardy-config.php` = client "Ardy lab social"
(`532339794075-kg0asdq…`) + secret NUOVO `eIV0`; (b) aperto `ardy-gbp-auth.php` → nel consenso **è comparso
"Google Business"** (scope concesso) → pagina "Token Business Profile salvato!"; (c) **eppure
`ardy-gbp-check.php` dà ancora 403-HTML** ("does not have permission to get URL /v1/accounts").
Contesto Playground: **la stessa chiamata dà 200** sia con ardy.documenti sia con a.panseo (client kg0asdq +
eIV0, scope business.manage). Quindi token+account+progetto SONO buoni: il check non sta usando il token
nuovo, o il consenso è partito con l'account sbagliato.
**3 controlli da fare (in ordine di probabilità), sul SERVER:**
1. **`ardy-gbp.php` è la versione nuova?** Aprirlo: `gbp_get_access_token()` deve contenere
   `is_file(GBP_TOKEN_FILE)` / `ardy-gbp-token.json`. Se dice ancora `return gcal_get_access_token();` → NON
   deployato, ricaricarlo. **Se è nuovo ma resta 403 → è OPCACHE:** svuotare PHP OPcache / riavviare PHP-FPM
   (server con `opcache.validate_timestamps=0` servono bytecode vecchio).
2. **Account del consenso:** con quale account è stato aperto `ardy-gbp-auth.php`? Deve essere
   `ardy.documenti` (o a.panseo). Se il browser ne ha auto-scelto un altro → token di account non
   provisionato → 403. Ri-fare scegliendo esplicitamente ardy.documenti.
3. **Scope del token:** aprire `ardy-gbp-token.json` sul server, controllare che la riga `"scope"` contenga
   `business.manage` (e che ci sia `refresh_token`). Se manca lo scope → il consenso non l'ha concesso.
Ipotesi principale = **#1 (opcache / `ardy-gbp.php` non aggiornato)**: è la spiegazione che quadra con
"token salvato + Google Business concesso, ma check 403".
Nota: se il redirect Playground era stato aggiunto al client, si può rimuovere (pulizia). Il caso email
Ravi si può chiudere (il problema non era loro). a.panseo resta come account alternativo già funzionante.

**✅ AUTO-DIAGNOSI AGGIUNTA AL CHECK (20/07, branch `claude/gbp-403-error-o7mo0y`):** invece di indovinare
fra i 3 controlli manuali, `ardy-gbp-check.php` ora **smaschera da solo il token che sta davvero usando** e
dice quale dei 3 casi è. Aggiunto un blocco "Diagnostica del token in uso" che:
- legge dal file token: mtime, `refresh_token` presente, `scope` salvato;
- chiama `oauth2.googleapis.com/tokeninfo` sull'access_token effettivo → mostra gli **scope realmente
  concessi** e il **client (`aud`)** dietro il token;
- emette un verdetto automatico:
  - scope = `calendar`/`gmail` e NON `business.manage` → **🎯 token gcal ancora servito = OPcache**: il file
    è giusto, è `ardy-gbp.php` a essere vecchio → **riavviare PHP-FPM / svuotare OPcache** (chiude il caso #1
    senza aprire i file sul server);
  - manca `business.manage` → consenso partito male / account sbagliato → ri-auth da `ardy-gbp-auth.php`;
  - ha `business.manage` ma l'API dà comunque 403 → non è OPcache né token: ri-auth scegliendo **esplicitamente**
    lo stesso account del Playground (il browser può averne auto-scelto un altro).
- il footer "Progetto del client OAuth" ora deriva dal **client GBP effettivo** (`gbp_client_id()`), non più
  dal client gcal.
**⏭️ Prossimo passo per Andrea:** deployare `ardy-gbp-check.php` aggiornato → ricaricare
`https://ardyagent.ardy-lab.it/ardy-gbp-check.php` → leggere il box "Diagnostica del token in uso": dirà nero
su bianco se è OPcache (99% dei casi qui) o altro. Poi agire di conseguenza (riavvio FPM) e ri-testare.

**🔬 ESITO AUTO-DIAGNOSI (20/07 16:15) — token PERFETTO, eppure 403. Tutte e 3 le ipotesi ELIMINATE:**
il box ha risposto *"Token con `business.manage` ma l'API risponde 403"*. Dettaglio:
- Scope concessi (tokeninfo) = `…/auth/business.manage` ✅ · Scope salvato nel file = idem ✅
- refresh_token salvato ✅ · file token fresco (mtime 15:59) ✅
- Client dell'access_token (aud) = `532339794075-kg0asdq…` ✅ **= client GBP** (lo stesso che nel Playground dà 200)
→ **NON è OPcache, NON è token sbagliato, NON è scope/account mancante.** Il token è identico (client+scope+
account) a quello che nel Playground risponde **200**, ma la stessa `GET /v1/accounts` dal server dà **403-HTML**
GFE ("does not have permission to get URL /v1/accounts"). Due richieste equivalenti, esiti diversi ⇒ la
differenza è nel **transporto/header**, non nel token.

**🧪 SECONDO TENTATIVO AGGIUNTO AL CHECK (branch `claude/gbp-403-error-o7mo0y`):** ipotesi principale = manca
l'header **`X-Goog-User-Project`** (quota project), che il Playground allega da solo e che alcune API private di
Google pretendono con credenziali utente. Il check ora, quando la 1ª chiamata NON è 200, **rifà la stessa GET
aggiungendo `X-Goog-User-Project: 532339794075`** e confronta:
- se passa a **200** → 🎯 causa trovata: aggiungere quell'header a `gbp_api_get()` e al POST localPost in
  `ardy-gbp.php` (fix definitivo, poi il flusso è sbloccato);
- se resta **403** → non è il quota project: test decisivo = lanciare la stessa
  `curl -H 'Authorization: Bearer <token>' …/v1/accounts` (a) dalla shell del server e (b) da un'altra macchina
  col MEDESIMO access_token; se (a) fallisce e (b) va → è l'IP/rete del server (blocco a monte lato Google).
**⏭️ Prossimo passo:** ri-deployare `ardy-gbp-check.php`, ricaricarlo, leggere il box "Secondo tentativo".

**🔎 SECONDO TENTATIVO = ANCORA 403 (20/07 16:20) — escluso pure il quota project.** Con `X-Goog-User-Project:
532339794075` la `GET /v1/accounts` dà lo stesso 403-HTML. E `ardy-gcal.php` prova che l'auth via header
`Authorization: Bearer` **funziona da questo server** (Calendar/Gmail girano con lo stesso identico pattern
curl) → non è header strippato / richiesta anonima. Rimane un solo sospetto che regge TUTTE le prove:
**il consenso è partito dall'account SBAGLIATO.** tokeninfo mostrava `aud`=kg0asdq e scope `business.manage`
ma **NON l'email** (il token non aveva scope identità): e `aud` è lo stesso per qualunque utente, quindi un
token con quei valori esatti può appartenere a un account NON allowlisted (il browser può averlo auto-scelto
aprendo `ardy-gbp-auth.php`). Solo `ardy.documenti@gmail.com` e `a.panseo@gmail.com` sono nel Google Group →
un altro account passa lo scope ma **prende 403 al gate**. Nel Playground i 200 erano con login esplicito
degli account giusti.

**✅ FIX + DIAGNOSI SMASCHERA-ACCOUNT (20/07, branch `claude/gbp-403-error-o7mo0y`):**
- **`ardy-gbp-auth.php`**: scope ora `openid email …/business.manage` (i due scope base NON sensibili non
  re-innescano il drop del bundle) → il token porta l'EMAIL; e `prompt=select_account consent` **forza il
  selettore account** (niente più auto-scelta silenziosa dell'account sbagliato).
- **`ardy-gbp-check.php`**: mostra **"Account del token (email)"** e confronta con l'allow-list; se il token è
  di un account NON allowlisted → verdetto rosso *"🎯 ACCOUNT SBAGLIATO"* con l'email esatta e l'istruzione di
  ri-autorizzare scegliendo `ardy.documenti`. Se l'email è ignota (token vecchio senza scope email) → dice di
  ri-autorizzare per rivelarla.
**⏭️ Prossimo passo per Andrea:** ri-deployare i 2 file → aprire `ardy-gbp-auth.php`, nel **selettore** scegliere
**esplicitamente `ardy.documenti@gmail.com`** (consenso con “Google Business”) → aprire `ardy-gbp-check.php`:
la riga "Account del token" dirà se ora è l'account giusto e, se sì, l'API dovrebbe passare a **200 verde**.

**🧨 ESITO (20/07 16:27) — ACCOUNT GIUSTO, MA 403 LO STESSO. Ora è escluso TUTTO il lato token.** Ri-autorizzato
con `ardy.documenti`: il check mostra **Account del token = `ardy.documenti@gmail.com` ✅ allowlisted**, scope
`email business.manage openid` ✅, aud kg0asdq ✅, refresh ✅ — eppure `GET /v1/accounts` = **403-HTML** (e 403
pure con `X-Goog-User-Project`). Riepilogo ipotesi ELIMINATE: OPcache, token/scope sbagliati, account
sbagliato, quota project, header strippato (Calendar/Gmail girano con lo stesso `Bearer`). Lo **stesso account**
nel Playground dà 200. ⇒ l'unica variabile rimasta è la **richiesta in uscita dal server** (PHP-curl o IP/egress)
vs il Playground.

**🧪 DUE TEST AGGIUNTI AL CHECK (20/07, branch `claude/gbp-403-error-o7mo0y`) per isolare l'ultimo miglio:**
1. **Terzo tentativo automatico — `User-Agent`:** PHP-curl di default non manda UA e vari backend privati Google
   respingono a monte le richieste senza UA (browser/Playground ce l'hanno → per questo Calendar/Gmail, più
   permissive, girano). Il check rifà la GET con UA esplicito (+ quota project): **se 200 → fix di codice
   pulito** (`CURLOPT_USERAGENT` in `gbp_api_get()` e nella POST di `ardy-gbp.php`).
2. **Test da shell (copia-incolla, legge il token dal file):** se anche l'UA non basta, il box "allowlisted ma
   403" stampa un comando `curl` pronto da lanciare **via SSH sul server**. Esito: **200** ⇒ è la config
   PHP-curl (proxy `http_proxy`/`https_proxy` nell'env FPM, o `curl.*` in `php.ini`); **403** ⇒ è l'IP/egress
   del server, respinto a monte da Google → confrontare da un'altra macchina e, se confermato, aprire il canale
   con Google fornendo il **public IP del server** (non più token/account).
**⏭️ Prossimo passo per Andrea:** ri-deployare `ardy-gbp-check.php`, ricaricarlo e leggere **"Terzo tentativo —
con User-Agent"**. Se verde → dimmelo, aggiungo l'UA a `ardy-gbp.php` e chiudiamo. Se no → lancia il comando da
shell del box e riportami se stampa 200 o 403.

**🧱 ESITO (20/07 16:33) — 403 ANCHE con User-Agent.** Non è l'UA. Osservazione chiave: il 403 porta gli header
di Google (`alt-svc: h3`, logo google.com) ⇒ **la richiesta ARRIVA a Google**, è il suo front-end a respingerla;
e dallo STESSO server, con lo STESSO token, `tokeninfo` (oauth2.googleapis.com) passa mentre l'API Business dà
403 ⇒ è specifico dell'**endpoint Business + questo server** (host = cPanel `micoperibg`, path
`/home/micoperibg/public_html/ardyagent.ardy-lab.it/`). Restano 2 possibilità: (a) **config PHP-curl** del server
(proxy nell'env FPM / instradamento) oppure (b) **IP/egress** del server rifiutato a monte dall'API Business.

**🔬 DIAGNOSTICA "Ambiente di rete PHP" AGGIUNTA (20/07, branch `claude/gbp-403-error-o7mo0y`):** il check ora
mostra a costo zero: **variabili proxy** nell'env PHP (`http_proxy`/`https_proxy`/…), **IP di egress**
(`CURLINFO_LOCAL_IP` = ciò che Google vede come sorgente), IP Google contattato, versione **HTTP** negoziata e
versione libcurl. Se compare un proxy nell'env FPM → candidato n.1 del 403 (e il `curl` da shell, che NON eredita
quell'env, darebbe 200).
**⏭️ Prossimo passo per Andrea (2 dati da riportare):** ri-deployare `ardy-gbp-check.php`, ricaricarlo, e dirmi:
(1) il box **"Ambiente di rete PHP"** segnala un **proxy**? qual è l'**IP di egress**? (2) il comando `curl` da
shell (box in alto) stampa **200 o 403**? Con questi due dati chiudo: proxy/PHP-curl → fix in `ardy-gbp.php`;
IP puro → si apre il canale con Google sull'IP del server (o si sposta la chiamata su un egress che funziona).

**🎯 CAUSA TROVATA (20/07 16:37) — EGRESS IPv6.** Il box "Ambiente di rete PHP" ha rivelato: **nessun proxy**,
ma **IP di egress = `2001:41d0:2005:100::79b`** → **IPv6** (range `2001:41d0` = OVH), IP Google contattato pure
IPv6 (`2001:4860…`), HTTP/1.1, libcurl 7.76.1. Il server esce verso l'API Business su **IPv6** e l'API **privata
Business Profile rifiuta l'egress IPv6** (mentre `oauth2/tokeninfo`, Calendar/Gmail — endpoint pubblici — lo
accettano, e il Playground gira su IPv4 di Google → 200). Ecco perché: stesso token valido, stesso account
allowlisted, ma 403 solo dal server e solo su quell'API.

**✅ FIX APPLICATO (20/07, branch `claude/gbp-403-error-o7mo0y`):** `CURL_IPRESOLVE_V4` (forza IPv4) su tutte le
chiamate GBP:
- **`ardy-gbp.php`**: aggiunto `curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4)` in `gbp_api_get()` (GET
  account/location) **e** nella POST dei localPost. Guardato da `defined('CURL_IPRESOLVE_V4')` per sicurezza.
- **`ardy-gbp-check.php`**: aggiunto **quarto tentativo** che rifà la GET forzando IPv4 → se 200, conferma la
  causa e dice che il fix è già in `ardy-gbp.php`.
Le chiamate di refresh token (oauth2.googleapis.com) NON toccate: endpoint pubblico, gira su IPv6 senza problemi.
**⏭️ Prossimo passo per Andrea:** ri-deployare `ardy-gbp.php` + `ardy-gbp-check.php` → ricaricare il check → il
box **"Quarto tentativo — forza IPv4"** deve essere **verde (200)**. A quel punto: riabilitare il toggle Google
(vedi sotto, rimettere `true` nel `tog('google', …)`) e pubblicare una fase di test per verificare che il post
compaia davvero sulla scheda.

**Lato codice — ✅ toggle RIABILITATO (20/07, a check verde):** il toggle Google nel pannello social
(`ardy-michela-app.html`, `socialDestHtml`) è ora **attivo** (`tog('google',…,false)`) dopo il 200 su IPv4.
Resta **deselezionato di default** (`selArr = facebook/instagram`): il bottone è cliccabile ma non pubblica su
Google finché Michela non lo sceglie. `inviaSocial()` è già cablato per spedire in parallelo a
`ardy-pubblica-social.php` (Facebook/Instagram, via n8n) e `ardy-gbp-post.php` (Google, diretto — nessuna
modifica al nodo n8n). Fix di sicurezza già applicato: `ardy-gbp-post.php`/`ardy-gbp-check.php` protetti da
Basic Auth in `.htaccess`, `ardy-gbp.php` (lib) nel deny.
**✅ CHIUSA — Google pubblica davvero, da entrambe le dash (26/07/2026).** Post verificati sulla scheda
"Ardy di Michela Panella", non solo `success:true`. Due cose imparate a caro prezzo, da non riscoprire:

1. **Il WEBP è ciò che faceva fallire Google**, con un 500 «Internal error encountered.» che non spiega nulla.
   Google Business accetta solo **JPG e PNG** (min 250×250, < 5 MB). Le foto della dash clienti sono JPG da
   telefono e passavano; il render di un progetto era WEBP e non passava. Risolto convertendo **prima** del
   caricamento su WordPress (`ardy-img.php` → `ardyImgNormalizza()`), perché è il file di WP quello che poi
   mandiamo ai social. Vale anche per **Instagram**, che vuole JPEG. `aggiorna_immagini` ricarica convertiti
   anche gli allegati vecchi in formato sbagliato, quindi gli articoli già pubblicati si riparano da soli.
2. ⚠️ **Falsa pista, non riaprirla:** la scheda espone `metadata.canOperateLocalPost = false`
   (`accounts/102932459424802940851`, `type: PERSONAL`, `verificationState: UNVERIFIED`) e sembra *la* causa.
   **Non lo è**: con quel flag a false i post escono lo stesso. `ardy-gbp-check.php` ora lo dice a chiare
   lettere invece di mandare a verificare una scheda che non ne ha bisogno.

**Diagnostica, se un giorno Google torna a dare 500:** `ardy-gbp-check.php` → sezione «Post locali». Elenca le
immagini degli ultimi articoli (un click e dice formato/dimensioni/esito) e, in fondo, pubblica due post di
prova — uno di solo testo e uno con quell'immagine: se passa senza e fallisce con, è la foto. I post di prova
compaiono davvero sulla scheda e vanno cancellati a mano. La pagina esce su **IPv4** come la libreria, quindi
misura quello che fa la dash e non il vecchio 403 dell'egress IPv6.

**✅ Elenco "Fasi pubblicate" ora è a fisarmonica (20/07):** ogni fase pubblicata è **cliccabile e si espande**
(`caricaFasiPubblicate` in `ardy-michela-app.html`) mostrando: il **testo** pubblicato, le **foto**, e il box
**"📲 Pubblica sui social"** con le 3 iconcine **FB/IG/Google** + **Anteprima** + **Pubblica**. Google parte
**deselezionato** e la selezione si legge dal DOM (niente fallback FB/IG) per non ripubblicare per sbaglio.
Riusa il motore esistente (`socialDestHtml`, `inviaSocial`, `renderIgPreview`). Utile per spingere su **Google**
le fasi già pubblicate prima dello sblocco. (Le comunicazioni straordinarie restano senza social.)

**✅ Contenuti social ≠ sito cliente (20/07):** il box per-fase precompilava col `testo_generato` (il testo del
**sito cliente**, rivolto al cliente: "la *sua* cucina…") e finiva su FB/IG **e** Google. Ora il box usa la
**caption social** (`testo_social`, tono brand/pubblico), separata e mostrata distinta dal "Testo del sito
cliente" (etichettato). Dettagli:
- **`inviaSocial`**: su **Google** la caption va **senza hashtag** (helper `stripHashtags`); FB/IG li tengono.
- **`fasi.testo_social`**: nuova colonna (migration) salvata alla pubblicazione (`ardy-pubblica-lavorazione.php`)
  → le fasi nuove hanno già la caption.
- **`ardy-genera-social.php`** (nuovo endpoint, Basic Auth): per le fasi **vecchie** senza caption, la genera al
  volo dalle note (`generaCaptionSocial`, stesso prompt di `generaTestoSocial` + "post pubblico, non rivolto al
  cliente") e la mette in **cache** su `fasi.testo_social`. Chiamato all'apertura della fase; bottone **✨ Rigenera**
  per riscriverla. Aggiunto al `<FilesMatch>` del `.htaccess`.
⚠️ Serve far girare **`ardy-migrate.php`** in produzione (crea la colonna `testo_social`).

---

## 📋 TASK DA SVILUPPARE (aperti)

### ☁️ Media su Backblaze B2 — off-load disco + semilavorato migrazione (PIANIFICATO 24/06)
**Obiettivo doppio:** togliere i media dal disco del server attuale (oggi si "intasa") **e**, nello stesso
gesto, pre-staggiare i media su B2 così che alla migrazione sul nuovo VPS (vedi `PIANO-MIGRAZIONE.md`
Fase 3/5) **non** si debba ri-trasferire tutto al cutover — la nuova app punta allo stesso bucket. È la
Fase 5 del piano ("upload diretti su B2") anticipata sull'infra attuale, come già fatto per il backup off-site.

**Decisioni prese (24/06):**
- **Scope v1 = foto private (scheda + chat) + video/reel** (i veri mangia-disco). Restano **fuori**: le foto
  di fase **già pubblicate su WP Media Library** (pubbliche, gestite da WP) e — per ora — la cache PDF
  preventivi (rigenerabile; eventuale fase 2).
- **Semilavorato = sync continuo via cron** (backfill iniziale + cron che tiene B2 allineato al disco):
  a fine migrazione è sempre aggiornato e funge anche da **backup media off-site continuo**.

**Architettura (privacy invariata):**
- Bucket **`ardy-media` PRIVATO** + app key **ristretta a quel bucket** (creazione manuale in console B2 —
  prerequisito infra, non da codice). Riusa l'account B2 esistente.
- **B2 via API nativa** in un piccolo `ardy-b2.php` (curl: `b2_authorize_account` → token in cache ~24h →
  `b2_upload_file`/`b2_download_file_by_name`/`b2_delete`). Niente SDK pesante (composer ha solo mpdf),
  coerente con lo stile del repo. (Alternativa S3-compatibile scartata: servirebbe SigV4/aws-sdk.)
- **Le immagini restano private**: gli script che oggi le servono (`ardy-lead-foto.php`, immagini chat in
  `ardy-proxy.php`, ecc.) diventano **proxy** che leggono da B2 e streammano dietro Basic Auth → **zero URL
  pubblici, zero modifiche al frontend**.
- **Layer di astrazione `ardy-storage.php`** (`put/get/delete/exists`) così i chiamanti non sanno se è disco
  o B2 → migrazione incrementale e reversibile.

**Fasi (a basso rischio, reversibili):**
1. Console B2: creare bucket `ardy-media` privato + app key ristretta → costanti in `ardy-config.php`
   (`ARDY_B2_KEY_ID`, `ARDY_B2_APP_KEY`, `ARDY_B2_BUCKET`, `ARDY_B2_BUCKET_ID`, flag `ARDY_B2_ENABLED` per
   rollout graduale). Aggiungere `ardy-b2.php`/`ardy-storage.php` al deny `.htaccess` (interne, non API).
2. `ardy-b2.php` (auth+cache token) + `ardy-storage.php` (astrazione disco/B2).
3. **Backfill** one-shot: sincronizza i media esistenti (`ARDY_UPLOAD_DIR/<session>/`, `lavorazioni/`, reel)
   su B2 con manifest. = il "semilavorato".
4. **Write path**: i nuovi upload (foto scheda/chat, video, reel) scrivono su B2; opzionale dual-write su
   disco per i primi giorni di sicurezza.
5. **Read path**: i serve-script provano B2 → **fallback a disco** (niente si rompe in transizione).
6. **Cron sync** continuo (allinea disco→B2, riconcilia eventuali delta) + **flip** a B2-only e reclaim del
   disco quando stabile.

**Note/attenzioni:** i reel sono intermedi (poi vanno su social/WP) — valutare se off-loadarli o cancellarli
post-pubblicazione; coordinare con `ardy-archivia-persi.php` (oggi sposta foto/reel dei PERSI in
`_da_liberare/`) e con `ardy-elimina-cliente.php` (cancella `ARDY_UPLOAD_DIR/<session>`) perché dovranno
agire anche su B2. ⚠️ Sul nuovo VPS no-panel il backup B2 va comunque rifatto in chiave no-cPanel (dump DB
cron + sync media) — questo task copre proprio il lato media.

### 🚚 Trasporti — aggiungere il WhatsApp ai 2 messaggi (oggi solo email)
Il flusso consegne/ritiri è LIVE solo via email: "è pronto" automatico al passaggio a COMPLETATO + messaggio
con la data dalla "giornata Trasporti". Manca il **WhatsApp**: servono **2 template Meta approvati**
(es. `WA_TEMPLATE_PRONTO` 1 var, `WA_TEMPLATE_TRASPORTO` 2 var: mobile + data). Poi agganciare l'invio WA in
`ardy_invia_pronto()` e `ardy_invia_avviso_trasporto()` (punti predisposti) + costanti in `ardy-config.php`. (media)

### 📲 Template WhatsApp avanzamento fase — aggiungere codice/social (lato Meta)
Il WA di avanzamento usa il template Meta `WA_TEMPLATE_FASI` a 4 var (nome · mobile · fase · link): codice
personale/social/spiegazione non si possono aggiungere da PHP. Vanno aggiunti **modificando il template su Meta**
e rifacendolo approvare, poi allineare i parametri in `inviaWhatsAppCliente()` (`ardy-pubblica-lavorazione.php`).
Quei dati ci sono già nell'email e nella pagina lavorazione. (bassa)

### 🎨 Adottare temi/layout da "Claude Design" — ANALISI PRONTA (da decidere)
Analisi completa in **`ANALISI-CLAUDE-DESIGN.md`**. Blocco vero: **363 `style="…"` inline** nel dashboard
scavalcano i temi. Procedura: Fase 0 (inline→classi + ampliare i token, su branch) → poi un solo `theme.css`
drop-in da Claude, provato su staging. Fattibilità: dashboard **alta**, webchat **media**, WordPress/Divi **bassa**.

### 👥 Accesso "dipendente" con permessi limitati (ruoli)
Quando Ardy avrà un dipendente. Deciso: lo `staff` può SOLO preventivi + schede cliente (+ fasi collegate); il
resto admin-only. `ardyAuthUser()` già restituisce lo username → manca lo strato ruoli. 3 mosse:
1. Mappa utente→ruolo in `ardy-config.php` (es. `define('ARDY_RUOLI', ['michela'=>'admin','andrea'=>'admin','dipendente'=>'staff']);`).
2. **Muro backend**: `ardyRole()` + `ardyRequireRole('admin')` in cima a OGNI endpoint admin-only (CRM, stats,
   solleciti, grazie-consegna, outreach, email-finder, elimina-cliente, import-*, dossier, gcal). Il confine vero
   è il check PHP su ogni endpoint, non l'`.htaccess`. Se ne manca uno, è un buco.
3. Cosmesi frontend: endpoint `/me` col ruolo → la dashboard nasconde i bottoni admin per lo `staff`.
Nuovo utente: `htpasswd -B <path> dipendente` (mai `-c`).

### Migliorie minori UX / dashboard CRM (bassa priorità)
- **Filtro sidebar default su ACCONTO/IN_LAVORAZIONE** invece di TUTTI (da decidere sull'uso reale).
- **Briefing del mattino** (opzionale): salvare data ultimo briefing per numero così il riepilogo lungo parte
  da solo al primo "buongiorno" (oggi parte quando Michela chiede "come va oggi?").
- **Widget WordPress** — ✅ FATTO nel backup repo (`wordpress-snippets/pulsante-flottante-ovunque.php`:
  testo del pulsante ora "Chatta con **Sole**", aria-label già "Sole"). ⚠️ Lo snippet repo è SOLO un backup:
  per renderlo live va re-incollato nel WPCode id 15243 ("Pulsante flottante ovunque") da WordPress.
- **Nota settimanale "cose da fare" in dashboard** — ✅ FATTO (DEPLOYATO 24/06, test live pendente). Pannello
  nella home (empty state, quando nessun cliente è selezionato): mostra la nota più recente a colpo
  d'occhio + "✏️ Modifica" → editor modale → salva. Stessa fonte di Sole su WhatsApp (tabella
  `note_staff`, si legge l'ultima per id, ogni salvataggio è una riga nuova con `settimana` ISO), quindi
  resta allineata col briefing del mattino. Nuovo endpoint `ardy-nota-settimanale-api.php` (GET = ultima,
  POST `{testo}` = salva), aggiunto al `<FilesMatch>` del `.htaccess`. Niente migrazione DB (tabella già
  esistente). ⚠️ Da verificare dal vivo: aprire la dashboard senza selezionare un cliente → la nota appare;
  modificarla e salvare → ricompare aggiornata e Sole su WhatsApp legge la stessa versione.
  **Accesso da ovunque (25/06):** aggiunto pulsante **"🗒️ DA FARE"** nella barra laterale (accanto a GUIDA/⚙︎)
  che apre l'editor della nota anche con una scheda cliente aperta (prima viveva solo nella home/empty state e
  per rivederla serviva ricaricare la pagina). Ricarica la versione più recente prima di aprire (riprende anche
  le modifiche fatte da Sole/Andrea su WhatsApp). Aggiunto anche un cache-buster `?v=` al link del CSS.
  ⚠️ Per Andrea la nota condivisa funziona solo se il suo numero è in `WA_ANDREA_NUMBER` (`ardy-config.php`).
- **Estrarre JS inline (~3.400 righe) dalla dashboard** in `ardy-michela-app.js` (CSS già esterno): win di caching, refactor delicato.

### ⚡ Reel async (`ardy-crea-reel.php`) — priorità media
Oggi monta il video in **sincrono** nella richiesta HTTP (worker FPM fino a 10 min). Non urgente (solo Michela,
no concorrenza); diventa prioritario con più utenti o se compaiono 504. Refactor: job in background
(`proc_open` detached) + polling. Quick win: (1) eliminare i 2 download ridondanti (righe ~206-217),
(2) download paralleli `curl_multi`, (3) caption Claude fuori dal path critico.

---

## ❄️ CONGELATI / PARCHEGGIATI (non ora)
- **Catalogo prezzi su Google Sheet / vendita**: ~~niente WooCommerce → la vendita andrà su un **agente dedicato a parte**, non Sole.~~ **RIBALTATO (09/07/2026):** WooCommerce **sì** (`object.ardy-lab.it`) e la chat prodotto **è Sole**. Fatto — vedi la sezione in cima e `PIANO-ECOMMERCE-OBJECT-WOO.md`.
  - ⮑ **Scongelato/evoluto in `PIANO-DASH-DESIGN.md`** (25/06): dash separata per i progetti interni di design
    (prototipi/lampade/mobili/restyling) → fasi → contenuti → vendita. Deciso: stesso codebase + dati separati
    (`progetti` + `fasi.progetto_id`), la dash NON vende (master contenuti), Woo master commercio via push a
    senso unico, un canale per volta. Tappa 1 (progetti + dash + fasi) è autoconsistente.
  - ⮑ **Filiera mappata (25/06)**: stampa 3D = prototipo+produzione (no on-demand); prodotto replicabile
    a serie, stock fuori dalla dash (Woo/Etsy); ciclo di vita finisce a A CATALOGO (no VENDUTO); prototipo
    tracciato v1/v2/v3 con iterazioni promuovibili a contenuto; "file congelato" = transizione manuale con
    snapshot STL+profilo+scheda; render/CAD/schede a livello progetto. Tutto in `PIANO-DASH-DESIGN.md` §3.
  - ⮑ **Materiali/costi (25/06)**: `materiali` testo pubblico + BOM `progetto_materiali` interna (filamento/
    stampa/elettrico/ferramenta/finitura/imballo/manodopera); costi filamento/ore digitati da OrcaSlicer (no
    integrazione Moonraker, stampante in LAN); manodopera €50/h default in config; scarto stampa 10% default;
    margine = prezzo − costo. Campi DB approvati. In coda (dopo Tappa 1): serve Woo al lancio?, CE/sicurezza
    lampade, tariffa oraria macchina. Vedi `PIANO-DASH-DESIGN.md` §2.5.
  - ⮑ **Tappa 1 AVVIATA (25/06, su branch)**: fondazione pronta — DDL (`progetti`, `progetto_materiali`,
    `progetto_iterazioni`, `fasi.progetto_id`) in `ardy-migrate.php`; `ardy-progetti-api.php` (CRUD + BOM/
    costi/margine + iterazioni + stato + congela-file); `ardy-design-app.html` (dash gemella theming-ready,
    pipeline stato, costi live, iterazioni). Endpoint nel `.htaccess`. ⏭️ **Resta**: wiring reel/social/WP
    sulle fasi di progetto (estendere `ardy-crea-reel.php`/`ardy-pubblica-social.php` ad accettare
    `progetto_id`, la colonna c'è già). ⚠️ Da testare dal vivo dopo il deploy (migrate crea le tabelle).
  - ⮑ **Fasi-contenuto progetto (25/06)**: `ardy-progetti-fasi-api.php` (CRUD fasi via progetto_id +
    upload/serve foto, riusa gli helper immagine) + editor completo nella dash. Foto su disco
    (`ARDY_UPLOAD_DIR/progetti/<id>/fasi/<faseid>/`); il DB salva solo i nomi → **seam pronto per la
    migrazione su Backblaze B2** (cambiano solo path scrittura + serving).
  - ⮑ **Reel da progetto (25/06)**: `ardy-crea-reel.php` ha un ramo `progetto_id` che legge le foto da
    disco (decisione A; foto progetto dietro Basic Auth, non scaricabili via HTTP). Ramo cliente invariato
    (helper `reelLeggiFoto`). Pulsante "Crea reel" nella dash design. ⏭️ Resta: clone publish WP/social
    sulle fasi di progetto (estendere `ardy-pubblica-lavorazione`/`ardy-pubblica-social` con `progetto_id`,
    SENZA guscio cliente — è contenuto di brand). Invio a catalogo a fine ciclo (Tappa 2/3).
- **Conoscenza Sole — FASE 2**: datazione fotografica guidata + community + eventuale mini-RAG se la knowledge base cresce troppo.
- **BIMI (logo avatar mittente Gmail)**: serve DMARC enforcement + VMC (certificato ~1.000+€/anno + marchio registrato). Decisione commerciale.
- **Codice d'accesso su WhatsApp**: su WA il numero = identità; il codice serve solo per numeri non registrati (raro). Servirebbe marker `[[CERCA:ARD-XXXX]]`.

---

## 📄 FUORI REPO / OPERATIVO
- **Import preventivi storici**: strumento pronto (`ardy-import-preventivi.php`, CSV + PDF). Michela mette i PDF in Drive → CSV precompilato → import.
- **Documenti legali**: `termini-privacy-wordpress.md` + `GUIDA-UTENTE.md` in revisione legale. Aggiornare le date alla pubblicazione effettiva.
- **Riorganizzare i `.php` in sottocartelle**: deciso NO (path file = URL pubblico; spostarli romperebbe n8n/webhook/require/deploy). Root piatta di proposito.

---

## 💶 Nota costi (riferimento)
- **Costo dominante = API Claude per messaggio**, mitigato col **prompt caching** (web ✅, lavorazione ✅, WhatsApp ✅).
- **Outreach AI**: arricchimento ~$0,05-0,10 a contatto (Google ~$0,03 + Claude web search); generatore template ~1 chiamata. Tetto Google giornaliero come rete di sicurezza.
- **Meta**: Michela↔Sole user-initiated = gratis; costi solo su template business→cliente fuori 24h (~3-4 cent/msg). Media Meta scadono → scaricarli subito col media ID.
