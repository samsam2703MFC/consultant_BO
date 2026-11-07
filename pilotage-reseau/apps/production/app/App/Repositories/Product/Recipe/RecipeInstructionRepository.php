<?php
namespace App\Repositories\Product\Recipe;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class RecipeInstructionRepository extends BaseRepository
{

    protected string $table = 'product_recipe_prepare_instruction_connection';

    protected $allowedColumns = [

    ];

    protected $fetchAllowedColumns = [

    ];

    public function getInstruction($data)
    {
        $sql = "SELECT
                        id,
                        prepare_step_desc,
                        duration
                    FROM product_recipe_prepare_instruction_connection
                    WHERE id_recipe = :id_recipe
                    ORDER BY prepare_step_num";
        return $this->query($sql, $data);
    }




    public function validate($data)
    {
        $this->errors = [];

        if(empty($data['name']))
        {
            $this->errors['name'] = "Wprowadź nazwę";
        }

        if(empty($data['weight']))
        {
            $this->errors['weight'] = "Wprowadź wagę";
        }

        if(empty($data['ppc']))
        {
            $this->errors['ppc'] = "Podaj ilość sztuk w kartonie";
        }
        if(empty($data['ppb']))
        {
            $this->errors['ppb'] = "Podaj ilość sztuk w opakowaniu";
        }
        if(empty($data['bpc']))
        {
            $this->errors['bpc'] = "Podaj ilość opakowań w kartonie";
        }
        if(empty($data['cpl']))
        {
            $this->errors['cpl'] = "Podaj ilość kartonów w jednej warstwie";
        }
        if(empty($data['lpp']))
        {
            $this->errors['lpp'] = "Podaj ilość warstw na palecie";
        }
        if(empty($data['cpp']))
        {
            $this->errors['cpp'] = "Podaj ilość kartonów na palecie";
        }

        if(empty($data['id_category']) || $data['id_category'] === "-1")
        {
            $this->errors['id_category'] = "Wybierz kategorię";
        }

        if(empty($data['id_section']) || $data['id_section'] === "-1")
        {
            $this->errors['id_category'] = "Wybierz dział produkcyjny";
        }

        if(empty($this->errors))
        {
            return true;
        }

        return false;
    }

}