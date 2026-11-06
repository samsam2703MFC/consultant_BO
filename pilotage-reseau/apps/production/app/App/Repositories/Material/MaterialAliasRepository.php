<?php

namespace App\Repositories\Material;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class MaterialAliasRepository extends BaseRepository
{

    protected string $table = 'raw_material_alias';

    protected $allowedColumns = [
        'material_id',
        'lang_code',
        'alias',
        'alias_type',
    ];

    protected $fetchAllowedColumns = [
        'id',
        'material_id',
        'lang_code',
        'alias',
        'alias_type',
        'create_timestamp',
        'update_timestamp'
    ];

}