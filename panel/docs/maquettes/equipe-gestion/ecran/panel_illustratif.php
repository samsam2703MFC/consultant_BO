<?php
// Les réponses des routes Mon équipe, calculées par le vrai code (panel/src/equipe.php) sur un panel illustratif.
declare(strict_types=1);
$R = dirname(__DIR__, 5);
// Les lettres du roulement : le comptoir et la responsable ; la production garde ses horaires (sans lettre).
final class Db { public static function rows($s, $a = []) {
    // Les tâches posées dans les creux du lundi 23 (la ligne du jour) : Laura et Chloé, au comptoir à ces heures-là.
    if (str_contains($s, 'panel_equipe_taches')) { return array_values(array_filter([['jour' => '2026-11-23', 'code' => '1.2.01', 'de' => 580, 'a' => 595, 'employe_id' => 28], ['jour' => '2026-11-23', 'code' => '1.5.01', 'de' => 855, 'a' => 895, 'employe_id' => 23], ['jour' => '2026-11-23', 'code' => '4.2.01', 'de' => 935, 'a' => 955, 'employe_id' => 28], ['jour' => '2026-11-23', 'code' => '1.2.02', 'de' => 1070, 'a' => 1080, 'employe_id' => 23]], static fn ($x) => $x['jour'] >= ($a[1] ?? '') && $x['jour'] <= ($a[2] ?? ''))); }
    return str_contains($s, 'panel_equipe_roulement') ? [['employe_id' => 23, 'lettre' => 'A'], ['employe_id' => 28, 'lettre' => 'B'], ['employe_id' => 26, 'lettre' => 'C'], ['employe_id' => 21, 'lettre' => 'D']] : []; } public static function row($s, $a = []) { if (str_contains($s, 'panel_equipe_options')) { return ['options' => '{"assistant":true,"taches":true,"matrice":true,"recopie":true}']; } return str_contains($s, 'panel_equipe_matrice') ? null : ['revenue_budget' => 108000, 'ca_theorique' => null]; } public static function exec($s, $a = []) {} }
$REG = [];
function setting(string $k, mixed $d = null): mixed { global $REG; if (preg_match('/^tmT5:(\S+)$/', $k, $m)) { return $m[1] <= '2026-11-23' && date('N', strtotime($m[1])) != 7 ? tickets($m[1]) : null; } return $REG[$k] ?? $d; }
function svGrave(string $k, array $v): void {}
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : (is_array($r['data'] ?? null) ? $r['data'] : []); }
function journalAdd(...$a): void {}
function body(): array { return []; }
function pjPoids(): ?array { return ['poids' => [1 => 0.9, 2 => 1.0, 3 => 1.1, 4 => 1.0, 5 => 1.2, 6 => 1.6, 7 => 1.0], 'adopteLe' => '', 'base' => '']; }
$auj = '2026-11-23';
// Les tickets : toutes les 4 minutes de 07:00 à 18:30, avec des creux (09:40–10:05, 14:10–15:00, 15:35–15:55, 17:50–18:10).
function tickets(string $d): array {
    $c = [['09:40', '10:05'], ['14:10', '15:00'], ['15:35', '15:55'], ['17:50', '18:10']];
    $s = fn ($h) => 3600 * (int) substr($h, 0, 2) + 60 * (int) substr($h, 3, 2);
    $t = []; $pas = [7 => 300, 8 => 160, 9 => 200, 10 => 230, 11 => 190, 12 => 110, 13 => 160, 14 => 330, 15 => 290, 16 => 200, 17 => 180, 18 => 400];
    for ($x = $s('07:00'); $x <= $s('18:30');) {
        $dans = false; foreach ($c as [$a, $b]) { if ($x > $s($a) && $x < $s($b)) { $dans = true; } }
        if (!$dans) { $t[] = $x; }
        $x += $pas[intdiv($x, 3600)] ?? 300;
    }
    if ($d === '2026-11-23') { $t = array_values(array_filter($t, fn ($x) => $x < $s('10:20'))); }
    return ['q' => time(), 't' => $t, 'pro' => 0];
}
$POSTES = [['id' => 1, 'name' => 'Boulanger', 'sector_id' => 11], ['id' => 2, 'name' => 'Tourier', 'sector_id' => 12], ['id' => 3, 'name' => 'Pâtissier', 'sector_id' => 13],
  ['id' => 4, 'name' => 'Sandwichs', 'sector_id' => 14], ['id' => 5, 'name' => 'Traiteur', 'sector_id' => 15], ['id' => 6, 'name' => 'Vente', 'sector_id' => null], ['id' => 7, 'name' => 'Responsable', 'sector_id' => null], ['id' => 8, 'name' => 'Plonge', 'sector_id' => null]];
$SECT = [11 => ['Boulangerie', ['Baguette tradition', 'Pain gris', 'Pain blanc', 'Pain aux céréales', 'Pistolets', 'Pain de seigle', 'Cramique', 'Craquelin', 'Pain d’épeautre', 'Demi-gris', 'Ficelle', 'Pain au levain', 'Pain complet', 'Pain aux noix', 'Pain de mie', 'Baguette blanche', 'Pain multicéréale', 'Pain sans sel', 'Mini-pistolets', 'Pain aux olives', 'Fougasse', 'Pain du jour', 'Pain campagnard', 'Pain au maïs', 'Pain aux graines', 'Boule de seigle']],
  12 => ['Viennoiserie', ['Croissant', 'Pain au chocolat', 'Couque suisse', 'Couque au beurre', 'Pain aux raisins', 'Chausson aux pommes', 'Croissant aux amandes', 'Couque au chocolat', 'Brioche', 'Brioche tressée', 'Mini-viennoiseries', 'Cougnou', 'Cramique au sucre', 'Couque au sucre', 'Suisse aux raisins', 'Croissant jambon', 'Kouign-amann', 'Torsade']],
  13 => ['Pâtisserie', array_map(fn ($i) => 'Pâtisserie ' . $i, range(1, 38))], 14 => ['Snacking', array_map(fn ($i) => 'Sandwich ' . $i, range(1, 24))],
  15 => ['Traiteur', ['Quiche lorraine', 'Vol-au-vent', 'Lasagne', 'Croquettes au fromage', 'Salade de pâtes', 'Boulettes sauce tomate', 'Quiche saumon-poireau', 'Gratin dauphinois', 'Américain préparé', 'Salade de poulet', 'Hachis parmentier', 'Soupe du jour']]];
$PROD = []; $i = 100; foreach ($SECT as $sid => [$n, $L]) { foreach ($L as $p) { $PROD[] = ['id' => $i++, 'name' => $p, 'sector_id' => $sid, 'sector_name' => $n, 'is_active' => 1, 'sale_enabled' => 1]; } }
foreach (['Bougies d’anniversaire', 'Sac isotherme', 'Carte cadeau', 'Café à emporter'] as $p) { $PROD[] = ['id' => $i++, 'name' => $p, 'sector_id' => null]; }
$E = [[21, 'Amélie', 'Dubois', ['Responsable de magasin'], [7, 3], 21.0], [22, 'Bastien', 'Lambert', ['Boulanger'], [1, 2], 19.5], [23, 'Chloé', 'Peeters', ['Vendeuse'], [6], 15.8],
  [24, 'David', 'Janssens', ['Boulanger'], [1], 18.5], [25, 'Elise', 'Maes', ['Pâtissière'], [3], 18.9], [26, 'Fatima', 'El Amrani', ['Vendeuse'], [6, 4], 15.8],
  [27, 'Jonas', 'Willems', ['Apprenti'], [], 9.6], [28, 'Laura', 'Claes', ['Vendeuse'], [6], 15.8], [29, 'Marc', 'Lefèvre', ['Tourier'], [2], 17.9]];
$nomP = array_column($POSTES, 'name', 'id');
$EMP = array_map(fn ($e) => ['id' => $e[0], 'id_shop' => 5, 'name' => $e[1], 'surname' => $e[2], 'positions' => array_map(fn ($p) => ['name' => $p], $e[3]),
  'workstations' => array_map(fn ($w) => ['id' => $w, 'name' => $nomP[$w]], $e[4]), 'money_per_hour' => $e[5], 'active' => 1, 'password' => 'NE-DOIT-PAS-SORTIR'], $E);
$PL = [22 => [['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '10:00'], null, null],
  21 => [['07:00', '15:00'], ['07:00', '15:00'], ['07:00', '15:00'], null, ['07:00', '15:00'], ['07:00', '15:00'], null],
  23 => [['12:30', '18:30'], ['12:30', '18:30'], null, ['12:30', '18:30'], null, ['07:00', '14:00'], ['07:00', '13:00']],
  24 => [null, null, null, ['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '11:00']],
  25 => [['06:00', '14:00'], ['06:00', '14:00'], ['06:00', '14:00'], ['06:00', '14:00'], null, ['06:00', '14:00'], null],
  26 => [null, ['10:00', '18:00'], ['10:00', '18:00'], ['10:00', '18:00'], ['10:00', '18:00'], ['10:00', '14:00'], null],
  27 => [['08:00', '16:00'], null, ['08:00', '16:00'], null, ['08:00', '16:00'], null, null],
  28 => [[['07:00', '11:00'], ['14:30', '18:30']], null, ['12:30', '18:30'], null, ['12:30', '18:30'], ['13:00', '18:30'], ['13:00', '18:00']],
  29 => [null, ['04:00', '12:00'], ['04:00', '12:00'], null, null, ['04:00', '12:00'], ['04:00', '11:00']]];
$id = 500; $SCH = [];
foreach ($PL as $emp => $jours) { foreach ($jours as $k => $x) { if (!$x) { continue; } $d = date('Y-m-d', strtotime('2026-11-23 +' . $k . ' days')); foreach (is_array($x[0]) ? $x : [$x] as [$a, $b]) { $SCH[$d][] = ['id_schedule' => $id++, 'id_employee' => $emp, 'work_date' => $d, 'start_hour' => $a . ':00', 'end_hour' => $b . ':00', 'id_shop' => 5]; } } }
final class PanelApi {
  public static ?string $lastError = null;
  public static function configured(): bool { return true; }
  public static function rep(string $p): mixed { global $EMP, $POSTES, $PROD, $SCH;
    if (str_starts_with($p, '/shops/5/employees')) { return $EMP; }
    if ($p === '/operational-framework/workstations') { return $POSTES; }
    if ($p === '/shops/5/products/available') { return $PROD; }
    if (preg_match('#^/shops/5/schedule\?date=(\S+)$#', $p, $m)) { return $SCH[$m[1]] ?? []; }
    if (str_contains($p, 'margin-heatmap')) { return ['days' => array_map(fn ($i) => ['date' => date('Y-m-d', strtotime('2026-11-22 -' . $i . ' days')), 'has_data' => 1, 'ca' => 3000], range(0, 27))]; }
    return null; }
  public static function get(string $p): mixed { return self::rep($p); }
  public static function getParallele(array $paths, int $f = 4, int $t = 25, bool $x = false, bool $r = true): array { return array_map(fn ($p) => self::rep($p), $paths); }
}
require $R . '/shared/src/cibles_reseau.php';
require $R . '/shared/src/resultat.php';
require $R . '/panel/src/temps_morts.php';
require $R . '/shared/src/checklists_codes.php';
require $R . '/panel/src/equipe.php';
require $R . '/shared/src/doc.php';
$out = $argv[1] ?? __DIR__;
$_GET = ['shop' => '5']; file_put_contents("$out/carte.json", json_encode(ep_equipe_carte(), JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '5', 'du' => '2026-11-23', 'jours' => '1']; $j = ep_equipe_planning(); $j['aujourdhui'] = '2026-11-23'; $j['maintenant'] = '10:20'; file_put_contents("$out/jour.json", json_encode($j, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '5', 'du' => '2026-11-23', 'jours' => '7']; $s = ep_equipe_planning(); $s['aujourdhui'] = '2026-11-23'; file_put_contents("$out/semaine.json", json_encode($s, JSON_UNESCAPED_UNICODE));
// Recopier la semaine précédente : la semaine du 30/11, vide, et l'aperçu de la recopie du 23 au 29.
$_GET = ['shop' => '5', 'du' => '2026-11-30', 'jours' => '7']; $s2 = ep_equipe_planning(); $s2['aujourdhui'] = '2026-11-23'; file_put_contents("$out/semaine2.json", json_encode($s2, JSON_UNESCAPED_UNICODE));
// Le mois de novembre (la vue Mois).
$_GET = ['shop' => '5', 'du' => '2026-11-01', 'jours' => '30']; $mo = ep_equipe_planning(); $mo['aujourdhui'] = '2026-11-23'; file_put_contents("$out/mois.json", json_encode($mo, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '5', 'du' => '2026-11-30']; file_put_contents("$out/recopie.json", json_encode(ep_equipe_recopie(), JSON_UNESCAPED_UNICODE));
// La matrice A B C D (celle de départ, semaine 1 = la semaine du 23/11) et le shift maker sur la semaine du 30/11.
$_GET = ['shop' => '5']; $mx = ep_equipe_matrice(); $mx['matrice']['reference'] = '2026-11-23'; file_put_contents("$out/matrice.json", json_encode($mx, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '5', 'du' => '2026-11-30']; file_put_contents("$out/generer.json", json_encode(ep_equipe_generer(), JSON_UNESCAPED_UNICODE));
// Les creux habituels de la semaine du 30/11 et les tâches qui peuvent y aller (l'étape « Les temps morts » de l'assistant).
$_GET = ['shop' => '5', 'du' => '2026-11-30']; file_put_contents("$out/taches.json", json_encode(ep_equipe_taches(), JSON_UNESCAPED_UNICODE));
// Le planning imprimé (le PDF de « Imprimer ») : le même HTML que ep_equipe_planning_pdf, avant Chromium.
$P = eqpPlanningDonnees(5, '2026-11-23', 7);
file_put_contents("$out/planning-imprime.html", '<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>' . docCss() . eqpPdfCss() . '</style></head><body>' . eqpPlanningPdfHtml($P, 'magasin', 'Sombreffe') . '</body></html>');
echo "ok\n";
