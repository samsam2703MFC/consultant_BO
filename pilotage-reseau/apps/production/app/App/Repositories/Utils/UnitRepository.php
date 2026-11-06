<?php
namespace App\Repositories\Utils;

use App\Core\Helpers\BaseRepository;


/**
 * Categort model class
 */
class UnitRepository extends BaseRepository
{
    protected string $table = 'unit';

    protected $allowedColumns = [
        'id',
        'name'
    ];

    public function getSmallUnit()
    {
        $sql = "SELECT id, name FROM unit WHERE id=3 OR id=4 OR id=5";
        return $this->query($sql);
    }

}