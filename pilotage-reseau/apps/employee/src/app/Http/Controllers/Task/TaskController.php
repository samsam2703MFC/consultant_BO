<?php

namespace App\Employee\app\Http\Controllers\Task;


use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Attachment\AttachmentService;
use App\Employee\app\Services\Task\TaskCompletionService;
use App\Employee\app\Services\Task\TaskService;
use App\Employee\core\Support\Route;

class TaskController extends Controller
{

    public function __construct(
        private TaskService $taskService,
        private TaskCompletionService $taskCompletionService,
        private AttachmentService $attachmentService,
    ) {}

    #[Route('GET', '/tasks')]
    public function index()
    {
        $data['tasks'] = $this->taskService->getForMe();

        $this->view("task/task", $data);
    }

    #[Route('GET', '/tasks/{id:\d+}')]
    public function taskOverview($id)
    {
        $tasks = $this->taskService->getForMe();
        $data['task'] = $this->taskService->filterById($id, $tasks);

        $this->view("task/task_overview", $data);
    }

    #[Route('GET', '/tasks/completion/{id:\d+}')]
    public function taskCompletionOverview($id)
    {
        $data['task_completion'] = $this->taskCompletionService->getById($id);


        if(!is_null($data['task_completion']->getAttachmentId())){
            $data['presigned_url'] = $this->attachmentService->getPresignedUrl($data['task_completion']->getAttachmentId());
        } else{
            $data['presigned_url'] = null;
        }

        $this->view("task/task_overview_done", $data);
    }

    #[Route('POST', '/tasks/{id:\d+}')]
    public function markAsDoneTask($id)
    {
        $tasks = $this->taskService->getForMe();
        $data['task'] = $this->taskService->filterById($id, $tasks);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            // Safety net (na wypadek braku hiddenów / custom requestów)
            $_POST['task_id'] = $_POST['task_id'] ?? (int)$id;
            $_POST['status'] = $_POST['status'] ?? 'DONE';
            $_POST['scheduled_for_date'] = $_POST['scheduled_for_date'] ?? date('Y-m-d');
            $_POST['scheduled_time'] = $_POST['scheduled_time'] ?? ($data['task']?->getExecutionTime() ?: '00:00:00');

            // ----------------------------
            // PHOTO VALIDATION (MVP)
            // ----------------------------
            $requiresPhoto = (bool)($data['task']?->getRequiresPhoto());

            if ($requiresPhoto) {

                // 1) Czy w ogóle przyszedł plik?
                if (!isset($_FILES['photo'])) {
                    $this->errors['photo_upload'] = "Photo is required.";
                    $this->view("task/task_overview", $data);
                    return;
                }

                $file = $_FILES['photo'];
                $err  = $file['error'] ?? UPLOAD_ERR_NO_FILE;

                // 2) Błędy uploadu (np. za duży plik)
                if ($err !== UPLOAD_ERR_OK) {
                    $maxMb = 10;

                    $msg = match ($err) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Photo is too large. Max size is {$maxMb}MB.",
                        UPLOAD_ERR_PARTIAL => "Photo upload was interrupted. Please try again.",
                        UPLOAD_ERR_NO_FILE => "Photo is required.",
                        UPLOAD_ERR_NO_TMP_DIR => "Server error: missing temp directory.",
                        UPLOAD_ERR_CANT_WRITE => "Server error: cannot save the file.",
                        UPLOAD_ERR_EXTENSION => "Upload blocked by server configuration.",
                        default => "Photo upload failed.",
                    };

                    $this->errors['photo_upload'] = $msg;
                    $this->view("task/task_overview", $data);
                    return;
                }

                // 3) Dodatkowy limit po stronie aplikacji (nawet jeśli php.ini pozwala więcej)
                $maxBytes = 10 * 1024 * 1024; // 10MB
                $size = (int)($file['size'] ?? 0);

                if ($size <= 0) {
                    $this->errors['photo_upload'] = "Photo upload failed (empty file).";
                    $this->view("task/task_overview", $data);
                    return;
                }

                if ($size > $maxBytes) {
                    $this->errors['photo_upload'] = "Photo is too large. Max size is 10MB.";
                    $this->view("task/task_overview", $data);
                    return;
                }

                // 4) Minimalna sanity-check: czy to jest upload przez HTTP
                $tmp = $file['tmp_name'] ?? '';
                if (!$tmp || !is_uploaded_file($tmp)) {
                    $this->errors['photo_upload'] = "Photo upload failed (invalid upload).";
                    $this->view("task/task_overview", $data);
                    return;
                }
            }

            $res = $this->taskService->markAsDone($id, $_POST, $_FILES ?? []);
            if (!empty($res['success'])) {
                redirect('/tasks');
                return;
            }

            $this->errors["task_complete_failed"] = $res['description'] ??  "Failed to mark task as done.";
        }

        $this->view("task/task_overview", $data);
    }
}