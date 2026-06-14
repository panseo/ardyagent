# Pubblicazione social — selezione per singolo social (nodo n8n)

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

## Lato dashboard (già fatto, in questo repo)

- Le icone brand nel pannello "📲 Vuoi pubblicare sui social?" e in ogni post in
  attesa sono ora **toggle** (`ardy-michela-app.html` → `socialDestHtml`,
  `toggleSocialDest`, `getSelectedPlatforms`). Default: Facebook + Instagram
  entrambi attivi; si può deselezionarne uno (non zero). Google resta disattivato.
- La selezione viaggia nel campo `piattaforme` e viene salvata anche nei post
  "in attesa" (localStorage), così resta scelta anche pubblicando più tardi.
