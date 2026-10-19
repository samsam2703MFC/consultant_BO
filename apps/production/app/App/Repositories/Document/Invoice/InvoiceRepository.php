<?php

namespace App\Repositories\Document\Invoice;

use App\Core\Helpers\BaseRepository;
use MYPDFModel;
use Tests\Fixtures\Model;


/**
 * Categort model class
 */
class InvoiceRepository extends BaseRepository
{

    protected string $table = 'document_invoice';

    protected $allowedColumns = [
        'id',
        'doc_num',
        'id_customer',
        'consignor_name',
        'consignor_address_1',
        'consignor_address_2',
        'consignor_nip',
        'consignor_bank',
        'consignor_swift',
        'consignee_name',
        'consignee_address_1',
        'consignee_address_2',
        'consignee_nip',
        'invoiced_date',
        'create_timestamp'
    ];

    public function getInvoiceNumer()
    {
        $sql = "SELECT CONCAT('FAC-', (SELECT RIGHT(CONCAT('00000', 
                            (SELECT COUNT(doc_num)+1 FROM document_invoice WHERE YEAR(NOW()) = YEAR(create_timestamp))), 5)), 
                            '/', 
                            YEAR(NOW())) as inv_num;";
        return $this->query($sql);
    }

    public function getExisted()
    {
        $sql = "SELECT 
                       di.id,
                       di.doc_num,
                       cust.company, 
                       di.create_timestamp 
                FROM document_invoice di
                INNER JOIN customer cust on di.id_customer = cust.id 
                ORDER BY di.create_timestamp DESC";
        return $this->query($sql);
    }


}