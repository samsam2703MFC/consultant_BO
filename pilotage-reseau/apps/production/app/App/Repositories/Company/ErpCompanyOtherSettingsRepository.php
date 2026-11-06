<?php

namespace App\Repositories\Company;
use App\Core\Helpers\BaseRepository;

/**
 * ShipmentLogModel class
 */
class ErpCompanyOtherSettingsRepository extends BaseRepository
{

    protected string $table = 'erp_company_other_settings';

    protected $allowedColumns = [
        'exchange_rate_euro'
    ];

    public function getExchangeRateEuro()
    {
        return $this->first(['id' => 1])['exchange_rate_euro'];
    }
}