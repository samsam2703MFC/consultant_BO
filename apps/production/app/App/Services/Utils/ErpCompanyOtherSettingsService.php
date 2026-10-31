<?php
namespace App\Services\Utils;

use App\Repositories\Company\ErpCompanyOtherSettingsRepository;

class ErpCompanyOtherSettingsService
{
    private ErpCompanyOtherSettingsRepository $erpCompanyOtherSettingsRepository;

    public function __construct(
        ErpCompanyOtherSettingsRepository $erpCompanyOtherSettingsRepository
    ) {
        $this->erpCompanyOtherSettingsRepository = $erpCompanyOtherSettingsRepository;
    }

    public function getById($id)
    {
        return $this->erpCompanyOtherSettingsRepository->first(['id' => $id]);
    }
}