<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\GroupRepository;
use App\Repositories\Workshop\OutputRepository;
use App\Repositories\Workshop\QualityRepository;
use App\Services\Purchasing\NcService;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Tablet grupy, zakładka « Kontrola jakości » : każda partia dnia sprawdzona jednym dotknięciem — OK albo
 * niezgodność. Niezgodność (z partii albo zgłoszona osobno) trafia do rejestru niezgodności ERP (n_nc,
 * źródło « production ») z numerem, produktem, ilością i osobą, która ją zgłosiła na tablecie.
 */
final class QualityService
{
    public const RESULTS = ['ok', 'nc'];
    private const REPORT_DAYS = 14;

    public function __construct(
        private QualityRepository $repo,
        private OutputRepository $outputs,
        private ExecutionRepository $execution,
        private GroupRepository $groups,
        private NcService $nc,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    /** Partie dnia grupy tabletu ze stanem kontroli, niezgodności grupy z ostatnich dni, karty grupy do zgłoszenia. */
    public function day(ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $day = date('Y-m-d');
        $group = $this->groups->find($groupId);
        return [
            'date' => $day,
            'group' => ['id' => $groupId, 'name' => $group['name_' . $context->translator->lang()] ?? $group['name_pl'] ?? ''],
            'batches' => array_map(fn($b) => [
                'id' => (int) $b['id'], 'item_id' => (int) $b['id_item'], 'name' => $b['name'], 'unit' => $b['base_unit'],
                'ppc' => (int) $b['ppc'] > 0 ? (int) $b['ppc'] : null, 'quantity' => (float) $b['quantity'], 'lot_code' => $b['lot_code'],
                'created_at' => $b['created_at'], 'employee' => $b['employee_name'] ?: null,
                'qc' => $b['qc_status'] === null ? null : ['status' => $b['qc_status'], 'at' => $b['qc_at'], 'employee' => $b['qc_employee_name'] ?: null,
                    'note' => $b['qc_note'], 'nc_number' => $b['nc_number']],
            ], $this->repo->batches($day, $groupId)),
            'reports' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'number' => $r['number'], 'reported_at' => $r['reported_at'], 'product' => $r['product'],
                'description' => $r['description'], 'severity' => $r['severity'], 'status' => $r['status'],
            ], $this->repo->reports($groupId, self::REPORT_DAYS)),
            'catalog' => array_map(fn($i) => ['item_id' => (int) $i['id'], 'name' => $i['name']], $this->outputs->groupItems($groupId)),
        ];
    }

    /** Kontrola partii : OK albo niezgodność (opis, waga) — niezgodność dostaje numer w rejestrze ERP. */
    public function check(int $outputId, array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $v = (new Validator($body))->only(['result', 'note', 'description', 'severity'])
            ->choice('result', self::RESULTS, true)->string('note', 255, false, true)
            ->string('description', 2000, ($body['result'] ?? null) === 'nc', true)->choice('severity', NcService::SEVERITIES, false);
        $v->throwIfInvalid();
        $o = $this->outputs->output($outputId) ?? throw ApiException::notFound('output', $outputId);
        $groups = $context->employeeId ? $this->execution->employeeGroups($context->employeeId) : [];
        if ((int) $o['id_group'] !== $groupId && !in_array((int) $o['id_group'], $groups, true)) throw new ApiException('OUTPUT_NOT_IN_YOUR_GROUPS', 403);
        if ($o['cancelled_at'] !== null) throw new ApiException('OUTPUT_CANCELLED', 409);
        if ($o['qc_status'] === 'nc') throw new ApiException('QC_ALREADY_NC', 409);
        $result = (string) $v->value('result');
        $note = $v->value('note') !== null ? trim((string) $v->value('note')) : null;
        $item = $this->outputs->item((int) $o['id_item']);
        $this->tx->execute(function () use ($o, $item, $result, $note, $v, $context, $groupId, $outputId) {
            $ncId = null;
            if ($result === 'nc') {
                $ncId = $this->createNc($context, $groupId, $item, (float) $o['quantity'],
                    $o['lot_code'] . ' · ' . trim((string) $v->value('description')), (string) ($v->value('severity') ?? 'minor'), $note);
            }
            $this->repo->setQc($outputId, $result, $context->employeeId, $note, $ncId);
            $this->audit->record($context, 'qc_' . $result, 'output', $outputId, $o['qc_status'] ? ['qc' => $o['qc_status']] : null, ['qc' => $result, 'nc' => $ncId]);
        });
        return $this->day($context);
    }

    /** Niezgodność zgłoszona z tabletu bez partii (produkt grupy albo ogólna). */
    public function report(array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $v = (new Validator($body))->only(['item_id', 'description', 'severity', 'quantity'])
            ->integer('item_id', false, 1, null, true)->string('description', 2000, true)
            ->choice('severity', NcService::SEVERITIES, false)->decimal('quantity', false, 0, 10_000_000, true);
        $v->throwIfInvalid();
        $item = null;
        if ($v->value('item_id') !== null) {
            $item = $this->outputs->item((int) $v->value('item_id'));
            if ($item === null) throw ApiException::validation([['field' => 'item_id', 'code' => 'NOT_FOUND_REF', 'params' => []]]);
        }
        $this->tx->execute(function () use ($v, $item, $context, $groupId) {
            $id = $this->createNc($context, $groupId, $item, $v->value('quantity') !== null ? (float) $v->value('quantity') : null,
                trim((string) $v->value('description')), (string) ($v->value('severity') ?? 'minor'), null);
            $this->audit->record($context, 'report', 'nc', $id, null, ['group' => $groupId, 'item' => $item['id'] ?? null]);
        });
        return $this->day($context);
    }

    private function createNc(ApiContext $context, int $groupId, ?array $item, ?float $quantity, string $description, string $severity, ?string $action): int
    {
        $who = $context->employeeId ? ($this->execution->employee($context->employeeId)['name'] ?? null) : null;
        $group = $this->groups->find($groupId);
        $text = ($item && !$item['id_final_product'] ? $item['name'] . ' · ' : '') . $description
            . ' — ' . trim(($who ? $who . ', ' : '') . 'tablet ' . ($group['name_pl'] ?? ''));
        $id = $this->nc->save(array_filter([
            'source' => 'production', 'product_id' => $item && $item['id_final_product'] ? (int) $item['id_final_product'] : null,
            'description' => $text, 'severity' => $severity, 'quantity' => $quantity, 'unit' => $quantity !== null && $item ? $item['base_unit'] : null,
            'immediate_action' => $action ?: null,
        ], fn($x) => $x !== null), null, null);
        $this->repo->setNcGroup($id, $groupId);
        return $id;
    }

    private function groupId(ApiContext $context): int
    {
        if ($context->deviceGroupId === null) throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
        return $context->deviceGroupId;
    }
}
