<?php
declare(strict_types=1);

namespace App\Services\Client\Order;

use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderPaymentRepository;
use App\Services\Communication\CommunicationService;
use App\Services\Document\ProformaService;
use App\Services\Logistics\Sms\SmsTransport;

/**
 * [312] Zamówienia klientów : przełącznik « opłacona / nieopłacona » (proforma zamówienia) i ponowne przypomnienie
 * o płatności — e-mail (kolejny poziom przypomnienia proformy z PDF, jak w Przypomnieniach) albo SMS w języku klienta.
 */
final class OrderPaymentService
{
    /** SMS : krótko, w języku komunikacji klienta (francuski, gdy brak). */
    private const SMS = [
        'pl' => '{company} : przypomnienie — proforma {num} ({amount} {currency}) czeka na płatność. Dziękujemy.',
        'fr' => '{company} : rappel — la proforma {num} ({amount} {currency}) est en attente de paiement. Merci.',
        'nl' => '{company}: herinnering — proforma {num} ({amount} {currency}) wacht op betaling. Dank u.',
        'en' => '{company}: reminder — proforma {num} ({amount} {currency}) is awaiting payment. Thank you.',
    ];
    private const CLOSED = ['paid', 'cancelled', 'archived'];

    public function __construct(
        private OrderPaymentRepository $repo,
        private ProformaService $proformas,
        private CommunicationService $communication,
        private SmsTransport $sms,
    ) {
    }

    /** @return array{paid:bool, message:string} */
    public function setPaid(int $orderId, bool $paid): array
    {
        $o = $this->proformaOrder($orderId);
        $isPaid = $o['proforma_status'] === 'paid';
        if ($paid && !$isPaid) {
            $this->proformas->markPaid((int) $o['id_proforma']);
        } elseif (!$paid && $isPaid) {
            // Cofnięcie (pomyłka) : proforma wraca do « wystawiona » — przypomnienia znów możliwe.
            $this->repo->setProformaStatus((int) $o['id_proforma'], 'issued');
        }
        return ['paid' => $paid, 'message' => $paid ? 'Proforma ' . $o['doc_num'] . ' opłacona.' : 'Proforma ' . $o['doc_num'] . ' znów nieopłacona.'];
    }

    /** @return array{channel:string, message:string} */
    public function remind(int $orderId, string $channel, ?int $userId): array
    {
        $o = $this->proformaOrder($orderId);
        if (in_array($o['proforma_status'], self::CLOSED, true)) throw new CustomException('Proforma ' . $o['doc_num'] . ' jest opłacona albo zamknięta — nie wysyłamy przypomnienia.', 'errors', 422);
        $author = 'order_list:' . ($userId ?? 0);
        if ($channel === 'email') {
            $level = $this->nextLevel((int) $o['id_proforma']);
            $this->communication->queueProformaReminder((int) $o['id_proforma'], $level, $author);
            return ['channel' => 'email', 'message' => 'Przypomnienie ' . $level . '/5 (proforma ' . $o['doc_num'] . ', PDF w załączniku) w kolejce e-maili.'];
        }
        if ($channel !== 'sms') throw new CustomException('Wybierz e-mail albo SMS.', 'errors', 422);
        $phone = trim((string) ($o['phone_head'] ?: $o['phone_alt'] ?: ''));
        if ($phone === '') throw new CustomException('Brak numeru telefonu klienta (fiche klienta : telefon).', 'errors', 422);
        $lang = isset(self::SMS[$o['language']]) ? $o['language'] : 'fr';
        $amount = $o['proforma_amount'] !== null ? number_format((float) $o['proforma_amount'], 2, ',', ' ') : '—';
        $body = strtr(self::SMS[$lang], ['{company}' => $this->repo->companyName() ?: '—', '{num}' => (string) $o['doc_num'], '{amount}' => $amount, '{currency}' => strtoupper((string) $o['currency'])]);
        $result = $this->sms->send($phone, $body, ['customer' => (int) $o['id_customer'], 'context' => 'payment_reminder', 'ref' => (int) $o['id_proforma'], 'author' => $author]);
        $this->repo->logSms(['id_proforma' => (int) $o['id_proforma'], 'id_order' => $orderId, 'recipient' => mb_substr($phone, 0, 40), 'language' => $lang,
            'body' => mb_substr($body, 0, 400), 'status' => $result['status'], 'provider' => $this->sms->name(), 'error' => $result['error'], 'created_by' => $author]);
        if ($result['status'] === 'failed') throw new CustomException('SMS nie wysłany : ' . ($result['error'] ?: 'błąd bramki') . '.', 'errors', 502);
        return ['channel' => 'sms', 'message' => 'SMS do ' . $phone . ($this->sms->delivers() ? ' wysłany.' : ' zapisany w historii (brak bramki SMS — nie wychodzi do klienta).')];
    }

    /** Ostatnie przypomnienie (e-mail / SMS) proform listy. */
    public function lastReminders(array $proformaIds): array
    {
        return $this->repo->lastReminders($proformaIds);
    }

    private function proformaOrder(int $orderId): array
    {
        $o = $this->repo->order($orderId) ?? throw new CustomException('Nie znaleziono zamówienia.', 'errors', 404);
        if (!$o['id_proforma']) throw new CustomException('Zamówienie bez proformy — najpierw wystaw proformę.', 'errors', 422);
        if (!empty($o['is_freebie'])) throw new CustomException('Zamówienie gratisowe — bez płatności.', 'errors', 422);
        return $o;
    }

    /** Kolejny poziom przypomnienia proformy (1 → 5 ; po 5 dalej 5). */
    private function nextLevel(int $proformaId): int
    {
        foreach ($this->communication->selectableUnpaidProformas() as $p) {
            if ((int) $p['id'] === $proformaId) return min(5, max(1, (int) ($p['level'] ?? 0) + 1));
        }
        return 1;
    }
}
