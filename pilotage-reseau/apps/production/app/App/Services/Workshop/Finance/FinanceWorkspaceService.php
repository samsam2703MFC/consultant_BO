<?php
declare(strict_types=1);

namespace App\Services\Workshop\Finance;

use App\Core\Api\Workshop\ApiContext;
use App\Repositories\Workshop\FinanceRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Supply\InvoiceService;

/**
 * « Faktury i koszty » — miejsce pracy stanowiska Administracja i fakturowanie (makieta 4), w trzech krokach :
 * 1. przypisz nowe pozycje (linie bez surowca z podpowiedzią ; faktura, która wygląda na usługę → Finanse),
 * 2. zatwierdź faktury (gotowe jednym kliknięciem, z ostrzeżeniami do sprawdzenia, usługi miesiąca),
 * 3. sprawdź wzrosty cen (ponad cenę bazową : skutek na miesiąc z zakupów 3 miesięcy, receptury ; drobne poniżej
 *    finance.small_price_up_pct jednym kliknięciem). Akcje to istniejące akcje faktur (Zaopatrzenie).
 */
final class FinanceWorkspaceService
{
    private const MAP_INVOICES = 8;
    private const MAP_LINES = 6;
    private const PURCHASE_DAYS = 90;

    public function __construct(
        private InvoiceService $invoices,
        private ServiceCostService $services,
        private FinanceRepository $repo,
        private ParamRepository $params,
    ) {
    }

    public function overview(ApiContext $context): array
    {
        $list = $this->invoices->list('open', $context);
        $open = $list['invoices'];

        // 1. Przypisania : faktury z liniami bez surowca ; podejrzane usługi osobno (jednym kliknięciem do Finansów).
        $suspects = array_values(array_map(fn($i) => ['id' => $i['id'], 'number' => $i['number'], 'issue_date' => $i['issue_date'], 'seller' => $i['supplier']['name'] ?? $i['seller_name'],
            'total_net' => $i['total_net'], 'currency' => $i['currency'], 'guess' => $i['service_guess']], array_filter($open, fn($i) => $i['service_guess'] !== null)));
        $toMap = array_values(array_filter($open, fn($i) => $i['service_guess'] === null && $i['control']['lines'] > 0 && $i['control']['mapped'] < $i['control']['lines']));
        $map = [];
        foreach (array_slice($toMap, 0, self::MAP_INVOICES) as $i) {
            $d = $this->invoices->detail($i['id'], $context);
            $lines = array_values(array_filter($d['lines'], fn($l) => $l['material'] === null));
            $map[] = ['id' => $i['id'], 'number' => $i['number'], 'issue_date' => $i['issue_date'], 'seller' => $i['supplier']['name'] ?? $i['seller_name'], 'supplier_known' => $i['supplier'] !== null,
                'lines' => array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'unit' => $l['unit'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'net_value' => $l['net_value'],
                    'suggestion' => $l['suggestions'][0] ?? null], array_slice($lines, 0, self::MAP_LINES)),
                'more' => max(0, count($lines) - self::MAP_LINES)];
        }
        $unmappedLines = array_sum(array_map(fn($i) => $i['control']['lines'] - $i['control']['mapped'], $toMap));

        // 2. Zatwierdzenie : gotowe, z ostrzeżeniami (do sprawdzenia), zablokowane (przypisania, podwyżki, dostawca).
        $row = fn($i) => ['id' => $i['id'], 'number' => $i['number'], 'issue_date' => $i['issue_date'], 'seller' => $i['supplier']['name'] ?? $i['seller_name'], 'total_net' => $i['total_net'],
            'currency' => $i['currency'], 'warnings' => $i['ready']['warnings'] ?? [], 'blockers' => $i['ready']['blockers'] ?? []];
        $goods = array_values(array_filter($open, fn($i) => $i['service_guess'] === null));
        $ready = array_values(array_filter($goods, fn($i) => $i['ready'] && $i['ready']['ok']));
        $warn = array_values(array_filter($goods, fn($i) => $i['ready'] && !$i['ready']['ok'] && !$i['ready']['blockers']));
        $blocked = array_values(array_filter($goods, fn($i) => $i['ready'] && $i['ready']['blockers']));

        // 3. Podwyżki ponad cenę bazową bez decyzji, ze skutkiem miesięcznym (zakupy z 3 miesięcy).
        $ups = [];
        foreach ($open as $i) {
            if (($i['control']['price_ups'] ?? 0) <= 0) continue;
            $d = $this->invoices->detail($i['id'], $context);
            foreach ($d['price_ups'] as $u) {
                if ($u['decision'] !== null) continue;
                $ups[] = $u + ['invoice_id' => $i['id'], 'number' => $i['number'], 'seller' => $i['supplier']['name'] ?? $i['seller_name'], 'issue_date' => $i['issue_date']];
            }
        }
        $bought = $this->repo->purchasedSince(array_values(array_unique(array_column($ups, 'raw_material_id'))), date('Y-m-d', strtotime('-' . self::PURCHASE_DAYS . ' days')));
        $small = max(0.0, (float) str_replace(',', '.', $this->params->get('finance.small_price_up_pct', '5')));
        foreach ($ups as &$u) {
            $monthly = ($bought[$u['raw_material_id']] ?? 0.0) / (self::PURCHASE_DAYS / 30);
            $u['monthly_quantity'] = round($monthly, 3);
            $u['monthly_extra'] = round($monthly * ($u['new_price'] - $u['base_price']), 2);
            $u['small'] = $u['change_pct'] < $small;
        }
        unset($u);
        usort($ups, fn($a, $b) => [$a['small'], -$a['monthly_extra'], -$a['extra']] <=> [$b['small'], -$b['monthly_extra'], -$b['extra']]);

        $month = ServiceCostService::defaultMonth();
        $sm = $this->services->month($month, $context);
        return [
            'map' => ['invoices' => $map, 'invoices_total' => count($toMap), 'lines_total' => $unmappedLines, 'suspects' => $suspects],
            'validate' => ['ready' => array_map($row, $ready), 'ready_net' => round(array_sum(array_map(fn($i) => (float) ($i['total_net'] ?? 0), $ready)), 2),
                'warnings' => array_map($row, $warn), 'blocked' => count($blocked), 'services_open' => $list['kpi']['services_open']],
            'price_ups' => ['items' => $ups, 'small_pct' => $small, 'small_count' => count(array_filter($ups, fn($u) => $u['small'])),
                'monthly_total' => round(array_sum(array_column($ups, 'monthly_extra')), 2), 'days' => self::PURCHASE_DAYS],
            'service_month' => ['month' => $month, 'label' => $sm['label'], 'closed' => $sm['closed'] !== null, 'open' => $sm['totals']['open'], 'missing' => $sm['totals']['missing'],
                'progress' => $sm['progress'], 'net' => $sm['totals']['net'], 'can_close' => $sm['can_close']],
            'kpi' => $list['kpi'], 'counts' => $list['counts'], 'auto' => $list['auto'],
            'can_write' => $context->can('prod.supply.write'), 'can_finance' => $context->can('prod.finance.write'),
        ];
    }

    /** Liczby do zadań (Dzisiaj, rail) : bez szczegółów faktur. */
    public function counts(ApiContext $context): array
    {
        $o = $this->overview($context);
        return ['map' => $o['map']['lines_total'], 'suspects' => count($o['map']['suspects']), 'ready' => count($o['validate']['ready']), 'warnings' => count($o['validate']['warnings']),
            'price_ups' => count($o['price_ups']['items']), 'services_open' => $o['validate']['services_open'], 'service_month' => $o['service_month']];
    }
}
