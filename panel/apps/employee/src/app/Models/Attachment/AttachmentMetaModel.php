<?php
namespace App\Employee\app\Models\Attachment;


use App\Employee\app\Models\Task\TaskModel;

class AttachmentMetaModel
{
    private $id;
    private $object_key;
    private $original_name;
    private $mime_type;
    private $size_bytes;
    private $created_at;

    public function __construct($data)
    {
        $this->id = $data['id'] ?? null;
        $this->object_key = $data['object_key'] ?? null;
        $this->original_name = $data['original_name'] ?? null;
        $this->mime_type = $data['mime_type'] ?? null;
        $this->size_bytes = $data['size_bytes'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
    }

    public function getId()
    {
        return $this->id;
    }
    public function getObjectKey()
    {
        return $this->object_key;
    }
    public function getOriginalName()
    {
        return $this->original_name;
    }
    public function getMimeType()
    {
        return $this->mime_type;
    }
    public function getSizeBytes()
    {
        return $this->size_bytes;
    }
    public function getCreatedAt()
    {
        return $this->created_at;
    }

}