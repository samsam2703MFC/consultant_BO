<?php
declare(strict_types=1);

namespace App\Services\Finances;

use App\Exceptions\CustomException;
use App\Repositories\Finances\PaymentsDayRepository;
use App\Repositories\Shipping\Difference\ShippingIssueRepository;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\PaymentReminderAutoService;
use App\Services\Document\ProformaService;

/**
 * Kreator « Płatności dnia » [230] (Administracja, codziennie) — trzy kroki :
 *   1. Wpłaty : proformy niezapłacone (dni od wystawienia, przypomnienia) → « opłacone » z datą i kwotą z wyciągu ;
 *   2. Przypomnienia : te, na które przyszedł czas (poziom 1–5 po 3 / 7 / 14 / 21 / 30 dniach) ; poziomy objęte wysyłką
 *      automatyczną (ustawienie) wychodzą same, pozostałe — podgląd i « Wyślij » w kompozytorze ;
 *   3. Różnice dostaw : dopłaty i zwroty z kolejki różnic (decyzje na stronie różnic).
 * « Zakończ » zapisuje wynik dnia (n_wizard_done) — zadanie zalicza się samo (TaskWizards : payments_reviewed).
 */
final class PaymentsDayService
{
    public const CODE = 'payments_reviewed';
    /** Po terminie : proforma starsza niż tyle dni (pierwsze przypomnienie poziomu 2). */
    public const OVERDUE_DAYS = 7;
    private const MAX_BATCH = 100;

    public function __construct(
        private PaymentsDayRepository $repo,
        private CommunicationService $communication,
        private ProformaService $proformas,
        private PaymentReminderAutoService $auto,
        private ShippingIssueRepository $issues,
    ) {
    }

    public function data(string $day): array
    {
        $rows = $this->communication->selectableUnpaidProformas($day);
        $autoLevels = $this->auto->levels();
        $unpaid = 0.0;
        $overdue = 0.0;
        $proformas = [];
        $reminders = [];
        foreach ($rows as $r) {
            $amount = round((float) $r['total_price_net'], 2);
            $days = (int) $r['days'];
            $unpaid += $amount;
            if ($days > self::OVERDUE_DAYS) $overdue += $amount;
            $p = ['id' => (int) $r['id'], 'number' => $r['doc_num'], 'date' => $r['established_date'], 'client' => $r['company'], 'amount' => $amount, 'days' => $days,
                'level' => (int) $r['level'], 'last_reminder' => $r['last_reminder_at'] ? substr((string) $r['last_reminder_at'], 0, 10) : null,
                'next_level' => $r['next_level'], 'due' => (bool) $r['due'], 'due_today' => $r['next_level'] !== null && $days === (CommunicationService::PROFORMA_REMINDER_DAYS[$r['next_level']] ?? -1)];
            $proformas[] = $p;
            if ($p['due']) $reminders[] = $p + ['auto' => $p['next_level'] <= $autoLevels];
        }
        usort($proformas, fn($a, $b) => [$b['days'], $b['amount']] <=> [$a['days'], $a['amount']]);
        usort($reminders, fn($a, $b) => [$b['next_level'], $b['days']] <=> [$a['next_level'], $a['days']]);
        $paid = $this->repo->paymentsRecorded($day);
        $paidSum = array_sum(array_map(fn($p) => (float) ($p['amount'] ?? 0), $paid));
        $diffs = [];
        foreach (['open', 'decided'] as $status) {
            foreach ($this->issues->list($status, null, 100) as $i) {
                $amount = $i['amount'] !== null ? round((float) $i['amount'], 2) : null;
                $diffs[] = ['id' => (int) $i['id'], 'source' => $i['source'], 'client' => $i['customer'], 'status' => $i['status'], 'decision' => $i['decision'],
                    'amount' => $amount, 'currency' => $i['currency'] ?: 'EUR', 'kind' => $i['source'] === 'delivery' ? 'delivery' : ($amount !== null && $amount < 0 ? 'refund' : 'extra'),
                    'opened_on' => $i['opened_on'], 'due_on' => $i['due_on'], 'owner' => $i['owner_name'], 'wdt' => $i['wdt_number'], 'reason' => $i['reason']];
            }
        }
        $done = $this->repo->done(self::CODE, $day);
        return [
            'day' => $day, 'proformas' => $proformas, 'reminders' => $reminders, 'auto_levels' => $autoLevels, 'differences' => $diffs,
            'paid_today' => array_map(fn($p) => ['id' => (int) $p['id_proforma'], 'number' => $p['doc_num'], 'client' => $p['company'], 'paid_on' => $p['paid_on'],
                'amount' => $p['amount'] !== null ? (float) $p['amount'] : null], $paid),
            'totals' => ['unpaid' => round($unpaid, 2), 'count' => count($proformas), 'overdue' => round($overdue, 2), 'paid_today' => round($paidSum, 2),
                'avg_days' => $this->repo->averageDaysToPay($day), 'reminders_due' => count($reminders), 'reminders_sent' => $this->repo->remindersSent($day),
                'extra' => count(array_filter($diffs, fn($d) => $d['kind'] === 'extra')), 'refund' => count(array_filter($diffs, fn($d) => $d['kind'] === 'refund')),
                'delivery' => count(array_filter($diffs, fn($d) => $d['kind'] === 'delivery'))],
            'done' => $done ? ['summary' => $done['summary'], 'at' => substr((string) $done['done_at'], 11, 5)] : null,
            'overdue_days' => self::OVERDUE_DAYS, 'reminder_days' => CommunicationService::PROFORMA_REMINDER_DAYS,
        ];
    }

    /**
     * Krok 1 : « opłacone » — [{id, paid_on, amount}] ; proforma zamrożona jako zapłacona (ProformaService::markPaid),
     * data i kwota z wyciągu zapisane (n_proforma_payment).
     * @return array{paid:int, failed:list<int>, sum:float}
     */
    public function markPaid(array $items, string $day, ?int $userId): array
    {
        if (!$items) throw new CustomException('Zaznacz co najmniej jedną proformę.', 'errors', 422);
        if (count($items) > self::MAX_BATCH) throw new CustomException('Najwyżej ' . self::MAX_BATCH . ' proform naraz.', 'errors', 422);
        $clean = [];
        foreach (array_values($items) as $i => $it) {
            $id = is_array($it) ? ($it['id'] ?? null) : null;
            if (!is_int($id) || $id <= 0) throw new CustomException('Pozycja ' . ($i + 1) . ' : brak proformy.', 'errors', 422);
            $paidOn = is_array($it) && is_string($it['paid_on'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $it['paid_on']) ? $it['paid_on'] : $day;
            if ($paidOn > date('Y-m-d') || $paidOn < date('Y-m-d', strtotime($day . ' -400 days'))) throw new CustomException('Data wpłaty : nie w przyszłości i najwyżej rok wstecz.', 'errors', 422);
            $amount = $it['amount'] ?? null;
            if ($amount !== null && ((!is_int($amount) && !is_float($amount)) || $amount <= 0 || $amount > 10_000_000)) throw new CustomException('Kwota wpłaty musi być dodatnią liczbą.', 'errors', 422);
            $clean[$id] = ['paid_on' => $paidOn, 'amount' => $amount !== null ? round((float) $amount, 2) : null];
        }
        $paid = 0;
        $sum = 0.0;
        $failed = [];
        foreach ($clean as $id => $c) {
            try {
                if (!$this->proformas->markPaid($id)) { $failed[] = $id; continue; }
                $this->repo->savePayment($id, $c['paid_on'], $c['amount'], $userId);
                $paid++;
                $sum += (float) ($c['amount'] ?? 0);
            } catch (\Throwable) {
                $failed[] = $id;
            }
        }
        return ['paid' => $paid, 'failed' => $failed, 'sum' => round($sum, 2)];
    }

    /** « Zakończ » (po kroku 3) : wynik dnia na karcie zadania. */
    public function finish(string $day, ?int $userId): array
    {
        $d = $this->data($day);
        $t = $d['totals'];
        $paid = count($d['paid_today']);
        $summary = sprintf('%d %s · %d %s · należności %s €', $paid, self::pl($paid, 'opłacona', 'opłacone', 'opłaconych'),
            $t['reminders_sent'], self::pl($t['reminders_sent'], 'przypomnienie', 'przypomnienia', 'przypomnień'), number_format($t['unpaid'], 0, ',', "\u{00A0}"));
        $this->repo->markDone(self::CODE, $day, $summary, $userId);
        return ['summary' => $summary];
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
