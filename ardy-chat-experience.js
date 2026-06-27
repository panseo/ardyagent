/*
 * Ardy Lab — Widget Chat "Ardy Experience" (per i B&B partner)
 * -----------------------------------------------------------
 * FONTE ATTIVA centralizzata: questo file è servito dal nostro server
 *   https://ardyagent.ardy-lab.it/ardy-chat-experience.js
 * e caricato dalla pagina WordPress ardy-lab.it/ardy-experience con un loader
 * di una riga (vedi wordpress-snippets/ardy-experience-page.html):
 *   <script src="https://ardyagent.ardy-lab.it/ardy-chat-experience.js"></script>
 *
 * Cosa fa: inietta una webchat autoportante (come il widget "Lavori in corso")
 * dove il titolare di un B&B può chiedere info su Ardy Experience, ricevere
 * delucidazioni da Sole, essere invitato a contattare Michela e FISSARE un
 * appuntamento in laboratorio. Riusa il cervello completo di Sole e i tool
 * calendario/CRM via ardy-proxy.php (nessuna modifica PHP).
 *
 * Modificare QUI + deploy aggiorna il sito (niente copia-incolla in WPCode).
 *
 * Si attiva SOLO sulla pagina Ardy Experience: o l'URL contiene
 * "ardy-experience", oppure esiste in pagina un elemento #ardy-experience,
 * oppure è impostato window.ARDY_XP = true. Espone window.ardyXpOpen() così
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
  var sessionId   = 'xp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

  // ── Si attiva solo sulla pagina Ardy Experience ──
  function shouldRun() {
    if (window.ARDY_XP === true) return true;
    if (document.getElementById('ardy-experience')) return true;
    return /ardy-experience/i.test(location.pathname);
  }

  // ── CSS (palette coerente con gli altri widget Ardy) ──
  function injectStyles() {
    var style = document.createElement('style');
    style.textContent = '' +
      '@import url("https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@400;500;600&display=swap");' +

      '#ardy-xp-toggle {' +
        'position:fixed;bottom:24px;right:24px;z-index:9999;' +
        'height:60px;padding:0 22px;border-radius:30px;border:none;cursor:pointer;' +
        'background:linear-gradient(135deg,#c8a96e 0%,#a8864a 100%);' +
        'box-shadow:0 4px 20px rgba(200,169,110,0.4);' +
        'display:flex;align-items:center;gap:10px;' +
        'transition:all 0.3s cubic-bezier(0.4,0,0.2,1);' +
        'font-family:"DM Sans",sans-serif;font-size:15px;font-weight:600;color:#fff;' +
      '}' +
      '#ardy-xp-toggle:hover { transform:scale(1.05);box-shadow:0 6px 28px rgba(200,169,110,0.55); }' +
      '#ardy-xp-toggle .ardy-xp-ico { font-size:22px;line-height:1; }' +

      '#ardy-xp-panel {' +
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
      '#ardy-xp-panel.open { display:flex; }' +
      '#ardy-xp-panel.visible { opacity:1;transform:translateY(0) scale(1); }' +

      '#ardy-xp-header {' +
        'background:linear-gradient(135deg,#1a1a1a 0%,#2d2d2d 100%);' +
        'color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;' +
        'border-bottom:2px solid #c8a96e;' +
      '}' +
      '#ardy-xp-header .ardy-h-ico { font-size:22px; }' +
      '#ardy-xp-header h3 { margin:0;font-family:"Crimson Pro",serif;font-size:16px;font-weight:600;color:#c8a96e; }' +
      '#ardy-xp-header p { margin:2px 0 0;font-size:11px;color:rgba(255,255,255,0.6); }' +
      '#ardy-xp-close {' +
        'margin-left:auto;background:none;border:none;color:rgba(255,255,255,0.5);' +
        'font-size:20px;cursor:pointer;padding:0 4px;transition:color 0.2s;' +
      '}' +
      '#ardy-xp-close:hover { color:#fff; }' +

      '#ardy-xp-messages {' +
        'flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px;' +
        'max-height:360px;min-height:220px;scrollbar-width:thin;scrollbar-color:#c8a96e33 transparent;' +
      '}' +
      '.ardy-xp-msg { display:flex;gap:8px;align-items:flex-start;animation:ardy-xp-fade 0.3s ease; }' +
      '@keyframes ardy-xp-fade { from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)} }' +
      '.ardy-xp-msg.user { flex-direction:row-reverse; }' +
      '.ardy-xp-avatar {' +
        'width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;' +
        'font-size:14px;flex-shrink:0;' +
      '}' +
      '.ardy-xp-msg.agent .ardy-xp-avatar { background:linear-gradient(135deg,#c8a96e,#a8864a); }' +
      '.ardy-xp-msg.user .ardy-xp-avatar { background:#e8e4de;font-size:11px;font-weight:600;color:#666; }' +
      '.ardy-xp-bubble { max-width:80%;padding:10px 14px;font-size:14px;line-height:1.5;color:#333; }' +
      '.ardy-xp-msg.agent .ardy-xp-bubble { background:#fff;border:1px solid #e8e4de;border-radius:12px 12px 12px 4px; }' +
      '.ardy-xp-msg.user .ardy-xp-bubble {' +
        'background:linear-gradient(135deg,#c8a96e,#b89a5e);color:#fff;border-radius:12px 12px 4px 12px;' +
      '}' +
      '.ardy-xp-bubble a { color:#8b7340;font-weight:600; }' +
      '.ardy-xp-msg.user .ardy-xp-bubble a { color:#fff;text-decoration:underline; }' +

      '.ardy-xp-typing { display:flex;gap:4px;padding:4px 0; }' +
      '.ardy-xp-dot { width:7px;height:7px;border-radius:50%;background:#c8a96e;animation:ardy-xp-bounce 1.4s infinite; }' +
      '.ardy-xp-dot:nth-child(2){animation-delay:0.15s}' +
      '.ardy-xp-dot:nth-child(3){animation-delay:0.3s}' +
      '@keyframes ardy-xp-bounce { 0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)} }' +

      '#ardy-xp-suggestions { display:flex;flex-wrap:wrap;gap:6px;padding:0 16px 12px; }' +
      '.ardy-xp-sugg {' +
        'background:#fff;border:1px solid #c8a96e44;border-radius:20px;padding:6px 14px;' +
        'font-size:12px;color:#8b7340;cursor:pointer;font-family:"DM Sans",sans-serif;transition:all 0.2s;' +
      '}' +
      '.ardy-xp-sugg:hover { background:#c8a96e;color:#fff;border-color:#c8a96e; }' +

      '#ardy-xp-inputbar { display:flex;gap:8px;padding:12px 16px;border-top:1px solid #e8e4de;background:#fff; }' +
      '#ardy-xp-input {' +
        'flex:1;border:1px solid #ddd;border-radius:8px;padding:10px 14px;font-size:14px;' +
        'font-family:"DM Sans",sans-serif;outline:none;background:#faf9f6;color:#333;transition:border-color 0.2s;' +
      '}' +
      '#ardy-xp-input:focus { border-color:#c8a96e; }' +
      '#ardy-xp-input::placeholder { color:#aaa; }' +
      '#ardy-xp-input:disabled { background:#f0f0f0;color:#999; }' +
      '#ardy-xp-send {' +
        'background:linear-gradient(135deg,#c8a96e,#a8864a);border:none;border-radius:8px;padding:0 16px;' +
        'cursor:pointer;color:#fff;font-size:16px;transition:all 0.2s;display:flex;align-items:center;justify-content:center;' +
      '}' +
      '#ardy-xp-send:hover { transform:scale(1.05); }' +
      '#ardy-xp-send:disabled { opacity:0.5;cursor:default;transform:none; }' +

      '@media(max-width:480px) {' +
        '#ardy-xp-panel { width:calc(100vw - 16px);right:8px;bottom:88px;max-height:72vh; }' +
        '#ardy-xp-toggle { bottom:16px;right:16px;padding:0 18px;font-size:14px; }' +
      '}';
    document.head.appendChild(style);
  }

  // ── HTML ──
  function injectHTML() {
    var toggle = document.createElement('button');
    toggle.id = 'ardy-xp-toggle';
    toggle.innerHTML = '<span class="ardy-xp-ico">✨</span><span>Parla di Ardy Experience</span>';
    toggle.title = 'Chiedi info su Ardy Experience per il tuo B&B';
    toggle.onclick = togglePanel;
    document.body.appendChild(toggle);

    var panel = document.createElement('div');
    panel.id = 'ardy-xp-panel';
    panel.innerHTML = '' +
      '<div id="ardy-xp-header">' +
        '<span class="ardy-h-ico">✨</span>' +
        '<div>' +
          '<h3>Ardy Experience — per il tuo B&B</h3>' +
          '<p>Chiedi a Sole come funziona la collaborazione</p>' +
        '</div>' +
        '<button id="ardy-xp-close">×</button>' +
      '</div>' +
      '<div id="ardy-xp-messages"></div>' +
      '<div id="ardy-xp-suggestions"></div>' +
      '<div id="ardy-xp-inputbar">' +
        '<input type="text" id="ardy-xp-input" placeholder="Scrivi la tua domanda..." />' +
        '<button id="ardy-xp-send">→</button>' +
      '</div>';
    document.body.appendChild(panel);

    document.getElementById('ardy-xp-close').onclick = closePanel;
    document.getElementById('ardy-xp-send').onclick = sendMessage;
    document.getElementById('ardy-xp-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') sendMessage();
    });
  }

  function startChat() {
    if (chatStarted) return;
    chatStarted = true;
    // La chat parte con un saluto di Sole già "incorniciato" su Ardy Experience/B&B.
    // Mettendolo anche nella history, il modello mantiene il contesto partner per
    // tutte le risposte successive (anche con il system prompt dedicato).
    var greet = 'Ciao! Sono Sole di Ardy Lab ✨ Mi fa piacere che tu stia pensando ad ' +
      'Ardy Experience per il tuo B&B. Dimmi pure: vuoi capire come funziona, ' +
      'le commissioni, o preferisci fissare un appuntamento da noi in laboratorio per ' +
      'vedere gli oggetti dal vivo?';
    addMessage(greet, 'agent');
    history.push({ role: 'assistant', content: greet });
    initSuggestions([
      'Come funziona Ardy Experience?',
      'Quanto guadagna il B&B?',
      'Vorrei fissare un appuntamento in laboratorio',
      'Posso parlare con Michela?'
    ]);
  }

  function togglePanel() {
    var panel = document.getElementById('ardy-xp-panel');
    if (!isOpen) {
      panel.classList.add('open');
      setTimeout(function () { panel.classList.add('visible'); }, 10);
      startChat();
      setTimeout(function () { document.getElementById('ardy-xp-input').focus(); }, 300);
      isOpen = true;
    } else {
      closePanel();
    }
  }

  function closePanel() {
    var panel = document.getElementById('ardy-xp-panel');
    panel.classList.remove('visible');
    setTimeout(function () { panel.classList.remove('open'); }, 300);
    isOpen = false;
  }

  function initSuggestions(suggestions) {
    var suggEl = document.getElementById('ardy-xp-suggestions');
    suggEl.innerHTML = '';
    suggestions.forEach(function (text) {
      var btn = document.createElement('button');
      btn.className = 'ardy-xp-sugg';
      btn.textContent = text;
      btn.onclick = function () {
        document.getElementById('ardy-xp-input').value = text;
        sendMessage();
        suggEl.style.display = 'none';
      };
      suggEl.appendChild(btn);
    });
  }

  function addMessage(text, role) {
    var container = document.getElementById('ardy-xp-messages');
    var row = document.createElement('div');
    row.className = 'ardy-xp-msg ' + role;
    var avatar = document.createElement('div');
    avatar.className = 'ardy-xp-avatar';
    avatar.textContent = role === 'agent' ? '✨' : 'Tu';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-xp-bubble';
    bubble.textContent = text;
    row.appendChild(avatar);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
  }

  function showTyping() {
    var container = document.getElementById('ardy-xp-messages');
    var row = document.createElement('div');
    row.className = 'ardy-xp-msg agent';
    row.id = 'ardy-xp-typing';
    var avatar = document.createElement('div');
    avatar.className = 'ardy-xp-avatar';
    avatar.textContent = '✨';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-xp-bubble';
    bubble.innerHTML = '<div class="ardy-xp-typing"><div class="ardy-xp-dot"></div><div class="ardy-xp-dot"></div><div class="ardy-xp-dot"></div></div>';
    row.appendChild(avatar);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
  }

  function hideTyping() {
    var el = document.getElementById('ardy-xp-typing');
    if (el) el.remove();
  }

  function disableInput() {
    var input = document.getElementById('ardy-xp-input');
    var send = document.getElementById('ardy-xp-send');
    input.disabled = true;
    input.placeholder = 'Conversazione terminata';
    send.disabled = true;
    addMessage('Per ora ci fermiamo qui 🌿 Per continuare scrivi o chiama Michela al ' + MICHELA_TEL + ': sarà felice di parlartene.', 'agent');
  }

  async function sendMessage() {
    if (!chatStarted) startChat();
    if (msgCount >= MAX_MSGS) { disableInput(); return; }

    var input = document.getElementById('ardy-xp-input');
    var text = input.value.trim();
    if (!text) return;

    input.value = '';
    addMessage(text, 'user');
    msgCount++;

    var suggEl = document.getElementById('ardy-xp-suggestions');
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
    window.ardyXpOpen = function () { if (!isOpen) togglePanel(); };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
