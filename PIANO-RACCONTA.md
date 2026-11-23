# Piano — "Racconta": la storia di un pezzo, da foto e voce

> Stato: **Fase 1 scritta** (23/11/2026, ramo `racconta-fase1`), da collaudare dal vivo: vedi
> `TODO-PROSSIMI-TASK.md`. Fase 2 (social) scritta il 23/11 (ramo `racconta-social`), da collaudare.
>
> Problema: la dash principale si usa poco per raccontare i lavori. Ogni pubblicazione passa dalla
> scheda cliente (sezione Lavorazione → fisarmonica "Crea e pubblica nuova fase") e un pezzo senza
> cliente registrato non ha dove stare. L'audit di luglio (`AUDIT-USABILITA-DASH.md`) dice il resto:
> 201 bottoni, testo minuscolo, fisarmoniche annidate. Il gesto "ho in mano un mobile, lo fotografo e
> lo racconto" è sepolto.

## Decisioni già prese (con Michela/Andrea, 23/11)

| Domanda | Risposta |
|---|---|
| Unità del racconto | **Un pezzo che cresce nel tempo**: arrivo, capitoli di lavorazione, finito |
| Rapporto col cliente | **Binari separati**. Il pezzo non è collegato a `clienti`, nemmeno internamente |
| Pagine "lavori in corso" dei clienti | **Convivono**. Un'area privata per il cliente (anche fuori da WP) è un tema futuro, non di questo piano |
| Chi la usa | Andrea (**Android**) e Michela (**iPhone**) |
| Sole sulla pagina del pezzo | Risponde a chiunque e **raccoglie il contatto esattamente come oggi** |
| Dove sul sito | Categoria esistente **`lavori-in-corso`** |
| Pubblicazione | **Sempre manuale**: per ogni canale si decide se, cosa e quando |

## Cosa si vede: una mini-app, non un'altra sezione della dash

`ardy-racconta.html`: una pagina sola, pensata per il telefono, dietro lo stesso Basic Auth della
dash. Raggiungibile dal bottone **📸 RACCONTA** nell'header della dash (accanto a 🎨 DESIGN e
📣 OUTREACH) e salvabile sulla home del telefono.

**Schermata 1: i pezzi.** Card grandi con foto e titolo, in cima **+ Nuovo pezzo**. Si vede a che
punto è ogni pezzo e cosa c'è da pubblicare.

**Schermata 2: il pezzo.**
1. **Aggiungi un capitolo**: 📷 foto (fotocamera o galleria, più di una) + 🎤 nota a voce o scritta
   ("credenza umbertina in noce, tarli sul fianco, oggi consolidamento con resina…").
2. Sole scrive il **capitolo** e aggiorna la **scheda del pezzo**: titolo, tipologia, epoca e stile
   stimati, legno/materiali, problemi trovati, tecniche e prodotti usati. Se le manca qualcosa,
   propone una o due domande ("che finitura avete scelto?"), a cui si può rispondere a voce.
3. Tutto è **bozza** finché non si sceglie di pubblicare. Si corregge a mano o a voce ("togli la
   parte sul prezzo").
4. **Pubblica**: un riquadro per canale, ognuno con anteprima modificabile e bottone proprio:

| Canale | Cosa esce | Quando ha senso |
|---|---|---|
| 🌐 Sito | Il capitolo si aggiunge alla pagina del pezzo (prima volta: crea la pagina) | A ogni capitolo |
| 📍 Google Business | Post breve + 1 foto + "Scopri di più" → pagina del pezzo | A ogni capitolo o ai momenti forti |
| 📘 Facebook | Post con foto + link alla pagina | Idem |
| 🎬 Reel Instagram | MP4 9:16 da tutte le foto dei capitoli + caption | Con almeno 4–5 foto, di solito a pezzo finito |

Ogni riquadro: *Pubblica ora / Salva per dopo / Non pubblicare*, e mostra cosa è già uscito e
quando. Social e GBP lavorano solo su contenuti già pubblicati sul sito (scaricano le foto da URL
pubblici), quindi il sito va pubblicato per primo: la UI lo rende evidente invece di bloccare.

### La voce, su Android e su iPhone
- **Bottone microfono** con la dettatura del browser (Web Speech API, italiano, gratis). Su Chrome
  Android funziona bene. Su Safari iPhone funziona da iOS 14.5, ma è meno stabile e **non** funziona
  se l'app è aperta dall'icona sulla home.
- **Ripiego sempre disponibile**: il campo di testo è grande e il microfono della tastiera (Gboard
  / tastiera iOS) detta dentro senza codice. Su iPhone sarà probabilmente la via principale, e va
  bene così.
- La trascrizione lato server (registrare l'audio con il browser e mandarlo a un servizio di
  speech-to-text, es. Whisper) costa pochi centesimi al mese e funziona uguale su iPhone e Android,
  anche dall'icona sulla home. Aggiunge però un fornitore e una chiave: si decide dopo la prova dal
  vivo. Il codice del microfono in `ardy-racconta.html` è isolato in `attivaMic()` per poterla
  inserire senza rifare la pagina.
- **App nativa: scartata** (23/11). Il motore di dettatura sarebbe lo stesso della tastiera iOS; in
  cambio servirebbero account Apple Developer (99 $/anno), build TestFlight che scadono ogni 90 giorni
  e due progetti in più da mantenere, per due persone.

## Come è fatto sotto

### Dati (`ardy-migrate.php`)
- **Tabella nuova `pezzi`**: `id`, `slug`, `titolo`, `scheda` (JSON: tipologia, epoca, stile,
  materiali, problemi, tecniche, prodotti, dimensioni), `storia` (testo lungo per la pagina),
  `faq_pubbliche` (JSON), `stato` (`in_corso | finito | archiviato`), `copertina_url`,
  `wp_post_id`, `wp_post_link`, `reel_url`, `created_by`, `created_at`, `updated_at`.
  *(Fatto, con differenze: FAQ, copertina e reel_url rimandati — la copertina è la prima foto, Sole
  risponde da scheda e capitoli. In più `pubblico_json`, l'istantanea di ciò che è sul sito: è
  l'unica cosa che legge Sole, così bozze e note dettate non escono mai. Soft delete con `deleted_at`.)*
- **`fasi.pezzo_id` nullable**: una fase è di un cliente, di un progetto design **o** di un pezzo.
  È la stessa scelta fatta il 25/06 con `progetto_id`, così reel/social/WP non si duplicano.
- **Pubblicazioni per canale**: colonne per fase (`wp_pubblicata_at`, `gbp_pubblicato_at`,
  `fb_pubblicato_at`) o una piccola tabella `pubblicazioni` (`fase_id|pezzo_id, canale, stato,
  testo, pubblicato_at, rif_esterno`). **Consiglio la tabella**: un canale nuovo non chiede DDL e
  "salva per dopo" ha un posto naturale. *(Rimandata alla Fase 2, dove serve davvero: per il solo
  sito bastano `fasi.stato` e `wp_pubblicata_at`.)*

**Perché non riusare `progetti`**, che ha già articolo WP, fasi, reel, social e una Sole pubblica?
Perché è un modello da prodotto in vendita (Woo, distinta costi, varianti, ciclo
IDEA→CATALOGATO). Metterci dentro i restauri vorrebbe dire filtri ovunque nella dash Design e
il rischio che un restauro finisca nel push verso Woo. Si **copia lo schema**, non la tabella.

### Endpoint nuovi (tutti in `FilesMatch` Basic Auth + `ardyRequireAuth()`)
- `ardy-pezzi-api.php`: lista, crea, salva scheda, capitoli (crea/modifica/elimina bozza), upload
  foto (stesso trattamento di `ardy-fasi-bozza-api.php`: JPEG, niente WEBP, che rompe GBP e IG).
- `ardy-pezzi-ai.php`: da foto + nota → capitolo + aggiornamento scheda + domande. Prompt con
  **regola di anonimato**: nomi di persone, indirizzi, telefoni, prezzi e riferimenti al cliente
  vengono tolti anche se dettati ("la credenza della signora Rossi…" → "una credenza"). In più un
  controllo deterministico prima di pubblicare (regex su telefoni/email/€) che avvisa.
- `ardy-pubblica-pezzo.php`: crea la pagina WP madre o aggiunge il capitolo, idempotente con i
  marcatori `<!-- ardy-fase-ID -->` come `ardy-pubblica-fase-progetto.php`. Categoria
  `lavori-in-corso` + **tag `storia-pezzo`** (serve al widget, vedi sotto).

### Endpoint riusati (con un ramo `pezzo_id`, come per `progetto_id`)
- `ardy-crea-reel.php`: foto dalle fasi del pezzo.
- `ardy-pubblica-social.php`, `ardy-gbp-post.php`, `ardy-pubblica-reel.php`: già generici
  (testo + immagini + link). Probabilmente nessuna modifica oltre al log.
- `ardy-social-foto.php`: conversione JPEG per Instagram.

### Sole sulla pagina del pezzo
Oggi sulle pagine `lavori-in-corso` il widget chiede "sei il cliente o hai solo una domanda?". Il
visitatore finisce su `ardy-proxy.php`, la Sole del sito, che raccoglie il contatto con
`salva_lead_crm`. È esattamente il comportamento richiesto, quindi **non serve una Sole nuova**:
- **Widget** (`ardy-widget-lavorazione.js`): se il post ha il tag `storia-pezzo` salta la scelta
  cliente/visitatore e apre direttamente la chat con Sole, mandando anche `wpPostId`. Lo snippet
  nelle Integrazioni di Divi **non cambia**: il widget legge i tag del post da solo.
- **`ardy-proxy.php`**: se arriva `wpPostId`, carica **lato server** la proiezione pubblica del
  pezzo (scheda, capitoli pubblicati, FAQ: solo campi in whitelist, come `ardy-object-lib.php`) e
  la aggiunge al blocco dinamico del prompt. Il client non può iniettare contesto, al massimo
  indicare quale pezzo. Lead capture, rate limit e prompt caching restano quelli di oggi.
- Il box informativo dello snippet ("se sei il cliente di questo lavoro…") su una pagina-pezzo
  stona: va cambiato a mano nelle Integrazioni di Divi (non WPCode) con una condizione sul tag. È l'unico intervento fuori repo.

## Fasi di lavoro

**Fase 1: racconto + sito + Sole** (la parte che dà valore subito)
Migrazione, `ardy-pezzi-api.php`, `ardy-pezzi-ai.php`, `ardy-pubblica-pezzo.php`,
`ardy-racconta.html` (lista, pezzo, capitolo con foto e dettatura, pubblica sul sito), widget +
`ardy-proxy.php`, `.htaccess`, `deploy.sh`, link nell'header della dash, README.

**Fase 2: canali social**
Riquadri GBP / Facebook / Reel con anteprime, *salva per dopo*, storico pubblicazioni, ramo
`pezzo_id` nel reel.

**Fase 3: da valutare dopo un mese d'uso**
Foto + vocale via WhatsApp a Sole (tool staff `crea_capitolo_pezzo`, con trascrizione esterna),
domande di Sole più mirate, area privata cliente (tema separato).

## Da verificare dal vivo
- Dettatura su iPhone di Michela: in Safari e dall'icona sulla home.
- Basic Auth con l'app salvata sulla home di iOS (a volte richiede il login a ogni apertura).
- Pagina-pezzo su WP: aspetto con Divi, tag, permalink, box dello snippet.
- Sole su una pagina-pezzo: risponde su materiali e tecniche, non inventa, raccoglie il contatto.
