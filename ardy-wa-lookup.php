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

// Calendar opzionale: incluso solo se le credenziali ci sono (altrimenti ardy-gcal.php
// va in errore sulle costanti). Deve stare a livello GLOBALE: il file usa variabili
// globali per le credenziali, che non si aggancerebbero se incluso dentro una funzione.
if (defined('ARDY_GCAL_CLIENT_ID') && defined('ARDY_GCAL_CLIENT_SECRET')) {
    require_once __DIR__ . '/ardy-gcal.php';
}

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
        // LEGACY: prompt completo e self-contained (com'è oggi su n8n). Non cacheabile.
        'system_prompt' => ardy_wa_prompt_titolare($riepilogo),
        // PROMPT CACHING (migrazione n8n): usare questi due al posto di system_prompt.
        //  - system_static  → blocco `system` con cache_control ephemeral (statico)
        //  - crm_context    → in un MESSAGGIO separato (NON nel system), volatile
        'system_static' => ardy_wa_prompt_titolare_static(),
        'crm_context'   => "## DATI OPERATIVI ATTUALI (dal CRM)\n" . $riepilogo,
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

    // Impegni in CALENDARIO (oggi e domani) — in cima: è la cosa più impellente per Michela.
    // Distingue sopralluoghi/consulenze dal titolo dell'evento.
    try {
        if (function_exists('gcal_list_events')) {
            $tz   = new DateTimeZone('Europe/Rome');
            $from = new DateTime('today', $tz);
            $to   = new DateTime('today +2 days', $tz); // oggi + domani interi
            $eventi = gcal_list_events($from, $to);
            if (is_array($eventi) && $eventi) {
                $oggiStr   = (new DateTime('today', $tz))->format('Y-m-d');
                $domaniStr = (new DateTime('today +1 day', $tz))->format('Y-m-d');
                $righe = [];
                foreach ($eventi as $ev) {
                    $gStr   = date('Y-m-d', $ev['start']);
                    $quando = $gStr === $oggiStr ? 'OGGI' : ($gStr === $domaniStr ? 'domani' : date('d/m', $ev['start']));
                    $ora    = $ev['all_day'] ? 'tutto il giorno' : date('H:i', $ev['start']);
                    $low    = mb_strtolower($ev['summary']);
                    $tipo   = strpos($low, 'sopralluogo') !== false ? '🏠 '
                            : (strpos($low, 'consulenz') !== false ? '💬 ' : '📌 ');
                    $loc    = $ev['location'] !== '' ? ' · ' . $ev['location'] : '';
                    $righe[] = "- {$quando} {$ora} · {$tipo}{$ev['summary']}{$loc}";
                }
                $out[] = "📅 IMPEGNI IN CALENDARIO (oggi e domani): " . count($righe);
                foreach (array_slice($righe, 0, 12) as $r) $out[] = $r;
            }
        }
    } catch (Throwable $e) { error_log('ARDY WA RIEPILOGO GCAL: ' . $e->getMessage()); }

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

    // Lavori IN LAVORAZIONE + evidenza URGENTI: scadenza entro 4 giorni, considerando
    // SIA l'inizio (lavoro che sta per partire) SIA la fine prevista (sta per chiudere).
    // Colonne create dalla dashboard (ardy-update-lead.php): qui sono opzionali → try difensivo.
    try {
        $rows = $db->query("SELECT nome, cognome, mobile, inizio_lavoro, fine_lavoro_prevista
                              FROM clienti WHERE UPPER(stato)='IN_LAVORAZIONE'
                          ORDER BY (fine_lavoro_prevista IS NULL), fine_lavoro_prevista ASC,
                                   (inizio_lavoro IS NULL), inizio_lavoro ASC")
                   ->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $urgenti = [];
            $lista   = [];
            $oggiTs  = strtotime('today');
            foreach ($rows as $r) {
                $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
                $mob  = $r['mobile'] ? ' · ' . $r['mobile'] : '';
                $ini  = $r['inizio_lavoro'] ?? '';
                $fine = $r['fine_lavoro_prevista'] ?? '';
                $note = [];
                $urg  = false;

                if ($ini) {
                    $gg = (int)floor((strtotime($ini . ' 00:00:00') - $oggiTs) / 86400);
                    $quando = date('d/m', strtotime($ini));
                    if ($gg > 0 && $gg <= 4) { $note[] = "inizia {$quando} (fra {$gg}g)"; $urg = true; }
                    elseif ($gg === 0)       { $note[] = "inizia OGGI";                    $urg = true; }
                    elseif ($gg < 0)         { $note[] = "iniziato {$quando}"; }
                    else                     { $note[] = "inizia {$quando}"; }
                }
                if ($fine) {
                    $gg = (int)floor((strtotime($fine . ' 00:00:00') - $oggiTs) / 86400);
                    $quando = date('d/m', strtotime($fine));
                    if ($gg < 0)        { $note[] = "fine prevista {$quando} (SCADUTO da " . (-$gg) . "g)"; $urg = true; }
                    elseif ($gg === 0)  { $note[] = "fine prevista OGGI";                   $urg = true; }
                    elseif ($gg <= 4)   { $note[] = "fine prevista {$quando} (fra {$gg}g)"; $urg = true; }
                    else                { $note[] = "fine prevista {$quando} (fra {$gg}g)"; }
                }

                $line = "- {$nome}{$mob}" . ($note ? " · " . implode(" · ", $note) : '');
                if ($urg) $urgenti[] = $line;
                $lista[] = $line;
            }
            if ($urgenti) {
                $out[] = "🔴 URGENTI (entro 4 giorni — inizio o fine): " . count($urgenti);
                foreach ($urgenti as $u) $out[] = $u;
            }
            $out[] = "IN LAVORAZIONE: " . count($rows);
            foreach (array_slice($lista, 0, 12) as $l) $out[] = $l;
        }
    } catch (PDOException $e) { /* colonne assenti o altro: salta */ }

    // Sopralluoghi fissati (data VERA dal calendario, salvata in sopralluogo_at)
    try {
        $rows = $db->query("SELECT nome, cognome, zona, sopralluogo_at FROM clienti
                             WHERE sopralluogo_at IS NOT NULL AND sopralluogo_at >= NOW()
                          ORDER BY sopralluogo_at ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out[] = "SOPRALLUOGHI FISSATI (prossimi):";
            foreach ($rows as $r) {
                $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
                $quando = date('d/m/Y H:i', strtotime((string)$r['sopralluogo_at']));
                $out[] = "- {$quando} · {$nome}" . ($r['zona'] ? " · " . $r['zona'] : '');
            }
        }
    } catch (PDOException $e) { /* colonna assente o altro: salta */ }

    // Follow-up generici in agenda (campo note follow-up del CRM)
    try {
        $rows = $db->query("SELECT nome, cognome, zona, data_followup FROM clienti
                             WHERE data_followup IS NOT NULL AND data_followup <> ''
                               AND data_followup >= CURDATE()
                          ORDER BY data_followup ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out[] = "FOLLOW-UP in agenda:";
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
// Istruzioni operative per la modalità titolare (parte STATICA, identica ad ogni
// messaggio → cacheabile). $datiSeparati=true quando il riepilogo CRM volatile NON
// è incollato qui ma viaggia in un MESSAGGIO separato (per il prompt caching): in
// quel caso le istruzioni rimandano "ai dati operativi forniti" invece che "qui sotto".
function ardy_wa_titolare_istruzioni(bool $datiSeparati): string {
    $dove = $datiSeparati
        ? "che ti vengono forniti nei dati operativi di questa conversazione"
        : "qui sotto";
    return "# MODALITÀ TITOLARE — STAI PARLANDO CON MICHELA (la tua titolare)\n"
        . "Il numero che ti scrive è quello di Michela Panella, titolare di Ardy Lab. NON è un cliente: è chi comanda.\n"
        . "- Comportati come la sua assistente personale/segretaria, NON come l'assistente commerciale dei clienti.\n"
        . "- NIENTE messaggio di benvenuto da lead, NIENTE domande di qualifica, NIENTE \"vuoi informazioni su restauri o sei un cliente\".\n"
        . "- Rivolgiti a lei per nome (Michela), dalle del tu, tono confidenziale ed efficiente. Messaggi brevi (è WhatsApp).\n"
        . "- Quando ti chiede aggiornamenti (es. \"aggiornami sulla settimana\", \"come va oggi\", \"situazione lead\", \"chi devo richiamare\"), rispondi USANDO I DATI OPERATIVI {$dove}: sintetici, concreti, azionabili. Per il \"buongiorno\"/briefing del mattino apri SEMPRE con gli IMPEGNI IN CALENDARIO di oggi e con i lavori URGENTI (scadenza entro 4 giorni), poi il resto (nuovi lead da richiamare, lavori in corso, morosi).\n"
        . "- Se ti chiede qualcosa che non è nei dati, dillo con onestà e indica dove guardare (la dashboard).\n"
        . "- Puoi aiutarla a ragionare, redigere messaggi/email, organizzare la giornata.\n"
        . "- I dati operativi sono una fotografia dal CRM al momento del messaggio: se servono dettagli più precisi, rimanda alla dashboard.\n\n"
        . "## CREARE UNA SCHEDA CLIENTE (Michela ti detta un nuovo contatto)\n"
        . "Quando Michela ti dà i dati di un cliente nuovo da mettere a CRM (a voce o per iscritto, es.\n"
        . "\"segna Mario Rossi, 3331234567, vuole rilaccare una credenza, zona Prati\"), aiutala a creare la scheda:\n"
        . "1. RACCOGLI questi campi (non servono tutti, ma serve almeno nome, cognome o telefono):\n"
        . "   nome, cognome, telefono, email, indirizzo, zona, servizio, mobile (il pezzo/i mobili), stato, note.\n"
        . "   Se manca qualcosa di importante (es. il telefono) chiedilo con una sola domanda; non insistere sui dettagli minori.\n"
        . "   Per 'stato' usa uno tra LEAD, SOPRALLUOGO, PREVENTIVO, ACCONTO, STANDBY, PERSO. Se Michela non lo dice, usa LEAD.\n"
        . "2. RIPETI i dati a Michela in modo compatto e CHIEDI CONFERMA esplicita (\"Confermo e salvo?\"). NON salvare prima del sì.\n"
        . "3. SOLO dopo il sì, termina il tuo messaggio con un marker su una riga a parte, in questo formato esatto:\n"
        . "   [[CREA_SCHEDA]]{\"nome\":\"\",\"cognome\":\"\",\"telefono\":\"\",\"email\":\"\",\"indirizzo\":\"\",\"zona\":\"\",\"servizio\":\"\",\"mobile\":\"\",\"stato\":\"\",\"note\":\"\"}\n"
        . "   Riempi solo i campi noti, lascia \"\" gli altri. JSON valido su UNA riga, niente code fence.\n"
        . "   Il marker viene intercettato dal sistema e rimosso prima di mostrare il messaggio: scrivi comunque una frase\n"
        . "   naturale di conferma per Michela (\"Fatto, scheda creata ✅\") PRIMA del marker.\n"
        . "   NON usare il marker se Michela non ha ancora confermato, e non inventare dati che non ti ha dato.\n";
}

// Documento di riferimento Ardy Lab (statico).
function ardy_wa_doc_riferimento(): string {
    $base = @file_get_contents(__DIR__ . '/ardy-system.txt') ?: '';
    return "\n---\n# SCHEDA INFORMATIVA DI RIFERIMENTO (servizi, prezzi, processi — solo se Michela te lo chiede)\n" . $base;
}

// LEGACY (self-contained): istruzioni + riepilogo CRM incollato + documento.
// Mantiene il comportamento attuale di n8n (campo `system_prompt`). Il riepilogo
// in mezzo impedisce il prompt caching: per cacheare usare invece `system_static`
// (statico) + `crm_context` (volatile, in un messaggio separato) — vedi sotto.
function ardy_wa_prompt_titolare(string $riepilogo): string {
    return ardy_wa_titolare_istruzioni(false)
        . "\n## DATI OPERATIVI ATTUALI (dal CRM)\n" . $riepilogo . "\n"
        . ardy_wa_doc_riferimento();
}

// STATICO (cacheabile): istruzioni + documento, SENZA il riepilogo volatile.
// Va messo nel blocco `system` del nodo HTTP n8n con cache_control ephemeral.
function ardy_wa_prompt_titolare_static(): string {
    return ardy_wa_titolare_istruzioni(true) . ardy_wa_doc_riferimento();
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
