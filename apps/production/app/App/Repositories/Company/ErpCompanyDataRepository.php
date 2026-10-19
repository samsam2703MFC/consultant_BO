<?php

namespace App\Repositories\Company;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class ErpCompanyDataRepository extends BaseRepository
{

    protected string $table = 'erp_company_data';

    protected $allowedColumns = [
        'wz_company_name',
        'wz_company_address_1',
        'wz_company_address_2',
        'wz_company_nip',
        'wz_company_bank_1',
        'wz_company_bank_2',
        'wz_company_swift',
        'cmr_company_name',
        'cmr_company_address_1',
        'cmr_company_address_2',
        'cmr_taking_over_place',
        'cmr_established_in',
        'cmr_carrier_reservation_observation',
        'cmr_sender_instruction'
    ];


}