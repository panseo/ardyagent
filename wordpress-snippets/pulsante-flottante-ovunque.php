<?php
/**
 * WPCode snippet — BACKUP (NON è la fonte attiva: vedi README.md)
 * id: 15243 | titolo: "Pulsante flottante ovunque" | tipo: php
 * posizione: everywhere | auto_insert: 1 | modificato: 2026-06-24
 * NB: in WPCode il codice gira SENZA il tag <?php (lo aggiunge WPCode).
 *     Qui il tag c'è solo per avere un .php valido/lintabile.
 */

add_action('wp_footer', function () {
    // Salta le pagine con chat dedicata: "Lavori in corso" (cliente) e
    // "Galleria Diffusa" (widget partner B&B) — lì il bottone lead confonde.
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (is_singular() && (in_category('lavori-in-corso') || strpos($uri, '/lavori-in-corso/') !== false || strpos($uri, '/project/') !== false || strpos($uri, '/galleria-diffusa') !== false)) {
        return;
    }
    ?>
    <a href="https://ardy-lab.it/ardy-agent/" id="ardy-chat-cta" aria-label="Chatta con Sole, l'assistente AI">
      <span class="ardy-chat-icon">💬</span>
      <span class="ardy-chat-text">Chatta con Sole</span>
    </a>
    <style>
    #ardy-chat-cta{
      position:fixed; right:20px; bottom:20px; z-index:99999;
      display:flex; align-items:center; gap:8px;
      background:#2c5f4a; color:#fff !important; text-decoration:none;
      padding:13px 20px; border-radius:50px;
      font:600 16px/1 system-ui,Arial,sans-serif;
      box-shadow:0 6px 20px rgba(0,0,0,.25);
      transition:transform .2s ease, box-shadow .2s ease;
    }
    #ardy-chat-cta:hover{ transform:translateY(-2px); box-shadow:0 8px 26px rgba(0,0,0,.32); }
    .ardy-chat-icon{ font-size:20px; }
    @media(max-width:600px){
      .ardy-chat-text{ display:none; }
      #ardy-chat-cta{ padding:15px; border-radius:50%; }
      .ardy-chat-icon{ font-size:24px; }
    }
    </style>
    <?php
}, 100);
