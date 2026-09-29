<?php
declare(strict_types=1);

/**
 * Scoring du trimestre — quatre postes de cinq points, magasin par magasin.
 *
 *   1. Note Google      la note de la fiche au dernier relevé du trimestre, telle quelle.
 *   2. Tâches           la moyenne des journées relevées dans le panel : chaque jour vaut
 *                       la part des tâches rendues (une tâche notée vaut sa cote / 5) ;
 *                       une tâche OBLIGATOIRE (checklist CO-) non faite met le jour à 0.
 *   3. Client mystère   la note reçue, encodée « obtenu / maximum » avec le PDF, sur 5.
 *   4. Budget           le CA du trimestre face au budget des trois mois : 100 % = 5, 90 % = 4,
 *                       80 % = 3, 70 % = 2, 60 % = 1, 50 % et moins = 0 — au prorata entre deux.
 *
 * Le total est toujours sur 20 : un poste sans donnée compte 0, et il se dit — le
 * tableau nomme ce qui manque (budget non encodé, client mystère à encoder). Les
 * étoiles sont le total ramené sur 5.
 *
 * Tout ce qui se lit ailleurs est relu, jamais recopié : la réputation
 * (`ceo_shop_reputation`), le relevé des tâches (`ceo_tache_jour`), le budget
 * (`/stores/perf`). Deux tables à ce module : la note Google gelée en fin de
 * trimestre (sinon un trimestre passé ne se comparerait plus) et le rapport du
 * client mystère. Le rapport A4 part par le reporting, le 1er jour du
 * trimestre suivant à 8 h, à chaque magasin du carnet, en copie au réseau.
 */

const SQ_POSTES = [
    'google' => ['nom' => 'Note Google', 'court' => 'Google',
        'regle' => 'La note de la fiche Google au dernier relevé du trimestre, telle quelle : 4,8 = 4,8 / 5.'],
    'taches' => ['nom' => 'Tâches', 'court' => 'Tâches',
        'regle' => 'Moyenne des journées du trimestre relevées dans le panel : chaque jour vaut la part des tâches rendues (une tâche notée vaut sa cote). Une tâche obligatoire (CO-) non faite met le jour à 0.'],
    'msp' => ['nom' => 'Client mystère', 'court' => 'Client mystère',
        'regle' => 'La note reçue au trimestre, encodée « obtenu / maximum » avec le rapport PDF, ramenée sur 5 : 68 / 80 = 4,3.'],
    'budget' => ['nom' => 'Budget', 'court' => 'Budget',
        'regle' => 'CA du trimestre face au budget des trois mois : 100 % = 5, 90 % = 4, 80 % = 3, 70 % = 2, 60 % = 1, 50 % et moins = 0 — au prorata entre deux paliers.'],
];
const SQ_RAPPORT_CODE = 'scoring-trimestre';
const SQ_MOIS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function ensureScoring(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_scoring_msp ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'trimestre CHAR(7) NOT NULL,'
        . 'obtenu DECIMAL(8,2) NOT NULL,'
        . 'maximum DECIMAL(8,2) NOT NULL,'
        . 'rubriques TEXT NULL,'
        . 'commentaire TEXT NULL,'
        . 'fichier VARCHAR(200) NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'le DATETIME NULL,'
        . 'UNIQUE KEY uq_sq_msp (shop_id, trimestre)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_scoring_google ('
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'trimestre CHAR(7) NOT NULL,'
        . 'note DECIMAL(3,2) NULL,'
        . 'avis INT NULL,'
        . 'le DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, trimestre)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    sqRapportSemer();
}

/** La ligne de reporting du scoring — semée si le reporting est là et qu'elle manque. */
function sqRapportSemer(): void
{
    try {
        if (Db::row('SELECT id FROM ceo_rapport WHERE code = ?', [SQ_RAPPORT_CODE]) !== null) { return; }
        Db::exec('INSERT INTO ceo_rapport (code, nom, poste, frequence, heure, jour, blocs, destinataires, par_magasin, actif, envoi_mode)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [SQ_RAPPORT_CODE, 'Scoring du trimestre — classement et fiche magasin', 'Magasins', 'mensuel', 8, 1,
             json_encode([]), json_encode([]), 1, 1, 'par-magasin']);
    } catch (PDOException $e) { /* le reporting n'est pas installé ici */ }
}

/* --- le trimestre ------------------------------------------------------------ */

function sqTrimestreCourant(): string
{
    return date('Y') . '-T' . (int) ceil((int) date('n') / 3);
}

/** « 2026-T3 » → ses bornes, ses mois, ses libellés, le trimestre d'avant. */
function sqTrimestre(?string $t): array
{
    if (!is_string($t) || !preg_match('/^(\d{4})-T([1-4])$/', $t, $m)) { $t = sqTrimestreCourant(); preg_match('/^(\d{4})-T([1-4])$/', $t, $m); }
    $a = (int) $m[1]; $n = (int) $m[2];
    $mois = [($n - 1) * 3 + 1, ($n - 1) * 3 + 2, ($n - 1) * 3 + 3];
    $du = sprintf('%04d-%02d-01', $a, $mois[0]);
    $au = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $a, $mois[2])));
    $hier = date('Y-m-d', strtotime('-1 day'));
    $prec = $n === 1 ? ($a - 1) . '-T4' : $a . '-T' . ($n - 1);
    $suiv = $n === 4 ? ($a + 1) . '-T1' : $a . '-T' . ($n + 1);
    return ['cle' => $t, 'annee' => $a, 'n' => $n, 'mois' => $mois, 'du' => $du, 'au' => $au,
        'arrete' => $au < $hier ? $au : $hier, 'clos' => $au < date('Y-m-d'), 'enCours' => $t === sqTrimestreCourant(),
        'court' => 'T' . $n . ' ' . $a, 'lib' => 'T' . $n . ' ' . $a . ' — ' . SQ_MOIS[$mois[0]] . ' → ' . SQ_MOIS[$mois[2]],
        'prec' => $prec, 'suiv' => $suiv];
}

/** Les huit derniers trimestres, le courant en tête. */
function sqTrimestres(): array
{
    $out = []; $t = sqTrimestreCourant();
    for ($i = 0; $i < 8; $i++) { $tr = sqTrimestre($t); $out[] = ['cle' => $tr['cle'], 'lib' => $tr['lib'], 'enCours' => $tr['enCours']]; $t = $tr['prec']; }
    return $out;
}

/** Les magasins ouverts — id => [id, nom, court, fr]. */
function sqMagasins(): array
{
    if (function_exists('viMagasins')) {
        $m = viMagasins();
        if ($m !== []) { return $m; }
    }
    $out = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $r) {
            $nom = (string) $r['name'];
            $out[(string) $r['id']] = ['id' => (string) $r['id'], 'nom' => $nom,
                'court' => str_contains($nom, ' - ') ? trim(substr($nom, strrpos($nom, ' - ') + 3)) : $nom, 'ville' => '', 'fr' => ''];
        }
    } catch (PDOException $e) { /* pas de table */ }
    return $out;
}

/* --- les quatre postes -------------------------------------------------------- */

/**
 * La note Google du trimestre. Pour le trimestre en cours, c'est la fiche
 * d'aujourd'hui, et on la GÈLE au passage (une ligne par magasin et trimestre,
 * réécrite à chaque lecture) ; pour un trimestre passé, c'est ce qui a été gelé.
 */
function sqGoogle(array $tri): array
{
    $out = [];
    if ($tri['enCours'] || !$tri['clos']) {
        try {
            foreach (Db::rows('SELECT shop_id, rating_avg, rating_count, synced_at FROM ceo_shop_reputation') as $r) {
                $sid = (string) $r['shop_id'];
                $note = $r['rating_avg'] !== null ? round((float) $r['rating_avg'], 2) : null;
                $out[$sid] = ['v' => $note, 'note' => $note, 'avis' => (int) $r['rating_count'], 'le' => $r['synced_at'] ? substr((string) $r['synced_at'], 0, 16) : null, 'gele' => false];
                if ($note !== null) {
                    Db::exec('INSERT INTO ceo_scoring_google (shop_id, trimestre, note, avis, le) VALUES (?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE note = VALUES(note), avis = VALUES(avis), le = VALUES(le)',
                        [$sid, $tri['cle'], $note, (int) $r['rating_count'], date('Y-m-d H:i:s')]);
                }
            }
        } catch (PDOException $e) { /* réputation absente */ }
        if ($out !== []) { return $out; }
    }
    try {
        foreach (Db::rows('SELECT shop_id, note, avis, le FROM ceo_scoring_google WHERE trimestre = ?', [$tri['cle']]) as $r) {
            $note = $r['note'] !== null ? round((float) $r['note'], 2) : null;
            $out[(string) $r['shop_id']] = ['v' => $note, 'note' => $note, 'avis' => (int) $r['avis'], 'le' => $r['le'] ? substr((string) $r['le'], 0, 16) : null, 'gele' => true];
        }
    } catch (PDOException $e) { /* table absente */ }
    return $out;
}

/**
 * Les tâches obligatoires : celles dont la checklist du panel commence par
 * « CO- » — lues une fois par jour sur les tâches d'aujourd'hui, gardées en
 * réglage. Le relevé quotidien ne porte pas la checklist ; l'identifiant de
 * tâche, lui, ne change pas d'un jour à l'autre.
 */
function sqObligatoires(): array
{
    $c = setting('scoringObligatoires');
    if (is_array($c) && (string) ($c['quand'] ?? '') === date('Y-m-d')) { return $c; }
    $ids = []; $noms = [];
    $lu = false;
    if (class_exists('PanelApi') && PanelApi::configured() && function_exists('ep_pwa_tasks')) {
        $avant = $_GET; $_GET['date'] = date('Y-m-d');
        try { $d = ep_pwa_tasks(); } catch (Throwable $e) { $d = null; } finally { $_GET = $avant; }
        if (is_array($d) && empty($d['indispo'])) {
            $lu = true;
            foreach (($d['shops'] ?? []) as $s) {
                foreach (($s['taches'] ?? []) as $t) {
                    if (preg_match('/^CO-/i', (string) ($t['checklist'] ?? ''))) {
                        $ids[(string) $t['taskId']] = true; $noms[(string) $t['taskId']] = (string) ($t['tache'] ?? '');
                    }
                }
            }
        }
    }
    if (!$lu) { return is_array($c) ? $c : ['quand' => null, 'ids' => [], 'noms' => []]; }
    $c = ['quand' => date('Y-m-d'), 'ids' => array_keys($ids), 'noms' => $noms];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['scoringObligatoires', json_encode($c, JSON_UNESCAPED_UNICODE)]);
    return $c;
}

/** Le poste Tâches d'un trimestre, magasin par magasin. */
function sqTaches(array $tri): array
{
    $oblig = sqObligatoires();
    $ob = array_flip(array_map('strval', $oblig['ids'] ?? []));
    $parJour = [];
    try {
        foreach (Db::rows('SELECT jour, id_shop, id_task, fait, note FROM ceo_tache_jour WHERE jour BETWEEN ? AND ?', [$tri['du'], $tri['arrete']]) as $r) {
            $parJour[(string) $r['id_shop']][(string) $r['jour']][] = $r;
        }
    } catch (PDOException $e) { return ['indispo' => 'le relevé des tâches n’est pas là']; }
    $out = [];
    foreach ($parJour as $sid => $jours) {
        $som = 0.0; $n = 0; $faites = 0; $attendues = 0; $manq = 0; $joursZero = 0;
        foreach ($jours as $jour => $ts) {
            $sj = 0.0; $mj = 0;
            foreach ($ts as $t) {
                $attendues++;
                $fait = (int) $t['fait'] === 1;
                if ($fait) { $faites++; $sj += $t['note'] !== null && (int) $t['note'] > 0 ? min(1, (int) $t['note'] / 5) : 1; }
                elseif (isset($ob[(string) $t['id_task']])) { $mj++; }
            }
            if ($mj > 0) { $manq += $mj; $joursZero++; $sj = 0.0; } else { $sj = count($ts) ? $sj / count($ts) : 0.0; }
            $som += $sj; $n++;
        }
        $out[$sid] = ['v' => $n ? round($som / $n * 5, 2) : null, 'part' => $attendues ? round(100 * $faites / $attendues) : null,
            'faites' => $faites, 'attendues' => $attendues, 'jours' => $n, 'manquees' => $manq, 'joursZero' => $joursZero];
    }
    return $out;
}

/** Le poste Budget : le CA des mois du trimestre face à leur budget — lu là où le cockpit le lit. */
function sqBudget(array $tri): array
{
    // La route /stores/perf est servie par ep_perf() (ep_stores_perf sur le banc).
    $ep = function_exists('ep_perf') ? 'ep_perf' : (function_exists('ep_stores_perf') ? 'ep_stores_perf' : null);
    if ($ep === null) { return []; }
    $avant = $_GET; $_GET['granularite'] = 'mois'; $_GET['annees'] = (string) $tri['annee'];
    try { $L = $ep(); } catch (Throwable $e) { $L = []; } finally { $_GET = $avant; }
    $acc = [];
    foreach (is_array($L) ? $L : [] as $r) {
        if ((int) ($r['annee'] ?? 0) !== $tri['annee'] || !in_array((int) ($r['mois'] ?? 0), $tri['mois'], true)) { continue; }
        $sid = (string) $r['storeId'];
        $b = $r['caBudget'] ?? null; $ca = $r['ca'] ?? null;
        if ($b === null || (float) $b <= 0 || $ca === null) { $acc[$sid] = $acc[$sid] ?? ['ca' => 0.0, 'budget' => 0.0, 'mois' => 0]; continue; }
        $acc[$sid] = $acc[$sid] ?? ['ca' => 0.0, 'budget' => 0.0, 'mois' => 0];
        $acc[$sid]['ca'] += (float) $ca; $acc[$sid]['budget'] += (float) $b; $acc[$sid]['mois']++;
    }
    $out = [];
    foreach ($acc as $sid => $a) {
        $ratio = $a['budget'] > 0 ? $a['ca'] / $a['budget'] : null;
        // L'échelle : 100 % = 5, 90 % = 4 … 60 % = 1, 50 % et moins = 0, au prorata entre deux paliers.
        $out[$sid] = ['v' => $ratio !== null ? round(max(0, min(5, ($ratio - 0.5) * 10)), 2) : null, 'ratio' => $ratio !== null ? round(100 * $ratio, 1) : null,
            'ca' => round($a['ca']), 'budget' => round($a['budget']), 'mois' => $a['mois']];
    }
    return $out;
}

function sqMspLigne(array $r): array
{
    $max = (float) $r['maximum'];
    return ['id' => (int) $r['id'], 'shop' => (string) $r['shop_id'], 'trimestre' => (string) $r['trimestre'],
        'obtenu' => (float) $r['obtenu'], 'maximum' => $max, 'v' => $max > 0 ? round(min(5, (float) $r['obtenu'] / $max * 5), 2) : null,
        'rubriques' => $r['rubriques'] ? (json_decode((string) $r['rubriques'], true) ?: []) : [],
        'commentaire' => $r['commentaire'], 'fichier' => $r['fichier'], 'par' => $r['par'], 'le' => $r['le'] ? substr((string) $r['le'], 0, 16) : null];
}

/** Le poste Client mystère : la note encodée du trimestre. */
function sqMsp(array $tri): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_scoring_msp WHERE trimestre = ?', [$tri['cle']]) as $r) { $out[(string) $r['shop_id']] = sqMspLigne($r); }
    return $out;
}

/** Les quatre postes d'un trimestre, pour chaque magasin, avec le total et les étoiles. */
function sqPostes(array $tri, array $magasins): array
{
    $g = sqGoogle($tri); $t = sqTaches($tri); $b = sqBudget($tri); $m = sqMsp($tri);
    $out = [];
    foreach ($magasins as $sid => $mag) {
        $p = ['google' => $g[$sid] ?? ['v' => null], 'taches' => isset($t['indispo']) ? ['v' => null, 'motif' => $t['indispo']] : ($t[$sid] ?? ['v' => null, 'motif' => 'aucune journée relevée']),
              'msp' => $m[$sid] ?? ['v' => null], 'budget' => $b[$sid] ?? ['v' => null]];
        $notes = array_values(array_filter(array_map(fn ($x) => $x['v'], $p), fn ($v) => $v !== null));
        $n = count($notes); $total = array_sum($notes);
        // Toujours sur 20 : un poste sans donnée vaut 0 (et se dit), les étoiles sont le total sur 5.
        $out[$sid] = ['postes' => $p, 'total' => round($total, 2), 'n' => $n, 'sur' => 20, 'etoiles' => round($total / 4, 2)];
    }
    return $out;
}

/** Tout le scoring d'un trimestre : le classement, le trimestre d'avant, le réseau. */
function sqCalcul(array $tri): array
{
    ensureScoring();
    $magasins = sqMagasins();
    $now = sqPostes($tri, $magasins);
    $prec = sqPostes(sqTrimestre($tri['prec']), $magasins);
    $lignes = [];
    foreach ($magasins as $sid => $mag) {
        $a = $now[$sid]; $b = $prec[$sid];
        // Les clés numériques d'un tableau PHP deviennent des entiers : l'identifiant repart en chaîne, comme partout.
        $lignes[] = ['id' => (string) $sid, 'nom' => $mag['nom'], 'court' => $mag['court'], 'fr' => $mag['fr'] ?? '',
            'postes' => $a['postes'], 'total' => $a['total'], 'n' => $a['n'], 'sur' => $a['sur'], 'etoiles' => $a['etoiles'],
            'prec' => ['total' => $b['total'], 'n' => $b['n'], 'etoiles' => $b['etoiles'], 'postes' => array_map(fn ($x) => $x['v'], $b['postes'])],
            'delta' => $a['etoiles'] !== null && $b['etoiles'] !== null ? round($a['etoiles'] - $b['etoiles'], 2) : null];
    }
    usort($lignes, fn ($x, $y) => ($y['etoiles'] ?? -1) <=> ($x['etoiles'] ?? -1) ?: $y['total'] <=> $x['total'] ?: strcmp($x['court'], $y['court']));
    foreach ($lignes as $i => &$l) { $l['rang'] = $i + 1; }
    unset($l);
    $complets = array_filter($lignes, fn ($l) => $l['n'] === 4);
    $etoiles = array_map(fn ($l) => $l['etoiles'], $lignes);
    $oblig = sqObligatoires();
    $rep = null;
    try { $rep = Db::row('SELECT id, destinataires, dest_par_magasin, actif FROM ceo_rapport WHERE code = ?', [SQ_RAPPORT_CODE]); } catch (PDOException $e) { /* pas de reporting */ }
    $carnet = $rep ? (json_decode((string) ($rep['dest_par_magasin'] ?? ''), true) ?: []) : [];
    return ['trimestre' => $tri, 'trimestres' => sqTrimestres(), 'postes' => SQ_POSTES, 'magasins' => $lignes,
        'reseau' => ['sur20' => $lignes ? round(array_sum(array_map(fn ($l) => $l['total'], $lignes)) / count($lignes), 1) : null, 'magasins' => count($lignes), 'complets' => count($complets),
            'etoiles' => $etoiles ? round(array_sum($etoiles) / count($etoiles), 2) : null],
        'sources' => ['obligatoires' => count($oblig['ids'] ?? []), 'obligatoiresLues' => $oblig['quand'] ?? null,
            'googleSynchro' => max(array_map(fn ($l) => (string) ($l['postes']['google']['le'] ?? ''), $lignes) ?: ['']) ?: null],
        'rapport' => $rep ? ['id' => (int) $rep['id'], 'actif' => (int) $rep['actif'] === 1,
            'copies' => array_values(array_filter(json_decode((string) ($rep['destinataires'] ?? '[]'), true) ?: [], fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL))),
            'carnet' => array_map(fn ($mag) => count(array_filter((array) ($carnet[$mag['nom']] ?? []), fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL))), $magasins),
            'smtp' => class_exists('Smtp') && Smtp::configured()] : null];
}

/* --- les lectures --------------------------------------------------------------- */

/** GET /scoring?trimestre=2026-T3 — le tableau du trimestre. */
function ep_scoring(): array
{
    return sqCalcul(sqTrimestre($_GET['trimestre'] ?? null));
}

/** GET /scoring/msp?shop=5 — l'historique des rapports client mystère d'un magasin (tout le réseau sans shop). */
function ep_scoring_msp(): array
{
    ensureScoring();
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $rows = Db::rows('SELECT * FROM ceo_scoring_msp' . ($shop !== '' ? ' WHERE shop_id = ?' : '') . ' ORDER BY trimestre DESC, shop_id', $shop !== '' ? [$shop] : []);
    return ['shop' => $shop, 'msp' => array_map('sqMspLigne', $rows)];
}

/**
 * POST /scoring/msp — le rapport client mystère d'un trimestre :
 * { shop, trimestre, obtenu, maximum, rubriques: { "Accueil": "17 / 20" }, commentaire, fichier (data:application/pdf;base64,…), par }.
 * Réencoder remplace ; le PDF est gardé s'il n'est pas renvoyé.
 */
function wr_scoring_msp(): array
{
    ensureScoring();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    if (!isset(sqMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $tri = sqTrimestre(is_string($b['trimestre'] ?? null) ? $b['trimestre'] : null);
    $obtenu = is_numeric($b['obtenu'] ?? null) ? round((float) $b['obtenu'], 2) : null;
    $max = is_numeric($b['maximum'] ?? null) ? round((float) $b['maximum'], 2) : null;
    if ($obtenu === null || $max === null || $max <= 0 || $obtenu < 0 || $obtenu > $max) { http_response_code(422); return ['error' => 'note attendue : obtenu et maximum, 0 ≤ obtenu ≤ maximum']; }
    $rub = [];
    foreach (is_array($b['rubriques'] ?? null) ? $b['rubriques'] : [] as $k => $v) {
        $k = mb_substr(trim((string) $k), 0, 60); $v = mb_substr(trim((string) $v), 0, 40);
        if ($k !== '' && $v !== '') { $rub[$k] = $v; }
    }
    $fichier = null;
    if (!empty($b['fichier']) && preg_match('#^data:application/pdf;base64,(.+)$#s', (string) $b['fichier'], $m)) {
        $bin = base64_decode($m[1], true);
        if ($bin === false || !str_starts_with($bin, '%PDF')) { http_response_code(422); return ['error' => 'le fichier n’est pas un PDF']; }
        if (strlen($bin) > 8 * 1024 * 1024) { http_response_code(422); return ['error' => 'PDF de plus de 8 Mo']; }
        $dossier = __DIR__ . '/../public/uploads/scoring/msp';
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true)) { http_response_code(500); return ['error' => 'dossier uploads/scoring inaccessible']; }
        $nom = preg_replace('/[^\w-]/', '', $shop) . '-' . $tri['cle'] . '.pdf';
        if (@file_put_contents($dossier . '/' . $nom, $bin) === false) { http_response_code(500); return ['error' => 'PDF non enregistré (droits du dossier uploads)']; }
        $fichier = 'uploads/scoring/msp/' . $nom;
    }
    $par = mb_substr(trim((string) ($b['par'] ?? '')), 0, 120) ?: null;
    Db::exec('INSERT INTO ceo_scoring_msp (shop_id, trimestre, obtenu, maximum, rubriques, commentaire, fichier, par, le) VALUES (?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE obtenu = VALUES(obtenu), maximum = VALUES(maximum), rubriques = VALUES(rubriques), commentaire = VALUES(commentaire),
              fichier = COALESCE(VALUES(fichier), fichier), par = VALUES(par), le = VALUES(le)',
        [$shop, $tri['cle'], $obtenu, $max, json_encode($rub, JSON_UNESCAPED_UNICODE), mb_substr(trim((string) ($b['commentaire'] ?? '')), 0, 2000) ?: null, $fichier, $par, date('Y-m-d H:i:s')]);
    $r = Db::row('SELECT * FROM ceo_scoring_msp WHERE shop_id = ? AND trimestre = ?', [$shop, $tri['cle']]);
    if (function_exists('journalAdd')) { journalAdd($par ?: 'CEO', 'Scoring', null, 'Client mystère ' . $tri['court'] . ' — ' . (sqMagasins()[$shop]['court'] ?? $shop) . ' : ' . $obtenu . ' / ' . $max); }
    return ['ok' => true, 'msp' => $r ? sqMspLigne($r) : null];
}

/** DELETE /scoring/msp/{id} — efface le rapport (et son PDF). */
function wr_scoring_msp_suppr(int $id): array
{
    ensureScoring();
    $r = Db::row('SELECT * FROM ceo_scoring_msp WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'rapport inconnu']; }
    if ($r['fichier']) { @unlink(__DIR__ . '/../public/' . $r['fichier']); }
    Db::exec('DELETE FROM ceo_scoring_msp WHERE id = ?', [$id]);
    return ['ok' => true];
}

/* --- le rapport A4 ---------------------------------------------------------------- */

function sqH(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function sqNf(?float $n, int $d = 1): string { return $n === null ? '—' : number_format($n, $d, ',', ' '); }
function sqEtoiles(?float $v, int $px = 13): string
{
    $pct = $v === null ? 0 : max(0, min(100, $v / 5 * 100));
    return '<span style="position:relative;display:inline-block;font-size:' . $px . 'px;line-height:1;letter-spacing:1px;color:#ddd4c6;white-space:nowrap' . ($v === null ? ';opacity:.45' : '') . '">'
        . '<span>&#9733;&#9733;&#9733;&#9733;&#9733;</span>'
        . '<span style="position:absolute;left:0;top:0;overflow:hidden;color:#e2b93b;white-space:nowrap;width:' . number_format($pct, 1, '.', '') . '%">&#9733;&#9733;&#9733;&#9733;&#9733;</span></span>';
}
/** Le détail d'un poste en une ligne — ce qui fait la note. */
function sqDetail(string $cle, array $p): string
{
    if ($cle === 'google') { return $p['v'] === null ? 'pas de fiche Google reliée' : sqNf($p['note']) . ' · ' . (int) $p['avis'] . ' avis' . (!empty($p['gele']) ? ' · gelée en fin de trimestre' : ''); }
    if ($cle === 'taches') {
        if ($p['v'] === null) { return (string) ($p['motif'] ?? 'aucune journée relevée'); }
        $s = (int) $p['part'] . ' % faites · ' . number_format($p['faites'], 0, ',', ' ') . ' / ' . number_format($p['attendues'], 0, ',', ' ') . ' · ' . (int) $p['jours'] . ' jours';
        if (!empty($p['joursZero'])) { $s .= ' · ' . (int) $p['joursZero'] . ' jour' . ($p['joursZero'] > 1 ? 's' : '') . ' à 0 (obligatoire manquée)'; }
        return $s;
    }
    if ($cle === 'msp') { return $p['v'] === null ? 'à encoder' : sqNf($p['obtenu'], 0) . ' / ' . sqNf($p['maximum'], 0) . ($p['le'] ? ' · reçu le ' . substr((string) $p['le'], 8, 2) . '/' . substr((string) $p['le'], 5, 2) : ''); }
    if ($cle === 'budget') { return $p['v'] === null ? 'budget non encodé' : sqNf($p['ratio']) . ' % · ' . number_format($p['ca'], 0, ',', ' ') . ' € / ' . number_format($p['budget'], 0, ',', ' ') . ' €' . ($p['mois'] < 3 ? ' · ' . $p['mois'] . ' mois budgété' . ($p['mois'] > 1 ? 's' : '') : ''); }
    return '';
}

/** Ce qu'on en fait — trois ou quatre lignes par magasin, tirées de ses chiffres. */
function sqConseils(array $l): array
{
    $p = $l['postes']; $c = [];
    $t = $p['taches'];
    if ($t['v'] !== null && $t['v'] < 2.5) {
        $c[] = '<b>Tâches — ' . sqNf($t['v']) . ' / 5.</b> ' . ($t['part'] === 0 ? 'Aucune tâche du panel n’est rendue' : 'Seulement ' . (int) $t['part'] . ' % des tâches sont rendues')
            . (!empty($t['joursZero']) ? ' ; ' . (int) $t['joursZero'] . ' journée' . ($t['joursZero'] > 1 ? 's' : '') . ' à 0 parce qu’une obligatoire manquait' : '')
            . '. Rendre chaque jour les obligatoires (photos du comptoir, clôture de caisse, contrôles qualité) est le levier le plus rapide du scoring.';
    } elseif ($t['v'] !== null) { $c[] = '<b>Tâches — ' . sqNf($t['v']) . ' / 5.</b> ' . (int) $t['part'] . ' % des tâches rendues sur ' . (int) $t['jours'] . ' jours ; tenir le rythme.'; }
    $b = $p['budget'];
    if ($b['v'] !== null) {
        $ecart = $b['budget'] - $b['ca'];
        $c[] = '<b>Budget — ' . sqNf($b['ratio']) . ' %.</b> ' . number_format($b['ca'], 0, ',', ' ') . ' € pour ' . number_format($b['budget'], 0, ',', ' ') . ' € budgétés'
            . ($ecart > 0 ? ' : il manque ' . number_format($ecart, 0, ',', ' ') . ' €.' : ' : budget dépassé de ' . number_format(-$ecart, 0, ',', ' ') . ' €.');
    } else { $c[] = '<b>Budget.</b> Aucun budget encodé pour ce trimestre : le poste ne compte pas. L’encoder dans Budget rend le scoring complet.'; }
    $m = $p['msp'];
    if ($m['v'] !== null) { $c[] = '<b>Client mystère — ' . sqNf($m['obtenu'], 0) . ' / ' . sqNf($m['maximum'], 0) . '.</b> ' . ($m['commentaire'] ? sqH((string) $m['commentaire']) : 'Le rapport complet est joint.'); }
    else { $c[] = '<b>Client mystère.</b> Pas de rapport encodé ce trimestre : le poste ne compte pas.'; }
    $g = $p['google'];
    if ($g['v'] !== null) { $c[] = '<b>Google — ' . sqNf($g['note']) . '.</b> ' . (int) $g['avis'] . ' avis' . ($g['note'] >= 4.5 ? ' ; la fiche tient la cible du réseau.' : ' ; sous la cible 4,5 — répondre aux avis et demander l’avis aux clients satisfaits.'); }
    return $c;
}

function sqPageEntete(array $sc, string $titre, string $droite): string
{
    $tri = $sc['trimestre'];
    $logo = function_exists('rapLogoDataUri') ? rapLogoDataUri() : '';
    return '<div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #222;padding-bottom:10px;margin-bottom:16px">'
        . '<div>' . ($logo ? '<img src="' . $logo . '" style="height:36px" alt="">' : '<b style="font-size:20px">L’ATELIER</b>')
        . '<div style="font:600 9.5px Helvetica,Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;color:#7a7268;margin-top:6px">' . sqH($titre) . '</div></div>'
        . '<div style="text-align:right"><div style="font:600 9.5px Helvetica,Arial,sans-serif;letter-spacing:.1em;text-transform:uppercase;color:#7a7268">' . sqH($tri['lib']) . '</div>'
        . '<div style="font-size:10.5px;color:#7a7268">' . sqH($droite) . '</div></div></div>';
}

/** La page réseau : le podium, le tableau, la lecture, les règles. */
function sqPageReseau(array $sc, int $pages): string
{
    $tri = $sc['trimestre']; $L = $sc['magasins'];
    $arrete = 'arrêté au ' . date('d/m/Y', strtotime($tri['arrete']));
    $h = '<div class="page">' . sqPageEntete($sc, 'Scoring trimestriel des magasins — réseau', $arrete);
    $h .= '<h1>Le scoring du trimestre</h1><div class="sous">Quatre postes de cinq points, 20 en tout : Google, tâches, client mystère, budget. Un poste sans donnée compte 0 et il est nommé.</div>';
    $h .= '<h2>Le classement</h2><table class="podium"><tr>';
    foreach ($L as $l) {
        $h .= '<td><div class="r">' . ($l['rang'] === 1 ? '&#127942; ' : '') . $l['rang'] . ($l['rang'] === 1 ? 'er' : 'e') . '</div><div class="nm">' . sqH($l['court']) . '</div>'
            . '<div class="pt">' . sqNf($l['total']) . '<small> / ' . $l['sur'] . '</small></div>' . sqEtoiles($l['etoiles'], 11)
            . '<div class="sous">' . ($l['delta'] === null ? 'sans comparaison' : ($l['delta'] >= 0 ? '+ ' : '− ') . sqNf(abs($l['delta'])) . ' ★ vs ' . sqH(sqTrimestre($tri['prec'])['court'])) . '</div></td>';
    }
    $h .= '</tr></table>';
    $h .= '<table class="sc"><thead><tr><th>Magasin</th><th>Note</th>';
    foreach (SQ_POSTES as $cle => $p) { $h .= '<th>' . sqH($p['nom']) . '</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach ($L as $l) {
        $h .= '<tr><td class="nom">' . $l['rang'] . '. ' . sqH($l['court']) . '</td><td><b class="n">' . sqNf($l['total']) . '</b><small> / ' . $l['sur'] . '</small><br>' . sqEtoiles($l['etoiles'], 11) . '</td>';
        foreach (SQ_POSTES as $cle => $p) {
            $x = $l['postes'][$cle];
            $h .= '<td>' . sqEtoiles($x['v'], 10) . '<br><b class="n">' . ($x['v'] === null ? '—' : sqNf($x['v'])) . '</b><br><span class="d">' . sqH(explode(' · ', sqDetail($cle, $x))[0]) . '</span></td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table>';
    // La lecture : le premier, le poste le plus faible du réseau, les postes sans donnée.
    $lect = [];
    if ($L !== []) {
        $prem = $L[0];
        $meilleurs = array_keys(array_filter($prem['postes'], fn ($x) => $x['v'] !== null && $x['v'] >= 4));
        $lect[] = sqH($prem['court']) . ' est en tête avec ' . sqNf($prem['total']) . ' / ' . $prem['sur'] . ($meilleurs ? ', porté par ' . implode(', ', array_map(fn ($k) => $k === 'google' ? 'Google' : mb_strtolower(SQ_POSTES[$k]['nom']), $meilleurs)) : '') . '.';
    }
    $moy = [];
    foreach (SQ_POSTES as $cle => $p) { $vs = array_filter(array_map(fn ($l) => $l['postes'][$cle]['v'], $L), fn ($v) => $v !== null); if ($vs) { $moy[$cle] = array_sum($vs) / count($vs); } }
    if ($moy) { asort($moy); $faible = array_key_first($moy); $lect[] = 'Le poste qui coûte le plus au réseau : ' . ($faible === 'google' ? 'Google' : mb_strtolower(SQ_POSTES[$faible]['nom'])) . ', ' . sqNf($moy[$faible]) . ' / 5 en moyenne.'; }
    $sans = array_filter($L, fn ($l) => $l['n'] < 4);
    if ($sans) { $lect[] = implode(', ', array_map(fn ($l) => sqH($l['court']) . ' (' . (4 - $l['n']) . ' poste' . (4 - $l['n'] > 1 ? 's' : '') . ' sans donnée)', $sans)) . ' : ces postes comptent 0 tant qu’ils ne sont pas renseignés.'; }
    $h .= '<div class="lecture"><b>Ce que dit le trimestre.</b> ' . implode(' ', $lect) . '</div>';
    $h .= '<h2>Les règles</h2><div class="regles">';
    $i = 0; foreach (SQ_POSTES as $p) { $i++; $h .= '<div><b>' . $i . '. ' . sqH($p['nom']) . '</b> — ' . sqH($p['regle']) . '</div>'; }
    $h .= '</div><div class="pied"><span>L’Atelier — pilotage réseau · scoring du trimestre</span><span>page 1 / ' . $pages . '</span></div></div>';
    return $h;
}

/** La page d'un magasin : sa note, ses quatre postes, ce qu'on en fait. */
function sqPageMagasin(array $sc, array $l, int $page, int $pages): string
{
    $tri = $sc['trimestre']; $prec = sqTrimestre($tri['prec'])['court'];
    $h = '<div class="page saut">' . sqPageEntete($sc, 'Scoring du trimestre — ' . $l['nom'], 'arrêté au ' . date('d/m/Y', strtotime($tri['arrete'])) . ' · ' . $l['rang'] . ($l['rang'] === 1 ? 'er' : 'e') . ' sur ' . count($sc['magasins']));
    $h .= '<table style="width:100%;border-collapse:collapse;margin-bottom:6px"><tr><td style="vertical-align:top"><h1 style="margin:0">' . sqH($l['court']) . '</h1><div class="sous">' . sqH($l['nom']) . ($l['fr'] ? ' · ' . sqH($l['fr']) : '') . '</div></td>'
        . '<td style="text-align:right;vertical-align:top"><div class="n" style="font-size:34px">' . sqNf($l['total']) . '<small style="font-size:11px;color:#7a7268"> / ' . $l['sur'] . '</small></div>' . sqEtoiles($l['etoiles'], 18)
        . '<div class="sous">' . sqNf($l['etoiles']) . ' ★' . ($l['delta'] === null ? '' : ' · ' . ($l['delta'] >= 0 ? '+ ' : '− ') . sqNf(abs($l['delta'])) . ' ★ vs ' . sqH($prec)) . ($l['n'] < 4 ? ' · ' . (4 - $l['n']) . ' poste' . (4 - $l['n'] > 1 ? 's' : '') . ' sans donnée, compté 0' : '') . '</div></td></tr></table>';
    $h .= '<h2>Les quatre postes</h2><table class="fiche">';
    foreach (SQ_POSTES as $cle => $p) {
        $x = $l['postes'][$cle]; $pv = $l['prec']['postes'][$cle] ?? null;
        $d = $x['v'] !== null && $pv !== null ? $x['v'] - $pv : null;
        $h .= '<tr><td class="k">' . sqH($p['nom']) . '<small>sur 5</small></td><td class="v"><b class="n">' . ($x['v'] === null ? '—' : sqNf($x['v'])) . '</b><small> / 5</small><br>' . sqEtoiles($x['v'], 11) . '</td>'
            . '<td class="x">' . sqH(sqDetail($cle, $x)) . '<br><span class="sous">' . ($d === null ? 'sans comparaison' : (abs($d) < 0.05 ? 'stable' : ($d > 0 ? '<b style="color:#2d7a3e">+ ' : '<b style="color:#C0182B">− ') . sqNf(abs($d)) . '</b>') . ' vs ' . sqH($prec)) . '</span></td></tr>';
    }
    $h .= '</table><h2>Ce qu’on en fait</h2><ul class="actions">';
    foreach (sqConseils($l) as $c) { $h .= '<li>' . $c . '</li>'; }
    $h .= '</ul>';
    $m = $l['postes']['msp'];
    if ($m['v'] !== null && ($m['commentaire'] || $m['fichier'])) {
        $h .= '<div class="lecture"><b>Client mystère — le rapport.</b> ' . ($m['commentaire'] ? sqH((string) $m['commentaire']) . ' ' : '') . ($m['fichier'] ? '<span class="sous">Le rapport complet est joint (' . sqH(basename((string) $m['fichier'])) . ').</span>' : '') . '</div>';
    }
    $h .= '<div class="pied"><span>L’Atelier — pilotage réseau · scoring du trimestre · ' . sqH($l['court']) . '</span><span>page ' . $page . ' / ' . $pages . '</span></div></div>';
    return $h;
}

/** Le document A4 complet : la page réseau puis une page par magasin — ou la page d'un seul magasin après le réseau. */
function sqRapportHtml(array $sc, ?string $shop = null): string
{
    $L = $shop !== null ? array_values(array_filter($sc['magasins'], fn ($l) => $l['id'] === $shop)) : $sc['magasins'];
    $pages = 1 + count($L);
    $css = '<style>'
        . '@page{size:A4;margin:14mm 15mm 18mm}'
        . 'body{margin:0;background:#fff;color:#222;font:12px/1.45 Helvetica,Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        . '.page{position:relative;min-height:250mm;padding:0 0 14mm}.page.saut{page-break-before:always}'
        . 'h1{font-size:28px;margin:0 0 4px;line-height:1.05;font-weight:800}h2{font-size:15px;margin:16px 0 8px;font-weight:800}'
        . '.sous{color:#7a7268;font-size:10.5px}.n{font-weight:800;font-variant-numeric:tabular-nums}'
        . 'table.podium{width:100%;border-collapse:separate;border-spacing:8px 0;margin:4px -8px 6px}table.podium td{width:25%;border:.5px solid #e6e0d6;border-radius:8px;padding:9px 11px;vertical-align:top}'
        . '.podium .r{font:700 9.5px Helvetica,Arial,sans-serif;letter-spacing:.06em;text-transform:uppercase;color:#7a7268}.podium .nm{font-weight:700;font-size:13px;margin:2px 0}.podium .pt{font-size:21px;font-weight:800;line-height:1.1}.podium .pt small{font-size:10px;color:#7a7268;font-weight:400}'
        . 'table.sc{width:100%;border-collapse:collapse;margin-top:8px}table.sc th{text-align:left;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a7268;padding:0 8px 5px}'
        . 'table.sc td{padding:8px;border-top:.5px solid #e6e0d6;font-size:11.5px;vertical-align:top}table.sc td.nom{font-weight:700}table.sc td .d{font-size:9.5px;color:#7a7268}'
        . '.lecture{background:#f6f2ec;border-radius:8px;padding:10px 14px;font-size:11.5px;margin-top:12px}'
        . '.regles{columns:2;column-gap:24px;font-size:10.5px;color:#444}.regles>div{break-inside:avoid;margin-bottom:5px}'
        . 'table.fiche{width:100%;border-collapse:separate;border-spacing:0 6px}table.fiche td{border-top:.5px solid #e6e0d6;border-bottom:.5px solid #e6e0d6;padding:9px 12px;vertical-align:middle}'
        . 'table.fiche td.k{width:140px;font-weight:700;font-size:12.5px;border-left:.5px solid #e6e0d6;border-radius:8px 0 0 8px}table.fiche td.k small{display:block;font-weight:400;color:#7a7268;font-size:9.5px}'
        . 'table.fiche td.v{width:110px}table.fiche td.v b{font-size:20px}table.fiche td.v small{font-size:10px;color:#7a7268}table.fiche td.x{font-size:11px;color:#444;border-right:.5px solid #e6e0d6;border-radius:0 8px 8px 0}'
        . 'ul.actions{margin:0;padding-left:18px}ul.actions li{margin:4px 0;font-size:11.5px}'
        . '.pied{position:absolute;left:0;right:0;bottom:0;font-size:9px;color:#7a7268;border-top:.5px solid #ddd;padding-top:5px;display:flex;justify-content:space-between}'
        . '@media screen{body{background:#EAE4DC;padding:20px}.page{background:#fff;width:794px;min-height:1123px;margin:0 auto 20px;padding:52px 56px 60px;box-sizing:border-box;box-shadow:0 8px 30px rgba(0,0,0,.14)}.pied{left:56px;right:56px;bottom:26px}}'
        . '</style>';
    $h = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Scoring ' . sqH($sc['trimestre']['court']) . '</title>' . $css . '</head><body>';
    $h .= sqPageReseau($sc, $pages);
    $i = 1; foreach ($L as $l) { $i++; $h .= sqPageMagasin($sc, $l, $i, $pages); }
    return $h . '</body></html>';
}

/** GET /scoring/rapport?trimestre=&shop=&format=html|pdf — le rapport A4, à l'écran (imprimable) ou en PDF. */
function ep_scoring_rapport(): array
{
    $tri = sqTrimestre($_GET['trimestre'] ?? null);
    $shop = trim((string) ($_GET['shop'] ?? '')) ?: null;
    $sc = sqCalcul($tri);
    $html = sqRapportHtml($sc, $shop);
    if ((string) ($_GET['format'] ?? 'html') === 'pdf') {
        $pdf = function_exists('rapPdfRendu') ? rapPdfRendu($html, ['rapport' => 'Scoring du trimestre ' . $tri['court'], 'magasin' => $shop ? (sqMagasins()[$shop]['nom'] ?? $shop) : 'Réseau', 'genere' => date('d/m/Y à H:i'), 'envoye' => '']) : null;
        if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur le serveur — ouvrez le rapport et imprimez-le en A4, le navigateur produit le même PDF']; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="scoring-' . $tri['cle'] . ($shop ? '-' . preg_replace('/[^\w-]/', '', $shop) : '') . '.pdf"');
        echo $pdf; exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $html; exit;
}

/* --- l'envoi ------------------------------------------------------------------------ */

/**
 * La distribution : chaque magasin du carnet reçoit le classement réseau et sa
 * page (un PDF), plus son rapport client mystère s'il est là ; les adresses
 * réseau de la ligne de reporting reçoivent le document complet. `essai` :
 * tout part à cette seule adresse, rien n'est journalisé.
 */
function sqDistribuer(array $tri, ?array $rep, ?string $essai = null): array
{
    $bilan = ['trimestre' => $tri['cle'], 'magasins' => [], 'copies' => []];
    if (!class_exists('Smtp') || !Smtp::configured()) { return $bilan + ['error' => 'SMTP non configuré (Paramètres) — aucun envoi']; }
    if (!function_exists('rapPdfRendu')) { return $bilan + ['error' => 'moteur PDF absent']; }
    $sc = sqCalcul($tri);
    $carnet = $rep ? (json_decode((string) ($rep['dest_par_magasin'] ?? ''), true) ?: []) : [];
    $copies = $rep ? array_values(array_filter(json_decode((string) ($rep['destinataires'] ?? '[]'), true) ?: [], fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL))) : [];
    $sujet = 'Scoring du trimestre — ' . $tri['court'];
    $corps = function (array $l) use ($tri): string {
        $lignes = '';
        foreach (SQ_POSTES as $cle => $p) { $x = $l['postes'][$cle]; $lignes .= '<tr><td style="padding:3px 10px 3px 0">' . sqH($p['nom']) . '</td><td style="padding:3px 0;font-weight:700">' . ($x['v'] === null ? '—' : sqNf($x['v']) . ' / 5') . '</td><td style="padding:3px 0 3px 10px;color:#7a7268">' . sqH(sqDetail($cle, $x)) . '</td></tr>'; }
        return '<div style="font-family:Helvetica,Arial,sans-serif;font-size:13px;color:#222;line-height:1.6"><p>Bonjour,</p>'
            . '<p>Voici le <b>scoring du trimestre ' . sqH($tri['court']) . '</b> : <b>' . sqH($l['court']) . ' obtient ' . sqNf($l['total']) . ' / 20</b>, ' . $l['rang'] . ($l['rang'] === 1 ? 'er' : 'e') . ' magasin sur ' . count(sqMagasins()) . '.</p>'
            . '<table style="border-collapse:collapse;font-size:12.5px">' . $lignes . '</table>'
            . '<p>Le rapport A4 est joint : le classement du réseau et votre page, avec ce qu’on en fait.' . ($l['postes']['msp']['fichier'] ? ' Le rapport du client mystère est joint aussi.' : '') . '</p>'
            . '<p style="color:#7a736a;font-size:11px">Envoyé par le cockpit, le premier jour du trimestre suivant.</p></div>';
    };
    foreach ($sc['magasins'] as $l) {
        $dests = $essai !== null ? [$essai] : array_values(array_filter((array) ($carnet[$l['nom']] ?? []), fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL)));
        if ($dests === []) { $bilan['magasins'][] = ['magasin' => $l['nom'], 'statut' => 'sans-adresse', 'note' => 'aucune adresse dans le carnet du reporting — non envoyé']; continue; }
        $pdf = rapPdfRendu(sqRapportHtml($sc, $l['id']), ['rapport' => 'Scoring du trimestre ' . $tri['court'], 'magasin' => $l['nom'], 'genere' => date('d/m/Y à H:i'), 'envoye' => date('d/m/Y')]);
        if ($pdf === null) { $bilan['magasins'][] = ['magasin' => $l['nom'], 'statut' => 'erreur', 'note' => 'le PDF n’a pas pu être rendu']; continue; }
        $pieces = [['nom' => 'scoring-' . $tri['cle'] . '-' . preg_replace('/[^\w-]/', '', $l['court']) . '.pdf', 'type' => 'application/pdf', 'contenu' => $pdf]];
        $f = $l['postes']['msp']['fichier'] ?? null;
        if ($f && is_file(__DIR__ . '/../public/' . $f)) { $pieces[] = ['nom' => basename((string) $f), 'type' => 'application/pdf', 'contenu' => (string) file_get_contents(__DIR__ . '/../public/' . $f)]; }
        $envoyes = []; $rate = null;
        foreach ($dests as $d) { if (Smtp::envoyer($d, $sujet . ' — ' . $l['court'], $corps($l), $pieces)) { $envoyes[] = $d; } else { $rate = (string) Smtp::$lastError; } }
        $bilan['magasins'][] = ['magasin' => $l['nom'], 'statut' => $envoyes !== [] ? 'envoye' : 'echec', 'envoyes' => $envoyes, 'note' => $rate];
        if ($essai !== null) { break; }
    }
    // Les adresses réseau : le document complet, une fois.
    $destsReseau = $essai !== null ? [] : $copies;
    if ($destsReseau !== []) {
        $pdf = rapPdfRendu(sqRapportHtml($sc), ['rapport' => 'Scoring du trimestre ' . $tri['court'], 'magasin' => 'Réseau', 'genere' => date('d/m/Y à H:i'), 'envoye' => date('d/m/Y')]);
        $corpsR = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:13px;color:#222;line-height:1.6"><p>Bonjour,</p><p>Le <b>scoring du trimestre ' . sqH($tri['court']) . '</b>, réseau complet : '
            . implode(' · ', array_map(fn ($l) => $l['rang'] . '. ' . sqH($l['court']) . ' ' . sqNf($l['total']) . ' / ' . $l['sur'], $sc['magasins'])) . '.</p><p>Le rapport A4 complet est joint — la page réseau et une page par magasin.</p></div>';
        foreach ($destsReseau as $d) {
            $ok = $pdf !== null && Smtp::envoyer($d, $sujet . ' — réseau', $corpsR, [['nom' => 'scoring-' . $tri['cle'] . '-reseau.pdf', 'type' => 'application/pdf', 'contenu' => $pdf]]);
            $bilan['copies'][] = ['a' => $d, 'statut' => $ok ? 'envoye' : 'echec', 'note' => $ok ? null : ($pdf === null ? 'PDF non rendu' : (string) Smtp::$lastError)];
        }
    }
    $servis = count(array_filter($bilan['magasins'], fn ($m) => $m['statut'] === 'envoye'));
    $sans = count(array_filter($bilan['magasins'], fn ($m) => $m['statut'] === 'sans-adresse'));
    $bilan['resume'] = 'Scoring ' . $tri['court'] . ' — ' . $servis . ' magasin(s) servi(s), ' . $sans . ' sans adresse' . ($bilan['copies'] ? ', ' . count(array_filter($bilan['copies'], fn ($c) => $c['statut'] === 'envoye')) . ' copie(s) réseau' : '') . ($essai !== null ? ' — essai vers ' . $essai : '');
    if ($essai === null && $rep) {
        try {
            $html = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:13px">' . sqH($bilan['resume']) . '<ul>'
                . implode('', array_map(fn ($m) => '<li>' . sqH($m['magasin'] . ' — ' . $m['statut'] . (!empty($m['envoyes']) ? ' (' . implode(', ', $m['envoyes']) . ')' : '')) . '</li>', $bilan['magasins'])) . '</ul></div>';
            Db::exec('INSERT INTO ceo_rapport_run (rapport_id, genere_le, periode_du, periode_au, statut, resume, html, contexte) VALUES (?,?,?,?,?,?,?,?)',
                [(int) $rep['id'], date('Y-m-d H:i:s'), $tri['du'], $tri['au'], $servis > 0 ? 'envoye' : 'vide', $bilan['resume'], $html, json_encode(['rapport' => 'Scoring du trimestre ' . $tri['court']], JSON_UNESCAPED_UNICODE)]);
            $bilan['runId'] = (int) Db::pdo()->lastInsertId();
        } catch (PDOException $e) { /* pas de journal de runs ici */ }
        if (function_exists('journalAdd')) { journalAdd('CEO', 'Rapport', 'Scoring du trimestre', $bilan['resume']); }
    }
    return $bilan;
}

/** POST /scoring/envoyer { trimestre, essai?: "adresse" } — l'envoi à la main. */
function wr_scoring_envoyer(): array
{
    ensureScoring();
    $b = body();
    $tri = sqTrimestre(is_string($b['trimestre'] ?? null) ? $b['trimestre'] : null);
    $essai = isset($b['essai']) && filter_var((string) $b['essai'], FILTER_VALIDATE_EMAIL) ? (string) $b['essai'] : null;
    $rep = null;
    try { $rep = Db::row('SELECT * FROM ceo_rapport WHERE code = ?', [SQ_RAPPORT_CODE]); } catch (PDOException $e) { /* pas de reporting */ }
    $bilan = sqDistribuer($tri, $rep, $essai);
    if (isset($bilan['error'])) { http_response_code(409); return $bilan; }
    return ['ok' => true] + $bilan;
}

/**
 * Le passage du cron du reporting (ep_rapports_cron, la ligne « scoring-trimestre »,
 * mensuelle, le 1 à 8 h) : rien sauf le premier mois d'un trimestre, où c'est le
 * trimestre révolu qui part. `null` = pas dû ce mois-ci.
 */
function scoringCron(array $rep): ?array
{
    if (!in_array((int) date('n'), [1, 4, 7, 10], true)) { return null; }
    $tri = sqTrimestre(sqTrimestre(sqTrimestreCourant())['prec']);
    return sqDistribuer($tri, $rep, null);
}
