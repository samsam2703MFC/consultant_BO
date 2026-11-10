<?php
namespace App\Repositories\Shipping\Carrier;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class ProductCarrierRepository extends BaseRepository
{



    protected string $table = 'carrier';

    protected $allowedColumns = [
        'name',
        'street',
        'street_num',
        'zip',
        'town',
        'phone',
        'email',
        'active',
    ];


    public function getActive()
    {
        $sql = "SELECT * FROM $this->table WHERE active = 1 ORDER BY name";
        return $this->query($sql);
    }



}