<?php
/**
 * Bouchon d'API pour relever la fiche « Cougnou Nature 400 g » de l'app Cuisine (figer.js).
 *
 * Sert la fiche technique du produit 1700400 et sa photo, avec les valeurs de la capture d'écran du 24/11/2026 ;
 * tout le reste part au bouchon du dépôt Cuisine (ThaiZu/TFB-Kitchen, tools/mock-api), dont le dossier est donné
 * par KITCHEN_DIR. Poste de développement uniquement : aucune authentification.
 *
 *   KITCHEN_DIR=/chemin/TFB-Kitchen php -S 127.0.0.1:8081 contexte/bouchon.php
 */
$chemin = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$photo = 'http://' . $_SERVER['HTTP_HOST'] . '/photo/cougnou.jpg';
// L'ApiClient de l'app enveloppe lui-même la réponse : on rend le corps nu.
$rendre = function ($d) { header('Content-Type: application/json'); echo json_encode($d); exit; };

if ($chemin === '/photo/cougnou.jpg') { header('Content-Type: image/jpeg'); readfile(__DIR__ . '/cougnou.jpg'); exit; }
if (preg_match('#^/products/1700400/webshop-image#', $chemin)) { $rendre(['image' => ['url' => $photo]]); }
if (preg_match('#^/products/1700400/technical-sheet/raw#', $chemin)) {
    $rendre([
        'object' => [
            'id' => 1700400, 'name' => 'Cougnou Nature - 400 g.', 'category_name' => 'Cougnou',
            'storage_name' => 'Comptoir Sec- 1', 'storage_description' => 'À température ambiante',
            'storage_temperature' => '20', 'positioning_name' => 'Voir plan de magasin',
            'shelf_life_minutes' => 600, 'shelf_life_category' => 'SSL', 'suggested_sale_price' => '5.80',
            'label_size' => 'small', 'single_weight' => 400, 'quantity_per_label' => 1,
            'webshop_image' => ['url' => $photo],
        ],
        'recipe' => [
            'id' => 9001, 'name' => 'Cougnou', 'yield_quantity' => 1, 'unit_name' => 'pcs',
            'elements' => [[
                'type' => 'ingredient', 'ingredient_id' => 501, 'name' => 'Cougnou - Nature 400 g. - Cru',
                'recipe_quantity' => 1, 'required_quantity' => 1, 'unit_name' => 'pcs',
                'allergens' => ['Œufs', 'Céréales contenant du gluten'],
            ]],
        ],
        'preparation' => ['types' => [['id' => 1, 'name' => 'Mise en place', 'duration_second' => 60]], 'steps' => []],
    ]);
}

require (getenv('KITCHEN_DIR') ?: __DIR__ . '/../../../../../../TFB-Kitchen') . '/tools/mock-api/index.php';
