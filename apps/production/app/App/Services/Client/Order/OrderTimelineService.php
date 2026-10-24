<?php

namespace App\Services\Client\Order;

use App\Repositories\Client\Order\OrderRepository;

/**
 * Oś statusu zamówienia: Przyjęte → Proforma → W produkcji → Przygotowane → Wysłane
 * → Zafakturowane (WDT) → KSeF → Opłacone. Każdy etap liczony z danych
 * (OrderRepository::getStatusTimelineFacts), nie z pola status.
 * Stany: done, current, todo, warn, error.
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
            $stages[] = $this->stage('Proforma', 'bi-receipt', 'todo', 'brak');
        } elseif ($f['proforma_status'] === 'cancelled') {
            $stages[] = $this->stage('Proforma', 'bi-receipt', 'warn', $f['proforma_num'] . ' · anulowana');
        } elseif ($f['proforma_status'] === 'archived') {
            $stages[] = $this->stage('Proforma', 'bi-receipt', 'done', $f['proforma_num'] . ' · zarchiwizowana');
        } else {
            $stages[] = $this->stage('Proforma', 'bi-receipt', 'done', $f['proforma_num'] . ' · ' . $this->date($f['proforma_at']));
        }

        $stages[] = $f['in_production_at']
            ? $this->stage('W produkcji', 'bi-gear', 'done', 'lista kompletacyjna ' . $this->date($f['in_production_at']))
            : $this->stage('W produkcji', 'bi-gear', 'todo', 'brak listy kompletacyjnej');

        if ($f['prepared_at']) {
            $stages[] = $this->stage('Przygotowane', 'bi-box-seam', 'done', 'palety ' . $this->date($f['prepared_at']));
        } elseif ((int) $f['pallets_scanning'] > 0) {
            $stages[] = $this->stage('Przygotowane', 'bi-box-seam', 'current', 'kompletowanie palet');
        } else {
            $stages[] = $this->stage('Przygotowane', 'bi-box-seam', 'todo', 'wysyłka ' . $this->date($f['expected_shipping_date']));
        }

        $stages[] = $f['shipped_at']
            ? $this->stage('Wysłane', 'bi-truck', 'done', $this->date($f['shipped_at']) . ' · wysyłka #' . (int) $f['id_shipment'], '/shipments')
            : $this->stage('Wysłane', 'bi-truck', 'todo', 'bez dokumentów wysyłki');

        $stages[] = $f['id_wdt']
            ? $this->stage('Zafakturowane (WDT)', 'bi-file-earmark-text', 'done', $f['wdt_num'] . ' · ' . $this->date($f['wdt_at']), '/wdt-documents/' . (int) $f['id_wdt'] . '/products')
            : $this->stage('Zafakturowane (WDT)', 'bi-file-earmark-text', 'todo', 'brak WDT');

        $ksef = (string) ($f['ksef_status'] ?? '');
        if ($ksef === 'accepted') {
            $stages[] = $this->stage('KSeF', 'bi-shield-check', 'done', 'przyjęta' . ($f['ksef_number'] ? ' · ' . $f['ksef_number'] : ''));
        } elseif (in_array($ksef, self::KSEF_PENDING, true)) {
            $stages[] = $this->stage('KSeF', 'bi-shield-check', 'current', 'w toku (' . $ksef . ')');
        } elseif (in_array($ksef, self::KSEF_FAILED, true)) {
            $stages[] = $this->stage('KSeF', 'bi-shield-check', 'error', $ksef === 'rejected' ? 'odrzucona' : 'błąd wysyłki');
        } else {
            $stages[] = $this->stage('KSeF', 'bi-shield-check', 'todo', $f['id_wdt'] ? 'nie wysłano' : '—');
        }

        $stages[] = ($f['proforma_status'] ?? '') === 'paid'
            ? $this->stage('Opłacone', 'bi-wallet2', 'done', 'proforma opłacona')
            : $this->stage('Opłacone', 'bi-wallet2', 'todo', empty($f['id_proforma']) ? 'brak proformy' : (($f['proforma_status'] ?? '') === 'archived' ? 'zarchiwizowana bez płatności' : 'nieopłacona'));

        // Następny krok po ciągłej serii zakończonych etapów.
        foreach ($stages as $i => $stage) {
            if ($stage['state'] === 'done') continue;
            if ($stage['state'] === 'todo') $stages[$i]['state'] = 'current';
            break;
        }

        return $stages;
    }

    private function stage(string $label, string $icon, string $state, string $meta, ?string $url = null): array
    {
        return ['label' => $label, 'icon' => $icon, 'state' => $state, 'meta' => $meta, 'url' => $url];
    }

    private function date($value): string
    {
        $t = $value ? strtotime((string) $value) : false;
        return $t ? date('d.m.Y', $t) : '';
    }
}
