<?php
namespace App\Services\Product\Test;

use App\Repositories\Product\Test\TestProductRepository;

class TestProductService
{
    private TestProductRepository $testProductRepository;

    public function __construct(
        TestProductRepository $testProductRepository
    )
    {
        $this->testProductRepository = $testProductRepository;
    }

    public function validate($data)
    {
        $errors = [];

        if(empty($data['name']))
        {
            $errors['name'] = "Wprowadź nazwę";
        }


        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function insert($data)
    {
        return $this->testProductRepository->insert($data);
    }

    public function getById($id)
    {
        return $this->testProductRepository->first(['id' => $id]);
    }

    public function update($id, $data)
    {
        return $this->testProductRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->testProductRepository->delete($id);
    }

    public function findAll()
    {
        return $this->testProductRepository->getAll();
    }
}