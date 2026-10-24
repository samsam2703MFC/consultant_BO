<?php
namespace App\Services\Communication;

/**
 * Transport wiadomości wychodzących. Interfejs pozwala uruchamiać kolejkę
 * z transportem testowym, bez połączenia z rzeczywistą skrzynką SMTP.
 */
interface SmtpTransport
{
    /** @param list<array{filename:string,mime_type:string,content:string}> $attachments */
    public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string;
}
