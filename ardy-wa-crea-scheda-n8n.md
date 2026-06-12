# Sole crea scheda da WhatsApp — Action layer n8n

Scenario 1 (l'unico approvato): in modalità **titolare**, Michela detta a Sole un cliente
nuovo; Sole raccoglie i dati, li ripete, e dopo il "sì" emette nel testo un marker:

```
[[CREA_SCHEDA]]{"nome":"Mario","cognome":"Rossi","telefono":"3331234567","email":"","indirizzo":"","zona":"Prati","servizio":"rilaccatura","mobile":"credenza","stato":"LEAD","note":""}
```

Il ramo WhatsApp di n8n è **solo testo**: serve un nodo **Code** che intercetti il marker,
chiami l'endpoint e ripulisca il messaggio mostrato a Michela.

## Endpoint
- URL: `https://ardyagent.ardy-lab.it/ardy-wa-crea-scheda.php`
- Metodo: `POST`, body JSON con i campi (o `{"dati":{...}}`).
- Header `X-Ardy-Secret: <WA_LOOKUP_SECRET>` (stesso segreto di `ardy-wa-lookup.php`).
- Risposta: `{"success":true,"created":true|false,"riepilogo":"Scheda creata ✅\n…"}`.

## Campi standard (1:1 con la scheda CRM)
`nome, cognome, telefono, email, indirizzo, zona, servizio, mobile, stato, note`
Stato ∈ `LEAD | SOPRALLUOGO | PREVENTIVO | ACCONTO | STANDBY | PERSO` (default `LEAD`).
Minimo richiesto: almeno uno tra `nome`, `cognome`, `telefono`.

## Nodo Code (JavaScript) — da inserire DOPO il nodo AI, PRIMA dell'invio WhatsApp

Si aspetta in input l'oggetto `{ reply: "<testo della AI>" }` (adatta `aiText` al nome
reale del campo nel tuo flusso). Esegue la chiamata HTTP via `fetch` (n8n recente).

```javascript
// === Action layer: [[CREA_SCHEDA]] → crea scheda nel CRM ===
const SECRET   = 'INCOLLA_QUI_WA_LOOKUP_SECRET';
const ENDPOINT = 'https://ardyagent.ardy-lab.it/ardy-wa-crea-scheda.php';

let aiText = $json.reply ?? $json.text ?? '';
const m = aiText.match(/\[\[CREA_SCHEDA\]\]\s*(\{[\s\S]*?\})/);

if (m) {
  // 1) togli il marker dal testo che vedrà Michela
  aiText = aiText.replace(m[0], '').trim();

  // 2) parse del JSON e chiamata all'endpoint
  let dati = null;
  try { dati = JSON.parse(m[1]); } catch (e) { dati = null; }

  if (dati) {
    try {
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Ardy-Secret': SECRET },
        body: JSON.stringify(dati),
      });
      const out = await res.json();
      if (out && out.success) {
        // opzionale: usa il riepilogo del server come testo di conferma
        if (!aiText) aiText = out.riepilogo;
      } else {
        aiText = (aiText ? aiText + '\n\n' : '') +
          '⚠️ Non sono riuscita a salvare la scheda: ' + ((out && out.error) || 'errore') +
          '. Provo di nuovo?';
      }
    } catch (e) {
      aiText = (aiText ? aiText + '\n\n' : '') +
        '⚠️ Errore di rete nel salvataggio della scheda. Riprovo?';
    }
  }
}

return [{ json: { reply: aiText } }];
```

> Nota: se la tua versione di n8n non supporta `fetch`/`await` nel nodo Code, usa due nodi:
> un **Code** che estrae il marker e produce `dati` + `replyPulito`, poi un nodo **HTTP Request**
> (POST JSON all'endpoint, header `X-Ardy-Secret`), e infine ricomponi il messaggio.

## Comportamento
- **Niente marker** → il testo passa invariato (Sole sta ancora raccogliendo/confermando).
- **Marker presente** → scheda creata/aggiornata (upsert per telefono/nome, niente doppioni)
  e il marker non arriva mai a Michela.
