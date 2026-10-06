<?php
namespace App\Employee\app\Repositories\Employee;


use App\Employee\app\Models\Employee\EmployeeModel;
use App\Employee\app\Models\Schedule\ScheduleModel;
use App\Employee\core\Http\ApiClient;

class EmployeeRepository {
    private $apiClient;

    public function __construct(ApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    public function getById($id, $include = []): ?EmployeeModel {
        $queryStr = "";
        if(!empty($include)) {
            $includeStr = implode(',', $include);
            $queryStr .= ($queryStr === "" ? "?" : "&") . "include=" . urlencode($includeStr);
        }
        $response = $this->apiClient->get("/employees/{$id}{$queryStr}");
        return $response['data'] ? new EmployeeModel($response['data']) : null;
    }




}