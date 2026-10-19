<?php

use App\Repositories\Document\Product\ProductCardRepository;
use App\Repositories\Product\Category\CategoryProductRepository;
use App\Repositories\Product\FinalProductRepository;

/**
 * product class
 */
class ProductCard
{
    use Controller;

    public function index()
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $categoryProductModel = new CategoryProductRepository();
        $data['action'] = '';
        $data['rows'] = $categoryProductModel->getExistedCategoryInProductList();
        $this->view('final_products', $data);
    }

    public function display($id)
    {
        $userModel = new UserModel();
        if (!$userModel->logged_in())
            redirect('login');

        $productCardModel = new ProductCardRepository();
        $finalProductModel = new FinalProductRepository();

        $data = [];

        $data['particular'] = $finalProductModel->getById(["id_product" => $id])[0];
        $productCardModel->getProductCard($data);

    }

}