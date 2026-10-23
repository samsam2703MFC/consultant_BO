<?php
declare(strict_types=1);
/*
 * Wartości odżywcze z internetu (Open Food Facts) : słowa nazwy i odmiana polska, dopasowanie kategorii (polskie,
 * słowniczek → angielskie, bez fałszywych przyjaciół), mediany produktów, propozycja dla surowca z fałszywym OFF
 * (bez sieci), pamięć kategorii i median, zapis zatwierdzonych wartości ze źródłem, ręczna zmiana, dostęp.
 *
 *   php tests/nutrition_test.php
 *
 * Baza : TFB_TEST_DSN (domyślnie mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS ;
 * struktury z TFB_TEST_SOURCE_DB (erp, migracje tfb zastosowane). Baza erp_nutrition_test tworzona i usuwana.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Material\NutritionRepository;
use App\Repositories\Organization\PageAccessRepository;
use App\Services\Material\Nutrition\NutritionLookupService as N;
use App\Services\Material\Nutrition\OffClient;
use App\Services\Organization\PageAccessService;

function expectNutri(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function failsNutri(callable $fn, int $code, string $message): void
{
    try { $fn(); } catch (CustomException $e) { expectNutri((int) $e->getCode() === $code, $message . ' (kod ' . $e->getCode() . ' : ' . $e->getMessage() . ')'); return; }
    throw new RuntimeException('FAILED: ' . $message . ' (brak wyjątku)');
}

/** Fałszywe Open Food Facts : mała taksonomia i produkty kategorii ; zapisuje zapytania. */
final class FakeOff extends OffClient
{
    public array $calls = [];
    public bool $down = false;

    protected function get(string $url, int $timeout): array
    {
        $this->calls[] = $url;
        if ($this->down) throw new CustomException('Open Food Facts niedostępne : timeout.', 'errors', 502);
        if ($url === self::TAXONOMY_URL) {
            return [200, json_encode([
                'en:butters' => ['name' => ['pl' => 'Masła', 'fr' => 'Beurres', 'en' => 'Butters', 'de' => 'Butter']],
                'en:wheat-flours' => ['name' => ['pl' => 'Mąki pszenne', 'en' => 'Wheat flours']],
                'en:flours' => ['name' => ['pl' => 'Mąki', 'en' => 'Flours']],
                'en:strawberries' => ['name' => ['en' => 'Strawberries', 'fr' => 'Fraises']],
                'en:frozen-strawberries' => ['name' => ['en' => 'Frozen strawberries']],
                'en:morels' => ['name' => ['en' => 'Morel']],
                'en:apricots' => ['name' => ['en' => 'Apricots']],
                'en:mascarpone' => ['name' => ['en' => 'Mascarpone', 'fr' => 'Mascarpone']],
                'en:cheeses' => ['name' => ['pl' => 'Sery', 'en' => 'Cheeses']],
                'en:beers' => ['name' => ['en' => 'Beers']],
                'en:empty' => ['name' => ['pl' => 'Masło orzechowe']],
            ])];
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $tag = $q['categories_tags'] ?? '';
        $products = match ($tag) {
            'en:butters' => [
                ['nutriments' => ['energy-kcal_100g' => 745, 'fat_100g' => 82, 'saturated-fat_100g' => 54, 'carbohydrates_100g' => 0.6, 'sugars_100g' => 0.6, 'proteins_100g' => 0.7, 'salt_100g' => 0.02]],
                ['nutriments' => ['energy-kcal_100g' => 740, 'fat_100g' => 82, 'saturated-fat_100g' => 56, 'carbohydrates_100g' => 0.5, 'sugars_100g' => 0.5, 'proteins_100g' => 0.6, 'salt_100g' => 0.01]],
                ['nutriments' => ['energy_100g' => 3060, 'fat_100g' => 80, 'saturated-fat_100g' => 52, 'carbohydrates_100g' => 0.7, 'sugars_100g' => 0.7, 'proteins_100g' => 0.8, 'salt_100g' => 0.03]],
                ['nutriments' => ['energy-kcal_100g' => 9999, 'fat_100g' => 'x']],
                ['product_name' => 'bez wartości'],
            ],
            'en:frozen-strawberries' => array_fill(0, 6, ['nutriments' => ['energy-kcal_100g' => 32, 'fat_100g' => 0.3, 'saturated-fat_100g' => 0, 'carbohydrates_100g' => 6, 'sugars_100g' => 6, 'fiber_100g' => 2, 'proteins_100g' => 0.7, 'salt_100g' => 0]]),
            'en:empty' => [['nutriments' => ['energy-kcal_100g' => 600]]],
            default => [],
        };
        return [200, json_encode(['count' => count($products) * 10, 'products' => $products])];
    }
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_nutrition_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "nutrition_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['raw_material', 'user', 'tfb_nutrition_category', 'tfb_nutrition_off_cache', 'tfb_material_nutrition_source', 'tfb_page_access', 'tfb_user_position',
                 'tfb_prod_user_role', 'tfb_position'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
        (1, 'Ad', 'Min', 'Admin', 'a@x.test', '1', 0, 'admin', 'x'), (2, 'Jan', 'K', 'Jan K.', 'j@x.test', '2', 0, 'jan', 'x'), (3, 'Ola', 'P', 'Ola P.', 'o@x.test', '3', 0, 'ola', 'x')");
    $pdo->exec("INSERT INTO tfb_user_position (id_user, id_position) VALUES (2, 5), (3, 6)");
    $pdo->exec("INSERT INTO tfb_page_access (id_position, page_key) VALUES (5, 'materials')");
    $pdo->exec("INSERT INTO raw_material (id, name, is_part_of_package, energy_100g, fat_100g, saturated_fat_100g, carbohydrates_100g, sugars_100g, salt_100g, fiber_100g, proteins_100g)
        VALUES (10, 'Masło extra 82% blok 10 kg', 0, 0, 0, 0, 0, 0, 0, 0, 0), (11, 'Truskawka mrożona', 0, 0, 0, 0, 0, 0, 0, 0, 0), (12, 'Kostka rosołowa Maga', 0, 1, 1, 1, 1, 1, 1, 1, 1)");

    // --- Słowa i odmiana
    expectNutri(array_column(N::words('Masło extra 82% blok 10 kg'), 0) === ['maslo'], 'name words: accents folded, weights, percentages and filler words dropped');
    expectNutri(N::wordScore(['maslo', 'masło'], ['masla', 'masła']) > 0.7 && N::wordScore(['maka', 'mąka'], ['maki', 'mąki']) > 0.7,
        'Polish inflection: masło / masła, mąka / mąki');
    expectNutri(N::wordScore(['banan', 'banan'], ['banyuls', 'banyuls']) === 0.0 && N::wordScore(['mak', 'mak'], ['makarony', 'makarony']) === 0.0
        && N::wordScore(['mak', 'mak'], ['maki', 'mąki']) < N::wordScore(['maka', 'mąka'], ['maki', 'mąki']), 'different words refused; diacritics difference lowers the score');

    // --- Dopasowanie kategorii
    $off = new FakeOff();
    $cats = $off->categories();
    expectNutri(count($cats) === 18 && !array_filter($cats, fn($c) => $c['lang'] === 'de'), 'taxonomy: names in pl, fr and en only');
    $m = N::match('Masło extra 82% blok 10 kg', $cats);
    expectNutri(($m[0]['tag'] ?? '') === 'en:butters' && $m[0]['lang'] === 'pl' && $m[0]['coverage'] == 1.0 && !in_array('en:empty', array_column($m, 'tag'), true),
        'butter → « Masła » (Polish name), not « Masło orzechowe » (more words than the material)');
    $m = N::match('Mąka pszenna typ 650', $cats);
    expectNutri(($m[0]['tag'] ?? '') === 'en:wheat-flours' && in_array('en:flours', array_column($m, 'tag'), true), 'wheat flour → « Mąki pszenne » before « Mąki »');
    $m = N::match('Truskawka mrożona', $cats);
    expectNutri(($m[0]['tag'] ?? '') === 'en:frozen-strawberries' && $m[0]['via'] === 'en' && $m[0]['coverage'] == 1.0, 'no Polish category: glossary → « Frozen strawberries »');
    $m = N::match('Morela św.', $cats);
    expectNutri(($m[0]['tag'] ?? '') === 'en:apricots' && !in_array('en:morels', array_column($m, 'tag'), true), 'false friend refused: morela (apricot) ≠ morel (mushroom)');
    $m = N::match('ser mascarpone', $cats);
    expectNutri(($m[0]['tag'] ?? '') === 'en:mascarpone', 'specific word (mascarpone) before the generic head noun (cheeses)');
    expectNutri(N::match('wołowina mielona', $cats) === [] && N::match('Kostka rosołowa Maga', $cats) === [], 'beef ≠ beers; unknown products give no category');

    // --- Mediany
    $med = N::medians($off->products('en:butters')['products']);
    expectNutri($med['sample'] === 3 && $med['values']['energy_100g'] == 740 && $med['values']['fat_100g'] == 82 && $med['values']['saturated_fat_100g'] == 54
        && $med['values']['fiber_100g'] === null && $med['values']['salt_100g'] == 0.02, 'medians: kJ converted, out-of-range and text values ignored, fewer than 3 values → none');
    $med = N::medians([['nutriments' => ['fat_100g' => 10, 'saturated-fat_100g' => 30]], ['nutriments' => ['fat_100g' => 10, 'saturated-fat_100g' => 30]], ['nutriments' => ['fat_100g' => 10, 'saturated-fat_100g' => 30]]]);
    expectNutri($med['values']['saturated_fat_100g'] == 10, 'saturated fat never above fat');

    // --- Propozycja, pamięć, zapis
    $repo = new NutritionRepository($pdo);
    $access = new PageAccessService(new PageAccessRepository($pdo));
    $svc = new N($repo, $off, $access);
    $off->calls = [];
    $r = $svc->lookup(10, null, null, 2);
    expectNutri($r['chosen']['tag'] === 'en:butters' && $r['chosen']['sample'] === 3 && $r['chosen']['values']['energy_100g'] == 740 && !$r['confident'] && $r['fetched']
        && $r['material']['values']['energy_100g'] == 0 && count($off->calls) === 2 && str_contains($r['chosen']['url'], 'openfoodfacts.org'),
        'lookup: taxonomy loaded once, category products fetched, median proposed (3 products : to check, not « sure »)');
    $off->calls = [];
    $r2 = $svc->lookup(11, null, null, 1);
    $r3 = $svc->lookup(10, null, null, 1);
    expectNutri($r2['confident'] && $r2['chosen']['tag'] === 'en:frozen-strawberries' && !$r3['fetched'] && count($off->calls) === 1,
        'cached taxonomy and medians reused (only the new category fetched); full match with enough products → sure');
    $r4 = $svc->lookup(12, 'masło', null, 1);
    expectNutri($r4['query'] === 'masło' && $r4['chosen']['tag'] === 'en:butters', 'search with another phrase');
    $r5 = $svc->lookup(10, null, 'en:empty', 1);
    expectNutri($r5['chosen'] === null, 'category with fewer than 3 products → no proposal');
    failsNutri(fn() => $svc->lookup(10, null, 'en:nope', 1), 422, 'unknown category refused');
    failsNutri(fn() => $svc->lookup(10, null, null, 3), 403, 'position without the materials page: no lookup');
    failsNutri(fn() => $svc->lookup(999, null, null, 1), 404, 'unknown material');

    $a = $svc->apply(10, ['tag' => 'en:butters', 'values' => ['energy_100g' => 740.4, 'fat_100g' => 82, 'saturated_fat_100g' => 54]], 2);
    $row = $pdo->query('SELECT energy_100g, fat_100g, saturated_fat_100g, proteins_100g FROM raw_material WHERE id = 10')->fetch();
    $src = $svc->sources()[10] ?? null;
    expectNutri((int) $row['energy_100g'] === 740 && (float) $row['fat_100g'] === 82.0 && (float) $row['proteins_100g'] === 0.0 && $src && $src['ref'] === 'en:butters'
        && $src['label'] === 'Masła' && (int) $src['sample'] === 3 && (int) $src['applied_by'] === 2 && $a['source']['label'] === 'Masła',
        'apply: only the chosen values saved, source (category, products, who) kept');
    failsNutri(fn() => $svc->apply(10, ['tag' => 'en:butters', 'values' => ['energy_100g' => 5000]], 1), 422, 'impossible value refused');
    failsNutri(fn() => $svc->apply(10, ['tag' => 'en:butters', 'values' => ['price' => 1]], 1), 422, 'unknown field refused');
    failsNutri(fn() => $svc->apply(10, ['tag' => 'en:butters', 'values' => []], 1), 422, 'nothing chosen');
    failsNutri(fn() => $svc->apply(10, ['tag' => '', 'values' => ['fat_100g' => 1]], 1), 422, 'source category required');
    failsNutri(fn() => $svc->apply(10, ['tag' => 'en:butters', 'values' => ['fat_100g' => 1]], 3), 403, 'no access: nothing saved');
    $svc->manualEdit(10);
    expectNutri(!isset($svc->sources()[10]), 'manual change of a value: the internet source no longer describes the material');

    // --- OFF niedostępne : kategorie zostają z pamięci
    $off->down = true;
    $pdo->exec("UPDATE tfb_nutrition_category SET loaded_at = '2020-01-01 00:00:00'");
    $r6 = $svc->lookup(10, null, null, 1);
    expectNutri($r6['chosen']['tag'] === 'en:butters', 'OFF down: old taxonomy and cached medians still used');
    $pdo->exec('DELETE FROM tfb_nutrition_off_cache');
    failsNutri(fn() => $svc->lookup(10, null, null, 1), 502, 'OFF down without cached medians: clear error');

    echo "nutrition_test: OK\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
