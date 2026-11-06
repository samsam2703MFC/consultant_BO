<?php
declare(strict_types=1);

namespace App\Services\Workshop\Referentiel;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\GroupRepository;

/** Groupes de production (lignes du Gantt), reliés si besoin aux sections de coûts de l'ERP. */
final class GroupService
{
    private const FIELDS = ['code', 'name_pl', 'name_fr', 'color', 'sort_order', 'section_id', 'active'];

    public function __construct(
        private GroupRepository $groups,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(?bool $active, ApiContext $context): array
    {
        return array_map(fn($row) => $this->present($row, $context), $this->groups->list($active));
    }

    public function get(int $id, ApiContext $context): array
    {
        return $this->present($this->load($id), $context);
    }

    public function create(array $input, ApiContext $context): array
    {
        $data = $this->validate($input, null);
        $id = $this->tx->execute(function () use ($data, $context) {
            $id = $this->groups->insert($data);
            $this->audit->record($context, 'create', 'group', $id, null, $data);
            return $id;
        });
        return $this->get($id, $context);
    }

    public function update(int $id, array $input, ApiContext $context): array
    {
        $before = $this->load($id);
        $data = $this->validate($input, $before);
        $this->tx->execute(function () use ($id, $data, $before, $context) {
            $this->groups->update($id, $data);
            $this->audit->record($context, 'update', 'group', $id, $before, $data);
        });
        return $this->get($id, $context);
    }

    private function validate(array $input, ?array $current): array
    {
        $v = (new Validator($input, $current !== null))->only(self::FIELDS)
            ->code('code')
            ->string('name_pl', 100)
            ->string('name_fr', 100)
            ->color('color', false)
            ->integer('sort_order', false, 0, 100000)
            ->integer('section_id', false, 1, null, true)
            ->boolean('active');
        if ($v->provided('code') && $this->groups->codeTaken($v->value('code'), $current ? (int) $current['id'] : null)) {
            throw new ApiException('DUPLICATE_CODE', 409, [], ['code' => $v->value('code')]);
        }
        if ($v->value('section_id') !== null && !$v->hasError('section_id') && !$this->groups->sectionExists($v->value('section_id'))) {
            $v->add('section_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('section_id')]);
        }
        $v->throwIfInvalid();

        $data = $v->clean();
        if (array_key_exists('section_id', $data)) {
            $data['id_section'] = $data['section_id'];
            unset($data['section_id']);
        }
        return $data;
    }

    private function load(int $id): array
    {
        return $this->groups->find($id) ?? throw ApiException::notFound('group', $id);
    }

    private function present(array $row, ApiContext $context): array
    {
        $lang = $context->translator->lang();
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name_' . $lang],
            'name_pl' => $row['name_pl'],
            'name_fr' => $row['name_fr'],
            'color' => $row['color'],
            'sort_order' => (int) $row['sort_order'],
            'section_id' => $row['id_section'] === null ? null : (int) $row['id_section'],
            'active' => (bool) $row['active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
