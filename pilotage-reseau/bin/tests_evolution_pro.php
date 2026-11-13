#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du B2B de GET /ventes/evolution (13/11/2026, bascule « ventes : Tout | Comptoir | B2B ») — sans base, sans
 * réseau, sans token.
 *
 *   php bin/tests_evolution_pro.php
 *
 * La base est une doublure : la caisse jour par jour (vsCaisseJours), la copie de caisse des clients pro
 * (vsProCopie), le dernier jour de la copie. Le panel ne répond pas. On vérifie : le filtre TVA appliqué à chaque
 * lecture, un jour sans ticket pro compté zéro (lu) et un jour absent de la copie compté manquant, le relevé du
 * panel qui prime depuis SV_DEBUT, le mois clos gravé seulement quand la copie le couvre, et `pro` posé sur
 * chaque mois et sur son N-1.
 */

// --- doublures : pas de base, pas de réseau ------------------------------------------------
final class Db
{
    public static int $lecturesPro = 0;
    public static bool $panne = false;
    public static function rows(string $sql, array $a = []): array
    {
        if (str_contains($sql, 'FROM shops')) { return [['id' => 4, 'name' => 'Atelier by - Essai']]; }
        if (str_contains($sql, 'LEFT JOIN client')) {
            self::$lecturesPro++;
            if (self::$panne) { throw new RuntimeException('base muette'); }
            return array_values(array_filter($GLOBALS['copie'], static fn ($r) => $r['j'] >= substr($a[1], 0, 10) && $r['j'] < substr($a[2], 0, 10)));
        }
        if (str_contains($sql, 'COUNT(DISTINCT ticket_key) t')) {
            $out = [];
            for ($j = substr($a[1], 0, 10); $j < substr($a[2], 0, 10) && $j <= date('Y-m-d'); $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
                if ($j >= $GLOBALS['ouverture']) { $out[] = ['j' => $j, 'ca' => 1000.0, 't' => 80]; }
            }
            return $out;
        }
        return [];
    }
    public static function row(string $sql, array $a = []): ?array
    {
        if (str_contains($sql, 'MAX(insert_timestamp)')) { return ['le' => $GLOBALS['copieAu'] . ' 18:00:00']; }
        return null;
    }
    public static function exec(string $sql, array $a = []): void
    {
        if (str_contains($sql, 'ceo_app_setting')) { $GLOBALS['reglages'][$a[0]] = json_decode($a[1], true); $GLOBALS['graves'][] = $a[0]; }
    }
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : (is_array($r) && isset($r['data']) ? $r['data'] : []); }
function nombreOuNull(array $x, array $cles): ?float { foreach ($cles as $c) { if (isset($x[$c]) && is_numeric($x[$c])) { return (float) $x[$c]; } } return null; }
final class PanelApi
{
    public static int $appels = 0;
    public static function configured(): bool { return true; }
    public static function get(string $p): mixed { self::$appels++; return null; }
    public static function getParallele(array $chemins, int $n = 6): array { self::$appels += count($chemins); return []; }
}

require __DIR__ . '/../src/stats_ventes.php';
require __DIR__ . '/../src/panel_ventes.php';
require __DIR__ . '/../src/ventes_pro.php';
require __DIR__ . '/../src/ventes_semaines.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$copieDe = static function (string $d1, string $d2, array $parJour, array $sauf = []): array {
    $out = [];
    for ($j = $d1; $j <= $d2; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        if (in_array($j, $sauf, true)) { continue; }
        $out[] = ['j' => $j, 'cli' => 0, 'ca' => 900.0, 'n' => 77];                // les tickets sans client pro
        foreach ($parJour as [$cli, $ca, $n]) { $out[] = ['j' => $j, 'cli' => $cli, 'ca' => $ca, 'n' => $n]; }
    }
    return $out;
};
$sid = 4;
// c12 a une TVA valide, c13 non : ses tickets restent au comptoir.
$tva = ['c12' => [1, time(), null], 'c13' => [0, time(), null]];

echo "vsProJours : la copie de caisse, filtrée par la TVA\n";
$GLOBALS['reglages'] = ['vpTva:' . $sid => $tva]; $GLOBALS['graves'] = [];
$GLOBALS['copieAu'] = date('Y-m-d');
$GLOBALS['copie'] = array_merge(
    [['j' => '2025-03-03', 'cli' => 0, 'ca' => 900.0, 'n' => 77], ['j' => '2025-03-03', 'cli' => 12, 'ca' => 100.0, 'n' => 2], ['j' => '2025-03-03', 'cli' => 13, 'ca' => 50.0, 'n' => 1]],
    [['j' => '2025-03-04', 'cli' => 0, 'ca' => 950.0, 'n' => 80]]     // un jour vendu sans aucun ticket pro
);                                                                   // le 05/03 n'est pas dans la copie
$p = vsProJours($sid, ['2025-03-03', '2025-03-04', '2025-03-05']);
verifie('le pro à TVA valide seulement', ($p['2025-03-03'] ?? null) === ['ca' => 100.0, 'clients' => 2], json_encode($p['2025-03-03'] ?? null));
verifie('un jour vendu sans ticket pro : zéro, lu', ($p['2025-03-04'] ?? null) === ['ca' => 0.0, 'clients' => 0], json_encode($p['2025-03-04'] ?? null));
verifie('un jour absent de la copie : pas lu', !isset($p['2025-03-05']));
$s = vsProSomme($p, ['2025-03-03', '2025-03-04', '2025-03-05']);
verifie('la somme dit les jours lus', $s === ['clients' => 2, 'ca' => 100.0, 'joursLus' => 2], json_encode($s));
verifie('le mois clos couvert par la copie est gravé, brut', in_array('vsPro' . $sid . ':2025-03', $GLOBALS['graves'], true)
    && isset($GLOBALS['reglages']['vsPro' . $sid . ':2025-03']['j']['2025-03-03']['c13']));

echo "vsProJours : le mois gravé ne se relit pas, le filtre TVA se réapplique\n";
$n0 = Db::$lecturesPro;
$GLOBALS['copie'] = [];
$p = vsProJours($sid, ['2025-03-03']);
verifie('aucune lecture de la copie', Db::$lecturesPro === $n0, (string) (Db::$lecturesPro - $n0));
verifie('le gravé relu donne le même pro', ($p['2025-03-03'] ?? null) === ['ca' => 100.0, 'clients' => 2], json_encode($p['2025-03-03'] ?? null));
// Un autre magasin, gravé lors d'une requête d'avant ; depuis, la fiche de c13 a été complétée (TVA valide).
$GLOBALS['reglages']['vsPro5:2025-03'] = $GLOBALS['reglages']['vsPro' . $sid . ':2025-03'];
$GLOBALS['reglages']['vpTva:5'] = ['c12' => [1, time(), null], 'c13' => [1, time(), null]];
$p = vsProJours(5, ['2025-03-03']);
verifie('c13 revient au pro', ($p['2025-03-03'] ?? null) === ['ca' => 150.0, 'clients' => 3], json_encode($p['2025-03-03'] ?? null));

echo "vsProJours : un mois que la copie ne couvre pas encore n'est pas gravé\n";
$GLOBALS['reglages'] = ['vpTva:' . $sid => $tva]; $GLOBALS['graves'] = [];
$GLOBALS['copieAu'] = '2025-04-20'; vsCopieAu(true);
$GLOBALS['copie'] = $copieDe('2025-04-01', '2025-04-20', [[12, 60.0, 2]]);
$p = vsProJours($sid, ['2025-04-10', '2025-04-25']);
verifie('le jour couvert est lu', ($p['2025-04-10'] ?? null) === ['ca' => 60.0, 'clients' => 2]);
verifie('le jour d\'après la copie manque', !isset($p['2025-04-25']));
verifie('rien de gravé', $GLOBALS['graves'] === [], implode(',', $GLOBALS['graves']));

echo "vsProJours : le relevé du panel prime depuis SV_DEBUT\n";
$jR = date('Y-m-d', strtotime(SV_DEBUT . ' +4 days'));
$GLOBALS['reglages'] = ['vpTva:' . $sid => $tva];
$GLOBALS['copieAu'] = date('Y-m-d'); vsCopieAu(true);
$GLOBALS['copie'] = $copieDe($jR, $jR, [[12, 200.0, 5]]);
$GLOBALS['reglages']['svP' . $sid . ':' . $jR] = ['quand' => 0, 'b' => ['q' => 0, 'n' => 80, 'ca' => 1000.0, 't' => [['10:00', 'c12', 60.0, 0, 0], ['11:00', 'c13', 40.0, 0, 0]], 'h' => []]];
$p = vsProJours($sid, [$jR]);
verifie('le relevé, pas la copie (et c13 sans TVA en est retiré)', ($p[$jR] ?? null) === ['ca' => 60.0, 'clients' => 1], json_encode($p[$jR] ?? null));

echo "vsProJours : base muette\n";
Db::$panne = true;
$GLOBALS['reglages'] = ['vpTva:' . $sid => $tva];
$p = vsProJours($sid, ['2025-05-05']);
verifie('rien de lu, rien d\'inventé', $p === [], json_encode($p));
Db::$panne = false;

echo "GET /ventes/evolution : pro sur chaque mois et sur son N-1\n";
$GLOBALS['ouverture'] = '2025-01-01';
$GLOBALS['reglages'] = ['vpTva:' . $sid => $tva]; $GLOBALS['graves'] = [];
$GLOBALS['copieAu'] = date('Y-m-d');
$GLOBALS['copie'] = $copieDe('2025-01-01', date('Y-m-d'), [[12, 60.0, 2], [13, 40.0, 1]]);
// Un jour relevé au panel, différent de la copie : il prime.
$GLOBALS['reglages']['svP' . $sid . ':' . $jR] = ['quand' => 0, 'b' => ['q' => 0, 'n' => 80, 'ca' => 1000.0, 't' => [['10:00', 'c12', 75.0, 0, 0], ['10:30', 'c12', 0.0, 0, 0], ['11:00', 'c12', 0.0, 0, 0]], 'h' => []]];
$r = ep_ventes_evolution();
$M = [];
foreach ($r['magasins'][0]['mois'] ?? [] as $x) { $M[$x['mois']] = $x; }
$juin = $M['2025-06'] ?? null;
verifie('juin 2025 : le pro de ses 30 jours', $juin !== null && $juin['pro'] === ['clients' => 60, 'ca' => 1800.0, 'joursLus' => 30], json_encode($juin['pro'] ?? null));
verifie('juin 2025 : pas de N-1 (le magasin ouvre en 2025)', $juin !== null && $juin['n1'] === null);
$ymR = substr($jR, 0, 7); $mR = $M[$ymR] ?? null; $nR = (int) date('t', strtotime($jR));
verifie('le mois du relevé : le jour relevé remplace celui de la copie', $mR !== null && $mR['pro']['ca'] === round(($nR - 1) * 60.0 + 75.0, 2) && $mR['pro']['clients'] === ($nR - 1) * 2 + 3 && $mR['pro']['joursLus'] === $nR, json_encode($mR['pro'] ?? null));
$moisN1 = date('Y-m', strtotime($jR . ' -1 year'));
verifie('son N-1 porte le pro de ses jours', $mR !== null && $mR['n1'] !== null && $mR['n1']['pro']['joursLus'] === $mR['n1']['jours'] && $mR['n1']['pro']['ca'] === round($mR['n1']['jours'] * 60.0, 2), json_encode($mR['n1'] ?? null));
$cur = $M[date('Y-m')] ?? null;
verifie('le mois en cours : tous ses jours lus', $cur !== null && $cur['enCours'] && $cur['pro']['joursLus'] === $cur['jours'], json_encode($cur['pro'] ?? null));
verifie('la source du B2B est dite', str_contains((string) ($r['sourcePro'] ?? ''), 'TVA valide'));

echo "\n" . ($ko === 0 ? "OK" : "ÉCHEC") . " — $ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
