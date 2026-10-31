<?php
declare(strict_types=1);

namespace App\Services\Client;

use App\Exceptions\CustomException;
use App\Repositories\Analysis\WdtSalesRepository;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\ClientSpaceRepository;
use App\Services\Document\CMRService;
use App\Services\Document\InvoiceService;
use App\Services\Document\OrderShippingDifferenceService;
use App\Services\Document\ProformaService;
use App\Services\Document\WDTService;
use App\Services\Document\WZService;
use App\Services\Utils\TCPDFService;

/**
 * Strona klienta (opcja w karcie klienta) :
 *  - Dokumenty : wszystkie dokumenty klienta w jednym miejscu, PDF pojedynczo albo kilka w jednym pliku ;
 *  - Cel obrotu (franczyza) : zakupy netto miesiąca × współczynnik sprzedaży = obrót brutto (TTC) do osiągnięcia w sklepie,
 *    według kategorii i produktów jak proforma. Współczynnik : kategorii → klienta → domyślny z Ustawień.
 *    Zakupy : faktury WDT (dostarczone, jedna definicja sprzedaży WDT) albo proformy (zamówione, bez anulowanych).
 */
final class ClientSpaceService
{
    public const DEFAULT_OPEN_DAYS = 26;
    public const FALLBACK_COEFFICIENT = 2.5;
    public const SOURCES = ['wdt', 'proforma'];
    public const PERIODS = ['3m' => 'Ostatnie 3 miesiące', '12m' => 'Ostatnie 12 miesięcy', 'all' => 'Wszystkie'];
    private const DOCUMENT_LIMIT = 300;

    public function __construct(
        private ClientSpaceRepository $repo,
        private ClientRepository $clients,
        private WdtSalesRepository $sales,
        private ProformaService $proformas,
        private WDTService $wdts,
        private InvoiceService $invoices,
        private WZService $wzs,
        private CMRService $cmrs,
        private OrderShippingDifferenceService $differences,
    ) {}

    public function client(int $customerId): array
    {
        $client = $this->clients->first(['id' => $customerId]);
        if (!$client) throw new CustomException('Nie znaleziono klienta.', 'errors', 404);
        $client['lang'] = $this->clients->communicationLang($customerId);
        return $client;
    }

    /** Opcje klienta z wartościami domyślnymi (do karty klienta i strony). */
    public function settings(int $customerId): array
    {
        $row = $this->repo->settings($customerId);
        $default = $this->repo->defaultCoefficient() ?? self::FALLBACK_COEFFICIENT;
        $coefficient = isset($row['coefficient']) && $row['coefficient'] !== null ? (float) $row['coefficient'] : null;
        $openDays = isset($row['open_days']) && $row['open_days'] !== null ? (int) $row['open_days'] : null;
        return [
            'docs' => (bool) ($row['docs_enabled'] ?? false),
            'goal' => (bool) ($row['goal_enabled'] ?? false),
            'coefficient' => $coefficient,
            'open_days' => $openDays,
            'default_coefficient' => $default,
            'effective_coefficient' => $coefficient ?? $default,
            'effective_open_days' => $openDays ?? self::DEFAULT_OPEN_DAYS,
        ];
    }

    /** Zapis opcji z formularza karty klienta (pola space_*). */
    public function saveSettings(int $customerId, array $input, ?string $author): void
    {
        $coefficient = self::decimal($input['space_coefficient'] ?? null);
        if ($coefficient !== null && ($coefficient <= 0 || $coefficient > 20)) throw new CustomException('Współczynnik sprzedaży : liczba od 0,01 do 20.', 'errors', 422);
        $openDays = trim((string) ($input['space_open_days'] ?? ''));
        if ($openDays !== '' && (!ctype_digit($openDays) || (int) $openDays < 1 || (int) $openDays > 31)) throw new CustomException('Dni otwarcia sklepu w miesiącu : od 1 do 31.', 'errors', 422);
        $this->repo->saveSettings($customerId, !empty($input['space_docs']), !empty($input['space_goal']), $coefficient, $openDays === '' ? null : (int) $openDays, $author);
    }

    /** @return list<int> */
    public function enabledCustomerIds(): array
    {
        return $this->repo->enabledCustomerIds();
    }

    /** Dokumenty klienta : liczby według typu + lista z filtrami (typ, okres, numer). */
    public function documents(int $customerId, array $filters): array
    {
        $type = isset(ClientSpaceRepository::DOCUMENT_TYPES[$filters['type'] ?? '']) ? $filters['type'] : null;
        $period = isset(self::PERIODS[$filters['period'] ?? '']) ? $filters['period'] : '3m';
        $search = trim((string) ($filters['q'] ?? ''));
        $from = match ($period) { '3m' => date('Y-m-d', strtotime('-3 months')), '12m' => date('Y-m-d', strtotime('-12 months')), default => null };
        return [
            'counts' => $this->repo->documentCounts($customerId),
            'rows' => $this->repo->documents($customerId, $type, $from, mb_substr($search, 0, 40), self::DOCUMENT_LIMIT),
            'limit' => self::DOCUMENT_LIMIT,
            'filters' => ['type' => $type ?? '', 'period' => $period, 'q' => $search],
            'types' => ClientSpaceRepository::DOCUMENT_TYPES,
            'periods' => self::PERIODS,
        ];
    }

    /**
     * Kilka dokumentów klienta w jednym PDF (kolejność wyboru). $docs : ['wdt:12', 'proforma:5', …].
     * @return array{filename: string, file_string: string}
     */
    public function documentsPdf(int $customerId, array $docs): array
    {
        $client = $this->client($customerId);
        $selected = [];
        foreach ($docs as $doc) {
            if (!preg_match('/^(proforma|wdt|invoice|wz|cmr|difference):(\d+)$/', (string) $doc, $m)) continue;
            if (!$this->repo->documentBelongs($m[1], (int) $m[2], $customerId)) throw new CustomException('Dokument nie należy do tego klienta.', 'errors', 403);
            $selected[] = [$m[1], (int) $m[2]];
        }
        if (!$selected) throw new CustomException('Zaznacz co najmniej jeden dokument.', 'errors', 422);
        if (count($selected) > 40) throw new CustomException('Najwyżej 40 dokumentów w jednym pliku.', 'errors', 422);
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        foreach ($selected as [$type, $id]) {
            $service = match ($type) {
                'proforma' => $this->proformas, 'wdt' => $this->wdts, 'invoice' => $this->invoices,
                'wz' => $this->wzs, 'cmr' => $this->cmrs, 'difference' => $this->differences,
            };
            $service->buildDocumentFromId($pdf, $id);
        }
        $name = preg_replace('/[^A-Za-z0-9\-]+/', '_', (string) ($client['company'] ?? 'klient'));
        return ['filename' => 'DOKUMENTY_' . trim($name, '_') . '_' . date('Y-m-d') . '.pdf', 'file_string' => $pdf->Output('', 'S')];
    }

    /** Miesiące do wyboru (ostatnie 13, najnowszy pierwszy) i domyślny : ostatni pełny miesiąc. */
    public function months(): array
    {
        $months = [];
        for ($i = 0; $i < 13; $i++) $months[] = date('Y-m', strtotime(date('Y-m-01') . " -{$i} months"));
        return $months;
    }

    public function defaultMonth(): string
    {
        return date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    }

    /**
     * Cel obrotu miesiąca : kategorie (współczynnik, źródło współczynnika, poza celem) z produktami, sumy, N-1 i cele dzienne.
     */
    public function goal(int $customerId, string $month, string $source): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = $this->defaultMonth();
        if (!in_array($source, self::SOURCES, true)) $source = 'wdt';
        $settings = $this->settings($customerId);
        $coefficients = $this->coefficientMap();
        [$from, $to] = WdtSalesRepository::monthBounds($month, $month);
        $categories = $this->categoriesFor($this->purchases($customerId, $source, $from, $to), $coefficients, $settings['effective_coefficient']);

        $monthN1 = date('Y-m', strtotime($month . '-01 -1 year'));
        [$fromN1, $toN1] = WdtSalesRepository::monthBounds($monthN1, $monthN1);
        $n1 = $this->totals($this->categoriesFor($this->purchases($customerId, $source, $fromN1, $toN1), $coefficients, $settings['effective_coefficient']));

        $totals = $this->totals($categories);
        $daysInMonth = (int) date('t', strtotime($month . '-01'));
        $openDays = $settings['effective_open_days'];
        return [
            'month' => $month,
            'month_n1' => $monthN1,
            'source' => $source,
            'categories' => $categories,
            'totals' => $totals,
            'n1' => $n1,
            'n1_change_pct' => $n1['target'] > 0 ? ($totals['target'] / $n1['target'] - 1) * 100 : null,
            'per_day' => $openDays > 0 ? $totals['target'] / $openDays : 0.0,
            'per_week' => $totals['target'] / ($daysInMonth / 7),
            'open_days' => $openDays,
            'documents' => $source === 'wdt' ? (int) ($this->sales->sales($from, $to, ['customer_id' => $customerId])[0]['documents'] ?? 0) : null,
            'settings' => $settings,
        ];
    }

    /** Współczynniki kategorii do edycji (wszystkie kategorie ; najpierw te z aktywnymi produktami). */
    public function categoryCoefficients(): array
    {
        $rows = $this->repo->categoryCoefficients();
        usort($rows, fn($a, $b) => [(int) ($b['active_products'] > 0), $a['name']] <=> [(int) ($a['active_products'] > 0), $b['name']]);
        return $rows;
    }

    /** Zapis współczynników kategorii : [{id, coefficient (puste = współczynnik klienta), excluded}]. */
    public function saveCategoryCoefficients(array $items, ?string $author): int
    {
        $known = array_flip(array_map('intval', array_column($this->repo->categoryCoefficients(), 'id')));
        $saved = 0;
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if (!isset($known[$id])) continue;
            $coefficient = self::decimal($item['coefficient'] ?? null);
            if ($coefficient !== null && ($coefficient <= 0 || $coefficient > 20)) throw new CustomException('Współczynnik kategorii : liczba od 0,01 do 20.', 'errors', 422);
            $this->repo->saveCategoryCoefficient($id, $coefficient, !empty($item['excluded']), $author);
            $saved++;
        }
        return $saved;
    }

    private function purchases(int $customerId, string $source, string $from, string $to): array
    {
        return $source === 'proforma'
            ? $this->repo->proformaPurchases($customerId, $from, $to)
            : $this->sales->sales($from, $to, ['customer_id' => $customerId], ['category', 'product']);
    }

    /** id kategorii => [coefficient|null, excluded] */
    private function coefficientMap(): array
    {
        $map = [];
        foreach ($this->repo->categoryCoefficients() as $row) {
            $map[(int) $row['id']] = [$row['coefficient'] !== null ? (float) $row['coefficient'] : null, (bool) $row['excluded']];
        }
        return $map;
    }

    private function categoriesFor(array $lines, array $coefficients, float $clientCoefficient): array
    {
        $categories = [];
        foreach ($lines as $line) {
            $catId = $line['category_id'] !== null ? (int) $line['category_id'] : 0;
            if (!isset($categories[$catId])) {
                [$catCoefficient, $excluded] = $coefficients[$catId] ?? [null, false];
                $categories[$catId] = [
                    'id' => $catId, 'name' => (string) $line['category_name'], 'excluded' => $excluded,
                    'coefficient' => $excluded ? 0.0 : ($catCoefficient ?? $clientCoefficient),
                    'coefficient_source' => $excluded ? 'excluded' : ($catCoefficient !== null ? 'category' : 'client'),
                    'items' => [], 'cartons' => 0.0, 'pieces' => 0.0, 'purchases' => 0.0, 'target' => 0.0,
                ];
            }
            $c = &$categories[$catId];
            $cartons = (float) $line['cartons'];
            $pieces = $cartons * (float) ($line['ppc'] ?? 0);
            $value = (float) $line['value_eur'];
            $c['items'][] = [
                'id' => (int) $line['product_id'], 'name' => (string) $line['product_name'], 'cartons' => $cartons, 'pieces' => $pieces,
                'purchases' => $value, 'piece_price' => $pieces > 0 ? $value / $pieces : null,
                'sale_price' => $pieces > 0 && !$c['excluded'] ? $value / $pieces * $c['coefficient'] : null,
                'target' => $value * $c['coefficient'],
            ];
            $c['cartons'] += $cartons;
            $c['pieces'] += $pieces;
            $c['purchases'] += $value;
            $c['target'] += $value * $c['coefficient'];
            unset($c);
        }
        foreach ($categories as &$c) usort($c['items'], fn($a, $b) => strcmp(self::sortKey($a['name']), self::sortKey($b['name'])));
        unset($c);
        uasort($categories, fn($a, $b) => strcmp(self::sortKey($a['name']), self::sortKey($b['name'])));
        return array_values($categories);
    }

    private function totals(array $categories): array
    {
        $t = ['purchases' => 0.0, 'purchases_sell' => 0.0, 'excluded' => 0.0, 'pieces' => 0.0, 'target' => 0.0, 'products' => 0];
        foreach ($categories as $c) {
            $t['purchases'] += $c['purchases'];
            $t['products'] += count($c['items']);
            if ($c['excluded']) { $t['excluded'] += $c['purchases']; continue; }
            $t['purchases_sell'] += $c['purchases'];
            $t['pieces'] += $c['pieces'];
            $t['target'] += $c['target'];
        }
        $t['coefficient'] = $t['purchases_sell'] > 0 ? $t['target'] / $t['purchases_sell'] : null;
        return $t;
    }

    /** Klucz sortowania bez akcentów i wielkości liter (« Pâtisserie » obok « Pain »). */
    private static function sortKey(string $name): string
    {
        return mb_strtolower(strtr($name, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Â' => 'A',
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']));
    }

    /** « 2,5 » / « 2.50 » → 2.5 ; puste → null. */
    private static function decimal($value): ?float
    {
        if ($value === null) return null;
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ($value === '') return null;
        if (!is_numeric($value)) throw new CustomException('Współczynnik : wpisz liczbę (np. 2,50).', 'errors', 422);
        return round((float) $value, 2);
    }
}
