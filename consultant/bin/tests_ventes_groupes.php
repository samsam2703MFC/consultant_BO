#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du CA par groupe de catégories, mois par mois (src/ventes_groupes.php, Résultat › Mois, 27/11/2026) — sans
 * base ni panel : la base et l'API sont des doublures en mémoire.
 *
 *   php consultant/bin/tests_ventes_groupes.php
 *
 * - vgCondense somme `sales_value` par `group_name` (et range une ligne sans groupe à part) ;
 * - vgMois lit le cache d'abord, ne dépasse pas son budget de lectures, dit ce qui reste, garde un mois clos et ne
 *   garde pas une lecture qui a échoué ;
 * - la route refuse une fenêtre à l'envers ou trop large.
 */

final class Db
{
    public static array $ecrits = [];
    public static function row(string $q, array $a = []): ?array { return null; }
    public static function rows(string $q, array $a = []): array { return []; }
    public static function exec(string $q, array $a = []): int { self::$ecrits[$a[0]] = $a[1]; return 1; }
}
final class PanelApi
{
    public static array $demandes = [];
    public static array $echecs = [];
    public static function configured(): bool { return true; }
    public static function consultantShops(): ?array { return [['id' => 2, 'name' => 'Corbais'], ['id' => 5, 'name' => 'Sombreffe']]; }
    public static function getParallele(array $paths, int $front = 4, int $timeout = 25): array
    {
        $out = [];
        foreach ($paths as $k => $p) {
            self::$demandes[] = $k;
            $out[$k] = in_array($k, self::$echecs, true) ? null : [
                ['group_name' => 'Traiteur', 'sales_value' => '100.50'],
                ['group_name' => 'Traiteur', 'sales_value' => '20'],
                ['group_name' => 'Tartes', 'sales_value' => '7.25'],
                ['group_name' => '', 'sales_value' => '1'],
            ];
        }
        return $out;
    }
}
$GLOBALS['cache'] = [];
function setting(string $k, $d = null) { return $GLOBALS['cache'][$k] ?? (isset(Db::$ecrits[$k]) ? json_decode(Db::$ecrits[$k], true) : $d); }
function analyseListe($r): array { return is_array($r) ? $r : []; }

require __DIR__ . '/../src/ventes_groupes.php';

$ok = 0; $ko = 0;
$voir = function (string $quoi, bool $vrai) use (&$ok, &$ko): void {
    if ($vrai) { $ok++; echo "  ok  $quoi\n"; } else { $ko++; echo "  KO  $quoi\n"; }
};

// 1) La condensation.
$g = vgCondense([['group_name' => 'Quiches', 'sales_value' => '10.10'], ['group_name' => ' Quiches ', 'sales_value' => '5'],
    ['group_name' => null, 'sales_value' => '2'], ['group_name' => 'Tartes', 'total_earning' => '3']]);
$voir('vgCondense somme par groupe (espaces ignorés)', ($g['Quiches'] ?? null) === 15.1);
$voir('vgCondense range une ligne sans groupe à part', ($g['Sans groupe'] ?? null) === 2.0);
$voir('vgCondense prend total_earning faute de sales_value', ($g['Tartes'] ?? null) === 3.0);

// 2) Le cache, le budget, ce qui reste.
$GLOBALS['cache']['vgM2:2025-01'] = ['quand' => 1, 'g' => ['Boulangerie' => 50.0]];
$couples = [[2, '2025-01'], [2, '2025-02'], [5, '2025-02'], [2, '2025-03'], [5, '2025-03']];
PanelApi::$echecs = ['5:2025-02'];
[$lu, $reste] = vgMois($couples, 3);
$voir('un mois clos en cache ne repart pas au panel', !in_array('2:2025-01', PanelApi::$demandes, true) && ($lu['2:2025-01']['Boulangerie'] ?? null) === 50.0);
$voir('au plus le budget de lectures par passe (3)', count(PanelApi::$demandes) === 3);
$voir('ce qui dépasse le budget est compté dans « reste »', $reste === 1 && !array_key_exists('5:2025-03', $lu));
$voir('un mois lu est rendu par groupe', ($lu['2:2025-02']['Traiteur'] ?? null) === 120.5 && ($lu['2:2025-02']['Tartes'] ?? null) === 7.25);
$voir('une lecture échouée vaut null et ne se garde pas', array_key_exists('5:2025-02', $lu) && $lu['5:2025-02'] === null && !isset(Db::$ecrits['vgM5:2025-02']));
$voir('un mois lu se garde (vgM{sid}:{AAAA-MM})', isset(Db::$ecrits['vgM2:2025-02']));

// 3) La route : les fenêtres refusées.
$_GET = ['du' => '2026-05', 'au' => '2026-01'];
$r = @ep_ventes_groupes_mois();   // @ : en CLI, le code HTTP arrive après la sortie du test
$voir('une fenêtre à l’envers est refusée', isset($r['error']));
$_GET = ['du' => '2022-01', 'au' => '2026-01'];
$r = @ep_ventes_groupes_mois();   // @ : en CLI, le code HTTP arrive après la sortie du test
$voir('une fenêtre de plus de ' . VG_MOIS_MAX . ' mois est refusée', isset($r['error']));
$_GET = ['du' => '2025-01', 'au' => '2025-02'];
$r = @ep_ventes_groupes_mois();   // @ : en CLI, le code HTTP arrive après la sortie du test
$voir('la route rend mois, magasins, CA par magasin et par mois', ($r['mois'] ?? null) === ['2025-01', '2025-02']
    && count($r['magasins'] ?? []) === 2 && isset(((array) $r['ca'])['2']['2025-01']));

echo "\n" . ($ko ? "ÉCHEC : $ko sur " . ($ok + $ko) . " vérifications.\n" : "Tout est vert : $ok vérifications.\n");
exit($ko ? 1 : 0);
