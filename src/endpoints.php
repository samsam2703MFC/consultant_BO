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
