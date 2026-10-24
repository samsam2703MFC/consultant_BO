<?php
namespace App\Services\Product\Test;

use App\Repositories\Product\Test\TestProductChangelogRepository;
use App\Repositories\Product\Test\TestProductRepository;
use App\Repositories\Product\Test\TestProductStatusRepository;

class TestProductChangelogService
{
    private TestProductChangelogRepository $testProductChangelogRepository;

    public function __construct(
        TestProductChangelogRepository $testProductChangelogRepository
    )
    {
        $this->testProductChangelogRepository = $testProductChangelogRepository;
    }

    public function validateInsert($data)
    {
        $errors = [];

        if(empty($data['id_test_product']))
        {
            $errors['missing_id'] = "Wystąpił błąd. Brak id.";
        }

        if(empty($data['description']))
        {
            $errors['description'] = "Wprowadź komentarz";
        }

        if(empty($data['deadline']))
        {
            $errors['deadline'] = "Wprowadź termin ostateczny";
        }


        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function validate($data)
    {
        $errors = [];

        if(empty($data['id']))
        {
            $errors['missing_id'] = "Wystąpił błąd. Brak id.";
        }

        if(empty($data['description']))
        {
            $errors['description'] = "Wprowadź komentarz";
        }

        if(empty($data['deadline']))
        {
            $errors['deadline'] = "Wprowadź termin ostateczny";
        }


        if(empty($errors))
        {
            return true;
        }

        return false;
    }

    public function insert($data)
    {
        return $this->testProductChangelogRepository->insert($data);
    }

    public function getById($id)
    {
        return $this->testProductChangelogRepository->first(['id' => $id]);
    }

    public function update($id, $data)
    {
        return $this->testProductChangelogRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->testProductChangelogRepository->delete($id);
    }

    public function selectChangelogForProduct($idProduct)
    {
        return $this->testProductChangelogRepository->selectChangelogForProduct($idProduct);
    }
}