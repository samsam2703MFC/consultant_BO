<?php
namespace App\Repositories\Client\FranchiseNetwork;
use App\Core\Helpers\BaseRepository;
/**
 * Categort model class
 */
class FranchiseNetworkRepository extends BaseRepository
{
    protected string $table = 'customer_franchise_network';
    protected $allowedColumns = [
        'code',
        'name',
        'contact_email',
        'contact_phone'
    ];

    protected $fetchAllowedColumns = [
        'id',
        'code',
        'name',
        'contact_email',
        'contact_phone',
        'created_at'
    ];

    public function getAllWithShopQuantity()
    {
        $sql = "SELECT cfn.*, (SELECT COUNT(id) FROM customer WHERE franchise_network_id = cfn.id) as qty_shop FROM $this->table cfn";
        return $this->query($sql);
    }
}