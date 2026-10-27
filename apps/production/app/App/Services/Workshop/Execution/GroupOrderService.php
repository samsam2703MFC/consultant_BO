<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\GroupOrderRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Zamówienia między grupami (makiety 2–4) : grupa zamawia na tablecie kompozycję, którą robi inna grupa (ilość, dzień,
 * godzina) ; grupa robiąca widzi zamówienie na liście do produkcji — przyjmij, produkuj (partia półproduktu powiązana
 * z zamówieniem), gotowe ; partia produktu grupy zamawiającej bierze numer partii kompozycji (zamówienie odebrane).
 * Stany : ordered → accepted → in_production → ready → picked ; cancelled (zamawiająca albo robiąca, przed produkcją).
 */
final class GroupOrderService
{
    public const ACTIONS = ['accept', 'ready', 'cancel'];

    public function __construct(
        private GroupOrderRepository $repo,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    /** Zakładka « Zamówienia grup » tabletu : co grupa może zamówić, jej zamówienia i zamówienia innych grup dla niej. */
    public function kiosk(ApiContext $context, ?int $now = null): array
    {
        $groupId = $this->groupId($context);
        $fr = $context->translator->lang() === 'fr';
        $now ??= time();
        $since = date('Y-m-d', $now - 2 * 86400);
        return [
            'group_id' => $groupId,
            'days' => $this->days($now),
            'hours' => $this->hours(),
            'orderables' => array_map(fn($o) => $this->orderableRow($o, $fr, $now), $this->repo->orderables($groupId)),
            'mine' => array_map(fn($r) => $this->row($r, $fr), $this->repo->from($groupId, $since)),
            'incoming' => array_map(fn($r) => $this->row($r, $fr), $this->repo->to($groupId, $since)),
        ];
    }

    public function create(array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $v = (new Validator($body))->only(['recipe_id', 'quantity', 'date', 'time', 'note', 'client_uuid'])
            ->integer('recipe_id', true, 1)->decimal('quantity', true, 0, 100000, false, true)->date('date', true)
            ->string('time', 5, true, false, '/^([01]\d|2[0-3]):[0-5]\d$/', 'INVALID_TIME')->string('note', 255, false, true)
            ->string('client_uuid', 36, false, true, '/^[0-9a-fA-F-]{36}$/', 'INVALID_UUID');
        $v->throwIfInvalid();
        $c = $v->clean();
        if (!empty($c['client_uuid']) && $this->repo->byUuid($c['client_uuid']) !== null) return $this->kiosk($context) + ['replayed' => true];
        $orderable = array_values(array_filter($this->repo->orderables($groupId), fn($o) => (int) $o['recipe_id'] === (int) $c['recipe_id']))[0] ?? null;
        if ($orderable === null) throw ApiException::validation([['field' => 'recipe_id', 'code' => 'NOT_ORDERABLE']]);
        $today = date('Y-m-d');
        $last = date('Y-m-d', strtotime($today . ' +' . $this->daysAhead() . ' days'));
        if ($c['date'] < $today || $c['date'] > $last) throw ApiException::validation([['field' => 'date', 'code' => 'DUE_OUT_OF_RANGE', 'params' => ['to' => $last]]]);
        $due = $c['date'] . ' ' . $c['time'] . ':00';
        if (strtotime($due) < time() - 1800) throw ApiException::validation([['field' => 'time', 'code' => 'DUE_IN_PAST']]);
        $id = $this->tx->execute(function () use ($c, $orderable, $groupId, $due, $context) {
            $id = $this->repo->insert([
                'id_recipe' => (int) $c['recipe_id'], 'id_item' => $orderable['item_id'] !== null ? (int) $orderable['item_id'] : null,
                'id_group_from' => $groupId, 'id_group_to' => (int) $orderable['producer_id'], 'quantity' => round((float) $c['quantity'], 3),
                'unit' => self::unit($orderable), 'due_at' => $due, 'status' => 'ordered',
                'note' => isset($c['note']) && trim((string) $c['note']) !== '' ? trim((string) $c['note']) : null,
                'ordered_by' => $context->employeeId, 'id_device' => $context->deviceId, 'client_uuid' => $c['client_uuid'] ?? null,
            ]);
            $this->audit->record($context, 'create', 'group_order', $id, null, ['recipe_id' => (int) $c['recipe_id'], 'to' => (int) $orderable['producer_id'], 'quantity' => (float) $c['quantity'], 'due_at' => $due]);
            return $id;
        });
        return $this->kiosk($context) + ['created_id' => $id];
    }

    /** Przyjmij (grupa robiąca), gotowe z partią (grupa robiąca), anuluj (przed produkcją : jedna albo druga grupa). */
    public function act(int $id, array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $v = (new Validator($body))->only(['action', 'output_id', 'note'])->choice('action', self::ACTIONS, true)
            ->integer('output_id', false, 1, null, true)->string('note', 255, false, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        $o = $this->repo->find($id) ?? throw ApiException::notFound('group_order', $id);
        $to = (int) $o['id_group_to'] === $groupId;
        $from = (int) $o['id_group_from'] === $groupId;
        if (!$to && !$from) throw new ApiException('ORDER_NOT_YOURS', 403);
        $now = date('Y-m-d H:i:s');
        $data = match ($c['action']) {
            'accept' => $to && $o['status'] === 'ordered' ? ['status' => 'accepted', 'accepted_by' => $context->employeeId, 'accepted_at' => $now] : null,
            'ready' => $to && in_array($o['status'], ['ordered', 'accepted', 'in_production'], true)
                ? ['status' => 'ready', 'ready_at' => $now, 'id_output' => $this->readyBatch($o, $c['output_id'] ?? null, $groupId)]
                    + ($o['accepted_at'] === null ? ['accepted_by' => $context->employeeId, 'accepted_at' => $now] : []) : null,
            'cancel' => in_array($o['status'], ['ordered', 'accepted'], true) ? ['status' => 'cancelled', 'cancelled_by' => $context->employeeId, 'cancelled_at' => $now]
                + (isset($c['note']) && trim((string) $c['note']) !== '' ? ['note' => trim((string) $c['note'])] : []) : null,
        };
        if ($data === null) throw new ApiException('ORDER_STATUS', 409, [], ['status' => $o['status']]);
        $this->tx->execute(function () use ($id, $data, $o, $context, $c) {
            $this->repo->update($id, $data);
            $this->audit->record($context, $c['action'], 'group_order', $id, ['status' => $o['status']], $data);
        });
        return $this->kiosk($context);
    }

    /**
     * « Produkuj » : partia półproduktu grupy robiącej zapisana dla zamówienia (w transakcji wywołującego).
     * Zamówienie innej grupy albo na inną kompozycję → błąd.
     */
    public function linkBatch(int $orderId, int $outputId, int $itemId, int $groupId, ?int $employeeId): void
    {
        $o = $this->repo->find($orderId) ?? throw ApiException::validation([['field' => 'group_order_id', 'code' => 'NOT_FOUND_REF']]);
        if ((int) $o['id_group_to'] !== $groupId || !in_array($o['status'], ['ordered', 'accepted', 'in_production'], true)
            || ($o['id_item'] !== null && (int) $o['id_item'] !== $itemId)) {
            throw ApiException::validation([['field' => 'group_order_id', 'code' => 'ORDER_MISMATCH']]);
        }
        $now = date('Y-m-d H:i:s');
        $this->repo->update($orderId, ['status' => 'in_production', 'id_output' => $outputId]
            + ($o['accepted_at'] === null ? ['accepted_by' => $employeeId, 'accepted_at' => $now] : []));
    }

    /** Partia produktu zużyła partię kompozycji : zamówienia grupy z tą partią — odebrane (kompozycja zużyta w całości). */
    public function markPicked(int $sourceOutputId, int $consumerOutputId, int $consumerGroupId, ?float $quantity): void
    {
        foreach ($this->repo->byOutput($sourceOutputId, $consumerGroupId) as $o) {
            $this->repo->update((int) $o['id'], ['status' => 'picked', 'picked_at' => date('Y-m-d H:i:s'), 'id_picked_output' => $consumerOutputId, 'picked_quantity' => $quantity]);
        }
    }

    /** ERP : zamówienia między grupami w okresie (grupa i kompozycje receptury opcjonalnie). */
    public function between(string $from, string $to, ?int $groupId, string $lang = 'pl', array $recipeIds = []): array
    {
        return array_map(fn($r) => $this->row($r, $lang === 'fr'), $this->repo->between($from, $to, $groupId, $recipeIds));
    }

    /** Otwarte zamówienia grupy na kompozycję (podpowiedź w partii produktu). */
    public function openFor(int $groupId, int $recipeId, bool $fr): array
    {
        return array_map(fn($r) => $this->row($r, $fr), $this->repo->openFor($groupId, $recipeId));
    }

    public function row(array $r, bool $fr): array
    {
        return [
            'id' => (int) $r['id'], 'recipe' => ['id' => (int) $r['id_recipe'], 'name' => (string) $r['recipe_name']],
            'item_id' => $r['id_item'] !== null ? (int) $r['id_item'] : null,
            'from' => ['id' => (int) $r['id_group_from'], 'name' => $fr && $r['from_fr'] ? $r['from_fr'] : $r['from_pl'], 'color' => $r['from_color']],
            'to' => ['id' => (int) $r['id_group_to'], 'name' => $fr && $r['to_fr'] ? $r['to_fr'] : $r['to_pl'], 'color' => $r['to_color']],
            'quantity' => (float) $r['quantity'], 'unit' => (string) $r['unit'], 'due_at' => (string) $r['due_at'], 'status' => (string) $r['status'],
            'note' => $r['note'], 'ordered_by' => $r['ordered_by_name'] ?: null, 'created_at' => (string) $r['created_at'],
            'accepted_at' => $r['accepted_at'], 'ready_at' => $r['ready_at'], 'picked_at' => $r['picked_at'],
            'batch' => $r['id_output'] !== null ? ['id' => (int) $r['id_output'], 'lot_code' => $r['lot_code'], 'quantity' => $r['output_quantity'] !== null ? (float) $r['output_quantity'] : null,
                'created_at' => $r['output_at']] : null,
            'picked_quantity' => $r['picked_quantity'] !== null ? (float) $r['picked_quantity'] : null, 'picked_lot' => $r['picked_lot'],
        ];
    }

    private function orderableRow(array $o, bool $fr, int $now): array
    {
        $lead = $o['lead_days'] !== null ? (int) $o['lead_days'] : null;
        return [
            'recipe_id' => (int) $o['recipe_id'], 'name' => (string) $o['name'], 'unit' => self::unit($o),
            'item_id' => $o['item_id'] !== null ? (int) $o['item_id'] : null, 'lead_days' => $lead,
            // Najwcześniejszy dzień zgodny z wyprzedzeniem (wcześniej = « krótki termin », grupa robiąca może przyjąć).
            'earliest' => date('Y-m-d', $now + 86400 * ($lead ?? 0)),
            'producer' => ['id' => (int) $o['producer_id'], 'name' => $fr && $o['producer_fr'] ? $o['producer_fr'] : $o['producer_pl'], 'color' => $o['producer_color']],
        ];
    }

    /** Jednostka zamówienia : kg dla receptur w g / kg / ml / l, inaczej jednostka karty (szt). */
    private static function unit(array $o): string
    {
        if (in_array($o['recipe_unit'] ?? '', ['g', 'kg', 'ml', 'l'], true)) return 'kg';
        return (string) ($o['base_unit'] ?? 'szt') ?: 'szt';
    }

    private function readyBatch(array $o, ?int $outputId, int $groupId): int
    {
        $id = $outputId ?? ($o['id_output'] !== null ? (int) $o['id_output'] : null);
        if ($id === null) throw new ApiException('ORDER_WITHOUT_BATCH', 409);
        $b = $this->repo->output($id);
        if ($b === null || (int) $b['id_group'] !== $groupId || $b['cancelled_at'] !== null || ($o['id_item'] !== null && (int) $b['id_item'] !== (int) $o['id_item'])) {
            throw ApiException::validation([['field' => 'output_id', 'code' => 'ORDER_BATCH_MISMATCH']]);
        }
        return $id;
    }

    /** @return list<string> dni do wyboru : dziś + group_orders.days_ahead */
    private function days(int $now): array
    {
        $out = [];
        for ($i = 0; $i <= $this->daysAhead(); $i++) $out[] = date('Y-m-d', strtotime(date('Y-m-d', $now) . " +{$i} days"));
        return $out;
    }

    private function daysAhead(): int
    {
        return max(1, min(30, (int) $this->params->get('group_orders.days_ahead', '5')));
    }

    /** @return list<string> */
    private function hours(): array
    {
        $hours = array_values(array_filter(array_map('trim', explode(',', $this->params->get('group_orders.hours', '06:00,08:00,10:00,12:00,14:00'))),
            fn($h) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)));
        return $hours ?: ['06:00', '08:00', '12:00', '14:00'];
    }

    private function groupId(ApiContext $context): int
    {
        if ($context->deviceGroupId === null) throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
        return $context->deviceGroupId;
    }
}
