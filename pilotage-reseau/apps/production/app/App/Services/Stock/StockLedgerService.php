<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Exceptions\CustomException;
use App\Repositories\Stock\FinalProduct\StockLedgerRepository;
use DateTimeImmutable;

/**
 * Ruchy magazynowe produktów gotowych : stan na początek okresu, przyjęcia (etykiety kartonów), wydania (kartony
 * wysłane, data palety, klient palety), stan na koniec — po produkcie, z wartością wg kosztu surowców z karty
 * produktu. Karta produktu : dzień po dniu z saldem i partie na magazynie. Rozbieżności : kartony przeterminowane na
 * magazynie, palety, które miały wyjechać, a stoją, daty niespójne. Liczone z kartonów — bez drugiego wpisywania.
 */
final class StockLedgerService
{
    private const MAX_DAYS = 400;
    /** Paleta stoi : jej data dostawy minęła o tyle dni, a kartony nie są wysłane. */
    private const PALLET_LATE_DAYS = 1;

    public function __construct(private StockLedgerRepository $repo) {}

    public function overview(?string $from, ?string $to, ?int $categoryId, string $query, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        [$from, $to] = $this->period($from, $to, $today);
        $needle = self::fold(trim($query));
        $rows = [];
        $totals = ['opening' => 0, 'produced' => 0, 'shipped' => 0, 'closing' => 0, 'value_opening' => 0.0, 'value_closing' => 0.0];
        foreach ($this->repo->summary($from, $to) as $r) {
            if ($categoryId !== null && (int) $r['id_category'] !== $categoryId) continue;
            if ($needle !== '' && !str_contains(self::fold((string) $r['name']), $needle)) continue;
            $row = self::row($r);
            $rows[] = $row;
            foreach (['opening', 'produced', 'shipped', 'closing'] as $k) $totals[$k] += $row[$k];
            $totals['value_opening'] += $row['value_opening'];
            $totals['value_closing'] += $row['value_closing'];
        }
        usort($rows, fn($a, $b) => ($b['produced'] + $b['shipped']) <=> ($a['produced'] + $a['shipped']) ?: strcmp($a['name'], $b['name']));
        $expired = $this->repo->expired($today->format('Y-m-d'));
        $waiting = $this->repo->waitingPallets($today->modify('-' . self::PALLET_LATE_DAYS . ' day')->format('Y-m-d'));
        return [
            'from' => $from, 'to' => $to,
            'presets' => self::presets($today),
            'category_id' => $categoryId, 'query' => trim($query),
            'categories' => $this->repo->categories(),
            'rows' => $rows,
            'totals' => $totals,
            'expired' => $expired,
            'expired_total' => array_sum(array_map(fn($e) => (int) $e['n'], $expired)),
            'waiting' => $waiting,
            'waiting_total' => array_sum(array_map(fn($w) => (int) $w['n'], $waiting)),
            'inconsistent' => $this->repo->inconsistent($from, $to),
        ];
    }

    /** Karta produktu : saldo dzień po dniu (przyjęcia z partiami, wydania z klientami) i partie na magazynie. */
    public function product(int $productId, ?string $from, ?string $to, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $product = $this->repo->product($productId) ?? throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
        [$from, $to] = $this->period($from, $to, $today);
        $opening = $this->repo->stockAt($productId, (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'));
        $days = [];
        foreach ($this->repo->inByDay($productId, $from, $to) as $r) {
            $days[$r['day']]['in'] = (int) $r['n'];
            $days[$r['day']]['batches'] = $r['batch_min'] === $r['batch_max'] ? (string) $r['batch_min'] : $r['batch_min'] . '–' . $r['batch_max'];
        }
        foreach ($this->repo->outByDay($productId, $from, $to) as $r) {
            $days[$r['day']]['out'] = ($days[$r['day']]['out'] ?? 0) + (int) $r['n'];
            $days[$r['day']]['customers'][] = ['name' => $r['customer'] ?: 'bez palety klienta', 'n' => (int) $r['n'], 'id' => $r['id_customer'] ? (int) $r['id_customer'] : null];
        }
        ksort($days);
        $balance = $opening;
        $lines = [];
        foreach ($days as $day => $d) {
            $in = $d['in'] ?? 0;
            $out = $d['out'] ?? 0;
            $balance += $in - $out;
            $lines[] = ['day' => $day, 'in' => $in, 'out' => $out, 'batches' => $d['batches'] ?? null, 'customers' => $d['customers'] ?? [], 'balance' => $balance];
        }
        $closing = $this->repo->stockAt($productId, $to);
        $todayStr = $today->format('Y-m-d');
        $batches = array_map(fn($b) => [
            'batch' => $b['batch_number'], 'expires' => $b['expiration_date'] > '2000-01-01' ? $b['expiration_date'] : null,
            'expired' => $b['expiration_date'] > '2000-01-01' && $b['expiration_date'] < $todayStr,
            'in_store' => (int) $b['in_store'], 'on_pallet' => (int) $b['on_pallet'], 'made_on' => $b['made_on'],
        ], $this->repo->batches($productId));
        return [
            'product' => ['id' => (int) $product['id'], 'name' => (string) $product['name'], 'category' => $product['category'], 'card_cost' => (float) $product['card_cost'], 'ppc' => (int) $product['ppc']],
            'from' => $from, 'to' => $to,
            'presets' => self::presets($today),
            'opening' => $opening,
            'closing' => $closing,
            'produced' => array_sum(array_column($lines, 'in')),
            'shipped' => array_sum(array_column($lines, 'out')),
            'lines' => array_reverse($lines),
            'batches' => $batches,
            'in_stock_now' => array_sum(array_map(fn($b) => $b['in_store'] + $b['on_pallet'], $batches)),
        ];
    }

    /** Tabela okresu do CSV (Excel : średnik, przecinek dziesiętny). */
    public function csv(array $overview): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Produkt', 'Kategoria', 'Stan początek', 'Przyjęcia', 'Wydania', 'Stan koniec', 'Koszt surowców / karton (PLN)', 'Wartość koniec (PLN)'], ';');
        foreach ($overview['rows'] as $r) {
            fputcsv($out, [$r['name'], $r['category'], $r['opening'], $r['produced'], $r['shipped'], $r['closing'],
                number_format($r['card_cost'], 2, ',', ''), number_format($r['value_closing'], 2, ',', '')], ';');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    private static function row(array $r): array
    {
        $cost = (float) $r['card_cost'];
        $opening = (int) $r['opening'];
        $produced = (int) $r['produced'];
        $shipped = (int) $r['shipped'];
        $closing = (int) $r['closing'];
        return [
            'id' => (int) $r['id_product'],
            'name' => (string) ($r['name'] ?? ('#' . $r['id_product'])),
            'category' => $r['category'],
            'card_cost' => $cost,
            'opening' => $opening, 'produced' => $produced, 'shipped' => $shipped, 'closing' => $closing,
            'delta' => $closing - $opening,
            // kartony wysłane przed etykietą (daty niespójne) : saldo nie zgadza się z ruchami
            'gap' => $closing - ($opening + $produced - $shipped),
            'value_opening' => $opening * $cost,
            'value_closing' => $closing * $cost,
        ];
    }

    /** @return array{0:string, 1:string} okres [od, do] ; domyślnie bieżący miesiąc do dziś. */
    private function period(?string $from, ?string $to, DateTimeImmutable $today): array
    {
        $valid = fn(?string $d) => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        $from = $valid($from) ? $from : $today->modify('first day of this month')->format('Y-m-d');
        $to = $valid($to) ? $to : $today->format('Y-m-d');
        if ($from > $to) [$from, $to] = [$to, $from];
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_DAYS) throw new CustomException('Okres najwyżej ' . self::MAX_DAYS . ' dni.', 'errors', 422);
        return [$from, $to];
    }

    private static function presets(DateTimeImmutable $today): array
    {
        $first = $today->modify('first day of this month');
        return [
            ['label' => 'Ten miesiąc', 'from' => $first->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            ['label' => 'Poprzedni miesiąc', 'from' => $first->modify('-1 month')->format('Y-m-d'), 'to' => $first->modify('-1 day')->format('Y-m-d')],
            ['label' => 'Ostatnie 7 dni', 'from' => $today->modify('-6 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            ['label' => 'Ten rok', 'from' => $today->format('Y') . '-01-01', 'to' => $today->format('Y-m-d')],
        ];
    }

    private static function fold(string $s): string
    {
        $s = mb_strtolower($s);
        return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'û' => 'u', 'ù' => 'u']);
    }
}
