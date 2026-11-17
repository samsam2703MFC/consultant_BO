<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Repositories\Communication\EmailAccountRepository;
use App\Repositories\Mail\UserMailRepository;

/**
 * [345] Adres wysyłki ↔ stanowisko (Konfiguracja poczty e-mail) : skrzynka accounting@ w Poczcie osób na stanowisku
 * « Administracja i fakturowanie », logistyka@ — « Logistyka », produkcja@ — « Kierownik produkcji ».
 * Dane dostępu są kopiowane (hasła zaszyfrowane) do skrzynki każdej osoby na tym stanowisku i aktualizowane razem z kontem.
 * Skrzynka ustawiona ręcznie przez osobę nie jest ruszana. Zmiana stanowiska lub konta : skrzynka stanowiska znika.
 */
final class PositionMailboxService
{
    public function __construct(private EmailAccountRepository $accounts, private UserMailRepository $repo, private MailboxSecretCipher $cipher)
    {
    }

    /** @return array{linked:int, updated:int, removed:int} */
    public function provision(): array
    {
        $stats = ['linked' => 0, 'updated' => 0, 'removed' => 0];
        $wanted = []; // id_user => konto
        foreach ($this->accounts->allActive() as $a) {
            $code = (string) ($a['position_code'] ?? '');
            if ($code === '') continue;
            foreach ($this->repo->usersWithPosition($code) as $userId) $wanted[$userId] ??= $a;
        }
        foreach ($wanted as $userId => $a) {
            $box = $this->repo->mailbox($userId);
            if ($box !== null && $box['id_email_account'] === null) continue; // skrzynka osobista
            $data = $this->boxData($a);
            if ($box !== null && $this->same($box, $data, $a)) continue;
            if ($box !== null && ($box['email'] !== $data['email'] || $box['imap_host'] !== $data['imap_host'])) $this->repo->deleteMailbox($userId);
            $this->repo->saveMailbox($userId, $data + ['imap_secret' => $this->cipher->encrypt((string) $a['imap_secret']),
                'smtp_secret' => $this->cipher->encrypt((string) $a['smtp_secret'])]);
            $box === null ? $stats['linked']++ : $stats['updated']++;
        }
        foreach ($this->repo->positionMailboxes() as $b) {
            if (!isset($wanted[$b['id_user']]) || (int) $wanted[$b['id_user']]['id'] !== $b['id_email_account']) {
                $this->repo->deleteMailbox($b['id_user']);
                $stats['removed']++;
            }
        }
        return $stats;
    }

    private function boxData(array $a): array
    {
        $smtpPort = (int) ($a['smtp_port'] ?: 465);
        $imapPort = (int) ($a['imap_port'] ?: 993);
        return [
            'id_email_account' => (int) $a['id'], 'email' => strtolower((string) $a['from_email']),
            'display_name' => mb_substr((string) ($a['from_name'] ?? ''), 0, 160) ?: null,
            'provider' => str_ends_with(strtolower((string) $a['imap_host']), 'ovh.net') ? 'ovh' : 'other',
            'imap_host' => strtolower((string) $a['imap_host']), 'imap_port' => $imapPort, 'imap_security' => $imapPort === 143 ? 'starttls' : 'ssl',
            'imap_username' => (string) $a['imap_username'],
            'smtp_host' => strtolower((string) $a['smtp_host']), 'smtp_port' => $smtpPort,
            'smtp_security' => ($a['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'ssl' : 'starttls', 'smtp_username' => (string) $a['smtp_username'],
            'tls_verify' => 1, 'last_error' => null,
        ];
    }

    private function same(array $box, array $data, array $a): bool
    {
        foreach (['email', 'display_name', 'imap_host', 'imap_port', 'imap_security', 'imap_username', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username'] as $k) {
            if ((string) $box[$k] !== (string) $data[$k]) return false;
        }
        return (int) $box['id_email_account'] === (int) $a['id']
            && $this->cipher->decrypt($box['imap_secret']) === (string) $a['imap_secret']
            && $this->cipher->decrypt($box['smtp_secret']) === (string) $a['smtp_secret'];
    }
}
