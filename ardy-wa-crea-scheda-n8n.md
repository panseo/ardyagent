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

## Nodo Code — dove va il codice

⚠️ Nel n8n di Ardy il nodo **HTTP Request standard non funziona** (timeout): le chiamate
HTTP si fanno dal **nodo Code** con `this.helpers.httpRequest`. Il flusso WhatsApp è già un
unico nodo Code (`lookup → Claude → invio Cloud API`): questo blocco va inserito **subito dopo
aver ottenuto la risposta di Claude** e **prima** di inviarla via Cloud API.

Adatta `aiText` alla variabile che nel tuo nodo contiene il testo di Claude (es. `replyText`).

```javascript
// === Action layer: [[CREA_SCHEDA]] → crea scheda nel CRM ===
const SECRET   = 'INCOLLA_QUI_WA_LOOKUP_SECRET'; // = WA_LOOKUP_SECRET di ardy-config.php
const ENDPOINT = 'https://ardyagent.ardy-lab.it/ardy-wa-crea-scheda.php';

// aiText = testo prodotto da Claude in questo nodo (rinomina se da te si chiama diversamente)
const m = aiText.match(/\[\[CREA_SCHEDA\]\]\s*(\{[\s\S]*?\})/);
if (m) {
  // 1) togli il marker dal testo che vedrà Michela
  aiText = aiText.replace(m[0], '').trim();

  // 2) parse del JSON
  let dati = null;
  try { dati = JSON.parse(m[1]); } catch (e) { dati = null; }

  // 3) chiama l'endpoint (httpRequest, NON il nodo HTTP Request)
  if (dati) {
    try {
      const out = await this.helpers.httpRequest({
        method: 'POST',
        url: ENDPOINT,
        headers: { 'X-Ardy-Secret': SECRET },
        body: dati,
        json: true,           // invia/riceve JSON
        timeout: 20000,
      });
      if (out && out.success) {
        if (!aiText) aiText = out.riepilogo;   // fallback al riepilogo del server
      } else {
        aiText = (aiText ? aiText + '\n\n' : '') +
          '⚠️ Non sono riuscita a salvare la scheda: ' + ((out && out.error) || 'errore') + '. Riprovo?';
      }
    } catch (e) {
      aiText = (aiText ? aiText + '\n\n' : '') +
        '⚠️ Errore nel salvataggio della scheda. Riprovo?';
    }
  }
}
// poi prosegui col tuo codice che invia `aiText` via Cloud API
```

## Comportamento
- **Niente marker** → il testo passa invariato (Sole sta ancora raccogliendo/confermando).
- **Marker presente** → scheda creata/aggiornata (upsert per telefono/nome, niente doppioni)
  e il marker non arriva mai a Michela.

## Come testarlo — frase d'esempio (Michela → Sole)

Michela scrive (o detta a voce) su WhatsApp, in un messaggio solo o spezzato:

> **Michela:** «Sole, segnami un cliente nuovo: Mario Rossi, 333 1234567, vuole rilaccare
> una credenza anni 60, zona Prati. Mettilo come lead.»

Sole ripete e chiede conferma (NON salva ancora):

> **Sole:** «Ok! Ti rileggo: Mario Rossi, 📞 333 1234567, servizio rilaccatura, mobile
> "credenza anni 60", zona Prati, stato LEAD. Confermo e salvo?»

Michela conferma:

> **Michela:** «Sì, salva.»

Sole risponde con la conferma + (dietro le quinte) il marker che n8n intercetta:

> **Sole:** «Fatto, scheda creata ✅»
> `[[CREA_SCHEDA]]{"nome":"Mario","cognome":"Rossi","telefono":"3331234567","email":"","indirizzo":"","zona":"Prati","servizio":"rilaccatura","mobile":"credenza anni 60","stato":"LEAD","note":""}`

Risultato: in dashboard compare la scheda di **Mario Rossi** (stato LEAD). Se Michela ridetta
lo stesso telefono, la scheda viene **aggiornata**, non duplicata.
