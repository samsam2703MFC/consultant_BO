<?php

namespace App\Repositories\Document\Cmr;

use App\Core\Helpers\BaseRepository;
use App\Repositories\Document\DocumentRepository;

/**
 * ShipmentLogModel class
 */
class CMRRepository extends BaseRepository
{

    protected string $table = 'document_cmr';

    protected $allowedColumns = [
        'doc_num',
        'id_document_wz',
        'id_customer',
        'id_carrier',
        'id_carrier_sec',
        'consignor_name',
        'consignor_address_1',
        'consignor_address_2',
        'consignee_name',
        'consignee_address_1',
        'consignee_address_2',
        'delivery_place',
        'taking_over_place',
        'carrier_name',
        'carrier_address_1',
        'carrier_address_2',
        'carrier_regis_num',
        'id_carrier_vehicle',
        'carrier_sec_name',
        'carrier_sec_address_1',
        'carrier_sec_address_2',
        'carrier_sec_regis_num',
        'id_carrier_sec_vehicle',
        'sender_instructions',
        'established_in',
        'established_date'
    ];

    /**
     * Ostatni przewoźnik i pojazd użyty na CMR klienta (tylko aktywni przewoźnicy;
     * pojazd musi należeć do przewoźnika). Podpowiedź dla kreatora wysyłki.
     */
    public function getLastCarrierForCustomer(int $customerId): ?array
    {
        $sql = "SELECT cmr.id                       AS id_cmr,
                       cmr.established_date,
                       cmr.id_carrier,
                       c.name                       AS carrier_name,
                       v.id                         AS id_carrier_vehicle,
                       v.regis_num                  AS regis_num,
                       cs.id                        AS id_carrier_sec,
                       cs.name                      AS carrier_sec_name,
                       vs.id                        AS id_carrier_sec_vehicle,
                       vs.regis_num                 AS sec_regis_num
                FROM document_cmr cmr
                INNER JOIN carrier c ON c.id = cmr.id_carrier AND c.active = 1
                LEFT JOIN carrier_vehicle v ON v.id = cmr.id_carrier_vehicle AND v.id_carrier = cmr.id_carrier
                LEFT JOIN carrier cs ON cs.id = cmr.id_carrier_sec AND cs.active = 1
                LEFT JOIN carrier_vehicle vs ON vs.id = cmr.id_carrier_sec_vehicle AND vs.id_carrier = cmr.id_carrier_sec
                WHERE cmr.id_customer = :id_customer
                ORDER BY cmr.id DESC
                LIMIT 1";
        $rows = $this->query($sql, ['id_customer' => $customerId]);

        return !empty($rows[0]) ? $rows[0] : null;
    }

    public function insertSelect($data)
    {
        $sql = "INSERT INTO `document_cmr`(
                           `doc_num`, 
                           `id_document_wz`, 
                           `id_customer`, 
                           `id_carrier`, 
                           `id_carrier_sec`, 
                           `consignor_name`, 
                           `consignor_address_1`, 
                           `consignor_address_2`, 
                           `consignee_name`, 
                           `consignee_address_1`, 
                           `consignee_address_2`, 
                           `delivery_place`, 
                           `taking_over_place`, 
                           `carrier_name`, 
                           `carrier_address_1`, 
                           `carrier_address_2`, 
                           `carrier_regis_num`, 
                           `id_carrier_vehicle`, 
                           `carrier_sec_name`, 
                           `carrier_sec_address_1`, 
                           `carrier_sec_address_2`, 
                           `carrier_sec_regis_num`, 
                           `id_carrier_sec_vehicle`, 
                           `sender_instructions`, 
                           `carrier_reservation_observation`, 
                           `established_in`,
                           `established_date`) 
                        SELECT 
                            COUNT(doc_num)+1,
                            :id_document_wz,
                            :id_customer,
                            :id_carrier,
                            :id_carrier_sec,
                            :consignor_name,
                            :consignor_address_1,
                            :consignor_address_2,
                            :consignee_name,
                            :consignee_address_1,
                            :consignee_address_2,
                            :delivery_place,
                            :taking_over_place,
                            :carrier_name,
                            :carrier_address_1,
                            :carrier_address_2,
                            :carrier_regis_num,
                            :id_carrier_vehicle,
                            :carrier_sec_name,
                            :carrier_sec_address_1,
                            :carrier_sec_address_2,
                            :carrier_sec_regis_num,
                            :id_carrier_sec_vehicle,
                            :sender_instructions,
                            :carrier_reservation_observation,
                            :established_in,
                            :established_date
                        FROM document_cmr";
        return $this->customInsert($sql, $data);
    }

}