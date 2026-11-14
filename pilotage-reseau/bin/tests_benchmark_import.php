#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de Benchmark › Import, Classement, Zones — sans base, sans réseau, sans clé.
 *
 *   php bin/tests_benchmark_import.php
 *
 * L'API Benchmark de TFBuddy est simulée comme dans tests_benchmark.php (ses manies comprises), le catalogue de
 * Sombreffe reprend des produits et prix réels du panel (lus le 14/11/2026), et l'API Anthropic est remplacée par un
 * transport qui rejoue des réponses. Ce qui se vérifie : les codes postaux et les zones, le classement d'un
 * concurrent sur ses magasins, le rapprochement avec nos produits, les comparaisons (pièce, lot, kilo, surface,
 * part), l'encodage rejouable, la boucle de la recherche web et la requête du PDF.
 */

final class Db
{
    public static array $exec = [];
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): int { self::$exec[] = [$sql, $a]; return 1; }
    public static function config(): array { return []; }
}
$GLOBALS['reglages'] = ['anthropic' => ['cle' => 'sk-test']];
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function journalAdd(string $actor, string $kind, ?string $project, string $message): void {}
function body(): array { return $GLOBALS['corps'] ?? []; }

/** L'API Benchmark simulée (comme tests_benchmark.php), /shops et le catalogue de Sombreffe. */
final class PanelApi
{
    public static ?string $lastError = null;
    public static array $comp = [], $obs = [], $items = [];
    public static int $seq = 100;
    public static function configured(): bool { return true; }
    public static function get(string $path): mixed
    {
        if ($path === '/shops') {
            return [['id' => 4, 'representative_name' => 'Atelier by - Halle', 'name' => 'Neutralles SRL', 'zip' => '1500', 'city' => 'Halle'],
                ['id' => 2, 'representative_name' => 'Atelier by Berlo - Corbais', 'name' => 'Berlo SRL', 'zip' => '1435', 'city' => 'Corbais'],
                ['id' => 5, 'representative_name' => 'Atelier by Harmonie - Sombreffe', 'name' => 'Harmonie SRL', 'zip' => '5140', 'city' => 'Sombreffe'],
                ['id' => 3, 'representative_name' => 'Atelier by Max & Sandra - Gosselies', 'name' => 'MS SRL', 'zip' => '6041', 'city' => 'Gosselies']];
        }
        return null;
    }
    public static function liste(array $d): array { return array_values(array_filter($d, 'is_array')); }
    public static function produitsDisponibles(int $shop): array
    {
        $p = fn (int $id, string $n, string $c, int $cat, string $prix, int $w = 0) => ['id' => $id, 'name' => $n, 'category_name' => $c, 'id_category' => $cat, 'portion_price_gross' => $prix, 'single_weight' => $w, 'is_active' => 1];
        return [
            $p(1610006, 'Croissant', 'Viennoiserie Ind.', 16100, '1.70'), $p(1610004, 'Pain au Chocolat', 'Viennoiserie Ind.', 16100, '1.80'),
            $p(1610009, 'Pain au Chocolat Nappé', 'Viennoiserie Ind.', 16100, '2.20'), $p(1610002, 'Suisse', 'Viennoiserie Ind.', 16100, '1.80'),
            $p(1610001, 'Croissant Amandes', 'Viennoiserie Ind.', 16100, '2.20'), $p(6700170, 'Couque au Beurre', 'Viennoiserie Ind.', 16100, '1.70', 90),
            $p(1620005, 'Mini - Croissant', 'Viennoiserie réduction ', 16200, '0.90'),
            $p(1200001, 'Pain Demi-Gris', 'Pain', 12000, '2.90', 600), $p(1200002, 'Pain 10 Céréales', 'Pain', 12000, '2.90', 600),
            $p(1210001, 'Pain au Noix & Sésame', 'Pain Tradition', 12100, '4.50', 600), $p(1300001, "Pistolet à l'Ancienne", 'Petite Boulangerie', 13000, '0.90', 60),
            $p(1300016, 'Baguettes (x2)', 'Petite Boulangerie', 13000, '3.99'), $p(1300003, 'Baguette Tradition', 'Petite Boulangerie', 13000, '2.30', 350),
            $p(6700246, 'Riz', 'Tartes – Ø 28 cm', 11500, '21.90', 1600), $p(6700228, 'Pommes Tranches', 'Tartes – Ø 28 cm', 11500, '21.90', 1500),
            $p(6700233, 'Fromage', 'Tartes – Ø 28 cm', 11500, '21.90', 1600), $p(2300014, 'Fromage Gouda Jeune', 'Sandwiches garnis', 23000, '5.80', 350),
            $p(1540001, 'éclair chocolat', 'Pâtisserie individuelle', 15400, '3.30'), $p(4100007, 'Bûche Chocolat - 4/6 personnes', 'Noël', 41000, '19.90'),
            $p(6700139, 'Cougnou Nature - 400 g.', 'Cougnou', 41000, '0.00', 400), $p(1510005, 'Entremet 120Ø -  Tiramisu', 'Entremets - 4/6 personnes', 15100, '15.90'),
        ];
    }
    public static function sonde(string $m, string $path, ?array $b = null, int $t = 15): array
    {
        $r = fn (int $c, mixed $corps) => ['code' => $c, 'corps' => $corps, 'ms' => 1, 'erreur' => $c >= 300 ? "HTTP $c" : null];
        $u = parse_url($path); $p = $u['path']; parse_str($u['query'] ?? '', $q);
        $inc = (string) ($q['include'] ?? '');
        $creer = function (array &$table, array $row) use ($r) { $id = ++self::$seq; $table[$id] = ['id' => $id] + $row; return $r(201, ['status' => 'success', 'inserted_id' => $id]); };
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors$#', $p, $x)) {
            if ($m === 'POST') { return $creer(self::$comp, ($b ?? []) + ['shop_id' => (int) $x[1]]); }
            $l = array_values(array_filter(self::$comp, fn ($c) => $c['shop_id'] === (int) $x[1]));
            if ($inc === 'observations') { foreach ($l as &$c) { $c['observations'] = array_values(array_filter(self::$obs, fn ($o) => $o['competitor_id'] === $c['id'])); } }
            return $r(200, $l);
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors/(\d+)/observations$#', $p, $x)) {
            if ($m === 'POST') { return $creer(self::$obs, ($b ?? []) + ['shop_id' => (int) $x[1], 'competitor_id' => (int) $x[2]]); }
            return $r(200, array_values(array_filter(self::$obs, fn ($o) => $o['competitor_id'] === (int) $x[2])));
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/observations/(\d+)/items$#', $p, $x)) {
            return $r(200, array_values(array_filter(self::$items, fn ($i) => $i['observation_id'] === (int) $x[2])));
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors/(\d+)/observations/(\d+)/items$#', $p, $x) && $m === 'POST') {
            // Comme TFBuddy : un prix nul n'entre pas, mais la réponse dit 201.
            if (!(($b['price'] ?? 0) > 0)) { return $r(201, ['status' => 'success', 'inserted_id' => 0]); }
            return $creer(self::$items, ($b ?? []) + ['shop_id' => (int) $x[1], 'competitor_id' => (int) $x[2], 'observation_id' => (int) $x[3]]);
        }
        return $r(404, ['status' => 'error']);
    }
}

require __DIR__ . '/../src/doc.php';
require __DIR__ . '/../src/benchmark.php';
require __DIR__ . '/../src/anthropic.php';
require __DIR__ . '/../src/benchmark_import.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$proche = fn (?float $a, float $b, float $e = 0.002) => $a !== null && abs($a - $b) < $e;

echo "Codes postaux et magasins\n";
verifie('le code postal d’une adresse', biCp('Chaussée de Gembloux 37, 5140 Tongrinne') === '5140');
verifie('ni 999, ni 51400, ni un numéro de rue', biCp('rue 37') === null && biCp('51400') === null && biCp('999') === null);
$mags = biMagasins();
verifie('quatre magasins, leur code postal lu au panel', array_column($mags, 'cp', 'id') === [2 => '1435', 3 => '6041', 4 => '1500', 5 => '5140'], json_encode(array_column($mags, 'cp', 'id')));
verifie('le franchisé tiré du nom du magasin, sinon la société', $mags[5]['franchise'] === 'Harmonie' && $mags[3]['franchise'] === 'Max & Sandra' && $mags[4]['franchise'] === 'Neutralles SRL', json_encode(array_column($mags, 'franchise', 'id')));

echo "Zones (plages de MétaDonnée)\n";
$z = biZones($mags);
verifie('par défaut : les codes à moins de 10 km de chaque boutique', count(array_filter($z, fn ($p) => $p['shopId'] === '5')) === 14 && count(array_filter($z, fn ($p) => $p['shopId'] === '4')) === 26);
$t = biClasser('5140', $z);
verifie('Tongrinne (5140) → Sombreffe seul', $t['statut'] === 'zone' && $t['magasins'] === [5] && str_contains($t['commune'], 'Sombreffe'), json_encode($t));
$g = biClasser('5030', $z);
$gm = $g['magasins']; sort($gm);
verifie('Gembloux (5030) → Sombreffe et Corbais', $gm === [2, 5], json_encode($g['magasins']));
verifie('Perwez (1360) → Corbais', biClasser('1360', $z)['magasins'] === [2]);
verifie('Gand (9000) → hors zone ; sans code postal → inconnu', biClasser('9000', $z)['statut'] === 'hors' && biClasser(null, $z)['statut'] === 'inconnu');
$net = biPlagesNettoyer([['de' => 5099, 'a' => 5000, 'shopId' => '5', 'libelle' => 'Namur'], ['de' => 999, 'a' => 999, 'shopId' => '5'], ['de' => 6000, 'a' => 6000, 'shopId' => '77']], $mags);
verifie('plages nettoyées : bornes remises dans l’ordre, magasin inconnu et code faux écartés', $net === [['de' => 5000, 'a' => 5099, 'shopId' => '5', 'libelle' => 'Namur']], json_encode($net));
$GLOBALS['reglages']['benchmark'] = ['zones' => $net];
verifie('une zone réglée remplace la zone par défaut', biClasser('5030', biZones($mags))['magasins'] === [5] && biClasser('1500', biZones($mags))['statut'] === 'hors');
unset($GLOBALS['reglages']['benchmark']);
verifie('code postal d’un concurrent : étiquette, « CP », ville connue', biCpConcurrent(['notes' => 'x [cp:5140]']) === '5140' && biCpConcurrent(['notes' => 'CP 1435']) === '1435' && biCpConcurrent(['city' => 'Gembloux']) === '5030');
verifie('supermarché national : pas de code postal à classer', biNational(['city' => 'national']) && !biNational(['city' => 'Namur']));

echo "Lignes lues\n";
$L = biLignesDepuisIa(['lignes' => [
    ['rubrique' => 'Viennoiseries', 'libelle' => 'Croissant pur beurre', 'prix' => 1.4, 'prix_promo' => null, 'promo_texte' => '', 'poids_g' => null, 'diametre_cm' => null, 'pieces' => null, 'parts' => null, 'page' => 2, 'source_url' => ''],
    ['rubrique' => 'Petits pains', 'libelle' => 'Pistolets', 'prix' => '4,20', 'prix_promo' => null, 'promo_texte' => '', 'poids_g' => null, 'diametre_cm' => null, 'pieces' => 6, 'parts' => null, 'page' => 1, 'source_url' => ''],
    ['rubrique' => '', 'libelle' => 'C?uque', 'prix' => null, 'prix_promo' => null, 'promo_texte' => '', 'poids_g' => null, 'diametre_cm' => null, 'pieces' => 1, 'parts' => null, 'page' => 1, 'source_url' => ''],
    ['rubrique' => '', 'libelle' => '', 'prix' => 3],
]]);
verifie('trois lignes (la ligne sans libellé tombe)', count($L) === 3);
verifie('« 4,20 » en texte lu comme 4,2 ; « les 6 » gardé ; une pièce seule n’est pas un lot', $L[1]['prix'] === 4.2 && $L[1]['pieces'] === 6 && $L[2]['pieces'] === null);
verifie('sans prix lisible : illisible', $L[2]['etat'] === 'illi' && $L[0]['etat'] === 'prix');
$c = biConcurrentDepuisIa(['concurrent' => ['nom' => 'DUMONT & PARTNERS', 'type' => 'artisan', 'adresse' => 'Chaussée de Gembloux 37', 'code_postal' => 'B-5140', 'ville' => 'Tongrinne', 'site' => '']], ['nom' => 'Dumont et Partners']);
verifie('le concurrent : le nom saisi l’emporte, le code postal est nettoyé', $c['nom'] === 'Dumont et Partners' && $c['cp'] === '5140' && $c['type'] === 'artisan');

echo "Rapprochement\n";
verifie('mots : « pur beurre » tombe, pluriels au singulier, « couque suisse » = suisse', biMots('Croissants pur beurre') === ['croissant'] && biMots('Croissant aux amandes') === ['croissant', 'amande'] && biMots('Couque suisse') === ['suisse']);
$cat = biCatalogue(5);
$lig = fn (string $lib, array $x = []) => $x + ['i' => 0, 'rubrique' => '', 'libelle' => $lib, 'prix' => 2.0, 'promo' => null, 'promoTexte' => '', 'g' => null, 'd' => null, 'pieces' => null, 'parts' => null, 'page' => 1, 'url' => '', 'etat' => 'prix'];
$nom = fn (?int $pid) => $pid === null ? null : (array_values(array_filter($cat, fn ($p) => $p['pid'] === $pid))[0]['nom'] ?? '?');
$cas = [
    ['Croissant pur beurre', [], 'Croissant', 'sur'], ['Pain au chocolat', [], 'Pain au Chocolat', 'sur'], ['Couque suisse', [], 'Suisse', 'sur'],
    ['Croissant aux amandes', [], 'Croissant Amandes', 'sur'], ['Éclair au chocolat', [], 'éclair chocolat', 'sur'],
    ['Tarte au riz', ['rubrique' => 'Tartes', 'd' => 26.0], 'Riz', 'sur'], ['Tarte au fromage', ['rubrique' => 'Tartes', 'd' => 26.0], 'Fromage', 'sur'],
    ['Pain gris 800 g', ['g' => 800.0], 'Pain Demi-Gris', 'conf'], ['Pain de mie sans croûte', [], null, 'sans'],
];
foreach ($cas as [$lib, $x, $attendu, $statut]) {
    $pr = biProposer($lig($lib, $x), $cat, []);
    verifie("« $lib » → " . ($attendu ?? 'sans équivalent') . " ($statut)", $nom($pr['pid']) === $attendu && $pr['statut'] === $statut, $nom($pr['pid']) . ' ' . $pr['statut'] . ' ' . $pr['score']);
}
$pr = biProposer($lig('Escargot aux raisins'), $cat, [biCleLibelle('Escargot aux raisins') => 1610001]);
verifie('la mémoire l’emporte : correspondance apprise, sûre', $pr['pid'] === 1610001 && $pr['appris'] && $pr['statut'] === 'sur');
$pr = biProposer($lig('Pistolets', ['pieces' => 6]), $cat, []);
verifie('« Pistolets » : le seul produit qui contient le mot, un mot de plus → sûr', $nom($pr['pid']) === "Pistolet à l'Ancienne" && $pr['statut'] === 'sur', json_encode($pr));
$pr = biProposer($lig('Tarte aux pommes', ['rubrique' => 'Tartes', 'd' => 26.0]), array_merge($cat, [biProduit(['id' => 6700232, 'name' => 'Pommes Parisienne', 'category_name' => 'Tartes – Ø 28 cm', 'portion_price_gross' => '21.90'])]), []);
verifie('deux tartes aux pommes au coude à coude : à confirmer', $pr['statut'] === 'conf' && count($pr['candidats']) >= 2, json_encode($pr['candidats']));
$pr = biProposer($lig('4 croissants + 1 offert', ['promo' => 1.12]), $cat, []);
verifie('promotion : « offert » ne compte pas, le croissant est retrouvé', $nom($pr['pid']) === 'Croissant' && $pr['statut'] === 'sur', json_encode($pr));
$pr = biProposer($lig('Pain au chocolat'), $cat, []);
verifie('« Pain au chocolat » ne prend pas le « nappé »', $nom($pr['pid']) === 'Pain au Chocolat');

echo "Comparaisons\n";
$P = []; foreach ($cat as $p) { $P[$p['nom']] = $p; }
$k = biComparer($lig('Pistolets', ['prix' => 4.2, 'pieces' => 6]), $P["Pistolet à l'Ancienne"]);
verifie('lot : les 6 à 4,20 € = 0,70 € la pièce, nous +28,6 %', $proche($k['eux'], 0.70) && $proche($k['ecart'], 0.2857, 0.001), json_encode($k));
$k = biComparer($lig('Pain gris 800 g', ['prix' => 3.3, 'g' => 800.0]), $P['Pain Demi-Gris']);
verifie('au kilo : 3,30 € les 800 g face à 2,90 € les 600 g', $k['unite'] === '/kg' && $proche($k['eux'], 4.125) && $proche($k['nous'], 4.833, 0.001), json_encode($k));
$k = biComparer($lig('Tarte au riz', ['prix' => 19.5, 'd' => 26.0]), $P['Riz']);
verifie('à surface égale : 19,50 € en Ø 26 = 22,62 € en Ø 28', $proche($k['eux'], 22.615, 0.01) && $k['nous'] === 21.9, json_encode($k));
$k = biComparer($lig('Bûche moka 6 pers.', ['prix' => 28.0, 'parts' => 6]), $P['Bûche Chocolat - 4/6 personnes']);
verifie('à la part : 28 € pour 6 face à 19,90 € pour 4/6 (5 parts)', $k['unite'] === '/part' && $proche($k['eux'], 4.667, 0.001) && $proche($k['nous'], 3.98), json_encode($k));
$k = biComparer($lig('Baguettes', ['prix' => 1.9]), $P['Baguettes (x2)']);
verifie('notre lot (x2) ramené à la pièce', $proche($k['nous'], 1.995), json_encode($k));
verifie('notre prix à 0 € au panel : pas de comparaison', biComparer($lig('Cougnou 500 g', ['prix' => 8.5, 'g' => 500.0]), $P['Cougnou Nature - 400 g.']) === ['manque' => true]);
verifie('Ø en mm dans le nom : 120Ø = 12 cm', $P['Entremet 120Ø -  Tiramisu']['d'] === 12.0 && $P['Riz']['d'] === 28.0);

echo "Décisions\n";
$sur = ['statut' => 'sur', 'pid' => 1610006]; $conf = ['statut' => 'conf', 'pid' => 1200001]; $sans = ['statut' => 'sans', 'pid' => null];
$l0 = $lig('x');
verifie('sûre : retenue ; à confirmer : attend ; sans équivalent : entre sans produit',
    biDecision($l0, $sur, [])['etat'] === 'produit' && biDecision($l0, $conf, [])['etat'] === 'attente' && biDecision($l0, $sans, [])['etat'] === 'sans');
verifie('le choix saisi l’emporte (produit, 0 = sans équivalent, −1 = ignorer)',
    biDecision($l0, $conf, ['0' => 1200002])['pid'] === 1200002 && biDecision($l0, $sur, ['0' => 0])['etat'] === 'sans' && biDecision($l0, $sur, ['0' => -1])['etat'] === 'ignore');

echo "Encodage dans TFBuddy\n";
$imp = ['id' => 7, 'source' => 'pdf', 'statut' => 'lu', 'titre' => 'Dumont et Partners', 'cree_le' => '2026-11-14 10:00:00', 'nom_origine' => 'tarif.pdf', 'octets' => 1, 'demande' => null,
    'concurrent' => ['nom' => 'Dumont et Partners', 'type' => 'artisan', 'adresse' => 'Chaussée de Gembloux 37', 'cp' => '5140', 'ville' => 'Tongrinne', 'site' => ''],
    'magasins' => [5], 'date_releve' => '2026-11-10', 'sources' => [], 'remarques' => '', 'modele' => 'claude-opus-5-5', 'jetons' => 0, 'encodage' => [], 'choix' => [],
    'lignes' => [
        $lig('Croissant pur beurre', ['i' => 0, 'prix' => 1.4, 'rubrique' => 'Viennoiseries']),
        $lig('Pistolets', ['i' => 1, 'prix' => 4.2, 'pieces' => 6]),
        $lig('Pain gris 800 g', ['i' => 2, 'prix' => 3.4, 'g' => 800.0]),
        $lig('Pain de mie sans croûte', ['i' => 3, 'prix' => 3.6]),
        $lig('C?uque', ['i' => 4, 'prix' => null, 'etat' => 'illi']),
        $lig('4 croissants + 1 offert', ['i' => 5, 'prix' => 1.4, 'promo' => 1.12, 'promoTexte' => '4 + 1 offert']),
    ]];
$v = biVue($imp);
verifie('la vue : 1 à confirmer (pain gris), 1 illisible, le reste retenu', $v['compte']['attente'] === 1 && $v['compte']['illi'] === 1 && $v['compte']['sans'] === 1, json_encode($v['compte']));
verifie('le classement du concurrent : Sombreffe', $v['classement']['magasins'] === [5] && $v['classement']['nomsMagasins'] === ['Sombreffe']);
$e = biEncoder($imp, false);
verifie('une ligne à confirmer bloque l’écriture', !$e['ok'] && str_contains($e['error'], 'à confirmer') && PanelApi::$comp === []);
$imp['choix'] = ['2' => 1200001];
$a = biEncoder($imp, true);
verifie('aperçu : un concurrent nouveau à Sombreffe, rien d’écrit', $a['ok'] && $a['apercu'] && $a['magasins'][0]['nouveau'] && PanelApi::$comp === [], json_encode($a['magasins']));
verifie('aperçu : 5 lignes (4 rattachées, 1 sans équivalent), l’illisible et l’ignorée restent dehors', $a['items'] === 5 && $a['magasins'][0]['avecProduit'] === 4, json_encode($a['magasins'][0]));
$e = biEncoder($imp, false);
verifie('écriture : tout relu', $e['ok'] && $e['magasins'][0]['etat'] === 'ok' && $e['magasins'][0]['relus'] === 5, json_encode($e['magasins']));
$cc = array_values(PanelApi::$comp)[0] ?? [];
verifie('le concurrent porte son code postal (notes « CP 5140 [cp:5140] »), son type, sa ville', str_contains((string) ($cc['notes'] ?? ''), '[cp:5140]') && ($cc['type'] ?? '') === 'Artisan' && ($cc['city'] ?? '') === 'Tongrinne', json_encode($cc));
$ob = array_values(PanelApi::$obs)[0] ?? [];
verifie('l’observation est datée du relevé et porte l’étiquette', ($ob['observed_at'] ?? '') === '2026-11-10' && str_contains((string) ($ob['notes'] ?? ''), '[cockpit:import:7]'));
$it = array_values(PanelApi::$items);
$pist = array_values(array_filter($it, fn ($i) => str_starts_with($i['name_raw'], 'Pistolets')))[0] ?? [];
verifie('le lot part avec son nombre de pièces', ($pist['unit_type'] ?? '') === 'piece' && (float) ($pist['unit_size'] ?? 0) === 6.0 && ($pist['my_product_id'] ?? 0) === 1300001, json_encode($pist));
$promo = array_values(array_filter($it, fn ($i) => !empty($i['is_promo'])))[0] ?? [];
verifie('la promotion : prix normal et prix promo', (float) ($promo['price'] ?? 0) === 1.4 && (float) ($promo['promo_price'] ?? 0) === 1.12);
$mie = array_values(array_filter($it, fn ($i) => str_starts_with($i['name_raw'], 'Pain de mie')))[0] ?? [];
verifie('sans équivalent : entre sans my_product_id', $mie !== [] && !isset($mie['my_product_id']));
verifie('la mémoire retient les lignes rattachées (concurrent et réseau)', count(array_filter(Db::$exec, fn ($x) => str_contains($x[0], 'ceo_bench_correspondance') && str_contains($x[0], 'INSERT'))) >= 8);
$n = count(PanelApi::$items);
$e2 = biEncoder($imp, false);
verifie('rejouer ne crée aucun doublon', $e2['magasins'][0]['etat'] === 'deja' && count(PanelApi::$items) === $n && count(PanelApi::$comp) === 1);
$imp2 = $imp; $imp2['magasins'] = []; $imp2['concurrent']['cp'] = '9000';
verifie('aucun magasin retenu : refusé avec la raison', !biEncoder($imp2, true)['ok']);

echo "Classement\n";
BenchmarkApi::creerConcurrent(5, ['name' => 'Boulangerie de Gosselies', 'type' => 'Artisan', 'city' => 'Gosselies', 'is_active' => true]);
BenchmarkApi::creerConcurrent(5, ['name' => 'Sans adresse', 'type' => 'Artisan', 'is_active' => true]);
$cl = biClassement();
$S = array_values(array_filter($cl['magasins'], fn ($m) => $m['id'] === 5))[0];
$parNom = []; foreach ($S['concurrents'] as $x) { $parNom[$x['nom']] = $x; }
verifie('Sombreffe : son franchisé, son code postal, sa zone', $S['franchise'] === 'Harmonie' && $S['cp'] === '5140' && $S['zone']['n'] === 14);
verifie('Dumont et Partners (5140) : dans la zone de Sombreffe', ($parNom['Dumont et Partners']['classement'] ?? '') === 'zone' && ($parNom['Dumont et Partners']['cp'] ?? '') === '5140');
verifie('un concurrent de Gosselies relevé pour Sombreffe : « ailleurs », chez Gosselies', ($parNom['Boulangerie de Gosselies']['classement'] ?? '') === 'ailleurs' && ($parNom['Boulangerie de Gosselies']['aussi'] ?? []) === ['Gosselies']);
verifie('sans adresse : code postal inconnu', ($parNom['Sans adresse']['classement'] ?? '') === 'inconnu');
verifie('les supermarchés nationaux du référentiel : national', ($parNom['Aldi']['classement'] ?? '') === 'national');
verifie('Pâtisserie Dumont (Bouge, Namur 5000) : hors zone', ($parNom['Pâtisserie Dumont (Bouge)']['classement'] ?? '') === 'hors' && ($parNom['Pâtisserie Dumont (Bouge)']['cp'] ?? '') === '5000');
verifie('le Pain d’Antan (Gembloux 5030) : dans la zone', ($parNom['Le Pain d\'Antan']['classement'] ?? '') === 'zone', json_encode($parNom['Le Pain d\'Antan'] ?? null));
verifie('la liste « hors zone » les reprend', (bool) array_filter($cl['horsZone'], fn ($x) => $x['nom'] === 'Boulangerie de Gosselies'));

echo "IA : la requête du PDF\n";
$envois = [];
Anthropic::$transport = function (array $corps, array $entetes) use (&$envois) {
    $envois[] = [$corps, $entetes];
    return [200, ['model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        'content' => [['type' => 'text', 'text' => json_encode(['concurrent' => ['nom' => 'Dumont', 'type' => 'artisan', 'adresse' => '', 'code_postal' => '5140', 'ville' => 'Tongrinne', 'site' => ''], 'date_document' => '2026-10-01', 'lignes' => [], 'remarques' => ''])]]]];
};
$r = Anthropic::releveDepuisPdf('%PDF-1.4 essai', 'concurrent annoncé : Dumont');
[$corps, $ent] = $envois[0];
verifie('le PDF part en document base64, avant la consigne', $corps['messages'][0]['content'][0]['type'] === 'document' && $corps['messages'][0]['content'][0]['source']['media_type'] === 'application/pdf' && base64_decode($corps['messages'][0]['content'][0]['source']['data']) === '%PDF-1.4 essai');
verifie('sortie structurée (json_schema), Claude Opus 5.5, repli serveur', ($corps['output_config']['format']['type'] ?? '') === 'json_schema' && $corps['model'] === 'claude-opus-5-5' && $corps['fallbacks'] === 'default' && in_array('anthropic-beta: server-side-fallback-2026-07-01', $ent, true));
verifie('ni température ni réflexion forcée', !isset($corps['temperature']) && !isset($corps['thinking']) && !isset($corps['tool_choice']));
verifie('le relevé revient lu', ($r['releve']['concurrent']['code_postal'] ?? '') === '5140' && $r['erreur'] === null);
$verifSchema = function (array $s) use (&$verifSchema): bool {
    if (($s['type'] ?? '') === 'object') {
        if (($s['additionalProperties'] ?? null) !== false || array_diff(array_keys($s['properties']), $s['required'])) { return false; }
        foreach ($s['properties'] as $p) { if (!$verifSchema($p)) { return false; } }
    }
    if (($s['type'] ?? '') === 'array') { return $verifSchema($s['items']); }
    return true;
};
verifie('schéma strict : chaque objet fermé, chaque champ requis', $verifSchema(Anthropic::schemaReleve(false)) && $verifSchema(Anthropic::schemaReleve(true)));
Anthropic::$transport = fn () => [200, ['stop_reason' => 'refusal', 'stop_details' => ['explanation' => 'catégorie'], 'content' => []]];
verifie('un refus devient une erreur lisible', str_contains((string) Anthropic::releveDepuisPdf('%PDF')['erreur'], 'refusé'));

echo "IA : la boucle de la recherche web\n";
$envois = [];
$reponses = [
    ['stop_reason' => 'pause_turn', 'content' => [['type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_search', 'input' => ['query' => 'Dumont Tongrinne']]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
    ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Je n’ai trouvé que la page Facebook.']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
    ['stop_reason' => 'tool_use', 'model' => 'claude-opus-5-5', 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'enregistrer_releve', 'input' => ['concurrent' => ['nom' => 'Dumont et Partners', 'code_postal' => '5140'], 'lignes' => [], 'sources' => ['https://exemple.be']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]],
];
Anthropic::$transport = function (array $corps, array $entetes) use (&$envois, &$reponses) { $envois[] = $corps; return [200, array_shift($reponses)]; };
$r = Anthropic::releveDepuisWeb('Concurrent : Dumont et Partners — Tongrinne (Belgique).', 'Tongrinne');
verifie('trois tours : reprise du tour en pause, une relance, puis l’outil', count($envois) === 3 && ($r['releve']['concurrent']['code_postal'] ?? '') === '5140', (string) count($envois));
verifie('le tour en pause repart tel quel, sans « continue »', end($envois[1]['messages'])['role'] === 'assistant' && ($envois[1]['messages'][1]['content'][0]['type'] ?? '') === 'server_tool_use');
verifie('la relance demande l’outil', end($envois[2]['messages'])['role'] === 'user' && str_contains((string) end($envois[2]['messages'])['content'], 'enregistrer_releve'));
$types = array_map(fn ($t) => $t['type'] ?? $t['name'], $envois[0]['tools']);
verifie('outils : recherche et lecture web (20260209), relevé strict, choix automatique', $types === ['web_search_20260209', 'web_fetch_20260209', 'enregistrer_releve'] && $envois[0]['tools'][2]['strict'] === true && $envois[0]['tool_choice'] === ['type' => 'auto']);
verifie('recherche localisée en Belgique', ($envois[0]['tools'][0]['user_location']['country'] ?? '') === 'BE' && ($envois[0]['tools'][0]['user_location']['city'] ?? '') === 'Tongrinne');
verifie('jetons cumulés sur les tours', ($r['usage']['input_tokens'] ?? 0) === 30);
Anthropic::$transport = null;

echo "\n$ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
