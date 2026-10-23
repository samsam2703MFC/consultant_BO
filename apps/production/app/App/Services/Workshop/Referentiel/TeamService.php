<?php
declare(strict_types=1);

namespace App\Services\Workshop\Referentiel;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\EmployeeRepository;
use App\Repositories\Workshop\GroupRepository;
use App\Repositories\Workshop\ShiftTemplateRepository;
use App\Repositories\Workshop\TeamRepository;

/** Équipes (groupe + shift + effectif prévu) et leurs membres. */
final class TeamService
{
    private const FIELDS = ['code', 'group_id', 'shift_template_id', 'name', 'planned_headcount', 'active'];

    public function __construct(
        private TeamRepository $teams,
        private GroupRepository $groups,
        private ShiftTemplateRepository $shifts,
        private EmployeeRepository $employees,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(?int $groupId, ?int $shiftId, ?bool $active): array
    {
        return array_map(fn($row) => $this->present($row), $this->teams->list($groupId, $shiftId, $active));
    }

    public function get(int $id): array
    {
        $row = $this->load($id);
        $row['member_count'] = $this->teams->memberCount($id);
        return $this->present($row);
    }

    public function create(array $input, ApiContext $context): array
    {
        $data = $this->validate($input, null);
        $id = $this->tx->execute(function () use ($data, $context) {
            $id = $this->teams->insert($data);
            $this->audit->record($context, 'create', 'team', $id, null, $data);
            return $id;
        });
        return $this->get($id);
    }

    public function update(int $id, array $input, ApiContext $context): array
    {
        $before = $this->load($id);
        $data = $this->validate($input, $before);
        $this->tx->execute(function () use ($id, $data, $before, $context) {
            $this->teams->update($id, $data);
            $this->audit->record($context, 'update', 'team', $id, $before, $data);
        });
        return $this->get($id);
    }

    public function members(int $id): array
    {
        $this->load($id);
        return array_map(fn($m) => [
            'production_employee_id' => (int) $m['id_production_employee'],
            'name' => $m['name'],
            'surname' => $m['surname'],
            'is_lead' => (bool) $m['is_lead'],
            'valid_from' => $m['valid_from'],
            'valid_to' => $m['valid_to'],
            'level_order' => $m['level_order'] === null ? null : (int) $m['level_order'],
            'level_name' => $m['level_name'],
            'active' => (bool) $m['active'],
        ], $this->teams->members($id));
    }

    /** Remplace la liste des membres. Un employé sans profil de production en reçoit un. */
    public function replaceMembers(int $id, array $input, ApiContext $context): array
    {
        $team = $this->load($id);
        $v = (new Validator($input))->only(['members']);
        $raw = $input['members'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            $v->add('members', $raw === null ? 'REQUIRED' : 'NOT_ARRAY');
            $v->throwIfInvalid();
        }

        $members = [];
        $seen = [];
        foreach ($raw as $i => $entry) {
            $field = "members[{$i}]";
            $m = new Validator(is_array($entry) ? $entry : []);
            $m->only(['production_employee_id', 'is_lead', 'valid_from', 'valid_to'])
                ->integer('production_employee_id', true, 1)
                ->boolean('is_lead')
                ->date('valid_from')
                ->date('valid_to');
            $employeeId = $m->value('production_employee_id');
            if ($employeeId !== null && isset($seen[$employeeId])) {
                $m->add('production_employee_id', 'DUPLICATE_IN_LIST', ['id' => $employeeId]);
            } elseif ($employeeId !== null && $this->employees->byProductionEmployee($employeeId) === null) {
                $m->add('production_employee_id', 'REFERENCE_NOT_FOUND', ['id' => $employeeId]);
            }
            $from = $m->value('valid_from');
            $to = $m->value('valid_to');
            if ($from !== null && $to !== null && $to < $from) {
                $m->add('valid_to', 'DATE_ORDER');
            }
            foreach ($m->errors() as $error) {
                $v->add($field . '.' . $error['field'], $error['code'], $error['params']);
            }
            if ($employeeId !== null) $seen[$employeeId] = true;
            $members[] = ['production_employee_id' => $employeeId, 'is_lead' => (bool) $m->value('is_lead', false), 'valid_from' => $from, 'valid_to' => $to];
        }
        $v->throwIfInvalid();

        $before = $this->members($id);
        $this->tx->execute(function () use ($id, $team, $members, $before, $context) {
            $rows = [];
            foreach ($members as $member) {
                $rows[] = [
                    'id_employee' => $this->employees->ensureProfile($member['production_employee_id'], (int) $team['id_group']),
                    'is_lead' => $member['is_lead'],
                    'valid_from' => $member['valid_from'],
                    'valid_to' => $member['valid_to'],
                ];
            }
            $this->teams->replaceMembers($id, $rows);
            $this->audit->record($context, 'replace_members', 'team', $id, ['members' => $before], ['members' => $members]);
        });
        return $this->members($id);
    }

    private function validate(array $input, ?array $current): array
    {
        $v = (new Validator($input, $current !== null))->only(self::FIELDS)
            ->code('code')
            ->integer('group_id', true, 1)
            ->integer('shift_template_id', true, 1)
            ->string('name', 100)
            ->integer('planned_headcount', false, 0, 500)
            ->boolean('active');
        if ($v->provided('code') && $this->teams->codeTaken($v->value('code'), $current ? (int) $current['id'] : null)) {
            throw new ApiException('DUPLICATE_CODE', 409, [], ['code' => $v->value('code')]);
        }
        if ($v->provided('group_id') && !$this->groups->exists($v->value('group_id'))) {
            $v->add('group_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('group_id')]);
        }
        if ($v->provided('shift_template_id') && !$this->shifts->exists($v->value('shift_template_id'))) {
            $v->add('shift_template_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('shift_template_id')]);
        }
        $v->throwIfInvalid();

        $data = $v->clean();
        foreach (['group_id' => 'id_group', 'shift_template_id' => 'id_shift_template'] as $api => $column) {
            if (array_key_exists($api, $data)) {
                $data[$column] = $data[$api];
                unset($data[$api]);
            }
        }
        return $data;
    }

    private function load(int $id): array
    {
        return $this->teams->find($id) ?? throw ApiException::notFound('team', $id);
    }

    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'group_id' => (int) $row['id_group'],
            'shift_template_id' => (int) $row['id_shift_template'],
            'name' => $row['name'],
            'planned_headcount' => (int) $row['planned_headcount'],
            'member_count' => (int) ($row['member_count'] ?? 0),
            'active' => (bool) $row['active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
