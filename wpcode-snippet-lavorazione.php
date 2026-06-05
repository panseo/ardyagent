<!-- 
  ARDY LAB — Widget Chat Lavorazione
  Da inserire in WPCode come snippet "HTML" con condizione:
  - Posizione: Footer (prima di </body>)
  - Condizione: Solo pagine della categoria "lavori-in-corso" (ID: 102)
  
  Oppure se WPCode non supporta la condizione per categoria,
  inserisci come snippet PHP con questo codice:
-->

<?php
// Verifica che siamo su un post della categoria "lavori-in-corso" (ID 102)
if ( is_single() && has_category( 102 ) ) :
?>
<script src="https://ardyagent.ardy-lab.it/ardy-widget-lavorazione.js" defer></script>
<?php endif; ?>

<!--
  ALTERNATIVA SEMPLICE (se WPCode dà problemi con PHP):
  Inserisci come snippet HTML nel footer e usa la condizione
  integrata di WPCode per filtrare per categoria.
  In quel caso usa solo questa riga:
  
  <script src="https://ardyagent.ardy-lab.it/ardy-widget-lavorazione.js" defer></script>
-->
