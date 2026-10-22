<?php
declare(strict_types=1);

namespace App\Services\Workshop\Product;

use App\Domain\Stock\ToProduce;
use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\GroupRepository;
use App\Repositories\Workshop\ItemRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Fiche de production d'un article (assistant en 7 étapes) :
 * 1 article et logistique (carton, palette) · 2 mode et stocks · 3 groupe, cuisson et durées de vie ·
 * 4 nomenclature et pertes · 5 gamme · 6 contrôle des temps · 7 validation.
 * Règles : jamais congelé ⇒ jamais MTS ; MTS ⇒ stock maximum renseigné ; stock max ≥ stock min ;
 * une étape a un poste actif, une capacité > 0 et au moins un temps ; le poste d'attente
 * (pousse, froid) est un poste de fermentation, de froid ou de surgélation ; un produit cuit ou
 * précuit passe par un four de sa gamme (le four est un poste : gestion des fours et investissements).
 */
final class ItemService
{
    public const TYPES = ['product', 'semi'];
    public const MODES = ['MTO', 'MTS'];
    public const STATUSES = ['draft', 'validated'];
    public const BAKE_STATES = ['none', 'par_baked', 'baked'];
    public const CARTON_TYPES = ['klapowy', 'fasonowy', 'inny'];
    public const PALLET_TYPES = ['eur', 'industrial', 'other'];
    public const STORAGES = ['frozen', 'chilled', 'ambient'];
    /** Typ etykiety ERP (final_product.label_type) : 0 = mała, 1 = duża. */
    public const LABEL_TYPES = [0, 1];
    private const DELAY_TYPES = ['fermentation', 'froid', 'surgelation'];
    /** Postes où l'étape a une température (four, pousse, froid, surgélation). */
    private const TEMPERATURE_TYPES = ['four', 'fermentation', 'froid', 'surgelation'];
    /** Palettes : hauteur du support (mm) et poids à vide (kg) pour la hauteur et le poids brut. */
    private const PALLETS = ['eur' => ['length_mm' => 1200, 'width_mm' => 800, 'base_mm' => 144, 'tare_kg' => 25.0],
        'industrial' => ['length_mm' => 1200, 'width_mm' => 1000, 'base_mm' => 144, 'tare_kg' => 30.0]];
    private const SETTINGS_FIELDS = ['mode', 'never_frozen', 'bake_state', 'stock_min', 'stock_max', 'coverage_days', 'batch_min', 'batch_multiple', 'batch_people', 'batch_minutes',
        'shelf_life_workshop_days', 'shelf_life_shop_days', 'primary_group_id', 'wizard_step', 'stock_calculated'];
    private const LOGISTICS_FIELDS = ['weight', 'ppc', 'ppb', 'bpc', 'cpl', 'lpp', 'cpp', 'carton_length_mm', 'carton_width_mm', 'carton_height_mm',
        'carton_tare_g', 'carton_type', 'carton_ean', 'pallet_type', 'storage'];
    private const STEP_FIELDS = ['id', 'name_pl', 'name_fr', 'workstation_id', 'fixed_time_s', 'variable_time_s', 'capacity_per_pass',
        'operators_required', 'min_level_order', 'delay_after_s', 'delay_workstation_id', 'cleaning_required', 'cleaning_time_s', 'temperature_c', 'alternatives'];
    private const ALT_FIELDS = ['workstation_id', 'fixed_time_s', 'variable_time_s', 'capacity_per_pass', 'priority'];

    public function __construct(
        private ItemRepository $items,
        private GroupRepository $groups,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(?string $type, ?int $groupId, ?string $status, ?string $mode, ?string $search, ApiContext $context, ?int $finalProductId = null): array
    {
        return array_map(fn($row) => $this->summary($row, $context), $this->items->list($type, $groupId, $status, $mode, $search, $finalProductId));
    }

    /**
     * Zapasy wg kategorii (lista kart) : tryb MTS / MTO, stany min. / maks., sprzedaż tygodniowa i średnio
     * na sklep na tydzień (WDT z stock.suggest_weeks tygodni ; sklep = klient, który kupił w tym okresie),
     * stany proponowane (stock.suggest_min_weeks / stock.suggest_max_weeks tygodni sprzedaży) i zapas
     * optymalny (stan maks. proponowany) w kartonach i paletach (kartony ÷ kartony na paletę z ERP).
     */
    public function stockOverview(?int $groupId): array
    {
        $this->items->createMissingCards();
        $weeks = max(1, (int) $this->params->get('stock.suggest_weeks', '6'));
        $minWeeks = max(0.0, (float) $this->params->get('stock.suggest_min_weeks', '1'));
        $maxWeeks = max($minWeeks, (float) $this->params->get('stock.suggest_max_weeks', '3'));
        $whole = fn(float $c) => (int) ceil(round($c, 6));
        $categories = [];
        $groups = [];
        // Zapas MTS (cel = stan maks. karty, bez maks. : propozycja ze sprzedaży) : kartony, palety, wartość
        // po średniej cenie sprzedaży kartonu (WDT okresu) i po koszcie surowców ; stan wolny dziś obok.
        $emptyTotals = ['products' => 0, 'mts' => 0, 'weekly_cartons' => 0.0, 'optimal_cartons' => 0, 'optimal_pallets' => 0.0,
            'target_cartons' => 0, 'target_pallets' => 0.0, 'target_value_eur' => 0.0, 'target_cost_eur' => 0.0,
            'current_cartons' => 0.0, 'current_value_eur' => 0.0, 'no_price' => 0, 'no_cpp' => 0,
            'stock_all_cartons' => 0.0, 'stock_all_value_eur' => 0.0];
        $all = $emptyTotals;
        $add = function (array &$t, array $item, float $weekly, int $optimal) {
            $t['products']++;
            $t['weekly_cartons'] = round($t['weekly_cartons'] + $weekly, 2);
            $t['optimal_cartons'] += $optimal;
            $t['optimal_pallets'] = round($t['optimal_pallets'] + ($item['optimal_pallets'] ?? 0), 2);
            // Stan wolny wszystkich produktów (MTS i MTO) : wartość magazynu dziś.
            $t['stock_all_cartons'] += $item['free_cartons'];
            $t['stock_all_value_eur'] = round($t['stock_all_value_eur'] + ($item['current_value_eur'] ?? 0), 2);
            if ($item['mode'] !== 'MTS') return;
            $t['mts']++;
            $t['target_cartons'] += $item['target_cartons'];
            $t['target_pallets'] = round($t['target_pallets'] + ($item['target_pallets'] ?? 0), 2);
            $t['target_value_eur'] = round($t['target_value_eur'] + ($item['target_value_eur'] ?? 0), 2);
            $t['target_cost_eur'] = round($t['target_cost_eur'] + ($item['target_cost_eur'] ?? 0), 2);
            $t['current_cartons'] += $item['free_cartons'];
            $t['current_value_eur'] = round($t['current_value_eur'] + ($item['current_value_eur'] ?? 0), 2);
            if ($item['price_eur'] === null) $t['no_price']++;
            if ($item['target_cartons'] > 0 && $item['cpp'] === null) $t['no_cpp']++;
        };
        foreach ($this->items->stockOverview($weeks * 7, $groupId) as $r) {
            $ppc = (int) $r['ppc'] > 0 ? (int) $r['ppc'] : null;
            $cpp = (int) $r['cpp'] > 0 ? (int) $r['cpp'] : null;
            $weekly = (float) $r['shipped_cartons'] / $weeks;
            $shops = (int) $r['shops'];
            $optimal = $whole($weekly * $maxWeeks);
            $cartons = fn($q) => $q === null || !$ppc ? null : round((float) $q / $ppc, 2);
            $key = $r['id_category'] !== null ? (int) $r['id_category'] : 0;
            $categories[$key] ??= ['id' => $key ?: null, 'name' => $r['category'], 'items' => [], 'totals' => $emptyTotals];
            $item = [
                // Produkt bez karty : id null (karta powstaje przy pierwszym przełączeniu na MTS / zmianie stanów).
                'id' => $r['id'] !== null ? (int) $r['id'] : null, 'has_card' => $r['id'] !== null,
                'final_product_id' => (int) $r['id_final_product'], 'name' => $r['name'], 'erp_active' => (bool) $r['erp_active'],
                'group_id' => $r['id_primary_group'] !== null ? (int) $r['id_primary_group'] : null, 'status' => $r['status'] ?? 'draft',
                'mode' => $r['mode'] ?? 'MTO', 'never_frozen' => (bool) ($r['never_frozen'] ?? false), 'ppc' => $ppc, 'cpp' => $cpp,
                'stock_min' => $this->num($r['stock_min']), 'stock_max' => $this->num($r['stock_max']), 'batch' => $this->num($r['batch_multiple']),
                'stock_min_cartons' => $cartons($r['stock_min']), 'stock_max_cartons' => $cartons($r['stock_max']), 'stock_calc_at' => $r['stock_calc_at'],
                'shipped_cartons' => round((float) $r['shipped_cartons'], 1), 'weekly_cartons' => round($weekly, 2), 'shops' => $shops,
                'per_shop_weekly_cartons' => $shops ? round($weekly / $shops, 2) : null,
                'per_shop_weekly_quantity' => $shops && $ppc ? round($weekly / $shops * $ppc, 1) : null,
                'suggested_min_cartons' => $whole($weekly * $minWeeks), 'suggested_max_cartons' => $optimal,
                'optimal_cartons' => $optimal, 'optimal_pallets' => $cpp ? round($optimal / $cpp, 2) : null,
            ] + $this->stockLevel($r, $ppc, $whole);
            $shipped = (float) $r['shipped_cartons'];
            $item['price_eur'] = $shipped > 0 ? round((float) $r['shipped_value_eur'] / $shipped, 2) : null;
            $item['cost_eur'] = $r['material_cost_eur'] !== null && (float) $r['material_cost_eur'] > 0 ? round((float) $r['material_cost_eur'], 2) : null;
            $target = $item['mode'] !== 'MTS' ? 0 : ($item['stock_max_cartons'] !== null ? $whole($item['stock_max_cartons']) : $optimal);
            $item['target_cartons'] = $target;
            $item['target_pallets'] = $cpp && $target ? round($target / $cpp, 2) : ($target ? null : 0.0);
            $item['target_value_eur'] = $item['price_eur'] !== null ? round($target * $item['price_eur'], 2) : null;
            $item['target_cost_eur'] = $item['cost_eur'] !== null ? round($target * $item['cost_eur'], 2) : null;
            $item['current_value_eur'] = $item['price_eur'] !== null ? round($item['free_cartons'] * $item['price_eur'], 2) : null;
            $categories[$key]['items'][] = $item;
            $add($categories[$key]['totals'], $item, $weekly, $optimal);
            $g = $item['group_id'] ?? 0;
            $groups[$g] ??= ['id' => $item['group_id'], 'totals' => $emptyTotals];
            $add($groups[$g]['totals'], $item, $weekly, $optimal);
            $add($all, $item, $weekly, $optimal);
        }
        return ['params' => ['weeks' => $weeks, 'min_weeks' => $minWeeks, 'max_weeks' => $maxWeeks,
                    'from' => date('Y-m-d', strtotime('-' . ($weeks * 7) . ' days')), 'to' => date('Y-m-d', strtotime('-1 day'))],
            'categories' => array_values($categories), 'groups' => array_values($groups), 'totals' => $all];
    }

    /**
     * Stan magazynu produktu (kartony) : wolny stan (carton_stock status 1), zamówienia otwarte jeszcze
     * nie paletyzowane (Nowe, W realizacji — paletyzowane kartony już zeszły ze stanu wolnego), zlecenia MTS
     * w toku ; dostępne = stan − zamówienia + w produkcji ; do produkcji poza zamówieniami = stan min.
     * (i maks.) − dostępne nieujemne ; zamówienia ponad stan (dostępne < 0) osobno.
     */
    private function stockLevel(array $r, ?int $ppc, callable $whole): array
    {
        $free = (float) $r['free_cartons'];
        $ordered = (float) $r['ordered_cartons'];
        $inProduction = $ppc ? round((float) $r['in_production'] / $ppc, 1) : 0.0;
        $min = $r['stock_min'] !== null && $ppc ? (float) $r['stock_min'] / $ppc : null;
        $max = $r['stock_max'] !== null && $ppc ? (float) $r['stock_max'] / $ppc : null;
        // Ta sama formuła co /stock (App\Domain\Stock\ToProduce).
        $split = ToProduce::split($ordered, $free, $min, $inProduction);
        $available = $split['available'];
        return [
            'free_cartons' => $free, 'ordered_cartons' => $ordered, 'in_production_cartons' => $inProduction, 'available_cartons' => $available,
            // Poza zamówieniami : brak na zamówienia (dostępne < 0) produkuje się jako zamówienia, osobno.
            'to_min_cartons' => $split['to_min'] !== null ? $whole($split['to_min']) : null,
            'to_max_cartons' => $max !== null ? $whole(max(0.0, $max - max(0.0, $available))) : null,
            'orders_over_stock_cartons' => $whole($split['orders']),
            'to_produce_cartons' => $whole($split['orders']) + ($split['to_min'] !== null ? $whole($split['to_min']) : 0),
            'below_min' => $min !== null && $available < $min,
        ];
    }

    /**
     * Stany z ostatnich tygodni sprzedaży (krok « Tryb i zapasy ») : średnia tygodniowa z WDT,
     * min = stock.suggest_min_weeks tygodni, maks = stock.suggest_max_weeks tygodni, w pełnych kartonach.
     */
    public function stockSuggestion(int $id): array
    {
        $row = $this->load($id);
        if ($row['item_type'] !== 'product') throw new ApiException('NOT_ALLOWED_FOR_TYPE', 409, [], ['item_type' => $row['item_type']]);
        $weeks = max(1, (int) $this->params->get('stock.suggest_weeks', '6'));
        $minWeeks = max(0.0, (float) $this->params->get('stock.suggest_min_weeks', '1'));
        $maxWeeks = max($minWeeks, (float) $this->params->get('stock.suggest_max_weeks', '3'));
        $ppc = max(1, (int) $row['ppc']);
        $cartons = $this->items->shippedCartons((int) $row['id_final_product'], $weeks * 7);
        $weekly = $cartons / $weeks;
        $whole = fn(float $c) => (int) ceil(round($c, 6));
        return [
            'weeks' => $weeks, 'from' => date('Y-m-d', strtotime('-' . ($weeks * 7) . ' days')), 'to' => date('Y-m-d', strtotime('-1 day')),
            'unit' => $row['base_unit'], 'ppc' => $ppc,
            'shipped_cartons' => round($cartons, 1), 'shipped_quantity' => round($cartons * $ppc, 1),
            'weekly_cartons' => round($weekly, 1), 'weekly_quantity' => round($weekly * $ppc, 1),
            'min_weeks' => $minWeeks, 'max_weeks' => $maxWeeks,
            'stock_min' => $whole($weekly * $minWeeks) * $ppc, 'stock_max' => $whole($weekly * $maxWeeks) * $ppc,
            'stock_min_cartons' => $whole($weekly * $minWeeks), 'stock_max_cartons' => $whole($weekly * $maxWeeks),
        ];
    }

    /** Listy wyboru kroku « Produkt ». */
    public function erpOptions(): array
    {
        $o = $this->items->erpOptions();
        return [
            'categories' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $o['categories']),
            'sections' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $o['sections']),
            'label_types' => self::LABEL_TYPES,
        ];
    }

    /**
     * Krok « Produkt » : dane produktu ERP (te same co formularz edycji produktu). Zmiana działu
     * przenosi kartę do grupy pracowni tego działu, jeśli taka jest.
     */
    public function updateProduct(int $id, array $input, ApiContext $context): array
    {
        $row = $this->load($id);
        $this->requireWrite($row, $context);
        if ($row['item_type'] !== 'product') throw new ApiException('NOT_ALLOWED_FOR_TYPE', 409, [], ['item_type' => $row['item_type']]);
        $v = (new Validator($input, true))->only(['name', 'category_id', 'section_id', 'label_type', 'shelf_life', 'stock_minimum'])
            ->string('name', 255)
            ->integer('category_id', false, 1)
            ->integer('section_id', false, 1)
            ->integer('label_type', false, 0, 1)
            ->integer('shelf_life', false, 0, 3650)
            ->integer('stock_minimum', false, 0, 1000000);
        if ($v->provided('name') && trim((string) $v->value('name')) === '' && !$v->hasError('name')) $v->add('name', 'REQUIRED');
        if ($v->value('category_id') !== null && !$v->hasError('category_id') && !$this->items->categoryExists((int) $v->value('category_id'))) $v->add('category_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('category_id')]);
        if ($v->value('section_id') !== null && !$v->hasError('section_id') && !$this->items->sectionExists((int) $v->value('section_id'))) $v->add('section_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('section_id')]);
        $v->throwIfInvalid();
        $clean = $v->clean();
        $data = [];
        foreach (['name' => 'name', 'category_id' => 'id_category', 'section_id' => 'id_section', 'label_type' => 'label_type', 'shelf_life' => 'shelf_life', 'stock_minimum' => 'stock_minimum'] as $in => $col) {
            if (array_key_exists($in, $clean)) $data[$col] = $in === 'name' ? trim((string) $clean[$in]) : $clean[$in];
        }
        $group = null;
        if (isset($data['id_section']) && (int) $data['id_section'] !== (int) $row['id_section']) {
            foreach ($this->groups->list(null) as $g) {
                if ((int) $g['id_section'] === (int) $data['id_section'] && $g['active']) { $group = (int) $g['id']; break; }
            }
        }
        $before = ['name' => $row['name'], 'id_category' => $row['id_category'], 'id_section' => $row['id_section'], 'label_type' => $row['label_type'],
            'shelf_life' => $row['shelf_life'], 'stock_minimum' => $row['stock_minimum']];
        $this->tx->execute(function () use ($id, $row, $data, $group, $before, $context) {
            if ($data) $this->items->updateErpProduct((int) $row['id_final_product'], $data);
            if ($group !== null) $this->items->saveSettings($id, ['id_primary_group' => $group, 'updated_by' => $context->userId]);
            $this->audit->record($context, 'update', 'item_product', $id, array_intersect_key($before, $data), $data + ($group !== null ? ['primary_group_id' => $group] : []));
        });
        return $this->get($id, $context);
    }

    public function candidates(string $type, ?string $search): array
    {
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'ppc' => isset($r['ppc']) ? (int) $r['ppc'] : null,
            'weight' => isset($r['weight']) ? (float) $r['weight'] : null,
            'section_id' => isset($r['id_section']) ? (int) $r['id_section'] : null,
        ], $this->items->candidates($type, $search, 50));
    }

    public function get(int $id, ApiContext $context): array
    {
        $row = $this->load($id);
        $steps = $this->items->steps($id);
        $alternatives = $this->items->alternatives(array_map(fn($s) => (int) $s['id'], $steps));
        $recipeId = $this->recipeId($row);
        $samples = json_decode($this->params->get('timing.sample_quantities', '[10,60,240]'), true) ?: [10, 60, 240];
        $lang = $context->translator->lang();
        return $this->summary($row, $context) + [
            'logistics' => $this->logistics($row, $steps),
            'equipment' => $this->equipment($steps, $lang),
            'routing' => array_map(fn($s) => $this->presentStep($s, $alternatives[(int) $s['id']] ?? [], $lang), $steps),
            'bom' => $recipeId ? array_map(fn($b) => [
                'child_kind' => $b['child_kind'],
                'child_id' => (int) $b['child_id'],
                'name' => $b['name'],
                'quantity' => (float) $b['quantity'],
                'unit' => $b['unit'],
                'loss_pct' => (float) $b['loss_pct'],
            ], $this->items->bom($recipeId)) : [],
            'durations' => array_map(fn($q) => $this->durationOf($steps, (float) $q), array_values(array_filter($samples, 'is_numeric'))),
            'checklist' => $this->checklist($row, $steps),
            'stock' => $row['item_type'] === 'product' ? $this->cardStock($id) : null,
        ];
    }

    /** Stan magazynu karty (kartony, jak « Stan magazynu ») : krok « Tryb i zapasy » liczy z niego partie do stanów. */
    private function cardStock(int $id): ?array
    {
        $r = $this->items->stockOverview(1, null, $id)[0] ?? null;
        if ($r === null || (int) $r['ppc'] <= 0) return null;
        return $this->stockLevel($r, (int) $r['ppc'], fn(float $c) => (int) ceil(round($c, 6)));
    }

    public function create(array $input, ApiContext $context): array
    {
        $v = (new Validator($input))->only(['item_type', 'final_product_id', 'recipe_id'])
            ->choice('item_type', self::TYPES)
            ->integer('final_product_id', false, 1, null, true)
            ->integer('recipe_id', false, 1, null, true);
        $type = $v->value('item_type');
        $erp = null;
        if ($type === 'product') {
            if ($v->value('final_product_id') === null) { if (!$v->hasError('final_product_id')) $v->add('final_product_id', 'REQUIRED'); }
            elseif (!($erp = $this->items->erpProduct($v->value('final_product_id')))) $v->add('final_product_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('final_product_id')]);
            if ($v->value('recipe_id') !== null) $v->add('recipe_id', 'NOT_ALLOWED_FOR_TYPE');
        } elseif ($type === 'semi') {
            if ($v->value('recipe_id') === null) { if (!$v->hasError('recipe_id')) $v->add('recipe_id', 'REQUIRED'); }
            elseif (!($erp = $this->items->erpRecipe($v->value('recipe_id')))) $v->add('recipe_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('recipe_id')]);
            if ($v->value('final_product_id') !== null) $v->add('final_product_id', 'NOT_ALLOWED_FOR_TYPE');
        }
        $v->throwIfInvalid();

        $existing = $type === 'product' ? $this->items->idForProduct((int) $erp['id']) : $this->items->idForRecipe((int) $erp['id']);
        if ($existing !== null) throw new ApiException('ITEM_EXISTS', 409, [], ['id' => $existing]);

        // Groupe par défaut : le premier groupe du dział de production du produit.
        $groupId = null;
        if ($type === 'product' && $erp['id_section']) {
            foreach ($this->groups->list(null) as $g) {
                if ((int) $g['id_section'] === (int) $erp['id_section'] && $g['active']) { $groupId = (int) $g['id']; break; }
            }
        }
        if (!$context->canInGroup('prod.products.write', $groupId)) {
            throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.products.write']);
        }
        $id = $this->tx->execute(function () use ($type, $erp, $groupId, $context) {
            $id = $this->items->insert([
                'item_type' => $type,
                'id_final_product' => $type === 'product' ? (int) $erp['id'] : null,
                'id_recipe' => $type === 'semi' ? (int) $erp['id'] : null,
                'base_unit' => $type === 'product' ? 'szt' : 'kg',
            ]);
            $this->items->saveSettings($id, ['mode' => 'MTO', 'never_frozen' => 0, 'id_primary_group' => $groupId, 'status' => 'draft', 'wizard_step' => 1, 'updated_by' => $context->userId]);
            $this->audit->record($context, 'create', 'item', $id, null, ['item_type' => $type, 'erp_id' => (int) $erp['id'], 'primary_group_id' => $groupId]);
            return $id;
        });
        return $this->get($id, $context);
    }

    public function updateSettings(int $id, array $input, ApiContext $context): array
    {
        $row = $this->load($id);
        $this->requireWrite($row, $context);
        $v = (new Validator($input, true))->only(self::SETTINGS_FIELDS)
            ->choice('mode', self::MODES)
            ->boolean('never_frozen')
            ->choice('bake_state', self::BAKE_STATES, false, true)
            ->decimal('stock_min', false, 0, null, true)
            ->decimal('stock_max', false, 0, null, true)
            ->decimal('coverage_days', false, 0, 365, true)
            ->decimal('batch_min', false, 0, null, true, true)
            ->decimal('batch_multiple', false, 0, null, true, true)
            ->decimal('batch_people', false, 0, 100, true, true)
            ->decimal('batch_minutes', false, 0, 10000, true, true)
            ->decimal('shelf_life_workshop_days', false, 0, 3650, true)
            ->decimal('shelf_life_shop_days', false, 0, 3650, true)
            ->integer('primary_group_id', false, 1, null, true)
            ->integer('wizard_step', false, 1, 8)
            ->boolean('stock_calculated');
        if ($v->value('primary_group_id') !== null && !$this->groups->exists($v->value('primary_group_id'))) {
            $v->add('primary_group_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('primary_group_id')]);
        }
        // Règles croisées sur l'état final (valeurs envoyées, sinon valeurs actuelles).
        $final = fn(string $field, string $column) => $v->provided($field) ? $v->value($field) : $row[$column];
        $mode = $final('mode', 'mode');
        $neverFrozen = (bool) $final('never_frozen', 'never_frozen');
        $min = $final('stock_min', 'stock_min');
        $max = $final('stock_max', 'stock_max');
        if (!$v->hasError('mode') && $neverFrozen && $mode === 'MTS') $v->add('mode', 'NEVER_FROZEN_MTS');
        if ($mode === 'MTS' && $max === null && !$v->hasError('stock_max')) $v->add('stock_max', 'REQUIRED_FOR_MTS');
        if ($min !== null && $max !== null && (float) $max < (float) $min && !$v->hasError('stock_max')) $v->add('stock_max', 'STOCK_MAX_BELOW_MIN');
        $v->throwIfInvalid();

        $data = $v->clean();
        // Stany z « Oblicz ze sprzedaży » : zapamiętana data obliczenia.
        if (!empty($data['stock_calculated'])) $data['stock_calc_at'] = date('Y-m-d H:i:s');
        unset($data['stock_calculated']);
        if (array_key_exists('primary_group_id', $data)) {
            $data['id_primary_group'] = $data['primary_group_id'];
            unset($data['primary_group_id']);
            if (!$context->canInGroup('prod.products.write', $data['id_primary_group'])) {
                throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.products.write']);
            }
        }
        $data['updated_by'] = $context->userId;
        $erpMin = $this->erpStockMinimum($row, $mode, $min, $v->provided('mode'));
        $this->tx->execute(function () use ($id, $data, $row, $context, $erpMin) {
            $this->items->saveSettings($id, $data);
            if ($erpMin !== null && $erpMin !== (int) $row['stock_minimum']) {
                $this->items->updateErpProduct((int) $row['id_final_product'], ['stock_minimum' => $erpMin]);
                $this->audit->record($context, 'update', 'item_erp', $id, ['stock_minimum' => (int) $row['stock_minimum']], ['stock_minimum' => $erpMin]);
            }
            $this->audit->record($context, 'update', 'item_settings', $id, $row, $data);
        });
        return $this->get($id, $context);
    }

    /**
     * Minimalny stan ERP (final_product.stock_minimum, kartony) = stan min. karty (sztuki), zaokrąglony w górę :
     * lista produkcyjna PDF i « Do produkcji » liczą wtedy z tej samej wartości. Przejście na MTO zeruje minimum ;
     * karta bez stanu min. nie zmienia wartości ERP.
     */
    private function erpStockMinimum(array $row, ?string $mode, mixed $min, bool $modeChanged): ?int
    {
        if ($row['item_type'] !== 'product' || !$row['id_final_product']) return null;
        if ($mode === 'MTS' && $min !== null && (int) $row['ppc'] > 0) return (int) ceil((float) $min / (int) $row['ppc'] - 1e-9);
        if ($mode === 'MTO' && $modeChanged && $row['mode'] !== 'MTO') return 0;
        return null;
    }

    /**
     * Étape 1 : logistique du produit. Poids, pièces par sachet / carton, cartons par couche et par
     * palette restent dans l'ERP (final_product) ; carton (dimensions, tare, type, EAN), palette et
     * conservation vont dans tfb_prod_item_logistics. Un semi-fini n'a pas de logistique.
     */
    public function updateLogistics(int $id, array $input, ApiContext $context): array
    {
        $row = $this->load($id);
        $this->requireWrite($row, $context);
        if ($row['item_type'] !== 'product') throw new ApiException('NOT_ALLOWED_FOR_TYPE', 409, [], ['item_type' => $row['item_type']]);
        $v = (new Validator($input, true))->only(self::LOGISTICS_FIELDS)
            // Colonnes NOT NULL de l'ERP (entiers, 0 = non renseigné) : jamais vidées depuis la fiche.
            ->integer('weight', false, 1, 100000)
            ->integer('ppc', false, 1, 100000)
            ->integer('ppb', false, 0, 100000)
            ->integer('bpc', false, 0, 100000)
            ->integer('cpl', false, 0, 1000)
            ->integer('lpp', false, 0, 100)
            ->integer('cpp', false, 0, 10000)
            ->integer('carton_length_mm', false, 1, 3000, true)
            ->integer('carton_width_mm', false, 1, 3000, true)
            ->integer('carton_height_mm', false, 1, 3000, true)
            ->integer('carton_tare_g', false, 0, 100000, true)
            ->choice('carton_type', self::CARTON_TYPES, false, true)
            ->string('carton_ean', 14, false, true, '/^[0-9]{8,14}$/', 'INVALID_EAN')
            ->choice('pallet_type', self::PALLET_TYPES, false, true)
            ->choice('storage', self::STORAGES, false, true);
        // Palette : jamais plus de cartons que couches × cartons par couche (valeurs finales).
        $final = fn(string $f) => $v->provided($f) ? $v->value($f) : $row[$f];
        $cpl = $final('cpl');
        $lpp = $final('lpp');
        $cpp = $final('cpp');
        if ((int) $cpl > 0 && (int) $lpp > 0 && (int) $cpp > (int) $cpl * (int) $lpp && !$v->hasError('cpp')) {
            $v->add('cpp', 'PALLET_OVER_LAYERS', ['max' => (int) $cpl * (int) $lpp]);
        }
        $v->throwIfInvalid();
        $data = $v->clean();
        $erp = array_intersect_key($data, array_flip(['weight', 'ppc', 'ppb', 'bpc', 'cpl', 'lpp', 'cpp']));
        $own = array_diff_key($data, $erp);
        $before = array_intersect_key($row, $data);
        $this->tx->execute(function () use ($id, $row, $erp, $own, $before, $data, $context) {
            if ($erp) $this->items->updateErpLogistics((int) $row['id_final_product'], $erp);
            if ($own) $this->items->saveLogistics($id, $own + ['updated_by' => $context->userId]);
            $this->audit->record($context, 'update', 'item_logistics', $id, $before, $data);
        });
        return $this->get($id, $context);
    }

    public function replaceRouting(int $id, array $input, ApiContext $context): array
    {
        $row = $this->load($id);
        $this->requireWrite($row, $context);
        $outer = (new Validator($input))->only(['steps']);
        $raw = $outer->raw('steps');
        if (!is_array($raw) || !array_is_list($raw)) {
            $outer->add('steps', 'NOT_ARRAY');
            $outer->throwIfInvalid();
        }
        $current = [];
        foreach ($this->items->steps($id) as $s) $current[(int) $s['id']] = $s;
        $errors = [];
        $steps = [];
        $wsIds = [];
        foreach ($raw as $i => $stepInput) {
            $wsIds[] = (int) ($stepInput['workstation_id'] ?? 0);
            $wsIds[] = (int) ($stepInput['delay_workstation_id'] ?? 0);
            foreach ((array) ($stepInput['alternatives'] ?? []) as $alt) $wsIds[] = (int) (is_array($alt) ? ($alt['workstation_id'] ?? 0) : 0);
        }
        $workstations = $this->items->workstations(array_values(array_unique(array_filter($wsIds))));

        foreach ($raw as $i => $stepInput) {
            if (!is_array($stepInput)) { $errors[] = ['field' => "steps.{$i}", 'code' => 'NOT_ARRAY']; continue; }
            $v = (new Validator($stepInput))->only(self::STEP_FIELDS)
                ->integer('id', false, 1, null, true)
                ->string('name_pl', 100)
                ->string('name_fr', 100, false, true)
                ->integer('workstation_id', true, 1)
                ->integer('fixed_time_s', false, 0, 86400)
                ->decimal('variable_time_s', false, 0, 86400)
                ->decimal('capacity_per_pass', false, 0, null, false, true)
                ->decimal('operators_required', false, 0, 50)
                ->integer('min_level_order', false, 0, 100, true)
                ->integer('delay_after_s', false, 0, 7 * 86400)
                ->integer('delay_workstation_id', false, 1, null, true)
                ->boolean('cleaning_required')
                ->integer('cleaning_time_s', false, 0, 86400)
                ->decimal('temperature_c', false, -60, 400, true);
            $stepId = $v->value('id');
            if ($stepId !== null && !isset($current[$stepId])) $v->add('id', 'REFERENCE_NOT_FOUND', ['id' => $stepId]);
            $ws = $workstations[(int) $v->value('workstation_id')] ?? null;
            if ($v->value('workstation_id') !== null && (!$ws || !$ws['active'])) $v->add('workstation_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('workstation_id')]);
            $delayWs = $v->value('delay_workstation_id') !== null ? ($workstations[(int) $v->value('delay_workstation_id')] ?? null) : null;
            if ($v->value('delay_workstation_id') !== null) {
                if (!$delayWs || !$delayWs['active']) $v->add('delay_workstation_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('delay_workstation_id')]);
                elseif (!in_array($delayWs['type'], self::DELAY_TYPES, true)) $v->add('delay_workstation_id', 'DELAY_WORKSTATION_TYPE');
                if ((int) $v->value('delay_after_s', 0) === 0) $v->add('delay_after_s', 'REQUIRED_WITH_DELAY_WORKSTATION');
            }
            $fixed = (int) $v->value('fixed_time_s', 0);
            $variable = (float) $v->value('variable_time_s', 0);
            if (!$v->hasError('fixed_time_s') && !$v->hasError('variable_time_s') && $fixed === 0 && $variable == 0.0) $v->add('variable_time_s', 'NO_TIME');
            $cleaning = (bool) $v->value('cleaning_required', false);
            if (!$cleaning && (int) $v->value('cleaning_time_s', 0) > 0) $v->add('cleaning_time_s', 'CLEANING_NOT_REQUIRED');
            if ($v->value('temperature_c') !== null && $ws && !in_array($ws['type'], self::TEMPERATURE_TYPES, true)) $v->add('temperature_c', 'TEMPERATURE_NOT_FOR_WORKSTATION');

            $alternatives = [];
            $altErrors = [];
            $altRaw = $v->raw('alternatives');
            if ($altRaw !== null) {
                if (!is_array($altRaw) || !array_is_list($altRaw)) $v->add('alternatives', 'NOT_ARRAY');
                else foreach ($altRaw as $j => $altInput) {
                    $a = (new Validator(is_array($altInput) ? $altInput : []))->only(self::ALT_FIELDS)
                        ->integer('workstation_id', true, 1)
                        ->integer('fixed_time_s', false, 0, 86400)
                        ->decimal('variable_time_s', false, 0, 86400)
                        ->decimal('capacity_per_pass', false, 0, null, false, true)
                        ->integer('priority', false, 1, 99);
                    $altWs = $workstations[(int) $a->value('workstation_id')] ?? null;
                    if ($a->value('workstation_id') !== null && (!$altWs || !$altWs['active'])) $a->add('workstation_id', 'REFERENCE_NOT_FOUND', ['id' => $a->value('workstation_id')]);
                    if ($a->value('workstation_id') !== null && $a->value('workstation_id') === $v->value('workstation_id')) $a->add('workstation_id', 'SAME_AS_MAIN_WORKSTATION');
                    foreach ($alternatives as $prev) if ($prev['id_workstation'] === $a->value('workstation_id')) $a->add('workstation_id', 'DUPLICATE_IN_LIST', ['id' => $a->value('workstation_id')]);
                    foreach ($a->errors() as $err) $altErrors[] = ['field' => "steps.{$i}.alternatives.{$j}.{$err['field']}"] + $err;
                    // Pole puste = wartość etapu (czas stały, czas na sztukę, pojemność) : alternatywa ma tę samą kadencję, chyba że podano inną.
                    $alternatives[] = [
                        'id_workstation' => $a->value('workstation_id'),
                        'fixed_time_s' => (int) $a->value('fixed_time_s', $fixed),
                        'variable_time_s' => (float) $a->value('variable_time_s', $variable),
                        'capacity_per_pass' => (float) $a->value('capacity_per_pass', $v->value('capacity_per_pass') ?? ($ws['capacity_per_pass'] ?? 1)),
                        'priority' => (int) $a->value('priority', $j + 1),
                    ];
                }
            }
            foreach ($v->errors() as $err) $errors[] = ['field' => "steps.{$i}.{$err['field']}"] + $err;
            array_push($errors, ...$altErrors);
            $steps[] = [
                'id' => $stepId,
                'step_no' => $i + 1,
                'name_pl' => $v->value('name_pl'),
                'name_fr' => $v->value('name_fr') ?: $v->value('name_pl'),
                'id_workstation' => $v->value('workstation_id'),
                'fixed_time_s' => $fixed,
                'variable_time_s' => $variable,
                'capacity_per_pass' => (float) $v->value('capacity_per_pass', $ws['capacity_per_pass'] ?? 1),
                'operators_required' => (float) $v->value('operators_required', 1),
                'min_level_order' => $v->value('min_level_order'),
                'delay_after_s' => (int) $v->value('delay_after_s', 0),
                'delay_id_workstation' => $v->value('delay_workstation_id'),
                'cleaning_required' => $cleaning,
                'cleaning_time_s' => $cleaning ? (int) $v->value('cleaning_time_s', 0) : 0,
                'temperature_c' => $v->value('temperature_c'),
                'alternatives' => $alternatives,
            ];
        }
        if (count($raw) > 30) $errors[] = ['field' => 'steps', 'code' => 'TOO_MANY', 'params' => ['max' => 30]];
        if ($errors) throw ApiException::validation($errors);

        $this->tx->execute(function () use ($id, $steps, $current, $context) {
            $this->items->replaceRouting($id, $steps);
            $this->audit->record($context, 'replace', 'item_routing', $id, ['steps' => array_values($current)], ['steps' => $steps]);
        });
        return $this->get($id, $context);
    }

    public function saveBomLosses(int $id, array $input, ApiContext $context): array
    {
        $row = $this->load($id);
        $this->requireWrite($row, $context);
        $recipeId = $this->recipeId($row);
        $outer = (new Validator($input))->only(['losses']);
        $raw = $outer->raw('losses');
        if (!is_array($raw) || !array_is_list($raw)) {
            $outer->add('losses', 'NOT_ARRAY');
            $outer->throwIfInvalid();
        }
        $known = [];
        foreach ($recipeId ? $this->items->bom($recipeId) : [] as $b) $known[$b['child_kind'] . ':' . $b['child_id']] = true;
        $errors = [];
        $rows = [];
        foreach ($raw as $i => $lossInput) {
            $v = (new Validator(is_array($lossInput) ? $lossInput : []))->only(['child_kind', 'child_id', 'loss_pct'])
                ->choice('child_kind', ['material', 'recipe'])
                ->integer('child_id', true, 1)
                ->decimal('loss_pct', true, 0, 99.99);
            if (!$v->hasError('child_kind') && !$v->hasError('child_id') && !isset($known[$v->value('child_kind') . ':' . $v->value('child_id')])) {
                $v->add('child_id', 'NOT_IN_BOM', ['id' => $v->value('child_id')]);
            }
            foreach ($v->errors() as $err) $errors[] = ['field' => "losses.{$i}.{$err['field']}"] + $err;
            $rows[] = [$v->value('child_kind'), (int) $v->value('child_id'), (float) $v->value('loss_pct')];
        }
        if ($errors) throw ApiException::validation($errors);
        $this->tx->execute(function () use ($recipeId, $rows, $id, $context) {
            foreach ($rows as [$kind, $childId, $pct]) $this->items->saveBomLoss($recipeId, $kind, $childId, $pct, $context->userId);
            $this->audit->record($context, 'update', 'item_bom_losses', $id, null, ['recipe_id' => $recipeId, 'losses' => $rows]);
        });
        return $this->get($id, $context);
    }

    /** Étape 7 : la fiche passe « validée » si la liste de contrôle est complète. */
    public function validate(int $id, ApiContext $context): array
    {
        $row = $this->load($id);
        $this->requireWrite($row, $context);
        $missing = array_values(array_filter($this->checklist($row, $this->items->steps($id)), fn($c) => !$c['ok']));
        if ($missing) {
            throw ApiException::validation(array_map(fn($c) => ['field' => $c['field'], 'code' => $c['code']], $missing));
        }
        $this->tx->execute(function () use ($id, $context) {
            $this->items->saveSettings($id, ['status' => 'validated', 'wizard_step' => 8, 'validated_at' => date('Y-m-d H:i:s'), 'validated_by' => $context->userId, 'updated_by' => $context->userId]);
            $this->audit->record($context, 'validate', 'item', $id, null, null);
        });
        return $this->get($id, $context);
    }

    public function duration(int $id, float $quantity): array
    {
        $this->load($id);
        if ($quantity <= 0 || $quantity > 1_000_000) {
            throw ApiException::validation([['field' => 'quantity', 'code' => 'OUT_OF_RANGE', 'params' => ['min' => 0, 'max' => 1000000]]]);
        }
        return $this->durationOf($this->items->steps($id), $quantity);
    }

    private function durationOf(array $steps, float $quantity): array
    {
        $calc = StandardTime::routing($steps, $quantity);
        return [
            'quantity' => $quantity,
            'steps' => array_map(fn($s, $c) => ['step_no' => (int) $s['step_no'], 'passes' => $c['passes'], 'work_s' => round($c['work_s'], 1),
                'cleaning_s' => $c['cleaning_s'], 'delay_s' => $c['delay_s'], 'labour_s' => round($c['labour_s'], 1)], $steps, $calc['steps']),
            'work_s' => round($calc['totals']['work_s'], 1),
            'elapsed_s' => round($calc['totals']['elapsed_s'], 1),
            'labour_s' => round($calc['totals']['labour_s'], 1),
            'labour_s_per_unit' => $calc['labour_s_per_unit'] === null ? null : round($calc['labour_s_per_unit'], 3),
        ];
    }

    private function checklist(array $row, array $steps): array
    {
        $checks = [
            ['field' => 'primary_group_id', 'code' => 'REQUIRED', 'ok' => $row['id_primary_group'] !== null],
            ['field' => 'mode', 'code' => 'NEVER_FROZEN_MTS', 'ok' => !($row['never_frozen'] && $row['mode'] === 'MTS')],
            ['field' => 'stock_max', 'code' => 'REQUIRED_FOR_MTS', 'ok' => $row['mode'] !== 'MTS' || $row['stock_max'] !== null],
            ['field' => 'routing', 'code' => 'ROUTING_EMPTY', 'ok' => count($steps) > 0],
        ];
        if ($row['item_type'] === 'product') {
            $checks[] = ['field' => 'ppc', 'code' => 'REQUIRED', 'ok' => (int) ($row['ppc'] ?? 0) > 0];
            $checks[] = ['field' => 'bake_state', 'code' => 'REQUIRED', 'ok' => $row['bake_state'] !== null];
            $hasOven = (bool) array_filter($steps, fn($s) => $s['workstation_type'] === 'four');
            $checks[] = ['field' => 'bake_state', 'code' => 'BAKE_WITHOUT_OVEN', 'ok' => !in_array($row['bake_state'], ['par_baked', 'baked'], true) || $hasOven];
        }
        foreach ($steps as $s) {
            if ((int) $s['fixed_time_s'] === 0 && (float) $s['variable_time_s'] == 0.0) {
                $checks[] = ['field' => 'routing.' . $s['step_no'], 'code' => 'NO_TIME', 'ok' => false];
            }
        }
        return $checks;
    }

    private function summary(array $row, ApiContext $context): array
    {
        return [
            'id' => (int) $row['id'],
            'item_type' => $row['item_type'],
            'final_product_id' => $row['id_final_product'] !== null ? (int) $row['id_final_product'] : null,
            'recipe_id' => $this->recipeId($row),
            'name' => $row['name'],
            'base_unit' => $row['base_unit'],
            'erp' => [
                'ppc' => $row['ppc'] !== null ? (int) $row['ppc'] : null,
                'weight' => $row['weight'] !== null ? (float) $row['weight'] : null,
                'ppb' => $row['ppb'] !== null ? (int) $row['ppb'] : null,
                'bpc' => $row['bpc'] !== null ? (int) $row['bpc'] : null,
                'cpl' => $row['cpl'] !== null ? (int) $row['cpl'] : null,
                'lpp' => $row['lpp'] !== null ? (int) $row['lpp'] : null,
                'cpp' => $row['cpp'] !== null ? (int) $row['cpp'] : null,
                'section_id' => $row['id_section'] !== null ? (int) $row['id_section'] : null,
                'name' => $row['id_final_product'] !== null ? $row['name'] : null,
                'category_id' => isset($row['id_category']) ? (int) $row['id_category'] : null,
                'label_type' => isset($row['label_type']) ? (int) $row['label_type'] : null,
                'shelf_life' => isset($row['shelf_life']) ? (int) $row['shelf_life'] : null,
                'stock_minimum' => isset($row['stock_minimum']) ? (int) $row['stock_minimum'] : null,
                'active' => $row['erp_active'] === null ? true : (bool) $row['erp_active'],
            ],
            'settings' => [
                'mode' => $row['mode'] ?? 'MTO',
                'never_frozen' => (bool) ($row['never_frozen'] ?? false),
                'bake_state' => $row['bake_state'] ?? null,
                'stock_min' => $this->num($row['stock_min']),
                'stock_max' => $this->num($row['stock_max']),
                'coverage_days' => $this->num($row['coverage_days']),
                'batch_min' => $this->num($row['batch_min']),
                'batch_multiple' => $this->num($row['batch_multiple']),
                'batch_people' => $this->num($row['batch_people'] ?? null),
                'batch_minutes' => $this->num($row['batch_minutes'] ?? null),
                'stock_calc_at' => $row['stock_calc_at'] ?? null,
                'shelf_life_workshop_days' => $this->num($row['shelf_life_workshop_days']),
                'shelf_life_shop_days' => $this->num($row['shelf_life_shop_days']),
                'primary_group_id' => $row['id_primary_group'] !== null ? (int) $row['id_primary_group'] : null,
                'status' => $row['status'] ?? 'draft',
                'wizard_step' => (int) ($row['wizard_step'] ?? 1),
                'validated_at' => $row['validated_at'],
            ],
            'step_count' => (int) $row['step_count'],
            'can_edit' => $context->canInGroup('prod.products.write', $row['id_primary_group'] !== null ? (int) $row['id_primary_group'] : null),
        ];
    }

    private function presentStep(array $s, array $alternatives, string $lang): array
    {
        return [
            'id' => (int) $s['id'],
            'step_no' => (int) $s['step_no'],
            'name' => $s['name_' . $lang],
            'name_pl' => $s['name_pl'],
            'name_fr' => $s['name_fr'],
            'workstation_id' => (int) $s['id_workstation'],
            'workstation' => ['code' => $s['workstation_code'], 'name' => $s['workstation_name_' . $lang], 'type' => $s['workstation_type']],
            'fixed_time_s' => (int) $s['fixed_time_s'],
            'variable_time_s' => (float) $s['variable_time_s'],
            'capacity_per_pass' => (float) $s['capacity_per_pass'],
            'operators_required' => (float) $s['operators_required'],
            'min_level_order' => $s['min_level_order'] !== null ? (int) $s['min_level_order'] : null,
            'delay_after_s' => (int) $s['delay_after_s'],
            'delay_workstation_id' => $s['delay_id_workstation'] !== null ? (int) $s['delay_id_workstation'] : null,
            'delay_workstation' => $s['delay_id_workstation'] !== null ? ['code' => $s['delay_workstation_code'], 'name' => $s['delay_workstation_name_' . $lang]] : null,
            'cleaning_required' => (bool) $s['cleaning_required'],
            'cleaning_time_s' => (int) $s['cleaning_time_s'],
            'temperature_c' => $s['temperature_c'] !== null ? (float) $s['temperature_c'] : null,
            'alternatives' => array_map(fn($a) => [
                'workstation_id' => (int) $a['id_workstation'],
                'workstation' => ['code' => $a['workstation_code'], 'name' => $a['workstation_name_' . $lang]],
                'fixed_time_s' => (int) $a['fixed_time_s'],
                'variable_time_s' => (float) $a['variable_time_s'],
                'capacity_per_pass' => (float) $a['capacity_per_pass'],
                'priority' => (int) $a['priority'],
            ], $alternatives),
        ];
    }

    /**
     * Logistique : champs saisis et valeurs calculées (carton net / brut, palette : pièces, poids,
     * hauteur ; temps de travail par carton pour une palette de production).
     */
    private function logistics(array $row, array $steps): ?array
    {
        if ($row['item_type'] !== 'product') return null;
        $int = fn($v) => $v === null || (int) $v === 0 ? null : (int) $v;
        $ppc = $int($row['ppc']);
        $weight = $row['weight'] !== null && (float) $row['weight'] > 0 ? (float) $row['weight'] : null;
        $cpp = $int($row['cpp']);
        $lpp = $int($row['lpp']);
        $tare = $int($row['carton_tare_g']);
        $height = $int($row['carton_height_mm']);
        $pallet = self::PALLETS[$row['pallet_type'] ?? ''] ?? null;
        $cartonNet = $ppc && $weight !== null ? $ppc * $weight / 1000 : null;
        $cartonGross = $cartonNet !== null && $tare !== null ? $cartonNet + $tare / 1000 : null;
        $palletUnits = $ppc && $cpp ? $ppc * $cpp : null;
        $labourPerCarton = null;
        if ($ppc && $steps) {
            $quantity = $palletUnits ?: $ppc * 10;
            $calc = StandardTime::routing($steps, (float) $quantity);
            $labourPerCarton = $calc['labour_s_per_unit'] === null ? null : round($calc['labour_s_per_unit'] * $ppc, 1);
        }
        return [
            'carton_length_mm' => $int($row['carton_length_mm']),
            'carton_width_mm' => $int($row['carton_width_mm']),
            'carton_height_mm' => $height,
            'carton_tare_g' => $tare,
            'carton_type' => $row['carton_type'],
            'carton_ean' => $row['carton_ean'],
            'pallet_type' => $row['pallet_type'],
            'storage' => $row['storage'],
            'computed' => [
                'carton_net_kg' => $cartonNet === null ? null : round($cartonNet, 3),
                'carton_gross_kg' => $cartonGross === null ? null : round($cartonGross, 3),
                'pallet_units' => $palletUnits,
                'pallet_net_kg' => $cartonNet !== null && $cpp ? round($cartonNet * $cpp, 1) : null,
                'pallet_gross_kg' => $cartonGross !== null && $cpp && $pallet ? round($cartonGross * $cpp + $pallet['tare_kg'], 1) : null,
                'pallet_height_mm' => $height && $lpp && $pallet ? $height * $lpp + $pallet['base_mm'] : null,
                'pallet_footprint_mm' => $pallet ? [$pallet['length_mm'], $pallet['width_mm']] : null,
                'labour_s_per_carton' => $labourPerCarton,
            ],
        ];
    }

    /** Postes de la gamme (machines, fours, robots…) : base de la gestion des fours et des investissements. */
    private function equipment(array $steps, string $lang): array
    {
        $out = [];
        foreach ($steps as $s) {
            $id = (int) $s['id_workstation'];
            $out[$id] ??= ['workstation_id' => $id, 'code' => $s['workstation_code'], 'name' => $s['workstation_name_' . $lang],
                'type' => $s['workstation_type'], 'steps' => [], 'temperatures_c' => []];
            $out[$id]['steps'][] = (int) $s['step_no'];
            if ($s['temperature_c'] !== null) $out[$id]['temperatures_c'][] = (float) $s['temperature_c'];
        }
        return array_values($out);
    }

    private function recipeId(array $row): ?int
    {
        $id = $row['item_type'] === 'semi' ? $row['id_recipe'] : $row['product_recipe_id'];
        return $id !== null ? (int) $id : null;
    }

    private function requireWrite(array $row, ApiContext $context): void
    {
        if (!$context->canInGroup('prod.products.write', $row['id_primary_group'] !== null ? (int) $row['id_primary_group'] : null)) {
            throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.products.write']);
        }
    }

    private function load(int $id): array
    {
        return $this->items->detail($id) ?? throw ApiException::notFound('item', $id);
    }

    private function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
