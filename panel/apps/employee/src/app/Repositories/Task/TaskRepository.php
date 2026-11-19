<?php
namespace App\Employee\app\Repositories\Task;


use App\Employee\app\Models\Task\TaskModel;
use App\Employee\core\Http\ApiClient;

class TaskRepository {
    private $apiClient;

    public function __construct(ApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    public function getByEmployee($id): array {

        $response = $this->apiClient->get("/employees/$id/tasks");

        $objects = [];

        if(isset($response['data'])){
            foreach ($response['data'] as $objectData) {
                $objects[] = new TaskModel($objectData);
            }
        }

        return $objects;
    }

    public function markAsDone($employeeId, $taskId, $data, $files = [])
    {
        return $this->apiClient->postMultipart("/employees/$employeeId/tasks/$taskId/mark-as-done", $data, $files);
    }

}