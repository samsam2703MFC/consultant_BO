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

    /**
     * Le PIN de caisse (30/10/2026) : le panel le garde dans franchisee_employee.pin, que PATCH /employees/{id} accepte
     * avec le jeton de la personne. Rend la réponse d'ApiClient::patch (success, code, description).
     */
    public function changerPin(int $id, string $pin): array
    {
        return $this->apiClient->patch("/employees/{$id}", ['pin' => $pin]);
    }




}