<?php
declare(strict_types=1);

namespace App\Services\Product;

use App\Domain\Logistics\PalletLayout;
use App\Exceptions\CustomException;
use App\Repositories\Product\CartonRepository;
use App\Repositories\Workshop\ItemRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Kartony i palety [205] (rail Produkt) : rodzaje kartonów (wymiary zewnętrzne, waga kartonu, maks. ładunek, rodzaj),
 * układ na palecie EUR / przemysłowej w maks. wysokości i wadze, produkty w kartonie (waga zawartości wobec maks.
 * ładunku, układ z ERP wobec palety) ; obliczenia kroku « Paleta » karty produktu.
 */
final class CartonService
{
    public const KINDS = ['klapowy', 'fasonowy', 'klejony', 'inny'];

    public function __construct(
        private CartonRepository $repo,
        private ParamRepository $params,
        private ItemRepository $items,
    ) {}

    /** Ograniczenia palety : parametry, nadpisane przez kartę produktu (puste = parametr). */
    public function limits(array $product = []): array
    {
        $p = fn(string $code, int $default, int $min, int $max) => max($min, min($max, (int) $this->params->get($code, (string) $default)));
        return [
            'max_height_mm' => (int) ($product['pallet_max_height_mm'] ?? 0) ?: $p('logistics.pallet_max_height_mm', 1800, 300, 3000),
            'max_kg' => (int) ($product['pallet_max_kg'] ?? 0) ?: $p('logistics.pallet_max_kg', 1000, 50, 3000),
            'overhang_mm' => isset($product['pallet_overhang_mm']) && $product['pallet_overhang_mm'] !== null ? (int) $product['pallet_overhang_mm'] : $p('logistics.pallet_overhang_mm', 0, 0, 100),
            'truck_height_mm' => $p('logistics.truck_inner_height_mm', 2550, 1000, 3500),
        ];
    }

    public function catalog(): array
    {
        $limits = $this->limits();
        $byCarton = [];
        $without = 0;
        foreach ($this->repo->products() as $p) {
            if ($p['carton_id'] === null) { $without++; continue; }
            $byCarton[(int) $p['carton_id']][] = $p;
        }
        $cartons = array_map(fn($c) => $this->present($c, $byCarton[(int) $c['id']] ?? [], $limits, false), $this->repo->cartons());
        return [
            'cartons' => $cartons,
            'limits' => $limits,
            'places' => max(1, (int) $this->params->get('logistics.default_pallet_places', '33')),
            'kinds' => self::KINDS,
            'without_carton' => $without,
            'products_total' => array_sum(array_map('count', $byCarton)) + $without,
        ];
    }

    public function detail(int $id): array
    {
        $c = $this->repo->carton($id) ?? throw new CustomException('Nie znaleziono kartonu.', 'errors', 404);
        $products = array_values(array_filter($this->repo->products(), fn($p) => $p['carton_id'] === $id));
        return $this->present($c, $products, $this->limits(), true);
    }

    public function candidates(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => trim((string) $r['name']), 'category' => $r['category']], $this->repo->candidates());
    }

    public function add(array $input, ?int $userId): array
    {
        $id = filter_var($input['raw_material_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !$this->repo->materialExists((int) $id)) {
            throw new CustomException('Wybierz opakowanie z listy surowców.', 'errors', 422, ['fields' => ['raw_material_id' => 'Wybierz opakowanie.']]);
        }
        if ($this->repo->carton((int) $id)) throw new CustomException('Ten karton jest już w katalogu.', 'errors', 409);
        $this->repo->add((int) $id, $userId);
        return $this->detail((int) $id);
    }

    /** Zapis : wymiary (mm), waga kartonu (g), maks. ładunek (kg), rodzaj, notatka, aktywny — tylko podane pola. */
    public function save(int $id, array $input, ?int $userId): array
    {
        if (!$this->repo->carton($id)) throw new CustomException('Nie znaleziono kartonu.', 'errors', 404);
        $data = [];
        $errors = [];
        foreach (['length_mm', 'width_mm', 'height_mm'] as $f) {
            if (!array_key_exists($f, $input)) continue;
            $v = self::number($input[$f]);
            if ($v === null) { $data[$f] = null; continue; }
            if ($v != (int) $v || $v < 20 || $v > 2000) { $errors[$f] = 'Liczba całkowita od 20 do 2000 mm.'; continue; }
            $data[$f] = (int) $v;
        }
        if (array_key_exists('max_load_kg', $input)) {
            $v = self::number($input['max_load_kg']);
            if ($v !== null && ($v < 0.1 || $v > 100)) $errors['max_load_kg'] = 'Od 0,1 do 100 kg.';
            else $data['max_load_kg'] = $v === null ? null : round($v, 2);
        }
        if (array_key_exists('kind', $input)) {
            $k = $input['kind'] === null || $input['kind'] === '' ? null : (string) $input['kind'];
            if ($k !== null && !in_array($k, self::KINDS, true)) $errors['kind'] = 'Wybierz rodzaj z listy.';
            else $data['kind'] = $k;
        }
        if (array_key_exists('note', $input)) {
            $n = trim((string) ($input['note'] ?? ''));
            if (mb_strlen($n) > 255) $errors['note'] = 'Do 255 znaków.';
            else $data['note'] = $n === '' ? null : $n;
        }
        if (array_key_exists('active', $input)) $data['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $tare = null;
        $tareGiven = array_key_exists('tare_g', $input);
        if ($tareGiven) {
            $tare = self::number($input['tare_g']);
            if ($tare !== null && ($tare < 1 || $tare > 10000)) $errors['tare_g'] = 'Od 1 do 10 000 g.';
        }
        if ($errors) {
            $labels = ['length_mm' => 'Długość', 'width_mm' => 'Szerokość', 'height_mm' => 'Wysokość', 'max_load_kg' => 'Maks. ładunek', 'kind' => 'Rodzaj', 'note' => 'Notatka', 'tare_g' => 'Waga kartonu'];
            throw new CustomException(implode(' ', array_map(fn($f, $m) => ($labels[$f] ?? $f) . ' : ' . $m, array_keys($errors), $errors)), 'errors', 422, ['fields' => $errors]);
        }
        $this->repo->save($id, $data, $tare, $tareGiven, $userId);
        return $this->detail($id);
    }

    /**
     * Krok « Paleta » : układ dla podanych wymiarów i ograniczeń + ocena układu z ERP.
     * Wejście : length_mm, width_mm, height_mm, tare_g, pallet, max_height_mm, max_kg, overhang_mm, net_kg, cpl, lpp, cpp.
     */
    public function plan(array $q): array
    {
        $int = fn(string $k, int $min, int $max, int $default) => max($min, min($max, (int) ($q[$k] ?? $default) ?: $default));
        $defaults = $this->limits();
        $limits = [
            'max_height_mm' => $int('max_height_mm', 300, 3000, $defaults['max_height_mm']),
            'max_kg' => $int('max_kg', 50, 3000, $defaults['max_kg']),
            'overhang_mm' => max(0, min(100, (int) ($q['overhang_mm'] ?? $defaults['overhang_mm']))),
            'truck_height_mm' => $defaults['truck_height_mm'],
        ];
        $pallet = isset(PalletLayout::PALLETS[$q['pallet'] ?? '']) ? (string) $q['pallet'] : 'eur';
        $net = self::number($q['net_kg'] ?? null);
        $plan = PalletLayout::plan(['length_mm' => (int) ($q['length_mm'] ?? 0), 'width_mm' => (int) ($q['width_mm'] ?? 0), 'height_mm' => (int) ($q['height_mm'] ?? 0),
            'tare_g' => self::number($q['tare_g'] ?? null)], $pallet, $limits, $net !== null && $net > 0 ? $net : null);
        if ($plan === null) throw new CustomException('Podaj wymiary kartonu (długość, szerokość, wysokość).', 'errors', 422);
        $plan['erp_check'] = PalletLayout::check($plan, (int) ($q['cpl'] ?? 0), (int) ($q['lpp'] ?? 0), (int) ($q['cpp'] ?? 0));
        return $plan;
    }

    /** Krok « Paleta » : kartony do wyboru, karton produktu (karta, PPWR, receptura), sprzedaż na tydzień, ograniczenia. */
    public function productInfo(int $finalProductId): array
    {
        $product = null;
        foreach ($this->repo->products() as $p) if ((int) $p['id'] === $finalProductId) { $product = $p; break; }
        if ($product === null) throw new CustomException('Nie znaleziono aktywnego produktu.', 'errors', 404);
        $weeks = max(1, (int) $this->params->get('stock.suggest_weeks', '6'));
        $cartons = array_values(array_filter(array_map(fn($c) => [
            'id' => (int) $c['id'], 'name' => trim((string) $c['name']), 'length_mm' => self::int($c['length_mm']), 'width_mm' => self::int($c['width_mm']),
            'height_mm' => self::int($c['height_mm']), 'tare_g' => $c['tare_g'] !== null ? (float) $c['tare_g'] : null,
            'max_load_kg' => $c['max_load_kg'] !== null ? (float) $c['max_load_kg'] : null, 'kind' => $c['kind'], 'active' => (bool) $c['active'],
        ], $this->repo->cartons()), fn($c) => $c['active'] || $c['id'] === $product['carton_id']));
        return [
            'cartons' => $cartons,
            'carton_id' => $product['carton_id'],
            'carton_source' => $product['carton_source'],
            'limits' => $this->limits($product),
            'defaults' => $this->limits(),
            'weeks' => $weeks,
            'weekly_cartons' => round($this->items->shippedCartons($finalProductId, $weeks * 7) / $weeks, 1),
            'places' => max(1, (int) $this->params->get('logistics.default_pallet_places', '33')),
        ];
    }

    private function present(array $c, array $products, array $limits, bool $full): array
    {
        $carton = ['length_mm' => self::int($c['length_mm']), 'width_mm' => self::int($c['width_mm']), 'height_mm' => self::int($c['height_mm']),
            'tare_g' => $c['tare_g'] !== null ? (float) $c['tare_g'] : null];
        $maxLoad = $c['max_load_kg'] !== null ? (float) $c['max_load_kg'] : null;
        $plans = [];
        foreach (array_keys(PalletLayout::PALLETS) as $pallet) {
            $plan = PalletLayout::plan($carton, $pallet, $limits, null);
            if ($plan === null) { $plans[$pallet] = null; continue; }
            $plans[$pallet] = $full ? $plan : ['per_layer' => $plan['per_layer'], 'layers' => $plan['max']['layers'], 'cartons' => $plan['max']['cartons'],
                'height_mm' => $plan['max']['height_mm'], 'floor_pct' => $plan['floor_pct']];
        }
        $rows = [];
        $issues = 0;
        $heaviest = null;
        foreach ($products as $p) {
            $ppc = (int) $p['ppc'];
            $net = $ppc > 0 && (float) $p['weight'] > 0 ? round($ppc * (float) $p['weight'] / 1000, 3) : null;
            $gross = $net !== null ? round($net + ($carton['tare_g'] ?? 0) / 1000, 3) : null;
            $over = $maxLoad !== null && $net !== null && $net > $maxLoad;
            $pallet = isset(PalletLayout::PALLETS[$p['pallet_type'] ?? '']) ? $p['pallet_type'] : 'eur';
            $plan = PalletLayout::plan($carton, $pallet, $this->limits($p), $net);
            $check = $plan ? PalletLayout::check($plan, (int) $p['cpl'], (int) $p['lpp'], (int) $p['cpp']) : [];
            $check = array_values(array_filter($check, fn($x) => $x['code'] !== 'ERP_EMPTY'));
            if ($over || $check) $issues++;
            if ($net !== null) $heaviest = max($heaviest ?? 0, $net);
            $rows[] = ['id' => (int) $p['id'], 'item_id' => $p['item_id'] !== null ? (int) $p['item_id'] : null, 'name' => $p['name'], 'ppc' => $ppc ?: null,
                'weight_g' => (float) $p['weight'] ?: null, 'net_kg' => $net, 'gross_kg' => $gross, 'over_load' => $over, 'source' => $p['carton_source'],
                'erp' => ['cpl' => (int) $p['cpl'], 'lpp' => (int) $p['lpp'], 'cpp' => (int) $p['cpp']], 'pallet' => $pallet,
                'best' => $plan ? ['per_layer' => $plan['per_layer'], 'layers' => $plan['max']['layers'], 'cartons' => $plan['max']['cartons']] : null,
                'issues' => $check];
        }
        return [
            'id' => (int) $c['id'], 'name' => trim((string) $c['name']), 'category' => $c['category'], 'kind' => $c['kind'],
            'price' => $c['price_per_unit'] !== null ? (float) $c['price_per_unit'] : null,
            'tare_g' => $carton['tare_g'], 'length_mm' => $carton['length_mm'], 'width_mm' => $carton['width_mm'], 'height_mm' => $carton['height_mm'],
            'volume_l' => $carton['length_mm'] && $carton['width_mm'] && $carton['height_mm'] ? round($carton['length_mm'] * $carton['width_mm'] * $carton['height_mm'] / 1e6, 1) : null,
            'max_load_kg' => $maxLoad, 'note' => $c['note'], 'active' => (bool) $c['active'],
            'plans' => $plans, 'products' => $rows, 'product_count' => count($rows), 'issue_count' => $issues, 'heaviest_kg' => $heaviest,
        ];
    }

    private static function number(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        $s = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], (string) $v);
        return is_numeric($s) ? (float) $s : -1.0;
    }

    private static function int(mixed $v): ?int
    {
        return $v === null || (int) $v <= 0 ? null : (int) $v;
    }
}
