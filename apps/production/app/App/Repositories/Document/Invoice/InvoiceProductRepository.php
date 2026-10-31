<?php


namespace App\Repositories\Document\Invoice;
use App\Core\Helpers\BaseRepository;
use Tests\Fixtures\Model;

/**
 * Categort model class
 */
class InvoiceProductRepository extends BaseRepository
{

    protected string $table = 'document_invoice_product';

    protected $allowedColumns = [
        'id_document_invoice',
        'id_product',
        'product_name',
        'quantity',
        'single_price',
        'tva_perc',
        'single_weight',
        'ppc'
    ];

    public function insertSelect($data)
    {
        $sql = "INSERT INTO `document_invoice_product`(
                            `id_document_invoice`,
                            `id_product`,
                            `product_name`,
                            `quantity`,
                            `single_price`,
                            `tva_perc`,
                            `single_weight`,
                            `ppc`
                        )
                        SELECT
                            :id_document_invoice,
                            fp.id,
                            fp.name,
                            :quantity,
                            :single_price,
                            :tax_perc,
                            (fp.weight/1000),
                            :ppc
                        FROM final_product fp
                        WHERE fp.id = :id_product";
        return $this->query($sql, $data);
    }

    public function getByInvoiceId($id)
    {
        $sql = "SELECT * FROM $this->table WHERE id_document_invoice = :id ORDER BY product_name";
        return $this->query($sql, ['id' => $id]);
    }

}