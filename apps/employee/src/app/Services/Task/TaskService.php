<?php
namespace App\Employee\app\Services\Task;



use App\Employee\app\Repositories\Task\TaskRepository;
use App\Employee\core\Support\GlobalRegistry;

class TaskService {


    public function __construct(private TaskRepository $taskRepository) {
    }

    public function getForMe()
    {
        return $this->taskRepository->getByEmployee(GlobalRegistry::get('user')->getId());
    }

    public function filterById($id, $tasks)
    {
        foreach ($tasks as $task) {
            if($task->getId() == $id) {
                return $task;
            }
        }
        return null;
    }

    public function markAsDone($taskId, $post, $files = [])
    {
        $employeeId = GlobalRegistry::get('user')->getId();
        $post['employee_id'] = $employeeId;
        $post['task_id'] = $post['task_id'] ?? (int)$taskId;
        return $this->taskRepository->markAsDone($employeeId, $taskId, $post, $files);
    }

}