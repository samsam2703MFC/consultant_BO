<?php
declare(strict_types=1);

/**
 * Export de TOUTES les commandes passées aux fournisseurs de matière première
 * (SLFood, Bruyerre, Berdiff…), tous magasins confondus, en CSV.
 *
 * Source : la base partagée du panel (`material_order` + `material_order_item`),
 * la même que l'écran des livraisons (src/commandes.php). Le nom du fournisseur
 * et celui de la matière ne sont pas dans ces tables : on cherche les tables de
 * référence (`material_supplier*`, `material`/`materials`) plutôt que de les
 * deviner, et on s'en passe si elles manquent.
 *
 * En option, les réquisitions matière des magasins (flux franchisé → centrale)
 * via l'API du panel, si le compte consultant est configuré.
 *
 * Usage (sur le serveur où config/config.php existe) :
 *   php bin/export_commandes_fournisseurs.php
 *   php bin/export_commandes_fournisseurs.php --fournisseur=slfood
 *   php bin/export_commandes_fournisseurs.php --depuis=2026-01-01 --jusqua=2026-11-30
 *   php bin/export_commandes_fournisseurs.php --sortie=/tmp/export --requisitions
 *
 * Produit dans le dossier de sortie (défaut : ./export-commandes-AAAAMMJJ) :
 *   commandes_fournisseurs.csv   une ligne par commande (en-tête)
 *   lignes_commandes.csv         une ligne par article commandé
 *   requisitions.csv             (avec --requisitions) les réquisitions panel
 * Séparateur « ; » et BOM UTF-8 : s'ouvre tel quel dans Excel (FR/BE).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/panel_api.php';

$opts = getopt('', ['fournisseur:', 'depuis:', 'jusqua:', 'sortie:', 'requisitions', 'aide']);
if (isset($opts['aide'])) {
    fwrite(STDOUT, "php bin/export_commandes_fournisseurs.php [--fournisseur=nom] [--depuis=AAAA-MM-JJ] [--jusqua=AAAA-MM-JJ] [--sortie=dossier] [--requisitions]\n");
    exit(0);
}
$filtreFourn = isset($opts['fournisseur']) ? mb_strtolower(trim((string) $opts['fournisseur'])) : '';
$depuis = (string) ($opts['depuis'] ?? '');
$jusqua = (string) ($opts['jusqua'] ?? '');
foreach (['depuis' => $depuis, 'jusqua' => $jusqua] as $k => $d) {
    if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        fwrite(STDERR, "--$k attend une date AAAA-MM-JJ\n");
        exit(1);
    }
}
$sortie = rtrim((string) ($opts['sortie'] ?? ('export-commandes-' . date('Ymd'))), '/');
if (!is_dir($sortie) && !@mkdir($sortie, 0775, true) && !is_dir($sortie)) {
    fwrite(STDERR, "dossier de sortie impossible à créer : $sortie\n");
    exit(1);
}

/** Première table du schéma courant dont le nom correspond, ou null. */
function exTable(array $candidats): ?string
{
    foreach ($candidats as $like) {
        $r = Db::rows("SELECT TABLE_NAME n FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?
                        ORDER BY CHAR_LENGTH(TABLE_NAME) LIMIT 1", [$like]);
        if ($r) { return (string) $r[0]['n']; }
    }
    return null;
}

/** Colonnes d'une table, ou [] si elle n'existe pas. */
function exColonnes(string $table): array
{
    return array_map(fn ($r) => (string) $r['c'], Db::rows(
        "SELECT COLUMN_NAME c FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$table]));
}

function exPremiere(array $cols, array $candidats): ?string
{
    foreach ($candidats as $c) { if (in_array($c, $cols, true)) { return $c; } }
    return null;
}

function exCsv(string $fichier, array $lignes): int
{
    $f = fopen($fichier, 'w');
    fwrite($f, "\xEF\xBB\xBF");
    if ($lignes) {
        fputcsv($f, array_keys($lignes[0]), ';');
        foreach ($lignes as $l) {
            fputcsv($f, array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE), $l), ';');
        }
    }
    fclose($f);
    return count($lignes);
}

try {
    Db::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "base injoignable : " . $e->getMessage() . "\n(config/config.php ou COCKPIT_DB_* manquant ?)\n");
    exit(1);
}

// --- tables de référence -----------------------------------------------
$tFourn = exTable(['material\\_supplier', 'material\\_suppliers', 'material\\_supplier%']);
$tMat = exTable(['material', 'materials']);
$colsItem = exColonnes('material_order_item');
$colsOrder = exColonnes('material_order');
if ($colsOrder === []) {
    fwrite(STDERR, "table material_order absente de la base configurée\n");
    exit(1);
}
$colMatItem = exPremiere($colsItem, ['id_material', 'material_id']);
$colsMat = $tMat !== null ? exColonnes($tMat) : [];
$colNomMat = exPremiere($colsMat, ['name', 'display_name', 'label']);

// --- en-têtes de commande ----------------------------------------------
$selF = $tFourn !== null ? 'f.name AS fournisseur' : 'NULL AS fournisseur';
$joinF = $tFourn !== null ? "LEFT JOIN `$tFourn` f ON f.id = o.id_supplier" : '';
$where = []; $params = [];
if ($depuis !== '') { $where[] = 'o.order_date >= ?'; $params[] = $depuis; }
if ($jusqua !== '') { $where[] = 'o.order_date < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $jusqua; }
if ($filtreFourn !== '' && $tFourn !== null) {
    $where[] = "REPLACE(LOWER(f.name), ' ', '') LIKE ?";
    $params[] = '%' . str_replace(' ', '', $filtreFourn) . '%';
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$nbItems = $colsItem !== [] ? '(SELECT COUNT(*) FROM material_order_item i WHERE i.id_order = o.id)' : 'NULL';

$commandes = Db::rows("SELECT o.id AS id_commande, o.id_shop, s.name AS magasin,
        o.id_supplier, $selF, o.status AS statut, o.order_date AS date_commande,
        o.expected_date AS date_attendue, o.supplier_planned_delivery_date AS date_prevue_fournisseur,
        o.delivered_on AS date_livree, $nbItems AS nb_lignes
    FROM material_order o
    LEFT JOIN shops s ON s.id = o.id_shop
    $joinF
    $sqlWhere
    ORDER BY o.order_date DESC, o.id DESC", $params);

// Le reste des colonnes de material_order, sans les réécrire à la main.
if ($commandes) {
    $ids = array_column($commandes, 'id_commande');
    $brut = [];
    foreach (array_chunk($ids, 500) as $lot) {
        $ph = implode(',', array_fill(0, count($lot), '?'));
        foreach (Db::rows("SELECT * FROM material_order WHERE id IN ($ph)", $lot) as $r) { $brut[(int) $r['id']] = $r; }
    }
    $dejaLa = ['id', 'id_shop', 'id_supplier', 'status', 'order_date', 'expected_date', 'supplier_planned_delivery_date', 'delivered_on'];
    foreach ($commandes as &$c) {
        foreach ($brut[(int) $c['id_commande']] ?? [] as $k => $v) {
            if (!in_array($k, $dejaLa, true)) { $c['mo_' . $k] = $v; }
        }
    }
    unset($c);
}

// --- lignes de commande --------------------------------------------------
$lignes = [];
if ($commandes && $colsItem !== []) {
    $parId = [];
    foreach ($commandes as $c) { $parId[(int) $c['id_commande']] = $c; }
    $selMat = ($tMat !== null && $colMatItem !== null && $colNomMat !== null) ? ", m.`$colNomMat` AS matiere" : ', NULL AS matiere';
    $joinMat = ($tMat !== null && $colMatItem !== null && $colNomMat !== null) ? "LEFT JOIN `$tMat` m ON m.id = i.`$colMatItem`" : '';
    foreach (array_chunk(array_keys($parId), 500) as $lot) {
        $ph = implode(',', array_fill(0, count($lot), '?'));
        foreach (Db::rows("SELECT i.* $selMat FROM material_order_item i $joinMat WHERE i.id_order IN ($ph) ORDER BY i.id_order, i.id", $lot) as $it) {
            $c = $parId[(int) $it['id_order']];
            $lignes[] = ['id_commande' => $c['id_commande'], 'date_commande' => $c['date_commande'],
                'magasin' => $c['magasin'], 'fournisseur' => $c['fournisseur'],
                'matiere' => $it['matiere']] + array_diff_key($it, ['matiere' => 1]);
        }
    }
}

$n1 = exCsv("$sortie/commandes_fournisseurs.csv", $commandes);
$n2 = exCsv("$sortie/lignes_commandes.csv", $lignes);
fwrite(STDOUT, "commandes fournisseurs : $n1 → $sortie/commandes_fournisseurs.csv\n");
fwrite(STDOUT, "lignes de commande     : $n2 → $sortie/lignes_commandes.csv\n");
if ($tFourn === null) { fwrite(STDOUT, "(table material_supplier* introuvable : noms de fournisseur vides, filtre --fournisseur ignoré)\n"); }
if ($commandes) {
    $parFourn = [];
    foreach ($commandes as $c) { $k = $c['fournisseur'] ?? ('#' . $c['id_supplier']); $parFourn[$k] = ($parFourn[$k] ?? 0) + 1; }
    arsort($parFourn);
    fwrite(STDOUT, "par fournisseur :\n");
    foreach ($parFourn as $k => $n) { fwrite(STDOUT, sprintf("  %-30s %d\n", $k, $n)); }
    $dates = array_filter(array_column($commandes, 'date_commande'));
    if ($dates) { fwrite(STDOUT, 'période : ' . min($dates) . ' → ' . max($dates) . "\n"); }
}

// --- réquisitions (API panel), en option ---------------------------------
if (isset($opts['requisitions'])) {
    // PanelApi lit ses identifiants via setting() (ceo_app_setting), défini ici.
    require_once __DIR__ . '/../src/endpoints.php';
    try {
        $configure = PanelApi::configured();
    } catch (Throwable $e) {
        $configure = false;
    }
    if (!$configure) {
        fwrite(STDOUT, "réquisitions : compte consultant du panel non configuré — ignorées\n");
        exit(0);
    }
    $shops = Db::rows('SELECT id, name FROM shops ORDER BY id');
    $chemins = [];
    foreach ($shops as $s) { $chemins[(int) $s['id']] = '/shops/' . (int) $s['id'] . '/material-requisitions'; }
    $noms = array_column($shops, 'name', 'id');
    $reqs = [];
    foreach (PanelApi::getParallele($chemins) as $sid => $res) {
        $liste = is_array($res) ? (array_is_list($res) ? $res : ($res['data'] ?? $res['items'] ?? [])) : [];
        foreach ((array) $liste as $r) {
            if (!is_array($r)) { continue; }
            $debut = (string) ($r['beginning_of_period'] ?? '');
            if ($depuis !== '' && $debut !== '' && substr($debut, 0, 10) < $depuis) { continue; }
            if ($jusqua !== '' && $debut !== '' && substr($debut, 0, 10) > $jusqua) { continue; }
            $reqs[] = ['id' => $r['id'] ?? null, 'id_shop' => $sid, 'magasin' => $noms[$sid] ?? null,
                'debut_periode' => $debut, 'jours' => $r['requisition_period_days'] ?? null,
                'type' => $r['type_of_requisition'] ?? null, 'statut' => $r['status'] ?? null,
                'valeur_estimee' => $r['estimated_value'] ?? null,
                'fournisseurs' => $r['suppliers'] ?? null,
                'par' => $r['employee']['display_name'] ?? null];
        }
    }
    $n3 = exCsv("$sortie/requisitions.csv", $reqs);
    fwrite(STDOUT, "réquisitions panel     : $n3 → $sortie/requisitions.csv\n");
}
