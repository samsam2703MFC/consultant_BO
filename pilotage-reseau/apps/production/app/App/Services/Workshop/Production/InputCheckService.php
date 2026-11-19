<?php
declare(strict_types=1);

namespace App\Services\Workshop\Production;

use App\Exceptions\CustomException;
use App\Repositories\Workshop\GroupRepository;
use App\Services\Workshop\Execution\OutputService;

/**
 * [352] Kontrola wejść produkcji (makieta 68 · 2) : partie wyrobów dnia wszystkich grup — ile surowców śledzonych ma
 * partię (z listy, wpisaną albo brak z powodem i zdjęciem), czego brakuje ; klik → składniki i wybrane partie.
 * Te same liczby co na tablecie (OutputService : dayOf / batchFor).
 */
final class InputCheckService
{
    public function __construct(private OutputService $outputs, private GroupRepository $groups)
    {
    }

    public function day(string $day): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !strtotime($day)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        $rows = [];
        foreach ($this->groups->list(null) as $g) {
            if (empty($g['active'])) continue;
            $d = $this->outputs->dayOf((int) $g['id'], $day);
            foreach ($d['items'] as $i) {
                foreach ($i['batches'] as $b) {
                    if ($b['cancelled']) continue;
                    $total = (int) ($b['lots_total'] ?? 0);
                    $done = min($total, (int) ($b['lots_done'] ?? 0));
                    $row = ['id' => $b['id'], 'lot_code' => $b['lot_code'], 'product' => $i['name'], 'group' => $d['group']['name'],
                        'employee' => $b['employee'], 'created_at' => $b['created_at'], 'done' => $done, 'total' => $total,
                        'complete' => $done >= $total, 'missing' => []];
                    if (!$row['complete']) $row['missing'] = $this->missing((int) $b['id']);
                    $rows[] = $row;
                }
            }
        }
        usort($rows, fn($a, $b) => [$a['complete'], $a['created_at']] <=> [$b['complete'], $b['created_at']]);
        return ['date' => $day, 'rows' => $rows,
            'complete' => count(array_filter($rows, fn($r) => $r['complete'])), 'incomplete' => count(array_filter($rows, fn($r) => !$r['complete']))];
    }

    /** Szczegół partii : składniki śledzone (partia z listy / wpisana / brak z powodem), kompozycje innych grup. */
    public function batch(int $outputId): array
    {
        $b = $this->outputs->batchFor($outputId) ?? throw new CustomException('Nie znaleziono partii.', 'errors', 404);
        return [
            'id' => $b['id'], 'lot_code' => $b['lot_code'], 'done' => $b['lots_done'], 'total' => $b['lots_total'],
            'ingredients' => array_map(fn($g) => ['name' => $g['name'], 'tracked' => $g['tracked'], 'packaging' => $g['packaging'],
                'quantity' => $g['quantity'], 'unit' => $g['unit'], 'lot_number' => $g['lot_number'], 'best_before' => $g['best_before'],
                'from_list' => $g['material_lot_id'] !== null, 'missing_reason' => $g['missing_reason'], 'photo' => $g['photo']], $b['ingredients']),
            'components' => array_map(fn($c) => ['name' => $c['name'], 'producer' => $c['producer']['name'], 'lot_code' => $c['source']['lot_code'] ?? null], $b['components']),
        ];
    }

    /** @return list<string> nazwy surowców śledzonych bez partii i kompozycji bez partii grupy robiącej */
    private function missing(int $outputId): array
    {
        $b = $this->outputs->batchFor($outputId);
        if ($b === null) return [];
        $out = [];
        foreach ($b['ingredients'] as $g) {
            if (!$g['tracked']) continue;
            if (($g['lot_number'] ?? '') === '' && $g['missing_reason'] === null) $out[] = $g['name'];
        }
        foreach ($b['components'] as $c) if ($c['source'] === null) $out[] = $c['name'];
        return $out;
    }
}
