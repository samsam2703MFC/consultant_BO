<?php
namespace App\Employee\app\Repositories\Shop;


use App\Employee\app\Models\Employee\EmployeeModel;
use App\Employee\app\Models\Schedule\ScheduleModel;
use App\Employee\app\Models\Shop\ShopModel;
use App\Employee\core\Http\ApiClient;

class ShopRepository {
    private $apiClient;

    public function __construct(ApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    public function getById($id): ?ShopModel {
        $response = $this->apiClient->get("/shops/$id");
        return $response['data'] ? new ShopModel($response['data']) : null;
    }




}