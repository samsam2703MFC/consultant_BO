<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\FinanceRepository;
use App\Repositories\Workshop\InvoiceRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Material\MaterialPriceService;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Workshop\Finance\CostCategories;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Kontrola faktur zakupowych : faktura z KSeF (pobrana jako nabywca), z pliku XML KSeF, z PDF (tekst
 * pdftotext) albo ze skanu / zdjęcia (tekst OCR z przeglądarki) — linie rozpoznane gdy ilość × cena = wartość ;
 * reszta wpisywana ręcznie. Nazwy dostawcy przypisywane do naszych surowców z przelicznikiem (jednostki bazowe
 * surowca na jednostkę faktury) — zapamiętane na dostawcę (NIP albo dostawca ERP), stosowane do kolejnych faktur. Porównanie z zamówieniem :
 * ilość fakturowana vs przyjęta (albo zamówiona), cena vs cena zamówienia (tolerancje 1 % ilości, 0,5 % ceny),
 * pozycje spoza zamówienia, suma linii vs suma faktury, skok ceny wobec kartoteki. Decyzja : zaakceptowana albo reklamacja.
 * « Zatwierdź fakturę » : ceny + ilości na stan + akceptacja jednym kliknięciem ; faktura w pełni rozpoznana
 * (dostawca, zapamiętane przypisania, bez rozbieżności) przetwarzana automatycznie przy imporcie (parametry supply.invoice_auto_*).
 * Rodzaj (27/10/2026) : surowce i opakowania (goods) zostają w Zaopatrzeniu ; usługa (service : paliwo, telefon, czynsz…) idzie
 * do Finansów z kategorią kalkulatora kosztów i miesiącem ; odrzucona (rejected) nie dotyczy firmy. Reguła dostawcy (NIP) klasyfikuje
 * kolejne faktury już przy imporcie.
 */
final class InvoiceService
{
    public const STATUSES = ['open', 'accepted', 'disputed'];
    /** Widoki listy : surowce i opakowania, usługi (Finanse), odrzucone. */
    public const KINDS = ['goods', 'service', 'rejected'];
    public const CLASSIFY = ['service', 'reject', 'goods'];
    public const MAX_FILE = 5 * 1024 * 1024;
    /** Linie odczytane z tekstu (PDF, OCR) albo wpisane : edytowalne ; z KSeF / XML nie. */
    private const EDITABLE_SOURCES = ['pdf', 'ocr', 'manual'];
    private const QTY_TOL = 0.01;
    private const PRICE_TOL = 0.005;
    /** Kroki « Zatwierdź fakturę » (domyślnie wszystkie). */
    private const STEPS = ['prices', 'stock', 'accept'];
    private ?float $alertPct = null;
    public const PRICE_DECISIONS = ['accept', 'keep'];

    public function __construct(
        private InvoiceRepository $repo,
        private SupplyRepository $supply,
        private SupplyService $orders,
        private KsefInvoiceSource $ksef,
        private TransactionManager $tx,
        private AuditTrail $audit,
        private MaterialPriceService $prices,
        private ParamRepository $params,
        private PriceHistoryService $history,
        private CostCategories $categories,
        private FinanceRepository $finance,
    ) {
    }

    // --- Lista i szczegóły ------------------------------------------------------------------------------------------

    /**
     * Lista : kind goods (surowce i opakowania : kontrola, gotowość, podpowiedź « wygląda na usługę »), service (usługi w Finansach)
     * albo rejected (odrzucone). Liczby do przełącznika, kafelki (do walidacji, gotowe, podwyżki, usługi miesiąca), kategorie kosztów.
     */
    public function list(string $status, ApiContext $context, string $kind = 'goods'): array
    {
        if (!in_array($kind, self::KINDS, true)) throw ApiException::validation([['field' => 'kind', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => implode(', ', self::KINDS)]]]);
        if (!in_array($status, ['all', ...self::STATUSES], true)) throw ApiException::validation([['field' => 'status', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => 'all, ' . implode(', ', self::STATUSES)]]]);
        $cats = $this->categories->all();
        $goods = function (array $i) use ($cats) {
            $lines = $this->repo->lines((int) $i['id']);
            $control = $this->control($i, $lines);
            return $this->summary($i) + ['control' => $control['summary'], 'ready' => $i['status'] === 'open' ? $this->readiness($i, $control) : null,
                'service_guess' => $i['status'] === 'open' && empty($i['is_correction']) ? CostCategories::guess($i, $lines, $cats) : null];
        };
        $open = $kind === 'goods' && $status === 'open' ? null : array_map($goods, $this->repo->invoices('open'));
        if ($kind === 'goods') {
            $rows = $open !== null && $status === 'open' ? $open : array_map($goods, $this->repo->invoices($status === 'all' ? null : $status));
            $open ??= $rows;
        } else {
            $rows = array_map(fn($i) => $this->summary($i) + $this->costInfo($i, $cats),
                $kind === 'rejected' ? $this->repo->invoices(null, null) : $this->repo->invoices($status === 'all' ? null : $status, 'service'));
        }
        $month = date('Y-m');
        return ['invoices' => $rows, 'kind' => $kind, 'counts' => $this->counts(),
            'kpi' => ['open' => count($open), 'open_net' => round(array_sum(array_map(fn($i) => (float) ($i['total_net'] ?? 0), $open)), 2),
                'ready' => count(array_filter($open, fn($i) => $i['ready'] && $i['ready']['ok'])),
                'price_ups' => array_sum(array_map(fn($i) => (int) ($i['control']['price_ups'] ?? 0), $open)),
                'services_suspected' => count(array_filter($open, fn($i) => $i['service_guess'] !== null)),
                'services_month' => $month, 'services_month_count' => (int) array_sum(array_column($this->finance->serviceTotals([$month]), 'n')),
                'services_open' => $this->finance->openServices()],
            'categories' => CostCategories::present($cats), 'can_write' => $context->can('prod.supply.write'), 'can_finance' => $context->can('prod.finance.read'), 'auto' => $this->autoMode()];
    }

    /** Liczby przełącznika : surowce (do walidacji, zatwierdzone, reklamacje), usługi (do sprawdzenia, zatwierdzone), odrzucone, reguły. */
    private function counts(): array
    {
        $out = ['goods' => ['open' => 0, 'accepted' => 0, 'disputed' => 0], 'service' => ['open' => 0, 'accepted' => 0], 'rejected' => 0, 'rules' => 0];
        foreach ($this->repo->counts() as $r) {
            if ($r['kind'] === 'rejected') $out['rejected'] += (int) $r['n'];
            elseif (isset($out[$r['kind']][$r['status']])) $out[$r['kind']][$r['status']] += (int) $r['n'];
        }
        $out['rules'] = count($this->repo->rules());
        return $out;
    }

    /** Usługa : kategoria (z etykietą), miesiąc, kwota do kosztów (PLN), reguła ; odrzucona : notatka. */
    private function costInfo(array $i, array $cats): array
    {
        $cat = $i['cost_category'] !== null ? ($cats[$i['cost_category']] ?? null) : null;
        return ['cost_category' => $i['cost_category'], 'category' => $cat ? ['code' => $cat['code'], 'label' => $cat['label'], 'group_label' => $cat['group_label']] : null,
            'service_month' => $i['service_month'], 'cost_amount' => self::costAmount($i), 'by_rule' => $i['id_rule'] !== null, 'note' => $i['note']];
    }

    /** Kwota do kosztów (PLN) : wpisana albo suma netto faktury w PLN ; inna waluta bez kwoty = null (do uzupełnienia). */
    public static function costAmount(array $i): ?float
    {
        if ($i['cost_amount'] !== null) return round((float) $i['cost_amount'], 2);
        return ($i['currency'] ?? 'PLN') === 'PLN' && $i['total_net'] !== null ? round((float) $i['total_net'], 2) : null;
    }

    public function detail(int $id, ApiContext $context): array
    {
        $i = $this->load($id);
        $lines = $this->repo->lines($id);
        $control = $this->control($i, $lines);
        $lang = $context->translator->lang();
        $materials = $this->repo->materials();
        $orderMaterials = $i['id_po'] ? array_map(fn($l) => (int) $l['id_raw_material'], $this->supply->lines((int) $i['id_po'])) : [];
        $out = [];
        foreach ($lines as $k => $l) {
            $c = $control['lines'][$k];
            $row = $this->presentLine($l, $lang) + ['control' => $c];
            if ($l['id_raw_material'] === null) $row['suggestions'] = $this->suggest($l, $i, $materials, $orderMaterials, $lang);
            $out[] = $row;
        }
        $mapped = array_values(array_unique(array_filter(array_map(fn($l) => $l['id_raw_material'] !== null ? (int) $l['id_raw_material'] : null, $lines))));
        $goods = $i['status'] === 'open' && $i['kind'] === 'goods';
        return $this->summary($i) + [
            'note' => $i['note'], 'decided_at' => $i['decided_at'],
            'lines' => $out, 'control' => $control['summary'],
            'ready' => $goods ? $this->readiness($i, $control) : null, 'auto' => $this->autoMode(),
            // Historia cen : poprzednia cena i najtańszy dostawca z 12 miesięcy dla każdego surowca faktury.
            'price_refs' => (object) $this->history->refs($mapped, $id),
            'price_ups' => $this->presentUps($this->priceUps($lines, !empty($i['is_correction'])), $lines, $lang),
            'orders' => $i['id_supplier'] ? $this->candidates((int) $i['id_supplier'], $lines) : [],
            'suppliers' => $goods ? $this->supplierChoices($i) : [],
            'text' => in_array($i['source'], ['pdf', 'ocr'], true) ? $this->repo->fileText($id) : null,
            'can_write' => $context->can('prod.supply.write'), 'editable' => $goods && $context->can('prod.supply.write'),
            'lines_editable' => in_array($i['source'], self::EDITABLE_SOURCES, true) && $goods && $context->can('prod.supply.write'),
            // Ilości : do zamówienia (wysłane / częściowo przyjęte) albo — bez zamówienia — przez zamówienie utworzone z faktury.
            'can_receive' => $context->can('prod.supply.write') && $this->canReceive($i, $control),
            'receive_mode' => $i['id_po'] !== null ? 'order' : 'invoice',
            'can_import_prices' => $i['kind'] === 'goods' && !in_array($i['status'], ['disputed', 'rejected'], true) && empty($i['is_correction']) && $context->can('prod.supply.write') && $control['summary']['mapped'] > 0,
            'prices_imported_at' => $i['prices_imported_at'],
            // Rodzaj : podpowiedź usługi, reguła dostawcy, inne otwarte faktury tego NIP, kategorie kosztów (okno « co zrobić z tą fakturą ? »).
            'classify' => $this->classifyInfo($i, $lines),
        ] + ($i['kind'] !== 'goods' || $i['status'] === 'rejected' ? $this->costInfo($i, $this->categories->all()) : []);
    }

    private function classifyInfo(array $i, array $lines): array
    {
        $cats = $this->categories->all();
        $rule = $i['seller_nip'] ? $this->repo->rule((string) $i['seller_nip']) : null;
        $movable = ($i['kind'] === 'goods' && $i['status'] === 'open' && $i['prices_imported_at'] === null && $i['id_po'] === null) || $i['status'] === 'rejected' || $i['kind'] === 'service';
        return ['movable' => $movable, 'guess' => $i['kind'] === 'goods' ? CostCategories::guess($i, $lines, $cats) : null, 'default_month' => self::monthOf($i['issue_date']) ?? date('Y-m'),
            'rule' => $rule ? ['id' => (int) $rule['id'], 'action' => $rule['action'], 'cost_category' => $rule['cost_category']] : null,
            'same_nip_open' => $i['seller_nip'] ? count($this->repo->openGoodsOfNip((string) $i['seller_nip'], (int) $i['id'])) : 0,
            'categories' => CostCategories::present($cats)];
    }

    /**
     * Kontrola linii : ilość w jednostkach bazowych (ilość × przelicznik), cena netto za jednostkę bazową,
     * porównanie z linią zamówienia tego surowca (ilość przyjęta, inaczej zamówiona ; cena zamówienia) i z cennikiem.
     */
    private function control(array $inv, array $lines): array
    {
        $po = [];
        if ($inv['id_po']) foreach ($this->supply->lines((int) $inv['id_po']) as $pl) $po[(int) $pl['id_raw_material']] = $pl;
        $out = [];
        $issues = 0;
        $mapped = 0;
        $jumps = 0;
        $gap = 0.0;
        $sum = 0.0;
        $ups = $this->priceUps($lines, !empty($inv['is_correction']));
        foreach ($lines as $l) {
            $flags = [];
            $net = $l['net_value'] !== null ? (float) $l['net_value'] : ($l['unit_price'] !== null ? (float) $l['unit_price'] * (float) $l['quantity'] : null);
            $sum += (float) ($net ?? 0);
            $c = ['quantity_base' => null, 'price_base' => null, 'ordered' => null, 'received' => null, 'order_price' => null, 'catalog_price' => null,
                'qty_gap_pct' => null, 'price_gap_pct' => null, 'catalog_gap_pct' => null, 'value_gap' => null, 'flags' => []];
            if ($l['id_raw_material'] === null) {
                $flags[] = 'unmapped';
            } else {
                $mapped++;
                $factor = (float) ($l['factor'] ?: 1);
                $qtyBase = (float) $l['quantity'] * $factor;
                $priceBase = $net !== null && $qtyBase > 0 ? $net / $qtyBase : null;
                $c['quantity_base'] = round($qtyBase, 3);
                $c['price_base'] = $priceBase;
                $catalog = $l['catalog_price'] !== null && (float) $l['catalog_price'] > 0 ? (float) $l['catalog_price'] : null;
                $c['catalog_price'] = $catalog;
                if ($catalog !== null && $priceBase !== null) $c['catalog_gap_pct'] = round(($priceBase - $catalog) / $catalog * 100, 2);
                // Cena wyższa niż cena bazowa (kartoteka, kalkulacja receptur) : decyzja na fakturze — nowa cena bazowa (accept)
                // albo cena bazowa bez zmian (keep) ; bez decyzji nic nie idzie automatycznie (price_up).
                $up = $ups[(int) $l['id_raw_material']] ?? null;
                if ($up !== null) $flags[] = $up['decision'] === null ? 'price_up' : 'price_up_' . $up['decision'];
                // Skok ceny wobec kartoteki : zły przelicznik albo prawdziwa podwyżka — do sprawdzenia (podwyżka z decyzją już sprawdzona).
                if ($c['catalog_gap_pct'] !== null && abs($c['catalog_gap_pct']) > $this->alertPct() && ($up === null || $up['decision'] === null)) { $flags[] = 'price_jump'; $jumps++; }
                if ($inv['id_po']) {
                    $pl = $po[(int) $l['id_raw_material']] ?? null;
                    if ($pl === null) $flags[] = 'not_in_order';
                    else {
                        $ordered = (float) $pl['quantity'];
                        $received = (float) $pl['received_qty'];
                        $c['ordered'] = $ordered;
                        $c['received'] = $received;
                        $ref = $received > 0 ? $received : $ordered;
                        if ($ref > 0) {
                            $c['qty_gap_pct'] = round(($qtyBase - $ref) / $ref * 100, 2);
                            if ($qtyBase > $ref * (1 + self::QTY_TOL)) $flags[] = $received > 0 ? 'qty_over_received' : 'qty_over_ordered';
                            elseif ($received > 0 && $qtyBase < $ref * (1 - self::QTY_TOL)) $flags[] = 'qty_under_received';
                        }
                        $orderPrice = $pl['unit_price'] !== null ? (float) $pl['unit_price'] : null;
                        $c['order_price'] = $orderPrice;
                        if ($orderPrice !== null && $orderPrice > 0 && $priceBase !== null) {
                            $c['price_gap_pct'] = round(($priceBase - $orderPrice) / $orderPrice * 100, 2);
                            if ($priceBase > $orderPrice * (1 + self::PRICE_TOL)) $flags[] = 'price_over';
                            elseif ($priceBase < $orderPrice * (1 - self::PRICE_TOL)) $flags[] = 'price_under';
                            $c['value_gap'] = $net !== null ? round($net - $qtyBase * $orderPrice, 2) : null;
                            $gap += (float) ($c['value_gap'] ?? 0);
                        }
                    }
                }
            }
            $c['flags'] = $flags;
            if (array_intersect($flags, ['unmapped', 'not_in_order', 'qty_over_received', 'qty_over_ordered', 'price_over'])) $issues++;
            $out[] = $c;
        }
        $totalMismatch = $inv['total_net'] !== null && $lines && abs((float) $inv['total_net'] - $sum) > 0.05;
        $state = !$lines ? 'no_lines' : ($mapped < count($lines) ? 'unmapped' : (!$inv['id_po'] ? 'no_order' : ($issues > 0 || $totalMismatch ? 'gaps' : 'clean')));
        return ['lines' => $out, 'summary' => ['state' => $state, 'lines' => count($lines), 'mapped' => $mapped, 'issues' => $issues, 'value_gap' => round($gap, 2),
            'lines_net' => round($sum, 2), 'total_mismatch' => $totalMismatch, 'price_jumps' => $jumps,
            'price_ups' => count(array_filter($ups, fn($u) => $u['decision'] === null)), 'price_ups_decided' => count(array_filter($ups, fn($u) => $u['decision'] !== null))]];
    }

    /**
     * Podwyżki według surowca : cena z faktury (ważona, za jednostkę bazową) przeniesiona do kartoteki tak jak applyPrices
     * (cena opakowania, 2 miejsca) wyższa niż cena bazowa (raw_material.price_per_unit — kalkulacja receptur) o więcej niż
     * supply.invoice_price_up_pct (0 = każda podwyżka). Decyzja ważna, gdy mają ją wszystkie linie surowca, a cena nie wzrosła
     * od zatwierdzenia ; nowe przypisanie albo przelicznik ją kasuje.
     * @return array<int, array{price: float, catalog: float, new_catalog: float, qty: float, decision: ?string, by: ?string, at: ?string}>
     */
    private function priceUps(array $lines, bool $correction = false): array
    {
        if ($correction) return [];
        $byMaterial = [];
        foreach ($lines as $l) if ($l['id_raw_material'] !== null) $byMaterial[(int) $l['id_raw_material']][] = $l;
        $out = [];
        foreach ($this->materialLines($lines) as $mid => $ml) {
            $rows = $byMaterial[$mid];
            $first = $rows[0];
            $catalog = (float) ($first['catalog_price'] ?? 0);
            if ($ml['price'] === null || $catalog <= 0) continue;
            $new = self::catalogPrice($ml['price'], $first['material_unit'] ?? null, $first['package_size'] ?? null);
            if ($new <= $catalog * (1 + $this->upPct() / 100) + 1e-9) continue;
            $decisions = array_values(array_unique(array_map(fn($l) => $l['price_decision'] ?? null, $rows)));
            $okPrice = min(array_map(fn($l) => isset($l['price_ok_price']) ? (float) $l['price_ok_price'] : 0.0, $rows));
            $decision = count($decisions) === 1 && in_array($decisions[0], self::PRICE_DECISIONS, true) && $okPrice > 0 && $ml['price'] <= $okPrice * (1 + 1e-6) ? $decisions[0] : null;
            $out[$mid] = ['price' => $ml['price'], 'catalog' => $catalog, 'new_catalog' => $new, 'qty' => $ml['quantity'], 'decision' => $decision,
                'by' => $decision !== null ? ($first['price_ok_name'] ?? null) : null, 'at' => $decision !== null ? ($first['price_ok_at'] ?? null) : null];
        }
        return $out;
    }

    /** Cena za jednostkę bazową, jaka trafiłaby do kartoteki : cena opakowania (2 miejsca) ÷ opakowanie, 5 miejsc — jak applyPrices. */
    private static function catalogPrice(float $price, ?string $unit, $packageSize): float
    {
        $package = (float) $packageSize > 0 ? (float) $packageSize : (float) CostOptimizationService::displayUnit($unit)['factor'];
        $whole = round($package);
        return $whole > 0 ? round(round($price * $package, 2) / $whole, 5) : $price;
    }

    private function upPct(): float
    {
        return max(0.0, (float) str_replace(',', '.', $this->params->get('supply.invoice_price_up_pct', '0')));
    }

    /**
     * Gotowość do « Zatwierdź fakturę » : blokady — brak linii, linie bez surowca, brak dostawcy ; ostrzeżenia (do potwierdzenia) —
     * rozbieżności z zamówieniem, skok ceny wobec kartoteki (> supply.invoice_price_alert_pct), suma linii ≠ suma faktury.
     * ok = bez blokad i bez ostrzeżeń (wtedy także przetwarzanie automatyczne).
     */
    private function readiness(array $inv, array $control): array
    {
        $s = $control['summary'];
        $blockers = [];
        if ($s['lines'] === 0) $blockers[] = 'no_lines';
        if ($s['mapped'] < $s['lines']) $blockers[] = 'unmapped';
        if ($inv['id_supplier'] === null) $blockers[] = 'no_supplier';
        // Podwyżka ponad cenę bazową bez decyzji : ani automat, ani « Zatwierdź fakturę » (także z potwierdzeniem).
        if (($s['price_ups'] ?? 0) > 0) $blockers[] = 'price_up';
        $warnings = [];
        if ($s['issues'] > 0 && $s['mapped'] === $s['lines']) $warnings[] = 'gaps';
        if ($s['price_jumps'] > 0) $warnings[] = 'price_jump';
        if ($s['total_mismatch']) $warnings[] = 'total_mismatch';
        // Faktura korygująca : akceptacja po potwierdzeniu (nigdy automatycznie), ceny i stan bez zmian.
        if (!empty($inv['is_correction'])) $warnings[] = 'correction';
        return ['ok' => !$blockers && !$warnings, 'blockers' => $blockers, 'warnings' => $warnings, 'unmapped' => $s['lines'] - $s['mapped'], 'price_ups' => $s['price_ups'] ?? 0];
    }

    /** Ilości na stan : do zamówienia (wysłane / częściowo przyjęte) albo — bez zamówienia — przez zamówienie utworzone z faktury. */
    private function canReceive(array $inv, array $control): bool
    {
        return $inv['status'] === 'open' && $inv['kind'] === 'goods' && empty($inv['is_correction']) && $control['summary']['mapped'] > 0
            && ($inv['id_po'] !== null ? in_array($inv['po_status'], ['sent', 'partial'], true) : $inv['id_supplier'] !== null);
    }

    private function alertPct(): float
    {
        return $this->alertPct ??= max(1.0, (float) str_replace(',', '.', $this->params->get('supply.invoice_price_alert_pct', '15')));
    }

    /** Parametry automatu : prices — ceny do kartoteki przy imporcie ; full — także ilości na stan i akceptacja. */
    private function autoMode(): array
    {
        $full = $this->params->get('supply.invoice_auto_full', '0') === '1';
        return ['prices' => $full || $this->params->get('supply.invoice_auto_prices', '1') === '1', 'full' => $full, 'price_alert_pct' => $this->alertPct(), 'price_up_pct' => $this->upPct(),
            'cost_max_months' => max(0, (int) $this->params->get('prices.cost_max_months', '0'))];
    }

    // --- Import ---------------------------------------------------------------------------------------------------

    /** Faktury zakupowe w KSeF za okres (bez zapisu) ; zaznaczone już zaimportowane. */
    public function ksefSearch(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['from', 'to'])->date('from', true, false)->date('to', true, false);
        if (!$v->hasError('from') && !$v->hasError('to') && $v->value('from') > $v->value('to')) $v->add('to', 'DATE_ORDER');
        $v->throwIfInvalid();
        try {
            $rows = $this->ksef->search((string) $v->value('from'), (string) $v->value('to'));
        } catch (\Throwable $e) {
            throw new ApiException('KSEF_UNAVAILABLE', 422, [], ['reason' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $done = array_flip($this->repo->importedKsef(array_column($rows, 'ksef_number')));
        return ['invoices' => array_map(fn($r) => $r + ['imported' => isset($done[$r['ksef_number']])], $rows)];
    }

    /** Import wybranych faktur z KSeF (XML) ; już zaimportowane pomijane. */
    public function ksefImport(array $body, ApiContext $context): array
    {
        $numbers = $body['ksef_numbers'] ?? null;
        $v = (new Validator($body))->only(['ksef_numbers']);
        if (!is_array($numbers) || !$numbers) $v->add('ksef_numbers', 'REQUIRED');
        elseif (count($numbers) > 50) $v->add('ksef_numbers', 'TOO_MANY', ['max' => 50]);
        elseif (array_filter($numbers, fn($n) => !is_string($n) || !preg_match('/^[\w\-]{10,64}$/', $n))) $v->add('ksef_numbers', 'INVALID_CHOICE', ['allowed' => 'KSeF numbers']);
        $v->throwIfInvalid();
        $imported = [];
        $skipped = 0;
        $errors = [];
        $auto = [];
        foreach (array_unique($numbers) as $n) {
            if ($this->repo->findDuplicate($n, null, null) !== null) { $skipped++; continue; }
            try {
                $xml = $this->ksef->xml($n);
                $parsed = InvoiceParser::ksefXml($xml);
                $dup = $this->repo->findDuplicate(null, $parsed['seller_nip'], $parsed['number']);
                if ($dup !== null) { $skipped++; continue; }
                $id = $this->create($parsed, 'ksef', $n, ['filename' => $n . '.xml', 'mime' => 'application/xml', 'content' => $xml, 'text' => null], $context);
                $imported[] = $id;
                if ($done = $this->autoProcess($id, $context)) $auto[] = ['id' => $id, 'number' => $parsed['number']] + $done;
            } catch (\Throwable $e) {
                $errors[] = ['ksef_number' => $n, 'message' => $e instanceof \InvalidArgumentException ? 'NOT_KSEF_XML' : mb_substr($e->getMessage(), 0, 300)];
            }
        }
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'auto' => $auto];
    }

    /**
     * « Pobierz nowe z KSeF » jednym kliknięciem : od ostatniej zaimportowanej faktury KSeF (− 7 dni ; inaczej 30 dni,
     * najwyżej 89 dni wstecz) do dziś, import wszystkich nowych (do 50) z przetwarzaniem automatycznym.
     */
    public function ksefSync(ApiContext $context): array
    {
        $to = date('Y-m-d');
        $last = $this->repo->lastKsefIssueDate();
        $from = max($last !== null ? date('Y-m-d', strtotime($last . ' -7 days')) : date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-89 days')));
        $found = $this->ksefSearch(['from' => $from, 'to' => $to], $context)['invoices'];
        $fresh = array_column(array_filter($found, fn($r) => !$r['imported']), 'ksef_number');
        $result = $fresh ? $this->ksefImport(['ksef_numbers' => array_slice($fresh, 0, 50)], $context) : ['imported' => [], 'skipped' => 0, 'errors' => [], 'auto' => []];
        return ['from' => $from, 'to' => $to, 'found' => count($found), 'more' => max(0, count($fresh) - 50)] + $result;
    }

    /**
     * Plik faktury : XML KSeF (pobrany ręcznie), PDF (tekst pdftotext), skan albo zdjęcie (tekst OCR z przeglądarki,
     * pole ocr_text). PDF bez tekstu (skan) bez ocr_text : PDF_SCANNED — przeglądarka odczytuje strony OCR i wysyła ponownie.
     */
    public function upload(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['filename', 'content_base64', 'ocr_text'])->string('filename', 255)->string('content_base64', (int) (self::MAX_FILE * 1.4))
            ->string('ocr_text', 200000, false, true);
        $v->throwIfInvalid();
        $content = base64_decode((string) $v->value('content_base64'), true);
        if ($content === false || $content === '') throw ApiException::validation([['field' => 'content_base64', 'code' => 'INVALID_FILE', 'params' => []]]);
        if (strlen($content) > self::MAX_FILE) throw ApiException::validation([['field' => 'content_base64', 'code' => 'FILE_TOO_LARGE', 'params' => ['max_kb' => self::MAX_FILE / 1024]]]);
        $filename = basename((string) $v->value('filename'));
        $hasOcr = $v->hasRaw('ocr_text');
        $ocr = InvoiceParser::ocrText((string) $v->value('ocr_text', ''));
        $image = self::imageMime($content);
        if (str_starts_with($content, '%PDF')) {
            try {
                $text = $this->pdfText($content);
            } catch (ApiException $e) {
                if (!$hasOcr) throw $e;
                $text = '';
            }
            if (InvoiceParser::hasText($text)) {
                $source = 'pdf';
            } elseif ($hasOcr) {
                [$text, $source] = [$ocr, 'ocr'];
            } else {
                throw new ApiException('PDF_SCANNED', 422);
            }
            $file = ['filename' => $filename, 'mime' => 'application/pdf', 'content' => $content, 'text' => $text];
            $parsed = InvoiceParser::pdfText($text, $this->repo->companyNip());
        } elseif ($image !== null) {
            // Zdjęcie bez tekstu OCR : zapisane, linie wpisywane ręcznie.
            $source = 'ocr';
            $file = ['filename' => $filename, 'mime' => $image, 'content' => $content, 'text' => $ocr !== '' ? $ocr : null];
            $parsed = InvoiceParser::pdfText($ocr, $this->repo->companyNip());
        } else {
            try {
                $parsed = InvoiceParser::ksefXml($content);
            } catch (\InvalidArgumentException) {
                throw ApiException::validation([['field' => 'content_base64', 'code' => 'INVALID_FILE', 'params' => []]]);
            }
            $source = 'xml';
            $file = ['filename' => $filename, 'mime' => 'application/xml', 'content' => $content, 'text' => null];
        }
        $dup = $this->repo->findDuplicate(null, $parsed['seller_nip'], $parsed['number']);
        if ($dup !== null) throw new ApiException('INVOICE_EXISTS', 409, [], ['id' => $dup]);
        $id = $this->create($parsed, $source, null, $file, $context);
        $auto = $this->autoProcess($id, $context);
        return $this->detail($id, $context) + ['auto_processed' => $auto];
    }

    /** Zdjęcie (JPEG, PNG, WebP) po sygnaturze pliku ; inaczej null. */
    private static function imageMime(string $content): ?string
    {
        if (str_starts_with($content, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($content, "\x89PNG\r\n\x1A\n")) return 'image/png';
        if (str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP') return 'image/webp';
        return null;
    }

    private function pdfText(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tfbinv');
        file_put_contents($tmp, $pdf);
        try {
            $proc = @proc_open(['pdftotext', '-layout', '-enc', 'UTF-8', $tmp, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) throw new ApiException('PDF_TEXT_UNAVAILABLE', 422);
            $text = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code === 127) throw new ApiException('PDF_TEXT_UNAVAILABLE', 422);
            return mb_substr($text, 0, 200000);
        } finally {
            @unlink($tmp);
        }
    }

    /** Zapis faktury : dostawca (NIP zapamiętany albo nazwa), linie z zapamiętanymi przypisaniami, zamówienie podpowiedziane. */
    private function create(array $p, string $source, ?string $ksefNumber, array $file, ApiContext $context): int
    {
        $supplierId = $p['seller_nip'] ? $this->repo->supplierForNip($p['seller_nip']) : null;
        if ($supplierId === null && $p['seller_name']) {
            $best = null;
            $second = 0.0;
            foreach ($this->repo->suppliers() as $s) {
                $score = InvoiceParser::similarity((string) $p['seller_name'], (string) $s['name']);
                if ($best === null || $score > $best[1]) { $second = $best[1] ?? 0.0; $best = [(int) $s['id'], $score]; }
                elseif ($score > $second) $second = $score;
            }
            if ($best && $best[1] >= 0.99 && $second < 0.99) $supplierId = $best[0];
        }
        // Reguła dostawcy (NIP) : usługa z kategorią (Finanse, miesiąc wystawienia) albo odrzucona — od razu przy imporcie.
        $rule = $p['seller_nip'] ? $this->repo->rule((string) $p['seller_nip']) : null;
        $kind = $rule ? ($rule['action'] === 'service' ? ['kind' => 'service', 'cost_category' => $rule['cost_category'], 'service_month' => self::monthOf($p['issue_date'] ?? null), 'id_rule' => (int) $rule['id']]
            : ['status' => 'rejected', 'note' => 'Reguła dostawcy : odrzucona', 'id_rule' => (int) $rule['id']]) : [];
        return $this->tx->execute(function () use ($p, $source, $ksefNumber, $file, $supplierId, $context, $kind) {
            $id = $this->repo->insert($kind + ['source' => $source, 'ksef_number' => $ksefNumber, 'id_supplier' => $supplierId, 'seller_nip' => $p['seller_nip'],
                'seller_name' => $p['seller_name'] !== null ? mb_substr($p['seller_name'], 0, 255) : null, 'number' => $p['number'] !== null ? mb_substr($p['number'], 0, 80) : null,
                'issue_date' => $p['issue_date'], 'currency' => $p['currency'] ?: 'PLN', 'total_net' => $p['total_net'], 'total_gross' => $p['total_gross'],
                // Faktura korygująca : linie « przed korektą » to nie zakupy — bez cen i stanu.
                'is_correction' => !empty($p['correction']) || ($p['total_net'] !== null && (float) $p['total_net'] < 0) ? 1 : 0,
                'status' => 'open', 'created_by' => $context->userId]);
            $this->repo->saveFile($id, $file['filename'], $file['mime'], $file['content'], $file['text']);
            if ($supplierId !== null && $p['seller_nip']) $this->repo->rememberSupplier($p['seller_nip'], $supplierId);
            $inv = $this->repo->invoice($id);
            $goods = $inv['kind'] === 'goods' && $inv['status'] === 'open';
            foreach ($p['lines'] as $k => $l) $this->repo->insertLine($id, ['line_no' => $l['line_no'] ?? $k + 1] + $l + ($goods ? $this->known($inv, $l) : []));
            if ($goods) $this->autoLink($id);
            $this->audit->record($context, 'create', 'purchase_invoice', $id, null, ['source' => $source, 'ksef_number' => $ksefNumber, 'number' => $p['number'], 'lines' => count($p['lines'])]
                + ($kind ? ['rule' => $kind['id_rule'], 'kind' => $inv['kind'], 'status' => $inv['status']] : []));
            return $id;
        });
    }

    /** Zapamiętane przypisanie linii (dostawca + nazwa + jednostka). */
    private function known(array $inv, array $l): array
    {
        foreach ($this->supplierKeys($inv) as $key) {
            $m = $this->repo->mapping($key, InvoiceParser::nameKey((string) $l['name']), InvoiceParser::unitKey($l['unit'] ?? null));
            if ($m) return ['id_raw_material' => (int) $m['id_raw_material'], 'factor' => (float) $m['factor']];
        }
        return [];
    }

    /** @return list<string> klucze dostawcy : NIP, potem dostawca ERP */
    private function supplierKeys(array $inv): array
    {
        $keys = [];
        if ($inv['seller_nip']) $keys[] = 'nip:' . $inv['seller_nip'];
        if ($inv['id_supplier']) $keys[] = 'sup:' . $inv['id_supplier'];
        return $keys;
    }

    /** Bez zamówienia : powiązanie z zamówieniem dostawcy, które ma najwięcej surowców faktury (nie zafakturowane). */
    private function autoLink(int $id): void
    {
        $inv = $this->repo->invoice($id);
        if ($inv['id_po'] !== null || $inv['id_supplier'] === null) return;
        $best = $this->candidates((int) $inv['id_supplier'], $this->repo->lines($id))[0] ?? null;
        if ($best && $best['overlap'] > 0 && $best['invoices'] === 0) $this->repo->update($id, ['id_po' => $best['id']]);
    }

    /** Dostawcy ERP do wyboru : najpierw najbardziej podobni do nazwy sprzedawcy. */
    private function supplierChoices(array $i): array
    {
        $rows = array_map(fn($s) => ['id' => (int) $s['id'], 'name' => trim((string) $s['name']),
            'score' => $i['seller_name'] ? round(InvoiceParser::similarity((string) $i['seller_name'], (string) $s['name']), 2) : 0.0], $this->repo->suppliers());
        usort($rows, fn($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);
        return $rows;
    }

    private function candidates(int $supplierId, array $lines): array
    {
        $materials = array_filter(array_map(fn($l) => $l['id_raw_material'] !== null ? (int) $l['id_raw_material'] : null, $lines));
        $rows = array_map(function ($o) use ($materials) {
            $poMaterials = array_map('intval', array_filter(explode(',', (string) $o['materials'])));
            return ['id' => (int) $o['id'], 'code' => $o['code'], 'status' => $o['status'], 'week_start' => $o['week_start'], 'delivery_date' => $o['delivery_date'],
                'invoices' => (int) $o['invoices'], 'overlap' => count(array_intersect($materials, $poMaterials)), 'lines' => count($poMaterials)];
        }, $this->repo->orderCandidates($supplierId));
        usort($rows, fn($a, $b) => [$b['overlap'], $a['invoices']] <=> [$a['overlap'], $b['invoices']]);
        return $rows;
    }

    /** Podpowiedzi surowca : z zamówienia, od dostawcy, potem wszystkie — wg podobieństwa nazw. */
    private function suggest(array $l, array $inv, array $materials, array $orderMaterials, string $lang): array
    {
        $scored = [];
        foreach ($materials as $m) {
            $score = InvoiceParser::similarity((string) $l['name'], (string) $m['name']);
            if (in_array((int) $m['id'], $orderMaterials, true)) $score += 0.3;
            if ($inv['id_supplier'] && (int) $m['id_current_supplier'] === (int) $inv['id_supplier']) $score += 0.2;
            if ($score >= 0.3) $scored[] = [$score, $m];
        }
        usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
        return array_map(fn($x) => ['raw_material_id' => (int) $x[1]['id'], 'name' => $lang === 'fr' && $x[1]['name_fr'] ? $x[1]['name_fr'] : $x[1]['name'], 'score' => round($x[0], 2),
            'base_unit' => $x[1]['unit'] ?: 'szt', 'factor' => InvoiceParser::defaultFactor($l['unit'], $x[1]['unit'], $x[1]['package_size'] !== null ? (float) $x[1]['package_size'] : null, (string) $l['name'])],
            array_slice($scored, 0, 5));
    }

    // --- Zmiany -------------------------------------------------------------------------------------------------------

    public function update(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->open($id);
        $v = (new Validator($body, true))->only(['supplier_id', 'po_id', 'number', 'issue_date', 'note'])
            ->integer('supplier_id', false, 1, null, true)->integer('po_id', false, 1, null, true)->string('number', 80, false, true)->date('issue_date')->string('note', 1000, false, true);
        $supplierId = $v->provided('supplier_id') ? $v->value('supplier_id') : ($inv['id_supplier'] !== null ? (int) $inv['id_supplier'] : null);
        if ($v->value('supplier_id') !== null && !$v->hasError('supplier_id') && !$this->repo->supplierExists((int) $v->value('supplier_id'))) $v->add('supplier_id', 'REFERENCE_NOT_FOUND', ['id' => (string) $v->value('supplier_id')]);
        if ($v->value('po_id') !== null && !$v->hasError('po_id') && ($supplierId === null || !$this->repo->orderOfSupplier((int) $v->value('po_id'), (int) $supplierId))) $v->add('po_id', 'REFERENCE_NOT_FOUND', ['id' => (string) $v->value('po_id')]);
        $v->throwIfInvalid();
        $data = [];
        foreach (['supplier_id' => 'id_supplier', 'po_id' => 'id_po', 'number' => 'number', 'issue_date' => 'issue_date', 'note' => 'note'] as $in => $col) if ($v->provided($in)) $data[$col] = $v->value($in);
        if (array_key_exists('id_supplier', $data) && (string) $data['id_supplier'] !== (string) $inv['id_supplier'] && !array_key_exists('id_po', $data)) $data['id_po'] = null;
        $this->tx->execute(function () use ($id, $inv, $data, $context) {
            if ($data) $this->repo->update($id, $data);
            if (!empty($data['id_supplier']) && $inv['seller_nip']) $this->repo->rememberSupplier($inv['seller_nip'], (int) $data['id_supplier']);
            // Nowy dostawca : zapamiętane przypisania tego dostawcy dla linii jeszcze bez surowca.
            if (!empty($data['id_supplier'])) {
                $fresh = $this->repo->invoice($id);
                foreach ($this->repo->lines($id) as $l) {
                    if ($l['id_raw_material'] !== null) continue;
                    $k = $this->known($fresh, $l);
                    if ($k) $this->repo->mapLine((int) $l['id'], $k['id_raw_material'], $k['factor']);
                }
                if (!array_key_exists('id_po', $data) || $data['id_po'] === null) $this->autoLink($id);
            }
            $this->audit->record($context, 'update', 'purchase_invoice', $id, array_intersect_key($inv, $data), $data);
        });
        return $this->detail($id, $context);
    }

    /** Linie faktury z PDF / ręcznej (zastępowane) ; przypisania zapamiętane stosowane od razu. */
    public function saveLines(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->open($id);
        if (!in_array($inv['source'], self::EDITABLE_SOURCES, true)) throw new ApiException('INVOICE_LINES_READ_ONLY', 409);
        $v = (new Validator($body))->only(['lines']);
        $rows = $body['lines'] ?? null;
        $clean = [];
        if (!is_array($rows)) $v->add('lines', 'NOT_ARRAY');
        elseif (count($rows) > 300) $v->add('lines', 'TOO_MANY', ['max' => 300]);
        else foreach (array_values($rows) as $i => $r) {
            $name = is_array($r) ? trim((string) ($r['name'] ?? '')) : '';
            if ($name === '') { $v->add("lines.$i.name", 'REQUIRED'); continue; }
            $qty = $r['quantity'] ?? null;
            if ((!is_int($qty) && !is_float($qty)) || $qty <= 0) { $v->add("lines.$i.quantity", 'GREATER_THAN', ['min' => 0]); continue; }
            $price = $r['unit_price'] ?? null;
            $net = $r['net_value'] ?? null;
            if ($price !== null && ((!is_int($price) && !is_float($price)) || $price < 0)) { $v->add("lines.$i.unit_price", 'MIN', ['min' => 0]); continue; }
            if ($net !== null && ((!is_int($net) && !is_float($net)) || $net < 0)) { $v->add("lines.$i.net_value", 'MIN', ['min' => 0]); continue; }
            $clean[] = ['line_no' => $i + 1, 'name' => mb_substr($name, 0, 500), 'code' => isset($r['code']) && $r['code'] !== '' ? mb_substr((string) $r['code'], 0, 80) : null,
                'quantity' => (float) $qty, 'unit' => isset($r['unit']) && $r['unit'] !== '' ? mb_substr((string) $r['unit'], 0, 20) : null,
                'unit_price' => $price !== null ? (float) $price : ($net !== null ? (float) $net / (float) $qty : null),
                'net_value' => $net !== null ? round((float) $net, 2) : ($price !== null ? round((float) $price * (float) $qty, 2) : null),
                'vat_rate' => isset($r['vat_rate']) && $r['vat_rate'] !== '' ? mb_substr((string) $r['vat_rate'], 0, 8) : null];
        }
        $v->throwIfInvalid();
        $before = [];
        foreach ($this->repo->lines($id) as $l) $before[InvoiceParser::nameKey($l['name']) . '|' . InvoiceParser::unitKey($l['unit'])] = $l;
        $this->tx->execute(function () use ($id, $inv, $clean, $before, $context) {
            $this->repo->deleteLines($id);
            foreach ($clean as $l) {
                $prev = $before[InvoiceParser::nameKey($l['name']) . '|' . InvoiceParser::unitKey($l['unit'])] ?? null;
                $map = $prev && $prev['id_raw_material'] !== null ? ['id_raw_material' => (int) $prev['id_raw_material'], 'factor' => (float) $prev['factor']] : $this->known($inv, $l);
                $this->repo->insertLine($id, $l + $map);
            }
            if ($inv['total_net'] === null || $inv['source'] === 'manual') $this->repo->update($id, ['total_net' => round(array_sum(array_map(fn($l) => (float) ($l['net_value'] ?? 0), $clean)), 2)]);
            $this->autoLink($id);
            $this->audit->record($context, 'update', 'purchase_invoice_lines', $id, null, ['lines' => count($clean)]);
        });
        return $this->detail($id, $context);
    }

    /** Przypisanie linii do surowca (null = usuń) ; remember = zapamiętaj dla dostawcy i zastosuj do takich samych linii. */
    public function mapLine(int $id, int $lineId, array $body, ApiContext $context): array
    {
        $inv = $this->open($id);
        $line = $this->repo->line($id, $lineId);
        if ($line === null) throw ApiException::notFound('purchase_invoice_line', $lineId);
        $v = (new Validator($body))->only(['raw_material_id', 'factor', 'remember'])
            ->integer('raw_material_id', true, 1, null, true)->decimal('factor', false, 0, 10_000_000, true, true)->boolean('remember');
        $mid = $v->value('raw_material_id');
        $material = $mid !== null && !$v->hasError('raw_material_id') ? $this->repo->material((int) $mid) : null;
        if ($mid !== null && !$v->hasError('raw_material_id') && $material === null) $v->add('raw_material_id', 'REFERENCE_NOT_FOUND', ['id' => (string) $mid]);
        $v->throwIfInvalid();
        // Bez przelicznika : domyślny z jednostek (kg → g = 1000), z wagi w nazwie (« WOREK 25KG ») albo opakowanie surowca.
        $factor = $mid === null ? null : (float) ($v->value('factor')
            ?? InvoiceParser::defaultFactor($line['unit'], $material['unit'], $material['package_size'] !== null ? (float) $material['package_size'] : null, (string) $line['name']));
        $remember = $v->value('remember') !== false;
        $keys = $this->supplierKeys($inv);
        $this->tx->execute(function () use ($id, $line, $mid, $factor, $remember, $keys, $inv, $context) {
            $nameKey = InvoiceParser::nameKey($line['name']);
            $unitKey = InvoiceParser::unitKey($line['unit']);
            foreach ($this->repo->lines($id) as $l) {
                if ((int) $l['id'] === (int) $line['id'] || ($remember && InvoiceParser::nameKey($l['name']) === $nameKey && InvoiceParser::unitKey($l['unit']) === $unitKey)) {
                    $this->repo->mapLine((int) $l['id'], $mid, $factor);
                }
            }
            if ($remember && $mid !== null && $keys) {
                $this->repo->saveMapping($keys[0], $inv['id_supplier'] !== null ? (int) $inv['id_supplier'] : null, $nameKey, $unitKey, $line['name'], $line['unit'], (int) $mid, (float) $factor, $context->userId);
            }
            $this->autoLink($id);
            $this->audit->record($context, 'map', 'purchase_invoice_line', (int) $line['id'], ['raw_material_id' => $line['id_raw_material'], 'factor' => $line['factor']],
                ['raw_material_id' => $mid, 'factor' => $factor, 'remember' => $remember]);
        });
        return $this->detail($id, $context);
    }

    /**
     * « Przypisz wszystkie » : kilka linii naraz (surowiec + przelicznik), zapamiętane dla dostawcy — jedno kliknięcie
     * zamiast linia po linii ; przelicznik pominięty = domyślny (jednostki, waga w nazwie, opakowanie).
     */
    public function mapLines(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->open($id);
        $v = (new Validator($body))->only(['lines', 'remember'])->boolean('remember');
        $rows = $body['lines'] ?? null;
        $clean = [];
        if (!is_array($rows) || !$rows) $v->add('lines', 'REQUIRED');
        elseif (count($rows) > 300) $v->add('lines', 'TOO_MANY', ['max' => 300]);
        else foreach (array_values($rows) as $k => $r) {
            $lineId = is_array($r) ? ($r['line_id'] ?? null) : null;
            $mid = is_array($r) ? ($r['raw_material_id'] ?? null) : null;
            $factor = is_array($r) ? ($r['factor'] ?? null) : null;
            $line = is_int($lineId) ? $this->repo->line($id, $lineId) : null;
            if ($line === null) { $v->add("lines.$k.line_id", 'REFERENCE_NOT_FOUND', ['id' => (string) json_encode($lineId)]); continue; }
            $material = is_int($mid) ? $this->repo->material($mid) : null;
            if ($material === null) { $v->add("lines.$k.raw_material_id", 'REFERENCE_NOT_FOUND', ['id' => (string) json_encode($mid)]); continue; }
            if ($factor !== null && ((!is_int($factor) && !is_float($factor)) || $factor <= 0 || $factor > 10_000_000)) { $v->add("lines.$k.factor", 'GREATER_THAN', ['min' => 0]); continue; }
            $clean[] = [$line, $mid, (float) ($factor ?? InvoiceParser::defaultFactor($line['unit'], $material['unit'], $material['package_size'] !== null ? (float) $material['package_size'] : null, (string) $line['name']))];
        }
        $v->throwIfInvalid();
        $remember = $v->value('remember') !== false;
        $keys = $this->supplierKeys($inv);
        $this->tx->execute(function () use ($id, $inv, $clean, $remember, $keys, $context) {
            foreach ($clean as [$line, $mid, $factor]) {
                $this->repo->mapLine((int) $line['id'], $mid, $factor);
                if ($remember && $keys) {
                    $this->repo->saveMapping($keys[0], $inv['id_supplier'] !== null ? (int) $inv['id_supplier'] : null, InvoiceParser::nameKey($line['name']), InvoiceParser::unitKey($line['unit']),
                        $line['name'], $line['unit'], $mid, $factor, $context->userId);
                }
            }
            $this->autoLink($id);
            $this->audit->record($context, 'map_all', 'purchase_invoice', $id, null, ['lines' => array_map(fn($x) => [(int) $x[0]['id'], $x[1], $x[2]], $clean), 'remember' => $remember]);
        });
        return $this->detail($id, $context);
    }

    /**
     * « Zatwierdź fakturę » jednym kliknięciem : ceny do kartoteki (jeśli jeszcze nie), ilości na stan (jeśli jeszcze nie
     * przyjęte i jest dokąd), akceptacja — wszystko albo nic. prices / stock / accept (domyślnie true) wybierają kroki.
     * Blokady : INVOICE_NO_LINES, INVOICE_UNMAPPED, INVOICE_NO_SUPPLIER (422) ; ostrzeżenia bez force : INVOICE_NEEDS_CONFIRMATION (409).
     */
    public function process(int $id, array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['prices', 'stock', 'accept', 'force'])->boolean('prices')->boolean('stock')->boolean('accept')->boolean('force');
        $v->throwIfInvalid();
        $steps = array_values(array_filter(self::STEPS, fn($s) => $v->value($s) !== false));
        $done = $this->runProcess($id, $steps, $v->value('force') === true, $context, 'process');
        return $this->detail($id, $context) + ['processed' => $done];
    }

    /** « Zatwierdź gotowe » : wszystkie otwarte faktury bez blokad i ostrzeżeń, każda jak « Zatwierdź fakturę ». */
    public function processReady(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['prices', 'stock', 'accept'])->boolean('prices')->boolean('stock')->boolean('accept');
        $v->throwIfInvalid();
        $steps = array_values(array_filter(self::STEPS, fn($s) => $v->value($s) !== false));
        $done = [];
        $errors = [];
        $left = 0;
        foreach ($this->repo->invoices('open') as $inv) {
            if (!$this->readiness($inv, $this->control($inv, $this->repo->lines((int) $inv['id'])))['ok']) { $left++; continue; }
            try {
                $done[] = ['id' => (int) $inv['id'], 'number' => $inv['number']] + $this->runProcess((int) $inv['id'], $steps, false, $context, 'process');
            } catch (ApiException $e) {
                $errors[] = ['id' => (int) $inv['id'], 'number' => $inv['number'], 'code' => $e->errorCode()];
                $left++;
            }
        }
        return ['processed' => $done, 'errors' => $errors, 'left' => $left];
    }

    /** @param list<string> $steps */
    private function runProcess(int $id, array $steps, bool $force, ApiContext $context, string $action): array
    {
        $inv = $this->open($id);
        $control = $this->control($inv, $this->repo->lines($id));
        $ready = $this->readiness($inv, $control);
        if (in_array('no_lines', $ready['blockers'], true)) throw new ApiException('INVOICE_NO_LINES', 422);
        if (in_array('unmapped', $ready['blockers'], true)) throw new ApiException('INVOICE_UNMAPPED', 422, [], ['n' => $ready['unmapped']]);
        if (in_array('no_supplier', $ready['blockers'], true)) throw new ApiException('INVOICE_NO_SUPPLIER', 422);
        if (in_array('price_up', $ready['blockers'], true)) throw new ApiException('INVOICE_PRICE_UP_PENDING', 409, [], ['n' => $ready['price_ups']]);
        if ($ready['warnings'] && !$force) throw new ApiException('INVOICE_NEEDS_CONFIRMATION', 409, [], ['warnings' => implode(', ', $ready['warnings'])]);
        return $this->tx->execute(function () use ($id, $inv, $control, $steps, $force, $context, $action, $ready) {
            $out = ['prices' => 0, 'prices_skipped' => [], 'prices_kept' => [], 'prices_held' => [], 'stock' => false, 'accepted' => false];
            // Linie bez ceny, ilości już przyjęte albo spoza zamówienia : krok pominięty, reszta wykonana.
            if (in_array('prices', $steps, true) && $inv['prices_imported_at'] === null) {
                try {
                    $p = $this->applyPrices($inv, $this->repo->lines($id), $context);
                    $out['prices'] = count($p['changes']);
                    $out['prices_skipped'] = $p['skipped'];
                    $out['prices_kept'] = $p['kept'];
                    $out['prices_held'] = $p['held'];
                } catch (ApiException $e) {
                    if (!in_array($e->errorCode(), ['INVOICE_NO_PRICES', 'INVOICE_CORRECTION'], true)) throw $e;
                }
            }
            if (in_array('stock', $steps, true) && $this->canReceive($inv, $control)) {
                try {
                    $this->applyReceive($inv, $context);
                    $out['stock'] = true;
                } catch (ApiException $e) {
                    if (!in_array($e->errorCode(), ['INVOICE_ALREADY_RECEIVED', 'INVOICE_NOTHING_TO_RECEIVE'], true)) throw $e;
                }
            }
            if (in_array('accept', $steps, true)) {
                $this->repo->update($id, ['status' => 'accepted', 'decided_by' => $context->userId, 'decided_at' => date('Y-m-d H:i:s')]);
                $out['accepted'] = true;
            }
            $this->audit->record($context, $action, 'purchase_invoice', $id, ['status' => $inv['status']], $out + ['steps' => $steps, 'force' => $force, 'warnings' => $ready['warnings']]);
            return $out;
        });
    }

    /**
     * Automatycznie po imporcie (KSeF, plik) — tylko faktura w pełni rozpoznana : dostawca znany, wszystkie linie
     * z zapamiętanych przypisań, bez rozbieżności, skoku ceny i różnicy sum. supply.invoice_auto_prices (1) : ceny do kartoteki ;
     * supply.invoice_auto_full (0) : także ilości na stan i akceptacja. Błąd nie przerywa importu (faktura czeka na « Zatwierdź »).
     */
    private function autoProcess(int $id, ApiContext $context): ?array
    {
        $mode = $this->autoMode();
        if (!$mode['prices']) return null;
        $inv = $this->repo->invoice($id);
        if ($inv === null || $inv['kind'] !== 'goods' || $inv['status'] !== 'open' || !$this->readiness($inv, $this->control($inv, $this->repo->lines($id)))['ok']) return null;
        try {
            return $this->runProcess($id, $mode['full'] ? self::STEPS : ['prices'], false, $context, 'auto_process');
        } catch (\Throwable $e) {
            error_log('[invoice auto] #' . $id . ' ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Decyzja o podwyżkach ceny ponad cenę bazową : accept — cena z faktury zostanie nową ceną bazową (kalkulacja receptur)
     * przy « Zatwierdź fakturę » / imporcie cen ; keep — cena bazowa bez zmian, faktura może być zaakceptowana.
     * raw_material_ids : wybrane surowce (domyślnie wszystkie bez decyzji). Kto i kiedy — na liniach i w dzienniku.
     */
    public function decidePrices(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->open($id);
        $v = (new Validator($body))->only(['decision', 'raw_material_ids'])->choice('decision', self::PRICE_DECISIONS);
        $ids = $body['raw_material_ids'] ?? null;
        if ($ids !== null && (!is_array($ids) || !$ids || array_filter($ids, fn($x) => !is_int($x)))) $v->add('raw_material_ids', 'NOT_ARRAY');
        $v->throwIfInvalid();
        $ups = $this->priceUps($this->repo->lines($id), !empty($inv['is_correction']));
        $chosen = $ids === null ? array_keys(array_filter($ups, fn($u) => $u['decision'] === null)) : array_values(array_unique($ids));
        $unknown = array_values(array_filter($chosen, fn($mid) => !isset($ups[$mid])));
        if ($unknown) throw ApiException::validation([['field' => 'raw_material_ids', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => implode(', ', array_keys($ups)) ?: '—']]]);
        if (!$chosen) throw new ApiException('INVOICE_NO_PRICE_UP', 409);
        $decision = (string) $v->value('decision');
        $this->tx->execute(function () use ($id, $chosen, $ups, $decision, $context) {
            foreach ($chosen as $mid) $this->repo->decidePrice($id, (int) $mid, $decision, $ups[$mid]['price'], $context->userId);
            $this->audit->record($context, 'price_decision', 'purchase_invoice', $id, null, ['decision' => $decision,
                'materials' => array_map(fn($mid) => [(int) $mid, $ups[$mid]['catalog'], $ups[$mid]['new_catalog']], $chosen)]);
        });
        return $this->detail($id, $context) + ['decided' => count($chosen)];
    }

    /** Otwarte faktury z podwyżką bez decyzji (kolejka « Do obsłużenia », Dzisiaj). */
    public function pendingPriceUps(): array
    {
        $out = [];
        foreach ($this->repo->invoices('open') as $inv) {
            $n = count(array_filter($this->priceUps($this->repo->lines((int) $inv['id']), !empty($inv['is_correction'])), fn($u) => $u['decision'] === null));
            if ($n > 0) $out[] = ['id' => (int) $inv['id'], 'number' => $inv['number'], 'issue_date' => $inv['issue_date'],
                'supplier' => trim((string) ($inv['supplier_name'] ?? '')) ?: $inv['seller_name'], 'total_net' => $inv['total_net'], 'currency' => $inv['currency'], 'materials' => $n];
        }
        return $out;
    }

    /** Podwyżki do pokazania : surowiec, cena bazowa i nowa (jednostka wyświetlana), zmiana %, dopłata na tej fakturze, receptury, decyzja. */
    private function presentUps(array $ups, array $lines, string $lang): array
    {
        if (!$ups) return [];
        $recipes = $this->repo->recipesUsing(array_keys($ups));
        $first = [];
        foreach ($lines as $l) if ($l['id_raw_material'] !== null) $first[(int) $l['id_raw_material']] ??= $l;
        $out = [];
        foreach ($ups as $mid => $u) {
            $l = $first[$mid];
            $unit = CostOptimizationService::displayUnit($l['material_unit']);
            $out[] = ['raw_material_id' => $mid, 'name' => $lang === 'fr' && $l['material_name_fr'] ? $l['material_name_fr'] : $l['material_name'],
                'unit' => $unit['label'], 'factor' => $unit['factor'], 'base_price' => $u['catalog'], 'invoice_price' => round($u['price'], 8), 'new_price' => $u['new_catalog'],
                'change_pct' => round(($u['new_catalog'] - $u['catalog']) / $u['catalog'] * 100, 2), 'quantity' => $u['qty'],
                'extra' => round($u['qty'] * ($u['price'] - $u['catalog']), 2), 'recipes' => $recipes[$mid] ?? 0,
                'decision' => $u['decision'], 'decided_by' => $u['by'], 'decided_at' => $u['at']];
        }
        usort($out, fn($a, $b) => [$a['decision'] !== null, -$a['extra']] <=> [$b['decision'] !== null, -$b['extra']]);
        return $out;
    }

    public function decide(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->load($id);
        if ($inv['kind'] !== 'goods' || $inv['status'] === 'rejected') throw new ApiException('INVOICE_NOT_GOODS', 409);
        $v = (new Validator($body))->only(['status', 'note'])->choice('status', self::STATUSES)->string('note', 1000, false, true);
        $v->throwIfInvalid();
        $status = $v->value('status');
        $data = ['status' => $status, 'decided_by' => $status === 'open' ? null : $context->userId, 'decided_at' => $status === 'open' ? null : date('Y-m-d H:i:s')];
        if ($v->provided('note')) $data['note'] = $v->value('note');
        $this->tx->execute(function () use ($id, $inv, $data, $context) {
            $this->repo->update($id, $data);
            $this->audit->record($context, 'decide', 'purchase_invoice', $id, ['status' => $inv['status']], $data);
        });
        return $this->detail($id, $context);
    }

    // --- Rodzaj faktury : surowce i opakowania ↔ usługa (Finanse) ↔ odrzucona ----------------------------------------

    /**
     * Klasyfikacja (makieta 2) : service — koszt firmy w Finansach (kategoria kalkulatora kosztów, miesiąc kosztu — domyślnie
     * miesiąc wystawienia) ; reject — nie dotyczy firmy (notatka) ; goods — z powrotem do zaopatrzenia (zapamiętane przypisania
     * wracają). rule : « zawsze dla tego dostawcy » (NIP) — reguła stosowana przy imporcie i od razu do innych otwartych faktur
     * tego NIP ; goods + rule usuwa regułę. Faktura przetworzona (ceny w kartotece, zamówienie, decyzja) zostaje w zaopatrzeniu ;
     * zamknięty miesiąc usług — najpierw go otworzyć. Linie faktury usługi / odrzuconej bez surowców (ceny i stany bez zmian).
     */
    public function classify(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->load($id);
        $v = (new Validator($body))->only(['action', 'cost_category', 'service_month', 'note', 'rule'])->choice('action', self::CLASSIFY)
            ->string('cost_category', 40, false, true)->string('service_month', 7, false, true, '/^\d{4}-(0[1-9]|1[0-2])$/', 'INVALID_MONTH')
            ->string('note', 500, false, true)->boolean('rule');
        $action = $v->value('action');
        $cats = $this->categories->all();
        $category = $v->value('cost_category');
        if ($action === 'service' && $category === null) $v->add('cost_category', 'REQUIRED');
        elseif ($action === 'service' && !isset($cats[$category])) $v->add('cost_category', 'INVALID_CHOICE', ['allowed' => implode(', ', array_keys($cats))]);
        $rule = (bool) $v->value('rule', false);
        if ($rule && !$inv['seller_nip']) $v->add('rule', 'NO_SELLER_NIP');
        $v->throwIfInvalid();
        $this->assertMovable($inv, $action);
        $month = $action === 'service' ? ($v->value('service_month') ?? self::monthOf($inv['issue_date']) ?? date('Y-m')) : null;
        if ($month !== null && $this->finance->closed($month)) throw new ApiException('SERVICE_MONTH_CLOSED', 409, [], ['month' => $month]);
        $note = $v->value('note');
        $applied = [];
        $this->tx->execute(function () use ($inv, $action, $category, $month, $note, $rule, $context, &$applied) {
            $ruleId = null;
            if ($rule && $action === 'goods') {
                $r = $this->repo->rule((string) $inv['seller_nip']);
                if ($r) {
                    $this->repo->deleteRule((int) $r['id']);
                    $this->audit->record($context, 'delete', 'purchase_invoice_rule', (int) $r['id'], ['seller_nip' => $r['seller_nip'], 'action' => $r['action']], null);
                }
            } elseif ($rule) {
                $ruleId = $this->repo->saveRule((string) $inv['seller_nip'], $inv['seller_name'], $action === 'service' ? 'service' : 'reject', $action === 'service' ? $category : null, $context->userId);
                $this->audit->record($context, 'save', 'purchase_invoice_rule', $ruleId, null, ['seller_nip' => $inv['seller_nip'], 'action' => $action, 'cost_category' => $category]);
            }
            $this->applyKind($inv, $action, $category, $month, $note, $ruleId, $context);
            if ($ruleId !== null) {
                foreach ($this->repo->openGoodsOfNip((string) $inv['seller_nip'], (int) $inv['id']) as $other) {
                    $m = $action === 'service' ? (self::monthOf($other['issue_date']) ?? $month) : null;
                    if ($m !== null && $this->finance->closed($m)) continue;
                    $this->applyKind($other, $action, $category, $m, null, $ruleId, $context);
                    $applied[] = (int) $other['id'];
                }
            }
        });
        return $this->detail($id, $context) + ['applied' => $applied];
    }

    /** Faktura do przeniesienia : surowce bez przetworzenia, usługa w otwartym miesiącu. */
    private function assertMovable(array $inv, string $action): void
    {
        if ($inv['kind'] === 'goods' && $inv['status'] !== 'rejected' && $action !== 'goods'
            && ($inv['status'] !== 'open' || $inv['prices_imported_at'] !== null || $inv['id_po'] !== null)) {
            throw new ApiException('INVOICE_PROCESSED', 409);
        }
        if ($inv['kind'] === 'service' && $inv['service_month'] !== null && $this->finance->closed($inv['service_month'])) {
            throw new ApiException('SERVICE_MONTH_CLOSED', 409, [], ['month' => $inv['service_month']]);
        }
    }

    private function applyKind(array $inv, string $action, ?string $category, ?string $month, ?string $note, ?int $ruleId, ApiContext $context): void
    {
        $id = (int) $inv['id'];
        if ($action === 'goods') {
            $data = ['kind' => 'goods', 'status' => 'open', 'cost_category' => null, 'service_month' => null, 'cost_amount' => null, 'id_rule' => null, 'decided_by' => null, 'decided_at' => null];
        } elseif ($action === 'service') {
            $keep = $inv['kind'] === 'service' && $inv['status'] === 'accepted' && $inv['cost_category'] === $category && $inv['service_month'] === $month;
            $data = ['kind' => 'service', 'status' => $keep ? 'accepted' : 'open', 'cost_category' => $category, 'service_month' => $month, 'id_rule' => $ruleId ?? ($inv['kind'] === 'service' ? $inv['id_rule'] : null)]
                + ($keep ? [] : ['decided_by' => null, 'decided_at' => null]);
        } else {
            $data = ['status' => 'rejected', 'id_rule' => $ruleId, 'decided_by' => $context->userId, 'decided_at' => date('Y-m-d H:i:s'), 'note' => $note ?? ($ruleId !== null ? 'Reguła dostawcy : odrzucona' : $inv['note'])];
        }
        $this->repo->update($id, $data);
        if ($action === 'goods') {
            $fresh = $this->repo->invoice($id);
            foreach ($this->repo->lines($id) as $l) {
                $known = $l['id_raw_material'] === null ? $this->known($fresh, $l) : [];
                if ($known) $this->repo->mapLine((int) $l['id'], $known['id_raw_material'], $known['factor']);
            }
            $this->autoLink($id);
        } else {
            $this->repo->unmapLines($id);
        }
        $this->audit->record($context, 'classify', 'purchase_invoice', $id, ['kind' => $inv['kind'], 'status' => $inv['status'], 'cost_category' => $inv['cost_category'], 'service_month' => $inv['service_month']],
            ['action' => $action] + $data);
    }

    /** Reguły dostawców (NIP → zawsze usługa z kategorią albo zawsze odrzucona). */
    public function rules(ApiContext $context): array
    {
        $cats = $this->categories->all();
        return ['rules' => array_map(fn($r) => ['id' => (int) $r['id'], 'seller_nip' => $r['seller_nip'], 'seller_name' => $r['seller_name'], 'action' => $r['action'],
            'cost_category' => $r['cost_category'], 'category' => $r['cost_category'] !== null && isset($cats[$r['cost_category']]) ? $cats[$r['cost_category']]['label'] : null,
            'invoices' => (int) $r['invoices'], 'last_invoice' => $r['last_invoice'], 'created_by' => $r['created_by_name'], 'created_at' => $r['created_at']], $this->repo->rules()),
            'can_write' => $context->can('prod.supply.write')];
    }

    /** Usunięcie reguły : kolejne faktury tego dostawcy wracają do zaopatrzenia (już sklasyfikowane zostają). */
    public function deleteRule(int $id, ApiContext $context): array
    {
        $r = $this->repo->findRule($id) ?? throw ApiException::notFound('purchase_invoice_rule', $id);
        $this->tx->execute(function () use ($id, $r, $context) {
            $this->repo->deleteRule($id);
            $this->audit->record($context, 'delete', 'purchase_invoice_rule', $id, ['seller_nip' => $r['seller_nip'], 'action' => $r['action'], 'cost_category' => $r['cost_category']], null);
        });
        return ['deleted' => true, 'id' => $id];
    }

    /** RRRR-MM z daty (null bez daty). */
    public static function monthOf(?string $date): ?string
    {
        return $date !== null && preg_match('/^\d{4}-\d{2}/', $date) ? substr($date, 0, 7) : null;
    }

    /** Przyjęcie dostawy wg faktury : ilości i ceny netto z faktury do zamówienia (linie przypisane do jego surowców). */
    public function receive(int $id, ApiContext $context): array
    {
        $inv = $this->open($id);
        if (!empty($inv['is_correction'])) throw new ApiException('INVOICE_CORRECTION', 409);
        $this->applyReceive($inv, $context);
        return $this->detail($id, $context);
    }

    private function applyReceive(array $inv, ApiContext $context): void
    {
        $id = (int) $inv['id'];
        if ($inv['id_po'] === null) { $this->receiveWithoutOrder($inv, $context); return; }
        $po = [];
        $already = [];
        foreach ($this->supply->lines((int) $inv['id_po']) as $pl) {
            $po[(int) $pl['id_raw_material']] = (int) $pl['id'];
            $already[(int) $pl['id_raw_material']] = (float) ($pl['received_qty'] ?? 0);
        }
        // Pas de double réception : ce qui est déjà reçu sur la commande (page Dostawy ou facture précédente) est déduit.
        $lines = [];
        $mapped = false;
        foreach ($this->repo->lines($id) as $l) {
            if ($l['id_raw_material'] === null || !isset($po[(int) $l['id_raw_material']])) continue;
            $mapped = true;
            $mid = (int) $l['id_raw_material'];
            $qty = (float) $l['quantity'] * (float) ($l['factor'] ?: 1);
            $net = $l['net_value'] !== null ? (float) $l['net_value'] : null;
            $left = $qty - $already[$mid];
            $already[$mid] = max(0.0, $already[$mid] - $qty);
            if ($left <= 0.0005) continue;
            $lines[] = ['id' => $po[$mid], 'quantity' => round($left, 3), 'unit_price' => $net !== null && $qty > 0 ? $net / $qty : null];
        }
        if (!$lines) throw $mapped ? new ApiException('INVOICE_ALREADY_RECEIVED', 409) : new ApiException('INVOICE_NOTHING_TO_RECEIVE', 422);
        $day = $inv['issue_date'] && $inv['issue_date'] <= date('Y-m-d') ? $inv['issue_date'] : date('Y-m-d');
        $this->orders->receive((int) $inv['id_po'], ['received_on' => $day, 'lines' => $lines], $context);
    }

    /**
     * Bez zamówienia : zamówienie « przyjęte » utworzone z faktury (linie przypisane, ilość × przelicznik, cena netto
     * na jednostkę bazową) — ilości wchodzą na stan jak każde przyjęcie ; faktura zostaje z nim powiązana.
     */
    private function receiveWithoutOrder(array $inv, ApiContext $context): void
    {
        if ($inv['id_supplier'] === null) throw new ApiException('INVOICE_NO_SUPPLIER', 422);
        $lines = $this->materialLines($this->repo->lines((int) $inv['id']));
        if (!$lines) throw new ApiException('INVOICE_NOTHING_TO_RECEIVE', 422);
        $day = $inv['issue_date'] && $inv['issue_date'] <= date('Y-m-d') ? $inv['issue_date'] : date('Y-m-d');
        $this->tx->execute(function () use ($inv, $lines, $day, $context) {
            $po = $this->supply->insertReceived((int) $inv['id_supplier'], $day, 'Faktura ' . ($inv['number'] ?: '#' . $inv['id']), $context->userId);
            foreach ($lines as $mid => $l) {
                $m = $this->repo->material($mid);
                $lineId = $this->supply->insertLine($po, $mid, $l['quantity'], $m && (float) $m['package_size'] > 0 ? (float) $m['package_size'] : null, $l['price']);
                $this->supply->addReceipt($lineId, $day, $l['quantity'], $l['price'], $context->userId);
            }
            $this->repo->update((int) $inv['id'], ['id_po' => $po]);
            $this->audit->record($context, 'receive', 'purchase_invoice', (int) $inv['id'], null, ['order_id' => $po, 'lines' => count($lines), 'received_on' => $day]);
        });
    }

    /**
     * Ostatnie ceny z faktury do kartoteki surowców : cena netto na jednostkę bazową (kilka linii tego samego surowca —
     * średnia ważona), dostawca faktury jako bieżący ; przez MaterialPriceService (historia cen, przeliczenie receptur).
     * line_ids : tylko wybrane linie (domyślnie wszystkie przypisane).
     */
    public function importPrices(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->load($id);
        if ($inv['status'] === 'disputed') throw new ApiException('INVOICE_CLOSED', 409, [], ['status' => $inv['status']]);
        $v = (new Validator($body))->only(['line_ids']);
        $wanted = $body['line_ids'] ?? null;
        if ($wanted !== null && (!is_array($wanted) || array_filter($wanted, fn($x) => !is_int($x)))) $v->add('line_ids', 'NOT_ARRAY');
        $v->throwIfInvalid();
        $rows = array_filter($this->repo->lines($id), fn($l) => $wanted === null || in_array((int) $l['id'], $wanted, true));
        $res = $this->applyPrices($inv, $rows, $context);
        return $this->detail($id, $context) + ['imported_prices' => $res['changes'], 'skipped' => $res['skipped'], 'kept' => $res['kept'], 'held' => $res['held']];
    }

    /** @return array{changes: list<array>, skipped: list<string>} */
    private function applyPrices(array $inv, array $rows, ApiContext $context): array
    {
        $id = (int) $inv['id'];
        if (!empty($inv['is_correction'])) throw new ApiException('INVOICE_CORRECTION', 409);
        $lines = array_filter($this->materialLines($rows), fn($l) => $l['price'] !== null && $l['price'] > 0);
        if (!$lines) throw new ApiException('INVOICE_NO_PRICES', 422);
        // Podwyżka ponad cenę bazową : tylko po decyzji ; « keep » = cena bazowa bez zmian.
        $ups = $this->priceUps($rows);
        $pending = array_filter($ups, fn($u) => $u['decision'] === null);
        if ($pending) throw new ApiException('INVOICE_PRICE_UP_PENDING', 409, [], ['n' => count($pending)]);
        $changes = [];
        $skipped = [];
        $kept = [];
        $held = [];
        foreach ($lines as $mid => $l) {
            $m = $this->repo->material($mid);
            if (($ups[$mid]['decision'] ?? null) === 'keep') { $kept[] = $m['name']; continue; }
            $supplier = $inv['id_supplier'] !== null ? (int) $inv['id_supplier'] : ($m['id_current_supplier'] !== null ? (int) $m['id_current_supplier'] : null);
            $package = (float) $m['package_size'] > 0 ? (float) $m['package_size'] : (float) CostOptimizationService::displayUnit($m['unit'])['factor'];
            $packPrice = round($l['price'] * $package, 2);
            // Kartoteka : cena za opakowanie (2 miejsca) ÷ opakowanie w całych jednostkach bazowych.
            if ($supplier === null || !$m['id_unit'] || $packPrice <= 0 || abs($package - round($package)) > 1e-9) { $skipped[] = $m['name']; continue; }
            // Cena bazowa = najwyższa zatwierdzona cena : niższa cena z faktury jej nie obniża (held).
            $res = $this->prices->applyValidated($mid, ['id_raw_material' => $mid, 'id_current_supplier' => $supplier, 'package_size_in_base_unit' => (int) round($package),
                'id_unit' => (int) $m['id_unit'], 'pack_price' => $packPrice], 'invoice', $context->userId);
            if ($res['held']) { $held[] = $m['name']; continue; }
            $unit = CostOptimizationService::displayUnit($m['unit']);
            $changes[] = ['raw_material_id' => $mid, 'name' => $m['name'], 'unit' => $unit['label'], 'factor' => $unit['factor'],
                'old_price' => $m['price_per_unit'] !== null ? (float) $m['price_per_unit'] : null, 'new_price' => $res['price']];
        }
        if ($changes || $held || $kept) {
            $this->repo->update($id, ['prices_imported_at' => date('Y-m-d H:i:s'), 'prices_imported_by' => $context->userId]);
            $this->audit->record($context, 'import_prices', 'purchase_invoice', $id, null, ['materials' => array_map(fn($c) => [$c['raw_material_id'], $c['old_price'], $c['new_price']], $changes)]);
        }
        return ['changes' => $changes, 'skipped' => $skipped, 'kept' => $kept, 'held' => $held];
    }

    /** Linie przypisane według surowca : ilość w jednostkach bazowych i cena netto za jednostkę bazową (ważona). */
    private function materialLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if ($l['id_raw_material'] === null) continue;
            $qty = (float) $l['quantity'] * (float) ($l['factor'] ?: 1);
            if ($qty <= 0) continue;
            $net = $l['net_value'] !== null ? (float) $l['net_value'] : ($l['unit_price'] !== null ? (float) $l['unit_price'] * (float) $l['quantity'] : null);
            $mid = (int) $l['id_raw_material'];
            $out[$mid] ??= ['quantity' => 0.0, 'value' => 0.0, 'priced' => 0.0];
            $out[$mid]['quantity'] += $qty;
            if ($net !== null) { $out[$mid]['value'] += $net; $out[$mid]['priced'] += $qty; }
        }
        return array_map(fn($x) => ['quantity' => round($x['quantity'], 3), 'price' => $x['priced'] > 0 ? $x['value'] / $x['priced'] : null], $out);
    }

    public function delete(int $id, ApiContext $context): array
    {
        $inv = $this->open($id);
        $this->tx->execute(function () use ($id, $inv, $context) {
            $this->repo->delete($id);
            $this->audit->record($context, 'delete', 'purchase_invoice', $id, ['number' => $inv['number'], 'seller_nip' => $inv['seller_nip'], 'source' => $inv['source']], null);
        });
        return ['deleted' => true, 'id' => $id];
    }

    /**
     * Jednostka surowca prosto z formularza przypisania (27/10/2026) : « 1 OP = … kg / g / szt / l / ml ». kg i g to ta sama
     * jednostka bazowa (g), l i ml też (ml) — sama skala wyświetlania. Zmiana masa / objętość / sztuki zapisana od razu na karcie
     * surowca : opakowanie i cena przeliczone wagą sztuki (packaging_unit_weight_g, karta BDO), gdy jest znana ; bez niej liczby
     * zostają (converted = false : sprawdzić cenę). Receptury zachowują swoje liczby (4 g → 4 szt) — zwracana ich liczba.
     */
    public function changeMaterialUnit(int $materialId, array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['unit'])->choice('unit', ['kg', 'g', 'l', 'ml', 'szt']);
        $v->throwIfInvalid();
        $m = $this->repo->materialUnitInfo($materialId) ?? throw new ApiException('NOT_FOUND', 404);
        $group = fn(?string $u) => in_array($u, ['g', 'kg'], true) ? 'mass' : (in_array($u, ['ml', 'l'], true) ? 'volume' : 'count');
        $from = $group($m['unit']);
        $to = $group($v->clean()['unit']);
        $info = fn(bool $changed, bool $converted) => ['raw_material_id' => $materialId, 'changed' => $changed, 'converted' => $converted,
            'base_unit' => $changed ? ['mass' => 'g', 'volume' => 'ml', 'count' => 'szt'][$to] : ($m['unit'] ?: 'szt'), 'recipes' => (int) $m['recipes']];
        if ($from === $to) return $info(false, true) + ['price_per_unit' => (float) $m['price_per_unit'], 'package_size' => (int) $m['package_size']];
        $base = ['mass' => 'g', 'volume' => 'ml', 'count' => 'szt'][$to];
        $unitId = $this->repo->unitId($base) ?? throw new ApiException('NOT_FOUND', 404);
        // Stara jednostka kg / l : najpierw w g / ml (opakowanie × 1000, cena ÷ 1000).
        $scale = in_array($m['unit'], ['kg', 'l'], true) ? 1000.0 : 1.0;
        $package = max(0.0, (float) $m['package_size']) * $scale;
        $price = (float) $m['price_per_unit'] / $scale;
        $w = $m['weight_g'] !== null && (float) $m['weight_g'] > 0 ? (float) $m['weight_g'] : null;
        // ratio = ile nowych jednostek w jednej starej (g → szt : 1 / waga sztuki ; szt → g : waga ; g ↔ ml : 1, gęstość nieznana).
        $ratio = null;
        if ($to === 'count' && $w !== null) $ratio = 1 / $w;
        elseif ($from === 'count' && $w !== null) $ratio = $w;
        $converted = $ratio !== null;
        if ($converted) {
            $package = $package * $ratio;
            $price = $price / $ratio;
        }
        $newPackage = $package > 0 ? max(1, (int) round($package)) : 0;
        $this->prices->changeUnit($materialId, $unitId, $newPackage, $price, $m['id_current_supplier'] !== null ? (int) $m['id_current_supplier'] : null, $context->userId);
        // Przeliczniki faktur (1 OP = …) w nowej jednostce, gdy przeliczenie jest znane.
        if ($converted) $this->repo->scaleFactors($materialId, $ratio * $scale);
        $this->audit->record($context, 'change_unit', 'raw_material', $materialId, ['unit' => $m['unit'], 'price_per_unit' => (float) $m['price_per_unit'], 'package_size' => (int) $m['package_size']],
            ['unit' => $base, 'price_per_unit' => round($price, 5), 'package_size' => $newPackage, 'converted' => $converted]);
        return $info(true, $converted) + ['price_per_unit' => round($price, 5), 'package_size' => $newPackage, 'ratio' => $converted ? round($ratio * $scale, 6) : null];
    }

    public function mappings(ApiContext $context): array
    {
        return ['mappings' => array_map(fn($m) => ['id' => (int) $m['id'], 'supplier_key' => $m['supplier_key'], 'supplier' => $m['supplier_name_erp'] !== null ? trim((string) $m['supplier_name_erp']) : null,
            'supplier_name' => $m['supplier_name'], 'supplier_unit' => $m['supplier_unit'], 'raw_material_id' => (int) $m['id_raw_material'], 'material' => $m['material_name'],
            'base_unit' => $m['material_unit'] ?: 'szt', 'factor' => (float) $m['factor'], 'updated_at' => $m['updated_at']], $this->repo->mappings()),
            'can_write' => $context->can('prod.supply.write')];
    }

    public function deleteMapping(int $id, ApiContext $context): array
    {
        if (!$this->repo->deleteMapping($id)) throw ApiException::notFound('purchase_invoice_mapping', $id);
        $this->audit->record($context, 'delete', 'purchase_invoice_mapping', $id, null, null);
        return ['deleted' => true, 'id' => $id];
    }

    /** Plik faktury (PDF albo XML KSeF) w base64 : przeglądarka otwiera go z pamięci. */
    public function file(int $id): array
    {
        $this->load($id);
        $f = $this->repo->file($id);
        if ($f === null) throw ApiException::notFound('invoice_file', $id);
        return ['filename' => $f['filename'], 'mime' => $f['mime'], 'size' => strlen($f['content']), 'content_base64' => base64_encode($f['content'])];
    }

    // --- Pomocnicze ---------------------------------------------------------------------------------------------------

    private function load(int $id): array
    {
        $i = $this->repo->invoice($id);
        if ($i === null) throw ApiException::notFound('purchase_invoice', $id);
        return $i;
    }

    private function open(int $id): array
    {
        $i = $this->load($id);
        if ($i['status'] !== 'open') throw new ApiException('INVOICE_CLOSED', 409, [], ['status' => $i['status']]);
        // Usługa (Finanse) : bez przypisań, cen i stanów — najpierw « z powrotem do zaopatrzenia ».
        if (($i['kind'] ?? 'goods') !== 'goods') throw new ApiException('INVOICE_NOT_GOODS', 409);
        return $i;
    }

    private function summary(array $i): array
    {
        return [
            'id' => (int) $i['id'], 'source' => $i['source'], 'kind' => $i['kind'] ?? 'goods', 'ksef_number' => $i['ksef_number'], 'number' => $i['number'], 'issue_date' => $i['issue_date'],
            'seller_nip' => $i['seller_nip'], 'seller_name' => $i['seller_name'], 'is_correction' => !empty($i['is_correction']),
            'supplier' => $i['id_supplier'] !== null ? ['id' => (int) $i['id_supplier'], 'name' => trim((string) ($i['supplier_name'] ?? ''))] : null,
            'currency' => $i['currency'], 'total_net' => $i['total_net'] !== null ? (float) $i['total_net'] : null, 'total_gross' => $i['total_gross'] !== null ? (float) $i['total_gross'] : null,
            'order' => $i['id_po'] !== null ? ['id' => (int) $i['id_po'], 'code' => $i['po_code'], 'status' => $i['po_status']] : null,
            'status' => $i['status'], 'file' => $i['filename'] !== null ? ['filename' => $i['filename'], 'mime' => $i['mime']] : null, 'created_at' => $i['created_at'],
        ];
    }

    private function presentLine(array $l, string $lang): array
    {
        $unit = $l['material_unit'] !== null ? CostOptimizationService::displayUnit($l['material_unit']) : null;
        return [
            'id' => (int) $l['id'], 'line_no' => (int) $l['line_no'], 'name' => $l['name'], 'code' => $l['code'], 'quantity' => (float) $l['quantity'], 'unit' => $l['unit'],
            'unit_price' => $l['unit_price'] !== null ? (float) $l['unit_price'] : null, 'net_value' => $l['net_value'] !== null ? (float) $l['net_value'] : null, 'vat_rate' => $l['vat_rate'],
            'material' => $l['id_raw_material'] !== null ? ['id' => (int) $l['id_raw_material'], 'name' => $lang === 'fr' && $l['material_name_fr'] ? $l['material_name_fr'] : $l['material_name'],
                'base_unit' => $l['material_unit'] ?: 'szt', 'unit' => $unit['label'] ?? 'szt', 'factor_display' => $unit['factor'] ?? 1] : null,
            'factor' => $l['factor'] !== null ? (float) $l['factor'] : null,
        ];
    }
}
