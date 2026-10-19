<?php
namespace App\Repositories\Product\Recipe;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class RecipeRepository extends BaseRepository
{

    protected string $table = 'product_recipe';

    protected $allowedColumns = [
        "name",
        "id_product",
        "description",
        "id_type",
        "is_subrecipe",
        "id_unit",
        "yield_quantity",
        "precalculated_material_cost"
    ];

    protected $fetchAllowedColumns = [

    ];

    public function getByType($typeId)
    {
        $sql = "SELECT pr.*,
                    EXISTS (
                        SELECT 1 FROM product_recipe_connection prc
                        WHERE prc.parent_recipe_id = pr.id
                    ) AS has_elements
                FROM product_recipe pr
                WHERE pr.id_type = :id_type
                ORDER BY pr.name";
        return $this->query($sql, ['id_type' => $typeId]);
    }

    public function getFinalRecipes()
    {
        return $this->query("SELECT 
                                    pr.id,
                                    p.name,
                                    pr.id_product,
                                    pr.description,
                                    pr.id_type,
                                    pr.is_subrecipe,
                                    pr.id_unit,
                                    pr.yield_quantity,
                                    pr.precalculated_material_cost 
                                FROM product_recipe pr
                                INNER JOIN final_product p 
                                ON pr.id = p.id_recipe WHERE pr.id_type = 3 ORDER BY p.name");
    }

    public function getRecipeBasicData($idRecipe)
    {
        $sql = "SELECT
                pr.id,
                IF(pr.id_type = 3, fp.name, pr.name) as name,
                pr.description,
                pr.id_type,
                pr.is_subrecipe,
                pr.id_unit,
                prt.name as type_name,
                pr.yield_quantity       
                FROM product_recipe pr
                INNER JOIN product_recipe_type prt
                    ON pr.id_type = prt.id
                LEFT JOIN (SELECT id_recipe, name FROM final_product WHERE id_recipe IS NOT NULL) fp
                        ON pr.id = fp.id_recipe
                WHERE pr.id = :id_recipe";
        return $this->query($sql, ['id_recipe' => $idRecipe])[0];
    }

    public function getAllConnectedWithProduct()
    {
        $sql = "SELECT 
                        pr.id,
                        IF(pr.id_type = 3, fp.name, pr.name) as name,
                        pr.description,
                        prt.name as type_name,
                        pr.id_type
                    FROM product_recipe pr
                    INNER JOIN product_recipe_type prt
                        ON pr.id_type = prt.id
                    INNER JOIN (SELECT id_recipe, name FROM final_product WHERE id_recipe IS NOT NULL) fp
                        ON pr.id = fp.id_recipe
                    ORDER BY name";
        return $this->query($sql);
    }

    #region composition

    //get created compositions
    public function getCompositions()
    {
        $sql = "SELECT 
                        pr.id,
                        pr.name,
                        pr.description,
                        prt.name as type_name,
                        unit.name as unit_name
                    FROM product_recipe pr
                    INNER JOIN product_recipe_type prt
                        ON pr.id_type = prt.id
                    INNER JOIN unit
                    ON pr.id_unit = unit.id
                    WHERE id_type = 1
                    ORDER BY name";
        return $this->query($sql);
    }





    //get created semi-final products
    public function getSFP()
    {
        $sql = "SELECT 
                        pr.id,
                        pr.name,
                        pr.description,
                        prt.name as type_name,
                        unit.name as unit_name
                    FROM product_recipe pr
                    INNER JOIN product_recipe_type prt
                        ON pr.id_type = prt.id
                    INNER JOIN unit
                    ON pr.id_unit = unit.id
                    WHERE id_type = 2
                    ORDER BY name";
        return $this->query($sql);
    }




    public function getRecipeMaterials($idRecipe)
    {
        $sql = "SELECT 
                    prc.child_ingredient_id as id,
                    mat.name,
                    mat.id_unit,
                    unit.name as unit_name,
                    prc.quantity
                FROM product_recipe_connection prc
                INNER JOIN raw_material mat
                    ON prc.child_ingredient_id = mat.id
                INNER JOIN unit
                    ON mat.id_unit = unit.id
                WHERE prc.parent_recipe_id = :id_recipe AND child_ingredient_id IS NOT NULL";
        return $this->query($sql, ['id_recipe' => $idRecipe]);
    }

    public function getByRecipeType($idRecipe, $idType)
    {
        $sql = "SELECT 
                    prc.child_recipe_id as id,
                    pr.name,
                    prc.quantity,
                    pr.id_unit,
                    unit.name as unit_name,
                    TRIM(prc.quantity)+0 as quantity_in_recipe
                FROM product_recipe_connection prc
                INNER JOIN product_recipe pr
                    ON prc.child_recipe_id = pr.id
                INNER JOIN unit
                    ON pr.id_unit = unit.id
                WHERE prc.parent_recipe_id = :id_recipe AND pr.id_type = :id_type;";
        return $this->query($sql, ['id_recipe' => $idRecipe, 'id_type' => $idType]);
    }

    public function getRecipeCompositions($idRecipe)
    {
        return $this->getByRecipeType($idRecipe, 1);
    }

    public function getRecipeSFP($idRecipe)
    {
        return $this->getByRecipeType($idRecipe, 2);
    }



    public function getRecipePreparationType($idRecipe)
    {
        $sql = "SELECT 
                    prpc.id_preparation_type as id_preparation,
                    prt.name,
                    TRIM(prpc.duration_second)+0 as duration_second
                FROM product_recipe_preparation_connection prpc
                INNER JOIN product_preparation_type prt
                    ON prpc.id_preparation_type = prt.id
                WHERE prpc.id_recipe = :id_recipe";
        return $this->query($sql, ['id_recipe' => $idRecipe]);
    }

    public function getRecipeDataForCalculation($id_recipe)
    {
        $sql = "SELECT 
                    pr.id,
                    IFNULL(pr.name, prod.name) as name,
                    pr.is_subrecipe, 
                    pr.yield_quantity,
                    pr.id_unit,
                    unit.name as unit_name,
                    IFNULL(prod.ppc,0) as ppc
                    FROM product_recipe pr
                    LEFT JOIN final_product prod
                    on pr.id = prod.id_recipe
                    INNER JOIN unit 
                        ON pr.id_unit = unit.id
                    WHERE pr.id = :id_recipe";

        $res = $this->query($sql, ['id_recipe' => $id_recipe], 'assoc');
        if(!is_array($res) || count($res) == 0){
            return [];
        } else {
            return $res[0];
        }
    }

    public function getRecipeCalculation($id_recipe)
    {

        $data = $this->getRecipeDataForCalculation($id_recipe);

        if (empty($data)) return [];

        // visited set to guard against cycles
        $visited = [(int)$data['id'] => true];

        $data['elements'] = $this->buildRecipeStructure($data['id'], $data['yield_quantity'], $data['yield_quantity'], $visited);

        $this->addTotalCalculatedPrice($data['elements']);

        return $data;
    }





    /**
     * Buduje strukturę receptury (składniki + podreceptury) i liczy koszt pozycji.
     *
     * @param int $id_recipe
     * @param float|int $yield_quantity
     * @param float|int $required_quantity
     * @param array<int,bool> $visitedRecipeIds
     */
    public function buildRecipeStructure($id_recipe, $yield_quantity, $required_quantity, array $visitedRecipeIds = [])
    {
        $res_data = [];

        $data = $this->getRecipeData($id_recipe);


        if(!is_array($data)){
            return $res_data;
        }

        // Precyzja: ilości liczymy dokładniej, a zaokrąglenie „pieniężne” robimy na końcu.
        $qtyScale = 6;

        $yield_quantity = (float)$yield_quantity;
        $required_quantity = (float)$required_quantity;

        // yield 0 nie ma sensu; bezpieczeństwo: traktujemy jako 1, żeby nie dzielić przez 0.
        if ($yield_quantity <= 0) {
            $yield_quantity = 1.0;
        }

        foreach ($data as $item) {

            // Bezpieczne rozróżnienie: składnik vs podreceptura po kluczach dziecka.
            $isIngredient = !empty($item['child_ingredient_id']);
            $isSubRecipe  = !$isIngredient && !empty($item['child_recipe_id']);

            // quantity w połączeniu receptury (w jednostce bazowej dziecka)
            $itemQty = (float)($item['quantity'] ?? 0);
            if ($itemQty < 0) {
                // ujemne ilości nie mają sensu – pomijamy (alternatywa: wyjątek)
                continue;
            }

            $quantity_to_recipe = round((($required_quantity * $itemQty) / $yield_quantity), $qtyScale);

            if ($isIngredient) {
                $ppu = (float)($item['suggested_base_unit_price'] ?? 0);
                if ($ppu < 0) {
                    // ujemna cena nie ma sensu
                    $ppu = 0.0;
                }

                $ingredientPrice = $quantity_to_recipe * $ppu;

                $res_data[] = [
                    'id' => $item['child_ingredient_id'],
                    'ingredient_id' => $item['child_ingredient_id'],
                    'parent_id' => $id_recipe,
                    'recipe_id' => $id_recipe,
                    'name' => $item['ing_name'],
                    'type' => 'ingredient',
                    'quantity_in_recipe' => $itemQty,
                    'required_quantity' => $quantity_to_recipe,
                    'suggested_base_unit_price' => $ppu,
                    'id_unit' => $item['id_unit'],
                    'unit_name' => $item['unit_name'],
                    'price_for_required_quantity' => round($ingredientPrice, 2)
                ];
                continue;
            }

            if ($isSubRecipe) {
                $childId = (int)$item['child_recipe_id'];
                if ($childId <= 0) {
                    continue;
                }

                // Ochrona przed cyklem A->B->A.
                if (isset($visitedRecipeIds[$childId])) {
                    $res_data[] = [
                        'id' => $childId,
                        'ingredient_id' => $childId,
                        'name' => $item['ing_name'],
                        'parent_id' => $id_recipe,
                        'recipe_id' => $id_recipe,
                        'type' => 'sub-recipe',
                        'quantity_in_recipe' => $itemQty,
                        'required_quantity' => $quantity_to_recipe,
                        'yield_quantity' => (float)($item['yield_quantity'] ?? 0),
                        'id_unit' => $item['id_unit'],
                        'unit_name' => $item['unit_name'],
                        'elements' => [],
                        'cycle_detected' => 1,
                        'price_for_required_quantity' => 0.0,
                    ];
                    continue;
                }

                $nextVisited = $visitedRecipeIds;
                $nextVisited[$childId] = true;

                $childYield = (float)($item['yield_quantity'] ?? 0);
                if ($childYield <= 0) {
                    $childYield = 1.0;
                }

                $res_data[] = [
                    'id' => $childId,
                    'name' => $item['ing_name'],
                    'parent_id' => $id_recipe,
                    'type' => 'sub-recipe',
                    'quantity_in_recipe' => $itemQty,
                    'required_quantity' => $quantity_to_recipe,
                    'yield_quantity' => $childYield,
                    'id_unit' => $item['id_unit'],
                    'unit_name' => $item['unit_name'],
                    'elements' => $this->buildRecipeStructure($childId, $childYield, $quantity_to_recipe, $nextVisited)
                ];
            }
        }

        return $res_data;
    }



    public function getRecipeData($id_recipe)
    {
        $sql = "SELECT 
                    prc.parent_recipe_id,
                    prc.child_recipe_id,
                    prc.child_ingredient_id,
                    prc.quantity,
                    pr.yield_quantity,
                    IF(prc.child_ingredient_id IS NOT NULL,
                        mat.name,  
                        IF(pr.name IS NULL, prod.name, pr.name)) as ing_name,
                    mat.price_per_unit as suggested_base_unit_price,
                    IF(prc.child_ingredient_id IS NOT NULL, mat.id_unit, pr.id_unit) as id_unit,
                    IF(prc.child_ingredient_id IS NOT NULL, mat_unit.name, rec_unit.name) as unit_name,
                    IFNULL(pr.id_type, 0) as id_type
                   
                FROM product_recipe_connection prc
                LEFT JOIN product_recipe pr
                    ON prc.child_recipe_id = pr.id
                LEFT JOIN final_product prod
                    ON prc.child_recipe_id = prod.id_recipe
                LEFT JOIN raw_material mat
                    ON prc.child_ingredient_id = mat.id
                LEFT JOIN unit mat_unit
                    ON mat.id_unit = mat_unit.id    
                LEFT JOIN unit rec_unit
                    ON pr.id_unit = rec_unit.id
            
                WHERE parent_recipe_id = :id_parent_recipe
                GROUP BY child_recipe_id, child_ingredient_id
                                ORDER BY prc.child_recipe_id, prc.child_ingredient_id;";
        return $this->query($sql, ['id_parent_recipe' => $id_recipe], 'assoc');
    }

    function addTotalCalculatedPrice(&$elements) {
        foreach ($elements as &$element) {
            if (isset($element['type']) && $element['type'] == 'sub-recipe') {
                $this->addTotalCalculatedPrice($element['elements']);

                $sum = 0.0;
                foreach ($element['elements'] as $subElement) {
                    if (!array_key_exists('price_for_required_quantity', $subElement)) {
                        // Jeżeli czegoś brakuje, traktujemy jako 0, ale bez tłumienia błędów.
                        continue;
                    }
                    $sum += (float)$subElement['price_for_required_quantity'];
                }

                $element['price_for_required_quantity'] = round($sum, 2);
            }
        }
        unset($element);
    }
}

