#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'alignement du seuil food cost sur l'objectif 2026 (kpiFoodCost2026, src/kpis.php) — sans base.
 *
 *   php bin/tests_kpi_food.php
 *
 * Une base factice : la ligne « food-cost-pct » du référentiel, la table `kpi` et les réglages. Chaque cas tourne dans
 * son propre processus (l'alignement se garde en mémoire le temps d'une requête).
 */

if (($argv[1] ?? '') === 'cas') {
    final class Db
    {
        public static ?array $ligne = null;
        public static array $ecrit = [];
        public static array $reglages = [];
        public static int $lectures = 0;
        public static function row(string $sql, array $a = []): ?array
        {
            if (str_contains($sql, 'COUNT(*)')) { return ['n' => 14]; }
            if (str_contains($sql, "code = 'food-cost-pct'")) { self::$lectures++; return self::$ligne; }
            return null;
        }
        public static function rows(string $sql, array $a = []): array { return []; }
        public static function exec(string $sql, array $a = []): int
        {
            if (str_starts_with($sql, 'UPDATE ceo_kpi_def')) { self::$ligne['seuil_alerte'] = $a[0]; self::$ligne['seuil_critique'] = $a[1]; }
            if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { self::$reglages[$a[0]] = json_decode($a[1], true); }
            if (!str_starts_with($sql, 'CREATE') && !str_starts_with($sql, 'ALTER')) { self::$ecrit[] = $sql; }
            return 1;
        }
    }
    function setting(string $k, mixed $d = null): mixed { return Db::$reglages[$k] ?? $d; }
    $JOURNAL = [];
    function journalAdd(string $qui, string $quoi, ?string $p, string $m): void { global $JOURNAL; $JOURNAL[] = $m; }
    require __DIR__ . '/../src/kpis.php';
    $cas = $argv[2];
    if ($cas === 'premier') {
        Db::$ligne = ['id' => 5, 'seuil_alerte' => '32.00', 'seuil_critique' => '37.00'];
        ensureKpiDefs(); ensureKpiDefs();
    } elseif ($cas === 'drapeau') {
        Db::$ligne = ['id' => 5, 'seuil_alerte' => '35.00', 'seuil_critique' => '39.00'];
        Db::$reglages['kpiFoodCost2026'] = ['le' => 'avant'];
        ensureKpiDefs();
    } elseif ($cas === 'absente') {
        ensureKpiDefs();
        $avant = count(Db::$ecrit);
        Db::$ligne = ['id' => 5, 'seuil_alerte' => null, 'seuil_critique' => null];
        ensureKpiDefs();
        echo json_encode(['avantLigne' => $avant]) . "\n";
    }
    echo json_encode(['ligne' => Db::$ligne, 'ecrit' => Db::$ecrit, 'drapeau' => Db::$reglages['kpiFoodCost2026'] ?? null, 'journal' => $JOURNAL, 'lectures' => Db::$lectures], JSON_UNESCAPED_UNICODE) . "\n";
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
    $l = array_values(array_filter(explode("\n", trim($out))));
    return array_map(static fn ($x) => json_decode($x, true) ?? ['brut' => $x], $l);
};

echo "Premier passage après la livraison\n";
[$R] = $lancer('premier');
verifie('alerte 32 → 40, critique 37 → 45 (l’écart de 5 points gardé)', (float) $R['ligne']['seuil_alerte'] === 40.0 && (float) $R['ligne']['seuil_critique'] === 45.0, json_encode($R));
verifie('la table kpi (food) suit par le pont', in_array("UPDATE kpi SET seuil_haut = ? WHERE code = 'food'", $R['ecrit'], true), json_encode($R['ecrit']));
verifie('les valeurs d’avant sont gardées dans le drapeau', $R['drapeau']['avant'] === ['alerte' => 32, 'critique' => 37] && $R['drapeau']['apres']['alerte'] == 40, json_encode($R['drapeau']));
verifie('une ligne au journal', count($R['journal']) === 1 && str_contains($R['journal'][0], 'alerte 32 → 40'), json_encode($R['journal']));
verifie('une seule fois par requête', $R['lectures'] === 1 && count($R['ecrit']) === 3, json_encode($R));

echo "Le drapeau posé : le Catalogue commande\n";
[$R] = $lancer('drapeau');
verifie('un seuil reposé au Catalogue (35 %) n’est pas écrasé', (float) $R['ligne']['seuil_alerte'] === 35.0 && $R['ecrit'] === [] && $R['lectures'] === 0, json_encode($R));

echo "Base neuve : la ligne n’existe pas encore\n";
[$A, $R] = $lancer('absente');
verifie('sans ligne, rien ne s’écrit', $A['avantLigne'] === 0, json_encode($A));
verifie('la lecture suivante aligne : 40 / 44', (float) $R['ligne']['seuil_alerte'] === 40.0 && (float) $R['ligne']['seuil_critique'] === 44.0 && $R['drapeau'] !== null, json_encode($R));

echo "\n" . ($ko === 0 ? "Tout est vert : $ok vérifications." : "$ko échec(s), $ok réussite(s).") . "\n";
exit($ko === 0 ? 0 : 1);
