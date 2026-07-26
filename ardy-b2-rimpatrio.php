<?php
/**
 * ARDY LAB — Rimpatrio dei file da Backblaze B2 al disco locale
 *
 * Serve a staccare B2 in sicurezza. I file caricati su B2 vivono SOLO lassù (l'upload
 * va su B2 *oppure* su disco, mai su entrambi): togliere le costanti ARDY_B2_* senza
 * prima riportarli giù li renderebbe irraggiungibili per sempre. Questo script fa il
 * passo che manca — scarica ogni oggetto e riporta la riga a storage='local'.
 *
 * Ordine consigliato per il distacco:
 *   1. `define('ARDY_B2_SOLO_LETTURA', true);` in ardy-config.php  → niente file NUOVI su B2
 *   2. questo script (prima in prova, poi con --applica)           → i vecchi tornano su disco
 *   3. solo a rimpatrio finito: via le costanti ARDY_B2_* dal config
 *
 * Uso (da CLI, sul server):
 *   php ardy-b2-rimpatrio.php                 # PROVA: dice cosa farebbe, non tocca nulla
 *   php ardy-b2-rimpatrio.php --applica       # scarica davvero e aggiorna il DB
 *   php ardy-b2-rimpatrio.php --applica --limite=50
 *
 * È ripetibile: le righe già rimpatriate non vengono più selezionate. Su un file che
 * non si riesce a scaricare NON tocca il DB (la riga resta 'b2' e si può riprovare),
 * e NON cancella nulla da B2: la pulizia del bucket si fa a mano, a cose verificate.
 */

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-storage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Solo da riga di comando.\n";
    exit;
}

$applica = in_array('--applica', $argv, true);
$limite  = 0;
foreach ($argv as $a) { if (preg_match('/^--limite=(\d+)$/', $a, $m)) $limite = (int) $m[1]; }

echo "=== Rimpatrio B2 → disco " . ($applica ? "(APPLICA)" : "(PROVA: non scrivo nulla)") . " ===\n";

if (!ardyB2Configured()) {
    echo "B2 non è configurato in ardy-config.php: niente da rimpatriare (o costanti già rimosse).\n";
    exit(1);
}
if (!ardyB2ScritturaAttiva()) {
    echo "Scrittura su B2 già disattivata (ARDY_B2_SOLO_LETTURA): bene, i file nuovi restano su disco.\n";
} else {
    echo "NOTA: si sta ancora scrivendo su B2. Metti prima ARDY_B2_SOLO_LETTURA nel config,\n";
    echo "      altrimenti mentre rimpatri continuano ad arrivare file nuovi lassù.\n";
}

/**
 * Tabelle con lo strato storage. 'dir' costruisce la cartella locale di destinazione
 * dalla riga: sono le stesse convenzioni dei rispettivi endpoint (galleria, file, vendita).
 */
$tabelle = [
    'progetto_galleria' => [
        'etichetta' => 'immagini galleria (articoli WordPress)',
        'dir' => fn(array $r) => rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . (int) $r['progetto_id'] . '/galleria/',
    ],
    'progetto_file' => [
        'etichetta' => 'file di stampa e documenti',
        'dir' => fn(array $r) => rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . (int) $r['progetto_id'] . '/file/',
    ],
    'progetto_foto_vendita' => [
        'etichetta' => 'foto vendita (WooCommerce)',
        'dir' => fn(array $r) => rtrim(ARDY_UPLOAD_DIR, '/') . '/progetti/' . (int) $r['progetto_id'] . '/vendita/',
    ],
];

$db = ardyDB();
$totOk = $totKo = $totGia = 0;

foreach ($tabelle as $tabella => $conf) {
    $sql = "SELECT id, progetto_id, nome_file, storage_key FROM `$tabella`
            WHERE storage = 'b2' AND storage_key IS NOT NULL AND storage_key <> ''
            ORDER BY id ASC";
    if ($limite > 0) $sql .= " LIMIT " . $limite;

    try {
        $righe = $db->query($sql)->fetchAll();
    } catch (PDOException $e) {
        echo "  ERR  $tabella — " . $e->getMessage() . "\n";
        continue;
    }

    echo "\n-- $tabella ({$conf['etichetta']}): " . count($righe) . " da rimpatriare\n";
    if (!$righe) continue;

    foreach ($righe as $r) {
        $dir   = ($conf['dir'])($r);
        $dest  = $dir . basename((string) $r['nome_file']);
        $chiave = (string) $r['storage_key'];

        // Copia locale già presente (dual-write di qualche passaggio precedente):
        // basta riportare la riga su 'local', senza riscaricare niente.
        if (is_file($dest) && filesize($dest) > 0) {
            echo "  già su disco  #{$r['id']} " . basename($dest) . "\n";
            if ($applica) {
                $db->prepare("UPDATE `$tabella` SET storage = 'local', storage_key = NULL WHERE id = ?")
                   ->execute([$r['id']]);
            }
            $totGia++;
            continue;
        }

        if (!$applica) { echo "  da scaricare  #{$r['id']} $chiave\n"; $totOk++; continue; }

        $bytes = ardyStorageGet($chiave);
        if ($bytes === null || $bytes === '') {
            // ardyStorageGet ha già loggato HTTP + errore curl: è lì la diagnosi.
            echo "  FALLITO       #{$r['id']} $chiave — non scaricabile (riga lasciata su 'b2')\n";
            $totKo++;
            continue;
        }
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            echo "  FALLITO       #{$r['id']} — cartella non creabile: $dir\n";
            $totKo++;
            continue;
        }
        if (file_put_contents($dest, $bytes) === false) {
            echo "  FALLITO       #{$r['id']} — scrittura su disco non riuscita: $dest\n";
            $totKo++;
            continue;
        }
        // Solo a file scritto si tocca il DB: se qualcosa va storto, la riga resta 'b2'.
        $db->prepare("UPDATE `$tabella` SET storage = 'local', storage_key = NULL WHERE id = ?")
           ->execute([$r['id']]);
        echo "  OK            #{$r['id']} " . basename($dest) . ' (' . strlen($bytes) . " byte)\n";
        $totOk++;
    }
}

echo "\n=== Fine: $totOk " . ($applica ? "rimpatriati" : "da rimpatriare") . ", $totGia già su disco, $totKo falliti ===\n";
if (!$applica) {
    echo "Questa era una PROVA. Per farlo davvero: php ardy-b2-rimpatrio.php --applica\n";
}
if ($totKo > 0) {
    echo "Sui falliti guarda il log PHP: cerca 'ARDY B2 GET' — c'è il codice HTTP che spiega perché.\n";
    echo "Se falliscono TUTTI, il problema è la lettura da B2 (credenziali, endpoint, orologio\n";
    echo "del server o firewall in uscita), non i singoli file: risolvi quello e rilancia.\n";
}
// Le foto delle fasi-racconto passate da «libera spazio disco» vivono anch'esse su B2
// ma senza colonna storage (il serving prova disco e poi B2 con la chiave calcolata):
// non sono qui dentro. Sono le meno critiche — quelle foto stanno già sull'articolo
// WordPress — ma vanno ricordate prima di svuotare il bucket.
echo "\nPromemoria: le foto delle fasi già passate da «libera spazio disco» non sono in queste\n";
echo "tabelle (non hanno colonna storage). Prima di svuotare il bucket, tienine conto.\n";
