<?php
namespace App\Services\Communication;

use App\Repositories\Communication\CommunicationMessageAttachmentRepository;
use App\Repositories\Communication\CommunicationMessageRepository;
use App\Repositories\Communication\EmailAccountRepository;

final class CommunicationEmailDeliveryWorker
{
    private const RETRY_DELAYS = [60, 300, 900, 3600, 21600];

    public function __construct(
        private CommunicationMessageRepository $messages,
        private CommunicationMessageAttachmentRepository $attachments,
        private EmailAccountRepository $accounts,
        private SmtpTransport $transport
    ) {}

    /** Processes up to $limit queued messages and returns counters. */
    public function processDue(int $limit = 20): array
    {
        $this->messages->resetStaleLocks();
        $result = ['sent' => 0, 'failed' => 0, 'empty' => 0];
        $worker = 'email-worker-' . getmypid();
        for ($i = 0; $i < $limit; $i++) {
            $message = $this->messages->claimNext($worker);
            if ($message === []) { $result['empty']++; break; }
            $delivery = $this->deliver($message);
            $result[$delivery['status'] === 'sent' ? 'sent' : 'failed']++;
        }
        return $result;
    }

    /** Attempts delivery now for exactly one queued message, without waiting for cron. */
    public function processMessageNow(int $id): array
    {
        $this->messages->resetStaleLocks();
        $message = $this->messages->claimById($id, 'email-manual-' . getmypid());
        if ($message === []) {
            $current = $this->messages->find($id);
            if ($current === []) throw new \RuntimeException('Nie znaleziono wiadomości.');
            throw new \RuntimeException('Można wysłać natychmiast wyłącznie wiadomość ze statusem „W kolejce”. Aktualny status: ' . $current['status'] . '.');
        }
        return $this->deliver($message);
    }

    /** @return array{status:string,message:string} */
    private function deliver(array $message): array
    {
        $started = date('Y-m-d H:i:s');
        try {
            $account = $this->accounts->find((int)$message['id_email_account']);
            if ($account === [] || empty($account['is_active'])) throw new \RuntimeException('Konto nadawcze wiadomości nie jest aktywne.');
            $providerId = $this->transport->send(
                $account,
                $message['recipient_email'],
                $message['subject_text'],
                $message['body_text'],
                $message['message_id_header'],
                $this->attachments->forMessage((int)$message['id'])
            );
            $this->messages->markSent((int)$message['id'], $providerId);
            $this->messages->recordAttempt((int)$message['id'], (int)$message['attempts'], 'sent', $providerId, null, $started);
            return ['status' => 'sent', 'message' => 'Wiadomość została wysłana.'];
        } catch (\Throwable $e) {
            $attempt = (int)$message['attempts'];
            $final = $attempt >= count(self::RETRY_DELAYS);
            $delay = self::RETRY_DELAYS[min($attempt - 1, count(self::RETRY_DELAYS) - 1)];
            $this->messages->scheduleRetry($message, $e->getMessage(), $delay, $final);
            $this->messages->recordAttempt((int)$message['id'], $attempt, 'failed', null, $e->getMessage(), $started);
            return ['status' => $final ? 'failed' : 'queued', 'message' => $final ? 'Wysyłka nie powiodła się po ostatniej próbie: ' . $e->getMessage() : 'Wysyłka nie powiodła się. Wiadomość pozostaje w kolejce i zostanie ponowiona przez cron.'];
        }
    }
}
