<?php
namespace App\Services\Utils;

use App\Repositories\Utils\CountryRepository;

class CountryService
{
    private CountryRepository $countryRepository;

    public function __construct(
        CountryRepository $countryRepository
    )
    {
        $this->countryRepository = $countryRepository;
    }

    public function getAll()
    {
        return $this->countryRepository->findAll();
    }
}