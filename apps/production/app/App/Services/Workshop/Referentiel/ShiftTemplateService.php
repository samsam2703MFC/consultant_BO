<?php
declare(strict_types=1);

namespace App\Services\Workshop\Referentiel;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ShiftTemplateRepository;

/** Modèles de shift (Jour 06–14, Après-midi 14–22, Nuit 22–06 +20 %…). Un shift peut passer minuit. */
final class ShiftTemplateService
{
    private const FIELDS = ['code', 'name_pl', 'name_fr', 'start_time', 'end_time', 'active_days', 'premium_pct', 'is_night', 'sort_order', 'active'];

    public function __construct(
        private ShiftTemplateRepository $shifts,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function list(?bool $active, ApiContext $context): array
    {
        return array_map(fn($row) => $this->present($row, $context), $this->shifts->list($active));
    }

    public function get(int $id, ApiContext $context): array
    {
        return $this->present($this->load($id), $context);
    }

    public function create(array $input, ApiContext $context): array
    {
        $data = $this->validate($input, null);
        $id = $this->tx->execute(function () use ($data, $context) {
            $id = $this->shifts->insert($data);
            $this->audit->record($context, 'create', 'shift_template', $id, null, $data);
            return $id;
        });
        return $this->get($id, $context);
    }

    public function update(int $id, array $input, ApiContext $context): array
    {
        $before = $this->load($id);
        $data = $this->validate($input, $before);
        $this->tx->execute(function () use ($id, $data, $before, $context) {
            $this->shifts->update($id, $data);
            $this->audit->record($context, 'update', 'shift_template', $id, $before, $data);
        });
        return $this->get($id, $context);
    }

    /** Durée en minutes d'un shift, minuit compris (22:00 → 06:00 = 480). */
    public static function durationMinutes(string $start, string $end): int
    {
        $s = (int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2);
        $e = (int) substr($end, 0, 2) * 60 + (int) substr($end, 3, 2);
        return $e > $s ? $e - $s : 1440 - $s + $e;
    }

    private function validate(array $input, ?array $current): array
    {
        $v = (new Validator($input, $current !== null))->only(self::FIELDS)
            ->code('code')
            ->string('name_pl', 100)
            ->string('name_fr', 100)
            ->time('start_time')
            ->time('end_time')
            ->days('active_days', false)
            ->decimal('premium_pct', false, 0, 200)
            ->boolean('is_night')
            ->integer('sort_order', false, 0, 100000)
            ->boolean('active');
        if ($v->provided('code') && $this->shifts->codeTaken($v->value('code'), $current ? (int) $current['id'] : null)) {
            throw new ApiException('DUPLICATE_CODE', 409, [], ['code' => $v->value('code')]);
        }
        $start = $v->value('start_time', $current['start_time'] ?? null);
        $end = $v->value('end_time', $current['end_time'] ?? null);
        if ($start !== null && $end !== null && $start === $end && !$v->hasError('start_time') && !$v->hasError('end_time')) {
            $v->add('end_time', 'SAME_START_END');
        }
        $v->throwIfInvalid();
        return $v->clean();
    }

    private function load(int $id): array
    {
        return $this->shifts->find($id) ?? throw ApiException::notFound('shift_template', $id);
    }

    private function present(array $row, ApiContext $context): array
    {
        $lang = $context->translator->lang();
        $start = substr($row['start_time'], 0, 5);
        $end = substr($row['end_time'], 0, 5);
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'name' => $row['name_' . $lang],
            'name_pl' => $row['name_pl'],
            'name_fr' => $row['name_fr'],
            'start_time' => $start,
            'end_time' => $end,
            'crosses_midnight' => $end <= $start,
            'duration_minutes' => self::durationMinutes($start, $end),
            'active_days' => array_map('intval', explode(',', $row['active_days'])),
            'premium_pct' => (float) $row['premium_pct'],
            'is_night' => (bool) $row['is_night'],
            'sort_order' => (int) $row['sort_order'],
            'active' => (bool) $row['active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
