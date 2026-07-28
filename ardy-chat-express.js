/*
 * Ardy Lab — Widget Chat "Ardy Express" (manutenzione a domicilio)
 * -----------------------------------------------------------
 * FONTE ATTIVA centralizzata: questo file è servito dal nostro server
 *   https://ardyagent.ardy-lab.it/ardy-chat-express.js
 * e caricato dalla pagina WordPress ardy-lab.it/ardy-express con un loader
 * di una riga (vedi wordpress-snippets/ardy-express-page.html):
 *   <script src="https://ardyagent.ardy-lab.it/ardy-chat-express.js"></script>
 *
 * Cosa fa: inietta una webchat autoportante (stampo di ardy-chat-interior-design.js)
 * dove il cliente racconta a Sole quali mobili e quanti (interno o esterno/giardino),
 * la zona, e può allegare foto. Sole dà una STIMA PROVVISORIA basata sulla griglia
 * prezzi fino a 10 pezzi (regole in ardy-proxy.php, sezione "ARDY EXPRESS") e
 * ricorda sempre che il costo si conferma con un sopralluogo. Riusa il cervello
 * completo di Sole via ardy-proxy.php: nessuna nuova tabella CRM, il lead viene
 * salvato con il tool esistente salva_lead_crm.
 *
 * Modificare QUI + deploy aggiorna il sito (niente copia-incolla in WPCode).
 *
 * Si attiva SOLO sulla pagina Ardy Express: o l'URL contiene "ardy-express",
 * oppure esiste in pagina un elemento #ardy-express, oppure è impostato
 * window.ARDY_EX = true. Espone window.ardyExOpen() così un bottone CTA della
 * pagina può aprire la chat.
 */
(function () {
  'use strict';

  var PROXY_URL = 'https://ardyagent.ardy-lab.it/ardy-proxy.php';
  var MICHELA_TEL = '+39 379 375 6437';
  var MAX_MSGS  = 30;

  var MAX_IMAGES  = 4;   // per messaggio (il proxy taglia comunque a ARDY_MAX_IMAGES_PER_MSG)

  var msgCount      = 0;
  var history       = [];
  var pendingImages = [];
  var isOpen        = false;
  var chatStarted   = false;
  var sessionId   = 'ex_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);

  // ── Si attiva solo sulla pagina Ardy Express ──
  function shouldRun() {
    if (window.ARDY_EX === true) return true;
    if (document.getElementById('ardy-express')) return true;
    return /ardy-express/i.test(location.pathname);
  }

  // ── CSS (palette coerente con gli altri widget Ardy) ──
  function injectStyles() {
    var style = document.createElement('style');
    style.textContent = '' +
      '@import url("https://fonts.googleapis.com/css2?family=Crimson+Pro:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@400;500;600&display=swap");' +

      '#ardy-ex-toggle {' +
        'position:fixed;bottom:24px;right:24px;z-index:9999;' +
        'height:60px;padding:0 22px;border-radius:30px;border:none;cursor:pointer;' +
        'background:linear-gradient(135deg,#c8a96e 0%,#a8864a 100%);' +
        'box-shadow:0 4px 20px rgba(200,169,110,0.4);' +
        'display:flex;align-items:center;gap:10px;' +
        'transition:all 0.3s cubic-bezier(0.4,0,0.2,1);' +
        'font-family:"DM Sans",sans-serif;font-size:15px;font-weight:600;color:#fff;' +
      '}' +
      '#ardy-ex-toggle:hover { transform:scale(1.05);box-shadow:0 6px 28px rgba(200,169,110,0.55); }' +
      '#ardy-ex-toggle .ardy-ex-ico { font-size:22px;line-height:1; }' +

      '#ardy-ex-panel {' +
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
      '#ardy-ex-panel.open { display:flex; }' +
      '#ardy-ex-panel.visible { opacity:1;transform:translateY(0) scale(1); }' +

      '#ardy-ex-header {' +
        'background:linear-gradient(135deg,#1a1a1a 0%,#2d2d2d 100%);' +
        'color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;' +
        'border-bottom:2px solid #c8a96e;' +
      '}' +
      '#ardy-ex-header .ardy-ex-h-ico { font-size:22px; }' +
      '#ardy-ex-header h3 { margin:0;font-family:"Crimson Pro",serif;font-size:16px;font-weight:600;color:#c8a96e; }' +
      '#ardy-ex-header p { margin:2px 0 0;font-size:11px;color:rgba(255,255,255,0.6); }' +
      '#ardy-ex-close {' +
        'margin-left:auto;background:none;border:none;color:rgba(255,255,255,0.5);' +
        'font-size:20px;cursor:pointer;padding:0 4px;transition:color 0.2s;' +
      '}' +
      '#ardy-ex-close:hover { color:#fff; }' +

      '#ardy-ex-messages {' +
        'flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px;' +
        'max-height:360px;min-height:220px;scrollbar-width:thin;scrollbar-color:#c8a96e33 transparent;' +
      '}' +
      '.ardy-ex-msg { display:flex;gap:8px;align-items:flex-start;animation:ardy-ex-fade 0.3s ease; }' +
      '@keyframes ardy-ex-fade { from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)} }' +
      '.ardy-ex-msg.user { flex-direction:row-reverse; }' +
      '.ardy-ex-avatar {' +
        'width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;' +
        'font-size:14px;flex-shrink:0;' +
      '}' +
      '.ardy-ex-msg.agent .ardy-ex-avatar { background:linear-gradient(135deg,#c8a96e,#a8864a); }' +
      '.ardy-ex-msg.user .ardy-ex-avatar { background:#e8e4de;font-size:11px;font-weight:600;color:#666; }' +
      '.ardy-ex-bubble { max-width:80%;padding:10px 14px;font-size:14px;line-height:1.5;color:#333; }' +
      '.ardy-ex-msg.agent .ardy-ex-bubble { background:#fff;border:1px solid #e8e4de;border-radius:12px 12px 12px 4px; }' +
      '.ardy-ex-msg.user .ardy-ex-bubble {' +
        'background:linear-gradient(135deg,#c8a96e,#b89a5e);color:#fff;border-radius:12px 12px 4px 12px;' +
      '}' +
      '.ardy-ex-bubble a { color:#8b7340;font-weight:600; }' +
      '.ardy-ex-msg.user .ardy-ex-bubble a { color:#fff;text-decoration:underline; }' +

      '.ardy-ex-typing { display:flex;gap:4px;padding:4px 0; }' +
      '.ardy-ex-dot { width:7px;height:7px;border-radius:50%;background:#c8a96e;animation:ardy-ex-bounce 1.4s infinite; }' +
      '.ardy-ex-dot:nth-child(2){animation-delay:0.15s}' +
      '.ardy-ex-dot:nth-child(3){animation-delay:0.3s}' +
      '@keyframes ardy-ex-bounce { 0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)} }' +

      '#ardy-ex-suggestions { display:flex;flex-wrap:wrap;gap:6px;padding:0 16px 12px; }' +
      '.ardy-ex-sugg {' +
        'background:#fff;border:1px solid #c8a96e44;border-radius:20px;padding:6px 14px;' +
        'font-size:12px;color:#8b7340;cursor:pointer;font-family:"DM Sans",sans-serif;transition:all 0.2s;' +
      '}' +
      '.ardy-ex-sugg:hover { background:#c8a96e;color:#fff;border-color:#c8a96e; }' +

      // Invito esplicito a fotografare i mobili. Compare SOLO su dispositivi con
      // puntatore "coarse" (telefoni/tablet): su desktop l'attributo capture viene
      // ignorato e si aprirebbe il selettore file, rendendo l'invito una bugia.
      '#ardy-ex-camcta {' +
        'display:none;width:calc(100% - 32px);margin:0 16px 10px;padding:11px 12px;' +
        'border:1px dashed rgba(200,169,110,0.6);border-radius:10px;background:#fff;' +
        'color:#8b7340;font-family:"DM Sans",sans-serif;font-size:13px;font-weight:600;' +
        'cursor:pointer;text-align:center;transition:all 0.2s;' +
      '}' +
      '#ardy-ex-camcta.visible { display:block; }' +
      '#ardy-ex-camcta:hover { background:#c8a96e;color:#fff;border-color:#c8a96e;border-style:solid; }' +

      // Anteprime delle foto in coda di invio
      '#ardy-ex-preview { display:none;flex-wrap:wrap;gap:6px;padding:0 16px 10px; }' +
      '#ardy-ex-preview.visible { display:flex; }' +
      '.ardy-ex-thumb-wrap { position:relative;width:54px;height:54px; }' +
      '.ardy-ex-thumb { width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid #e8e4de; }' +
      '.ardy-ex-thumb-del {' +
        'position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;' +
        'border:none;background:#1a1a1a;color:#fff;font-size:13px;line-height:1;cursor:pointer;' +
        'display:flex;align-items:center;justify-content:center;padding:0;' +
      '}' +
      '.ardy-ex-bubble img { display:block;max-width:100%;border-radius:8px;margin-top:6px; }' +

      '#ardy-ex-inputbar { display:flex;gap:8px;padding:12px 16px;border-top:1px solid #e8e4de;background:#fff;align-items:center; }' +
      '.ardy-ex-iconbtn {' +
        'background:none;border:1px solid #e8e4de;border-radius:8px;padding:0 10px;height:38px;' +
        'font-size:17px;cursor:pointer;transition:all 0.2s;flex-shrink:0;' +
      '}' +
      '.ardy-ex-iconbtn:hover { border-color:#c8a96e;background:#faf9f6; }' +
      '.ardy-ex-iconbtn:disabled { opacity:0.4;cursor:default; }' +
      '#ardy-ex-input {' +
        'flex:1;min-width:0;border:1px solid #ddd;border-radius:8px;padding:10px 14px;font-size:14px;' +
        'font-family:"DM Sans",sans-serif;outline:none;background:#faf9f6;color:#333;transition:border-color 0.2s;' +
      '}' +
      '#ardy-ex-input:focus { border-color:#c8a96e; }' +
      '#ardy-ex-input::placeholder { color:#aaa; }' +
      '#ardy-ex-input:disabled { background:#f0f0f0;color:#999; }' +
      '#ardy-ex-send {' +
        'background:linear-gradient(135deg,#c8a96e,#a8864a);border:none;border-radius:8px;padding:0 16px;' +
        'cursor:pointer;color:#fff;font-size:16px;transition:all 0.2s;display:flex;align-items:center;justify-content:center;' +
        'flex-shrink:0;height:38px;' +
      '}' +
      '#ardy-ex-send:hover { transform:scale(1.05); }' +
      '#ardy-ex-send:disabled { opacity:0.5;cursor:default;transform:none; }' +

      // MOBILE: la chat si apre A TUTTO SCHERMO (100dvh tiene conto della barra
      // indirizzi che si ritrae).
      '@media(max-width:600px) {' +
        '#ardy-ex-panel {' +
          'top:0;left:0;right:0;bottom:0;width:100%;height:100dvh;max-height:none;' +
          'border-radius:0;border:none;transform:none;' +
          'padding-bottom:env(safe-area-inset-bottom,0px);' +
        '}' +
        '#ardy-ex-panel.visible { transform:none; }' +
        '#ardy-ex-header { padding-top:calc(16px + env(safe-area-inset-top,0px)); }' +
        '#ardy-ex-messages { max-height:none;min-height:0;flex:1; }' +
        '#ardy-ex-close { font-size:28px;padding:0 8px; }' +
        '#ardy-ex-toggle { bottom:16px;right:16px;padding:0 18px;font-size:14px; }' +
        '#ardy-ex-toggle.hidden { display:none; }' +
      '}';
    document.head.appendChild(style);
  }

  // ── HTML ──
  function injectHTML() {
    var toggle = document.createElement('button');
    toggle.id = 'ardy-ex-toggle';
    toggle.innerHTML = '<span class="ardy-ex-ico">🪑</span><span>Preventivo Ardy Express</span>';
    toggle.title = 'Chiedi a Sole una stima per la manutenzione a domicilio';
    toggle.onclick = togglePanel;
    document.body.appendChild(toggle);

    var panel = document.createElement('div');
    panel.id = 'ardy-ex-panel';
    panel.innerHTML = '' +
      '<div id="ardy-ex-header">' +
        '<span class="ardy-ex-h-ico">🪑</span>' +
        '<div>' +
          '<h3>Ardy Express</h3>' +
          '<p>Quali mobili, quanti, e una stima di massima</p>' +
        '</div>' +
        '<button id="ardy-ex-close">×</button>' +
      '</div>' +
      '<div id="ardy-ex-messages"></div>' +
      '<div id="ardy-ex-suggestions"></div>' +
      '<button id="ardy-ex-camcta">📷 Fai una foto ai tuoi mobili</button>' +
      '<div id="ardy-ex-preview"></div>' +
      '<div id="ardy-ex-inputbar">' +
        '<button class="ardy-ex-iconbtn" id="ardy-ex-cam" title="Scatta una foto" aria-label="Scatta una foto">📷</button>' +
        '<button class="ardy-ex-iconbtn" id="ardy-ex-attach" title="Allega una foto" aria-label="Allega una foto">📎</button>' +
        '<input type="text" id="ardy-ex-input" placeholder="Scrivi la tua risposta..." />' +
        '<button id="ardy-ex-send">→</button>' +
      '</div>';
    document.body.appendChild(panel);

    // Input file nascosti: uno per la galleria, uno che apre direttamente la
    // fotocamera sul telefono (capture) — come negli altri widget Ardy.
    var fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.multiple = true;
    fileInput.style.display = 'none';
    panel.appendChild(fileInput);

    var camInput = document.createElement('input');
    camInput.type = 'file';
    camInput.accept = 'image/*';
    camInput.setAttribute('capture', 'environment');
    camInput.style.display = 'none';
    panel.appendChild(camInput);

    fileInput.addEventListener('change', function () { processFiles(fileInput.files); fileInput.value = ''; });
    camInput.addEventListener('change', function ()  { processFiles(camInput.files);  camInput.value  = ''; });

    document.getElementById('ardy-ex-attach').onclick = function () { fileInput.click(); };
    document.getElementById('ardy-ex-cam').onclick    = function () { camInput.click(); };

    // L'invito a fotografare si mostra solo dove la fotocamera si apre davvero
    // (telefoni/tablet): su desktop restano solo 📎 e 📷.
    var camCta = document.getElementById('ardy-ex-camcta');
    camCta.onclick = function () { camInput.click(); };
    if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
      camCta.classList.add('visible');
    }

    document.getElementById('ardy-ex-close').onclick = closePanel;
    document.getElementById('ardy-ex-send').onclick = sendMessage;
    document.getElementById('ardy-ex-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') sendMessage();
    });
  }

  // ── Foto dei mobili da manutenere ──
  // Il proxy le valida sul MIME reale, le salva nella cartella della sessione e
  // le allega all'email a Michela.
  //
  // ⚠️ RIDIMENSIONIAMO QUI, PRIMA DI INVIARE, e non è un'ottimizzazione: è ciò
  // che tiene in piedi la conversazione. La compressione del proxy vale solo per
  // il messaggio corrente, mentre la history che rispediamo a ogni turno viaggia
  // verso l'API così com'è. Una foto da telefono non toccata (4-8MB → ~+33% in
  // base64) sfonda il limite di 5MB per immagine dell'API: il primo messaggio con
  // foto passa e il SECONDO fa fallire tutta la chiamata. Salvando solo la
  // versione ridotta, `images` e `history` restano leggere.
  var MAX_SIDE = 1600;   // lato lungo in px: più che sufficiente per giudicare lo stato di un mobile
  var JPEG_Q   = 0.82;

  function downscale(file, cb) {
    function disegna(src, w, h) {
      var scale = Math.min(1, MAX_SIDE / Math.max(w, h));
      var nw = Math.max(1, Math.round(w * scale));
      var nh = Math.max(1, Math.round(h * scale));
      var canvas = document.createElement('canvas');
      canvas.width = nw; canvas.height = nh;
      try {
        canvas.getContext('2d').drawImage(src, 0, 0, nw, nh);
        var url = canvas.toDataURL('image/jpeg', JPEG_Q);
        cb({ data: url.split(',')[1], type: 'image/jpeg', preview: url });
      } catch (e) { cb(null); }
    }

    function viaImg() {
      var reader = new FileReader();
      reader.onload = function (e) {
        var img = new Image();
        img.onload  = function () { disegna(img, img.naturalWidth, img.naturalHeight); };
        img.onerror = function () { cb(null); };
        img.src = e.target.result;
      };
      reader.onerror = function () { cb(null); };
      reader.readAsDataURL(file);
    }

    // createImageBitmap con imageOrientation applica l'EXIF: senza, le foto
    // scattate in verticale finirebbero ruotate una volta passate dal canvas.
    if (window.createImageBitmap) {
      try {
        createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bm) {
          disegna(bm, bm.width, bm.height);
          if (bm.close) bm.close();
        }).catch(viaImg);
        return;
      } catch (e) { /* opzioni non supportate: si prosegue col fallback */ }
    }
    viaImg();
  }

  function processFiles(files) {
    Array.from(files).forEach(function (file) {
      if (!file.type.startsWith('image/')) return;
      if (pendingImages.length >= MAX_IMAGES) {
        addMessage('Per ora fermiamoci a ' + MAX_IMAGES + ' immagini per messaggio: mandami le altre nel prossimo. 🙂', 'agent');
        return;
      }
      if (file.size > 25 * 1024 * 1024) {
        addMessage('Quell\'immagine è davvero pesante. Puoi mandarmene una più leggera?', 'agent');
        return;
      }
      downscale(file, function (img) {
        if (!img) {
          addMessage('Non sono riuscita a leggere quell\'immagine. Ne proviamo un\'altra?', 'agent');
          return;
        }
        if (pendingImages.length >= MAX_IMAGES) return;  // ricontrollo: downscale è asincrono
        pendingImages.push(img);
        renderPreviews();
      });
    });
  }

  function renderPreviews() {
    var el = document.getElementById('ardy-ex-preview');
    el.innerHTML = '';
    if (!pendingImages.length) { el.classList.remove('visible'); return; }
    el.classList.add('visible');
    pendingImages.forEach(function (img, idx) {
      var wrap = document.createElement('div');
      wrap.className = 'ardy-ex-thumb-wrap';
      var thumb = document.createElement('img');
      thumb.className = 'ardy-ex-thumb';
      thumb.src = img.preview;
      var del = document.createElement('button');
      del.className = 'ardy-ex-thumb-del';
      del.textContent = '×';
      del.title = 'Togli questa immagine';
      del.onclick = function () { pendingImages.splice(idx, 1); renderPreviews(); };
      wrap.appendChild(thumb);
      wrap.appendChild(del);
      el.appendChild(wrap);
    });
  }

  function startChat() {
    if (chatStarted) return;
    chatStarted = true;
    // Il saluto CHIEDE IL PERMESSO e inquadra subito il servizio: manutenzione a
    // domicilio, non restauro. Non è ancora una domanda: il cliente deve poter
    // dire di no e chiedere subito il numero di Michela.
    var greet = 'Ciao! Sono Sole, l\'assistente virtuale (AI) di Ardy Lab 👋 Ardy Express è il nostro ' +
      'servizio di manutenzione a domicilio: puliamo, patiniamo e rinfreschiamo i tuoi mobili di pregio ' +
      '(o da giardino) direttamente a casa tua, in una mattina. Se mi dici quali mobili e quanti, ti do ' +
      'subito un\'idea di massima. Iniziamo?';
    addMessage(greet, 'agent');
    history.push({ role: 'assistant', content: greet });
    initSuggestions([
      'Sì, dimmi come funziona',
      'Quanto costa?',
      'Che prodotti usate?',
      'Preferisco parlare con Michela'
    ]);
  }

  // Su telefono la chat è a tutto schermo: mentre è aperta il toggle sparisce e
  // la pagina sotto non deve scorrere.
  function isFullscreen() {
    return window.matchMedia && window.matchMedia('(max-width:600px)').matches;
  }

  var prevBodyOverflow = '';

  function togglePanel() {
    var panel = document.getElementById('ardy-ex-panel');
    if (!isOpen) {
      panel.classList.add('open');
      setTimeout(function () { panel.classList.add('visible'); }, 10);
      document.getElementById('ardy-ex-toggle').classList.add('hidden');
      if (isFullscreen()) {
        prevBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
      }
      startChat();
      // Su mobile NON diamo il focus automatico: aprirebbe la tastiera subito,
      // coprendo il messaggio di benvenuto proprio mentre chiede il permesso.
      if (!isFullscreen()) {
        setTimeout(function () { document.getElementById('ardy-ex-input').focus(); }, 300);
      }
      isOpen = true;
    } else {
      closePanel();
    }
  }

  function closePanel() {
    var panel = document.getElementById('ardy-ex-panel');
    panel.classList.remove('visible');
    setTimeout(function () { panel.classList.remove('open'); }, 300);
    document.getElementById('ardy-ex-toggle').classList.remove('hidden');
    document.body.style.overflow = prevBodyOverflow;
    isOpen = false;
  }

  function initSuggestions(suggestions) {
    var suggEl = document.getElementById('ardy-ex-suggestions');
    suggEl.innerHTML = '';
    suggestions.forEach(function (text) {
      var btn = document.createElement('button');
      btn.className = 'ardy-ex-sugg';
      btn.textContent = text;
      btn.onclick = function () {
        document.getElementById('ardy-ex-input').value = text;
        sendMessage();
        suggEl.style.display = 'none';
      };
      suggEl.appendChild(btn);
    });
  }

  // Sole scrive in markdown (**grassetto**): stampandolo con textContent gli
  // asterischi finivano a schermo. Qui li rendiamo <strong> costruendo NODI DOM
  // — mai innerHTML — così nessun testo del modello può iniettare markup.
  function appendRichText(el, text) {
    var re = /\*\*([^*]+)\*\*/g, last = 0, m;
    while ((m = re.exec(text)) !== null) {
      if (m.index > last) el.appendChild(document.createTextNode(text.slice(last, m.index)));
      var strong = document.createElement('strong');
      strong.textContent = m[1];
      el.appendChild(strong);
      last = m.index + m[0].length;
    }
    if (last < text.length) el.appendChild(document.createTextNode(text.slice(last)));
  }

  function addMessage(text, role, imgPreviews) {
    var container = document.getElementById('ardy-ex-messages');
    var row = document.createElement('div');
    row.className = 'ardy-ex-msg ' + role;
    var avatar = document.createElement('div');
    avatar.className = 'ardy-ex-avatar';
    avatar.textContent = role === 'agent' ? '🪑' : 'Tu';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-ex-bubble';
    if (text) appendRichText(bubble, text);
    if (imgPreviews && imgPreviews.length) {
      imgPreviews.forEach(function (src) {
        var img = document.createElement('img');
        img.src = src;
        bubble.appendChild(img);
      });
    }
    row.appendChild(avatar);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
  }

  function showTyping() {
    var container = document.getElementById('ardy-ex-messages');
    var row = document.createElement('div');
    row.className = 'ardy-ex-msg agent';
    row.id = 'ardy-ex-typing';
    var avatar = document.createElement('div');
    avatar.className = 'ardy-ex-avatar';
    avatar.textContent = '🪑';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-ex-bubble';
    bubble.innerHTML = '<div class="ardy-ex-typing"><div class="ardy-ex-dot"></div><div class="ardy-ex-dot"></div><div class="ardy-ex-dot"></div></div>';
    row.appendChild(avatar);
    row.appendChild(bubble);
    container.appendChild(row);
    container.scrollTop = container.scrollHeight;
  }

  function hideTyping() {
    var el = document.getElementById('ardy-ex-typing');
    if (el) el.remove();
  }

  function disableInput() {
    var input = document.getElementById('ardy-ex-input');
    var send = document.getElementById('ardy-ex-send');
    input.disabled = true;
    input.placeholder = 'Conversazione terminata';
    send.disabled = true;
    ['ardy-ex-attach', 'ardy-ex-cam'].forEach(function (id) {
      var b = document.getElementById(id);
      if (b) b.disabled = true;
    });
    var camCta = document.getElementById('ardy-ex-camcta');
    if (camCta) camCta.classList.remove('visible');
    pendingImages = [];
    renderPreviews();
    addMessage('Per ora ci fermiamo qui 🌿 Per continuare scrivi o chiama Michela al ' + MICHELA_TEL + ': sarà felice di parlartene.', 'agent');
  }

  // Seconda difesa: anche ridotte, le foto pesano. Se la conversazione ne
  // accumula parecchie, ogni turno le rispedirebbe TUTTE. Teniamo le immagini
  // solo negli ultimi IMG_TURNI messaggi che ne contengono; nei più vecchi
  // resta una nota testuale: Sole le ha già viste e commentate.
  var IMG_TURNI = 2;

  function historyDaInviare() {
    var conImmagini = [];
    history.forEach(function (m, i) {
      if (Array.isArray(m.content) && m.content.some(function (b) { return b.type === 'image'; })) {
        conImmagini.push(i);
      }
    });
    if (conImmagini.length <= IMG_TURNI) return history;
    var daAlleggerire = conImmagini.slice(0, conImmagini.length - IMG_TURNI);
    return history.map(function (m, i) {
      if (daAlleggerire.indexOf(i) === -1) return m;
      var testo = m.content.filter(function (b) { return b.type === 'text'; })
                           .map(function (b) { return b.text; }).join(' ');
      var n = m.content.filter(function (b) { return b.type === 'image'; }).length;
      return {
        role: m.role,
        content: (testo ? testo + ' ' : '') + '[' + n + (n === 1 ? ' foto inviata' : ' foto inviate') + ' in precedenza]'
      };
    });
  }

  async function sendMessage() {
    if (!chatStarted) startChat();
    if (msgCount >= MAX_MSGS) { disableInput(); return; }

    var input = document.getElementById('ardy-ex-input');
    var text = input.value.trim();
    // Si può inviare anche solo una foto, senza testo.
    if (!text && !pendingImages.length) return;

    input.value = '';
    var imagesToSend = pendingImages.slice();
    pendingImages = [];
    renderPreviews();

    addMessage(text || null, 'user', imagesToSend.map(function (i) { return i.preview; }));
    msgCount++;

    var suggEl = document.getElementById('ardy-ex-suggestions');
    if (suggEl) suggEl.style.display = 'none';

    // Le immagini restano nella history in base64: così il modello continua a
    // "vederle" anche nei messaggi successivi, non solo in quello in cui arrivano.
    if (imagesToSend.length) {
      var content = imagesToSend.map(function (i) {
        return { type: 'image', source: { type: 'base64', media_type: i.type, data: i.data } };
      });
      if (text) content.push({ type: 'text', text: text });
      history.push({ role: 'user', content: content });
    } else {
      history.push({ role: 'user', content: text });
    }
    showTyping();

    try {
      var res = await fetch(PROXY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message:   text,
          history:   historyDaInviare(),
          images:    imagesToSend.map(function (i) { return { data: i.data, type: i.type }; }),
          sessionId: sessionId,
          // Dichiara da quale pagina arriva la conversazione. Il proxy lo usa per
          // applicare in modo DETERMINISTICO le regole Ardy Express (griglia
          // prezzi, domande guidate), senza dipendere dal fatto che il modello
          // capisca da solo il contesto.
          origine:   'ardy-express'
        })
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
    window.ardyExOpen = function () { if (!isOpen) togglePanel(); };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
