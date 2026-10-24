<?php
declare(strict_types=1);
/*
 * Karta produktu → tablet : zdjęcie produktu i 4 etapy ze zdjęciem (zapis obrazu i opisu, odrzucenie złego pliku,
 * usuwanie, tablet w języku PL / FR) ; grupa przygotowująca półprodukt (zapis, usunięcie, lista grupy).
 * Na kopii lokalnej ERP ; wszystko, co test tworzy, jest usuwane.
 *   php tests/product_media_producer_test.php
 */
require_once __DIR__ . '/../App/Core/init.php';

use App\Exceptions\CustomException;
use App\Repositories\Workshop\RecipeProducerRepository;
use App\Services\Workshop\ProductMediaService;

function expectMedia(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

try {
    $container = require __DIR__ . '/../App/Core/bootstrap.php';
    $pdo = $container->get(PDO::class);
} catch (Throwable $e) {
    echo "product_media_producer_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}
if (!$pdo->query("SHOW TABLES LIKE 'n_product_media'")->fetchColumn()) {
    echo "product_media_producer_test: SKIPPED (run php Bin/tfb_migrate.php apply first)\n";
    exit(0);
}
$product = (int) $pdo->query('SELECT id FROM final_product WHERE id NOT IN (SELECT id_final_product FROM n_product_media) ORDER BY id LIMIT 1')->fetchColumn();
$component = (int) $pdo->query('SELECT id FROM product_recipe WHERE id_type IN (1, 2) AND id NOT IN (SELECT id_recipe FROM n_recipe_producer) ORDER BY id LIMIT 1')->fetchColumn();
$group = (int) $pdo->query('SELECT id FROM n_prod_group WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if (!$product || !$component || !$group) { echo "product_media_producer_test: SKIPPED (no data)\n"; exit(0); }

$media = $container->get(ProductMediaService::class);
$producers = $container->get(RecipeProducerRepository::class);
$png = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
try {
    $sheet = $media->sheet($product);
    expectMedia($sheet['photo']['image'] === null && count($sheet['steps']) === 4, 'pusta karta : zdjęcie + 4 etapy');
    $sheet = $media->save($product, 0, ['image' => $png], null);
    expectMedia(str_contains((string) $sheet['photo']['image'], "/products/{$product}/media/0?v="), 'zdjęcie produktu : adres z wersją');
    $media->save($product, 2, ['image' => $png, 'caption' => 'Piecz 35 min', 'caption_fr' => 'Cuire 35 min'], null);
    $media->save($product, 3, ['caption' => 'Zapakuj'], null);
    $media->save($product, 3, ['caption_fr' => 'Emballer'], null);
    $sheet = $media->sheet($product);
    expectMedia($sheet['steps'][2]['caption'] === 'Zapakuj' && $sheet['steps'][2]['caption_fr'] === 'Emballer', 'opis PL zostaje przy zapisie opisu FR');
    foreach ([['image' => 'data:text/plain;base64,aGVsbG8='], ['image' => 'data:image/png;base64,' . base64_encode('not a png')], ['caption' => str_repeat('x', 301)]] as $bad) {
        try { $media->save($product, 1, $bad, null); expectMedia(false, 'zły plik / za długi opis odrzucony'); } catch (CustomException) {}
    }
    try { $media->save($product, 5, ['caption' => 'x'], null); expectMedia(false, 'slot spoza 0–4 odrzucony'); } catch (CustomException) {}
    try { $media->save(999999999, 1, ['caption' => 'x'], null); expectMedia(false, 'nieznany produkt'); } catch (CustomException) {}

    $kiosk = $media->forKiosk($product, false);
    expectMedia(str_starts_with((string) $kiosk['photo'], 'data:image/png;base64,'), 'tablet : zdjęcie jako data URL');
    expectMedia(array_column($kiosk['steps'], 'no') === [2, 3] && $kiosk['steps'][0]['text'] === 'Piecz 35 min' && $kiosk['steps'][1]['src'] === null, 'tablet : tylko wypełnione etapy, etap bez zdjęcia');
    expectMedia($media->forKiosk($product, true)['steps'][0]['text'] === 'Cuire 35 min', 'tablet po francusku');
    $media->deleteImage($product, 2);
    expectMedia($media->forKiosk($product, false)['steps'][0]['src'] === null, 'usunięte zdjęcie, opis zostaje');
    $media->save($product, 3, ['caption' => '', 'caption_fr' => ''], null);
    expectMedia(!$pdo->query("SELECT 1 FROM n_product_media WHERE id_final_product = {$product} AND slot = 3")->fetchColumn(), 'pusty slot znika');

    $producers->set($component, $group, null);
    expectMedia(($producers->forRecipes([$component])[$component]['id'] ?? null) === $group, 'grupa przygotowująca zapisana');
    expectMedia(in_array($component, array_column($producers->recipesOfGroup($group), 'id'), true), 'lista receptur grupy');
    expectMedia($producers->isComponent($component) && !$producers->isComponent((int) $pdo->query('SELECT id FROM product_recipe WHERE id_type = 3 LIMIT 1')->fetchColumn()), 'tylko półprodukty i kompozycje');
    $producers->set($component, null, null);
    expectMedia($producers->forRecipes([$component]) === [], 'grupa usunięta');
} finally {
    $pdo->exec("DELETE FROM n_product_media WHERE id_final_product = {$product}");
    $pdo->exec("DELETE FROM n_recipe_producer WHERE id_recipe = {$component}");
}

$root = __DIR__ . '/..';
$kioskJs = file_get_contents("$root/public/assets/js/workshop/kiosk.js");
expectMedia(str_contains($kioskJs, '/kiosk/recipes/components/') && str_contains($kioskJs, 'kiosk-rec-stepgrid') && str_contains($kioskJs, 'producerBadge'), 'tablet : etapy ze zdjęciem, półprodukty innych grup');
expectMedia(str_contains(file_get_contents("$root/public/assets/js/workshop/products.js"), 'mediaBlock('), 'karta produktu : zdjęcia (przeciągnij i upuść)');
foreach (['pl', 'fr'] as $lang) {
    $k = json_decode(file_get_contents("$root/public/resources/lang/ui/$lang/kiosk.json"), true);
    foreach (['rec_components', 'rec_made_by', 'rec_ready_from', 'rec_steps_photos', 'rec_how_much'] as $key) expectMedia(isset($k[$key]), "kiosk.json $lang : $key");
    $w = json_decode(file_get_contents("$root/public/resources/lang/ui/$lang/workshop.json"), true);
    foreach (['m.title', 'm.step', 'm.drop', 'm.caption_fr'] as $key) expectMedia(isset($w[$key]), "workshop.json $lang : $key");
}
echo "product_media_producer_test: OK\n";
