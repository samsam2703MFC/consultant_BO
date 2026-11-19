#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'alignement des seuils du Catalogue des KPI sur les cibles 2026 du réseau (kpiCibles2026, src/kpis.php) :
 * food cost 40 %, personnel 17 % — sans base.
 *
 *   php bin/tests_kpi_cibles.php
 *
 * Une base factice : les lignes « food-cost-pct » et « labour-pct » du référentiel, la table `kpi` et les réglages.
 * Chaque cas tourne dans son propre processus (l'alignement se garde en mémoire le temps d'une requête).
 */

if (($argv[1] ?? '') === 'cas') {
    final class Db
    {
        /** code => ligne du référentiel */
        public static array $lignes = [];
        public static array $ecrit = [];
        public static array $reglages = [];
        public static int $lectures = 0;
        public static function row(string $sql, array $a = []): ?array
        {
            if (str_contains($sql, 'COUNT(*)')) { return ['n' => 14]; }
            if (str_contains($sql, 'FROM ceo_kpi_def WHERE code = ?')) { self::$lectures++; return self::$lignes[$a[0]] ?? null; }
            return null;
        }
        public static function rows(string $sql, array $a = []): array { return []; }
        public static function exec(string $sql, array $a = []): int
        {
            if (str_starts_with($sql, 'UPDATE ceo_kpi_def')) {
                foreach (self::$lignes as $k => $l) { if ($l['id'] === $a[2]) { self::$lignes[$k]['seuil_alerte'] = $a[0]; self::$lignes[$k]['seuil_critique'] = $a[1]; } }
            }
            if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { self::$reglages[$a[0]] = json_decode($a[1], true); }
            if (!str_starts_with($sql, 'CREATE') && !str_starts_with($sql, 'ALTER')) { self::$ecrit[] = [$sql, $a]; }
            return 1;
        }
    }
    function setting(string $k, mixed $d = null): mixed { return Db::$reglages[$k] ?? $d; }
    $JOURNAL = [];
    function journalAdd(string $qui, string $quoi, ?string $p, string $m): void { global $JOURNAL; $JOURNAL[] = $p . ' : ' . $m; }
    require __DIR__ . '/../src/kpis.php';
    $food = ['id' => 5, 'seuil_alerte' => '32.00', 'seuil_critique' => '37.00'];
    $labour = ['id' => 6, 'seuil_alerte' => '33.00', 'seuil_critique' => '36.00'];
    $cas = $argv[2];
    if ($cas === 'premier') {
        Db::$lignes = ['food-cost-pct' => $food, 'labour-pct' => $labour];
        ensureKpiDefs(); ensureKpiDefs();
    } elseif ($cas === 'foodDeja') {
        // Le food cost a été aligné à la livraison d'avant (puis reposé à 38 au Catalogue) : seul le personnel s'aligne.
        Db::$lignes = ['food-cost-pct' => ['id' => 5, 'seuil_alerte' => '38.00', 'seuil_critique' => '42.00'], 'labour-pct' => $labour];
        Db::$reglages['kpiFoodCost2026'] = ['le' => 'avant'];
        ensureKpiDefs();
    } elseif ($cas === 'absente') {
        ensureKpiDefs();
        $avant = count(Db::$ecrit);
        Db::$lignes = ['food-cost-pct' => ['id' => 5, 'seuil_alerte' => null, 'seuil_critique' => null], 'labour-pct' => ['id' => 6, 'seuil_alerte' => null, 'seuil_critique' => null]];
        ensureKpiDefs();
        echo json_encode(['avantLigne' => $avant]) . "\n";
    }
    echo json_encode(['lignes' => Db::$lignes, 'ecrit' => Db::$ecrit, 'reglages' => Db::$reglages, 'journal' => $JOURNAL, 'lectures' => Db::$lectures], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$lancer = static function (string $cas): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' cas ' . escapeshellarg($cas) . ' 2>&1');
    return array_map(static fn ($x) => json_decode($x, true) ?? ['brut' => $x], array_values(array_filter(explode("\n", trim($out)))));
};
$f = static fn ($v) => $v === null ? null : (float) $v;
$pont = static fn (array $R, string $code) => array_values(array_filter($R['ecrit'], static fn ($e) => str_starts_with($e[0], 'UPDATE kpi SET') && ($e[1][1] ?? '') === $code));

echo "Premier passage après la livraison\n";
[$R] = $lancer('premier');
verifie('food cost : alerte 32 → 40, critique 37 → 45 (l’écart gardé)', $f($R['lignes']['food-cost-pct']['seuil_alerte']) === 40.0 && $f($R['lignes']['food-cost-pct']['seuil_critique']) === 45.0, json_encode($R['lignes']));
verifie('personnel : alerte 33 → 17, critique 36 → 20 (l’écart gardé)', $f($R['lignes']['labour-pct']['seuil_alerte']) === 17.0 && $f($R['lignes']['labour-pct']['seuil_critique']) === 20.0, json_encode($R['lignes']));
verifie('la table kpi suit par le pont : food 40, labour 17', count($pont($R, 'food')) === 1 && $f($pont($R, 'food')[0][1][0]) === 40.0 && $f($pont($R, 'labour')[0][1][0]) === 17.0, json_encode($R['ecrit']));
verifie('les valeurs d’avant sont gardées, un drapeau par cible', ($R['reglages']['kpiFoodCost2026']['avant'] ?? null) == ['alerte' => 32, 'critique' => 37] && ($R['reglages']['kpiPersonnel2026']['avant'] ?? null) == ['alerte' => 33, 'critique' => 36], json_encode($R['reglages']));
verifie('deux lignes au journal', count($R['journal']) === 2 && str_contains($R['journal'][1], 'Labour cost (%) : Seuil aligné sur l’objectif 2026 : alerte 33 → 17'), json_encode($R['journal']));
verifie('une seule fois par requête', $R['lectures'] === 2 && count($R['ecrit']) === 6, json_encode($R));

echo "Le food cost déjà aligné : le Catalogue commande\n";
[$R] = $lancer('foodDeja');
verifie('le food cost reposé à 38 % n’est pas écrasé', $f($R['lignes']['food-cost-pct']['seuil_alerte']) === 38.0 && $pont($R, 'food') === [], json_encode($R));
verifie('le personnel s’aligne seul : 17 / 20', $f($R['lignes']['labour-pct']['seuil_alerte']) === 17.0 && $f($R['lignes']['labour-pct']['seuil_critique']) === 20.0 && isset($R['reglages']['kpiPersonnel2026']), json_encode($R['lignes']));

echo "Base neuve : les lignes n’existent pas encore\n";
[$A, $R] = $lancer('absente');
verifie('sans ligne, rien ne s’écrit', $A['avantLigne'] === 0, json_encode($A));
verifie('la lecture suivante aligne : 40 / 44 et 17 / 20', $f($R['lignes']['food-cost-pct']['seuil_alerte']) === 40.0 && $f($R['lignes']['food-cost-pct']['seuil_critique']) === 44.0
    && $f($R['lignes']['labour-pct']['seuil_alerte']) === 17.0 && $f($R['lignes']['labour-pct']['seuil_critique']) === 20.0, json_encode($R['lignes']));

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
