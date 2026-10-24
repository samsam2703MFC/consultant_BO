<?php
namespace App\Repositories\Material\Purchase;

use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class DocumentItemRepository extends BaseRepository
{

    protected string $table = 'purchase_item';

    protected $allowedColumns = [
        'purchase_document_id',
        'raw_material_id',
        'qty_base',
        'unit_price_net',
        'line_total_net',
        'package_size_base',
        'package_price_net',
        'vat_rate',
    ];

    protected $fetchAllowedColumns = [
        'id',
        'purchase_document_id',
        'raw_material_id',
        'qty_base',
        'unit_price_net',
        'line_total_net',
        'package_size_base',
        'package_price_net',
        'vat_rate',
        'created_at'
    ];

    public function getByDocumentId($id)
    {
        $sql = "SELECT
                    pi.*,
                    mat.name as raw_material_name
                    FROM {$this->table} pi
                    INNER JOIN raw_material mat
                    ON pi.raw_material_id = mat.id
                    WHERE pi.purchase_document_id = :id";
        return $this->query($sql, ['id' => $id]);
    }

}