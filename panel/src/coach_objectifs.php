<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/src/cibles_reseau.php';   // la cible du food cost du réseau, la même partout

/**
 * Les objectifs 2026 des coachs et les propositions de prix face à la concurrence (18/11/2026, Sam : « dans le coach
 * de ventes, le but est d'arriver à 40 % de food cost 2026 (établir les targets par coach), 17 % de frais de
 * personnel, xx % de clients scannés, xx % de ventes additionnelles ou de menu… et de là proposer, en fonction de la
 * concurrence (benchmark), les produits où on est en dessous : augmenter comme la médiane de la concurrence, mais
 * proposer un bundle qui donne le prix actuel avec un plus gros volume »).
 *
 * Trois pièces, lues par l'assistant du dashboard magasin (src/assistant_magasin.php) :
 *
 *  1. LES OBJECTIFS (CO_OBJECTIFS). Chaque coach porte les siens (AM_COACHS[…]['objectifs']), réglés au cockpit
 *     (ERP franchisé › Assistant · coachs) ; une cible vide : le coach ne suit pas cet objectif. Valeurs de départ :
 *     le Coach Vente seul, 40 % de food cost, 17 % de frais de personnel, 25 % de clients scannés, 15 % de menus, et
 *     les articles par ticket et le panier du Catalogue des KPI (les cibles du réseau, src/cibles_reseau.php).
 *
 *  2. LES MESURES du magasin (coMesures), dans la fiche de faits, avec les définitions du cockpit :
 *     · food cost = coût matière ÷ CA de la heatmap de marge du panel (bornes from/to, les seules honorées), comme
 *       Résultat et le rapport ; quand le panel ne chiffre pas la marge, les journées se recomposent depuis les tickets
 *       gravés (svMatiereJour), et la période ne prend un ratio que si elles portent 80 % de son CA (ep_perf) ;
 *     · personnel = main-d'œuvre du P&L jour par jour (resCoutsPeriode) ÷ ce même CA ; un jour manquant : null ;
 *     · clients scannés = tickets rattachés à un client ÷ tickets, sur la mémoire de Fidélité › Profils (90 jours) ;
 *     · menus = part du CA des produits dont la catégorie ou le nom dit « menu » ou « formule » (historique des
 *       ventes, mois en cours jusqu'à hier) — la vente additionnelle « menu » ;
 *     · articles par ticket et panier moyen : le KPI du panel, mois en cours.
 *     Le mois en cours ET le mois dernier : le premier est le plus frais, le second le plus sûr (début de mois).
 *     Une mesure sans source reste null, jamais zéro.
 *
 *  3. LES PROPOSITIONS DE PRIX (outil prix_a_monter, à côté de prix_concurrence du Coach Vente, qui montre les relevés
 *     produit par produit : src/franchise/assistant_vente.php). Nos produits vendus plus de 5 % sous la médiane des
 *     boulangeries concurrentes du magasin (relevés du module Benchmark de TFBuddy, hors supermarchés : le verdict de
 *     l'onglet Concurrence du dashboard, mêmes règles de rapprochement) : le prix monte à la médiane, arrondi aux
 *     5 cents sans la dépasser, et un LOT garde le prix actuel à la pièce pour qui prend plus (4 croissants à 4,40 €
 *     quand la pièce passe de 1,10 € à 1,30 €). Le client fidèle garde son prix en prenant plus, le client de
 *     passage paie le prix du marché : le food cost baisse et le panier monte. Deux relevés au moins par produit.
 *     Les relevés : la lecture du Coach Vente (aivBenchmark, celle de l'onglet Concurrence), sinon le module Benchmark ;
 *     le calcul est gardé six heures dans aia_cache.
 *
 * Le coach ne change rien : il propose. Le prix se change au panel ; le lot se pose dans Promotions.
 */

/** Les objectifs qu'un coach peut suivre : libellé, unité, sens (max = au plus, min = au moins), bornes de saisie. */
const CO_OBJECTIFS = [
    'foodCost' => ['lib' => 'Food cost', 'unite' => '%', 'sens' => 'max', 'min' => 0, 'max' => 100, 'mesure' => 'coût matière ÷ CA du mois'],
    'personnel' => ['lib' => 'Frais de personnel', 'unite' => '%', 'sens' => 'max', 'min' => 0, 'max' => 100, 'mesure' => 'main-d’œuvre ÷ CA du mois'],
    'clientsScannes' => ['lib' => 'Clients scannés', 'unite' => '%', 'sens' => 'min', 'min' => 0, 'max' => 100, 'mesure' => 'tickets rattachés à un client ÷ tickets'],
    'menus' => ['lib' => 'Menus et formules', 'unite' => '%', 'sens' => 'min', 'min' => 0, 'max' => 100, 'mesure' => 'vente additionnelle : part du CA'],
    'articles' => ['lib' => 'Articles par ticket', 'unite' => '', 'sens' => 'min', 'min' => 0, 'max' => 20, 'mesure' => 'articles vendus ÷ tickets'],
    'panier' => ['lib' => 'Panier moyen', 'unite' => '€', 'sens' => 'min', 'min' => 0, 'max' => 500, 'mesure' => 'CA ÷ tickets'],
];

const CO_RELEVES_MIN = 2;        // relevés de boulangeries au moins pour proposer un prix
const CO_SEUIL_SOUS = -5.0;      // écart (%) sous lequel on est « en dessous » : le verdict de l'onglet Concurrence
const CO_PRIX_TTL = 21600;       // les propositions d'un magasin, gardées six heures (les relevés changent peu)
const CO_PRIX_JOURS = 30;        // les volumes : les 30 derniers jours, jusqu'à hier
/** Le lot qui garde le prix actuel : [prix actuel sous ce seuil (€), pièces du lot]. Au-delà du dernier seuil, pas de lot. */
const CO_LOTS = [[1.0, 6], [2.5, 4], [8.0, 2]];
/** Ce qui dit « menu » dans une catégorie ou un nom de produit. */
const CO_MENU_RE = '/\b(menus?|formules?)\b/iu';

/* ---------------------------------------------------------------------------
 * 1. Les objectifs
 * ------------------------------------------------------------------------- */

/** Une cible saisie (nombre, « 40 », « 12,5 ») ramenée à ses bornes ; null quand elle est vide ou illisible. */
function coCible(string $cle, mixed $v): ?float
{
    $d = CO_OBJECTIFS[$cle] ?? null;
    if ($d === null) { return null; }
    if (is_string($v)) { $v = str_replace([',', ' ', '%', '€'], ['.', '', '', ''], trim($v)); }
    if ($v === null || $v === '' || !is_numeric($v)) { return null; }
    $x = (float) $v;
    return $x < $d['min'] || $x > $d['max'] ? null : round($x, 2);
}

/** Les cibles d'un coach, toutes les clés présentes (null = non suivi). */
function coObjectifsNormaux(mixed $o): array
{
    $o = is_array($o) ? $o : [];
    $out = [];
    foreach (array_keys(CO_OBJECTIFS) as $k) { $out[$k] = coCible($k, $o[$k] ?? null); }
    return $out;
}

/**
 * Les cibles de départ d'un coach (AM_COACHS) : ses cibles food cost, personnel, articles par ticket et panier, quand il
 * en a, suivent celles du réseau, la même variable partout (18/11/2026 : foodCostCible, personnelCible, articlesCible,
 * panierCible, src/cibles_reseau.php) ; clients scannés et menus telles qu'écrites.
 */
function coObjectifsDepart(mixed $o): array
{
    $n = coObjectifsNormaux($o);
    if (!is_array($o)) { return $n; }
    if (array_key_exists('foodCost', $o) && function_exists('foodCostCible')) { $n['foodCost'] = coCible('foodCost', foodCostCible()); }
    if (array_key_exists('personnel', $o) && function_exists('personnelCible')) { $n['personnel'] = coCible('personnel', personnelCible()); }
    if (array_key_exists('articles', $o) && function_exists('articlesCible')) { $n['articles'] = coCible('articles', articlesCible()); }
    if (array_key_exists('panier', $o) && function_exists('panierCible')) { $n['panier'] = coCible('panier', panierCible()); }
    return $n;
}

/** Une cible dite comme à l'oral : « 40 % au plus », « 13 € au moins », « 3,5 au moins ». */
function coCibleTexte(string $cle, float $v): string
{
    $d = CO_OBJECTIFS[$cle];
    $n = rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
    return $n . ($d['unite'] !== '' ? ' ' . $d['unite'] : '') . ($d['sens'] === 'max' ? ' au plus' : ' au moins');
}

/** Le paragraphe des objectifs dans le personnage du coach ; vide s'il n'en suit aucun. */
function coPersona(array $objectifs): string
{
    $L = [];
    foreach (CO_OBJECTIFS as $k => $d) {
        if (($objectifs[$k] ?? null) === null) { continue; }
        $L[] = $d['lib'] . ' : ' . coCibleTexte($k, (float) $objectifs[$k]) . ' (' . $d['mesure'] . ')';
    }
    if ($L === []) { return ''; }
    $t = "\n\nTes objectifs 2026 pour ce magasin (réglés au cockpit) : " . implode(' ; ', $L) . '. '
        . "Leur état — la mesure du mois, l'écart, ce qu'il vaut — est dans le bloc « objectifs » joint à la fiche. "
        . "Tu les vérifies dans chaque bilan : l'objectif le plus loin de sa cible d'abord, puis le levier qui le rapproche.";
    if (($objectifs['foodCost'] ?? null) !== null) {
        $t .= " Pour le food cost, ton levier est le prix face à la concurrence : appelle prix_a_monter, et présente chaque hausse avec son lot.";
    }
    return $t;
}

/**
 * L'état des objectifs d'un coach pour un magasin : pour chaque cible posée, la mesure (le mois en cours, sinon le
 * mois dernier), l'écart et ce qu'il vaut en euros ou en clients. Pure : les mesures viennent de coMesures.
 */
function coEtat(array $objectifs, array $mesures): array
{
    $out = [];
    foreach (CO_OBJECTIFS as $k => $d) {
        $cible = $objectifs[$k] ?? null;
        if ($cible === null) { continue; }
        $m = is_array($mesures[$k] ?? null) ? $mesures[$k] : [];
        $v = $m['moisEnCours'] ?? null; $quand = 'mois en cours';
        if ($v === null && ($m['moisDernier'] ?? null) !== null) { $v = $m['moisDernier']; $quand = 'mois dernier'; }
        $e = ['objectif' => $d['lib'], 'cible' => coCibleTexte($k, (float) $cible), 'mesure' => $d['mesure']];
        if ($v === null) { $out[] = $e + ['etat' => 'pas mesurable : ' . ($m['motif'] ?? 'la source ne répond pas')]; continue; }
        $v = (float) $v;
        $ecart = round($v - (float) $cible, 2);
        $tenu = $d['sens'] === 'max' ? $v <= $cible : $v >= $cible;
        // Une mesure sur sa propre fenêtre (les clients scannés : la mémoire de la fidélité) la dit telle quelle.
        $per = isset($m['fenetre']) ? (string) $m['fenetre'] : $quand . (isset($m['periode']) && $quand !== 'mois dernier' ? ' (' . $m['periode'] . ')' : '');
        $n = rtrim(rtrim(number_format($ecart, 2, ',', ' '), '0'), ',');
        $e += ['valeur' => $v, 'periode' => $per,
            'moisDernier' => $quand === 'mois en cours' ? ($m['moisDernier'] ?? null) : null,
            'ecart' => $n . ($d['unite'] === '%' ? (abs($ecart) >= 2 ? ' points' : ' point') : ($d['unite'] === '€' ? ' €' : '')), 'atteint' => $tenu];
        if (!$tenu) {
            $base = $quand === 'mois en cours' ? ($m['base'] ?? null) : ($m['baseMoisDernier'] ?? null);
            $e['ceQueVautLEcart'] = coValeurEcart($k, $v, (float) $cible, is_array($base) ? $base : []);
        }
        $out[] = $e;
    }
    return $out;
}

/** Ce que vaut l'écart d'un objectif non tenu, en euros ou en clients, sur la période mesurée ; null sans base. */
function coValeurEcart(string $k, float $v, float $cible, array $b): ?string
{
    $f = static fn (float $x) => number_format(round($x), 0, ',', ' ');
    $ca = isset($b['ca']) ? (float) $b['ca'] : null;
    $tk = isset($b['tickets']) ? (float) $b['tickets'] : null;
    return match ($k) {
        'foodCost' => $ca ? 'environ ' . $f(($v - $cible) / 100 * $ca) . ' € de coût matière au-dessus de la cible sur la période' : null,
        'personnel' => $ca ? 'environ ' . $f(($v - $cible) / 100 * $ca) . ' € de salaires au-dessus de la cible sur la période' : null,
        'clientsScannes' => $tk ? 'environ ' . $f(($cible - $v) / 100 * $tk) . ' tickets de plus à rattacher à un client sur la période' : null,
        'menus' => $ca ? 'environ ' . $f(($cible - $v) / 100 * $ca) . ' € de menus en plus sur la période' : null,
        'articles' => $tk ? 'environ ' . $f(($cible - $v) * $tk) . ' articles de plus sur la période' : null,
        'panier' => $tk ? 'environ ' . $f(($cible - $v) * $tk) . ' € de CA en plus à trafic égal sur la période' : null,
        default => null,
    };
}

/* ---------------------------------------------------------------------------
 * 2. Les mesures du magasin
 * ------------------------------------------------------------------------- */

/** Le premier et le dernier jour du mois dernier. */
function coMoisDernier(string $auj): array
{
    $du = date('Y-m-01', strtotime(date('Y-m-01', strtotime($auj)) . ' -1 month'));
    return [$du, date('Y-m-t', strtotime($du))];
}

/**
 * Le food cost d'une période (heatmap de marge du panel, comme ep_perf) : {pct, ca, cout, source} ; pct null quand le
 * panel ne chiffre pas la marge et que les tickets gravés ne portent pas 80 % du CA.
 */
function coFoodCost(int $sid, string $du, string $au): array
{
    $hm = class_exists('PanelApi') ? PanelApi::marginHeatmapEntre($sid, $du, $au) : null;
    $t = is_array($hm) ? ($hm['totals'] ?? null) : null;
    $ca = is_array($t) && is_numeric($t['ca'] ?? null) ? (float) $t['ca'] : null;
    if ($ca === null || $ca <= 0) { return ['pct' => null, 'ca' => $ca, 'motif' => 'heatmap de marge illisible ou sans vente']; }
    $mg = is_numeric($t['margin_value'] ?? null) ? (float) $t['margin_value'] : null;
    if ($mg !== null) { return ['pct' => round(($ca - $mg) / $ca * 100, 1), 'ca' => round($ca, 2), 'cout' => round($ca - $mg, 2), 'source' => 'heatmap de marge du panel']; }
    if (function_exists('svMatiereJour')) {
        $caE = 0.0; $matE = 0.0; $zero = 0;
        foreach ((array) ($hm['days'] ?? []) as $d) {
            $caJ = (float) ($d['ca'] ?? 0);
            if ($caJ <= 0 || empty($d['has_data'])) { continue; }
            $e = svMatiereJour($sid, (string) ($d['date'] ?? ''), $zero, 0);
            if ($e !== null) { $caE += $caJ; $matE += (float) $e['estime']; }
        }
        if ($caE > 0 && $caE >= 0.8 * $ca) { return ['pct' => round($matE / $caE * 100, 1), 'ca' => round($ca, 2), 'cout' => round($matE / $caE * $ca, 2), 'source' => 'coût des recettes vendues (tickets gravés)']; }
    }
    return ['pct' => null, 'ca' => round($ca, 2), 'motif' => 'le panel ne chiffre pas le coût matière de la période'];
}

/** La main-d'œuvre d'une période (resCoutsPeriode) ; null quand un jour manque. $budget : lectures du panel permises. */
function coSalaires(int $sid, string $du, string $au, int $budget, string $auj): ?float
{
    if (!function_exists('resCoutsPeriode')) { return null; }
    try { $co = resCoutsPeriode($sid, $du, $au, $budget, $auj); } catch (Throwable $e) { return null; }
    return $co['manque'] === 0 && $co['jours'] > 0 && $co['sansPlanning'] === 0 ? round((float) $co['lab'], 2) : null;
}

/**
 * La part du CA des menus et formules d'une période, depuis l'historique des ventes : {pct, ca, caMenus, produits}.
 * $produits : les lignes de hvProduits ; $catalogue : svCatalogue (pid => {cat}). Pure.
 */
function coMenus(array $produits, array $catalogue): array
{
    $tot = 0.0; $men = 0.0; $noms = [];
    foreach ($produits as $x) {
        [$pid, , $nom, , $v] = $x;
        $tot += (float) $v;
        $cat = (string) ($catalogue[$pid]['cat'] ?? '');
        if (preg_match(CO_MENU_RE, $cat . ' ' . $nom)) { $men += (float) $v; $noms[$nom] = ($noms[$nom] ?? 0) + (float) $v; }
    }
    arsort($noms);
    if ($tot <= 0) { return ['pct' => null, 'motif' => 'aucune vente lue sur la période']; }
    if ($noms === []) { return ['pct' => 0.0, 'ca' => round($tot, 2), 'caMenus' => 0.0, 'produits' => [], 'note' => 'aucun produit « menu » ou « formule » vendu (catégorie ou nom)']; }
    return ['pct' => round($men / $tot * 100, 1), 'ca' => round($tot, 2), 'caMenus' => round($men, 2), 'produits' => array_slice(array_keys($noms), 0, 4)];
}

/**
 * Les mesures des objectifs d'un magasin, pour la fiche de faits. $ventes : le bloc `ventes` de la fiche (KPI du
 * panel) ; $fidelite : le résultat de fpMagasin, ou null.
 */
function coMesures(int $sid, array $ventes, ?array $fidelite): array
{
    $auj = date('Y-m-d');
    $du = date('Y-m-01');
    [$duP, $auP] = coMoisDernier($auj);
    $perEnCours = 'du ' . date('j', strtotime($du)) . ' au ' . date('j', strtotime($auj));
    $m = [];
    $fc = coFoodCost($sid, $du, $auj);
    $fcP = coFoodCost($sid, $duP, $auP);
    $m['foodCost'] = ['moisEnCours' => $fc['pct'], 'moisDernier' => $fcP['pct'], 'periode' => $perEnCours,
        'base' => ['ca' => $fc['ca'] ?? null], 'baseMoisDernier' => ['ca' => $fcP['ca'] ?? null],
        'source' => $fc['source'] ?? ($fcP['source'] ?? null)] + ($fc['pct'] === null && $fcP['pct'] === null ? ['motif' => $fc['motif'] ?? 'illisible'] : []);
    $lab = coSalaires($sid, $du, date('Y-m-t'), 12, $auj);
    $labP = coSalaires($sid, $duP, $auP, 0, $auj);
    $pc = static fn (?float $s, ?float $ca) => $s !== null && $ca ? round($s / $ca * 100, 1) : null;
    $m['personnel'] = ['moisEnCours' => $pc($lab, $fc['ca'] ?? null), 'moisDernier' => $pc($labP, $fcP['ca'] ?? null), 'periode' => $perEnCours,
        'salairesMoisEnCours' => $lab, 'base' => ['ca' => $fc['ca'] ?? null], 'baseMoisDernier' => ['ca' => $fcP['ca'] ?? null], 'source' => 'P&L jour par jour du panel'];
    if ($m['personnel']['moisEnCours'] === null && $m['personnel']['moisDernier'] === null) { $m['personnel']['motif'] = 'des journées sans salaires ou sans planning au panel'; }
    if (is_array($fidelite) && (int) ($fidelite['tickets'] ?? 0) > 0) {
        $t = (int) $fidelite['tickets']; $i = (int) ($fidelite['identifies'] ?? 0);
        $m['clientsScannes'] = ['moisEnCours' => round($i / $t * 100, 1), 'fenetre' => ($fidelite['memoire'] ?? 90) . ' derniers jours',
            'tickets' => $t, 'rattaches' => $i, 'base' => ['tickets' => $t], 'source' => 'Fidélité › Profils'];
    } else {
        $m['clientsScannes'] = ['moisEnCours' => null, 'motif' => 'tickets de la fidélité pas encore lus'];
    }
    if (function_exists('hvProduits')) {
        $hier = date('Y-m-d', strtotime('-1 day'));
        $cat = function_exists('svCatalogue') ? svCatalogue() : [];
        $me = $hier >= $du ? coMenus(hvProduits([$sid], $du, $hier)['produits'], $cat) : ['pct' => null, 'motif' => 'premier jour du mois'];
        $meP = coMenus(hvProduits([$sid], $duP, $auP)['produits'], $cat);
        $m['menus'] = ['moisEnCours' => $me['pct'], 'moisDernier' => $meP['pct'], 'periode' => 'jusqu’à hier',
            'base' => ['ca' => $me['ca'] ?? null], 'baseMoisDernier' => ['ca' => $meP['ca'] ?? null],
            'produitsMenus' => $me['produits'] ?? ($meP['produits'] ?? []), 'source' => 'historique des ventes'] + array_filter(['note' => $me['note'] ?? ($meP['note'] ?? null), 'motif' => $me['motif'] ?? null]);
    }
    $k = is_array($ventes['moisEnCours'] ?? null) ? $ventes['moisEnCours'] : null;
    $base = $k ? ['tickets' => $k['tickets']] : null;
    $m['articles'] = ['moisEnCours' => $k ? $k['articlesParTicket'] : null, 'periode' => $perEnCours, 'base' => $base, 'source' => 'KPI du panel'];
    $m['panier'] = ['moisEnCours' => $k ? $k['panier'] : null, 'periode' => $perEnCours, 'base' => $base, 'source' => 'KPI du panel'];
    return $m;
}

/* ---------------------------------------------------------------------------
 * 3. Les propositions de prix face à la concurrence
 * ------------------------------------------------------------------------- */

/** Un nom ramené à ses lettres : minuscules, sans accents ni ponctuation (BM_NORM du dashboard). */
function coNorm(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
        'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', '’' => ' ', "'" => ' ']);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
}

const CO_VIDES = ['avec', 'sans', 'pour', 'aux', 'des', 'les', 'une', 'piece', 'pieces', 'ind', 'mini', 'grand', 'petit', 'nature', 'sucre'];

/** Les mots qui portent un produit (bmMots du dashboard). */
function coMots(string $nom): array
{
    $w = array_values(array_filter(explode(' ', coNorm($nom)), static fn ($x) => strlen($x) >= 4 && !in_array($x, CO_VIDES, true)));
    if ($w !== []) { return $w; }
    $t = explode(' ', coNorm($nom));
    return [$t[0] ?? ''];
}

/**
 * Les règles de rapprochement de l'onglet Concurrence (BM_TOP_REGLES de dashboard.js) : [notre produit, le relevé,
 * ce qui le contredit]. Les relevés du terrain portent des noms courts (« chocolat », « sandw »).
 */
const CO_REGLES = [
    ['/pain au chocolat nappe|chocolat nappe/', '/nappe|double|dubbel|glace/', '/mini/'],
    ['/mini.*croissant/', '/mini croissant/', '/jambon|amande/'],
    ['/mini.*chocolat/', '/mini (pain au )?chocolat|mini couque/', '/nappe/'],
    ['/pain au chocolat|chocolatine/', '/^(pain au )?chocolat$|pain au chocolat|chocoladekoek|chocolatine/', '/nappe|mini|double|dubbel|amande|croissant/'],
    ['/^croissant$/', '/^croissant( beurre| nature)?$/', '/mini|jambon|amande|creme|fromage/'],
    ['/^sandwich$/', '/^sandw(ich)?( mou| nature| blanc| wit)?$|^petit pain|^pain sandwich$|^zachte sandwich/', '/garni|plateau/'],
    ['/baguette/', '/baguette/', '/garni|poulet|jambon|mini|sandwich|plateau|demie|demi/'],
    ['/pistolet/', '/pistolet/', '/garni|jambon|fromage|mini/'],
    ['/pain dore|pain blanc/', '/pain (dore|blanc)|wit brood|witbrood|carre blanc|pain de mie/', '/mini|sandwich/'],
    ['/cereale/', '/cereal|granen|multigrain/', '/mini|sandwich|couque/'],
    ['/demi gris|pain gris/', '/demi gris|pain gris|grijs/', '/mini/'],
    ['/couque au beurre/', '/couque au beurre|boterkoek/', '/mini/'],
];
const CO_GMS_RE = '/delhaize|carrefour|colruyt|aldi|lidl|intermarch|\bmatch\b|\bspar\b|okay|cora|proxy|bio-planet|albert heijn|\bah\b|jumbo/i';

/** Un relevé de supermarché (hors médiane des boulangeries). */
function coEstGms(array $r): bool
{
    return (bool) preg_match('/gms|super|hyper/i', (string) ($r['type'] ?? '')) || (bool) preg_match(CO_GMS_RE, (string) ($r['concurrent'] ?? ''));
}

/** Le relevé $r porte-t-il notre produit {id, nom} ? Le lien posé dans TFBuddy d'abord, puis les règles, puis les mots. */
function coCorrespond(array $p, array $r): bool
{
    if (($r['monProduit'] ?? null) !== null && (int) $r['monProduit'] === (int) $p['id']) { return true; }
    $n = coNorm((string) $p['nom']); $rn = coNorm((string) ($r['produit'] ?? ''));
    if ($rn === '') { return false; }
    // Les familles saisonnières et les plateaux ont leurs propres comparaisons (au kilo, au diamètre).
    foreach (['cougn', 'galette', 'frangipane', 'plateau'] as $w) { if (str_contains($rn, $w) && !str_contains($n, $w)) { return false; } }
    foreach (CO_REGLES as [$nous, $oui, $non]) {
        if (preg_match($nous, $n)) { return (bool) preg_match($oui, $rn) && !preg_match($non, $rn); }
    }
    foreach (coMots((string) $p['nom']) as $w) { if ($w === '' || !str_contains($rn, $w)) { return false; } }
    return true;
}

/** La médiane d'une liste de nombres ; null si elle est vide. */
function coMediane(array $v): ?float
{
    $v = array_values(array_filter($v, 'is_numeric')); sort($v);
    $n = count($v);
    if ($n === 0) { return null; }
    return round($n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2, 2);
}

/** Les pièces du lot qui garde le prix actuel ; null au-delà du dernier seuil (produit d'occasion, pas de lot). */
function coLotPieces(float $prix): ?int
{
    foreach (CO_LOTS as [$seuil, $n]) { if ($prix < $seuil) { return $n; } }
    return null;
}

/** Un prix en euros, à la belge : « 1,30 € ». */
function coEur(float $v): string
{
    return number_format($v, 2, ',', ' ') . ' €';
}

/**
 * Les propositions : pour chacun de nos produits vendus sur la période, la médiane des relevés de boulangeries qui
 * le portent ; s'il est vendu plus de 5 % en dessous (deux relevés au moins), le prix proposé (la médiane, arrondie
 * aux 5 cents sans la dépasser) et le lot qui garde le prix actuel. Pure.
 *
 * @param list<array{id:int, nom:string, cat?:string, prixTtc:?float, prixHt:?float, cout:?float}> $produits  pmProduits
 * @param list<array> $releves  relevés {produit, concurrent, type, prix, promo, monProduit}
 * @param array<int, array{q:float, v:float}> $volumes  pid => quantité et ventes TTC sur la période
 */
function coPropositions(array $produits, array $releves, array $volumes): array
{
    $props = []; $auPrix = 0; $auDessus = 0; $peu = 0; $sans = 0;
    $paye = static fn (array $r) => (float) ($r['promo'] ?? 0) > 0 ? (float) $r['promo'] : (float) ($r['prix'] ?? 0);
    foreach ($produits as $p) {
        $vol = $volumes[(int) $p['id']] ?? null;
        $prix = (float) ($p['prixTtc'] ?? 0);
        if ($vol === null || $vol['q'] <= 0 || $prix <= 0) { continue; }
        $R = array_values(array_filter($releves, static fn ($r) => $paye($r) > 0 && !coEstGms($r) && coCorrespond($p, $r)));
        if ($R === []) { $sans++; continue; }
        if (count($R) < CO_RELEVES_MIN) { $peu++; continue; }
        $v = array_map($paye, $R);
        $med = coMediane($v);
        $ecart = round(($prix - $med) / $med * 100, 1);
        if ($ecart > 5) { $auDessus++; continue; }
        if ($ecart >= CO_SEUIL_SOUS) { $auPrix++; continue; }
        $nouveau = floor($med * 20 + 1e-6) / 20;
        if ($nouveau <= $prix) { $auPrix++; continue; }
        $n = coLotPieces($prix);
        $ht = (float) ($p['prixHt'] ?? 0); $cout = (float) ($p['cout'] ?? 0);
        $fcAvant = $ht > 0 && $cout > 0 ? round($cout / $ht * 100, 1) : null;
        $fcApres = $ht > 0 && $cout > 0 ? round($cout / ($ht * $nouveau / $prix) * 100, 1) : null;
        $props[] = [
            'produit' => (string) $p['nom'], 'categorie' => (string) ($p['cat'] ?? ''),
            'prixActuel' => round($prix, 2), 'medianeBoulangeries' => $med, 'releves' => count($v),
            'fourchette' => coEur(min($v)) . ' – ' . coEur(max($v)), 'ecartPct' => $ecart,
            'prixPropose' => round($nouveau, 2), 'hausse' => round($nouveau - $prix, 2),
            'lot' => $n === null ? null : ['pieces' => $n, 'prix' => round($n * $prix, 2), 'dit' => $n . ' pour ' . coEur($n * $prix) . ' (le prix actuel à la pièce)',
                'economieClient' => round($n * ($nouveau - $prix), 2)],
            'venduSurLaPeriode' => round($vol['q'], 1), 'ventesSurLaPeriode' => round($vol['v'], 2),
            'gainMaxSurLaPeriode' => round($vol['q'] * ($nouveau - $prix), 2),
            'foodCostProduit' => $fcAvant !== null ? ['avant' => $fcAvant, 'apres' => $fcApres] : null,
        ];
    }
    usort($props, static fn ($a, $b) => $b['gainMaxSurLaPeriode'] <=> $a['gainMaxSurLaPeriode']);
    return ['propositions' => $props, 'auPrixDuMarche' => $auPrix, 'auDessus' => $auDessus, 'unSeulReleve' => $peu, 'sansReleve' => $sans];
}

/**
 * Les relevés de prix des concurrents d'un magasin : la lecture du Coach Vente (aivBenchmark : celle de l'onglet
 * Concurrence si elle a moins d'une semaine, sinon gardée un jour), sinon le module Benchmark de TFBuddy, lu ici ;
 * null si rien ne se lit.
 */
function coReleves(int $sid): ?array
{
    if (function_exists('aivBenchmark')) {
        $B = aivBenchmark($sid);
        if (is_array($B) && is_array($B['releves'] ?? null)) {
            return array_map(static fn ($r) => ['produit' => (string) ($r['produit'] ?? ''), 'concurrent' => (string) ($r['concurrent'] ?? ''), 'type' => (string) ($r['type'] ?? ''),
                'prix' => (float) ($r['prix'] ?? 0), 'promo' => isset($r['promo']) ? (float) $r['promo'] : null,
                'monProduit' => isset($r['monProduit']) ? (int) $r['monProduit'] : null], $B['releves']);
        }
    }
    if (!class_exists('BenchmarkApi') || !BenchmarkApi::disponible()) { return null; }
    $C = BenchmarkApi::concurrents($sid, true);
    if ($C === null) { return null; }
    $out = [];
    foreach ($C as $c) {
        if (isset($c['is_active']) && !$c['is_active']) { continue; }
        foreach ((array) ($c['observations'] ?? []) as $o) {
            foreach ((array) BenchmarkApi::items($sid, (int) ($o['id'] ?? 0)) as $i) {
                $out[] = ['produit' => trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', (string) ($i['name_raw'] ?? ''))),
                    'concurrent' => (string) ($c['name'] ?? ''), 'type' => (string) ($c['type'] ?? ''),
                    'prix' => (float) ($i['price'] ?? 0), 'promo' => !empty($i['is_promo']) ? (float) ($i['promo_price'] ?? 0) : null,
                    'monProduit' => isset($i['my_product_id']) ? (int) $i['my_product_id'] : null];
            }
        }
    }
    return $out;
}

/**
 * Les propositions d'un magasin, gardées CO_PRIX_TTL : {lu, periode, propositions, …} ou {erreur}. $faits : la fiche
 * du magasin, pour relier le gain au food cost du mois.
 */
function coPrixMagasin(int $sid, array $faits = []): array
{
    $out = function_exists('aiaCache') ? aiaCache('coachPrix:' . $sid, CO_PRIX_TTL, static fn () => coPrixCalcul($sid)) : coPrixCalcul($sid);
    if (!is_array($out)) { return ['erreur' => 'les propositions de prix ne se calculent pas pour le moment']; }
    if (isset($out['erreur'])) { return $out; }
    // Le food cost du mois, à volume égal : le coût matière ne bouge pas, le CA monte du gain.
    $fc = $faits['mesures']['foodCost'] ?? null;
    $pct = is_array($fc) ? ($fc['moisEnCours'] ?? $fc['moisDernier'] ?? null) : null;
    $ca = (float) ($out['caSurLaPeriode'] ?? 0); $gain = (float) ($out['gainMaxSurLaPeriode'] ?? 0);
    if ($pct !== null && $ca > 0 && $gain > 0) {
        $out['foodCostMagasin'] = ['actuel' => (float) $pct, 'apresBorneHaute' => round((float) $pct * $ca / ($ca + $gain), 1),
            'calcul' => 'food cost × CA ÷ (CA + gain), sur les ' . CO_PRIX_JOURS . ' derniers jours'];
    }
    return $out;
}

/** Le calcul des propositions d'un magasin (sans la fiche) ; une erreur se rend sans être gardée. */
function coPrixCalcul(int $sid): ?array
{
    $R = coReleves($sid);
    if ($R === null) { error_log('prix_a_monter : relevés illisibles pour le magasin ' . $sid); return null; }
    $P = function_exists('pmProduits') ? pmProduits($sid) : null;
    if ($P === null) { error_log('prix_a_monter : prix et coûts illisibles pour le magasin ' . $sid); return null; }
    $au = date('Y-m-d', strtotime('-1 day')); $du = date('Y-m-d', strtotime('-' . CO_PRIX_JOURS . ' days'));
    $vol = []; $ca = 0.0;
    foreach (hvProduits([$sid], $du, $au)['produits'] as $x) {
        [$pid, $por, , $q, $v] = $x;
        $ca += (float) $v;
        if ((int) $por !== 0) { continue; }   // une portion (½, ¼) a son propre prix : la pièce entière seule
        $vol[(int) $pid] = ['q' => ($vol[(int) $pid]['q'] ?? 0) + (float) $q, 'v' => ($vol[(int) $pid]['v'] ?? 0) + (float) $v];
    }
    $r = coPropositions($P, $R, $vol);
    $gain = array_sum(array_column($r['propositions'], 'gainMaxSurLaPeriode'));
    $out = ['lu' => date('Y-m-d H:i'), 'periode' => 'du ' . $du . ' au ' . $au . ' (' . CO_PRIX_JOURS . ' jours)', 'releves' => count($R),
        'regle' => 'produits vendus plus de 5 % sous la médiane des boulangeries concurrentes du magasin (hors supermarchés, ' . CO_RELEVES_MIN . ' relevés au moins) : '
            . 'le prix monte à la médiane, arrondi aux 5 cents sans la dépasser ; le lot garde le prix actuel à la pièce pour qui prend plus',
        'caSurLaPeriode' => round($ca, 2), 'gainMaxSurLaPeriode' => round($gain, 2),
        'gainMax' => 'borne haute : si tout le volume passe au nouveau prix ; ce qui part en lot reste au prix actuel, avec plus de pièces par ticket'] + $r;
    return $out;
}

/** L'outil prix_a_monter, tel que le modèle le voit. Texte fixe : il fait partie du préfixe mis en cache. */
function coOutils(): array
{
    return [['name' => 'prix_a_monter',
        'description' => 'Les produits du magasin du franchisé vendus plus de 5 % sous la médiane des boulangeries concurrentes autour de lui (relevés de prix du module Benchmark, hors supermarchés, deux relevés au moins). '
            . 'Pour chacun : le prix actuel, la médiane, le prix proposé (la médiane arrondie aux 5 cents), le lot qui garde le prix actuel à la pièce pour qui prend plus, '
            . 'les ventes des 30 derniers jours, le gain au plus et le food cost du produit avant et après ; et l’effet sur le food cost du magasin. '
            . 'À appeler pour le food cost, la marge, les hausses de prix, ou « que puis-je améliorer ? ». Pour voir tous les relevés d’un produit : prix_concurrence.',
        'input_schema' => ['type' => 'object', 'properties' => [
            'categorie' => ['type' => 'string', 'description' => 'Ne garder qu’une catégorie ou un produit (un mot suffit : « viennoiserie », « croissant »). Absent : tous.'],
            'limite' => ['type' => 'integer', 'description' => 'Combien de propositions rendre, la plus rentable d’abord : de 1 à 20, 8 par défaut.'],
        ]]]];
}

/** L'outil prix_a_monter : les propositions du magasin qui demande, filtrées et limitées. */
function coOutilPrix(int $sid, array $in, array $faits = []): array
{
    $r = coPrixMagasin($sid, $faits);
    if (isset($r['erreur'])) { return $r; }
    $lim = max(1, min(20, (int) ($in['limite'] ?? 8)));
    $f = coNorm((string) ($in['categorie'] ?? ''));
    $L = $r['propositions'];
    if ($f !== '') { $L = array_values(array_filter($L, static fn ($p) => str_contains(coNorm($p['categorie'] . ' ' . $p['produit']), $f))); }
    $r['propositionsTotal'] = count($r['propositions']);
    $r['propositions'] = array_slice($L, 0, $lim);
    return $r;
}
