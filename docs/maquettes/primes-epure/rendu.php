<?php
declare(strict_types=1);

/**
 * Le rendu RÉEL de « Mes primes » (primes.twig, 14/10/2026) sur un jeu d'essai : la fiche est
 * calculée par le moteur du cockpit (primesBriques, primesMagasinMois, primesClassements,
 * primesCartes), sans base ni panel — des doublures en mémoire comme bin/tests_primes.php —, puis
 * rendue par la vue Twig de l'app. Prénoms et chiffres inventés (Halle d'après ses chiffres d'octobre).
 * Trois icônes déposées (demo/uploads/primes : la tarte, la quiche, le café) ; les autres primes gardent
 * leur emoji. La note Google de Halle : 4,6, au-dessus du seuil de 4,5.
 *
 *   php docs/maquettes/primes-epure/rendu.php            → rendu-app.html et rendu-stats.html (à servir depuis la racine du dépôt)
 */

$RACINE = dirname(__DIR__, 3);
$GLOBALS['REGLAGES'] = [];
$GLOBALS['TABLES'] = ['shops' => [], 'employes' => [], 'planning' => [], 'budgets' => [], 'reputation' => [['shop_id' => 4, 'rating_avg' => 4.6, 'rating_count' => 312]]];
final class Db
{
    public static function rows(string $sql, array $a = []): array
    {
        $T = $GLOBALS['TABLES'];
        if (str_contains($sql, 'FROM shops')) { return $T['shops']; }
        if (str_contains($sql, 'ceo_shop_reputation') || str_contains($sql, 'mar_avis_fiche')) { return $T['reputation']; }
        if (str_contains($sql, 'franchisee_employee_schedule')) { return $T['planning']; }
        if (str_contains($sql, 'FROM franchisee_employee')) { return $T['employes']; }
        if (str_contains($sql, 'ceo_shop_month_perf')) { return $T['budgets']; }
        return [];
    }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void
    {
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { $GLOBALS['REGLAGES'][$a[0]] = json_decode($a[1], true); }
    }
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['REGLAGES'][$k] ?? $d; }
function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void {}
function body(): array { return []; }
final class PanelApi
{
    public static function configured(): bool { return false; }
    public static function get(string $p): mixed { return null; }
}
require $RACINE . '/src/ventes.php';
require $RACINE . '/src/panel_ventes.php';
require $RACINE . '/src/primes.php';
require $RACINE . '/src/primes_app.php';

// --- le jeu d'essai : Halle et le réseau, octobre au 14 --------------------------------------
$m = date('Y-m');
$magasins = [4 => 'Halle', 5 => 'Namur', 6 => 'Louvain-la-Neuve', 7 => 'Waterloo', 8 => 'Uccle', 9 => 'Sombreffe', 10 => 'Wavre', 11 => 'Ixelles'];
foreach ($magasins as $id => $n) { $GLOBALS['TABLES']['shops'][] = ['id' => $id, 'name' => 'Atelier by - ' . $n, 'active' => 1]; }
$GLOBALS['TABLES']['budgets'] = [['shop_id' => 4, 'revenue_budget' => 61000, 'ca_theorique' => null]];
// [id, prénom, magasin, tickets, lignes, ventes croisées, tartes, quiches, café + croissant]
$gens = [
    [1, 'Léa', 4, 300, 820, 162, 51, 19, 22], [2, 'Toi', 4, 262, 718, 147, 42, 27, 31], [3, 'Manon', 4, 240, 600, 125, 37, 15, 34],
    [4, 'Sarah', 4, 230, 610, 140, 29, 24, 20], [5, 'Julie', 4, 150, 380, 74, 18, 9, 11], [6, 'Camille', 4, 120, 290, 53, 12, 6, 7],
    [7, 'Inès', 5, 330, 860, 198, 68, 36, 0], [8, 'Nora', 6, 310, 800, 198, 63, 26, 0], [9, 'Emma', 7, 290, 790, 160, 57, 22, 0],
    [10, 'Chloé', 8, 280, 760, 185, 49, 21, 0], [11, 'Zoé', 9, 200, 560, 106, 46, 18, 0], [12, 'Lucie', 10, 260, 690, 140, 40, 30, 0],
    [13, 'Yasmine', 11, 250, 640, 122, 35, 17, 0], [14, 'Amélie', 10, 270, 700, 157, 33, 41, 0], [15, 'Laura', 5, 230, 600, 124, 30, 33, 0]];
$cr = ['e' => [], 'jours' => 13, 'complet' => true, 'tqComplet' => true, 'joursTqShop' => [4 => 13]];
foreach ($gens as [$id, $nom, $shop, $t, $l, $c, $ta, $qu, $pr]) {
    $GLOBALS['TABLES']['employes'][] = ['id' => $id, 'id_shop' => $shop, 'display_name' => $nom, 'name' => '', 'surname' => ''];
    $cr['e'][$id] = ['t' => $t, 'l' => $l, 'c' => $c, 'q' => ['tartes' => (float) $ta, 'quiches' => (float) $qu], 'qp' => ['pc900' => (float) $pr], 'tq' => (float) ($ta + $qu)];
}
$GLOBALS['REGLAGES']['venteCroisees'] = ['cibleDefaut' => 50, 'cibles' => ['4' => 55], 'minTickets' => 30];
$GLOBALS['REGLAGES']['ventePrimesApp'] = ['recompense' => 50, 'liste' => [
    ['type' => 'target', 'cle' => 'croisees', 'montant' => 50],
    ['type' => 'target', 'cle' => 'objectif', 'montant' => 50, 'pct' => 100, 'heuresMin' => 20],
    ['type' => 'target', 'cle' => 'google', 'montant' => 25, 'seuil' => 4.5, 'heuresMin' => 20],
    ['type' => 'categorie', 'cle' => 'tartes', 'lib' => 'Queen of Tartes', 'motif' => 'tarte', 'minPieces' => 10, 'montant' => 50, 'icone' => 'uploads/primes/7a1e0c3b9f2d4e5a6b70.svg'],
    ['type' => 'categorie', 'cle' => 'quiches', 'lib' => 'Queen of Quiches', 'motif' => 'quiche', 'minPieces' => 10, 'montant' => 50, 'icone' => 'uploads/primes/3c9d2b81e04f6a7c5d12.svg'],
    ['type' => 'promo', 'montant' => 50, 'icone' => 'uploads/primes/e58b40d2c7a91f36b0c4.svg', 'lib' => 'Promo café + croissant', 'promo' => ['source' => 'catalogue', 'sorte' => 'bundle', 'id' => '900', 'nom' => 'Café + croissant',
        'du' => $m . '-01', 'au' => date('Y-m-t'), 'shops' => [], 'ids' => [900]]]]];
// Le CA de Halle jour par jour (≈ 1 900 € les jours de semaine, 2 700 € le week-end) : la projection de l'objectif.
for ($j = $m . '-01'; $j <= date('Y-m-d'); $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
    $we = (int) date('N', strtotime($j)) >= 6;
    $GLOBALS['REGLAGES']['pvJ4:' . $j] = ['quand' => time(), 'ca' => $we ? 2690.0 : 1880.0];
}

$emps = venteEmployes();
$nomDe = primesMagasins();
$moi = ['id' => 2, 'tickets' => 262, 'lignesTicket' => 2.74, 'score' => 59, 'rang' => 2, 'ca' => 3290, 'heures' => 38.5, 'caHeure' => 85, 'classable' => true];
$lignes = [];
foreach ([[1, 'Léa', 61], [2, 'Toi', 59], [3, 'Manon', 52], [4, 'Sarah', 50]] as [$id, $nom, $sc]) { $lignes[] = ['id' => $id, 'nom' => $nom, 'shopId' => '4', 'score' => $sc, 'classable' => true]; }
$avant = date('Y-m', strtotime($m . '-01 -1 month'));
$parMois = [$avant => [['id' => '2', 'tickets' => 240, 'lignesTicket' => 2.8]]];
$b = primesBriques(2, $m, $moi, $lignes, $cr, $parMois, '4');
$b['google'] = googleBrique('4', 38.5);
$reg = primeMagasinReglages();
$pm = primesMagasinMois(4, $m, $reg, 128.0, 604.0, ['montant' => 61000.0, 'source' => 'budget'], 6);
$classements = primesClassements(2, '4', $cr, $emps, concoursReglages(), croiseesReglages(), $nomDe, promosConfig($m, '4'));
$cartes = primesCartes($b, $pm, $classements, true);
$acquis = round(array_sum(primesMontants($b, $pm)), 2);
$enJeu = 0.0;
foreach ($cartes as $c) { $enJeu += max((float) $c['montant'], (float) $c['gain']); }
$fiche = ['emp' => 2, 'm' => $m, 'enCours' => true, 'magasin' => ['id' => '4', 'nom' => 'Atelier by - Halle'],
    'cartes' => $cartes, 'classements' => $classements, 'acquis' => $acquis, 'enJeu' => max($acquis, $enJeu), 'iconesBase' => 'demo'];

// --- le rendu par la vue de l'app ------------------------------------------------------------
require $RACINE . '/apps/employee/vendor/autoload.php';
define('ROOT', '/public/employee');
$lang = $argv[1] ?? 'fr';
\App\Employee\core\Support\GlobalRegistry::set('lang_code', $lang);
$tr = array_merge(
    json_decode((string) file_get_contents($RACINE . '/apps/employee/src/core/I18n/translations/page/' . $lang . '/components.json'), true) ?: [],
    json_decode((string) file_get_contents($RACINE . '/apps/employee/src/core/I18n/translations/page/' . $lang . '/primes.json'), true) ?: []);
$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($RACINE . '/apps/employee/src/app/Views'), ['cache' => false, 'autoescape' => 'html', 'strict_variables' => false]);
$twig->addExtension(new \App\Employee\core\Twig\AppExtension([]));
$html = $twig->render('primes/primes.twig', ['primes' => $fiche, 'primesErreur' => null, 'jour' => (int) date('j'), 'heure' => date('H:i'),
    'translations' => $tr, 'ROOT' => ROOT, 'errors' => [], 'information' => [], 'warnings' => [], 'successes' => [], 'permissions' => null]);
// Le service worker ne s'inscrit pas sur la page d'essai.
$html = preg_replace('#<script>\s*if \("serviceWorker".*?</script>#s', '', $html) ?? $html;
file_put_contents(__DIR__ . '/rendu-app' . ($lang === 'fr' ? '' : '-' . $lang) . '.html', $html);

// --- « Statistiques » (16/10/2026) : la même fiche, avec ses douze semaines, sa journée et ses titres --------
$sem = [];
foreach ([[47, 210, 6150, 41.5, 78], [49, 190, 5660, 40, 74], [52, 230, 7010, 44, 81], [50, 205, 6020, 38.5, 79], [54, 240, 7400, 45, 83], [51, 214, 6390, 41, 80],
    [55, 251, 7700, 46.5, 84], [53, 222, 6800, 42, 82], [57, 248, 7860, 46, 86], [56, 236, 7210, 44.5, 85], [58, 262, 8090, 47, 87], [56, 131, 3950, 22.5, 88]] as $i => [$tx, $t, $ca, $h, $cah]) {
    $sem[] = ['lib' => 'S' . (int) date('W', strtotime('monday this week -' . (11 - $i) . ' weeks')), 'tickets' => $t, 'croisees' => (int) round($t * $tx / 100), 'taux' => (float) $tx,
        'ca' => (float) $ca, 'heures' => $h, 'caHeure' => $cah, 'rang' => null, 'sur' => 0,
        'panier' => round($ca / $t, 2), 'lignesTicket' => round(2.45 + 0.03 * $i + ($i % 3) * 0.04, 2),
        // La vendeuse moyenne de son magasin et du réseau, la même semaine.
        'mag' => ['panier' => round(28.4 + 0.25 * $i - ($i % 2) * 0.6, 2), 'lignesTicket' => round(2.52 + 0.015 * $i, 2), 'taux' => 46.0 + ($i % 4), 'caHeure' => 72 + $i, 'tickets' => 176 + 3 * $i],
        'res' => ['panier' => round(27.6 + 0.12 * $i, 2), 'lignesTicket' => round(2.47 + 0.01 * $i, 2), 'taux' => 44.0 + ($i % 3), 'caHeure' => 69 + (int) ($i / 2), 'tickets' => 168 + 2 * $i]];
}
// Une semaine de congé : pas de vente à son nom, un trou dans sa ligne, les moyennes continuent.
$sem[4] = array_merge($sem[4], ['tickets' => 0, 'croisees' => 0, 'taux' => null, 'ca' => 0.0, 'heures' => 0, 'caHeure' => null, 'panier' => null, 'lignesTicket' => null]);
$b['meilleure'] = array_merge($b['meilleure'], ['ca' => 3290, 'caHeure' => 85, 'heures' => 38.5, 'tickets' => 262, 'panier' => 12.56]);
$fiche += ['briques' => $b, 'semaines12' => $sem, 'prime' => $pm, 'aujourdhui' => ['tickets' => 19, 'croisees' => 11, 'lignes' => 52, 'taux' => 57.9, 'quand' => '15:40'],
    'collection' => ['titres' => [['cle' => 'concours:quiches', 'lib' => 'Queen of Quiches', 'n' => 2], ['cle' => 'croisees', 'lib' => 'Ventes croisées', 'n' => 3]],
        'enCours' => [['cle' => 'croisees', 'lib' => 'Ventes croisées']]]];
$trS = array_merge(
    json_decode((string) file_get_contents($RACINE . '/apps/employee/src/core/I18n/translations/page/' . $lang . '/components.json'), true) ?: [],
    json_decode((string) file_get_contents($RACINE . '/apps/employee/src/core/I18n/translations/page/' . $lang . '/stats.json'), true) ?: []);
$htmlS = $twig->render('stats/statistiques.twig', ['fiche' => $fiche, 'ficheErreur' => null, 'jour' => (int) date('j'),
    'translations' => $trS, 'ROOT' => ROOT, 'errors' => [], 'information' => [], 'warnings' => [], 'successes' => [], 'permissions' => null]);
$htmlS = preg_replace('#<script>\s*if \("serviceWorker".*?</script>#s', '', $htmlS) ?? $htmlS;
file_put_contents(__DIR__ . '/rendu-stats' . ($lang === 'fr' ? '' : '-' . $lang) . '.html', $htmlS);
echo 'rendu-app' . ($lang === 'fr' ? '' : '-' . $lang) . '.html · ' . count($cartes) . ' cartes · acquis ' . $acquis . ' € · en jeu ' . $fiche['enJeu'] . " €\n";
