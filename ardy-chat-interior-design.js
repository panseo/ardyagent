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

  var MAX_IMAGES  = 4;   // per messaggio (il proxy taglia comunque a ARDY_MAX_IMAGES_PER_MSG)

  var msgCount      = 0;
  var history       = [];
  var pendingImages = [];
  var isOpen        = false;
  var chatStarted   = false;
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

      // Invito esplicito a fotografare l'ambiente. Compare SOLO su dispositivi con
      // puntatore "coarse" (telefoni/tablet): su desktop l'attributo capture viene
      // ignorato e si aprirebbe il selettore file, rendendo l'invito una bugia.
      '#ardy-id-camcta {' +
        'display:none;width:calc(100% - 32px);margin:0 16px 10px;padding:11px 12px;' +
        'border:1px dashed rgba(200,169,110,0.6);border-radius:10px;background:#fff;' +
        'color:#8b7340;font-family:"DM Sans",sans-serif;font-size:13px;font-weight:600;' +
        'cursor:pointer;text-align:center;transition:all 0.2s;' +
      '}' +
      '#ardy-id-camcta.visible { display:block; }' +
      '#ardy-id-camcta:hover { background:#c8a96e;color:#fff;border-color:#c8a96e;border-style:solid; }' +

      // Anteprime delle foto in coda di invio (immagini di riferimento del cliente)
      '#ardy-id-preview { display:none;flex-wrap:wrap;gap:6px;padding:0 16px 10px; }' +
      '#ardy-id-preview.visible { display:flex; }' +
      '.ardy-id-thumb-wrap { position:relative;width:54px;height:54px; }' +
      '.ardy-id-thumb { width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid #e8e4de; }' +
      '.ardy-id-thumb-del {' +
        'position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;' +
        'border:none;background:#1a1a1a;color:#fff;font-size:13px;line-height:1;cursor:pointer;' +
        'display:flex;align-items:center;justify-content:center;padding:0;' +
      '}' +
      '.ardy-id-bubble img { display:block;max-width:100%;border-radius:8px;margin-top:6px; }' +

      '#ardy-id-inputbar { display:flex;gap:8px;padding:12px 16px;border-top:1px solid #e8e4de;background:#fff;align-items:center; }' +
      '.ardy-id-iconbtn {' +
        'background:none;border:1px solid #e8e4de;border-radius:8px;padding:0 10px;height:38px;' +
        'font-size:17px;cursor:pointer;transition:all 0.2s;flex-shrink:0;' +
      '}' +
      '.ardy-id-iconbtn:hover { border-color:#c8a96e;background:#faf9f6; }' +
      '.ardy-id-iconbtn:disabled { opacity:0.4;cursor:default; }' +
      // min-width:0 è indispensabile: senza, l'input non scende sotto la sua
      // larghezza intrinseca (~20 caratteri) e su schermi stretti spinge il
      // tasto invio fuori dal pannello. Si vede solo da quando ci sono 📷 e 📎.
      '#ardy-id-input {' +
        'flex:1;min-width:0;border:1px solid #ddd;border-radius:8px;padding:10px 14px;font-size:14px;' +
        'font-family:"DM Sans",sans-serif;outline:none;background:#faf9f6;color:#333;transition:border-color 0.2s;' +
      '}' +
      '#ardy-id-input:focus { border-color:#c8a96e; }' +
      '#ardy-id-input::placeholder { color:#aaa; }' +
      '#ardy-id-input:disabled { background:#f0f0f0;color:#999; }' +
      '#ardy-id-send {' +
        'background:linear-gradient(135deg,#c8a96e,#a8864a);border:none;border-radius:8px;padding:0 16px;' +
        'cursor:pointer;color:#fff;font-size:16px;transition:all 0.2s;display:flex;align-items:center;justify-content:center;' +
        'flex-shrink:0;height:38px;' +
      '}' +
      '#ardy-id-send:hover { transform:scale(1.05); }' +
      '#ardy-id-send:disabled { opacity:0.5;cursor:default;transform:none; }' +

      // MOBILE: la chat si apre A TUTTO SCHERMO. Il pannello "a finestrella" su
      // telefono era schiacciato tra la pagina che scorre dietro e le barre di
      // sistema, e l'area messaggi restava altissima due dita. Qui prende tutto:
      // niente bordi, messaggi in flex:1, e il toggle sparisce mentre è aperta.
      // 100dvh (non 100vh) tiene conto della barra indirizzi che si ritrae.
      '@media(max-width:600px) {' +
        '#ardy-id-panel {' +
          'top:0;left:0;right:0;bottom:0;width:100%;height:100dvh;max-height:none;' +
          'border-radius:0;border:none;transform:none;' +
          'padding-bottom:env(safe-area-inset-bottom,0px);' +
        '}' +
        '#ardy-id-panel.visible { transform:none; }' +
        '#ardy-id-header { padding-top:calc(16px + env(safe-area-inset-top,0px)); }' +
        '#ardy-id-messages { max-height:none;min-height:0;flex:1; }' +
        '#ardy-id-close { font-size:28px;padding:0 8px; }' +
        '#ardy-id-toggle { bottom:16px;right:16px;padding:0 18px;font-size:14px; }' +
        '#ardy-id-toggle.hidden { display:none; }' +
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
          '<p>Cinque domande veloci: stile, colori, luce, budget</p>' +
        '</div>' +
        '<button id="ardy-id-close">×</button>' +
      '</div>' +
      '<div id="ardy-id-messages"></div>' +
      '<div id="ardy-id-suggestions"></div>' +
      '<button id="ardy-id-camcta">📷 Fai la foto al tuo ambiente</button>' +
      '<div id="ardy-id-preview"></div>' +
      '<div id="ardy-id-inputbar">' +
        '<button class="ardy-id-iconbtn" id="ardy-id-cam" title="Scatta una foto" aria-label="Scatta una foto">📷</button>' +
        '<button class="ardy-id-iconbtn" id="ardy-id-attach" title="Allega un\'immagine di riferimento" aria-label="Allega un\'immagine">📎</button>' +
        '<input type="text" id="ardy-id-input" placeholder="Scrivi la tua risposta..." />' +
        '<button id="ardy-id-send">→</button>' +
      '</div>';
    document.body.appendChild(panel);

    // Input file nascosti: uno per la galleria, uno che apre direttamente la
    // fotocamera sul telefono (capture) — come nella chat generale del sito.
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

    document.getElementById('ardy-id-attach').onclick = function () { fileInput.click(); };
    document.getElementById('ardy-id-cam').onclick    = function () { camInput.click(); };

    // L'invito a fotografare l'ambiente si mostra solo dove la fotocamera si apre
    // davvero (telefoni/tablet): su desktop resta il 📎 e l'icona 📷.
    var camCta = document.getElementById('ardy-id-camcta');
    camCta.onclick = function () { camInput.click(); };
    if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
      camCta.classList.add('visible');
    }

    document.getElementById('ardy-id-close').onclick = closePanel;
    document.getElementById('ardy-id-send').onclick = sendMessage;
    document.getElementById('ardy-id-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') sendMessage();
    });
  }

  // ── Foto di riferimento (stili, oggetti, ambienti del cliente) ──
  // Il proxy accetta già le immagini: le valida sul MIME reale, le comprime, le
  // salva nella cartella della sessione e le allega all'email a Michela.
  function processFiles(files) {
    Array.from(files).forEach(function (file) {
      if (!file.type.startsWith('image/')) return;
      if (pendingImages.length >= MAX_IMAGES) {
        addMessage('Per ora fermiamoci a ' + MAX_IMAGES + ' immagini per messaggio: mandami le altre nel prossimo. 🙂', 'agent');
        return;
      }
      if (file.size > 8 * 1024 * 1024) {
        addMessage('Quell\'immagine è un po\' troppo pesante (oltre 8MB). Puoi mandarmene una più leggera?', 'agent');
        return;
      }
      var reader = new FileReader();
      reader.onload = function (e) {
        pendingImages.push({
          data:    e.target.result.split(',')[1],
          type:    file.type,
          preview: e.target.result
        });
        renderPreviews();
      };
      reader.readAsDataURL(file);
    });
  }

  function renderPreviews() {
    var el = document.getElementById('ardy-id-preview');
    el.innerHTML = '';
    if (!pendingImages.length) { el.classList.remove('visible'); return; }
    el.classList.add('visible');
    pendingImages.forEach(function (img, idx) {
      var wrap = document.createElement('div');
      wrap.className = 'ardy-id-thumb-wrap';
      var thumb = document.createElement('img');
      thumb.className = 'ardy-id-thumb';
      thumb.src = img.preview;
      var del = document.createElement('button');
      del.className = 'ardy-id-thumb-del';
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
    // La chat parte con un saluto di Sole già "incorniciato" sulla consulenza di
    // interior design. Mettendolo anche nella history, il modello mantiene il
    // contesto per tutte le risposte successive (anche con il system prompt dedicato).
    // ⚠️ Il saluto CHIEDE IL PERMESSO e non fa ancora nessuna domanda: è il "Passo 0"
    // dell'intervista guidata (vedi ardy-system.txt → CONSULENZA INTERIOR DESIGN).
    // Non trasformarlo in una prima domanda: il cliente deve poter dire di no.
    var greet = 'Ciao! Sono Sole, l\'assistente virtuale (AI) di Ardy Lab 👋 Se ti va, ti faccio ' +
      'cinque domande veloci sui tuoi gusti — stile, colori, luce e budget — così Michela arriva al ' +
      'primo incontro sapendo già come vorresti stare a casa tua. Sono due minuti. Iniziamo?';
    addMessage(greet, 'agent');
    history.push({ role: 'assistant', content: greet });
    initSuggestions([
      'Sì, iniziamo',
      'Come funziona la consulenza?',
      'Che cosa mi chiedi di preciso?',
      'Preferisco parlare con Michela'
    ]);
  }

  // Su telefono la chat è a tutto schermo: mentre è aperta il toggle sparisce e
  // la pagina sotto non deve scorrere (altrimenti "scappa" dietro alla chat).
  function isFullscreen() {
    return window.matchMedia && window.matchMedia('(max-width:600px)').matches;
  }

  var prevBodyOverflow = '';

  function togglePanel() {
    var panel = document.getElementById('ardy-id-panel');
    if (!isOpen) {
      panel.classList.add('open');
      setTimeout(function () { panel.classList.add('visible'); }, 10);
      document.getElementById('ardy-id-toggle').classList.add('hidden');
      if (isFullscreen()) {
        prevBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
      }
      startChat();
      // Su mobile NON diamo il focus automatico: aprirebbe la tastiera subito,
      // coprendo il messaggio di benvenuto proprio mentre chiede il permesso.
      if (!isFullscreen()) {
        setTimeout(function () { document.getElementById('ardy-id-input').focus(); }, 300);
      }
      isOpen = true;
    } else {
      closePanel();
    }
  }

  function closePanel() {
    var panel = document.getElementById('ardy-id-panel');
    panel.classList.remove('visible');
    setTimeout(function () { panel.classList.remove('open'); }, 300);
    document.getElementById('ardy-id-toggle').classList.remove('hidden');
    document.body.style.overflow = prevBodyOverflow;
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
    var container = document.getElementById('ardy-id-messages');
    var row = document.createElement('div');
    row.className = 'ardy-id-msg ' + role;
    var avatar = document.createElement('div');
    avatar.className = 'ardy-id-avatar';
    avatar.textContent = role === 'agent' ? '🛋️' : 'Tu';
    var bubble = document.createElement('div');
    bubble.className = 'ardy-id-bubble';
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
    ['ardy-id-attach', 'ardy-id-cam'].forEach(function (id) {
      var b = document.getElementById(id);
      if (b) b.disabled = true;
    });
    var camCta = document.getElementById('ardy-id-camcta');
    if (camCta) camCta.classList.remove('visible');
    pendingImages = [];
    renderPreviews();
    addMessage('Per ora ci fermiamo qui 🌿 Per continuare scrivi o chiama Michela al ' + MICHELA_TEL + ': sarà felice di parlartene.', 'agent');
  }

  async function sendMessage() {
    if (!chatStarted) startChat();
    if (msgCount >= MAX_MSGS) { disableInput(); return; }

    var input = document.getElementById('ardy-id-input');
    var text = input.value.trim();
    // Si può inviare anche solo una foto, senza testo (es. "questo è lo stile che mi piace").
    if (!text && !pendingImages.length) return;

    input.value = '';
    var imagesToSend = pendingImages.slice();
    pendingImages = [];
    renderPreviews();

    addMessage(text || null, 'user', imagesToSend.map(function (i) { return i.preview; }));
    msgCount++;

    var suggEl = document.getElementById('ardy-id-suggestions');
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
          history:   history,
          images:    imagesToSend.map(function (i) { return { data: i.data, type: i.type }; }),
          sessionId: sessionId
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
    window.ardyIdOpen = function () { if (!isOpen) togglePanel(); };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
