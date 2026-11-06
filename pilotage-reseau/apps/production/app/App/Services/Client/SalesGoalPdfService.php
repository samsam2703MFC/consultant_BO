<?php
declare(strict_types=1);

namespace App\Services\Client;

use App\Repositories\Company\ErpCompanyDataRepository;
use App\Services\Utils\DocumentLabels;
use App\Services\Utils\TCPDFService;

/**
 * PDF « Cel obrotu » dla franczyzobiorcy, w jego języku (pod polskim), w stylu proformy :
 * nagłówek (klient, okres, źródło zakupów), cele (miesiąc, dzień otwarcia, tydzień, N-1) i tabela według kategorii.
 */
final class SalesGoalPdfService
{
    public function __construct(private ClientSpaceService $space, private ErpCompanyDataRepository $company) {}

    /** @return array{filename: string, file_string: string, goal: array, client: array} */
    public function generate(int $customerId, string $month, string $source): array
    {
        $client = $this->space->client($customerId);
        $goal = $this->space->goal($customerId, $month, $source);
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->render($pdf, $goal, $client, $client['lang']);
        $name = trim((string) preg_replace('/[^A-Za-z0-9\-]+/', '_', (string) ($client['company'] ?? 'klient')), '_');
        return ['filename' => 'CEL_OBROTU_' . $name . '_' . $goal['month'] . '.pdf', 'file_string' => $pdf->Output('', 'S'), 'goal' => $goal, 'client' => $client];
    }

    public function render(TCPDFService $pdf, array $goal, array $client, string $lang): TCPDFService
    {
        $L = fn(string $k) => DocumentLabels::get($k);
        $S = fn(string $k) => DocumentLabels::sub($k, $lang);
        $P = fn(string $k) => DocumentLabels::pair($k, $lang);
        $money = fn($v) => DocumentLabels::money($v);
        $coef = fn($v) => '× ' . DocumentLabels::number($v, 2);
        $period = DocumentLabels::month($goal['month'], 'pl') . ($lang !== 'pl' ? ' / ' . DocumentLabels::month($goal['month'], $lang) : '');
        $company = $this->company->first(['id' => 1]) ?: [];

        $pdf->brandPage($L('doc_sales_goal'), ['ref' => (string) $client['company'] . ' · ' . $goal['month'], 'lang' => $lang,
            'company' => DocumentLabels::companyLine($company, 'proforma_company')]);
        $pdf->brandHeader($L('doc_sales_goal'), [
            [$P('client'), (string) $client['company']],
            [$P('period'), $period],
            [$P('purchase_source'), $P('source_' . $goal['source'])],
        ], $S('doc_sales_goal'));

        $t = $goal['totals'];
        $info = [
            [$P('coefficient'), $t['coefficient'] !== null ? $coef($t['coefficient']) : '—'],
            ...($t['excluded'] > 0 ? [[DocumentLabels::get('not_for_resale') . ($lang !== 'pl' ? ' / ' . DocumentLabels::get('not_for_resale', $lang) : ''), $money($t['excluded'])]] : []),
            [$P('per_open_day'), $money($goal['per_day']) . ' (' . $goal['open_days'] . ')'],
            [$P('per_week'), $money($goal['per_week'])],
            [$P('previous_year'), $money($goal['n1']['target'])],
        ];
        $pdf->brandSummary($info, [[$P('purchases_net'), $money($t['purchases_sell'])], [$P('goal_total'), $money($t['target'])]]);

        $columns = [
            ['label' => $L('line_no'), 'sub' => $S('line_no'), 'w' => 5, 'align' => 'R'],
            ['label' => $L('product'), 'sub' => $S('product'), 'w' => 30],
            ['label' => $L('cartons'), 'sub' => $S('cartons'), 'w' => 8, 'align' => 'R'],
            ['label' => $L('pieces'), 'sub' => $S('pieces'), 'w' => 8, 'align' => 'R'],
            ['label' => $L('purchase_price_piece'), 'sub' => $S('purchase_price_piece'), 'w' => 11, 'align' => 'R'],
            ['label' => $L('purchases_net'), 'sub' => $S('purchases_net'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('coefficient'), 'sub' => $S('coefficient'), 'w' => 9, 'align' => 'R'],
            ['label' => $L('sale_price_piece'), 'sub' => $S('sale_price_piece'), 'w' => 12, 'align' => 'R'],
            ['label' => $L('revenue_target'), 'sub' => $S('revenue_target'), 'w' => 14, 'align' => 'R'],
        ];
        $rows = [];
        $n = 0;
        foreach ($goal['categories'] as $c) {
            $rows[] = ['_group' => $c['name'] . ($c['excluded'] ? ' — ' . DocumentLabels::get('not_for_resale', $lang) : '')];
            foreach ($c['items'] as $i) {
                $rows[] = [++$n, $i['name'], DocumentLabels::number($i['cartons']), DocumentLabels::number($i['pieces']),
                    $i['piece_price'] !== null ? $money($i['piece_price']) : '—', $money($i['purchases']),
                    $c['excluded'] ? '—' : $coef($c['coefficient']), $i['sale_price'] !== null ? $money($i['sale_price']) : '—',
                    $c['excluded'] ? '—' : $money($i['target'])];
            }
            $rows[] = ['_total' => ['', $c['name'], DocumentLabels::number($c['cartons']), DocumentLabels::number($c['pieces']), '', $money($c['purchases']),
                $c['excluded'] ? '—' : $coef($c['coefficient']), '', $c['excluded'] ? '—' : $money($c['target'])]];
        }
        $rows[] = ['_total' => ['', $P('total'), '', DocumentLabels::number($t['pieces']), '', $money($t['purchases_sell']),
            $t['coefficient'] !== null ? $coef($t['coefficient']) : '', '', $money($t['target'])]];
        $pdf->brandTable($columns, $rows, ['font' => 7, 'head_font' => 6.5]);
        $pdf->brandNote(DocumentLabels::get('goal_note', 'pl') . ($lang !== 'pl' ? "\n" . DocumentLabels::get('goal_note', $lang) : ''));
        return $pdf;
    }
}
