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
        'aujourdhui'       => setting('aujourdhui'),
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
    return array_map(fn ($r) => [
        'id' => $r['id'], 'code' => $r['code'], 'nom' => $r['name'], 'fr' => $r['franchisee'],
        'zone' => $r['zone'], 'status' => $r['status'],
        'opened' => $r['opened_on'] ? substr($r['opened_on'], 0, 7) : null,
        'valT' => $r['valuation_target'] !== null ? (float) $r['valuation_target'] : null,
        'panier' => $r['basket_ref'] !== null ? (float) $r['basket_ref'] : null,
        'pwaId' => isset($r['pwa_shop_id']) && $r['pwa_shop_id'] !== null ? (int) $r['pwa_shop_id'] : null,
    ], Db::rows('SELECT * FROM ceo_shop ORDER BY id'));
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
    $magasins = array_map(fn ($r) => ['id' => $r['id'], 'nom' => $r['name'], 'pwaId' => $r['pwa_shop_id'] !== null ? (int) $r['pwa_shop_id'] : null],
        Db::rows("SELECT id, name, pwa_shop_id FROM ceo_shop WHERE status = 'Ouvert' ORDER BY id"));
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

function ep_perf(): array
{
    $annees = array_map('intval', explode(',', $_GET['annees'] ?? '2025,2026'));
    $in = implode(',', array_fill(0, count($annees), '?'));
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
 * GET /referentiels/facebook-regles — le pack de règles de l'agent de contrôle.
 *
 * Le front n'a aucune règle en dur : il affiche ce que renvoie cet endpoint
 * (libellés, gravités, aide, actif/inactif) et repasse le pack entier par
 * `PUT /parametres/fbControle` quand le CEO désactive une règle. Les cinq
 * niveaux de notes ne sont PAS recopiés ici : ils viennent de
 * `meta.signalement`, une seule échelle pour tout le réseau.
 */
function ep_fb_regles(): array
{
    $r = fbRegles();
    return [
        'seuil'    => fbSeuil(),
        'familles' => $r['familles'] ?? [],
        'regles'   => array_map(fn ($x) => [
            'code' => $x['code'], 'nom' => $x['nom'], 'famille' => $x['famille'], 'type' => $x['type'],
            'gravite' => (int) $x['gravite'], 'actif' => !empty($x['actif']), 'aide' => $x['aide'] ?? '',
        ], $r['regles'] ?? []),
    ];
}

/** GET /facebook/posts — les posts soumis, leur contrôle et leur décision. */
function ep_fb_posts(): array
{
    $out = [];
    $rows = Db::rows(
        'SELECT p.*, s.name AS shop_name FROM ceo_fb_post p LEFT JOIN ceo_shop s ON s.id = p.shop_id
          ORDER BY COALESCE(p.planned_at, p.submitted_at) DESC, p.id DESC LIMIT 300');
    foreach ($rows as $p) {
        $out[] = [
            'id' => $p['id'], 'magasinId' => $p['shop_id'], 'magasin' => $p['shop_name'],
            'auteur' => $p['author'], 'format' => $p['format'],
            'message' => $p['message'], 'lien' => $p['link'],
            'medias' => $p['medias_json'] !== null ? json_decode($p['medias_json'], true) : [],
            'publierLe' => $p['planned_at'] !== null ? substr($p['planned_at'], 0, 16) : null,
            'soumisLe' => substr($p['submitted_at'], 0, 16),
            'statut' => $p['status'],
            // Ce que l'agent propose — jamais confondu avec ce que le CEO décide.
            'agent' => [
                'note' => $p['agent_note'] !== null ? (int) $p['agent_note'] : null,
                'resume' => $p['agent_summary'],
                'le' => $p['agent_ran_at'] !== null ? substr($p['agent_ran_at'], 0, 16) : null,
                'passages' => (int) $p['agent_runs'],
            ],
            'ecarts' => fbEcartsDuPost($p['id']),
            'decision' => [
                'note' => $p['note'] !== null ? (int) $p['note'] : null,
                'famille' => $p['decision_famille'], 'type' => $p['decision_type'],
                'commentaire' => $p['decision_comment'],
                'le' => $p['decided_at'] !== null ? substr($p['decided_at'], 0, 16) : null,
                'par' => $p['decided_by'],
            ],
            'publie' => [
                'le' => $p['published_at'] !== null ? substr($p['published_at'], 0, 16) : null,
                'fbId' => $p['fb_post_id'],
            ],
        ];
    }
    return $out;
}

/** Les écarts d'un post, du plus grave au plus léger. */
function fbEcartsDuPost(string $postId): array
{
    return array_map(fn ($e) => [
        'id' => (int) $e['id'], 'code' => $e['rule_code'], 'regle' => $e['rule_name'],
        'famille' => $e['famille'], 'type' => $e['type'], 'gravite' => (int) $e['gravite'],
        'message' => $e['message'], 'extrait' => $e['extrait'], 'statut' => $e['status'],
    ], Db::rows('SELECT * FROM ceo_fb_finding WHERE post_id = ? ORDER BY gravite DESC, id', [$postId]));
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

function ep_people(): array
{
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
    [$annee, $mois] = array_map('intval', explode('-', $periode));
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
