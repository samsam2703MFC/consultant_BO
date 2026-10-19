<?php
namespace App\Services\Material;

use App\Repositories\Material\Category\CategoryMaterialRepository;
use App\Repositories\Material\MaterialRepository;

class MaterialCategoryService
{
    private CategoryMaterialRepository $categoryMaterialRepository;

    public function __construct(
        CategoryMaterialRepository $categoryMaterialRepository
    )
    {
        $this->categoryMaterialRepository = $categoryMaterialRepository;
    }

    public function getAll()
    {
        return  $this->categoryMaterialRepository->findAll();
    }

    public function getUsed()
    {
        return  $this->categoryMaterialRepository->getExistedCategoryInMaterialList();
    }

    public function create(array $data)
    {
        return $this->categoryMaterialRepository->insert($data);
    }

    public function update($id, array $data)
    {
        return $this->categoryMaterialRepository->update($id, $data);
    }

    public function getById($id)
    {
        return $this->categoryMaterialRepository->first(['id' => $id]);
    }
}