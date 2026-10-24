<?php
declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\CustomException;
use App\Repositories\Mail\UserMailRepository;
use App\Services\Communication\MailDeliveryPolicy;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Poczta użytkownika ERP : własna skrzynka (Ustawienia › Moja skrzynka e-mail), odbiór IMAP, odczyt, załączniki,
 * odpowiedź i nowa wiadomość z jego adresu przez SMTP. Wysyłka zgodnie z trybem serwera (MAIL_DELIVERY_MODE :
 * na kopii testowej nic nie wychodzi, wiadomość trafia tylko do dziennika « Wysłane z ERP »).
 */
final class UserMailService
{
    public const PROVIDERS = [
        'gmail' => ['label' => 'Gmail / Google Workspace', 'imap' => ['imap.gmail.com', 993, 'ssl'], 'smtp' => ['smtp.gmail.com', 587, 'starttls'],
            'help' => 'Hasło aplikacji : Konto Google › Bezpieczeństwo › Weryfikacja dwuetapowa › Hasła aplikacji (nie hasło konta).'],
        'outlook' => ['label' => 'Microsoft 365 / Outlook', 'imap' => ['outlook.office365.com', 993, 'ssl'], 'smtp' => ['smtp.office365.com', 587, 'starttls'],
            'help' => 'Administrator Microsoft 365 musi włączyć IMAP i « SMTP AUTH » dla skrzynki.'],
        'ovh' => ['label' => 'OVH (MX Plan, Email Pro)', 'imap' => ['ssl0.ovh.net', 993, 'ssl'], 'smtp' => ['ssl0.ovh.net', 465, 'ssl'], 'help' => ''],
        'zoho' => ['label' => 'Zoho Mail (UE)', 'imap' => ['imap.zoho.eu', 993, 'ssl'], 'smtp' => ['smtp.zoho.eu', 465, 'ssl'], 'help' => 'IMAP włącza się w ustawieniach Zoho Mail.'],
        'wp' => ['label' => 'WP Poczta', 'imap' => ['imap.wp.pl', 993, 'ssl'], 'smtp' => ['smtp.wp.pl', 465, 'ssl'], 'help' => 'IMAP włącza się w ustawieniach poczty WP.'],
        'onet' => ['label' => 'Onet Poczta', 'imap' => ['imap.poczta.onet.pl', 993, 'ssl'], 'smtp' => ['smtp.poczta.onet.pl', 465, 'ssl'], 'help' => ''],
        'o2' => ['label' => 'o2 / Tlen', 'imap' => ['poczta.o2.pl', 993, 'ssl'], 'smtp' => ['poczta.o2.pl', 465, 'ssl'], 'help' => ''],
        'interia' => ['label' => 'Interia', 'imap' => ['poczta.interia.pl', 993, 'ssl'], 'smtp' => ['poczta.interia.pl', 465, 'ssl'], 'help' => ''],
        'other' => ['label' => 'Inny serwer (dane od dostawcy poczty)', 'imap' => ['', 993, 'ssl'], 'smtp' => ['', 587, 'starttls'], 'help' => ''],
    ];
    /** Foldery ERP : odebrane i wysłane ze skrzynki (IMAP) + dziennik wysłanych z ERP. */
    public const FOLDERS = ['INBOX' => 'Odebrane', 'SENT' => 'Wysłane', 'ERP' => 'Wysłane z ERP'];
    public const PAGE = 30;
    private const FIRST_SYNC = 60;
    private const RAW_LIMIT = 524288;
    private const SYNC_BUDGET_S = 25;
    private const MAX_ATTACHMENTS_BYTES = 5 * 1024 * 1024;
    /** Większa wiadomość nie jest otwierana w ERP (pamięć serwera) : tylko zapisany tekst. */
    private const MAX_OPEN_BYTES = 25 * 1024 * 1024;

    public function __construct(private UserMailRepository $repo, private MailboxSecretCipher $cipher)
    {
    }

    // --- ustawienia skrzynki ----------------------------------------------------------------------

    /** Stan dla strony : skrzynka (bez haseł), dostawcy, tryb wysyłki serwera, liczniki folderów. */
    public function state(int $userId): array
    {
        $box = $this->repo->mailbox($userId);
        $policy = new MailDeliveryPolicy();
        return [
            'configured' => $box !== null,
            'mailbox' => $box === null ? null : self::publicBox($box),
            'providers' => array_map(fn($p) => ['label' => $p['label'], 'imap' => $p['imap'], 'smtp' => $p['smtp'], 'help' => $p['help']], self::PROVIDERS),
            'folders' => self::FOLDERS,
            'counts' => $box === null ? [] : $this->repo->folderCounts($userId),
            'delivery' => ['mode' => $policy->mode(), 'label' => $policy->label()],
        ];
    }

    public function saveMailbox(int $userId, array $in): array
    {
        $old = $this->repo->mailbox($userId);
        $errors = [];
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Podaj poprawny adres e-mail.';
        $provider = (string) ($in['provider'] ?? 'other');
        if (!isset(self::PROVIDERS[$provider])) $provider = 'other';
        $data = ['email' => $email, 'display_name' => mb_substr(trim((string) ($in['display_name'] ?? '')), 0, 160) ?: null, 'provider' => $provider,
            'signature' => mb_substr(trim((string) ($in['signature'] ?? '')), 0, 2000) ?: null, 'tls_verify' => !empty($in['tls_verify']) ? 1 : 0];
        foreach (['imap', 'smtp'] as $p) {
            $host = strtolower(trim((string) ($in[$p . '_host'] ?? '')));
            $port = (int) ($in[$p . '_port'] ?? 0);
            $security = (string) ($in[$p . '_security'] ?? '');
            $user = trim((string) ($in[$p . '_username'] ?? '')) ?: $email;
            if (!preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) $errors[$p . '_host'] = 'Podaj adres serwera (np. imap.gmail.com).';
            elseif (($why = self::forbiddenHost($host)) !== null) $errors[$p . '_host'] = $why;
            if ($port < 1 || $port > 65535) $errors[$p . '_port'] = 'Port od 1 do 65535.';
            if (!in_array($security, ['ssl', 'starttls'], true)) $errors[$p . '_security'] = 'Wybierz SSL albo STARTTLS.';
            $data += [$p . '_host' => $host, $p . '_port' => $port, $p . '_security' => $security, $p . '_username' => mb_substr($user, 0, 255)];
            $secret = (string) ($in[$p . '_password'] ?? '');
            if ($secret !== '') $data[$p . '_secret'] = $this->cipher->encrypt($secret);
            elseif ($old === null || empty($old[$p . '_secret'])) $errors[$p . '_password'] = 'Podaj hasło.';
        }
        // ten sam serwer i login IMAP / SMTP : jedno hasło wystarczy
        if (isset($errors['smtp_password']) && !isset($errors['imap_password']) && $data['smtp_username'] === $data['imap_username']) {
            unset($errors['smtp_password']);
            $data['smtp_secret'] = $data['imap_secret'] ?? $old['imap_secret'] ?? null;
        }
        if ($errors) throw new CustomException('Popraw zaznaczone pola.', 'errors', 422, ['fields' => $errors]);
        if ($old !== null && ($old['imap_host'] !== $data['imap_host'] || $old['imap_username'] !== $data['imap_username'])) {
            $this->repo->deleteMailbox($userId); // inna skrzynka : stare wiadomości znikają
            $data['sent_folder'] = null;
        }
        $data['last_error'] = null;
        $this->repo->saveMailbox($userId, $data);
        return $this->state($userId);
    }

    public function deleteMailbox(int $userId): void
    {
        $this->repo->deleteMailbox($userId);
    }

    /** Sprawdza IMAP (logowanie, foldery) i SMTP (połączenie i logowanie, nic nie jest wysyłane). */
    public function test(int $userId): array
    {
        $box = $this->box($userId);
        $out = ['imap' => ['ok' => false, 'message' => ''], 'smtp' => ['ok' => false, 'message' => '']];
        try {
            $client = $this->imap($box);
            $folders = $client->folders();
            $sent = self::sentFolder($folders);
            $client->logout();
            if ($sent !== null && $sent !== $box['sent_folder']) $this->repo->updateMailbox($userId, ['sent_folder' => $sent]);
            $out['imap'] = ['ok' => true, 'message' => 'Połączono : ' . count($folders) . ' folderów' . ($sent ? ', wysłane w « ' . $sent . ' »' : '') . '.'];
        } catch (\Throwable $e) {
            $out['imap']['message'] = $e->getMessage();
        }
        try {
            $mail = $this->mailer($box);
            if (!$mail->smtpConnect()) throw new RuntimeException('Serwer SMTP odrzucił połączenie.');
            $mail->smtpClose();
            $out['smtp'] = ['ok' => true, 'message' => 'Połączono i zalogowano (nic nie zostało wysłane).'];
        } catch (\Throwable $e) {
            $out['smtp']['message'] = self::smtpError($e);
        }
        $this->repo->updateMailbox($userId, ['last_error' => $out['imap']['ok'] && $out['smtp']['ok'] ? null : mb_substr(trim($out['imap']['message'] . ' ' . $out['smtp']['message']), 0, 500)]);
        return $out;
    }

    // --- odbiór -----------------------------------------------------------------------------------

    /** Nowe wiadomości (odebrane i wysłane), flagi i usunięte. */
    public function sync(int $userId): array
    {
        $box = $this->box($userId);
        $started = microtime(true);
        try {
            $client = $this->imap($box);
            if (empty($box['sent_folder'])) {
                $box['sent_folder'] = self::sentFolder($client->folders());
                if ($box['sent_folder'] !== null) $this->repo->updateMailbox($userId, ['sent_folder' => $box['sent_folder']]);
            }
            $stats = ['INBOX' => $this->syncFolder($client, $userId, 'INBOX', 'INBOX', $started)];
            if (!empty($box['sent_folder'])) $stats['SENT'] = $this->syncFolder($client, $userId, 'SENT', (string) $box['sent_folder'], $started);
            $client->logout();
            $this->repo->updateMailbox($userId, ['last_sync_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
            return ['new' => array_sum(array_column($stats, 'new')), 'folders' => $stats, 'counts' => $this->repo->folderCounts($userId)];
        } catch (RuntimeException $e) {
            $this->repo->updateMailbox($userId, ['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            throw new CustomException($e->getMessage(), 'errors', 502);
        }
    }

    private function syncFolder(ImapClient $client, int $userId, string $key, string $imapName, float $started): array
    {
        $state = $client->select($imapName, true);
        $max = $this->repo->maxUid($userId, $key, $state['uidvalidity']);
        $all = $state['exists'] > 0 ? $client->uidSearch('ALL') : [];
        $removed = $this->repo->forgetMissing($userId, $key, $state['uidvalidity'], 0, $all);
        $known = array_values(array_filter($all, fn($u) => $u <= $max));
        if ($known) {
            $flags = [];
            foreach ($client->fetchFlags(array_slice($known, -300)) as $uid => $f) {
                $flags[$uid] = ['seen' => in_array('\\Seen', $f, true), 'answered' => in_array('\\Answered', $f, true), 'flagged' => in_array('\\Flagged', $f, true)];
            }
            $this->repo->syncFlags($userId, $key, $flags);
        }
        $new = array_values(array_filter($all, fn($u) => $u > $max));
        if ($max === 0) $new = array_slice($new, -self::FIRST_SYNC);
        $added = 0;
        foreach (array_chunk(array_reverse($new), 25) as $chunk) {
            foreach ($client->fetchHeaders($chunk) as $uid => $h) {
                $head = MimeMessage::parseHeaders($h['headers']);
                $parsed = null;
                if ($h['size'] <= self::RAW_LIMIT && microtime(true) - $started < self::SYNC_BUDGET_S) {
                    $raw = $client->fetchRaw($uid);
                    if ($raw !== null) $parsed = MimeMessage::parse($raw);
                }
                $this->repo->insertMail($this->row($userId, $key, $state['uidvalidity'], $uid, $h, $head, $parsed));
                $added++;
            }
        }
        return ['new' => $added, 'removed' => $removed, 'total' => count($all)];
    }

    private function row(int $userId, string $key, int $uidValidity, int $uid, array $h, MimeMessage $head, ?MimeMessage $parsed): array
    {
        $from = $head->addresses('from')[0] ?? ['email' => null, 'name' => ''];
        $to = $head->addresses('to');
        $contactEmail = $key === 'INBOX' ? (string) $from['email'] : (string) ($to[0]['email'] ?? '');
        $internal = $h['internaldate'] !== '' ? strtotime($h['internaldate']) : false;
        $body = $parsed?->plainText();
        $contentType = strtolower((string) $head->header('content-type'));
        return [
            'id_user' => $userId, 'folder' => $key, 'uid_validity' => $uidValidity, 'uid' => $uid,
            'message_id' => mb_substr((string) $head->messageId(), 0, 255) ?: null, 'in_reply_to' => mb_substr((string) $head->messageId('in-reply-to'), 0, 255) ?: null,
            'from_email' => $from['email'], 'from_name' => mb_substr($from['name'], 0, 255) ?: null,
            'to_list' => mb_substr(implode(', ', array_column($to, 'email')), 0, 1000) ?: null,
            'cc_list' => mb_substr(implode(', ', array_column($head->addresses('cc'), 'email')), 0, 1000) ?: null,
            'subject' => mb_substr((string) $head->header('subject'), 0, 500) ?: null,
            'sent_at' => $head->date() ?? ($internal ? date('Y-m-d H:i:s', $internal) : date('Y-m-d H:i:s')),
            'size' => $h['size'], 'seen' => in_array('\\Seen', $h['flags'], true) ? 1 : 0, 'answered' => in_array('\\Answered', $h['flags'], true) ? 1 : 0,
            'flagged' => in_array('\\Flagged', $h['flags'], true) ? 1 : 0,
            'has_attachments' => $parsed !== null ? (count(array_filter($parsed->attachments, fn($a) => !$a['inline'])) > 0 ? 1 : 0) : (str_starts_with($contentType, 'multipart/mixed') ? 1 : 0),
            'snippet' => $parsed?->snippet(), 'body_text' => $body !== null ? mb_substr($body, 0, 60000) : null,
        ] + $this->repo->contact($contactEmail);
    }

    // --- lista i odczyt ---------------------------------------------------------------------------

    public function list(int $userId, string $folder, string $q, int $page): array
    {
        $this->box($userId);
        $page = max(1, $page);
        $offset = ($page - 1) * self::PAGE;
        if ($folder === 'ERP') {
            $r = $this->repo->sentList($userId, $q, $offset, self::PAGE);
            $rows = array_map(fn($s) => ['id' => (int) $s['id'], 'kind' => 'erp', 'to' => $s['to_list'], 'cc' => $s['cc_list'], 'subject' => $s['subject'],
                'date' => $s['created_at'], 'snippet' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s['body_text']) ?? ''), 0, 200), 'body' => $s['body_text'],
                'attachments' => json_decode((string) $s['attachments'], true) ?: [], 'mode' => $s['mode'], 'status' => $s['status'], 'error' => $s['error'],
                'customer' => $s['id_customer'] ? ['id' => (int) $s['id_customer'], 'name' => $s['customer_name']] : null], $r['rows']);
        } else {
            $folder = $folder === 'SENT' ? 'SENT' : 'INBOX';
            $r = $this->repo->list($userId, $folder, $q, $offset, self::PAGE);
            $rows = array_map(fn($m) => self::listRow($m), $r['rows']);
        }
        return ['folder' => $folder, 'page' => $page, 'pages' => max(1, (int) ceil($r['total'] / self::PAGE)), 'total' => $r['total'], 'rows' => $rows];
    }

    /** Pełna wiadomość ze skrzynki : tekst, HTML (do ramki bez skryptów), załączniki ; oznacza jako przeczytaną. */
    public function open(int $userId, int $id): array
    {
        $box = $this->box($userId);
        $mail = $this->repo->mail($userId, $id) ?? throw new CustomException('Nie znaleziono wiadomości.', 'errors', 404);
        $parsed = null;
        $offline = null;
        if ((int) $mail['size'] > self::MAX_OPEN_BYTES) {
            $offline = 'Wiadomość ma ' . round((int) $mail['size'] / 1048576) . ' MB : otwórz ją w swojej poczcie (ERP pokazuje tylko zapisany tekst).';
        } else try {
            $client = $this->imap($box);
            $client->select($this->imapFolder($box, (string) $mail['folder']));
            $raw = $client->fetchRaw((int) $mail['uid']);
            if ($raw === null) {
                $client->logout();
                $this->repo->forgetMissing($userId, (string) $mail['folder'], (int) $mail['uid_validity'], (int) $mail['uid'], []);
                throw new CustomException('Wiadomość została usunięta ze skrzynki.', 'errors', 404);
            }
            $parsed = MimeMessage::parse($raw);
            if (!$mail['seen']) $client->setFlag((int) $mail['uid'], '\\Seen');
            $client->logout();
        } catch (RuntimeException $e) {
            $offline = 'Skrzynka niedostępna (' . $e->getMessage() . ') : pokazany tekst zapisany w ERP.';
        }
        $update = ['seen' => 1];
        if ($parsed !== null && $mail['body_text'] === null) {
            $update += ['body_text' => mb_substr($parsed->plainText(), 0, 60000), 'snippet' => $parsed->snippet(),
                'has_attachments' => count(array_filter($parsed->attachments, fn($a) => !$a['inline'])) > 0 ? 1 : 0];
        }
        $this->repo->updateMail($id, $update);
        return self::listRow(['seen' => 1] + $mail) + [
            'cc' => $mail['cc_list'], 'message_id' => $mail['message_id'],
            'text' => $parsed?->plainText() ?? (string) $mail['body_text'],
            'html' => $parsed?->html !== null ? self::inlineImages($parsed) : null,
            'attachments' => $parsed === null ? [] : array_values(array_map(fn($a) => ['index' => $a['index'], 'filename' => $a['filename'], 'mime' => $a['mime'], 'size' => $a['size']],
                array_filter($parsed->attachments, fn($a) => !$a['inline']))),
            'offline' => $offline,
        ];
    }

    /** @return array{filename:string, mime:string, content:string} */
    public function attachment(int $userId, int $id, int $index): array
    {
        $box = $this->box($userId);
        $mail = $this->repo->mail($userId, $id) ?? throw new CustomException('Nie znaleziono wiadomości.', 'errors', 404);
        if ((int) $mail['size'] > self::MAX_OPEN_BYTES) throw new CustomException('Wiadomość jest za duża dla ERP : pobierz załącznik w swojej poczcie.', 'errors', 413);
        try {
            $client = $this->imap($box);
            $client->select($this->imapFolder($box, (string) $mail['folder']), true);
            $raw = $client->fetchRaw((int) $mail['uid']);
            $client->logout();
        } catch (RuntimeException $e) {
            throw new CustomException('Skrzynka niedostępna : ' . $e->getMessage(), 'errors', 502);
        }
        foreach ($raw === null ? [] : MimeMessage::parse($raw)->attachments as $a) {
            if ($a['index'] === $index) return ['filename' => $a['filename'], 'mime' => $a['mime'] ?: 'application/octet-stream', 'content' => $a['content']];
        }
        throw new CustomException('Nie znaleziono załącznika.', 'errors', 404);
    }

    // --- wysyłka ----------------------------------------------------------------------------------

    /**
     * Nowa wiadomość albo odpowiedź z adresu użytkownika. Tryb serwera : live = wysyłka (kopia w « Wysłane »),
     * redirect = na adres testowy, log_only = tylko dziennik « Wysłane z ERP ».
     */
    public function send(int $userId, array $in): array
    {
        $box = $this->box($userId);
        $errors = [];
        $to = self::emails((string) ($in['to'] ?? ''));
        $cc = self::emails((string) ($in['cc'] ?? ''));
        if ($to === null || $to === []) $errors['to'] = 'Podaj co najmniej jeden poprawny adres (oddzielone przecinkiem).';
        if ($cc === null) $errors['cc'] = 'Niepoprawny adres w polu DW.';
        if (count(array_merge($to ?? [], $cc ?? [])) > 20) $errors['to'] = 'Najwyżej 20 adresatów.';
        $subject = trim(preg_replace('/[\r\n]+/', ' ', (string) ($in['subject'] ?? '')) ?? '');
        if ($subject === '' || mb_strlen($subject) > 300) $errors['subject'] = 'Temat : od 1 do 300 znaków.';
        $text = str_replace("\r\n", "\n", (string) ($in['text'] ?? ''));
        if (trim($text) === '' || mb_strlen($text) > 100000) $errors['text'] = 'Treść : od 1 do 100 000 znaków.';
        $files = [];
        $total = 0;
        foreach (array_slice(is_array($in['attachments'] ?? null) ? $in['attachments'] : [], 0, 11) as $i => $a) {
            $content = base64_decode((string) preg_replace('#^data:[^,]*,#', '', (string) ($a['data'] ?? '')), true);
            $name = trim(preg_replace('/[\x00-\x1f\/\\\\]+/', '_', (string) ($a['name'] ?? '')) ?? '');
            if ($content === false || $name === '') { $errors['attachments'] = 'Nie udało się odczytać załącznika ' . ($i + 1) . '.'; continue; }
            $total += strlen($content);
            $files[] = ['name' => mb_substr($name, 0, 180), 'content' => $content, 'size' => strlen($content)];
        }
        if (count($files) > 10) $errors['attachments'] = 'Najwyżej 10 załączników.';
        if ($total > self::MAX_ATTACHMENTS_BYTES) $errors['attachments'] = 'Załączniki razem najwyżej 5 MB.';
        $reply = !empty($in['reply_to_id']) ? $this->repo->mail($userId, (int) $in['reply_to_id']) : null;
        if (!empty($in['reply_to_id']) && $reply === null) $errors['reply_to_id'] = 'Nie znaleziono wiadomości, na którą to odpowiedź.';
        if ($errors) throw new CustomException('Popraw zaznaczone pola.', 'errors', 422, ['fields' => $errors]);

        $policy = new MailDeliveryPolicy();
        $mail = $this->mailer($box);
        $mail->setFrom($box['email'], (string) ($box['display_name'] ?: $box['email']));
        if ($policy->mode() === 'redirect') {
            $mail->addAddress($policy->redirectTo());
            $mail->Subject = '[do: ' . implode(', ', array_merge($to, $cc)) . '] ' . $subject;
        } else {
            foreach ($to as $e) $mail->addAddress($e);
            foreach ($cc as $e) $mail->addCC($e);
            $mail->Subject = $subject;
        }
        $domain = substr((string) strrchr($box['email'], '@'), 1) ?: 'localhost';
        $mail->MessageID = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        if ($reply !== null && $reply['message_id']) {
            $mail->addCustomHeader('In-Reply-To', $reply['message_id']);
            $mail->addCustomHeader('References', $reply['message_id']);
        }
        $mail->isHTML(true);
        $mail->Body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>';
        $mail->AltBody = $text;
        foreach ($files as $f) $mail->addStringAttachment($f['content'], $f['name']);

        $status = 'sent';
        $error = null;
        $note = $policy->label();
        try {
            if ($policy->mode() === 'log_only') {
                $mail->preSend(); // wiadomość zbudowana i sprawdzona, bez połączenia z serwerem
            } else {
                $mail->send();
                if ($policy->mode() === 'live') $note = $this->copyToSent($box, $mail->getSentMIMEMessage()) ?? 'Wysłano.';
                if ($reply !== null) $this->markAnswered($box, $reply);
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = mb_substr(self::smtpError($e), 0, 500);
        }
        $contact = $this->repo->contact($to[0] ?? '');
        $logId = $this->repo->logSent([
            'id_user' => $userId, 'to_list' => mb_substr(implode(', ', $to), 0, 1000), 'cc_list' => $cc ? mb_substr(implode(', ', $cc), 0, 1000) : null,
            'subject' => mb_substr($subject, 0, 500), 'body_text' => $text,
            'attachments' => $files ? json_encode(array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size']], $files), JSON_UNESCAPED_UNICODE) : null,
            'id_reply_to' => $reply['id'] ?? null, 'message_id' => $mail->MessageID, 'mode' => $policy->mode(), 'status' => $status, 'error' => $error,
            'id_customer' => $contact['id_customer'],
        ]);
        if ($reply !== null && $status === 'sent') $this->repo->updateMail((int) $reply['id'], ['answered' => 1]);
        if ($status === 'failed') throw new CustomException('Nie wysłano : ' . $error, 'errors', 502, ['id' => $logId]);
        return ['id' => $logId, 'mode' => $policy->mode(), 'message' => $note];
    }

    private function copyToSent(array $box, string $raw): ?string
    {
        // Gmail sam zapisuje wysłane przez SMTP w « Wysłane ».
        if (empty($box['sent_folder']) || str_ends_with((string) $box['smtp_host'], 'gmail.com')) return null;
        try {
            $client = $this->imap($box);
            $client->append((string) $box['sent_folder'], $raw);
            $client->logout();
            return null;
        } catch (RuntimeException $e) {
            return 'Wysłano ; kopia w folderze « ' . $box['sent_folder'] . ' » nie powiodła się (' . $e->getMessage() . ').';
        }
    }

    private function markAnswered(array $box, array $reply): void
    {
        try {
            $client = $this->imap($box);
            $client->select($this->imapFolder($box, (string) $reply['folder']));
            $client->setFlag((int) $reply['uid'], '\\Answered');
            $client->logout();
        } catch (RuntimeException) {
        }
    }

    // --- wspólne ----------------------------------------------------------------------------------

    public function unread(int $userId): int
    {
        return $this->repo->unread($userId);
    }

    /** Wyszukiwarka z górnego paska. */
    public function search(int $userId, string $q, int $limit): array
    {
        return $this->repo->search($userId, $q, $limit);
    }

    private function box(int $userId): array
    {
        return $this->repo->mailbox($userId) ?? throw new CustomException('Najpierw podłącz swoją skrzynkę : Ustawienia › Moja skrzynka e-mail.', 'errors', 409);
    }

    private function imap(array $box): ImapClient
    {
        $password = $this->cipher->decrypt($box['imap_secret']) ?? throw new RuntimeException('Hasło IMAP do wpisania ponownie (Ustawienia › Moja skrzynka e-mail).');
        if (($why = self::forbiddenHost((string) $box['imap_host'])) !== null) throw new RuntimeException($why);
        $client = new ImapClient((string) $box['imap_host'], (int) $box['imap_port'], (string) $box['imap_security'], (bool) $box['tls_verify']);
        $client->connect();
        $client->login((string) $box['imap_username'], $password);
        return $client;
    }

    private function mailer(array $box): PHPMailer
    {
        $password = $this->cipher->decrypt($box['smtp_secret']) ?? throw new RuntimeException('Hasło SMTP do wpisania ponownie (Ustawienia › Moja skrzynka e-mail).');
        if (($why = self::forbiddenHost((string) $box['smtp_host'])) !== null) throw new RuntimeException($why);
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $box['smtp_host'];
        $mail->Port = (int) $box['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = (string) $box['smtp_username'];
        $mail->Password = $password;
        $mail->SMTPSecure = $box['smtp_security'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 20;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        if (!$box['tls_verify']) $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        return $mail;
    }

    private function imapFolder(array $box, string $key): string
    {
        return $key === 'SENT' ? (string) $box['sent_folder'] : 'INBOX';
    }

    /** Folder wysłanych : flaga \Sent, a bez niej typowe nazwy (pl, en, fr, de). */
    private static function sentFolder(array $folders): ?string
    {
        foreach ($folders as $f) if (in_array('\\Sent', $f['flags'], true)) return $f['name'];
        foreach ($folders as $f) if (preg_match('/(^|[\/.])(sent|sent items|sent mail|wysłane|elementy wysłane|envoy[ée]s|gesendet)$/iu', $f['name'])) return $f['name'];
        return null;
    }

    /**
     * Serwer poczty w sieci wewnętrznej (localhost, 10.x, 192.168.x…) : odmowa, żeby formularz nie służył do
     * skanowania serwera ERP. Wyjątek tylko z MAIL_ALLOW_PRIVATE_HOSTS=1 (testy).
     */
    private static function forbiddenHost(string $host): ?string
    {
        if ((string) ($_ENV['MAIL_ALLOW_PRIVATE_HOSTS'] ?? getenv('MAIL_ALLOW_PRIVATE_HOSTS') ?: '') === '1') return null;
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'Ten adres serwera jest w sieci wewnętrznej — podaj serwer dostawcy poczty.';
        }
        return null;
    }

    /** @return list<string>|null null = niepoprawny adres */
    private static function emails(string $value): ?array
    {
        $out = [];
        foreach (preg_split('/[,;\s]+/', trim($value)) ?: [] as $e) {
            if ($e === '') continue;
            if (preg_match('/<([^>]+)>/', $e, $m)) $e = $m[1];
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return null;
            $out[] = strtolower($e);
        }
        return array_values(array_unique($out));
    }

    private static function smtpError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'authenticate') !== false) return 'Logowanie SMTP odrzucone : sprawdź login i hasło (Gmail : hasło aplikacji).';
        if (stripos($msg, 'connect') !== false) return 'Brak połączenia z serwerem SMTP : sprawdź adres, port i szyfrowanie.';
        return mb_substr($msg, 0, 300);
    }

    /** Obrazy wstawione w treść (cid:) jako data: — ramka wiadomości nie wczytuje niczego z zewnątrz. */
    private static function inlineImages(MimeMessage $m): string
    {
        $html = (string) $m->html;
        foreach ($m->attachments as $a) {
            if ($a['cid'] === null || !str_starts_with($a['mime'], 'image/') || $a['size'] > 1048576) continue;
            $html = str_ireplace('cid:' . $a['cid'], 'data:' . $a['mime'] . ';base64,' . base64_encode($a['content']), $html);
        }
        return $html;
    }

    private static function listRow(array $m): array
    {
        return [
            'id' => (int) $m['id'], 'kind' => 'mail', 'folder' => $m['folder'], 'from' => $m['from_email'], 'from_name' => $m['from_name'], 'to' => $m['to_list'],
            'subject' => $m['subject'], 'date' => $m['sent_at'], 'seen' => (bool) $m['seen'], 'answered' => (bool) $m['answered'], 'flagged' => (bool) $m['flagged'],
            'attachments_flag' => (bool) $m['has_attachments'], 'snippet' => $m['snippet'],
            'customer' => !empty($m['id_customer']) ? ['id' => (int) $m['id_customer'], 'name' => $m['customer_name'] ?? null] : null,
            'supplier' => !empty($m['id_supplier']) ? ['id' => (int) $m['id_supplier'], 'name' => $m['supplier_name'] ?? null] : null,
        ];
    }

    private static function publicBox(array $b): array
    {
        return [
            'email' => $b['email'], 'display_name' => $b['display_name'], 'provider' => $b['provider'] ?: 'other',
            'imap_host' => $b['imap_host'], 'imap_port' => (int) $b['imap_port'], 'imap_security' => $b['imap_security'], 'imap_username' => $b['imap_username'],
            'smtp_host' => $b['smtp_host'], 'smtp_port' => (int) $b['smtp_port'], 'smtp_security' => $b['smtp_security'], 'smtp_username' => $b['smtp_username'],
            'has_imap_password' => !empty($b['imap_secret']), 'has_smtp_password' => !empty($b['smtp_secret']), 'tls_verify' => (bool) $b['tls_verify'],
            'signature' => $b['signature'], 'sent_folder' => $b['sent_folder'], 'last_sync_at' => $b['last_sync_at'], 'last_error' => $b['last_error'],
        ];
    }
}
