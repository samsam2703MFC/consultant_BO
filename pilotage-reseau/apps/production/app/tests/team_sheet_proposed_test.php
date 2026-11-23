<?php
/**
 * [386] Feuille d'équipe « Co produkujemy w tym tygodniu » : ce qui reste « do rozplanowania » est inscrit dans les
 * colonnes des jours (répartition de « Rozdziel na dni », calcul seul), en italique ; la mention rouge
 * « nierozplanowane » disparaît, sauf reste impossible à poser (semaine passée). Sans base de données : team() et
 * teamSections() appelés par réflexion avec un tygodnia factice.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\Translator;
use App\Services\Workshop\Planning\TeamSheetService;
use App\Services\Workshop\Planning\WeekService;

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };

$svc = (new ReflectionClass(TeamSheetService::class))->newInstanceWithoutConstructor();
$team = new ReflectionMethod(TeamSheetService::class, 'team');
$sections = new ReflectionMethod(TeamSheetService::class, 'teamSections');

$days = [];
foreach (['2026-11-18' => 3, '2026-11-19' => 4, '2026-11-20' => 5, '2026-11-23' => 1, '2026-11-24' => 2] as $d => $w) $days[] = ['date' => $d, 'weekday' => $w, 'today' => $d === '2026-11-23', 'past' => $d < '2026-11-23'];
$week = ['days' => $days];
$prod = fn(int $id, string $name, array $slots, float $unassigned, float $direct, ?string $due) => ['item_id' => $id, 'name' => $name, 'item_type' => 'product', 'unit' => 'szt', 'ppc' => 18,
    'batch' => null, 'quantity' => array_sum(array_column($slots, 'quantity')) + $unassigned, 'slots' => $slots, 'unassigned_qty' => $unassigned, 'unassigned_h' => 0.0,
    'direct' => $direct, 'direct_parts' => $due ? [['due_date' => $due, 'quantity' => $direct]] : []];
$products = [
    $prod(1, 'Baguette Poivre & Sel', [], 216, 216, '2026-11-24'),                                   // tout à répartir
    $prod(2, 'Brioche', [['day' => '2026-11-23', 'quantity' => 10, 'cartons' => 1, 'batches' => null, 'hours' => 1, 'direct_qty' => 10, 'stock_qty' => 0, 'late' => false, 'time' => '06:00']], 6, 16, '2026-11-24'),
    $prod(3, 'Carré 10 Céréales', [], 1650, 0, null),                                              // reste impossible à poser
    $prod(4, 'Ciabatta', [['day' => '2026-11-19', 'quantity' => 45, 'cartons' => 3, 'batches' => null, 'hours' => 1, 'direct_qty' => 45, 'stock_qty' => 0, 'late' => false, 'time' => null]], 0, 45, '2026-11-24'),
];
$suggested = [1 => ['2026-11-23' => 108.0, '2026-11-24' => 108.0], 2 => ['2026-11-23' => 6.0], 99 => ['2026-11-23' => 5.0]];
$t = $team->invoke($svc, ['id' => 7, 'name' => 'Piekarnia', 'color' => '#8D1D2C'], [], $products, $week, [], [], $suggested, [3 => 1800.0, 1 => 0.0]);
$rows = array_column($t['products'], null, 'item_id');

$check($rows[1]['days']['2026-11-23']['quantity'] === 108.0 && $rows[1]['days']['2026-11-24']['quantity'] === 108.0 && $rows[1]['days']['2026-11-23']['proposed'] === 108.0
    && $rows[1]['unassigned_qty'] === 0.0 && $rows[1]['proposed_qty'] === 216.0 && $rows[1]['quantity'] === 216.0, 'unplanned 216 written into the days (108 + 108), nothing left « nierozplanowane », total unchanged');
$check($rows[2]['days']['2026-11-23']['quantity'] === 16.0 && $rows[2]['days']['2026-11-23']['proposed'] === 6.0 && $rows[2]['days']['2026-11-23']['time'] === '06:00'
    && $rows[2]['unassigned_qty'] === 0.0, 'planned 10 + proposed 6 on the same day : 16, time of the planned block kept');
$check($rows[3]['unassigned_qty'] === 1650.0 && $rows[3]['days'] === [] && $rows[3]['proposed_qty'] === 0.0, 'nothing could be placed (no suggestion) : the rest stays « nierozplanowane »');
$check(!isset($rows[4]['days']['2026-11-19']['proposed']) && (float) $rows[4]['days']['2026-11-19']['quantity'] === 45.0, 'already planned only : unchanged, not italic');
$check($t['proposed'] === 2 && $t['unassigned'] === 1, 'team : 2 products with proposed days, 1 left unplaced');
// [388] « Jest na stanie » : le stock existant, à part, jamais compté dans « Do zrobienia ».
$check($rows[3]['on_hand'] === 1800.0 && $rows[3]['quantity'] === 1650.0 && $rows[1]['on_hand'] === 0.0 && $rows[2]['on_hand'] === null, 'on hand : stock shown apart (1 800 szt), to produce unchanged ; unknown stock → null');
$blocks = array_merge(...array_map(fn($d) => $d['blocks'], $t['days']));
$check(count($blocks) === 2, 'calendar / day lists (tablets) : only planned blocks, proposals do not create blocks');

$html = implode("\n", array_map(fn($s) => $s[1], $sections->invoke($svc, $t, new Translator('pl'))));
$check(str_contains($html, '<i>108</i>') && str_contains($html, '<i>16</i>') && str_contains($html, '>45<'), 'PDF : proposed quantities in italics in the day columns, planned ones plain');
$check(substr_count($html, 'nierozplanowane') === 1 && str_contains($html, 'nierozplanowane : 1'), 'PDF : « nierozplanowane » only for the product that could not be placed');
$check(str_contains($html, 'Liczby kursywą : do zrobienia'), 'PDF : footnote explains the italics');
// [390] Présentation de la Lista produkcyjna : W magazynie / Zamówienie (kart. + szt.), Do produkcji.
$pos = fn(string $x) => strpos($html, $x);
$check($pos('W magazynie') !== false && $pos('W magazynie') < $pos('Zamówienie') && $pos('Zamówienie') < $pos('Do produkcji') && substr_count($html, '>Kart.<') === 2 && substr_count($html, '>Szt.<') === 4
    && !str_contains($html, 'Na magazyn') && !str_contains($html, 'Na zamówienia') && !str_contains($html, '>Razem<'), 'PDF : columns as the production list — W magazynie (kart./szt.) → Zamówienie (kart./szt.) → Do produkcji (szt.)');
$check(str_contains($html, '1 800') && str_contains($html, '>100<') && str_contains($html, 'Do produkcji = zamówienie − w magazynie'), 'PDF : stock in cartons and pieces, legend under the table');
// Cas de la référence (23.11.2026) : Poivre & Sel 10 kart. / 180 szt. en stock, 12 kart. / 216 szt. commandés → 36 à produire.
// [393] Tradition (produit de stock) : les 1 620 commandés partent des 1 692 en stock ; il reste 72 < min → réassort 3 528
// (max 3 600). Do produkcji = « - » comme l'ancienne liste ; le réassort est à part, dans « Na zapas ».
$ref = [$prod(1, 'Baguette Poivre & Sel', [], 36, 36, '2026-11-24'), $prod(2, 'Baguette Tradition 500 g.', [], 3528, 0, null)];
$ref[0]['ppc'] = 18; $ref[1]['ppc'] = 18;
$tr = $team->invoke($svc, ['id' => 7, 'name' => 'Piekarnia', 'color' => null], [], $ref, $week, [], [], [], [1 => 180.0, 2 => 1692.0], [1 => ['qty' => 216.0, 'due' => '2026-11-24'], 2 => ['qty' => 1620.0, 'due' => '2026-11-24']]);
$rr = array_column($tr['products'], null, 'item_id');
$check($rr[1]['ordered'] === 216.0 && $rr[1]['on_hand'] === 180.0 && $rr[1]['quantity'] === 36.0 && $rr[1]['ordered_due'] === '2026-11-24', 'reference : ordered 216, in stock 180, to produce 36 (216 − 180)');
$h = implode("\n", array_map(fn($s) => $s[1], $sections->invoke($svc, $tr, new Translator('pl'))));
$check(str_contains($h, '>10<') && str_contains($h, '>180<') && str_contains($h, '>12<') && str_contains($h, '>216<') && str_contains($h, '<b>36</b>'), 'reference PDF : 10 / 180 | 12 / 216 | 36');
$check(str_contains($h, '>94<') && str_contains($h, '>1 692<') && str_contains($h, '>90<') && str_contains($h, '>1 620<'), 'reference PDF : Baguette Tradition 94 / 1 692 | 90 / 1 620 | nothing to produce');
$trad = substr($h, strpos($h, 'Baguette Tradition'));
$trad = substr($trad, 0, strpos($trad, '</tr>'));
$check(preg_match('/>1 620<.*?<td[^>]*>-<\/td><td[^>]*>3 528<\/td>/s', $trad) === 1, '[393] Tradition : Do produkcji « - » (like the old list), Na zapas 3 528 apart');
$check(str_contains($h, '<b>36</b>'), '[393] Poivre & Sel : Do produkcji 36 = 216 − 180 (same as the old list)');
$t2 = $team->invoke($svc, ['id' => 7, 'name' => 'Piekarnia', 'color' => null], [], [$products[3]], $week, [], [], []);
$html2 = implode("\n", array_map(fn($s) => $s[1], $sections->invoke($svc, $t2, new Translator('pl'))));
$check(!str_contains($html2, 'Liczby kursywą'), 'no proposal : no footnote');

// WeekService : suggested() est public et la répartition partagée avec distribute() (une seule règle).
$check((new ReflectionMethod(WeekService::class, 'suggested'))->isPublic() && (new ReflectionMethod(WeekService::class, 'placement'))->isPrivate(), 'WeekService::suggested() public, placement() shared and private');
$src = (string) file_get_contents(__DIR__ . '/../App/Services/Workshop/Planning/WeekService.php');
preg_match('/public function suggested\(.*?\n    \}/s', $src, $m);
$check(isset($m[0]) && !str_contains($m[0], 'setSlot') && !str_contains($m[0], '->tx->') && !str_contains($m[0], 'restoreFixedFrom'), 'suggested() writes nothing (no setSlot, no transaction)');
$check(substr_count($src, '$this->placement(') === 2, 'distribute() and suggested() use the same placement');

foreach (['ui/pl/teamsheet.json', 'ui/fr/teamsheet.json', 'api/pl/production.json', 'api/fr/production.json'] as $f) {
    $j = json_decode((string) file_get_contents(__DIR__ . '/../public/resources/lang/' . $f), true);
    $check(is_array($j), "lang $f : valid JSON");
}

echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
