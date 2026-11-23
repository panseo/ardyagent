# Analisi — Lavorazioni dei clienti fuori da WordPress, in un'area su ardyagent

> Domanda (23/11/2026): le pagine "lavori in corso" dei clienti vanno tolte dal sito. Al loro posto,
> eventualmente, una sezione su `ardyagent.ardy-lab.it` dove instradare il cliente. Come si fa?
>
> Stato: **passo 1 scritto** (area in sola lettura, ramo `area-cliente-lettura`), da collaudare. Decisioni prese il 23/11 (§7): foto sul server, accesso
> col solo codice, area aperta anche dopo la consegna, **nessuna chat nell'area** (le domande si fanno
> su WhatsApp come oggi), categoria non rinominata.

---

## 1. Perché ha senso

- **Privacy.** Oggi la lavorazione di un cliente è una pagina pubblica e indicizzabile, con foto di
  casa sua e del suo mobile. La protegge solo il fatto che il link non è pubblicizzato. Un'area
  privata toglie questo rischio alla radice.
- **La categoria `lavori-in-corso` diventa coerente.** Ci restano solo le storie dei pezzi di
  Racconta, cioè contenuti pensati per il pubblico. Oggi le due cose sono mescolate, e il widget
  deve chiedere al visitatore "sei il cliente?".
- **Su ardyagent c'è già tutto il necessario**: dati delle fasi nel DB, codice di accesso
  `ARD-XXXX-XXXX`, link firmati HMAC (già usati per i lead), chat di Sole con il calendario visite.

## 2. Cosa dipende oggi dalla pagina WordPress del cliente

Mappato dal codice. Ogni riga è un punto da cambiare o da tenere com'è.

| Dove | Cosa fa con la pagina WP | Impatto |
|---|---|---|
| `ardy-pubblica-lavorazione.php` | Crea/aggiorna il post, ci carica le foto, salva `clienti.wp_post_id/wp_post_link` | **Cuore del cambio**: smette di creare il post |
| Stesso file, foto | Le foto vanno nella libreria media di WP e `fasi.foto_urls` salva gli **URL WP** | **Il punto delicato**, vedi §3 |
| Stesso file, notifiche | Email e WhatsApp al cliente con il link alla pagina | Il link diventa quello dell'area. Il template Meta `WA_TEMPLATE_FASI` ha il link nel **corpo** ({{4}}), non in un bottone URL: cambiarlo non richiede una nuova approvazione. Da rileggere solo il testo del template, se dice "sul nostro sito" |
| `ardy-widget-lavorazione.js` + `ardy-verify-client.php` + `ardy-proxy-lavorazione.php` | Chat del cliente sulla pagina, con verifica telefono/codice e prenotazione visita | **Si ritira il ramo cliente**: le domande il cliente le fa su WhatsApp, dove Sole lo riconosce dal telefono. Il widget resta solo per le pagine-pezzo. La verifica col codice diventa l'accesso all'area |
| `ardy-proxy.php` (tool `cerca_cliente`) | Con il codice d'accesso, Sole restituisce `pagina_lavoro` | Diventa il link all'area |
| `ardy-wa-lookup.php` + `ardy-whatsapp-system.txt` | Su WhatsApp Sole manda "trovi foto e fasi qui: {{wp_post_link}}" | Diventa il link all'area (firmato per quel cliente) |
| `ardy-dossier.php`, `ardy-crm-api.php`, dash | Mostrano "Pagina lavoro" | Link all'area (vista staff) |
| `ardy-crea-reel.php` | Le foto cliente le **scarica dagli URL WP** | Dipende da §3 |
| Social e Google (`ardy-pubblica-social.php`, `ardy-gbp-post.php`) | Prendono le immagini da URL pubblici (WP) e Google linka la pagina | Immagini: dipende da §3. Link "Scopri di più": va su una pagina pubblica (pagina-pezzo o home), **mai** sull'area privata |
| `ardy-crea-faq.php` | Usa `wp_post_id` del cliente | Da rivedere quando si tocca |
| WordPress: `lavorazioni-copertina-fallback.php`, `pulsante-flottante-ovunque.php`, loader nelle Integrazioni Divi | Logica sulla categoria `lavori-in-corso` | Restano validi per le pagine-pezzo. Il loader si semplifica: non c'è più il ramo cliente |
| Pagine **già pubblicate** e link già mandati per email/WhatsApp | Puntano a `ardy-lab.it/lavori-in-corso/...` | Vanno **reindirizzati**, sennò il cliente trova un 404. Vedi §5 |

## 3. Il nodo vero: dove stanno le foto

Oggi le foto di una fase **diventano pubbliche passando da WordPress**. Su quegli URL pubblici
contano reel, Facebook/Instagram e Google, che devono poter scaricare l'immagine. Togliendo il
post, le foto devono comunque avere un indirizzo pubblico per i social e uno privato per l'area.

| Opzione | Come | Pro | Contro |
|---|---|---|---|
| **A. Media WP senza post** | Si continua a caricare in libreria media, ma senza creare il post | Zero cambi per reel/social/GBP | Le foto restano pubbliche su ardy-lab.it (URL difficili da indovinare, ma pubbliche); WP può creare pagine-allegato indicizzabili |
| **B. Disco ardyagent, privato** ⭐ | Come i capitoli di Racconta: foto su disco, servite all'area da un endpoint che controlla l'accesso. **Solo quando si pubblica sui social** una foto viene caricata nella libreria media WP (lo fa già `ardy-social-foto.php`) | L'area è davvero privata, e le foto diventano pubbliche solo se si sceglie di pubblicarle | Reel e foto social del cliente vanno ricablati per leggere dal disco (il reel lo fa già per progetti e pezzi) |
| C. B2 con URL firmati | Foto su Backblaze, link temporanei | Spazio illimitato | Più parti in movimento; B2 ha già dato problemi letto da dentro WP |

**Deciso: B** (23/11). È coerente con Racconta, e "privato" vuol dire davvero privato.

## 4. Com'è fatta l'area

- **Indirizzo**: `https://ardyagent.ardy-lab.it/lavoro/` (riscrittura verso `ardy-area-cliente.html`).
- **Accesso: il codice** `ARD-XXXX-XXXX` (già generato e mandato al cliente), nessuna password
  (deciso 23/11). Limite di tentativi per IP, come `ardy-verify-client.php`. Per comodità il link nelle
  email/WhatsApp può già contenere il codice (`.../lavoro/?codice=ARD-…`), così il cliente non lo
  digita: è lo stesso segreto, non ne aggiunge uno nuovo.
- **Sessione**: cookie firmato, `HttpOnly`, `Secure`, `SameSite=Lax`, durata qualche settimana.
  Così il cliente non rifà l'accesso a ogni visita.
- **Cosa vede**: le fasi in ordine (testo, foto, video, data), le comunicazioni straordinarie
  evidenziate, lo stato del lavoro, e un bottone **"Scrivici su WhatsApp"** (`wa.me`) per le domande.
  **Nessuna chat nell'area** (deciso 23/11): su WhatsApp Sole riconosce già il cliente dal telefono.
  I dati vengono tutti dalla tabella `fasi`: **non c'è contenuto da migrare**, nemmeno per i lavori
  già pubblicati.
- **Durata**: l'area resta consultabile anche dopo la consegna, come oggi le pagine WP.
- **Sicurezza** (invarianti del progetto): l'area è **fuori** dal Basic Auth staff ma ha un suo
  controllo per ogni richiesta; `noindex` + `robots.txt`; foto servite solo previo controllo della
  sessione; mai `getMessage()` al client; rate limit su codice e telefono.
- **Più avanti, se serve**: preventivi in PDF, documenti di consegna, saldo da pagare. Il dossier
  esiste già lato staff.

## 5. Le pagine già pubblicate

Due strade, non esclusive:

1. **Redirect automatico**: un piccolo snippet PHP in WPCode. Se il post è in `lavori-in-corso`
   **senza** il tag `storia-pezzo`, fa un 301 verso `ardyagent.ardy-lab.it/lavoro/`. L'area chiede il
   codice. I vecchi link mandati ai clienti continuano a portare al loro lavoro.
2. **Ritiro dei post**: messi in bozza (o cestino) a blocchi, solo **dopo** che il redirect è attivo
   e collaudato. Con il redirect attivo i post in bozza non servono più, ma tenerli per un po' è
   una rete di sicurezza.

SEO: le pagine cliente non portano traffico utile. Il 301 verso una pagina `noindex` le fa uscire
dall'indice in modo pulito. Le pagine-pezzo di Racconta prendono il loro posto come contenuto
pubblico.

## 6. Ordine di lavoro proposto

1. **Area in sola lettura** (`/lavoro/`, accesso col codice, fasi da DB). Le foto delle fasi già
   pubblicate stanno su WP e si mostrano da lì; quelle nuove arriveranno dal disco. Si prova con i
   clienti attuali **senza toccare** la pubblicazione: le pagine WP restano accese.
2. **Pubblicazione fase → area**: `ardy-pubblica-lavorazione.php` smette di creare il post, foto su
   disco (opzione B); email/WhatsApp/Sole/dossier mandano il link dell'area; reel e social ricablati
   per leggere dal disco.
3. **Redirect su WordPress** dei vecchi post cliente, poi ritiro dei post.
4. Pulizia: widget e loader Divi senza ramo cliente, `ardy-proxy-lavorazione.php` e
   `ardy-verify-client.php` ritirati, `README`, guida Michela.

Il passo 1 non rompe nulla e si collauda con calma; il passo 2 è il cambio vero.

## 7. Decisioni (23/11/2026)

- **Foto**: sul server (opzione B).
- **Accesso**: basta il codice, niente password.
- **Dopo la consegna**: l'area resta consultabile, come oggi.
- **Chat**: nessuna nell'area. Le domande si fanno su WhatsApp, dove il cliente è riconosciuto dal telefono.
- **Categoria**: resta "Lavori in corso", nessuna rinomina. Era solo una questione di etichetta: i
  clienti non passano più da WordPress, e lo slug resta comunque quello.
