<?php
declare(strict_types=1);

namespace App\Services\Workshop\Product;

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
 * 1 article de l'ERP · 2 mode et stocks · 3 groupe et durées de vie · 4 nomenclature et pertes ·
 * 5 gamme · 6 contrôle des temps · 7 validation.
 * Règles : jamais congelé ⇒ jamais MTS ; MTS ⇒ stock maximum renseigné ; stock max ≥ stock min ;
 * une étape a un poste actif, une capacité > 0 et au moins un temps ; le poste d'attente
 * (pousse, froid) est un poste de fermentation, de froid ou de surgélation.
 */
final class ItemService
{
    public const TYPES = ['product', 'semi'];
    public const MODES = ['MTO', 'MTS'];
    public const STATUSES = ['draft', 'validated'];
    private const DELAY_TYPES = ['fermentation', 'froid', 'surgelation'];
    private const SETTINGS_FIELDS = ['mode', 'never_frozen', 'stock_min', 'stock_max', 'coverage_days', 'batch_min', 'batch_multiple',
        'shelf_life_workshop_days', 'shelf_life_shop_days', 'primary_group_id', 'wizard_step'];
    private const STEP_FIELDS = ['id', 'name_pl', 'name_fr', 'workstation_id', 'fixed_time_s', 'variable_time_s', 'capacity_per_pass',
        'operators_required', 'min_level_order', 'delay_after_s', 'delay_workstation_id', 'cleaning_required', 'cleaning_time_s', 'alternatives'];
    private const ALT_FIELDS = ['workstation_id', 'fixed_time_s', 'variable_time_s', 'capacity_per_pass', 'priority'];

    public function __construct(
        private ItemRepository $items,
        private GroupRepository $groups,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(?string $type, ?int $groupId, ?string $status, ?string $mode, ?string $search, ApiContext $context): array
    {
        return array_map(fn($row) => $this->summary($row, $context), $this->items->list($type, $groupId, $status, $mode, $search));
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
        ];
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
            $this->items->saveSettings($id, ['mode' => 'MTO', 'never_frozen' => 0, 'id_primary_group' => $groupId, 'status' => 'draft', 'wizard_step' => 2, 'updated_by' => $context->userId]);
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
            ->decimal('stock_min', false, 0, null, true)
            ->decimal('stock_max', false, 0, null, true)
            ->decimal('coverage_days', false, 0, 365, true)
            ->decimal('batch_min', false, 0, null, true, true)
            ->decimal('batch_multiple', false, 0, null, true, true)
            ->decimal('shelf_life_workshop_days', false, 0, 3650, true)
            ->decimal('shelf_life_shop_days', false, 0, 3650, true)
            ->integer('primary_group_id', false, 1, null, true)
            ->integer('wizard_step', false, 1, 7);
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
        if (array_key_exists('primary_group_id', $data)) {
            $data['id_primary_group'] = $data['primary_group_id'];
            unset($data['primary_group_id']);
            if (!$context->canInGroup('prod.products.write', $data['id_primary_group'])) {
                throw new ApiException('FORBIDDEN', 403, [], ['permission' => 'prod.products.write']);
            }
        }
        $data['updated_by'] = $context->userId;
        $this->tx->execute(function () use ($id, $data, $row, $context) {
            $this->items->saveSettings($id, $data);
            $this->audit->record($context, 'update', 'item_settings', $id, $row, $data);
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
                ->integer('cleaning_time_s', false, 0, 86400);
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
                    $alternatives[] = [
                        'id_workstation' => $a->value('workstation_id'),
                        'fixed_time_s' => (int) $a->value('fixed_time_s', $fixed),
                        'variable_time_s' => (float) $a->value('variable_time_s', $variable),
                        'capacity_per_pass' => (float) $a->value('capacity_per_pass', $altWs['capacity_per_pass'] ?? 1),
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
            $this->items->saveSettings($id, ['status' => 'validated', 'wizard_step' => 7, 'validated_at' => date('Y-m-d H:i:s'), 'validated_by' => $context->userId, 'updated_by' => $context->userId]);
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
                'section_id' => $row['id_section'] !== null ? (int) $row['id_section'] : null,
                'active' => $row['erp_active'] === null ? true : (bool) $row['erp_active'],
            ],
            'settings' => [
                'mode' => $row['mode'] ?? 'MTO',
                'never_frozen' => (bool) ($row['never_frozen'] ?? false),
                'stock_min' => $this->num($row['stock_min']),
                'stock_max' => $this->num($row['stock_max']),
                'coverage_days' => $this->num($row['coverage_days']),
                'batch_min' => $this->num($row['batch_min']),
                'batch_multiple' => $this->num($row['batch_multiple']),
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
