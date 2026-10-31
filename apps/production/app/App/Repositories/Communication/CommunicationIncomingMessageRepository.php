<?php
namespace App\Repositories\Communication;

use App\Core\Helpers\BaseRepository;

class CommunicationIncomingMessageRepository extends BaseRepository
{
    protected string $table = 'communication_incoming_message';
    protected $allowedColumns = ['id_email_account','id_message','imap_uid','uid_validity','sender_email','subject_text','body_text','message_id_header','in_reply_to_header','received_at'];

    public function forMessage(int $messageId): array
    {
        return $this->query('SELECT * FROM communication_incoming_message WHERE id_message = :id ORDER BY received_at ASC', ['id' => $messageId]) ?: [];
    }

    public function exists(int $accountId, string $uidValidity, int $uid): bool
    {
        return $this->first(['id_email_account' => $accountId, 'uid_validity' => $uidValidity, 'imap_uid' => $uid]) !== [];
    }
}
