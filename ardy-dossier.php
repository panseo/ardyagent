<?php
// -----------------------------------------------------------
// ARDY LAB — Dossier cliente in Markdown
// -----------------------------------------------------------
// Assembla, per un dato session_id, un quadro COMPLETO del cliente in Markdown:
//   anagrafica/servizio/stato, preventivo/i, fasi di lavorazione, chat WhatsApp.
// Serve a dare a Sole (e a Michela) un contesto immediato, sia da chat web che WA.
//
// Uso:
//   - come LIBRERIA: include il file e chiama ardy_genera_dossier($db, $sessionId).
//   - come ENDPOINT HTTP: GET/POST con session_id.
//       ?format=md (default) → text/markdown ; ?format=json → {markdown, meta}
//       ?save=1 → salva anche in dossier/<session>.md
//
// Accesso (dati personali → protetto):
//   - dal browser/dashboard: Basic Auth via .htaccess (come gli altri endpoint).
//   - server-to-server (es. il prompt di Sole): header X-Ardy-Internal = ARDY_INTERNAL_SECRET.
//
// NOTA: la chat WEB non è ancora persistita (il proxy web è stateless) → nel
// dossier compare solo la chat WhatsApp. Vedi TODO "persistenza chat web".
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';

date_default_timezone_set('Europe/Rome');

// --- helper interni ---------------------------------------------------------

function ardy_dossier_clean_session($s) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $s);
}

// Solo cifre, ultime 9 (per match telefono robusto come negli altri lookup)
function ardy_dossier_last9($tel) {
    $d = preg_replace('/\D/', '', (string) $tel);
    return strlen($d) > 9 ? substr($d, -9) : $d;
}

function ardy_dossier_data($v) {
    if (!$v) return '';
    try { return (new DateTime($v))->format('d/m/Y'); } catch (Exception $e) { return (string) $v; }
}

function ardy_dossier_euro($v) {
    if ($v === null || $v === '') return '';
    return '€ ' . number_format((float) $v, 2, ',', '.');
}

// Tronca un testo lungo (per il budget token quando finisce nel prompt di Sole)
function ardy_dossier_tronca($t, $max = 1200) {
    $t = trim((string) $t);
    if (mb_strlen($t) <= $max) return $t;
    return mb_substr($t, 0, $max) . '…';
}

/**
 * Genera il dossier Markdown del cliente. Ritorna la stringa Markdown,
 * oppure null se non esiste alcuna scheda per quel session_id.
 *
 * @param bool $perCliente  Se true → versione "client-safe": esclude le NOTE
 *   INTERNE di Michela (da non mostrare al cliente). Tutto il resto sono dati
 *   del cliente stesso (suoi preventivi/fasi/chat) → ok come contesto per Sole.
 * @param bool $senzaChat   Se true → omette le sezioni chat (WhatsApp/web). Usato
 *   quando il dossier viene iniettato in una conversazione LIVE (web/WhatsApp), dove
 *   la cronologia è già presente: evita di mandarla due volte (token sprecati).
 */
function ardy_genera_dossier(PDO $db, string $sessionId, bool $perCliente = false, bool $senzaChat = false): ?string {
    $sid = ardy_dossier_clean_session($sessionId);
    if ($sid === '') return null;

    // 1) Anagrafica cliente
    $q = $db->prepare("SELECT * FROM clienti WHERE session_id = :sid LIMIT 1");
    $q->execute([':sid' => $sid]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;

    $nome = trim(($c['nome'] ?? '') . ' ' . ($c['cognome'] ?? ''));
    if ($nome === '') $nome = $sid;

    $md  = "# Dossier cliente — {$nome}\n";
    $md .= '_Generato il ' . date('d/m/Y H:i') . " · session `{$sid}`_\n\n";

    // --- Anagrafica e stato ---
    $md .= "## Anagrafica e stato\n";
    $righe = [
        'Stato'        => $c['stato']     ?? '',
        'Servizio'     => $c['servizio']  ?? '',
        'Mobile'       => $c['mobile']    ?? '',
        'Zona'         => $c['zona']      ?? '',
        'Telefono'     => $c['telefono']  ?? '',
        'Email'        => $c['email']     ?? '',
        'Indirizzo'    => $c['indirizzo'] ?? '',
        'Budget'       => $c['budget']    ?? '',
        'Sopralluogo'  => ardy_dossier_data($c['sopralluogo_at'] ?? ''),
        'Inizio lavoro'        => ardy_dossier_data($c['inizio_lavoro'] ?? ''),
        'Fine lavoro prevista' => ardy_dossier_data($c['fine_lavoro_prevista'] ?? ''),
        'Pagina lavoro'        => $c['wp_post_link'] ?? '',
    ];
    foreach ($righe as $k => $v) {
        if (trim((string) $v) !== '') $md .= "- **{$k}:** {$v}\n";
    }
    // Note interne SOLO nella versione per Michela (mai client-safe).
    if (!$perCliente && trim((string) ($c['note'] ?? '')) !== '') {
        $md .= "\n**Note interne:** " . ardy_dossier_tronca($c['note'], 800) . "\n";
    }
    // Note consegna (materiali mancanti, logistica) — interne, mai al cliente.
    if (!$perCliente && trim((string) ($c['note_consegna'] ?? '')) !== '') {
        $md .= "\n**📦 Note consegna (cosa serve/manca per consegnare):** " . ardy_dossier_tronca($c['note_consegna'], 800) . "\n";
    }
    $md .= "\n";

    // 2) Preventivi
    try {
        $qp = $db->prepare("SELECT numero, oggetto, stato, grand_total, voci_json, data_emissione, created_at
                            FROM preventivi WHERE session_id = :sid ORDER BY created_at DESC");
        $qp->execute([':sid' => $sid]);
        $prevs = $qp->fetchAll(PDO::FETCH_ASSOC);
        if ($prevs) {
            $md .= "## Preventivi (" . count($prevs) . ")\n";
            foreach ($prevs as $p) {
                $data = ardy_dossier_data($p['data_emissione'] ?: ($p['created_at'] ?? ''));
                $md .= "### " . ($p['numero'] ?: '—') . ' · ' . ($p['oggetto'] ?: 'Preventivo')
                     . ($data ? " · {$data}" : '') . "\n";
                $md .= "- Stato: " . ($p['stato'] ?: '—') . "\n";
                if ($p['grand_total'] !== null && $p['grand_total'] !== '')
                    $md .= "- Totale: " . ardy_dossier_euro($p['grand_total']) . "\n";
                // Voci (se decodificabili): elenco sintetico descrizione + prezzo
                $voci = json_decode((string) ($p['voci_json'] ?? ''), true);
                if (is_array($voci)) {
                    // voci_json può essere annidato (opzioni); estraggo le descrizioni note
                    $linee = ardy_dossier_estrai_voci($voci);
                    if ($linee) {
                        $md .= "- Voci:\n";
                        foreach (array_slice($linee, 0, 20) as $l) $md .= "  - {$l}\n";
                    }
                }
                $md .= "\n";
            }
        }
    } catch (PDOException $e) { error_log('ARDY DOSSIER preventivi: ' . $e->getMessage()); }

    // 3) Fasi di lavorazione (la "prova" del lavoro svolto)
    // Per la versione cliente (perCliente) si mostrano SOLO le fasi pubblicate:
    // le bozze non devono mai arrivare al cliente (coerente col widget pubblico).
    try {
        $soloPubblicate = $perCliente ? " AND COALESCE(stato,'pubblicata') = 'pubblicata'" : "";
        $qf = $db->prepare("SELECT fase_nome, fase_tipo, testo_generato, testo_breve, created_at
                            FROM fasi WHERE session_id = :sid" . $soloPubblicate . " ORDER BY created_at ASC");
        $qf->execute([':sid' => $sid]);
        $fasi = $qf->fetchAll(PDO::FETCH_ASSOC);
        if ($fasi) {
            $md .= "## Fasi di lavorazione (" . count($fasi) . ")\n";
            foreach ($fasi as $f) {
                $tag  = (($f['fase_tipo'] ?? '') === 'comunicazione') ? '⚠ Comunicazione' : 'Fase';
                $data = ardy_dossier_data($f['created_at'] ?? '');
                $md .= "### {$tag}: " . ($f['fase_nome'] ?: '—') . ($data ? " · {$data}" : '') . "\n";
                $testo = $f['testo_generato'] ?: ($f['testo_breve'] ?? '');
                if (trim((string) $testo) !== '') $md .= ardy_dossier_tronca($testo, 700) . "\n";
                $md .= "\n";
            }
        }
    } catch (PDOException $e) { error_log('ARDY DOSSIER fasi: ' . $e->getMessage()); }

    // 4) Chat WhatsApp (match per telefono; web non ancora persistita)
    $tel9 = ardy_dossier_last9($c['telefono'] ?? '');
    if (!$senzaChat && $tel9 !== '') {
        try {
            $qw = $db->prepare(
                "SELECT role, content, created_at FROM wa_messaggi
                 WHERE RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = :t9
                 ORDER BY id DESC LIMIT 40");
            $qw->execute([':t9' => $tel9]);
            $msgs = array_reverse($qw->fetchAll(PDO::FETCH_ASSOC));
            if ($msgs) {
                $md .= "## Chat WhatsApp (ultimi " . count($msgs) . " messaggi)\n";
                foreach ($msgs as $m) {
                    $who = (($m['role'] ?? '') === 'assistant') ? 'Sole' : 'Cliente';
                    $md .= "- **{$who}:** " . ardy_dossier_tronca(str_replace("\n", ' ', $m['content']), 400) . "\n";
                }
                $md .= "\n";
            }
        } catch (PDOException $e) {
            // REGEXP_REPLACE richiede MySQL 8+/MariaDB 10.0.5+: fallback su LIKE
            error_log('ARDY DOSSIER wa (regexp): ' . $e->getMessage());
            try {
                $qw = $db->prepare(
                    "SELECT role, content, created_at FROM wa_messaggi
                     WHERE phone LIKE :like ORDER BY id DESC LIMIT 40");
                $qw->execute([':like' => '%' . $tel9]);
                $msgs = array_reverse($qw->fetchAll(PDO::FETCH_ASSOC));
                if ($msgs) {
                    $md .= "## Chat WhatsApp (ultimi " . count($msgs) . " messaggi)\n";
                    foreach ($msgs as $m) {
                        $who = (($m['role'] ?? '') === 'assistant') ? 'Sole' : 'Cliente';
                        $md .= "- **{$who}:** " . ardy_dossier_tronca(str_replace("\n", ' ', $m['content']), 400) . "\n";
                    }
                    $md .= "\n";
                }
            } catch (PDOException $e2) { error_log('ARDY DOSSIER wa (like): ' . $e2->getMessage()); }
        }
    }

    // 5) Chat WEB (per session_id) — ora persistita da ardy-proxy.php
    if (!$senzaChat) try {
        require_once __DIR__ . '/ardy-web-memoria.php';
        $web = ardy_web_storico($db, $sid, 40);
        if ($web) {
            $md .= "## Chat web (ultimi " . count($web) . " messaggi)\n";
            foreach ($web as $m) {
                $who = (($m['role'] ?? '') === 'assistant') ? 'Sole' : 'Cliente';
                $md .= "- **{$who}:** " . ardy_dossier_tronca(str_replace("\n", ' ', $m['content']), 400) . "\n";
            }
            $md .= "\n";
        }
    } catch (Throwable $e) { error_log('ARDY DOSSIER web chat: ' . $e->getMessage()); }

    return $md;
}

// Estrae righe leggibili "descrizione — prezzo" da una struttura voci_json
// (tollerante: gestisce array piatti e annidati a opzioni).
function ardy_dossier_estrai_voci($voci): array {
    $out = [];
    $walk = function ($node) use (&$walk, &$out) {
        if (!is_array($node)) return;
        $descr = $node['descrizione'] ?? $node['descr'] ?? $node['nome'] ?? null;
        $prezzo = $node['prezzo'] ?? $node['importo'] ?? $node['totale'] ?? null;
        if ($descr !== null && is_string($descr) && trim($descr) !== '') {
            $riga = trim($descr);
            if ($prezzo !== null && $prezzo !== '') $riga .= ' — ' . ardy_dossier_euro($prezzo);
            $out[] = $riga;
            return; // non scendo oltre una voce-foglia
        }
        foreach ($node as $v) { if (is_array($v)) $walk($v); }
    };
    $walk($voci);
    return $out;
}

// ===========================================================================
// MODALITÀ ENDPOINT HTTP (se chiamato direttamente)
// ===========================================================================
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {

    // Auth server-to-server opzionale (oltre al Basic Auth del .htaccess)
    $internalOk = defined('ARDY_INTERNAL_SECRET')
        && hash_equals(ARDY_INTERNAL_SECRET, (string) ($_SERVER['HTTP_X_ARDY_INTERNAL'] ?? ''));

    $sessionId = $_GET['session_id'] ?? $_POST['session_id'] ?? '';
    if ($sessionId === '') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (is_array($body)) $sessionId = $body['session_id'] ?? '';
    }

    $format     = strtolower((string) ($_GET['format'] ?? 'md'));
    $save       = !empty($_GET['save']);
    $perCliente = !empty($_GET['cliente']);

    if (trim((string) $sessionId) === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'session_id mancante']);
        exit();
    }

    try {
        $db = ardyDB();
        $markdown = ardy_genera_dossier($db, $sessionId, $perCliente);
    } catch (Throwable $e) {
        error_log('ARDY DOSSIER ERROR: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Errore interno']);
        exit();
    }

    if ($markdown === null) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Nessuna scheda per questa sessione']);
        exit();
    }

    // Salvataggio opzionale su file (dossier/<session>.md)
    if ($save) {
        $dir = __DIR__ . '/dossier';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/' . ardy_dossier_clean_session($sessionId) . '.md', $markdown);
    }

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'markdown' => $markdown], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/markdown; charset=utf-8');
        echo $markdown;
    }
    exit();
}
