<?php

use App\Repositories\Client\ClientRepository;
use App\Repositories\Document\DocumentRepository;
use App\Repositories\Product\Category\CategoryProductRepository;
use App\Repositories\Product\Price\PriceListRepository;
use App\Repositories\Production\ProductionRepository;

/**
 * product class
 */
class Document
{
    use Controller;

    public function index()
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $categoryProductModel = new CategoryProductRepository();
        $data['action'] = '';
        $data['rows'] = $categoryProductModel->getExistedCategoryInProductList();
        $this->view('document', $data);
    }

    public function panel($action = null, $data = [])
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $documentModel = new DocumentRepository();
        $data['action'] = $action;

        if($action == "standard")
        {

        }else if($action == "palette"){

        }else if($action == "wdt"){

        }

        $data['errors'] = $documentModel->errors;
        $this->view('document', $data);
    }

    public function generate($action = null, $data = [])
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $data['action'] = $action;

        if($action == "cmr")
        {

        }else if($action == "palette"){

        }else if($action == "wdt"){

        }else if($action == "stock_last_day_in_month"){
            $stockModel = new StockModel();
            $stockModel->getCurrentStockListPDF();

        }else if($action == "missing_recipe"){
            $recipeModel = new RecipeModel();
            $recipeModel->getMissingRecipePDF();

        }else if($action == "production_list"){
            $productionModel = new ProductionRepository();
            $productionModel->getProductionListPDF($data);
        }else{
            $this->view('document', $data);
        }

        //echo $pdf;
    }

    public function price_list($action = null, $id = null)
    {
        $userModel = new UserModel();
        if(!$userModel->logged_in())
            redirect('login');

        $priceListModel = new PriceListRepository();
        $customerModel = new ClientRepository();

        $customerData = $customerModel->first(['id' => $id]);

        if($action == "franchisee"){
            $priceListModel->getPriceList(['customer_data' => $customerData]);
        }
    }

}
