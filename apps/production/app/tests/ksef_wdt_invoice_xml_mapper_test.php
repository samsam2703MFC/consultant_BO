<?php
declare(strict_types=1);

require_once __DIR__ . '/../App/Services/Ksef/KsefWdtInvoiceXmlMapper.php';

use App\Services\Ksef\KsefWdtInvoiceXmlMapper;

function expectKsefMapper(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$invoice = [
    'doc_num' => 'TEST/1/2026',
    'invoiced_date' => '2026-09-01',
    'shipping_date' => '2026-09-01',
    'currency' => 'EUR',
    // This legacy value must have no effect on the KSeF total.
    'total_net' => '9999.99',
    'consignor_name' => 'Testowy sprzedawca',
    'consignor_nip' => '1234567890',
    'consignor_address_1' => 'Testowa 1',
    'consignor_address_2' => '00-001 Warszawa',
    'consignor_bank' => 'PL61 1090 1014 0000 0712 1981 2874',
    'consignee_name' => 'Testowy odbiorca',
    'consignee_nip' => 'BE0123456789',
    'consignee_address_1' => 'Rue de Test 1',
    'consignee_address_2' => '1000 Bruxelles',
];
$lines = [
    ['name' => 'Produkt A', 'id_product' => 101, 'code' => '19059090', 'quantity' => '2', 'price_carton' => '12.50', 'total_net' => '25.00'],
    ['name' => 'Produkt B', 'id_product' => 102, 'code' => '19059090', 'quantity' => '1', 'price_carton' => '4.20', 'total_net' => '4.20'],
];

$mapper = new KsefWdtInvoiceXmlMapper();
$xml = new DOMDocument();
$xml->loadXML($mapper->build($invoice, $lines));
$xpath = new DOMXPath($xml);
$xpath->registerNamespace('f', 'http://crd.gov.pl/wzor/2025/06/25/13775/');

expectKsefMapper($xpath->evaluate('string(/f:Faktura/f:Fa/f:P_15)') === '29.20', 'P_15 must be calculated from WDT line totals, not document_wdt.total_net.');
expectKsefMapper($xpath->evaluate('string(/f:Faktura/f:Fa/f:P_13_6_2)') === '29.20', 'WDT taxable base must equal the sum of line totals.');
expectKsefMapper($xpath->evaluate('string(/f:Faktura/f:Fa/f:Platnosc/f:RachunekBankowy/f:NrRB)') === 'PL61109010140000071219812874', 'KSeF must receive the seller bank account as an unformatted IBAN.');
expectKsefMapper($xpath->evaluate('string(/f:Faktura/f:Fa/f:FaWiersz[1]/f:Indeks)') === '101', 'The first KSeF index must be the product number, not the legacy CN code.');
expectKsefMapper($xpath->evaluate('string(/f:Faktura/f:Fa/f:FaWiersz[2]/f:Indeks)') === '102', 'The second KSeF index must be the product number, not the legacy CN code.');
expectKsefMapper(
    $xpath->evaluate('boolean(/f:Faktura/f:Fa/f:FaWiersz[last()]/following-sibling::f:Platnosc)') === true,
    'Payment data must follow all invoice lines, as required by the FA(3) sequence.'
);

$invalidLines = $lines;
$invalidLines[1]['total_net'] = '4.10';
try {
    $mapper->build($invoice, $invalidLines);
    throw new RuntimeException('A line with an inconsistent net total must block KSeF XML generation.');
} catch (InvalidArgumentException) {
}

echo "ksef_wdt_invoice_xml_mapper_test: OK\n";
