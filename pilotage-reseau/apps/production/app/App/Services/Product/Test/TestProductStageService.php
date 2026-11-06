<?php
namespace App\Services\Product\Test;

use App\Repositories\Product\Test\TestProductChangelogRepository;
use App\Repositories\Product\Test\TestProductRepository;
use App\Repositories\Product\Test\TestProductStageRepository;
use App\Repositories\Product\Test\TestProductStatusRepository;

class TestProductStageService
{
    private TestProductStageRepository $testProductStageRepository;

    public function __construct(
        TestProductStageRepository $testProductStageRepository
    )
    {
        $this->testProductStageRepository = $testProductStageRepository;
    }

    public function findAll()
    {
        return $this->testProductStageRepository->findAll();
    }

    public function validate($data)
    {
        $errors = [];

        if(empty($data['name']))
        {
            $errors['missing_name'] = "Wprowadź nazwę";
        }


        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function insert($data)
    {
        return $this->testProductStageRepository->insert($data);
    }

    public function delete($id)
    {
        return $this->testProductStageRepository->delete($id);
    }

    public function update($id, $data)
    {
        return $this->testProductStageRepository->update($id, $data);
    }

    public function updatePriority($data)
    {
        return $this->testProductStageRepository->updatePriority($data);
    }

    public function getById($id)
    {
        return $this->testProductStageRepository->first(['id' => $id]);
    }
}