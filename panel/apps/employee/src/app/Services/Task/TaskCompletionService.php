<?php
namespace App\Employee\app\Services\Task;



use App\Employee\app\Repositories\Task\TaskCompletionRepository;
use App\Employee\app\Repositories\Task\TaskRepository;
use App\Employee\core\Support\GlobalRegistry;

class TaskCompletionService {


    public function __construct(private TaskCompletionRepository $taskCompletionRepository) {
    }

    public function getById($id)
    {
        return $this->taskCompletionRepository->getById($id);
    }


}