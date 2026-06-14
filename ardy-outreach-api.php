<?php
// -----------------------------------------------------------
// ARDY LAB — Outreach API
// Gestione contatti, template e invio email via Brevo
// -----------------------------------------------------------
require_once __DIR__ . '/ardy-config.php';
require_once __DIR__ . '/ardy-db.php';
require_once __DIR__ . '/ardy-auth.php';
require_once __DIR__ . '/ardy-email.php';

header('Access-Control-Allow-Origin: https://ardyagent.ardy-lab.it');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// Difesa in profondità: invio email di massa + cancellazione dati. Non
// affidarsi solo al Basic Auth dell'.htaccess — richiedi un utente autenticato.
ardyRequireAuth();

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

try {
    $db = ardyDB();

    // Crea tabelle se non esistono
    $db->exec("CREATE TABLE IF NOT EXISTS `outreach_contatti` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `nome` VARCHAR(200) NOT NULL,
        `referente` VARCHAR(100) DEFAULT NULL,
        `categoria` VARCHAR(50) NOT NULL DEFAULT 'antiquari',
        `email` VARCHAR(191) DEFAULT NULL,
        `telefono` VARCHAR(50) DEFAULT NULL,
        `sito` VARCHAR(500) DEFAULT NULL,
        `indirizzo` VARCHAR(400) DEFAULT NULL,
        `stato` VARCHAR(50) NOT NULL DEFAULT 'da_contattare',
        `note` TEXT DEFAULT NULL,
        `data_contatto` DATE DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_categoria` (`categoria`),
        KEY `idx_stato` (`stato`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS `outreach_template` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `nome` VARCHAR(100) NOT NULL,
        `categoria` VARCHAR(50) DEFAULT NULL,
        `oggetto` VARCHAR(200) NOT NULL,
        `corpo` TEXT NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    switch ($action) {

        // --------------------------------------------------------
        // CONTATTI — CRUD
        // --------------------------------------------------------
        case 'get_contacts':
            $cat    = $input['categoria'] ?? ($_GET['categoria'] ?? '');
            $stato  = $input['stato']     ?? ($_GET['stato']     ?? '');
            $search = $input['search']    ?? ($_GET['search']    ?? '');
            $sql    = "SELECT * FROM outreach_contatti WHERE 1=1";
            $params = [];
            if ($cat)    { $sql .= " AND categoria = :cat";                                        $params[':cat']   = $cat; }
            if ($stato)  { $sql .= " AND stato = :stato";                                          $params[':stato'] = $stato; }
            if ($search) { $sql .= " AND (nome LIKE :s OR email LIKE :s2 OR telefono LIKE :s3)";  $params[':s'] = "%$search%"; $params[':s2'] = "%$search%"; $params[':s3'] = "%$search%"; }
            $sql .= " ORDER BY nome ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
            break;

        case 'add_contact':
            $stmt = $db->prepare("INSERT INTO outreach_contatti (nome, referente, categoria, email, telefono, sito, indirizzo, stato, note) VALUES (:nome,:ref,:cat,:email,:tel,:sito,:ind,:stato,:note)");
            $stmt->execute([
                ':nome'  => $input['nome']      ?? '',
                ':ref'   => $input['referente'] ?? null,
                ':cat'   => $input['categoria'] ?? 'antiquari',
                ':email' => $input['email']     ?? null,
                ':tel'   => $input['telefono']  ?? null,
                ':sito'  => $input['sito']      ?? null,
                ':ind'   => $input['indirizzo'] ?? null,
                ':stato' => $input['stato']     ?? 'da_contattare',
                ':note'  => $input['note']      ?? null,
            ]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
            break;

        case 'update_contact':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID mancante']); break; }
            $fields = ['nome','referente','categoria','email','telefono','sito','indirizzo','stato','note','data_contatto'];
            $set    = ['`updated_at` = NOW()'];
            $params = [':id' => $id];
            foreach ($fields as $f) {
                if (array_key_exists($f, $input)) {
                    $set[]       = "`$f` = :$f";
                    $params[":$f"] = ($input[$f] === '' || $input[$f] === null) ? null : $input[$f];
                }
            }
            $db->prepare("UPDATE outreach_contatti SET " . implode(', ', $set) . " WHERE id = :id")->execute($params);
            echo json_encode(['success' => true]);
            break;

        case 'delete_contact':
            $id = (int)($input['id'] ?? 0);
            $db->prepare("DELETE FROM outreach_contatti WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['success' => true]);
            break;

        // --------------------------------------------------------
        // STATISTICHE
        // --------------------------------------------------------
        case 'get_stats':
            $cats = ['antiquari','mercatini','interior_designer','bb'];
            $out  = [];
            foreach ($cats as $cat) {
                $row = $db->query("SELECT
                    COUNT(*) as totale,
                    SUM(CASE WHEN stato='da_contattare'  THEN 1 ELSE 0 END) as da_fare,
                    SUM(CASE WHEN stato='inviato'        THEN 1 ELSE 0 END) as inviati,
                    SUM(CASE WHEN stato='risposto'       THEN 1 ELSE 0 END) as risposto,
                    SUM(CASE WHEN stato='partner'        THEN 1 ELSE 0 END) as partner
                    FROM outreach_contatti WHERE categoria='$cat'")->fetch();
                $out[$cat] = $row;
            }
            echo json_encode($out);
            break;

        // --------------------------------------------------------
        // TEMPLATE — CRUD
        // --------------------------------------------------------
        case 'get_templates':
            $rows = $db->query("SELECT * FROM outreach_template ORDER BY categoria, nome")->fetchAll();
            echo json_encode($rows);
            break;

        case 'save_template':
            $id = (int)($input['id'] ?? 0);
            if ($id) {
                $db->prepare("UPDATE outreach_template SET nome=:nome, categoria=:cat, oggetto=:ogg, corpo=:corpo, updated_at=NOW() WHERE id=:id")
                   ->execute([':nome'=>$input['nome'],':cat'=>$input['categoria'],':ogg'=>$input['oggetto'],':corpo'=>$input['corpo'],':id'=>$id]);
            } else {
                $db->prepare("INSERT INTO outreach_template (nome,categoria,oggetto,corpo) VALUES (:nome,:cat,:ogg,:corpo)")
                   ->execute([':nome'=>$input['nome'],':cat'=>$input['categoria'],':ogg'=>$input['oggetto'],':corpo'=>$input['corpo']]);
                $id = $db->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'delete_template':
            $id = (int)($input['id'] ?? 0);
            $db->prepare("DELETE FROM outreach_template WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['success' => true]);
            break;

        // --------------------------------------------------------
        // IMPORT ANTIQUARI (una-tantum)
        // --------------------------------------------------------
        case 'import_antiquari':
            $count = (int)$db->query("SELECT COUNT(*) FROM outreach_contatti WHERE categoria='antiquari'")->fetchColumn();
            if ($count > 0) {
                echo json_encode(['success' => true, 'message' => "Già importati: $count antiquari", 'skipped' => true]);
                break;
            }
            $list = [
                ['Magazzini Ruffi Antiquariato','antiquariato@ruffi.it','340221817','http://www.ruffi.it','P.le Ardeatino, 7 - 00154 Roma'],
                ['Laura Scribano Antiquariato','laurascribano@gmail.com','3755286733','https://www.laurascribano.com/','Via Gregorio VII 274 - Roma'],
                ['Antiquariato Valligiano','info@antiquariatovalligiano.it','335354973','https://www.antiquariatovalligiano.it','Via Giulia, 193'],
                ['Antiquariato Europeo','info@antiquariatoeuropeo.com','','https://www.antiquariatoeuropeo.com','Via Gregorio VII 272'],
                ['Antonio Antichità','','3388536256','https://antonioantichita.business.site/','Viale Città d\'Europa 00144 Roma'],
                ['Bruschini Tanca Antichità','info@galleriatanca.it','3392370004','https://www.galleriatanca.it/','Via dei Coronari, 8'],
                ['Antichità Ripetta','','63202699','','Via di Ripetta, 2, 00186 Roma'],
                ['Compro Arte Roma','info@comproarteroma.it','3401217967','https://www.comproarteroma.it/','Via Vodice, 9, 00195 Roma'],
                ['Sabrina Egidi','sabrina@achatantiquites.fr','3356585431','https://www.acquisto-antichita.it/','Via Boezio, 6, 00193 Roma'],
                ['Spazio Antiquario','','32937036018','https://www.spazioantiquario.it/','Via Trionfale n°4'],
                ['Antichità Alberto Di Castro','info@dicastro.com','66792269','http://www.dicastro.com/','Piazza di Spagna, 5, 00187 Roma'],
                ['Calò Antichità','albertocaloac@libero.it','3392517280','https://www.caloantichita.com/','Via Basento 73/75 Roma'],
                ['Mondo Antico Roma','','3922515117','https://mondoanticoshop.business.site/','Via Ancona, 30, 00198 Roma'],
                ['Antichità Luciano Prili','','668805660','','Via dei Banchi Nuovi, 26, 00186 Roma'],
                ['Antiquariato Anna Pace','','66865556','','Via dei Pettinari, 76, 00186 Roma'],
                ['Il Collezioniere Antichità','ilcollezioniere@yahoo.com','3356878031','https://www.ilcollezioniereantichitaroma.it/','Via Valerio Publicola, 21'],
                ['L\'Angelus Antichità','langelusantichita@yahoo.it','3891107667','http://www.langelusantichita.it/','Via dell\'Angeletto, 14'],
                ['Carlucci Gallerie Antiquarie','infcarlucci@libero.it','63227305','http://www.carluccigallerie.com/','Via del Babuino, 50'],
                ['Antichità Mosca','','3335441364','http://www.antichitamosca.com','Via Antonio Stoppani, 20'],
                ['La Casa della Nonna Antichità','','335411198','https://la-casa-della-nonna-antichita.business.site/','Via Panisperna, 105'],
                ['Antiquariato Bianca Scribano','','3333104172','','Via Gregorio VII, 350'],
                ['Antiquaria Sant\'Angelo','','','','Via del Banco di Santo Spirito, 61'],
                ['Lorenzale Antichità','','66864616','','Via dei Coronari, 3'],
                ['Alessandra Di Castro Antichità','info@alessandradicastro.com','669923127','http://www.alessandradicastro.com/','Piazza di Spagna, 4'],
                ['Antichità Italiane','laltrait@gmail.com','3406478042','https://www.antichitaitaliane.it/','Via Nizza, 26, 00198 Roma'],
                ['Antiquariato Roma','italiarredo.srl@gmail.com','636307456','https://www.romantiquariato.it/','Via di Vigna Stelluti, 34'],
                ['Galleria Antiqua - Argentieri','argentieri@galleriaantiqua.191.it','66873041','http://www.galleriantiqua.com/','Via Terenzio, 27-a'],
                ['Antiquariato Parioli','antiquariatoromaparioli@gmail.com','3457640360','https://www.antiquariatoparioli.com/','Via Tagliamento, 54'],
                ['De Luca Adele Roma','','3533456534','https://de-luca-adele-roma.business.site/','Via Merulana, 68'],
                ['Studio Antiquario Lampade','','66892814','','lg. Fontana di Borghese, 78'],
                ['Acanto Antiquariato di Edoardo Mele','','66865481','','Via della Stelletta, 10'],
                ['Antiq e Design Roma','','633251543','','Viale Eritrea, 70-L'],
                ['Antiquari in Piazza Borghese','info@bonino.us','3476760650','http://bonino.us/','Piazza Borghese, 111'],
                ['Negozio Antiquariato e Restauro','','63613770','','Via dei Greci, 7'],
                ['Martella Francesco Antiquariato','','644237240','','Via Pavia, 96'],
                ['L\'Occasione Antichità','info@loccasioneantichita.it','645492110','http://www.loccasioneantichita.it/','Via Francesco Caracciolo, 1'],
                ['Ferretti e Guerrini Antichità','','668307448','https://ferrettieguerrini.business.site/','Via dei Banchi Vecchi, 45'],
                ['Antichità Valerio Turchi','turchi.valerio@yahoo.it','0632350047','http://www.galleriavalerioturchi.it/','Via Margutta, 91'],
                ['Milena Tanca','info@antichitatanca.it','668806052','https://www.carillonantichi.com/','Via dei Coronari, 33'],
                ['Antichità Tiber Arte','','668136328','','Via del Piè di Marmo, 33'],
                ['Ponte Milvio Antiquariato','pontemilvioantiquariato@gmail.com','3343303829','http://www.pontemilvioantiquariato.it/','Via Capoprati, Roma'],
                ['Blu Old Silver Antiques','richblu@me.com','3476172400','http://www.bluoldsilverantiques.it/','Via dei Coronari, 37'],
                ['La Soffitta delle Meraviglie','','684240714','https://lasoffittadellemeraviglie.business.site','Via Tirso, 71'],
                ['Anna Maria Quattrini Antiquariato','','668801954','http://annamariaquattrini.it/','Via dei Coronari, 185'],
                ['Antico Zinelli di Simona Venturi','','68412658','','Via Andrea Ripa, 10'],
                ['Antiquariato e Arte','','63233676','','Via Alessandro Scarlatti, 1'],
                ['Antiquariato Carlo Montanaro','','','','Via Laurina, 31'],
                ['Murdocca Antichità','','66879166','','Via della Scrofa, 99'],
                ['La Lucerna','','3332807796','','Via Albenga, 22'],
                ['Hutong Roma','hutong@hutongroma.it','63233145','http://www.hutongroma.it/','Via dei Coronari, 55 A'],
                ['Antichità Fabbrini Arte','','3388840260','https://www.fabbriniarte.com/','Piazza Sabazio, 31'],
                ['Scorrano Carlo Antichità','','3394692726','','Via del Piè di Marmo, 33'],
                ['Antichità D\'Andrea','dandreangelo@hotmail.com','335236443','http://antichitadandrea.com/','Via della Scrofa, 114/116'],
                ['Oasi Antichità','info@oasiantichita.com','63207585','http://www.oasiantichita.com/','Via del Babuino, 83'],
                ['Galleria Parioli di Stefano Di Matteo','info@galleriaparioli.it','3395380778','http://www.galleriaparioli.it/','Piazza Verbano, 27'],
                ['Alessandra Corvi Antiquariato','info@stimavalutazioneantiquariato.it','68412822','http://www.stimavalutazioneantiquariato.it/','Via Salaria, 237'],
                ['Galleria dei Coronari','info@galleriadeicoronari.com','3348026214','https://www.proantic.com/galerie/galleria-dei-coronari/','Via dei Coronari, 59'],
                ['Modernariato e Design Lombardi','','3356754681','','Via dei Coronari, 29'],
                ['La Polvere e i Ricordi','lapolvereeiricordi@gmail.com','3385454421','https://www.lapolvereeiricordi.com/','Piazza Vescovio, 6A'],
                ['Galerie Giulia Antiquités','','3388845306','http://www.galeriegiulia.it/','Via Giulia, 140A'],
                ['Il Restauro','','66871402','https://il-restauro.business.site/','Vicolo della Torretta, 3A'],
                ['Arte Antica Rufini','rufini.paolo@libero.it','66865046','http://www.arteanticarufini.it/','Via dei Coronari, 79'],
                ['Mercatino Passato e Presente','','','https://www.mercatinopassatoepresente.it/','Via San Basilio 26, Roma'],
            ];
            $stmt = $db->prepare("INSERT INTO outreach_contatti (nome,categoria,email,telefono,sito,indirizzo,stato) VALUES (:nome,'antiquari',:email,:tel,:sito,:ind,'da_contattare')");
            $imported = 0;
            foreach ($list as $r) {
                $stmt->execute([':nome'=>$r[0], ':email'=>$r[1]?:null, ':tel'=>$r[2]?:null, ':sito'=>$r[3]?:null, ':ind'=>$r[4]?:null]);
                $imported++;
            }
            echo json_encode(['success' => true, 'imported' => $imported]);
            break;

        // --------------------------------------------------------
        // INIT TEMPLATE DI DEFAULT
        // --------------------------------------------------------
        case 'init_templates':
            $count = (int)$db->query("SELECT COUNT(*) FROM outreach_template")->fetchColumn();
            if ($count > 0) { echo json_encode(['success' => true, 'skipped' => true]); break; }
            $tpls = [
                [
                    'nome' => 'Antiquari — Proposta servizi',
                    'categoria' => 'antiquari',
                    'oggetto' => 'Ardy Lab — Restauro e valorizzazione mobili antichi | Proposta di collaborazione',
                    'corpo' => "Gentile {{nome}},\n\nmi chiamo Michela Panella, sono la fondatrice di Ardy Lab, bottega artigianale specializzata nel restauro e nella valorizzazione di mobili antichi a Roma EUR.\n\nHo trovato il vostro spazio cercando i migliori antiquari di Roma e vi scrivo perché credo ci siano interessanti possibilità di collaborazione.\n\nOffriamo:\n— Restauro conservativo e completo di mobili antichi\n— Patinature e laccature decorative artigianali\n— Doratura a foglia oro\n— Restauro cornici e specchiere\n— Complementi in stile con stampa 3D\n\nSappiamo che spesso i pezzi in vendita hanno bisogno di un intervento per trovare il cliente giusto. Potremmo essere il vostro laboratorio di riferimento per valorizzare i pezzi che lo richiedono, aumentandone vendibilità e valore.\n\nSarebbe un piacere incontrarci o parlare al telefono.\n\nCordiali saluti,\nMichela Panella\nArdy Lab · ardy-lab.it · +39 377 659 5547"
                ],
                [
                    'nome' => 'Antiquari — Partnership B&B Living Galleries',
                    'categoria' => 'antiquari',
                    'oggetto' => 'Ardy Lab — Un\'idea per trasformare i B&B di Roma in gallerie viventi',
                    'corpo' => "Gentile {{nome}},\n\nmi chiamo Michela Panella di Ardy Lab, bottega artigianale di Roma EUR.\n\nOltre ai nostri servizi di restauro, stiamo sviluppando un progetto chiamato Living Galleries: portiamo i pezzi restaurati e i complementi che produciamo all'interno di B&B boutique di Roma, dove i turisti possono vederli, toccarli e acquistarli.\n\nPensando ai pezzi che passano per il vostro spazio, mi è venuta un'idea: potremmo collaborare anche su questo fronte. Voi avete gli oggetti e i pezzi, noi abbiamo il laboratorio e il canale.\n\nSe vi fa piacere ne parliamo.\n\nCordiali saluti,\nMichela Panella\nArdy Lab · ardy-lab.it · +39 377 659 5547"
                ],
                [
                    'nome' => 'Interior Designer — Partnership fornitore',
                    'categoria' => 'interior_designer',
                    'oggetto' => 'Ardy Lab — Fornitore artigianale per i vostri progetti | Proposta',
                    'corpo' => "Gentile {{nome}},\n\nmi chiamo Michela Panella, restauratrice e fondatrice di Ardy Lab, laboratorio artigianale a Roma EUR.\n\nVi scrivo perché siamo il tipo di realtà che molti interior designer cercano: un laboratorio che lavora su misura, rispetta le scadenze e produce pezzi unici.\n\nCosa possiamo offrire ai vostri progetti:\n— Restauro e restyling di mobili per ambienti residenziali e contract\n— Laccature decorative e patinature personalizzate\n— Doratura a foglia oro su mobili, cornici e complementi\n— Produzione di complementi originali (anche con stampa 3D)\n— Boiserie — restauro e realizzazione\n\nLavoriamo su commessa con rendering incluso.\n\nSarei felice di mostrarvi il nostro portfolio.\n\nCordiali saluti,\nMichela Panella\nArdy Lab · ardy-lab.it · +39 377 659 5547"
                ],
                [
                    'nome' => 'B&B — Progetto Living Galleries',
                    'categoria' => 'bb',
                    'oggetto' => 'Ardy Lab — Le vostre camere diventano una galleria d\'arte vivente | Proposta',
                    'corpo' => "Gentile {{nome}},\n\nmi chiamo Michela Panella, fondatrice di Ardy Lab, bottega artigianale di Roma EUR specializzata nel restauro di mobili antichi e nella produzione di complementi d'arredo unici.\n\nVi scrivo per una proposta che potrebbe rendere il vostro B&B ancora più memorabile per i vostri ospiti.\n\nSi chiama Living Galleries:\n\n— Arrediamo le vostre camere con pezzi restaurati e complementi artigianali del nostro laboratorio\n— Ogni pezzo ha un tag NFC: l'ospite avvicina lo smartphone e scopre la storia dell'oggetto e del restauro\n— L'ospite può acquistare il pezzo o ordinarne una versione personalizzata\n— Voi non acquistate nulla: gli arredi restano di nostra proprietà in comodato\n\nPer voi è un'esperienza unica da offrire senza costi di arredamento. Per noi è una vetrina nel cuore di Roma.\n\nSarei felice di incontrarvi per mostrarvi qualche esempio concreto.\n\nCordiali saluti,\nMichela Panella\nArdy Lab · ardy-lab.it · +39 377 659 5547"
                ],
            ];
            $stmt = $db->prepare("INSERT INTO outreach_template (nome,categoria,oggetto,corpo) VALUES (:nome,:cat,:ogg,:corpo)");
            foreach ($tpls as $t) {
                $stmt->execute([':nome'=>$t['nome'],':cat'=>$t['categoria'],':ogg'=>$t['oggetto'],':corpo'=>$t['corpo']]);
            }
            echo json_encode(['success' => true, 'inserted' => count($tpls)]);
            break;

        // --------------------------------------------------------
        // INVIA EMAIL SINGOLA
        // --------------------------------------------------------
        case 'send_email':
            $cid = (int)($input['contact_id']  ?? 0);
            $tid = (int)($input['template_id'] ?? 0);
            $customOgg   = $input['oggetto'] ?? null;
            $customCorpo = $input['corpo']   ?? null;

            $c = $db->prepare("SELECT * FROM outreach_contatti WHERE id=:id");
            $c->execute([':id' => $cid]);
            $contact = $c->fetch();

            if (!$contact || !$contact['email']) {
                echo json_encode(['success' => false, 'error' => 'Contatto o email mancante']); break;
            }

            $oggetto = $customOgg;
            $corpo   = $customCorpo;
            if ($tid && !$customCorpo) {
                $t = $db->prepare("SELECT * FROM outreach_template WHERE id=:id");
                $t->execute([':id' => $tid]);
                $tpl = $t->fetch();
                if ($tpl) { $oggetto = $tpl['oggetto']; $corpo = $tpl['corpo']; }
            }
            if (!$oggetto || !$corpo) { echo json_encode(['success' => false, 'error' => 'Oggetto o corpo mancante']); break; }

            $nome  = $contact['referente'] ?: $contact['nome'];
            $corpo = str_replace(['{{nome}}','{{azienda}}'], [$nome, $contact['nome']], $corpo);

            $result = brevoSend($contact['email'], $nome, $oggetto, $corpo);
            if ($result['ok']) {
                $db->prepare("UPDATE outreach_contatti SET stato='inviato', data_contatto=CURDATE(), updated_at=NOW() WHERE id=:id")->execute([':id' => $cid]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $result['error']]);
            }
            break;

        // --------------------------------------------------------
        // INVIA CAMPAGNA (bulk)
        // --------------------------------------------------------
        case 'send_campaign':
            $ids = $input['contact_ids'] ?? [];
            $tid = (int)($input['template_id'] ?? 0);
            if (empty($ids)) { echo json_encode(['success' => false, 'error' => 'Nessun destinatario']); break; }

            $tpl = $db->prepare("SELECT * FROM outreach_template WHERE id=:id");
            $tpl->execute([':id' => $tid]);
            $t = $tpl->fetch();
            if (!$t) { echo json_encode(['success' => false, 'error' => 'Template non trovato']); break; }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT * FROM outreach_contatti WHERE id IN ($placeholders) AND email IS NOT NULL AND email != ''");
            $stmt->execute($ids);
            $contacts = $stmt->fetchAll();

            $sent = 0; $failed = 0; $errors = [];
            foreach ($contacts as $c) {
                $nome  = $c['referente'] ?: $c['nome'];
                $corpo = str_replace(['{{nome}}','{{azienda}}'], [$nome, $c['nome']], $t['corpo']);
                $res   = brevoSend($c['email'], $nome, $t['oggetto'], $corpo);
                if ($res['ok']) {
                    $db->prepare("UPDATE outreach_contatti SET stato='inviato', data_contatto=CURDATE(), updated_at=NOW() WHERE id=:id")->execute([':id' => $c['id']]);
                    $sent++;
                } else {
                    $failed++;
                    $errors[] = $c['nome'] . ': ' . $res['error'];
                }
                usleep(300000); // 300ms tra invii
            }
            echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed, 'errors' => $errors]);
            break;

        // --------------------------------------------------------
        // DISISCRIZIONE
        // --------------------------------------------------------
        case 'unsubscribe':
            $email = $input['email'] ?? '';
            if ($email) {
                $db->prepare("UPDATE outreach_contatti SET stato='non_interessato', updated_at=NOW() WHERE email=:email")->execute([':email' => $email]);
            }
            echo json_encode(['success' => true]);
            break;

        // --------------------------------------------------------
        // RICERCA AZIENDE IN RETE (OpenStreetMap — Nominatim + Overpass)
        // Cerca attività per categoria e zona, senza API key.
        // --------------------------------------------------------
        case 'web_search':
            $cat    = $input['categoria'] ?? 'antiquari';
            $citta  = trim($input['citta'] ?? '');
            $raggio = max(1, min(50, (int)($input['raggio'] ?? 10))); // km, 1..50
            if ($citta === '') { echo json_encode(['success' => false, 'error' => 'Indica una città o zona']); break; }

            // 1) Geocoding della zona
            $geo = osmGeocode($citta);
            if (!$geo) { echo json_encode(['success' => false, 'error' => 'Zona non trovata: ' . $citta]); break; }

            // 2) Overpass: attività della categoria entro il raggio
            $results = osmOverpass($cat, $geo['lat'], $geo['lon'], $raggio * 1000);
            if ($results === null) { echo json_encode(['success' => false, 'error' => 'Servizio di ricerca non raggiungibile, riprova']); break; }

            // 3) Marca i contatti già presenti (per nome o sito)
            $existing = $db->query("SELECT LOWER(nome) AS n, LOWER(COALESCE(sito,'')) AS s FROM outreach_contatti")->fetchAll();
            $exNomi = array_column($existing, 'n');
            $exSiti = array_filter(array_column($existing, 's'));
            foreach ($results as &$r) {
                $nomeL = strtolower($r['nome']);
                $sitoL = strtolower($r['sito'] ?? '');
                $r['exists'] = in_array($nomeL, $exNomi, true) || ($sitoL && in_array($sitoL, $exSiti, true));
            }
            unset($r);

            echo json_encode(['success' => true, 'zona' => $geo['display'], 'count' => count($results), 'results' => $results]);
            break;

        // --------------------------------------------------------
        // SALVA LEAD SELEZIONATI DALLA RICERCA (bulk)
        // --------------------------------------------------------
        case 'save_leads':
            $leads = $input['leads'] ?? [];
            $cat   = $input['categoria'] ?? 'antiquari';
            if (empty($leads) || !is_array($leads)) { echo json_encode(['success' => false, 'error' => 'Nessun lead da salvare']); break; }

            // Set per dedup (nome + sito già in DB)
            $existing = $db->query("SELECT LOWER(nome) AS n, LOWER(COALESCE(sito,'')) AS s FROM outreach_contatti")->fetchAll();
            $exNomi = array_column($existing, 'n');
            $exSiti = array_filter(array_column($existing, 's'));

            $ins = $db->prepare("INSERT INTO outreach_contatti (nome,categoria,email,telefono,sito,indirizzo,stato,note) VALUES (:nome,:cat,:email,:tel,:sito,:ind,'da_contattare',:note)");
            $saved = 0; $skipped = 0;
            foreach ($leads as $l) {
                $nome = trim($l['nome'] ?? '');
                if ($nome === '') { continue; }
                $sitoL = strtolower(trim($l['sito'] ?? ''));
                if (in_array(strtolower($nome), $exNomi, true) || ($sitoL && in_array($sitoL, $exSiti, true))) { $skipped++; continue; }
                $ins->execute([
                    ':nome'  => $nome,
                    ':cat'   => $cat,
                    ':email' => ($l['email'] ?? '') ?: null,
                    ':tel'   => ($l['telefono'] ?? '') ?: null,
                    ':sito'  => ($l['sito'] ?? '') ?: null,
                    ':ind'   => ($l['indirizzo'] ?? '') ?: null,
                    ':note'  => 'Trovato via ricerca OSM',
                ]);
                $exNomi[] = strtolower($nome);
                if ($sitoL) { $exSiti[] = $sitoL; }
                $saved++;
            }
            echo json_encode(['success' => true, 'saved' => $saved, 'skipped' => $skipped]);
            break;

        default:
            echo json_encode(['error' => 'Azione non riconosciuta: ' . $action]);
    }

} catch (PDOException $e) {
    error_log('ARDY OUTREACH API ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno']);
}

// ============================================================
// FUNZIONE INVIO BREVO
// ============================================================
function brevoSend(string $toEmail, string $toName, string $oggetto, string $corpo): array {
    $unsubSecret = defined('ARDY_UNSUB_SECRET') ? ARDY_UNSUB_SECRET : (defined('ARDY_API_KEY') ? ARDY_API_KEY : '');
    $unsubToken  = substr(hash_hmac('sha256', strtolower(trim($toEmail)), $unsubSecret), 0, 20);
    $unsubLink   = 'https://ardy-lab.it/ardy-unsubscribe.php?email=' . urlencode($toEmail) . '&t=' . $unsubToken;
    $corpoHtml  = nl2br(htmlspecialchars($corpo));
    $htmlEmail  = '<!DOCTYPE html><html><body style="font-family:Georgia,serif;background:#f5f5f5;margin:0;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;padding:40px;border-radius:4px;">
  <div style="border-bottom:2px solid #c8a96e;padding-bottom:20px;margin-bottom:30px;">
    ' . ardy_email_logo_url(44) . '
    <p style="color:#999;font-size:12px;margin:4px 0 0;font-family:sans-serif;">Restauro · Laccatura · Stampa 3D · Roma EUR</p>
  </div>
  <div style="font-size:15px;line-height:1.9;color:#333;">' . $corpoHtml . '</div>
  <div style="margin-top:40px;padding-top:20px;border-top:1px solid #eee;font-size:12px;color:#999;font-family:sans-serif;">
    <p style="margin:0;"><strong style="color:#333;">Ardy Lab</strong> · Via James Joyce 4, 00143 Roma EUR</p>
    <p style="margin:4px 0 0;"><a href="https://ardy-lab.it" style="color:#c8a96e;">ardy-lab.it</a></p>
    <p style="margin:8px 0 0;font-size:11px;"><a href="' . $unsubLink . '" style="color:#bbb;">Disiscriviti da questa lista</a></p>
  </div>
</div></body></html>';

    $payload = json_encode([
        'sender'      => ['name' => 'Ardy Lab', 'email' => 'noreply@ardy-lab.it'],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $oggetto,
        'htmlContent' => $htmlEmail,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'api-key: ' . ARDY_BREVO_API_KEY,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('ARDY BREVO CURL: ' . $err);
        return ['ok' => false, 'error' => 'Errore di connessione al servizio email'];
    }
    if ($code < 200 || $code >= 300) {
        error_log("ARDY BREVO HTTP $code: $res");
        return ['ok' => false, 'error' => "Invio non riuscito (HTTP $code)"];
    }
    return ['ok' => true];
}

// ============================================================
// RICERCA OSM — Geocoding (Nominatim) + attività (Overpass)
// ============================================================
function osmHttpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        45);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    // Nominatim/Overpass richiedono uno User-Agent identificativo
    curl_setopt($ch, CURLOPT_USERAGENT, 'ArdyLabOutreach/1.0 (https://ardy-lab.it)');
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res || $code < 200 || $code >= 300) return null;
    return $res;
}

function osmGeocode(string $query): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($query);
    $res = osmHttpGet($url);
    if (!$res) return null;
    $data = json_decode($res, true);
    if (empty($data[0]['lat'])) return null;
    return [
        'lat'     => (float)$data[0]['lat'],
        'lon'     => (float)$data[0]['lon'],
        'display' => $data[0]['display_name'] ?? $query,
    ];
}

// Mappa categoria Ardy -> filtri OSM (tag) per Overpass
function osmCategoryFilters(string $cat): array {
    switch ($cat) {
        case 'antiquari':         return ['["shop"="antiques"]'];
        case 'mercatini':         return ['["shop"="second_hand"]', '["amenity"="marketplace"]'];
        case 'interior_designer': return ['["office"="interior_design"]', '["shop"="interior_decoration"]', '["craft"="interior_work"]'];
        case 'bb':                return ['["tourism"="guest_house"]', '["tourism"="bed_and_breakfast"]'];
        default:                  return ['["shop"="antiques"]'];
    }
}

function osmOverpass(string $cat, float $lat, float $lon, int $radiusMeters): ?array {
    $filters = osmCategoryFilters($cat);
    $parts = '';
    foreach ($filters as $f) {
        // nwr = node + way + relation, con nome obbligatorio
        $parts .= "nwr{$f}[\"name\"](around:{$radiusMeters},{$lat},{$lon});";
    }
    $query = "[out:json][timeout:40];({$parts});out center tags 80;";
    $res = osmHttpGet('https://overpass-api.de/api/interpreter?data=' . urlencode($query));
    if (!$res) return null;
    $data = json_decode($res, true);
    if (!isset($data['elements'])) return [];

    $out  = [];
    $seen = [];
    foreach ($data['elements'] as $el) {
        $t = $el['tags'] ?? [];
        $nome = trim($t['name'] ?? '');
        if ($nome === '') continue;
        $key = strtolower($nome);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        // Indirizzo da tag addr:*
        $via   = trim(($t['addr:street'] ?? '') . ' ' . ($t['addr:housenumber'] ?? ''));
        $citta = trim(($t['addr:postcode'] ?? '') . ' ' . ($t['addr:city'] ?? ''));
        $indirizzo = trim($via . ($via && $citta ? ', ' : '') . $citta);

        $sito = $t['website'] ?? ($t['contact:website'] ?? '');
        $tel  = $t['phone']   ?? ($t['contact:phone']   ?? '');
        $email= $t['email']   ?? ($t['contact:email']   ?? '');

        $out[] = [
            'nome'      => $nome,
            'indirizzo' => $indirizzo,
            'sito'      => $sito,
            'telefono'  => $tel,
            'email'     => $email,
        ];
    }
    // Ordina alfabeticamente
    usort($out, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
    return $out;
}
