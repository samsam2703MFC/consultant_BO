<?php
// Les jauges comptoir et B2B du dashboard (23/10/2026) : exJ7 (avec le pro de J−7), exHeuresPro, exPartProHabituelle, isolées.
$src = file_get_contents(__DIR__ . '/../src/endpoints.php');
$a = strpos($src, 'function exJ7('); $b = strpos($src, '/* --- Le Résultat gardé quelques minutes', $a);
$GLOBALS['B'] = [];
function svHeuresJours($sid, $jours) { $o = []; foreach ($jours as $j) { $o[$j] = [['h' => 9, 'tickets' => 10, 'ca' => 500.0], ['h' => 11, 'tickets' => 8, 'ca' => 380.0], ['h' => 14, 'tickets' => 6, 'ca' => 90.0]]; } return $o; }
function svHeuresAvecMatiere($sid, $j, $hs) { return $hs; }
function vpLu($sid, $j) { return $GLOBALS['B'][$j] ?? null; }
eval(substr($src, $a, $b - $a));
$ok = 0; $ko = 0; $v = function ($n, $c, $d = '') use (&$ok, &$ko) { if ($c) { $ok++; echo "  ok  $n\n"; } else { $ko++; echo "  KO  $n — $d\n"; } };
$date = '2026-10-22'; $j7 = '2026-10-15';
$r = exJ7(4, $date, false);
$v('J−7 sans relevé pro : le pro reste null', $r['caPro'] === null && $r['caProJour'] === null && !isset($r['heures'][0]['pro']), json_encode($r));
$GLOBALS['B'][$j7] = ['n' => 24, 'ca' => 970.0, 't' => [['09:12', 'k1', 300.0, 1, 0], ['11:40', 'k2', 80.0, 0, 0]], 'h' => ['9' => [300.0, 1], '11' => [80.0, 1]]];
$r = exJ7(4, $date, false);
$v('J−7 journée close : tout le pro, heure par heure', $r['caPro'] === 380.0 && $r['caProJour'] === 380.0 && $r['ticketsPro'] === 2 && $r['heures'][0]['pro'] === 300.0 && $r['heures'][1]['proN'] === 1 && $r['heures'][2]['pro'] === 0.0, json_encode($r));
$v('heures pro d’un relevé', exHeuresPro($GLOBALS['B'][$j7]) === [['h' => 9, 'ca' => 300.0, 'n' => 1], ['h' => 11, 'ca' => 80.0, 'n' => 1]] && exHeuresPro(null) === null);
foreach (['2026-10-15' => [1000, 200], '2026-10-08' => [800, 0], '2026-10-01' => [1200, 100]] as $j => [$ca, $pro]) { $GLOBALS['B'][$j] = ['n' => 10, 'ca' => (float) $ca, 't' => $pro ? [['10:00', 'k', (float) $pro, 0, 0]] : [], 'h' => []]; }
$p = exPartProHabituelle(4, $date);
$v('part habituelle : 300 € de pro sur 3 000 € des trois jours lus = 10 %', $p === ['part' => 10.0, 'jours' => 3], json_encode($p));
$v('aucun jour lu : part null', exPartProHabituelle(4, '2025-01-01') === ['part' => null, 'jours' => 0]);
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
