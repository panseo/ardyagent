<?php
// -----------------------------------------------------------
// ARDY LAB — Tetto di spesa sugli endpoint autenticati costosi
//
// Perché esiste (SECURITY-AUDIT §9.12). Gli endpoint della dashboard che chiamano
// l'AI (Anthropic, a consumo) o spediscono email (Brevo/SMTP) sono dietro login,
// ma il login è l'UNICA barriera: se le credenziali trapelassero, uno script
// potrebbe chiamarli in loop, generando spesa sull'API o email di massa dal
// dominio di Ardy Lab (reputazione del mittente bruciata). Oggi gli attacchi
// sono automatizzati: chi entra non clicca, lancia un ciclo.
//
// Cosa fa. Un contatore GLOBALE per "secchio" (non per IP: chi usa credenziali
// rubate cambia IP quanto vuole, e lo scopo è limitare la spesa, non la persona)
// su due finestre mobili: ultima ora e ultime 24 ore. Superata una delle due,
// risponde 429 e termina, PRIMA che parta la chiamata costosa.
//
// Le soglie sono larghe rispetto all'uso reale da dashboard (decine al giorno),
// ma tagliano un ciclo automatico entro pochi minuti. Si possono ritoccare per
// singolo secchio in ardy-config.php:
//   define('ARDY_COST_CAPS', ['ai-enrich' => [300, 1500]]);   // [orario, giornaliero]
//
// Scelte:
// - CLI esente: cron e test lanciati sul server non passano dal login e non
//   sono il rischio che questo tetto copre.
// - Fail-open su errori di filesystem: un problema di permessi sulla cartella
//   non deve bloccare il lavoro di Michela; si logga e si prosegue (stessa
//   logica di ardyVerifyHtpasswd() quando .htpasswd non è leggibile).
// - flock sul file del contatore: due richieste parallele non devono poter
//   leggere entrambe "sotto soglia" e passare tutte e due.
// -----------------------------------------------------------

/** Soglie predefinite [per ora, per giorno], per secchio. */
const ARDY_COST_CAPS_DEFAULT = [
    // Generazione testi AI dalla dashboard (FAQ, post, didascalie, titoli,
    // solleciti, preventivi, estrazioni da PDF, distillazione conoscenza).
    'ai'        => [60, 300],
    // Arricchimento contatti (agente con ricerca web): c'è un'azione "arricchisci
    // tutti" che ne lancia uno per contatto, quindi la soglia è più alta.
    'ai-enrich' => [200, 1000],
    // Email in uscita verso clienti/contatti: unità = destinatari, così una
    // campagna da 80 indirizzi pesa 80. Il piano gratuito Brevo è 300/giorno.
    'email'     => [150, 300],
];

/**
 * Soglie [ora, giorno] per un secchio: override da ARDY_COST_CAPS se presente,
 * altrimenti il default; un secchio sconosciuto ricade su quelle di 'ai'.
 */
function ardyCostCapLimits(string $bucket): array {
    $base = ARDY_COST_CAPS_DEFAULT[$bucket] ?? ARDY_COST_CAPS_DEFAULT['ai'];
    if (defined('ARDY_COST_CAPS') && is_array(constant('ARDY_COST_CAPS'))) {
        $o = constant('ARDY_COST_CAPS')[$bucket] ?? null;
        if (is_array($o) && count($o) === 2) {
            $base = [max(0, (int) $o[0]), max(0, (int) $o[1])];
        }
    }
    return $base;
}

/**
 * Registra $units utilizzi del secchio $bucket; se si sforerebbe il tetto
 * orario o giornaliero risponde 429 (JSON) e termina lo script.
 *
 * Va chiamata DOPO ardyRequireAuth() e subito PRIMA dell'operazione costosa,
 * così le azioni economiche dello stesso endpoint (liste, salvataggi) non
 * consumano il tetto.
 */
function ardyCostCap(string $bucket, int $units = 1): void {
    if (PHP_SAPI === 'cli' || $units <= 0) {
        return;
    }
    [$perOra, $perGiorno] = ardyCostCapLimits($bucket);

    $dir = defined('ARDY_RATE_LIMIT_DIR') ? constant('ARDY_RATE_LIMIT_DIR') : (sys_get_temp_dir() . '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $nome = preg_replace('/[^a-z0-9\-]/', '', strtolower($bucket));
    $file = rtrim($dir, '/') . '/costcap_' . $nome . '.json';

    $bloccato = '';   // '' = passa, altrimenti 'oraria' | 'giornaliera'
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        error_log("ARDY COSTCAP: impossibile aprire $file, tetto '$bucket' non applicato");
        return;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            error_log("ARDY COSTCAP: flock fallito su $file, tetto '$bucket' non applicato");
            return;
        }
        $now  = time();
        $raw  = stream_get_contents($fh);
        // Formato: lista di [timestamp, unità]. Una campagna da 80 destinatari è
        // UNA voce [t, 80], non 80 timestamp: il file resta piccolo.
        $voci = json_decode((string) $raw, true);
        $voci = is_array($voci) ? $voci : [];
        $voci = array_values(array_filter($voci, fn($v) =>
            is_array($v) && isset($v[0], $v[1]) && ($now - (int) $v[0]) < 86400));

        $ora = 0; $giorno = 0;
        foreach ($voci as $v) {
            $giorno += (int) $v[1];
            if (($now - (int) $v[0]) < 3600) $ora += (int) $v[1];
        }

        if ($ora + $units > $perOra || $giorno + $units > $perGiorno) {
            $bloccato = ($ora + $units > $perOra) ? 'oraria' : 'giornaliera';
            error_log("ARDY COSTCAP: superata soglia $bloccato del secchio '$bucket' "
                . "(ora $ora/$perOra, giorno $giorno/$perGiorno, richieste $units)");
        } else {
            $voci[] = [$now, $units];
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($voci));
            fflush($fh);
        }
    } finally {
        @flock($fh, LOCK_UN);
        fclose($fh);
    }

    // Risposta fuori dal try: in PHP exit() NON esegue i blocchi finally, quindi
    // si rilascia il lock prima e solo dopo si termina.
    if ($bloccato !== '') {
        http_response_code(429);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . ($bloccato === 'oraria' ? 3600 : 86400));
        }
        // 'limite' permette ai cicli lato dashboard di fermarsi invece di
        // continuare a bussare per ogni elemento rimasto.
        echo json_encode([
            'success' => false,
            'ok'      => false,
            'limite'  => true,
            'error'   => "Limite di sicurezza raggiunto (soglia $bloccato) per questo tipo di operazione. "
                       . "Riprova più tardi; se è un uso legittimo, la soglia si alza in ardy-config.php (ARDY_COST_CAPS).",
        ]);
        exit();
    }
}
