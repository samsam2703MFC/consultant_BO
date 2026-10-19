<?php
declare(strict_types=1);

namespace App\Services\Workshop\Referentiel;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\TransactionManager;
use App\Repositories\Workshop\GroupRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\ShiftTemplateRepository;
use App\Repositories\Workshop\WorkstationRepository;

/**
 * Postes de travail : machine, four, robot, manuel, fermentation, froid, surgélation.
 * Un robot a un mode (autonome / avec opérateur) ; seul un robot autonome peut tourner la nuit.
 */
final class WorkstationService
{
    public const TYPES = ['machine', 'four', 'robot', 'manuel', 'fermentation', 'froid', 'surgelation'];
    public const ROBOT_MODES = ['autonome', 'avec_operateur'];
    private const FIELDS = ['code', 'group_id', 'name_pl', 'name_fr', 'type', 'robot_mode', 'capacity_per_pass', 'capacity_unit', 'hourly_cost', 'photo_url', 'notes', 'active', 'shift_availability'];

    public function __construct(
        private WorkstationRepository $workstations,
        private GroupRepository $groups,
        private ShiftTemplateRepository $shifts,
        private ParamRepository $params,
        private WorkshopConfig $config,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(?int $groupId, ?string $type, ?bool $active, ApiContext $context): array
    {
        $rows = $this->workstations->list($groupId, $type, $active);
        $availability = $this->workstations->availability(array_map(fn($r) => (int) $r['id'], $rows));
        $shifts = $this->shifts->list(null);
        return array_map(fn($row) => $this->present($row, $availability[(int) $row['id']] ?? [], $shifts, $context), $rows);
    }

    public function get(int $id, ApiContext $context): array
    {
        $row = $this->load($id);
        $availability = $this->workstations->availability([$id]);
        return $this->present($row, $availability[$id] ?? [], $this->shifts->list(null), $context);
    }

    public function create(array $input, ApiContext $context): array
    {
        [$data, $availability] = $this->validate($input, null);
        $id = $this->tx->execute(function () use ($data, $availability, $context) {
            $id = $this->workstations->insert($data);
            if ($availability !== null) $this->workstations->replaceAvailability($id, $availability);
            $this->audit->record($context, 'create', 'workstation', $id, null, $data + ['shift_availability' => $availability]);
            return $id;
        });
        return $this->get($id, $context);
    }

    public function update(int $id, array $input, ApiContext $context): array
    {
        $before = $this->load($id);
        [$data, $availability] = $this->validate($input, $before);
        $this->tx->execute(function () use ($id, $data, $availability, $before, $context) {
            $this->workstations->update($id, $data);
            if ($availability !== null) $this->workstations->replaceAvailability($id, $availability);
            $this->audit->record($context, 'update', 'workstation', $id, $before, $data + ['shift_availability' => $availability]);
        });
        return $this->get($id, $context);
    }

    /** @return array{0:array,1:?array<int,bool>} */
    private function validate(array $input, ?array $current): array
    {
        $units = json_decode($this->params->get('workstation.capacity_units', '["piece"]'), true) ?: ['piece'];
        $v = (new Validator($input, $current !== null))->only(self::FIELDS)
            ->code('code')
            ->integer('group_id', true, 1)
            ->string('name_pl', 100)
            ->string('name_fr', 100)
            ->choice('type', self::TYPES)
            ->choice('robot_mode', self::ROBOT_MODES, false, true)
            ->decimal('capacity_per_pass', false, 0, null, false, true)
            ->choice('capacity_unit', array_values(array_map('strval', $units)), false)
            ->decimal('hourly_cost', false, 0)
            ->url('photo_url', $this->config->r2PublicBaseUrl())
            ->string('notes', 500, false, true)
            ->boolean('active');

        if ($v->provided('code') && $this->workstations->codeTaken($v->value('code'), $current ? (int) $current['id'] : null)) {
            throw new ApiException('DUPLICATE_CODE', 409, [], ['code' => $v->value('code')]);
        }
        if ($v->provided('group_id') && !$this->groups->exists($v->value('group_id'))) {
            $v->add('group_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('group_id')]);
        }

        // Mode robot : obligatoire pour un robot, interdit sinon. Passer un robot en autre type
        // sans préciser le mode efface simplement le mode.
        $type = $v->value('type', $current['type'] ?? null);
        if ($type !== null && !$v->hasError('type') && !$v->hasError('robot_mode')) {
            if ($type === 'robot') {
                $mode = $v->hasRaw('robot_mode') ? $v->value('robot_mode') : ($current['robot_mode'] ?? null);
                if ($mode === null) $v->add('robot_mode', 'ROBOT_MODE_REQUIRED');
            } elseif ($v->value('robot_mode') !== null) {
                $v->add('robot_mode', 'ROBOT_MODE_FORBIDDEN');
            }
        }

        $availability = null;
        if ($v->hasRaw('shift_availability')) {
            $availability = $this->validateAvailability($v);
        }
        $v->throwIfInvalid();

        $data = $v->clean();
        if (array_key_exists('group_id', $data)) {
            $data['id_group'] = $data['group_id'];
            unset($data['group_id']);
        }
        if ($type !== null && $type !== 'robot') {
            $data['robot_mode'] = null;
        }
        return [$data, $availability];
    }

    /** @return array<int,bool> id shift => disponible */
    private function validateAvailability(Validator $v): array
    {
        $raw = $v->raw('shift_availability');
        if (!is_array($raw) || !array_is_list($raw)) {
            $v->add('shift_availability', 'NOT_ARRAY');
            return [];
        }
        $result = [];
        foreach ($raw as $entry) {
            $shiftId = is_array($entry) ? ($entry['shift_template_id'] ?? null) : null;
            $available = is_array($entry) ? ($entry['available'] ?? null) : null;
            if (!is_int($shiftId) || !is_bool($available)) {
                $v->add('shift_availability', 'NOT_ARRAY');
                continue;
            }
            if (isset($result[$shiftId])) {
                $v->add('shift_availability', 'DUPLICATE_IN_LIST', ['id' => $shiftId]);
                continue;
            }
            if (!$this->shifts->exists($shiftId)) {
                $v->add('shift_availability', 'REFERENCE_NOT_FOUND', ['id' => $shiftId]);
                continue;
            }
            $result[$shiftId] = $available;
        }
        return $result;
    }

    private function load(int $id): array
    {
        return $this->workstations->find($id) ?? throw ApiException::notFound('workstation', $id);
    }

    private function present(array $row, array $availability, array $shifts, ApiContext $context): array
    {
        $lang = $context->translator->lang();
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'group_id' => (int) $row['id_group'],
            'name' => $row['name_' . $lang],
            'name_pl' => $row['name_pl'],
            'name_fr' => $row['name_fr'],
            'type' => $row['type'],
            'robot_mode' => $row['robot_mode'],
            'night_compatible' => $row['type'] === 'robot' && $row['robot_mode'] === 'autonome',
            'capacity_per_pass' => (float) $row['capacity_per_pass'],
            'capacity_unit' => $row['capacity_unit'],
            'hourly_cost' => (float) $row['hourly_cost'],
            'photo_url' => $row['photo_url'],
            'notes' => $row['notes'],
            'active' => (bool) $row['active'],
            'shift_availability' => array_map(fn($s) => [
                'shift_template_id' => (int) $s['id'],
                'code' => $s['code'],
                'available' => $availability[(int) $s['id']] ?? true,
            ], $shifts),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
