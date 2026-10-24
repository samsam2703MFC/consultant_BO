<?php
namespace App\Repositories\Shipping\Utils;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class MissingProductReasonRepository extends BaseRepository
{

    protected string $table = 'missig_product_reason';

    protected $allowedColumns = [
        'name'
    ];


}