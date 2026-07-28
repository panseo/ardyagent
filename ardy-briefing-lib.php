<?php
// -----------------------------------------------------------
// ARDY LAB — Libreria briefing/riepilogo operativo (condivisa)
// Contiene ardy_riepilogo_settimana(): la "foto" sintetica dal CRM usata
//   - dal "buongiorno"/briefing su WhatsApp (ardy-wa-lookup.php)
//   - dal briefing del mattino via email (ardy-briefing-mattino.php)
// Estratta da ardy-wa-lookup.php per non duplicare la logica. Nessun output:
// solo definizioni di funzione (sicura da includere ovunque).
// -----------------------------------------------------------

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
                            : ((strpos($low, 'consegna') !== false || strpos($low, 'ritiro') !== false) ? '📦 '
                            : (strpos($low, 'consulenz') !== false ? '💬 ' : '📌 '));
                    $loc    = $ev['location'] !== '' ? ' · ' . $ev['location'] : '';
                    $righe[] = "- {$quando} {$ora} · {$tipo}{$ev['summary']}{$loc}";
                }
                $out[] = "📅 IMPEGNI IN CALENDARIO (oggi e domani): " . count($righe);
                foreach (array_slice($righe, 0, 12) as $r) $out[] = $r;
            }
        }
    } catch (Throwable $e) { error_log('ARDY WA RIEPILOGO GCAL: ' . $e->getMessage()); }

    // 🗒️ PROMEMORIA dello staff (il più recente) — così entra nel briefing del
    // mattino senza che Michela debba chiederla. Stessa fonte del tool leggi_nota_settimanale
    // (tabella note_staff, ultima per id). Difensivo: se la tabella manca, salta in silenzio.
    try {
        $nota = $db->query("SELECT testo, settimana FROM note_staff ORDER BY id DESC LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
        $testoNota = $nota ? trim((string) $nota['testo']) : '';
        if ($testoNota !== '') {
            $sett = !empty($nota['settimana']) ? ' (aggiornato ' . $nota['settimana'] . ')' : '';
            $out[] = "🗒️ PROMEMORIA{$sett}:";
            foreach (array_slice(preg_split('/\r\n|\r|\n/', $testoNota), 0, 20) as $riga) {
                $riga = rtrim($riga);
                if ($riga !== '') $out[] = "  {$riga}";
            }
        }
    } catch (PDOException $e) { /* tabella note_staff assente: salta */ }

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

    // 🧾 PREVENTIVI DA GESTIRE: clienti fermi in stato PREVENTIVO. È la scadenza che
    // Sole deve citare nel briefing del mattino, distinguendo due casi:
    //   ✍️ preventivo DA FARE          → nessun documento ancora (o solo una bozza salvata);
    //   📤 preventivo INVIATO da SOLLECITARE → il preventivo è già partito, si aspetta la
    //      risposta del cliente (con da quanti giorni è stato inviato → quanto sollecitare).
    // L'ultimo preventivo di ogni cliente arriva da una sola query aggregata (per
    // session_id). Difensivo: se la tabella 'preventivi' manca, tutti risultano "da fare"
    // (fallback ragionevole: senza documento tracciato il preventivo è comunque da fare).
    try {
        $rows = $db->query(
            "SELECT nome, cognome, mobile, session_id
               FROM clienti
              WHERE UPPER(stato) = 'PREVENTIVO' AND deleted_at IS NULL
           ORDER BY updated_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $prevMap = []; // session_id => ['stato'=>..., 'quando'=>...]
            try {
                $pr = $db->query(
                    "SELECT p.session_id, p.stato, COALESCE(p.data_emissione, p.created_at) AS quando
                       FROM preventivi p
                       JOIN (SELECT session_id, MAX(id) mid FROM preventivi GROUP BY session_id) x
                         ON x.mid = p.id"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pr as $r) $prevMap[$r['session_id']] = $r;
            } catch (PDOException $e) { /* tabella preventivi assente: tutti "da fare" */ }

            $daFare = [];
            $daSollecitare = [];
            $oggiTs = strtotime('today');
            foreach ($rows as $r) {
                $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
                $mob  = $r['mobile'] ? ' · ' . $r['mobile'] : '';
                $p    = $prevMap[$r['session_id']] ?? null;
                $st   = $p ? strtolower(trim((string)$p['stato'])) : '';
                if ($st === 'inviato') {
                    $note = '';
                    if (!empty($p['quando'])) {
                        $gg = (int)floor(($oggiTs - strtotime((string)$p['quando'] . ' 00:00:00')) / 86400);
                        if ($gg > 0)       $note = " · inviato da {$gg}g";
                        elseif ($gg === 0) $note = " · inviato oggi";
                    }
                    $daSollecitare[] = "- {$nome}{$mob}{$note}";
                } else {
                    // nessun preventivo, oppure bozza/rifiutato → è ancora DA FARE
                    $extra = ($st === 'bozza') ? ' · bozza salvata, da inviare' : '';
                    $daFare[] = "- {$nome}{$mob}{$extra}";
                }
            }
            if ($daFare || $daSollecitare) {
                $out[] = "🧾 PREVENTIVI DA GESTIRE: " . (count($daFare) + count($daSollecitare));
                if ($daFare) {
                    $out[] = "  ✍️ Preventivo DA FARE: " . count($daFare);
                    foreach ($daFare as $l) $out[] = $l;
                }
                if ($daSollecitare) {
                    $out[] = "  📤 Preventivo INVIATO — da sollecitare risposta: " . count($daSollecitare);
                    foreach ($daSollecitare as $l) $out[] = $l;
                }
            }
        }
    } catch (PDOException $e) { /* colonna/tabella assente o altro: salta */ }

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

    // Appuntamenti fissati (data VERA dal calendario, mirror in sopralluogo_at). Il
    // tipo (sopralluogo/consegna/ritiro) arriva dalla riga sopralluoghi corrispondente,
    // così una consegna NON viene etichettata come un sopralluogo nel contesto di Sole.
    try {
        $rows = $db->query(
            "SELECT c.nome, c.cognome, c.zona, c.sopralluogo_at,
                    COALESCE(s.tipo, 'sopralluogo') AS tipo, s.etichetta
               FROM clienti c
          LEFT JOIN sopralluoghi s
                 ON s.session_id = c.session_id
                AND ( (c.gcal_event_id IS NOT NULL AND c.gcal_event_id <> '' AND s.gcal_event_id = c.gcal_event_id)
                   OR ((c.gcal_event_id IS NULL OR c.gcal_event_id = '') AND s.data_ora = c.sopralluogo_at) )
              WHERE c.sopralluogo_at IS NOT NULL AND c.sopralluogo_at >= NOW()
                AND c.deleted_at IS NULL
           ORDER BY c.sopralluogo_at ASC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $out[] = "APPUNTAMENTI FISSATI (prossimi):";
            foreach ($rows as $r) {
                $nome  = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? '')) ?: '(senza nome)';
                $tipo  = (string) ($r['tipo'] ?? 'sopralluogo');
                $label = $tipo === 'ritiro' ? 'Ritiro' : ($tipo === 'consegna' ? 'Consegna' : ($tipo === 'intervento' ? 'Intervento sul posto' : 'Sopralluogo'));
                $quando = date('d/m/Y H:i', strtotime((string)$r['sopralluogo_at']));
                $out[] = "- {$quando} · {$label} · {$nome}" . ($r['zona'] ? " · " . $r['zona'] : '');
            }
        }
    } catch (PDOException $e) { /* colonna/tabella assente o altro: salta */ }

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

// Converte il riepilogo testuale (righe-intestazione + righe "- voce") in HTML
// leggibile per l'email del briefing del mattino. Le righe che NON iniziano con
// "- " e non sono indentate vengono trattate come intestazioni (in grassetto).
function ardy_briefing_email_html(string $testo): string {
    $righe = preg_split('/\r\n|\r|\n/', $testo);
    $html  = '';
    foreach ($righe as $r) {
        $r = rtrim($r);
        if ($r === '') { $html .= '<div style="height:6px;"></div>'; continue; }
        $esc = htmlspecialchars($r, ENT_QUOTES, 'UTF-8');
        if (preg_match('/^\s*-\s/', $r) || preg_match('/^\s{2,}\S/', $r)) {
            $html .= '<div style="margin:2px 0 2px 10px;color:#444;">' . $esc . '</div>';
        } else {
            $html .= '<div style="margin:16px 0 4px;font-weight:bold;color:#222;">' . $esc . '</div>';
        }
    }
    return $html;
}

// Rollover settimanale della nota "cose da fare": dato il testo della nota,
// rimuove le righe marcate FATTE (✔ ✓ ✅ oppure "[x]"/"[X]") e tiene le altre.
// Ritorna ['testo'=>nuovoTesto, 'totali'=>righeAttività, 'rimosse'=>righeFatte].
// "totali" conta solo le righe con contenuto (le righe vuote non contano).
function ardy_nota_strip_fatte(string $testo): array {
    $righe   = preg_split('/\r\n|\r|\n/', $testo);
    $tenute  = [];
    $totali  = 0;
    $rimosse = 0;
    foreach ($righe as $r) {
        if (trim($r) === '') { $tenute[] = ''; continue; } // preserva la spaziatura
        $totali++;
        $fatta = (bool) preg_match('/[\x{2714}\x{2713}\x{2705}]/u', $r)  // ✔ ✓ ✅
              || (bool) preg_match('/^\s*\[\s*[xX]\s*\]/', $r);          // [x] / [X]
        if ($fatta) { $rimosse++; continue; }
        $tenute[] = $r;
    }
    $nuovo = preg_replace("/\n{3,}/", "\n\n", trim(implode("\n", $tenute)));
    return ['testo' => (string) $nuovo, 'totali' => $totali, 'rimosse' => $rimosse];
}
