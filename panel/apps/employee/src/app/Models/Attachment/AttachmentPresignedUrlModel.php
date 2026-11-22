<?php
namespace App\Employee\app\Models\Attachment;

class AttachmentPresignedUrlModel
{
    private $url;

    private $expires_at;

    public function __construct($data)
    {
        $this->url = $data['url'] ?? null;
        $this->expires_at = $data['expires_at'] ?? null;
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getExpiresAt()
    {
        return $this->expires_at;
    }

}