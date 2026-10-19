<?php
namespace App\Services\Utils;

use App\Repositories\Company\CompanyCostRepository;

class CompanyCostService
{
    private CompanyCostRepository $companyCostRepository;

    public function __construct(
        CompanyCostRepository $companyCostRepository
    )
    {
        $this->companyCostRepository = $companyCostRepository;
    }


    public function update($id, $data)
    {
        return $this->companyCostRepository->update($id,$data);
    }
}