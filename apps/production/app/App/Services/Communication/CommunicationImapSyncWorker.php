<?php
namespace App\Services\Communication;

use App\Repositories\Communication\CommunicationIncomingMessageRepository;
use App\Repositories\Communication\CommunicationMessageRepository;
use App\Repositories\Communication\EmailAccountRepository;

final class CommunicationImapSyncWorker
{
    public function __construct(
        private EmailAccountRepository $accounts,
        private CommunicationMessageRepository $messages,
        private CommunicationIncomingMessageRepository $incoming
    ) {}

    /** Imports recent IMAP messages once; duplicates are prevented by UID and UIDVALIDITY. */
    public function syncActive(int $limit = 100): array
    {
        if (!function_exists('imap_open')) throw new \RuntimeException('Rozszerzenie PHP IMAP nie jest dostępne na serwerze.');
        $account = $this->accounts->active();
        if ($account === []) throw new \RuntimeException('Brak aktywnego konta e-mail.');
        $mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $account['imap_host'], (int)$account['imap_port']);
        $stream = @imap_open($mailbox, $account['imap_username'], $account['imap_secret'], 0, 1);
        if ($stream === false) throw new \RuntimeException('Nie można połączyć się z IMAP: ' . (imap_last_error() ?: 'nieznany błąd'));
        try {
            $status = imap_status($stream, $mailbox, SA_UIDVALIDITY);
            $uidValidity = (string)($status->uidvalidity ?? '0');
            $uids = imap_search($stream, 'ALL', SE_UID) ?: [];
            $uids = array_slice(array_reverse($uids), 0, max(1, $limit));
            $imported = 0;
            foreach ($uids as $uid) {
                $uid = (int)$uid;
                if ($this->incoming->exists((int)$account['id'], $uidValidity, $uid)) continue;
                $overview = imap_fetch_overview($stream, (string)$uid, FT_UID)[0] ?? null;
                if (!$overview) continue;
                $header = imap_headerinfo($stream, imap_msgno($stream, $uid));
                $inReplyTo = $this->normalizeMessageId((string)($header->in_reply_to ?? ''));
                $this->incoming->insert([
                    'id_email_account' => $account['id'],
                    'id_message' => $inReplyTo ? $this->messages->findIdByHeader($inReplyTo) : null,
                    'imap_uid' => $uid,
                    'uid_validity' => $uidValidity,
                    'sender_email' => $this->address($header->from[0] ?? null),
                    'subject_text' => $this->decode((string)($overview->subject ?? '')),
                    'body_text' => $this->decodeBody((string)imap_body($stream, $uid, FT_UID)),
                    'message_id_header' => $this->normalizeMessageId((string)($header->message_id ?? '')),
                    'in_reply_to_header' => $inReplyTo,
                    'received_at' => date('Y-m-d H:i:s', strtotime((string)($overview->date ?? 'now')) ?: time()),
                ]);
                $imported++;
            }
            $this->accounts->update((int)$account['id'], ['last_synced_at' => date('Y-m-d H:i:s')]);
            return ['imported' => $imported, 'scanned' => count($uids)];
        } finally { imap_close($stream); }
    }

    private function address(mixed $address): string
    {
        if (!is_object($address)) return '';
        return (string)($address->mailbox ?? '') . '@' . (string)($address->host ?? '');
    }
    private function normalizeMessageId(string $value): ?string
    {
        return preg_match('/<[^>]+>/', $value, $matches) ? $matches[0] : null;
    }
    private function decode(string $value): string
    {
        $parts = imap_mime_header_decode($value);
        return implode('', array_map(static fn($part) => mb_convert_encoding($part->text, 'UTF-8', $part->charset === 'default' ? 'UTF-8' : $part->charset), $parts));
    }
    private function decodeBody(string $value): string
    {
        return mb_convert_encoding(quoted_printable_decode($value), 'UTF-8', 'UTF-8');
    }
}
