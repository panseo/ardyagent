<?php
// -----------------------------------------------------------
// ARDY LAB — Lookup numero WhatsApp
// Dato un numero (come arriva dalla Cloud API, es. "393519677973")
// dice a n8n con chi sta parlando e fornisce il contesto:
//   mode = lead | cliente | cliente_lavorazione
// Così n8n sceglie il prompt giusto (vedi ardy-whatsapp-system.txt).
//
// Chiamato server-to-server da n8n. Se in ardy-config.php è definito
// WA_LOOKUP_SECRET, va passato nell'header X-Ardy-Secret.
// -----------------------------------------------------------

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

header('Content-Type: application/json');

// Protezione opzionale via segreto condiviso (consigliata)
if (defined('WA_LOOKUP_SECRET') && WA_LOOKUP_SECRET !== '') {
    $sent = $_SERVER['HTTP_X_ARDY_SECRET'] ?? ($_GET['secret'] ?? '');
    if (!hash_equals(WA_LOOKUP_SECRET, (string)$sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'non autorizzato']);
        exit();
    }
}

// Numero: da querystring (?phone=) o da body JSON {"phone": "..."}
$phone = $_GET['phone'] ?? '';
if ($phone === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $phone = $in['phone'] ?? '';
}
$digits = preg_replace('/\D+/', '', (string)$phone);
if ($digits === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'phone mancante']);
    exit();
}

// Match robusto: confronta le ultime 9 cifre (ignora prefisso +39 / spazi nel DB)
$last9 = substr($digits, -9);

// ── È la titolare (Michela)? → modalità assistente personale, non lead ──
$ownerDigits = defined('WA_MICHELA_NUMBER') ? preg_replace('/\D+/', '', (string)WA_MICHELA_NUMBER) : '';
if ($ownerDigits !== '' && $last9 === substr($ownerDigits, -9)) {
    $riepilogo = '(riepilogo non disponibile al momento)';
    try { $riepilogo = ardy_riepilogo_settimana(ardyDB()); }
    catch (PDOException $e) { error_log('ARDY WA RIEPILOGO ERROR: ' . $e->getMessage()); }
    echo json_encode([
        'success'       => true,
        'mode'          => 'titolare',
        'cliente'       => null,
        'system_prompt' => ardy_wa_prompt_titolare($riepilogo),
    ]);
    exit();
}

try {
    $db = ardyDB();
    $stmt = $db->prepare(
        "SELECT session_id, nome, cognome, telefono, email, servizio, mobile, stato,
                wp_post_id, wp_post_link
           FROM clienti
          WHERE REPLACE(REPLACE(REPLACE(REPLACE(telefono,' ',''),'+',''),'-',''),'.','') LIKE :p
       ORDER BY updated_at DESC, id DESC
          LIMIT 1"
    );
    $stmt->execute([':p' => '%' . $last9]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ARDY WA LOOKUP ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'errore database']);
    exit();
}

// Riepilogo operativo per la titolare: foto sintetica dal CRM (ultimi 7 giorni + quadro).
// Ogni blocco è difensivo: se una tabella/colonna manca, viene saltato senza errori.
function ardy_riepilogo_settimana(PDO $db): string {
    $out = [];

    // Nuovi contatti ultimi 7 giorni
    try {
        $rows = $db->query("SELECT nome, cognome, zona, servizio, stato, created_at
                              FROM clienti
                             WHERE created_at >= (NOW() - INTERVAL 7 DAY)
                          ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $out[] = "NUOVI CONTATTI (ultimi 7 giorni): " . count($rows);
        foreach (array_slice($rows, 0, 12) as $r) {
            $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
            $out[] = "- {$nome} · " . ($r['servizio'] ?: '—') . " · " . ($r['zona'] ?: '—')
                   . " · stato " . ($r['stato'] ?: '-') . " (" . substr((string)$r['created_at'], 0, 10) . ")";
        }
    } catch (PDOException $e) { /* salta */ }

    // Quadro clienti per stato
    try {
        $byStato = $db->query("SELECT COALESCE(NULLIF(stato,''),'-') s, COUNT(*) c FROM clienti GROUP BY s")
                      ->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($byStato) {
            $line = [];
            foreach ($byStato as $s => $c) $line[] = "{$s}: {$c}";
            $out[] = "QUADRO CLIENTI per stato: " . implode(' · ', $line);
        }
    } catch (PDOException $e) { /* salta */ }

    // Lavori in corso (stato ACCONTO)
    try {
        $rows = $db->query("SELECT nome, cognome, mobile FROM clienti WHERE UPPER(stato)='ACCONTO' ORDER BY updated_at DESC")
                   ->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out[] = "LAVORI IN CORSO (ACCONTO): " . count($rows);
            foreach (array_slice($rows, 0, 10) as $r) {
                $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
                $out[] = "- {$nome}" . ($r['mobile'] ? " · " . $r['mobile'] : '');
            }
        }
    } catch (PDOException $e) { /* salta */ }

    // Follow-up / sopralluoghi in agenda (prossimi)
    try {
        $rows = $db->query("SELECT nome, cognome, zona, data_followup FROM clienti
                             WHERE data_followup IS NOT NULL AND data_followup <> ''
                               AND data_followup >= CURDATE()
                          ORDER BY data_followup ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out[] = "FOLLOW-UP / APPUNTAMENTI in agenda:";
            foreach ($rows as $r) {
                $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
                $out[] = "- " . $r['data_followup'] . " · {$nome}" . ($r['zona'] ? " · " . $r['zona'] : '');
            }
        }
    } catch (PDOException $e) { /* salta */ }

    // Fasi pubblicate ultimi 7 giorni
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM fasi WHERE created_at >= (NOW() - INTERVAL 7 DAY)")->fetchColumn();
        $out[] = "FASI/AGGIORNAMENTI pubblicati (ultimi 7 giorni): {$n}";
    } catch (PDOException $e) { /* salta */ }

    // Morosi aperti (tabella opzionale)
    try {
        $rows = $db->query("SELECT nome_cliente, importo_dovuto FROM solleciti_pagamento WHERE stato='APERTO' ORDER BY updated_at DESC")
                   ->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out[] = "MOROSI APERTI: " . count($rows);
            foreach (array_slice($rows, 0, 10) as $r) {
                $imp = $r['importo_dovuto'] !== null ? (' · € ' . number_format((float)$r['importo_dovuto'], 2, ',', '.')) : '';
                $out[] = "- " . ($r['nome_cliente'] ?: '(senza nome)') . $imp;
            }
        }
    } catch (PDOException $e) { /* salta */ }

    return $out ? implode("\n", $out) : "(nessun dato disponibile dal CRM)";
}

// System prompt per la TITOLARE (Michela): assistente personale, non flusso lead.
function ardy_wa_prompt_titolare(string $riepilogo): string {
    $base = @file_get_contents(__DIR__ . '/ardy-system.txt') ?: '';
    $istr  = "# MODALITÀ TITOLARE — STAI PARLANDO CON MICHELA (la tua titolare)\n"
        . "Il numero che ti scrive è quello di Michela Panella, titolare di Ardy Lab. NON è un cliente: è chi comanda.\n"
        . "- Comportati come la sua assistente personale/segretaria, NON come l'assistente commerciale dei clienti.\n"
        . "- NIENTE messaggio di benvenuto da lead, NIENTE domande di qualifica, NIENTE \"vuoi informazioni su restauri o sei un cliente\".\n"
        . "- Rivolgiti a lei per nome (Michela), dalle del tu, tono confidenziale ed efficiente. Messaggi brevi (è WhatsApp).\n"
        . "- Quando ti chiede aggiornamenti (es. \"aggiornami sulla settimana\", \"come va\", \"situazione lead\", \"chi devo richiamare\"), rispondi USANDO I DATI OPERATIVI qui sotto: sintetici, concreti, azionabili. Metti in evidenza ciò che richiede attenzione (nuovi lead da richiamare, appuntamenti, morosi).\n"
        . "- Se ti chiede qualcosa che non è nei dati, dillo con onestà e indica dove guardare (la dashboard).\n"
        . "- Puoi aiutarla a ragionare, redigere messaggi/email, organizzare la giornata.\n"
        . "- I dati qui sotto sono una fotografia dal CRM al momento del messaggio: se servono dettagli più precisi, rimanda alla dashboard.\n\n"
        . "## DATI OPERATIVI ATTUALI (dal CRM)\n" . $riepilogo . "\n";
    return $istr . "\n---\n# SCHEDA INFORMATIVA DI RIFERIMENTO (servizi, prezzi, processi — solo se Michela te lo chiede)\n" . $base;
}

// Costruisce il system prompt completo per n8n:
// wrapper WhatsApp + contesto (mode/cliente) + documento principale Ardy Lab.
function ardy_wa_system_prompt(string $mode, ?array $cliente): string {
    $wrap = @file_get_contents(__DIR__ . '/ardy-whatsapp-system.txt') ?: '';
    $base = @file_get_contents(__DIR__ . '/ardy-system.txt') ?: '';
    $ctx  = "\n\n## CONTESTO DI QUESTA CONVERSAZIONE\nmode: {$mode}\n";
    if ($cliente) {
        $ctx .= "cliente:\n" . json_encode($cliente, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        $ctx .= "cliente: (nessuno — nuovo contatto)\n";
    }
    return $wrap . $ctx . "\n" . $base;
}

// Nessun match → nuovo contatto
if (!$row) {
    echo json_encode([
        'success'       => true,
        'mode'          => 'lead',
        'cliente'       => null,
        'system_prompt' => ardy_wa_system_prompt('lead', null),
    ]);
    exit();
}

$hasLavorazione = !empty($row['wp_post_id']);
$mode = $hasLavorazione ? 'cliente_lavorazione' : 'cliente';

// Ultima fase pubblicata (contesto per la modalità cliente_lavorazione)
$ultimaFase = null;
if ($hasLavorazione) {
    try {
        $f = $db->prepare("SELECT fase_nome, testo_generato, created_at
                             FROM fasi WHERE session_id = :sid
                         ORDER BY id DESC LIMIT 1");
        $f->execute([':sid' => $row['session_id']]);
        $ultimaFase = $f->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('ARDY WA LOOKUP FASE ERROR: ' . $e->getMessage());
    }
}

$clienteOut = [
    'session_id'   => $row['session_id'],
    'nome'         => trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? '')),
    'email'        => $row['email'] ?? '',
    'servizio'     => $row['servizio'] ?? '',
    'mobile'       => $row['mobile'] ?? '',
    'stato'        => $row['stato'] ?? '',
    'wp_post_link' => $row['wp_post_link'] ?? '',
    'ultima_fase'  => $ultimaFase,
];

echo json_encode([
    'success'       => true,
    'mode'          => $mode,
    'cliente'       => $clienteOut,
    'system_prompt' => ardy_wa_system_prompt($mode, $clienteOut),
]);
