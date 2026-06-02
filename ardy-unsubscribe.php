<?php
require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

$email = $_GET['email'] ?? '';

if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        $db = ardyDB();
        $db->prepare("UPDATE outreach_contatti SET stato='non_interessato', updated_at=NOW() WHERE email=:email")
           ->execute([':email' => $email]);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Disiscrizione — Ardy Lab</title>
<style>
  body { font-family:Georgia,serif; background:#f5f5f5; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
  .box { background:#fff; padding:48px; border-radius:4px; max-width:480px; text-align:center; }
  h2 { color:#c8a96e; font-family:sans-serif; font-size:20px; letter-spacing:2px; margin-bottom:16px; }
  p  { color:#666; font-size:15px; line-height:1.7; margin-bottom:24px; }
  a  { color:#c8a96e; }
</style>
</head>
<body>
<div class="box">
  <h2>ARDY LAB</h2>
  <?php if ($email): ?>
  <p>L'indirizzo <strong><?= htmlspecialchars($email) ?></strong> è stato rimosso dalla nostra lista di contatti.<br>Non riceverai altre comunicazioni da Ardy Lab.</p>
  <?php else: ?>
  <p>Link di disiscrizione non valido.</p>
  <?php endif; ?>
  <p><a href="https://ardy-lab.it">Torna al sito →</a></p>
</div>
</body>
</html>
