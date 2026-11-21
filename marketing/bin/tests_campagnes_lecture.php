#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Les campagnes se lisent au module Marketing (21/11/2026, lot 2) : GET /marketing (calendrier, campagnes, types) et
 * les campagnes et boutiques du budget des campagnes passent par l'API du module, plus par mar_campaign,
 * mar_campaign_type, mar_campaign_status, mar_campaign_shop, mar_lever ni mar_brand. Sans base, sans réseau.
 *
 *   php marketing/bin/tests_campagnes_lecture.php
 */
ob_start();

final class Db
{
    /** @var list<string> */
    public static array $sql = [];
    public static function rows(string $s, array $a = []): array { self::$sql[] = $s; return []; }
    public static function row(string $s, array $a = []): ?array { self::$sql[] = $s; return null; }
    public static function exec(string $s, array $a = []): int { self::$sql[] = $s; return 1; }
}
$GLOBALS['appels'] = [];
$GLOBALS['fixes'] = [];
function marketingAppel(string $methode, string $chemin, ?array $corps = null): array
{
    $GLOBALS['appels'][] = [$methode, $chemin];
    $r = $GLOBALS['fixes'][$chemin] ?? ['code' => 404, 'j' => ['description' => 'Route inconnue.']];
    $j = $r['j'];
    $msg = $r['code'] >= 400 || $r['code'] === 0 ? (string) ($j['description'] ?? 'module injoignable') : null;
    return ['code' => $r['code'], 'corps' => is_array($j) && array_key_exists('data', $j) ? $j['data'] : $j, 'erreur' => $msg, 'brut' => $j];
}
require __DIR__ . '/../src/mkt_types.php';   // mktIcones : la bibliothèque d'icônes du cockpit (une constante, pas une table)
require __DIR__ . '/../src/campagnes.php';
require __DIR__ . '/../src/mesure.php';    // la mesure des campagnes (lot 3) : rien ne s'y exécute au chargement

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function poser(array $routes): void { Db::$sql = []; $GLOBALS['appels'] = []; $GLOBALS['fixes'] = $routes; }
$ok200 = static fn ($j): array => ['code' => 200, 'j' => $j];

$campagnes = [
    ['id' => 9, 'name' => 'Noël', 'scope' => 'RESEAU', 'status_code' => 'active', 'status_label' => 'En cours', 'status_text_hex' => '#0a0', 'status_bg_rgba' => 'rgba(0,1,0,.1)',
        'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31', 'budget_amount' => 5000.0, 'spent_amount' => 1200.5, 'image_url' => '', 'shops_count' => 4,
        'type_id' => 4, 'type_label' => 'Fêtes', 'type_color_hex' => '#AA3344'],
    ['id' => 7, 'name' => 'Rentrée', 'scope' => 'LOCALE', 'status_code' => 'draft', 'status_label' => null, 'status_text_hex' => null, 'status_bg_rgba' => null,
        'starts_on' => '2026-09-01', 'ends_on' => null, 'budget_amount' => 0.0, 'spent_amount' => 0.0, 'image_url' => '/img/r.jpg', 'shops_count' => 0,
        'type_id' => null, 'type_label' => null, 'type_color_hex' => null],
    ['id' => 6, 'name' => 'Ouverture', 'status_code' => 'done', 'status_label' => 'Terminée', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'type_label' => 'Ouverture', 'type_color_hex' => '#111111'],
    ['id' => 3, 'name' => 'Ancienne', 'status_code' => 'done', 'starts_on' => '2025-03-01', 'ends_on' => '2025-12-31'],
    ['id' => 2, 'name' => 'Future', 'status_code' => 'draft', 'starts_on' => '2027-01-05', 'ends_on' => '2027-02-01'],
    ['id' => 1, 'name' => 'Sans date', 'status_code' => 'draft', 'starts_on' => null, 'ends_on' => null],
];
$jeu = [
    '/brands' => $ok200([['id' => 2, 'name' => 'Seconde'], ['id' => 1, 'name' => 'L’Atelier by']]),
    '/references' => $ok200([
        'campaignStatuses' => [['code' => 'draft', 'label' => 'Brouillon', 'text_hex' => '#666', 'bg_rgba' => 'rgba(1,1,1,.1)', 'sort_order' => '1'],
            ['code' => 'active', 'label' => 'En cours', 'text_hex' => '#0a0', 'bg_rgba' => 'rgba(0,1,0,.1)', 'sort_order' => '2']],
        'levers' => [['id' => 2, 'code' => 'TRAFIC', 'label' => 'Trafic', 'color_hex' => '#123456'], ['id' => 1, 'code' => 'PANIER', 'label' => 'Panier', 'color_hex' => '#654321']]]),
    '/campaign-types' => $ok200(['types' => [
        ['id' => 4, 'code' => 'fetes', 'label' => 'Fêtes', 'description' => 'Fin d’année', 'color_hex' => '#AA3344', 'icon_key' => 'cadeau', 'icon_path' => 'M0',
            'default_lever_code' => 'TRAFIC', 'default_kpi_label' => 'Tickets', 'lever_id' => 2, 'lever_badge_label' => 'Trafic / fêtes', 'lever_label' => 'Trafic / fêtes',
            'lever_color_hex' => '#123456', 'sort_order' => 1, 'is_active' => true, 'campaigns' => 1],
        ['id' => 5, 'code' => 'vieux', 'label' => 'Ancien type', 'description' => null, 'color_hex' => null, 'icon_key' => null, 'icon_path' => null,
            'default_lever_code' => 'PANIER', 'default_kpi_label' => null, 'lever_id' => null, 'lever_badge_label' => null, 'lever_label' => null,
            'lever_color_hex' => null, 'sort_order' => 9, 'is_active' => false, 'campaigns' => 3],
    ], 'icons' => []]),
    '/campaigns' => $ok200($campagnes),
];

echo "GET /marketing — calendrier, campagnes et types\n";
poser($jeu);
$m = ep_mkt();
verifie('aucune requête en base : tout vient du module', Db::$sql === [], json_encode(Db::$sql));
verifie('quatre lectures au module : marques, référentiels, types, campagnes',
    array_column($GLOBALS['appels'], 1) === ['/brands', '/references', '/campaign-types', '/campaigns'], json_encode($GLOBALS['appels']));
verifie('la marque : la première par identifiant', $m['marqueId'] === 1 && $m['marque'] === 'L’Atelier by');
verifie('les statuts, dans l’ordre du module', array_column($m['statuts'], 'code') === ['draft', 'active'] && $m['statuts'][0] === ['code' => 'draft', 'nom' => 'Brouillon', 'texte' => '#666', 'fond' => 'rgba(1,1,1,.1)', 'ordre' => 1]);
$t4 = $m['types'][0]; $t5 = $m['types'][1];
verifie('les types, inactifs compris, avec leur nombre de campagnes', count($m['types']) === 2 && $t5['actif'] === false && $t5['nCampagnes'] === 3 && $t4['actif'] === true, json_encode($m['types']));
verifie('le levier d’un type : son badge, sinon le code d’avant la liaison', $t4['levier'] === 'Trafic / fêtes' && $t4['levierId'] === 2 && $t4['levierCouleur'] === '#123456'
    && $t5['levier'] === 'PANIER' && $t5['levierId'] === null && $t4['iconePath'] === 'M0' && $t4['kpi'] === 'Tickets', json_encode([$t4, $t5]));
$c9 = $m['campagnes'][0]; $c7 = $m['campagnes'][1];
verifie('une campagne garde ses clés : type (identifiant, couleur), statut, budget, dépense, boutiques',
    $c9 === ['id' => 9, 'nom' => 'Noël', 'typeId' => 4, 'type' => 'Fêtes', 'typeCouleur' => '#AA3344', 'scope' => 'RESEAU', 'statut' => 'active', 'statutNom' => 'En cours',
        'statutTexte' => '#0a0', 'statutFond' => 'rgba(0,1,0,.1)', 'debut' => '2026-12-01', 'fin' => '2026-12-31', 'budget' => 5000.0, 'depense' => 1200.5, 'nBoutiques' => 4, 'image' => null],
    json_encode($c9));
verifie('sans type ni libellé de statut : les replis d’avant', $c7['typeId'] === null && $c7['statutNom'] === 'draft' && $c7['statutTexte'] === '#666666'
    && $c7['statutFond'] === 'rgba(120,116,110,.12)' && $c7['image'] === '/img/r.jpg' && count($m['campagnes']) === 6, json_encode($c7));
verifie('les leviers et les icônes accompagnent les types', $m['leviers'][0] === ['id' => 2, 'code' => 'TRAFIC', 'nom' => 'Trafic', 'couleur' => '#123456'] && count($m['icones']) > 0);
poser(['/campaign-types' => ['code' => 0, 'j' => ['description' => 'module injoignable']]] + $jeu);
$m = ep_mkt();
verifie('module injoignable : l’écran le dit (indispo), rien d’inventé', ($m['indispo'] ?? false) === true && str_contains((string) $m['raison'], 'module injoignable') && Db::$sql === [], json_encode($m));

echo "Le budget des campagnes : campagnes de l'exercice et boutiques\n";
poser($jeu);
[$l, $err] = campagnesDeLExercice(2026);
verifie('les campagnes qui touchent l’exercice, de la plus ancienne à la plus récente (même jour : par identifiant)',
    $err === null && array_column($l, 'id') === [6, 7, 9], json_encode(array_column((array) $l, 'id')));
verifie('dans la forme que lisait le budget (statut et type, couleur comprise)',
    $l[2] === ['id' => 9, 'name' => 'Noël', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31', 'status_code' => 'active', 'statut' => 'En cours', 'type_label' => 'Fêtes', 'type_color' => '#AA3344'], json_encode($l[2]));
[$l, $err] = campagnesDeLExercice(2025);
verifie('un autre exercice : la campagne finie le 31 décembre en fait partie', $err === null && array_column($l, 'id') === [3]);
poser(['/campaigns/9' => $ok200(['id' => 9, 'shops' => [['shop_id' => 5, 'shop_name' => 'Gosselies'], ['shop_id' => 12, 'shop_name' => 'Halle'], ['shop_id' => 3, 'shop_name' => 'Namur']]])]);
[$ids, $err] = campagneBoutiques(9);
verifie('les boutiques d’une campagne, lues à sa fiche, par identifiant (la fiche les range par nom)',
    $ids === ['3', '5', '12'] && $err === null && Db::$sql === [], json_encode($ids));
poser([]);
[$ids, $err] = campagneBoutiques(9);
verifie('campagne inconnue du module : la raison, pas une liste vide', $ids === null && $err === 'campagne inconnue du module');

echo "La mesure des campagnes (lot 3)\n";
poser($jeu);
[$d, $err] = campagnesDatees();
verifie('les campagnes datées, les plus récentes d’abord, sans celle qui n’a pas de date',
    $err === null && array_column($d, 'id') === [9, 7, 6, 3, 2] && $d[0]['scope'] === 'RESEAU' && $d[0]['statut'] === 'En cours' && $d[0]['type_label'] === 'Fêtes'
        && Db::$sql === [], json_encode(array_column((array) $d, 'id')));
poser(['/campaigns/9' => $ok200(['id' => 9, 'name' => 'Noël', 'objective_coef_pct' => 12.5, 'type_lever_code' => 'TRAFIC', 'offer' => ['items' => [
    ['label' => 'Tarte', 'sku_ref' => 'S1', 'catalog_name' => 'Tarte du jour'],
    ['label' => null, 'sku_ref' => 'S2', 'catalog_name' => 'Croissant'],
    ['label' => 'Produit libre', 'sku_ref' => null, 'catalog_name' => null],
]]])]);
$p = mesProduitsCampagne(9, "S2 Croissant au beurre\nX9");
verifie('les produits promus : l’offre lue à la fiche (libellé, sinon nom du catalogue), puis la saisie libre',
    $p === [['sku' => 'S1', 'nom' => 'Tarte', 'source' => 'offre'], ['sku' => 'S2', 'nom' => 'Croissant au beurre', 'source' => 'saisie'], ['sku' => 'X9', 'nom' => 'X9', 'source' => 'saisie']]
        && Db::$sql === [] && $GLOBALS['appels'] === [['GET', '/campaigns/9']], json_encode($p));
$f = campagneFiche(9);
verifie('la fiche d’une campagne : coefficient d’objectif et levier du type', $f['erreur'] === null && $f['fiche']['objective_coef_pct'] === 12.5 && $f['fiche']['type_lever_code'] === 'TRAFIC');
poser([]);
$f = campagneFiche(9);
verifie('une campagne que le module ne connaît pas : ni fiche ni erreur (404)', $f === ['fiche' => null, 'erreur' => null]);
poser(['/campaigns/9' => ['code' => 0, 'j' => ['description' => 'module injoignable']]]);
verifie('module injoignable : la raison', campagneFiche(9) === ['fiche' => null, 'erreur' => 'module injoignable']
    && mesProduitsCampagne(9, 'A1') === [['sku' => 'A1', 'nom' => 'A1', 'source' => 'saisie']]);

echo "\n$ok ok, $ko KO\n";
ob_end_flush();
exit($ko === 0 ? 0 : 1);
