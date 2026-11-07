<?php
namespace App\Repositories\Document\Wz;

use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class WZRepository extends BaseRepository
{

    protected string $table = 'document_wz';

    protected $allowedColumns = [
        'doc_num', 
        'id_customer', 
        'consignor_name', 
        'consignor_address_1', 
        'consignor_address_2', 
        'consignee_name', 
        'consignee_address_1', 
        'consignee_address_2', 
        'consignor_nip', 
        'consignor_bank_1', 
        'consignor_bank_2', 
        'consignor_swift', 
        'consignee_nip', 
        'established_date'
    ];

    public function insertSelect($data)
    {
        $sql = "INSERT INTO `document_wz`(
                          `doc_num`, 
                          `id_customer`, 
                          `consignor_name`, 
                          `consignor_address_1`, 
                          `consignor_address_2`, 
                          `consignee_name`, 
                          `consignee_address_1`, 
                          `consignee_address_2`, 
                          `consignor_nip`, 
                          `consignor_bank_1`, 
                          `consignor_bank_2`, 
                          `consignor_swift`, 
                          `consignee_nip`, 
                          `established_date`) 
                     SELECT 
                        	CONCAT('WZ-0', (SELECT COUNT(doc_num)+1 FROM document_wz), '-', DATE_FORMAT(NOW(), '%d/%m/%Y')),  
                        	:id_customer,
                        	:consignor_name, 
                            :consignor_address_1, 
                            :consignor_address_2, 
                            :consignee_name, 
                            :consignee_address_1, 
                            :consignee_address_2, 
                            :consignor_nip, 
                            :consignor_bank_1, 
                            :consignor_bank_2, 
                            :consignor_swift, 
                            :consignee_nip, 
                            :established_date";
        return $this->customInsert($sql, $data);
    }

}