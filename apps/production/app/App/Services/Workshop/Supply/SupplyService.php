<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Purchasing\MaterialCostRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Communication\TemplateRenderer;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Workshop\Planning\WeekService;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Zaopatrzenie : po wygenerowaniu produkcji — czego potrzeba z surowców i opakowań, co jest na stanie, co zamówić
 * i u kogo, zamówienia mailem (szablon supplier_order) i przyjęcia z ilością i ceną.
 *
 * Potrzeba tygodnia = do produkcji z arkusza tygodnia ÷ sztuk w kartonie × receptura na karton (receptura
 * wypłaszczona : surowce półproduktów są w niej). Stan = ostatnia inwentaryzacja (stan na koniec dnia) + przyjęcia
 * − zużycie z produkcji po tym dniu (kartony wyprodukowane × receptura) ; bez inwentaryzacji : od pierwszego
 * przyjęcia (stan 0 przed nim), a bez przyjęć — nieznany. Do zamówienia : surowiec « na stanie » z min. / maks. —
 * gdy stan + zamówione − potrzeba < min., uzupełnienie do maks. ; inaczej (« na zamówienie ») brakująca ilość ;
 * w górę do pełnych opakowań dostawcy. Ilości w API w jednostce bazowej surowca (g, ml, szt…), unit / factor
 * podają jednostkę wyświetlaną (kg, l…).
 */
final class SupplyService
{
    public const MODES = ['order', 'stock'];
    public const STATUSES = ['draft', 'sent', 'partial', 'received', 'cancelled'];
    private const MAX_QTY = 1_000_000_000;

    public function __construct(
        private SupplyRepository $repo,
        private WeekService $week,
        private CommunicationTemplateRepository $templates,
        private TemplateRenderer $renderer,
        private OutgoingMessageService $outgoing,
        private MaterialCostRepository $company,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    // --- Potrzeby ----------------------------------------------------------------------------------------------

    public function needs(string $date, int $weeks, ApiContext $context): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw ApiException::validation([['field' => 'date', 'code' => 'INVALID_DATE', 'params' => []]]);
        if ($weeks < 1 || $weeks > 4) throw ApiException::validation([['field' => 'weeks', 'code' => 'OUT_OF_RANGE', 'params' => ['min' => 1, 'max' => 4]]]);
        $recipes = $this->repo->recipes();
        $need = [];
        $products = [];
        $noRecipe = [];
        $periods = [];
        for ($w = 0; $w < $weeks; $w++) {
            $sheet = $this->week->sheet(date('Y-m-d', strtotime($date . ' +' . (7 * $w) . ' days')), $context);
            $periods[] = ['week_start' => $sheet['week_start'], 'week_end' => $sheet['week_end'], 'iso_week' => $sheet['iso_week']];
            foreach ($sheet['rows'] as $r) {
                if ($r['item_type'] !== 'product' || $r['to_produce'] <= 0) continue;
                if (!$r['ppc'] || !isset($recipes[$r['item_id']])) { $noRecipe[$r['item_id']] = $r['name']; continue; }
                $cartons = $r['to_produce'] / $r['ppc'];
                foreach ($recipes[$r['item_id']] as $ing) {
                    $need[$ing['material_id']][$w] = ($need[$ing['material_id']][$w] ?? 0.0) + $cartons * $ing['quantity'];
                    $products[$ing['material_id']][$r['item_id']] = true;
                }
            }
        }
        $levels = $this->levels();
        $onOrder = $this->repo->onOrder();
        $rows = [];
        $lang = $context->translator->lang();
        foreach ($this->repo->materials() as $m) {
            $id = (int) $m['id'];
            $weekly = array_map(fn($w) => round($need[$id][$w] ?? 0.0, 3), range(0, $weeks - 1));
            $total = array_sum($weekly);
            $ordered = $onOrder[$id] ?? 0.0;
            $plan = $this->toOrder($m, $total, $levels[$id]['stock'] ?? null, $ordered);
            if ($total <= 0 && $plan['to_order'] <= 0 && $ordered <= 0) continue;
            $rows[] = $this->presentMaterial($m, $lang, $levels[$id] ?? null) + [
                'need' => round($total, 3), 'need_weeks' => $weekly, 'on_order' => round($ordered, 3), 'products' => count($products[$id] ?? []),
            ] + $plan;
        }
        usort($rows, fn($a, $b) => [$a['supplier']['name'] ?? '~', $a['packaging'], $a['name']] <=> [$b['supplier']['name'] ?? '~', $b['packaging'], $b['name']]);
        $toOrder = array_filter($rows, fn($r) => $r['to_order'] > 0);
        return [
            'date' => $periods[0]['week_start'], 'weeks' => $periods, 'rows' => $rows,
            'totals' => ['materials' => count($rows), 'to_order' => count($toOrder), 'value' => round(array_sum(array_column($toOrder, 'value')), 2),
                'suppliers' => count(array_unique(array_map(fn($r) => $r['supplier']['id'] ?? 0, $toOrder)))],
            'warnings' => [
                'no_recipe' => array_values($noRecipe),
                'no_price' => array_values(array_map(fn($r) => $r['name'], array_filter($toOrder, fn($r) => $r['price'] === null))),
                'no_supplier' => array_values(array_map(fn($r) => $r['name'], array_filter($toOrder, fn($r) => $r['supplier'] === null))),
                'no_count' => count(array_filter($rows, fn($r) => $r['stock_basis'] === null)),
            ],
            'can_write' => $context->can('prod.supply.write'),
        ];
    }

    /**
     * Ile zamówić : na stanie (z maks.) — uzupełnienie do maks., gdy dostępne po produkcji < min. ; na zamówienie —
     * brak do potrzeby. Ujemny stan teoretyczny liczy się jak 0. W górę do pełnych opakowań.
     * @return array{available_after:float, to_order:float, packages:?int, value:?float}
     */
    private function toOrder(array $m, float $need, ?float $stock, float $onOrder): array
    {
        $after = max(0.0, $stock ?? 0.0) + $onOrder - $need;
        $max = $m['stock_max'] !== null ? (float) $m['stock_max'] : null;
        if ($m['supply_mode'] === 'stock' && $max !== null) $qty = $after < (float) ($m['stock_min'] ?? 0) - 1e-9 ? $max - $after : 0.0;
        else $qty = max(0.0, -$after);
        $pack = (float) ($m['package_size'] ?? 0);
        $packages = null;
        if ($qty > 1e-9 && $pack > 0) {
            $packages = (int) ceil(round($qty / $pack, 6));
            $qty = $packages * $pack;
        }
        $price = $m['price_per_unit'] !== null && (float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null;
        return ['available_after' => round($after, 3), 'to_order' => round($qty, 3), 'packages' => $packages, 'value' => $price !== null ? round($qty * $price, 2) : null];
    }

    /**
     * Stan teoretyczny surowców : inwentaryzacja + przyjęcia − zużycie z produkcji po dniu inwentaryzacji ;
     * bez inwentaryzacji : przyjęcia − zużycie od pierwszego przyjęcia (basis = receipts).
     * @return array<int, array{stock:float, basis:string, count_date:?string}>
     */
    private function levels(): array
    {
        $counts = $this->repo->lastCounts();
        $in = $this->repo->receivedSinceCount();
        $out = $this->repo->consumedSinceCount();
        $levels = [];
        foreach ($counts as $id => $c) {
            $levels[$id] = ['stock' => round($c['quantity'] + ($in[$id] ?? 0.0) - ($out[$id] ?? 0.0), 3), 'basis' => 'count', 'count_date' => $c['count_date']];
        }
        foreach ($in as $id => $qty) {
            if (isset($levels[$id])) continue;
            $levels[$id] = ['stock' => round($qty - ($out[$id] ?? 0.0), 3), 'basis' => 'receipts', 'count_date' => null];
        }
        return $levels;
    }

    private function presentMaterial(array $m, string $lang, ?array $level): array
    {
        $unit = CostOptimizationService::displayUnit($m['unit']);
        $price = $m['price_per_unit'] !== null && (float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null;
        return [
            'id' => (int) $m['id'], 'name' => $lang === 'fr' && $m['name_fr'] ? $m['name_fr'] : $m['name'], 'category' => $m['category'],
            'packaging' => (bool) $m['packaging'], 'base_unit' => $m['unit'] ?: 'szt', 'unit' => $unit['label'], 'factor' => $unit['factor'],
            'supplier' => $m['supplier_id'] !== null && $m['supplier_name'] !== null ? ['id' => (int) $m['supplier_id'], 'name' => trim((string) $m['supplier_name']),
                'email' => filter_var(trim((string) $m['supplier_email']), FILTER_VALIDATE_EMAIL) ? trim((string) $m['supplier_email']) : null] : null,
            'mode' => $m['supply_mode'], 'stock_min' => $m['stock_min'] !== null ? (float) $m['stock_min'] : null, 'stock_max' => $m['stock_max'] !== null ? (float) $m['stock_max'] : null,
            'stock' => $level['stock'] ?? null, 'stock_basis' => $level['basis'] ?? null, 'count_date' => $level['count_date'] ?? null,
            'price' => $price, 'package_size' => $m['package_size'] !== null && (float) $m['package_size'] > 0 ? (float) $m['package_size'] : null,
        ];
    }

    // --- Surowce : tryb, stany, inwentaryzacja ------------------------------------------------------------------

    public function materials(ApiContext $context): array
    {
        $levels = $this->levels();
        $onOrder = $this->repo->onOrder();
        $to = date('Y-m-d', strtotime('yesterday'));
        $use = $this->repo->consumed(date('Y-m-d', strtotime($to . ' -27 days')), $to);
        $lang = $context->translator->lang();
        $rows = array_map(fn($m) => $this->presentMaterial($m, $lang, $levels[(int) $m['id']] ?? null) + [
            'on_order' => round($onOrder[(int) $m['id']] ?? 0.0, 3), 'weekly_use' => round(($use[(int) $m['id']] ?? 0.0) / 4, 3),
        ], $this->repo->materials());
        usort($rows, fn($a, $b) => [$a['packaging'], $a['name']] <=> [$b['packaging'], $b['name']]);
        return ['rows' => $rows, 'use_weeks' => 4, 'can_write' => $context->can('prod.supply.write')];
    }

    public function saveMaterial(int $id, array $body, ApiContext $context): array
    {
        if (!$this->repo->materialExists($id)) throw ApiException::notFound('raw_material', $id);
        $v = (new Validator($body, true))->only(['supply_mode', 'stock_min', 'stock_max'])
            ->choice('supply_mode', self::MODES, false)->decimal('stock_min', false, 0, self::MAX_QTY, true)->decimal('stock_max', false, 0, self::MAX_QTY, true);
        $v->throwIfInvalid();
        $data = $v->clean();
        $before = $this->repo->supply($id);
        $min = array_key_exists('stock_min', $data) ? $data['stock_min'] : ($before['stock_min'] ?? null);
        $max = array_key_exists('stock_max', $data) ? $data['stock_max'] : ($before['stock_max'] ?? null);
        if ($min !== null && $max !== null && (float) $max < (float) $min) throw ApiException::validation([['field' => 'stock_max', 'code' => 'STOCK_MAX_BELOW_MIN', 'params' => []]]);
        if ($data) {
            $this->tx->execute(function () use ($id, $data, $before, $context) {
                $this->repo->saveSupply($id, $data + ['updated_by' => $context->userId]);
                $this->audit->record($context, $before ? 'update' : 'create', 'material_supply', $id, $before ? array_intersect_key($before, $data) : null, $data);
            });
        }
        return $this->material($id, $context);
    }

    public function addCount(int $id, array $body, ApiContext $context): array
    {
        if (!$this->repo->materialExists($id)) throw ApiException::notFound('raw_material', $id);
        $v = (new Validator($body))->only(['quantity', 'count_date', 'note'])
            ->decimal('quantity', true, 0, self::MAX_QTY)->date('count_date', false, false)->string('note', 255, false, true);
        $day = $v->value('count_date') ?? date('Y-m-d');
        if (!$v->hasError('count_date') && $day > date('Y-m-d')) $v->add('count_date', 'IN_FUTURE');
        $v->throwIfInvalid();
        $this->tx->execute(function () use ($id, $v, $day, $context) {
            $countId = $this->repo->addCount($id, $day, (float) $v->value('quantity'), $v->value('note'), $context->userId);
            $this->audit->record($context, 'create', 'material_count', $countId, null, ['raw_material_id' => $id, 'count_date' => $day, 'quantity' => (float) $v->value('quantity')]);
        });
        return $this->material($id, $context);
    }

    private function material(int $id, ApiContext $context): array
    {
        $m = $this->repo->materials([$id])[0];
        return $this->presentMaterial($m, $context->translator->lang(), $this->levels()[$id] ?? null) + ['on_order' => round($this->repo->onOrder()[$id] ?? 0.0, 3)];
    }

    // --- Zamówienia ---------------------------------------------------------------------------------------------

    /** Zamówienia z zaznaczonych potrzeb : jedno zamówienie (szkic) na dostawcę bieżącego surowca. */
    public function createOrders(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['week_start', 'lines'])->date('week_start', false, true);
        $lines = $this->lineInput($v, $body, false);
        $v->throwIfInvalid();
        $materials = [];
        foreach ($this->repo->materials(array_column($lines, 'raw_material_id')) as $m) $materials[(int) $m['id']] = $m;
        $orphans = array_filter($lines, fn($l) => ($materials[$l['raw_material_id']]['supplier_id'] ?? null) === null || $this->repo->supplier((int) $materials[$l['raw_material_id']]['supplier_id']) === null);
        if ($orphans) throw new ApiException('SUPPLY_NO_SUPPLIER', 422, [], ['materials' => implode(', ', array_map(fn($l) => $materials[$l['raw_material_id']]['name'], $orphans))]);
        $bySupplier = [];
        foreach ($lines as $l) $bySupplier[(int) $materials[$l['raw_material_id']]['supplier_id']][] = $l;
        $week = $v->value('week_start');
        $delivery = $week !== null ? max(date('Y-m-d', strtotime('tomorrow')), date('Y-m-d', strtotime($week . ' -1 day'))) : null;
        $ids = $this->tx->execute(function () use ($bySupplier, $materials, $week, $delivery, $context) {
            $ids = [];
            foreach ($bySupplier as $supplierId => $supplierLines) {
                $supplier = $this->repo->supplier($supplierId);
                $email = trim((string) $supplier['email']);
                $id = $this->repo->insert(['code' => $this->repo->nextCode(date('Y-m-d')), 'id_supplier' => $supplierId, 'status' => 'draft', 'week_start' => $week,
                    'delivery_date' => $delivery, 'language' => 'pl', 'email_to' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, 'created_by' => $context->userId]);
                foreach ($supplierLines as $l) {
                    $m = $materials[$l['raw_material_id']];
                    $this->repo->insertLine($id, $l['raw_material_id'], $l['quantity'], (float) $m['package_size'] > 0 ? (float) $m['package_size'] : null,
                        $l['unit_price'] ?? ((float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null));
                }
                $this->audit->record($context, 'create', 'supplier_order', $id, null, ['supplier_id' => $supplierId, 'lines' => count($supplierLines), 'week_start' => $week]);
                $ids[] = $id;
            }
            return $ids;
        });
        return ['orders' => array_map(fn($id) => $this->order($id, $context), $ids)];
    }

    public function orders(string $status, ApiContext $context): array
    {
        if (!in_array($status, ['open', 'all', ...self::STATUSES], true)) {
            throw ApiException::validation([['field' => 'status', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => 'open, all, ' . implode(', ', self::STATUSES)]]]);
        }
        return ['orders' => array_map(fn($o) => $this->presentSummary($o), $this->repo->orders($status === 'all' ? null : $status)),
            'can_write' => $context->can('prod.supply.write')];
    }

    public function order(int $id, ApiContext $context): array
    {
        $o = $this->load($id);
        $lang = $context->translator->lang();
        $lines = array_map(function ($l) use ($lang) {
            $unit = CostOptimizationService::displayUnit($l['unit']);
            $qty = (float) $l['quantity'];
            $received = (float) $l['received_qty'];
            $price = $l['unit_price'] !== null ? (float) $l['unit_price'] : null;
            $last = $l['last_price'] !== null && $l['last_price'] !== '' ? (float) $l['last_price'] : null;
            $pack = $l['package_size'] !== null && (float) $l['package_size'] > 0 ? (float) $l['package_size'] : null;
            return ['id' => (int) $l['id'], 'raw_material_id' => (int) $l['id_raw_material'], 'name' => $lang === 'fr' && $l['name_fr'] ? $l['name_fr'] : $l['name'],
                'base_unit' => $l['unit'] ?: 'szt', 'unit' => $unit['label'], 'factor' => $unit['factor'],
                'quantity' => $qty, 'package_size' => $pack, 'packages' => $pack ? round($qty / $pack, 2) : null,
                'unit_price' => $price, 'catalog_price' => $l['catalog_price'] !== null ? (float) $l['catalog_price'] : null, 'value' => $price !== null ? round($qty * $price, 2) : null,
                'received_qty' => round($received, 3), 'remaining' => round(max(0.0, $qty - $received), 3), 'received_value' => $l['received_value'] !== null ? round((float) $l['received_value'], 2) : null,
                'last_price' => $last, 'last_day' => $l['last_day'],
                'price_gap_pct' => $last !== null && $price !== null && $price > 0 ? round(($last - $price) / $price * 100, 2) : null,
                'qty_gap_pct' => $received > 0 && $qty > 0 ? round(($received - $qty) / $qty * 100, 2) : null];
        }, $this->repo->lines($id));
        return $this->presentSummary($this->repo->orderSummary($id) ?? $o) + [
            'supplier_email' => $o['supplier_email'], 'supplier_phone' => $o['supplier_phone'], 'note' => $o['note'],
            'lines' => $lines, 'receipts' => array_map(fn($r) => ['line_id' => (int) $r['id_po_line'], 'raw_material_id' => (int) $r['id_raw_material'], 'received_on' => $r['received_on'],
                'quantity' => (float) $r['quantity'], 'unit_price' => $r['unit_price'] !== null ? (float) $r['unit_price'] : null], $this->repo->receipts($id)),
            'can_edit' => $o['status'] === 'draft' && $context->can('prod.supply.write'),
            'can_receive' => in_array($o['status'], ['sent', 'partial'], true) && $context->can('prod.supply.write'),
        ];
    }

    public function updateOrder(int $id, array $body, ApiContext $context): array
    {
        $o = $this->editable($id);
        $v = (new Validator($body, true))->only(['delivery_date', 'language', 'email_to', 'note', 'save_supplier_email'])
            ->date('delivery_date')->choice('language', ['pl', 'fr'], false)->string('email_to', 255, false, true)->string('note', 1000, false, true)->boolean('save_supplier_email');
        $email = $v->value('email_to');
        if ($email !== null && !$v->hasError('email_to') && !filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL)) $v->add('email_to', 'INVALID_EMAIL', ['id' => (string) $email]);
        $v->throwIfInvalid();
        $data = array_diff_key($v->clean(), ['save_supplier_email' => true]);
        if (array_key_exists('email_to', $data) && $data['email_to'] !== null) $data['email_to'] = trim((string) $data['email_to']);
        $this->tx->execute(function () use ($id, $o, $data, $v, $context) {
            if ($data) $this->repo->update($id, $data);
            if ($v->value('save_supplier_email') === true && !empty($data['email_to'])) $this->repo->setSupplierEmail((int) $o['id_supplier'], $data['email_to']);
            $this->audit->record($context, 'update', 'supplier_order', $id, array_intersect_key($o, $data), $data + ($v->value('save_supplier_email') === true ? ['save_supplier_email' => true] : []));
        });
        return $this->order($id, $context);
    }

    /** Linie szkicu zastępowane w całości (ilość w jednostce bazowej, cena opcjonalna — inaczej cennik). */
    public function saveLines(int $id, array $body, ApiContext $context): array
    {
        $this->editable($id);
        $v = (new Validator($body))->only(['lines']);
        $lines = $this->lineInput($v, $body, true);
        $v->throwIfInvalid();
        $materials = [];
        foreach ($this->repo->materials(array_column($lines, 'raw_material_id')) as $m) $materials[(int) $m['id']] = $m;
        $this->tx->execute(function () use ($id, $lines, $materials, $context) {
            $this->repo->deleteLines($id);
            foreach ($lines as $l) {
                $m = $materials[$l['raw_material_id']];
                $this->repo->insertLine($id, $l['raw_material_id'], $l['quantity'], (float) $m['package_size'] > 0 ? (float) $m['package_size'] : null,
                    $l['unit_price'] ?? ((float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null));
            }
            $this->audit->record($context, 'update', 'supplier_order_lines', $id, null, ['lines' => $lines]);
        });
        return $this->order($id, $context);
    }

    public function preview(int $id, ApiContext $context): array
    {
        $o = $this->load($id);
        [$template, $subject, $text] = $this->mail($o);
        return ['recipient' => $o['email_to'], 'language' => $o['language'], 'subject' => $subject, 'body' => $text, 'template_id' => isset($template['id']) ? (int) $template['id'] : null];
    }

    /** Wysyłka : mailem (kolejka, plik CSV w załączniku) albo oznaczenie « wysłane ręcznie » (telefon, sklep). */
    public function send(int $id, array $body, ApiContext $context): array
    {
        $o = $this->editable($id);
        $v = (new Validator($body))->only(['manual'])->boolean('manual');
        $v->throwIfInvalid();
        if (!$this->repo->lines($id)) throw new ApiException('PO_NO_LINES', 422);
        $manual = $v->value('manual') === true;
        if (!$manual && !filter_var((string) $o['email_to'], FILTER_VALIDATE_EMAIL)) throw new ApiException('PO_NO_EMAIL', 422);
        $this->tx->execute(function () use ($id, $o, $manual, $context) {
            $messageId = null;
            if (!$manual) {
                [$template, $subject, $text, $variables] = $this->mail($o);
                $messageId = $this->outgoing->queue([
                    'message_type' => 'supplier_order', 'template' => $template, 'recipient' => $o['email_to'], 'subject' => $subject, 'body' => $text,
                    'variables' => $variables, 'created_by' => 'supplier_order:' . $o['code'],
                ], [['filename' => $o['code'] . '.csv', 'mime_type' => 'text/csv', 'content' => $this->csv($o)]]);
            }
            $this->repo->update($id, ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'sent_by' => $manual ? 'manual' : 'email', 'id_message' => $messageId]);
            $this->audit->record($context, 'send', 'supplier_order', $id, ['status' => $o['status']], ['status' => 'sent', 'sent_by' => $manual ? 'manual' : 'email', 'message_id' => $messageId]);
        });
        return $this->order($id, $context);
    }

    /** Przyjęcie dostawy : ilość i cena rzeczywista na linię ; status częściowo / całkowicie przyjęte. */
    public function receive(int $id, array $body, ApiContext $context): array
    {
        $o = $this->load($id);
        if (!in_array($o['status'], ['sent', 'partial'], true)) throw new ApiException('PO_NOT_RECEIVABLE', 409, [], ['status' => $o['status']]);
        $v = (new Validator($body))->only(['received_on', 'lines'])->date('received_on', false, false);
        $day = $v->value('received_on') ?? date('Y-m-d');
        if (!$v->hasError('received_on') && $day > date('Y-m-d')) $v->add('received_on', 'IN_FUTURE');
        $own = [];
        foreach ($this->repo->lines($id) as $l) $own[(int) $l['id']] = $l;
        $rows = $body['lines'] ?? null;
        $clean = [];
        if (!is_array($rows) || !$rows) $v->add('lines', 'REQUIRED');
        else foreach (array_values($rows) as $i => $r) {
            $lineId = is_array($r) ? ($r['id'] ?? null) : null;
            if (!is_int($lineId) || !isset($own[$lineId])) { $v->add("lines.$i.id", 'REFERENCE_NOT_FOUND', ['id' => is_scalar($lineId) ? (string) $lineId : '?']); continue; }
            $qty = $r['quantity'] ?? null;
            if (!is_int($qty) && !is_float($qty)) { $v->add("lines.$i.quantity", 'NOT_NUMBER'); continue; }
            if ($qty < 0 || $qty > self::MAX_QTY) { $v->add("lines.$i.quantity", 'OUT_OF_RANGE', ['min' => 0, 'max' => self::MAX_QTY]); continue; }
            $price = $r['unit_price'] ?? null;
            if ($price !== null && ((!is_int($price) && !is_float($price)) || $price <= 0)) { $v->add("lines.$i.unit_price", 'GREATER_THAN', ['min' => 0]); continue; }
            if ($qty > 0) $clean[] = ['id' => $lineId, 'quantity' => (float) $qty, 'unit_price' => $price !== null ? (float) $price : ($own[$lineId]['unit_price'] !== null ? (float) $own[$lineId]['unit_price'] : null)];
        }
        $v->throwIfInvalid();
        if (!$clean) throw ApiException::validation([['field' => 'lines', 'code' => 'REQUIRED', 'params' => []]]);
        $this->tx->execute(function () use ($id, $o, $day, $clean, $context) {
            foreach ($clean as $c) $this->repo->addReceipt($c['id'], $day, $c['quantity'], $c['unit_price'], $context->userId);
            $done = true;
            foreach ($this->repo->lines($id) as $l) if ((float) $l['received_qty'] < (float) $l['quantity'] - 1e-6) $done = false;
            $status = $done ? 'received' : 'partial';
            $this->repo->update($id, ['status' => $status]);
            $this->audit->record($context, 'receive', 'supplier_order', $id, ['status' => $o['status']], ['status' => $status, 'received_on' => $day, 'lines' => $clean]);
        });
        return $this->order($id, $context);
    }

    public function cancel(int $id, ApiContext $context): array
    {
        $o = $this->load($id);
        if (!in_array($o['status'], ['draft', 'sent', 'partial'], true)) throw new ApiException('PO_NOT_EDITABLE', 409, [], ['status' => $o['status']]);
        $this->tx->execute(function () use ($id, $o, $context) {
            $this->repo->update($id, ['status' => 'cancelled']);
            $this->audit->record($context, 'cancel', 'supplier_order', $id, ['status' => $o['status']], ['status' => 'cancelled']);
        });
        return $this->order($id, $context);
    }

    public function delete(int $id, ApiContext $context): array
    {
        $o = $this->editable($id);
        $this->tx->execute(function () use ($id, $o, $context) {
            $this->repo->delete($id);
            $this->audit->record($context, 'delete', 'supplier_order', $id, ['code' => $o['code'], 'supplier_id' => (int) $o['id_supplier']], null);
        });
        return ['deleted' => true, 'id' => $id];
    }

    // --- Pomocnicze -----------------------------------------------------------------------------------------------

    /** Linie z body.lines : surowiec istniejący, ilość > 0, cena > 0 lub brak ; bez powtórzeń. */
    private function lineInput(Validator $v, array $body, bool $allowEmpty): array
    {
        $rows = $body['lines'] ?? null;
        if (!is_array($rows) || (!$rows && !$allowEmpty)) { $v->add('lines', is_array($rows) ? 'REQUIRED' : 'NOT_ARRAY'); return []; }
        if (count($rows) > 200) { $v->add('lines', 'TOO_MANY', ['max' => 200]); return []; }
        $out = [];
        $seen = [];
        foreach (array_values($rows) as $i => $r) {
            $mid = is_array($r) ? ($r['raw_material_id'] ?? null) : null;
            if (!is_int($mid) || !$this->repo->materialExists($mid)) { $v->add("lines.$i.raw_material_id", 'REFERENCE_NOT_FOUND', ['id' => is_scalar($mid) ? (string) $mid : '?']); continue; }
            if (isset($seen[$mid])) { $v->add("lines.$i.raw_material_id", 'DUPLICATE_IN_LIST', ['id' => (string) $mid]); continue; }
            $seen[$mid] = true;
            $qty = $r['quantity'] ?? null;
            if (!is_int($qty) && !is_float($qty)) { $v->add("lines.$i.quantity", 'NOT_NUMBER'); continue; }
            if ($qty <= 0 || $qty > self::MAX_QTY) { $v->add("lines.$i.quantity", 'GREATER_THAN', ['min' => 0]); continue; }
            $price = $r['unit_price'] ?? null;
            if ($price !== null && ((!is_int($price) && !is_float($price)) || $price <= 0)) { $v->add("lines.$i.unit_price", 'GREATER_THAN', ['min' => 0]); continue; }
            $out[] = ['raw_material_id' => $mid, 'quantity' => (float) $qty, 'unit_price' => $price !== null ? (float) $price : null];
        }
        return $out;
    }

    private function load(int $id): array
    {
        $o = $this->repo->order($id);
        if ($o === null) throw ApiException::notFound('supplier_order', $id);
        return $o;
    }

    private function editable(int $id): array
    {
        $o = $this->load($id);
        if ($o['status'] !== 'draft') throw new ApiException('PO_NOT_EDITABLE', 409, [], ['status' => $o['status']]);
        return $o;
    }

    private function presentSummary(array $o): array
    {
        $ordered = (float) ($o['ordered_qty'] ?? 0);
        return [
            'id' => (int) $o['id'], 'code' => $o['code'], 'status' => $o['status'],
            'supplier' => ['id' => (int) $o['id_supplier'], 'name' => trim((string) ($o['supplier_name'] ?? ''))],
            'week_start' => $o['week_start'], 'delivery_date' => $o['delivery_date'], 'language' => $o['language'], 'email_to' => $o['email_to'],
            'sent_at' => $o['sent_at'], 'sent_by' => $o['sent_by'], 'created_at' => $o['created_at'],
            'lines_count' => (int) ($o['line_count'] ?? 0), 'value_ordered' => round((float) ($o['value_ordered'] ?? 0), 2),
            'value_received' => round((float) ($o['value_received'] ?? 0), 2), 'value_received_expected' => round((float) ($o['value_received_expected'] ?? 0), 2),
            'received_pct' => $ordered > 0 ? round((float) ($o['received_qty'] ?? 0) / $ordered * 100, 1) : 0.0,
            'price_gaps' => (int) ($o['price_gaps'] ?? 0),
        ];
    }

    /** @return array{0:array, 1:string, 2:string, 3:array} szablon, temat, treść, zmienne */
    private function mail(array $o): array
    {
        $lang = $o['language'] === 'fr' ? 'fr' : 'pl';
        $template = $this->templates->getDefault('supplier_order', $lang) ?: $this->templates->getDefault('supplier_order', 'pl');
        if (!$template) throw new ApiException('PO_NO_TEMPLATE', 422);
        $lines = [];
        foreach ($this->repo->lines((int) $o['id']) as $l) {
            $unit = CostOptimizationService::displayUnit($l['unit']);
            $name = $lang === 'fr' && $l['name_fr'] ? $l['name_fr'] : $l['name'];
            $qty = (float) $l['quantity'];
            $pack = (float) ($l['package_size'] ?? 0);
            $text = '- ' . $name . ' — ' . $this->number($qty / $unit['factor']) . ' ' . $unit['label'];
            if ($pack > 0 && abs($pack - $unit['factor']) > 1e-9) $text .= ' (' . $this->number($qty / $pack) . ' × ' . $this->number($pack / $unit['factor']) . ' ' . $unit['label'] . ')';
            $lines[] = $text;
        }
        $delivery = $o['delivery_date'] ? ($lang === 'fr' ? ' pour le ' : ' do dnia ') . date('d.m.Y', strtotime($o['delivery_date'])) : '';
        $variables = [
            'order' => ['code' => $o['code'], 'delivery' => $delivery, 'lines' => implode("\n", $lines), 'note' => $o['note'] ? "\n" . $o['note'] . "\n" : ''],
            'company' => ['name' => $this->company->companyName()],
        ];
        return [$template, $this->renderer->render($template['subject_template'], $variables), $this->renderer->render($template['body_template'], $variables), $variables];
    }

    /** Załącznik CSV (średnik, przecinek dziesiętny) : surowiec, ilość, jednostka, opakowania. */
    private function csv(array $o): string
    {
        $pl = $o['language'] !== 'fr';
        $rows = [[$pl ? 'Zamówienie' : 'Commande', $pl ? 'Towar' : 'Article', $pl ? 'Ilość' : 'Quantité', $pl ? 'Jednostka' : 'Unité', $pl ? 'Opakowania' : 'Colis']];
        foreach ($this->repo->lines((int) $o['id']) as $l) {
            $unit = CostOptimizationService::displayUnit($l['unit']);
            $pack = (float) ($l['package_size'] ?? 0);
            $rows[] = [$o['code'], $pl || !$l['name_fr'] ? $l['name'] : $l['name_fr'], $this->number((float) $l['quantity'] / $unit['factor']), $unit['label'], $pack > 0 ? $this->number((float) $l['quantity'] / $pack) : ''];
        }
        return "\xEF\xBB\xBF" . implode("\r\n", array_map(fn($r) => implode(';', array_map(fn($c) => '"' . str_replace('"', '""', (string) $c) . '"', $r)), $rows));
    }

    private function number(float $v): string
    {
        return number_format($v, abs($v - round($v)) < 0.0005 ? 0 : (abs($v) < 10 ? 2 : 1), ',', ' ');
    }
}
