#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du module Benchmark — sans base, sans réseau, sans jeton.
 *
 *   php bin/tests_benchmark.php
 *
 * L'API Benchmark de TFBuddy est simulée en mémoire, AVEC les manies que décrit sa
 * spec OpenAPI : `[]` pour « introuvable », 204 sur PATCH, `inserted_id`, aucun
 * contrôle de `shopId`. Ce qui se vérifie : la vérification des 18 routes (lecture et
 * cycle d'écriture nettoyé), le semis cougnou idempotent, le calcul au kilo, la
 * position et le calendrier de la routine.
 */

// --- doublures ----------------------------------------------------------------------
final class Db
{
    public static array $exec = [];
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void { self::$exec[] = $sql; }
    public static function config(): array { return []; }
}
$GLOBALS['reglages'] = [];
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function journalAdd(string $actor, string $kind, ?string $project, string $message): void {}
function body(): array { return []; }

/** L'API Benchmark simulée. */
final class PanelApi
{
    public static ?string $lastError = null;
    public static array $comp = [], $obs = [], $items = [];
    public static int $seq = 100;
    public static int $appels = 0;
    public static function configured(): bool { return true; }
    public static function sonde(string $m, string $path, ?array $b = null, int $t = 15): array
    {
        self::$appels++;
        $r = fn (int $c, mixed $corps) => ['code' => $c, 'corps' => $corps, 'ms' => 1, 'erreur' => $c >= 300 ? "HTTP $c" : null];
        $u = parse_url($path); $p = $u['path']; parse_str($u['query'] ?? '', $q);
        $inc = (string) ($q['include'] ?? '');
        $creer = function (array &$table, array $row) use ($r) { $id = ++self::$seq; $table[$id] = ['id' => $id] + $row + ['created_at' => date('Y-m-d H:i:s')]; return $r(201, ['status' => 'success', 'message' => 'ok', 'inserted_id' => $id]); };
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors$#', $p, $x)) {
            if ($m === 'POST') { return $creer(self::$comp, ($b ?? []) + ['shop_id' => (int) $x[1]]); }
            $l = array_values(array_filter(self::$comp, fn ($c) => $c['shop_id'] === (int) $x[1]));
            if ($inc === 'observations') { foreach ($l as &$c) { $c['observations'] = array_values(array_filter(self::$obs, fn ($o) => $o['competitor_id'] === $c['id'])); } }
            return $r(200, $l);
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors/(\d+)$#', $p, $x)) {
            $id = (int) $x[2];   // shopId non contrôlé, comme l'API réelle
            if ($m === 'PATCH') { if (isset(self::$comp[$id])) { self::$comp[$id] = array_merge(self::$comp[$id], $b ?? []); } return $r(204, null); }
            if ($m === 'DELETE') { unset(self::$comp[$id]); return $r(200, ['status' => 'success', 'message' => 'ok']); }
            return $r(200, self::$comp[$id] ?? []);
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors/(\d+)/observations$#', $p, $x)) {
            if ($m === 'POST') { return $creer(self::$obs, ($b ?? []) + ['shop_id' => (int) $x[1], 'competitor_id' => (int) $x[2]]); }
            $l = array_values(array_filter(self::$obs, fn ($o) => $o['competitor_id'] === (int) $x[2]));
            if ($inc === 'items') { foreach ($l as &$o) { $o['items'] = array_values(array_filter(self::$items, fn ($i) => $i['observation_id'] === $o['id'])); } }
            return $r(200, $l);
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/observations/(\d+)$#', $p, $x)) {
            $id = (int) $x[2];
            if ($m === 'PATCH') { if (isset(self::$obs[$id])) { self::$obs[$id] = array_merge(self::$obs[$id], $b ?? []); } return $r(204, null); }
            if ($m === 'DELETE') { unset(self::$obs[$id]); return $r(200, ['status' => 'success', 'message' => 'ok']); }
            return $r(200, self::$obs[$id] ?? ($inc === 'items' ? ['items' => []] : []));
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/observations/(\d+)/items$#', $p, $x)) {
            return $r(200, array_values(array_filter(self::$items, fn ($i) => $i['observation_id'] === (int) $x[2])));
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/competitors/(\d+)/observations/(\d+)/items$#', $p, $x) && $m === 'POST') {
            return $creer(self::$items, ($b ?? []) + ['shop_id' => (int) $x[1], 'competitor_id' => (int) $x[2], 'observation_id' => (int) $x[3]]);
        }
        if (preg_match('#^/shops/(\d+)/benchmarks/observations/(\d+)/items/(\d+)$#', $p, $x)) {
            $id = (int) $x[3];
            if ($m === 'PATCH') { if (isset(self::$items[$id])) { self::$items[$id] = array_merge(self::$items[$id], $b ?? []); } return $r(204, null); }
            if ($m === 'DELETE') { unset(self::$items[$id]); return $r(200, ['status' => 'success', 'message' => 'ok']); }
            return $r(200, self::$items[$id] ?? []);
        }
        if (preg_match('#^/shops/\d+/benchmarks/analysis/my-products$#', $p)) {
            return $r(200, ['kpi' => ['tracked_products' => 0, 'covered_competitors' => count(self::$comp), 'comparisons_count' => count(self::$items), 'freshness_days' => 0], 'products' => []]);
        }
        if (preg_match('#^(/shops/\d+)?/benchmarks/analysis/my-products/competitors$#', $p)) { return $r(200, ['breakdown' => []]); }
        return $r(404, ['status' => 'error', 'description' => 'not found']);
    }
}

require __DIR__ . '/../src/doc.php';
require __DIR__ . '/../src/benchmark.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "Vérification des routes\n";
$v = benchmarkVerifier(4, false);
verifie('lecture seule sur une base vide : tout passe', $v['ok'], $v['resume']);
verifie('lecture seule : aucune écriture', PanelApi::$comp === []);
$v = benchmarkVerifier(4, true);
verifie('cycle d\'écriture complet', $v['ok'], $v['resume']);
verifie('cycle d\'écriture : 13 étapes d\'écriture + 6 lectures', count($v['etapes']) >= 19, (string) count($v['etapes']));
verifie('témoin nettoyé (concurrent, observation, item)', PanelApi::$comp === [] && PanelApi::$obs === [] && PanelApi::$items === []);
verifie('cloisonnement absent signalé', (bool) array_filter($v['alertes'], fn ($a) => str_contains($a, 'Cloisonnement')));

echo "Semis cougnou\n";
$d = benchmarkCougnouDonnees();
$sim = benchmarkCougnouSemer(true);
verifie('simulation : rien n\'est écrit', PanelApi::$comp === [] && $sim['compte']['items'] > 0, json_encode($sim['compte']));
$r1 = benchmarkCougnouSemer(false);
verifie('semis : sans erreur', $r1['ok'], implode(' | ', $r1['journal']));
verifie('semis : autant d\'items que simulés', $r1['compte']['items'] === $sim['compte']['items'], $r1['compte']['items'] . ' vs ' . $sim['compte']['items']);
$nItems = count(PanelApi::$items);
$r2 = benchmarkCougnouSemer(false);
verifie('rejouer ne crée aucun doublon', count(PanelApi::$items) === $nItems && $r2['compte']['concurrents'] === 0 && $r2['compte']['deja'] > 0, json_encode($r2['compte']));
$lie = array_filter(PanelApi::$items, fn ($i) => isset($i['my_product_id']));
verifie('items rattachés à nos produits (my_product_id)', count($lie) > 0);
verifie('chaque concurrent a son magasin', !array_filter(PanelApi::$comp, fn ($c) => !in_array($c['shop_id'], [2, 3, 4, 5], true)));

echo "Calculs\n";
verifie('€/kg : 4,50 € les 500 g = 9 €/kg', benchmarkPrixKg(['price' => 4.5, 'unit_type' => 'g', 'unit_size' => 500]) === 9.0);
verifie('€/kg : la promo prime', benchmarkPrixKg(['price' => 10, 'promo_price' => 8, 'is_promo' => true, 'unit_type' => 'kg', 'unit_size' => 1]) === 8.0);
verifie('€/kg : sans poids, rien', benchmarkPrixKg(['price' => 3]) === null);
verifie('médiane paire', benchmarkMediane([1, 3, 2, 4]) === 2.5);
verifie('date sûre pour le SQL de l\'API', BenchmarkApi::date("2026-11-07' OR 1=1") === null && BenchmarkApi::date('2026-02-30') === null && BenchmarkApi::date('2026-11-07') === '2026-11-07');
$rap = benchmarkCougnouRapport(false);
verifie('rapport : source API quand l\'API répond', $rap['source'] === 'api', $rap['source']);
verifie('rapport : notre prix au kilo calculé', is_float($rap['ref_eur_kg']) && $rap['ref_eur_kg'] > 5);
verifie('rapport : relevés dédoublonnés entre magasins', count($rap['concurrents']) <= count(array_merge(...array_map(fn ($c) => $c['items'], $d['concurrents']))));
verifie('rapport HTML rendu', str_contains(benchmarkCougnouHtml($rap), 'Prix au kilo'));

echo "Routine\n";
$tz = new DateTimeZone('Europe/Brussels');
verifie('lundi 7 h : passe', benchmarkCronDu(new DateTime('2026-03-02 07:05', $tz)));
verifie('jeudi 7 h hors saison : non', !benchmarkCronDu(new DateTime('2026-03-05 07:05', $tz)));
verifie('jeudi 7 h en saison (novembre) : passe', benchmarkCronDu(new DateTime('2026-11-12 07:05', $tz)));
verifie('jeudi 7 h début janvier : passe', benchmarkCronDu(new DateTime('2027-01-01 07:05', $tz)));
verifie('lundi 8 h : non', !benchmarkCronDu(new DateTime('2026-03-02 08:05', $tz)));

echo "\n$ok ok, $ko KO\n";
exit($ko === 0 ? 0 : 1);
