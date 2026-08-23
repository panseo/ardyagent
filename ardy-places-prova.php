<?php
// -----------------------------------------------------------
// ARDY LAB — Prova di copertura Google Places sulle strutture del portale
//
// Domanda a cui risponde: "se pago Google Places per arricchire i B&B estratti
// da bed-and-breakfast.it, quanti telefoni ottengo davvero?" Nessun listino può
// dirlo — dipende da quante di QUELLE strutture Google le ha in scheda.
//
// Fa un campione piccolo e misura. NON scrive niente sul database: è solo una
// misura, i contatti si importano e si arricchiscono dalla dash come sempre.
//
// Esegui da CLI, sul server (serve la chiave in ardy-config.php):
//   php ardy-places-prova.php              → 20 strutture della Puglia
//   php ardy-places-prova.php sicilia 20   → altra regione
//
// Costo: una chiamata Places per struttura, sulla fascia Enterprise (chiediamo
// telefono e sito). Le prime 1.000 chiamate del mese sono gratis, oltre sono
// ~$35 ogni 1.000. Il campione è limitato a 50 per non bruciare budget con un
// refuso, e resta comunque sotto il tetto giornaliero di ardy-places.php.
// -----------------------------------------------------------

require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-places.php';
require_once __DIR__ . '/ardy-portale-bb.php';

// Da CLI resta libero (uso previsto); via web deve essere autenticato, così una
// richiesta HTTP non protetta non fa spendere chiamate Google a nessuno.
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/ardy-auth.php';
    ardyRequireAuth();
    header('Content-Type: text/plain; charset=utf-8');
}

const PROVA_MAX_CAMPIONE = 50;   // rete di sicurezza contro i refusi
const PROVA_MAX_PAGINE   = 6;    // pagine di portale da cui pescare il campione
const PROVA_COSTO_MILLE  = 35.0; // $ per 1.000 chiamate, fascia Enterprise
// Il gratuito mensile lo sa ardyPlacesFreeMonth() (sovrascrivibile da config).

$slug   = strtolower(trim($argv[1] ?? 'puglia'));
$quante = (int)($argv[2] ?? 20);
$quante = max(1, min(PROVA_MAX_CAMPIONE, $quante));

$regioni = ardyPortaleBbRegioni();
if (!isset($regioni[$slug])) {
    fwrite(STDERR, "Regione non valida: $slug\nAmmesse: " . implode(', ', array_keys($regioni)) . "\n");
    exit(1);
}
if (!ardyPlacesConfigured()) {
    fwrite(STDERR, "Chiave Google Places non configurata (ARDY_GOOGLE_PLACES_KEY in ardy-config.php).\n");
    exit(1);
}

echo "PROVA COPERTURA GOOGLE PLACES — {$regioni[$slug]}\n";
echo str_repeat('=', 60) . "\n\n";

// --- 1. Bacino da cui campionare (gratis: è il portale) ------------------
// Non prendiamo le prime 20 e via: la pagina 1 è piena di strutture promosse
// ("TopBnB"), che sono le più curate e falserebbero la stima verso l'alto.
// Peschiamo su più pagine e poi campioniamo a passo regolare.
echo "Leggo l'elenco dal portale (gratis)...\n";
$bacino = [];
for ($p = 1; $p <= PROVA_MAX_PAGINE; $p++) {
    $r = ardyPortaleBbPagina($slug, $p);
    if (!$r['ok']) { fwrite(STDERR, "Portale: " . ($r['error'] ?? 'errore') . "\n"); break; }
    if (!count($r['results'])) { break; }        // elenco finito
    foreach ($r['results'] as $x) { $bacino[] = $x; }
    usleep(600000);                               // educati col portale
}
if (count($bacino) < $quante) { $quante = count($bacino); }
if ($quante < 1) { fwrite(STDERR, "Nessuna struttura trovata sul portale.\n"); exit(1); }

$passo    = max(1, (int) floor(count($bacino) / $quante));
$campione = [];
for ($i = 0; count($campione) < $quante && $i < count($bacino); $i += $passo) {
    $campione[] = $bacino[$i];
}
printf("Bacino: %d strutture · campione: %d (una ogni %d)\n\n", count($bacino), count($campione), $passo);

// --- 2. Lookup su Google, uno per struttura ------------------------------
echo "Interrogo Google Places (" . count($campione) . " chiamate)...\n\n";

/** Pad a larghezza di caratteri, non di byte: senza, gli accenti sfasano le colonne. */
$colonna = function (string $s, int $largh): string {
    $s = mb_strimwidth($s, 0, $largh, '…');
    return $s . str_repeat(' ', max(0, $largh - mb_strlen($s)));
};

// Stessa misura che gira dietro il bottone della dash (azione places_prova).
$m = ardyPlacesCoperturaCampione($campione);

$idx = 0;
foreach ($m['righe'] as $r) {
    $idx++;
    printf("%2d. %s %s %s\n",
        $idx,
        $colonna($r['nome'], 38),
        $colonna($r['zona'], 24),
        $r['telefono'] !== '' ? "☎ {$r['telefono']}"
            : ($r['esito'] === 'senza_telefono' ? '— trovata, senza telefono' : '✗ non trovata')
    );
}
if ($m['capHit']) { echo "\n⚠ Tetto giornaliero Places raggiunto: mi fermo qui.\n"; }

// --- 3. Esito ------------------------------------------------------------
$righe   = $m['righe'];
$trovate = $m['trovate'];
$conTel  = $m['conTel'];
$conSito = $m['conSito'];
$fatte   = $m['fatte'];
if ($fatte === 0) { fwrite(STDERR, "\nNessuna chiamata eseguita.\n"); exit(1); }

$percTel  = round($conTel  * 100 / $fatte);
$percSito = round($conSito * 100 / $fatte);

echo "\n" . str_repeat('=', 60) . "\n";
echo "ESITO SU $fatte STRUTTURE\n\n";
printf("  Trovate su Google : %d (%d%%)\n", $trovate, round($trovate * 100 / $fatte));
printf("  Con TELEFONO      : %d (%d%%)   ← il dato che ci serve\n", $conTel, $percTel);
printf("  Con sito web      : %d (%d%%)\n", $conSito, $percSito);
printf("\n  Chiamate Places oggi: %d (tetto giornaliero: %d)\n",
    ardyPlacesCountToday(), ardyPlacesDailyCap());
printf("  Questo mese: %d su %d gratis\n", ardyPlacesCountMonth(), ardyPlacesFreeMonth());

// Proiezione sul resto della regione, per decidere se vale la spesa.
// Il residuo gratuito tiene conto di quel che si e' gia' consumato nel mese:
// dire "le prime 1.000 sono gratis" a chi ne ha gia' spese 543 e' fuorviante.
$gratisRimaste = max(0, ardyPlacesFreeMonth() - ardyPlacesCountMonth());
echo "\nSE ESTENDI A TUTTA LA REGIONE\n";
printf("  Serve 1 chiamata per struttura. Questo mese te ne restano %d gratis,\n", $gratisRimaste);
echo "  oltre costano ~$" . number_format(PROVA_COSTO_MILLE, 2) . " ogni 1.000.\n";
foreach ([715, 1000, 1500] as $tot) {
    $oltre  = max(0, $tot - $gratisRimaste);
    $costo  = $oltre * PROVA_COSTO_MILLE / 1000;
    printf("  %4d strutture → ~%d telefoni · costo ~$%s\n",
        $tot, (int) round($tot * $percTel / 100), number_format($costo, 2));
}
echo "\n  (Listino verificato ad agosto 2026: ricontrolla la console Google\n";
echo "   prima di una passata grossa, i prezzi cambiano.)\n";

echo "\nCOME LEGGERLA\n";
if ($percTel >= 60) {
    echo "  Copertura buona: conviene arricchire tutta la regione con Places,\n";
    echo "  e lasciare l'agente Claude solo alle strutture rimaste scoperte.\n";
} elseif ($percTel >= 30) {
    echo "  Copertura media: ha senso su tutta la regione solo se i telefoni ti\n";
    echo "  servono davvero; altrimenti filtra prima per provincia e voto ospiti.\n";
} else {
    echo "  Copertura bassa: non spendere sul grosso dell'elenco. Meglio filtrare\n";
    echo "  le strutture migliori e usare l'agente Claude, che trova anche email\n";
    echo "  e canali social — su queste strutture il telefono da solo scarseggia.\n";
}
echo "\nNiente è stato scritto sul database: questa è solo una misura.\n";
