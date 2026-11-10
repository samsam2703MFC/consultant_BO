#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du Tableau d'analyse des promotions (GET /promotions/reseau) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_promos_reseau.php
 *
 * Les règles du panel sont gardées en mémoire (pvRegles), les tickets à remise gravés (svP…), les commandes du webshop
 * en doublure. On vérifie ce que le tableau en tire : chaque promotion reconnue dans les tickets de chaque magasin (fois,
 * ventes, remise, part webshop), son état, celles de la marque magasin par magasin, ce qui sort du tableau (terminée
 * avant la fenêtre, sans effet), la remise qu'aucune promotion n'explique, et les totaux.
 */

// --- doublures : pas de base, pas de réseau ------------------------------------------------
final class Db
{
    public static array $shops = [];
    public static function rows(string $sql, array $a = []): array { return str_contains($sql, 'FROM shops') ? self::$shops : []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void {}
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function svGrave(string $k, $v): void { $GLOBALS['reglages'][$k] = $v; }
function svCategories(): array { return []; }
function panelCatalogue(): array { return ['produits' => [101 => ['catId' => 12], 102 => ['catId' => 12], 201 => ['catId' => 20], 301 => ['catId' => 3]]]; }
$GLOBALS['commandes'] = [];
function coCommandes(int $sid, string $depuis): ?array { $GLOBALS['cmdLues'][] = [$sid, $depuis]; return $GLOBALS['commandes'][$sid] ?? []; }

require __DIR__ . '/../src/promos_ventes.php';
require __DIR__ . '/../src/promos_reseau.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$proche = static fn ($a, $b) => abs((float) $a - (float) $b) < 0.005;

// --- le décor -------------------------------------------------------------------------------
$auj = date('Y-m-d');
$j = static fn (int $n) => date('Y-m-d', strtotime($auj . ' ' . ($n >= 0 ? '+' : '') . $n . ' day'));
$fiche = static fn (array $f) => $f + ['promotion_type' => 'BUY_X_GET_Y', 'status' => 'ACTIVE', 'priority' => 0];
$cat = static fn (int $q, int $c) => ['quantity' => $q, 'category_ids' => [$c]];
$prod = static fn (int $q, int $p) => ['quantity' => $q, 'product_ids' => [$p]];
$regles = array_map('pvNormaliser', array_map($fiche, [
    // Gosselies (4) : 2 cookies achetés, le 3e offert — en caisse.
    ['id' => 21, 'name' => 'Cookies 2 + 1 offert', 'shop_scope_type' => 'SELECTED_SHOPS', 'shop_ids' => [4], 'valid_from' => $j(-20) . ' 00:00:00', 'valid_to' => $j(10) . ' 23:59:00',
        'trigger' => $cat(2, 12), 'reward' => $cat(1, 12) + ['type' => 'FREE']],
    // Gosselies : un brouillon (inactif au panel).
    ['id' => 22, 'name' => 'Tarte −20 %', 'status' => 'INACTIVE', 'shop_scope_type' => 'SELECTED_SHOPS', 'shop_ids' => [4], 'valid_from' => $j(5) . ' 00:00:00', 'valid_to' => $j(30) . ' 23:59:00',
        'trigger' => $cat(1, 7), 'reward' => $cat(1, 7) + ['type' => 'PERCENTAGE_DISCOUNT', 'value' => 20]],
    // Halle (5) : terminée bien avant la fenêtre, sans effet — hors du tableau.
    ['id' => 23, 'name' => 'Vieille promotion', 'shop_scope_type' => 'SELECTED_SHOPS', 'shop_ids' => [5], 'valid_from' => $j(-90) . ' 00:00:00', 'valid_to' => $j(-60) . ' 23:59:00',
        'trigger' => $cat(1, 3), 'reward' => $cat(1, 3) + ['type' => 'FREE']],
    // Halle : terminée dans la fenêtre — elle reste, terminée.
    ['id' => 24, 'name' => 'Croissant 4 + 2', 'shop_scope_type' => 'SELECTED_SHOPS', 'shop_ids' => [5], 'valid_from' => $j(-40) . ' 00:00:00', 'valid_to' => $j(-3) . ' 23:59:00',
        'trigger' => $prod(4, 301), 'reward' => $prod(2, 301) + ['type' => 'FREE']],
    // La marque, tout le réseau : un café, la viennoiserie à 1 €.
    ['id' => 12, 'name' => 'Café + viennoiserie à 1 €', 'shop_scope_type' => 'ALL_SHOPS', 'valid_from' => $j(-30) . ' 00:00:00',
        'trigger' => $prod(1, 201), 'reward' => $prod(1, 301) + ['type' => 'FIXED_PRICE', 'value' => 1]],
    // La marque, deux magasins, à venir.
    ['id' => 25, 'name' => 'Saint-Nicolas', 'shop_scope_type' => 'SELECTED_SHOPS', 'shop_ids' => [4, 5], 'valid_from' => $j(20) . ' 00:00:00', 'valid_to' => $j(25) . ' 23:59:00',
        'trigger' => $cat(2, 12), 'reward' => $cat(1, 12) + ['type' => 'PERCENTAGE_DISCOUNT', 'value' => 30]],
]));
// Les tickets à remise : [clé, pid, q, prix, remise, total].
$cookies = ['t' => 1, 'l' => [['101', 101, 3, 2.60, 2.60, 5.20]]];            // 2 + 1 offert
$web = ['t' => 555, 'l' => [['101', 101, 3, 2.60, 2.60, 5.20]]];               // le même, payé au webshop et encaissé en caisse
$inconnue = ['t' => 2, 'l' => [['999', 999, 1, 5.00, 0.70, 4.30]]];           // une remise qu'aucune règle n'explique
$cafe = ['t' => 3, 'l' => [['201', 201, 1, 2.40, 0, 2.40], ['301', 301, 1, 1.50, 0.50, 1.00]]];
$p = static fn (float $ca) => ['10' => ['101' => ['Cookie', 10, $ca * 0.6, 1.0]], '15' => ['201' => ['Café', 20, $ca * 0.4, 0.5]]];

echo "Un jour lu : ses ventes\n";
verifie('les ventes du jour = la somme des produits heure par heure', $proche(prCaJour($p(1000)), 1000));
verifie('un jour sans produits vaut 0', prCaJour(null) === 0.0 && prCaJour(['x' => 'rien']) === 0.0);

echo "\nUn magasin sur ses jours (prMagasin)\n";
$catId = [101 => 12, 102 => 12, 201 => 20, 301 => 3];
$M4 = prMagasin([$j(-2) => ['r' => [$cookies, $inconnue], 'ca' => 1000.0], $j(-1) => ['r' => [$web, $cafe], 'ca' => 800.0], $j(0) => null], $regles, [555 => 'cc'], $catId, [], 4);
$a = $M4['regles']['21'] ?? null;
verifie('Cookies 2 + 1 : deux fois, deux tickets', $a !== null && $a['fois'] === 2 && $a['tickets'] === 2, json_encode($a));
verifie('Cookies 2 + 1 : ventes 10,40 € (2 × 2 cookies payés), remise 5,20 €', $a !== null && $proche($a['v'], 10.40) && $proche($a['r'], 5.20), json_encode($a));
verifie('Cookies 2 + 1 : une fois sur un ticket du webshop', $a !== null && $a['web'] === 1);
$c = $M4['regles']['12'] ?? null;
verifie('la promotion de la marque vaut à Gosselies : 1 fois, 3,40 € payés, 0,50 € de remise', $c !== null && $c['fois'] === 1 && $proche($c['v'], 3.40) && $proche($c['r'], 0.50), json_encode($c));
$x = $M4['regles']['x'] ?? null;
verifie('la remise inexpliquée reste à part', $x !== null && $x['fois'] === 1 && $proche($x['r'], 0.70));
verifie('le CA des jours lus, les jours lus et ceux qui ne le sont pas', $proche($M4['ca'], 1800) && $M4['lus'] === 2 && $M4['sans'] === 1);
$M5 = prMagasin([$j(-1) => ['r' => [$cookies, $cafe], 'ca' => 500.0]], $regles, [], $catId, [], 5);
verifie('une promotion d\'un autre magasin ne vaut pas ici (Cookies de Gosselies à Halle)', !isset($M5['regles']['21']));
verifie('la promotion de la marque vaut aussi à Halle', ($M5['regles']['12']['fois'] ?? 0) === 1);

echo "\nL'état d'une promotion\n";
$par = []; foreach ($regles as $r) { $par[$r['id']] = $r; }
verifie('active au panel, dans sa période : en caisse', prEtat($par[21], $auj, true) === 'en_caisse');
verifie('inactive, du magasin : brouillon', prEtat($par[22], $auj, true) === 'brouillon');
verifie('inactive, de la marque : inactive', prEtat(['statut' => 'INACTIVE'] + $par[12], $auj, false) === 'inactive');
verifie('période passée : terminée', prEtat($par[24], $auj, true) === 'terminee');
verifie('période à venir : à venir', prEtat($par[25], $auj, false) === 'a_venir');

echo "\nLe tableau (prReseau)\n";
$appels = [];
$coef = static function (array $r, array $shops) use (&$appels): ?array { $appels[$r['id']] = $shops; return ['lot' => 2.5, 'magasin' => $shops[0], 'x' => 'Cookie', 'y' => 'Cookie']; };
$mags = [['id' => 4, 'nom' => 'Gosselies'], ['id' => 5, 'nom' => 'Halle']];
$T = prReseau($regles, [4 => $M4 + ['commandes' => true], 5 => $M5 + ['commandes' => false]], $mags, $j(-29), $auj, $coef);
$P = []; foreach ($T['promos'] as $l) { $P[$l['id']] = $l; }
verifie('la promotion terminée avant la fenêtre, sans effet, n\'y est plus', !isset($P[23]));
verifie('celle terminée dans la fenêtre y reste, terminée', ($P[24]['etat'] ?? '') === 'terminee');
verifie('Cookies 2 + 1 : du magasin, Gosselies, en caisse, 2 fois dont 1 au webshop', ($P[21]['src'] ?? '') === 'mag' && $P[21]['shop'] === 4 && $P[21]['etat'] === 'en_caisse' && $P[21]['fois'] === 2 && $P[21]['web'] === 1);
verifie('la promotion de la marque : tout le réseau, 2 fois, magasin par magasin', ($P[12]['src'] ?? '') === 'marque' && $P[12]['shops'] === null && $P[12]['fois'] === 2
    && ((array) $P[12]['parMagasin'])['4']['fois'] === 1 && ((array) $P[12]['parMagasin'])['5']['fois'] === 1, json_encode($P[12] ?? null));
verifie('la marque sur deux magasins, à venir, n\'est pas « du magasin »', ($P[25]['src'] ?? '') === 'marque' && $P[25]['shops'] === [4, 5] && $P[25]['etat'] === 'a_venir');
verifie('le brouillon y est, sans chiffres', ($P[22]['etat'] ?? '') === 'brouillon' && $P[22]['fois'] === 0);
verifie('le texte de la règle vient du panel', str_contains((string) ($P[21]['texte'] ?? ''), '2 achetés'), (string) ($P[21]['texte'] ?? ''));
verifie('le coefficient : pour les « X + Y » pas terminées, sur leurs magasins (tout le réseau = les magasins ouverts)',
    ($appels[21] ?? null) === [4] && ($appels[22] ?? null) === [4] && ($appels[12] ?? null) === [4, 5] && ($appels[25] ?? null) === [4, 5] && !isset($appels[24]) && ($P[21]['coef']['lot'] ?? null) === 2.5, json_encode($appels));
verifie('une terminée n\'a pas de coefficient', array_key_exists('coef', $P[24]) && $P[24]['coef'] === null);
verifie('les plus utilisées d\'abord', $T['promos'][0]['id'] === 21, json_encode(array_column($T['promos'], 'id')));
verifie('les totaux : 4 fois, 1 au webshop, ventes et remise', $T['total']['fois'] === 4 && $T['total']['web'] === 1 && $proche($T['total']['v'], 10.40 + 3.40 + 3.40) && $proche($T['total']['r'], 5.20 + 0.50 + 0.50), json_encode($T['total']));
verifie('le CA des magasins s\'additionne', $proche($T['total']['ca'], 2300));
// À Halle, les cookies remisés n'ont pas de règle (2 + 1 est celle de Gosselies) : 0,70 € + 2,60 €.
verifie('la remise inexpliquée, à part, magasin par magasin', $T['nonReconnue']['fois'] === 2 && $proche($T['nonReconnue']['r'], 3.30), json_encode($T['nonReconnue']));
verifie('les magasins : nom, CA, jours lus, commandes lues ou non', $T['magasins'][0]['nom'] === 'Gosselies' && $proche($T['magasins'][0]['ca'], 1800) && $T['magasins'][0]['lus'] === 2 && $T['magasins'][1]['commandes'] === false);

echo "\nLa route (GET /promotions/reseau)\n";
Db::$shops = [['id' => 4, 'name' => 'L\'Atelier by - Gosselies'], ['id' => 5, 'name' => 'L\'Atelier by - Halle']];
$GLOBALS['reglages'] = ['pvRegles' => ['ts' => time(), 'r' => $regles],
    'svP4:' . $j(-2) => ['p' => $p(1000), 'r' => [$cookies, $inconnue]], 'svP4:' . $j(-1) => ['p' => $p(800), 'r' => [$web, $cafe]],
    'svP5:' . $j(-1) => ['p' => $p(500), 'r' => [$cookies, $cafe]],
    // Un jour gravé avant que les remises soient gardées : pas lu pour les promotions.
    'svP5:' . $j(-2) => ['p' => $p(700)]];
$GLOBALS['commandes'] = [4 => [['canal' => 'cc', 'tx' => 555], ['canal' => 'liv', 'tx' => 0]]];
$_GET = ['jours' => '7'];
$E = ep_promotions_reseau();
$P = []; foreach ($E['promos'] as $l) { $P[$l['id']] = $l; }
verifie('7 jours, d\'il y a six jours à aujourd\'hui', $E['jours'] === 7 && $E['du'] === $j(-6) && $E['au'] === $auj);
verifie('les noms courts des magasins ouverts', array_column($E['magasins'], 'nom') === ['Gosselies', 'Halle']);
verifie('le ticket encaissé d\'une commande du webshop compte au webshop', ($P[21]['web'] ?? null) === 1 && ($P[21]['fois'] ?? null) === 2);
verifie('un jour sans remises gardées n\'est pas lu', $E['magasins'][1]['lus'] === 1 && $E['magasins'][1]['sans'] === 6);
verifie('les commandes lues une fois par magasin, depuis le début de la fenêtre', count($GLOBALS['cmdLues']) === 2 && $GLOBALS['cmdLues'][0] === [4, $j(-6)]);
verifie('sans assistant chargé, pas de coefficient (pmDemande absent)', array_key_exists('coef', $P[21]) && $P[21]['coef'] === null);
verifie('l\'heure de lecture du panel et le repère', $E['lu'] !== null && $E['regles']['coefMin'] === 2.0);
$_GET = [];
verifie('30 jours par défaut', ep_promotions_reseau()['jours'] === 30);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
