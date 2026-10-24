<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

use App\Services\Ksef\KsefPurchaseInvoiceService;

/**
 * Faktury zakupowe z KSeF przez istniejącą przeglądarkę (zapytanie o metadane jako nabywca, data wystawienia ;
 * do 4 stron po 25) — tylko odczyt, nic nie jest wysyłane do KSeF.
 */
final class KsefPurchaseSource implements KsefInvoiceSource
{
    private const MAX_PAGES = 4;

    public function __construct(private KsefPurchaseInvoiceService $ksef)
    {
    }

    public function search(string $from, string $to): array
    {
        $out = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $res = $this->ksef->list(['from' => $from, 'to' => $to, 'dateType' => 'Issue', 'subjectType' => 'Subject2', 'pageOffset' => $page]);
            foreach ($res['invoices'] as $i) {
                if (!is_array($i)) continue;
                $number = (string) ($i['ksefNumber'] ?? $i['ksefReferenceNumber'] ?? '');
                if ($number === '') continue;
                $seller = is_array($i['seller'] ?? null) ? $i['seller'] : [];
                $out[] = ['ksef_number' => $number, 'number' => $i['invoiceNumber'] ?? null, 'issue_date' => isset($i['issueDate']) ? substr((string) $i['issueDate'], 0, 10) : null,
                    'seller_nip' => $seller['nip'] ?? null, 'seller_name' => $seller['name'] ?? null,
                    'net' => isset($i['netAmount']) ? (float) $i['netAmount'] : null, 'gross' => isset($i['grossAmount']) ? (float) $i['grossAmount'] : null,
                    'currency' => $i['currency'] ?? null];
            }
            if (empty($res['hasMore'])) break;
        }
        return $out;
    }

    public function xml(string $ksefNumber): string
    {
        return $this->ksef->invoiceXml($ksefNumber);
    }
}
