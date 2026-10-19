<?php

namespace App\Services\Search;

use App\Repositories\Search\GlobalSearchRepository;

/**
 * Wyszukiwarka z górnego paska (GET /ajax/search?q=): zamówienia, klienci, produkty,
 * surowce, proformy, faktury i WDT. Do 5 trafień na typ, minimum 2 znaki.
 * Adresy są względne (bez ROOT) — strona dokleja katalog aplikacji.
 */
class GlobalSearchService
{
    public const MIN_LENGTH = 2;
    public const PER_TYPE = 5;
    private const MAX_LENGTH = 100;

    private const PROFORMA_STATUS = ['issued' => 'nieopłacona', 'paid' => 'opłacona', 'cancelled' => 'anulowana'];

    public function __construct(private GlobalSearchRepository $repository)
    {
    }

    /** Oczyszczone zapytanie albo null, gdy za krótkie. */
    public function normalize(string $q): ?string
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        $q = mb_substr($q, 0, self::MAX_LENGTH);
        return mb_strlen($q) >= self::MIN_LENGTH ? $q : null;
    }

    public function search(string $q): array
    {
        // „#1484” → numer zamówienia 1484
        $term = ltrim($q, '#');
        if (mb_strlen($term) < self::MIN_LENGTH && !ctype_digit($term)) {
            $term = $q;
        }
        $pattern = '%' . addcslashes($term, '\\%_') . '%';
        $exact = ctype_digit($term) ? $term : '';
        $n = self::PER_TYPE;

        $groups = [
            ['type' => 'orders', 'label' => 'Zamówienia', 'icon' => 'bi-cart', 'items' => array_map(fn($r) => [
                'title' => 'Zamówienie #' . $r['id'] . ($r['reference'] !== null && $r['reference'] !== '' ? ' · ' . $r['reference'] : ''),
                'sub'   => trim($r['company'] . ' · wysyłka ' . $this->date($r['expected_shipping_date']) . ($r['status_name'] ? ' · ' . $r['status_name'] : ''), ' ·'),
                'url'   => '/client-orders/' . (int) $r['id'] . '/edit',
            ], $this->repository->orders($pattern, $exact, $n))],

            ['type' => 'clients', 'label' => 'Klienci', 'icon' => 'bi-people', 'items' => array_map(fn($r) => [
                'title' => $r['company'],
                'sub'   => implode(' · ', array_filter([
                    $r['tax_number'] ? 'NIP ' . $r['tax_number'] : null,
                    $r['franchise'] ? 'franczyza' . ($r['franchisee_code'] ? ' ' . $r['franchisee_code'] : '') : null,
                    (string) $r['active'] === '1' ? null : 'nieaktywny',
                ])),
                'url'   => '/clients/' . (int) $r['id'] . '/edit',
            ], $this->repository->clients($pattern, $n))],

            ['type' => 'products', 'label' => 'Produkty', 'icon' => 'bi-box-seam', 'items' => array_map(fn($r) => [
                'title' => $r['name'],
                'sub'   => 'ID ' . $r['id'] . ((string) $r['active'] === '1' ? '' : ' · nieaktywny') . ($r['id_recipe'] ? '' : ' · bez receptury'),
                'url'   => '/products/' . (int) $r['id'] . '/edit',
            ], $this->repository->products($pattern, $exact, $n))],

            ['type' => 'materials', 'label' => 'Surowce', 'icon' => 'bi-basket', 'items' => array_map(fn($r) => [
                'title' => $r['name'],
                'sub'   => trim(($r['name_fr'] ? $r['name_fr'] . ' · ' : '') . ($r['unit_name'] ?? ''), ' ·'),
                'url'   => '/materials/' . (int) $r['id'] . '/edit',
            ], $this->repository->materials($pattern, $n))],

            // Proforma nie ma własnej strony: zamówienie, a bez niego PDF.
            ['type' => 'proformas', 'label' => 'Proformy', 'icon' => 'bi-receipt', 'items' => array_map(fn($r) => [
                'title' => $r['doc_num'],
                'sub'   => trim(($r['consignee_name'] ?? '') . ' · ' . (self::PROFORMA_STATUS[$r['status']] ?? $r['status']) . ($r['order_id'] ? ' · zamówienie #' . $r['order_id'] : ''), ' ·'),
                'url'   => $r['order_id'] ? '/client-orders/' . (int) $r['order_id'] . '/edit' : '/ajax/proforma-documents/' . (int) $r['id'] . '/document',
            ], $this->repository->proformas($pattern, $n))],

            ['type' => 'invoices', 'label' => 'Faktury', 'icon' => 'bi-file-earmark-text', 'items' => array_map(fn($r) => [
                'title' => $r['doc_num'],
                'sub'   => trim(($r['consignee_name'] ?? '') . ' · ' . $this->date($r['invoiced_date']) . ' · PDF', ' ·'),
                'url'   => '/ajax/invoice-documents/' . (int) $r['id'] . '/document',
            ], $this->repository->invoices($pattern, $n))],

            ['type' => 'wdt', 'label' => 'WDT', 'icon' => 'bi-truck', 'items' => array_map(fn($r) => [
                'title' => 'WDT ' . $r['doc_num'],
                'sub'   => trim(($r['consignee_name'] ?? '') . ' · ' . $this->date($r['invoiced_date']) . ($r['order_id'] ? ' · zamówienie #' . $r['order_id'] : ''), ' ·'),
                'url'   => '/wdt-documents/' . (int) $r['id'] . '/products',
            ], $this->repository->wdt($pattern, $n))],
        ];

        $groups = array_values(array_filter($groups, static fn($g) => !empty($g['items'])));

        return [
            'q'      => $q,
            'total'  => array_sum(array_map(static fn($g) => count($g['items']), $groups)),
            'groups' => $groups,
        ];
    }

    private function date($value): string
    {
        $t = $value ? strtotime((string) $value) : false;
        return $t ? date('d.m.Y', $t) : '';
    }
}
