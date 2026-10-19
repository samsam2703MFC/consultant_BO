<?php
namespace App\Repositories\Supplier;

use App\Core\Helpers\BaseRepository;

/**
 * Categort model class
 */
class MaterialSupplierRepository extends BaseRepository
{

    protected string $table = 'supplier';

    protected $allowedColumns = [
        'name',
        'phone',
        'email',
        'is_active'
    ];

    public function getActive()
    {
        $sql = "SELECT id, name, phone, email, is_active FROM $this->table WHERE is_active = 1 ORDER BY name";
        return $this->query($sql);
    }



}