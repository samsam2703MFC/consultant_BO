<?php

namespace App\Services\Client\Order;

use App\Repositories\Client\Order\OrderRepository;

/**
 * Oś statusu zamówienia: Przyjęte → Proforma → E-mail → W produkcji → Przygotowane → Wysłane
 * → Zafakturowane (WDT) → KSeF → Dostarczone → (Różnica) → Opłacone. Każdy etap liczony z danych
 * (OrderRepository::getStatusTimelineFacts), nie z pola status.
 * Stany: done, current, todo, warn, error. Teczka zamówienia (audit 2, P6) : etap do zrobienia ma swój przycisk
 * (action : label + url albo post) — ta sama akcja co na stronie, z której pochodzi ; KSeF tylko odsyła do Wysyłek.
 */
class OrderTimelineService
{
    private const KSEF_PENDING = ['ready', 'submitted', 'processing'];
    private const KSEF_FAILED = ['rejected', 'failed'];

    public function __construct(private OrderRepository $orderRepository)
    {
    }

    public function forOrder(int $id): array
    {
        $f = $this->orderRepository->getStatusTimelineFacts($id);
        if ($f === null) {
            return [];
        }

        $stages = [];
        $stages[] = $this->stage('Przyjęte', 'bi-inbox', 'done', 'wpłynęło ' . $this->date($f['create_order_date']));

        if (empty($f['id_proforma'])) {
            $stages[] = $this->stage('Proforma', 'bi-receipt', 'todo', 'brak', null,
                ['label' => 'Wystaw proformę', 'post' => '/ajax/client-orders/' . $id . '/proforma', 'confirm' => 'Wystawić proformę zamówienia #' . $id . ' ?']);
        } elseif ($f['proforma_status'] === 'cancelled') {
            $stages[] = $this->stage('Proforma', 'bi-receipt', 'warn', $f['proforma_num'] . ' · anulowana');
        } elseif ($f['proforma_status'] === 'archived') {
            $stages[] = $this->stage('Proforma', 'bi-receipt', 'done', $f['proforma_num'] . ' · zarchiwizowana');
        } else {
            $stages[] = $this->stage('Proforma', 'bi-receipt', 'done', $f['proforma_num'] . ' · ' . $this->date($f['proforma_at']));
        }

        // E-mail z proformą do klienta (stan z wysyłki : is_sent_to_client).
        if (!empty($f['id_proforma']) && !in_array($f['proforma_status'], ['cancelled', 'archived'], true)) {
            $stages[] = !empty($f['proforma_sent'])
                ? $this->stage('E-mail', 'bi-envelope', 'done', 'proforma wysłana ' . $this->date($f['proforma_sent_at']))
                : $this->stage('E-mail', 'bi-envelope', 'todo', 'proforma niewysłana', null,
                    ['label' => 'Wyślij proformę', 'url' => '/communication/compose/proforma/' . (int) $f['id_proforma']]);
        }

        $stages[] = $f['in_production_at']
            ? $this->stage('W produkcji', 'bi-gear', 'done', 'lista kompletacyjna ' . $this->date($f['in_production_at']))
            : $this->stage('W produkcji', 'bi-gear', 'todo', 'brak listy kompletacyjnej', null, ['label' => 'Listy produkcyjne', 'url' => '/workshop/team-sheet#lists=1']);

        if ($f['prepared_at']) {
            $stages[] = $this->stage('Przygotowane', 'bi-box-seam', 'done', 'palety ' . $this->date($f['prepared_at']));
        } elseif ((int) $f['pallets_scanning'] > 0) {
            $stages[] = $this->stage('Przygotowane', 'bi-box-seam', 'current', 'kompletowanie palet');
        } else {
            $stages[] = $this->stage('Przygotowane', 'bi-box-seam', 'todo', 'wysyłka ' . $this->date($f['expected_shipping_date']));
        }

        $stages[] = $f['shipped_at']
            ? $this->stage('Wysłane', 'bi-truck', 'done', $this->date($f['shipped_at']) . ' · wysyłka #' . (int) $f['id_shipment'], '/shipments')
            : $this->stage('Wysłane', 'bi-truck', 'todo', 'bez dokumentów wysyłki', null,
                ['label' => 'Wyślij dzień', 'url' => '/shipping/wizard' . ($f['expected_shipping_date'] ? '?date=' . substr((string) $f['expected_shipping_date'], 0, 10) : '')]);

        $stages[] = $f['id_wdt']
            ? $this->stage('Zafakturowane (WDT)', 'bi-file-earmark-text', 'done', $f['wdt_num'] . ' · ' . $this->date($f['wdt_at']), '/wdt-documents/' . (int) $f['id_wdt'] . '/products')
            : $this->stage('Zafakturowane (WDT)', 'bi-file-earmark-text', 'todo', 'brak WDT');

        $ksef = (string) ($f['ksef_status'] ?? '');
        if ($ksef === 'accepted') {
            $stages[] = $this->stage('KSeF', 'bi-shield-check', 'done', 'przyjęta' . ($f['ksef_number'] ? ' · ' . $f['ksef_number'] : ''));
        } elseif (in_array($ksef, self::KSEF_PENDING, true)) {
            $stages[] = $this->stage('KSeF', 'bi-shield-check', 'current', 'w toku (' . $ksef . ')');
        } elseif (in_array($ksef, self::KSEF_FAILED, true)) {
            $stages[] = $this->stage('KSeF', 'bi-shield-check', 'error', $ksef === 'rejected' ? 'odrzucona' : 'błąd wysyłki', null, ['label' => 'Wysyłki i KSeF', 'url' => '/shipments']);
        } else {
            $stages[] = $this->stage('KSeF', 'bi-shield-check', 'todo', $f['id_wdt'] ? 'nie wysłano' : '—', null, $f['id_wdt'] ? ['label' => 'Wysyłki i KSeF', 'url' => '/shipments'] : null);
        }

        // Dostawa potwierdzona na tablecie kierowcy ; problem → sprawa w « Różnice i problemy dostaw ».
        $delivery = (string) ($f['delivery_status'] ?? '');
        if ($delivery === 'delivered') {
            $stages[] = $this->stage('Dostarczone', 'bi-check2-square', 'done', 'potwierdzone przez kierowcę', '/logistics/deliveries/' . (int) $f['delivery_stop_id'] . '/proof.pdf');
        } elseif ($delivery === 'problem') {
            $stages[] = $this->stage('Dostarczone', 'bi-check2-square', 'warn', 'problem przy dostawie', null, ['label' => 'Sprawa dostawy', 'url' => '/shipments/differences']);
        } else {
            $stages[] = $this->stage('Dostarczone', 'bi-check2-square', 'todo', $f['shipped_at'] ? 'czeka na potwierdzenie kierowcy' : '—');
        }

        // Różnica proforma ↔ WDT (tylko gdy jest dokument różnic).
        if (!empty($f['id_difference'])) {
            $issue = (string) ($f['issue_status'] ?? '');
            $stages[] = $issue === 'done'
                ? $this->stage('Różnica', 'bi-arrow-left-right', 'done', 'rozliczona', '/shipments/differences?status=done')
                : $this->stage('Różnica', 'bi-arrow-left-right', 'warn', $issue === 'decided' ? 'decyzja w realizacji' : 'do decyzji', null,
                    ['label' => 'Rozlicz różnicę', 'url' => '/shipments/differences' . ($issue === 'decided' ? '?status=decided' : '')]);
        }

        $payable = !empty($f['id_proforma']) && !in_array($f['proforma_status'] ?? '', ['paid', 'cancelled', 'archived'], true);
        $stages[] = ($f['proforma_status'] ?? '') === 'paid'
            ? $this->stage('Opłacone', 'bi-wallet2', 'done', 'proforma opłacona')
            : $this->stage('Opłacone', 'bi-wallet2', 'todo', empty($f['id_proforma']) ? 'brak proformy' : (($f['proforma_status'] ?? '') === 'archived' ? 'zarchiwizowana bez płatności' : 'nieopłacona'), null,
                $payable ? ['label' => 'Oznacz jako opłaconą', 'post' => '/ajax/proforma-documents/' . (int) $f['id_proforma'] . '/paid',
                    'confirm' => 'Oznaczyć proformę ' . $f['proforma_num'] . ' jako opłaconą ? Jej pozycje staną się niezmienne.'] : null);

        // Następny krok po ciągłej serii zakończonych etapów.
        foreach ($stages as $i => $stage) {
            if ($stage['state'] === 'done') continue;
            if ($stage['state'] === 'todo') $stages[$i]['state'] = 'current';
            break;
        }

        return $stages;
    }

    private function stage(string $label, string $icon, string $state, string $meta, ?string $url = null, ?array $action = null): array
    {
        return ['label' => $label, 'icon' => $icon, 'state' => $state, 'meta' => $meta, 'url' => $url, 'action' => $state === 'done' ? null : $action];
    }

    private function date($value): string
    {
        $t = $value ? strtotime((string) $value) : false;
        return $t ? date('d.m.Y', $t) : '';
    }
}
