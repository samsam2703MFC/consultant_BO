<?php
namespace App\Repositories\Production\Cost;

use App\Core\Helpers\BaseRepository;

class FinalProductRepository extends BaseRepository
{
    protected string $table = 'final_product';

    public function getActiveForCosting(): array
    {
        $sql = "
            SELECT
                fp.id,
                fp.name,
                fp.ppc,
                fp.id_section,
                ps.name as section_name,
                fp.standard_price,
                fp.pre_calculated_raw_price_per_carton
            FROM final_product fp
            INNER JOIN production_sections ps
            ON fp.id_section = ps.id
            WHERE fp.active = 1
            ORDER BY fp.id_section ASC, fp.name ASC
        ";
        return $this->query($sql) ?: [];
    }
}
