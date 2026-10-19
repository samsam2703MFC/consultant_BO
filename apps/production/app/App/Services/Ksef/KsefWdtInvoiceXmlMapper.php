<?php

namespace App\Services\Ksef;

use InvalidArgumentException;
use XMLWriter;

/** Builds the FA(3) WDT variant described by the official MF example FA_3_Przykład_22. */
final class KsefWdtInvoiceXmlMapper
{
    private const NS = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    /** @param array<string,mixed> $invoice @param list<array<string,mixed>> $lines */
    public function build(array $invoice, array $lines): string
    {
        $this->validate($invoice, $lines);
        [$buyerCountry, $buyerVat] = $this->splitEuVat((string) $invoice['consignee_nip']);
        $sellerNip = preg_replace('/\D/', '', (string) $invoice['consignor_nip']);
        $bankAccount = $this->bankAccountNumber($invoice);
        // document_wdt.total_net is a legacy field and is deliberately not used.
        $total = $this->amountFromCents($this->totalFromLinesInCents($lines));

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'Faktura', self::NS);
        $xml->writeAttributeNS('xmlns', 'etd', null, 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/');

        $xml->startElement('Naglowek');
        $xml->startElement('KodFormularza'); $xml->writeAttribute('kodSystemowy', 'FA (3)'); $xml->writeAttribute('wersjaSchemy', '1-0E'); $xml->text('FA'); $xml->endElement();
        $this->element($xml, 'WariantFormularza', '3');
        $this->element($xml, 'DataWytworzeniaFa', gmdate('Y-m-d\\TH:i:s\\Z'));
        $this->element($xml, 'SystemInfo', 'Asima ERP');
        $xml->endElement();

        $xml->startElement('Podmiot1');
        $this->element($xml, 'PrefiksPodatnika', 'PL');
        $xml->startElement('DaneIdentyfikacyjne'); $this->element($xml, 'NIP', $sellerNip); $this->element($xml, 'Nazwa', $invoice['consignor_name']); $xml->endElement();
        $this->address($xml, 'PL', $invoice['consignor_address_1'], $invoice['consignor_address_2']);
        $xml->endElement();

        $xml->startElement('Podmiot2');
        $xml->startElement('DaneIdentyfikacyjne'); $this->element($xml, 'KodUE', $buyerCountry); $this->element($xml, 'NrVatUE', $buyerVat); $this->element($xml, 'Nazwa', $invoice['consignee_name']); $xml->endElement();
        $this->address($xml, $buyerCountry, $invoice['consignee_address_1'], $invoice['consignee_address_2']);
        $this->element($xml, 'JST', '2'); $this->element($xml, 'GV', '2');
        $xml->endElement();

        $xml->startElement('Fa');
        $this->element($xml, 'KodWaluty', strtoupper((string)($invoice['currency'] ?: 'EUR')));
        $this->element($xml, 'P_1', $invoice['invoiced_date']);
        $this->element($xml, 'P_2', $invoice['doc_num']);
        if (!empty($invoice['shipping_date']) && $invoice['shipping_date'] !== $invoice['invoiced_date']) $this->element($xml, 'P_6', $invoice['shipping_date']);
        $this->element($xml, 'P_13_6_2', $total);
        $this->element($xml, 'P_15', $total);
        $xml->startElement('Adnotacje');
        foreach (['P_16' => '2', 'P_17' => '2', 'P_18' => '2', 'P_18A' => '2'] as $name => $value) $this->element($xml, $name, $value);
        $xml->startElement('Zwolnienie'); $this->element($xml, 'P_19N', '1'); $xml->endElement();
        $xml->startElement('NoweSrodkiTransportu'); $this->element($xml, 'P_22N', '1'); $xml->endElement();
        $this->element($xml, 'P_23', '2');
        $xml->startElement('PMarzy'); $this->element($xml, 'P_PMarzyN', '1'); $xml->endElement();
        $xml->endElement();
        $this->element($xml, 'RodzajFaktury', 'VAT');
        foreach ($lines as $index => $line) {
            $xml->startElement('FaWiersz');
            $this->element($xml, 'NrWierszaFa', (string)($index + 1));
            $this->element($xml, 'P_7', $line['name']);
            if (!empty($line['id_product'])) $this->element($xml, 'Indeks', $line['id_product']);
            $this->element($xml, 'P_8A', 'krt.');
            $this->element($xml, 'P_8B', $this->quantity($line['quantity']));
            $this->element($xml, 'P_9A', $this->amount($line['price_carton']));
            $this->element($xml, 'P_11', $this->amount($line['total_net']));
            $this->element($xml, 'P_12', '0 WDT');
            $xml->endElement();
        }
        $xml->startElement('Platnosc');
        $this->element($xml, 'FormaPlatnosci', '6');
        $xml->startElement('RachunekBankowy');
        $this->element($xml, 'NrRB', $bankAccount);
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        return $xml->outputMemory();
    }

    /** @param array<string,mixed> $invoice @param list<array<string,mixed>> $lines */
    public function validate(array $invoice, array $lines): void
    {
        $this->assertInvoice($invoice, $lines);
    }

    /** @param array<string,mixed> $invoice @param list<array<string,mixed>> $lines */
    private function assertInvoice(array $invoice, array $lines): void
    {
        foreach (['doc_num', 'invoiced_date', 'consignor_name', 'consignor_nip', 'consignor_address_1', 'consignee_name', 'consignee_nip', 'consignee_address_1'] as $field) if (trim((string)($invoice[$field] ?? '')) === '') throw new InvalidArgumentException('WDT nie zawiera wymaganego pola: ' . $field . '.');
        if ($lines === []) throw new InvalidArgumentException('WDT nie zawiera pozycji faktury.');
        $this->splitEuVat((string)$invoice['consignee_nip']);
        $this->bankAccountNumber($invoice);
        $this->totalFromLinesInCents($lines);
    }

    /** @return array{string,string} */
    private function splitEuVat(string $vat): array
    {
        $vat = strtoupper(preg_replace('/[^A-Z0-9]/', '', $vat) ?? '');
        if (preg_match('/^([A-Z]{2})([A-Z0-9]{2,14})$/', $vat, $matches) !== 1 || $matches[1] === 'PL') throw new InvalidArgumentException('WDT musi zawierać VAT UE nabywcy z prefiksem kraju, np. BE0123456789.');
        return [$matches[1], $matches[2]];
    }

    /**
     * WDT stores the account as display text used on the PDF. KSeF accepts only
     * the account identifier itself (IBAN or a Polish 26-digit NRB) in NrRB.
     */
    private function bankAccountNumber(array $invoice): string
    {
        $source = strtoupper((string) ($invoice['consignor_bank'] ?? ''));
        $compact = preg_replace('/[^A-Z0-9]/', '', $source) ?? '';

        if (preg_match('/PL\d{26}/', $compact, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/(?<!\d)\d{26}(?!\d)/', $compact, $matches) === 1) {
            return $matches[0];
        }

        throw new InvalidArgumentException('WDT nie zawiera poprawnego numeru rachunku bankowego sprzedawcy (wymagany jest IBAN lub 26-cyfrowy NRB w polu consignor_bank).');
    }

    private function address(XMLWriter $xml, string $country, mixed $line1, mixed $line2): void { $xml->startElement('Adres'); $this->element($xml, 'KodKraju', $country); $this->element($xml, 'AdresL1', $line1); if (trim((string)$line2) !== '') $this->element($xml, 'AdresL2', $line2); $xml->endElement(); }
    private function element(XMLWriter $xml, string $name, mixed $value): void { $xml->startElement($name); $xml->text((string)$value); $xml->endElement(); }
    private function amount(mixed $value): string { return number_format((float)$value, 2, '.', ''); }
    private function quantity(mixed $value): string { return rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.'); }

    /** @param list<array<string,mixed>> $lines */
    private function totalFromLinesInCents(array $lines): int
    {
        $total = 0;
        foreach ($lines as $index => $line) {
            foreach (['quantity', 'price_carton', 'total_net'] as $field) {
                if (!isset($line[$field]) || !is_numeric($line[$field])) {
                    throw new InvalidArgumentException(sprintf('Pozycja %d nie zawiera prawidłowej wartości: %s.', $index + 1, $field));
                }
            }

            $expectedCents = (int) round((float) $line['quantity'] * (float) $line['price_carton'] * 100, 0, PHP_ROUND_HALF_UP);
            $storedCents = (int) round((float) $line['total_net'] * 100, 0, PHP_ROUND_HALF_UP);
            if ($expectedCents !== $storedCents) {
                throw new InvalidArgumentException(sprintf(
                    'Pozycja %d „%s” ma niespójną wartość netto: ilość × cena kartonu = %s, zapisane total_net = %s.',
                    $index + 1,
                    (string) ($line['name'] ?? ''),
                    $this->amountFromCents($expectedCents),
                    $this->amountFromCents($storedCents),
                ));
            }
            $total += $storedCents;
        }
        return $total;
    }

    private function amountFromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
