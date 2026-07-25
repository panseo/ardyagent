/*
 * Ardy Lab — Widget Chat "Consulenza Interior Design"
 * -----------------------------------------------------------
 * FONTE ATTIVA centralizzata: questo file è servito dal nostro server
 *   https://ardyagent.ardy-lab.it/ardy-chat-interior-design.js
 * e caricato dalla pagina WordPress ardy-lab.it/interior-design con un loader
 * di una riga (vedi wordpress-snippets/interior-design-page.html):
 *   <script src="https://ardyagent.ardy-lab.it/ardy-chat-interior-design.js"></script>
 *
 * Cosa fa: inietta una webchat autoportante (come "Galleria Diffusa") dove il
 * potenziale cliente della consulenza di interior design di Michela risponde a
 * Sole su stile, colori preferiti, luce e budget, oltre ai dati anagrafici. Sole
 * salva la scheda (tool salva_lead_crm) e ACCENDE la sezione dedicata nella
 * scheda cliente in dashboard (tool attiva_interior_design). Riusa il cervello
 * completo di Sole via ardy-proxy.php: nessuna modifica PHP per questo widget,
 * le regole vivono in ardy-system.txt (sezione "CONSULENZA INTERIOR DESIGN").
 *
 * Modificare QUI + deploy aggiorna il sito (niente copia-incolla in WPCode).
 *
 * Si attiva SOLO sulla pagina Interior Design: o l'URL contiene
 * "interior-design", oppure esiste in pagina un elemento #interior-design,
 * oppure è impostato window.ARDY_ID = true. Espone window.ardyIdOpen() così
 * un bottone CTA della pagina può aprire la chat.
 */
(function () {
  'use strict';

  var PROXY_URL = 'https://ardyagent.ardy-lab.it/ardy-proxy.php';
  var MICHELA_TEL = '+39 379 375 6437';
  var MAX_MSGS  = 30;

  var msgCount    = 0;
  var history     = [];
  var isOpen      = false;
  var chatStarted = false;
  var sessionId   = 'id_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

  // ── Si attiva solo sulla pagina Interior Design ──
  function shouldRun() {
    if (window.ARDY_ID === true) return true;
    if (document.getElementById('interior-design')) return true;
    return /interior-design/i.test(location.pathname);
  }

  // ── CSS (palette coerente con gli altri widget Ardy) ──
  function injectStyles() {
    var style = document.createElement('style');
    style.textContent = '' +
      '@import url("https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@400;500;600&display=swap");' +

      '#ardy-id-toggle {' +
        'position:fixed;bottom:24px;right:24px;z-index:9999;' +
        'height:60px;padding:0 22px;border-radius:30px;border:none;cursor:pointer;' +
        'background:linear-gradient(135deg,#c8a96e 0%,#a8864a 100%);' +
        'box-shadow:0 4px 20px rgba(200,169,110,0.4);' +
        'display:flex;align-items:center;gap:10px;' +
        'transition:all 0.3s cubic-bezier(0.4,0,0.2,1);' +
        'font-family:"DM Sans",sans-serif;font-size:15px;font-weight:600;color:#fff;' +
      '}' +
      '#ardy-id-toggle:hover { transform:scale(1.05);box-shadow:0 6px 28px rgba(200,169,110,0.55); }' +
      '#ardy-id-toggle .ardy-id-ico { font-size:22px;line-height:1; }' +

      '#ardy-id-panel {' +
        'position:fixed;bottom:96px;right:24px;z-index:9998;' +
        'width:400px;max-height:560px;' +
        'background:#faf9f6;border-radius:16px;' +
        'box-shadow:0 12px 48px rgba(0,0,0,0.15),0 2px 8px rgba(0,0,0,0.08);' +
        'display:none;flex-direction:column;overflow:hidden;' +
        'font-family:"DM Sans",sans-serif;' +
        'border:1px solid rgba(200,169,110,0.2);' +
        'opacity:0;transform:translateY(12px) scale(0.96);' +
        'transition:opacity 0.3s ease,transform 0.3s ease;' +
      '}' +
      '#ardy-id-panel.open { display:flex; }' +
      '#ardy-id-panel.visible { opacity:1;transform:translateY(0) scale(1); }' +

      '#ardy-id-header {' +
        'background:linear-gradient(135deg,#1a1a1a 0%,#2d2d2d 100%);' +
        'color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;' +
        'border-bottom:2px solid #c8a96e;' +
      '}' +
      '#ardy-id-header .ardy-h-ico { font-size:22px; }' +
      '#ardy-id-header h3 { margin:0;font-family:"Crimson Pro",serif;font-size:16px;font-weight:600;color:#c8a96e; }' +
      '#ardy-id-header p { margin:2px 0 0;font-size:11px;color:rgba(255,255,255,0.6); }' +
      '#ardy-id-close {' +
        'margin-left:auto;background:none;border:none;color:rgba(255,255,255,0.5);' +
        'font-size:20px;cursor:pointer;padding:0 4px;transition:color 0.2s;' +
      '}' +
      '#ardy-id-close:hover { color:#fff; }' +

      '#ardy-id-messages {' +
        'flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px;' +
        'max-height:360px;min-height:220px;scrollbar-width:thin;scrollbar-color:#c8a96e33 transparent;' +
      '}' +
      '.ardy-id-msg { display:flex;gap:8px;align-items:flex-start;animation:ardy-id-fade 0.3s ease; }' +
      '@keyframes ardy-id-fade { from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)} }' +
      '.ardy-id-msg.user { flex-direction:row-reverse; }' +
      '.ardy-id-avatar {' +
        'width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;' +
        'font-size:14px;flex-shrink:0;' +
      '}' +
      '.ardy-id-msg.agent .ardy-id-avatar { background:linear-gradient(135deg,#c8a96e,#a8864a); }' +
      '.ardy-id-msg.user .ardy-id-avatar { background:#e8e4de;font-size:11px;font-weight:600;color:#666; }' +
      '.ardy-id-bubble { max-width:80%;padding:10px 14px;font-size:14px;line-height:1.5;color:#333; }' +
      '.ardy-id-msg.agent .ardy-id-bubble { background:#fff;border:1px solid #e8e4de;border-radius:12px 12px 12px 4px; }' +
      '.ardy-id-msg.user .ardy-id-bubble {' +
        'background:linear-gradient(135deg,#c8a96e,#b89a5e);color:#fff;border-radius:12px 12px 4px 12px;' +
      '}' +
      '.ardy-id-bubble a { color:#8b7340;font-weight:600; }' +
      '.ardy-id-msg.user .ardy-id-bubble a { color:#fff;text-decoration:underline; }' +

      '.ardy-id-typing { display:flex;gap:4px;padding:4px 0; }' +
      '.ardy-id-dot { width:7px;height:7px;border-radius:50%;background:#c8a96e;animation:ardy-id-bounce 1.4s infinite; }' +
      '.ardy-id-dot:nth-child(2){animation-delay:0.15s}' +
      '.ardy-id-dot:nth-child(3){animation-delay:0.3s}' +
      '@keyframes ardy-id-bounce { 0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)} }' +

      '#ardy-id-suggestions { display:flex;flex-wrap:wrap;gap:6px;padding:0 16px 12px; }' +
      '.ardy-id-sugg {' +
        'background:#fff;border:1px solid #c8a96e44;border-radius:20px;padding:6px 14px;' +
        'font-size:12px;color:#8b7340;cursor:pointer;font-family:"DM Sans",sans-serif;transition:all 0.2s;' +
      '}' +
      '.ardy-id-sugg:hover { background:#c8a96e;color:#fff;border-color:#c8a96e; }' +

      '#ardy-id-inputbar { display:flex;gap:8px;padding:12px 16px;border-top:1px solid #e8e4de;background:#fff; }' +
      '#ardy-id-input {' +
        'flex:1;border:1px solid #ddd;border-radius:8px;padding:10px 14px;font-size:14px;' +
        'font-family:"DM Sans",sans-serif;outline:none;background:#faf9f6;color:#333;transition:border-color 0.2s;' +
      '}' +
      '#ardy-id-input:focus { border-color:#c8a96e; }' +
      '#ardy-id-input::placeholder { color:#aaa; }' +
      '#ardy-id-input:disabled { background:#f0f0f0;color:#999; }' +
      '#ardy-id-send {' +
        'background:linear-gradient(135deg,#c8a96e,#a8864a);border:none;border-radius:8px;padding:0 16px;' +
        'cursor:pointer;color:#fff;font-size:16px;transition:all 0.2s;display:flex;align-items:center;justify-content:center;' +
      '}' +
      '#ardy-id-send:hover { transform:scale(1.05); }' +
      '#ardy-id-send:disabled { opacity:0.5;cursor:default;transform:none; }' +

      '@media(max-width:480px) {' +
        '#ardy-id-panel { width:calc(100vw - 16px);right:8px;bottom:88px;max-height:72vh; }' +
        '#ardy-id-toggle { bottom:16px;right:16px;padding:0 18px;font-size:14px; }' +
      '}';
    document.head.appendChild(style);
  }

  // ── HTML ──
  function injectHTML() {
    var toggle = document.createElement('button');
    toggle.id = 'ardy-id-toggle';
    toggle.innerHTML = '<span class="ardy-id-ico">🛋️</span><span>Parla con Sole</span>';
    toggle.title = 'Chiedi a Sole una consulenza di interior design';
    toggle.onclick = togglePanel;
    document.body.appendChild(toggle);

    var panel = document.createElement('div');
    panel.id = 'ardy-id-panel';
    panel.innerHTML = '' +
      '<div id="ardy-id-header">' +
        '<span class="ardy-h-ico">🛋️</span>' +
        '<div>' +
          '<h3>Consulenza Interior Design</h3>' +
          '<p>Raccontala a Sole: stile, colori, luce e budget</p>' +
        '</div>' +
        '<button id="ardy-id-close">×</button>' +
      '</div>' +
      '<div id="ardy-id-messages"></div>' +
      '<div id="ardy-id-suggestions"></div>' +
      '<div id="ardy-id-inputbar">' +
        '<input type="text" id="ardy-id-input" placeholder="Scrivi la tua domanda..." />' +
        '<button id="ardy-id-send">→</button>' +
      '</div>';
    document.body.appendChild(panel);

    document.getElementById('ardy-id-close').onclick = closePanel;
    document.getElementById('ardy-id-send').onclick = sendMessage;
    document.getElementById('ardy-id-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') sendMessage();
    });
  }

  function startChat() {
    if (chatStarted) return;
    chatStarted = true;
    // La chat parte con un saluto di Sole già "incorniciato" sulla consulenza di
    // interior design. Mettendolo anche nella history, il modello mantiene il
    // contesto per tutte le risposte successive (anche con il system prompt dedicato).
    var greet = 'Ciao! Sono Sole, l\'assistente virtuale (AI) di Ardy Lab 👋 Vedo che ti interessa una ' +
      'consulenza di interior design con Michela. Raccontami un po\': che ambiente vuoi arredare o rinnovare?';
    addMessage(greet, 'agent');
    history.push({ role: 'assistant', content: greet });
    initSuggestions([
      'Vorrei rinnovare il soggiorno',
      'Non so ancora che stile mi piace',
      'Ho già un\'idea di budget',
      'Vorrei fissare un incontro con Michela'
    ]);
  }

  function togglePanel() {
    var panel = document.getElementById('ardy-id-panel');
    if (!isOpen) {
      panel.classList.add('open');
      setTimeout(function () { panel.classList.add('visible'); }, 10);
      startChat();
      setTimeout(function () { document.getElementById('ardy-id-input').focus(); }, 300);
      isOpen = true;
    } else {
      closePanel();
    }
  }

  function closePanel() {
    var panel = document.getElementById('ardy-id-panel');
    panel.classList.remove('visible');
    setTimeout(function () { panel.classList.remove('open'); }, 300);
    isOpen = false;
  }

  function initSuggestions(suggestions) {
    var suggEl = document.getElementById('ardy-id-suggestions');
    suggEl.innerHTML = '';
    suggestions.forEach(function (text) {
      var btn = document.createElement('button');
      btn.className = 'ardy-id-sugg';
      btn.textContent = text;
      btn.onclick = function () {
        document.getElementById('ardy-id-input').value = text;
        sendMessage();
        suggEl.style.display = 'none';
      };
      suggEl.appendChild(btn);
    });
  }

  function addMessage(text, role) {
    var container = document.getElementById('ardy-id-messages');
    var row = document.createElement('div');
    row.className = 'ardy-id-msg ' + role;
    var avatar = document.createElement('div');
    avatar.className = 'ardy-id-avatar';
    avatar.textContent = role === 'agent' ? '🛋️' : 'Tu';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-id-bubble';
    bubble.textContent = text;
    row.appendChild(avatar);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
  }

  function showTyping() {
    var container = document.getElementById('ardy-id-messages');
    var row = document.createElement('div');
    row.className = 'ardy-id-msg agent';
    row.id = 'ardy-id-typing';
    var avatar = document.createElement('div');
    avatar.className = 'ardy-id-avatar';
    avatar.textContent = '🛋️';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-id-bubble';
    bubble.innerHTML = '<div class="ardy-id-typing"><div class="ardy-id-dot"></div><div class="ardy-id-dot"></div><div class="ardy-id-dot"></div></div>';
    row.appendChild(avatar);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
  }

  function hideTyping() {
    var el = document.getElementById('ardy-id-typing');
    if (el) el.remove();
  }

  function disableInput() {
    var input = document.getElementById('ardy-id-input');
    var send = document.getElementById('ardy-id-send');
    input.disabled = true;
    input.placeholder = 'Conversazione terminata';
    send.disabled = true;
    addMessage('Per ora ci fermiamo qui 🌿 Per continuare scrivi o chiama Michela al ' + MICHELA_TEL + ': sarà felice di parlartene.', 'agent');
  }

  async function sendMessage() {
    if (!chatStarted) startChat();
    if (msgCount >= MAX_MSGS) { disableInput(); return; }

    var input = document.getElementById('ardy-id-input');
    var text = input.value.trim();
    if (!text) return;

    input.value = '';
    addMessage(text, 'user');
    msgCount++;

    var suggEl = document.getElementById('ardy-id-suggestions');
    if (suggEl) suggEl.style.display = 'none';

    history.push({ role: 'user', content: text });
    showTyping();

    try {
      var res = await fetch(PROXY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, history: history, images: [], sessionId: sessionId })
      });
      var data = await res.json();
      hideTyping();
      var reply = data.reply || 'Scusa, non sono riuscita a rispondere. Riprova!';
      history.push({ role: 'assistant', content: reply });
      addMessage(reply, 'agent');
      if (msgCount >= MAX_MSGS) disableInput();
    } catch (e) {
      hideTyping();
      history.pop();
      msgCount--;
      addMessage('Problema di connessione. Riprova tra un momento.', 'agent');
    }
  }

  // ── INIT ──
  function init() {
    if (!shouldRun()) return;
    injectStyles();
    injectHTML();
    // Apertura programmatica da un bottone CTA della pagina.
    window.ardyIdOpen = function () { if (!isOpen) togglePanel(); };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
