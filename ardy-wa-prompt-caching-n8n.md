# Prompt caching modalità titolare — migrazione n8n

Obiettivo: tagliare il **costo dominante** (API Claude per ogni messaggio di Michela a Sole)
attivando il **prompt caching** sul ramo WhatsApp. Oggi non funziona perché il **riepilogo CRM**
(volatile, rigenerato ad ogni messaggio) è incollato **dentro** il system prompt, davanti al
documento statico: il prefisso cambia ad ogni messaggio → la cache è inutile.

Il caching è un **match di prefisso**: appena un byte cambia nel prefisso, tutto ciò che segue
si invalida. Quindi la parte volatile (riepilogo CRM) deve stare **DOPO** la parte statica.

## Cosa è cambiato lato repo (`ardy-wa-lookup.php`)

La risposta in `mode: "titolare"` ora include, **oltre** al campo legacy, due campi nuovi:

```jsonc
{
  "success": true,
  "mode": "titolare",
  "cliente": null,
  "system_prompt": "…",   // LEGACY: prompt completo (riepilogo incluso). NON cacheabile.
  "system_static": "…",   // STATICO: istruzioni + documento. → blocco system con cache_control
  "crm_context":  "## DATI OPERATIVI ATTUALI (dal CRM)\n…"  // VOLATILE: in un messaggio a parte
}
```

`system_prompt` resta invariato: **finché n8n non viene aggiornato, tutto funziona come prima**
(nessuna regressione). Il caching si attiva solo quando il nodo HTTP passa a `system_static` +
`crm_context`.

## Modifica al nodo Code (chiamata a Claude)

Nel ramo titolare, **al posto** di usare `lookup.system_prompt` come unico system, costruire la
richiesta così:

```js
// lookup = risposta JSON di ardy-wa-lookup.php
const body = {
  model: 'claude-sonnet-4-6',
  max_tokens: 1000,
  // SYSTEM: solo la parte statica, con breakpoint di cache sull'ultimo blocco.
  system: [
    { type: 'text', text: lookup.system_static, cache_control: { type: 'ephemeral' } },
  ],
  messages: [
    // Il riepilogo CRM volatile entra come PRIMO messaggio utente, NON nel system,
    // così non invalida il prefisso cacheato.
    { role: 'user', content: lookup.crm_context },
    // …poi la cronologia della conversazione e il nuovo messaggio di Michela…
    ...history,
    { role: 'user', content: messaggioMichela },
  ],
};

const res = await this.helpers.httpRequest({
  method: 'POST',
  url: 'https://api.anthropic.com/v1/messages',
  headers: {
    'content-type': 'application/json',
    'x-api-key': '<ARDY_API_KEY>',
    'anthropic-version': '2023-06-01',
    'anthropic-beta': 'prompt-caching-2024-07-31',
  },
  body,
  json: true,
});
```

Note:
- Il blocco `system` deve avere `cache_control` sull'**ultimo** blocco testuale (qui l'unico).
- Il `crm_context` va **dopo** il breakpoint (in un messaggio `user`), mai dentro `system`.
- Header `anthropic-beta: prompt-caching-2024-07-31` obbligatorio.

## Verifica che la cache faccia hit

Nella risposta dell'API leggere `usage`:

```js
const u = res.usage || {};
// Atteso dal 2º messaggio in poi (entro 5 min): cache_read_input_tokens > 0
console.log('USAGE', u.input_tokens, u.cache_read_input_tokens, u.cache_creation_input_tokens);
```

- 1º messaggio: `cache_creation_input_tokens` > 0 (scrittura cache, costo 1.25×).
- Messaggi successivi entro 5 min: `cache_read_input_tokens` > 0 (lettura ~0.1×) → risparmio.
- Se `cache_read` resta **0** anche al 2º messaggio ravvicinato → c'è ancora un invalidatore nel
  prefisso (qualcosa di volatile finito in `system` o nei tool). Controllare che `crm_context`
  sia davvero fuori dal `system`.

⚠️ Soglia minima cacheabile per `claude-sonnet-4-6`: **2048 token** di prefisso. `system_static`
(istruzioni titolare + `ardy-system.txt`) è ampiamente sopra, quindi la cache si attiva.

## Rollback

Se qualcosa va storto: tornare a usare `lookup.system_prompt` come system stringa singola
(comportamento legacy). Il repo continua a fornirlo.
