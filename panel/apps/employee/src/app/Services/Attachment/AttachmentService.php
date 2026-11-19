<?php
namespace App\Employee\app\Services\Attachment;



use App\Employee\app\Repositories\Attachment\AttachmentRepository;
use App\Employee\app\Repositories\Task\TaskRepository;
use App\Employee\core\Support\GlobalRegistry;

class AttachmentService {


    public function __construct(private AttachmentRepository $attachmentRepository) {
    }

    public function getPresignedUrl($id)
    {
        return $this->attachmentRepository->getPresignedUrl($id);
    }
}