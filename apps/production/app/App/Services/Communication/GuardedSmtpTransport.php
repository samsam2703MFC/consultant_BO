<?php
namespace App\Services\Communication;

/**
 * Transport branché par défaut (bootstrap.php) : applique MailDeliveryPolicy avant le vrai
 * transport SMTP. Tout envoi de l'ERP — manuel, file d'attente, test de configuration,
 * e-mails automatiques — passe par ici.
 */
final class GuardedSmtpTransport implements SmtpTransport
{
    public function __construct(private SmtpTransport $smtp, private MailDeliveryPolicy $policy)
    {
    }

    public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string
    {
        return match ($this->policy->mode()) {
            'live' => $this->smtp->send($account, $to, $subject, $body, $messageId, $attachments),
            'redirect' => $this->smtp->send(
                $account,
                $this->policy->redirectTo(),
                '[TEST → ' . $to . '] ' . $subject,
                "Wiadomość testowa — pierwotny odbiorca: {$to}\n\n" . $body,
                $messageId,
                $attachments
            ),
            default => MailDeliveryPolicy::LOG_ONLY_PREFIX . $messageId,
        };
    }
}
