<?php
declare(strict_types=1);
/*
 * Étiquette d'une recette : les allergènes et les ingrédients doivent être enregistrés
 * (régression : ils l'étaient vides, la requête renvoyant une liste au lieu d'une ligne).
 * Base jetable erp_label_test, structure copiée depuis TFB_TEST_SOURCE_DB (défaut erp).
 *   php tests/predefined_recipe_label_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Product\Recipe\FlattenedRecipeIngredientRepository;
use App\Services\Recipe\PredefinedRecipeLabelService;

function expectLabel(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "predefined_recipe_label_test: SKIPPED (no database)\n";
    exit(0);
}

$pdo->exec('DROP DATABASE IF EXISTS erp_label_test');
$pdo->exec('CREATE DATABASE erp_label_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
foreach (['flattened_recipe_ingredient', 'raw_material', 'material_allergen_connection', 'allergen', 'raw_material_alias',
          'allergen_alias', 'product_recipe', 'predefined_recipe_label', 'language'] as $table) {
    $pdo->exec("CREATE TABLE erp_label_test.{$table} LIKE {$source}.{$table}");
}
$pdo->exec('USE erp_label_test');
$pdo->exec("SET SESSION sql_mode = ''");
$pdo->exec("INSERT INTO allergen (id, code, name) VALUES (1, 'GLUTEN', 'Gluten'), (2, 'MILK', 'Mleko')");
$pdo->exec("INSERT INTO allergen_alias (allergen_id, alias, alias_type, lang_code) VALUES (1, 'gluten', 'product_label', 'fr'), (2, 'lait', 'product_label', 'fr')");
$pdo->exec("INSERT INTO raw_material (id, name, is_part_of_package) VALUES (1, 'Mąka pszenna', 0), (2, 'Masło', 0), (3, 'Karton', 1)");
$pdo->exec("INSERT INTO raw_material_alias (material_id, alias, alias_type, lang_code) VALUES (1, 'farine de blé', 'product_label', 'fr'), (2, 'beurre', 'product_label', 'fr')");
$pdo->exec("INSERT INTO material_allergen_connection (material_id, allergen_id) VALUES (1, 1), (2, 2)");
$pdo->exec("INSERT INTO product_recipe (id, name, id_type) VALUES (10, 'Croissant test', 2)");
$pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (10, 1, 50), (10, 2, 25), (10, 3, 1)");

$repository = new FlattenedRecipeIngredientRepository($pdo);
$label = $repository->getForLabel(10, 'fr');
expectLabel(isset($label['allergens'], $label['ingredients']), 'getForLabel returns one row with allergens and ingredients');
expectLabel($label['ingredients'] === 'farine de blé (gluten), beurre (lait)', 'ingredients ordered by quantity, with their allergens: ' . $label['ingredients']);
expectLabel($label['allergens'] === 'gluten, lait', 'allergens listed once each: ' . $label['allergens']);
expectLabel(!str_contains($label['ingredients'], 'Karton'), 'packaging is not an ingredient');
expectLabel($repository->getForLabel(999, 'fr')['allergens'] === '', 'a recipe without ingredients gives empty texts');

$container = (new DI\ContainerBuilder())->addDefinitions([PDO::class => $pdo])->build();
$container->get(PredefinedRecipeLabelService::class)->buildForRecipe(10, 'fr');
$stored = $pdo->query("SELECT allergens, ingredients FROM predefined_recipe_label WHERE recipe_id = 10 AND lang_code = 'fr'")->fetch();
expectLabel($stored['allergens'] === 'gluten, lait' && str_contains($stored['ingredients'], 'farine de blé (gluten)'), 'the stored label keeps allergens and ingredients');

$pdo->exec('DROP DATABASE erp_label_test');
echo "predefined_recipe_label_test: OK\n";
