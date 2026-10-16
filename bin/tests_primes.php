#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des primes de l'app worker (14/10/2026) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_primes.php
 *
 * Ce qui se vérifie : la liste des primes (réglage d'avant repris, deux targets toujours là, une
 * promo créée depuis la liste du panel et jamais depuis ce que le navigateur envoie), une récompense
 * par prime (ventes croisées à la cible, objectif magasin par personne, 1re du magasin pour une
 * catégorie ou une promo), les pièces d'une promo sur ses seules dates et dans ses seuls magasins,
 * la relecture des jours sans une famille, les promotions du panel mises à plat, les moyennes par
 * magasin, les cartes de l'app.
 *
 * `Db`, `setting()` et le panel sont remplacés par des doublures : un magasin de réglages en mémoire
 * que les écritures du code remplissent.
 */

// --- doublures ---------------------------------------------------------------------------
$GLOBALS['REGLAGES'] = [];
// La note Google des magasins (la Réputation) : Halle à 4,6, Namur à 4,3.
$GLOBALS['TABLES'] = ['shops' => [], 'employes' => [], 'planning' => [], 'budgets' => [],
    'reputation' => [['shop_id' => 4, 'rating_avg' => 4.6, 'rating_count' => 312], ['shop_id' => 5, 'rating_avg' => 4.3, 'rating_count' => 120]]];
final class Db
{
    public static function rows(string $sql, array $a = []): array
    {
        $T = $GLOBALS['TABLES'];
        if (str_contains($sql, 'FROM shops')) { return $T['shops']; }
        if (str_contains($sql, 'ceo_shop_reputation') || str_contains($sql, 'mar_avis_fiche')) { return $T['reputation']; }
        if (str_contains($sql, 'franchisee_employee_schedule')) { return $T['planning']; }
        if (str_contains($sql, 'FROM franchisee_employee')) { return $T['employes']; }
        if (str_contains($sql, 'ceo_shop_month_perf')) { return $T['budgets'][(int) $a[0] . '-' . str_pad((string) $a[1], 2, '0', STR_PAD_LEFT)] ?? []; }
        return [];
    }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void
    {
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { $GLOBALS['REGLAGES'][$a[0]] = json_decode($a[1], true); }
    }
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['REGLAGES'][$k] ?? $d; }
$GLOBALS['JOURNAL'] = [];
function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void { $GLOBALS['JOURNAL'][] = $message; }
$GLOBALS['CORPS'] = [];
function body(): array { return $GLOBALS['CORPS']; }
final class PanelApi
{
    public static array $reponses = [];
    public static bool $configure = false;
    public static function configured(): bool { return self::$configure; }
    public static function sondeGet(string $path, int $timeout = 12): array { return self::$reponses[$path] ?? ['code' => 403, 'corps' => ['message' => 'Forbidden'], 'erreur' => 'HTTP 403']; }
    public static function liste(array $d): array
    {
        if (array_is_list($d)) { return array_values(array_filter($d, 'is_array')); }
        foreach (['data', 'items'] as $k) { if (isset($d[$k]) && is_array($d[$k])) { return self::liste($d[$k]); } }
        return [];
    }
    public static function get(string $p): mixed { return null; }
}
/** Le catalogue : deux bundles, une tarte, une quiche. */
function svCategories(): array { return [900 => 'Bundle', 901 => 'Bundle', 10 => 'Tartes', 20 => 'Quiches']; }
function svNomProduit(int|string $cle, string $nomLu): string { return [900 => 'Café + croissant', 901 => '2 Cookies', 10 => 'Tarte aux pommes', 20 => 'Quiche lorraine'][(int) $cle] ?? $nomLu; }

require __DIR__ . '/../src/ventes.php';
require __DIR__ . '/../src/panel_ventes.php';
require __DIR__ . '/../src/primes.php';
require __DIR__ . '/../src/primes_app.php';
require __DIR__ . '/../src/sms.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
/** Le réglage relu à neuf (primesAppReglages le garde le temps d'une requête). */
function relire(): array { return primesAppReglages(true); }

// --- 1. la liste des primes ------------------------------------------------------------------
echo "La liste des primes\n";
$GLOBALS['REGLAGES']['venteConcoursTQ'] = ['actif' => true, 'liste' => [
    ['cle' => 'tartes', 'lib' => 'Queen of Tartes', 'motif' => 'tarte', 'magasin' => 60, 'reseau' => 100, 'minPieces' => 12],
    ['cle' => 'quiches', 'lib' => 'Queen of Quiches', 'motif' => 'quiche', 'magasin' => 50, 'reseau' => 100, 'minPieces' => 10]]];
$GLOBALS['REGLAGES']['ventePrimeMagasin'] = ['paliers' => [], 'heuresMin' => 24];
$d = primesAppDefauts();
verifie('le réglage d’avant repris : 3 targets + 2 catégories', array_column($d['liste'], 'cle') === ['croisees', 'objectif', 'google', 'tartes', 'quiches'], json_encode(array_column($d['liste'], 'cle')));
verifie('la catégorie garde le montant de sa couronne du magasin', $d['liste'][3]['montant'] === 60 && $d['liste'][3]['minPieces'] === 12);
verifie('la note Google : 25 € à 4,5, le minimum d’heures de l’objectif', $d['liste'][2]['montant'] === 25 && $d['liste'][2]['seuil'] === 4.5 && $d['liste'][2]['heuresMin'] === 24 && $d['liste'][2]['actif']);
verifie('l’objectif garde le minimum d’heures', $d['liste'][1]['heuresMin'] === 24 && $d['liste'][1]['pct'] === 100);
verifie('les targets valent la récompense par défaut', $d['liste'][0]['montant'] === 50 && $d['liste'][1]['montant'] === 50);
$l = primesAppListePropre([['type' => 'categorie', 'lib' => 'Queen of Tartes', 'motif' => 'tarte'], ['type' => 'categorie', 'lib' => 'Queen of Tartes', 'motif' => 'tarte']], 40);
verifie('les targets manquants reviennent en tête, sans doublon', array_column($l, 'cle') === ['croisees', 'objectif', 'google', 'queenoftartes'], json_encode(array_column($l, 'cle')));
verifie('une prime sans montant prend la récompense par défaut', $l[0]['montant'] === 40 && $l[3]['montant'] === 40);
verifie('la note Google revenue garde ses 25 €, pas la récompense par défaut', $l[2]['montant'] === 25);
$l = primesAppListePropre([['type' => 'categorie', 'lib' => 'Pain au chocolat', 'motif' => 'chocolat']], 50);
verifie('une catégorie ne prend pas la forme d’une clé de promo', $l[3]['cle'] === 'kpainauchocolat', $l[3]['cle']);
$l = primesAppListePropre([['type' => 'categorie', 'lib' => 'Google', 'motif' => 'google']], 50);
verifie('une catégorie ne prend pas la clé de la note Google', $l[3]['cle'] === 'kgoogle', $l[3]['cle']);
$eG = primesAppEntreeValide(['type' => 'target', 'cle' => 'google', 'seuil' => 9, 'heuresMin' => 10, 'montant' => 30, 'payeur' => 'magasin'], 0, 50);
verifie('la note Google : un seuil borné à 5, son montant, son payeur', $eG['seuil'] === 5.0 && $eG['montant'] === 30 && $eG['heuresMin'] === 10 && $eG['payeur'] === 'magasin');
$GLOBALS['REGLAGES']['ventePrimeGoogle'] = ['actif' => false];
verifie('l’ancien coefficient éteint : la note Google éteinte', primesAppDefauts()['liste'][2]['actif'] === false);
unset($GLOBALS['REGLAGES']['ventePrimeGoogle']);
verifie('un motif invalide refusé', primesAppEntreeValide(['type' => 'categorie', 'lib' => 'X', 'motif' => '(('], 0, 50) === null);
verifie('un target inconnu refusé', primesAppEntreeValide(['type' => 'target', 'cle' => 'autre'], 0, 50) === null);

// --- 2. la création d'une promo : depuis la liste du panel, jamais depuis le navigateur ---------------
echo "Une promo créée depuis la liste du panel\n";
$GLOBALS['REGLAGES']['primesPromos'] = ['quand' => time(), 'api' => false, 'erreurs' => [], 'liste' => [
    ['source' => 'catalogue', 'sorte' => 'bundle', 'id' => '900', 'nom' => 'Café + croissant', 'prix' => null, 'du' => null, 'au' => null, 'shops' => [], 'ids' => [900], 'composants' => []]]];
$GLOBALS['CORPS'] = ['recompense' => 50, 'liste' => [
    ['type' => 'target', 'cle' => 'croisees', 'montant' => 50],
    ['type' => 'target', 'cle' => 'objectif', 'montant' => 50, 'pct' => 100, 'heuresMin' => 20],
    ['type' => 'categorie', 'cle' => 'tartes', 'lib' => 'Queen of Tartes', 'motif' => 'tarte', 'minPieces' => 10, 'montant' => 50],
    ['type' => 'promo', 'ref' => ['source' => 'catalogue', 'id' => '900'], 'montant' => 50, 'promo' => ['ids' => [1, 2, 3]]]]];
$r = wr_primes_app();
$c = relire();
$promo = $c['liste'][4] ?? [];
verifie('la promo enregistrée', ($r['ok'] ?? false) && ($promo['type'] ?? '') === 'promo' && $promo['cle'] === 'pc900', json_encode($promo));
verifie('ses produits viennent du panel, pas du navigateur', ($promo['promo']['ids'] ?? null) === [900]);
verifie('elle prend le nom de la promotion', $promo['lib'] === 'Café + croissant');
verifie('le réglage passe au journal', str_contains(end($GLOBALS['JOURNAL']), 'Café + croissant 50 €'));
$GLOBALS['CORPS'] = ['liste' => [['type' => 'promo', 'ref' => ['source' => 'catalogue', 'id' => '999']]]];
$r = @wr_primes_app();   // 422 (le code HTTP ne se pose pas en ligne de commande)
verifie('une promotion inconnue refusée', str_contains((string) ($r['error'] ?? ''), 'introuvable'), json_encode($r));
$GLOBALS['CORPS'] = ['liste' => array_merge($c['liste'], [])];
$GLOBALS['CORPS']['liste'][4]['promo'] = ['source' => 'catalogue', 'id' => '900', 'ids' => [7, 8]];
wr_primes_app();
verifie('une promo déjà là garde ses produits', (relire()['liste'][4]['promo']['ids'] ?? null) === [900]);

// --- 3. une récompense par prime ---------------------------------------------------------------
echo "Une récompense par prime\n";
$cfg = ['montant' => 50, 'minTickets' => 30];
verifie('ventes croisées à la cible : la récompense', croiseesPrime(55.0, 55.0, $cfg, 40)['montant'] === 50);
verifie('sous la cible : rien, la cible à viser', croiseesPrime(54.9, 55.0, $cfg, 40)['montant'] === 0 && croiseesPrime(54.9, 55.0, $cfg, 40)['prochain']['taux'] === 55.0);
verifie('trop peu de tickets : rien', croiseesPrime(70.0, 55.0, $cfg, 29)['montant'] === 0);
verifie('prime éteinte : rien, rien à viser', croiseesPrime(70.0, 55.0, ['montant' => 0, 'minTickets' => 30], 40) === ['montant' => 0, 'palier' => null, 'prochain' => null]);
$m = date('Y-m', strtotime('first day of last month'));
$GLOBALS['REGLAGES']['pvCa4:' . $m] = ['quand' => time(), 'ca' => 61000.0, 'tickets' => 4600];
$reg = ['paliers' => [['pct' => 100, 'montant' => 50, 'lib' => 'Objectif']], 'heuresMin' => 20];
$pm = primesMagasinMois(4, $m, $reg, 128.0, 604.0, ['montant' => 60000.0, 'source' => 'budget'], 5);
verifie('objectif atteint : la récompense, pas des euros par heure', $pm['vous'] === 50.0 && $pm['equipe'] === 250.0, json_encode([$pm['vous'], $pm['equipe']]));
$pm = primesMagasinMois(4, $m, $reg, 12.0, 604.0, ['montant' => 60000.0, 'source' => 'budget'], 5);
verifie('sous le minimum d’heures : rien', $pm['vous'] === 0.0 && $pm['sousMin']);
$pm = primesMagasinMois(4, $m, $reg, 128.0, 604.0, ['montant' => 65000.0, 'source' => 'budget'], 5);
verifie('objectif manqué : rien', $pm['vous'] === 0.0 && $pm['palier'] === null);

// --- 4. les pièces d'une promo : ses dates, ses magasins -------------------------------------------
echo "Les pièces d'une promo\n";
$GLOBALS['TABLES']['shops'] = [['id' => 4, 'name' => 'Atelier by - Halle', 'active' => 1], ['id' => 5, 'name' => 'Atelier by - Namur', 'active' => 1]];
$GLOBALS['TABLES']['employes'] = [
    ['id' => 1, 'id_shop' => 4, 'display_name' => 'Léa', 'name' => '', 'surname' => ''],
    ['id' => 2, 'id_shop' => 4, 'display_name' => 'Toi', 'name' => '', 'surname' => ''],
    ['id' => 3, 'id_shop' => 5, 'display_name' => 'Inès', 'name' => '', 'surname' => '']];
$mc = date('Y-m', strtotime('first day of last month'));
$jour = static fn (int $d) => $mc . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
// La promo du 5 au 10, à Halle seulement.
$GLOBALS['REGLAGES']['ventePrimesApp']['liste'][4]['promo']['du'] = $jour(5);
$GLOBALS['REGLAGES']['ventePrimesApp']['liste'][4]['promo']['au'] = $jour(10);
$GLOBALS['REGLAGES']['ventePrimesApp']['liste'][4]['promo']['shops'] = ['4'];
relire();
$per = ['du' => $jour(5), 'au' => $jour(10), 'shops' => ['4' => true]];
verifie('dans la promo : le 7 à Halle', promoCouvre($per, $jour(7), '4'));
verifie('hors dates : le 11', !promoCouvre($per, $jour(11), '4'));
verifie('hors magasins : Namur', !promoCouvre($per, $jour(7), '5'));
verifie('une promo sans dates ni magasins couvre tout', promoCouvre(['du' => null, 'au' => null, 'shops' => null], $jour(1), '5'));

// La moisson du mois : la promo ne compte que du 5 au 10, et qu'à Halle.
$GLOBALS['TABLES']['shops'] = [['id' => 4, 'name' => 'Atelier by - Halle'], ['id' => 5, 'name' => 'Atelier by - Namur']];
foreach ([3, 7, 12] as $d) {
    $GLOBALS['REGLAGES']['pvL4:' . $jour($d)] = ['v' => 3, 'f' => ['tartes', 'pc900'], 'e' => [2 => ['t' => 10, 'l' => 20, 'c' => 5, 'q' => ['tartes' => 1, 'pc900' => 4]]]];
    $GLOBALS['REGLAGES']['pvL5:' . $jour($d)] = ['v' => 3, 'f' => ['tartes', 'pc900'], 'e' => [3 => ['t' => 10, 'l' => 20, 'c' => 5, 'q' => ['tartes' => 2, 'pc900' => 9]]]];
}
$crM = pvCroiseesMois($mc);
verifie('les catégories : le mois entier', $crM['e'][2]['q']['tartes'] === 3.0 && $crM['e'][3]['q']['tartes'] === 6.0, json_encode($crM['e']));
verifie('la promo : ses seules dates (le 7)', $crM['e'][2]['qp']['pc900'] === 4.0, json_encode($crM['e'][2]['qp'] ?? null));
verifie('la promo : ses seuls magasins (rien à Namur)', !isset($crM['e'][3]['qp']['pc900']), json_encode($crM['e'][3]['qp'] ?? null));
$f = promoFamilles();
verifie('la moisson compte la promo par ses produits', isset($f['pc900'][900]));

// Les familles requises : un jour gravé sans « f » est juste pour les catégories, pas pour une promo.
$req = ['tartes' => ['du' => $jour(1), 'au' => null, 'shops' => null, 'promo' => false], 'pc900' => ['du' => $jour(5), 'au' => $jour(10), 'shops' => ['4' => true], 'promo' => true]];
verifie('jour ancien, hors promo : à jour', pvFamillesOk(['e' => []], 4, $jour(2), $req));
verifie('jour ancien, dans la promo : à recompter', !pvFamillesOk(['e' => []], 4, $jour(6), $req));
verifie('jour compté avec la promo : à jour', pvFamillesOk(['e' => [], 'f' => ['tartes', 'pc900']], 4, $jour(6), $req));
verifie('jour compté sans une catégorie ajoutée : à recompter', !pvFamillesOk(['e' => [], 'f' => ['pc900']], 4, $jour(6), $req));
verifie('autre magasin : la promo ne compte pas', pvFamillesOk(['e' => [], 'f' => ['tartes']], 5, $jour(6), $req));

// --- 5. le classement au nombre de pièces, la 1re du magasin ----------------------------------------
echo "Le classement, la 1re du magasin\n";
$emps = venteEmployes();
$cr = ['e' => [
    1 => ['t' => 300, 'q' => ['tartes' => 51.0], 'qp' => ['pc900' => 20.0]],
    2 => ['t' => 260, 'q' => ['tartes' => 42.0], 'qp' => ['pc900' => 31.0]],
    3 => ['t' => 280, 'q' => ['tartes' => 68.0], 'qp' => ['pc900' => 90.0]]]];
$cfgK = ['actif' => true, 'liste' => [['cle' => 'tartes', 'lib' => 'Queen of Tartes', 'magasin' => 50, 'reseau' => 0, 'minPieces' => 10]]];
$cls = concoursClassement($cr, $emps, $cfgK);
verifie('Léa 1re de Halle, Inès 1re du réseau', $cls['tartes'][1]['rangMag'] === 1 && $cls['tartes'][3]['rangRes'] === 1 && $cls['tartes'][2]['rangMag'] === 2);
verifie('la 1re du magasin gagne la récompense', concoursPrime($cls, 1, $cfgK) === 50 && concoursPrime($cls, 2, $cfgK) === 0);
verifie('plus de couronne du réseau payée', concoursTitres($cls, 3, $cfgK) === [['cle' => 'tartes', 'lib' => 'Queen of Tartes', 'niveau' => 'magasin', 'montant' => 50]]);
$promos = [['cle' => 'pc900', 'lib' => 'Café + croissant', 'magasin' => 50, 'reseau' => 0, 'minPieces' => 1, 'shops' => ['4']]];
$clsP = promosClassement($cr, $emps, $promos);
verifie('promo : Namur hors classement', !isset($clsP['pc900'][3]));
verifie('promo : Toi 1re de Halle avec 31', $clsP['pc900'][2]['rangMag'] === 1 && $clsP['pc900'][2]['surRes'] === 2);
$cfgK['liste'][0]['minPieces'] = 60;
$cls = concoursClassement($cr, $emps, $cfgK);
verifie('sous le minimum de pièces : pas classée', $cls['tartes'][1]['rangMag'] === null && concoursPrime($cls, 1, $cfgK) === 0);

// --- 6. les promotions du panel, mises à plat ------------------------------------------------------
echo "Les promotions du panel\n";
$cat = primesPromosCatalogue();
verifie('le repli : les bundles du catalogue', array_column($cat, 'id') === ['901', '900'] && $cat[1]['ids'] === [900], json_encode(array_column($cat, 'id')));
$p = primesPromoNormaliser(['id' => 12, 'name' => 'Café + croissant', 'price' => '3.50', 'start_date' => date('Y-m-01'), 'end_date' => date('Y-m-t'), 'shops' => [['id' => 4], ['id' => 5]], 'items' => [['product_id' => 1], ['product_id' => 2]]], 'bundle', $cat);
verifie('un bundle sans produit à lui : le produit du catalogue à son nom', $p !== null && $p['ids'] === [900] && $p['composants'] === [1, 2], json_encode($p));
verifie('ses dates, son prix, ses magasins', $p['du'] === date('Y-m-01') && $p['prix'] === 3.5 && $p['shops'] === ['4', '5']);
$p = primesPromoNormaliser(['id' => 13, 'title' => 'Menu midi', 'product_id' => 950, 'is_active' => 1], 'bundle', $cat);
verifie('un bundle avec son produit : ce produit', $p['ids'] === [950]);
$p = primesPromoNormaliser(['id' => 5, 'name' => '5 macarons + 1 offert', 'buy_products' => [['id_product' => 30], ['id_product' => 31]], 'get_products' => [31]], 'xy', $cat);
verifie('une offre X + Y : ses produits', $p['ids'] === [30, 31], json_encode($p['ids'] ?? null));
verifie('une promotion éteinte écartée', primesPromoNormaliser(['id' => 6, 'name' => 'x', 'active' => false], 'bundle', $cat) === null);
verifie('une promotion finie depuis longtemps écartée', primesPromoNormaliser(['id' => 7, 'name' => 'x', 'end_date' => '2020-01-01'], 'bundle', $cat) === null);
PanelApi::$configure = true;
PanelApi::$reponses = ['/admin/promotions/bundles?active=1' => ['code' => 200, 'corps' => ['data' => [['id' => 12, 'name' => 'Café + croissant', 'items' => []]]], 'erreur' => null]];
$pp = primesPromosPanel(true);
verifie('l’API : le bundle, puis les bundles du catalogue qu’elle n’a pas donnés', array_map(static fn ($x) => $x['source'] . ':' . $x['id'], $pp['liste']) === ['api:12', 'catalogue:901'], json_encode(array_map(static fn ($x) => $x['source'] . ':' . $x['id'], $pp['liste'])));
verifie('le 403 des offres X + Y est dit', $pp['api'] && $pp['erreurs'][0]['code'] === 403 && str_contains($pp['erreurs'][0]['chemin'], 'buy-x-get-y'));
PanelApi::$configure = false;

// --- 7. les cartes de l'app ------------------------------------------------------------------
echo "Les cartes de l'app\n";
$b = ['croisees' => ['taux' => 56.0, 'cible' => 55.0, 'montant' => 50, 'tickets' => 262, 'minTickets' => 30],
    'record' => ['lt' => 2.74, 'record' => 2.8, 'montant' => 0, 'eurDixieme' => 100, 'maxDixiemes' => 3],
    'meilleure' => ['score' => 59, 'rangMag' => 2, 'surMag' => 6, 'scorePremier' => 61, 'premierNom' => 'Léa', 'secondNom' => 'Toi', 'scoreSecond' => 59, 'montant' => 0, 'montantMag' => 75],
    'concours' => ['liste' => [['cle' => 'tartes', 'lib' => 'Queen of Tartes', 'pieces' => 42.0, 'rangMag' => 2, 'surMag' => 6, 'minPieces' => 10, 'montantMag' => 50, 'montant' => 0]]],
    'promos' => ['liste' => [['cle' => 'pc900', 'lib' => 'Café + croissant', 'pieces' => 31.0, 'rangMag' => 1, 'surMag' => 6, 'minPieces' => 1, 'montantMag' => 50, 'montant' => 50, 'du' => null, 'au' => null]]]];
$pmC = ['clos' => false, 'atteinte' => 40.0, 'atteinteProj' => 98.0, 'vous' => 0.0, 'sousMin' => false, 'objectif' => 61000.0];
$clsC = [['cle' => 'tartes', 'magasin' => [['nom' => 'Léa', 'pieces' => 51.0, 'moi' => false], ['nom' => 'Toi', 'pieces' => 42.0, 'moi' => true]]],
    ['cle' => 'pc900', 'magasin' => [['nom' => 'Toi', 'pieces' => 31.0, 'moi' => true], ['nom' => 'Manon', 'pieces' => 30.0, 'moi' => false]]]];
$cartes = primesCartes($b, $pmC, $clsC, true);
$par = array_column($cartes, null, 'cle');
verifie('l’ordre : croisées, record, objectif, meilleure, catégorie, promo', array_column($cartes, 'cle') === ['croisees', 'record', 'objectif', 'meilleure', 'tartes', 'pc900'], json_encode(array_column($cartes, 'cle')));
verifie('croisées gagnées', $par['croisees']['etat'] === 'gagne' && $par['croisees']['gain'] === 50.0);
verifie('record : la cible est le record + 0,2 (le 1er dixième pose le record)', $par['record']['cible'] === 3.0 && $par['record']['etat'] === 'att' && abs($par['record']['ecart'] - 0.26) < 1e-9);
verifie('record : jusqu’à 3 dixièmes', $par['record']['montant'] === 300 && $par['record']['max']);
verifie('objectif : projeté, manque 2 pts', $par['objectif']['etat'] === 'att' && $par['objectif']['ecart'] === 2.0 && $par['objectif']['projete']);
verifie('catégorie : 2e, la 1re nommée avec sa quantité', $par['tartes']['etat'] === 'rang' && $par['tartes']['premier']['nom'] === 'Léa' && $par['tartes']['premier']['q'] === 51.0);
verifie('promo : en tête, la 2e nommée', $par['pc900']['etat'] === 'tete' && $par['pc900']['second']['nom'] === 'Manon');
verifie('mois clos : en tête devient gagné', array_column(primesCartes($b, $pmC, $clsC, false), null, 'cle')['pc900']['etat'] === 'gagne');
// La demi-jauge (16/10/2026) : où elle en est aujourd'hui, et l'objectif à atteindre.
verifie('croisées : 56 % sur 0 → 70 %, objectif la cible 55 %', $par['croisees']['jauge'] === 80.0 && $par['croisees']['repere'] === 78.6 && $par['croisees']['repereType'] === 'cible' && $par['croisees']['repereVal'] === 55.0);
$jJ = (int) date('j'); $cJ = round(100 * $jJ / (int) date('t'), 1);
verifie('objectif en cours : l’atteinte à date, l’objectif 100 %, et où en être au jour J', $par['objectif']['repereType'] === 'cible' && $par['objectif']['repereVal'] === 100.0
    && $par['objectif']['cibleJour'] === $cJ && $par['objectif']['repereJour'] === round(100 * $cJ / 110, 1)
    && $par['objectif']['aDate'] === 40.0 && $par['objectif']['jauge'] === round(100 * 40 / 110, 1) && $par['objectif']['avance'] === round(40.0 - $cJ, 1), json_encode([$par['objectif']['repereJour'], $cJ]));
verifie('objectif d’un mois clos : pas de repère du jour', array_column(primesCartes($b, ['clos' => true] + $pmC, $clsC, false), null, 'cle')['objectif']['repereJour'] === null);
verifie('catégorie, 2e : l’objectif est de passer la 1re (51 + 1)', $par['tartes']['repereType'] === 'passer' && $par['tartes']['repereVal'] === 52.0);
verifie('promo, 1re : l’objectif est de garder la place devant la 2e (30)', $par['pc900']['repereType'] === 'garder' && $par['pc900']['repereVal'] === 30.0);
verifie('meilleure vendeuse : passer la 1re du magasin (61 + 1)', $par['meilleure']['repereType'] === 'passer' && $par['meilleure']['repereVal'] === 62.0);
// La barre des targets, le « drapeau » (16/10/2026) : l'objectif toujours à 75 %.
verifie('croisées 56 % pour 55 % : la barre passe le trait (76,4), la prime est activée', $par['croisees']['barre'] === 76.4 && $par['croisees']['actif'] === true, json_encode([$par['croisees']['barre'], $par['croisees']['actif']]));
verifie('record 2,74 pour 3,00 : 68,5, pas encore activée', $par['record']['barre'] === 68.5 && $par['record']['actif'] === false, json_encode([$par['record']['barre'], $par['record']['actif']]));
verifie('objectif du magasin à 40 % au jour J : 30 sur la barre, le repère du jour au prorata, pas activé', $par['objectif']['barre'] === 30.0 && $par['objectif']['barreJour'] === round(75 * $cJ / 100, 1) && $par['objectif']['actif'] === false);
verifie('meilleure vendeuse 2e : la barre sous le trait de la 1re + 1', $par['meilleure']['barre'] !== null && $par['meilleure']['barre'] < 75 && $par['meilleure']['actif'] === false);
verifie('la barre ne dépasse jamais 100', primesCarteJauge(['cle' => 'croisees', 'type' => 'target', 'etat' => 'gagne', 'val' => 99.0, 'cible' => 40.0])['barre'] === 100.0);
verifie('note Google 4,6 pour 4,5 : l’échelle part de 3,5 (82,5)', primesCarteJauge(['cle' => 'google', 'type' => 'target', 'etat' => 'gagne', 'val' => 4.6, 'cible' => 4.5])['barre'] === 82.5);
// Les catégories : la quantité pour être 1re, pas une jauge (16/10/2026).
verifie('catégorie, 2e à 42 pièces derrière 51 : 10 pièces pour être 1re', $par['tartes']['pour1'] === 10 && $par['tartes']['avance1'] === null);
verifie('la promo garde sa jauge (pas de quantité pour être 1re)', $par['pc900']['pour1'] === null && $par['pc900']['jauge'] !== null);
$cat = static fn (array $c) => primesCarteJauge($c + ['cle' => 'q', 'type' => 'categorie', 'val' => 27.0, 'minPieces' => 10, 'premier' => null, 'second' => null]);
verifie('catégorie, 1re à 27 devant 24 : 3 pièces d’avance', $cat(['premier' => ['nom' => 'Toi', 'q' => 27.0, 'moi' => true], 'second' => ['nom' => 'Sarah', 'q' => 24.0, 'moi' => false]])['avance1'] === 3);
verifie('catégorie, personne classée : le minimum fait la 1re (10 − 4 = 6)', $cat(['val' => 4.0])['pour1'] === 6);
verifie('catégorie, en tête mais sous le minimum : ce qui manque au minimum', $cat(['val' => 7.0, 'premier' => ['nom' => 'Toi', 'q' => 7.0, 'moi' => true]])['pour1'] === 3);
verifie('catégorie, la 1re sous le minimum : le minimum compte (10 − 2 = 8)', $cat(['val' => 2.0, 'premier' => ['nom' => 'Léa', 'q' => 5.0, 'moi' => false]])['pour1'] === 8);
verifie('catégorie pas encore comptée : ni quantité ni avance', $cat(['val' => null])['pour1'] === null && $cat(['val' => null])['avance1'] === null);
// La barre des catégories (16/10/2026) : le trait, ce qu'il faut pour être 1re ; en tête, la 2e à garder derrière.
verifie('catégorie 2e à 42, la 1re à 51 : le trait à 52, la barre à 60,6, pas activée', $par['tartes']['barre'] === 60.6 && $par['tartes']['actif'] === false, json_encode([$par['tartes']['barre'], $par['tartes']['actif']]));
$catB = primesCarteJauge(['cle' => 'q', 'type' => 'categorie', 'etat' => 'tete', 'val' => 27.0, 'minPieces' => 10, 'premier' => ['nom' => 'Toi', 'q' => 27.0, 'moi' => true], 'second' => ['nom' => 'Sarah', 'q' => 24.0, 'moi' => false]]);
verifie('catégorie 1re à 27 devant 24 : la barre passe le trait (84,4), activée', $catB['barre'] === 84.4 && $catB['actif'] === true, json_encode([$catB['barre'], $catB['actif']]));
$catS = primesCarteJauge(['cle' => 'q', 'type' => 'categorie', 'etat' => 'tete', 'val' => 12.0, 'minPieces' => 10, 'premier' => ['nom' => 'Toi', 'q' => 12.0, 'moi' => true], 'second' => null]);
verifie('catégorie seule en lice au-dessus du minimum : au-delà du trait', $catS['barre'] === 90.0 && $catS['actif'] === true, json_encode($catS['barre']));
// Le podium des catégories (16/10/2026).
verifie('podium de la catégorie : Léa 51 puis elle 42', array_map(static fn ($x) => [$x['rang'], $x['nom'], $x['q'], $x['moi']], $par['tartes']['podium']) === [[1, 'Léa', 51.0, false], [2, 'Toi', 42.0, true]], json_encode($par['tartes']['podium']));
verifie('pas de podium pour une promo', $par['pc900']['podium'] === []);
$cl5 = ['magasin' => [['nom' => 'A', 'pieces' => 9.0, 'rang' => 1], ['nom' => 'B', 'pieces' => 8.0, 'rang' => 2], ['nom' => 'C', 'pieces' => 7.0, 'rang' => 3], ['nom' => 'D', 'pieces' => 6.0, 'rang' => 4], ['nom' => 'Moi', 'pieces' => 5.0, 'rang' => 5, 'moi' => true]]];
verifie('5e du magasin : la 1re, la 2e, puis elle à son rang', array_column(primesPodium($cl5), 'rang') === [1, 2, 5] && primesPodium($cl5)[2]['moi']);
verifie('3e du magasin : les trois premières telles quelles', array_column(primesPodium(['magasin' => array_slice($cl5['magasin'], 0, 3)]), 'nom') === ['A', 'B', 'C']);
verifie('personne dans le classement : podium vide', primesPodium(null) === []);

// --- 7 bis. la note Google : 25 € par personne à 4,5 ou mieux (14/10/2026) ----------------------
echo "La note Google\n";
relire();
$g4 = googleDuMagasin('4'); $g5 = googleDuMagasin('5');
verifie('Halle à 4,6 : le seuil tenu, 25 €', $g4['atteint'] && $g4['note'] === 4.6 && $g4['montant'] === 25 && $g4['seuil'] === 4.5, json_encode($g4));
verifie('Namur à 4,3 : sous le seuil', !$g5['atteint']);
verifie('Halle : 25 € à chacune au-dessus du minimum d’heures, rien en dessous', googlePrime($g4, 30.0) === 25.0 && googlePrime($g4, 10.0) === 0.0);
verifie('Namur : rien', googlePrime($g5, 30.0) === 0.0);
verifie('un magasin sans fiche Google : pas de note, rien', googleDuMagasin('9')['note'] === null && googlePrime(googleDuMagasin('9'), 30.0) === 0.0);
$b2 = $b; $b2['google'] = googleBrique('4', 30.0);
$cartesG = primesCartes($b2, $pmC, $clsC, true);
$parG = array_column($cartesG, null, 'cle');
verifie('la carte Google vient après l’objectif', array_column($cartesG, 'cle') === ['croisees', 'record', 'objectif', 'google', 'meilleure', 'tartes', 'pc900'], json_encode(array_column($cartesG, 'cle')));
verifie('Halle : gagnée, +25 €, la note face au seuil', $parG['google']['etat'] === 'gagne' && $parG['google']['gain'] === 25.0 && $parG['google']['val'] === 4.6 && $parG['google']['cible'] === 4.5 && $parG['google']['unite'] === 'note');
$b2['google'] = googleBrique('5', 30.0);
$cG = array_column(primesCartes($b2, $pmC, $clsC, true), null, 'cle')['google'];
verifie('Namur : en gris, 0,2 point à prendre', $cG['etat'] === 'att' && $cG['ecart'] === 0.2 && $cG['gain'] === 0.0 && $cG['montant'] === 25);
$b2['google'] = googleBrique('4', 10.0);
$cG = array_column(primesCartes($b2, $pmC, $clsC, true), null, 'cle')['google'];
verifie('sous le minimum d’heures : rien, le motif le dit', $cG['etat'] === 'vide' && $cG['motif'] === 'heures' && $cG['gain'] === 0.0);
$b2['google'] = googleBrique('4', 30.0);
$b2['concours']['montant'] = 0; $b2['promos']['montant'] = 50;
verifie('les montants du mois comptent la note Google, sans coefficient', primesMontants($b2, $pmC + ['vous' => 0.0])['google'] === 25.0 && primesMontants($b2, $pmC)['croisees'] === 50.0);
verifie('le titre « Note Google » entre dans la collection', in_array('google', array_column(primesTitres($b2, $pmC), 'cle'), true));
verifie('le SMS nomme la note Google', in_array('Note Google', primesGagnees(['google' => 25.0]), true));
verifie('le payeur de la note Google : la marque par défaut', primesPayeurDe('google') === 'marque');

// --- 7 ter. les icônes des primes (14/10/2026) ------------------------------------------------
echo "Les icônes\n";
$ic = 'uploads/primes/0123456789abcdef0123.svg';
verifie('un chemin d’icône gardé', primesAppIcone($ic) === $ic);
verifie('un chemin hors du dossier, un script, une adresse : refusés', primesAppIcone('../config/config.php') === '' && primesAppIcone('uploads/primes/0123456789abcdef0123.php') === '' && primesAppIcone('https://ailleurs.be/x.png') === '');
verifie('une prime garde son icône', primesAppEntreeValide(['type' => 'target', 'cle' => 'croisees', 'icone' => $ic], 0, 50)['icone'] === $ic);
verifie('le record et la meilleure ont la leur', primesAppExternes(['record' => ['icone' => $ic]])['record']['icone'] === $ic && primesAppExternes([])['meilleure']['icone'] === '');
$GLOBALS['CORPS'] = ['liste' => array_map(static fn ($e) => $e['cle'] === 'croisees' ? ['icone' => $ic] + $e : $e, relire()['liste']), 'externes' => ['record' => ['icone' => $ic]]];
wr_primes_app();
relire();
$parI = array_column(primesCartes($b, $pmC, $clsC, true), null, 'cle');
verifie('les cartes portent l’icône (et l’emoji en repli)', $parI['croisees']['icone'] === $ic && $parI['record']['icone'] === $ic && $parI['objectif']['icone'] === '' && $parI['objectif']['emo'] === '🏪');
$dos = sys_get_temp_dir() . '/icones-' . bin2hex(random_bytes(4));
$im = imagecreatetruecolor(32, 32); ob_start(); imagepng($im); $png = (string) ob_get_clean();
$r = primesIconeEcrire('data:image/png;base64,' . base64_encode($png), $dos);
verifie('un PNG écrit sous uploads/primes, au nom tiré au hasard', preg_match('#^uploads/primes/[a-f0-9]{20}\.png$#', $r['icone'] ?? '') === 1 && is_file($dos . '/' . basename((string) ($r['icone'] ?? 'x'))), json_encode($r));
$im = imagecreatetruecolor(8, 8); ob_start(); imagepng($im); $png = (string) ob_get_clean();
verifie('une icône de 8 px refusée', (primesIconeEcrire('data:image/png;base64,' . base64_encode($png), $dos)['code'] ?? 0) === 422);
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="g"><stop offset="0" stop-color="#8D1D2C"/></linearGradient></defs><path fill="url(#g)" d="M12 2l3 7h7l-6 4 2 7-6-4-6 4 2-7-6-4h7z"/><use href="#g"/></svg>';
verifie('un SVG propre accepté', str_ends_with((string) (primesIconeEcrire('data:image/svg+xml;base64,' . base64_encode($svg), $dos)['icone'] ?? ''), '.svg'));
verifie('un SVG avec un script refusé', (primesIconeEcrire('data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), $dos)['code'] ?? 0) === 415);
verifie('un SVG avec un gestionnaire d’événement refusé', !primesSvgSain('<svg onload="alert(1)"></svg>') && !primesSvgSain('<svg><a href="javascript:alert(1)"><rect/></a></svg>'));
verifie('un SVG qui appelle l’extérieur refusé', !primesSvgSain('<svg><image href="https://ailleurs.be/x.png"/></svg>') && !primesSvgSain('<svg><rect style="fill:url(https://ailleurs.be/y)"/></svg>'));
verifie('un SVG qui anime un gestionnaire ou un lien refusé', !primesSvgSain('<svg><set attributeName="onmouseover" to="alert(1)"/></svg>') && !primesSvgSain('<svg><a><animate attributeName="href" values="https://ailleurs.be"/></a></svg>'));
verifie('un SVG avec une entité refusé', !primesSvgSain('<!DOCTYPE svg [<!ENTITY x "y">]><svg>&x;</svg>'));
verifie('du texte qui n’est pas une image refusé', (primesIconeEcrire('data:text/plain;base64,' . base64_encode(str_repeat('pas une image ', 5)), $dos)['code'] ?? 0) === 415);
verifie('plus d’un Mo refusé', (primesIconeEcrire('data:image/png;base64,' . base64_encode(str_repeat('x', PRIMES_ICONE_MAX + 1)), $dos)['code'] ?? 0) === 413);
array_map('unlink', glob($dos . '/*') ?: []); @rmdir($dos);

// --- 7 quater. les semaines du réseau portent les lignes (16/10/2026, lignes par ticket de Statistiques) -----
echo "Les semaines\n";
$lundiP = date('Y-m-d', strtotime('monday this week -7 days'));
$GLOBALS['REGLAGES']['pvL4:' . $lundiP] = ['v' => 3, 'f' => [], 'e' => [2 => ['t' => 10, 'l' => 27, 'c' => 5], 1 => ['t' => 4, 'l' => 9, 'c' => 1]]];
$GLOBALS['REGLAGES']['pvL4:' . date('Y-m-d', strtotime($lundiP . ' +1 day'))] = ['v' => 3, 'f' => [], 'e' => [2 => ['t' => 6, 'l' => 13, 'c' => 2]]];
$semR = array_column(pvSemainesReseau(2), null, 'du');
verifie('une semaine cumule les tickets et les lignes de chacune', ($semR[$lundiP]['e'][2]['t'] ?? null) === 16 && ($semR[$lundiP]['e'][2]['l'] ?? null) === 40, json_encode($semR[$lundiP]['e'][2] ?? null));
// Les moyennes du magasin et du réseau, semaine par semaine : la vendeuse moyenne, jamais une collègue.
$GLOBALS['REGLAGES']['pvE4:' . $lundiP] = ['quand' => time(), 'e' => [2 => ['ca' => 200.0, 't' => 16], 1 => ['ca' => 40.0, 't' => 4], 3 => ['ca' => 60.0, 't' => 5]]];
$planningAvant = $GLOBALS['TABLES']['planning'];
$GLOBALS['TABLES']['planning'] = [['emp' => 2, 'sem' => date('oW', strtotime($lundiP)), 'h' => 8], ['emp' => 1, 'sem' => date('oW', strtotime($lundiP)), 'h' => 4]];
unset($GLOBALS['REGLAGES']['primesS12moy']);
$moyS = primesSemainesMoyennes();
$m4 = $moyS[$lundiP]['mag']['4'] ?? [];
verifie('moyenne du magasin : 2,45 lignes par ticket, 10 tickets par vendeuse', ($m4['lignesTicket'] ?? null) === 2.45 && ($m4['tickets'] ?? null) === 10, json_encode($m4));
verifie('moyenne du magasin : ticket moyen 12 €, 20 € par heure (sans le CA de qui n’a pas d’heures)', ($m4['panier'] ?? null) === 12.0 && ($m4['caHeure'] ?? null) === 20, json_encode($m4));
verifie('moyenne du réseau la même semaine (un seul magasin a vendu)', ($moyS[$lundiP]['res']['panier'] ?? null) === 12.0 && ($moyS[$lundiP]['res']['lignesTicket'] ?? null) === 2.45);
verifie('aucun nom dans les moyennes', !str_contains(json_encode($moyS), 'nom'));
$GLOBALS['TABLES']['planning'] = $planningAvant;

// --- 8. les moyennes par magasin -------------------------------------------------------------
echo "Les moyennes par magasin\n";
$mm = date('Y-m', strtotime(date('Y-m') . '-01 -1 month'));
$GLOBALS['REGLAGES'] = array_filter($GLOBALS['REGLAGES'], static fn ($k) => !str_starts_with((string) $k, 'pvL') && !str_starts_with((string) $k, 'prMoy'), ARRAY_FILTER_USE_KEY);
for ($d = 1; $d <= 2; $d++) {
    $j = $mm . '-0' . $d;
    $GLOBALS['REGLAGES']['pvL4:' . $j] = ['v' => 3, 'f' => ['tartes'], 'e' => [1 => ['t' => 20, 'l' => 50, 'c' => 11, 'q' => ['tartes' => 3]], 2 => ['t' => 20, 'l' => 54, 'c' => 12, 'q' => ['tartes' => 2]]]];
    $GLOBALS['REGLAGES']['pvL5:' . $j] = ['v' => 3, 'f' => ['tartes'], 'e' => [3 => ['t' => 40, 'l' => 100, 'c' => 20, 'q' => ['tartes' => 6]]]];
}
$GLOBALS['REGLAGES']['pvCa4:' . $mm] = ['quand' => time(), 'ca' => 1000.0, 'tickets' => 80];
$GLOBALS['REGLAGES']['pvCa5:' . $mm] = ['quand' => time(), 'ca' => 900.0, 'tickets' => 80];
$GLOBALS['TABLES']['budgets'][$mm] = [['shop_id' => 4, 'revenue_budget' => 1250, 'ca_theorique' => null]];
$GLOBALS['REGLAGES']['venteCroisees'] = ['cibleDefaut' => 50, 'cibles' => ['4' => 55], 'minTickets' => 30];
$_GET['n'] = 1;
$moy = ep_primes_moyennes();
$h = array_column($moy['magasins'], null, 'id');
verifie('Halle : 80 tickets, 2 vendeuses à 30 tickets ou plus, 40 par vendeuse', $h['4']['tickets'] === 80 && $h['4']['vendeuses'] === 2.0 && $h['4']['ticketsVendeuse'] === 40, json_encode($h['4']));
verifie('Halle : ticket moyen 12,50 €, 2,6 lignes, 57,5 % de croisées', $h['4']['ticketMoyen'] === 12.5 && $h['4']['lignesTicket'] === 2.6 && $h['4']['croisees'] === 57.5);
verifie('Halle : objectif atteint à 80 %, 5 tartes par vendeuse', $h['4']['atteinte'] === 80.0 && $h['4']['pieces']['tartes'] === 5.0, json_encode($h['4']));
verifie('Halle : sa cible en face', $h['4']['cible'] === 55.0 && $h['5']['cible'] === null);
verifie('le réseau : 160 tickets, 3 vendeuses, 11,88 € de ticket moyen', $moy['reseau']['tickets'] === 160 && $moy['reseau']['vendeuses'] === 3.0 && $moy['reseau']['ticketMoyen'] === 11.88, json_encode($moy['reseau']));

// --- 9. qui paie : la marque, le magasin, un sponsor --------------------------------------------
echo "Qui paie\n";
verifie('la marque par défaut', primesAppPayeur([]) === ['payeur' => 'marque', 'sponsor' => '']);
verifie('un sponsor nommé', primesAppPayeur(['payeur' => 'sponsor', 'sponsor' => ' Lavazza ']) === ['payeur' => 'sponsor', 'sponsor' => 'Lavazza']);
verifie('un sponsor sans nom reste à la marque', primesAppPayeur(['payeur' => 'sponsor', 'sponsor' => '']) === ['payeur' => 'marque', 'sponsor' => '']);
verifie('un payeur inconnu : la marque', primesAppPayeur(['payeur' => 'banque']) === ['payeur' => 'marque', 'sponsor' => '']);
$GLOBALS['CORPS'] = ['recompense' => 50, 'liste' => [
    ['type' => 'target', 'cle' => 'croisees', 'montant' => 50, 'categorie' => 'Boissons chaudes'],
    ['type' => 'target', 'cle' => 'objectif', 'montant' => 50, 'payeur' => 'magasin'],
    ['type' => 'categorie', 'cle' => 'tartes', 'lib' => 'Queen of Tartes', 'motif' => 'tarte', 'montant' => 50, 'payeur' => 'sponsor', 'sponsor' => 'Moulins Dumont']],
    'externes' => ['record' => ['payeur' => 'magasin'], 'meilleure' => ['payeur' => 'sponsor', 'sponsor' => 'Lavazza']], 'sms' => ['actif' => true]];
wr_primes_app();
relire();
verifie('chaque prime garde son payeur', primesPayeurDe('objectif') === 'magasin' && primesPayeurDe('tartes') === 'sponsor:Moulins Dumont' && primesPayeurDe('croisees') === 'marque');
verifie('le record et la meilleure vendeuse aussi', primesPayeurDe('record') === 'magasin' && primesPayeurDe('meilleure') === 'sponsor:Lavazza');
verifie('les SMS à la réussite sont activés', primesAppReglages()['sms']['actif'] === true);
verifie('le payeur passe au journal', str_contains(end($GLOBALS['JOURNAL']), 'Queen of Tartes 50 € (sponsor Moulins Dumont)') && str_contains(end($GLOBALS['JOURNAL']), 'SMS à la réussite activés'));

// --- 10. les ventes croisées d'une catégorie -----------------------------------------------------
echo "Les ventes croisées d'une catégorie\n";
verifie('la catégorie réglée', croiseesReglages()['categorie'] === 'Boissons chaudes' && pvCroiseesCategorie() === 'Boissons chaudes');
$mk = date('Y-m', strtotime('first day of last month'));
$GLOBALS['TABLES']['shops'] = [['id' => 4, 'name' => 'Atelier by - Halle']];
$GLOBALS['REGLAGES'] = array_filter($GLOBALS['REGLAGES'], static fn ($k) => !str_starts_with((string) $k, 'pvL'), ARRAY_FILTER_USE_KEY);
// Le 1 : compté par catégorie ; le 2 : moissonné avant (sans « k »).
$GLOBALS['REGLAGES']['pvL4:' . $mk . '-01'] = ['v' => 3, 'k' => 1, 'f' => ['tartes'], 'e' => [2 => ['t' => 40, 'l' => 90, 'c' => 20, 'cc' => ['Boissons chaudes' => 8, 'Viennoiseries' => 15]]]];
$GLOBALS['REGLAGES']['pvL4:' . $mk . '-02'] = ['v' => 3, 'f' => [], 'e' => [2 => ['t' => 30, 'l' => 70, 'c' => 15]]];
$crK = pvCroiseesMois($mk);
verifie('la prime compte les tickets avec une boisson chaude', $crK['e'][2]['c'] === 8, json_encode($crK['e'][2]));
verifie('sur les seuls jours comptés par catégorie', $crK['e'][2]['t'] === 40 && !$crK['complet']);
verifie('un jour sans le compte par catégorie se recompte', !pvFamillesOk($GLOBALS['REGLAGES']['pvL4:' . $mk . '-02'], 4, $mk . '-02', pvFamillesRequises()));
verifie('un jour compté par catégorie est à jour', pvFamillesOk($GLOBALS['REGLAGES']['pvL4:' . $mk . '-01'], 4, $mk . '-01', pvFamillesRequises()));
verifie('le menu déroulant : les catégories, sans les bundles', primesCategories() === ['Quiches', 'Tartes'], json_encode(primesCategories()));

// --- 11. le SMS à la réussite --------------------------------------------------------------
echo "Le SMS à la réussite\n";
verifie('un numéro belge national devient international', smsNumero('0470 12 34 56') === '+32470123456');
verifie('0032 devient +32', smsNumero('0032470123456') === '+32470123456' && smsNumero('+32 470 12 34 56') === '+32470123456');
verifie('un numéro trop court refusé', smsNumero('123') === null && smsNumero('') === null);
$pSms = ['id' => 2, 'nom' => 'Léa Dupont', 'total' => 100.0, 'croisees' => ['montant' => 50], 'magasin' => 0, 'concours' => ['titres' => [['lib' => 'Queen of Quiches']]], 'promo' => ['titres' => []], 'record' => ['montant' => 0], 'meilleure' => 0];
$txt = primesSmsTexte($pSms, '2026-10');
verifie('le texte : prénom, mois, total, primes', $txt === 'Bravo Léa ! Octobre : 100 € de primes gagnées (Ventes croisées, Queen of Quiches). L’Atelier', $txt);
verifie('il tient dans un SMS', mb_strlen($txt) <= 160);
$GLOBALS['TABLES']['employes'] = [['id' => 2, 'id_shop' => 4, 'display_name' => 'Léa', 'name' => '', 'surname' => '', 'phone' => '0470123456']];
$GLOBALS['REGLAGES']['pvEmployesTel'] = ['t' => [2 => '0470123456']];
$r = primesSmsReussite('2026-10', [$pSms, ['id' => 3, 'nom' => 'Inès', 'total' => 50.0], ['id' => 4, 'nom' => 'Julie', 'total' => 0.0]]);
verifie('sans fournisseur : rien ne part, c’est dit', $r['actif'] && !$r['configure'] && $r['envoyes'] === 0 && $r['echecs'] === 1 && $r['sansNumero'] === 1, json_encode($r));
verifie('le journal le dit', str_contains(end($GLOBALS['JOURNAL']), 'aucun fournisseur SMS réglé'));
$GLOBALS['CORPS'] = ['sms' => ['actif' => false]];
wr_primes_app(); relire();
verifie('éteints : aucun SMS', primesSmsReussite('2026-10', [$pSms]) === ['actif' => false]);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
