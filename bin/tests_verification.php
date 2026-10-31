#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la Vérification, lot 1 — les neuf règles des recettes douteuses ou manquantes.
 * Sans base, sans réseau : vfRegles() est une fonction pure, nourrie ici d'un petit réseau fictif ; le parcours
 * complet (ventes gravées → produits → recettes lues par lots → cache → registre → cron) tourne sur des doublures
 * de la base, du panel et des réglages, comme bin/tests.php.
 *
 *   php bin/tests_verification.php
 */

// --- doublures : la base (réglages, magasins, registre), le panel, les matières -------------------------------
final class Db
{
    public static array $reglages = [];
    public static array $registre = [];
    public static array $shops = [];
    public static function rows(string $sql, array $p = []): array
    {
        if (str_contains($sql, 'FROM shops')) { return self::$shops; }
        if (str_contains($sql, 'FROM ceo_verif_recette')) { return array_values(self::$registre); }
        return [];
    }
    public static function row(string $sql, array $p = []): ?array
    {
        if (str_contains($sql, 'FROM ceo_verif_recette') && str_contains($sql, 'COUNT(*)')) {
            $r = array_filter(self::$registre, static fn ($x) => $x['resolu_le'] !== null && $x['resolu_le'] >= $p[0]);
            return ['n' => count($r), 'd' => $r === [] ? null : min(array_column($r, 'resolu_le'))];
        }
        return null;
    }
    public static function exec(string $sql, array $p = []): int
    {
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { self::$reglages[$p[0]] = $p[1]; return 1; }
        if (str_starts_with($sql, 'INSERT INTO ceo_verif_recette')) {
            self::$registre[$p[0]] = ['cle' => $p[0], 'regle' => $p[1], 'premier_vu' => $p[8], 'dernier_vu' => $p[9], 'resolu_le' => null];
            return 1;
        }
        if (str_starts_with($sql, 'UPDATE ceo_verif_recette SET resolu_le')) { self::$registre[$p[1]]['resolu_le'] = $p[0]; return 1; }
        return 0;
    }
}
function setting(string $cle, mixed $defaut = null): mixed
{
    return array_key_exists($cle, Db::$reglages) ? json_decode(Db::$reglages[$cle], true) : $defaut;
}
function analyseListe($r): array { return is_array($r) ? (array_is_list($r) ? $r : (array) ($r['data'] ?? [])) : []; }
final class PanelApi
{
    public static ?string $lastError = null;
    public static array $dispo = [];       // sid => lignes products/available
    public static array $routes = [];      // chemin => réponse
    public static int $lectures = 0;       // recettes lues (…/cost)
    public static function configured(): bool { return true; }
    public static function config(): array { return ['base' => 'https://atelierby.tfbuddy.com/api/v1']; }
    public static function coutsMatiere(): array { return []; }
    public static function produitsDisponibles(int $sid): array { return self::$dispo[$sid] ?? []; }
    public static function get(string $chemin): mixed { return self::$routes[$chemin] ?? null; }
    public static function getParallele(array $chemins, int $front = 4, int $timeout = 25): array
    {
        $out = [];
        foreach ($chemins as $k => $c) { if (str_ends_with($c, '/cost')) { self::$lectures++; } $out[$k] = self::$routes[$c] ?? null; }
        return $out;
    }
}
function mfMatieresApi(array $sids, bool $frais): array
{
    $out = [];
    foreach ($sids as $sid) { $out[$sid] = []; foreach ((array) (PanelApi::$routes['/shops/' . $sid . '/materials'] ?? []) as $m) { $out[$sid][(string) $m['id']] = ['prix' => $m['base_unit_price_net']]; } }
    return $out;
}

// La clôture de la Production, simulée : pour un magasin et un jour, les lignes que rend ep_production_flux_cloture.
$CLOTURE = []; $APPELS_CLOTURE = 0;
function ep_production_flux_cloture(): array
{
    global $CLOTURE, $APPELS_CLOTURE;
    $APPELS_CLOTURE++;
    $k = ($_GET['shop'] ?? '') . ':' . ($_GET['date'] ?? '');
    return $CLOTURE[$k] ?? ['lignes' => [], 'ventesLues' => true, 'enregistree' => false, 'cuissonsValidees' => 0, 'cuissons' => 2, 'poubelleLue' => true];
}

require __DIR__ . '/../src/verification.php';
require __DIR__ . '/../src/verification_flux.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  ÉCHEC  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$mags = [4 => 'Halle', 3 => 'Gosselies', 2 => 'Corbais', 5 => 'Sombreffe'];
$prod = static fn (int $pid, string $nom, ?int $rid, ?float $cout, ?float $prixHt, array $x = []) => array_merge(
    ['pid' => $pid, 'nom' => $nom, 'cat' => $x['cat'] ?? 'Catégorie', 'recette' => $rid, 'cout' => $cout, 'prixHt' => $prixHt, 'divisible' => false, 'part' => null], $x);

// Les produits disponibles, par magasin.
$D = [];
foreach ([4, 3, 2, 5] as $sid) {
    $D[$sid] = [
        100 => $prod(100, 'Pâtes Amatriciana', null, null, 6.13),
        101 => $prod(101, 'Cookie Noix & Caramel', 11, 0.0, 2.08),
        102 => $prod(102, 'Merveilleux', 12, 1.10, 3.40),
        103 => $prod(103, 'Wrapps - Poulet & Parmesan', 13, 6.85, 5.50),
        104 => $prod(104, 'FlipFlap - Thon', 14, ['4' => 0.79, '3' => 3.73, '2' => 3.91, '5' => 3.80][(string) $sid], 4.67),
        105 => $prod(105, 'Pain au Chocolat Nappé', 15, 26.40, 1.51),
        106 => $prod(106, 'Pain Tradition - Olives', 16, 1.10, 3.87),
        107 => $prod(107, '1/4 - Quiches', 17, 0.90, 3.96, ['divisible' => true, 'part' => null]),
        108 => $prod(108, 'Croissant', 18, 0.31, 1.13),
    ];
}
// Les ventes de la période : tout se vend partout, sauf le Merveilleux qui ne se vend qu'à Sombreffe et à Halle.
$V = [];
foreach ([4, 3, 2, 5] as $sid) {
    $V[$sid] = [];
    foreach ([100, 101, 103, 104, 105, 106, 107, 108] as $pid) { $V[$sid][$pid] = ['ca' => 100.0 + $pid, 'q' => 10.0, 'nom' => $D[$sid][$pid]['nom']]; }
}
$V[5][102] = ['ca' => 300.0, 'q' => 80.0, 'nom' => 'Merveilleux'];
$V[4][102] = ['ca' => 200.0, 'q' => 55.0, 'nom' => 'Merveilleux'];
$V[3][999] = ['ca' => 0.0, 'q' => 0.0, 'nom' => 'Rien vendu'];   // un CA nul n'est pas jugé

$ligne = static fn (int $mid, string $nom, float $q, string $u, float $cout) => ['mid' => $mid, 'nom' => $nom, 'q' => $q, 'u' => $u, 'prix' => null, 'src' => 'LOCAL', 'cout' => $cout, 'sous' => null];
$R = [
    11 => ['sid' => 4, 'nom' => 'Cookie Noix & Caramel', 'rend' => 1.0, 'coutNet' => 0.0, 'complet' => false, 'lignes' => []],
    12 => ['sid' => 5, 'nom' => 'Merveilleux', 'rend' => 1.0, 'coutNet' => 1.10, 'complet' => true, 'lignes' => [$ligne(900, 'Sucre glace', 0.03, 'kg', 0.05), $ligne(901, 'Crème 35 %', 0.05, 'l', 0.2), $ligne(902, 'Blancs d’œufs', 0.04, 'l', 0.85)]],
    13 => ['sid' => 4, 'nom' => 'Wrapps', 'rend' => 1.0, 'coutNet' => 6.85, 'complet' => true, 'lignes' => [$ligne(903, 'Tortilla', 1, 'pcs', 2.4), $ligne(904, 'Poulet', 0.1, 'kg', 2.45), $ligne(905, 'Sauce', 1, 'l', 2.0)]],
    14 => ['sid' => 4, 'nom' => 'FlipFlap Thon', 'rend' => 1.0, 'coutNet' => 0.79, 'complet' => true, 'lignes' => [$ligne(906, 'Thon à l’huile', 0.07, 'kg', 0.6), $ligne(901, 'Crème 35 %', 0.02, 'l', 0.19)]],
    15 => ['sid' => 4, 'nom' => 'Pain au Chocolat Nappé', 'rend' => 1.0, 'coutNet' => 26.40, 'complet' => true, 'lignes' => [$ligne(907, 'Bâtons chocolat', 2, 'kg', 26.0), $ligne(908, 'Pâte feuilletée', 0.06, 'kg', 0.4)]],
    16 => ['sid' => 4, 'nom' => 'Pain Olives', 'rend' => 0.0, 'coutNet' => 1.10, 'complet' => true, 'lignes' => [$ligne(909, 'Farine T65', 0.3, 'kg', 0.21), $ligne(910, 'Olives', 0.1, 'kg', 0.89)]],
    17 => ['sid' => 4, 'nom' => 'Quiche', 'rend' => 1.0, 'coutNet' => 0.90, 'complet' => true, 'lignes' => [$ligne(911, 'Œufs entiers', 4, 'pcs', 0.8), $ligne(909, 'Farine T65', 0.1, 'kg', 0.1)]],
    18 => ['sid' => 4, 'nom' => 'Croissant', 'rend' => 1.0, 'coutNet' => 0.31, 'complet' => true, 'lignes' => [$ligne(909, 'Farine T65', 0.05, 'kg', 0.04), $ligne(912, 'Beurre de tourage', 0.03, 'kg', 0.27)]],
];
// Le prix des matières : le sucre glace n'a pas de prix à Sombreffe ; tout le reste en a un.
$M = [];
foreach ([4, 3, 2, 5] as $sid) { $M[$sid] = []; foreach (range(900, 912) as $mid) { $M[$sid][$mid] = 1.0; } }
$M[5][900] = null;
// Le stock : du sucre perlé (jamais en recette) et des sachets (emballage, hors recette) à Gosselies, de la farine (employée).
$S = [3 => [
    'm950' => ['mid' => 950, 'nom' => 'Sucre perlé', 'cat' => 'Sucres', 'q' => 3.5, 'u' => 'kg'],
    'm951' => ['mid' => 951, 'nom' => 'Sachets papier', 'cat' => 'Emballages', 'q' => 400, 'u' => 'pcs'],
    'm909' => ['mid' => 909, 'nom' => 'Farine T65', 'cat' => 'Farines', 'q' => 95, 'u' => 'kg'],
], 4 => [], 2 => [], 5 => []];
$M[3][950] = 3.20;

$a = vfRegles($mags, $V, $D, $R, $M, $S, 0.05, 30);
$par = [];
foreach ($a['lignes'] as $l) { $par[$l['regle']][] = $l; }
$regles = static fn (int $pid) => array_values(array_map(static fn ($l) => $l['regle'], array_filter($a['lignes'], static fn ($l) => $l['pid'] === $pid)));

echo "Les règles\n";
verifie('pas de recette : Pâtes Amatriciana, dans les quatre magasins', $regles(100) === ['sans'] && count($par['sans'][0]['magasins']) === 4);
verifie('recette vide : Cookie Noix & Caramel', $regles(101) === ['vide'], implode(',', $regles(101)));
verifie('matière sans prix : Merveilleux, à Sombreffe seulement', $regles(102) === ['prix'] && $par['prix'][0]['magasins'] === ['Sombreffe'], json_encode($par['prix'] ?? null, JSON_UNESCAPED_UNICODE));
verifie('le détail nomme la matière sans prix', str_contains($par['prix'][0]['detail'] ?? '', 'Sucre glace'));
verifie('coût invraisemblable : Wrapps (coût au-dessus du prix), sans unité suspecte', $regles(103) === ['inv'], implode(',', $regles(103)));
verifie('hors norme du réseau : FlipFlap - Thon à Halle seulement', $regles(104) === ['reseau'] && $par['reseau'][0]['magasins'] === ['Halle']);
verifie('unité suspecte : Pain au Chocolat Nappé, le coût invraisemblable n’est pas compté en plus', $regles(105) === ['unite'], implode(',', $regles(105)));
verifie('l’unité suspecte nomme la ligne lourde', str_contains($par['unite'][0]['detail'] ?? '', 'Bâtons chocolat'));
verifie('rendement à zéro : Pain Tradition - Olives', $regles(106) === ['rend'], implode(',', $regles(106)));
verifie('portion sans fraction : 1/4 - Quiches', $regles(107) === ['port'], implode(',', $regles(107)));
verifie('une recette juste ne sort pas : Croissant', $regles(108) === []);
verifie('matière jamais en recette : le sucre perlé, pas les sachets ni la farine', count($par['orph'] ?? []) === 1 && ($par['orph'][0]['nom'] ?? '') === 'Sucre perlé');
verifie('la matière orpheline porte sa valeur de stock', abs((float) ($par['orph'][0]['valeur'] ?? 0) - 11.2) < 0.01);

echo "Ordre et couverture\n";
verifie('les matières orphelines ferment la liste', end($a['lignes'])['regle'] === 'orph');
$cas = array_map(static fn ($l) => $l['ca'], array_filter($a['lignes'], static fn ($l) => $l['regle'] !== 'orph'));
$tri = $cas; rsort($tri);
verifie('le reste est classé par CA concerné, du plus grand au plus petit', array_values($cas) === $tri);
$caTot = 0.0; foreach ($V as $ps) { foreach ($ps as $v) { $caTot += $v['ca']; } }
$caFiable = 4 * 208.0 + 200.0 + 3 * 204.0;   // le croissant partout, le merveilleux à Halle (son prix y est), le thon hors de Halle
verifie('le CA total compte toutes les ventes', abs($a['couverture']['caTotal'] - $caTot) < 0.01);
verifie('le CA fiable : le croissant partout, le merveilleux à Halle, le thon hors de Halle', abs($a['couverture']['caFiable'] - $caFiable) < 0.01, (string) $a['couverture']['caFiable']);
verifie('fiable + manquant = total', abs($a['couverture']['caFiable'] + $a['couverture']['caManquant'] - $caTot) < 0.01);
verifie('toutes les recettes sont lues : rien à lire, orphelines jugées', $a['aLire'] === [] && $a['recettes']['orphJugees'] === true);

echo "Recettes pas encore lues\n";
$b = vfRegles($mags, $V, $D, [], [], [], 0.05, 30);
verifie('sans recette lue : les règles de structure attendent, la liste à lire est complète', count($b['aLire']) === 8 && !isset(array_column($b['lignes'], null, 'regle')['vide']));
verifie('sans recette lue : pas de jugement des matières orphelines', $b['recettes']['orphJugees'] === false);
verifie('sans recette lue : le coût invraisemblable du Wrapps se voit déjà', in_array('inv', array_map(static fn ($l) => $l['regle'], array_filter($b['lignes'], static fn ($l) => $l['pid'] === 103)), true));
verifie('la recette se lit dans le magasin qui vend le plus', ($b['aLire'][12] ?? null) === 5);

echo "Une recette ramenée à ses lignes\n";
$c = vfRecetteCompacte(['name' => 'Tarte', 'yield_quantity' => 2, 'cost_net' => 4.0, 'cost_complete' => true, 'elements' => [
    ['type' => 'ingredient', 'material_id' => 1, 'name' => 'Farine', 'required_quantity' => 0.4, 'unit_name' => 'kg', 'price_net' => 1.0, 'calculated_req_price_net' => 0.4],
    ['type' => 'sub-recipe', 'name' => 'Crème', 'elements' => [['type' => 'ingredient', 'material_id' => 2, 'name' => 'Lait', 'required_quantity' => 1, 'unit_name' => 'l', 'price_net' => 0.9]]],
]], 4);
verifie('le rendement divise les quantités et les coûts', abs($c['lignes'][0]['q'] - 0.2) < 1e-9 && abs($c['lignes'][0]['cout'] - 0.2) < 1e-9 && abs((float) $c['coutNet'] - 2.0) < 1e-9);
verifie('une sous-recette est dépliée et nommée', ($c['lignes'][1]['sous'] ?? '') === 'Crème' && abs($c['lignes'][1]['cout'] - 0.45) < 1e-9);
verifie('le prix HT de la pièce entière d’un produit divisible', abs((float) vfPrixHt(['is_divisible' => 1, 'portion_size' => 0.25, 'portion_price_net' => 1.0]) - 4.0) < 1e-9);

echo "Formules et promotions\n";
$oui = [['Lunch ( salade ou baguette garnie + boisson + dessert)', 'Bundle'], ['Panier du jour - 15€', 'Bundle'], ['2 Cookies', ''], ['2 pains classiques', ''],
    ['Sandwich (10 + 5)', ''], ['4 Tendresses', ''], ['Promotie - 1.5 euros', ''], ['2x Merveilleux', ''], ['5 +1 | Mini-Viennoiserie', ''], ['1 croissant + 1 café', ''], ['Croissant', 'Viennoiserie', 'Promotions']];
$non = [['1/2 - Quiches', ''], ['1/4', ''], ['1/2 - Chocolade', ''], ['Croissant', 'Viennoiserie Ind.'], ['Pain 10 Céréales', 'Pain'], ['Halloumi & Légumes grillés', ''], ['Plateau mini sandwiches - 30 pièces', 'Plateau Sandwich']];
verifie('les formules et promotions sont reconnues (catégorie, groupe ou forme du nom)', array_filter($oui, static fn ($x) => !vfEstFormule($x[0], $x[1], $x[2] ?? '')) === [],
    implode(' | ', array_map(static fn ($x) => $x[0], array_filter($oui, static fn ($x) => !vfEstFormule($x[0], $x[1], $x[2] ?? '')))));
verifie('les portions et les produits ordinaires n’en sont pas', array_filter($non, static fn ($x) => vfEstFormule($x[0], $x[1])) === [],
    implode(' | ', array_map(static fn ($x) => $x[0], array_filter($non, static fn ($x) => vfEstFormule($x[0], $x[1])))));
$Ff = [100 => true];   // les pâtes jouent la formule
$af = vfRegles($mags, $V, $D, $R, $M, $S, 0.05, 30, $Ff);
$lf = array_values(array_filter($af['lignes'], static fn ($l) => $l['pid'] === 100));
verifie('la ligne d’une formule est marquée, les autres non', ($lf[0]['formule'] ?? null) === true && count(array_filter($af['lignes'], static fn ($l) => !empty($l['formule']))) === 1);
verifie('une formule sans recette sort en « formule sans ses produits », pas en « pas de recette »', ($lf[0]['regle'] ?? '') === 'form' && count($lf) === 1 && str_contains($lf[0]['detail'], 'prix réduit'));
$h = $af['couverture']['horsFormules'];
verifie('la couverture hors formules retire leur CA du total et du manquant', abs($h['caTotal'] - ($af['couverture']['caTotal'] - 4 * 200.0)) < 0.01 && abs($h['caManquant'] - ($af['couverture']['caManquant'] - 4 * 200.0)) < 0.01);
verifie('hors formules, le CA fiable ne change pas (la formule n’était pas fiable)', abs($h['caFiable'] - $af['couverture']['caFiable']) < 0.01 && $h['pct'] > $af['couverture']['pct']);
verifie('le poids des formules est rendu', abs($af['couverture']['formules']['ca'] - 800.0) < 0.01 && $af['couverture']['formules']['produits'] === 1);

echo "Bout en bout, sur les doublures\n";
Db::$shops = [['id' => 4, 'name' => 'Atelier by - Halle'], ['id' => 3, 'name' => 'Atelier by Max & Sandra - Gosselies']];
// Les ventes gravées par la moisson : chaque jour, un croissant et un FlipFlap au thon dans les deux magasins, des pâtes à Gosselies.
for ($i = 1; $i <= 30; $i++) {
    $j = date('Y-m-d', strtotime('-' . $i . ' day'));
    foreach ([4, 3] as $sid) {
        $p = ['8' => ['2130006' => ['Croissant', 10, 12.0, 3.1], '2300010' => ['FlipFlap - Thon', 4, 22.0, null]]];
        if ($sid === 3) { $p['12'] = ['2400001' => ['Pâtes Amatriciana', 3, 19.5, null], '2400002:77' => ['Tarte — 1/2', 2, 14.0, 4.4], '5100003' => ['Lunch', 1, 12.0, null]]; }
        Db::$reglages['svP' . $sid . ':' . $j] = json_encode(['quand' => time(), 'p' => $p]);
    }
}
Db::$reglages['svP4:' . date('Y-m-d')] = json_encode(['quand' => time(), 'p' => ['9' => ['2130006' => ['Croissant', 99, 999.0, 1]]]]);   // aujourd'hui : exclu
$ligneDispo = static fn (int $pid, string $nom, ?int $rid, ?float $cout, float $prixNet, array $x = []) => array_merge(['id' => $pid, 'base_name' => $nom, 'base_category_name' => 'Catégorie', 'id_recipe' => $rid,
    'recipe_cost_net' => $cout, 'portion_price_net' => $prixNet, 'is_divisible' => 0, 'is_active' => 1], $x);
foreach ([4, 3] as $sid) {
    PanelApi::$dispo[$sid] = [
        $ligneDispo(2130006, 'Croissant', 573, 0.31, 1.13),
        $ligneDispo(2300010, 'FlipFlap - Thon', 574, $sid === 4 ? 0.79 : 3.80, 4.67),
        $ligneDispo(2400001, 'Pâtes Amatriciana', null, null, 6.13),
        $ligneDispo(2400002, 'Tarte', 575, 8.85, 3.0, ['is_divisible' => 1, 'portion_size' => 0.5]),
        $ligneDispo(5100003, 'Lunch ( salade ou baguette garnie + boisson + dessert)', null, null, 11.0, ['base_category_name' => 'Bundle']),
    ];
    PanelApi::$routes['/shops/' . $sid . '/materials'] = [['id' => 1, 'base_unit_price_net' => 0.7], ['id' => 2, 'base_unit_price_net' => 8.6], ['id' => 3, 'base_unit_price_net' => 26.43], ['id' => 4, 'base_unit_price_net' => 1.1]];
    PanelApi::$routes['/shops/' . $sid . '/material-inventory'] = [['id_material' => 1, 'material_name' => 'Farine T65', 'category_name' => 'Farines', 'current_quantity' => 50, 'unit_name' => 'kg', 'exist_in_inventory' => 1]];
}
$el = static fn (int $mid, string $nom, float $q, string $u, float $prix) => ['type' => 'ingredient', 'material_id' => $mid, 'name' => $nom, 'required_quantity' => $q, 'unit_name' => $u, 'price_net' => $prix, 'calculated_req_price_net' => $q * $prix, 'price_source' => 'LOCAL'];
PanelApi::$routes['/shops/4/recipes/573/cost'] = ['name' => 'Croissant', 'yield_quantity' => 1, 'cost_net' => 0.31, 'cost_complete' => true, 'elements' => [$el(1, 'Farine T65', 0.05, 'kg', 0.7), $el(2, 'Beurre de tourage', 0.032, 'kg', 8.6)]];
PanelApi::$routes['/shops/4/recipes/574/cost'] = ['name' => 'FlipFlap Thon', 'yield_quantity' => 1, 'cost_net' => 0.79, 'cost_complete' => true, 'elements' => [$el(3, 'Thon à l’huile', 0.07, 'kg', 26.43)]];
PanelApi::$routes['/shops/3/recipes/575/cost'] = ['name' => 'Tarte', 'yield_quantity' => 1, 'cost_net' => 8.85, 'cost_complete' => true, 'elements' => [$el(4, 'Sucre', 0.1, 'kg', 1.1)]];

$_GET = [];
$r1 = ep_verification_recettes();
$regles1 = array_count_values(array_column($r1['lignes'], 'regle'));
verifie('la première ouverture lit les recettes (budget de l’écran) et rend la liste', PanelApi::$lectures === 3 && ($r1['recettes']['complet'] ?? false) === true, json_encode($r1['recettes'] ?? null));
verifie('pas de recette : les pâtes, à Gosselies', ($regles1['sans'] ?? 0) === 1 && ($r1['lignes'][array_search('sans', array_column($r1['lignes'], 'regle'), true)]['magasins'] ?? []) === ['Gosselies']);
verifie('hors norme : le thon à Halle n’a que deux magasins — pas de médiane des autres, pas de signalement', !isset($regles1['reseau']));
verifie('coût invraisemblable : la tarte, coût de la pièce entière au-dessus du prix de la pièce', ($regles1['inv'] ?? 0) === 1, json_encode($regles1));
verifie('la farine en stock est employée : aucune matière orpheline', !isset($regles1['orph']));
verifie('les ventes d’aujourd’hui sont exclues', abs($r1['couverture']['caTotal'] - 30 * (2 * (12 + 22) + 19.5 + 14 + 12)) < 0.01, (string) $r1['couverture']['caTotal']);
verifie('le lunch (catégorie Bundle) sort en « formule sans ses produits », marqué formule', count(array_filter($r1['lignes'], static fn ($l) => $l['pid'] === 5100003 && $l['regle'] === 'form' && $l['formule'] === true)) === 1);
verifie('la couverture hors formules est rendue avec la réponse', abs(($r1['couverture']['horsFormules']['caTotal'] ?? 0) - 30 * (2 * (12 + 22) + 19.5 + 14)) < 0.01);
verifie('chaque ligne dit depuis quand elle est repérée', count(array_filter($r1['lignes'], static fn ($l) => ($l['depuis'] ?? '') === date('Y-m-d'))) === count($r1['lignes']));
verifie('le registre garde chaque signalement', count(Db::$registre) === count($r1['lignes']));
$r2 = ep_verification_recettes();
verifie('la deuxième ouverture sert le cache, sans relire le panel', PanelApi::$lectures === 3 && is_array($r2['cache'] ?? null));
// La recette de la tarte est corrigée au panel (portion de 1/2 : prix de la pièce 6 €) ; le cron repasse.
foreach ([4, 3] as $sid) { PanelApi::$dispo[$sid][3]['portion_price_net'] = 6.0; PanelApi::$dispo[$sid][3]['recipe_cost_net'] = 2.1; }
$bilan = vfCron();
$r3 = vfCache('vfAnalyse', 3600)['v'] ?? [];
verifie('le cron recalcule et le dit en une phrase', str_contains($bilan, 'signalement') && str_contains($bilan, 'couverture'), $bilan);
verifie('la tarte corrigée disparaît de la liste', !in_array('inv', array_column($r3['lignes'] ?? [], 'regle'), true));
verifie('le registre la note résolue aujourd’hui', count(array_filter(Db::$registre, static fn ($x) => $x['resolu_le'] === date('Y-m-d'))) === 1 && ($r3['corrigees']['n'] ?? 0) === 1);

echo "Lot 2 — audit des flux et fuites\n";
$lx = static fn (int $pid, string $nom, string $cat, float $prix, float $s0, float $so, int $ok, float $vd, float $jd, float $r, ?float $co = null) =>
    ['pid' => $pid, 'nom' => $nom, 'cat' => $cat, 'groupe' => 'G-' . $cat, 'prix' => $prix, 'garde' => $r > 0, 'vie' => 'S', 'report0' => $s0, 'sorti' => $so, 'sortiValide' => (bool) $ok,
     'vendu' => $vd, 'jeteDeclare' => $jd, 'report' => $r, 'compte' => $co];
$a = vxVide();
vxAjouteLigne($a, ['p' => 1, 'n' => 'Croissant', 'c' => 'Vien', 'g' => 'V', 'x' => 1.2, 'k' => 0, 'v' => 'S', 's0' => 0, 'so' => 100, 'ok' => 1, 'vd' => 80, 'jd' => 6, 'r' => 0, 'co' => null]);
vxAjouteLigne($a, ['p' => 2, 'n' => 'Pain', 'c' => 'Pain', 'g' => 'P', 'x' => 3.0, 'k' => 1, 'v' => 'M', 's0' => 4, 'so' => 20, 'ok' => 1, 'vd' => 18, 'jd' => 0, 'r' => 5, 'co' => 5]);
$L = vxLecture($a);
verifie('disponible = gardé d’hier + sorti, en pièces et au prix de vente', abs($L['pcs']['dispo'] - 124) < 1e-9 && abs($L['eur']['dispo'] - (100 * 1.2 + 24 * 3)) < 1e-9);
verifie('non expliqué = disponible − vendu − poubelle − invendu', abs($L['pcs']['nonExpl'] - (124 - 98 - 6 - 5)) < 1e-9);
verifie('les taux se lisent en % du disponible, au prix de vente', abs($L['taux']['nonExpl'] - round(100 * (14 * 1.2 + 1 * 3.0) / 192, 1)) < 1e-9, json_encode($L['taux']));
verifie('tout mesuré : la part mesurée vaut 100 % et le verdict juge la fuite', $L['mesure'] === 100.0 && $L['verdict']['ton'] === 'ko', json_encode($L['verdict'], JSON_UNESCAPED_UNICODE));
$b = vxVide();
vxAjouteLigne($b, ['p' => 1, 'n' => 'Croissant', 'c' => 'Vien', 'g' => 'V', 'x' => 1.2, 'k' => 0, 'v' => 'S', 's0' => 0, 'so' => 100, 'ok' => 0, 'vd' => 80, 'jd' => 6, 'r' => 0, 'co' => null]);
$Lb = vxLecture($b);
verifie('cuissons non validées : « Non mesuré », un écart au plan et pas une fuite', $Lb['verdict']['ton'] === 'mu' && $Lb['verdict']['nom'] === 'Non mesuré' && $Lb['tons']['nonExpl'] === 'mu' && str_contains($Lb['verdict']['pourquoi'], 'écart au plan'));
$c2 = vxVide();
vxAjouteLigne($c2, ['p' => 1, 'n' => 'Pain', 'c' => 'Pain', 'g' => 'P', 'x' => 2.0, 'k' => 0, 'v' => 'S', 's0' => 0, 'so' => 100, 'ok' => 1, 'vd' => 110, 'jd' => 0, 'r' => 0, 'co' => null]);
$Lc = vxLecture($c2);
verifie('vendu plus que produit (écart négatif) : signalé, jugé sur sa valeur absolue', $Lc['verdict']['nom'] === 'Vendu plus que produit' && $Lc['tons']['nonExpl'] === 'ko');
[$du, $au, $js] = vxBornes('semaine', '2026-10-21');
verifie('une semaine va du lundi au dimanche', $du === '2026-10-19' && $au === '2026-10-25' && count($js) === 7);
[$du, $au, $js] = vxBornes('mois', '2026-09-14');
verifie('un mois va du premier au dernier jour', $du === '2026-09-01' && $au === '2026-09-30' && count($js) === 30);
[$du, $au, $js] = vxBornes('mois', date('Y-m-d'));
verifie('le mois en cours s’arrête à hier', $au === max($du, date('Y-m-d', strtotime('-1 day'))));
verifie('une journée passée gravée après sa fin, même signature : bonne', vxGraveValide(['quand' => time(), 'sig' => 's', 'j' => []], date('Y-m-d', strtotime('-20 day')), 's'));
verifie('une validation faite après coup (signature changée) la fait recalculer', !vxGraveValide(['quand' => time(), 'sig' => 's', 'j' => []], date('Y-m-d', strtotime('-20 day')), 'autre'));
verifie('une des sept dernières journées, gravée hier : recalculée aujourd’hui', !vxGraveValide(['quand' => strtotime('-1 day'), 'sig' => 's', 'j' => []], date('Y-m-d', strtotime('-3 day')), 's'));

// Le parcours : deux magasins, une semaine ; Halle valide ses cuissons, Gosselies non.
$sem = vxBornes('semaine', date('Y-m-d', strtotime('-10 day')))[2];
Db::$shops = [['id' => 4, 'name' => 'Atelier by - Halle'], ['id' => 3, 'name' => 'Atelier by Max & Sandra - Gosselies']];
foreach ($sem as $j) {
    $CLOTURE['4:' . $j] = ['ventesLues' => true, 'enregistree' => true, 'cuissonsValidees' => 2, 'cuissons' => 2, 'poubelleLue' => true,
        'lignes' => [$lx(1, 'Croissant', 'Viennoiserie', 1.2, 0, 100, 1, 90, 5, 0), $lx(2, 'FlipFlap - Club', 'Sandwiches', 5.0, 0, 20, 1, 12, 0, 0)]];
    $CLOTURE['3:' . $j] = ['ventesLues' => true, 'enregistree' => false, 'cuissonsValidees' => 0, 'cuissons' => 3, 'poubelleLue' => true,
        'lignes' => [$lx(1, 'Croissant', 'Viennoiserie', 1.2, 0, 80, 0, 70, 2, 0), $lx(3, 'Pain Demi-Gris', 'Pain', 2.9, 3, 20, 0, 18, 0, 4)]];
}
$_GET = ['vue' => 'semaine', 'date' => $sem[2]];
$APPELS_CLOTURE = 0;
$f = ep_verification_flux();
$h = array_values(array_filter($f['magasins'], static fn ($m) => $m['id'] === '4'))[0];
$g = array_values(array_filter($f['magasins'], static fn ($m) => $m['id'] === '3'))[0];
verifie('l’audit réseau calcule les 14 journées (2 magasins × 7 jours)', $APPELS_CLOTURE === 14 && $f['manquants'] === 0 && count($h['parJour']) === 7);
verifie('Halle (cuissons validées) : mesuré, la fuite des sandwichs est jugée', $h['mesure'] === 100.0 && $h['verdict']['ton'] === 'ko', json_encode($h['verdict'], JSON_UNESCAPED_UNICODE));
verifie('Gosselies (rien de validé) : « Non mesuré »', $g['verdict']['nom'] === 'Non mesuré' && $g['mesure'] === 0.0);
verifie('le réseau somme les deux magasins', abs($f['reseau']['eur']['dispo'] - ($h['eur']['dispo'] + $g['eur']['dispo'])) < 0.2);
$APPELS_CLOTURE = 0;
$f2 = ep_verification_flux();
verifie('la deuxième ouverture lit les journées gravées, sans rien recalculer', $APPELS_CLOTURE === 0 && abs($f2['reseau']['eur']['dispo'] - $f['reseau']['eur']['dispo']) < 0.01);
$_GET = ['vue' => 'jour', 'date' => $sem[2], 'shop' => '4'];
$fj = ep_verification_flux();
verifie('un magasin, un jour : produit par produit, les plus gros écarts d’abord', count($fj['produits'] ?? []) === 2 && $fj['produits'][0]['nom'] === 'FlipFlap - Club' && ($fj['journee']['clotureEnregistree'] ?? false) === true);
verifie('un magasin : par catégorie sur la période', count($fj['categories'] ?? []) === 2);
$_GET = ['vue' => 'semaine', 'date' => $sem[2]];
$fu = ep_verification_fuites();
$cats4 = array_column(array_values(array_filter($fu['magasins'], static fn ($m) => $m['id'] === '4'))[0]['categories'], 'cat');
$cats3 = array_column(array_values(array_filter($fu['magasins'], static fn ($m) => $m['id'] === '3'))[0]['categories'], 'cat');
verifie('fuites : un tableau par magasin, mêmes catégories dans le même ordre', $cats4 === $cats3 && count($cats4) === 3, json_encode([$cats4, $cats3], JSON_UNESCAPED_UNICODE));
$fh = array_values(array_filter($fu['magasins'], static fn ($m) => $m['id'] === '4'))[0];
$sand = array_values(array_filter($fh['categories'], static fn ($c) => $c['cat'] === 'Sandwiches'))[0];
verifie('le delta d’une catégorie est la somme des non-expliqués de ses journées (8 pièces × 7 jours)', abs($sand['pcs']['nonExpl'] - 56) < 1e-9 && abs($sand['eur']['nonExpl'] - 280) < 1e-9);
verifie('une catégorie que le magasin ne vend pas reste dans son tableau, vide', count(array_filter($fh['categories'], static fn ($c) => $c['cat'] === 'Pain' && $c['pcs']['dispo'] == 0)) === 1);
verifie('la matrice croise catégories et magasins', count($fu['matrice']) === 3 && isset($fu['matrice'][0]['magasins']['4'], $fu['matrice'][0]['magasins']['3']));
$_GET = [];

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko > 0 ? 1 : 0);
