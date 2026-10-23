<?php
declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

/**
 * Jedna struktura faktur (faktura, faktura WDT, proforma) dla programu OCR biura rachunkowego :
 *  - nagłówek : numer, data wystawienia, data dostawy — zawsze te same etykiety i to samo miejsce ;
 *  - Sprzedawca po lewej, Nabywca po prawej, NIP w ostatniej linii bloku ;
 *  - tabela z tymi samymi kolumnami (Lp. = numer wiersza jak w KSeF), bez wierszy grup ;
 *  - jeden blok sum (netto, VAT, razem brutto) zawsze na dole ostatniej strony, w tym samym miejscu,
 *    obok płatności (rachunek, SWIFT, tytuł przelewu) ; uwaga prawna pod sumami.
 */
final class InvoiceLayout
{
    /** Strefa kodu QR KSeF nad blokiem sum (mm). */
    private const QR_HEIGHT = 40.0;

    /**
     * $doc : title_key, number, issue_date, delivery_date, lang, currency, company_line,
     *        seller / buyer [name, lines[]], payment [[etykieta, wartość], …], note, correction (kwota albo 0), qr (callable|null)
     * $lines : [code, name, stat, quantity, price, net, vat_rate, vat, gross, weight, ppc, price_piece]
     */
    public static function render(TCPDFService $pdf, array $doc, array $lines): TCPDFService
    {
        $lang = $doc['lang'];
        $L = fn(string $k) => DocumentLabels::get($k);
        $S = fn(string $k) => DocumentLabels::sub($k, $lang);
        $P = fn(string $k) => DocumentLabels::pair($k, $lang);
        $cur = $doc['currency'] ?? '€';
        $money = fn($v) => DocumentLabels::money($v, $cur);

        $pdf->brandPage($L($doc['title_key']), ['ref' => $doc['number'], 'lang' => $lang, 'company' => $doc['company_line']]);
        $pdf->brandHeader($L($doc['title_key']), [
            [$P('number'), $doc['number']],
            [$P('issue_date'), $doc['issue_date']],
            [$P('delivery_date'), $doc['delivery_date'] ?: $doc['issue_date']],
        ], $S($doc['title_key']));
        $pdf->brandParties(['label' => $P('seller')] + $doc['seller'], ['label' => $P('buyer')] + $doc['buyer']);

        $columns = [
            ['label' => $L('line_no'), 'sub' => $S('line_no'), 'w' => 4, 'align' => 'R'],
            ['label' => $L('code'), 'sub' => $S('code'), 'w' => 8],
            ['label' => $L('description'), 'sub' => $S('description'), 'w' => 24],
            ['label' => $L('stat_code'), 'sub' => $S('stat_code'), 'w' => 9],
            ['label' => $L('quantity'), 'sub' => $S('quantity'), 'w' => 6, 'align' => 'R'],
            ['label' => $L('price_carton'), 'sub' => $S('price_carton'), 'w' => 10, 'align' => 'R'],
            ['label' => $L('net_value'), 'sub' => $S('net_value'), 'w' => 10, 'align' => 'R'],
            ['label' => $L('vat_rate'), 'sub' => $S('vat_rate'), 'w' => 7, 'align' => 'R'],
            ['label' => $L('vat_value'), 'sub' => $S('vat_value'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('gross_value'), 'sub' => $S('gross_value'), 'w' => 10, 'align' => 'R'],
            ['label' => $L('weight'), 'sub' => $S('weight'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('ppc'), 'sub' => $S('ppc'), 'w' => 8, 'align' => 'R'],
            ['label' => $L('price_piece'), 'sub' => $S('price_piece'), 'w' => 9, 'align' => 'R'],
        ];
        $rows = [];
        $sum = ['qty' => 0.0, 'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0, 'weight' => 0.0];
        foreach (array_values($lines) as $i => $l) {
            $rows[] = [$i + 1, $l['code'], $l['name'], $l['stat'] ?? '', DocumentLabels::number($l['quantity']), $money($l['price']), $money($l['net']),
                DocumentLabels::number($l['vat_rate']) . ' %', $money($l['vat']), $money($l['gross']), DocumentLabels::number($l['weight'], 2) . ' kg',
                DocumentLabels::number($l['ppc']), $money($l['price_piece'])];
            $sum['qty'] += (float) $l['quantity'];
            $sum['net'] += (float) $l['net'];
            $sum['vat'] += (float) $l['vat'];
            $sum['gross'] += (float) $l['gross'];
            $sum['weight'] += (float) $l['weight'];
        }
        $pdf->brandTable($columns, $rows, ['font' => 6.8, 'head_font' => 6.3]);

        $correction = (float) ($doc['correction'] ?? 0);
        $totals = [
            [$P('net'), $money($sum['net'])],
            [$P('vat'), $money($sum['vat'])],
        ];
        if ($correction != 0.0) $totals[] = [$P('correction'), $money($correction)];
        $totals[] = [$P('gross_total'), $money($sum['gross'] + $correction)];
        $info = array_merge([
            [$P('total_quantity'), DocumentLabels::number($sum['qty']) . ' · ' . DocumentLabels::number($sum['weight'], 2) . ' kg'],
        ], $doc['payment']);

        // Blok sum zawsze na dole ostatniej strony (to samo miejsce na każdej fakturze).
        $pdf->brandClosing($info, $totals, (string) ($doc['note'] ?? ''), $doc['qr'] ?? null, isset($doc['qr']) ? self::QR_HEIGHT : 0.0);
        return $pdf;
    }
}
