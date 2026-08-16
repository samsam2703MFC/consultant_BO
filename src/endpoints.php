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
        // Période réellement servie par le scoring produit (le backend replie
        // sur le dernier mois de caisse encodé) — la modale « perte par
        // magasin » doit interroger la MÊME fenêtre, sinon les deux chiffres
        // se contredisent sans que rien ne le signale.
        'periodeProduits'  => setting('periodeProduits', date('Y-m')),
        'contribOuverture' => setting('contribOuverture', 0),
        'notes'            => setting('notes', new stdClass()),
        'familles'         => setting('familles', []),
        // Pondération et seuils du scoring produit — réglage, jamais une
        // constante d'écran : le score qui décide de retirer une référence
        // doit pouvoir se discuter et s'ajuster sans déploiement.
        'scoring'          => setting('scoring', ['poids' => ['volume' => 40, 'marge' => 30, 'perte' => 20, 'comptoir' => 10],
                                                  'seuils' => ['moteur' => 68, 'conforter' => 46],
                                                  'marge' => ['bas' => 20, 'basNote' => 20, 'haut' => 80, 'hautNote' => 100]]),
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
            // Une tâche NOTÉE est validée : la note EST la validation. Le
            // contrôle de la direction consiste à noter (ou à renoter), pas à
            // cocher une case en plus — sans quoi l'écran annonce « 0 validée »
            // devant dix tâches déjà évaluées.
            $valide = $note !== null;
            $ctrlDir = !empty($r['owner_validated_at']);
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
                // Qui a validé = qui a noté (le consultant), sauf si la
                // direction a explicitement contresigné : elle prime alors.
                'valideePar'  => $valide ? ($r['consultant_name'] ?? null) : null,
                'revuePar'    => $ctrlDir ? ($r['owner_name'] ?? null) : null,
                'valideeLe'   => $ctrlDir ? substr((string) $r['owner_validated_at'], 0, 16)
                                          : ($valide && $r['updated_at'] !== null ? substr((string) $r['updated_at'], 0, 16) : null),
                'ctrlDir'     => $ctrlDir,
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
/**
 * GET /pwa/waste/debug?shop=&from=&to= — forme brute de la réponse « pertes ».
 *
 * Sonde de diagnostic : la structure exacte de /shops/{id}/products/waste n'est
 * pas documentée ici. Plutôt que de deviner les noms de champs et d'écrire un
 * calcul qui échouerait en silence, on regarde. Lecture seule, quelques lignes.
 */
function ep_pwa_waste_debug(): array
{
    $shopId = (int) ($_GET['shop'] ?? 0);
    if ($shopId <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    $from = $_GET['from'] ?? null; $to = $_GET['to'] ?? null;
    $q = [];
    if ($from) { $q['from'] = $from; $q['date_from'] = $from; }
    if ($to)   { $q['to'] = $to;     $q['date_to'] = $to; }
    $path = '/shops/' . $shopId . '/products/waste' . ($q ? '?' . http_build_query($q) : '');
    $brut = PanelApi::brut($path);
    $liste = PanelApi::shopWaste($shopId, $from, $to);
    $apercu = static function ($v) {
        if (!is_array($v)) { return ['type' => gettype($v), 'valeur' => $v]; }
        if (array_is_list($v)) {
            return ['type' => 'liste', 'n' => count($v),
                'clesPremier' => ($v && is_array($v[0])) ? array_slice(array_keys($v[0]), 0, 25) : null,
                'premier' => $v[0] ?? null];
        }
        return ['type' => 'objet', 'cles' => array_slice(array_keys($v), 0, 25), 'extrait' => array_slice($v, 0, 6, true)];
    };
    return [
        'chemin'    => $path,
        'erreur'    => PanelApi::$lastError,
        'clesBrut'  => is_array($brut) ? array_slice(array_keys($brut), 0, 15) : null,
        'products'         => is_array($brut) && isset($brut['products']) ? $apercu($brut['products']) : null,
        'grouped_products' => is_array($brut) && isset($brut['grouped_products']) ? $apercu($brut['grouped_products']) : null,
        'period_summary'   => is_array($brut) && isset($brut['period_summary']) ? $apercu($brut['period_summary']) : null,
        'nbLignesApresDepaquetage' => count($liste),
    ];
}

/**
 * GET /products/waste?produit=&periode=YYYY-MM — perte d'une référence,
 * magasin par magasin, pour la modale du scoring.
 *
 * Le taux réseau d'une référence peut cacher un seul magasin qui jette : la
 * décision (retirer ? reformer une équipe ?) n'est pas la même. On rend donc
 * le détail par boutique, trié du plus mauvais au meilleur.
 */
function ep_product_waste(): array
{
    $pid = (int) ($_GET['produit'] ?? 0);
    if ($pid <= 0) { http_response_code(400); return ['error' => 'produit requis']; }
    // Défaut : le mois de caisse réellement encodé, PAS le mois courant. La
    // caisse s'arrête à la mi-juillet ; interroger août rend des rebuts réels
    // face à zéro vente, donc 100 % de perte partout — un chiffre faux qui a
    // l'air d'une catastrophe. L'écran de scoring travaille déjà sur ce mois.
    $periode = (string) ($_GET['periode'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
        $ref = setting('periodeProduits');
        $periode = (is_string($ref) && preg_match('/^\d{4}-\d{2}$/', $ref)) ? $ref : date('Y-m');
    }
    preg_match('/^(\d{4})-(\d{2})$/', $periode, $m);
    $from = sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
    $to   = date('Y-m-d', strtotime($from . ' +1 month -1 day'));

    $out = ['produitId' => (string) $pid, 'nom' => null, 'periode' => $periode,
        'du' => $from, 'au' => $to, 'magasins' => [],
        'reseau' => ['jete' => 0, 'vendu' => 0, 'taux' => null],
        'api' => ['configure' => PanelApi::configured(), 'erreur' => null]];
    if (!PanelApi::configured()) {
        $out['api']['erreur'] = 'compte consultant non configuré (Mon compte)';
        return $out;
    }

    try {
        $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id');
    } catch (PDOException $e) { $shops = []; }

    // Ventes PAR MAGASIN : notre propre agrégat de caisse. L'API rend un
    // `sold_qty` identique pour tous les magasins (valeur réseau) — s'en servir
    // comme dénominateur par magasin donnerait des taux faux, d'autant plus
    // trompeurs qu'ils paraissent plausibles.
    $venteParShop = [];
    try {
        foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */ t.id_shop, SUM(tp.quantity) q
                           FROM transaction t JOIN transaction_product tp ON tp.id_transaction = t.id
                           WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ? AND tp.id_product = ?
                           GROUP BY t.id_shop",
            [$from . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($to . ' +1 day')), $pid]) as $v) {
            $venteParShop[(int) $v['id_shop']] = (float) $v['q'];
        }
    } catch (PDOException $eV) { /* caisse indisponible : ventes inconnues */ }

    $totJ = 0.0; $totV = 0.0;
    foreach ($shops as $sh) {
        $sid = (int) $sh['id'];
        $w = PanelApi::shopProductWaste($sid, $pid, $from, $to);
        $j = $w !== null ? (float) ($w['waste_qty'] ?? 0) : 0.0;
        $v = $venteParShop[$sid] ?? 0.0;
        if ($out['nom'] === null && $w !== null && !empty($w['product_name'])) { $out['nom'] = (string) $w['product_name']; }
        $den = $j + $v;
        $totJ += $j; $totV += $v;
        $out['magasins'][] = [
            'shopId' => (string) $sid, 'magasin' => (string) $sh['name'],
            'jete' => (int) round($j), 'vendu' => (int) round($v),
            // Pas de vente ni de rebut : la référence n'était pas proposée ici.
            // C'est différent d'un taux nul, et l'écran doit pouvoir le dire.
            'taux' => $den > 0 ? round($j / $den, 4) : null,
            'motif' => $w !== null && !empty($w['top_reason']) ? (string) $w['top_reason'] : null,
            'caPerdu' => $w !== null && isset($w['ca_waste_net']) ? round((float) $w['ca_waste_net'], 2) : null,
        ];
    }
    usort($out['magasins'], function ($a, $b) {
        if ($a['taux'] === null) { return 1; }
        if ($b['taux'] === null) { return -1; }
        return $b['taux'] <=> $a['taux'];
    });
    $den = $totJ + $totV;
    $out['reseau'] = ['jete' => (int) round($totJ), 'vendu' => (int) round($totV),
        'taux' => $den > 0 ? round($totJ / $den, 4) : null];
    $out['api']['erreur'] = PanelApi::$lastError;
    return $out;
}

/**
 * GET /pwa/probe?paths=a,b,c — quels chemins de l'API amont répondent.
 *
 * Sonde de branchement : avant de câbler l'ETP il faut savoir COMMENT lister
 * les employés d'une boutique. On essaie les chemins plausibles et on rapporte
 * ce qui répond, plutôt que d'en supposer un et d'écrire un calcul muet.
 */
function ep_pwa_probe(): array
{
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    $paths = array_filter(array_map('trim', explode(',', (string) ($_GET['paths'] ?? ''))));
    if (!$paths) { http_response_code(400); return ['error' => 'paths requis']; }
    $out = [];
    foreach (array_slice($paths, 0, 12) as $p) {
        PanelApi::$lastError = null;
        $r = PanelApi::brut($p);
        $apercu = null;
        if (is_array($r)) {
            $apercu = array_is_list($r)
                ? ['type' => 'liste', 'n' => count($r), 'clesPremier' => ($r && is_array($r[0])) ? array_slice(array_keys($r[0]), 0, 20) : null, 'premier' => $r[0] ?? null]
                : ['type' => 'objet', 'cles' => array_slice(array_keys($r), 0, 20)];
        }
        $out[] = ['chemin' => $p, 'erreur' => PanelApi::$lastError, 'apercu' => $apercu];
    }
    return ['resultats' => $out];
}

/**
 * GET /production/catalogue — référentiel produit RÉSEAU (partie franchiseur).
 *
 * Temps de production, batchs, capacité four, durée de vie, coût matière : rien
 * de tout cela n'existe côté panel ni en base partagée. Le cockpit en est la
 * source. `pwaId` rapproche la référence du catalogue de caisse, ce qui permet
 * de croiser avec les ventes et les pertes réelles.
 */
/**
 * Catalogue produit du réseau.
 *
 * Le catalogue N'EST PAS tenu par le cockpit : il vit dans `product`
 * (atelierby_db), avec ses catégories et ses gammes saisonnières. Le cockpit
 * n'ajoute que ce qui n'existe nulle part ailleurs — les paramètres de
 * production (temps, batchs, four) et le coût matière saisi à la main. On
 * enrichit donc, on ne duplique pas : dupliquer ferait diverger les deux
 * listes sans que personne ne s'en aperçoive.
 */
function ep_prod_catalogue(): array
{
    $enrich = [];   // id_product du panel → ligne cockpit
    $parRef = [];
    foreach (Db::rows('SELECT * FROM ceo_prod_product') as $r) {
        $parRef[(string) $r['ref']] = $r;
        if ($r['pwa_id'] !== null) { $enrich[(int) $r['pwa_id']] = $r; }
    }
    $plano = [];
    foreach (Db::rows('SELECT * FROM ceo_prod_planogram') as $p) { $plano[(string) $p['ref']] = $p; }

    $reel = ep_prod_catalogue_reel($enrich, $parRef, $plano);
    if ($reel !== null) { return $reel; }

    // Repli : installation autonome, sans la base de caisse.
    $rows = Db::rows('SELECT * FROM ceo_prod_product WHERE actif = 1 ORDER BY categorie, nom');
    return array_map(function ($r) use ($plano) {
        $pl = $plano[$r['ref']] ?? null;
        $mat = $r['mat'] !== null ? (float) $r['mat'] : null;
        $prix = $r['prix'] !== null ? (float) $r['prix'] : null;
        return [
            'ref' => $r['ref'], 'nom' => $r['nom'], 'categorie' => $r['categorie'],
            'prep' => (int) $r['prep'], 'cuisson' => (int) $r['cuisson'], 'fin' => (int) $r['fin'],
            'bmin' => (int) $r['bmin'], 'bmult' => (int) $r['bmult'], 'four' => (int) $r['four'],
            'dlv' => (int) $r['dlv'], 'mat' => $mat, 'prix' => $prix,
            // Marge unitaire : disponible ici parce que le coût matière est
            // tenu par le réseau — l'API de caisse ne l'expose pas.
            'marge' => ($mat !== null && $prix !== null) ? round($prix - $mat, 3) : null,
            'margePct' => ($mat !== null && $prix > 0) ? round(($prix - $mat) / $prix, 4) : null,
            'must' => (bool) $r['must'], 'qmin' => (int) $r['qmin'],
            'periods' => $r['periods'], 'profil' => $r['profil'],
            'pwaId' => $r['pwa_id'] !== null ? (int) $r['pwa_id'] : null,
            'zone' => $pl ? $pl['zone'] : null,
            'meuble' => $pl ? $pl['meuble'] : null,
            'niveau' => $pl ? $pl['niveau'] : null,
            'slot' => $pl && $pl['slot'] !== null ? (int) $pl['slot'] : null,
        ];
    }, $rows);
}

/**
 * Suivi de production du réseau.
 *
 * Source : `product_movement`, qui journalise les mouvements de la caisse
 * avec leur nature (PRODUCTION, WASTE, SALE, RETURN, ADJUSTMENT). C'est la
 * seule trace de ce qui a été RÉELLEMENT produit — les fournées « demandées »
 * n'existent nulle part dans la base, et cet écran ne prétend donc pas les
 * connaître : il rend le produit et le jeté, pas un écart contre une consigne
 * qui n'est pas enregistrée.
 */
function ep_prod_suivi(): array
{
    $periode = (string) ($_GET['periode'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
        // Le dernier mois réellement journalisé, pas le mois courant : la
        // caisse peut s'être arrêtée avant, et un écran vide passerait pour
        // « aucune production ».
        $periode = null;
        try {
            $d = Db::rows("SELECT DATE_FORMAT(MAX(created_at), '%Y-%m') p FROM product_movement");
            if ($d && !empty($d[0]['p'])) { $periode = (string) $d[0]['p']; }
        } catch (PDOException $e) { /* table absente */ }
        if ($periode === null) { $periode = date('Y-m'); }
    }
    $from = $periode . '-01 00:00:00';
    $to   = date('Y-m-d 00:00:00', strtotime($from . ' +1 month'));

    $out = ['periode' => $periode, 'du' => substr($from, 0, 10), 'au' => date('Y-m-d', strtotime($to . ' -1 day')),
        'reseau' => ['produit' => 0, 'jete' => 0, 'taux' => null],
        'magasins' => [], 'produits' => [], 'motifs' => [], 'source' => 'product_movement'];

    $agg = static function (string $group) use ($from, $to): array {
        return Db::rows("SELECT $group AS k,
                                SUM(CASE WHEN movement_type = 'PRODUCTION' THEN quantity ELSE 0 END) prod,
                                SUM(CASE WHEN movement_type = 'WASTE'      THEN quantity ELSE 0 END) jete
                           FROM product_movement
                          WHERE created_at >= ? AND created_at < ?
                            AND movement_type IN ('PRODUCTION','WASTE')
                       GROUP BY k", [$from, $to]);
    };

    try {
        $noms = [];
        foreach (Db::rows('SELECT id, name FROM shops') as $s) { $noms[(int) $s['id']] = (string) $s['name']; }
        foreach ($agg('id_shop') as $r) {
            $p = (float) $r['prod']; $j = (float) $r['jete']; $den = $p + $j;
            $out['magasins'][] = ['shopId' => (string) (int) $r['k'],
                'magasin' => $noms[(int) $r['k']] ?? ('Magasin ' . (int) $r['k']),
                'produit' => (int) round($p), 'jete' => (int) round($j),
                'taux' => $den > 0 ? round($j / $den, 4) : null];
            $out['reseau']['produit'] += (int) round($p);
            $out['reseau']['jete']    += (int) round($j);
        }
        usort($out['magasins'], fn($a, $b) => ($b['taux'] ?? -1) <=> ($a['taux'] ?? -1));

        $pn = [];
        try {
            foreach (Db::rows('SELECT id, name FROM product') as $p) { $pn[(int) $p['id']] = (string) $p['name']; }
        } catch (PDOException $e) { /* noms indisponibles */ }
        foreach ($agg('id_product') as $r) {
            $p = (float) $r['prod']; $j = (float) $r['jete']; $den = $p + $j;
            if ($den <= 0) { continue; }
            $out['produits'][] = ['produitId' => (string) (int) $r['k'],
                'nom' => $pn[(int) $r['k']] ?? ('#' . (int) $r['k']),
                'produit' => (int) round($p), 'jete' => (int) round($j),
                'taux' => round($j / $den, 4)];
        }
        usort($out['produits'], fn($a, $b) => $b['jete'] <=> $a['jete']);
        $out['produits'] = array_slice($out['produits'], 0, 40);

        foreach (Db::rows("SELECT reason, COUNT(*) n, SUM(quantity) q FROM product_movement
                            WHERE created_at >= ? AND created_at < ? AND movement_type = 'WASTE'
                         GROUP BY reason ORDER BY q DESC LIMIT 12", [$from, $to]) as $m) {
            $out['motifs'][] = ['motif' => (string) ($m['reason'] ?? ''), 'lignes' => (int) $m['n'],
                'quantite' => (int) round((float) $m['q'])];
        }
    } catch (PDOException $e) {
        $out['erreur'] = 'journal des mouvements indisponible';
        return $out;
    }

    $den = $out['reseau']['produit'] + $out['reseau']['jete'];
    $out['reseau']['taux'] = $den > 0 ? round($out['reseau']['jete'] / $den, 4) : null;
    return $out;
}

/**
 * Catégories produit indexées par id, avec leur groupe.
 * La liaison porte `id_category` / `id_group`, et une catégorie peut relever
 * de PLUSIEURS groupes (les boissons sont aussi du traiteur) : sans
 * regroupement, la jointure dupliquerait la catégorie autant de fois.
 * Rend null si la base partagée n'est pas là.
 */
function catalogueCategories(): ?array
{
    $sql = "SELECT c.id, c.name,
                   GROUP_CONCAT(DISTINCT g.name ORDER BY g.id SEPARATOR ' · ') AS groupe
              FROM product_category c
         LEFT JOIN product_category_group_connection k ON k.id_category = c.id
         LEFT JOIN product_category_group g ON g.id = k.id_group
          GROUP BY c.id, c.name";
    try {
        $rows = Db::rows($sql);
    } catch (PDOException $e) {
        // Le regroupement est un confort ; la catégorie, elle, est nécessaire.
        try { $rows = Db::rows('SELECT id, name, NULL AS groupe FROM product_category'); }
        catch (PDOException $e2) { return null; }
    }
    $cat = [];
    foreach ($rows as $c) {
        $cat[(int) $c['id']] = ['nom' => (string) $c['name'],
            'groupe' => !empty($c['groupe']) ? (string) $c['groupe'] : null];
    }
    return $cat ?: null;
}

/**
 * Coût matière par référence, depuis les recettes du réseau.
 *
 * `product` ne porte aucun coût : il vit dans `recipe_cost`, rattaché à la
 * recette et non au produit. Deux natures de lignes s'y côtoient — le coût
 * de référence du réseau (id_shop = 0, price_type « suggested ») et le coût
 * recalculé par magasin. On préfère la référence réseau ; à défaut, la
 * moyenne des magasins. Les zéros sont écartés : ils signifient « pas encore
 * calculé », pas « gratuit », et les prendre pour argent comptant afficherait
 * une marge de 100 %.
 */
function catalogueCouts(): array
{
    $out = [];
    try {
        // Le rendement divise le coût quand la recette en déclare un. Mesuré
        // sur la base : il vaut 1,00 partout aujourd'hui, la division ne change
        // donc aucun chiffre — elle protège d'une recette future au rendement
        // multiple. Les coûts aberrants constatés (un cannelloni à 734 € de
        // matière pour 7,50 € de vente) ne viennent PAS de là : ce sont des
        // recettes mal chiffrées en amont. Seul le contrôle de vraisemblance
        // ci-dessous les empêche de nourrir le score.
        $rows = Db::rows("SELECT p.id AS pid, r.yield_quantity AS rendement,
                                 AVG(CASE WHEN rc.id_shop = 0 AND rc.calculated_cost_net > 0
                                          THEN rc.calculated_cost_net END) AS reseau,
                                 AVG(CASE WHEN rc.id_shop > 0 AND rc.calculated_cost_net > 0
                                          THEN rc.calculated_cost_net END) AS magasins
                            FROM product p
                            JOIN recipe_cost rc ON rc.id_recipe = p.id_recipe
                       LEFT JOIN product_recipe r ON r.id = p.id_recipe
                           WHERE p.id_recipe IS NOT NULL AND p.is_active = 1
                        GROUP BY p.id, r.yield_quantity");
    } catch (PDOException $e) { return []; }
    foreach ($rows as $r) {
        $res = $r['reseau'] !== null ? (float) $r['reseau'] : null;
        $mag = $r['magasins'] !== null ? (float) $r['magasins'] : null;
        $v = $res ?? $mag;
        if ($v === null || $v <= 0) { continue; }
        $rend = $r['rendement'] !== null ? (float) $r['rendement'] : 1.0;
        if ($rend > 0) { $v /= $rend; }
        $out[(int) $r['pid']] = ['mat' => round($v, 3), 'rendement' => $rend,
            'source' => $res !== null ? 'recette réseau' : 'moyenne magasins'];
    }
    return $out;
}

/**
 * Un coût matière est-il exploitable pour calculer une marge ?
 *
 * Le seuil bas est un réglage (`production.coutRatioMin`, 5 % par défaut) et
 * non une constante cachée : c'est un jugement métier, il doit pouvoir se
 * discuter. Un coût nul ou négatif n'est jamais crédible.
 */
function coutVraisemblable(?float $mat, ?float $prix): bool
{
    if ($mat === null || $prix === null || $prix <= 0) { return true; }  // rien à juger
    if ($mat <= 0 || $mat >= $prix) { return false; }
    $p = setting('production', []);
    $min = (is_array($p) && isset($p['coutRatioMin'])) ? (float) $p['coutRatioMin'] : 0.05;
    if ($min <= 0) { return true; }
    return ($mat / $prix) >= $min;
}

/** Prix de vente réellement pratiqué, moyenne réseau (`shop_product`). */
function cataloguePrix(): array
{
    $out = [];
    try {
        $rows = Db::rows('SELECT id_product, AVG(portion_price) prix
                            FROM shop_product WHERE portion_price > 0 GROUP BY id_product');
    } catch (PDOException $e) { return []; }
    foreach ($rows as $r) { $out[(int) $r['id_product']] = round((float) $r['prix'], 2); }
    return $out;
}

/**
 * Lecture du catalogue réel dans la base partagée.
 * Rend null — et non un tableau vide — si les tables ne sont pas là : un vide
 * se confondrait avec « catalogue sans produit » et masquerait la panne.
 */
function ep_prod_catalogue_reel(array $enrich, array $parRef, array $plano): ?array
{
    // Catégorie + groupe. La liaison passe par une table dédiée ; si elle
    // manque, on garde la catégorie et on perd seulement le regroupement.
    $cat = catalogueCategories();
    if ($cat === null) { return null; }

    // Gammes saisonnières : plusieurs périodes possibles par référence.
    $per = [];
    try {
        foreach (Db::rows('SELECT k.id_product, p.name
                             FROM product_availability_period_connection k
                             JOIN product_availability_period p ON p.id = k.id_period
                            WHERE p.is_active = 1') as $r) {
            $per[(int) $r['id_product']][] = (string) $r['name'];
        }
    } catch (PDOException $e) { /* sans gamme : le produit reste permanent */ }

    try {
        $prods = Db::rows('SELECT id, name, id_category, id_recipe, is_active,
                                  suggested_sale_price, expected_margin, shelf_life_minutes,
                                  is_prepared_before_sales, single_weight, nutriscore, allergene
                             FROM product WHERE is_active = 1 ORDER BY id_category, name');
    } catch (PDOException $e) { return null; }
    if (!$prods) { return null; }

    $couts = catalogueCouts();
    $prixR = cataloguePrix();
    $out = [];
    foreach ($prods as $p) {
        $pid = (int) $p['id'];
        $c   = $cat[(int) $p['id_category']] ?? null;
        $e   = $enrich[$pid] ?? ($parRef[(string) $pid] ?? null);
        $ref = $e !== null ? (string) $e['ref'] : (string) $pid;
        $pl  = $plano[$ref] ?? null;

        // Prix : la saisie réseau prime, puis le prix réellement pratiqué en
        // boutique (`shop_product`), puis seulement le prix conseillé de la
        // fiche — qui vaut 1,00 partout, donc ne veut rien dire.
        $prix = $e !== null && $e['prix'] !== null ? (float) $e['prix'] : null;
        $prixSrc = $prix !== null ? 'réseau' : null;
        if ($prix === null && isset($prixR[$pid])) { $prix = $prixR[$pid]; $prixSrc = 'boutiques'; }
        if ($prix === null && (float) $p['suggested_sale_price'] > 0) {
            $prix = (float) $p['suggested_sale_price']; $prixSrc = 'fiche';
        }
        // Coût matière : la saisie cockpit prime, sinon la recette du réseau.
        $mat = $e !== null && $e['mat'] !== null ? (float) $e['mat'] : null;
        $matSrc = $mat !== null ? 'saisi' : null;
        if ($mat === null && isset($couts[$pid])) {
            $mat = $couts[$pid]['mat']; $matSrc = $couts[$pid]['source'];
        }
        // Vraisemblance du coût. Au-dessus du prix, la recette est mal
        // chiffrée ; très en dessous, elle est incomplète — un granola à
        // 0,01 € de matière pour 8,95 € donne 99,9 % de marge et hisserait la
        // référence en tête du critère. Les deux erreurs se valent, on écarte
        // les deux. On MONTRE le coût malgré tout : c'est ce qui permet de le
        // corriger à la source.
        $matFiable = coutVraisemblable($mat, $prix);

        $dlv = $e !== null && (int) $e['dlv'] > 0 ? (int) $e['dlv'] : null;
        if ($dlv === null && (int) $p['shelf_life_minutes'] > 0) {
            $dlv = (int) round(((int) $p['shelf_life_minutes']) / 60);
        }

        $out[] = [
            'ref' => $ref, 'pwaId' => $pid, 'nom' => (string) $p['name'],
            'categorie' => $c['nom'] ?? '', 'groupe' => $c['groupe'] ?? null,
            'categorieId' => (int) $p['id_category'],
            'prep'    => $e ? (int) $e['prep'] : 0,
            'cuisson' => $e ? (int) $e['cuisson'] : 0,
            'fin'     => $e ? (int) $e['fin'] : 0,
            'bmin'    => $e ? (int) $e['bmin'] : 0,
            'bmult'   => $e ? (int) $e['bmult'] : 1,
            'four'    => $e ? (int) $e['four'] : 0,
            'dlv' => $dlv ?? 0, 'mat' => $mat, 'prix' => $prix,
            'matSource' => $matSrc, 'prixSource' => $prixSrc, 'matFiable' => $matFiable,
            'marge'    => ($matFiable && $mat !== null && $prix !== null) ? round($prix - $mat, 3) : null,
            'margePct' => ($matFiable && $mat !== null && $prix > 0) ? round(($prix - $mat) / $prix, 4) : null,
            'margeAttendue' => $p['expected_margin'] !== null && (float) $p['expected_margin'] > 0
                ? round((float) $p['expected_margin'], 2) : null,
            'must'   => $e ? (bool) $e['must'] : false,
            'qmin'   => $e ? (int) $e['qmin'] : 0,
            'profil' => $e ? (string) $e['profil'] : '',
            'periods' => $per[$pid] ?? [],
            'recetteId' => $p['id_recipe'] !== null ? (int) $p['id_recipe'] : null,
            'prepare'   => (int) $p['is_prepared_before_sales'] === 1,
            'poids'     => (int) $p['single_weight'] ?: null,
            'parametre' => $e !== null,   // la fiche de production est-elle remplie ?
            'zone'   => $pl ? $pl['zone'] : null,
            'meuble' => $pl ? $pl['meuble'] : null,
            'niveau' => $pl ? $pl['niveau'] : null,
            'slot'   => $pl && $pl['slot'] !== null ? (int) $pl['slot'] : null,
        ];
    }
    return $out;
}

/**
 * GET /production/categories — catégories produit du réseau.
 *
 * Source : API du panel (/product-categories). Repli sur les tables partagées
 * (product_category ⨝ product_category_group) si l'API n'est pas configurée —
 * les deux existent, autant ne pas dépendre d'une seule.
 *
 * `debug=1` rend les clés brutes : la forme n'est pas documentée ici, et un
 * mapping deviné produirait un référentiel faux sans erreur visible.
 */
function ep_prod_categories(): array
{
    $debug = !empty($_GET['debug']);
    $out = ['source' => null, 'categories' => [], 'erreur' => null];

    if (PanelApi::configured()) {
        $rows = PanelApi::productCategories();
        if ($rows) {
            $out['source'] = 'api';
            $out['chemin'] = PanelApi::$lastPath;
            if ($debug) { $out['clesBrut'] = array_slice(array_keys($rows[0]), 0, 25); $out['premier'] = $rows[0]; }
            foreach ($rows as $c) {
                $id = null;
                foreach (['id', 'id_category', 'category_id'] as $k) {
                    if (isset($c[$k]) && is_numeric($c[$k])) { $id = (int) $c[$k]; break; }
                }
                $nom = '';
                foreach (['name', 'category_name', 'label', 'title', 'nom'] as $k) {
                    if (!empty($c[$k]) && is_string($c[$k])) { $nom = trim($c[$k]); break; }
                }
                if ($nom === '') { continue; }
                $grp = '';
                foreach (['group_name', 'category_group_name', 'group', 'parent_name'] as $k) {
                    if (!empty($c[$k]) && is_string($c[$k])) { $grp = trim($c[$k]); break; }
                }
                $out['categories'][] = ['id' => $id, 'nom' => $nom, 'groupe' => $grp !== '' ? $grp : null];
            }
            if ($out['categories']) {
                // L'API rend les catégories SANS leur groupe : mesuré en ligne,
                // 81 sur 81 arrivaient orphelines. Le rattachement n'existe que
                // dans la base (product_category_group_connection). On garde
                // donc les intitulés de l'API, qui font foi, et on va chercher
                // le groupe là où il se trouve — plutôt que de livrer un arbre
                // sans branches.
                $sans = 0;
                foreach ($out['categories'] as $c) { if ($c['groupe'] === null) { $sans++; } }
                if ($sans > 0) {
                    $ref = catalogueCategories();
                    if ($ref !== null) {
                        $repris = 0;
                        foreach ($out['categories'] as &$c) {
                            if ($c['groupe'] === null && $c['id'] !== null && !empty($ref[$c['id']]['groupe'])) {
                                $c['groupe'] = $ref[$c['id']]['groupe'];
                                $repris++;
                            }
                        }
                        unset($c);
                        if ($repris > 0) { $out['source'] = 'api + groupes atelierby_db'; }
                    }
                }
                return $out;
            }
        }
        $out['erreur'] = PanelApi::$lastError;
    }

    // Repli base partagée. `catalogueCategories()` porte la jointure correcte
    // (product_category_group_connection : id_category / id_group) et regroupe
    // les catégories rattachées à plusieurs groupes, qui sinon apparaîtraient
    // en double.
    if (!$out['categories']) {
        $cat = catalogueCategories();
        if ($cat !== null) {
            foreach ($cat as $id => $c) {
                $out['categories'][] = ['id' => $id, 'nom' => $c['nom'], 'groupe' => $c['groupe']];
            }
            usort($out['categories'], function ($a, $b) {
                return [$a['groupe'] === null, (string) $a['groupe'], $a['id']]
                   <=> [$b['groupe'] === null, (string) $b['groupe'], $b['id']];
            });
            $out['source'] = 'atelierby_db';
        }
    }
    return $out;
}

/**
 * Références d'une catégorie, pour ouvrir une branche de l'arbre produit.
 *
 * L'API donne la liste faisant foi ; le coût matière, la marge et les
 * paramètres de production viennent de chez nous. Repli sur la base partagée,
 * qui porte le même rattachement (`product.id_category`).
 */
function ep_prod_categorie_produits(): array
{
    $cid = (int) ($_GET['id'] ?? 0);
    if ($cid <= 0) { http_response_code(400); return ['error' => 'catégorie requise']; }

    $cats = catalogueCategories();
    $out = ['categorieId' => $cid, 'categorie' => $cats[$cid]['nom'] ?? null,
        'groupe' => $cats[$cid]['groupe'] ?? null,
        'source' => null, 'chemin' => null, 'produits' => [], 'erreur' => null];

    $couts = catalogueCouts();
    $prixR = cataloguePrix();
    $enrich = [];
    try {
        foreach (Db::rows('SELECT pwa_id, mat, prix, must FROM ceo_prod_product WHERE pwa_id IS NOT NULL') as $r) {
            $enrich[(int) $r['pwa_id']] = $r;
        }
    } catch (PDOException $e) { /* référentiel de production absent */ }

    // Assemble une ligne à partir d'un id et d'un nom, d'où qu'ils viennent :
    // la provenance change, la forme rendue à l'écran ne doit pas.
    $ligne = function (int $pid, string $nom) use ($couts, $prixR, $enrich): array {
        $e = $enrich[$pid] ?? null;
        $prix = $e !== null && $e['prix'] !== null ? (float) $e['prix'] : ($prixR[$pid] ?? null);
        $mat  = $e !== null && $e['mat'] !== null ? (float) $e['mat'] : ($couts[$pid]['mat'] ?? null);
        $ok   = coutVraisemblable($mat, $prix);
        return ['id' => (string) $pid, 'nom' => $nom, 'prix' => $prix, 'mat' => $mat,
            'matFiable' => $ok,
            'margePct' => ($ok && $mat !== null && $prix > 0) ? round(($prix - $mat) / $prix, 4) : null,
            'must' => $e !== null ? (bool) $e['must'] : false];
    };

    if (PanelApi::configured()) {
        $rows = PanelApi::categoryProducts($cid);
        if ($rows) {
            $out['source'] = 'api';
            $out['chemin'] = PanelApi::$lastPath;
            foreach ($rows as $p) {
                $pid = 0;
                foreach (['id', 'id_product', 'product_id'] as $k) {
                    if (isset($p[$k]) && is_numeric($p[$k])) { $pid = (int) $p[$k]; break; }
                }
                $nom = '';
                foreach (['name', 'product_name', 'label', 'title', 'nom'] as $k) {
                    if (!empty($p[$k]) && is_string($p[$k])) { $nom = trim($p[$k]); break; }
                }
                if ($pid <= 0 || $nom === '') { continue; }
                $out['produits'][] = $ligne($pid, $nom);
            }
            if ($out['produits']) { return $out; }
        }
        $out['erreur'] = PanelApi::$lastError;
    }

    try {
        foreach (Db::rows('SELECT id, name FROM product WHERE id_category = ? AND is_active = 1 ORDER BY name',
            [$cid]) as $p) {
            $out['produits'][] = $ligne((int) $p['id'], (string) $p['name']);
        }
        if ($out['produits']) { $out['source'] = 'atelierby_db'; }
    } catch (PDOException $e) {
        $out['erreur'] = $out['erreur'] ?? $e->getMessage();
    }
    return $out;
}

/**
 * Groupes de catégories du réseau (/product-category-groups).
 * C'est le premier niveau de l'arbre produit : Boulangerie, Viennoiserie,
 * Pâtisserie… Les catégories s'y rattachent par une table de liaison, et une
 * catégorie peut relever de plusieurs groupes.
 */
function ep_prod_groupes(): array
{
    $debug = !empty($_GET['debug']);
    $out = ['source' => null, 'groupes' => [], 'erreur' => null];

    if (PanelApi::configured()) {
        $rows = PanelApi::productCategoryGroups();
        if ($rows) {
            $out['source'] = 'api';
            $out['chemin'] = PanelApi::$lastPath;
            if ($debug) { $out['clesBrut'] = array_slice(array_keys($rows[0]), 0, 25); $out['premier'] = $rows[0]; }
            foreach ($rows as $g) {
                $id = null;
                foreach (['id', 'id_group', 'group_id', 'id_product_category_group'] as $k) {
                    if (isset($g[$k]) && is_numeric($g[$k])) { $id = (int) $g[$k]; break; }
                }
                $nom = '';
                foreach (['name', 'group_name', 'category_group_name', 'label', 'title', 'nom'] as $k) {
                    if (!empty($g[$k]) && is_string($g[$k])) { $nom = trim($g[$k]); break; }
                }
                if ($nom === '') { continue; }
                $out['groupes'][] = ['id' => $id, 'nom' => $nom];
            }
            if ($out['groupes']) { return $out; }
        }
        $out['erreur'] = PanelApi::$lastError;
    }

    // Repli base partagée, avec le nombre de catégories rattachées : un groupe
    // vide n'est pas une erreur, mais il ne mérite pas d'entrée dans un filtre.
    try {
        foreach (Db::rows('SELECT g.id, g.name, COUNT(k.id_category) n
                             FROM product_category_group g
                        LEFT JOIN product_category_group_connection k ON k.id_group = g.id
                         GROUP BY g.id, g.name ORDER BY g.name') as $g) {
            $out['groupes'][] = ['id' => (int) $g['id'], 'nom' => (string) $g['name'],
                'categories' => (int) $g['n']];
        }
        if ($out['groupes']) { $out['source'] = 'atelierby_db'; }
    } catch (PDOException $e) {
        $out['erreur'] = $out['erreur'] ?? $e->getMessage();
    }
    return $out;
}

/** GET /production/params — réglages du moteur de production. */
function ep_prod_params(): array
{
    $p = setting('production', []);
    return is_array($p) ? $p : [];
}

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

/**
 * GET /stores/etp?annees=2025,2026 — ETP réel par boutique et par mois.
 *
 * L'écran Marge & coûts déduisait l'effectif du chiffre d'affaires
 * (max(3, ca/14200)) : un ETP inventé, qui déclenchait pourtant une alerte de
 * dimensionnement d'équipe. On le calcule ici depuis le planning réel :
 * somme des heures planifiées du mois ÷ 168 = 1 ETP (règle du réseau).
 *
 * Un seul appel par boutique. Seules des heures agrégées sont conservées — le
 * flux porte des données personnelles (nom, téléphone) qui n'ont pas à entrer
 * dans le cockpit.
 */
function ep_stores_etp(): array
{
    $annees = array_map('intval', explode(',', $_GET['annees'] ?? date('Y')));
    $out = [];
    if (!PanelApi::configured()) { return $out; }
    try {
        $shops = Db::rows('SELECT id FROM shops WHERE active = 1');
    } catch (PDOException $e) { return $out; }

    $HEURES_ETP = (float) setting('heuresEtpMois', 168);
    foreach ($shops as $sh) {
        $sid = (int) $sh['id'];
        $parMois = [];
        foreach (PanelApi::shopSchedule($sid) as $c) {
            $d = (string) ($c['work_date'] ?? '');
            if (!preg_match('/^(\d{4})-(\d{2})/', $d, $m)) { continue; }
            $an = (int) $m[1];
            if (!in_array($an, $annees, true)) { continue; }
            $deb = strtotime('1970-01-01 ' . (string) ($c['start_hour'] ?? ''));
            $fin = strtotime('1970-01-01 ' . (string) ($c['end_hour'] ?? ''));
            if ($deb === false || $fin === false) { continue; }
            // Un créneau qui finit avant de commencer passe minuit : sans ça,
            // une nuit compterait des heures négatives.
            if ($fin <= $deb) { $fin += 86400; }
            $h = ($fin - $deb) / 3600;
            if ($h <= 0 || $h > 24) { continue; }
            $k = $an . '-' . (int) $m[2];
            $parMois[$k] = ($parMois[$k] ?? 0) + $h;
        }
        foreach ($parMois as $k => $h) {
            [$an, $mo] = array_map('intval', explode('-', $k));
            $out[] = ['storeId' => (string) $sid, 'annee' => $an, 'mois' => $mo,
                'heures' => round($h, 1), 'etp' => $HEURES_ETP > 0 ? round($h / $HEURES_ETP, 2) : null];
        }
    }
    return $out;
}

function ep_budgets(): array
{
    $exercice = (int) ($_GET['exercice'] ?? setting('exercice', (int) date('Y')));
    $slugByTag = levierSlugByTag();
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_shop_budget WHERE fiscal_year = ?', [$exercice]) as $b) {
        $sid = $b['shop_id'];
        // Mois encodés = mois dont le BUDGET est saisi. On comptait les mois
        // ayant un `revenue` dans ceo_shop_month_perf — or le réel vient
        // désormais du panel (mac_shop_monthly_pnl / transaction) et cette
        // colonne reste vide : le compteur affichait « 0 / 12 » même après une
        // saisie complète, laissant croire que l'encodage n'avait rien gardé.
        $enc = Db::row('SELECT COUNT(*) n, MAX(encoded_at) last FROM ceo_shop_month_perf
                        WHERE shop_id = ? AND year = ? AND revenue_budget IS NOT NULL AND revenue_budget > 0',
            [$sid, $exercice]);
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

/**
 * GET /referentiels/roles — rôles disponibles, lus dans `atelierby_db`.
 *
 * Le rôle affiché sur un compte ne doit pas être un texte libre : il vient du
 * référentiel du panel (`position`), pour que « Consultant réseau » désigne la
 * même chose des deux côtés. Le schéma de `position` variant, la colonne du
 * libellé est détectée comme le fait le panel plutôt que supposée.
 *
 * Repli, dans l'ordre : `position` → rôles réellement portés par les comptes
 * actifs (user_membership) → liste vide (l'écran laisse alors le champ libre).
 */
function ep_roles(): array
{
    // 1) Référentiel des positions du panel.
    try {
        $cols = array_map(fn ($r) => (string) $r['COLUMN_NAME'],
            Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'position'"));
        if ($cols !== []) {
            $lower = array_map('strtolower', $cols);
            $nameCol = null;
            foreach (['name', 'label', 'title', 'nom', 'libelle'] as $cand) {
                $i = array_search($cand, $lower, true);
                if ($i !== false) { $nameCol = $cols[$i]; break; }
            }
            if ($nameCol === null) {
                foreach ($cols as $c) { if (stripos($c, 'name') !== false) { $nameCol = $c; break; } }
            }
            if ($nameCol !== null) {
                $where = '';
                // Beaucoup de schémas portent un drapeau d'activité : on ne
                // propose pas un rôle désactivé.
                foreach (['active', 'is_active', 'enabled'] as $a) {
                    $i = array_search($a, $lower, true);
                    if ($i !== false) { $where = ' WHERE `' . $cols[$i] . '` = 1'; break; }
                }
                $out = [];
                foreach (Db::rows("SELECT DISTINCT `$nameCol` AS nom FROM `position`$where ORDER BY `$nameCol` LIMIT 200") as $r) {
                    $n = trim((string) $r['nom']);
                    if ($n !== '') { $out[] = $n; }
                }
                if ($out !== []) { return ['source' => 'position', 'roles' => $out]; }
            }
        }
    } catch (PDOException $e) { /* table absente : on tente le repli */ }

    // 2) À défaut, les rôles réellement portés par les comptes actifs.
    try {
        $out = [];
        foreach (Db::rows("SELECT DISTINCT app, scope_type FROM user_membership WHERE is_active = 1") as $r) {
            $app = trim((string) ($r['app'] ?? ''));
            if ($app === '') { continue; }
            $lib = ucfirst(strtolower($app)) . ((string) ($r['scope_type'] ?? '') === 'SHOP' ? ' boutique' : ' réseau');
            if (!in_array($lib, $out, true)) { $out[] = $lib; }
        }
        sort($out);
        if ($out !== []) { return ['source' => 'user_membership', 'roles' => $out]; }
    } catch (PDOException $e) { /* rien */ }

    return ['source' => null, 'roles' => []];
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

        // Mémoriser la période réellement servie : la modale de détail doit
        // interroger la même fenêtre que le tableau.
        try {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['periodeProduits', json_encode(substr($from, 0, 7))]);
        } catch (PDOException $eP) { /* sans importance */ }

        // PERTES par référence — API du panel (/shops/{id}/products/waste), la
        // seule source : la base partagée ne connaît que les ventes. On agrège
        // les quantités jetées et vendues sur TOUTES les boutiques pour la même
        // période que le volume, puis on en tire un taux réseau. Le volet
        // rapporte aussi la catégorie réelle et le motif principal de rebut.
        $perteVol = []; $catApi = []; $motif = [];
        if (PanelApi::configured()) {
            $dFrom = substr($from, 0, 10);
            $dTo   = date('Y-m-d', strtotime($to . ' -1 day'));
            try {
                $shopIds = array_map(fn ($r) => (int) $r['id'], Db::rows('SELECT id FROM shops WHERE active = 1'));
            } catch (PDOException $eS) { $shopIds = []; }
            foreach ($shopIds as $sid) {
                foreach (PanelApi::shopWaste($sid, $dFrom, $dTo) as $w) {
                    $pid = (int) ($w['id_product'] ?? 0);
                    if ($pid <= 0) { continue; }
                    // `waste_qty` est bien propre au magasin (il diffère d'un
                    // magasin à l'autre) et s'additionne. `sold_qty`, lui, est
                    // rendu IDENTIQUE pour tous les magasins : c'est une valeur
                    // réseau. L'additionner multipliait les ventes par le nombre
                    // de magasins et écrasait le taux. On l'ignore : le volume
                    // vendu vient de notre propre agrégat de caisse.
                    $perteVol[$pid] = ($perteVol[$pid] ?? 0) + (float) ($w['waste_qty'] ?? 0);
                    $cn = trim((string) ($w['category_name'] ?? ''));
                    if ($cn !== '' && !isset($catApi[$pid])) { $catApi[$pid] = $cn; }
                    $tr = trim((string) ($w['top_reason'] ?? ''));
                    if ($tr !== '' && !isset($motif[$pid])) { $motif[$pid] = $tr; }
                }
            }
        }

        // COÛT MATIÈRE — sans lui, « marge nette » (30 % du score) reste nulle
        // pour tout le monde et le classement se joue sur le seul volume.
        // Deux sources, dans cet ordre : les recettes du réseau
        // (product_recipe ⨝ recipe_cost, ~422 références sur 711), puis la
        // saisie du cockpit qui prime — elle corrige au cas par cas.
        // Le rapprochement se fait par identifiant de caisse UNIQUEMENT,
        // jamais par l'intitulé : deux références peuvent porter des noms
        // voisins et une marge fausse ne se voit pas.
        $cout = [];
        foreach (catalogueCouts() as $pid => $c) { $cout[$pid] = $c['mat']; }
        try {
            foreach (Db::rows('SELECT pwa_id, mat FROM ceo_prod_product WHERE pwa_id IS NOT NULL AND mat IS NOT NULL AND actif = 1') as $c) {
                $cout[(int) $c['pwa_id']] = (float) $c['mat'];
            }
        } catch (PDOException $eC) { /* référentiel absent : recettes seules */ }

        // Catégorie : le vrai catalogue (product ⨝ product_category), indexé
        // par identifiant de caisse. La table miroir sig_products porte des
        // identifiants d'un autre format (« pwp1000001 ») : la recherche par
        // id numérique n'y trouvait jamais rien, et la catégorie retombait
        // silencieusement sur « Non catégorisé » dès que l'API était muette.
        $cat = [];
        $refCat = catalogueCategories();
        if ($refCat !== null) {
            try {
                foreach (Db::rows('SELECT id, id_category FROM product WHERE is_active = 1') as $c) {
                    $k = (int) $c['id_category'];
                    if (isset($refCat[$k])) { $cat[(int) $c['id']] = $refCat[$k]['nom']; }
                }
            } catch (PDOException $eCat) { /* catalogue absent : catégorie vide */ }
        }

        return array_map(function ($r) use ($cat, $catApi, $perteVol, $motif, $cout) {
            $vol  = (float) $r['volume'];
            $prix = $vol > 0 ? round((float) $r['ca'] / $vol, 2) : null;
            $pid  = (int) $r['id_product'];
            // Taux de perte = jeté / (vendu + jeté) sur la période, en part
            // (0..1). Dénominateur = ce qui a été PRODUIT et proposé, sinon un
            // produit très jeté mais peu vendu afficherait un taux > 100 %.
            $tp = null;
            if (isset($perteVol[$pid])) {
                $den = $vol + $perteVol[$pid];      // vendu (notre agrégat) + jeté
                if ($den > 0) { $tp = round($perteVol[$pid] / $den, 4); }
            }
            return [
                'id'        => (string) $pid,
                'nom'       => ($r['nom'] !== null && $r['nom'] !== '') ? $r['nom'] : ('#' . $pid),
                // Catégorie : celle de l'API (fiable) avant le référentiel local.
                'categorie' => $catApi[$pid] ?? $cat[$pid] ?? 'Non catégorisé',
                'volume'    => (int) round($vol),
                'prix'      => $prix,
                // « Marge nette » pèse 30 % du score. Un coût mal chiffré —
                // au-dessus du prix comme quasi nul — y ferait plus de dégâts
                // que son absence, qui est déjà gérée (critère neutralisé).
                'coutUnit'  => (isset($cout[$pid]) && coutVraisemblable($cout[$pid], $prix))
                    ? $cout[$pid] : null,
                'tendVol'   => 1,
                'magasins'  => (int) $r['magasins'],
                'tauxPerte' => $tp,
                'jete'      => isset($perteVol[$pid]) ? (int) round($perteVol[$pid]) : null,
                'motifPerte' => $motif[$pid] ?? null,
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
