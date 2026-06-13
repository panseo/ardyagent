/*
 * Ardy Lab — Chat generale del sito (pagina /ardy-agent/)
 * -----------------------------------------------------------
 * FONTE ATTIVA centralizzata: questo file è servito dal nostro server
 *   https://ardyagent.ardy-lab.it/ardy-chat-site.js
 * e caricato da WordPress con un solo loader nello snippet WPCode "ardychat"
 * (tipo HTML, footer):
 *   <script src="https://ardyagent.ardy-lab.it/ardy-chat-site.js" defer></script>
 *
 * Modificare QUI + deploy aggiorna il sito (niente più copia-incolla in WPCode).
 * Backup storico dello snippet: wordpress-snippets/ardychat.js
 * Dipende dagli elementi `ac-*` presenti nella pagina /ardy-agent/.
 */

(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function () {

    var PROXY_URL = 'https://ardyagent.ardy-lab.it/ardy-proxy.php';
    var MAX_MSGS  = 40; // limite messaggi utente per sessione (20 scambi)

    var conversationHistory = [];
    var pendingImages       = [];
    var sessionId           = 'session_' + Math.random().toString(36).substr(2, 9);
    var suggestionsHidden   = false;
    var msgCount            = 0;

    var welcomeEl  = document.getElementById('ac-welcome');
    var chatBodyEl = document.getElementById('ac-chat-body');
    var startBtn   = document.getElementById('ac-start-btn');
    var messagesEl = document.getElementById('ac-messages');
    var inputEl    = document.getElementById('ac-input');
    var sendBtn    = document.getElementById('ac-send');
    var attachBtn  = document.getElementById('ac-attach');
    var fileInput  = document.getElementById('ac-file-input');
    var previewEl  = document.getElementById('ac-preview');
    var suggestEl  = document.getElementById('ac-suggestions');

    // La pagina chat non è presente → non fare nulla (lo script è site-wide)
    if (!messagesEl || !inputEl) return;

    // Transizione welcome → chat
    if (startBtn) {
        startBtn.onclick = function () {
            welcomeEl.classList.add('hiding');
            setTimeout(function () {
                welcomeEl.style.display = 'none';
                chatBodyEl.classList.add('visible');
                if (inputEl) inputEl.focus();
            }, 350);
        };
    }

    if (attachBtn && fileInput) {
        attachBtn.onclick = function () { fileInput.click(); };
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            Array.from(fileInput.files).forEach(function (file) {
                if (!file.type.startsWith('image/')) return;
                if (file.size > 8 * 1024 * 1024) {
                    alert('La foto è troppo grande. Massimo 8MB.');
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
            fileInput.value = '';
        });
    }

    function renderPreviews() {
        previewEl.innerHTML = '';
        if (!pendingImages.length) { previewEl.classList.remove('visible'); return; }
        previewEl.classList.add('visible');
        pendingImages.forEach(function (img, idx) {
            var wrap  = document.createElement('div');
            wrap.className = 'ac-thumb-wrap';
            var thumb = document.createElement('img');
            thumb.className = 'ac-thumb';
            thumb.src = img.preview;
            var del   = document.createElement('button');
            del.className = 'ac-thumb-del';
            del.textContent = '×';
            del.onclick = function () { pendingImages.splice(idx, 1); renderPreviews(); };
            wrap.appendChild(thumb);
            wrap.appendChild(del);
            previewEl.appendChild(wrap);
        });
    }

    function addMsg(text, role, imgPreviews) {
        if (role === 'user' && !suggestionsHidden) {
            suggestEl.style.display = 'none';
            suggestionsHidden = true;
        }
        var row     = document.createElement('div');
        row.className = 'ac-msg-row ' + role;
        var avatarEl = document.createElement('div');
        avatarEl.className = 'ac-msg-avatar';
        avatarEl.textContent = role === 'agent' ? '🪑' : 'Tu';
        var bubble   = document.createElement('div');
        bubble.className = 'ac-bubble';
        if (text) bubble.textContent = text;
        if (imgPreviews && imgPreviews.length) {
            imgPreviews.forEach(function (src) {
                var img = document.createElement('img');
                img.src = src;
                bubble.appendChild(img);
            });
        }
        row.appendChild(avatarEl);
        row.appendChild(bubble);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function disableInput() {
        inputEl.disabled = true;
        inputEl.placeholder = 'Conversazione terminata';
        inputEl.style.background = '#f5f5f5';
        sendBtn.disabled = true;
        sendBtn.style.background = '#ccc';
        if (attachBtn) { attachBtn.disabled = true; attachBtn.style.opacity = '0.4'; }
        // Messaggio di limite
        var row  = document.createElement('div');
        row.className = 'ac-msg-row agent';
        var av   = document.createElement('div');
        av.className = 'ac-msg-avatar';
        av.textContent = '🪑';
        var bub  = document.createElement('div');
        bub.className = 'ac-bubble';
        bub.style.cssText = 'background:#fff3cd;color:#856404;font-size:13px';
        bub.textContent = 'Hai raggiunto il limite di questa conversazione. Per continuare scrivi a Michela direttamente — trovi i contatti su ardy-lab.it 🙏';
        row.appendChild(av);
        row.appendChild(bub);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function showTyping() {
        var row    = document.createElement('div');
        row.className = 'ac-typing-row';
        row.id    = 'ac-typing';
        var avatarEl = document.createElement('div');
        avatarEl.className = 'ac-msg-avatar';
        avatarEl.textContent = '🪑';
        var bubble = document.createElement('div');
        bubble.className = 'ac-typing-bubble';
        bubble.innerHTML = '<div class="ac-dot"></div><div class="ac-dot"></div><div class="ac-dot"></div>';
        row.appendChild(avatarEl);
        row.appendChild(bubble);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function hideTyping() {
        var t = document.getElementById('ac-typing');
        if (t) t.remove();
    }

    window.acUseSuggestion = function (btn) {
        inputEl.value = btn.textContent;
        inputEl.focus();
    };

    async function sendMessage() {

        if (msgCount >= MAX_MSGS) { disableInput(); return; }

        var text    = inputEl.value.trim();
        var hasImages = pendingImages.length > 0;
        if (!text && !hasImages) return;

        inputEl.value = '';
        var imagesToSend = pendingImages.slice();
        pendingImages = [];
        renderPreviews();

        var previews = imagesToSend.map(function (i) { return i.preview; });
        addMsg(text || null, 'user', previews);
        msgCount++;

        // ── MEMORIA IMMAGINI ──
        // Salva le immagini in base64 nella history
        // così Anthropic le vede anche nei messaggi successivi
        var historyContent = [];
        imagesToSend.forEach(function (i) {
            historyContent.push({
                type:   'image',
                source: { type: 'base64', media_type: i.type, data: i.data }
            });
        });
        if (text) historyContent.push({ type: 'text', text: text });

        conversationHistory.push({
            role:    'user',
            content: historyContent.length === 1 && historyContent[0].type === 'text'
                ? text
                : historyContent
        });

        showTyping();

        try {
            var res = await fetch(PROXY_URL, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({
                    message:   text,
                    history:   conversationHistory,
                    images:    imagesToSend.map(function (i) { return { data: i.data, type: i.type }; }),
                    sessionId: sessionId
                })
            });

            var data = await res.json();
            hideTyping();

            var reply = data.reply || 'Ops, risposta non valida.';
            conversationHistory.push({ role: 'assistant', content: reply });
            addMsg(reply, 'agent');

            if (msgCount >= MAX_MSGS) { disableInput(); }

        } catch (e) {
            console.error(e);
            hideTyping();
            conversationHistory.pop();
            msgCount--;
            addMsg('Ops, problema di connessione. Riprova tra un momento.', 'agent');
        }
    }

    if (sendBtn)  sendBtn.onclick = sendMessage;
    if (inputEl)  inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') sendMessage(); });

    });
})();
