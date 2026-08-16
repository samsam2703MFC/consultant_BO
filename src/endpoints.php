<?php
declare(strict_types=1);

/**
 * Cockpit CEO — endpoints de lecture (GET), un par écran + référentiels.
 * Formes JSON : voir contrat-api.md. Aucune donnée métier dans le HTML :
 * tout ce que le front affiche sort d'ici.
 */

/** Correspondance slug levier ↔ of_tag.id (le nom/type/desc sont applicatifs, la couleur vient d'of_tag). */
const LEVIER_DEFS = [
    ['slug' => 'trafic',        'nom' => 'Trafic',            'type' => 'Vente', 'tag' => 4, 'desc' => 'Faire venir plus de monde : visibilité locale, vitrine, animations, signalétique.'],
    ['slug' => 'recurrence',    'nom' => 'Récurrence',        'type' => 'Vente', 'tag' => 3, 'desc' => 'Faire revenir les clients : fidélisation PWA, qualité constante, loyalty, suivi B2B.'],
    ['slug' => 'xp',            'nom' => 'Expérience Client', 'type' => 'Vente', 'tag' => 2, 'desc' => 'Qualité du moment en boutique : accueil < 3 s, conseil, ambiance, rapidité.'],
    ['slug' => 'food-cost',     'nom' => 'Food Cost',         'type' => 'Coût',  'tag' => 5, 'desc' => 'Coût matière : recettes, contrôle réception ProdAtelier, FIFO, casse & invendus.'],
    ['slug' => 'labour-cost',   'nom' => 'Labour Cost',       'type' => 'Coût',  'tag' => 6, 'desc' => 'Coût main d\'œuvre : plannings au flux, productivité, ratio CA/ETP, polyvalence.'],
    ['slug' => 'overhead-cost', 'nom' => 'Overhead Cost',     'type' => 'Coût',  'tag' => 7, 'desc' => 'Charges fixes : loyer, énergies, abonnements, assurances, maintenance.'],
];

function levierSlugByTag(): array
{
    $out = [];
    foreach (LEVIER_DEFS as $l) { $out[$l['tag']] = $l['slug']; }
    return $out;
}

function setting(string $key, mixed $default = null): mixed
{
    $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$key]);
    return $r === null ? $default : json_decode($r['value'], true);
}

function ep_meta(): array
{
    $seuils = [];
    foreach (Db::rows("SELECT code, seuil_bas, seuil_haut FROM kpi WHERE code IS NOT NULL") as $k) {
        $seuils[$k['code']] = $k['seuil_haut'] !== null ? (float) $k['seuil_haut'] : (float) $k['seuil_bas'];
    }
    return [
        'reseau'           => setting('reseau', ['nom' => '', 'sousTitre' => '']),
        'utilisateur'      => setting('utilisateur', ['initiales' => '', 'nom' => '', 'role' => '']),
        // « Aujourd'hui » pilote la logique de dates (défaut du planning d'un
        // nouveau projet, jalons/tâches en retard). Sans réglage explicite, on
        // prend la date réelle du serveur — jamais null (sinon les comparaisons
        // de dates côté écran deviennent fausses) et jamais figée.
        'aujourdhui'       => setting('aujourdhui', date('Y-m-d')),
        'dateLabel'        => setting('dateLabel', ''),
        'periodeLabel'     => setting('periodeLabel', ''),
        'exercice'         => (int) setting('exercice', (int) date('Y')),
        'moisLabels'       => setting('moisLabels', []),
        'seuils'           => [
            'food'        => $seuils['food'] ?? 32,
            'labour'      => $seuils['labour'] ?? 33,
            'overhead'    => $seuils['overhead'] ?? 13.5,
            'royalties'   => $seuils['royalties'] ?? 3,
            'financieres' => $seuils['financieres'] ?? 2.2,
            'caEtp'       => $seuils['ca_etp'] ?? 13000,
        ],
        'contribOuverture' => setting('contribOuverture', 0),
        'notes'            => setting('notes', new stdClass()),
        'familles'         => setting('familles', []),
        'reportTypes'      => setting('reportTypes', []),
        // Validation des tâches : une seule source pour les cinq niveaux, le
        // seuil et le référentiel famille → type. Rien n'est recopié dans le
        // JavaScript, sinon les deux se mettent à diverger en silence.
        'signalement'      => setting('signalement', [
            'seuil'    => 4,
            'niveaux'  => [],
            'familles' => [],
        ]),
    ];
}

function ep_leviers(): array
{
    $colors = [];
    foreach (Db::rows('SELECT id, color FROM of_tag') as $t) { $colors[(int) $t['id']] = $t['color']; }
    return array_map(fn ($l) => [
        'slug' => $l['slug'], 'nom' => $l['nom'], 'type' => $l['type'],
        'color' => $colors[$l['tag']] ?? '#666666', 'desc' => $l['desc'],
    ], LEVIER_DEFS);
}

function ep_kpis(): array
{
    return array_map(fn ($r) => ['nom' => $r['name']], Db::rows('SELECT name FROM kpi WHERE code IS NULL ORDER BY id'));
}

function ep_email_templates(): array
{
    return array_map(fn ($r) => ['id' => $r['id'], 'nom' => $r['name'], 'sujet' => $r['subject'], 'corps' => $r['body']],
        Db::rows('SELECT * FROM ceo_email_template ORDER BY id'));
}

function ep_project_templates(): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_project_template') as $r) {
        $out[$r['axe']] = ['jalons' => json_decode($r['jalons_json'], true), 'couts' => json_decode($r['couts_json'], true)];
    }
    return $out;
}

function ep_stores(): array
{
    // Vraies boutiques du panel (table partagée `shops` d'atelierby_db) — même
    // source que le panel consultant. Repli sur `ceo_shop` si la table partagée
    // est absente (installation autonome / démo).
    try {
        $rows = Db::rows('SELECT id, slug, name, legal_name, city, zone, region, active, kind,
                                 is_franchise, operator, contrat, since_year, sort_order
                          FROM shops ORDER BY sort_order, id');
        return array_map(fn ($r) => [
            'id'     => (string) $r['id'],
            'code'   => $r['slug'] !== null ? strtoupper((string) $r['slug']) : (string) $r['id'],
            'nom'    => $r['name'],
            'fr'     => $r['operator'] ?: ($r['legal_name'] ?: ''),
            'zone'   => $r['zone'] ?: ($r['region'] ?: $r['city']),
            'status' => ((int) $r['active'] === 1) ? 'Ouvert' : 'Fermé',
            'opened' => $r['since_year'] ? sprintf('%04d-01', (int) $r['since_year']) : null,
            'valT'   => null,          // valorisation calculée côté valuation, non stockée
            'panier' => null,          // panier moyen : dérivé de /stores/perf
            'pwaId'  => (int) $r['id'], // la boutique du panel EST le pwa_shop_id
        ], $rows);
    } catch (PDOException $e) {
        return array_map(fn ($r) => [
            'id' => $r['id'], 'code' => $r['code'], 'nom' => $r['name'], 'fr' => $r['franchisee'],
            'zone' => $r['zone'], 'status' => $r['status'],
            'opened' => $r['opened_on'] ? substr($r['opened_on'], 0, 7) : null,
            'valT' => $r['valuation_target'] !== null ? (float) $r['valuation_target'] : null,
            'panier' => $r['basket_ref'] !== null ? (float) $r['basket_ref'] : null,
            'pwaId' => isset($r['pwa_shop_id']) && $r['pwa_shop_id'] !== null ? (int) $r['pwa_shop_id'] : null,
        ], Db::rows('SELECT * FROM ceo_shop ORDER BY id'));
    }
}

/**
 * GET /pwa/reports — rapports du panel consultant (pwa_consultant).
 *
 * Deux volets :
 *  - `base` + `magasins[].pwaId` : de quoi construire côté client les liens de
 *    GÉNÉRATION (`/reports/view?type=week|month&scope=all|{id}`,
 *    `/reports/checklist/week|month?scope={id}`) — le rapport est rendu par le
 *    panel à l'ouverture, c'est sa génération ;
 *  - `partages` : les liens de partage FIGÉS récupérés de `mac_report_share`
 *    (un rapport mensuel, une boutique, un mois — page publique `/r/{token}`),
 *    avec état, ouvertures et expiration.
 */
function ep_pwa_reports(): array
{
    $cfgBase = Db::config()['pwaBase'] ?? null;                       // config/env prime
    $base = rtrim((string) ($cfgBase ?: setting('pwaBase', '')), '/');
    // Boutiques : vraies boutiques du panel (`shops`) si disponibles, sinon
    // ceo_shop. pwaId = id de la boutique du panel (= id_shop de mac_report_share).
    try {
        $magasins = array_map(fn ($r) => ['id' => (string) $r['id'], 'nom' => $r['name'], 'pwaId' => (int) $r['id']],
            Db::rows("SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id"));
    } catch (PDOException $e) {
        $magasins = array_map(fn ($r) => ['id' => $r['id'], 'nom' => $r['name'], 'pwaId' => $r['pwa_shop_id'] !== null ? (int) $r['pwa_shop_id'] : null],
            Db::rows("SELECT id, name, pwa_shop_id FROM ceo_shop WHERE status = 'Ouvert' ORDER BY id"));
    }
    $shopByPwa = [];
    foreach ($magasins as $m) { if ($m['pwaId'] !== null) { $shopByPwa[$m['pwaId']] = $m['nom']; } }

    $partages = [];
    try {
        $rows = Db::rows('SELECT token, id_shop, ym, label, consultant_name, created_at, expires_at, revoked_at, opens, last_opened_at
                            FROM mac_report_share ORDER BY created_at DESC LIMIT 100');
    } catch (PDOException $e) {
        $rows = []; // table absente (panel sur une autre base) : volet vide, pas d'erreur
    }
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $r) {
        $etat = $r['revoked_at'] !== null ? 'Révoqué' : ($r['expires_at'] < $now ? 'Expiré' : 'Actif');
        $partages[] = [
            'label' => $r['label'], 'ym' => $r['ym'],
            'magasin' => $shopByPwa[(int) $r['id_shop']] ?? ('Boutique #' . $r['id_shop']),
            'consultant' => $r['consultant_name'],
            'url' => $base !== '' ? $base . '/r/' . $r['token'] : '/r/' . $r['token'],
            'cree' => substr($r['created_at'], 0, 10), 'expire' => substr($r['expires_at'], 0, 10),
            'etat' => $etat, 'opens' => (int) $r['opens'],
            'derniereOuverture' => $r['last_opened_at'] ? substr($r['last_opened_at'], 0, 10) : null,
        ];
    }
    return ['base' => $base, 'magasins' => $magasins, 'partages' => $partages];
}

/**
 * Noms des tâches prédéfinies du panel (table partagée `todo_task`).
 *
 * Le schéma de `todo_task` varie ; on détecte la colonne du libellé comme le
 * fait le panel (TodoTaskRepository) plutôt que de supposer `name`. Table ou
 * colonne absente → map vide (les tâches s'afficheront par leur identifiant).
 *
 * @return array<int, string> id_task => libellé
 */
function todoTaskNames(): array
{
    try {
        $cols = array_map(fn ($r) => (string) $r['COLUMN_NAME'],
            Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todo_task'"));
        if ($cols === []) { return []; }
        $lower = array_map('strtolower', $cols);
        $nameCol = null;
        foreach (['name', 'title', 'label', 'task_name', 'task'] as $cand) {
            $i = array_search($cand, $lower, true);
            if ($i !== false) { $nameCol = $cols[$i]; break; }
        }
        if ($nameCol === null) {
            foreach ($cols as $c) { if (stripos($c, 'name') !== false) { $nameCol = $c; break; } }
        }
        if ($nameCol === null) { return []; }
        $idCol = in_array('id', $lower, true) ? 'id' : $cols[0];
        $out = [];
        foreach (Db::rows("SELECT `$idCol` AS id, `$nameCol` AS name FROM todo_task LIMIT 1000") as $r) {
            $out[(int) $r['id']] = trim((string) $r['name']);
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * GET /pwa/tasks?date=YYYY-MM-DD — contrôle des tâches consultants du panel.
 *
 * Arbre Boutique › Tâche › avis consultant, lu dans la table partagée
 * `mac_task_review` (même source que l'écran « réseau » du panel). Chaque avis
 * porte la note, l'acceptation, le commentaire, qui a évalué, ET la validation
 * de l'Owner (owner_validated_at/owner_name) — le CEO valide ou retire depuis
 * ici. Les libellés de tâche viennent de `todo_task`, les magasins de `shops`.
 *
 * Sans date : la dernière journée réellement évaluée (l'écran n'est pas vide).
 * Le contenu VIVANT des checklists (tâches planifiées du jour, photos) vient de
 * l'API amont du panel, hors de portée ici : on montre ce qui est persité.
 */
function ep_pwa_tasks(): array
{
    $date = $_GET['date'] ?? null;
    if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) { $date = null; }

    $empty = ['date' => $date ?? date('Y-m-d'), 'dates' => [], 'shops' => [],
        'consultants' => [], 'totals' => ['taches' => 0, 'valides' => 0, 'refuses' => 0, 'aValider' => 0, 'noteMoy' => null],
        'indispo' => true];

    try {
        // Journées réellement évaluées (sélecteur de date) + date active par défaut.
        $dates = array_map(fn ($r) => (string) $r['d'],
            Db::rows("SELECT DISTINCT review_date d FROM mac_task_review ORDER BY review_date DESC LIMIT 90"));
        if ($date === null) { $date = $dates[0] ?? date('Y-m-d'); }

        $taskNames = todoTaskNames();
        $shopNames = [];
        try {
            foreach (Db::rows("SELECT id, name FROM shops") as $s) { $shopNames[(int) $s['id']] = $s['name']; }
        } catch (PDOException $e) { /* shops absente : nom = #id */ }

        $rows = Db::rows("SELECT * FROM mac_task_review WHERE review_date = ? ORDER BY id_shop, id_task", [$date]);

        // Noms RÉELS des tâches : l'API amont du panel est la seule à les porter
        // (la base ne garde que l'identifiant). On interroge une fois par
        // boutique concernée, et on complète le référentiel local `todo_task`.
        // Sans identifiants API configurés, on garde les noms disponibles.
        $apiNames = [];
        $apiOn = PanelApi::configured();
        if ($apiOn) {
            foreach (array_unique(array_map(fn ($r) => (int) $r['id_shop'], $rows)) as $sid) {
                foreach (PanelApi::shopTasks($sid, $date) as $t) {
                    $tid = (int) ($t['task_id'] ?? $t['id'] ?? 0);
                    $nom = trim((string) ($t['task_name'] ?? $t['name'] ?? ''));
                    if ($tid > 0 && $nom !== '') { $apiNames[$sid . '|' . $tid] = $nom; }
                }
            }
        }

        $byShop = [];
        $cons = [];
        $tot = ['taches' => 0, 'valides' => 0, 'refuses' => 0, 'aValider' => 0];
        $noteSum = 0; $noteN = 0;
        foreach ($rows as $r) {
            $sid = (int) $r['id_shop'];
            $tid = (int) $r['id_task'];
            $note = isset($r['rating']) && $r['rating'] !== null ? (int) $r['rating'] : null;
            $acc  = isset($r['is_accepted']) && $r['is_accepted'] !== null ? (bool) (int) $r['is_accepted'] : null;
            $valide = !empty($r['owner_validated_at']);
            if (!isset($byShop[$sid])) {
                $byShop[$sid] = ['shopId' => (string) $sid, 'shop' => $shopNames[$sid] ?? ('Boutique #' . $sid), 'taches' => []];
            }
            $byShop[$sid]['taches'][] = [
                'taskId'      => (string) $tid,
                'tache'       => $apiNames[$sid . '|' . $tid] ?? $taskNames[$tid] ?? ('Tâche #' . $tid),
                'note'        => $note,
                'accepte'     => $acc,
                'comment'     => $r['comment'] !== null && $r['comment'] !== '' ? (string) $r['comment'] : null,
                'consultant'  => $r['consultant_name'] !== null ? (string) $r['consultant_name'] : null,
                'consultantId' => (int) ($r['id_consultant'] ?? 0),
                'date'        => (string) $r['review_date'],
                'valide'      => $valide,
                'valideePar'  => $valide ? ($r['owner_name'] ?? null) : null,
                'valideeLe'   => $valide ? substr((string) $r['owner_validated_at'], 0, 16) : null,
                'majLe'       => $r['updated_at'] !== null ? substr((string) $r['updated_at'], 0, 16) : null,
            ];
            $tot['taches']++;
            if ($valide) { $tot['valides']++; } else { $tot['aValider']++; }
            if ($acc === false) { $tot['refuses']++; }
            if ($note !== null) { $noteSum += $note; $noteN++; }

            // Agrégat par consultant (pilotage des consultants).
            $cid = (int) ($r['id_consultant'] ?? 0);
            if (!isset($cons[$cid])) {
                $cons[$cid] = ['id' => $cid, 'nom' => $r['consultant_name'] !== null ? (string) $r['consultant_name'] : ('#' . $cid),
                    'avis' => 0, 'refuses' => 0, 'valides' => 0, 'noteSum' => 0, 'noteN' => 0];
            }
            $cons[$cid]['avis']++;
            if ($acc === false) { $cons[$cid]['refuses']++; }
            if ($valide) { $cons[$cid]['valides']++; }
            if ($note !== null) { $cons[$cid]['noteSum'] += $note; $cons[$cid]['noteN']++; }
        }

        $consultants = array_map(fn ($c) => [
            'id' => $c['id'], 'nom' => $c['nom'], 'avis' => $c['avis'], 'refuses' => $c['refuses'], 'valides' => $c['valides'],
            'noteMoy' => $c['noteN'] > 0 ? round($c['noteSum'] / $c['noteN'], 1) : null,
        ], array_values($cons));
        usort($consultants, fn ($a, $b) => $b['avis'] <=> $a['avis']);

        $tot['noteMoy'] = $noteN > 0 ? round($noteSum / $noteN, 1) : null;

        // Répartition par NIVEAU DE CONFORMITÉ — le barème des cinq niveaux
        // (Exemplaire / Conforme / NC mineur / majeur / critique) est le
        // réglage `signalement`, partagé avec l'écran de validation : un
        // « majeur » doit vouloir dire la même chose partout.
        $sig = setting('signalement', []);
        $niveaux = (is_array($sig) && isset($sig['niveaux']) && is_array($sig['niveaux'])) ? $sig['niveaux'] : [];
        $parNote = [];
        foreach ($rows as $r) {
            if ($r['rating'] !== null) { $n = (int) $r['rating']; $parNote[$n] = ($parNote[$n] ?? 0) + 1; }
        }
        $repartition = [];
        foreach ($niveaux as $lv) {
            $n = (int) ($lv['n'] ?? 0);
            $c = $parNote[$n] ?? 0;
            $repartition[] = [
                'n' => $n, 'nom' => (string) ($lv['nom'] ?? ($n . '/5')),
                'couleur' => (string) ($lv['couleur'] ?? '#666666'),
                'aide' => (string) ($lv['aide'] ?? ''),
                'conforme' => $n >= (int) ($sig['seuil'] ?? 4),
                'nb' => $c, 'pct' => $noteN > 0 ? round(100 * $c / $noteN) : 0,
            ];
        }
        $tot['notees'] = $noteN;
        $tot['nonNotees'] = $tot['taches'] - $noteN;

        return ['date' => $date, 'dates' => $dates, 'shops' => array_values($byShop),
            'repartition' => $repartition, 'seuil' => (int) ($sig['seuil'] ?? 4),
            'consultants' => $consultants, 'totals' => $tot, 'indispo' => false,
            // L'écran doit pouvoir DIRE pourquoi il manque des noms/photos.
            'api' => ['configure' => $apiOn, 'erreur' => $apiOn ? PanelApi::$lastError : null]];
    } catch (PDOException $e) {
        // mac_task_review absente (panel sur une autre base / jamais alimentée).
        return $empty;
    }
}

/**
 * GET /pwa/tasks/detail?shop=&task=&date= — le détail d'une tâche : photo de
 * réalisation + avis en cours.
 *
 * La photo n'est pas en base : l'API donne un `attachment_id` sur le flux
 * d'avancement de la checklist, puis une URL signée pour l'image. On parcourt
 * les checklists du jour pour retrouver la ligne de CETTE tâche.
 */
function ep_pwa_task_detail(): array
{
    $shopId = (int) ($_GET['shop'] ?? 0);
    $taskId = (int) ($_GET['task'] ?? 0);
    $date   = (string) ($_GET['date'] ?? '');
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shop, task et date (YYYY-MM-DD) sont requis'];
    }

    // Ce que la base partagée sait déjà de l'avis (toujours disponible).
    $avis = null;
    try {
        $r = Db::row('SELECT * FROM mac_task_review WHERE id_shop = ? AND id_task = ? AND review_date = ?',
            [$shopId, $taskId, $date]);
        if ($r !== null) {
            $avis = [
                'note' => $r['rating'] !== null ? (int) $r['rating'] : null,
                'accepte' => $r['is_accepted'] !== null ? (bool) (int) $r['is_accepted'] : null,
                'comment' => $r['comment'], 'consultant' => $r['consultant_name'],
                'checklistId' => $r['id_checklist'] !== null ? (int) $r['id_checklist'] : null,
                'completionId' => $r['completion_id'] !== null ? (int) $r['completion_id'] : null,
                'valide' => !empty($r['owner_validated_at']), 'valideePar' => $r['owner_name'],
            ];
        }
    } catch (PDOException $e) { /* table absente : avis inconnu */ }

    $out = ['shopId' => (string) $shopId, 'taskId' => (string) $taskId, 'date' => $date,
        'tache' => null, 'checklist' => null, 'photo' => null, 'obligatoire' => null,
        'photoRequise' => null, 'statut' => null, 'completionId' => $avis['completionId'] ?? null,
        'checklistId' => $avis['checklistId'] ?? null, 'avis' => $avis,
        // Référence : la photo de la fiche technique du produit contrôlé, pour
        // juger par COMPARAISON. Rapprochée par identifiant seul (jamais le nom).
        'produitId' => null, 'produit' => null, 'photoRef' => null,
        'api' => ['configure' => PanelApi::configured(), 'erreur' => null]];

    if (!PanelApi::configured()) {
        $out['api']['erreur'] = 'identifiants API du panel non configurés (Paramètres)';
        return $out;
    }

    // 1) Nom / obligation / photo requise : la liste des tâches du jour.
    foreach (PanelApi::shopTasks($shopId, $date) as $t) {
        if ((int) ($t['task_id'] ?? $t['id'] ?? 0) === $taskId) {
            $out['tache']        = trim((string) ($t['task_name'] ?? $t['name'] ?? '')) ?: null;
            $out['checklist']    = $t['checklist_name'] ?? null;
            $out['obligatoire']  = isset($t['is_mandatory']) ? (bool) $t['is_mandatory'] : null;
            $out['photoRequise'] = isset($t['requires_photo']) ? (bool) $t['requires_photo'] : null;
            $out['statut']       = $t['status'] ?? null;
            foreach (['product_id', 'id_product', 'productId'] as $pk) {
                if (!empty($t[$pk]) && is_numeric($t[$pk])) { $out['produitId'] = (int) $t[$pk]; break; }
            }
            break;
        }
    }

    // 2) Pièce jointe + completion : c'est l'AVANCEMENT qui les porte.
    $attId = 0;
    $checklists = $out['checklistId'] !== null
        ? [['id' => $out['checklistId']]]
        : PanelApi::shopChecklists($shopId, $date);
    foreach ($checklists as $cl) {
        $cid = (int) ($cl['id'] ?? $cl['checklist_id'] ?? 0);
        if ($cid <= 0) { continue; }
        foreach (PanelApi::checklistProgress($shopId, $cid, $date) as $p) {
            if ((int) ($p['task_id'] ?? $p['id'] ?? 0) !== $taskId) { continue; }
            $attId = (int) ($p['attachment_id'] ?? 0);
            $out['checklistId']  = $cid;
            $out['completionId'] = $p['completion_id'] !== null ? (int) $p['completion_id'] : $out['completionId'];
            $out['statut']       = $p['status'] ?? $out['statut'];
            if ($out['tache'] === null) {
                $out['tache'] = trim((string) ($p['task_name'] ?? $p['name'] ?? '')) ?: null;
            }
            if ($out['produitId'] === null) {
                foreach (['product_id', 'id_product', 'productId'] as $pk) {
                    if (!empty($p[$pk]) && is_numeric($p[$pk])) { $out['produitId'] = (int) $p[$pk]; break; }
                }
            }
            break 2;
        }
    }
    if ($attId > 0) { $out['photo'] = PanelApi::attachmentUrl($attId); }
    if ($out['produitId'] !== null) {
        $ref = PanelApi::productPhoto($out['produitId']);
        if ($ref !== null) { $out['produit'] = $ref['nom']; $out['photoRef'] = $ref['url']; }
    }
    $out['api']['erreur'] = PanelApi::$lastError;
    return $out;
}

function ep_perf(): array
{
    $annees = array_map('intval', explode(',', $_GET['annees'] ?? '2025,2026'));
    $in = implode(',', array_fill(0, count($annees), '?'));
    // Vrai P&L mensuel du panel (table partagée `mac_shop_monthly_pnl`, la même
    // que le ValuationService du panel). Le snapshot porte ca / marge nette /
    // labour / overhead ; il ne porte NI « material » (food, cf. ticket T5a du
    // panel) NI tickets/panier — laissés à null. Repli sur ceo_shop_month_perf.
    try {
        $key = fn ($s, $y, $m) => $s . '-' . $y . '-' . $m;
        $cells = [];
        // 1) P&L mensuel (mac_shop_monthly_pnl) : CA, marge nette, labour, overhead.
        foreach (Db::rows("SELECT id_shop, year, month, ca, net_margin_pct, net_result, labour, overhead
                          FROM mac_shop_monthly_pnl WHERE year IN ($in)", $annees) as $r) {
            $ca  = $r['ca'] !== null ? (float) $r['ca'] : null;
            $pos = $ca !== null && $ca > 0;
            $cells[$key($r['id_shop'], $r['year'], $r['month'])] = [
                'storeId'       => (string) $r['id_shop'], 'annee' => (int) $r['year'], 'mois' => (int) $r['month'],
                'ca'            => $ca, 'caBudget' => null, 'caTheorique' => null,
                'margeNette'    => $r['net_result'] !== null ? (float) $r['net_result'] : null,
                'margePct'      => $r['net_margin_pct'] !== null ? round((float) $r['net_margin_pct'] / 100, 4) : null,
                'tickets'       => null, 'panierMoyen' => null, 'foodCostPct' => null,
                'labourCostPct' => ($pos && $r['labour'] !== null) ? round((float) $r['labour'] / $ca * 100, 1) : null,
                'overheadPct'   => ($pos && $r['overhead'] !== null) ? round((float) $r['overhead'] / $ca * 100, 1) : null,
                'valorisation'  => null,
            ];
        }
        // 2) Ventes caisse (`transaction`) : tickets + panier moyen RÉELS, et CA de
        //    repli pour les mois sans P&L. Borné à l'exercice courant (perf récente)
        //    et plafonné côté MySQL ; en cas de lenteur/absence on garde le P&L seul.
        try {
            $yMax = max($annees);
            $from = sprintf('%04d-01-01 00:00:00', $yMax);
            $to   = sprintf('%04d-01-01 00:00:00', $yMax + 1);
            foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */
                                      id_shop, MONTH(insert_timestamp) m,
                                      COUNT(DISTINCT ticket_key) tickets,
                                      SUM(total_gross_amount_after_discount) ca
                               FROM transaction
                               WHERE insert_timestamp >= ? AND insert_timestamp < ?
                               GROUP BY id_shop, m", [$from, $to]) as $r) {
                $tickets = (int) $r['tickets'];
                $caPos   = $r['ca'] !== null ? (float) $r['ca'] : null;
                $panier  = ($tickets > 0 && $caPos !== null) ? round($caPos / $tickets, 2) : null;
                $k = $key($r['id_shop'], $yMax, (int) $r['m']);
                if (isset($cells[$k])) {
                    $cells[$k]['tickets'] = $tickets;
                    $cells[$k]['panierMoyen'] = $panier;
                    if ($cells[$k]['ca'] === null) { $cells[$k]['ca'] = $caPos; }
                } else {
                    $cells[$k] = [
                        'storeId' => (string) $r['id_shop'], 'annee' => (int) $yMax, 'mois' => (int) $r['m'],
                        'ca' => $caPos, 'caBudget' => null, 'caTheorique' => null, 'margeNette' => null, 'margePct' => null,
                        'tickets' => $tickets, 'panierMoyen' => $panier, 'foodCostPct' => null,
                        'labourCostPct' => null, 'overheadPct' => null, 'valorisation' => null,
                    ];
                }
            }
        } catch (PDOException $eTx) { /* transaction lente/absente : P&L seul */ }

        // 3) Le BUDGET encodé (`ceo_shop_month_perf`) — table du cockpit, la
        //    seule qui le porte. Ni `mac_shop_monthly_pnl` ni `transaction` ne
        //    connaissent le budget : sans cette passe, l'encodage était écrit
        //    en base et jamais relu, et tous les écrans qui comparent au
        //    budget (suivi budget, heatmap, objectifs de CA) affichaient un
        //    objectif vide sans la moindre erreur.
        //
        //    Un mois budgété SANS réel doit exister aussi : « budget 80 k,
        //    rien encaissé » est une information, pas une ligne à masquer.
        try {
            foreach (Db::rows("SELECT shop_id, year, month, revenue_budget, ca_theorique
                               FROM ceo_shop_month_perf
                               WHERE year IN ($in) AND (revenue_budget IS NOT NULL OR ca_theorique IS NOT NULL)", $annees) as $r) {
                $k = $key($r['shop_id'], $r['year'], $r['month']);
                $bud = $r['revenue_budget'] !== null ? (float) $r['revenue_budget'] : null;
                $theo = $r['ca_theorique'] !== null ? (float) $r['ca_theorique'] : null;
                if (isset($cells[$k])) {
                    $cells[$k]['caBudget'] = $bud;
                    $cells[$k]['caTheorique'] = $theo;
                } else {
                    $cells[$k] = [
                        'storeId' => (string) $r['shop_id'], 'annee' => (int) $r['year'], 'mois' => (int) $r['month'],
                        'ca' => null, 'caBudget' => $bud, 'caTheorique' => $theo,
                        'margeNette' => null, 'margePct' => null, 'tickets' => null, 'panierMoyen' => null,
                        'foodCostPct' => null, 'labourCostPct' => null, 'overheadPct' => null, 'valorisation' => null,
                    ];
                }
            }
        } catch (PDOException $eBud) { /* table du cockpit absente : réel seul */ }

        return array_values($cells);
    } catch (PDOException $e) {
        return array_map(fn ($r) => [
            'storeId' => $r['shop_id'], 'annee' => (int) $r['year'], 'mois' => (int) $r['month'],
            'ca' => $r['revenue'] !== null ? (float) $r['revenue'] : null,
            'caBudget' => $r['revenue_budget'] !== null ? (float) $r['revenue_budget'] : null,
            'caTheorique' => $r['ca_theorique'] !== null ? (float) $r['ca_theorique'] : null,
            'margeNette' => $r['net_margin'] !== null ? (float) $r['net_margin'] : null,
            'margePct' => ($r['net_margin'] !== null && $r['revenue'] > 0) ? round($r['net_margin'] / $r['revenue'], 4) : null,
            'tickets' => $r['tickets'] !== null ? (int) $r['tickets'] : null,
            'panierMoyen' => $r['basket_avg'] !== null ? (float) $r['basket_avg'] : null,
            'foodCostPct' => $r['food_pct'] !== null ? (float) $r['food_pct'] : null,
            'labourCostPct' => $r['labour_pct'] !== null ? (float) $r['labour_pct'] : null,
            'overheadPct' => $r['overhead_pct'] !== null ? (float) $r['overhead_pct'] : null,
            'valorisation' => $r['valuation'] !== null ? (float) $r['valuation'] : null,
        ], Db::rows("SELECT * FROM ceo_shop_month_perf WHERE year IN ($in) ORDER BY shop_id, year, month", $annees));
    }
}

function ep_budgets(): array
{
    $exercice = (int) ($_GET['exercice'] ?? setting('exercice', (int) date('Y')));
    $slugByTag = levierSlugByTag();
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_shop_budget WHERE fiscal_year = ?', [$exercice]) as $b) {
        $sid = $b['shop_id'];
        $enc = Db::row('SELECT COUNT(*) n, MAX(encoded_at) last FROM ceo_shop_month_perf WHERE shop_id = ? AND year = ? AND revenue IS NOT NULL', [$sid, $exercice]);
        $charges = array_map(fn ($l) => [
            'poste' => $l['label'],
            'levier' => $l['levid'] !== null ? ($slugByTag[(int) $l['levid']] ?? '') : '',
            'pctBudget' => (float) $l['pct_budget'],
            'pctTheorique' => $l['pct_theorique'] !== null ? (float) $l['pct_theorique'] : null,
            'champReel' => $l['real_field'],
        ], Db::rows('SELECT * FROM ceo_shop_budget_line WHERE shop_id = ? AND fiscal_year = ? ORDER BY sort_order', [$sid, $exercice]));
        $out[] = [
            'storeId' => $sid, 'exercice' => $exercice,
            'moisEncodes' => (int) $enc['n'], 'moisTotal' => (int) $b['months_total'],
            'dernierEncodage' => $enc['last'] ? substr($enc['last'], 0, 10) : null,
            'panierEngagement' => $b['basket_target'] !== null ? (float) $b['basket_target'] : null,
            'caTheoriqueAn' => $b['ca_theorique_an'] !== null ? (float) $b['ca_theorique_an'] : null,
            'etudeMarche' => [
                'date' => $b['etude_date'], 'source' => $b['etude_source'],
                'potentielMenages' => $b['etude_potentiel_menages'] !== null ? (int) $b['etude_potentiel_menages'] : null,
                'potentielMaturite' => $b['etude_potentiel_maturite'] !== null ? (float) $b['etude_potentiel_maturite'] : null,
                'anneeExploitation' => $b['annee_exploitation'] !== null ? (int) $b['annee_exploitation'] : null,
                'monteeEnRegime' => $b['montee_regime'] !== null ? json_decode($b['montee_regime'], true) : null,
                'saisonnalite' => $b['saisonnalite'] !== null ? json_decode($b['saisonnalite'], true) : null,
                'annexe' => $b['etude_annexe'] !== null ? json_decode($b['etude_annexe'], true) : null,
            ],
            'charges' => $charges,
        ];
    }
    return $out;
}

function ep_targets(): array
{
    $ca = []; $expansion = [];
    foreach (Db::rows('SELECT * FROM ceo_network_target') as $t) {
        $h = $t['horizon'];
        $ca[$h] = ['an' => (int) $t['target_year'], 'cible' => (float) $t['revenue_target']];
        if ($t['note'] !== null) { $ca[$h]['note'] = $t['note']; }
        $expansion[$h] = ['an' => (int) $t['target_year'], 'cible' => (int) $t['openings_target'], 'reel' => (int) $t['openings_real']];
    }
    return ['ca' => $ca, 'expansion' => $expansion, 'caMoyenOuverture' => (float) setting('caMoyenOuverture', 0)];
}

function ep_consultants(): array
{
    // Vrais consultants du panel : user_membership(app='CONSULTANT') ⨝ user_profile.
    // Repli sur ceo_consultant pour une installation autonome.
    try {
        $rows = Db::rows(
            "SELECT m.id, m.scope_type, m.scope_id,
                    COALESCE(NULLIF(TRIM(p.display_name), ''),
                             NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))), ''),
                             CONCAT('Consultant #', m.id)) AS nom,
                    NULLIF(TRIM(p.email), '') AS email
               FROM user_membership m
               LEFT JOIN user_profile p ON p.auth_user_id = m.auth_user_id
              WHERE m.app = 'CONSULTANT' AND m.is_active = 1
              ORDER BY nom");
        return array_map(fn ($c) => [
            'id'     => 'u' . $c['id'],
            'nom'    => $c['nom'],
            'role'   => $c['scope_type'] === 'SHOP' ? 'Consultant boutique' : 'Consultant réseau',
            'email'  => $c['email'],
            'tjm'    => null,        // TJM/charge : données RH propres au cockpit, non présentes côté panel
            'charge' => null,
            'visites' => [],         // les visites (mac_consultant_visit) sont branchées séparément si présentes
        ], $rows);
    } catch (PDOException $e) {
        $out = [];
        foreach (Db::rows('SELECT * FROM ceo_consultant ORDER BY id') as $c) {
            $visites = array_map(fn ($v) => ['date' => $v['visited_on'], 'store' => $v['store_label'], 'objet' => $v['subject']],
                Db::rows('SELECT * FROM ceo_consultant_visit WHERE consultant_id = ? ORDER BY visited_on DESC', [$c['id']]));
            $out[] = ['id' => $c['id'], 'nom' => $c['name'], 'role' => $c['role'], 'email' => $c['email'],
                'tjm' => $c['daily_rate'] !== null ? (float) $c['daily_rate'] : null,
                'charge' => $c['workload'] !== null ? (int) $c['workload'] : null, 'visites' => $visites];
        }
        return $out;
    }
}

function ep_suppliers(): array
{
    return array_map(fn ($s) => ['id' => $s['id'], 'nom' => $s['name'], 'perim' => $s['perimeter'], 'email' => $s['email']],
        Db::rows('SELECT * FROM ceo_supplier ORDER BY id'));
}

function ep_projects(): array
{
    $slugByTag = levierSlugByTag();
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_project ORDER BY id') as $p) {
        $id = $p['id'];
        $leviers = array_map(fn ($r) => $slugByTag[(int) $r['levid']] ?? '', Db::rows('SELECT levid FROM ceo_project_levid WHERE project_id = ?', [$id]));
        $jalons = array_map(fn ($j) => ['nom' => $j['name'], 'cible' => $j['target_on'], 'reel' => $j['done_on']],
            Db::rows('SELECT * FROM ceo_project_milestone WHERE project_id = ? ORDER BY sort_order, id', [$id]));
        $couts = array_map(fn ($c) => ['poste' => $c['label'], 'prevu' => (float) $c['planned'], 'reel' => (float) $c['actual']],
            Db::rows('SELECT * FROM ceo_project_cost WHERE project_id = ? ORDER BY id', [$id]));
        $taches = array_map(fn ($t) => [
            'id' => $t['id'], 'nom' => $t['name'],
            'owner' => ['t' => $t['owner_kind'], 'id' => $t['owner_id']],
            'magasin' => $t['shop_id'], 'due' => $t['due_on'], 'done' => $t['done_on'],
            'relance' => $t['reminded_on'], 'desc' => $t['description'],
            'valideeLe' => $t['validated_at'] ?? null,
            'renduePar' => $t['delivered_by'] ?? null,
            'noteRemise' => $t['delivery_note'] ?? null,
            'budget' => $t['budget'] !== null ? (float) $t['budget'] : null,
            // `note` nulle sur une tâche rendue = elle attend une validation :
            // c'est ce qui alimente le groupe « À valider » de l'écran.
            'note' => $t['note'] !== null ? (int) $t['note'] : null,
            'valideePar' => $t['validated_by'],
            'signalement' => tacheSignalement($t['id']),
        ], Db::rows('SELECT * FROM ceo_project_task WHERE project_id = ? ORDER BY id', [$id]));
        $out[] = [
            'id' => $id, 'nom' => $p['name'], 'famille' => $p['famille'], 'statut' => $p['status'], 'prio' => $p['priority'],
            'debut' => $p['starts_on'], 'fin' => $p['ends_on'],
            'axes' => $p['axes_json'] ? json_decode($p['axes_json'], true) : [$p['axe']],
            'leviers' => $leviers,
            'budget' => $p['budget'] !== null ? (float) $p['budget'] : null,
            'valeurEst' => $p['value_est'] !== null ? (float) $p['value_est'] : null,
            'valeurReal' => $p['value_real'] !== null ? (float) $p['value_real'] : null,
            'valeurTxt' => $p['value_txt'],
            'kpis' => $p['kpis_json'] ? json_decode($p['kpis_json'], true) : [],
            'jalons' => $jalons, 'taches' => $taches, 'couts' => $couts,
        ];
    }
    return $out;
}

/**
 * Le dernier signalement d'une tâche, ouvert ou clos.
 *
 * Une tâche validée sous le seuil garde son signalement au suivi : l'écran
 * affiche « 1 ouvert depuis 4 jours » sur l'intervenant, ce qui n'a de sens
 * que si la ligne survit à la clôture de la tâche.
 */
function tacheSignalement(string $taskId): ?array
{
    $r = Db::row('SELECT * FROM ceo_task_issue WHERE task_id = ? ORDER BY id DESC LIMIT 1', [$taskId]);
    if ($r === null) {
        return null;
    }
    return [
        'id'      => (int) $r['id'],
        'note'    => (int) $r['note'],
        'famille' => $r['famille'],
        'type'    => $r['type'],
        'comment' => $r['comment'],
        'copie'   => $r['recipients'] !== null && $r['recipients'] !== '' ? explode(',', $r['recipients']) : [],
        'statut'  => $r['status'],
        'ouvert'  => $r['closed_at'] === null,
        'creeLe'  => $r['created_at'],
        'creePar' => $r['created_by'],
    ];
}

/**
 * Suivi des tâches — les chiffres d'une période, et les signalements à traiter.
 *
 * Calculé en SQL plutôt qu'au client : l'écran doit pouvoir répondre « combien
 * de tâches validées cette semaine » sans charger tous les projets, et le même
 * calcul sert au rapport hebdomadaire et au rapport mensuel.
 *
 * `periode` vaut `semaine` ou `mois`. La borne est la date du jour côté base —
 * pas celle du navigateur, qui peut être n'importe où.
 */
function ep_taches_suivi(string $periode = 'mois'): array
{
    $jours = $periode === 'semaine' ? 7 : 30;
    $depuis = date('Y-m-d', strtotime('-' . $jours . ' days'));

    // Les tâches validées sur la période, bornées sur la date de VALIDATION.
    // Borner sur `done_on` — la livraison — situerait une validation d'
    // aujourd'hui dans le mois où la tâche a été rendue : une tâche livrée en
    // mars et jugée en août n'apparaîtrait dans aucun suivi utile.
    // COALESCE pour les validations d'avant l'existence de la colonne.
    $val = Db::rows(
        'SELECT t.id, t.name, t.note, t.done_on, t.validated_at, t.owner_kind, t.owner_id, t.shop_id,'
        . ' p.name AS projet FROM ceo_project_task t'
        . ' JOIN ceo_project p ON p.id = t.project_id'
        . ' WHERE t.note IS NOT NULL AND COALESCE(t.validated_at, t.done_on) >= ?'
        . ' ORDER BY COALESCE(t.validated_at, t.done_on) DESC', [$depuis]);

    $notes = array_map(static fn ($r) => (int) $r['note'], $val);
    $repartition = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    foreach ($notes as $n) { $repartition[$n] = ($repartition[$n] ?? 0) + 1; }

    // Les signalements : ceux de la période, plus TOUS ceux qui restent
    // ouverts. Un signalement de trois semaines qui traîne doit apparaître
    // dans le suivi de la semaine — c'est même le premier à devoir sauter aux
    // yeux, alors qu'une borne de date l'aurait masqué.
    $sig = Db::rows(
        'SELECT i.*, t.name AS tache, t.owner_kind, t.owner_id, p.name AS projet'
        . ' FROM ceo_task_issue i'
        . ' JOIN ceo_project_task t ON t.id = i.task_id'
        . ' JOIN ceo_project p ON p.id = t.project_id'
        . ' WHERE i.closed_at IS NULL OR i.created_at >= ?'
        . ' ORDER BY (i.closed_at IS NULL) DESC, i.note ASC, i.created_at ASC', [$depuis]);

    $signalements = array_map(static fn ($r) => [
        'id'       => (int) $r['id'],
        'tacheId'  => $r['task_id'],
        'tache'    => $r['tache'],
        'projet'   => $r['projet'],
        'owner'    => ['t' => $r['owner_kind'], 'id' => $r['owner_id']],
        'note'     => (int) $r['note'],
        'famille'  => $r['famille'],
        'type'     => $r['type'],
        'comment'  => $r['comment'],
        'statut'   => $r['status'],
        'ouvert'   => $r['closed_at'] === null,
        'creeLe'   => $r['created_at'],
        'creePar'  => $r['created_by'],
        'vuLe'     => $r['seen_at'],
        'closLe'   => $r['closed_at'],
        'closPar'  => $r['closed_by'],
    ], $sig);

    // Par intervenant : ce qui permet de dire à qui parler, pas seulement
    // combien de lignes il reste.
    $par = [];
    foreach ($val as $r) {
        $k = $r['owner_kind'] . ':' . $r['owner_id'];
        $par[$k] ??= ['owner' => ['t' => $r['owner_kind'], 'id' => $r['owner_id']], 'validees' => 0, 'somme' => 0, 'sousSeuil' => 0, 'ouverts' => 0];
        $par[$k]['validees']++;
        $par[$k]['somme'] += (int) $r['note'];
    }
    foreach ($signalements as $g) {
        $k = $g['owner']['t'] . ':' . $g['owner']['id'];
        $par[$k] ??= ['owner' => $g['owner'], 'validees' => 0, 'somme' => 0, 'sousSeuil' => 0, 'ouverts' => 0];
        if ($g['ouvert']) { $par[$k]['ouverts']++; }
    }
    $parIntervenant = array_values(array_map(static function (array $x): array {
        $x['moyenne'] = $x['validees'] > 0 ? round($x['somme'] / $x['validees'], 2) : null;
        unset($x['somme'], $x['sousSeuil']);
        return $x;
    }, $par));

    $ouverts = count(array_filter($signalements, static fn ($g) => $g['ouvert']));
    return [
        'periode'    => $periode,
        'depuis'     => $depuis,
        'validees'   => count($val),
        'moyenne'    => $notes !== [] ? round(array_sum($notes) / count($notes), 2) : null,
        'repartition' => $repartition,
        'ouverts'    => $ouverts,
        'traites'    => count($signalements) - $ouverts,
        'signalements' => $signalements,
        'parIntervenant' => $parIntervenant,
        'taches'     => array_map(static fn ($r) => [
            'id' => $r['id'], 'nom' => $r['name'], 'note' => (int) $r['note'],
            'le' => $r['validated_at'] ?? $r['done_on'], 'projet' => $r['projet'],
            'owner' => ['t' => $r['owner_kind'], 'id' => $r['owner_id']],
        ], $val),
    ];
}

function ep_crm(): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_project_crm') as $r) {
        $out[$r['project_id']] = ['gain' => $r['gain'], 'apport' => $r['apport'], 'objectif' => $r['objectif'],
            'attendu' => $r['attendu'] !== null ? (float) $r['attendu'] : null,
            'realise' => $r['realise'] !== null ? (float) $r['realise'] : null];
    }
    return $out;
}

/**
 * Les personnes destinataires des rapports.
 *
 * Source de vérité : les comptes du panel (`user_membership` ⨝ `user_profile`),
 * comme pour les consultants. `ceo_person` n'est qu'un repli d'installation
 * autonome — sur une base réelle il est vide, et l'écran Reporting n'offrait
 * alors AUCUN destinataire sélectionnable, sans le moindre message.
 *
 * On rend TOUS les comptes actifs, adresse ou non. Filtrer sur l'e-mail
 * paraissait raisonnable — « un destinataire sans adresse n'en est pas un » —
 * mais sur la vraie base cela faisait disparaître trois consultants sur cinq
 * de la liste, sans rien dire. On ne peut pas corriger une fiche qu'on ne voit
 * plus : l'écran les montre, et signale l'adresse manquante.
 */
function ep_people(): array
{
    try {
        $rows = Db::rows(
            "SELECT m.id, m.app, m.scope_type,
                    COALESCE(NULLIF(TRIM(p.display_name), ''),
                             NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))), ''),
                             CONCAT('Compte #', m.id)) AS nom,
                    NULLIF(TRIM(p.email), '') AS email
               FROM user_membership m
               LEFT JOIN user_profile p ON p.auth_user_id = m.auth_user_id
              WHERE m.is_active = 1 AND p.auth_user_id IS NOT NULL
              GROUP BY m.id, m.app, m.scope_type, nom, email
              ORDER BY nom");
        if ($rows !== []) {
            return array_map(static fn ($r) => [
                'id'    => 'u' . $r['id'],
                'nom'   => $r['nom'],
                'role'  => $r['app'] === 'CONSULTANT'
                    ? ($r['scope_type'] === 'SHOP' ? 'Consultant boutique' : 'Consultant réseau')
                    : ucfirst(strtolower((string) $r['app'])),
                'email' => $r['email'],
                // Un rapport ne part pas sans adresse : l'écran doit pouvoir le
                // dire, plutôt que de proposer un destinataire injoignable.
                'joignable' => $r['email'] !== null && $r['email'] !== '',
            ], $rows);
        }
    } catch (PDOException $e) { /* tables du panel absentes : repli local */ }

    return array_map(fn ($p) => ['id' => $p['id'], 'nom' => $p['name'], 'role' => $p['role'], 'email' => $p['email']],
        Db::rows('SELECT * FROM ceo_person ORDER BY id'));
}

function ep_reporting(): array
{
    $reports = array_map(fn ($r) => [
        'id' => $r['id'], 'nom' => $r['name'], 'type' => $r['type'], 'desc' => $r['description'],
        'freq' => $r['frequency'], 'postes' => json_decode($r['postes_json'], true),
        'destId' => $r['dest_id'], 'ccId' => $r['cc_id'] ?? '',
        'dernier' => $r['last_run'], 'actif' => (bool) $r['active'],
    ], Db::rows('SELECT * FROM ceo_report_schedule ORDER BY id'));
    $alertRules = array_map(fn ($a) => ['id' => $a['id'], 'nom' => $a['name'], 'canal' => $a['channel'], 'actif' => (bool) $a['active']],
        Db::rows('SELECT * FROM ceo_alert_rule ORDER BY id'));
    return ['reports' => $reports, 'alertRules' => $alertRules];
}

function ep_journal(): array
{
    return array_map(fn ($l) => [
        'ts' => substr($l['happened_at'], 0, 16), 'qui' => $l['actor'], 'type' => $l['kind'],
        'projet' => $l['project'] ?? '—', 'msg' => $l['message'],
    ], Db::rows('SELECT * FROM ceo_journal_entry ORDER BY happened_at DESC, id DESC LIMIT 500'));
}

function ep_products(): array
{
    $periode = $_GET['periode'] ?? '2026-07';
    if (!preg_match('/^(\d{4})-(\d{2})$/', $periode, $m)) { $m = [null, '2026', '07']; }
    $annee = (int) $m[1]; $mois = (int) $m[2];

    // Vraies ventes par produit sur le mois (lignes de caisse `transaction_product`
    // ⨝ `transaction` pour la période et le magasin). Mêmes bornes que la perf.
    // coutUnit = null : le COÛT matière n'est PAS dans la base partagée (il vient
    // de l'API amont, cf. panel) → marge non calculable ici. Requête bornée à un
    // mois, plafonnée MySQL et encapsulée. Repli sur ceo_product si les tables POS
    // sont absentes (installation autonome).
    try {
        $venteMois = static function (string $from, string $to): array {
            return Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */
                                    tp.id_product,
                                    MAX(tp.product_name) nom,
                                    SUM(tp.quantity) volume,
                                    SUM(tp.total_gross_value_after_discount) ca,
                                    COUNT(DISTINCT t.id_shop) magasins
                             FROM transaction t
                             JOIN transaction_product tp ON tp.id_transaction = t.id
                             WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                             GROUP BY tp.id_product
                             ORDER BY volume DESC
                             LIMIT 200", [$from, $to]);
        };
        $from = sprintf('%04d-%02d-01 00:00:00', $annee, $mois);
        $to   = date('Y-m-01 00:00:00', strtotime("$from +1 month"));
        $rows = $venteMois($from, $to);
        // Période demandée sans vente (mois courant partiel, ou installation
        // fraîche) : replier sur le dernier mois de caisse réellement encodé.
        if (!$rows) {
            $last = Db::row("SELECT /*+ MAX_EXECUTION_TIME(4000) */
                                    DATE_FORMAT(MAX(insert_timestamp), '%Y-%m-01 00:00:00') d FROM transaction");
            if ($last !== null && $last['d'] !== null) {
                $from = $last['d'];
                $to   = date('Y-m-01 00:00:00', strtotime("$from +1 month"));
                $rows = $venteMois($from, $to);
            }
        }

        // Petit référentiel catégorie (sig_products → sig_product_categories).
        $cat = [];
        try {
            foreach (Db::rows("SELECT sp.id, sc.name FROM sig_products sp
                               LEFT JOIN sig_product_categories sc ON sc.id = sp.category_id") as $c) {
                $cat[(string) $c['id']] = $c['name'];
            }
        } catch (PDOException $eCat) { /* référentiel absent : catégorie vide */ }

        return array_map(function ($r) use ($cat) {
            $vol  = (float) $r['volume'];
            $prix = $vol > 0 ? round((float) $r['ca'] / $vol, 2) : null;
            return [
                'id'        => (string) $r['id_product'],
                'nom'       => ($r['nom'] !== null && $r['nom'] !== '') ? $r['nom'] : ('#' . $r['id_product']),
                'categorie' => $cat[(string) $r['id_product']] ?? 'Non catégorisé',
                'volume'    => (int) round($vol),
                'prix'      => $prix,
                'coutUnit'  => null,
                'tendVol'   => 1,
                'magasins'  => (int) $r['magasins'],
            ];
        }, $rows);
    } catch (PDOException $e) {
        $rows = Db::rows(
            'SELECT p.id, p.nom, p.categorie, s.volume, s.nb_magasins, s.prix_moyen, s.cout_unitaire,
                    n1.volume AS volume_n1
               FROM ceo_product p
               JOIN ceo_product_month_sales s  ON s.product_id = p.id AND s.annee = ? AND s.mois = ?
          LEFT JOIN ceo_product_month_sales n1 ON n1.product_id = p.id AND n1.annee = ? AND n1.mois = ?
              WHERE p.actif = 1 ORDER BY p.id', [$annee, $mois, $annee - 1, $mois]);
        return array_map(fn ($r) => [
            'id' => $r['id'], 'nom' => $r['nom'], 'categorie' => $r['categorie'],
            'volume' => (int) $r['volume'], 'prix' => (float) $r['prix_moyen'], 'coutUnit' => (float) $r['cout_unitaire'],
            'tendVol' => $r['volume_n1'] ? round($r['volume'] / $r['volume_n1'], 4) : 1,
            'magasins' => (int) $r['nb_magasins'],
        ], $rows);
    }
}
