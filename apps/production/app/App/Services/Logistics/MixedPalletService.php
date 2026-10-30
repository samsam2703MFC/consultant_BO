<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Domain\Logistics\MixedPallet;
use App\Domain\Logistics\PalletLayout;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\MixedPalletRepository;
use App\Services\Product\CartonService;
use App\Services\Utils\TCPDFService;

/**
 * Paleta mieszana [215] (Kartony i palety › Paleta mieszana, makieta 214) : resztki zamówienia klienta na dzień wysyłki
 * (kartony poza pełnymi paletami) układane pełnymi warstwami jedna na drugiej (MixedPallet).
 *  - propozycje dnia : klienci, u których połączenie resztek oszczędza miejsce w ciężarówce (palety klienta teraz / po) ;
 *  - edytor : produkty klienta i warstwy (+ / −, najwyżej zamówione), kontrole, widok z boku i z góry ;
 *  - zatwierdzenie : dzień wysyłki liczy paletę jako jedną (LogisticsService::palletize), kolejność na etykiecie PDF
 *    i na tablecie pakowania.
 * Produkt bez kartonu z wymiarami, kartonów na warstwę, wagi albo kartonów na paletę : nie wchodzi (powód na liście).
 */
final class MixedPalletService
{
    public function __construct(private LogisticsService $logistics, private MixedPalletRepository $repo, private CartonService $cartons)
    {
    }

    /**
     * Strona : dzień, klienci dnia, propozycje, edytor (klient z adresu, paleta zatwierdzona albo pierwsza propozycja).
     * $q : date, customer, pallet, items (« produkt:kartony,… »).
     */
    public function page(array $q): array
    {
        $date = (string) ($q['date'] ?? '') ?: $this->logistics->defaultDate();
        self::checkDate($date);
        $ctx = $this->context($date);
        $proposals = $this->proposals($ctx);
        $cid = isset($q['customer']) && (int) $q['customer'] > 0 ? (int) $q['customer'] : null;
        $pid = isset($q['pallet']) && (int) $q['pallet'] > 0 ? (int) $q['pallet'] : null;
        $items = self::items($q['items'] ?? null);
        if ($pid !== null && $cid === null) {
            foreach ($ctx['stacks'] as $c => $list) foreach ($list as $s) if ($s['id'] === $pid) $cid = $c;
        }
        if ($cid === null && $pid === null && $proposals) {
            $first = array_values(array_filter($proposals, fn($p) => !$p['confirmed']))[0] ?? $proposals[0];
            [$cid, $pid, $items] = [$first['customer_id'], $first['pallet_id'], $first['confirmed'] ? null : $first['items']];
        }
        return [
            'date' => $date, 'dates' => $this->logistics->dates(),
            'customers' => array_values(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'town' => $c['town']], $ctx['customers'])),
            'proposals' => $proposals,
            'editor' => $cid !== null ? $this->editor($ctx, $cid, $pid, $items) : null,
            'limits' => $ctx['limits'],
        ];
    }

    /** Edytor po zmianie (warstwy, dodany produkt) — bez zapisu. */
    public function preview(array $q): array
    {
        $date = (string) ($q['date'] ?? '');
        self::checkDate($date);
        $cid = (int) ($q['customer'] ?? 0);
        $pid = isset($q['pallet']) && (int) $q['pallet'] > 0 ? (int) $q['pallet'] : null;
        return $this->editor($this->context($date), $cid, $pid, self::items($q['items'] ?? null) ?? []);
    }

    /**
     * Zatwierdzenie palety (nowa albo poprawiona) : co najmniej dwa produkty klienta, kartony najwyżej zamówione,
     * wszystkie kontrole bez błędu ; serwer liczy od nowa.
     */
    public function confirm(array $input, ?int $userId): array
    {
        $date = (string) ($input['date'] ?? '');
        self::checkDate($date);
        $cid = (int) ($input['customer_id'] ?? 0);
        $palletId = isset($input['pallet_id']) && (int) $input['pallet_id'] > 0 ? (int) $input['pallet_id'] : null;
        $items = [];
        foreach ((array) ($input['items'] ?? []) as $it) {
            $n = (int) ($it['cartons'] ?? 0);
            if ($n > 0) $items[(int) ($it['product_id'] ?? 0)] = $n;
        }
        $ctx = $this->context($date);
        $ed = $this->editor($ctx, $cid, $palletId, $items);
        $byId = array_column($ed['groups'], null, 'product_id');
        foreach ($items as $pid => $n) {
            $g = $byId[$pid] ?? null;
            if ($g === null) throw new CustomException('Produkt spoza zamówienia klienta na ten dzień albo bez danych kartonu.', 'errors', 422);
            if ($g['cartons'] !== $n) throw new CustomException("{$g['name']} : najwyżej {$g['available']} kart. (zamówione).", 'errors', 422);
        }
        if (count($ed['groups']) < 2) throw new CustomException('Paleta mieszana : co najmniej dwa produkty klienta.', 'errors', 422);
        foreach ($ed['checks'] as $c) {
            if ($c['status'] === 'error') throw new CustomException('Paleta nie przechodzi kontroli : ' . $c['text'] . '.', 'errors', 422);
        }
        $id = $this->repo->save($palletId, $date, $cid, 'eur', $ed['height_mm'], $ed['kg'],
            array_map(fn($g) => ['product_id' => $g['product_id'], 'cartons' => $g['cartons'], 'layers' => $g['layers'], 'per_layer' => $g['per_layer']], $ed['groups']), $userId);
        return $this->page(['date' => $date, 'customer' => $cid, 'pallet' => $id]);
    }

    public function delete(int $id): array
    {
        $h = $this->repo->head($id) ?? throw new CustomException('Nie znaleziono palety mieszanej.', 'errors', 404);
        $this->repo->delete($id);
        return $this->page(['date' => $h['ship_date'], 'customer' => $h['id_customer']]);
    }

    /**
     * Etykieta palety (PDF) : warstwy od dołu (kolejność układania), wysokość, waga, kontrole — paleta zatwierdzona ($q pallet)
     * albo projekt z edytora (date, customer, items). @return array{filename: string, file_string: string}
     */
    public function label(array $q): array
    {
        $pid = isset($q['pallet']) && (int) $q['pallet'] > 0 ? (int) $q['pallet'] : null;
        if ($pid !== null) {
            $h = $this->repo->head($pid) ?? throw new CustomException('Nie znaleziono palety mieszanej.', 'errors', 404);
            [$date, $cid, $items] = [$h['ship_date'], $h['id_customer'], null];
        } else {
            [$date, $cid, $items] = [(string) ($q['date'] ?? ''), (int) ($q['customer'] ?? 0), self::items($q['items'] ?? null) ?? []];
            self::checkDate($date);
        }
        $ed = $this->editor($this->context($date), $cid, $pid, $items);
        if (!$ed['groups']) throw new CustomException('Paleta bez produktów.', 'errors', 422);
        return ['filename' => 'PALETA_MIESZANA_' . $date . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $ed['customer']['name']) . '.pdf', 'file_string' => self::pdf($date, $ed)];
    }

    // --- Obliczenia ----------------------------------------------------------------------------------

    /** Dzień : klienci z pozycjami, dane palety produktów, limity logistyki, palety zatwierdzone. */
    private function context(string $date): array
    {
        $customers = $this->logistics->customerLines($date);
        $ids = [];
        foreach ($customers as $c) foreach ($c['lines'] as $l) $ids[(int) $l['product_id']] = true;
        return ['date' => $date, 'customers' => $customers, 'data' => $this->cartons->palletData(array_keys($ids)),
            'limits' => $this->cartons->limits(), 'stacks' => $this->logistics->mixedPallets($date)];
    }

    /** Propozycje dnia (oszczędzają miejsce) i palety zatwierdzone ; najpierw największa oszczędność. */
    private function proposals(array $ctx): array
    {
        $rows = [];
        foreach (array_keys($ctx['customers']) as $cid) {
            $all = $ctx['stacks'][$cid] ?? [];
            $cl = $this->client($ctx, $cid);
            foreach ($all as $s) {
                $others = array_values(array_filter($all, fn($o) => $o['id'] !== $s['id']));
                $rows[] = ['customer_id' => $cid, 'customer' => $cl['name'], 'pallet_id' => $s['id'], 'confirmed' => true,
                    'products' => array_map(fn($it) => ['name' => $it['product'], 'layers' => $it['layers'], 'cartons' => $it['cartons']], $s['items']),
                    'now' => $this->places($cl['lines'], $others), 'after' => $this->places($cl['lines'], $all),
                    'height_mm' => $s['height_mm'], 'kg' => $s['kg'], 'items' => array_column($s['items'], 'cartons', 'product_id'), 'who' => $s['who'], 'at' => $s['confirmed_at']];
            }
            $candidates = [];
            foreach ($cl['products'] as $p) if ($p['reason'] === null && $p['leftover'] > 0) $candidates[] = ['cartons' => $p['leftover']] + $p;
            if (count($candidates) < 2) continue;
            $now = $this->places($cl['lines'], $all);
            foreach (MixedPallet::propose($candidates, $ctx['limits']) as $bin) {
                if (count($bin) < 2) continue;
                $after = $this->places($cl['lines'], [...$all, self::asStack($bin)]);
                if ($after >= $now) continue;
                $st = MixedPallet::stack($bin, $ctx['limits']);
                $rows[] = ['customer_id' => $cid, 'customer' => $cl['name'], 'pallet_id' => null, 'confirmed' => false,
                    'products' => array_map(fn($g) => ['name' => $g['name'], 'layers' => $g['layers'], 'cartons' => $g['cartons']], $st['groups']),
                    'now' => $now, 'after' => $after, 'height_mm' => $st['height_mm'], 'kg' => $st['kg'],
                    'items' => array_column($st['groups'], 'cartons', 'product_id'), 'who' => null, 'at' => null];
            }
        }
        usort($rows, fn($a, $b) => [$a['confirmed'], $b['now'] - $b['after'], $a['customer']] <=> [$b['confirmed'], $a['now'] - $a['after'], $b['customer']]);
        return $rows;
    }

    /**
     * Edytor palety klienta : $items produkt => kartony (null = zapisana paleta albo pierwsza propozycja klienta).
     * Kartony produktu najwyżej « dostępne » (zamówione − na innych zatwierdzonych paletach mieszanych klienta).
     */
    private function editor(array $ctx, int $cid, ?int $palletId, ?array $items): array
    {
        $own = null;
        foreach ($ctx['stacks'][$cid] ?? [] as $s) if ($s['id'] === $palletId) $own = $s;
        if ($palletId !== null && $own === null) throw new CustomException('Nie znaleziono palety mieszanej klienta na ten dzień.', 'errors', 404);
        $cl = $this->client($ctx, $cid, $palletId);
        if ($items === null && $own !== null) $items = array_column($own['items'], 'cartons', 'product_id');
        if ($items === null) {
            $items = [];
            foreach ($this->proposals(['customers' => [$cid => $ctx['customers'][$cid]]] + $ctx) as $p) {
                if (!$p['confirmed']) { $items = $p['items']; break; }
            }
        }
        $chosen = [];
        foreach ($items as $pid => $n) {
            $p = $cl['products'][(int) $pid] ?? null;
            if ($p === null || $p['reason'] !== null) continue;
            $n = max(0, min((int) $n, $p['available']));
            if ($n > 0) $chosen[] = ['cartons' => $n] + $p;
        }
        $st = MixedPallet::stack($chosen, $ctx['limits']);
        $now = $this->places($cl['lines'], $cl['others']);
        $after = $chosen ? $this->places($cl['lines'], [...$cl['others'], self::asStack($chosen)]) : $now;
        $overhang = (int) ($ctx['limits']['overhang_mm'] ?? 0);
        $groups = array_map(fn($g) => array_intersect_key($g, array_flip(['product_id', 'name', 'carton', 'length_mm', 'width_mm', 'height_mm', 'per_layer', 'carton_kg',
            'cartons', 'layers', 'partial', 'floor_pct', 'layer_kg', 'kg', 'height', 'position', 'z_mm', 'load_kg', 'own_load_kg', 'ordered', 'available', 'full_pallets', 'leftover', 'cpp']))
            + ['rects' => array_slice(PalletLayout::layer($g['length_mm'], $g['width_mm'], 1200, 800, $overhang), 0, $g['per_layer'])], $st['groups']);
        $inside = array_flip(array_column($groups, 'product_id'));
        $options = [];
        foreach ($cl['products'] as $p) {
            if (isset($inside[$p['product_id']])) continue;
            $options[] = ['product_id' => $p['product_id'], 'name' => $p['name'], 'carton' => $p['carton'], 'per_layer' => $p['per_layer'],
                'available' => $p['available'], 'leftover' => $p['leftover'], 'reason' => $p['reason'] ?? ($p['available'] <= 0 ? 'już na innej palecie mieszanej' : null)];
        }
        usort($options, fn($a, $b) => [$a['reason'] !== null, -$a['leftover'], $a['name']] <=> [$b['reason'] !== null, -$b['leftover'], $b['name']]);
        return [
            'customer' => ['id' => $cid, 'name' => $cl['name'], 'town' => $cl['town']],
            'pallet_id' => $palletId, 'confirmed' => $own ? ['at' => $own['confirmed_at'], 'who' => $own['who']] : null,
            'groups' => $groups, 'height_mm' => $st['height_mm'], 'kg' => $st['kg'], 'cartons' => $st['cartons'], 'layers' => $st['layers'],
            'max_height_mm' => $st['max_height_mm'], 'max_kg' => $st['max_kg'], 'base_mm' => PalletLayout::PALLETS['eur']['base_mm'],
            'checks' => array_map(fn($c) => $c + ['text' => self::text($c)], $st['checks']),
            'ok' => $st['ok'] && count($groups) >= 2,
            'places' => ['now' => $now, 'after' => $after],
            'options' => $options,
            'others' => count($cl['others']),
        ];
    }

    /** Klient dnia : produkty (dane palety), kartony dostępne (zamówione − inne zatwierdzone palety mieszane), resztki. */
    private function client(array $ctx, int $cid, ?int $except = null): array
    {
        $c = $ctx['customers'][$cid] ?? throw new CustomException('Klient nie ma zamówień na ten dzień wysyłki.', 'errors', 404);
        $others = array_values(array_filter($ctx['stacks'][$cid] ?? [], fn($s) => $s['id'] !== $except));
        $on = [];
        foreach ($others as $s) foreach ($s['items'] as $it) $on[$it['product_id']] = ($on[$it['product_id']] ?? 0) + $it['cartons'];
        $products = [];
        foreach ($c['lines'] as $l) {
            $p = self::product($l, $ctx['data'][(int) $l['product_id']] ?? []);
            $p['available'] = max(0, $p['ordered'] - ($on[$p['product_id']] ?? 0));
            $p['full_pallets'] = $p['cpp'] > 0 ? intdiv($p['available'], $p['cpp']) : 0;
            $p['leftover'] = $p['available'] - $p['full_pallets'] * $p['cpp'];
            $products[$p['product_id']] = $p;
        }
        return ['name' => $c['name'], 'town' => $c['town'], 'lines' => $c['lines'], 'others' => $others, 'products' => $products];
    }

    /** Produkt klienta jako warstwy palety : karton (wymiary, na warstwę, waga, limity) albo powód braku. */
    private static function product(array $line, array $d): array
    {
        $ppc = (int) ($d['ppc'] ?? 0);
        $g = (float) ($d['weight_g'] ?? 0);
        $cpl = (int) ($d['cpl'] ?? 0);
        $cpp = (int) $line['cpp'];
        $reason = match (true) {
            empty($d['carton_id']) => 'brak kartonu z katalogu',
            !($d['length_mm'] ?? 0) || !($d['width_mm'] ?? 0) || !($d['height_mm'] ?? 0) => 'karton bez wymiarów',
            $cpl <= 0 => 'brak kartonów na warstwę',
            $ppc <= 0 || $g <= 0 => 'brak wagi (sztuki × gramatura)',
            $cpp <= 0 => 'brak kartonów na paletę',
            default => null,
        };
        return [
            'product_id' => (int) $line['product_id'], 'name' => (string) $line['product'], 'carton' => $d['carton'] ?? null,
            'length_mm' => (int) ($d['length_mm'] ?? 0), 'width_mm' => (int) ($d['width_mm'] ?? 0), 'height_mm' => (int) ($d['height_mm'] ?? 0),
            'per_layer' => $cpl, 'carton_kg' => round(($ppc * $g + (float) ($d['tare_g'] ?? 0)) / 1000, 3),
            'lpp' => $cpp > 0 && $cpl > 0 ? max(1, (int) round($cpp / $cpl)) : null,
            'max_height_mm' => $d['max_height_mm'] ?? null, 'max_kg' => $d['max_kg'] ?? null,
            'ordered' => (int) round((float) $line['cartons']), 'cpp' => max(0, $cpp), 'reason' => $reason,
        ];
    }

    /** Palety klienta (miejsca w ciężarówce) z paletami mieszanymi — jak dzień wysyłki. */
    private function places(array $lines, array $stacks): int
    {
        return (int) $this->logistics->palletize($lines, $stacks)['pallets'];
    }

    private static function asStack(array $items): array
    {
        return ['id' => 0, 'items' => array_map(fn($it) => ['product_id' => $it['product_id'], 'cartons' => $it['cartons']], $items)];
    }

    /** « 12:4,13:36 » albo [produkt => kartony] → [produkt => kartony] ; brak = null. */
    private static function items(mixed $v): ?array
    {
        if ($v === null || $v === '') return null;
        $out = [];
        if (is_array($v)) {
            foreach ($v as $k => $n) $out[(int) $k] = (int) $n;
        } else {
            foreach (explode(',', (string) $v) as $pair) {
                [$pid, $n] = array_pad(explode(':', $pair, 2), 2, '0');
                if ((int) $pid > 0) $out[(int) $pid] = (int) $n;
            }
        }
        return $out;
    }

    private static function checkDate(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
    }

    // --- Teksty i etykieta -----------------------------------------------------------------------------

    /** Liczba po polsku jak na stronie (pl-PL) : spacja tysięcy dopiero od 10 000. */
    private static function n(float|int $v, int $d = 0): string
    {
        return number_format((float) $v, $d, ',', abs((float) $v) >= 10000 ? ' ' : '');
    }

    /** Tekst kontroli (PL) : strona, komunikat zatwierdzenia, etykieta PDF. */
    public static function text(array $c): string
    {
        $p = $c['params'];
        return match ($c['code']) {
            'height' => $c['status'] === 'ok'
                ? 'Wysokość ' . self::n($p['height']) . ' mm ≤ maks. ' . self::n($p['max']) . ' mm (najniższy limit kartonów na palecie)'
                : 'Wysokość ' . self::n($p['height']) . ' mm > maks. ' . self::n($p['max']) . ' mm (najniższy limit kartonów na palecie) — zdejmij warstwę',
            'truck' => 'Wysokość ' . self::n($p['height']) . ' mm > wnętrze ciężarówki ' . self::n($p['max']) . ' mm',
            'weight' => $c['status'] === 'ok'
                ? 'Waga ' . self::n($p['kg']) . ' kg ≤ maks. ' . self::n($p['max']) . ' kg ; ładunek na najniższy karton : ' . self::n($p['load'], 1) . ' kg'
                : 'Waga ' . self::n($p['kg']) . ' kg > maks. ' . self::n($p['max']) . ' kg — zdejmij warstwę',
            'load' => 'Ładunek na karton ' . $p['name'] . ' : ' . self::n($p['load'], 1) . ' kg — więcej niż na palecie samego produktu (' . self::n($p['own'], 1) . ' kg)',
            'order' => isset($p['kg'])
                ? 'Cięższa warstwa na dole : ' . implode(' pod ', array_map(fn($v) => self::n($v, 1) . ' kg / warstwę', $p['kg']))
                : 'Cięższa warstwa wyżej : ' . $p['upper'] . ' (' . self::n($p['upper_kg'], 1) . ' kg / warstwę) na ' . $p['lower'] . ' (' . self::n($p['lower_kg'], 1)
                    . ' kg / warstwę) — mniejsze kartony i niepełna warstwa zawsze wyżej',
            'floor_same' => 'Ta sama podłoga (' . $p['base'] . ') : warstwa wyżej stoi na pełnej warstwie — ' . self::n($p['pct']) . ' % palety',
            'floor_rule' => 'Gdy kartony mają inną podłogę (np. 390 × 195 na 590 × 390) : dozwolone tylko pełne warstwy ≥ ' . self::n($p['pct']) . ' % palety, mniejsze kartony zawsze wyżej',
            'floor_diff' => 'Inna podłoga wyżej : pod spodem pełne warstwy ≥ ' . self::n($p['min']) . ' % palety (najmniej ' . self::n($p['pct']) . ' %), mniejsze kartony wyżej',
            'floor_small' => $p['name'] . ' : warstwa pokrywa ' . self::n($p['pct']) . ' % palety — inna podłoga wyżej tylko na pełnych warstwach ≥ ' . self::n($p['min']) . ' %',
            'partial_top' => 'Niepełna warstwa tylko na górze : ' . $p['name'] . ' (' . $p['n'] . ' z ' . $p['per'] . ' kartonów)',
            'partial' => 'Niepełna warstwa ' . $p['name'] . ' (' . $p['n'] . ' z ' . $p['per'] . ') pod innym produktem — tylko jedna niepełna warstwa, na samej górze',
            default => $c['code'],
        };
    }

    /** Etykieta A4 : nagłówek, warstwy od dołu (tabela + rysunek z boku), kontrole ; projekt oznaczony. */
    private static function pdf(string $date, array $ed): string
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $client = $ed['customer']['name'];
        $pdf->brandPage('Etykieta palety mieszanej', ['ref' => $client . ' · ' . date('d.m.Y', strtotime($date))]);
        $pdf->brandHeader('Paleta mieszana', [
            ['Klient', $client], ['Dzień wysyłki', date('d.m.Y', strtotime($date))], ['Paleta', 'EUR 1200 × 800'],
            ['Wysokość', self::n($ed['height_mm']) . ' mm'], ['Waga', self::n($ed['kg']) . ' kg'], ['Kartony', self::n($ed['cartons'])],
        ], $ed['confirmed'] ? 'Zatwierdzona ' . date('d.m.Y H:i', strtotime($ed['confirmed']['at'])) . ($ed['confirmed']['who'] ? ' · ' . $ed['confirmed']['who'] : '') : 'Projekt — paleta jeszcze niezatwierdzona');
        $pdf->brandSection('Układanie od dołu', 'Pełne warstwy jednego produktu jedna na drugiej : cięższa warstwa na dole, mniejsze kartony wyżej.');
        $rows = [];
        foreach ($ed['groups'] as $g) {
            $rows[] = [(string) $g['position'], $g['name'], (string) ($g['carton'] ?? '—'), $g['layers'] . ($g['partial'] ? ' (ostatnia ' . $g['partial'] . ' z ' . $g['per_layer'] . ')' : ''),
                self::n($g['per_layer']), self::n($g['cartons']), self::n($g['kg'], 1) . ' kg'];
        }
        $pdf->brandTable([
            ['label' => 'Od dołu', 'w' => 16, 'align' => 'C'], ['label' => 'Produkt', 'w' => 58], ['label' => 'Karton', 'w' => 40],
            ['label' => 'Warstwy', 'w' => 24, 'align' => 'R'], ['label' => 'Na warstwę', 'w' => 16, 'align' => 'R'],
            ['label' => 'Kartony', 'w' => 14, 'align' => 'R'], ['label' => 'Waga', 'w' => 18, 'align' => 'R'],
        ], $rows, ['font' => 9]);

        // Widok z boku : paleta, warstwy produktów (od dołu), linia maks. wysokości.
        $pdf->brandSection('Widok z boku');
        $top = $pdf->GetY() + 2;
        $scale = 70 / max($ed['max_height_mm'], $ed['height_mm']);
        $x = 30.0;
        $w = 80.0;
        $bottom = $top + 74;
        $pdf->SetFillColor(184, 176, 164);
        $pdf->Rect($x, $bottom - $ed['base_mm'] * $scale, $w, $ed['base_mm'] * $scale, 'F');
        $colors = [[201, 163, 107], [230, 207, 159], [185, 199, 163], [169, 191, 214], [217, 167, 160], [205, 184, 222]];
        $y = $bottom - $ed['base_mm'] * $scale;
        $pdf->SetDrawColor(138, 106, 58);
        foreach ($ed['groups'] as $i => $g) {
            $start = $y;
            $pdf->SetFillColor(...$colors[$i % count($colors)]);
            for ($k = 0; $k < $g['layers']; $k++) {
                $y -= $g['height_mm'] * $scale;
                $pdf->Rect($x, $y, $w, $g['height_mm'] * $scale, 'DF');
            }
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetXY($x + $w + 4, ($start + $y) / 2 - 2.5);
            $pdf->Cell(80, 5, $g['position'] . '. ' . $g['name'] . ' · ' . $g['layers'] . ' w. · ' . self::n($g['height']) . ' mm', 0, 0, 'L', false, '', 1);
        }
        $ymax = $bottom - $ed['max_height_mm'] * $scale;
        $pdf->SetDrawColor(154, 23, 48);
        $pdf->SetLineStyle(['width' => 0.4, 'dash' => '2,1.5', 'color' => [154, 23, 48]]);
        $pdf->Line($x - 8, $ymax, $x + $w + 8, $ymax);
        $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0]);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->SetTextColor(154, 23, 48);
        $pdf->SetXY($x - 18, $ymax - 4.5);
        $pdf->Cell(40, 4, 'maks. ' . self::n($ed['max_height_mm']) . ' mm', 0, 0, 'L');
        $pdf->SetTextColor(34, 34, 34);
        $pdf->SetY($bottom + 4);

        $pdf->brandSection('Kontrole');
        foreach ($ed['checks'] as $c) {
            $mark = ['ok' => '✓', 'warn' => '⚠', 'info' => 'ℹ', 'error' => '✕'][$c['status']] ?? '•';
            $pdf->brandText($mark . ' ' . $c['text'], ['size' => 8.5, 'after' => 1]);
        }
        return $pdf->Output('', 'S');
    }
}
