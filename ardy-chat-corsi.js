/*
 * Ardy Lab — Chat in "modalità corso" (pagina /ardy-agent/?corso=...)
 * -----------------------------------------------------------
 * FONTE ATTIVA centralizzata: questo file è servito dal nostro server
 *   https://ardyagent.ardy-lab.it/ardy-chat-corsi.js
 * e caricato da WordPress con un solo loader nello snippet WPCode
 * "Chat per i corsi" (tipo HTML, footer):
 *   <script src="https://ardyagent.ardy-lab.it/ardy-chat-corsi.js"></script>
 *
 * Cosa fa: quando l'URL ha ?corso=<nome> e c'è la pagina chat (#ardy-chat-page),
 * adatta intestazione, box "come funziona", primo messaggio, suggerimenti e
 * placeholder alla "modalità corso", e all'avvio chiede info sul corso scelto.
 *
 * Modificare QUI + deploy aggiorna il sito (niente più copia-incolla in WPCode).
 * Backup storico dello snippet: wordpress-snippets/chat-corsi.html
 * (Codice identico allo snippet originale: usa già ready() perché uno script
 * ESTERNO può caricarsi dopo che DOMContentLoaded è già scattato.)
 */

(function () {
  function ready(fn){ document.readyState!=='loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); }
  ready(function () {
    var corso = new URLSearchParams(location.search).get('corso');
    if (!corso || !document.getElementById('ardy-chat-page')) return;
    corso = corso.replace(/[<>]/g,'').trim();

    // Intestazione → modalità corso
    var eyebrow = document.querySelector('.ac-hero-eyebrow');
    var h1 = document.querySelector('.ac-hero h1');
    var hp = document.querySelector('.ac-hero p');
    if (eyebrow) eyebrow.textContent = 'Ardy School · Corsi';
    if (h1) h1.textContent = 'Info sul corso';
    if (hp) hp.textContent = 'Chiedi a Sole date, posti disponibili e programma del corso che ti interessa.';

    // Box "Come funziona" → contenuti corso
    var wt = document.querySelector('.ac-welcome-title');
    var ws = document.querySelector('.ac-welcome-sub');
    if (wt) wt.textContent = 'Ti interessa questo corso?';
    if (ws) ws.textContent = 'Hai scelto: ' + corso + '. Avvia la chat per date, disponibilità (max 2 studenti) e prenotazione.';
    var sb = document.querySelectorAll('.ac-step-body');
    if (sb.length >= 3) {
      sb[0].querySelector('strong').textContent = 'Scegli il corso';
      sb[0].querySelector('p').textContent = 'Sole ti dà programma, durata e costo del corso.';
      sb[1].querySelector('strong').textContent = 'Date e posti';
      sb[1].querySelector('p').textContent = 'Posti limitati a 2 studenti: verifichiamo insieme la disponibilità.';
      sb[2].querySelector('strong').textContent = 'Prenoti il colloquio';
      sb[2].querySelector('p').textContent = 'Fissiamo un colloquio gratuito con Andrea per il percorso su misura.';
    }

    // Bottone + saluto + suggerimenti + placeholder
    var startBtn = document.getElementById('ac-start-btn');
    if (startBtn) startBtn.textContent = 'Chiedi info sul corso';

    var bubble = document.querySelector('#ac-messages .ac-msg-row.agent .ac-bubble');
    if (bubble) {
      bubble.textContent = '';
      bubble.appendChild(document.createTextNode('Ciao! Sono Sole, l\'assistente virtuale (AI) di Ardy Lab 👋 Vedo che ti interessa il '));
      var b = document.createElement('strong'); b.textContent = corso; bubble.appendChild(b);
      bubble.appendChild(document.createTextNode('. Dimmi pure cosa vuoi sapere: date, posti o programma.'));
    }

    var sugg = document.getElementById('ac-suggestions');
    if (sugg) sugg.innerHTML =
      '<button class="ac-suggestion" onclick="acUseSuggestion(this)">Quando inizia?</button>' +
      '<button class="ac-suggestion" onclick="acUseSuggestion(this)">Ci sono posti?</button>' +
      '<button class="ac-suggestion" onclick="acUseSuggestion(this)">Cosa include?</button>' +
      '<button class="ac-suggestion" onclick="acUseSuggestion(this)">Come prenoto?</button>';

    var input = document.getElementById('ac-input');
    if (input) input.placeholder = 'Chiedi info sul corso...';

    // Al click "Chiedi info sul corso": avvia la chat già sul corso
    if (startBtn && input) {
      startBtn.addEventListener('click', function () {
        setTimeout(function () {
          input.value = 'Ciao! Vorrei informazioni sul ' + corso + ': date, posti disponibili e programma.';
          var send = document.getElementById('ac-send');
          if (send) send.click();
        }, 600);
      });
    }
  });
})();
