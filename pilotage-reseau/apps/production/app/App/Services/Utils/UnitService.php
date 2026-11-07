<?php
namespace App\Services\Utils;

use App\Repositories\Product\Recipe\RecipeRepository;
use App\Repositories\Utils\UnitRepository;

class UnitService
{
    private UnitRepository $unitRepository;

    public function __construct(
        UnitRepository $unitRepository
    )
    {
        $this->unitRepository = $unitRepository;
    }

    public function getSmallUnit()
    {
        return  $this->unitRepository->getSmallUnit();
    }


}