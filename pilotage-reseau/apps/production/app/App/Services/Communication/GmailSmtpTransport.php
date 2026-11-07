<?php
namespace App\Services\Communication;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class GmailSmtpTransport implements SmtpTransport
{
    /** @param list<array{filename:string,mime_type:string,content:string}> $attachments */
    public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $account['smtp_host'];
        $mail->Port = (int)$account['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = $account['smtp_username'];
        $mail->Password = $account['smtp_secret'];
        $mail->SMTPSecure = $account['smtp_encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($account['from_email'], $account['from_name']);
        $mail->addReplyTo($account['reply_to_email'], $account['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->MessageID = $messageId;
        $mail->isHTML(true);
        $mail->Body = MailLayout::html($body, $footer);
        MailLayout::embed($mail);
        $mail->AltBody = $body . ($footer !== null && trim($footer) !== '' ? "\n\n-- \n" . trim($footer) : '');
        foreach ($attachments as $attachment) $mail->addStringAttachment($attachment['content'], $attachment['filename'], PHPMailer::ENCODING_BASE64, $attachment['mime_type']);
        if (!$mail->send()) throw new RuntimeException('SMTP nie przyjął wiadomości.');
        return $mail->getLastMessageID() ?: $messageId;
    }
}
