<?php
declare(strict_types=1);

namespace App\Services\Client;

use App\Repositories\Client\ClientMessageLogRepository;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\MailDeliveryPolicy;

/**
 * [313] Strona klienta › Wysyłki : wszystkie e-maile i SMS-y do klienta w jednej osi czasu (co, kiedy, do kogo, kto,
 * status), filtr kanału i okresu, liczniki 30 dni. Treść do podglądu ; nic się tu nie wysyła.
 */
final class ClientMessageLogService
{
    public const CHANNELS = ['' => 'E-maile i SMS', 'email' => 'Tylko e-maile', 'sms' => 'Tylko SMS'];
    public const PERIODS = ['3m' => 'Ostatnie 3 miesiące', '12m' => 'Ostatnie 12 miesięcy', 'all' => 'Wszystkie'];
    private const LIMIT = 300;
    private const SMS_KINDS = ['delivery_notice' => 'Informacja o dostawie', 'payment_reminder' => 'Przypomnienie o płatności', 'hr_note' => 'Notatka HR', 'clock_reminder' => 'HR : brak wejścia (grafik)', 'test' => 'SMS próbny', 'other' => 'SMS'];
    private const EXTRA_KINDS = ['mailbox' => 'Poczta (wiadomość napisana)', 'task_reminder' => 'Przypomnienie zadania', 'supplier_order' => 'Zamówienie u dostawcy'];

    public function __construct(private ClientMessageLogRepository $repo) {}

    public function log(int $customerId, array $query): array
    {
        $channel = array_key_exists((string) ($query['channel'] ?? ''), self::CHANNELS) ? (string) ($query['channel'] ?? '') : '';
        $period = array_key_exists((string) ($query['period'] ?? ''), self::PERIODS) ? (string) $query['period'] : '12m';
        $since = ['3m' => date('Y-m-d', strtotime('-3 months')), '12m' => date('Y-m-d', strtotime('-12 months')), 'all' => null][$period];

        $rows = [];
        if ($channel !== 'sms') $rows = array_merge($this->repo->emails($customerId, $since, self::LIMIT), $this->repo->mailbox($customerId, $since, self::LIMIT));
        if ($channel !== 'email') $rows = array_merge($rows, $this->repo->sms($customerId, $since, self::LIMIT));
        usort($rows, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        $rows = array_slice($rows, 0, self::LIMIT);

        $ids = [];
        foreach ($rows as $r) if (preg_match('/(?:^|:)(\d+)$/', (string) $r['author'], $m)) $ids[] = (int) $m[1];
        $names = $this->repo->userNames($ids);
        $out = array_map(fn($r) => $this->row($r, $names), $rows);

        // Liczniki 30 dni (niezależnie od filtrów).
        $month = date('Y-m-d', strtotime('-30 days'));
        $recent = array_merge($this->repo->emails($customerId, $month, 1000), $this->repo->mailbox($customerId, $month, 1000), $this->repo->sms($customerId, $month, 1000));
        $counts = ['email' => 0, 'sms' => 0, 'failed' => 0, 'last' => null];
        foreach ($recent as $r) {
            $counts[$r['channel']]++;
            if ($r['status'] === 'failed') $counts['failed']++;
        }
        $counts['last'] = $out[0]['at'] ?? null;

        return ['rows' => $out, 'counts' => $counts, 'limit' => self::LIMIT, 'channels' => self::CHANNELS, 'periods' => self::PERIODS,
            'filters' => ['channel' => $channel, 'period' => $period]];
    }

    private function row(array $r, array $names): array
    {
        $kind = (string) $r['kind'];
        $label = $r['channel'] === 'sms' ? (self::SMS_KINDS[$kind] ?? 'SMS') : (self::EXTRA_KINDS[$kind] ?? self::short(CommunicationService::TEMPLATE_TYPES[$kind] ?? $kind));
        // Status czytelny : e-mail « sent » w trybie testowym (LOG_ONLY / Poczta w log_only) = zapisany, nie wysłany.
        $test = $r['channel'] === 'email' && (MailDeliveryPolicy::isLogOnly((string) $r['provider_id']) || $r['provider_id'] === 'log_only');
        $status = match (true) {
            $r['status'] === 'failed' => 'failed',
            $test, $r['status'] === 'logged' => 'logged',
            in_array($r['status'], ['queued', 'processing'], true) => 'queued',
            default => 'sent',
        };
        $author = (string) ($r['author'] ?? '');
        if (preg_match('/(?:^|:)(\d+)$/', $author, $m) && isset($names[(int) $m[1]])) $author = $names[(int) $m[1]];
        elseif ($r['origin'] === 'system' && ($author === '' || preg_match('/^(cron|system|auto|migration|rule|dispatch)/i', $author))) $author = 'automatycznie';
        return ['channel' => $r['channel'], 'origin' => $r['origin'], 'id' => (int) $r['id'], 'kind' => $kind, 'label' => $label, 'recipient' => (string) $r['recipient'],
            'subject' => $r['subject'], 'body' => (string) $r['body'], 'status' => $status, 'error' => $r['error'], 'author' => $author ?: '—', 'at' => (string) $r['at']];
    }

    /** « Proforma : przypomnienie 1/5 — prośba… » → « Proforma : przypomnienie 1/5 ». */
    private static function short(string $label): string
    {
        return trim(preg_replace('/\s+(—|\().*$/u', '', $label) ?? $label);
    }
}
