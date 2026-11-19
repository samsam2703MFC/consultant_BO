<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Repositories\Communication\EmailAccountRepository;
use App\Repositories\Mail\UserMailRepository;
use App\Exceptions\CustomException;

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
        $active = [];
        foreach ($this->accounts->allActive() as $a) $active[(int) $a['id']] = $a;
        $wanted = []; // id_user => konto
        foreach ($active as $a) {
            $code = (string) ($a['position_code'] ?? '');
            if ($code === '') continue;
            foreach ($this->repo->usersWithPosition($code) as $userId) $wanted[$userId] ??= $a;
        }
        // [347] Konto wybrane w Ustawieniach skrzynki : wybór osoby wygrywa ze stanowiskiem.
        $chosen = [];
        foreach ($this->repo->positionMailboxes() as $b) if ($b['chosen']) $chosen[$b['id_user']] = $b['id_email_account'];
        foreach ($chosen as $userId => $accountId) {
            if (isset($active[$accountId])) $wanted[$userId] = $active[$accountId];
            else unset($wanted[$userId]); // konto wyłączone lub usunięte : skrzynka znika niżej
        }
        foreach ($wanted as $userId => $a) {
            $box = $this->repo->mailbox($userId);
            if ($box !== null && $box['id_email_account'] === null) continue; // skrzynka osobista
            $r = $this->apply($userId, $a, $box, isset($chosen[$userId]));
            if ($r !== null) $stats[$r]++;
        }
        foreach ($this->repo->positionMailboxes() as $b) {
            if (!isset($wanted[$b['id_user']]) || (int) $wanted[$b['id_user']]['id'] !== $b['id_email_account']) {
                $this->repo->deleteMailbox($b['id_user']);
                $stats['removed']++;
            }
        }
        return $stats;
    }

    /**
     * [347] Konta firmowe do wyboru w Ustawieniach skrzynki (bez haseł) : administrator — wszystkie aktywne,
     * pozostali — konta swojego stanowiska.
     * @return list<array{id:int, email:string, name:?string, purpose:?string, position:?string, ready:bool}>
     */
    public function choices(int $userId, bool $admin): array
    {
        $mine = $admin ? null : $this->repo->userPositionCodes($userId);
        $names = array_column($this->accounts->positions(), 'name_pl', 'code');
        $out = [];
        foreach ($this->accounts->allActive() as $a) {
            $code = (string) ($a['position_code'] ?? '');
            if ($mine !== null && ($code === '' || !in_array($code, $mine, true))) continue;
            $out[] = ['id' => (int) $a['id'], 'email' => strtolower((string) $a['from_email']), 'name' => $a['from_name'] ?? null,
                'purpose' => $a['purpose'] ?? null, 'position' => $code !== '' ? ($names[$code] ?? $code) : null,
                'ready' => trim((string) $a['imap_host']) !== '' && (string) $a['imap_secret'] !== '' && (string) $a['smtp_secret'] !== ''];
        }
        return $out;
    }

    /** [347] Wybór konta firmowego jako skrzynki osoby (dane dostępu kopiowane, hasła zaszyfrowane). */
    public function choose(int $userId, int $accountId, bool $admin): void
    {
        $ids = array_column($this->choices($userId, $admin), 'id');
        if (!in_array($accountId, $ids, true)) throw new CustomException('To konto nie jest dostępne do wyboru.', 'errors', 403);
        $a = $this->accounts->find($accountId);
        if (trim((string) $a['imap_host']) === '' || (string) $a['imap_secret'] === '') {
            throw new CustomException('Konto ' . $a['from_email'] . ' nie ma jeszcze serwera IMAP lub hasła — uzupełnij je w Konfiguracji poczty e-mail.', 'errors', 422);
        }
        $this->apply($userId, $a, $this->repo->mailbox($userId), true, true);
    }

    /** Kopiuje konto do skrzynki osoby ; zwraca « linked », « updated » albo null (bez zmian). */
    private function apply(int $userId, array $a, ?array $box, bool $chosen, bool $force = false): ?string
    {
        $data = $this->boxData($a) + ['account_chosen' => $chosen ? 1 : 0];
        if (!$force && $box !== null && $this->same($box, $data, $a)) return null;
        if ($box !== null && ($box['email'] !== $data['email'] || $box['imap_host'] !== $data['imap_host'])) $this->repo->deleteMailbox($userId);
        $this->repo->saveMailbox($userId, $data + ['imap_secret' => $this->cipher->encrypt((string) $a['imap_secret']),
            'smtp_secret' => $this->cipher->encrypt((string) $a['smtp_secret'])]);
        return $box === null ? 'linked' : 'updated';
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
        return (int) $box['id_email_account'] === (int) $a['id'] && (int) ($box['account_chosen'] ?? 0) === (int) $data['account_chosen']
            && $this->cipher->decrypt($box['imap_secret']) === (string) $a['imap_secret']
            && $this->cipher->decrypt($box['smtp_secret']) === (string) $a['smtp_secret'];
    }
}
