# Pubblicazione social — selezione per singolo social (nodo n8n)

> ✅ **APPLICATO (14/06)**: nel workflow n8n **"Meta"**, ramo **post-foto** (`Webhook` → nodo
> **Code in JavaScript**, webhook `7d01db65-…`) è stato inserito il gate `wantFB`/`wantIG`. Il ramo
> **Reels** in alto (`Webhook1 → HTTP Request → Wait → HTTP Request1`) NON è toccato.
> Riferimenti reali del nodo: Graph API `v25.0`, Pagina FB `376551605541671`, IG `17841404189479259`.

Contesto: `ardy-pubblica-social.php` invia al webhook n8n
(`/webhook/7d01db65-…`) il post da pubblicare. Ora il payload include **quali
social** vanno usati, così si può pubblicare anche su **uno solo** (Facebook *o*
Instagram) e non sempre su entrambi.

## Cosa arriva ora nel body del webhook

Campi già esistenti: `testo`, `testo_social`, `fase`, `mobile`, `post_link`,
`immagini[]`, `cliente`.

Nuovi campi:

```json
{
  "piattaforme": ["facebook"],   // oppure ["instagram"] oppure ["facebook","instagram"]
  "facebook":  true,             // comodità: booleano già pronto
  "instagram": false
}
```

- `piattaforme`: array whitelisted lato PHP (solo `facebook`/`instagram`).
- `facebook` / `instagram`: booleani di comodo, equivalenti a
  `piattaforme.includes(...)`.
- **Retro-compatibilità**: se `piattaforme` manca o è vuoto, il PHP lo forza a
  `["facebook","instagram"]` (= comportamento di prima). Quindi vecchie chiamate
  continuano a pubblicare su entrambi.

## Modifica al nodo Code "Meta" (ramo post-foto)

Nel nodo Code che oggi pubblica **sempre** su Facebook e Instagram, racchiudere
i due blocchi in un `if`:

```js
const body = $json.body || $json;            // a seconda di come leggi il webhook
const wantFB = body.facebook === true ||
               (Array.isArray(body.piattaforme) && body.piattaforme.includes('facebook')) ||
               body.piattaforme === undefined;   // default: entrambi
const wantIG = body.instagram === true ||
               (Array.isArray(body.piattaforme) && body.piattaforme.includes('instagram')) ||
               body.piattaforme === undefined;

let risultati = {};

if (wantFB) {
  // ... blocco esistente che pubblica su Facebook (/feed o /photos) ...
  risultati.facebook = 'ok';
}

if (wantIG) {
  // ... blocco esistente che pubblica su Instagram (/media → /media_publish) ...
  risultati.instagram = 'ok';
}

return [{ json: { ok: true, pubblicato: risultati } }];
```

> Il `body.piattaforme === undefined` nel default serve solo se qualche vecchio
> trigger chiama il webhook senza il campo; con le chiamate dalla dashboard
> aggiornata il campo c'è sempre.

## ⚠️ Esito REALE per piattaforma (fix "semaforo verde ma niente pubblicato")

> Sintomo osservato: pubblicazione andata a vuoto (né testo né foto su Meta) ma
> la dashboard verde. Causa: due difetti che si sommano.

**Difetto 1 — il workflow mente.** Nello snippet qui sopra `risultati.facebook = 'ok'`
è impostato **incondizionatamente**, senza guardare la risposta di Graph API. Se la
`HTTP Request` verso Graph fallisce (token scaduto, immagine rifiutata da IG per
proporzioni/formato, permessi), il workflow risponde comunque `{ ok: true }`.

**Difetto 2 — n8n risponde subito.** Se il nodo **Webhook** è su *"Respond
immediately"*, torna `200` **prima** ancora che le chiamate a Graph avvengano: il
PHP non può sapere l'esito.

### Cosa cambiare in n8n

1. **Webhook → Respond: "Using 'Respond to Webhook' node"** (non "immediately"), e
   mettere il `Respond to Webhook` **in fondo**, dopo le chiamate a Graph. Così il
   `200` arriva solo a pubblicazione conclusa. (`ardy-pubblica-social.php` ora
   aspetta fino a 30s.)
2. **Derivare l'esito dalla risposta di Graph**, non fissarlo a `'ok'`. Graph torna
   `{ id: ... }`/`{ post_id: ... }` sul successo e `{ error: { message, ... } }`
   sul fallimento. Esempio:

```js
// dopo la HTTP Request a Graph (per ciascuna piattaforma):
const fb = $node["HTTP Request FB"]?.json || {};
const ig = $node["HTTP Request IG"]?.json || {};

let risultati = {};
let errori = [];
if (wantFB) {
  if (fb.id || fb.post_id) risultati.facebook = 'ok';
  else { risultati.facebook = 'errore'; if (fb.error) errori.push('FB: ' + (fb.error.message || 'errore')); }
}
if (wantIG) {
  if (ig.id) risultati.instagram = 'ok';
  else { risultati.instagram = 'errore'; if (ig.error) errori.push('IG: ' + (ig.error.message || 'errore')); }
}

const ok = errori.length === 0;
return [{ json: ok ? { ok: true, pubblicato: risultati }
                    : { ok: false, pubblicato: risultati, error: errori.join(' · ') } }];
```

### Contratto letto dal PHP

`ardy-pubblica-social.php` (funzione `ardySocialInterpretaEsito`) interpreta la
risposta così:

- `{ ok:false }` **oppure** `{ error: ... }` / `{ errors: [...] }` → **fallimento**:
  la dash mostra l'errore (niente verde).
- `{ ok:true, pubblicato:{ facebook:'ok', instagram:'ok' } }` → **confermato**: verde
  pieno solo per le piattaforme marcate `'ok'` (una `'errore'` non conta).
- `200` con corpo vuoto o senza questi campi → **inviato ma non confermato**: la dash
  scrive *"📤 Inviato ai social — verifica che sia pubblicato"* invece del verde.
  (Stato di transizione finché il workflow non espone l'esito reale: nessuna
  regressione sui flussi che oggi funzionano.)

## Lato dashboard (già fatto, in questo repo)

- Le icone brand nel pannello "📲 Vuoi pubblicare sui social?" e in ogni post in
  attesa sono ora **toggle** (`ardy-michela-app.html` → `socialDestHtml`,
  `toggleSocialDest`, `getSelectedPlatforms`). Default: Facebook + Instagram
  entrambi attivi; si può deselezionarne uno (non zero). Google resta disattivato.
- La selezione viaggia nel campo `piattaforme` e viene salvata anche nei post
  "in attesa" (localStorage), così resta scelta anche pubblicando più tardi.
