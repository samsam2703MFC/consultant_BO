<?php
namespace App\Services\Shipping\Utils;

use App\Repositories\Shipping\Utils\MissingProductReasonRepository;

class MissingProductReasonService
{
    private MissingProductReasonRepository $missingProductReasonRepository;

    public function __construct(MissingProductReasonRepository $missingProductReasonRepository)
    {
        $this->missingProductReasonRepository = $missingProductReasonRepository;
    }

    public function getAll()
    {
        return $this->missingProductReasonRepository->findAll();
    }
}