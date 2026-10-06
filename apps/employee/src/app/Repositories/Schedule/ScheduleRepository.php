<?php
namespace App\Employee\app\Repositories\Schedule;


use App\Employee\app\Models\Schedule\ScheduleModel;
use App\Employee\core\Http\ApiClient;

class ScheduleRepository {
    private $apiClient;

    public function __construct(ApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    public function getByEmployee($id, $dateFrom = null, $dateTo = null): array {

        $queryStr = http_build_query(['start_date' => $dateFrom, 'end_date' => $dateTo]);

        $response = $this->apiClient->get("/employees/$id/schedule?{$queryStr}");

        $objects = [];

        if(isset($response['data'])){
            foreach ($response['data'] as $objectData) {
                $objects[] = new ScheduleModel($objectData);
            }
        }

        return $objects;
    }




}