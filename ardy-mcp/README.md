# Ardy MCP Server

Collega **Claude Desktop** (o un altro client MCP) all'outreach di Ardy Lab. Dal desktop parli in
italiano — *"cerca i B&B del Salento senza email e trovami i loro social"* — e l'agente lavora sui
contatti veri, usando le API di Ardy.

Gira **in locale**, come sottoprocesso del client, su trasporto stdio. Non va deployato sul server:
`deploy.sh` lo esclude apposta.

---

## Cosa può fare, e cosa no

**Legge e propone**

| Tool | Cosa fa |
|---|---|
| `ardy_cerca_contatti` | Cerca per categoria, zona, stato, testo. Filtri utili: senza email, con/senza canali social |
| `ardy_dettaglio_contatto` | Scheda completa di un contatto |
| `ardy_statistiche_outreach` | Totali per categoria: da contattare, inviati, risposte |
| `ardy_lista_regioni` | Le zone già in uso, per non tirare a indovinare nei filtri |
| `ardy_lista_template_email` | I template email salvati |

**Cerca in rete** (costa — vedi sotto)

| Tool | Cosa fa |
|---|---|
| `ardy_trova_canali_social` | Cerca i profili Instagram/Facebook/LinkedIn di un contatto |
| `ardy_arricchisci_contatto` | Completa la scheda: email, telefono, sito, indirizzo, referente, social. `scope='google'` limita ai passi gratuiti + Places, senza mai far partire l'agente — pensato per gli import grossi |
| `ardy_estrai_portale_bb` | Legge una pagina di B&B da bed-and-breakfast.it (gratis, dato pubblico del portale) |
| `ardy_prova_copertura_places` | Misura quanti telefoni darebbe Google Places su un campione, prima di spendere sull'arricchimento di tutta una regione |

Tutti e quattro restituiscono **proposte/misure, non scritture**: niente arriva sul database senza passare da `ardy_aggiorna_contatto`. Il salvataggio in blocco dei lead trovati sul portale (`save_leads`) resta in dash di proposito — stessa logica di `send_campaign`, vedi sotto.

**Scrive**

| Tool | Cosa fa |
|---|---|
| `ardy_aggiorna_contatto` | Salva i campi che hai verificato |
| `ardy_scrivi_messaggio_social` | Bozza di DM + link che apre la chat. **Non invia** |
| `ardy_scrivi_email` | Bozza di email nel tono di Ardy Lab. Non invia, non salva |

**Invia davvero**

| Tool | Cosa fa |
|---|---|
| `ardy_invia_email` | Manda una email a **un** contatto via Brevo. Richiede `conferma: true` |

### Cosa NON è esposto, di proposito

- **Invio di campagne in massa** (`send_campaign`). Un errore di battitura in chat non deve poter
  scrivere a duecento persone. Le campagne restano in dash, dove vedi la lista prima di premere invio.
- **Cancellazione contatti** (`delete_contact`, `delete_contacts`). Stessa ragione, al contrario.
- **Import clienti dal CRM**. Operazione rara, meglio farla dove si vede cosa entra.
- **Salvataggio dei lead trovati sul portale B&B** (`save_leads`). `ardy_estrai_portale_bb` propone,
  non scrive: è un inserimento in blocco di righe nuove, e resta in dash per lo stesso motivo delle
  campagne — si vede l'elenco prima di importarlo.

### Perché i DM social non partono da qui

Instagram, Facebook e LinkedIn **non espongono API per il messaggio diretto a freddo**: si può solo
*rispondere* entro 24h a chi ha aperto lui la conversazione. Gli strumenti che promettono "DM
automatici" usano automazioni non ufficiali che violano i ToS e portano al **blocco dell'account** —
lo stesso account business che pubblica su Facebook e Instagram.

Perciò `ardy_scrivi_messaggio_social` prepara il testo e ti dà il link diretto alla chat
(`ig.me/m/…`, `m.me/…`), ma a incollare e inviare sei tu.

---

## Installazione

Serve **Node 18+**.

```bash
cd ardy-mcp
npm install
npm run build
```

## Configurazione in Claude Desktop

Apri il file di configurazione:

- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

e aggiungi (metti il **percorso assoluto** di `dist/index.js`):

```json
{
  "mcpServers": {
    "ardy": {
      "command": "node",
      "args": ["/percorso/assoluto/ardyagent/ardy-mcp/dist/index.js"],
      "env": {
        "ARDY_API_URL": "https://ardyagent.ardy-lab.it",
        "ARDY_USER": "utente-basic-auth",
        "ARDY_PASS": "password-basic-auth"
      }
    }
  }
}
```

Riavvia Claude Desktop. Se il server non parte, l'errore è su stderr e dice quale variabile manca.

`ARDY_USER` e `ARDY_PASS` sono le credenziali del **Basic Auth** dell'area riservata, le stesse che il
browser chiede aprendo la dash. `ARDY_API_URL` **deve** essere `https://`: il Basic Auth manda le
credenziali in chiaro, quindi il server rifiuta di partire su `http://`.

> Le credenziali stanno in chiaro in `claude_desktop_config.json`, come per ogni server MCP. È un file
> sul tuo computer: proteggilo come proteggeresti un file con le password, e non metterlo nel repo.

---

## Costi

Alcuni tool chiamano l'AI e si pagano sull'account Anthropic di Ardy Lab:

| Tool | Costo indicativo |
|---|---|
| `ardy_trova_canali_social` | gratis se il sito ha le icone social, altrimenti ~$0,02–0,07 |
| `ardy_arricchisci_contatto` (default, scope='tutto') | ~$0,05–0,10 se mancano dati di contatto (include Google Places) |
| `ardy_arricchisci_contatto` (scope='google') | $0 sull'account Anthropic — sito ufficiale + Google Places, mai l'agente |
| `ardy_prova_copertura_places` | una chiamata Places a struttura del campione, sull'account Google (non Anthropic); prime 1.000/mese gratis |
| `ardy_estrai_portale_bb` | gratis — legge dato pubblico del portale |
| `ardy_scrivi_messaggio_social`, `ardy_scrivi_email` | pochi centesimi a chiamata |

Tutto il resto è gratuito. Se al contatto manca **solo** il social,
`ardy_arricchisci_contatto` (scope='tutto') non fa partire la ricerca a pagamento: rilegge solo il sito.
Su un giro di import grosso conviene `ardy_prova_copertura_places` su un campione, poi
`ardy_arricchisci_contatto` con `scope='google'` su tutti: zero spesa sull'agente Claude.

---

## Esempi

> **"Quanti B&B ho in Salento e quanti ho già contattato?"**
> → `ardy_statistiche_outreach`, `ardy_cerca_contatti`

> **"Prendi i B&B del Salento senza email, trova i loro canali social e dimmi quali ne hanno uno."**
> → `ardy_cerca_contatti` (categoria=bb, regione=Salento, solo_senza_email=true) →
> `ardy_trova_canali_social` su ciascuno → `ardy_aggiorna_contatto` per salvare quelli buoni

> **"Scrivi un messaggio Instagram per questo B&B per proporgli Galleria Diffusa."**
> → `ardy_scrivi_messaggio_social` → ti restituisce testo e link, poi incolli tu

> **"Manda a questo antiquario l'email di presentazione."**
> → `ardy_lista_template_email` → `ardy_invia_email` (con `conferma: true` dopo che hai approvato)

> **"Quanti B&B ci sono in Puglia sul portale, e conviene arricchirli con Google?"**
> → `ardy_estrai_portale_bb` (regione_slug=puglia, più pagine finché `fine`) →
> `ardy_prova_copertura_places` su un campione dei risultati → se la copertura è buona, importa da dash
> e poi `ardy_arricchisci_contatto` con `scope='google'` su ognuno

---

## Sviluppo

```bash
npm run dev     # ricompila e riavvia a ogni modifica
npm run build   # compila in dist/
```

Per ispezionare i tool senza Claude Desktop:

```bash
npx @modelcontextprotocol/inspector node dist/index.js
```
