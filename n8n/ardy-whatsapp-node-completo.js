// --- Rete di sicurezza: rimuove sintassi tool sbrodolata come testo ---
function ardyStripToolSyntax(text) {
  if (!text) return '';
  const tags = 'function_calls|invoke|parameter|tool_use|tool_call|tool_result|tool_calls';
  text = text.replace(/<\s*(?:antml:)?function_calls\b[^>]*>[\s\S]*?<\s*\/\s*(?:antml:)?function_calls\s*>/gi, '');
  text = text.replace(new RegExp('<\\s*/?\\s*(?:antml:)?(?:' + tags + ')\\b[^>]*>', 'gi'), '');
  text = text.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n');
  return text.trim();
}
const body = ($input.first().json.body) || $input.first().json;
const from = body.from;
let message = body.message || '';

// Foto WhatsApp: id del media (l'agente la scaricherà). Se ha una didascalia
// la usiamo come messaggio, altrimenti un segnaposto per la memoria.
const mediaId   = body.media_id   || '';
const mediaMime = body.media_mime || '';
const caption   = body.caption    || '';
if (!message && mediaId) message = caption || '[foto inviata]';

// 🔐 Segreti da VARIABILI D'AMBIENTE n8n — MAI in chiaro nel codice del nodo,
// così non finiscono negli export del workflow. Impostale sull'host n8n (file
// .env / docker-compose), con QUESTI nomi:
//   WA_LOOKUP_SECRET   = WA_LOOKUP_SECRET di ardy-config.php
//   META_WA_TOKEN      = token Cloud API di Meta
//   ANTHROPIC_API_KEY  = API key Anthropic
// Richiede che l'accesso a $env nei nodi NON sia bloccato
// (N8N_BLOCK_ENV_ACCESS_IN_NODE=false — è il default).
const SECRET        = $env.WA_LOOKUP_SECRET;
const WA_TOKEN      = $env.META_WA_TOKEN;
const ANTHROPIC_KEY = $env.ANTHROPIC_API_KEY;
const PHONE_ID      = $env.WA_PHONE_NUMBER_ID || '1151535311377293'; // non segreto
const BASE          = 'https://ardyagent.ardy-lab.it';

// Fail-fast: se un segreto manca, fermati con un errore chiaro invece di fare
// chiamate non autenticate (che i guard fail-closed rifiuterebbero comunque).
if (!SECRET || !WA_TOKEN || !ANTHROPIC_KEY) {
  throw new Error("Config n8n mancante: imposta le variabili d'ambiente WA_LOOKUP_SECRET, META_WA_TOKEN e ANTHROPIC_API_KEY sull'host n8n.");
}

// Solo testo (per ora)
if (!message) {
  await this.helpers.httpRequest({
    method: 'POST', url: `https://graph.facebook.com/v21.0/${PHONE_ID}/messages`,
    headers: { Authorization: `Bearer ${WA_TOKEN}`, 'content-type': 'application/json' },
    body: { messaging_product: 'whatsapp', to: from, type: 'text',
            text: { body: 'Ciao! Per ora riesco a leggere solo messaggi di testo 🙂 Scrivimi pure cosa ti serve.' } },
    json: true,
  });
  return [{ json: { skipped: 'non-testuale', from } }];
}

// 1) Chi scrive + system prompt
const look = await this.helpers.httpRequest({
  method: 'GET', url: BASE + '/ardy-wa-lookup.php',
  qs: { phone: from }, headers: { 'X-Ardy-Secret': SECRET }, json: true,
});

// 2) Storico conversazione
let history = [];
try {
  const mem = await this.helpers.httpRequest({
    method: 'GET', url: BASE + '/ardy-wa-memoria.php',
    qs: { phone: from }, headers: { 'X-Ardy-Secret': SECRET }, json: true,
  });
  if (mem && Array.isArray(mem.history)) history = mem.history;
} catch (e) {}

// Costruisci i messaggi: storico + nuovo, garantendo alternanza e inizio con 'user'
let raw = history.map(h => ({ role: h.role === 'assistant' ? 'assistant' : 'user', content: String(h.content || '') }));
raw.push({ role: 'user', content: message });
const msgs = [];
for (const m of raw) {
  if (!m.content) continue;
  if (msgs.length && msgs[msgs.length - 1].role === m.role) {
    msgs[msgs.length - 1].content += '\n' + m.content; // unisci ruoli consecutivi uguali
  } else {
    msgs.push({ role: m.role, content: m.content });
  }
}
while (msgs.length && msgs[0].role !== 'user') msgs.shift();

// 3) Risposta di Sole — CON PROMPT CACHING
// La parte STATICA del system va in un blocco con cache_control: viene riletta dalla
// cache ad ogni messaggio (costo ~0,1x) invece di essere rispedita a prezzo pieno.
// In modalità titolare il riepilogo CRM volatile NON sta nel system (invaliderebbe
// la cache): arriva separato in look.crm_context e lo attacchiamo al messaggio corrente.
// Fallback: se i campi nuovi non ci sono, si usa il vecchio look.system_prompt.
const systemText = look.system_static || look.system_prompt;
const systemBlocks = [
  { type: 'text', text: systemText, cache_control: { type: 'ephemeral' } },
];

const claudeMsgs = msgs.map(m => ({ role: m.role, content: m.content }));
if (look.crm_context && claudeMsgs.length) {
  const last = claudeMsgs[claudeMsgs.length - 1];
  if (last.role === 'user') {
    last.content = look.crm_context + '\n\n---\n\n' + last.content;
  }
}

let reply;
if (look.mode === 'titolare') {
  // STAFF (Michela/Andrea): flusso esistente, single-shot + marker. Nessun tool.
  const claude = await this.helpers.httpRequest({
    method: 'POST', url: 'https://api.anthropic.com/v1/messages',
    headers: {
      'x-api-key': ANTHROPIC_KEY,
      'anthropic-version': '2023-06-01',
      'anthropic-beta': 'prompt-caching-2024-07-31',
      'content-type': 'application/json',
    },
    body: { model: 'claude-sonnet-4-6', max_tokens: 600, system: systemBlocks, messages: claudeMsgs },
    json: true,
  });
  reply = (claude.content && claude.content[0] && claude.content[0].text) || 'Scusa, ora non riesco a rispondere. Ti ricontatto a breve.';
} else {
  // CLIENTI: cervello PHP con tool calendario.
  const ag = await this.helpers.httpRequest({
    method: 'POST', url: BASE + '/ardy-wa-agent.php',
    headers: { 'X-Ardy-Secret': SECRET, 'content-type': 'application/json' },
    body: {
      system: systemText,
      messages: claudeMsgs,
      phone: from,
      media_id: mediaId,
      media_mime: mediaMime,
      session_id: (look.cliente && look.cliente.session_id) || '',
      cliente: look.cliente || null,
    },
    json: true, timeout: 120000,
  });
  reply = (ag && ag.reply) || 'Scusa, ora non riesco a rispondere. Ti ricontatto a breve.';
}

// 3b) Azione: se Sole ha emesso [[CREA_SCHEDA]] crea la scheda nel CRM
const mk = reply.match(/\[\[CREA_SCHEDA\]\]\s*(\{[\s\S]*?\})/);
if (mk) {
  // togli il codice segreto dal messaggio che vedrà Michela
  reply = reply.replace(mk[0], '').trim();
  let dati = null;
  try { dati = JSON.parse(mk[1]); } catch (e) { dati = null; }
  if (dati) {
    try {
      const out = await this.helpers.httpRequest({
        method: 'POST', url: BASE + '/ardy-wa-crea-scheda.php',
        headers: { 'X-Ardy-Secret': SECRET, 'content-type': 'application/json' },
        body: dati, json: true, timeout: 20000,
      });
      if (out && out.success) {
        if (!reply) reply = out.riepilogo; // se il testo è rimasto vuoto, usa il riepilogo del server
      } else {
        reply = (reply ? reply + '\n\n' : '') +
          '⚠️ Non sono riuscita a salvare la scheda: ' + ((out && out.error) || 'errore') + '. Riprovo?';
      }
    } catch (e) {
      reply = (reply ? reply + '\n\n' : '') + '⚠️ Errore nel salvataggio della scheda. Riprovo?';
    }
  }
}
// 3c) Azione: se Sole ha emesso [[CONTATTA_LEAD]] invia il primo contatto WA al lead
const mc = reply.match(/\[\[CONTATTA_LEAD\]\]\s*(\{[\s\S]*?\})/);
if (mc) {
  reply = reply.replace(mc[0], '').trim();

  let datiC = null;
  try { datiC = JSON.parse(mc[1]); } catch (e) { datiC = null; }

  if (datiC && datiC.session_id) {
    try {
      const out = await this.helpers.httpRequest({
        method: 'POST', url: BASE + '/ardy-lead-contatto.php',
        headers: { 'X-Ardy-Secret': SECRET, 'content-type': 'application/json' },
        body: { session_id: datiC.session_id },
        json: true, timeout: 20000,
      });
      if (out && out.success) {
        if (!reply) reply = out.riepilogo;
      } else {
        reply = (reply ? reply + '\n\n' : '') +
          '⚠️ Non sono riuscita a inviare il contatto: ' + ((out && out.error) || 'errore');
      }
    } catch (e) {
      reply = (reply ? reply + '\n\n' : '') +
        '⚠️ Errore nell\'invio del primo contatto al lead.';
    }
  }
}
// Rete di sicurezza: pulisci la risposta da eventuale sintassi tool prima di salvarla e inviarla
reply = ardyStripToolSyntax(reply);
if (!reply) reply = 'Un attimo che verifico e ti riscrivo subito 🙂';
// 4) Salva nello storico (utente + Sole)
try {
  await this.helpers.httpRequest({
    method: 'POST', url: BASE + '/ardy-wa-memoria.php',
    headers: { 'X-Ardy-Secret': SECRET, 'content-type': 'application/json' },
    body: { phone: from, messages: [ { role: 'user', content: message }, { role: 'assistant', content: reply } ] },
    json: true,
  });
} catch (e) {}

// 5) Invia su WhatsApp
await this.helpers.httpRequest({
  method: 'POST', url: `https://graph.facebook.com/v21.0/${PHONE_ID}/messages`,
  headers: { Authorization: `Bearer ${WA_TOKEN}`, 'content-type': 'application/json' },
  body: { messaging_product: 'whatsapp', to: from, type: 'text', text: { body: reply } },
  json: true,
});

return [{ json: { from, mode: look.mode, turni: msgs.length, reply } }];
