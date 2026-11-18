#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des objectifs 2026 des coachs et des propositions de prix face à la concurrence — sans base, sans réseau,
 * sans clé.
 *
 *   php bin/tests_coach_objectifs.php
 *
 * Les calculs sont purs (coCible, coEtat, coMenus, coCorrespond, coPropositions) ; l'assistant tourne sur un faux
 * modèle pour vérifier que le Coach Vente reçoit ses objectifs, leur état et l'outil prix_concurrence, et le réglage
 * du cockpit sur une base factice.
 */

// --- doublures -----------------------------------------------------------------------
final class Db
{
    public static array $ecrit = [];
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): int { self::$ecrit[] = [$sql, $a]; return 0; }
}
final class PanelApi
{
    public static function configured(): bool { return false; }
    public static function get(string $p): mixed { return null; }
}
final class Anthropic
{
    public static function config(): array { return ['cle' => 'cle-de-test', 'espace' => '']; }
}
$REGLAGES = [];
function setting(string $k, mixed $d = null): mixed { global $REGLAGES; return $REGLAGES[$k] ?? ($k === 'assistantSel' ? str_repeat('a', 32) : $d); }
$BODY = [];
function body(): array { global $BODY; return $BODY; }

require __DIR__ . '/../src/assistant_magasin.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "Les cibles saisies\n";
verifie('« 40 » et « 12,5 % » se lisent', coCible('foodCost', '40') === 40.0 && coCible('personnel', '12,5 %') === 12.5);
verifie('vide, illisible ou hors bornes : null', coCible('foodCost', '') === null && coCible('foodCost', 'abc') === null && coCible('foodCost', 140) === null && coCible('articles', 25) === null);
verifie('un panier en euros', coCible('panier', '13,50 €') === 13.5);
$N = coObjectifsNormaux(['foodCost' => 40, 'inconnu' => 3]);
verifie('toutes les clés présentes, l’inconnue écartée', array_keys($N) === array_keys(CO_OBJECTIFS) && $N['foodCost'] === 40.0 && $N['menus'] === null && !isset($N['inconnu']), json_encode($N));
verifie('une cible dite à l’oral', coCibleTexte('foodCost', 40.0) === '40 % au plus' && coCibleTexte('panier', 13.5) === '13,5 € au moins' && coCibleTexte('articles', 3.0) === '3 au moins');

echo "Les coachs portent leurs objectifs\n";
$C = amCoachs();
verifie('le Coach Vente part avec 40 % de food cost et 17 % de personnel', $C['vente']['objectifs']['foodCost'] === 40.0 && $C['vente']['objectifs']['personnel'] === 17.0 && $C['vente']['objectifs']['clientsScannes'] === null, json_encode($C['vente']['objectifs']));
verifie('Mr. Pierre et le Coach n’en suivent aucun au départ', array_filter($C['pierre']['objectifs']) === [] && array_filter($C['coach']['objectifs']) === []);
$P = amPersona('vente');
verifie('le personnage du Coach Vente dit ses objectifs et son levier', str_contains($P, 'Food cost : 40 % au plus') && str_contains($P, 'Frais de personnel : 17 % au plus') && str_contains($P, 'prix_concurrence'), $P);
verifie('celui de Mr. Pierre n’en parle pas', !str_contains(amPersona('pierre'), 'objectifs 2026'));

echo "L'état des objectifs face aux mesures du magasin\n";
$mes = [
    'foodCost' => ['moisEnCours' => 43.0, 'moisDernier' => 41.5, 'periode' => 'du 1 au 18', 'base' => ['ca' => 30000.0]],
    'personnel' => ['moisEnCours' => null, 'moisDernier' => 16.2, 'base' => ['ca' => 30000.0], 'baseMoisDernier' => ['ca' => 52000.0]],
    'clientsScannes' => ['moisEnCours' => 18.0, 'base' => ['tickets' => 9000]],
    'menus' => ['moisEnCours' => null, 'moisDernier' => null, 'motif' => 'premier jour du mois'],
];
$E = coEtat(['foodCost' => 40.0, 'personnel' => 17.0, 'clientsScannes' => 30.0, 'menus' => 8.0, 'panier' => null], $mes);
verifie('quatre cibles posées, quatre lignes', count($E) === 4, json_encode($E));
verifie('food cost : 43 % pour 40 %, 3 points, manqué', $E[0]['valeur'] === 43.0 && $E[0]['ecart'] === '3 points' && $E[0]['atteint'] === false, json_encode($E[0]));
verifie('food cost : environ 900 € de coût matière en trop', str_contains((string) $E[0]['ceQueVautLEcart'], '900 €'), (string) ($E[0]['ceQueVautLEcart'] ?? ''));
verifie('personnel : le mois dernier quand le mois en cours manque, tenu', $E[1]['valeur'] === 16.2 && $E[1]['atteint'] === true && str_starts_with($E[1]['periode'], 'mois dernier'), json_encode($E[1]));
verifie('clients scannés : 1 080 tickets de plus à rattacher', $E[2]['atteint'] === false && str_contains((string) $E[2]['ceQueVautLEcart'], '1 080 tickets'), json_encode($E[2]));
$E2 = coEtat(['foodCost' => 40.0, 'menus' => 10.0, 'clientsScannes' => 30.0], ['foodCost' => ['moisEnCours' => 41.0], 'menus' => ['moisEnCours' => 25.4], 'clientsScannes' => ['moisEnCours' => 18.0, 'fenetre' => '90 derniers jours']]);
verifie('les écarts à la belge : « 1 point », « 15,4 points »', $E2[0]['ecart'] === '1 point' && $E2[2]['ecart'] === '15,4 points', json_encode($E2));
verifie('une mesure sur sa fenêtre la dit telle quelle', $E2[1]['periode'] === '90 derniers jours', json_encode($E2[1]));
verifie('menus : pas mesurable, avec la raison', str_contains((string) ($E[3]['etat'] ?? ''), 'premier jour du mois'), json_encode($E[3]));
verifie('aucune cible : aucun état', coEtat(coObjectifsNormaux([]), $mes) === []);

echo "La part des menus\n";
$prods = [[1, 0, 'Menu midi', 40, 360.0, 0, 0], [2, 0, 'Croissant', 900, 990.0, 0, 0], [3, 0, 'Sandwich jambon', 100, 450.0, 0, 0], [4, 0, 'Formule petit-déj', 20, 200.0, 0, 0]];
$M = coMenus($prods, [3 => ['cat' => 'Sandwichs']]);
verifie('« menu » et « formule » dans le nom : 28 % du CA', $M['pct'] === 28.0 && $M['caMenus'] === 560.0 && $M['produits'] === ['Menu midi', 'Formule petit-déj'], json_encode($M));
$M2 = coMenus($prods, [3 => ['cat' => 'Menus du midi']]);
verifie('la catégorie compte aussi : 50,5 %', $M2['pct'] === 50.5, json_encode($M2));
$M3 = coMenus([[2, 0, 'Croissant', 900, 990.0, 0, 0]], []);
verifie('sans menu : 0 %, et la note le dit', $M3['pct'] === 0.0 && str_contains($M3['note'], 'aucun produit'), json_encode($M3));
verifie('sans vente : null', coMenus([], [])['pct'] === null);

echo "Le rapprochement des relevés (règles de l'onglet Concurrence)\n";
$croissant = ['id' => 10, 'nom' => 'Croissant'];
verifie('« Croissant beurre » porte notre croissant', coCorrespond($croissant, ['produit' => 'Croissant beurre']));
verifie('« Mini croissant » et « Croissant jambon » ne le portent pas', !coCorrespond($croissant, ['produit' => 'Mini croissant']) && !coCorrespond($croissant, ['produit' => 'Croissant jambon fromage']));
verifie('le lien posé dans TFBuddy suffit', coCorrespond($croissant, ['produit' => 'Boterkoek', 'monProduit' => 10]));
$pac = ['id' => 11, 'nom' => 'Pain au chocolat'];
verifie('« Chocolat » (relevé court) porte le pain au chocolat, pas « Chocolat nappé »', coCorrespond($pac, ['produit' => 'Chocolat']) && !coCorrespond($pac, ['produit' => 'Chocolat nappé']));
$eclair = ['id' => 12, 'nom' => 'Éclair au café'];
verifie('hors règle : tous les mots qui comptent', coCorrespond($eclair, ['produit' => 'Eclair café']) && !coCorrespond($eclair, ['produit' => 'Eclair chocolat']));
verifie('un cougnou ne compare pas une brioche', !coCorrespond(['id' => 13, 'nom' => 'Brioche sucre'], ['produit' => 'Cougnou brioche']));
verifie('les supermarchés se reconnaissent au type ou à l’enseigne', coEstGms(['type' => 'GMS']) && coEstGms(['concurrent' => 'Delhaize Wavre']) && !coEstGms(['type' => 'Artisan', 'concurrent' => 'Boulangerie Dupont']));

echo "Les propositions : la médiane, et un lot au prix actuel\n";
$produits = [
    ['id' => 10, 'nom' => 'Croissant', 'cat' => 'Viennoiserie', 'prixTtc' => 1.10, 'prixHt' => 1.04, 'cout' => 0.42],
    ['id' => 11, 'nom' => 'Pain au chocolat', 'cat' => 'Viennoiserie', 'prixTtc' => 1.60, 'prixHt' => 1.51, 'cout' => 0.50],
    ['id' => 12, 'nom' => 'Éclair au café', 'cat' => 'Pâtisserie', 'prixTtc' => 3.20, 'prixHt' => 3.02, 'cout' => 0.90],
    ['id' => 14, 'nom' => 'Tarte aux pommes', 'cat' => 'Tartes', 'prixTtc' => 14.00, 'prixHt' => 13.21, 'cout' => 4.0],
    ['id' => 15, 'nom' => 'Pistolet', 'cat' => 'Pains', 'prixTtc' => 0.50, 'prixHt' => 0.47, 'cout' => 0.12],
    ['id' => 16, 'nom' => 'Baguette', 'cat' => 'Pains', 'prixTtc' => 2.00, 'prixHt' => 1.89, 'cout' => 0.40],
];
$a = static fn (string $p, float $prix, string $c = 'Boulangerie A', string $t = 'Artisan', ?float $promo = null) => ['produit' => $p, 'concurrent' => $c, 'type' => $t, 'prix' => $prix, 'promo' => $promo, 'monProduit' => null];
$releves = [
    $a('Croissant', 1.20), $a('Croissant beurre', 1.30, 'B'), $a('Croissant', 1.40, 'C'), $a('Croissant', 0.79, 'Delhaize', 'GMS'),
    $a('Chocolat', 1.70, 'B'), $a('Pain au chocolat', 1.65, 'C'),   // médiane 1,675 : à moins de 5 % — au prix du marché
    $a('Eclair café', 3.90), $a('Eclair café', 4.10, 'B'), $a('Eclair café', 3.50, 'C', 'Artisan', 3.70),   // promo 3,70 : médiane 3,90
    $a('Tarte aux pommes', 19.0), $a('Tarte aux pommes', 21.0, 'B'),
    $a('Pistolet', 0.65), $a('Pistolet', 0.60, 'B'), $a('Pistolet', 0.70, 'C'),
    $a('Baguette', 2.60),   // un seul relevé : pas de proposition
];
$volumes = [10 => ['q' => 2400, 'v' => 2640.0], 11 => ['q' => 1500, 'v' => 2400.0], 12 => ['q' => 300, 'v' => 960.0], 14 => ['q' => 40, 'v' => 560.0], 15 => ['q' => 3000, 'v' => 1500.0], 16 => ['q' => 600, 'v' => 1200.0]];
$R = coPropositions($produits, $releves, $volumes);
$par = array_column($R['propositions'], null, 'produit');
verifie('quatre produits sous le marché', count($R['propositions']) === 4 && isset($par['Croissant'], $par['Éclair au café'], $par['Tarte aux pommes'], $par['Pistolet']), json_encode(array_keys($par)));
verifie('le pain au chocolat est au prix du marché, la baguette n’a qu’un relevé', $R['auPrixDuMarche'] === 1 && $R['unSeulReleve'] === 1, json_encode($R));
$c = $par['Croissant'];
verifie('croissant : médiane des boulangeries 1,30 € (le supermarché écarté)', $c['medianeBoulangeries'] === 1.3 && $c['releves'] === 3, json_encode($c));
verifie('croissant : 1,10 € → 1,30 €, 4 pour 4,40 €', $c['prixPropose'] === 1.3 && $c['lot']['pieces'] === 4 && $c['lot']['prix'] === 4.4 && str_starts_with($c['lot']['dit'], '4 pour 4,40 €'), json_encode($c));
verifie('croissant : jusqu’à 480 € sur la période, food cost du produit 40,4 % → 34,2 %', $c['gainMaxSurLaPeriode'] === 480.0 && $c['foodCostProduit'] === ['avant' => 40.4, 'apres' => 34.2], json_encode($c));
$e = $par['Éclair au café'];
verifie('éclair : le prix payé en promo compte, médiane 3,90 €, lot de 2 à 6,40 €', $e['medianeBoulangeries'] === 3.9 && $e['prixPropose'] === 3.9 && $e['lot']['pieces'] === 2 && $e['lot']['prix'] === 6.4, json_encode($e));
verifie('pistolet : lot de 6 à 3,00 €', $par['Pistolet']['lot']['pieces'] === 6 && $par['Pistolet']['lot']['prix'] === 3.0 && $par['Pistolet']['prixPropose'] === 0.65, json_encode($par['Pistolet']));
verifie('tarte : 20 € proposés, pas de lot au-delà de 8 €', $par['Tarte aux pommes']['prixPropose'] === 20.0 && $par['Tarte aux pommes']['lot'] === null);
verifie('la plus rentable d’abord', $R['propositions'][0]['produit'] === 'Croissant', $R['propositions'][0]['produit']);
$R2 = coPropositions([['id' => 10, 'nom' => 'Croissant', 'prixTtc' => 1.10, 'prixHt' => 1.04, 'cout' => 0.42]], [$a('Croissant', 1.27), $a('Croissant', 1.27, 'B')], [10 => ['q' => 10, 'v' => 11.0]]);
verifie('le prix proposé ne dépasse jamais la médiane : 1,27 € → 1,25 €', $R2['propositions'][0]['prixPropose'] === 1.25, json_encode($R2));
$R3 = coPropositions([['id' => 10, 'nom' => 'Croissant', 'prixTtc' => 1.10, 'prixHt' => 1.04, 'cout' => 0.42]], [$a('Croissant', 1.30), $a('Croissant', 1.40, 'B')], []);
verifie('un produit qui ne s’est pas vendu ne se propose pas', $R3['propositions'] === []);
$R4 = coPropositions([['id' => 14, 'nom' => 'Tarte aux pommes 6 pers.', 'prixTtc' => 14.0, 'prixHt' => 13.21, 'cout' => 4.0]], [$a('Tarte aux pommes', 19.0), $a('Tarte aux pommes', 21.0, 'B')], [14 => ['q' => 40, 'v' => 560.0]]);
verifie('« 6 pers. » ne se compare pas à une tarte sans taille (règle des mots du dashboard)', $R4['propositions'] === [] && $R4['sansReleve'] === 1, json_encode($R4));
verifie('les lots : 6, 4, 2 pièces, puis aucun', coLotPieces(0.5) === 6 && coLotPieces(1.1) === 4 && coLotPieces(3.2) === 2 && coLotPieces(8.0) === null);

echo "L'assistant : le bloc objectifs et l'outil prix\n";
$appels = [];
$faux = static function (array $corps, array $c) use (&$appels): array {
    $appels[] = $corps;
    $raw = json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"titre":"Objectifs"}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]);
    return [json_decode($raw, true), json_decode($raw), null];
};
$faits = ['magasin' => 'magasin 4', 'aujourdhui' => date('Y-m-d') . ' (mercredi)', 'mesures' => $mes];
$Rv = amDemander($faits, 'Où en sont mes objectifs 2026 ?', [], 'vente', 4, $faux);
$premier = $appels[0]['messages'][0]['content'];
verifie('une réponse', ($Rv['reponse']['titre'] ?? '') === 'Objectifs', json_encode($Rv));
verifie('le bloc objectifs suit la fiche, hors du cache', count($premier) === 3 && isset($premier[0]['cache_control']) && str_contains($premier[1]['text'], 'Bloc « objectifs »') && !isset($premier[1]['cache_control']), json_encode($premier));
verifie('il porte le food cost manqué de 3 points', str_contains($premier[1]['text'], '"ecart": "3 points"') && str_contains($premier[1]['text'], '40 % au plus'), $premier[1]['text']);
verifie('l’outil prix_concurrence est déclaré', in_array('prix_concurrence', array_column($appels[0]['tools'], 'name'), true));
verifie('les consignes parlent du lot au prix actuel', str_contains($appels[0]['system'][0]['text'], 'les 4 restent à 4,40 €'));
$appels = [];
amDemander($faits, 'Comment va ma journée ?', [], 'pierre', 4, $faux);
verifie('Mr. Pierre, sans objectif : pas de bloc', count($appels[0]['messages'][0]['content']) === 2, json_encode($appels[0]['messages'][0]['content']));

echo "Le réglage du cockpit\n";
$BODY = ['coachs' => ['vente' => ['objectifs' => ['foodCost' => '38', 'personnel' => '', 'clientsScannes' => '35', 'menus' => '12,5']]], 'qui' => 'Test'];
$r = wr_assistant_coachs();
$reg = json_decode(end(Db::$ecrit)[1][1], true);
verifie('publié : la cible vidée vaut null, les autres sont gardées', ($r['ok'] ?? false) === true && $reg['vente']['objectifs']['foodCost'] === 38 && $reg['vente']['objectifs']['personnel'] === null && $reg['vente']['objectifs']['menus'] === 12.5, json_encode($reg));
$REGLAGES['assistantCoachs'] = $reg;
$C = amCoachs();
verifie('relu : 38 % de food cost, plus de cible de personnel', $C['vente']['objectifs']['foodCost'] === 38.0 && $C['vente']['objectifs']['personnel'] === null && $C['vente']['objectifs']['clientsScannes'] === 35.0, json_encode($C['vente']['objectifs']));
$BODY = ['coachs' => ['vente' => ['objectifs' => ['foodCost' => '40', 'personnel' => '17']]]];
$n = count(Db::$ecrit);
wr_assistant_coachs();
$reg2 = json_decode(end(Db::$ecrit)[1][1], true);
verifie('les valeurs de départ ne s’écrivent pas', count(Db::$ecrit) === $n + 1 && !isset($reg2['vente']), json_encode($reg2));
$BODY = ['coachs' => ['vente' => ['objectifs' => ['foodCost' => '140']]]];
$r = @wr_assistant_coachs();
verifie('une cible hors bornes est refusée', isset($r['error']) && str_contains($r['error'], 'Food cost'), json_encode($r));
$G = ep_assistant_coachs();
verifie('le cockpit reçoit les objectifs et les valeurs de départ complètes', isset($G['objectifs']['foodCost']) && $G['defauts']['vente']['objectifs']['foodCost'] === 40.0 && array_key_exists('menus', $G['defauts']['pierre']['objectifs']), json_encode($G['defauts']['vente']['objectifs']));

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
