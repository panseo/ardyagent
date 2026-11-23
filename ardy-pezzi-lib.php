<?php
// -----------------------------------------------------------
// ARDY LAB — Libreria "Racconta" (pezzi raccontati da foto + voce)
// Condivisa da ardy-pezzi-api.php, ardy-pezzi-ai.php, ardy-pubblica-pezzo.php e
// dalla Sole del sito (ardy-proxy.php). Vedi PIANO-RACCONTA.md.
// (File interno: negato all'accesso diretto via .htaccess.)
// -----------------------------------------------------------

/** Campi della scheda di un pezzo, nell'ordine in cui si mostrano (chiave → etichetta). */
const PEZZI_SCHEDA_CAMPI = [
    'tipologia'  => 'Tipologia',
    'epoca'      => 'Epoca',
    'stile'      => 'Stile',
    'materiali'  => 'Legno e materiali',
    'dimensioni' => 'Dimensioni',
    'problemi'   => 'Com\'è arrivato',
    'tecniche'   => 'Lavorazioni',
    'prodotti'   => 'Prodotti e finiture',
];

/** Cartella su disco delle foto di un capitolo. */
function pezziFotoDir(int $pezzoId, int $faseId): string {
    return rtrim(ARDY_UPLOAD_DIR, '/') . '/pezzi/' . $pezzoId . '/fasi/' . $faseId . '/';
}

/** Scheda normalizzata: solo i campi noti, stringhe ripulite. */
function pezziSchedaNorm($scheda): array {
    if (is_string($scheda)) $scheda = json_decode($scheda, true);
    if (!is_array($scheda)) $scheda = [];
    $out = [];
    foreach (array_keys(PEZZI_SCHEDA_CAMPI) as $k) {
        $out[$k] = mb_substr(trim((string) ($scheda[$k] ?? '')), 0, 600);
    }
    return $out;
}

/**
 * Avvisi su dati che non dovrebbero finire su una pagina pubblica. Il prompt di Sole
 * li toglie già quando scrive, ma il testo si corregge anche a mano o a voce dopo:
 * questo controllo deterministico è la seconda rete, prima di pubblicare.
 * Non blocca: elenca, e chi pubblica decide.
 */
function pezziAvvisiPrivacy(string $testo): array {
    $avvisi = [];
    if (preg_match('/(?:\+?\d[\s.\-]?){9,}/', $testo))                         $avvisi[] = 'sembra esserci un numero di telefono';
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $testo))       $avvisi[] = 'c\'è un indirizzo email';
    if (preg_match('/€\s?\d|\d\s?€|\beuro\b/iu', $testo))                        $avvisi[] = 'si parla di prezzi o importi';
    if (preg_match('/\b(?:via|viale|piazza|largo|corso)\s+[A-ZÀ-Ü]/u', $testo)) $avvisi[] = 'sembra esserci un indirizzo';
    if (preg_match('/\b(?:signor|signora|sig\.|sig\.ra|dott\.|cliente)\b/iu', $testo)) $avvisi[] = 'si nomina una persona o "il cliente"';
    return $avvisi;
}

/**
 * Ciò che Sole sa di un pezzo, a partire dall'id del post WordPress su cui si trova
 * il visitatore. Legge SOLO l'istantanea scritta alla pubblicazione (pubblico_json):
 * bozze, note dettate e modifiche non ancora pubblicate non escono mai di qui.
 * null se il post non è la pagina di un pezzo.
 */
function pezziProiezionePubblica(PDO $db, int $wpPostId): ?array {
    if ($wpPostId <= 0) return null;
    $st = $db->prepare("SELECT pubblico_json FROM pezzi WHERE wp_post_id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$wpPostId]);
    $json = $st->fetchColumn();
    if (!is_string($json) || $json === '') return null;
    $p = json_decode($json, true);
    return is_array($p) ? $p : null;
}

/** La proiezione pubblica in forma di testo, per il prompt di Sole. */
function pezziProiezioneTesto(array $p): string {
    $righe = ['Pezzo: ' . ($p['titolo'] ?? '')];
    foreach (PEZZI_SCHEDA_CAMPI as $k => $label) {
        $v = trim((string) ($p['scheda'][$k] ?? ''));
        if ($v !== '') $righe[] = $label . ': ' . $v;
    }
    if (!empty($p['storia'])) $righe[] = "\nIntroduzione:\n" . $p['storia'];
    foreach ($p['capitoli'] ?? [] as $c) {
        $righe[] = "\nCapitolo del " . ($c['data'] ?? '') . ' — ' . ($c['titolo'] ?? '') . "\n" . ($c['testo'] ?? '');
    }
    return mb_substr(implode("\n", $righe), 0, 9000);
}
