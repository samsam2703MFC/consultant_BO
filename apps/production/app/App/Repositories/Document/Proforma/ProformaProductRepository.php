<?php

namespace App\Repositories\Document\Proforma;

use App\Models\ApiCURL;
use App\Core\Helpers\BaseRepository;

/**
 * User class
 */
class ProformaProductRepository extends BaseRepository
{
    protected string $table = 'document_proforma_product';
    protected $allowedColumns = [
        'id_proforma',
        'id_product',
        'product_name',
        'code_stat',
        'quantity',
        'price_per_carton',
        'total_price_net',
        'tva',
        'price_brut',
        'tva_perc',
        'single_carton_weight_kg',
        'total_weight_kg',
        'ppc',
        'price_per_piece'
    ];

    protected $fetchAllowedColumns = [
        'id_proforma',
        'id_product',
        'product_name',
        'code_stat',
        'quantity',
        'price_per_carton',
        'total_price_net',
        'tva',
        'price_brut',
        'tva_perc',
        'single_carton_weight_kg',
        'total_weight_kg',
        'ppc',
        'price_per_piece'
    ];

    public $errors = [];

    public function insertFromOrder($idProforma, $idOrder, bool $isFreebie = false)
    {
        if ($isFreebie) {
            $pricePerCarton    = '0';
            $totalPriceNet     = '0';
            $priceBrut         = '0';
            $pricePerPiece     = '0';
        } else {
            $pricePerCarton    = 'cop.purchase_price';
            $totalPriceNet     = 'TRUNCATE((cop.quantity_carton * cop.purchase_price),2)';
            $priceBrut         = 'null';
            $pricePerPiece     = 'TRUNCATE(cop.purchase_price/fp.ppc, 2)';
        }

        $sql = "INSERT INTO `document_proforma_product`(
                        `id_proforma`,
                        `id_product`,
                        `product_name`,
                        `code_stat`,
                        `quantity`,
                        `price_per_carton`,
                        `total_price_net`,
                        `tva`,
                        `price_brut`,
                        `tva_perc`,
                        `single_carton_weight_kg`,
                        `total_weight_kg`,
                        `ppc`,
                        `price_per_piece`
                    )
                    SELECT
                        :id_proforma,
                        fp.id as id_product,
                        fp.name as product_name,
                        '19059090',
                        cop.quantity_carton,
                        {$pricePerCarton},
                        {$totalPriceNet} as total_carton_price,
                        '0',
                        {$priceBrut},
                        '0',
                        TRUNCATE(fp.weight/1000, 2) as single_weight,
                        TRUNCATE(((fp.ppc*fp.weight)*cop.quantity_carton)/1000, 2) as sum_weight,
                        fp.ppc,
                        {$pricePerPiece}
                FROM customer_order_product cop
                INNER JOIN final_product fp
                    ON cop.id_product = fp.id
                WHERE cop.id_order = :id_order
                ORDER BY fp.name";
        return $this->query($sql, [
            ':id_proforma' => $idProforma,
            ':id_order' => $idOrder
        ]);
    }

    public function getByIdProforma($id)
    {
        $sql = "SELECT * FROM `document_proforma_product` WHERE `id_proforma` = :id_proforma ORDER BY product_name";
        return $this->query($sql, [':id_proforma' => $id]);
    }
}