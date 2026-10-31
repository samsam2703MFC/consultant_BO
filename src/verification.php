<?php
declare(strict_types=1);

/**
 * Vérification — lot 1 : les recettes douteuses ou manquantes (demande du 31/10/2026).
 * Plan : docs/plan-verification.md § 3.5 ; maquette : docs/maquettes/verification/e.png.
 *
 * Chaque produit vendu doit avoir une recette complète, chiffrée et plausible : sinon l'écart
 * matière, la marge et le CA attendu sont faux. Neuf règles, appliquées magasin par magasin sur
 * les produits vendus des trente derniers jours, rangées par chiffre d'affaires concerné :
 *
 *   sans   — pas de recette : produit vendu sans id_recipe ;
 *   form   — formule sans ses produits (31/10/2026) : une formule ou une promotion vendue comme un seul article, sans
 *            recette — ses produits ne sortent pas sur le ticket et leur matière quitte le stock sans vente ; ils
 *            doivent y figurer, chacun à prix réduit (promotion BUNDLE_PROMOTION du panel : prix du lot, articles) ;
 *   vide   — recette vide : aucune ligne ;
 *   prix   — matière sans prix dans un magasin qui vend le produit ;
 *   inv    — coût invraisemblable : sous le seuil (production.coutRatioMin, 5 %) ou au-dessus du prix HT ;
 *   reseau — coût hors norme du réseau : plus de 40 % d'écart à la médiane des autres magasins ;
 *   unite  — unité suspecte : une ligne pèse plus de la moitié du coût, et ×1000 ou ÷1000 la ramène
 *            dans un coût plausible ;
 *   rend   — rendement à zéro ;
 *   port   — portion sans fraction : vendu à la portion sans part définie ;
 *   orph   — matière en stock employée par aucune recette vendue.
 *
 * Une même pièce ne porte que l'explication la plus précise : une recette vide ou une matière sans
 * prix expliquent un coût invraisemblable, qui n'est alors pas compté une seconde fois.
 *
 * Sources, lecture seule (aucune écriture au panel) :
 *  · ventes : les tickets gravés par la moisson horaire (`svP{magasin}:{jour}`, stats_ventes.php),
 *    jamais relus ici ;
 *  · /shops/{id}/products/available : id_recipe, recipe_cost_net, prix, portions, catégorie ;
 *  · /shops/{id}/recipes/{recette}/cost : les lignes de la recette (sous-recettes dépliées), lue une
 *    fois par jour et par recette (`vfRec:{recette}`) — le panel ne tient que quatre lectures de
 *    front : le cron horaire en lit un lot (VF_BUDGET_CRON), l'écran un petit (VF_BUDGET_DEMANDE) ;
 *  · /shops/{id}/materials : le prix de chaque matière dans chaque magasin (mfMatieresApi) ;
 *  · /shops/{id}/material-inventory : le stock, pour les matières jamais en recette.
 *
 * L'analyse est gardée une heure (`vfAnalyse`). Le registre `ceo_verif_recette` dit depuis quand
 * chaque recette est signalée, et quand elle a cessé de l'être (corrigée au panel).
 *
 * GET /verification/recettes[?rafraichir=1]  — la liste, la couverture, les règles ;
 * GET /verification/recettes/sonde?shop=&pid= — ce que les routes rendent (lecture seule, mesure) ;
 * GET /verification/recettes/fiche?pid=      — la recette d'un produit, ouverte au clic dans la liste.
 */

const VF_JOURS = 30;
const VF_SEC_RECETTE = 86400;       // la structure d'une recette : relue une fois par jour
const VF_SEC_ANALYSE = 3600;        // l'analyse servie telle quelle pendant une heure
const VF_SEC_STOCK = 21600;         // le stock matière d'un magasin : six heures
const VF_BUDGET_DEMANDE = 16;       // recettes lues au plus à l'ouverture de l'écran (quatre de front)
const VF_BUDGET_CRON = 120;         // recettes lues au plus par battement du cron horaire
const VF_ECART_RESEAU = 40.0;       // % d'écart à la médiane des autres magasins
const VF_PART_UNITE = 0.5;          // une ligne qui pèse plus de la moitié du coût
const VF_PROFONDEUR = 4;            // sous-recettes dépliées au plus
const VF_RELUES_ORPH = 0.95;        // part des recettes relues pour juger les matières orphelines

/** Les règles : code => [nom, explication]. L'ordre est celui des pastilles à l'écran. */
const VF_REGLES = [
    'sans'   => ['Pas de recette', 'produit vendu sans recette au panel'],
    'form'   => ['Formule sans ses produits', 'formule vendue comme un seul article : ses produits doivent sortir sur le ticket, chacun à prix réduit (promotion « bundle » du panel)'],
    'vide'   => ['Recette vide', 'la recette n’a aucune ligne'],
    'prix'   => ['Matière sans prix', 'une matière de la recette n’a pas de prix dans un magasin qui vend le produit'],
    'inv'    => ['Coût invraisemblable', 'le coût de la pièce est sous le seuil ou au-dessus du prix HT'],
    'reseau' => ['Coût hors norme du réseau', 'plus de 40 % d’écart à la médiane des autres magasins'],
    'unite'  => ['Unité suspecte', 'une ligne pèse plus de la moitié du coût, et ×1000 ou ÷1000 la ramène dans la norme'],
    'rend'   => ['Rendement à zéro', 'la recette déclare un rendement de 0'],
    'port'   => ['Portion sans fraction', 'vendu à la portion sans part définie : le coût est celui de la pièce entière'],
    'orph'   => ['Matière jamais en recette', 'en stock, employée par aucune recette vendue ces 30 jours'],
];

/** Les matières qui ne passent par aucune recette, par nature : emballages, hygiène, entretien. */
const VF_HORS_RECETTE = '/emball|hygi[eè]n|nettoy|entretien|papeter|vaisselle|jetable|consommable|non[ -]?alimentaire|packag|sachet|bo[iî]te|gobelet|serviette|couvert|paille|\bsacs?\b|etiquet|étiquet|gant|film/iu';

/** Les formules et promotions (décision du 31/10/2026 : on les garde, un interrupteur les masque) : leur catégorie ou
 *  son groupe parle de bundle, de promotion ou de formule ; ou leur nom a la forme d'une offre (« 2 Cookies »,
 *  « 2x Merveilleux », « 5 +1 | Mini-Viennoiserie », « Sandwich (10 + 5) », « Promotie - 1.5 euros »). Une portion (« 1/2 - Quiches ») n'en est pas une. */
const VF_FORMULE_CAT = '/bundle|promo|formule/iu';
const VF_FORMULE_NOM = '/^\d+\s*(?:[x×]\s*)?\p{L}|^\d+\s*\+\s*\d+|\(\s*\d+\s*\+\s*\d+\s*\)|^promoti|^formule|^menu\b/iu';

function vfEstFormule(string $nom, string $cat, string $groupe = ''): bool
{
    if (preg_match(VF_FORMULE_CAT, $cat . ' ' . $groupe) === 1) { return true; }
    return preg_match(VF_FORMULE_NOM, trim($nom)) === 1;
}

/* ---------------------------------------------------------------------------------------------
 * Petits outils
 * ------------------------------------------------------------------------------------------- */

/** Premier nombre parmi des clés. */
function vfNum(array $x, array $cles): ?float
{
    foreach ($cles as $k) { if (isset($x[$k]) && is_numeric($x[$k])) { return (float) $x[$k]; } }
    return null;
}

/** Premier texte non vide parmi des clés. */
function vfTxt(array $x, array $cles): string
{
    foreach ($cles as $k) { if (isset($x[$k]) && is_string($x[$k]) && trim($x[$k]) !== '') { return trim($x[$k]); } }
    return '';
}

function vfMediane(array $v): ?float
{
    $v = array_values(array_filter($v, static fn ($x) => $x !== null));
    if ($v === []) { return null; }
    sort($v); $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
}

/** Un montant à la française, pour les phrases : « 3,87 € », « 1 240 € ». */
function vfEur(?float $v, int $d = 2): string
{
    if ($v === null) { return '—'; }
    return str_replace(' ', "\u{00A0}", number_format($v, $d, ',', ' ')) . ' €';
}

function vfPct(?float $v, int $d = 0): string
{
    return $v === null ? '—' : number_format($v, $d, ',', ' ') . ' %';
}

/** Une quantité : « 120 g », « 2,5 kg ». */
function vfQte(?float $q, string $u): string
{
    if ($q === null) { return '—'; }
    $d = abs($q - round($q)) < 1e-9 ? 0 : (abs($q) < 10 ? 2 : 1);
    return number_format($q, $d, ',', ' ') . ($u !== '' ? ' ' . $u : '');
}

/** Lecture d'une valeur gardée : ['le' => ts, 'v' => …] ; null si absente ou plus vieille que $maxSec. */
function vfCache(string $cle, int $maxSec): ?array
{
    try { $c = setting($cle); } catch (Throwable $e) { return null; }
    if (!is_array($c) || !isset($c['le'], $c['v']) || !is_array($c['v'])) { return null; }
    if (time() - (int) $c['le'] >= $maxSec) { return null; }
    return $c;
}

function vfGarder(string $cle, array $v): void
{
    try {
        $j = json_encode(['le' => time(), 'v' => $v], JSON_UNESCAPED_UNICODE);
        if ($j !== false) { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, $j]); }
    } catch (Throwable $e) { /* sans cache : la lecture suivante recommencera */ }
}

/* ---------------------------------------------------------------------------------------------
 * Lectures
 * ------------------------------------------------------------------------------------------- */

/** Les magasins actifs : [sid => nom court]. */
function vfMagasins(): array
{
    $out = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id') as $s) {
            $out[(int) $s['id']] = (string) preg_replace('/^.* - /', '', (string) $s['name']);
        }
    } catch (Throwable $e) {
        try { foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY id') as $s) { $out[(int) $s['id']] = (string) preg_replace('/^.* - /', '', (string) $s['name']); } }
        catch (Throwable $e2) { /* sans magasins */ }
    }
    return $out;
}

/**
 * Les ventes des N derniers jours (aujourd'hui exclu), par magasin et par produit, telles que la
 * moisson horaire les a gravées : [ventes => [sid => [pid => [ca, q, nom]]], jours => [sid => jours lus],
 * du, au]. Le CA est celui des lignes de ticket (TTC, remise déduite) ; une portion compte pour son produit.
 */
function vfVentes(array $sids, int $jours): array
{
    $J = [];
    for ($i = $jours; $i >= 1; $i--) { $J[] = date('Y-m-d', strtotime('-' . $i . ' day')); }
    $out = ['ventes' => [], 'jours' => [], 'du' => $J[0], 'au' => $J[count($J) - 1], 'nJours' => $jours];
    foreach ($sids as $sid) {
        $out['ventes'][$sid] = []; $lus = 0;
        foreach ($J as $j) {
            try { $g = setting('svP' . $sid . ':' . $j); } catch (Throwable $e) { $g = null; }
            if (!is_array($g) || !is_array($g['p'] ?? null)) { continue; }
            $lus++;
            foreach ($g['p'] as $lst) {
                foreach ((array) $lst as $k => $x) {
                    $pid = (int) explode(':', (string) $k)[0];
                    if ($pid <= 0 || !is_array($x)) { continue; }
                    $v = &$out['ventes'][$sid][$pid];
                    if ($v === null) { $v = ['ca' => 0.0, 'q' => 0.0, 'nom' => preg_replace('/ — .*$/u', '', (string) ($x[0] ?? ''))]; }
                    $v['q'] += (float) ($x[1] ?? 0);
                    $v['ca'] += (float) ($x[2] ?? 0);
                    unset($v);
                }
            }
        }
        $out['jours'][$sid] = $lus;
    }
    return $out;
}

/** Le prix HT de la pièce entière d'une ligne de products/available (même lecture que l'écran des coefficients). */
function vfPrixHt(array $l): ?float
{
    $divisible = (int) ($l['is_divisible'] ?? 0) === 1;
    $taille = vfNum($l, ['portion_size']);
    $part = $divisible && $taille !== null && $taille > 0 && $taille <= 1 ? $taille : 1.0;
    $ht = vfNum($l, ['portion_price_net']);
    if ($ht === null || $ht <= 0) {
        $ttc = vfNum($l, ['portion_price_gross', 'portion_price']);
        $tva = vfNum($l, ['tax_value_percent', 'tax_val_perc']);
        $ht = ($ttc !== null && $ttc > 0 && $tva !== null) ? $ttc / (1 + $tva / 100) : null;
    }
    return $ht !== null && $ht > 0 ? $ht / $part : null;
}

/** Une ligne de products/available ramenée à ce que les règles lisent. */
function vfProduitDe(array $l): ?array
{
    $pid = (int) (vfNum($l, ['id', 'product_id', 'id_product']) ?? 0);
    if ($pid <= 0) { return null; }
    $rid = (int) (vfNum($l, ['id_recipe', 'recipe_id']) ?? 0);
    $cout = vfNum($l, ['recipe_cost_net']);
    if ($cout === null) { $cout = vfNum($l, ['recipe_cost_gross']); }
    $co = isset($l['category']) && is_array($l['category']) ? $l['category'] : [];
    return ['pid' => $pid, 'nom' => vfTxt($l, ['base_name', 'name', 'product_name']),
        'cat' => vfTxt($l, ['base_category_name', 'category_name']) ?: vfTxt($co, ['base_name', 'name']),
        'catId' => (int) (vfNum($l, ['id_category', 'category_id']) ?? vfNum($co, ['id']) ?? 0) ?: null,
        'recette' => $rid > 0 ? $rid : null, 'cout' => $cout, 'prixHt' => vfPrixHt($l),
        'divisible' => (int) ($l['is_divisible'] ?? 0) === 1, 'part' => vfNum($l, ['portion_size'])];
}

/** Les produits disponibles de chaque magasin : [sid => [pid => produit]]. Quatre lectures de front. */
function vfDispo(array $sids): array
{
    $out = [];
    if (!PanelApi::configured()) { return $out; }
    PanelApi::coutsMatiere();   // lit products/available de tous les magasins du compte, en parallèle
    foreach ($sids as $sid) {
        $out[$sid] = [];
        foreach (PanelApi::produitsDisponibles((int) $sid) as $l) {
            if (!is_array($l)) { continue; }
            $p = vfProduitDe($l);
            if ($p !== null) { $out[$sid][$p['pid']] = $p; }
        }
    }
    return $out;
}

/**
 * Une recette telle que /shops/{id}/recipes/{recette}/cost la rend, ramenée à ses lignes de matière
 * (sous-recettes dépliées, quantités ramenées à une pièce par le rendement). Même lecture que la
 * modale « Recette & marge » du dashboard (rpApi).
 */
function vfRecetteCompacte(array $c, int $sid): array
{
    $rendLu = vfNum($c, ['yield_quantity']);
    $rend = $rendLu !== null && $rendLu > 0 ? $rendLu : 1.0;
    $fac = 1.0 / $rend;
    $lignes = [];
    $marche = static function (array $els, ?string $sous, int $prof) use (&$marche, &$lignes, $fac): void {
        foreach ($els as $e) {
            if (!is_array($e)) { continue; }
            $type = strtolower((string) ($e['type'] ?? 'ingredient'));
            if ($type === 'sub-recipe' || $type === 'subrecipe') {
                if ($prof < VF_PROFONDEUR) { $n = trim((string) ($e['name'] ?? '')); $marche((array) ($e['elements'] ?? []), $n !== '' ? $n : $sous, $prof + 1); }
                continue;
            }
            $q = vfNum($e, ['required_quantity', 'quantity_cost', 'recipe_quantity']);
            $cout = vfNum($e, ['calculated_req_price_net']);
            $prix = vfNum($e, ['price_net']);
            if ($cout === null && $q !== null && $prix !== null) { $cout = $q * $prix; }
            $lignes[] = ['mid' => (int) (vfNum($e, ['material_id', 'ingredient_id']) ?? 0), 'nom' => trim((string) ($e['name'] ?? '')),
                'q' => $q !== null ? round($q * $fac, 6) : null, 'u' => trim((string) ($e['unit_name'] ?? '')),
                'prix' => $prix, 'src' => strtoupper((string) ($e['price_source'] ?? '')),
                'cout' => $cout !== null ? round($cout * $fac, 6) : null, 'sous' => $sous];
        }
    };
    $marche((array) ($c['elements'] ?? []), null, 0);
    $net = vfNum($c, ['cost_net']);
    return ['sid' => $sid, 'nom' => trim((string) ($c['name'] ?? '')), 'rend' => $rendLu, 'coutNet' => $net !== null ? round($net * $fac, 6) : null,
        'complet' => (bool) ($c['cost_complete'] ?? false), 'lignes' => $lignes];
}

/** Les recettes déjà lues (moins d'un jour) : [rid => recette compacte]. */
function vfRecettesEnCache(array $rids): array
{
    $out = [];
    foreach ($rids as $rid) {
        $c = vfCache('vfRec:' . (int) $rid, VF_SEC_RECETTE);
        if ($c !== null) { $out[(int) $rid] = $c['v']; }
    }
    return $out;
}

/** Lit au plus $budget recettes (rid => magasin de référence), quatre de front ; rend le nombre lu. */
function vfLireRecettes(array $aLire, int $budget): int
{
    if ($budget <= 0 || $aLire === [] || !PanelApi::configured()) { return 0; }
    $lot = array_slice($aLire, 0, $budget, true);
    $chemins = [];
    foreach ($lot as $rid => $sid) { $chemins[(int) $rid] = '/shops/' . (int) $sid . '/recipes/' . (int) $rid . '/cost'; }
    $n = 0;
    foreach (PanelApi::getParallele($chemins, 4, 20) as $rid => $r) {
        if (!is_array($r) || !isset($r['elements']) || !is_array($r['elements'])) { continue; }
        vfGarder('vfRec:' . (int) $rid, vfRecetteCompacte($r, (int) $lot[$rid]));
        $n++;
    }
    return $n;
}

/** Le prix de chaque matière dans chaque magasin : [sid => [mid => prix|null]] ; un magasin illisible vaut []. */
function vfPrixMatieres(array $sids): array
{
    $out = [];
    if (!function_exists('mfMatieresApi') || !PanelApi::configured()) { return $out; }
    foreach (mfMatieresApi(array_values($sids), false) as $sid => $m) {
        $out[(int) $sid] = [];
        foreach ((array) $m as $mid => $x) { $out[(int) $sid][(int) $mid] = is_array($x) ? ($x['prix'] ?? null) : null; }
    }
    return $out;
}

/** Le stock matière de chaque magasin, matières présentes seulement : [sid => [cle => [mid, nom, cat, q, u]]]. Six heures. */
function vfStocks(array $sids): array
{
    $out = []; $manque = [];
    foreach ($sids as $sid) {
        $c = vfCache('vfStock:' . $sid, VF_SEC_STOCK);
        if ($c !== null) { $out[$sid] = $c['v']; } else { $manque[$sid] = '/shops/' . $sid . '/material-inventory'; }
    }
    if ($manque !== [] && PanelApi::configured()) {
        foreach (PanelApi::getParallele($manque, 4, 20) as $sid => $r) {
            $m = [];
            foreach (is_array($r) ? analyseListe($r) : [] as $x) {
                if (!is_array($x) || (int) ($x['exist_in_inventory'] ?? 1) !== 1) { continue; }
                $q = vfNum($x, ['current_quantity', 'quantity']);
                if ($q === null || $q <= 0) { continue; }
                $mid = (int) (vfNum($x, ['id_material', 'material_id']) ?? 0);
                $nom = vfTxt($x, ['material_name', 'name']);
                $m[$mid > 0 ? 'm' . $mid : 'n' . mb_strtolower($nom)] = ['mid' => $mid, 'nom' => $nom, 'cat' => vfTxt($x, ['category_name']), 'q' => round($q, 3), 'u' => vfTxt($x, ['unit_name'])];
            }
            $out[$sid] = $m;
            if ($m !== []) { vfGarder('vfStock:' . $sid, $m); }
        }
    }
    foreach ($sids as $sid) { $out[$sid] ??= []; }
    return $out;
}

/* ---------------------------------------------------------------------------------------------
 * Les règles — fonction pure : aucune lecture, aucune écriture (bin/tests_verification.php)
 * ------------------------------------------------------------------------------------------- */

/**
 * @param array $mags   [sid => nom court]
 * @param array $V      [sid => [pid => [ca, q, nom]]] — les ventes de la période
 * @param array $D      [sid => [pid => produit]] — products/available (vfProduitDe)
 * @param array $R      [rid => recette compacte] — les recettes déjà lues
 * @param array $M      [sid => [mid => prix|null]] — le prix des matières par magasin
 * @param array $S      [sid => [cle => matière en stock]]
 * @param float $ratioMin le seuil bas du coût (part du prix HT)
 * @param array $F      [pid => true] — les formules et promotions (marquées, comptées à part dans la couverture)
 * @return array{lignes: array, couverture: array, parRegle: array, recettes: array, aLire: array}
 */
function vfRegles(array $mags, array $V, array $D, array $R, array $M, array $S, float $ratioMin = 0.05, int $nJours = VF_JOURS, array $F = []): array
{
    $info = [];          // pid => produit de référence (le premier magasin qui le connaît)
    foreach ($D as $sid => $ps) { foreach ($ps as $pid => $p) { $info[$pid] ??= $p; } }
    $caTot = 0.0; $caParPid = []; $fTot = 0.0;
    $aLire = []; $caRid = [];                         // rid => magasin de référence (celui qui vend le plus)
    foreach ($V as $sid => $ps) {
        foreach ($ps as $pid => $v) {
            if (($v['ca'] ?? 0) <= 0) { continue; }
            $caTot += $v['ca']; $caParPid[$pid] = ($caParPid[$pid] ?? 0) + $v['ca'];
            if (isset($F[$pid])) { $fTot += $v['ca']; }
            $rid = ($D[$sid][$pid]['recette'] ?? null) ?: ($info[$pid]['recette'] ?? null);
            if ($rid && (!isset($caRid[$rid]) || $v['ca'] > $caRid[$rid])) { $caRid[$rid] = $v['ca']; $aLire[$rid] = $sid; }
        }
    }
    // Le coût de chaque produit dans chaque magasin, pour la norme du réseau.
    $coutsPid = [];
    foreach ($D as $sid => $ps) { foreach ($ps as $pid => $p) { if (($p['cout'] ?? null) !== null && $p['cout'] > 0) { $coutsPid[$pid][$sid] = (float) $p['cout']; } } }

    $flags = [];          // cle => ligne en construction
    $nonFiable = [];      // sid:pid => true
    $caARelire = 0.0; $caFiable = 0.0; $fFiable = 0.0; $fARelire = 0.0;
    $ajoute = static function (string $regle, int $pid, int $sid, float $ca, string $detail, array $extra = []) use (&$flags, $mags): void {
        $cle = $regle . ':' . $pid;
        if (!isset($flags[$cle])) { $flags[$cle] = ['cle' => $cle, 'regle' => $regle, 'pid' => $pid, 'ca' => 0.0, 'shops' => [], 'detail' => $detail, 'caDetail' => -1.0] + $extra; }
        $f = &$flags[$cle];
        $f['ca'] += $ca; $f['shops'][(string) $sid] = $mags[$sid] ?? ('Magasin ' . $sid);
        if ($ca > $f['caDetail']) { $f['detail'] = $detail; $f['caDetail'] = $ca; foreach ($extra as $k => $x) { $f[$k] = $x; } }
        unset($f);
    };

    foreach ($V as $sid => $ps) {
        $court = $mags[$sid] ?? ('magasin ' . $sid);
        foreach ($ps as $pid => $v) {
            $ca = (float) ($v['ca'] ?? 0);
            if ($ca <= 0) { continue; }
            $p = $D[$sid][$pid] ?? null;
            $ref = $p ?? ($info[$pid] ?? null);
            $nom = $ref['nom'] ?? ($v['nom'] ?? ('Produit ' . $pid));
            $cat = $ref['cat'] ?? '';
            $base = ['nom' => $nom !== '' ? $nom : (string) ($v['nom'] ?? ''), 'cat' => $cat];
            $rid = ($p['recette'] ?? null) ?: ($ref['recette'] ?? null);
            $k = $sid . ':' . $pid;
            if (!$rid && isset($F[$pid])) {
                $ajoute('form', $pid, $sid, $ca, 'Vendu ' . vfQte(round((float) $v['q'], 1), 'fois') . ' en ' . $nJours . ' jours (' . vfEur($ca, 0) . ') comme un seul article, sans recette ni produits : ses produits ne sortent pas sur le ticket, leur matière quitte le stock sans vente. Ils doivent y figurer, chacun à prix réduit (promotion « bundle » du panel).', $base + ['rid' => null]);
                $nonFiable[$k] = true;
                continue;
            }
            if (!$rid) {
                $ajoute('sans', $pid, $sid, $ca, $ref === null
                    ? 'Vendu ' . vfQte(round((float) $v['q'], 1), 'fois') . ' en ' . $nJours . ' jours (' . vfEur($ca, 0) . '), absent des produits disponibles du panel : recette inconnue.'
                    : 'Vendu ' . vfQte(round((float) $v['q'], 1), 'fois') . ' en ' . $nJours . ' jours (' . vfEur($ca, 0) . '), aucune recette au panel : son coût n’est pas connu.', $base + ['rid' => null]);
                $nonFiable[$k] = true;
                continue;
            }
            $r = $R[$rid] ?? null;
            $cand = [];   // regle => [detail, extra]
            $prixHt = $p['prixHt'] ?? ($ref['prixHt'] ?? null);
            $cout = $p['cout'] ?? null;

            // Ce que dit la structure de la recette (lue une fois pour le réseau).
            if ($r !== null) {
                $L = (array) ($r['lignes'] ?? []);
                $nomR = (string) ($r['nom'] ?? '') !== '' ? (string) $r['nom'] : 'n° ' . $rid;
                if ($L === []) {
                    $cand['vide'] = ['Recette « ' . $nomR . ' » sans aucune ligne : coût 0 €.', []];
                }
                if (($r['rend'] ?? null) !== null && (float) $r['rend'] <= 0) {
                    $cand['rend'] = ['Rendement à 0 dans la recette « ' . $nomR . ' » : le coût de la recette entière part sur une seule pièce.', []];
                }
                // Une matière sans prix DANS CE MAGASIN (le magasin a été lu, la matière n'y a pas de prix).
                if (isset($M[$sid]) && $M[$sid] !== [] && $L !== []) {
                    $sansPrix = [];
                    foreach ($L as $l) {
                        $mid = (int) ($l['mid'] ?? 0);
                        if ($mid <= 0) { continue; }
                        $px = $M[$sid][$mid] ?? null;
                        if ($px === null || $px <= 0) { $sansPrix[$l['nom'] !== '' ? $l['nom'] : 'matière ' . $mid] = true; }
                    }
                    if ($sansPrix !== []) {
                        $n = count($sansPrix); $noms = array_keys($sansPrix);
                        $cand['prix'] = [($n > 1 ? $n . ' matières' : '1 matière') . ' sans prix à ' . $court . ' : ' . implode(', ', array_slice($noms, 0, 4)) . ($n > 4 ? '…' : '') . '.', []];
                    }
                }
                // Une ligne qui pèse plus de la moitié du coût et que ×1000 ou ÷1000 ramène dans un coût plausible.
                $total = 0.0; foreach ($L as $l) { if (($l['cout'] ?? null) !== null) { $total += (float) $l['cout']; } }
                $prixRef = $prixHt;
                if ($total > 0 && $prixRef !== null && $prixRef > 0) {
                    $ratio = $total / $prixRef;
                    if ($ratio >= 1 || $ratio < $ratioMin) {
                        $lourde = null;
                        foreach ($L as $l) { if (($l['cout'] ?? null) !== null && (float) $l['cout'] > VF_PART_UNITE * $total && ($lourde === null || $l['cout'] > $lourde['cout'])) { $lourde = $l; } }
                        if ($lourde !== null) {
                            $alt = $ratio >= 1 ? $total - $lourde['cout'] + $lourde['cout'] / 1000 : $total - $lourde['cout'] + $lourde['cout'] * 1000;
                            $r2 = $alt / $prixRef;
                            if ($r2 >= max($ratioMin, 0.05) && $r2 < 0.8) {
                                $cand['unite'] = ['« ' . $lourde['nom'] . ' » pèse ' . vfPct(100 * $lourde['cout'] / $total) . ' du coût (' . vfQte($lourde['q'], (string) $lourde['u']) . ', ' . vfEur((float) $lourde['cout']) . ') : '
                                    . ($ratio >= 1 ? 'lue mille fois trop grande' : 'lue mille fois trop petite') . ', la pièce coûterait ' . vfEur($alt) . ' (' . vfPct(100 * $r2) . ' du prix HT) au lieu de ' . vfEur($total) . '.', ['mid' => (int) ($lourde['mid'] ?? 0)]];
                            }
                        }
                    }
                }
            }
            // Vendu à la portion sans part définie.
            if ($p !== null && !empty($p['divisible'])) {
                $part = $p['part'];
                if ($part === null || $part <= 0 || $part >= 1) {
                    $cand['port'] = ['Vendu à la portion sans part définie : le prix est celui d’une portion, le coût celui de la pièce entière.', []];
                }
            }
            // Coût invraisemblable face au prix HT de CE magasin.
            if ($prixHt !== null && $prixHt > 0) {
                if ($cout === null || $cout <= 0) {
                    $cand['inv'] = ['Coût à 0 € à ' . $court . ' pour un prix de ' . vfEur($prixHt) . ' HT : le panel ne chiffre pas cette recette.', []];
                } elseif ($cout >= $prixHt) {
                    $cand['inv'] = ['Coût ' . vfEur($cout) . ' pour un prix de ' . vfEur($prixHt) . ' HT à ' . $court . ' (' . vfPct(100 * $cout / $prixHt) . ') : le coût dépasse le prix.', []];
                } elseif ($cout / $prixHt < $ratioMin) {
                    $cand['inv'] = ['Coût ' . vfEur($cout) . ' pour ' . vfEur($prixHt) . ' HT à ' . $court . ' (' . vfPct(100 * $cout / $prixHt, 1) . ' du prix), sous le seuil de ' . vfPct(100 * $ratioMin) . '.', []];
                }
            }
            // Hors norme du réseau : la médiane des AUTRES magasins (deux au moins).
            if ($cout !== null && $cout > 0) {
                $autres = $coutsPid[$pid] ?? []; unset($autres[$sid]);
                if (count($autres) >= 2) {
                    $med = vfMediane(array_values($autres));
                    if ($med !== null && $med > 0) {
                        $ecart = 100 * ($cout - $med) / $med;
                        if (abs($ecart) > VF_ECART_RESEAU) {
                            $cand['reseau'] = [vfEur($cout) . ' à ' . $court . ' ; médiane des autres magasins ' . vfEur($med) . ' (' . ($ecart > 0 ? '+' : '') . vfPct($ecart) . ').', []];
                        }
                    }
                }
            }
            // L'explication la plus précise l'emporte : vide, unité ou prix manquant expliquent un coût aberrant.
            if (isset($cand['vide']) || isset($cand['unite']) || isset($cand['prix'])) { unset($cand['inv'], $cand['reseau']); }
            if (isset($cand['inv'])) { unset($cand['reseau']); }
            foreach ($cand as $regle => [$detail, $extra]) { $ajoute($regle, $pid, $sid, $ca, $detail, $base + ['rid' => $rid] + $extra); }
            if ($cand !== []) { $nonFiable[$k] = true; }
            elseif ($r === null) { $caARelire += $ca; if (isset($F[$pid])) { $fARelire += $ca; } }
            if ($cand === []) { $caFiable += $ca; if (isset($F[$pid])) { $fFiable += $ca; } }
        }
    }

    // Les matières en stock que n'emploie aucune recette vendue — seulement quand presque toutes les recettes sont lues.
    $nRid = count($aLire); $nLues = count(array_intersect_key($R, $aLire));
    $orphJuge = $nRid > 0 && $nLues / $nRid >= VF_RELUES_ORPH;
    if ($orphJuge) {
        $emploiId = []; $emploiNom = [];
        foreach (array_intersect_key($R, $aLire) as $r) {
            foreach ((array) ($r['lignes'] ?? []) as $l) {
                if ((int) ($l['mid'] ?? 0) > 0) { $emploiId[(int) $l['mid']] = true; }
                if (($l['nom'] ?? '') !== '') { $emploiNom[mb_strtolower((string) $l['nom'])] = true; }
            }
        }
        foreach ($S as $sid => $ms) {
            foreach ($ms as $m) {
                $mid = (int) ($m['mid'] ?? 0); $nomM = (string) ($m['nom'] ?? '');
                if (($mid > 0 && isset($emploiId[$mid])) || isset($emploiNom[mb_strtolower($nomM)])) { continue; }
                if (preg_match(VF_HORS_RECETTE, $nomM . ' ' . ($m['cat'] ?? '')) === 1) { continue; }
                $cle = 'orph:' . ($mid > 0 ? 'm' . $mid : mb_strtolower($nomM));
                $px = $mid > 0 ? ($M[$sid][$mid] ?? null) : null;
                $val = $px !== null && $px > 0 ? (float) $m['q'] * $px : null;
                if (!isset($flags[$cle])) { $flags[$cle] = ['cle' => $cle, 'regle' => 'orph', 'pid' => null, 'mid' => $mid ?: null, 'rid' => null, 'nom' => $nomM, 'cat' => (string) ($m['cat'] ?? ''), 'ca' => 0.0, 'valeur' => 0.0, 'shops' => [], 'stocks' => [], 'detail' => '', 'caDetail' => -1.0]; }
                $f = &$flags[$cle];
                $f['shops'][(string) $sid] = $mags[$sid] ?? ('Magasin ' . $sid);
                $f['stocks'][] = vfQte((float) $m['q'], (string) $m['u']) . ' à ' . ($mags[$sid] ?? $sid);
                if ($val !== null) { $f['valeur'] += $val; }
                $f['detail'] = 'En stock (' . implode(', ', $f['stocks']) . '), employée par aucune recette vendue ces ' . $nJours . ' jours.';
                unset($f);
            }
        }
    }

    // La ligne finale : magasins, CA arrondi, rang.
    $lignes = [];
    foreach ($flags as $f) {
        unset($f['caDetail'], $f['stocks']);
        $f['ca'] = round((float) $f['ca'], 2);
        if (isset($f['valeur'])) { $f['valeur'] = $f['valeur'] > 0 ? round((float) $f['valeur'], 2) : null; }
        $f['magasins'] = array_values($f['shops']); $f['shops'] = array_map('strval', array_keys($f['shops']));
        $f['nomRegle'] = VF_REGLES[$f['regle']][0];
        $f['formule'] = $f['pid'] !== null && isset($F[$f['pid']]);
        $lignes[] = $f;
    }
    usort($lignes, static function ($a, $b) {
        $oa = $a['regle'] === 'orph' ? 1 : 0; $ob = $b['regle'] === 'orph' ? 1 : 0;
        if ($oa !== $ob) { return $oa <=> $ob; }
        return [$b['ca'], $b['valeur'] ?? 0] <=> [$a['ca'], $a['valeur'] ?? 0] ?: strcmp((string) $a['nom'], (string) $b['nom']);
    });
    $parRegle = array_fill_keys(array_keys(VF_REGLES), 0);
    foreach ($lignes as $l) { $parRegle[$l['regle']]++; }
    // Les produits concernés (une pièce peut porter deux règles) et le CA qui manque.
    $caManquant = 0.0;
    foreach ($V as $sid => $ps) { foreach ($ps as $pid => $v) { if (isset($nonFiable[$sid . ':' . $pid]) && ($v['ca'] ?? 0) > 0) { $caManquant += $v['ca']; } } }
    $top10 = 0.0; $vus = [];
    foreach ($lignes as $l) { if ($l['regle'] === 'orph' || isset($vus[$l['pid']])) { continue; } $vus[$l['pid']] = true; if (count($vus) <= 10) { $top10 += $l['ca']; } }
    // La même couverture sans les formules et promotions (l'interrupteur de l'écran).
    $fManquant = 0.0; $top10h = 0.0; $vusH = [];
    foreach ($V as $sid => $ps) { foreach ($ps as $pid => $v) { if (isset($F[$pid], $nonFiable[$sid . ':' . $pid]) && ($v['ca'] ?? 0) > 0) { $fManquant += $v['ca']; } } }
    foreach ($lignes as $l) { if ($l['regle'] === 'orph' || !empty($l['formule']) || isset($vusH[$l['pid']])) { continue; } $vusH[$l['pid']] = true; if (count($vusH) <= 10) { $top10h += $l['ca']; } }
    $hTot = $caTot - $fTot; $hFiable = $caFiable - $fFiable; $hManquant = $caManquant - $fManquant;
    return [
        'lignes' => $lignes,
        'couverture' => ['caTotal' => round($caTot, 2), 'caFiable' => round($caFiable, 2), 'caManquant' => round($caManquant, 2), 'caARelire' => round($caARelire, 2),
            'pct' => $caTot > 0 ? round(100 * $caFiable / $caTot, 1) : null, 'partTop10' => $caManquant > 0 ? round(100 * min($top10, $caManquant) / $caManquant, 0) : null,
            'produits' => count($vus),
            'horsFormules' => ['caTotal' => round($hTot, 2), 'caFiable' => round($hFiable, 2), 'caManquant' => round($hManquant, 2), 'caARelire' => round($caARelire - $fARelire, 2),
                'pct' => $hTot > 0 ? round(100 * $hFiable / $hTot, 1) : null, 'partTop10' => $hManquant > 0 ? round(100 * min($top10h, $hManquant) / $hManquant, 0) : null,
                'produits' => count($vusH)],
            'formules' => ['ca' => round($fTot, 2), 'produits' => count(array_intersect_key($F, $caParPid))]],
        'parRegle' => $parRegle,
        'recettes' => ['vendues' => $nRid, 'lues' => $nLues, 'orphJugees' => $orphJuge],
        'aLire' => array_diff_key($aLire, $R),
    ];
}

/* ---------------------------------------------------------------------------------------------
 * L'analyse, son cache et son registre
 * ------------------------------------------------------------------------------------------- */

/** Calcule l'analyse complète en lisant au plus $budget recettes de plus ; la garde une heure. */
function vfAnalyse(int $budget): array
{
    $mags = vfMagasins();
    $sids = array_keys($mags);
    $V = vfVentes($sids, VF_JOURS);
    $D = vfDispo($sids);
    // Les recettes à lire : celles des produits vendus, lues dans le magasin qui les vend le plus.
    $passe = vfRegles($mags, $V['ventes'], $D, [], [], [], 0.05, VF_JOURS);
    $rids = array_keys($passe['aLire']);
    $R = vfRecettesEnCache($rids);
    $manque = array_diff_key($passe['aLire'], $R);
    $lues = vfLireRecettes($manque, $budget);
    if ($lues > 0) { $R = vfRecettesEnCache($rids); }
    $M = vfPrixMatieres($sids);
    $S = vfStocks($sids);
    $p = setting('production', []);
    $ratioMin = (is_array($p) && isset($p['coutRatioMin'])) ? (float) $p['coutRatioMin'] : 0.05;
    $F = vfFormules($V['ventes'], $D);
    $a = vfRegles($mags, $V['ventes'], $D, $R, $M, $S, $ratioMin, VF_JOURS, $F);
    $complet = $a['aLire'] === [];
    $a['depuis'] = vfRegistre($a['lignes'], $complet);
    foreach ($a['lignes'] as &$l) { $l['depuis'] = $a['depuis']['cles'][$l['cle']] ?? date('Y-m-d'); }
    unset($l);
    $out = [
        'lignes' => $a['lignes'], 'total' => count($a['lignes']), 'couverture' => $a['couverture'], 'parRegle' => $a['parRegle'],
        'regles' => array_map(static fn ($c, $r) => ['code' => $c, 'nom' => $r[0], 'explication' => $r[1]], array_keys(VF_REGLES), array_values(VF_REGLES)),
        'recettes' => $a['recettes'] + ['aLire' => count($a['aLire']), 'luesCePassage' => $lues, 'complet' => $complet],
        'corrigees' => $a['depuis']['corrigees'],
        'magasins' => array_map(static fn ($id, $n) => ['id' => (string) $id, 'nom' => $n, 'joursLus' => (int) ($V['jours'][$id] ?? 0)], array_keys($mags), array_values($mags)),
        'periode' => ['du' => $V['du'], 'au' => $V['au'], 'jours' => VF_JOURS],
        'seuils' => ['coutRatioMin' => $ratioMin, 'ecartReseau' => VF_ECART_RESEAU, 'partUnite' => VF_PART_UNITE],
        'lienRecette' => vfLienPanelModele(),
        'lu' => date('c'),
        'source' => 'ventes : tickets gravés par la moisson (30 jours, aujourd’hui exclu) · produits, recettes et coûts : API du panel (/shops/{id}/products/available, /shops/{id}/recipes/{recette}/cost, /shops/{id}/materials, /shops/{id}/material-inventory) · lecture seule',
    ];
    vfGarder('vfAnalyse', $out);
    return $out;
}

/**
 * Les formules et promotions parmi les produits vendus : [pid => true]. La catégorie vient de products/available, sinon
 * du catalogue complet du panel (les produits qu'aucun magasin ne propose plus) ; son groupe, du catalogue.
 */
function vfFormules(array $V, array $D): array
{
    $cats = []; $complet = [];
    try { if (function_exists('panelCatalogue')) { $cats = (array) (panelCatalogue()['categories'] ?? []); } } catch (Throwable $e) { $cats = []; }
    try { if (function_exists('panelCatalogueComplet')) { $complet = (array) (panelCatalogueComplet()['produits'] ?? []); } } catch (Throwable $e) { $complet = []; }
    $F = []; $vu = [];
    foreach ($V as $ps) {
        foreach ($ps as $pid => $v) {
            if (isset($vu[$pid])) { continue; }
            $vu[$pid] = true;
            $ref = null; foreach ($D as $ps2) { if (isset($ps2[$pid])) { $ref = $ps2[$pid]; break; } }
            $x = (array) ($complet[$pid] ?? []);
            $cat = (string) (($ref['cat'] ?? '') ?: ($x['cat'] ?? ''));
            $cid = (int) (($ref['catId'] ?? 0) ?: ($x['catId'] ?? 0));
            $groupe = $cid > 0 ? (string) ($cats[$cid]['groupe'] ?? '') : '';
            $nom = (string) (($ref['nom'] ?? '') ?: (($x['nom'] ?? '') ?: ($v['nom'] ?? '')));
            if (vfEstFormule($nom, $cat, $groupe)) { $F[(int) $pid] = true; }
        }
    }
    return $F;
}

/** Crée le registre au besoin. */
function vfRegistreTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_verif_recette ('
        . 'cle VARCHAR(120) NOT NULL PRIMARY KEY,'
        . 'regle VARCHAR(12) NOT NULL,'
        . 'product_id INT NULL, material_id INT NULL,'
        . 'nom VARCHAR(200) NULL, detail VARCHAR(600) NULL,'
        . 'ca_30j DECIMAL(12,2) NULL, magasins VARCHAR(200) NULL,'
        . 'premier_vu DATE NOT NULL, dernier_vu DATE NOT NULL, resolu_le DATE NULL,'
        . 'KEY (resolu_le)'
        . ') DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/**
 * Le registre : chaque signalement garde le jour où il est apparu ; un signalement qui disparaît d'une
 * analyse COMPLÈTE (toutes les recettes lues) est noté résolu ce jour-là. Rend [cles => premier vu, corrigees].
 */
function vfRegistre(array $lignes, bool $complet): array
{
    $auj = date('Y-m-d');
    $out = ['cles' => [], 'corrigees' => ['n' => 0, 'depuis' => null]];
    try {
        vfRegistreTable();
        $actifs = [];
        foreach (Db::rows('SELECT cle, premier_vu, resolu_le FROM ceo_verif_recette') as $r) { $actifs[(string) $r['cle']] = $r; }
        foreach ($lignes as $l) {
            $prev = $actifs[$l['cle']] ?? null;
            $premier = ($prev !== null && $prev['resolu_le'] === null) ? (string) $prev['premier_vu'] : $auj;
            Db::exec('INSERT INTO ceo_verif_recette (cle, regle, product_id, material_id, nom, detail, ca_30j, magasins, premier_vu, dernier_vu, resolu_le) VALUES (?,?,?,?,?,?,?,?,?,?,NULL)'
                . ' ON DUPLICATE KEY UPDATE regle = VALUES(regle), product_id = VALUES(product_id), material_id = VALUES(material_id), nom = VALUES(nom), detail = VALUES(detail),'
                . ' ca_30j = VALUES(ca_30j), magasins = VALUES(magasins), premier_vu = VALUES(premier_vu), dernier_vu = VALUES(dernier_vu), resolu_le = NULL',
                [$l['cle'], $l['regle'], $l['pid'] ?? null, $l['mid'] ?? null, mb_substr((string) $l['nom'], 0, 200), mb_substr((string) $l['detail'], 0, 600),
                    $l['ca'], mb_substr(implode(', ', $l['magasins']), 0, 200), $premier, $auj]);
            $out['cles'][$l['cle']] = $premier;
        }
        if ($complet) {
            $vus = array_flip(array_column($lignes, 'cle'));
            foreach ($actifs as $cle => $r) {
                if ($r['resolu_le'] === null && !isset($vus[$cle])) { Db::exec('UPDATE ceo_verif_recette SET resolu_le = ? WHERE cle = ?', [$auj, $cle]); }
            }
        }
        $c = Db::row('SELECT COUNT(*) n, MIN(resolu_le) d FROM ceo_verif_recette WHERE resolu_le >= ?', [date('Y-m-d', strtotime('-30 day'))]);
        $out['corrigees'] = ['n' => (int) ($c['n'] ?? 0), 'depuis' => $c['d'] ?? null];
    } catch (Throwable $e) { /* sans registre : la liste reste juste, « repérée depuis » vaut aujourd'hui */ }
    return $out;
}

/* ---------------------------------------------------------------------------------------------
 * Routes
 * ------------------------------------------------------------------------------------------- */

/**
 * L'adresse d'un produit au panel, avec {pid} : le réglage `verifLienRecette`, sinon la page produit du panel
 * (mesuré le 31/10/2026 : /panel/products/{id} existe — il renvoie à la connexion —, /panel/recipes/{id} rend 404).
 */
function vfLienPanelModele(): string
{
    $r = '';
    try { $r = (string) (setting('verifLienRecette', '') ?: ''); } catch (Throwable $e) { $r = ''; }
    if ($r !== '') { return $r; }
    $base = rtrim((string) preg_replace('#/api/v\d+$#', '', (string) (PanelApi::config()['base'] ?? 'https://atelierby.tfbuddy.com/api/v1')), '/');
    return $base . '/panel/products/{pid}';
}

/**
 * GET /verification/recettes/fiche?pid=[&shop=] — la recette d'un produit, ouverte au clic dans la liste : ses lignes
 * (sous-recettes dépliées, ramenées à une pièce), le prix de chaque matière dans chaque magasin, le produit dans chaque
 * magasin (coût, prix HT, coefficient), ses signalements, et l'adresse de sa page au panel. Lecture seule : la
 * correction se fait au panel (l'écriture depuis le cockpit attend la mesure de PATCH /recipes/{id}).
 */
function ep_verification_fiche(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    $pid = (int) ($_GET['pid'] ?? 0);
    if ($pid <= 0) { http_response_code(400); return ['error' => 'pid requis']; }
    $mags = vfMagasins(); $sids = array_keys($mags);
    $D = vfDispo($sids);
    $rid = null; $nom = ''; $cat = ''; $magasins = []; $ref = (int) ($_GET['shop'] ?? 0);
    foreach ($sids as $sid) {
        $p = $D[$sid][$pid] ?? null;
        if ($p !== null) { $rid = $rid ?: $p['recette']; $nom = $nom ?: $p['nom']; $cat = $cat ?: $p['cat']; if ($ref <= 0) { $ref = $sid; } }
        $cout = $p['cout'] ?? null; $ht = $p['prixHt'] ?? null;
        $magasins[] = ['id' => (string) $sid, 'nom' => $mags[$sid], 'present' => $p !== null, 'cout' => $cout !== null ? round((float) $cout, 4) : null,
            'prixHt' => $ht !== null ? round((float) $ht, 2) : null, 'coef' => $cout !== null && $cout > 0 && $ht !== null ? round($ht / $cout, 2) : null,
            'food' => $cout !== null && $ht !== null && $ht > 0 ? round(100 * $cout / $ht, 1) : null,
            'divisible' => (bool) ($p['divisible'] ?? false), 'part' => $p['part'] ?? null];
    }
    $R = null;
    if ($rid) {
        $R = vfRecettesEnCache([$rid])[$rid] ?? null;
        if ($R === null && $ref > 0) { vfLireRecettes([$rid => $ref], 1); $R = vfRecettesEnCache([$rid])[$rid] ?? null; }
    }
    $M = vfPrixMatieres($sids);
    $total = 0.0; foreach ((array) ($R['lignes'] ?? []) as $l) { if (($l['cout'] ?? null) !== null) { $total += (float) $l['cout']; } }
    $lignes = [];
    foreach ((array) ($R['lignes'] ?? []) as $l) {
        $px = [];
        foreach ($sids as $sid) { $px[(string) $sid] = ($M[$sid] ?? []) === [] ? 'inconnu' : (isset($M[$sid][(int) $l['mid']]) && $M[$sid][(int) $l['mid']] > 0 ? round((float) $M[$sid][(int) $l['mid']], 4) : null); }
        $lignes[] = $l + ['part' => $total > 0 && ($l['cout'] ?? null) !== null ? round(100 * (float) $l['cout'] / $total, 1) : null, 'prixMagasins' => $px];
    }
    $sig = [];
    $A = vfCache('vfAnalyse', 2 * 86400);
    foreach ((array) ($A['v']['lignes'] ?? []) as $l) { if ((int) ($l['pid'] ?? 0) === $pid) { $sig[] = $l; } }
    return ['pid' => $pid, 'nom' => $nom, 'cat' => $cat, 'rid' => $rid,
        'recette' => $R === null ? null : ['nom' => $R['nom'] ?? '', 'rendement' => $R['rend'] ?? null, 'coutNet' => $R['coutNet'] ?? null, 'complet' => $R['complet'] ?? null,
            'luDans' => $mags[(int) ($R['sid'] ?? 0)] ?? null, 'total' => round($total, 4), 'lignes' => $lignes],
        'magasins' => $magasins, 'signalements' => $sig, 'lienPanel' => str_replace(['{pid}', '{id}', '{rid}'], [(string) $pid, (string) $pid, (string) ($rid ?? '')], vfLienPanelModele()),
        'lu' => date('c'), 'source' => 'recette : /shops/{id}/recipes/{recette}/cost (gardée 24 h) · prix : /shops/{id}/materials · produit : /shops/{id}/products/available · lecture seule'];
}

/** GET /verification/recettes[?rafraichir=1] — servie du cache (une heure) ; « rafraichir » lit un lot de recettes de plus. */
function ep_verification_recettes(): array
{
    if (!PanelApi::configured()) {
        return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres) : sans lui, ni produits ni recettes.', 'lignes' => [], 'total' => 0];
    }
    $frais = !empty($_GET['rafraichir']);
    if (!$frais) {
        $c = vfCache('vfAnalyse', VF_SEC_ANALYSE);
        if ($c !== null) { return $c['v'] + ['cache' => ['le' => date('c', (int) $c['le']), 'age' => time() - (int) $c['le']]]; }
    }
    @set_time_limit(120);
    return vfAnalyse(VF_BUDGET_DEMANDE) + ['cache' => null];
}

/**
 * GET /verification/recettes/sonde?shop=4[&pid=] — lecture seule : les clés d'une ligne de products/available (et
 * celle du produit demandé), la recette de ce produit telle que /cost la rend (trois premières lignes), une ligne de
 * /materials et de /material-inventory. Pour mesurer avant de coder (lot 0).
 */
function ep_verification_sonde(): array
{
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { $m = vfMagasins(); $sid = (int) (array_key_first($m) ?? 0); }
    $pid = (int) ($_GET['pid'] ?? 0);
    $dispo = PanelApi::produitsDisponibles($sid);
    $ligne = null;
    foreach ($dispo as $l) { if (is_array($l) && (int) (vfNum($l, ['id', 'product_id']) ?? 0) === $pid) { $ligne = $l; break; } }
    if ($ligne === null) { $ligne = $dispo[0] ?? null; }
    $rid = is_array($ligne) ? (int) (vfNum($ligne, ['id_recipe']) ?? 0) : 0;
    $cout = $rid > 0 ? PanelApi::get('/shops/' . $sid . '/recipes/' . $rid . '/cost') : null;
    if (is_array($cout) && isset($cout['elements']) && is_array($cout['elements'])) { $cout['elements'] = array_slice($cout['elements'], 0, 3); }
    $mat = PanelApi::get('/shops/' . $sid . '/materials');
    $inv = PanelApi::get('/shops/' . $sid . '/material-inventory');
    // Le format de la recette et du produit tels que le panel les rend : c'est la forme qu'attend PATCH /recipes/{id}
    // (écriture depuis le cockpit, à venir) — les listes sont coupées à trois éléments.
    $court = static function ($r) use (&$court) {
        if (!is_array($r)) { return $r; }
        $o = [];
        foreach ($r as $k => $v) { $o[$k] = is_array($v) && array_is_list($v) ? array_map($court, array_slice($v, 0, 3)) : (is_array($v) ? $court($v) : $v); }
        return $o;
    };
    $recBrute = $rid > 0 ? $court(PanelApi::get('/recipes/' . $rid)) : null;
    $pidLu = is_array($ligne) ? (int) (vfNum($ligne, ['id', 'product_id']) ?? 0) : 0;
    $prodBrut = $pidLu > 0 ? $court(PanelApi::get('/products/' . $pidLu)) : null;
    $premier = static fn ($r) => is_array($r) ? (analyseListe($r)[0] ?? null) : null;
    return ['shop' => $sid, 'nProduits' => count($dispo), 'clesProduit' => is_array($ligne) ? array_keys($ligne) : [], 'produit' => $ligne,
        'recette' => $rid ?: null, 'cout' => $cout, 'matiere' => $premier($mat), 'stock' => $premier($inv),
        'recetteBrute' => $recBrute, 'produitBrut' => $prodBrut, 'erreur' => PanelApi::$lastError];
}

/** Le battement du cron horaire (/rapports/cron) : un lot de recettes, l'analyse remise à jour, le registre tenu. */
function vfCron(): string
{
    if (!PanelApi::configured()) { return 'compte panel non configuré'; }
    @set_time_limit(300);
    $a = vfAnalyse(VF_BUDGET_CRON);
    $r = $a['recettes'];
    return $r['luesCePassage'] . ' recette(s) lue(s), ' . $r['lues'] . '/' . $r['vendues'] . ' en cache, ' . $a['total'] . ' signalement(s), couverture '
        . ($a['couverture']['pct'] === null ? '—' : number_format((float) $a['couverture']['pct'], 1, ',', '') . ' %');
}
