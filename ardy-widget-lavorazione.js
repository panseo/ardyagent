// -----------------------------------------------------------
// ARDY LAB — Widget Chat Lavorazione v2
// Con verifica cliente prima di aprire la chat
// -----------------------------------------------------------
(function () {
  'use strict';

  var PROXY_URL  = 'https://ardyagent.ardy-lab.it/ardy-proxy-lavorazione.php';
  var VERIFY_URL = 'https://ardyagent.ardy-lab.it/ardy-verify-client.php';
  var MAX_MSGS   = 20;
  var msgCount   = 0;
  var history    = [];
  var isOpen     = false;
  var isVerified = false;
  var clientName = '';
  var pageContext = '';
  var pageTitle   = '';
  var wpPostId    = '';

  // ── ESTRAI CONTESTO E POST ID ──
  function extractContext() {
    var h1 = document.querySelector('.entry-title') || document.querySelector('h1');
    pageTitle = h1 ? h1.textContent.trim() : document.title;

    var fasi = document.querySelectorAll('[style*="border-left"]');
    var testi = [];
    fasi.forEach(function (el) { testi.push(el.textContent.trim()); });

    if (!testi.length) {
      var article = document.querySelector('.entry-content') || document.querySelector('article');
      if (article) testi.push(article.textContent.trim().substring(0, 3000));
    }
    pageContext = testi.join('\n\n').substring(0, 4000);

    // Estrai wp_post_id dal body class
    var match = document.body.className.match(/postid-(\d+)/);
    wpPostId = match ? match[1] : '';
  }

  // ── CSS ──
  function injectStyles() {
    var style = document.createElement('style');
    style.textContent = '' +
      '@import url("https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@400;500;600&display=swap");' +

      '#ardy-lav-toggle {' +
        'position:fixed;bottom:24px;right:24px;z-index:9999;' +
        'width:60px;height:60px;border-radius:50%;border:none;cursor:pointer;' +
        'background:linear-gradient(135deg,#c8a96e 0%,#a8864a 100%);' +
        'box-shadow:0 4px 20px rgba(200,169,110,0.4);' +
        'display:flex;align-items:center;justify-content:center;' +
        'transition:all 0.3s cubic-bezier(0.4,0,0.2,1);' +
        'font-size:24px;line-height:1;' +
      '}' +
      '#ardy-lav-toggle:hover {' +
        'transform:scale(1.08);box-shadow:0 6px 28px rgba(200,169,110,0.55);' +
      '}' +
      '#ardy-lav-toggle .ardy-badge {' +
        'position:absolute;top:-2px;right:-2px;' +
        'background:#e74c3c;color:#fff;font-size:11px;font-weight:600;' +
        'width:20px;height:20px;border-radius:50%;' +
        'display:flex;align-items:center;justify-content:center;' +
        'font-family:"DM Sans",sans-serif;' +
        'animation:ardy-pulse 2s infinite;' +
      '}' +
      '@keyframes ardy-pulse {' +
        '0%,100%{transform:scale(1)}50%{transform:scale(1.15)}' +
      '}' +

      '#ardy-lav-panel {' +
        'position:fixed;bottom:96px;right:24px;z-index:9998;' +
        'width:380px;max-height:520px;' +
        'background:#faf9f6;' +
        'border-radius:16px;' +
        'box-shadow:0 12px 48px rgba(0,0,0,0.15),0 2px 8px rgba(0,0,0,0.08);' +
        'display:none;flex-direction:column;overflow:hidden;' +
        'font-family:"DM Sans",sans-serif;' +
        'border:1px solid rgba(200,169,110,0.2);' +
        'opacity:0;transform:translateY(12px) scale(0.96);' +
        'transition:opacity 0.3s ease,transform 0.3s ease;' +
      '}' +
      '#ardy-lav-panel.open { display:flex; }' +
      '#ardy-lav-panel.visible { opacity:1;transform:translateY(0) scale(1); }' +

      '#ardy-lav-header {' +
        'background:linear-gradient(135deg,#1a1a1a 0%,#2d2d2d 100%);' +
        'color:#fff;padding:16px 20px;' +
        'display:flex;align-items:center;gap:12px;' +
        'border-bottom:2px solid #c8a96e;' +
      '}' +
      '#ardy-lav-header .ardy-h-icon { font-size:22px; }' +
      '#ardy-lav-header .ardy-h-text h3 {' +
        'margin:0;font-family:"Crimson Pro",serif;font-size:16px;font-weight:600;color:#c8a96e;' +
      '}' +
      '#ardy-lav-header .ardy-h-text p {' +
        'margin:2px 0 0;font-size:11px;color:rgba(255,255,255,0.6);' +
      '}' +
      '#ardy-lav-close {' +
        'margin-left:auto;background:none;border:none;color:rgba(255,255,255,0.5);' +
        'font-size:20px;cursor:pointer;padding:0 4px;transition:color 0.2s;' +
      '}' +
      '#ardy-lav-close:hover { color:#fff; }' +

      // Verify screen
      '#ardy-lav-verify {' +
        'padding:24px 20px;text-align:center;' +
      '}' +
      '#ardy-lav-verify .ardy-v-icon { font-size:40px;margin-bottom:12px; }' +
      '#ardy-lav-verify .ardy-v-title {' +
        'font-family:"Crimson Pro",serif;font-size:18px;font-weight:600;color:#333;margin-bottom:8px;' +
      '}' +
      '#ardy-lav-verify .ardy-v-desc {' +
        'font-size:13px;color:#888;margin-bottom:20px;line-height:1.5;' +
      '}' +
      '#ardy-lav-verify input {' +
        'width:100%;border:1px solid #ddd;border-radius:8px;padding:12px 14px;' +
        'font-size:15px;font-family:"DM Sans",sans-serif;outline:none;' +
        'text-align:center;letter-spacing:1px;box-sizing:border-box;' +
        'transition:border-color 0.2s;' +
      '}' +
      '#ardy-lav-verify input:focus { border-color:#c8a96e; }' +
      '#ardy-lav-verify .ardy-v-btn {' +
        'margin-top:14px;width:100%;padding:12px;border:none;border-radius:8px;' +
        'background:linear-gradient(135deg,#c8a96e,#a8864a);color:#fff;' +
        'font-size:14px;font-weight:600;cursor:pointer;font-family:"DM Sans",sans-serif;' +
        'transition:all 0.2s;' +
      '}' +
      '#ardy-lav-verify .ardy-v-btn:hover { transform:scale(1.02); }' +
      '#ardy-lav-verify .ardy-v-btn:disabled { opacity:0.5;cursor:default;transform:none; }' +
      '#ardy-lav-verify .ardy-v-error {' +
        'color:#e74c3c;font-size:12px;margin-top:10px;display:none;' +
      '}' +
      '#ardy-lav-verify .ardy-v-hint {' +
        'font-size:11px;color:#aaa;margin-top:12px;' +
      '}' +

      // Chat area
      '#ardy-lav-chatarea { display:none;flex-direction:column;flex:1;overflow:hidden; }' +
      '#ardy-lav-chatarea.active { display:flex; }' +

      '#ardy-lav-messages {' +
        'flex:1;overflow-y:auto;padding:16px;' +
        'display:flex;flex-direction:column;gap:12px;' +
        'max-height:340px;min-height:200px;' +
        'scrollbar-width:thin;scrollbar-color:#c8a96e33 transparent;' +
      '}' +

      '.ardy-lav-msg {' +
        'display:flex;gap:8px;align-items:flex-start;' +
        'animation:ardy-fadeIn 0.3s ease;' +
      '}' +
      '@keyframes ardy-fadeIn {' +
        'from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}' +
      '}' +
      '.ardy-lav-msg.user { flex-direction:row-reverse; }' +

      '.ardy-lav-avatar {' +
        'width:30px;height:30px;border-radius:50%;' +
        'display:flex;align-items:center;justify-content:center;' +
        'font-size:14px;flex-shrink:0;' +
      '}' +
      '.ardy-lav-msg.agent .ardy-lav-avatar {' +
        'background:linear-gradient(135deg,#c8a96e,#a8864a);' +
      '}' +
      '.ardy-lav-msg.user .ardy-lav-avatar {' +
        'background:#e8e4de;font-size:11px;font-weight:600;color:#666;' +
      '}' +

      '.ardy-lav-bubble {' +
        'max-width:80%;padding:10px 14px;border-radius:12px;' +
        'font-size:14px;line-height:1.5;color:#333;' +
      '}' +
      '.ardy-lav-msg.agent .ardy-lav-bubble {' +
        'background:#fff;border:1px solid #e8e4de;border-radius:12px 12px 12px 4px;' +
      '}' +
      '.ardy-lav-msg.user .ardy-lav-bubble {' +
        'background:linear-gradient(135deg,#c8a96e,#b89a5e);color:#fff;' +
        'border-radius:12px 12px 4px 12px;' +
      '}' +

      '.ardy-lav-typing { display:flex;gap:4px;padding:4px 0; }' +
      '.ardy-lav-dot {' +
        'width:7px;height:7px;border-radius:50%;background:#c8a96e;' +
        'animation:ardy-bounce 1.4s infinite;' +
      '}' +
      '.ardy-lav-dot:nth-child(2){animation-delay:0.15s}' +
      '.ardy-lav-dot:nth-child(3){animation-delay:0.3s}' +
      '@keyframes ardy-bounce {' +
        '0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)}' +
      '}' +

      '#ardy-lav-inputbar {' +
        'display:flex;gap:8px;padding:12px 16px;' +
        'border-top:1px solid #e8e4de;background:#fff;' +
      '}' +
      '#ardy-lav-input {' +
        'flex:1;border:1px solid #ddd;border-radius:8px;padding:10px 14px;' +
        'font-size:14px;font-family:"DM Sans",sans-serif;' +
        'outline:none;background:#faf9f6;color:#333;' +
        'transition:border-color 0.2s;' +
      '}' +
      '#ardy-lav-input:focus { border-color:#c8a96e; }' +
      '#ardy-lav-input::placeholder { color:#aaa; }' +
      '#ardy-lav-input:disabled { background:#f0f0f0;color:#999; }' +

      '#ardy-lav-send {' +
        'background:linear-gradient(135deg,#c8a96e,#a8864a);' +
        'border:none;border-radius:8px;padding:0 16px;cursor:pointer;' +
        'color:#fff;font-size:16px;transition:all 0.2s;' +
        'display:flex;align-items:center;justify-content:center;' +
      '}' +
      '#ardy-lav-send:hover { transform:scale(1.05); }' +
      '#ardy-lav-send:disabled { opacity:0.5;cursor:default;transform:none; }' +

      '#ardy-lav-suggestions {' +
        'display:flex;flex-wrap:wrap;gap:6px;padding:0 16px 12px;' +
      '}' +
      '.ardy-lav-sugg {' +
        'background:#fff;border:1px solid #c8a96e44;border-radius:20px;' +
        'padding:6px 14px;font-size:12px;color:#8b7340;cursor:pointer;' +
        'font-family:"DM Sans",sans-serif;transition:all 0.2s;' +
      '}' +
      '.ardy-lav-sugg:hover {' +
        'background:#c8a96e;color:#fff;border-color:#c8a96e;' +
      '}' +

      '@media(max-width:480px) {' +
        '#ardy-lav-panel {' +
          'width:calc(100vw - 16px);right:8px;bottom:88px;max-height:70vh;' +
        '}' +
        '#ardy-lav-toggle { bottom:16px;right:16px;width:54px;height:54px; }' +
      '}';

    document.head.appendChild(style);
  }

  // ── HTML ──
  function injectHTML() {
    // Toggle button
    var toggle = document.createElement('button');
    toggle.id = 'ardy-lav-toggle';
    toggle.innerHTML = '🪑<span class="ardy-badge">?</span>';
    toggle.title = 'Chiedimi della tua lavorazione';
    toggle.onclick = togglePanel;
    document.body.appendChild(toggle);

    // Panel
    var panel = document.createElement('div');
    panel.id = 'ardy-lav-panel';
    panel.innerHTML = '' +
      // Header
      '<div id="ardy-lav-header">' +
        '<span class="ardy-h-icon">🪑</span>' +
        '<div class="ardy-h-text">' +
          '<h3>Ardy — La tua lavorazione</h3>' +
          '<p>Chiedimi qualsiasi cosa su questo lavoro</p>' +
        '</div>' +
        '<button id="ardy-lav-close">×</button>' +
      '</div>' +

      // Verify screen
      '<div id="ardy-lav-verify">' +
        '<div class="ardy-v-icon">🔐</div>' +
        '<div class="ardy-v-title">Verifica la tua identità</div>' +
        '<div class="ardy-v-desc">Per accedere alla chat sulla tua lavorazione, inserisci il numero di telefono che hai fornito quando hai richiesto il servizio.</div>' +
        '<input type="tel" id="ardy-lav-tel" placeholder="Es: 333 1234567" />' +
        '<button class="ardy-v-btn" id="ardy-lav-verify-btn">Verifica</button>' +
        '<div class="ardy-v-error" id="ardy-lav-verify-error"></div>' +
        '<div class="ardy-v-hint">Lo trovi sul preventivo o nella email di conferma</div>' +
      '</div>' +

      // Chat area (hidden until verified)
      '<div id="ardy-lav-chatarea">' +
        '<div id="ardy-lav-messages"></div>' +
        '<div id="ardy-lav-suggestions"></div>' +
        '<div id="ardy-lav-inputbar">' +
          '<input type="text" id="ardy-lav-input" placeholder="Scrivi la tua domanda..." />' +
          '<button id="ardy-lav-send">→</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(panel);

    // Events
    document.getElementById('ardy-lav-close').onclick = closePanel;
    document.getElementById('ardy-lav-verify-btn').onclick = verifyClient;
    document.getElementById('ardy-lav-tel').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') verifyClient();
    });
    document.getElementById('ardy-lav-send').onclick = sendMessage;
    document.getElementById('ardy-lav-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') sendMessage();
    });
  }

  function togglePanel() {
    var panel = document.getElementById('ardy-lav-panel');
    var badge = document.querySelector('#ardy-lav-toggle .ardy-badge');
    if (!isOpen) {
      panel.classList.add('open');
      setTimeout(function () { panel.classList.add('visible'); }, 10);
      if (badge) badge.style.display = 'none';
      if (isVerified) {
        document.getElementById('ardy-lav-input').focus();
      } else {
        document.getElementById('ardy-lav-tel').focus();
      }
      isOpen = true;
    } else {
      closePanel();
    }
  }

  function closePanel() {
    var panel = document.getElementById('ardy-lav-panel');
    panel.classList.remove('visible');
    setTimeout(function () { panel.classList.remove('open'); }, 300);
    isOpen = false;
  }

  // ── VERIFICA CLIENTE ──
  async function verifyClient() {
    var tel = document.getElementById('ardy-lav-tel').value.trim();
    var errorEl = document.getElementById('ardy-lav-verify-error');
    var btn = document.getElementById('ardy-lav-verify-btn');

    if (!tel || tel.length < 6) {
      errorEl.textContent = 'Inserisci un numero di telefono valido';
      errorEl.style.display = 'block';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Verifico...';
    errorEl.style.display = 'none';

    try {
      var res = await fetch(VERIFY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ telefono: tel, wp_post_id: wpPostId })
      });
      var data = await res.json();

      if (data.verified) {
        isVerified = true;
        clientName = data.nome || '';

        // Nascondi verifica, mostra chat
        document.getElementById('ardy-lav-verify').style.display = 'none';
        document.getElementById('ardy-lav-chatarea').classList.add('active');

        // Messaggio di benvenuto personalizzato
        var welcomeName = clientName ? clientName.split(' ')[0] : '';
        addMessage('Ciao' + (welcomeName ? ' ' + welcomeName : '') + '! Sono Sole, sono qui per aiutarti con la tua lavorazione. Chiedimi pure!', 'agent');

        // Suggerimenti
        initSuggestions();

        setTimeout(function () {
          document.getElementById('ardy-lav-input').focus();
        }, 300);
      } else {
        errorEl.textContent = data.error || 'Numero non riconosciuto. Controlla e riprova.';
        errorEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Verifica';
      }
    } catch (e) {
      errorEl.textContent = 'Errore di connessione. Riprova tra un momento.';
      errorEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Verifica';
    }
  }

  // ── SUGGERIMENTI ──
  function initSuggestions() {
    var suggestions = [
      'A che punto siamo?',
      'Cosa significa questa fase?',
      'Posso visitare il laboratorio?'
    ];
    var suggEl = document.getElementById('ardy-lav-suggestions');
    suggestions.forEach(function (text) {
      var btn = document.createElement('button');
      btn.className = 'ardy-lav-sugg';
      btn.textContent = text;
      btn.onclick = function () {
        document.getElementById('ardy-lav-input').value = text;
        sendMessage();
        suggEl.style.display = 'none';
      };
      suggEl.appendChild(btn);
    });
  }

  // ── CHAT ──
  function addMessage(text, role) {
    var container = document.getElementById('ardy-lav-messages');
    var row = document.createElement('div');
    row.className = 'ardy-lav-msg ' + role;
    var avatar = document.createElement('div');
    avatar.className = 'ardy-lav-avatar';
    avatar.textContent = role === 'agent' ? '🪑' : 'Tu';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-lav-bubble';
    bubble.textContent = text;
    row.appendChild(avatar);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
  }

  function showTyping() {
    var container = document.getElementById('ardy-lav-messages');
    var row = document.createElement('div');
    row.className = 'ardy-lav-msg agent';
    row.id = 'ardy-lav-typing';
    var avatar = document.createElement('div');
    avatar.className = 'ardy-lav-avatar';
    avatar.textContent = '🪑';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-lav-bubble';
    bubble.innerHTML = '<div class="ardy-lav-typing"><div class="ardy-lav-dot"></div><div class="ardy-lav-dot"></div><div class="ardy-lav-dot"></div></div>';
    row.appendChild(avatar);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
  }

  function hideTyping() {
    var el = document.getElementById('ardy-lav-typing');
    if (el) el.remove();
  }

  function disableInput() {
    var input = document.getElementById('ardy-lav-input');
    var send = document.getElementById('ardy-lav-send');
    input.disabled = true;
    input.placeholder = 'Conversazione terminata';
    send.disabled = true;
    addMessage('Hai raggiunto il limite di questa chat. Per altre domande scrivi a Michela al 351 967 7973 o su ardy-lab.it', 'agent');
  }

  async function sendMessage() {
    if (!isVerified) return;
    if (msgCount >= MAX_MSGS) { disableInput(); return; }

    var input = document.getElementById('ardy-lav-input');
    var text = input.value.trim();
    if (!text) return;

    input.value = '';
    addMessage(text, 'user');
    msgCount++;

    var suggEl = document.getElementById('ardy-lav-suggestions');
    if (suggEl) suggEl.style.display = 'none';

    history.push({ role: 'user', content: text });
    showTyping();

    try {
      var res = await fetch(PROXY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: text,
          history: history,
          context: pageContext,
          titolo: pageTitle,
          nome: clientName
        })
      });
      var data = await res.json();
      hideTyping();
      var reply = data.reply || 'Scusa, non sono riuscito a rispondere. Riprova!';
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
    extractContext();
    if (!wpPostId) return; // Non siamo su una pagina con post ID
    injectStyles();
    injectHTML();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
