<?php
declare(strict_types=1);

namespace App\Services\Document;

use App\Exceptions\CustomException;
use App\Repositories\Company\ErpCompanyDataRepository;
use App\Services\Product\Price\ClientPriceListService;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

/**
 * « Wzory dokumentów » : przykład każdego wydruku w każdym języku klienta, na danych fikcyjnych (klient, produkty,
 * kwoty) i z prawdziwymi danymi firmy (erp_company_data). Nic nie jest zapisywane w bazie.
 */
class DocumentSampleService
{
    /**
     * klucz => [etykieta, grupa, języki przykładu (puste = bez przykładu), opis, strona ERP, wydruk z bieżących danych (GET)].
     * Dokumenty klienta : przykład na danych fikcyjnych w każdym języku. Wydruki wewnętrzne (po polsku) : z bieżących danych.
     */
    public const DOCUMENTS = [
        'proforma' => ['Faktura proforma', 'Sprzedaż i finanse', DocumentLabels::LANGUAGES, 'Przedpłata zamówienia : wysyłana klientowi z potwierdzeniem zamówienia. Układ faktury (OCR).', '/proforma-documents', null],
        'invoice' => ['Faktura', 'Sprzedaż i finanse', DocumentLabels::LANGUAGES, 'Faktura krajowa. Jeden układ z WDT i proformą : numer i daty w tym samym miejscu, Lp. jak w KSeF, sumy zawsze na dole ostatniej strony (OCR biura rachunkowego).', '/invoice-documents', null],
        'wdt' => ['Faktura WDT', 'Sprzedaż i finanse', DocumentLabels::LANGUAGES, 'Wewnątrzwspólnotowa dostawa towarów : Lp. = numer wiersza KSeF, palety i wagi, kod QR KSeF nad sumami. Układ faktury (OCR).', '/shipments', null],
        'difference' => ['Różnice zamówienie / faktura', 'Sprzedaż i finanse', DocumentLabels::LANGUAGES, 'List do klienta : dopłata albo zwrot różnicy między proformą a fakturą.', '/shipments?type=difference', null],
        'price_list' => ['Cennik klienta', 'Sprzedaż i finanse', DocumentLabels::LANGUAGES, 'Ceny klienta według kategorii (karta klienta).', '/clients', null],
        'general_price_list' => ['Cennik ogólny produktów', 'Sprzedaż i finanse', [], 'Ceny standardowe wszystkich produktów.', '/price-lists/general', '/ajax/products/price-list/pdf'],
        'sales_summary' => ['Podsumowanie sprzedaży WDT', 'Sprzedaż i finanse', [], 'Kartony i średnia miesięczna na produkt dla klienta (w jego języku).', '/finances/sales-analytics', null],
        'wz' => ['WZ — wydanie zewnętrzne', 'Logistyka', DocumentLabels::LANGUAGES, 'Dokument wydania towaru z podpisami wydającego i odbiorcy.', '/shipments?type=wz', null],
        'cmr' => ['CMR', 'Logistyka', ['pl'], 'Międzynarodowy list przewozowy : urzędowy formularz, układ bez zmian (PL / EN).', '/shipments?type=cmr', null],
        'picking_list' => ['Lista kompletacyjna', 'Logistyka', [], 'Zamówienie albo zwroty klienta do skompletowania, z polem do odhaczenia.', '/client-orders', null],
        'packaging' => ['Lista opakowań BDO / PPWR wysyłki', 'Logistyka', [], 'Opakowania wysłane do klienta (w pliku dokumentów dostawy).', '/shipments', null],
        'packaging_reports' => ['Raporty BDO i PPWR', 'Logistyka', [], 'Masa opakowań w okresie, według materiału i klienta.', '/audits/packaging-reports', null],
        'production_list' => ['Lista produkcyjna działu', 'Produkcja i magazyn', [], 'Do produkcji według planu tygodnia, stan i dostawy na 4 dni.', '/workshop/team-sheet', null],
        'requirement_list' => ['Lista zapotrzebowania materiałowego', 'Produkcja i magazyn', [], 'Surowce i opakowania potrzebne do zamówień, stan i do zamówienia.', '/workshop/team-sheet', null],
        'team_sheet' => ['Plan zespołu', 'Produkcja i magazyn', [], 'Kalendarz tygodnia, produkcja i dostawy surowców zespołu (papier).', '/workshop/team-sheet', null],
        'technical_card' => ['Karta techniczna produktu', 'Produkcja i magazyn', [], 'Składniki, alergeny, czasy, etapy i kompetencje.', '/workshop/products', null],
        'stock_state' => ['Stan magazynowy', 'Produkcja i magazyn', [], 'Zapas wyrobów gotowych w kartonach i jego wartość (koszt surowców).', '/stock', '/ajax/stock/stock-state'],
        'products_without_recipe' => ['Produkty bez receptury', 'Produkcja i magazyn', [], 'Produkty do uzupełnienia według działu i kategorii.', '/recipes', '/ajax/recipes/products-without-recipe/pdf'],
    ];

    private const SAMPLE_PRODUCTS = [
        // [kod, nazwa, kartony, cena kartonu, szt./karton, waga szt. kg, paleta]
        [1004184, 'Croissant au beurre 70 g', 6, 48.60, 60, 0.07, 1],
        [1004185, 'Pain au chocolat 80 g', 5, 52.20, 60, 0.08, 1],
        [1002112, 'Pistolet à l\'ancienne 70 g', 10, 23.54, 80, 0.07, 1],
        [1003134, 'Quiche lorraine 26 cm', 4, 58.40, 6, 0.70, 2],
        [1002168, 'Tarte aux pommes 24 cm', 3, 54.91, 8, 1.20, 2],
        [1005010, 'Éclair au chocolat', 2, 42.00, 24, 0.09, 2],
    ];

    public function __construct(
        private ErpCompanyDataRepository $companyData,
        private WZService $wz,
        private WDTService $wdt,
        private InvoiceService $invoice,
        private ProformaService $proforma,
        private OrderShippingDifferenceService $difference,
        private CMRService $cmr,
        private ClientPriceListService $priceList,
    ) {
    }

    /** Katalog dla strony : grupy => dokumenty. */
    public function catalogue(): array
    {
        $groups = [];
        foreach (self::DOCUMENTS as $key => [$label, $group, $languages, $description, $where, $live]) {
            $groups[$group][] = ['key' => $key, 'label' => $label, 'languages' => $languages, 'description' => $description, 'where' => $where, 'live' => $live];
        }
        return $groups;
    }

    /** @return array{filename: string, file_string: string} */
    public function render(string $key, string $lang): array
    {
        if (!isset(self::DOCUMENTS[$key]) || self::DOCUMENTS[$key][2] === []) throw new CustomException('DOCUMENT_NOT_FOUND', 'errors', 404);
        $lang = DocumentLabels::lang($lang, 'fr');
        if (!in_array($lang, self::DOCUMENTS[$key][2], true)) $lang = self::DOCUMENTS[$key][2][0];

        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle('Wzór : ' . self::DOCUMENTS[$key][0]);
        $company = $this->company();
        $client = $this->client($lang);
        $date = date('Y-m-d');
        $products = self::SAMPLE_PRODUCTS;

        switch ($key) {
            case 'wz':
                $this->wz->renderDocument($pdf, ['lang' => $lang, 'wzData' => [
                    'doc_num' => 'WZ-0001/' . date('d/m/Y'), 'established_date' => $date,
                    'consignor_name' => $company['wz_company_name'], 'consignor_address_1' => $company['wz_company_address_1'], 'consignor_address_2' => $company['wz_company_address_2'],
                    'consignor_nip' => $company['wz_company_nip'], 'consignor_bank_1' => $company['wz_company_bank_1'], 'consignor_bank_2' => $company['wz_company_bank_2'],
                    'consignor_swift' => $company['wz_company_swift'],
                ] + $this->consignee($client), 'wzProductData' => array_map(fn($p) => [
                    'id_product' => $p[0], 'name' => $p[1], 'code' => '19059090', 'quantity' => $p[2], 'weight_unit' => $p[5],
                    'weight_total' => round($p[2] * $p[4] * $p[5], 2), 'ppc' => $p[4]], $products)]);
                break;
            case 'wdt':
                $rows = array_map(fn($p) => [
                    'id_palette' => $p[6], 'id_product' => $p[0], 'name' => $p[1], 'code' => '19059090', 'quantity' => $p[2], 'price_carton' => $p[3],
                    'total_net' => round($p[2] * $p[3], 2), 'total_tva' => 0, 'tva_perc' => 0, 'weight_carton' => round($p[4] * $p[5], 2),
                    'weight_carton_total' => round($p[2] * $p[4] * $p[5], 2), 'ppc' => $p[4], 'price_per_piece' => round($p[3] / $p[4], 2)], $products);
                $this->wdt->renderDocument($pdf, ['lang' => $lang, 'wdtData' => [
                    'doc_num' => 'WDT-0001/' . date('Y'), 'established_date' => $date, 'invoiced_date' => $date, 'shipping_date' => $date,
                    'consignor_name' => $company['wdt_company_name'], 'consignor_address_1' => $company['wdt_company_address_1'], 'consignor_address_2' => $company['wdt_company_address_2'],
                    'consignor_nip' => $company['wdt_company_nip'], 'consignor_bank' => $company['wdt_company_bank_1'], 'consignor_swift' => $company['wdt_company_swift'],
                    'total_weight' => array_sum(array_column($rows, 'weight_carton_total')), 'palette_nums' => '1, 2', 'currency' => 'EUR',
                ] + $this->consignee($client), 'wdtProductData' => $rows, 'ksefSubmission' => []]);
                break;
            case 'invoice':
                $this->invoice->renderDocument($pdf, ['lang' => $lang, 'invoiceData' => [
                    'doc_num' => 'FAC-00001/' . date('Y'), 'invoiced_date' => $date,
                    'consignor_name' => $company['proforma_company_name'], 'consignor_address_1' => $company['proforma_company_address_1'],
                    'consignor_address_2' => $company['proforma_company_address_2'], 'consignor_nip' => $company['proforma_company_nip'],
                    'consignor_bank' => $company['proforma_company_bank'], 'consignor_swift' => $company['proforma_company_swift'],
                ] + $this->consignee($client), 'invoiceProductData' => array_map(fn($p) => [
                    'id_product' => $p[0], 'product_name' => $p[1], 'quantity' => $p[2], 'single_price' => $p[3], 'tva_perc' => 0,
                    'single_weight' => round($p[4] * $p[5], 2), 'ppc' => $p[4]], $products)]);
                break;
            case 'proforma':
                $this->proforma->renderDocument($pdf, ['lang' => $lang, 'proformaData' => [
                    'doc_num' => 'FP-00001/' . date('d/m/Y'), 'established_date' => $date,
                    'consignor_name' => $company['proforma_company_name'], 'consignor_address_1' => $company['proforma_company_address_1'],
                    'consignor_address_2' => $company['proforma_company_address_2'], 'consignor_nip' => $company['proforma_company_nip'],
                    'consignor_bank' => $company['proforma_company_bank'], 'consignor_swift' => $company['proforma_company_swift'],
                ] + $this->consignee($client), 'proformaProductData' => array_map(fn($p) => [
                    'id_product' => $p[0], 'product_name' => $p[1], 'code_stat' => '19059090', 'quantity' => $p[2], 'price_per_carton' => $p[3],
                    'total_price_net' => round($p[2] * $p[3], 2), 'tva_perc' => 0, 'single_carton_weight_kg' => round($p[4] * $p[5], 3),
                    'total_weight_kg' => round($p[2] * $p[4] * $p[5], 3), 'ppc' => $p[4], 'price_per_piece' => round($p[3] / $p[4], 2)], $products)]);
                break;
            case 'difference':
                $wdt = ['doc_num' => 'WDT-0001/' . date('Y'), 'invoiced_date' => $date,
                    'consignor_name' => $company['wdt_company_name'], 'consignor_address_1' => $company['wdt_company_address_1'],
                    'consignor_address_2' => $company['wdt_company_address_2'], 'consignor_nip' => $company['wdt_company_nip']] + $this->consignee($client);
                $this->difference->renderDocument($pdf, ['lang' => $lang, 'wdtData' => $wdt, 'differenceData' => ['established_date' => $date], 'diffProductData' => [
                    ['id_product' => 1004184, 'product_name' => 'Croissant au beurre 70 g', 'quantity_ordered' => 6, 'price_during_ordering' => '48.60', 'ordered_total_amount' => '291.60',
                        'invoiced_quantity' => 5, 'price_during_invoicing' => '48.60', 'invoiced_total_amount' => '243.00'],
                    ['id_product' => 1003134, 'product_name' => 'Quiche lorraine 26 cm', 'quantity_ordered' => 4, 'price_during_ordering' => '58.40', 'ordered_total_amount' => '233.60',
                        'invoiced_quantity' => 3, 'price_during_invoicing' => '58.40', 'invoiced_total_amount' => '175.20'],
                ]]);
                break;
            case 'price_list':
                $rows = [];
                foreach ([[1, 'Viennoiseries', [[1, 'Croissant au beurre 70 g', 48.60], [2, 'Pain au chocolat 80 g', 52.20]]],
                          [2, 'Pains', [[3, 'Pistolet à l\'ancienne 70 g', 23.54], [4, 'Baguette tradition 250 g', 31.20]]],
                          [3, 'Traiteur', [[5, 'Quiche lorraine 26 cm', 58.40], [6, 'Quiche saumon épinards 26 cm', 61.90]]],
                          [4, 'Pâtisserie', [[7, 'Tarte aux pommes 24 cm', 54.91], [8, 'Éclair au chocolat', 42.00]]]] as [$catId, $cat, $items]) {
                    foreach ($items as [$id, $name, $price]) $rows[] = ['id_category' => $catId, 'category_name' => $cat, 'final_product_name' => $name, 'price' => $price];
                }
                $this->priceList->renderDocument($pdf, ['lang' => $lang, 'clientData' => ['company' => $client['name']], 'productPriceData' => $rows]);
                break;
            case 'cmr':
                $this->cmr->renderDocument($pdf, [
                    'cmrData' => [
                        'doc_num' => 'CMR-0001/' . date('d/m/Y'), 'established_date' => $date, 'established_in' => $company['cmr_established_in'],
                        'consignor_name' => $company['cmr_company_name'], 'consignor_address_1' => $company['cmr_company_address_1'], 'consignor_address_2' => $company['cmr_company_address_2'],
                        'consignee_name' => $client['name'], 'consignee_address_1' => $client['address_1'], 'consignee_address_2' => $client['address_2'],
                        'delivery_place' => $client['address_2'], 'taking_over_place' => $company['cmr_taking_over_place'],
                        'carrier_name' => 'Transport Exemple Sp. z o.o.', 'carrier_address_1' => 'ul. Przykładowa 1', 'carrier_address_2' => '48-300 Nysa', 'carrier_regis_num' => 'ONY 12345',
                        'carrier_sec_name' => '', 'carrier_sec_address_1' => '', 'carrier_sec_address_2' => '', 'carrier_sec_regis_num' => '',
                        'sender_instructions' => $company['cmr_sender_instruction'], 'carrier_reservation_observation' => $company['cmr_carrier_reservation_observation'],
                    ],
                    'cmrPackageData' => [['name' => 'Cartons', 'quantity' => array_sum(array_column($products, 2)), 'packing_method' => 'Cartons', 'product_type' => 'Bakery Products',
                        'stats_num' => '19059090', 'gross_weight' => 84.6, 'volume_m3' => 0]],
                    'wzData' => ['doc_num' => 'WZ-0001/' . date('d/m/Y')],
                ]);
                break;
        }

        return ['filename' => 'WZOR_' . strtoupper($key) . '_' . strtoupper($lang) . '.pdf', 'file_string' => $pdf->Output('', 'S')];
    }

    private function company(): array
    {
        $rows = $this->companyData->query('SELECT * FROM erp_company_data ORDER BY id LIMIT 1');
        return array_map(fn($v) => (string) $v, $rows[0] ?? []) + array_fill_keys([
            'wz_company_name', 'wz_company_address_1', 'wz_company_address_2', 'wz_company_nip', 'wz_company_bank_1', 'wz_company_bank_2', 'wz_company_swift',
            'wdt_company_name', 'wdt_company_address_1', 'wdt_company_address_2', 'wdt_company_nip', 'wdt_company_bank_1', 'wdt_company_swift',
            'proforma_company_name', 'proforma_company_address_1', 'proforma_company_address_2', 'proforma_company_nip', 'proforma_company_bank', 'proforma_company_swift',
            'cmr_company_name', 'cmr_company_address_1', 'cmr_company_address_2', 'cmr_taking_over_place', 'cmr_established_in', 'cmr_carrier_reservation_observation', 'cmr_sender_instruction',
        ], '');
    }

    /** Klient fikcyjny w kraju języka. */
    private function client(string $lang): array
    {
        return match ($lang) {
            'nl' => ['name' => 'Bakkerij Voorbeeld BV', 'address_1' => 'Voorbeeldstraat 12', 'address_2' => '9000 Gent, België', 'nip' => 'BE 0123.456.789'],
            'pl' => ['name' => 'Piekarnia Przykładowa Sp. z o.o.', 'address_1' => 'ul. Przykładowa 12', 'address_2' => '50-001 Wrocław, Polska', 'nip' => 'PL 1234567890'],
            'en' => ['name' => 'Example Bakery Ltd', 'address_1' => '12 Example Street', 'address_2' => 'L-1111 Luxembourg', 'nip' => 'LU 12345678'],
            default => ['name' => 'Boulangerie Exemple SRL', 'address_1' => "Rue de l'Exemple 12", 'address_2' => '1000 Bruxelles, Belgique', 'nip' => 'BE 0123.456.789'],
        };
    }

    private function consignee(array $client): array
    {
        return ['consignee_name' => $client['name'], 'consignee_address_1' => $client['address_1'], 'consignee_address_2' => $client['address_2'], 'consignee_nip' => $client['nip']];
    }
}
