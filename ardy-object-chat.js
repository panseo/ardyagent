// -----------------------------------------------------------
// ARDY LAB — Widget Chat Oggetto (negozio object.ardy-lab.it)
// Bolla di Sole sulla scheda prodotto WooCommerce. Legge lo slug da
// <div id="ardy-object-chat" data-slug="..."> (iniettato dallo snippet WP) e
// dialoga con ardy-object-proxy.php. Il contesto dell'oggetto lo carica il server:
// qui si manda solo {slug, message, history}. Vedi PIANO-ECOMMERCE-OBJECT-WOO.md §6.
// -----------------------------------------------------------
(function () {
  'use strict';

  var PROXY_URL = 'https://ardyagent.ardy-lab.it/ardy-object-proxy.php';
  var MAX_MSGS  = 20;

  function init() {
    var mount = document.getElementById('ardy-object-chat');
    if (!mount) return;
    var slug = (mount.getAttribute('data-slug') || '').trim();
    if (!slug) return;
    if (mount.getAttribute('data-ready') === '1') return; // no doppia init
    mount.setAttribute('data-ready', '1');

    var history  = [];
    var msgCount = 0;
    var isOpen   = false;
    var busy     = false;

    injectStyles();

    // ── Bolla toggle ──
    var toggle = document.createElement('button');
    toggle.id = 'ardy-obj-toggle';
    toggle.setAttribute('aria-label', 'Chatta con Sole su questo oggetto');
    toggle.innerHTML = '<span>Chiedi a Sole</span>';
    document.body.appendChild(toggle);

    // ── Pannello ──
    var panel = document.createElement('div');
    panel.id = 'ardy-obj-panel';
    panel.innerHTML =
      '<div id="ardy-obj-head">' +
        '<div class="ardy-obj-id"><b>Sole</b><span>assistente AI di Ardy</span></div>' +
        '<button id="ardy-obj-close" aria-label="Chiudi">&times;</button>' +
      '</div>' +
      '<div id="ardy-obj-msgs"></div>' +
      '<form id="ardy-obj-form" autocomplete="off">' +
        '<input id="ardy-obj-input" type="text" maxlength="1000" placeholder="Scrivi qui…" />' +
        '<button id="ardy-obj-send" type="submit" aria-label="Invia">&#8593;</button>' +
      '</form>';
    document.body.appendChild(panel);

    var msgs  = panel.querySelector('#ardy-obj-msgs');
    var form  = panel.querySelector('#ardy-obj-form');
    var input = panel.querySelector('#ardy-obj-input');

    function esc(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function addMsg(text, who) {
      var el = document.createElement('div');
      el.className = 'ardy-obj-msg ardy-obj-' + who;
      el.innerHTML = esc(text).replace(/\n/g, '<br>');
      msgs.appendChild(el);
      msgs.scrollTop = msgs.scrollHeight;
      return el;
    }

    function typing(on) {
      var t = msgs.querySelector('.ardy-obj-typing');
      if (on && !t) {
        t = document.createElement('div');
        t.className = 'ardy-obj-msg ardy-obj-bot ardy-obj-typing';
        t.innerHTML = '<span></span><span></span><span></span>';
        msgs.appendChild(t);
        msgs.scrollTop = msgs.scrollHeight;
      } else if (!on && t) {
        t.remove();
      }
    }

    function open() {
      isOpen = true;
      panel.classList.add('open');
      requestAnimationFrame(function () { panel.classList.add('visible'); });
      toggle.style.display = 'none';
      if (!msgs.childElementCount) {
        addMsg('Ciao! Sono Sole, l\'assistente virtuale (AI) di Ardy Lab 👋 Chiedimi pure di questo pezzo: storia, materiali, come si usa.', 'bot');
      }
      setTimeout(function () { input.focus(); }, 260);
    }
    function close() {
      isOpen = false;
      panel.classList.remove('visible');
      setTimeout(function () { panel.classList.remove('open'); }, 260);
      toggle.style.display = '';
    }

    function send(text) {
      if (busy) return;
      if (msgCount >= MAX_MSGS) {
        addMsg('Per continuare scrivici su ardy-lab.it o chiama il 351 967 7973. A presto!', 'bot');
        return;
      }
      busy = true;
      msgCount++;
      addMsg(text, 'user');
      history.push({ role: 'user', content: text });
      typing(true);

      fetch(PROXY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug: slug, message: text, history: history.slice(0, -1) })
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          typing(false);
          var reply = (d && d.reply) ? d.reply : 'Scusa, non ho ricevuto risposta. Riprova!';
          addMsg(reply, 'bot');
          history.push({ role: 'assistant', content: reply });
          if (history.length > 40) history = history.slice(-40);
          busy = false;
        })
        .catch(function () {
          typing(false);
          addMsg('Ops, problema di connessione. Riprova tra un momento.', 'bot');
          busy = false;
        });
    }

    toggle.addEventListener('click', open);
    panel.querySelector('#ardy-obj-close').addEventListener('click', close);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text) return;
      input.value = '';
      send(text);
    });
  }

  function injectStyles() {
    if (document.getElementById('ardy-obj-styles')) return;
    var s = document.createElement('style');
    s.id = 'ardy-obj-styles';
    s.textContent = [
      '#ardy-obj-toggle{position:fixed;right:20px;bottom:20px;z-index:99998;border:none;cursor:pointer;',
        'padding:12px 18px;border-radius:999px;font:600 15px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;',
        'color:#1a1a1a;background:#e9cfa0;box-shadow:0 6px 22px rgba(0,0,0,.16);transition:transform .2s ease,box-shadow .2s ease}',
      '#ardy-obj-toggle:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(0,0,0,.22)}',
      '#ardy-obj-panel{position:fixed;right:20px;bottom:20px;z-index:99999;width:370px;max-width:calc(100vw - 32px);',
        'height:560px;max-height:calc(100vh - 40px);display:none;flex-direction:column;overflow:hidden;',
        'background:#fff;border-radius:18px;box-shadow:0 18px 60px rgba(0,0,0,.22);',
        'font:400 15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1a1a1a;',
        'opacity:0;transform:translateY(14px) scale(.98);transition:opacity .25s ease,transform .25s ease}',
      '#ardy-obj-panel.open{display:flex}',
      '#ardy-obj-panel.visible{opacity:1;transform:none}',
      '#ardy-obj-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;',
        'border-bottom:1px solid #eee}',
      '#ardy-obj-head .ardy-obj-id b{font-weight:600;font-size:15px}',
      '#ardy-obj-head .ardy-obj-id span{display:block;font-size:12px;color:#8a8a8a;margin-top:1px}',
      '#ardy-obj-close{border:none;background:transparent;font-size:26px;line-height:1;color:#9a9a9a;cursor:pointer;padding:0 4px}',
      '#ardy-obj-close:hover{color:#333}',
      '#ardy-obj-msgs{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:#fafafa}',
      '.ardy-obj-msg{max-width:82%;padding:10px 13px;border-radius:14px;word-wrap:break-word}',
      '.ardy-obj-bot{align-self:flex-start;background:#fff;border:1px solid #ececec;border-bottom-left-radius:5px}',
      '.ardy-obj-user{align-self:flex-end;background:#1a1a1a;color:#fff;border-bottom-right-radius:5px}',
      '.ardy-obj-typing{display:flex;gap:4px;align-items:center}',
      '.ardy-obj-typing span{width:7px;height:7px;border-radius:50%;background:#c9c9c9;animation:ardyObjBlink 1.2s infinite}',
      '.ardy-obj-typing span:nth-child(2){animation-delay:.2s}',
      '.ardy-obj-typing span:nth-child(3){animation-delay:.4s}',
      '@keyframes ardyObjBlink{0%,60%,100%{opacity:.3}30%{opacity:1}}',
      '#ardy-obj-form{display:flex;gap:8px;padding:12px;border-top:1px solid #eee;background:#fff}',
      '#ardy-obj-input{flex:1;border:1px solid #e0e0e0;border-radius:999px;padding:11px 15px;font-size:15px;outline:none}',
      '#ardy-obj-input:focus{border-color:#c9a464}',
      '#ardy-obj-send{border:none;cursor:pointer;width:42px;border-radius:50%;background:#1a1a1a;color:#fff;font-size:18px}',
      '#ardy-obj-send:hover{background:#000}',
      '@media (max-width:480px){#ardy-obj-panel{right:8px;bottom:8px;width:calc(100vw - 16px);height:calc(100vh - 16px);max-height:none}}'
    ].join('');
    document.head.appendChild(s);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
