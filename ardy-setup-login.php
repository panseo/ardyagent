<?php
// -----------------------------------------------------------
// ARDY LAB — Setup Login (paginetta temporanea)
// Crea il file .htpasswd con utente + password per l'area riservata.
// ⚠️ DOPO L'USO, CANCELLA QUESTO FILE DAL SERVER.
// -----------------------------------------------------------

$htpasswdPath = __DIR__ . '/.htpasswd';
$messaggio    = '';
$ok           = false;

// Se il login è già configurato, non permettere di sovrascriverlo da qui.
$giaConfigurato = file_exists($htpasswdPath);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$giaConfigurato) {
    $utente   = trim($_POST['utente'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($utente === '' || $password === '') {
        $messaggio = 'Inserisci sia utente che password.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $utente)) {
        $messaggio = 'L\'utente può contenere solo lettere, numeri, punto, trattino e underscore.';
    } elseif (strlen($password) < 8) {
        $messaggio = 'La password deve avere almeno 8 caratteri.';
    } else {
        $hash  = password_hash($password, PASSWORD_BCRYPT);
        $linea = $utente . ':' . $hash . "\n";
        if (file_put_contents($htpasswdPath, $linea) !== false) {
            $ok = true;
            $messaggio = 'Login creato correttamente.';
            $giaConfigurato = true;
        } else {
            $messaggio = 'Errore: non riesco a scrivere il file. Controlla i permessi della cartella.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ardy Lab — Setup Login</title>
<style>
  body { font-family: system-ui, sans-serif; background:#f4f1ea; color:#333; max-width:520px; margin:40px auto; padding:0 20px; line-height:1.6; }
  h1 { font-size:20px; color:#c8a96e; }
  .box { background:#fff; border:1px solid #e3ddd0; border-radius:10px; padding:24px; }
  label { display:block; font-size:13px; margin:14px 0 4px; font-weight:600; }
  input { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px; font-size:15px; box-sizing:border-box; }
  button { margin-top:18px; background:#c8a96e; color:#0e0e0e; border:none; padding:12px 20px; border-radius:6px; font-size:15px; font-weight:600; cursor:pointer; width:100%; }
  .msg { padding:12px 14px; border-radius:6px; margin-bottom:16px; font-size:14px; }
  .msg.ok { background:#e6f6ea; border:1px solid #58d68d; color:#1d7a3e; }
  .msg.err { background:#fdeaea; border:1px solid #e88; color:#a33; }
  .path { font-family:monospace; font-size:12px; background:#f7f5ef; padding:8px; border-radius:4px; word-break:break-all; }
  .warn { margin-top:22px; background:#fff7e6; border:1px solid #e0c068; border-radius:8px; padding:14px; font-size:14px; }
</style>
</head>
<body>
<div class="box">
  <h1>🔐 Crea il login dell'area riservata</h1>

  <?php if ($messaggio): ?>
    <div class="msg <?= $ok ? 'ok' : 'err' ?>"><?= htmlspecialchars($messaggio) ?></div>
  <?php endif; ?>

  <?php if ($ok): ?>
    <p>Il file è stato scritto in:</p>
    <p class="path"><?= htmlspecialchars($htpasswdPath) ?></p>
    <p>Controlla che nel file <strong>.htaccess</strong> ci sia esattamente questa riga:</p>
    <p class="path">AuthUserFile <?= htmlspecialchars($htpasswdPath) ?></p>
    <div class="warn">
      ✅ <strong>Ora carica il nuovo .htaccess</strong> (se non l'hai già fatto), prova ad aprire la dashboard:
      ti deve chiedere utente e password.<br><br>
      🗑️ <strong>Poi CANCELLA questo file</strong> (<code>ardy-setup-login.php</code>) dal server: non serve più.
    </div>
  <?php elseif ($giaConfigurato): ?>
    <div class="msg ok">Il login è già stato configurato.</div>
    <div class="warn">
      🗑️ Per sicurezza, <strong>cancella questo file</strong> (<code>ardy-setup-login.php</code>) dal server.<br><br>
      Se vuoi cambiare utente/password, prima cancella il file <code>.htpasswd</code> dal server, poi ricarica questa pagina.
    </div>
  <?php else: ?>
    <p style="font-size:14px;">Scegli un utente e una password. Li userai per entrare nella dashboard.
    Tienili da parte (anche su un foglio).</p>
    <form method="post">
      <label>Utente</label>
      <input type="text" name="utente" autocomplete="off" placeholder="es. michela" required>
      <label>Password (almeno 8 caratteri)</label>
      <input type="text" name="password" autocomplete="off" placeholder="scegli una password" required>
      <button type="submit">Crea il login</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
