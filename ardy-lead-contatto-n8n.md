# Primo contatto lead da WhatsApp — Action layer n8n

Scenario: dopo che Sole ha creato la scheda ([[CREA_SCHEDA]]), Michela chiede a Sole
di contattare il lead. Sole prepara il messaggio, lo mostra, aspetta conferma, poi
emette il marker:

```
[[CONTATTA_LEAD]]{"session_id":"wa-abc1234567890123"}
```

Il nodo Code di n8n intercetta il marker, chiama l'endpoint, e rimanda a Michela
il riepilogo (o l'errore).

## Endpoint
- URL: `https://ardyagent.ardy-lab.it/ardy-lead-contatto.php`
- Metodo: `POST`, body JSON `{"session_id":"..."}`.
- Header `X-Ardy-Secret: <WA_LOOKUP_SECRET>`.
- Risposta: `{"success":true,"session_id":"...","telefono":"...","chat_link":"...","riepilogo":"Primo contatto inviato ✅\n..."}`.

## Nodo Code — dove va il codice

Come per [[CREA_SCHEDA]], il blocco va **subito dopo aver ottenuto la risposta di Claude**
e **prima** di inviarla via Cloud API. Va DOPO il blocco [[CREA_SCHEDA]] (un messaggio
può contenere solo uno dei due marker).

```javascript
// === Action layer: [[CONTATTA_LEAD]] → primo contatto WA al lead ===
const CONTATTO_ENDPOINT = 'https://ardyagent.ardy-lab.it/ardy-lead-contatto.php';

const mc = aiText.match(/\[\[CONTATTA_LEAD\]\]\s*(\{[\s\S]*?\})/);
if (mc) {
  aiText = aiText.replace(mc[0], '').trim();

  let dati = null;
  try { dati = JSON.parse(mc[1]); } catch (e) { dati = null; }

  if (dati && dati.session_id) {
    try {
      const out = await this.helpers.httpRequest({
        method: 'POST',
        url: CONTATTO_ENDPOINT,
        headers: { 'X-Ardy-Secret': SECRET },
        body: { session_id: dati.session_id },
        json: true,
        timeout: 20000,
      });
      if (out && out.success) {
        if (!aiText) aiText = out.riepilogo;
      } else {
        aiText = (aiText ? aiText + '\n\n' : '') +
          '⚠️ Non sono riuscita a inviare il contatto: ' + ((out && out.error) || 'errore');
      }
    } catch (e) {
      aiText = (aiText ? aiText + '\n\n' : '') +
        '⚠️ Errore nell\'invio del primo contatto al lead.';
    }
  }
}
```

## Flusso tipico (Michela → Sole su WhatsApp)

1. Michela: «Sole, contatta quel lead di Grottaferrata, Mario Rossi»
2. Sole: «Ok! Gli manderei un messaggio su WhatsApp con un link alla nostra chat, così
   può raccontarci i dettagli del tavolo. Il messaggio direbbe:
   "Buongiorno Mario, sono Sole di Ardy Lab. Ho visto la sua richiesta per la
   verniciatura del tavolo e le scrivo per capire come possiamo aiutarla..."
   Confermi che lo mando?»
3. Michela: «Sì, manda»
4. Sole: «Primo contatto inviato ✅ A Mario Rossi, link webchat incluso.»
   `[[CONTATTA_LEAD]]{"session_id":"wa-abc1234567890123"}`

Il marker viene intercettato da n8n, il WA parte al lead con il template Meta, e
Michela vede solo il riepilogo pulito.
