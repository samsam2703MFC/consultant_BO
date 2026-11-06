<?php
namespace App\Repositories\Communication;

use App\Core\Helpers\BaseRepository;

class CommunicationMessageAttachmentRepository extends BaseRepository
{
    protected string $table = 'communication_message_attachment';
    protected $allowedColumns = ['id_message', 'filename', 'mime_type', 'content_sha256', 'content'];

    public function forMessage(int $messageId): array
    {
        return $this->query('SELECT filename, mime_type, content FROM communication_message_attachment WHERE id_message = :id ORDER BY id', ['id' => $messageId]) ?: [];
    }

    public function metadataForMessage(int $messageId): array
    {
        return $this->query('SELECT id, filename, mime_type, content_sha256, OCTET_LENGTH(content) AS size_bytes FROM communication_message_attachment WHERE id_message = :id ORDER BY id', ['id' => $messageId]) ?: [];
    }
}
