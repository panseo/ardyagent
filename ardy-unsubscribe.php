<?php
require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

$email = $_GET['email'] ?? '';
$token = $_GET['t'] ?? '';

// Verifica firma: solo chi ha ricevuto il link può disiscriversi
// (impedisce di disiscrivere indirizzi altrui passandoli a mano)
$secret   = defined('ARDY_UNSUB_SECRET') ? ARDY_UNSUB_SECRET : (defined('ARDY_API_KEY') ? ARDY_API_KEY : '');
$expected = ($email !== '' && $secret !== '')
    ? substr(hash_hmac('sha256', strtolower(trim($email)), $secret), 0, 20)
    : '';
$valid = $email !== '' && $token !== '' && $expected !== ''
    && hash_equals($expected, $token)
    && filter_var($email, FILTER_VALIDATE_EMAIL);

if ($valid) {
    try {
        $db = ardyDB();
        $db->prepare("UPDATE outreach_contatti SET stato='non_interessato', updated_at=NOW() WHERE email=:email")
           ->execute([':email' => $email]);
    } catch (Exception $e) {
        error_log('ARDY UNSUB ERROR: ' . $e->getMessage());
    }
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
  <?php if ($valid): ?>
  <p>L'indirizzo <strong><?= htmlspecialchars($email) ?></strong> è stato rimosso dalla nostra lista di contatti.<br>Non riceverai altre comunicazioni da Ardy Lab.</p>
  <?php else: ?>
  <p>Link di disiscrizione non valido o scaduto.<br>Per essere rimosso scrivi a <a href="mailto:marketing@ardy-lab.it">marketing@ardy-lab.it</a>.</p>
  <?php endif; ?>
  <p><a href="https://ardy-lab.it">Torna al sito →</a></p>
</div>
</body>
</html>
