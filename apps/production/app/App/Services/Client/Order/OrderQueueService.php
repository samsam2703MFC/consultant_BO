<?php
declare(strict_types=1);

namespace App\Services\Client\Order;

use App\Repositories\Client\Order\OrderQueueRepository;
use App\Repositories\Shipping\Difference\ShippingIssueRepository;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\SendStatusService;
use App\Services\Document\ProformaContentComparator;
use App\Services\Document\ProformaSnapshotPolicy;
use DateTimeImmutable;

/**
 * Do obsłużenia : une ligne par commande, proforma, expédition ou e-mail qui attend une action, avec son échéance,
 * les postes responsables et l'action directe. Les règles ne changent aucun statut : elles montrent l'exception.
 * Mêmes définitions que le reste de l'ERP : commandes ouvertes (OpenOrders), proforma périmée (comme la liste des
 * commandes), rappel de paiement dû (Przypomnienia płatności), palettes prêtes (Wyślij dzień), écarts d'expédition
 * (Różnice i problemy dostaw), e-mails en erreur (Stan wysyłek).
 */
final class OrderQueueService
{
    public const RULES = [
        'late_shipping' => ['label' => 'Po terminie wysyłki', 'icon' => 'bi-calendar-x', 'positions' => ['LOGISTYKA', 'KIEROWNIK_PRODUKCJI']],
        'no_proforma' => ['label' => 'Bez proformy', 'icon' => 'bi-file-earmark-plus', 'positions' => ['ADMINISTRACJA']],
        'proforma_stale' => ['label' => 'Proforma nieaktualna', 'icon' => 'bi-arrow-repeat', 'positions' => ['ADMINISTRACJA']],
        'proforma_not_sent' => ['label' => 'Proforma niewysłana', 'icon' => 'bi-envelope', 'positions' => ['ADMINISTRACJA']],
        'reminder_due' => ['label' => 'Przypomnienie o płatności', 'icon' => 'bi-bell', 'positions' => ['ADMINISTRACJA']],
        'documents_ready' => ['label' => 'Dokumenty do wystawienia', 'icon' => 'bi-truck', 'positions' => ['LOGISTYKA']],
        'shipping_issue' => ['label' => 'Różnica / problem dostawy', 'icon' => 'bi-exclamation-diamond', 'positions' => ['ADMINISTRACJA', 'LOGISTYKA']],
        'send_error' => ['label' => 'E-mail do klienta nie wyszedł', 'icon' => 'bi-send-exclamation', 'positions' => ['ADMINISTRACJA']],
    ];
    public const WHEN = ['all' => 'Wszystko', 'overdue' => 'Po terminie', 'today' => 'Do dziś', 'week' => 'Do 7 dni'];
    /** Palettes prêtes : comme Dzisiaj, les 14 derniers jours. */
    private const READY_DAYS = 14;

    public function __construct(
        private OrderQueueRepository $repo,
        private ShippingIssueRepository $issues,
        private CommunicationService $communication,
        private SendStatusService $sends,
    ) {}

    /**
     * @param ?string $rule une règle de RULES ou null
     * @param string $when all | overdue | today | week
     */
    public function overview(?string $rule, string $when, bool $mine, ?int $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $today = $now->format('Y-m-d');
        $rule = isset(self::RULES[$rule]) ? $rule : null;
        $when = isset(self::WHEN[$when]) ? $when : 'all';
        $position = $userId ? $this->repo->positionCode($userId) : null;
        $names = $this->repo->positionNames();

        $all = $this->items($now);
        foreach ($all as &$i) {
            $i['mine'] = $position !== null && in_array($position, $i['positions'], true);
            $i['owners'] = array_values(array_filter(array_map(fn($c) => $names[$c] ?? null, $i['positions'])));
            $i['days'] = $i['due'] === null ? null : (int) round((strtotime($i['due']) - strtotime($today)) / 86400);
            $i['tone'] = $i['days'] === null ? 'info' : ($i['days'] < 0 ? 'danger' : ($i['days'] === 0 ? 'warning' : 'info'));
        }
        unset($i);

        $counts = array_fill_keys(array_keys(self::RULES), 0);
        $whenCounts = array_fill_keys(array_keys(self::WHEN), 0);
        $mineCount = 0;
        foreach ($all as $i) {
            if ($i['mine']) $mineCount++;
            if ($mine && !$i['mine']) continue;
            if ($this->inWindow($i, $when)) $counts[$i['rule']]++;
            if ($rule === null || $i['rule'] === $rule) foreach (array_keys(self::WHEN) as $w) if ($this->inWindow($i, $w)) $whenCounts[$w]++;
        }
        $items = array_values(array_filter($all, fn($i) => (!$mine || $i['mine']) && ($rule === null || $i['rule'] === $rule) && $this->inWindow($i, $when)));
        usort($items, fn($a, $b) => [$a['due'] ?? '9999-12-31', $a['rule'], $a['key']] <=> [$b['due'] ?? '9999-12-31', $b['rule'], $b['key']]);
        // Tuiles : les trois règles qui ont le plus de cas (ordre des règles à égalité).
        $order = array_flip(array_keys(self::RULES));
        $top = array_keys(array_filter($counts));
        usort($top, fn($a, $b) => [$counts[$b], $order[$a]] <=> [$counts[$a], $order[$b]]);
        return [
            'rule' => $rule, 'when' => $when, 'mine' => $mine, 'position' => $position, 'position_name' => $position ? ($names[$position] ?? $position) : null,
            'rules' => self::RULES, 'whens' => self::WHEN,
            'counts' => $counts, 'top' => array_slice($top, 0, 3), 'when_counts' => $whenCounts, 'mine_count' => $mineCount, 'total' => count($all),
            'overdue' => count(array_filter($all, fn($i) => $i['days'] !== null && $i['days'] < 0)),
            'items' => $items, 'today' => $today,
        ];
    }

    /** Nombre de cas par règle (pour Dzisiaj, sans filtre). */
    public function counts(?DateTimeImmutable $now = null): array
    {
        $counts = array_fill_keys(array_keys(self::RULES), 0);
        foreach ($this->items($now ?? new DateTimeImmutable()) as $i) $counts[$i['rule']]++;
        return $counts;
    }

    private function items(DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $out = [];

        $orders = $this->repo->openOrders();
        $withProforma = array_filter($orders, fn($o) => $o['id_proforma'] !== null && !in_array($o['proforma_status'], ['paid', 'cancelled'], true));
        $orderLines = $this->repo->orderLines(array_column($withProforma, 'id'));
        $proformaLines = $this->repo->proformaLines(array_column($withProforma, 'id_proforma'));
        foreach ($orders as $o) {
            $id = (int) $o['id'];
            $title = 'Zamówienie #' . $id . ($o['reference'] ? ' · ' . $o['reference'] : '');
            $detail = trim((int) $o['cartons'] . ' kart.' . (!empty($o['is_freebie']) ? ' · gratis' : '') . ($o['order_type'] === 'SUPPLEMENT' ? ' · domówienie' : '')
                . ' · wysyłka ' . date('d.m.Y', strtotime((string) $o['shipping_date'])));
            $base = ['customer' => $o['customer'], 'customer_id' => $o['id_customer'] ? (int) $o['id_customer'] : null, 'title' => $title, 'detail' => $detail, 'order_id' => $id];
            if ($o['shipping_date'] !== null && $o['shipping_date'] < $today) {
                $out[] = $this->item('late_shipping', 'order:' . $id, $o['shipping_date'], $base, [
                    ['label' => 'Załadunek dnia', 'url' => '/logistics?date=' . $o['shipping_date'], 'primary' => true],
                    ['label' => 'Zamówienie', 'url' => '/client-orders/' . $id . '/edit'],
                ]);
            }
            if ($o['id_proforma'] === null) {
                $out[] = $this->item('no_proforma', 'order:' . $id, $o['shipping_date'], $base, [
                    ['label' => 'Wystaw proformę', 'post' => '/ajax/client-orders/' . $id . '/proforma', 'confirm' => 'Wystawić proformę dla zamówienia #' . $id . ' ?', 'primary' => true],
                    ['label' => 'Zamówienie', 'url' => '/client-orders/' . $id . '/edit'],
                ]);
                continue;
            }
            $proformaId = (int) $o['id_proforma'];
            if (in_array($o['proforma_status'], ['paid', 'cancelled'], true) || ProformaSnapshotPolicy::isPaid(['status' => $o['proforma_status']])) continue;
            $base['title'] = $title . ' · proforma ' . $o['proforma_number'];
            // Même règle que la liste des commandes (OrderService::getProformaContext) : contenu différent de la commande.
            if (ProformaContentComparator::differs($orderLines[$id] ?? [], $proformaLines[$proformaId] ?? [], !empty($o['is_freebie']))) {
                $out[] = $this->item('proforma_stale', 'proforma:' . $proformaId, $o['shipping_date'], $base + ['note' => 'zamówienie zmienione po wystawieniu proformy'], [
                    ['label' => 'Nowa proforma', 'post' => '/ajax/client-orders/' . $id . '/proforma/regenerate',
                        'confirm' => 'Obecna nieopłacona proforma ' . $o['proforma_number'] . ' zostanie anulowana, a nowa będzie zawierała aktualne pozycje zamówienia. Kontynuować ?', 'primary' => true],
                    ['label' => 'Zamówienie', 'url' => '/client-orders/' . $id . '/edit'],
                ]);
            } elseif (!(int) $o['sent_to_client'] && !(int) $o['sent_to_franchise']) {
                $out[] = $this->item('proforma_not_sent', 'proforma:' . $proformaId, $o['shipping_date'], $base + ['note' => 'wystawiona ' . date('d.m.Y', strtotime((string) $o['proforma_date']))], [
                    ['label' => 'Przygotuj e-mail', 'url' => '/communication/compose/proforma/' . $proformaId, 'primary' => true],
                    ['label' => 'PDF', 'url' => '/ajax/proforma-documents/' . $proformaId . '/document', 'blank' => true],
                ]);
            }
        }

        foreach ($this->communication->selectableUnpaidProformas($today) as $p) {
            if (empty($p['due']) || $p['next_level'] === null) continue;
            $due = date('Y-m-d', strtotime((string) $p['established_date'] . ' +' . CommunicationService::PROFORMA_REMINDER_DAYS[$p['next_level']] . ' days'));
            $out[] = $this->item('reminder_due', 'proforma:' . (int) $p['id'], $due, [
                'customer' => $p['company'], 'customer_id' => (int) $p['id_customer'],
                'title' => 'Proforma ' . $p['doc_num'] . ' nieopłacona od ' . $p['days'] . ' dni',
                'detail' => number_format((float) $p['total_price_net'], 2, ',', ' ') . ' netto · przypomnienia wysłane : ' . (int) $p['level'] . '/5',
                'note' => 'następne : ' . $p['next_level'] . '/5',
            ], [
                ['label' => 'Przypomnienie ' . $p['next_level'] . '/5', 'url' => '/communication/compose/reminder/proforma/' . (int) $p['id'], 'primary' => true],
                ['label' => 'Oznacz jako opłaconą', 'post' => '/ajax/proforma-documents/' . (int) $p['id'] . '/paid', 'confirm' => 'Oznaczyć proformę ' . $p['doc_num'] . ' jako opłaconą ?'],
            ]);
        }

        foreach ($this->repo->readyPallets($now->modify('-' . self::READY_DAYS . ' days')->format('Y-m-d')) as $r) {
            $out[] = $this->item('documents_ready', 'pallets:' . $r['day'] . ':' . (int) $r['id_customer'], $r['day'], [
                'customer' => $r['customer'], 'customer_id' => $r['id_customer'] ? (int) $r['id_customer'] : null,
                'title' => 'Palety gotowe : ' . (int) $r['pallets'] . ' ' . ((int) $r['pallets'] === 1 ? 'paleta' : 'palet') . ' na ' . date('d.m.Y', strtotime((string) $r['day'])),
                'detail' => 'WZ / WDT / CMR do wystawienia',
            ], [
                ['label' => 'Wyślij dzień', 'url' => '/shipping/wizard?date=' . $r['day'], 'primary' => true],
            ]);
        }

        foreach ($this->issues->list('open', null) as $s) {
            $isDelivery = $s['source'] === 'delivery';
            $out[] = $this->item('shipping_issue', 'issue:' . (int) $s['id'], $s['due_on'], [
                'customer' => $s['customer'], 'customer_id' => $s['id_customer'] ? (int) $s['id_customer'] : null,
                'title' => $isDelivery ? 'Problem przy dostawie ' . date('d.m.Y', strtotime((string) $s['opened_on'])) : 'Różnica WDT ' . ($s['wdt_number'] ?? '') . ' ↔ proforma',
                'detail' => $isDelivery ? (string) ($s['reason'] ?? '') : ($s['amount'] !== null ? number_format((float) $s['amount'], 2, ',', ' ') . ' ' . ($s['currency'] ?: 'EUR') : ''),
                'note' => $s['owner_name'] ? 'odpowiedzialny : ' . $s['owner_name'] : 'brak odpowiedzialnego',
            ], [
                ['label' => 'Decyzja', 'url' => '/shipments/differences', 'primary' => true],
            ]);
        }

        $sends = $this->sends->overview('error', 'email', null, $now);
        foreach ($sends['items'] as $m) {
            $out[] = $this->item('send_error', 'email:' . $m['id'], substr((string) $m['last_at'], 0, 10), [
                'customer' => $m['customer'], 'customer_id' => $m['customer_id'],
                'title' => $m['kind_label'] . ($m['what'] ? ' — ' . $m['what'] : ''),
                'detail' => $m['error'], 'note' => $m['recipient'],
            ], [
                ['label' => 'Stan wysyłek', 'url' => '/communication/outbox?filter=error&source=email', 'primary' => true],
                ['label' => 'Wiadomość', 'url' => '/communication/messages/' . $m['id']],
            ]);
        }
        return $out;
    }

    private function item(string $rule, string $key, ?string $due, array $data, array $actions): array
    {
        return $data + [
            'rule' => $rule, 'rule_label' => self::RULES[$rule]['label'], 'icon' => self::RULES[$rule]['icon'],
            'key' => $rule . ':' . $key, 'due' => $due ? substr($due, 0, 10) : null, 'positions' => self::RULES[$rule]['positions'],
            'note' => $data['note'] ?? null, 'actions' => $actions,
        ];
    }

    private function inWindow(array $i, string $when): bool
    {
        return match ($when) {
            'overdue' => $i['days'] !== null && $i['days'] < 0,
            'today' => $i['days'] !== null && $i['days'] <= 0,
            'week' => $i['days'] !== null && $i['days'] <= 7,
            default => true,
        };
    }
}
