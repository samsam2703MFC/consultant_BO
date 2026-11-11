<?php
namespace App\Employee\app\Repositories\Task;


use App\Employee\app\Models\Task\TaskCompletionModel;
use App\Employee\app\Models\Task\TaskModel;
use App\Employee\core\Http\ApiClient;

class TaskCompletionRepository {
    private $apiClient;

    public function __construct(ApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    public function getById($id)
    {
        $response = $this->apiClient->get("/tasks/completions/$id");

        return $response['data'] ? new TaskCompletionModel($response['data']) : null;
    }

}