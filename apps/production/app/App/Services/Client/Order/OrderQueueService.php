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
 * (Różnice i problemy dostaw), e-mails en erreur (Stan wysyłek), WDT pas encore acceptées par KSeF (Wysyłki).
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
        'ksef' => ['label' => 'WDT do KSeF', 'icon' => 'bi-cloud-upload', 'positions' => ['ADMINISTRACJA', 'LOGISTYKA']],
        // Zakupy (P20) : nic nie czeka bez wiedzy działu zakupów.
        'po_draft' => ['label' => 'Zamówienie u dostawcy niewysłane', 'icon' => 'bi-cart3', 'positions' => ['ZAKUPY']],
        'po_late' => ['label' => 'Dostawa surowców po terminie', 'icon' => 'bi-truck', 'positions' => ['ZAKUPY', 'KIEROWNIK_PRODUKCJI']],
        'invoice_unlinked' => ['label' => 'Faktura zakupowa do przypisania', 'icon' => 'bi-receipt', 'positions' => ['ZAKUPY', 'ADMINISTRACJA']],
        'invoice_price_up' => ['label' => 'Podwyżka ceny surowca do zatwierdzenia', 'icon' => 'bi-graph-up-arrow', 'positions' => ['ZAKUPY', 'ADMINISTRACJA', 'KIEROWNIK_PRODUKCJI']],
        'inventory_old' => ['label' => 'Inwentaryzacja surowców > 30 dni', 'icon' => 'bi-clipboard-check', 'positions' => ['ZAKUPY', 'KIEROWNIK_PRODUKCJI']],
        // Zakupy tygodnia : szkic niewysłany, oferty do decyzji (termin : odpowiedź dostawców), pozycje z mniej niż 3 dostawcami.
        'buy_round' => ['label' => 'Zakupy tygodnia niewysłane', 'icon' => 'bi-basket2', 'positions' => ['ZAKUPY']],
        'buy_offers' => ['label' => 'Oferty dostawców do decyzji', 'icon' => 'bi-star', 'positions' => ['ZAKUPY']],
        'buy_suppliers' => ['label' => 'Pozycje z mniej niż 3 dostawcami', 'icon' => 'bi-people', 'positions' => ['ZAKUPY']],
        // Finanse (27/10/2026) : faktury usług do sprawdzenia, miesiąc kosztów usług do zamknięcia (finance.service_due_day).
        'service_open' => ['label' => 'Faktura usługi do sprawdzenia', 'icon' => 'bi-receipt-cutoff', 'positions' => ['ADMINISTRACJA']],
        'service_month' => ['label' => 'Koszty usług miesiąca do zamknięcia', 'icon' => 'bi-calendar2-range', 'positions' => ['ADMINISTRACJA']],
        // Reklamacje i zwroty (P8) : decyzja w terminie alerts.complaint_days, zamiennik do wysłania.
        'complaint_open' => ['label' => 'Reklamacja bez decyzji', 'icon' => 'bi-chat-square-text', 'positions' => ['ADMINISTRACJA', 'JAKOSC']],
        'return_to_ship' => ['label' => 'Zamiennik do wysłania', 'icon' => 'bi-box-arrow-up', 'positions' => ['LOGISTYKA']],
    ];
    public const WHEN = ['all' => 'Wszystko', 'overdue' => 'Po terminie', 'today' => 'Do dziś', 'week' => 'Do 7 dni'];
    /** Palettes prêtes : comme Dzisiaj, les 14 derniers jours. */
    private const READY_DAYS = 14;
    /** KSeF : WDT des 60 derniers jours ; « en attente » = sans réponse depuis plus de 60 minutes (rafraîchi toutes les 5 min). */
    private const KSEF_DAYS = 60;
    private const KSEF_STUCK_MINUTES = 60;
    /** Zakupy : brouillon à envoyer dans la journée, facture à contrôler sous 7 jours, inventaire tous les 30 jours. */
    private const INVOICE_DAYS = 7;
    private const INVENTORY_DAYS = 30;
    private const KSEF_LABELS = ['ready' => 'przygotowana, niewysłana', 'failed' => 'błąd wysyłki', 'rejected' => 'odrzucona przez KSeF',
        'submitted' => 'bez odpowiedzi KSeF', 'processing' => 'bez odpowiedzi KSeF'];

    public function __construct(
        private OrderQueueRepository $repo,
        private ShippingIssueRepository $issues,
        private CommunicationService $communication,
        private SendStatusService $sends,
        private \App\Repositories\Client\Complaint\ReturnsRepository $returns,
        private \App\Repositories\Workshop\ParamRepository $params,
        // Faktury z podwyżką ceny ponad cenę bazową bez decyzji (ta sama reguła co na fakturze) ; brak = pomijane.
        private ?\App\Services\Workshop\Supply\InvoiceService $invoices = null,
        // Zakupy tygodnia (zakupy otwarte, pozycje z mniej niż 3 dostawcami) ; brak = pomijane.
        private ?\App\Services\Workshop\Supply\BuyService $buy = null,
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
        // Pozycja zbiorcza (pozycje z mniej niż 3 dostawcami) liczy się swoją liczbą.
        foreach ($this->items($now ?? new DateTimeImmutable()) as $i) $counts[$i['rule']] += $i['weight'] ?? 1;
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
        $stuck = $now->modify('-' . self::KSEF_STUCK_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        foreach ($this->repo->ksefPending($now->modify('-' . self::KSEF_DAYS . ' days')->format('Y-m-d'), $stuck) as $k) {
            $id = (int) $k['id'];
            $status = $k['ksef_status'];
            $waiting = in_array($status, ['submitted', 'processing'], true);
            $actions = $waiting ? [] : [['label' => $status === null || $status === 'ready' ? 'Wyślij do KSeF' : 'Wyślij ponownie', 'post' => '/ajax/shipments/' . $id . '/ksef/send',
                'confirm' => 'Wysłać WDT ' . $k['wdt_number'] . ' do KSeF ?', 'primary' => true]];
            $actions[] = ['label' => 'Wysyłki', 'url' => '/shipments', 'primary' => $waiting];
            $out[] = $this->item('ksef', 'shipment:' . $id, $k['shipment_date'], [
                'customer' => $k['customer'], 'customer_id' => $k['id_customer'] ? (int) $k['id_customer'] : null,
                'title' => 'WDT ' . $k['wdt_number'] . ' · wysyłka ' . date('d.m.Y', strtotime((string) $k['shipment_date'])),
                'detail' => $status === null ? 'nie wysłana do KSeF' : (self::KSEF_LABELS[$status] ?? $status) . ($waiting && $k['submitted_at'] ? ' od ' . date('d.m H:i', strtotime((string) $k['submitted_at'])) : ''),
                'note' => in_array($status, ['failed', 'rejected'], true) && $k['error_message'] ? mb_substr((string) $k['error_message'], 0, 160) : null,
            ], $actions);
        }

        foreach ($this->repo->poDrafts() as $p) {
            $id = (int) $p['id'];
            $out[] = $this->item('po_draft', 'po:' . $id, date('Y-m-d', strtotime($p['created_on'] . ' +1 day')), [
                'customer' => $p['supplier'], 'customer_id' => null, 'supplier' => true,
                'title' => 'Zamówienie ' . $p['code'] . ' (szkic) · pozycji : ' . (int) $p['line_count'],
                'detail' => $p['delivery_date'] ? 'dostawa ' . date('d.m.Y', strtotime((string) $p['delivery_date'])) : 'bez daty dostawy',
                'note' => 'utworzone ' . date('d.m.Y', strtotime((string) $p['created_on'])),
            ], [
                ['label' => 'Sprawdź i wyślij', 'url' => '/workshop/supply#tab=orders&order=' . $id, 'primary' => true],
            ]);
        }
        foreach ($this->repo->poLate($today) as $p) {
            $id = (int) $p['id'];
            $out[] = $this->item('po_late', 'po:' . $id, $p['delivery_date'], [
                'customer' => $p['supplier'], 'customer_id' => null, 'supplier' => true,
                'title' => 'Zamówienie ' . $p['code'] . ' · dostawa ' . date('d.m.Y', strtotime((string) $p['delivery_date'])),
                'detail' => $p['status'] === 'partial' ? 'przyjęte częściowo' : 'nieprzyjęte',
            ], [
                ['label' => 'Przyjmij dostawę', 'url' => '/workshop/supply#tab=orders&order=' . $id . '&receive=1', 'primary' => true],
                ['label' => 'Dostawy surowców', 'url' => '/workshop/deliveries'],
            ]);
        }
        foreach ($this->repo->invoicesToLink() as $inv) {
            $id = (int) $inv['id'];
            $out[] = $this->item('invoice_unlinked', 'invoice:' . $id, $inv['issue_date'] ? date('Y-m-d', strtotime($inv['issue_date'] . ' +' . self::INVOICE_DAYS . ' days')) : null, [
                'customer' => $inv['supplier'], 'customer_id' => null, 'supplier' => true,
                'title' => 'Faktura ' . $inv['number'] . ($inv['issue_date'] ? ' z ' . date('d.m.Y', strtotime((string) $inv['issue_date'])) : ''),
                'detail' => number_format((float) $inv['total_net'], 2, ',', ' ') . ' ' . ($inv['currency'] ?: 'PLN') . ' netto',
                'note' => trim(($inv['id_po'] === null ? 'bez zamówienia' : '') . ((int) $inv['unmapped'] ? ($inv['id_po'] === null ? ' · ' : '') . 'pozycje bez surowca : ' . (int) $inv['unmapped'] : '')),
            ], [
                ['label' => 'Kontrola faktury', 'url' => '/workshop/supply#tab=invoices&inv=' . $id, 'primary' => true],
                ['label' => 'Faktury i koszty', 'url' => '/finances/invoices'],
            ]);
        }
        foreach ($this->repo->openServiceInvoices() as $inv) {
            $out[] = $this->item('service_open', 'service:' . (int) $inv['id'], $inv['issue_date'] ? date('Y-m-d', strtotime($inv['issue_date'] . ' +' . self::INVOICE_DAYS . ' days')) : null, [
                'customer' => $inv['supplier'], 'customer_id' => null, 'supplier' => true,
                'title' => 'Faktura ' . $inv['number'] . ($inv['issue_date'] ? ' z ' . date('d.m.Y', strtotime((string) $inv['issue_date'])) : ''),
                'detail' => number_format((float) $inv['total_net'], 2, ',', ' ') . ' ' . ($inv['currency'] ?: 'PLN') . ' netto',
                'note' => 'koszt firmy, miesiąc ' . $inv['service_month'],
            ], [
                ['label' => 'Koszty usług miesiąca', 'url' => '/finances/costs#m=' . $inv['service_month'], 'primary' => true],
            ]);
        }
        // Poprzedni miesiąc kosztów usług niezamknięty od finance.service_due_day (gdy są faktury usług w nim albo miesiąc wcześniej).
        $prev = $now->modify('first day of last month')->format('Y-m');
        $dueDay = min(28, max(1, (int) $this->params->get('finance.service_due_day', '10')));
        if ((int) $now->format('j') >= $dueDay && !$this->repo->serviceMonthClosed($prev) && $this->repo->serviceInvoicesAround($prev) > 0) {
            $out[] = $this->item('service_month', 'service-month:' . $prev, $now->format('Y-m-') . sprintf('%02d', $dueDay), [
                'customer' => 'Finanse', 'customer_id' => null, 'supplier' => true,
                'title' => 'Koszty usług ' . $prev, 'detail' => 'zatwierdź, uzupełnij brakujące i zamknij miesiąc', 'note' => 'sumy do kalkulatora kosztów (Rentowność)',
            ], [
                ['label' => 'Koszty usług miesiąca', 'url' => '/finances/costs#m=' . $prev, 'primary' => true],
            ]);
        }
        foreach ($this->invoices?->pendingPriceUps() ?? [] as $inv) {
            $out[] = $this->item('invoice_price_up', 'invoice-price:' . $inv['id'], $today, [
                'customer' => $inv['supplier'], 'customer_id' => null, 'supplier' => true,
                'title' => 'Faktura ' . $inv['number'] . ($inv['issue_date'] ? ' z ' . date('d.m.Y', strtotime((string) $inv['issue_date'])) : ''),
                'detail' => number_format((float) $inv['total_net'], 2, ',', ' ') . ' ' . ($inv['currency'] ?: 'PLN') . ' netto',
                'note' => 'cena wyższa niż cena bazowa (kalkulacja receptur) : ' . $inv['materials'] . ' poz.',
            ], [
                ['label' => 'Zatwierdź ceny', 'url' => '/workshop/supply#tab=invoices&inv=' . $inv['id'] . '&pu=1', 'primary' => true],
            ]);
        }
        $stale = $this->repo->staleInventory($now->modify('-' . self::INVENTORY_DAYS . ' days')->format('Y-m-d'));
        if ((int) ($stale['materials'] ?? 0) > 0) {
            $oldest = $stale['oldest'] ?? null;
            $out[] = $this->item('inventory_old', 'inventory', (int) $stale['never'] > 0 || $oldest === null ? $today : date('Y-m-d', strtotime($oldest . ' +' . self::INVENTORY_DAYS . ' days')), [
                'customer' => null, 'customer_id' => null,
                'title' => 'Surowce na stan bez inwentaryzacji od ' . self::INVENTORY_DAYS . ' dni : ' . (int) $stale['materials'],
                'detail' => (int) $stale['never'] ? 'nigdy nieliczone : ' . (int) $stale['never'] : 'najstarsza inwentaryzacja ' . date('d.m.Y', strtotime((string) $oldest)),
            ], [
                ['label' => 'Inwentaryzacja (arkusz)', 'url' => '/workshop/supply#tab=stock', 'primary' => true],
            ]);
        }

        $buy = $this->buy?->queue();
        foreach ($buy['rounds'] ?? [] as $r) {
            $id = (int) $r['id'];
            $week = 'tydzień od ' . date('d.m.Y', strtotime((string) $r['week_start']));
            if ($r['status'] === 'draft') {
                $out[] = $this->item('buy_round', 'round:' . $id, $today, [
                    'customer' => 'Zakupy tygodnia', 'customer_id' => null, 'supplier' => true,
                    'title' => 'Zakupy ' . $r['code'] . ' : szkic niewysłany', 'detail' => 'pozycji : ' . $r['line_count'] . ' · ' . $week,
                ], [
                    ['label' => 'Dokończ i wyślij', 'url' => '/workshop/buy', 'primary' => true],
                ]);
            } elseif ($r['ordered'] < $r['line_count']) {
                $out[] = $this->item('buy_offers', 'round:' . $id, $r['reply_by'] ? substr((string) $r['reply_by'], 0, 10) : $today, [
                    'customer' => 'Zakupy tygodnia', 'customer_id' => null, 'supplier' => true,
                    'title' => 'Zakupy ' . $r['code'] . ' : odpowiedziało ' . $r['responded'] . ' z ' . $r['requests'] . ' dostawców (' . $r['offers'] . ' ofert)',
                    'detail' => 'zamówione ' . $r['ordered'] . ' z ' . $r['line_count'] . ' pozycji · ' . $week,
                    'note' => $r['reply_by'] ? 'odpowiedź do ' . date('d.m.Y H:i', strtotime((string) $r['reply_by'])) : null,
                ], [
                    ['label' => 'Oferty', 'url' => '/workshop/buy#tab=offers', 'primary' => true],
                ]);
            }
        }
        if (($buy['missing'] ?? 0) > 0) {
            $out[] = $this->item('buy_suppliers', 'suppliers', null, [
                'customer' => null, 'customer_id' => null, 'weight' => (int) $buy['missing'],
                'title' => 'Znajdź dostawców : ' . (int) $buy['missing'] . ' pozycji ma mniej niż 3 dostawców',
                'detail' => implode(', ', $buy['examples']) . ((int) $buy['missing'] > count($buy['examples']) ? '…' : ''),
                'note' => 'cel : 3 dostawców na pozycję → porównanie cen',
            ], [
                ['label' => 'Dostawcy pozycji', 'url' => '/workshop/buy#tab=suppliers', 'primary' => true],
            ]);
        }

        // P8 : reklamacje franczyzy bez decyzji (termin = wpłynięcie + alerts.complaint_days) i zamienniki do wysłania.
        $complaintDays = max(1, (int) $this->params->get('alerts.complaint_days', '3'));
        foreach ($this->returns->openComplaints() as $c) {
            $out[] = $this->item('complaint_open', 'complaint:' . (int) $c['id'], date('Y-m-d', strtotime($c['received_at'] . ' +' . $complaintDays . ' days')), [
                'customer' => $c['customer'], 'customer_id' => $c['id_customer'] !== null ? (int) $c['id_customer'] : null,
                'title' => 'Reklamacja #' . (int) $c['id'] . ($c['product'] ? ' · ' . $c['product'] : ''),
                'detail' => ($c['status'] === 'IN_REVIEW' ? 'w trakcie' : 'nowa') . ' · wpłynęła ' . date('d.m.Y', strtotime((string) $c['received_at'])),
            ], [
                ['label' => 'Decyzja', 'url' => '/franchise-complaints', 'primary' => true],
            ]);
        }
        foreach ($this->returns->toShip() as $r) {
            $out[] = $this->item('return_to_ship', 'replacement:' . (int) $r['id'], $r['shipping_date'] ?: date('Y-m-d', strtotime($r['insert_timestamp'] . ' +2 days')), [
                'customer' => $r['customer'], 'customer_id' => $r['id_customer'] !== null ? (int) $r['id_customer'] : null,
                'title' => 'Zamiennik : ' . ($r['product'] ?? '—') . ' × ' . (int) $r['carton_qty'] . ' kart.',
                'detail' => (int) $r['status'] === 1 ? 'spakowany — wyjdzie z najbliższym WZ klienta' : 'do spakowania',
            ], [
                ['label' => 'Zwroty do wysłania', 'url' => '/client-complaints/to-ship#zw-ship', 'primary' => true],
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
