<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

use App\Core\Api\Workshop\ApiException;
use App\Repositories\Workshop\PriceHistoryRepository;
use App\Services\Purchasing\CostOptimizationService;

/**
 * Historia cen surowców za kg (l, szt) i według dostawcy : faktury zakupowe (cena zapłacona), przyjęcia z ceną
 * z zamówień bez faktury, zmiany ceny w kartotece (oferta, faktura, ręcznie, nowy surowiec). Cena « faktura » w kartotece
 * pochodzi z faktury już pokazanej — nie liczona drugi raz. Ceny w API : zł za jednostkę bazową (g, ml, szt) ;
 * factor = jednostki bazowe w jednostce wyświetlanej (kg = 1000 g).
 */
final class PriceHistoryService
{
    public const MONTHS = [3, 6, 12, 24, 36];
    private const SAME_PRICE = 0.005;

    public function __construct(private PriceHistoryRepository $repo)
    {
    }

    /** Lista surowców z ceną w okresie : ostatnia cena i dostawca, zmiana wobec poprzedniej, najtańsza i najdroższa, liczba dostawców. */
    public function overview(int $months, string $lang): array
    {
        $months = $this->months($months);
        $since = date('Y-m-d', strtotime("-{$months} months"));
        $points = $this->points(null, $since);
        $rows = [];
        foreach ($this->repo->materials() as $m) {
            $pts = $points[(int) $m['id']] ?? [];
            if (!$pts) continue;
            $unit = CostOptimizationService::displayUnit($m['unit']);
            $last = end($pts);
            $prev = null;
            for ($k = count($pts) - 2; $k >= 0; $k--) {
                if (abs($pts[$k]['price'] - $last['price']) > $last['price'] * self::SAME_PRICE || $pts[$k]['supplier_id'] !== $last['supplier_id']) { $prev = $pts[$k]; break; }
            }
            $min = array_reduce($pts, fn($c, $p) => $c === null || $p['price'] < $c['price'] ? $p : $c);
            $max = array_reduce($pts, fn($c, $p) => $c === null || $p['price'] > $c['price'] ? $p : $c);
            $rows[] = ['raw_material_id' => (int) $m['id'], 'name' => $this->name($m, $lang), 'base_unit' => $m['unit'] ?: 'szt', 'unit' => $unit['label'], 'factor' => $unit['factor'],
                'packaging' => (bool) $m['is_part_of_package'],
                'catalog_price' => (float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null, 'current_supplier' => $m['supplier'],
                'last' => $this->brief($last), 'previous' => $prev ? $this->brief($prev) : null,
                'change_pct' => $prev && $prev['price'] > 0 ? round(($last['price'] - $prev['price']) / $prev['price'] * 100, 1) : null,
                'min' => $this->brief($min), 'max' => $this->brief($max),
                'suppliers' => count(array_unique(array_map(fn($p) => $p['supplier_id'] ?? 0, $pts))), 'points' => count($pts)];
        }
        return ['months' => $months, 'rows' => $rows];
    }

    /** Jeden surowiec : punkty (wykres), podsumowanie według dostawcy (ostatnia, min., maks., średnia ważona ilością), najtańszy. */
    public function material(int $id, int $months, string $lang): array
    {
        $m = $this->repo->materials([$id])[0] ?? null;
        if ($m === null) throw ApiException::notFound('raw_material', $id);
        $months = $this->months($months);
        $pts = $this->points([$id], date('Y-m-d', strtotime("-{$months} months")))[$id] ?? [];
        $unit = CostOptimizationService::displayUnit($m['unit']);
        $year = date('Y-m-d', strtotime('-12 months'));
        $by = [];
        foreach ($pts as $p) {
            $k = $p['supplier_id'] ?? 0;
            $s = $by[$k] ??= ['supplier_id' => $p['supplier_id'], 'supplier' => $p['supplier'], 'count' => 0, 'qty' => 0.0, 'value' => 0.0, 'sum' => 0.0, 'min' => null, 'max' => null, 'first' => $p, 'last' => $p];
            $s['count']++;
            $s['sum'] += $p['price'];
            if ($p['qty'] !== null) { $s['qty'] += $p['qty']; $s['value'] += $p['qty'] * $p['price']; }
            $s['min'] = $s['min'] === null ? $p['price'] : min($s['min'], $p['price']);
            $s['max'] = $s['max'] === null ? $p['price'] : max($s['max'], $p['price']);
            $s['last'] = $p;
            $by[$k] = $s;
        }
        $suppliers = array_map(fn($s) => [
            'supplier_id' => $s['supplier_id'], 'supplier' => $s['supplier'], 'current' => $s['supplier_id'] !== null && $s['supplier_id'] === (int) $m['id_current_supplier'],
            'last_price' => $s['last']['price'], 'last_day' => $s['last']['day'], 'last_kind' => $s['last']['kind'],
            'first_price' => $s['first']['price'], 'change_pct' => $s['first']['price'] > 0 && $s['count'] > 1 ? round(($s['last']['price'] - $s['first']['price']) / $s['first']['price'] * 100, 1) : null,
            'min' => $s['min'], 'max' => $s['max'], 'avg' => $s['qty'] > 0 ? $s['value'] / $s['qty'] : $s['sum'] / $s['count'],
            'count' => $s['count'], 'qty' => $s['qty'] > 0 ? round($s['qty'], 3) : null, 'recent' => $s['last']['day'] >= $year,
        ], array_values($by));
        usort($suppliers, fn($a, $b) => [!$a['recent'], $a['last_price']] <=> [!$b['recent'], $b['last_price']]);
        $best = array_values(array_filter($suppliers, fn($s) => $s['recent']))[0] ?? null;
        return [
            'material' => ['id' => (int) $m['id'], 'name' => $this->name($m, $lang), 'base_unit' => $m['unit'] ?: 'szt', 'unit' => $unit['label'], 'factor' => $unit['factor'],
                'catalog_price' => (float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null, 'current_supplier' => $m['supplier']],
            'months' => $months, 'suppliers' => $suppliers,
            'best' => $best ? ['supplier_id' => $best['supplier_id'], 'supplier' => $best['supplier'], 'price' => $best['last_price'], 'day' => $best['last_day']] : null,
            'points' => array_map(fn($p) => $p + ['price' => round($p['price'], 6)], $pts),
        ];
    }

    /**
     * Do faktury : dla każdego surowca poprzednia cena (ostatni punkt spoza tej faktury) i najtańszy dostawca z 12 miesięcy.
     * @return array<int, array{previous: ?array, best: ?array, suppliers: int}>
     */
    public function refs(array $materialIds, int $excludeInvoice): array
    {
        if (!$materialIds) return [];
        $out = [];
        foreach ($this->points($materialIds, date('Y-m-d', strtotime('-12 months')), $excludeInvoice) as $mid => $pts) {
            $lastBy = [];
            foreach ($pts as $p) $lastBy[$p['supplier_id'] ?? 0] = $p;
            $best = array_reduce($lastBy, fn($c, $p) => $c === null || $p['price'] < $c['price'] ? $p : $c);
            $out[$mid] = ['previous' => $this->brief(end($pts)), 'best' => $best ? $this->brief($best) : null, 'suppliers' => count($lastBy)];
        }
        return $out;
    }

    /**
     * Punkty według surowca, od najstarszego : kind invoice | receipt | catalog, day, supplier_id, supplier, price (zł / jedn. bazową),
     * qty (jedn. bazowe, zakupy), ref (numer faktury / zamówienia, źródło zmiany w kartotece), user (kto zmienił kartotekę).
     * @return array<int, list<array>>
     */
    private function points(?array $materialIds, string $since, int $excludeInvoice = 0): array
    {
        $out = [];
        $excluded = [];
        foreach ($this->repo->invoicePoints($materialIds, $since) as $r) {
            $qty = (float) $r['qty_base'];
            $net = $r['net'] !== null ? (float) $r['net'] : 0.0;
            if ($qty <= 0 || $net <= 0) continue;
            $out[(int) $r['id_raw_material']][] = ['kind' => 'invoice', 'day' => $r['day'], 'supplier_id' => $r['id_supplier'] !== null ? (int) $r['id_supplier'] : null,
                'supplier' => $r['supplier'], 'price' => $net / $qty, 'qty' => round($qty, 3), 'ref' => $r['number'] ?: '#' . $r['invoice_id'], 'invoice_id' => (int) $r['invoice_id'], 'user' => null];
            if ((int) $r['invoice_id'] === $excludeInvoice) $excluded[(int) $r['id_raw_material']] = true;
        }
        foreach ($this->repo->receiptPoints($materialIds, $since) as $r) {
            $out[(int) $r['id_raw_material']][] = ['kind' => 'receipt', 'day' => $r['day'], 'supplier_id' => $r['id_supplier'] !== null ? (int) $r['id_supplier'] : null,
                'supplier' => $r['supplier'], 'price' => (float) $r['price'], 'qty' => round((float) $r['qty_base'], 3), 'ref' => $r['code'], 'invoice_id' => null, 'user' => null];
        }
        foreach ($this->repo->catalogPoints($materialIds, $since) as $r) {
            $mid = (int) $r['id_raw_material'];
            $sid = $r['id_supplier'] !== null && (int) $r['id_supplier'] > 0 ? (int) $r['id_supplier'] : null;
            $price = (float) $r['price'];
            // Cena z faktury przeniesiona do kartoteki : ta sama faktura jest już punktem « invoice ».
            if ($r['source'] === 'invoice' && array_filter($out[$mid] ?? [], fn($p) => $p['kind'] === 'invoice' && $p['supplier_id'] === $sid && abs($p['price'] - $price) <= $price * self::SAME_PRICE)) continue;
            $out[$mid][] = ['kind' => 'catalog', 'day' => $r['day'], 'supplier_id' => $sid, 'supplier' => $r['supplier'], 'price' => $price, 'qty' => null,
                'ref' => $r['source'] ?: null, 'invoice_id' => null, 'user' => $r['user_name']];
        }
        // Faktura pokazywana (refs) : jej linie i ceny z niej w kartotece nie są « poprzednią ceną » — usunięte po odfiltrowaniu duplikatów.
        foreach (array_keys($excluded) as $mid) $out[$mid] = array_values(array_filter($out[$mid], fn($p) => $p['invoice_id'] !== $excludeInvoice));
        $out = array_filter($out);
        foreach ($out as &$pts) usort($pts, fn($a, $b) => [$a['day'], $a['kind'] === 'catalog'] <=> [$b['day'], $b['kind'] === 'catalog']);
        unset($pts);
        return $out;
    }

    private function brief(array $p): array
    {
        return ['price' => round($p['price'], 6), 'day' => $p['day'], 'supplier_id' => $p['supplier_id'], 'supplier' => $p['supplier'], 'kind' => $p['kind'], 'ref' => $p['ref']];
    }

    private function months(int $months): int
    {
        if (!in_array($months, self::MONTHS, true)) throw ApiException::validation([['field' => 'months', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => implode(', ', self::MONTHS)]]]);
        return $months;
    }

    private function name(array $m, string $lang): string
    {
        return trim($lang === 'fr' && $m['name_fr'] ? (string) $m['name_fr'] : (string) $m['name']);
    }
}
