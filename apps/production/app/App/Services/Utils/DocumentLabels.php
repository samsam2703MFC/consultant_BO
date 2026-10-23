<?php
declare(strict_types=1);

namespace App\Services\Utils;

/**
 * Etykiety wydruków (PDF) w językach klientów : polski zawsze (dokument polskiej firmy), drugi język = język
 * komunikacji klienta (customer.communication_lang : fr, nl, en ; pl = tylko polski).
 */
final class DocumentLabels
{
    public const LANGUAGES = ['pl', 'fr', 'nl', 'en'];

    private const LABELS = [
        // nagłówek, strony
        'producer' => ['pl' => 'Producent zatwierdzony przez', 'fr' => 'Producteur référencé pour', 'nl' => 'Erkend producent voor', 'en' => 'Approved producer for'],
        'page' => ['pl' => 'Strona', 'fr' => 'Page', 'nl' => 'Pagina', 'en' => 'Page'],
        'number' => ['pl' => 'Numer', 'fr' => 'Numéro', 'nl' => 'Nummer', 'en' => 'Number'],
        'date' => ['pl' => 'Data', 'fr' => 'Date', 'nl' => 'Datum', 'en' => 'Date'],
        'seller' => ['pl' => 'Sprzedawca', 'fr' => 'Vendeur', 'nl' => 'Verkoper', 'en' => 'Seller'],
        'buyer' => ['pl' => 'Nabywca', 'fr' => 'Acheteur', 'nl' => 'Koper', 'en' => 'Buyer'],
        'consignor' => ['pl' => 'Wydający', 'fr' => 'Expéditeur', 'nl' => 'Afzender', 'en' => 'Consignor'],
        'consignee' => ['pl' => 'Odbiorca', 'fr' => 'Destinataire', 'nl' => 'Ontvanger', 'en' => 'Consignee'],
        'tax_id' => ['pl' => 'NIP', 'fr' => 'TVA', 'nl' => 'btw', 'en' => 'VAT'],
        'bank' => ['pl' => 'Rachunek bankowy', 'fr' => 'Compte en banque', 'nl' => 'Bankrekening', 'en' => 'Bank account'],
        'swift' => ['pl' => 'SWIFT / BIC', 'fr' => 'Code BIC Swift', 'nl' => 'BIC / SWIFT', 'en' => 'SWIFT / BIC'],
        'payment' => ['pl' => 'Płatność', 'fr' => 'Paiement', 'nl' => 'Betaling', 'en' => 'Payment'],
        'payment_proforma' => ['pl' => 'Przedpłata (proforma)', 'fr' => 'Paiement pro forma (acompte)', 'nl' => 'Vooruitbetaling (pro forma)', 'en' => 'Prepayment (proforma)'],
        'payment_reference' => ['pl' => 'Tytuł przelewu', 'fr' => 'Communication du virement', 'nl' => 'Mededeling', 'en' => 'Payment reference'],
        'invoice_date' => ['pl' => 'Data fakturowania', 'fr' => 'Date de facturation', 'nl' => 'Factuurdatum', 'en' => 'Invoice date'],
        'shipping_date' => ['pl' => 'Data wysyłki', 'fr' => "Date d'expédition", 'nl' => 'Verzenddatum', 'en' => 'Shipping date'],
        'issue_date' => ['pl' => 'Data wystawienia', 'fr' => "Date d'émission", 'nl' => 'Uitgiftedatum', 'en' => 'Issue date'],
        'total_weight' => ['pl' => 'Waga łącznie', 'fr' => 'Poids total', 'nl' => 'Totaal gewicht', 'en' => 'Total weight'],
        'pallets' => ['pl' => 'Palety', 'fr' => 'Palettes', 'nl' => 'Pallets', 'en' => 'Pallets'],
        'pallet' => ['pl' => 'Paleta', 'fr' => 'Palette', 'nl' => 'Pallet', 'en' => 'Pallet'],
        'net' => ['pl' => 'Wartość netto', 'fr' => 'Total net', 'nl' => 'Totaal netto', 'en' => 'Net total'],
        'vat' => ['pl' => 'VAT', 'fr' => 'TVA', 'nl' => 'btw', 'en' => 'VAT'],
        'correction' => ['pl' => 'Korekta', 'fr' => 'Correction', 'nl' => 'Correctie', 'en' => 'Correction'],
        'issued_by' => ['pl' => 'Wydał', 'fr' => 'Expédié par', 'nl' => 'Afgegeven door', 'en' => 'Issued by'],
        'received_by' => ['pl' => 'Odebrał', 'fr' => 'Reçu par', 'nl' => 'Ontvangen door', 'en' => 'Received by'],
        'signature' => ['pl' => 'data i podpis', 'fr' => 'date et signature', 'nl' => 'datum en handtekening', 'en' => 'date and signature'],
        'total' => ['pl' => 'Razem', 'fr' => 'Total', 'nl' => 'Totaal', 'en' => 'Total'],
        // kolumny tabel
        'code' => ['pl' => 'Kod towaru', 'fr' => 'Réf. article', 'nl' => 'Artikelcode', 'en' => 'Item code'],
        'description' => ['pl' => 'Opis towaru', 'fr' => 'Description', 'nl' => 'Omschrijving', 'en' => 'Description'],
        'stat_code' => ['pl' => 'PKWiU', 'fr' => 'Code stat.', 'nl' => 'Stat. code', 'en' => 'Stat. code'],
        'quantity' => ['pl' => 'Ilość', 'fr' => 'Quantité', 'nl' => 'Aantal', 'en' => 'Quantity'],
        'cartons' => ['pl' => 'Kartony', 'fr' => 'Cartons', 'nl' => 'Dozen', 'en' => 'Cartons'],
        'price_carton' => ['pl' => 'Cena kartonu netto', 'fr' => 'Prix carton net', 'nl' => 'Prijs per doos netto', 'en' => 'Price per carton net'],
        'net_value' => ['pl' => 'Wartość netto', 'fr' => 'Total net', 'nl' => 'Netto', 'en' => 'Net value'],
        'vat_value' => ['pl' => 'VAT', 'fr' => 'TVA', 'nl' => 'btw', 'en' => 'VAT'],
        'gross_value' => ['pl' => 'Wartość brutto', 'fr' => 'Total brut', 'nl' => 'Bruto', 'en' => 'Gross value'],
        'vat_rate' => ['pl' => 'Stawka VAT', 'fr' => 'Taux TVA', 'nl' => 'btw-tarief', 'en' => 'VAT rate'],
        'weight_carton' => ['pl' => 'Waga kartonu', 'fr' => 'Poids carton', 'nl' => 'Gewicht doos', 'en' => 'Carton weight'],
        'weight_unit' => ['pl' => 'Waga jedn.', 'fr' => 'Poids net', 'nl' => 'Nettogewicht', 'en' => 'Net weight'],
        'weight' => ['pl' => 'Waga', 'fr' => 'Poids brut', 'nl' => 'Brutogewicht', 'en' => 'Gross weight'],
        'ppc' => ['pl' => 'Szt. w kartonie', 'fr' => 'Qté par carton', 'nl' => 'Stuks per doos', 'en' => 'Pcs per carton'],
        'price_piece' => ['pl' => 'Cena/szt.', 'fr' => 'Prix/unité', 'nl' => 'Prijs/stuk', 'en' => 'Price/piece'],
        'category' => ['pl' => 'Kategoria', 'fr' => 'Catégorie', 'nl' => 'Categorie', 'en' => 'Category'],
        'product' => ['pl' => 'Produkt', 'fr' => 'Produit', 'nl' => 'Product', 'en' => 'Product'],
        'price' => ['pl' => 'Cena', 'fr' => 'Prix', 'nl' => 'Prijs', 'en' => 'Price'],
        'ordered' => ['pl' => 'Zamówiono', 'fr' => 'Commandé', 'nl' => 'Besteld', 'en' => 'Ordered'],
        'invoiced' => ['pl' => 'Zafakturowano', 'fr' => 'Facturé', 'nl' => 'Gefactureerd', 'en' => 'Invoiced'],
        'difference' => ['pl' => 'Różnica', 'fr' => 'Différence', 'nl' => 'Verschil', 'en' => 'Difference'],
        // tytuły dokumentów
        'doc_wz' => ['pl' => 'WZ — wydanie zewnętrzne', 'fr' => 'Bon de livraison', 'nl' => 'Leveringsbon', 'en' => 'Delivery note'],
        'doc_wdt' => ['pl' => 'Faktura WDT', 'fr' => 'Facture intracommunautaire', 'nl' => 'Intracommunautaire factuur', 'en' => 'Intra-EU invoice'],
        'doc_invoice' => ['pl' => 'Faktura', 'fr' => 'Facture', 'nl' => 'Factuur', 'en' => 'Invoice'],
        'doc_proforma' => ['pl' => 'Faktura proforma', 'fr' => 'Facture pro forma', 'nl' => 'Pro-formafactuur', 'en' => 'Proforma invoice'],
        'doc_difference' => ['pl' => 'Różnice zamówienie / faktura', 'fr' => 'Écarts commande / facture', 'nl' => 'Verschillen bestelling / factuur', 'en' => 'Order / invoice differences'],
        'doc_price_list' => ['pl' => 'Cennik', 'fr' => 'Liste de prix', 'nl' => 'Prijslijst', 'en' => 'Price list'],
        'doc_sales_summary' => ['pl' => 'Podsumowanie sprzedaży', 'fr' => 'Récapitulatif des ventes', 'nl' => 'Verkoopoverzicht', 'en' => 'Sales summary'],
    ];

    /** Język komunikacji klienta (pl, fr, nl, en) ; nieznany → domyślny. */
    public static function lang(?string $lang, string $default = 'pl'): string
    {
        $lang = strtolower(trim((string) $lang));
        return in_array($lang, self::LANGUAGES, true) ? $lang : $default;
    }

    public static function get(string $key, string $lang = 'pl'): string
    {
        $row = self::LABELS[$key] ?? null;
        if ($row === null) return $key;
        return $row[self::lang($lang)] ?? $row['pl'];
    }

    /** Drugi język pod polskim (pusty, gdy klient po polsku albo tłumaczenie takie samo). */
    public static function sub(string $key, string $lang): string
    {
        $lang = self::lang($lang);
        if ($lang === 'pl') return '';
        $second = self::get($key, $lang);
        return $second === self::get($key) ? '' : $second;
    }

    /** « Polski / drugi język » w jednej linii. */
    public static function pair(string $key, string $lang): string
    {
        $sub = self::sub($key, $lang);
        return self::get($key) . ($sub !== '' ? ' / ' . $sub : '');
    }

    /** Kwota z przecinkiem i spacją tysięcy (zapis polski, francuski i niderlandzki). */
    public static function money($value, string $currency = '€'): string
    {
        return number_format((float) $value, 2, ',', ' ') . ($currency !== '' ? ' ' . $currency : '');
    }

    /** NIP / numer VAT bez etykiety zapisanej w danych firmy (« NIP: PL 897… » → « PL 897… »). */
    public static function taxId(?string $raw): string
    {
        return trim((string) preg_replace('/^\s*(NIP|TVA|VAT|BTW)\s*[:\/]?\s*/i', '', (string) $raw));
    }

    /** Linia stopki dokumentu : nazwa · adres · NIP (+ dodatki, np. BDO). */
    public static function companyLine(array $doc, string $prefix = 'consignor', array $extra = []): string
    {
        $address = trim(implode(', ', array_filter([trim((string) ($doc[$prefix . '_address_1'] ?? '')), trim((string) ($doc[$prefix . '_address_2'] ?? ''))])));
        $nip = self::taxId($doc[$prefix . '_nip'] ?? '');
        return implode(' · ', array_filter([trim((string) ($doc[$prefix . '_name'] ?? '')), $address, $nip !== '' ? 'NIP ' . $nip : '', ...$extra]));
    }

    public static function number($value, int $decimals = 0): string
    {
        $value = (float) $value;
        if ($decimals > 0 && abs($value - round($value)) < 0.00001) $decimals = 0;
        return number_format($value, $decimals, ',', ' ');
    }
}
