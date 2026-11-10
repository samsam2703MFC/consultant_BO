<?php
namespace App\Employee\app\Repositories\Attachment;


use App\Employee\app\Models\Attachment\AttachmentMetaModel;
use App\Employee\app\Models\Attachment\AttachmentPresignedUrlModel;
use App\Employee\core\Http\ApiClient;

class AttachmentRepository {
    private $apiClient;

    public function __construct(ApiClient $apiClient) {
        $this->apiClient = $apiClient;
    }

    public function getAttachmentMetaById($id)
    {
        $res = $this->apiClient->get("/attachments/$id/meta");

        return $res['data'] ? new AttachmentMetaModel($res['data']) : null;
    }
    public function getPresignedUrl($id)
    {
        $res = $this->apiClient->get("/attachments/$id/presigned-url");

        return $res['data'] ? new AttachmentPresignedUrlModel($res['data']) : null;
    }
}