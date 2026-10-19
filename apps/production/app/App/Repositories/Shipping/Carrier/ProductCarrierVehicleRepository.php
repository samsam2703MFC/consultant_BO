<?php
namespace App\Repositories\Shipping\Carrier;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class ProductCarrierVehicleRepository extends BaseRepository
{


    protected string $table = 'carrier_vehicle';

    protected $allowedColumns = [
        'id_carrier',
        'regis_num',
        'driver_name',
        'driver_surname',
        'driver_phone',
        'driver_email'
    ];

    public function getByIdCarrier($idCarrier)
    {
        $sql = "SELECT * FROM $this->table WHERE id_carrier = :id_carrier ORDER BY regis_num";
        return $this->query($sql, ['id_carrier' => $idCarrier]);
    }



}