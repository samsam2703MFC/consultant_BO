<?php

namespace App\Repositories\Utils;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class CountryRepository extends BaseRepository
{

    protected string $table = 'country';

    protected $allowedColumns = [
        'name'
    ];


}