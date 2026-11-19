#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des bundles du Coach Vente (src/franchise/bundles.php, 17/11/2026) — sans base, sans réseau, sans panel.
 *
 *   php bin/tests_bundles.php
 *
 * Les prix et coûts de recette sont ceux de Halle au panel (croissant, pain au chocolat, café, cappuccino, éclair,
 * baguette) ; les ventes, des pièces d'exemple. On vérifie : la marge d'un produit et la marge de base (pondérée par les
 * ventes hors TVA), un couple (prix conseillé, marge du bundle, marge en plus), le choix des couples (sous la marge de
 * base, 78 % et plus, le moment partagé, un produit qui rapporte deux fois au plus), les dates (le lundi qui suit, un
 * mois), le corps envoyé au panel (Y à prix fixe), la fiche de l'équipe (ni marge ni coefficient) et l'équipe.
 */

final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): int { return 0; }
}
function setting(string $k, mixed $d = null): mixed { return $d; }

require __DIR__ . '/../src/bundles.php';
require __DIR__ . '/../src/automatisations.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// Les fiches du panel (aivProduits) : prix TTC, HT, coût de recette.
$fiche = static fn (string $nom, string $cat, float $ttc, float $cout) => ['nom' => $nom, 'categorie' => $cat, 'prixTtc' => $ttc, 'prixHt' => round($ttc / 1.06, 4), 'cout' => $cout];
$prod = [
    1 => $fiche('Pain au Chocolat', 'Viennoiserie Ind.', 1.90, 0.805),
    2 => $fiche('Croissant', 'Viennoiserie Ind.', 1.80, 0.6838),
    3 => $fiche('Baguette Tradition', 'Petite Boulangerie', 2.20, 1.466),
    4 => $fiche('Café', 'Boissons chaudes', 2.30, 0.147),
    5 => $fiche('Cappuccino', 'Boissons chaudes', 2.80, 0.091),
    6 => $fiche('Éclair chocolat', 'Pâtisserie individuelle', 3.00, 0.555),
    7 => $fiche('Tarte au riz', 'Tartes', 21.90, 9.182),
    9 => $fiche('Pistolet', 'Petite Boulangerie', 0.60, 0.12),
    8 => ['nom' => 'Sans coût', 'categorie' => 'x', 'prixTtc' => 2.0, 'prixHt' => 1.89, 'cout' => null],
];
// Les ventes (hvProduits) : [pid, portion, nom, pièces, ventes TTC] ; le pain au chocolat se vend aussi en portion.
$ventes = [[1, 0, 'Pain au Chocolat', 1500, 2850.0], [1, 2, 'Pain au Chocolat', 87, 165.3], [2, 0, 'Croissant', 1800, 3240.0], [3, 0, 'Baguette Tradition', 850, 1870.0],
    [4, 0, 'Café', 80, 184.0], [5, 0, 'Cappuccino', 20, 56.0], [6, 0, 'Éclair chocolat', 290, 870.0], [7, 0, 'Tarte au riz', 100, 2190.0], [9, 0, 'Pistolet', 2500, 1500.0], [8, 0, 'Sans coût', 50, 100.0]];
// Les créneaux : la viennoiserie et le café le matin, la baguette et l'éclair l'après-midi.
$cr = ['matin' => [[1, 0, '', 1300, 0], [2, 0, '', 1600, 0], [4, 0, '', 70, 0], [5, 0, '', 18, 0], [3, 0, '', 150, 0], [6, 0, '', 20, 0]],
    'midi' => [[1, 0, '', 150, 0], [2, 0, '', 150, 0], [4, 0, '', 5, 0], [3, 0, '', 300, 0], [6, 0, '', 70, 0]],
    'apres' => [[1, 0, '', 137, 0], [2, 0, '', 50, 0], [4, 0, '', 5, 0], [5, 0, '', 2, 0], [3, 0, '', 400, 0], [6, 0, '', 200, 0]]];

echo "La marge des produits et la marge de base\n";
$P = bndProduits($ventes, $prod, $cr);
verifie('un produit sans coût de recette ne compte pas', !isset($P[8]) && count($P) === 8);
verifie('les portions s’ajoutent au produit', abs($P[1]['q'] - 1587) < 0.01 && abs($P[1]['v'] - 3015.3) < 0.01);
verifie('la marge d’un produit : (prix HT − coût) ÷ prix HT', $P[1]['m'] === 55.1 && $P[4]['m'] === 93.2 && $P[3]['m'] === 29.4, json_encode([$P[1]['m'], $P[4]['m'], $P[3]['m']]));
verifie('les produits, par pièces vendues décroissantes', array_keys($P) === [9, 2, 1, 3, 6, 7, 4, 5]);
verifie('les parts de chaque créneau', abs($P[4]['parts']['matin'] - 70 / 80) < 1e-9 && abs(array_sum($P[3]['parts']) - 1) < 1e-9);
$B = bndBase($P);
$ht = 0; $mg = 0; foreach ($P as $p) { $ht += $p['v'] / 1.06; $mg += $p['v'] / 1.06 * $p['m'] / 100; }
verifie('la marge de base : pondérée par les ventes hors TVA', abs($B['base'] - round(100 * $mg / $ht, 1)) < 0.001 && $B['produits'] === 8, json_encode($B));
$q = array_sum(array_column($P, 'q'));
$sous = array_sum(array_map(static fn ($p) => $p['m'] < $B['base'] ? $p['q'] : 0, $P)); $haut = array_sum(array_map(static fn ($p) => $p['m'] >= 78 ? $p['q'] : 0, $P));
verifie('les pièces sous la marge de base, et à 78 % et plus', $B['sousPct'] === round(100 * $sous / $q, 1) && $B['hautPct'] === round(100 * $haut / $q, 1) && $B['base'] > 55.1, json_encode($B));

echo "Un couple\n";
verifie('le prix conseillé : −10 %, à 10 centimes près', bndPrixConseille(4.20) === 3.8 && bndPrixConseille(4.60) === 4.1 && bndPrixConseille(6.40) === 5.8);
$c = bndCouple($P[1], $P[4], 3.80);
verifie('pain au chocolat + café à 3,80 € : le client gagne 0,40 €', $c['separes'] === 4.2 && $c['remise'] === 0.4 && $c['remisePct'] === 9.5);
verifie('la marge du bundle et celle du produit seul', abs($c['margeBundle'] - 2.63) < 0.006 && abs($c['margeBundlePct'] - 73.5) <= 0.1 && $c['margeXSeulPct'] === 55.1, json_encode($c));
verifie('la marge en plus d’un ticket : bundle − pain au chocolat seul', abs($c['gainTicket'] - 1.65) < 0.006);
verifie('par mois : un client sur dix, sur 30 jours', $c['bundlesMois'] === 159 && abs($c['gainMois'] - 158.7 * $c['gainTicket']) < 1, json_encode($c));
verifie('au panel, le café à prix fixe : 3,80 € − 1,90 €', $c['prixY'] === 1.9);

echo "Les couples\n";
$C = bndCouples($P, $B['base']);
$paires = array_map(static fn ($x) => $x['x'] . '+' . $x['y'], $C);
verifie('le produit qui attire est sous la marge de base, le produit qui rapporte à 78 % et plus', $C !== [] && !array_filter($C, static fn ($x) => $P[$x['x']]['m'] >= $B['base'] || $P[$x['y']]['m'] < 78), implode(' ', $paires));
verifie('pas de tarte entière dans un couple (8 € au plus)', !array_filter($C, static fn ($x) => $x['x'] === 7 || $x['y'] === 7));
verifie('un produit qui attire une seule fois', count(array_unique(array_column($C, 'x'))) === count($C));
verifie('un produit qui rapporte deux fois au plus', max(array_count_values(array_column($C, 'y'))) <= 2);
verifie('deux produits de familles différentes', !array_filter($C, static fn ($x) => bndFamille($P[$x['x']]['cat']) === bndFamille($P[$x['y']]['cat'])) && bndFamille('Viennoiserie Ind.') === bndFamille('Viennoiserie réduction') && bndFamille('Pâtisserie individuelle') === 'patisserie');
verifie('chaque couple monte la marge du ticket', !array_filter($C, static fn ($x) => $x['margeBundlePct'] <= $x['margeXSeulPct'] || $x['gainTicket'] <= 0));
verifie('classés par la marge en plus du mois', array_column($C, 'gainMois') === array_values(array_reverse(array_values(array_sort_numeric(array_column($C, 'gainMois'))))), json_encode(array_column($C, 'gainMois')));
$pac = array_values(array_filter($C, static fn ($x) => $x['x'] === 1))[0] ?? null;
verifie('le pain au chocolat va avec une boisson du matin, au moment qui leur est commun', $pac !== null && in_array($pac['y'], [4, 5], true) && $pac['moment'] === 'le matin', json_encode($pac));
$bag = array_values(array_filter($C, static fn ($x) => $x['x'] === 3))[0] ?? null;
verifie('la baguette (29 %) va avec l’éclair de l’après-midi', $bag !== null && $bag['y'] === 6 && $bag['moment'] === 'l’après-midi', json_encode($bag));
function array_sort_numeric(array $a): array { sort($a); return $a; }

echo "Les dates\n";
verifie('validé un mardi : du lundi qui suit, un mois (la veille du même quantième)', bndDates('1m', '2026-11-17') === ['2026-11-23', '2026-12-22']);
verifie('validé un lundi : le lundi suivant', bndDates('1m', '2026-11-23') === ['2026-11-30', '2026-12-29']);
verifie('deux semaines : du lundi au dimanche', bndDates('2s', '2026-11-17') === ['2026-11-23', '2026-12-06']);
verifie('deux mois', bndDates('2m', '2026-11-17') === ['2026-11-23', '2027-01-22']);
verifie('les jours en mots', bndJour('2026-11-23') === 'lundi 23 novembre' && bndJour('2026-12-22', true) === 'mardi 22 décembre 2026');

echo "Le panel et la fiche\n";
$f = ['magasin' => 'Halle', 'nom' => 'Duo du matin', 'x' => 1, 'xNom' => 'Pain au Chocolat', 'y' => 4, 'yNom' => 'Café', 'prix' => 3.80, 'separes' => 4.20,
    'duree' => '1m', 'canal' => 'tous', 'du' => '2026-11-23', 'au' => '2026-12-22', 'moment' => 'le matin', 'couple' => $c, 'le' => '2026-11-17 13:52:00'];
$k = bndCorpsPanel(4, $f);
verifie('au panel : une promotion envoyée, X une pièce, Y une pièce à prix fixe', $k['action'] === 'envoyer' && $k['x']['prods'][0]['id'] === 1 && $k['x']['q'] === 1
    && $k['y']['prods'][0]['id'] === 4 && $k['y']['type'] === 'FIXED_PRICE' && $k['y']['valeur'] === 1.9, json_encode($k));
verifie('du lundi 00:00 à la fin 23:59, répétable, sur les canaux choisis', $k['du'] === '2026-11-23 00:00' && $k['au'] === '2026-12-22 23:59' && $k['repetable'] === true && $k['canal'] === 'tous');
$h = bndFicheHtml($f);
verifie('la fiche dit le bundle, le prix, les dates et les phrases', str_contains($h, 'Duo du matin') && str_contains($h, "3,80\u{00A0}€") && str_contains($h, "4,20\u{00A0}€")
    && str_contains($h, 'lundi 23 novembre') && str_contains($h, 'mardi 22 décembre 2026') && str_contains($h, 'Café avec votre pain au chocolat ?'));
verifie('la fiche de l’équipe ne dit ni la marge ni le coefficient', !preg_match('/marge|coefficient|co[ûu]t/iu', strip_tags($h)), mb_substr(strip_tags($h), 0, 400));
verifie('le nom pour l’équipe : sans la précision entre parenthèses', bndNomCourt('Cappuccino (à emporter)') === 'Cappuccino' && bndNomCourt('Pain au Chocolat') === 'Pain au Chocolat');
verifie('une adresse se masque', bndMasquer('lina.k@gmail.com') === 'l•••@gmail.com' && bndMasquer('rien') === '•••');

echo "L'équipe\n";
verifie('une personne : un nom et une adresse valide', amEquipeValider(['nom' => ' Lina K. ', 'adresse' => ' Lina.K@Gmail.com ']) === ['Lina K.', 'lina.k@gmail.com', null]);
verifie('sans nom, ou avec une adresse fausse : refusé', amEquipeValider(['nom' => '', 'adresse' => 'a@b.be'])[2] !== null && amEquipeValider(['nom' => 'Tom', 'adresse' => 'tom@'])[2] !== null);

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
