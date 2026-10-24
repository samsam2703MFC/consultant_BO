<?php
namespace App\Repositories\Material\Price;


use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class MaterialPriceChangelogRepository extends BaseRepository
{

    protected string $table = 'raw_material_price_changelog';

    protected $allowedColumns = [
        'id_supplier',
        'id_raw_material',
        'pack_price',
        'package_size_in_base_unit'
    ];

    protected $fetchAllowedColumns = [

    ];

}