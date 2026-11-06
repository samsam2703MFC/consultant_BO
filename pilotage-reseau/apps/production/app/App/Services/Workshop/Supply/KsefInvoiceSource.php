<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

/** Faktury zakupowe w KSeF (tylko odczyt) : lista za okres i XML faktury. Interfejs pozwala testować bez KSeF. */
interface KsefInvoiceSource
{
    /** @return list<array{ksef_number:string, number:?string, issue_date:?string, seller_nip:?string, seller_name:?string, net:?float, gross:?float, currency:?string}> */
    public function search(string $from, string $to): array;

    public function xml(string $ksefNumber): string;
}
