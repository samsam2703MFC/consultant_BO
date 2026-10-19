<?php

namespace App\Repositories\Company;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class CompanyCostRepository extends BaseRepository
{


    protected string $table = 'company_cost';

    protected $allowedColumns = [
        'depreciation',
        'water',
        'power_supply',
        'other'
    ];

    protected $fetchAllowedColumns = [

    ];
}