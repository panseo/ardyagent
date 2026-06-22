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

// ── È staff Ardy (Michela o Andrea)? → modalità assistente personale, non lead ──
// Numeri autorizzati come "titolare" (stesso prompt/permessi, cambia solo il nome).
$staff = [
    'Michela' => defined('WA_MICHELA_NUMBER') ? preg_replace('/\D+/', '', (string)WA_MICHELA_NUMBER) : '',
    'Andrea'  => defined('WA_ANDREA_NUMBER')  ? preg_replace('/\D+/', '', (string)WA_ANDREA_NUMBER)  : '',
];
$matchedName = '';
foreach ($staff as $nome => $dig) {
    if ($dig !== '' && $last9 === substr($dig, -9)) { $matchedName = $nome; break; }
}
if ($matchedName !== '') {
    $riepilogo = '(riepilogo non disponibile al momento)';
    try { $riepilogo = ardy_riepilogo_settimana(ardyDB(), array_values($staff)); }
    catch (PDOException $e) { error_log('ARDY WA RIEPILOGO ERROR: ' . $e->getMessage()); }
    echo json_encode([
        'success'       => true,
        'mode'          => 'titolare',
        'cliente'       => null,
        // LEGACY: prompt completo e self-contained (com'è oggi su n8n). Non cacheabile.
        'system_prompt' => ardy_wa_prompt_titolare($riepilogo, $matchedName),
        // PROMPT CACHING (migrazione n8n): usare questi due al posto di system_prompt.
        //  - system_static  → blocco `system` con cache_control ephemeral (statico)
        //  - crm_context    → in un MESSAGGIO separato (NON nel system), volatile
        'system_static' => ardy_wa_prompt_titolare_static($matchedName),
        'crm_context'   => "## DATI OPERATIVI ATTUALI (dal CRM)\n" . $riepilogo,
    ]);
    exit();
}

try {
    $db = ardyDB();
    ardyEnsureTelefonoLast9($db);
    $stmt = $db->prepare(
        "SELECT session_id, nome, cognome, telefono, email, servizio, mobile, zona, stato,
                wp_post_id, wp_post_link, primo_contatto_wa_at
           FROM clienti
          WHERE telefono_last9 = :p
            AND deleted_at IS NULL
       ORDER BY updated_at DESC, id DESC
          LIMIT 1"
    );
    $stmt->execute([':p' => $last9]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ARDY WA LOOKUP ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'errore database']);
    exit();
}

// Riepilogo operativo per la titolare: foto sintetica dal CRM (ultimi 7 giorni + quadro).
// Ogni blocco è difensivo: se una tabella/colonna manca, viene saltato senza errori.
function ardy_riepilogo_settimana(PDO $db, array $staffDigits = []): string {
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

    // 💬 CONVERSAZIONI RECENTI dei clienti/lead (ultime 48h) — chi ha scritto a Sole.
    // Risponde a domande tipo "i contatti di ieri ti hanno risposto?". Solo messaggi
    // IN ARRIVO (role='user'), esclusi i numeri staff (Michela/Andrea). Difensivo: se
    // le tabelle wa_messaggi/web_messaggi non esistono ancora, salta in silenzio.
    try {
        $staffLast9 = [];
        foreach ($staffDigits as $d) {
            $d = preg_replace('/\D+/', '', (string)$d);
            if ($d !== '') $staffLast9[substr($d, -9)] = true;
        }

        $conv = []; // [ ['t'=>ts, 'chi'=>..., 'msg'=>..., 'via'=>'WA'|'WEB'], ... ]

        // WhatsApp: ultimo messaggio in arrivo per numero nelle ultime 48h.
        $waRows = $db->query(
            "SELECT t.phone, t.content, t.created_at
               FROM wa_messaggi t
               JOIN (SELECT phone, MAX(id) mid FROM wa_messaggi
                      WHERE role='user' AND created_at >= (NOW() - INTERVAL 48 HOUR)
                   GROUP BY phone) x ON x.mid = t.id
           ORDER BY t.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $nameByPhone = $db->prepare(
            "SELECT TRIM(CONCAT(COALESCE(nome,''),' ',COALESCE(cognome,''))) nome, mobile
               FROM clienti WHERE telefono_last9 = :p AND deleted_at IS NULL
           ORDER BY updated_at DESC LIMIT 1"
        );
        foreach ($waRows as $r) {
            $l9 = substr(preg_replace('/\D+/', '', (string)$r['phone']), -9);
            if (isset($staffLast9[$l9])) continue; // è Michela/Andrea, non un cliente
            $chi = '+' . $r['phone'];
            try {
                $nameByPhone->execute([':p' => $l9]);
                if ($c = $nameByPhone->fetch(PDO::FETCH_ASSOC)) {
                    $nm = trim((string)$c['nome']);
                    if ($nm !== '') $chi = $nm . ($c['mobile'] ? " ({$c['mobile']})" : '');
                }
            } catch (PDOException $e) { /* salta nome */ }
            $conv[] = ['t' => strtotime((string)$r['created_at']), 'chi' => $chi, 'msg' => (string)$r['content'], 'via' => 'WA'];
        }

        // Chat sito: ultimo messaggio in arrivo per sessione nelle ultime 48h.
        try {
            $webRows = $db->query(
                "SELECT t.session_id, t.content, t.created_at
                   FROM web_messaggi t
                   JOIN (SELECT session_id, MAX(id) mid FROM web_messaggi
                          WHERE role='user' AND created_at >= (NOW() - INTERVAL 48 HOUR)
                       GROUP BY session_id) x ON x.mid = t.id
               ORDER BY t.created_at DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $nameBySession = $db->prepare(
                "SELECT TRIM(CONCAT(COALESCE(nome,''),' ',COALESCE(cognome,''))) nome
                   FROM clienti WHERE session_id = :s AND deleted_at IS NULL LIMIT 1"
            );
            foreach ($webRows as $r) {
                $chi = 'chat sito (anonimo)';
                try {
                    $nameBySession->execute([':s' => $r['session_id']]);
                    $nm = trim((string)($nameBySession->fetchColumn() ?: ''));
                    if ($nm !== '') $chi = $nm . ' (chat sito)';
                } catch (PDOException $e) { /* salta */ }
                $conv[] = ['t' => strtotime((string)$r['created_at']), 'chi' => $chi, 'msg' => (string)$r['content'], 'via' => 'WEB'];
            }
        } catch (PDOException $e) { /* tabella web_messaggi assente: salta */ }

        if ($conv) {
            usort($conv, fn($a, $b) => $b['t'] - $a['t']);
            $out[] = "💬 CONVERSAZIONI RECENTI (ultime 48h — chi ti ha scritto): " . count($conv);
            $oggiStr  = date('Y-m-d');
            $ieriStr  = date('Y-m-d', strtotime('yesterday'));
            foreach (array_slice($conv, 0, 12) as $c) {
                $gStr   = date('Y-m-d', $c['t']);
                $quando = $gStr === $oggiStr ? 'oggi' : ($gStr === $ieriStr ? 'ieri' : date('d/m', $c['t']));
                $snip   = trim(preg_replace('/\s+/', ' ', $c['msg']));
                if (mb_strlen($snip) > 90) $snip = mb_substr($snip, 0, 90) . '…';
                $out[] = "- {$c['chi']} · {$quando} " . date('H:i', $c['t']) . " · «{$snip}»";
            }
        }
    } catch (PDOException $e) { /* wa_messaggi assente o altro: salta */ }

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

    // Elenco clienti attivi con STATO ATTUALE — tabella di lookup per domande sul
    // singolo cliente (es. "Tavolo Fratino ha cambiato stato?"). Esclusi i cestinati;
    // ordinati per ultima attività (i cambi recenti finiscono in cima); cap a 30 per
    // non gonfiare il prompt. Difensivo: se 'deleted_at' non esiste, salta in silenzio.
    try {
        $rows = $db->query(
            "SELECT nome, cognome, mobile, COALESCE(NULLIF(stato,''),'-') stato, updated_at
               FROM clienti
              WHERE deleted_at IS NULL
           ORDER BY updated_at DESC, id DESC
              LIMIT 30"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out[] = "CLIENTI ATTIVI — stato attuale (prime 30 per ultima attività):";
            foreach ($rows as $r) {
                $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
                $mob  = $r['mobile'] ? ' · ' . $r['mobile'] : '';
                $out[] = "- {$nome}{$mob} · stato {$r['stato']}";
            }
        }
    } catch (PDOException $e) { /* colonna deleted_at assente o altro: salta */ }

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

    // 📦 NOTE CONSEGNA: clienti con un promemoria di consegna (materiali mancanti,
    // bulloni, logistica…). Sono le info che servono a Sole per rispondere "cosa manca
    // per consegnare a X". Mostrate solo per chi ha la nota valorizzata. Difensivo.
    try {
        $rows = $db->query(
            "SELECT nome, cognome, mobile, note_consegna
               FROM clienti
              WHERE note_consegna IS NOT NULL AND TRIM(note_consegna) <> ''
                AND deleted_at IS NULL
           ORDER BY updated_at DESC LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out[] = "📦 NOTE CONSEGNA (cosa manca/serve per consegnare): " . count($rows);
            foreach ($rows as $r) {
                $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
                $mob  = $r['mobile'] ? ' · ' . $r['mobile'] : '';
                $nota = trim(preg_replace('/\s+/', ' ', (string)$r['note_consegna']));
                if (mb_strlen($nota) > 300) $nota = mb_substr($nota, 0, 300) . '…';
                $out[] = "- {$nome}{$mob}: {$nota}";
            }
        }
    } catch (PDOException $e) { /* colonna note_consegna assente o altro: salta */ }

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

    // Fasi/aggiornamenti pubblicati di recente — CON cliente e nome fase (non solo il
    // conteggio), così Sole sa SE e SU CHI è stata pubblicata una fase.
    try {
        $rows = $db->query(
            "SELECT f.fase_nome, f.created_at,
                    TRIM(CONCAT(COALESCE(c.nome,''),' ',COALESCE(c.cognome,''))) AS cliente, c.mobile
               FROM fasi f
          LEFT JOIN clienti c ON c.session_id = f.session_id
              WHERE f.created_at >= (NOW() - INTERVAL 7 DAY)
                AND COALESCE(f.stato,'pubblicata') = 'pubblicata'
           ORDER BY f.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out[] = "FASI/AGGIORNAMENTI pubblicati (ultimi 7 giorni): " . count($rows);
        foreach (array_slice($rows, 0, 12) as $r) {
            $chi = trim((string)$r['cliente']) ?: '(cliente?)';
            $mob = $r['mobile'] ? ' · ' . $r['mobile'] : '';
            $out[] = "- " . substr((string)$r['created_at'], 0, 10) . " · {$chi}{$mob} → " . ($r['fase_nome'] ?: 'fase');
        }
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
function ardy_wa_titolare_istruzioni(bool $datiSeparati, string $nome = 'Michela'): string {
    $dove = $datiSeparati
        ? "che ti vengono forniti nei dati operativi di questa conversazione"
        : "qui sotto";
    $ruolo = $nome === 'Michela'
        ? 'Michela Panella, titolare di Ardy Lab'
        : "{$nome}, dello staff di Ardy Lab (stessi permessi della titolare)";
    return "# MODALITÀ TITOLARE — STAI PARLANDO CON {$nome} (staff Ardy Lab)\n"
        . "Il numero che ti scrive è quello di {$ruolo}. NON è un cliente: è chi comanda.\n"
        . "- Comportati come la sua assistente personale/segretaria, NON come l'assistente commerciale dei clienti.\n"
        . "- NIENTE messaggio di benvenuto da lead, NIENTE domande di qualifica, NIENTE \"vuoi informazioni su restauri o sei un cliente\".\n"
        . "- Rivolgiti a {$nome} per nome, dalle/dagli del tu, tono confidenziale ed efficiente. Messaggi brevi (è WhatsApp).\n"
        . "- Quando ti chiede aggiornamenti (es. \"aggiornami sulla settimana\", \"come va oggi\", \"situazione lead\", \"chi devo richiamare\"), rispondi USANDO I DATI OPERATIVI {$dove}: sintetici, concreti, azionabili. Per il \"buongiorno\"/briefing del mattino apri SEMPRE con gli IMPEGNI IN CALENDARIO di oggi e con i lavori URGENTI (scadenza entro 4 giorni), poi il resto (nuovi lead da richiamare, lavori in corso, morosi).\n"
        . "- Se {$nome} ti chiede se un cliente/lead ha risposto o ti ha scritto (es. \"i contatti di ieri ti hanno risposto?\"), GUARDA il blocco \"💬 CONVERSAZIONI RECENTI\" nei dati operativi: elenca chi ha scritto nelle ultime 48h (WhatsApp + chat sito) con orario e un estratto. Se un nome NON è in quel blocco, vuol dire che non ha scritto in quella finestra; dillo con onestà.\n"
        . "- Se ti chiede qualcosa che non è nei dati, dillo con onestà e indica dove guardare (la dashboard).\n"
        . "- Puoi aiutarla/aiutarlo a ragionare, redigere messaggi/email, organizzare la giornata.\n"
        . "- ⚡ IMPORTANTE: questi dati operativi sono letti DAL VIVO dal CRM a OGNI tuo messaggio, quindi sono in TEMPO REALE — NON una vecchia istantanea che {$nome} ti ha girato. Rispondi con sicurezza sullo stato ATTUALE di clienti, cambi di stato, fasi e lavori. NON dire \"non ho accesso in tempo reale alla dashboard\" e NON chiedere a {$nome} di mandarti i dati mattina e sera: ce li hai già aggiornati ad ogni messaggio. Per sapere se un cliente ha cambiato stato o ha una nuova fase, GUARDA i blocchi \"CLIENTI ATTIVI — stato attuale\" e \"FASI/AGGIORNAMENTI pubblicati\". Rimanda alla dashboard SOLO per ciò che davvero non è nei dati (es. allegati/foto o il testo integrale di una chat).\n\n"
        . "## CREARE UNA SCHEDA CLIENTE ({$nome} ti detta un nuovo contatto)\n"
        . "Quando {$nome} ti dà i dati di un cliente nuovo da mettere a CRM (a voce o per iscritto, es.\n"
        . "\"segna Mario Rossi, 3331234567, vuole rilaccare una credenza, zona Prati\"), aiutala a creare la scheda:\n"
        . "1. RACCOGLI questi campi (non servono tutti, ma serve almeno nome, cognome o telefono):\n"
        . "   nome, cognome, telefono, email, indirizzo, zona, servizio, mobile (il pezzo/i mobili), stato, note.\n"
        . "   Se manca qualcosa di importante (es. il telefono) chiedilo con una sola domanda; non insistere sui dettagli minori.\n"
        . "   Per 'stato' usa uno tra LEAD, SOPRALLUOGO, PREVENTIVO, ACCONTO, STANDBY, PERSO. Se {$nome} non lo dice, usa LEAD.\n"
        . "2. RIPETI i dati a {$nome} in modo compatto e CHIEDI CONFERMA esplicita (\"Confermo e salvo?\"). NON salvare prima del sì.\n"
        . "3. SOLO dopo il sì, termina il tuo messaggio con un marker su una riga a parte, in questo formato esatto:\n"
        . "   [[CREA_SCHEDA]]{\"nome\":\"\",\"cognome\":\"\",\"telefono\":\"\",\"email\":\"\",\"indirizzo\":\"\",\"zona\":\"\",\"servizio\":\"\",\"mobile\":\"\",\"stato\":\"\",\"note\":\"\"}\n"
        . "   Riempi solo i campi noti, lascia \"\" gli altri. JSON valido su UNA riga, niente code fence.\n"
        . "   Il marker viene intercettato dal sistema e rimosso prima di mostrare il messaggio: scrivi comunque una frase\n"
        . "   naturale di conferma per {$nome} (\"Fatto, scheda creata ✅\") PRIMA del marker.\n"
        . "   NON usare il marker se {$nome} non ha ancora confermato, e non inventare dati che non ti ha dato.\n\n"
        . "## CONTATTARE UN LEAD (primo messaggio WhatsApp al potenziale cliente)\n"
        . "Dopo aver creato la scheda, {$nome} può chiederti di contattare il lead (es. \"contattalo\", \"mandagli un messaggio\",\n"
        . "\"scrivi a Mario Rossi\"). In questo caso:\n"
        . "1. Cerca il lead nei dati operativi (per nome/cognome) e trova il suo session_id (formato wa-XXXXXXXXXXXXXXXX).\n"
        . "   Se non lo trovi, dì a {$nome} che non lo hai nei dati e chiedi il session_id o di precisare.\n"
        . "2. Spiega a {$nome} cosa manderai: un WhatsApp di presentazione con un link alla chat del sito dove il lead\n"
        . "   può raccontare i dettagli del lavoro a Sole. Mostra il concetto del messaggio (non il testo esatto, quello\n"
        . "   è un template Meta fisso).\n"
        . "3. ASPETTA la conferma esplicita di {$nome}. È un contatto a freddo: MAI mandare senza il suo OK.\n"
        . "4. SOLO dopo il sì, termina il tuo messaggio con il marker:\n"
        . "   [[CONTATTA_LEAD]]{\"session_id\":\"wa-XXXXXXXXXXXXXXXX\"}\n"
        . "   Scrivi una frase naturale di conferma PRIMA del marker (\"Primo contatto inviato ✅\").\n"
        . "   Il marker viene intercettato e rimosso come per [[CREA_SCHEDA]].\n\n"
        . "## FISSARE / SPOSTARE APPUNTAMENTI (hai i tool del calendario)\n"
        . "Su questo canale hai strumenti VERI e funzionanti per gestire il calendario di Ardy Lab PER CONTO di un cliente del CRM. Usali DAVVERO: NON scrivere mai a parole \"lancio il tool\" o \"adesso fisso\", non descrivere la chiamata — invoca lo strumento e basta. (È esattamente l'errore da evitare: annunciare un tool senza eseguirlo.)\n"
        . "- `ottieni_disponibilita_calendario`: leggi gli slot liberi quando devi scegliere quando mettere un sopralluogo.\n"
        . "- `fissa_appuntamento_staff`: AGGIUNGE un sopralluogo per un cliente, identificandolo PER NOME (es. {$nome} dice \"fissa Alberto domani alle 10\"). Fissa solo quando {$nome} ti ha indicato giorno e ora precisi.\n"
        . "- ⚠️ PIÙ SOPRALLUOGHI: un cliente può averne più di uno (1°, 2°, sopralluogo colori…). `fissa_appuntamento_staff` ne aggiunge SEMPRE uno nuovo, non è mai un doppione: se {$nome} dice \"aggiungi un secondo sopralluogo per Alberto\", fissalo (puoi passare un'etichetta, es. \"2° sopralluogo\").\n"
        . "- `sposta_appuntamento_staff`: sposta a una nuova data/ora un sopralluogo già esistente. Se il cliente ne ha PIÙ d'uno, il tool ti restituirà l'elenco: CHIEDI a {$nome} QUALE spostare, poi richiama col `sopralluogo_id` giusto.\n"
        . "- `elenca_sopralluoghi_staff`: elenca i sopralluoghi di un cliente (utile per \"che sopralluoghi ha Alberto?\" o per sapere quale spostare).\n"
        . "- `cerca_scheda_cliente`: cerca le schede per nome quando vuoi controllare di star agendo sul cliente giusto.\n"
        . "- ⚠️ OMONIMI: se per quel nome esiste più di una scheda, NON scegliere a caso. Il tool ti restituirà l'elenco delle schede col loro session_id: CHIEDI a {$nome} quale cliente è, poi richiama il tool col session_id giusto.\n"
        . "- Un appuntamento è fissato/spostato SOLO dopo che il tool ha risposto con successo: prima di quella risposta NON darlo per fatto.\n"
        . "- ⚠️ Il documento di riferimento qui sotto descrive il flusso calendario del canale CLIENTE (tool `fissa_appuntamento_calendario`/`sposta_appuntamento`, senza suffisso `_staff`): a TE, dello staff, NON si applica quel flusso e quei nomi NON esistono qui. Tu usi solo i tool `_staff` elencati sopra.\n";
}

// Documento di riferimento Ardy Lab (statico).
function ardy_wa_doc_riferimento(string $nome = 'Michela'): string {
    $base = @file_get_contents(__DIR__ . '/ardy-system.txt') ?: '';
    return "\n---\n# SCHEDA INFORMATIVA DI RIFERIMENTO (servizi, prezzi, processi — solo se {$nome} te lo chiede)\n" . $base;
}

// LEGACY (self-contained): istruzioni + riepilogo CRM incollato + documento.
// Mantiene il comportamento attuale di n8n (campo `system_prompt`). Il riepilogo
// in mezzo impedisce il prompt caching: per cacheare usare invece `system_static`
// (statico) + `crm_context` (volatile, in un messaggio separato) — vedi sotto.
function ardy_wa_prompt_titolare(string $riepilogo, string $nome = 'Michela'): string {
    return ardy_wa_titolare_istruzioni(false, $nome)
        . "\n## DATI OPERATIVI ATTUALI (dal CRM)\n" . $riepilogo . "\n"
        . ardy_wa_doc_riferimento($nome);
}

// STATICO (cacheabile): istruzioni + documento, SENZA il riepilogo volatile.
// Va messo nel blocco `system` del nodo HTTP n8n con cache_control ephemeral.
function ardy_wa_prompt_titolare_static(string $nome = 'Michela'): string {
    return ardy_wa_titolare_istruzioni(true, $nome) . ardy_wa_doc_riferimento($nome);
}

// Costruisce il system prompt completo per n8n:
// wrapper WhatsApp + contesto (mode/cliente) + documento principale Ardy Lab.
function ardy_wa_system_prompt(string $mode, ?array $cliente): string {
    $wrap = @file_get_contents(__DIR__ . '/ardy-whatsapp-system.txt') ?: '';
    $base = @file_get_contents(__DIR__ . '/ardy-system.txt') ?: '';
    // Conoscenza di bottega (legno/restauro/cura): solo lato cliente (qui), NON nel
    // prompt della titolare. Va in system_static → cacheato col resto.
    $conoscenza = @file_get_contents(__DIR__ . '/ardy-conoscenza-restauro.txt') ?: '';
    $ctx  = "\n\n## CONTESTO DI QUESTA CONVERSAZIONE\nmode: {$mode}\n";
    if ($cliente) {
        $ctx .= "cliente:\n" . json_encode($cliente, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        $ctx .= "cliente: (nessuno — nuovo contatto)\n";
    }

    // Lead da portale: Sole lo riconosce già e continua la conversazione senza riqualificare.
    if ($mode === 'lead_portale' && $cliente) {
        $ln = trim((string)($cliente['nome'] ?? ''));
        $ls = trim((string)($cliente['servizio'] ?? ''));
        $lm = trim((string)($cliente['mobile'] ?? ''));
        $lz = trim((string)($cliente['zona'] ?? ''));
        $ctx .= "\n## LEAD DA PORTALE — CONTESTO PRECARICATO\n"
            . "Questo cliente ha risposto al tuo WhatsApp di presentazione (arrivava da un portale tipo ProntoPro). "
            . "Ha già una scheda nel CRM. NON ripartire da zero con la qualifica: salutalo per nome, "
            . "mostra che sai già cosa ha chiesto e prosegui la conversazione da lì.\n"
            . "Sei su WhatsApp: risposte brevi e dirette, niente HTML o markdown complesso.\n";
        if ($ln) $ctx .= "- Nome: {$ln}\n";
        if ($ls) $ctx .= "- Servizio richiesto: {$ls}\n";
        if ($lm) $ctx .= "- Mobile/oggetto: {$lm}\n";
        if ($lz) $ctx .= "- Zona: {$lz}\n";
        $ctx .= "Obiettivo: qualificarlo completamente (foto, misure, stato del pezzo, budget indicativo, sopralluogo) "
            . "così Michela ha tutto per fare un preventivo.\n";
    }

    // Conoscenza APPRESA dalle fasi reali (autoapprendimento): come la conoscenza
    // di bottega, solo lato cliente. Blocco DB separato curato/approvato da Michela.
    $appresa = '';
    try {
        require_once __DIR__ . '/ardy-conoscenza-appresa.php';
        $appresa = ardy_conoscenza_appresa_blocco(ardyDB());
    } catch (Throwable $e) { error_log('ARDY WA conoscenza appresa: ' . $e->getMessage()); }

    $doc = $base
        . ($conoscenza !== '' ? "\n\n---\n" . $conoscenza : '')
        . ($appresa    !== '' ? "\n\n---\n" . $appresa    : '');
    return $wrap . $ctx . "\n" . $doc;
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

$hasLavorazione   = !empty($row['wp_post_id']);
$hasPrimoContatto = !empty($row['primo_contatto_wa_at']);

// Priorità: se il lavoro è avviato è già un cliente a pieno titolo;
// altrimenti, se gli abbiamo già inviato il template di primo contatto, è un lead da portale.
if ($hasLavorazione) {
    $mode = 'cliente_lavorazione';
} elseif ($hasPrimoContatto) {
    $mode = 'lead_portale';
} else {
    $mode = 'cliente';
}

// Ultima fase pubblicata (contesto per la modalità cliente_lavorazione)
$ultimaFase = null;
if ($hasLavorazione) {
    try {
        $f = $db->prepare("SELECT fase_nome, testo_generato, created_at
                             FROM fasi WHERE session_id = :sid
                              AND COALESCE(stato,'pubblicata') = 'pubblicata'
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
    'zona'         => $row['zona'] ?? '',
    'stato'        => $row['stato'] ?? '',
    'wp_post_link' => $row['wp_post_link'] ?? '',
    'ultima_fase'  => $ultimaFase,
];

// Dossier client-safe + compatto (niente note interne, niente chat: la conversazione
// live ce l'ha già). È STABILE per la durata della conversazione col cliente, quindi
// lo mettiamo nel blocco `system_static` → il nodo n8n lo manda con cache_control e
// dal 2° messaggio in poi si rilegge dalla cache (~0,1x del costo).
$systemStatic = ardy_wa_system_prompt($mode, $clienteOut);
try {
    require_once __DIR__ . '/ardy-dossier.php';
    $dossierCliente = (string) ardy_genera_dossier($db, (string) $row['session_id'], true, true);
    if ($dossierCliente !== '') {
        $systemStatic .= "\n\n## SCHEDA COMPLETA DEL CLIENTE (riservata a te, Sole: usala come contesto, NON elencarla; rispondi solo a ciò che chiede)\n" . $dossierCliente;
    }
} catch (Throwable $e) { error_log('ARDY WA LOOKUP dossier: ' . $e->getMessage()); }

echo json_encode([
    'success'       => true,
    'mode'          => $mode,
    'cliente'       => $clienteOut,
    // legacy/fallback (self-contained) + il nodo n8n preferisce system_static (cacheato).
    'system_prompt' => $systemStatic,
    'system_static' => $systemStatic,
    // Il dossier è già dentro system_static (cacheato): niente da attaccare al messaggio (no doppione).
    'crm_context'   => '',
]);
