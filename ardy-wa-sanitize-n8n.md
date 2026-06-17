# Rete di sicurezza anti-sbrodolatura — ramo WhatsApp (n8n)

Obiettivo: impedire che la **sintassi di una chiamata a tool** (es.
`</invoke></function_calls>`) finisca in un messaggio WhatsApp a un cliente,
come successo nella conversazione con Alberto (LEAD).

## Perché serve qui e non solo nel repo

La risposta WhatsApp viene **generata e inviata da n8n** (nodo Code → chiamata
all'API Claude → invio a Meta). Il repo PHP **non tocca** quel messaggio in
uscita, quindi la sanitizzazione lato `ardy-proxy.php` (chat web) non copre
WhatsApp. La garanzia per WhatsApp va messa nel nodo n8n, subito **prima**
dell'invio a Meta.

Su WhatsApp Sole **non ha tool collegati**: quando "prova" a usarne uno (es.
controllare il calendario) il modello recita la sintassi del tool come testo
normale, e quella sintassi non eseguita finisce dritta al cliente. Il system
prompt (`ardy-whatsapp-system.txt`) glielo vieta a parole, ma un'istruzione in
prosa non è una garanzia: questo filtro lo è.

## Dove inserirlo

Nel workflow WhatsApp, tra il nodo che ottiene il testo di risposta di Claude
e il nodo HTTP che chiama `graph.facebook.com/.../messages`. Passa il testo
attraverso `ardyStripToolSyntax()` e invia il risultato.

## Snippet (nodo Code, JS) — porting di `ardy-sanitize.php`

```js
// Rimuove i residui di sintassi tool-call lasciati dal modello come testo:
// blocchi <function_calls>…</function_calls> completi e tag orfani
// (<invoke>, <parameter>, <tool_use>, ecc.). Conservativo: tocca solo
// token chiaramente sintattici, mai parole normali.
function ardyStripToolSyntax(text) {
  if (!text) return '';
  const tags = 'function_calls|invoke|parameter|tool_use|tool_call|tool_result|tool_calls';

  // 1) Blocchi completi <function_calls>…</function_calls>, contenuto incluso.
  text = text.replace(
    /<\s*(?:antml:)?function_calls\b[^>]*>[\s\S]*?<\s*\/\s*(?:antml:)?function_calls\s*>/gi,
    ''
  );

  // 2) Tag orfani rimasti (apertura o chiusura).
  text = text.replace(
    new RegExp('<\\s*/?\\s*(?:antml:)?(?:' + tags + ')\\b[^>]*>', 'gi'),
    ''
  );

  // 3) Normalizza la spaziatura lasciata dai tagli.
  text = text.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n');

  return text.trim();
}

// Esempio d'uso nel nodo Code, prima dell'invio:
const raw = $json.reply;               // testo grezzo di Claude
let reply = ardyStripToolSyntax(raw);

// Se lo strip ha svuotato il messaggio (output composto SOLO da sintassi tool),
// usa un fallback sicuro invece di inviare un messaggio vuoto.
if (!reply) {
  reply = 'Un attimo che verifico e ti riscrivo subito 🙂';
}

return [{ json: { ...$json, reply } }];
```

## Verifica veloce

Dai in pasto al filtro una stringa con la sbrodolatura e controlla che esca
pulita:

```js
ardyStripToolSyntax('</invoke></function_calls>\nUn attimo che controllo il calendario! 📅')
// → 'Un attimo che controllo il calendario! 📅'
```

## Nota

Questo è il **Piano 1** (rete di sicurezza): chiude il rischio che il cliente
veda codice, qualunque cosa faccia il modello. Resta da decidere il **Piano 2**
(architettura calendario su WhatsApp: handoff a Michela / tool veri / link di
booking). Il filtro va bene a prescindere dalla scelta.
