<?php
declare(strict_types=1);

namespace App\Services\Workshop\Referentiel;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\EmployeeRepository;
use App\Repositories\Workshop\GroupRepository;

/**
 * Employés de l'atelier vus par le module : la fiche ERP (production_employee) + le profil de
 * production (groupe principal, lien vers le panel TFB, niveau, superviseur de nuit).
 * Le panel est maître du niveau : level_order / level_name en sont une copie, saisie ici tant
 * que la synchronisation avec le panel n'existe pas.
 */
final class EmployeeService
{
    private const FIELDS = ['panel_employee_id', 'primary_group_id', 'level_order', 'level_name', 'night_supervisor', 'active'];

    public function __construct(
        private EmployeeRepository $employees,
        private GroupRepository $groups,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(?int $groupId, ?bool $active): array
    {
        return array_map(fn($row) => $this->present($row), $this->employees->list($groupId, $active));
    }

    public function get(int $productionEmployeeId): array
    {
        return $this->present($this->load($productionEmployeeId));
    }

    public function update(int $productionEmployeeId, array $input, ApiContext $context): array
    {
        $before = $this->load($productionEmployeeId);
        $v = (new Validator($input, true))->only(self::FIELDS)
            ->integer('panel_employee_id', false, 1, null, true)
            ->integer('primary_group_id', false, 1, null, true)
            ->integer('level_order', false, 0, 100, true)
            ->string('level_name', 100, false, true)
            ->boolean('night_supervisor')
            ->boolean('active');
        $panelId = $v->value('panel_employee_id');
        if ($panelId !== null && $this->employees->panelIdTaken($panelId, $productionEmployeeId)) {
            $v->add('panel_employee_id', 'DUPLICATE_IN_LIST', ['id' => $panelId]);
        }
        $groupId = $v->value('primary_group_id');
        if ($groupId !== null && !$this->groups->exists($groupId)) {
            $v->add('primary_group_id', 'REFERENCE_NOT_FOUND', ['id' => $groupId]);
        }
        $v->throwIfInvalid();

        $data = $v->clean();
        if (array_key_exists('primary_group_id', $data)) {
            $data['id_primary_group'] = $data['primary_group_id'];
            unset($data['primary_group_id']);
        }
        if (array_key_exists('level_order', $data) || array_key_exists('level_name', $data)) {
            $data['level_synced_at'] = date('Y-m-d H:i:s');
        }

        $this->tx->execute(function () use ($productionEmployeeId, $data, $before, $context) {
            $profileId = $this->employees->ensureProfile($productionEmployeeId);
            $this->employees->update($profileId, $data);
            $this->audit->record($context, 'update', 'employee', $productionEmployeeId, $before, $data);
        });
        return $this->get($productionEmployeeId);
    }

    private function load(int $productionEmployeeId): array
    {
        return $this->employees->byProductionEmployee($productionEmployeeId) ?? throw ApiException::notFound('employee', $productionEmployeeId);
    }

    private function present(array $row): array
    {
        $int = fn($v) => $v === null ? null : (int) $v;
        return [
            'production_employee_id' => (int) $row['production_employee_id'],
            'name' => $row['name'],
            'surname' => $row['surname'],
            'section_id' => $int($row['section_id']),
            'has_profile' => $row['profile_id'] !== null,
            'panel_employee_id' => $int($row['panel_employee_id']),
            'primary_group_id' => $int($row['id_primary_group']),
            'level_order' => $int($row['level_order']),
            'level_name' => $row['level_name'],
            'level_synced_at' => $row['level_synced_at'],
            'night_supervisor' => (bool) $row['night_supervisor'],
            'active' => $row['active'] === null ? true : (bool) $row['active'],
        ];
    }
}
