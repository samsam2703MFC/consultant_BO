#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des temps morts — sans base, sans réseau.
 *
 *   php bin/tests_temps_morts.php
 *
 * Le calcul d'une journée (tmMesure) et la lecture d'une liste de tickets (tmDuListe) sont
 * des fonctions pures : on leur donne des heures, on vérifie les trous.
 */

// --- doublures -----------------------------------------------------------------------
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void {}
}
function setting(string $k, mixed $d = null): mixed { return $d; }
function svGrave(string $k, array $v): void {}
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : (is_array($r['data'] ?? null) ? $r['data'] : []); }

require __DIR__ . '/../src/temps_morts.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$s = static fn (string $hm) => 3600 * (int) substr($hm, 0, 2) + 60 * (int) substr($hm, 3, 2) + (int) (substr($hm, 6, 2) ?: 0);

echo "Une journée simple\n";
$m = tmMesure(array_map($s, ['06:00', '06:05', '06:20', '06:22', '07:00']), 10, true);
verifie('deux trous de 10 min ou plus (15 et 38 min)', $m['nb'] === 2, json_encode($m));
verifie('53 minutes sans client sur 60', $m['mort'] === 53 && $m['ouvert'] === 60, json_encode($m));
verifie('part de l’ouverture 88,3 %', $m['pct'] === 88.3, (string) $m['pct']);
verifie('le plus long : 38 min, de 06:22 à 07:00', $m['plusLong'] === ['min' => 38.0, 'de' => '06:22', 'a' => '07:00'], json_encode($m['plusLong']));
$h6 = (array) $m['heures']->{'6'};
verifie('6 h : 53 min mortes, 4 tickets, 2 trous', $h6['mort'] === 53.0 && $h6['tk'] === 4 && $h6['nb'] === 2, json_encode($h6));
$h7 = (array) $m['heures']->{'7'};
verifie('7 h : le dernier ticket, aucune minute ouverte', $h7['ouvert'] === 0.0 && $h7['pct'] === null && $h7['tk'] === 1, json_encode($h7));
verifie('le détail porte les trous', $m['trous'] === [[$s('06:05'), $s('06:20')], [$s('06:22'), $s('07:00')]]);
$m5 = tmMesure(array_map($s, ['06:00', '06:05', '06:20', '06:22', '07:00']), 5);
verifie('au seuil de 5 min, le trou de 5 min compte aussi', $m5['nb'] === 3 && $m5['mort'] === 58, json_encode($m5));
verifie('sans détail, ni tickets ni trous', !isset($m5['ticks']) && !isset($m5['trous']));

echo "Un trou à cheval sur deux heures\n";
$m = tmMesure(array_map($s, ['09:50', '09:55', '10:20', '10:25']), 10);
$h9 = (array) $m['heures']->{'9'}; $h10 = (array) $m['heures']->{'10'};
verifie('9 h : 5 minutes mortes, le trou y commence', $h9['mort'] === 5.0 && $h9['nb'] === 1, json_encode($h9));
verifie('10 h : 20 minutes mortes, aucun trou n’y commence', $h10['mort'] === 20.0 && $h10['nb'] === 0, json_encode($h10));
verifie('9 h : 10 min ouvertes, 50 % sans client', $h9['ouvert'] === 10.0 && $h9['pct'] === 50.0, json_encode($h9));

echo "Les bords de la journée\n";
$m = tmMesure(array_map($s, ['05:00', '06:00', '06:05', '06:10', '19:30']), 10, true);
verifie('un ticket à 5 h, une heure avant le suivant, n’ouvre pas la journée', $m['debut'] === '06:00', json_encode($m));
verifie('un ticket à 19 h 30 n’allonge pas la journée', $m['fin'] === '06:10' && $m['nb'] === 0, json_encode($m));
verifie('deux tickets écartés, rendus à part', $m['isoles'] === 2 && $m['ecartes'] === [$s('05:00'), $s('19:30')], json_encode($m));
$m = tmMesure(array_map($s, ['06:00', '06:44', '07:00']), 10);
verifie('à moins de 45 min du suivant, le premier ticket ouvre la journée', $m['debut'] === '06:00' && $m['nb'] === 2 && $m['mort'] === 60, json_encode($m));
$m = tmMesure(array_map($s, ['06:00', '06:05', '06:50']), 10);
verifie('à 45 min tout juste du précédent, le dernier ticket est écarté', $m['fin'] === '06:05' && $m['isoles'] === 1, json_encode($m));
verifie('aucun ticket : fermé', tmMesure([], 10) === ['ferme' => true, 'tickets' => 0]);
verifie('un seul ticket : fermé', tmMesure([$s('09:00')], 10)['ferme'] === true);
$m = tmMesure([$s('09:30'), $s('09:00'), $s('09:35')], 10);
verifie('les tickets se trient', $m['debut'] === '09:00' && $m['fin'] === '09:35' && $m['nb'] === 1, json_encode($m));

echo "La liste du panel\n";
$liste = ['data' => [
    ['id' => 3, 'insert_timestamp' => '2026-11-06 07:03:20', 'is_client_b2b' => false],
    ['id' => 1, 'insert_timestamp' => '2026-11-06 06:58:00', 'is_client_b2b' => true],
    ['id' => 2, 'insert_timestamp' => '2026-11-07 00:10:00'],
    ['id' => 4, 'insert_timestamp' => '2026-11-06T08:15'],
    ['id' => 5],
]];
$r = tmDuListe($liste, '2026-11-06');
verifie('les heures en secondes, triées, du seul jour demandé', $r['t'] === [$s('06:58'), $s('07:03:20'), $s('08:15')], json_encode($r['t']));
verifie('un ticket pro compté', $r['pro'] === 1);

echo "Les moyennes\n";
$a = tmMesure(array_map($s, ['06:00', '06:20', '07:00']), 10);
$b = tmMesure(array_map($s, ['06:00', '06:05', '06:10', '06:15', '06:50', '07:00']), 10);
$M = tmMoyenne([$a, $b, ['ferme' => true, 'tickets' => 0], null]);
verifie('deux journées ouvertes, la fermée et la non lue écartées', $M['jours'] === 2, json_encode($M));
verifie('nombre moyen 2 ; minutes moyennes 52,5 → 53', $M['nb'] === 2.0 && $M['mort'] === 53, json_encode($M));
verifie('la part sur les minutes additionnées : 105 / 120', $M['pct'] === 87.5, json_encode($M));
verifie('aucune journée : zéro jour', tmMoyenne([null]) === ['jours' => 0]);

echo "Le magasin\n";
verifie('un magasin inconnu ne passe pas', tmMagasinExiste(99999) === false);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
