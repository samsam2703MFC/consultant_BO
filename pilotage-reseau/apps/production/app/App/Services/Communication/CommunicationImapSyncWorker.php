<?php
namespace App\Services\Communication;

use App\Repositories\Communication\CommunicationIncomingMessageRepository;
use App\Repositories\Communication\CommunicationMessageRepository;
use App\Repositories\Communication\EmailAccountRepository;
use App\Services\Mail\ImapClient;
use App\Services\Mail\MimeMessage;

final class CommunicationImapSyncWorker
{
    public function __construct(
        private EmailAccountRepository $accounts,
        private CommunicationMessageRepository $messages,
        private CommunicationIncomingMessageRepository $incoming
    ) {}

    /**
     * [341] Imports recent messages of every active account (accounting@, logistyka@, produkcja@…) through the
     * ERP IMAP client (no PHP imap extension needed); duplicates are prevented by account, UID and UIDVALIDITY.
     * One failing mailbox does not stop the others.
     */
    public function syncActive(int $limit = 100): array
    {
        $accounts = $this->accounts->allActive();
        if ($accounts === []) throw new \RuntimeException('Brak aktywnego konta e-mail.');
        $total = ['imported' => 0, 'scanned' => 0, 'accounts' => []];
        foreach ($accounts as $account) {
            try {
                $r = $this->syncAccount($account, $limit);
                $total['imported'] += $r['imported']; $total['scanned'] += $r['scanned'];
                $total['accounts'][$account['from_email']] = $r;
            } catch (\Throwable $e) {
                $total['accounts'][$account['from_email']] = ['error' => $e->getMessage()];
            }
        }
        return $total;
    }

    private function syncAccount(array $account, int $limit): array
    {
        $port = (int) ($account['imap_port'] ?: 993);
        $client = new ImapClient((string) $account['imap_host'], $port, $port === 143 ? 'starttls' : 'ssl');
        $client->connect();
        try {
            $client->login((string) $account['imap_username'], (string) $account['imap_secret']);
            $state = $client->select('INBOX', true);
            $uidValidity = (string) $state['uidvalidity'];
            $uids = $state['exists'] > 0 ? $client->uidSearch('ALL') : [];
            $uids = array_slice(array_reverse($uids), 0, max(1, $limit));
            $imported = 0;
            foreach ($uids as $uid) {
                $uid = (int) $uid;
                if ($this->incoming->exists((int) $account['id'], $uidValidity, $uid)) continue;
                $raw = $client->fetchRaw($uid);
                if ($raw === null) continue;
                $mail = MimeMessage::parse($raw);
                $from = $mail->addresses('from')[0]['email'] ?? '';
                $inReplyTo = $mail->messageId('in-reply-to');
                $this->incoming->insert([
                    'id_email_account' => $account['id'],
                    'id_message' => $inReplyTo ? $this->messages->findIdByHeader($inReplyTo) : null,
                    'imap_uid' => $uid,
                    'uid_validity' => $uidValidity,
                    'sender_email' => (string) $from,
                    'subject_text' => mb_substr((string) $mail->header('subject'), 0, 500),
                    'body_text' => mb_substr((string) $mail->plainText(), 0, 60000),
                    'message_id_header' => $mail->messageId(),
                    'in_reply_to_header' => $inReplyTo,
                    'received_at' => $mail->date() ?? date('Y-m-d H:i:s'),
                ]);
                $imported++;
            }
            $this->accounts->update((int) $account['id'], ['last_synced_at' => date('Y-m-d H:i:s')]);
            return ['imported' => $imported, 'scanned' => count($uids)];
        } finally {
            $client->logout();
        }
    }

}
