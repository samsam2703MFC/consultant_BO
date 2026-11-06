<?php
namespace App\Repositories\Product\Test;


use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class TestProductStatusRepository extends BaseRepository
{
    protected string $table = 'test_product_status';

    protected $allowedColumns = [
        'name',
    ];

}