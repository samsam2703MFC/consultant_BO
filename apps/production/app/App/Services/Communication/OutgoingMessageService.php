<?php
namespace App\Services\Communication;

use App\Repositories\Communication\CommunicationMessageAttachmentRepository;
use App\Repositories\Communication\CommunicationMessageRepository;

/**
 * Met un message en file d'envoi (communication_message + pièces jointes). Il partira par le
 * worker existant, à travers GuardedSmtpTransport (MAIL_DELIVERY_MODE).
 */
final class OutgoingMessageService
{
    public function __construct(
        private CommunicationMessageRepository $messages,
        private CommunicationMessageAttachmentRepository $attachments,
        private EmailAccountService $accounts,
    ) {
    }

    /**
     * @param array{message_type:string, template:array, recipient:string, subject:string, body:string,
     *              variables:array, id_customer?:?int, links?:array, created_by:string} $message
     * @param list<array{filename:string, mime_type:string, content:string}> $files
     */
    public function queue(array $message, array $files = []): int
    {
        if (!filter_var($message['recipient'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Nieprawidłowy adres odbiorcy: ' . $message['recipient']);
        }
        $account = $this->accounts->active();
        $domain = $account !== [] && str_contains($account['from_email'], '@') ? substr(strrchr($account['from_email'], '@'), 1) : 'localhost';
        $links = $message['links'] ?? [];
        $now = date('Y-m-d H:i:s');
        $id = (int) $this->messages->insert([
            'message_type' => $message['message_type'],
            'template_id' => $message['template']['id'] ?? null,
            'template_version' => $message['template']['version'] ?? null,
            'id_customer' => $message['id_customer'] ?? null,
            'id_proforma' => $links['id_proforma'] ?? null,
            'id_invoice' => $links['id_invoice'] ?? null,
            'id_wdt' => $links['id_wdt'] ?? null,
            'id_email_account' => $account['id'] ?? null,
            'recipient_email' => $message['recipient'],
            'sender_email' => $account['from_email'] ?? null,
            'reply_to_email' => $account['reply_to_email'] ?? null,
            'subject_text' => $message['subject'],
            'body_text' => $message['body'],
            'variables_json' => json_encode($message['variables'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'was_edited' => false,
            'status' => 'queued',
            'message_id_header' => '<slfood-' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'created_by' => $message['created_by'],
            'queued_at' => $now,
            'next_try_at' => $now,
        ]);
        if ($id <= 0) {
            throw new \RuntimeException('Nie udało się zapisać wiadomości.');
        }
        foreach ($files as $file) {
            $this->attachments->insert([
                'id_message' => $id,
                'filename' => $file['filename'],
                'mime_type' => $file['mime_type'],
                'content_sha256' => hash('sha256', $file['content']),
                'content' => $file['content'],
            ]);
        }
        return $id;
    }
}
