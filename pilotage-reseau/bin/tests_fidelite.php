#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la fidélité (src/fidelite.php, 13/11/2026) : la carte à points de l'ERP et son bingo de saison, lus et
 * écrits par l'API seule — sans base, sans réseau. L'ERP est une doublure (tools/fidelite_faux_erp.php) qui suit
 * l'openapi : formes des réponses, revision, carte figée, code de bon déjà pris.
 *
 *   php bin/tests_fidelite.php
 */

// --- doublures : pas de base, pas de session ----------------------------------------------
$GLOBALS['corps'] = [];
$GLOBALS['journal'] = [];
function body(): array { return $GLOBALS['corps']; }
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function journalAdd(string $a, string $k, ?string $p, string $m, ?string $ts = null): void { $GLOBALS['journal'][] = $m; }

require __DIR__ . '/../src/fidelite.php';
require __DIR__ . '/../tools/fidelite_faux_erp.php';

$ok = 0; $ko = 0;
function verifie(bool $c, string $quoi): void
{
    global $ok, $ko;
    if ($c) { $ok++; } else { $ko++; fwrite(STDERR, "ÉCHEC : $quoi\n"); }
}
function nouvelErp(int $n = 240): FidFauxErp
{
    $e = new FidFauxErp(['clients' => $n]);
    $GLOBALS['FID_ERP'] = $e;
    $GLOBALS['FID_SANS_CACHE'] = true;
    $GLOBALS['FID_AUJOURDHUI'] = '2027-01-14';
    $GLOBALS['FID_HEURE'] = '16:00';
    $_GET = [];
    return $e;
}

// --- 1. La saison --------------------------------------------------------------------------
nouvelErp();
$s = fidSaison('2027-01-14');
verifie($s['id'] === 'H27' && count($s['semaines']) === 13, 'la saison d’hiver, 13 semaines');
verifie($s['enCours'] === 8 && $s['closes'] === 7, 'jeudi 14/01 : semaine 8 en cours, 7 closes');
verifie($s['semaines'][7]['du'] === '2027-01-11' && $s['semaines'][7]['au'] === '2027-01-17', 'S8 du lundi 11 au dimanche 17/01');
verifie($s['au'] === '2027-02-21', 'la saison finit le dimanche 21/02');
verifie(fidSaison('2026-11-22')['enCours'] === 0 && fidSaison('2026-11-22')['closes'] === 0, 'la veille : rien de commencé');
$A = fidAnnee('2027-01-14');
verifie(array_column($A, 'id') === ['H27', 'P27', 'E27', 'A27'] && array_column($A, 'etat') === ['cours', 'avenir', 'avenir', 'avenir'], 'quatre cartes de bingo dans l’année');
verifie($A[1]['du'] === '2027-02-22' && $A[3]['au'] === '2027-11-21', 'chaque saison commence le lundi qui suit la précédente');
foreach (fidBingoConf()['saisons'] as $x) { verifie(count($x['semaines']) === 13, 'treize semaines : ' . $x['id']); }
verifie(fidSaison('2027-02-22')['id'] === 'P27' && fidSaison('2027-02-21')['id'] === 'H27', 'la carte suivante arrive le lundi');
verifie(fidSaison('2027-01-14', 'E27')['etat'] === 'avenir' && fidSaison('2027-01-14', 'E27')['campagne'] === 'Bingo été', 'une autre saison se choisit');
$b = fidBonsSaison($s);
verifie(array_column($b, 'k') === [3, 6, 9, 12] && array_column($b, 'valeur') === [2.0, 2.0, 2.0, 2.0], 'un bon de 2 € toutes les 3 cases (handoff)');
verifie(array_column($b, 'nom') === ['Bon de l’Avent', 'Bon des Rois', 'Bon de l’hiver', 'Bon carte pleine'], 'les noms des bons selon le rang');
verifie(fidUtc('2026-11-23') === '2026-11-22 23:00:00' && fidUtc('2027-06-01') === '2027-05-31 22:00:00', 'minuit de Bruxelles en UTC (hiver, été)');

// --- 2. GET /fidelite : la carte et le bingo ------------------------------------------------
$d = ep_fidelite();
verifie($d['erp']['ok'] && $d['carte']['id'] === 12 && $d['carte']['code'] === 'FID-POINTS', 'la carte du programme : FID-POINTS');
verifie($d['carte']['verrou'] === true && $d['carte']['revision'] === 7, 'la carte est figée, revision 7');
verifie($d['carte']['points']['tiers'] === [['points' => 100, 'voucher_cents' => 500]], 'le palier 100 → 5 €');
verifie($d['bingo']['seuil'] === 10.0 && $d['bingo']['multiplicateur'] === 2.0 && $d['bingo']['palier']['points'] === 100, 'les réglages du handoff : 10 €, ×2 après la case, 100 points');
verifie(count($d['carte']['points']['bonuses']) === 2 && $d['carte']['points']['bonuses'][0]['days'] === [1, 2, 3], 'deux ×2, mardi à jeudi (lundi = 0)');
verifie(count($d['autres']) === 4 && $d['autres'][0]['nom'] === 'Carte Pain', 'quatre cartes à tampons à arrêter');
verifie(count($d['magasins']) === 4 && $d['magasins'][0]['nom'] === 'Corbais', 'les magasins de l’ERP, nom court');
verifie(array_map(static fn ($x) => $x['campagne']['id'] ?? null, $d['bingo']['bons']) === [41, 41, 41, 41], 'les bons de 2 € partagent la campagne « Bingo hiver · 2 € »');
verifie($d['bingo']['superBon']['campagne']['statut'] === 'DRAFT' && $d['bingo']['superBon']['provisoire'], 'le super bon : campagne en brouillon, montant provisoire');
verifie($d['bingo']['coefficients'] === ['caseCochee' => 2, 'jourPerso' => 3, 'offrePerso' => [2, 3], 'produitSemaine' => 2, 'habituel' => 1], 'les coefficients de la semaine (handoff, onglet Points)');
verifie(count($d['annee']) === 4 && $d['annee'][0]['etat'] === 'cours', 'les quatre cartes de l’année');
$_GET = ['saison' => 'P27'];
$dp = ep_fidelite();
verifie($dp['bingo']['saison']['id'] === 'P27' && ($dp['bingo']['bons'][0]['campagne']['id'] ?? null) === 51 && $dp['bingo']['superBon']['campagne'] === null, 'le printemps a ses propres campagnes');
$_GET = [];

// --- 3. GET /fidelite/clients : les cases viennent des points de chaque semaine -------------
$c = ep_fidelite_clients();
verifie($c['erp']['ok'] && count($c['semaines']) === 8, 'huit semaines lues (sept closes et l’en cours)');
verifie(array_sum($c['repartition']) === $c['kpis']['membres'], 'la répartition compte chaque membre une fois');
verifie(count($c['repartition']) === 8, 'de 0 à 7 cases après sept semaines closes');
$parts = array_filter(array_column($c['semaines'], 'part'), static fn ($v) => $v !== null);
verifie(count($parts) === 8 && min($parts) >= 0 && max($parts) <= 100, 'une part entre 0 et 100 chaque semaine');
verifie($c['semaines'][5]['part'] < $c['semaines'][4]['part'], 'Nouvel An creuse, Noël plein');
verifie($c['semaines'][0]['avant'] !== null && $c['semaines'][7]['avant'] === null, 'l’hiver passé, sur les semaines closes seulement');
verifie($c['kpis']['points'] > 0 && $c['kpis']['transactions'] > 0, 'les chiffres de /loyalty/statistics');
verifie(count($c['relances']) === 5 && $c['membres'] !== [], 'cinq relances et la liste des membres');
$m0 = $c['membres'][0];
verifie(count($m0['coches']) === 8 && $m0['cases'] === count(array_filter($m0['coches'])), 'les cases d’un membre = ses semaines à 20 points');
$_GET = ['shop' => '5'];
$c5 = ep_fidelite_clients();
verifie($c5['kpis']['membres'] < $c['kpis']['membres'] && $c5['shop'] === '5', 'un magasin : ses membres seulement');
$_GET = ['q' => 'julie'];
$cq = ep_fidelite_clients();
verifie($cq['membres'] !== [] && count(array_filter($cq['membres'], static fn ($m) => !str_starts_with($m['nom'], 'Julie'))) === 0, 'la recherche par prénom');
$_GET = [];

// La règle elle-même : 10 € d'achats dans la semaine, tous magasins, sinon pas de case (les points n'y sont pour rien).
$GLOBALS['FID_ERP'] = static function (string $m, string $p, ?array $b) {
    if (str_starts_with($p, '/admin/loyalty/options')) { return ['code' => 200, 'corps' => ['shops' => [['id' => 2, 'name' => 'Corbais'], ['id' => 3, 'name' => 'Gosselies']]], 'erreur' => null]; }
    parse_str((string) parse_url($p, PHP_URL_QUERY), $q);
    $shop = (int) preg_replace('#^/shops/(\d+)/.*#', '$1', $p);
    // Semaine 1 : 6,40 € à Corbais + 3,60 € à Gosselies = 10 € → case ; semaine 2 : 9,99 € (et 40 points) → pas de case.
    [$eur, $pts] = ['2026-11-23' => [2 => [6.4, 6], 3 => [3.6, 3]], '2026-11-30' => [2 => [9.99, 40], 3 => [0, 0]]][$q['from'] ?? ''][$shop] ?? [0, 0];
    $f = ['id' => 7, 'name' => 'Julie', 'surname' => 'V', 'programs' => [['id' => 12, 'global_progress' => ['points' => ['balance' => 40, 'tiers' => []]], 'shop_activity' => ['points_earned' => $pts]]],
        'shop_activity' => ['gross_sales_before_corrections' => number_format($eur, 2, '.', '')]];
    return ['code' => 200, 'corps' => ['items' => [$f], 'pagination' => ['total' => 1]], 'erreur' => null];
};
$GLOBALS['FID_AUJOURDHUI'] = '2026-12-07';
$n = 0;
$C = fidBingoClients(fidSaison(), 12, ['2', '3'], 10.0, $n);
verifie(abs($C[7]['euros'][1] - 10.0) < 0.001 && $C[7]['coches'][1] === true, '6,40 € + 3,60 € dans deux magasins = une case');
verifie($C[7]['coches'][2] === false && $C[7]['points'][2] === 40 && $C[7]['cases'] === 1, '9,99 € : pas de case, quels que soient les points');
verifie($C[7]['nom'] === 'Julie V.', 'prénom et initiale');

// --- 4. POST /fidelite/simuler -----------------------------------------------------------------
$e = nouvelErp();
$GLOBALS['corps'] = ['shop' => 2, 'montant' => '22,40', 'quand' => '2027-01-14T15:10'];
$r = wr_fidelite_simuler();
verifie($r['points'] === 44 && $r['tranches'] === 22 && $r['coefBonus'] === '2' && $r['coche'] === true, 'jeudi 15:10, 22,40 € à Corbais : 44 points, la case');
$pts = $d['carte']['points']; $pts['shop_coefficients']['5'] = 1.5;
$GLOBALS['corps'] = ['shop' => 5, 'montant' => 22.4, 'quand' => '2027-01-14T15:10', 'points' => $pts];
verifie(wr_fidelite_simuler()['points'] === 66, 'le barème en cours d’écriture : Sombreffe ×1,5 → 66 points');
$GLOBALS['corps'] = ['shop' => 2, 'montant' => 12, 'quand' => '2027-01-18T10:00'];
$r = wr_fidelite_simuler();
verifie($r['points'] === 12 && $r['coche'] === true, 'lundi 10 h, 12 € : 12 points, et la case (≥ 10 €)');
$GLOBALS['corps'] = ['shop' => 2, 'montant' => '6,40', 'quand' => '2027-01-11T10:00'];
verifie(wr_fidelite_simuler()['coche'] === false, '6,40 € seul ne coche pas');
verifie($e->ecrits[0][1] === '/admin/loyalty/simulate' && count(array_filter($e->ecrits, static fn ($w) => $w[1] !== '/admin/loyalty/simulate')) === 0, 'simuler n’écrit rien d’autre');

// --- 5. POST /fidelite/publier -----------------------------------------------------------------
$e = nouvelErp();
$pts['tiers'][] = ['points' => 300, 'voucher_cents' => 1100];
$GLOBALS['corps'] = ['carte' => ['id' => 12, 'revision' => 6, 'points' => $pts], 'qui' => 'Test'];
$r = wr_fidelite_publier();
verifie(isset($r['error']) && $e->ecrits === [], 'revision périmée : refus, rien d’écrit');
$GLOBALS['corps'] = ['carte' => ['id' => 12, 'revision' => 7, 'points' => $pts], 'debut' => '2027-02-01', 'arreter' => [3, 4], 'qui' => 'Test'];
$r = wr_fidelite_publier();
verifie($r['ok'] === true && count($r['etapes']) === 4, 'carte figée : version 2, fin de la v1, deux cartes arrêtées');
verifie($e->ecrits[0][0] === 'POST' && $e->ecrits[0][2]['version'] === 2 && $e->ecrits[0][2]['starts_at'] === '2027-01-31 23:00:00', 'la v2 commence le 01/02 à minuit, heure de Bruxelles');
verifie($e->ecrits[0][2]['points_config']['tiers'][1] === ['points' => 300, 'voucher_cents' => 1100] && $e->ecrits[0][2]['code'] === 'FID-POINTS', 'la v2 : même code, nouveau barème');
verifie(!isset($e->ecrits[0][2]['earning_product_ids']) && $e->ecrits[0][2]['keywords'] === ['tout'], 'mots-clés OU identifiants, jamais les deux');
verifie($e->ecrits[1][0] === 'PATCH' && $e->ecrits[1][2] === ['revision' => 7, 'ends_at' => '2027-01-31 22:59:59'], 'la v1 finit la veille à 23:59:59');
verifie($e->cartes[3]['ends_at'] === '2027-01-14 22:59:59' && $e->cartes[4]['revision'] === 4, 'les cartes à tampons s’arrêtent ce soir');
verifie(count($GLOBALS['journal']) === 1, 'au journal');
$e = nouvelErp();
$e->cartes[12]['terms_locked'] = false;
$GLOBALS['corps'] = ['carte' => ['id' => 12, 'revision' => 7, 'points' => $pts]];
$r = wr_fidelite_publier();
verifie($r['ok'] && count($e->ecrits) === 1 && $e->ecrits[0][0] === 'PATCH' && $e->cartes[12]['revision'] === 8, 'carte libre : un PATCH avec sa revision');

// --- 6. Les bons du bingo ---------------------------------------------------------------------
$e = nouvelErp(240);
$r = fidBingoBons(false);
verifie($r['simulation'] && $e->ecrits === [], 'simulation : rien d’écrit');
$dus = array_sum(array_column(array_slice($r['bons'], 0, 2), 'aEmettre'));
verifie($r['bons'][0]['ayants'] >= $r['bons'][1]['ayants'] && $r['bons'][0]['ayants'] > 0, 'plus de clients à 3 cases qu’à 6');
verifie($r['bons'][2]['ayants'] === 0 && $r['bons'][4]['k'] === 13, 'personne à 9 cases après sept semaines ; le super bon à 13');
$r = fidBingoBons(true);
verifie($r['crees'] === $dus && $dus > 0, 'les bons dus sont créés (campagnes actives)');
$w = $e->ecrits[0][2];
verifie(preg_match('/^BGOH27K[36]C\d+$/', $w['code']) === 1 && str_contains($e->ecrits[0][1], '/41/codes') && $w['usage_limit'] === 1 && $w['valid_from'] === '2027-01-15 00:00:00' && $w['valid_to'] === '2027-02-14 23:59:59', 'un code par client, valable 30 jours dès le lendemain');
$r = fidBingoBons(true);
verifie($r['crees'] === 0, 'une seconde fois : rien de plus');
$e->campagnes[41]['status'] = 'DRAFT';
verifie(fidBingoBons(true)['crees'] === 0, 'une campagne en brouillon n’émet rien');

// --- 7. ERP absent ------------------------------------------------------------------------
$GLOBALS['FID_ERP'] = static fn () => ['code' => 0, 'corps' => null, 'erreur' => 'API ERP injoignable'];
$d = ep_fidelite();
verifie($d['erp']['ok'] === false && $d['carte'] === null && $d['bingo']['saison']['id'] === 'H27', 'ERP injoignable : dit, et le calendrier reste');
verifie(ep_fidelite_clients()['erp']['ok'] === false, 'les clients aussi le disent');

echo "fidélité : $ok vérifications passées, $ko en échec\n";
exit($ko ? 1 : 0);
