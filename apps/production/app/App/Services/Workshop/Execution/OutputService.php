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
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Tablet grupy : produkcja dnia i identyfikowalność.
 *
 * Osoba zidentyfikowana na tablecie grupy wpisuje partie wyrobu (produkt + ilość w kartonach / sztukach) ;
 * każda partia dostaje numer RRMMDD-GRUPA-NN. Do partii : surowce i opakowania z receptury (ilość
 * przeliczona na partię) i ich numery partii z datą przydatności — wpisane, odczytane OCR ze zdjęcia
 * etykiety na tablecie albo « jak ostatnio » (ostatnia partia surowca w grupie). Poprawki i anulowanie
 * tylko przez kiosk.output_edit_days dni ; client_uuid : zapis rejestrowany raz (tablet bez sieci).
 */
final class OutputService
{
    private const PHOTO_TYPES = ['image/jpeg' => "\xFF\xD8\xFF", 'image/png' => "\x89PNG", 'image/webp' => 'RIFF'];
    /** Powody strat ; « inne » wymaga notatki. */
    public const WASTE_REASONS = ['quality', 'burnt', 'damaged', 'samples', 'other'];

    public function __construct(
        private OutputRepository $repo,
        private ExecutionRepository $execution,
        private GroupRepository $groups,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Produkcja dnia grupy tabletu : plan z Gantta tygodnia, partie wpisane, straty (dobre = zrobione − straty),
     * zatwierdzenie dnia przez zespół, karty grupy do dodania.
     */
    public function day(ApiContext $context, ?string $date = null): array
    {
        $groupId = $this->groupId($context);
        $day = $this->day0($date);
        $catalog = [];
        foreach ($this->repo->groupItems($groupId) as $i) $catalog[(int) $i['id']] = $this->itemRow($i);
        $batches = array_map(fn($o) => $this->batchRow($o), $this->repo->day($day, $groupId));
        $wastes = array_map(fn($w) => $this->wasteRow($w), $this->repo->dayWaste($day, $groupId));
        foreach (array_merge($batches, $wastes) as $b) {
            if (!isset($catalog[$b['item_id']]) && ($i = $this->repo->item($b['item_id']))) $catalog[$b['item_id']] = $this->itemRow($i);
        }
        $planned = $this->repo->planned($day, array_keys($catalog));
        $items = [];
        foreach ($catalog as $id => $c) {
            $mine = array_values(array_filter($batches, fn($b) => $b['item_id'] === $id));
            $lost = array_values(array_filter($wastes, fn($w) => $w['item_id'] === $id));
            if ($mine) {
                $item = $this->repo->item($id);
                $total = $item && $item['id_recipe'] ? count($this->repo->recipe((int) $item['id_recipe'])) : 0;
                $mine = array_map(fn($b) => $b + ['lots_total' => $total], $mine);
            }
            if (!isset($planned[$id]) && !$mine && !$lost) continue;
            $produced = array_sum(array_map(fn($b) => $b['cancelled'] ? 0 : $b['quantity'], $mine));
            $waste = array_sum(array_map(fn($w) => $w['cancelled'] ? 0 : $w['quantity'], $lost));
            $items[] = $c + [
                'planned' => isset($planned[$id]) ? round($planned[$id], 3) : null, 'produced' => round($produced, 3),
                'waste' => round($waste, 3), 'good' => round($produced - $waste, 3), 'batches' => $mine, 'wastes' => $lost,
            ];
        }
        usort($items, fn($a, $b) => [$a['planned'] === null, $a['name']] <=> [$b['planned'] === null, $b['name']]);
        $group = $this->groups->find($groupId);
        $close = $this->repo->dayClose($day, $groupId);
        return [
            'date' => $day,
            'editable' => $this->editable($day),
            'group' => ['id' => $groupId, 'name' => $context->translator->lang() === 'fr' ? ($group['name_fr'] ?? $group['name_pl']) : $group['name_pl']],
            'items' => $items,
            'totals' => $this->totals($items),
            'gaps' => $this->gaps($items),
            'closed' => $close === null ? null : [
                'at' => $close['closed_at'], 'employee' => $close['employee_name'] ?: null, 'note' => $close['note'],
                // Partie lub straty zmienione po zatwierdzeniu : zespół zatwierdza dzień jeszcze raz.
                'changed' => json_decode((string) $close['summary'], true) != $this->summary($items),
            ],
            'waste_reasons' => self::WASTE_REASONS,
            'catalog' => array_values($catalog),
        ];
    }

    /** Strata : produkt, ilość, powód (opcjonalnie partia wyrobu) ; nie więcej niż dobra ilość dnia. */
    public function recordWaste(array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $v = (new Validator($body))->only(['item_id', 'quantity', 'reason', 'note', 'output_id', 'date', 'client_uuid'])
            ->integer('item_id', true, 1)->decimal('quantity', true, 0, 1000000, false, true)->choice('reason', self::WASTE_REASONS, true)
            ->string('note', 255, false, true)->integer('output_id', false, 1, null, true)->date('date', false)
            ->string('client_uuid', 36, false, true, '/^[0-9a-fA-F-]{36}$/', 'INVALID_UUID');
        $v->throwIfInvalid();
        $c = $v->clean();
        if (!empty($c['client_uuid']) && $this->repo->wasteByUuid($c['client_uuid']) !== null) {
            return $this->day($context, $c['date'] ?? null) + ['replayed' => true];
        }
        if ($c['reason'] === 'other' && trim((string) ($c['note'] ?? '')) === '') throw ApiException::validation([['field' => 'note', 'code' => 'WASTE_NOTE_REQUIRED']]);
        $item = $this->repo->item((int) $c['item_id']) ?? throw ApiException::validation([['field' => 'item_id', 'code' => 'NOT_FOUND_REF']]);
        $day = $this->day0($c['date'] ?? null);
        if (!$this->editable($day)) throw new ApiException('OUTPUT_LOCKED', 409, [], ['days' => $this->editDays()]);
        $outputId = null;
        if (!empty($c['output_id'])) {
            $o = $this->output((int) $c['output_id'], $context);
            if ((int) $o['id_item'] !== (int) $item['id'] || (string) $o['work_date'] !== $day || $o['cancelled_at'] !== null) {
                throw ApiException::validation([['field' => 'output_id', 'code' => 'WASTE_OUTPUT_MISMATCH']]);
            }
            $outputId = (int) $o['id'];
        }
        $quantity = round((float) $c['quantity'], 3);
        $current = array_values(array_filter($this->day($context, $day)['items'], fn($i) => $i['item_id'] === (int) $item['id']))[0] ?? null;
        $good = $current['good'] ?? 0.0;
        if ($quantity > $good + 1e-6) throw new ApiException('WASTE_EXCEEDS_PRODUCED', 409, [], ['good' => $good]);
        $this->tx->execute(function () use ($c, $item, $day, $groupId, $outputId, $quantity, $context) {
            $id = $this->repo->insertWaste([
                'work_date' => $day, 'id_group' => $groupId, 'id_item' => (int) $item['id'], 'id_output' => $outputId, 'quantity' => $quantity,
                'reason' => $c['reason'], 'note' => isset($c['note']) && trim((string) $c['note']) !== '' ? trim((string) $c['note']) : null,
                'id_employee' => $context->employeeId, 'id_device' => $context->deviceId, 'client_uuid' => $c['client_uuid'] ?? null,
            ]);
            $this->audit->record($context, 'create', 'waste', $id, null, ['item_id' => (int) $item['id'], 'quantity' => $quantity, 'reason' => $c['reason'], 'date' => $day]);
        });
        return $this->day($context, $day);
    }

    /** Anulowanie straty (tylko w dniach edycji). */
    public function updateWaste(int $id, array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $w = $this->repo->waste($id) ?? throw ApiException::notFound('waste', $id);
        if ((int) $w['id_group'] !== $groupId) throw new ApiException('OUTPUT_NOT_IN_YOUR_GROUPS', 403);
        $v = (new Validator($body, true))->only(['cancelled'])->boolean('cancelled', true);
        $v->throwIfInvalid();
        if ($w['cancelled_at'] !== null) throw new ApiException('WASTE_CANCELLED', 409);
        if (!$this->editable((string) $w['work_date'])) throw new ApiException('OUTPUT_LOCKED', 409, [], ['days' => $this->editDays()]);
        if (!empty($v->clean()['cancelled'])) {
            $this->tx->execute(function () use ($id, $w, $context) {
                $this->repo->cancelWaste($id, $context->employeeId);
                $this->audit->record($context, 'cancel', 'waste', $id, ['quantity' => (float) $w['quantity'], 'reason' => $w['reason']], ['cancelled' => true]);
            });
        }
        return $this->day($context, (string) $w['work_date']);
    }

    /**
     * Zespół zatwierdza produkcję dnia : podsumowanie (plan, zrobione, straty, dobre) zapisane z osobą i godziną.
     * Braki (partie bez numerów partii surowców lub bez kontroli jakości) : zatwierdzenie tylko z notatką.
     */
    public function closeDay(array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $v = (new Validator($body))->only(['date', 'note'])->date('date', false)->string('note', 255, false, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        $day = $this->day0($c['date'] ?? null);
        if (!$this->editable($day)) throw new ApiException('OUTPUT_LOCKED', 409, [], ['days' => $this->editDays()]);
        $current = $this->day($context, $day);
        if (!$current['items']) throw new ApiException('DAY_NOTHING_TO_CLOSE', 409);
        $note = isset($c['note']) && trim((string) $c['note']) !== '' ? trim((string) $c['note']) : null;
        // Traçabilité : partie sans lots matières ou sans contrôle qualité → validation possible, mais avec une note (pourquoi).
        $gaps = $current['gaps'];
        if (($gaps['lots'] || $gaps['qc']) && $note === null) {
            throw new ApiException('DAY_GAPS_NOTE_REQUIRED', 422, [], ['lots' => count($gaps['lots']), 'qc' => count($gaps['qc'])]);
        }
        $this->tx->execute(function () use ($day, $groupId, $note, $current, $context, $gaps) {
            $this->repo->saveDayClose($day, $groupId, $context->employeeId, $context->deviceId, $note, json_encode($this->summary($current['items'])));
            $this->audit->record($context, 'close', 'production_day', $groupId, null, ['date' => $day] + $current['totals'] + ['missing_lots' => count($gaps['lots']), 'missing_qc' => count($gaps['qc'])]);
        });
        return $this->day($context, $day);
    }

    /** Nowa partia wyrobu. */
    public function record(array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $v = (new Validator($body))->only(['item_id', 'quantity', 'date', 'note', 'client_uuid'])
            ->integer('item_id', true, 1)->decimal('quantity', true, 0, 1000000, false, true)->date('date', false)
            ->string('note', 255, false, true)->string('client_uuid', 36, false, true, '/^[0-9a-fA-F-]{36}$/', 'INVALID_UUID');
        $v->throwIfInvalid();
        $c = $v->clean();
        if (!empty($c['client_uuid']) && ($done = $this->repo->byUuid($c['client_uuid'])) !== null) {
            return $this->batch((int) $done['id'], $context) + ['replayed' => true];
        }
        $item = $this->repo->item((int) $c['item_id']) ?? throw ApiException::validation([['field' => 'item_id', 'code' => 'NOT_FOUND_REF']]);
        $day = $this->day0($c['date'] ?? null);
        if (!$this->editable($day)) throw new ApiException('OUTPUT_LOCKED', 409, [], ['days' => $this->editDays()]);
        $id = $this->tx->execute(function () use ($c, $item, $day, $groupId, $context) {
            $id = $this->repo->insert([
                'work_date' => $day, 'id_group' => $groupId, 'id_item' => (int) $item['id'], 'quantity' => round((float) $c['quantity'], 3),
                'lot_code' => $this->lotCode($day, $groupId), 'id_employee' => $context->employeeId, 'id_device' => $context->deviceId,
                'client_uuid' => $c['client_uuid'] ?? null, 'note' => $c['note'] ?? null,
            ]);
            $this->audit->record($context, 'create', 'output', $id, null, ['item_id' => (int) $item['id'], 'quantity' => (float) $c['quantity'], 'date' => $day]);
            return $id;
        });
        return $this->batch($id, $context);
    }

    /** Partia z recepturą : surowce i opakowania, potrzebna ilość, wpisane partie, podpowiedź « jak ostatnio ». */
    public function batch(int $id, ApiContext $context): array
    {
        $o = $this->output($id, $context);
        $item = $this->repo->item((int) $o['id_item']);
        $lots = $this->repo->lots($id);
        $recipe = $item && $item['id_recipe'] ? $this->repo->recipe((int) $item['id_recipe']) : [];
        $last = $this->repo->lastLots((int) $o['id_group'], array_map(fn($r) => (int) $r['material_id'], $recipe), $id);
        $received = $this->repo->receivedLots(array_map(fn($r) => (int) $r['material_id'], $recipe));
        $factor = $this->factor($item, (float) $o['quantity']);
        $fr = $context->translator->lang() === 'fr';
        $ingredients = array_map(function ($r) use ($lots, $last, $received, $factor, $fr) {
            $m = (int) $r['material_id'];
            $l = $lots[$m] ?? null;
            return [
                'material_id' => $m, 'name' => $fr && $r['name_fr'] ? $r['name_fr'] : $r['name'], 'unit' => $r['unit'],
                'packaging' => (bool) $r['packaging'], 'per_unit' => (float) $r['quantity'],
                'quantity' => $factor === null ? null : round((float) $r['quantity'] * $factor, 3),
                'lot_number' => $l['lot_number'] ?? null, 'best_before' => $l['best_before'] ?? null,
                'photo' => $l !== null && $l['id_photo'] !== null,
                'last' => isset($last[$m]) ? ['lot_number' => $last[$m]['lot_number'], 'best_before' => $last[$m]['best_before']] : null,
                // Partie z dostaw (FEFO) : przyjęte z partią i datą ważności, najkrótsza data najpierw.
                'received' => $received[$m] ?? [],
            ];
        }, $recipe);
        $row = $this->batchRow($o);
        $row['lots_done'] = count(array_filter($ingredients, fn($i) => $i['lot_number'] !== null && $i['lot_number'] !== ''));
        return $row + [
            'lots_total' => count($ingredients),
            'item' => $item ? $this->itemRow($item) : null,
            'basis' => $factor === null ? 'recipe' : 'batch',
            'ingredients' => $ingredients,
        ];
    }

    /** Poprawa ilości / notatki albo anulowanie partii (tylko w dniach edycji). */
    public function update(int $id, array $body, ApiContext $context): array
    {
        $o = $this->output($id, $context);
        $v = (new Validator($body, true))->only(['quantity', 'note', 'cancelled'])
            ->decimal('quantity', false, 0, 1000000, false, true)->string('note', 255, false, true)->boolean('cancelled');
        $v->throwIfInvalid();
        $c = $v->clean();
        $this->assertEditable($o);
        $data = [];
        if (array_key_exists('quantity', $c)) $data['quantity'] = round((float) $c['quantity'], 3);
        if (array_key_exists('note', $c)) $data['note'] = $c['note'];
        if (!empty($c['cancelled'])) { $data['cancelled_at'] = date('Y-m-d H:i:s'); $data['cancelled_by'] = $context->employeeId; }
        if ($data) {
            $this->tx->execute(function () use ($id, $data, $o, $context) {
                $this->repo->update($id, $data);
                $this->audit->record($context, isset($data['cancelled_at']) ? 'cancel' : 'update', 'output', $id,
                    ['quantity' => (float) $o['quantity'], 'note' => $o['note']], array_diff_key($data, ['cancelled_by' => 1]));
            });
        }
        return $this->batch($id, $context);
    }

    /** Partia surowca / opakowania w partii wyrobu : numer, data przydatności, zdjęcie etykiety, tekst OCR. */
    public function setLot(int $id, int $materialId, array $body, ApiContext $context): array
    {
        $o = $this->output($id, $context);
        $v = (new Validator($body))->only(['lot_number', 'best_before', 'photo', 'ocr_text'])
            ->string('lot_number', 64, false, true)->date('best_before', false)->string('ocr_text', 4000, false, true);
        $v->throwIfInvalid();
        $c = $v->clean();
        $this->assertEditable($o);
        $item = $this->repo->item((int) $o['id_item']);
        $line = null;
        foreach ($item && $item['id_recipe'] ? $this->repo->recipe((int) $item['id_recipe']) : [] as $r) if ((int) $r['material_id'] === $materialId) $line = $r;
        if ($line === null) throw ApiException::validation([['field' => 'material_id', 'code' => 'MATERIAL_NOT_IN_RECIPE']]);
        $photo = isset($body['photo']) && $body['photo'] !== null && $body['photo'] !== '' ? $this->decodePhoto($body['photo']) : null;
        $lot = isset($c['lot_number']) ? (preg_replace('/\s+/u', ' ', mb_strtoupper(trim((string) $c['lot_number']))) ?: null) : null;
        $factor = $this->factor($item, (float) $o['quantity']);
        $this->tx->execute(function () use ($id, $materialId, $line, $factor, $lot, $c, $photo, $context) {
            $photoId = $photo ? $this->repo->insertPhoto($photo[0], $photo[1], $context->deviceId, $context->employeeId) : null;
            $this->repo->saveLot($id, $materialId, $factor === null ? null : round((float) $line['quantity'] * $factor, 3), $lot,
                $c['best_before'] ?? null, $photoId, isset($c['ocr_text']) ? trim((string) $c['ocr_text']) : null, $context->employeeId);
            $this->audit->record($context, 'lot', 'output', $id, null, ['material_id' => $materialId, 'lot_number' => $lot, 'best_before' => $c['best_before'] ?? null, 'photo' => $photoId]);
        });
        return $this->batch($id, $context);
    }

    // ---------------------------------------------------------------------------------------------

    private function groupId(ApiContext $context): int
    {
        if ($context->deviceGroupId === null) throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
        return $context->deviceGroupId;
    }

    private function output(int $id, ApiContext $context): array
    {
        $o = $this->repo->output($id) ?? throw ApiException::notFound('output', $id);
        $groups = $context->employeeId ? $this->execution->employeeGroups($context->employeeId) : [];
        if ($context->deviceGroupId !== null) $groups[] = $context->deviceGroupId;
        if (!in_array((int) $o['id_group'], $groups, true)) throw new ApiException('OUTPUT_NOT_IN_YOUR_GROUPS', 403);
        return $o;
    }

    private function day0(?string $date): string
    {
        return $date !== null && $date !== '' ? $date : date('Y-m-d');
    }

    private function editDays(): int
    {
        return max(0, (int) $this->params->get('kiosk.output_edit_days', '1'));
    }

    private function editable(string $day): bool
    {
        return $day <= date('Y-m-d') && $day >= date('Y-m-d', strtotime('-' . $this->editDays() . ' days'));
    }

    private function assertEditable(array $o): void
    {
        if ($o['cancelled_at'] !== null) throw new ApiException('OUTPUT_CANCELLED', 409);
        if (!$this->editable((string) $o['work_date'])) throw new ApiException('OUTPUT_LOCKED', 409, [], ['days' => $this->editDays()]);
    }

    /** Mnożnik receptury : receptura produktu jest na karton (sztuki ÷ szt./karton) ; półprodukt : brak (ilości na recepturę). */
    private function factor(?array $item, float $quantity): ?float
    {
        if ($item === null || $item['item_type'] !== 'product' || (int) $item['ppc'] <= 0) return null;
        return $quantity / (int) $item['ppc'];
    }

    private function lotCode(string $day, int $groupId): string
    {
        $group = $this->groups->find($groupId);
        $code = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', (string) ($group['code'] ?? 'G' . $groupId)), 0, 4)) ?: 'G' . $groupId;
        $n = $this->repo->countForDay($day, $groupId);
        do {
            $lot = sprintf('%s-%s-%02d', date('ymd', strtotime($day)), $code, ++$n);
        } while ($this->repo->lotCodeTaken($lot));
        return $lot;
    }

    /** @return array{0: string, 1: string} [typ MIME, bajty] */
    private function decodePhoto(mixed $photo): array
    {
        $maxKb = max(100, (int) $this->params->get('kiosk.lot_photo_max_kb', '1500'));
        $error = fn(string $code) => ApiException::validation([['field' => 'photo', 'code' => $code, 'params' => ['max_kb' => $maxKb]]]);
        if (!is_string($photo) || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $photo, $m)) throw $error('INVALID_PHOTO');
        if (strlen($m[2]) > $maxKb * 1000 * 4 / 3 + 4) throw $error('PHOTO_TOO_LARGE');
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) < 100 || !str_starts_with($bytes, self::PHOTO_TYPES[$m[1]])) throw $error('INVALID_PHOTO');
        if (strlen($bytes) > $maxKb * 1000) throw $error('PHOTO_TOO_LARGE');
        return [$m[1], $bytes];
    }

    /** Podsumowanie zatwierdzenia : na produkt plan, zrobione i straty (porównywane przy kolejnym odczycie). */
    /**
     * Braki dnia (assistant « Koniec dnia ») : partie bez wszystkich numerów partii surowców, partie bez kontroli
     * jakości (anulowane pominięte) — identyfikatory partii.
     */
    private function gaps(array $items): array
    {
        $lots = $qc = [];
        foreach ($items as $i) {
            foreach ($i['batches'] as $b) {
                if ($b['cancelled']) continue;
                if (($b['lots_total'] ?? 0) > ($b['lots_done'] ?? 0)) $lots[] = $b['id'];
                if (($b['qc'] ?? null) === null) $qc[] = $b['id'];
            }
        }
        return ['lots' => $lots, 'qc' => $qc];
    }

    private function summary(array $items): array
    {
        return array_map(fn($i) => ['item_id' => $i['item_id'], 'planned' => $i['planned'], 'produced' => $i['produced'], 'waste' => $i['waste']], $items);
    }

    private function totals(array $items): array
    {
        $t = ['products' => count($items), 'planned_done' => 0, 'planned' => 0, 'produced' => 0.0, 'waste' => 0.0];
        foreach ($items as $i) {
            if ($i['planned'] !== null) { $t['planned']++; if ($i['produced'] + 1e-6 >= $i['planned']) $t['planned_done']++; }
            $t['produced'] += $i['produced'];
            $t['waste'] += $i['waste'];
        }
        $t['produced'] = round($t['produced'], 3);
        $t['waste'] = round($t['waste'], 3);
        $t['waste_pct'] = $t['produced'] > 0 ? round($t['waste'] / $t['produced'] * 100, 1) : null;
        return $t;
    }

    private function wasteRow(array $w): array
    {
        return [
            'id' => (int) $w['id'], 'item_id' => (int) $w['id_item'], 'quantity' => (float) $w['quantity'], 'reason' => $w['reason'],
            'note' => $w['note'], 'lot_code' => $w['lot_code'] ?? null, 'employee' => $w['employee_name'] ?: null,
            'created_at' => $w['created_at'], 'cancelled' => $w['cancelled_at'] !== null,
            'editable' => $w['cancelled_at'] === null && $this->editable((string) $w['work_date']),
        ];
    }

    private function itemRow(array $i): array
    {
        return ['item_id' => (int) $i['id'], 'name' => $i['name'], 'type' => $i['item_type'], 'unit' => $i['base_unit'],
            'ppc' => $i['ppc'] !== null && (int) $i['ppc'] > 0 ? (int) $i['ppc'] : null,
            // Partia produkcyjna karty : tablet pokazuje kafelkę na partię i proponuje jedną partię naraz.
            'batch' => isset($i['batch_multiple']) && (float) $i['batch_multiple'] > 0 ? (float) $i['batch_multiple'] : null];
    }

    private function batchRow(array $o): array
    {
        return [
            'id' => (int) $o['id'], 'item_id' => (int) $o['id_item'], 'date' => $o['work_date'], 'quantity' => (float) $o['quantity'],
            'lot_code' => $o['lot_code'], 'employee' => $o['employee_name'] ?? null, 'note' => $o['note'],
            'created_at' => $o['created_at'], 'cancelled' => $o['cancelled_at'] !== null,
            'lots_done' => isset($o['lots_done']) ? (int) $o['lots_done'] : null,
            'qc' => $o['qc_status'] ?? null,
            'editable' => $o['cancelled_at'] === null && $this->editable((string) $o['work_date']),
        ];
    }
}
