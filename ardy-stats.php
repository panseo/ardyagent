<?php
// -----------------------------------------------------------
// ARDY LAB — Statistiche visite pagine (protetta da Basic Auth via .htaccess)
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-db.php';
date_default_timezone_set('Europe/Rome');
header('Content-Type: text/html; charset=utf-8');

$db = ardyDB();
// Tabella visite_pagina creata da ardy-migrate.php.

// Etichette leggibili per le pagine note
$labels = ['chat-lead' => 'Chat lead — "Parla subito con Sole"'];

// Totali per pagina
$totali = $db->query("
    SELECT pagina,
           SUM(visite) AS visite,
           SUM(unici)  AS unici,
           SUM(CASE WHEN giorno = CURDATE() THEN visite ELSE 0 END) AS visite_oggi,
           SUM(CASE WHEN giorno = CURDATE() THEN unici  ELSE 0 END) AS unici_oggi,
           SUM(CASE WHEN giorno >= CURDATE() - INTERVAL 6 DAY THEN visite ELSE 0 END) AS visite_7,
           SUM(CASE WHEN giorno >= CURDATE() - INTERVAL 6 DAY THEN unici  ELSE 0 END) AS unici_7
    FROM visite_pagina
    GROUP BY pagina
    ORDER BY visite DESC
")->fetchAll();

// Ultimi 14 giorni (per il dettaglio giornaliero)
$giorni = $db->query("
    SELECT pagina, giorno, visite, unici
    FROM visite_pagina
    WHERE giorno >= CURDATE() - INTERVAL 13 DAY
    ORDER BY giorno DESC, pagina ASC
")->fetchAll();

function lbl(string $p, array $labels): string {
    return htmlspecialchars($labels[$p] ?? $p);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statistiche visite — Ardy Lab</title>
<style>
  :root { --accent:#c8a96e; --bg:#0e0e0e; --surface:#1a1a1a; --border:#333; --dim:#888; }
  body { background:var(--bg); color:#eee; font-family:'DM Sans',Arial,sans-serif; margin:0; padding:24px; }
  h1 { color:var(--accent); font-size:20px; margin:0 0 4px; }
  .sub { color:var(--dim); font-size:13px; margin:0 0 24px; }
  .cards { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:28px; }
  .card { background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:18px 22px; min-width:200px; }
  .card h2 { font-size:14px; margin:0 0 12px; color:#fff; }
  .stat-row { display:flex; justify-content:space-between; gap:18px; font-size:13px; padding:4px 0; border-bottom:1px dashed #2a2a2a; }
  .stat-row:last-child { border-bottom:none; }
  .stat-row .n { color:var(--accent); font-weight:600; font-family:'DM Mono',monospace; }
  .stat-row .lbl { color:var(--dim); }
  table { border-collapse:collapse; width:100%; max-width:640px; margin-top:8px; }
  th,td { border:1px solid var(--border); padding:6px 10px; text-align:left; font-size:13px; }
  th { background:#222; color:var(--accent); }
  td.num { font-family:'DM Mono',monospace; text-align:right; }
  .empty { color:var(--dim); font-style:italic; }
</style>
</head>
<body>
  <h1>📊 Statistiche visite</h1>
  <p class="sub">Pagine con widget Ardy · fuso Europe/Rome · oggi: <?= date('d/m/Y') ?></p>

  <?php if (!$totali): ?>
    <p class="empty">Ancora nessuna visita registrata. Apri la pagina lead per generare il primo dato.</p>
  <?php else: ?>
    <div class="cards">
      <?php foreach ($totali as $t): ?>
        <div class="card">
          <h2><?= lbl($t['pagina'], $labels) ?></h2>
          <div class="stat-row"><span class="lbl">Oggi</span><span class="n"><?= (int)$t['visite_oggi'] ?> visite · <?= (int)$t['unici_oggi'] ?> unici</span></div>
          <div class="stat-row"><span class="lbl">Ultimi 7 giorni</span><span class="n"><?= (int)$t['visite_7'] ?> visite · <?= (int)$t['unici_7'] ?> unici</span></div>
          <div class="stat-row"><span class="lbl">Totale</span><span class="n"><?= (int)$t['visite'] ?> visite · <?= (int)$t['unici'] ?> unici</span></div>
        </div>
      <?php endforeach; ?>
    </div>

    <h2 style="font-size:15px;color:#fff;">Dettaglio ultimi 14 giorni</h2>
    <table>
      <tr><th>Giorno</th><th>Pagina</th><th>Visite</th><th>Unici</th></tr>
      <?php foreach ($giorni as $g): ?>
        <tr>
          <td><?= htmlspecialchars(date('d/m/Y', strtotime($g['giorno']))) ?></td>
          <td><?= lbl($g['pagina'], $labels) ?></td>
          <td class="num"><?= (int)$g['visite'] ?></td>
          <td class="num"><?= (int)$g['unici'] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</body>
</html>
