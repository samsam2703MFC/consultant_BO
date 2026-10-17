#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des promotions du magasin (/exploitation/promos-magasin) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_promos.php
 *
 * Les prix et coûts de recette du magasin sont une doublure de /analyse/produits/coefficients ;
 * les réglages vivent en mémoire. On vérifie le coefficient du lot (le cas le plus serré, la pièce
 * offerte la moins chère), les règles du magasin (une fin, 14 jours, deux à la fois, ×2) et le
 * parcours d'une demande : brouillon, envoi, décision de la marque, retrait.
 */

// --- doublures : pas de base, pas de réseau ------------------------------------------------
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void
    {
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { $GLOBALS['reglages'][$a[0]] = json_decode($a[1], true); }
    }
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function body(): array { return $GLOBALS['corps']; }
// Les produits du magasin 4 : prix HT, coût de recette, TVA.
function ep_analyse_produits_coefficients(): array
{
    $sid = $_GET['shop'] ?? '';
    // Le magasin 5 vend le pain au chocolat moins cher : 1,40 € HT.
    if ($sid === '5') { return ['produits' => [['id' => 2, 'nom' => 'Pain au chocolat', 'catId' => 10, 'prixHt' => 1.40, 'cout' => 0.60, 'tva' => 6]]]; }
    if ($sid !== '4') { return ['produits' => []]; }
    return ['produits' => [
        ['id' => 1, 'nom' => 'Croissant', 'catId' => 10, 'prixHt' => 2.00, 'cout' => 0.50, 'tva' => 6],
        ['id' => 2, 'nom' => 'Pain au chocolat', 'catId' => 10, 'prixHt' => 1.50, 'cout' => 0.60, 'tva' => 6],
        ['id' => 3, 'nom' => 'Tarte sans fiche', 'catId' => 20, 'prixHt' => 3.00, 'cout' => null, 'tva' => 6],
        ['id' => 4, 'nom' => 'Éclair', 'catId' => 20, 'prixHt' => 4.00, 'cout' => 1.00, 'tva' => 6],
    ]];
}

require __DIR__ . '/../src/promos_magasin.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$_GET = ['shop' => '4']; $P = ep_analyse_produits_coefficients()['produits']; $_GET = [];
$cote = static fn (int $q, array $cats = [], array $prods = [], array $ex = []) => ['q' => $q, 'cats' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $cats),
    'prods' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $prods), 'exclus' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $ex)];

echo "Coefficient du lot\n";
$y = $cote(1, [10]) + ['type' => 'FREE', 'valeur' => null];
$c = pmCoef($P, $cote(2, [10]), $y);
// Le cas le plus serré : 3 pains au chocolat, le 3e offert — 3,00 € HT payés pour 1,80 € de matière.
verifie('2 achetés + 1 offert dans la catégorie : le cas le plus serré', $c['lot'] === 1.67 && $c['x'] === 'Pain au chocolat' && $c['y'] === 'Pain au chocolat', json_encode($c));
verifie('sans la promotion, le même lot', $c['sans'] === 2.5);
verifie('la paire impossible en caisse écartée (un croissant offert contre un pain au chocolat acheté)', $c['paires'] === 3, (string) $c['paires']);
$c = pmCoef($P, $cote(2, [10], [], [2]), $y);
verifie('le produit exclu ne compte plus', $c['lot'] === 2.5 && $c['nX'] === 1 && $c['nY'] === 2, json_encode($c));
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'FIXED_PRICE', 'valeur' => 2.12]);
verifie('prix fixe saisi TTC, ramené HT (2,12 € → 2,00 €)', $c['lot'] === 2.67, json_encode($c));
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 50]);
verifie('remise de 50 %', $c['lot'] === 2.67);
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'FIXED_AMOUNT_DISCOUNT', 'valeur' => 1.06]);
verifie('remise de 1,06 € TTC', $c['lot'] === 3.33, json_encode($c));
$c = pmCoef($P, $cote(1, [20]), $cote(1, [20]) + ['type' => 'FREE', 'valeur' => null]);
verifie('un produit sans coût de recette nommé, pas chiffré', $c['sansCout'] === ['Tarte sans fiche'] && $c['lot'] === 2.0, json_encode($c));
$c = pmCoef($P, $cote(1, [], [3]), $cote(1, [], [3]) + ['type' => 'FREE', 'valeur' => null]);
verifie('rien de chiffrable : pas de coefficient', $c['lot'] === null && $c['paires'] === 0);

echo "Les règles du magasin\n";
$now = date('Y-m-d H:i');
$j = static fn (int $n, string $h = '00:00') => date('Y-m-d', strtotime("+$n day")) . ' ' . $h;
$base = pmPropre(['nom' => 'Viennoiseries 2+1', 'x' => ['q' => 2, 'cats' => [['id' => 10]]], 'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 10]]], 'du' => $j(1), 'au' => $j(7, '23:59')], 4);
$coefOk = ['lot' => 2.4, 'x' => 'a', 'y' => 'b', 'paires' => 1, 'nX' => 1, 'nY' => 1];
verifie('une demande complète passe', pmErreurs($base, [], $coefOk, $now) === [], json_encode(pmErreurs($base, [], $coefOk, $now), JSON_UNESCAPED_UNICODE));
verifie('sans fin : refusée', count(pmErreurs(['au' => null] + $base, [], $coefOk, $now)) === 1);
verifie('15 jours : refusée', count(pmErreurs(['au' => $j(15)] + $base, [], $coefOk, $now)) === 1);
verifie('14 jours : acceptée', pmErreurs(['au' => $j(14, '23:59')] + $base, [], $coefOk, $now) === []);
$autres = [['shop' => 4, 'nom' => 'A', 'statut' => 'validee', 'du' => $j(0), 'au' => $j(3)], ['shop' => 4, 'nom' => 'B', 'statut' => 'a_valider', 'du' => $j(2), 'au' => $j(9)], ['shop' => 4, 'nom' => 'C', 'statut' => 'brouillon', 'du' => $j(1), 'au' => $j(5)],
    ['shop' => 7, 'nom' => 'D', 'statut' => 'validee', 'du' => $j(1), 'au' => $j(5)]];
$e = pmErreurs($base, $autres, $coefOk, $now);
verifie('deux autres en même temps : refusée, les deux nommées (pas le brouillon)', count($e) === 1 && str_contains($e[0], '« A », « B »'), json_encode($e, JSON_UNESCAPED_UNICODE));
verifie('une seule autre en même temps : acceptée (celle d’un autre magasin ne compte pas)', pmErreurs($base, array_slice($autres, 1), $coefOk, $now) === []);
$autres[] = ['shop' => 9, 'shops' => [9, 4], 'nom' => 'E', 'statut' => 'validee', 'du' => $j(1), 'au' => $j(2)];
verifie('une promotion d’un autre magasin qui couvre aussi celui-ci compte', count(pmErreurs($base, array_slice($autres, 1), $coefOk, $now)) === 1);
$e = pmErreurs(['shops' => [4, 7]] + $base, [$autres[1], $autres[3], ['shop' => 7, 'nom' => 'F', 'statut' => 'a_valider', 'du' => $j(1), 'au' => $j(3)]], $coefOk, $now);
verifie('deux magasins : la règle des deux à la fois tenue magasin par magasin', count($e) === 1 && str_contains($e[0], '« D », « F »'), json_encode($e, JSON_UNESCAPED_UNICODE));
$d = pmPropre(['shops' => [5, 4, 6, 7, 8, 9]], 4);
verifie('le magasin qui écrit d’abord, quatre magasins au plus', $d['shops'] === [4, 5, 6, 7]);
$e = pmErreurs($base, [], ['lot' => 1.67, 'x' => 'Pain au chocolat', 'y' => 'Pain au chocolat'] + $coefOk, $now);
verifie('coefficient sous ×2 : refusée, le cas nommé', count($e) === 1 && str_contains($e[0], '×1,67') && str_contains($e[0], 'Pain au chocolat'), json_encode($e, JSON_UNESCAPED_UNICODE));
$e = pmErreurs(pmPropre(['y' => ['type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 120]], 4), [], null, $now);
verifie('vide : nom, X, Y, valeur, dates demandés', count($e) === 6, json_encode($e, JSON_UNESCAPED_UNICODE));
$d = pmPropre(['creneaux' => [['jours' => [5, 1, 9], 'de' => '07:00', 'a' => '10:00'], ['jours' => [2], 'de' => '12:00', 'a' => '11:00']], 'maxParTicket' => '3', 'du' => '2026-10-20T08:30'], 4);
verifie('créneaux nettoyés (jours triés, 9 et le créneau à l’envers écartés)', $d['creneaux'] === [['jours' => [1, 5], 'de' => '07:00', 'a' => '10:00']], json_encode($d['creneaux']));
verifie('date du navigateur relue, nombre max par ticket', $d['du'] === '2026-10-20 08:30' && $d['maxParTicket'] === 3);

echo "Le parcours d'une demande\n";
$GLOBALS['reglages'] = [];
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'brouillon', 'nom' => 'Pains au chocolat 2+1', 'x' => ['q' => 2, 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]]];
$r = wr_promos_magasin();
verifie('un brouillon s’enregistre même incomplet, coefficient calculé', ($r['demande']['statut'] ?? '') === 'brouillon' && $r['demande']['coef']['lot'] === 1.67, json_encode($r));
$id = $r['demande']['id'];
$GLOBALS['corps'] = ['id' => $id, 'action' => 'envoyer', 'du' => $j(1), 'au' => $j(5)] + $GLOBALS['corps'];
$r = @wr_promos_magasin();
verifie('l’envoi d’un lot à ×1,67 refusé (422), la raison dite', ($r['error'] ?? '') === 'à compléter' && count($r['erreurs']) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps']['y'] = ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 30, 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]];
$GLOBALS['corps']['x'] = ['q' => 3, 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]];
$r = wr_promos_magasin();
verifie('3 achetés + le 4e à −30 % : envoyé à la marque', ($r['demande']['statut'] ?? '') === 'a_valider' && $r['demande']['id'] === $id && $r['demande']['coef']['lot'] >= 2, json_encode($r['demande']['coef'] ?? $r));
verifie('une seule demande gardée', count(pmLire()) === 1);
$_GET = ['shop' => '4'];
$l = ep_promos_magasin_demandes();
verifie('la liste du magasin la rend, avec les règles', count($l['demandes']) === 1 && $l['regles']['coefMin'] === 2.0);
$_GET = ['shop' => '5']; $l = ep_promos_magasin_demandes();
verifie('un autre magasin ne la voit pas', $l['demandes'] === []);
$_GET = [];
$GLOBALS['corps'] = ['decision' => 'refuser'];
$r = @wr_promos_magasin_decision($id);
verifie('un refus sans mot : refusé', isset($r['error']));
$GLOBALS['corps'] = ['decision' => 'valider'];
$r = wr_promos_magasin_decision($id);
verifie('la marque valide', ($r['demande']['statut'] ?? '') === 'validee');
$GLOBALS['corps'] = ['shop' => 4, 'id' => $id, 'action' => 'brouillon', 'nom' => 'x'];
$r = @wr_promos_magasin();
verifie('validée : ne se modifie plus d’ici', str_contains((string) ($r['error'] ?? ''), 'validée'));
$_GET = ['shop' => '4', 'id' => $id];
$r = @wr_promos_magasin_retirer();
verifie('validée : ne se retire plus d’ici', isset($r['error']));
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'brouillon', 'nom' => 'Essai'];
$r = wr_promos_magasin();
$_GET = ['shop' => '5', 'id' => $r['demande']['id']];
$r2 = @wr_promos_magasin_retirer();
verifie('le brouillon d’un autre magasin ne se retire pas', isset($r2['error']));
$_GET = ['shop' => '4', 'id' => $r['demande']['id']];
verifie('un brouillon se retire', (wr_promos_magasin_retirer()['ok'] ?? false) === true && count(pmLire()) === 1);
$GLOBALS['corps'] = ['shop' => 4, 'shops' => [4, 5], 'action' => 'envoyer', 'nom' => 'Pains au chocolat, deux magasins', 'du' => $j(1), 'au' => $j(5),
    'x' => ['q' => 3, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 30, 'prods' => [['id' => 2]]]];
$r = @wr_promos_magasin();
// À Halle, ×2,31 ; au magasin 5 (1,40 € HT), 3 × 1,40 + 0,98 = 5,18 € pour 2,40 € de matière : ×2,16 — le plus serré.
verifie('deux magasins : le coefficient le plus serré, son magasin nommé', ($r['demande']['coef']['magasin'] ?? 0) === 5 && $r['demande']['coef']['lot'] === 2.16, json_encode($r['demande']['coef'] ?? $r, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '5']; $l = ep_promos_magasin_demandes();
verifie('le magasin 5 la voit dans sa liste', count($l['demandes']) === 1 && $l['demandes'][0]['shop'] === 4);
$GLOBALS['corps']['y']['valeur'] = 60;   // Halle ×2,13 ; magasin 5 : 4,20 + 0,56 = 4,76 € pour 2,40 € — ×1,98
$GLOBALS['corps']['id'] = $l['demandes'][0]['id']; $GLOBALS['corps']['shop'] = 5;
$r = @wr_promos_magasin();
verifie('modifiée depuis le magasin 5 : refusée sous ×2 là-bas, le magasin qui l’a écrite le reste', isset($r['erreurs']) && str_contains($r['erreurs'][0], 'magasin 5') && pmLire()[1]['shop'] === 4, json_encode($r, JSON_UNESCAPED_UNICODE));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
