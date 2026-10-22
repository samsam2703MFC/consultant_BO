<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Repositories\Workshop\SchedulingRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Purchasing\CostOptimizationService;
use TCPDF;

/**
 * Plan zespołu na tydzień produkcji (druk i tablet) : z tablicy tygodnia — kalendarz (bloki dnia w kolejności
 * godzin, obsada i godziny), tabela produkcji (razem, partie, godziny, na wysyłkę z terminem / na magazyn,
 * dni, nierozplanowane) i dostawy surowców tego tygodnia, gdy zespół ich potrzebuje (surowce z receptur jego
 * produktów). Tylko odczyt.
 */
final class TeamSheetService
{
    private const EPS = 0.0005;

    public function __construct(
        private WeekService $weeks,
        private SupplyRepository $supply,
        private SchedulingRepository $scheduling,
    ) {
    }

    /** Zespoły z produkcją w tygodniu (albo jeden zespół, także bez produkcji). */
    public function build(string $date, ?int $groupId, ApiContext $context): array
    {
        $lang = $context->translator->lang();
        $known = [];
        foreach ($this->scheduling->groups() as $g) $known[(int) $g['id']] = ['id' => (int) $g['id'], 'name' => $g['name_' . $lang] ?: $g['name_pl'], 'color' => $g['color']];
        if ($groupId !== null && !isset($known[$groupId])) throw ApiException::notFound('group', $groupId);
        $week = $this->weeks->week($date, $context);
        $recipes = $this->supply->recipes();
        $deliveries = $this->deliveries($week['week_start'], $week['week_end'], $lang);
        $products = [];
        foreach ($week['products'] as $p) $products[(int) ($p['group_id'] ?? 0)][] = $p;
        $lanes = array_column(array_map(fn($g) => $g + ['key' => (int) ($g['id'] ?? 0)], $week['groups']), null, 'key');
        $ids = $groupId !== null ? [$groupId] : array_values(array_filter(array_keys($lanes), fn($id) => !empty($products[$id])));
        $teams = [];
        foreach ($ids as $id) {
            $lane = $lanes[$id] ?? ['id' => $id ?: null, 'name' => null, 'color' => null, 'days' => []];
            $info = $known[$id] ?? ['id' => null, 'name' => null, 'color' => null];
            $teams[] = $this->team($info, $lane, $products[$id] ?? [], $week, $recipes, $deliveries);
        }
        return [
            'week_start' => $week['week_start'], 'week_end' => $week['week_end'], 'iso_week' => $week['iso_week'], 'today' => $week['today'],
            'days' => array_map(fn($d) => ['date' => $d['date'], 'weekday' => $d['weekday'], 'today' => $d['today'], 'past' => $d['past']], $week['days']),
            'groups' => array_values($known), 'teams' => $teams, 'generated_at' => date('Y-m-d H:i'),
        ];
    }

    /** PDF do druku : A4 poziomo, każdy zespół od nowej strony. */
    public function pdf(string $date, ?int $groupId, ApiContext $context): array
    {
        $data = $this->build($date, $groupId, $context);
        $tr = $context->translator;
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('TFB');
        $pdf->SetTitle($tr->text('team_sheet_title'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(true, 8);
        $pdf->SetFont('dejavusans', '', 8);
        if (!$data['teams']) {
            $pdf->AddPage();
            $pdf->writeHTML('<h2>' . $this->e($tr->text('team_sheet_title')) . '</h2><p>' . $this->e($tr->text('team_sheet_empty_week')) . '</p>');
        }
        foreach ($data['teams'] as $team) {
            $pdf->AddPage();
            $pdf->writeHTML($this->teamHtml($team, $data, $tr), true, false, true, false, '');
        }
        $slug = $groupId !== null && $data['teams'] ? '_' . preg_replace('/[^a-z0-9]+/', '-', strtolower($this->ascii((string) ($data['teams'][0]['name'] ?? '')))) : '';
        return ['filename' => $tr->text('team_sheet_file') . '_' . $data['week_start'] . $slug . '.pdf', 'content_base64' => base64_encode($pdf->Output('', 'S'))];
    }

    private function team(array $info, array $lane, array $products, array $week, array $recipes, array $deliveries): array
    {
        $days = [];
        foreach ($week['days'] as $d) $days[$d['date']] = ['date' => $d['date'], 'weekday' => $d['weekday'], 'today' => $d['today'], 'past' => $d['past'],
            'people' => 0, 'available_h' => 0.0, 'required_h' => 0.0, 'blocks' => [], 'deliveries' => []];
        foreach ($lane['days'] ?? [] as $d) {
            if (!isset($days[$d['date']])) continue;
            $days[$d['date']]['people'] = $d['people'];
            $days[$d['date']]['available_h'] = $d['available_h'];
            $days[$d['date']]['required_h'] = $d['required_h'];
        }
        $rows = [];
        $need = [];
        $usedBy = [];
        foreach ($products as $p) {
            $unit = CostOptimizationService::displayUnit($p['unit']);
            $perDay = [];
            foreach ($p['slots'] as $s) {
                if (!isset($days[$s['day']])) continue;
                $days[$s['day']]['blocks'][] = ['item_id' => $p['item_id'], 'name' => $p['name'], 'time' => $s['time'], 'quantity' => $s['quantity'], 'cartons' => $s['cartons'],
                    'batches' => $s['batches'], 'hours' => $s['hours'], 'direct_qty' => $s['direct_qty'], 'stock_qty' => $s['stock_qty'], 'late' => $s['late'],
                    'unit' => $unit['label'], 'factor' => $unit['factor']];
                $perDay[$s['day']] = ['quantity' => $s['quantity'], 'cartons' => $s['cartons'], 'time' => $s['time']];
            }
            $due = array_values(array_filter(array_column($p['direct_parts'], 'due_date')));
            $rows[] = ['item_id' => $p['item_id'], 'name' => $p['name'], 'item_type' => $p['item_type'], 'unit' => $unit['label'], 'factor' => $unit['factor'], 'ppc' => $p['ppc'],
                'batch' => $p['batch'], 'quantity' => $p['quantity'], 'cartons' => $p['cartons'], 'batches' => $p['batches'], 'hours' => $p['hours'],
                'direct' => $p['direct'], 'stock' => $p['stock'], 'due_date' => $due ? min($due) : null, 'unassigned_qty' => round((float) $p['unassigned_qty'], 3), 'days' => $perDay];
            if ($p['ppc'] && $p['quantity'] > self::EPS && isset($recipes[$p['item_id']])) {
                foreach ($recipes[$p['item_id']] as $ing) {
                    $need[$ing['material_id']] = ($need[$ing['material_id']] ?? 0.0) + $p['quantity'] / $p['ppc'] * $ing['quantity'];
                    $usedBy[$ing['material_id']][$p['item_id']] = $p['name'];
                }
            }
        }
        $mine = [];
        foreach ($deliveries as $o) {
            $lines = [];
            foreach ($o['lines'] as $l) {
                if (!isset($need[$l['material_id']])) continue;
                $lines[] = $l + ['need' => round($need[$l['material_id']], 3), 'products' => array_values($usedBy[$l['material_id']])];
            }
            if (!$lines) continue;
            $mine[] = ['lines' => $lines] + $o;
            if ($o['date'] !== null && isset($days[$o['date']])) $days[$o['date']]['deliveries'][] = ['order_id' => $o['order_id'], 'supplier' => $o['supplier'],
                'status' => $o['status'], 'materials' => array_column($lines, 'name')];
        }
        foreach ($days as &$d) usort($d['blocks'], fn($a, $b) => [$a['time'] ?? '99:99', $a['name']] <=> [$b['time'] ?? '99:99', $b['name']]);
        unset($d);
        usort($rows, fn($a, $b) => [array_key_first($a['days']) ?? '9', $a['name']] <=> [array_key_first($b['days']) ?? '9', $b['name']]);
        return [
            'id' => $info['id'], 'name' => $info['name'], 'color' => $info['color'],
            'people' => (int) max([0, ...array_column($days, 'people')]),
            'available_h' => round(array_sum(array_column($days, 'available_h')), 2), 'required_h' => round(array_sum(array_column($days, 'required_h')), 2),
            'hours' => round(array_sum(array_column($rows, 'hours')), 2),
            'unassigned' => count(array_filter($rows, fn($r) => $r['unassigned_qty'] > self::EPS)),
            'days' => array_values($days), 'products' => $rows, 'deliveries' => $mine,
        ];
    }

    /** Zamówienia z dostawą w tygodniu, linie w jednostce wyświetlanej (kg, l). */
    private function deliveries(string $from, string $to, string $lang): array
    {
        $out = [];
        foreach ($this->supply->deliveries($from, $to) as $r) {
            $id = (int) $r['id'];
            $out[$id] ??= ['order_id' => $id, 'code' => $r['code'], 'supplier' => trim((string) $r['supplier_name']), 'date' => $r['delivery_date'], 'status' => $r['status'], 'lines' => []];
            $unit = CostOptimizationService::displayUnit($r['unit']);
            $out[$id]['lines'][] = ['material_id' => (int) $r['id_raw_material'], 'name' => $lang === 'fr' && $r['name_fr'] ? $r['name_fr'] : $r['name'],
                'quantity' => (float) $r['quantity'], 'received' => (float) $r['received_qty'], 'unit' => $unit['label'], 'factor' => $unit['factor']];
        }
        return array_values($out);
    }

    // --- PDF -------------------------------------------------------------------------------------------------

    private function teamHtml(array $team, array $data, Translator $tr): string
    {
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $team['color']) ? $team['color'] : '#8a1c2b';
        $name = $team['name'] ?? $tr->text('team_sheet_no_group');
        $html = '<table cellpadding="2"><tr><td width="70%"><span style="font-size:15pt;font-weight:bold;color:' . $color . '">' . $this->e($tr->text('team_sheet_title')) . ' : ' . $this->e($name) . '</span><br>'
            . '<span style="font-size:10pt">' . $this->e($tr->text('team_sheet_week', ['week' => $data['iso_week'], 'from' => $this->day($data['week_start']), 'to' => $this->day($data['week_end'])])) . '</span></td>'
            . '<td width="30%" align="right" style="font-size:8pt;color:#555">' . $this->e($tr->text('team_sheet_hours', ['people' => $team['people'], 'hours' => $this->n($team['hours'], 1), 'available' => $this->n($team['available_h'], 1)]))
            . '<br>' . $this->e($tr->text('team_sheet_printed', ['at' => $this->day(substr($data['generated_at'], 0, 10)) . '.' . substr($data['generated_at'], 0, 4) . ' ' . substr($data['generated_at'], 11)])) . '</td></tr></table>';

        // Kalendarz : dni w kolumnach, bloki w kolejności godzin, dostawy dnia.
        $width = round(100 / max(1, count($team['days'])), 2);
        $head = '';
        $cells = '';
        foreach ($team['days'] as $d) {
            $bg = $d['today'] ? '#fff4d6' : '#f2ede8';
            $head .= '<td width="' . $width . '%" style="background-color:' . $bg . ';border:0.3px solid #999"><b>' . $this->e($tr->text('weekday_' . $d['weekday'])) . ' ' . $this->day($d['date']) . '</b><br>'
                . '<span style="font-size:6.5pt;color:#555">' . ($d['available_h'] > 0 || $d['required_h'] > 0
                    ? $this->e($tr->text('team_sheet_day_hours', ['people' => $d['people'], 'required' => $this->n($d['required_h'], 1), 'available' => $this->n($d['available_h'], 1)]))
                    : $this->e($tr->text('team_sheet_no_shift'))) . '</span></td>';
            $body = '';
            foreach ($d['blocks'] as $b) {
                $body .= '<b>' . ($b['time'] ? $this->e($b['time']) . ' ' : '') . $this->e($b['name']) . '</b><br><span style="font-size:6.5pt">'
                    . $this->e($this->qty($b['quantity'], $b['unit'], $b['factor']) . ($b['cartons'] ? ' · ' . $this->n($b['cartons'], 1) . ' ' . $tr->text('team_sheet_cartons') : '')
                        . ($b['batches'] ? ' · ' . $this->n($b['batches'], 2) . ' ' . $tr->text('team_sheet_batches') : '') . ' · ' . $this->n($b['hours'], 1) . ' h')
                    . ($b['late'] ? ' <span style="color:#b00020">' . $this->e($tr->text('team_sheet_late')) . '</span>' : '') . '</span><br>';
            }
            foreach ($d['deliveries'] as $x) {
                $body .= '<span style="font-size:6.5pt;color:#1f4f7a"><b>' . $this->e($tr->text('team_sheet_delivery', ['supplier' => $x['supplier']])) . '</b> ' . $this->e(implode(', ', $x['materials'])) . '</span><br>';
            }
            $cells .= '<td width="' . $width . '%" style="border:0.3px solid #999">' . ($body ?: '<span style="color:#999">—</span>') . '</td>';
        }
        $html .= '<h4 style="color:' . $color . '">' . $this->e($tr->text('team_sheet_calendar')) . '</h4><table cellpadding="3" style="font-size:7.5pt"><tr>' . $head . '</tr><tr>' . $cells . '</tr></table>';

        // Produkcja tygodnia.
        $dayCols = '';
        foreach ($team['days'] as $d) $dayCols .= '<th width="6%" align="center" style="border:0.3px solid #999;background-color:#f2ede8">' . $this->e($tr->text('weekday_' . $d['weekday'])) . '<br>' . $this->day($d['date']) . '</th>';
        $html .= '<h4 style="color:' . $color . '">' . $this->e($tr->text('team_sheet_production')) . '</h4>';
        if (!$team['products']) {
            $html .= '<p>' . $this->e($tr->text('team_sheet_empty')) . '</p>';
        } else {
            $productWidth = 100 - 6 * count($team['days']) - 34;
            $html .= '<table cellpadding="2" style="font-size:7pt"><thead><tr style="background-color:#f2ede8">'
                . '<th width="' . $productWidth . '%" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_product')) . '</th>'
                . '<th width="9%" align="right" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_total')) . '</th>'
                . '<th width="5%" align="right" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_batches')) . '</th>'
                . '<th width="4%" align="right" style="border:0.3px solid #999">h</th>'
                . '<th width="9%" align="right" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_ship')) . '</th>'
                . '<th width="7%" align="right" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_stock')) . '</th>'
                . $dayCols . '</tr></thead><tbody>';
            foreach ($team['products'] as $r) {
                $cols = '';
                foreach ($team['days'] as $d) {
                    $x = $r['days'][$d['date']] ?? null;
                    $cols .= '<td width="6%" align="center" style="border:0.3px solid #ccc">' . ($x ? ($x['time'] ? '<span style="font-size:6pt;color:#555">' . $this->e($x['time']) . '</span><br>' : '')
                        . $this->e($this->qty($x['quantity'], $r['unit'], $r['factor'], false)) : '') . '</td>';
                }
                $html .= '<tr nobr="true"><td width="' . $productWidth . '%" style="border:0.3px solid #ccc"><b>' . $this->e($r['name']) . '</b>'
                    . ($r['unassigned_qty'] > self::EPS ? '<br><span style="font-size:6pt;color:#b00020">' . $this->e($tr->text('team_sheet_waiting', ['qty' => $this->qty($r['unassigned_qty'], $r['unit'], $r['factor'])])) . '</span>' : '') . '</td>'
                    . '<td width="9%" align="right" style="border:0.3px solid #ccc">' . $this->e($this->qty($r['quantity'], $r['unit'], $r['factor']))
                    . ($r['cartons'] ? '<br><span style="font-size:6pt;color:#555">' . $this->n($r['cartons'], 1) . ' ' . $this->e($tr->text('team_sheet_cartons')) . '</span>' : '') . '</td>'
                    . '<td width="5%" align="right" style="border:0.3px solid #ccc">' . ($r['batches'] ? $this->n($r['batches'], 2) : '—') . '</td>'
                    . '<td width="4%" align="right" style="border:0.3px solid #ccc">' . $this->n($r['hours'], 1) . '</td>'
                    . '<td width="9%" align="right" style="border:0.3px solid #ccc">' . ($r['direct'] > self::EPS ? $this->e($this->qty($r['direct'], $r['unit'], $r['factor']))
                        . ($r['due_date'] ? '<br><span style="font-size:6pt;color:#555">' . $this->e($tr->text('team_sheet_due', ['day' => $this->day($r['due_date'])])) . '</span>' : '') : '—') . '</td>'
                    . '<td width="7%" align="right" style="border:0.3px solid #ccc">' . ($r['stock'] > self::EPS ? $this->e($this->qty($r['stock'], $r['unit'], $r['factor'])) : '—') . '</td>'
                    . $cols . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        // Dostawy surowców potrzebnych zespołowi.
        if ($team['deliveries']) {
            $html .= '<h4 style="color:' . $color . '">' . $this->e($tr->text('team_sheet_deliveries')) . '</h4>'
                . '<table cellpadding="2" style="font-size:7pt"><thead><tr style="background-color:#f2ede8">'
                . '<th width="10%" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_date')) . '</th>'
                . '<th width="18%" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_supplier')) . '</th>'
                . '<th width="26%" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_material')) . '</th>'
                . '<th width="11%" align="right" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_quantity')) . '</th>'
                . '<th width="11%" align="right" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_need')) . '</th>'
                . '<th width="24%" style="border:0.3px solid #999">' . $this->e($tr->text('team_sheet_col_for')) . '</th></tr></thead><tbody>';
            foreach ($team['deliveries'] as $o) {
                foreach ($o['lines'] as $k => $l) {
                    $done = $l['received'] >= $l['quantity'] - self::EPS;
                    $html .= '<tr nobr="true"><td width="10%" style="border:0.3px solid #ccc">' . ($k === 0 ? ($o['date'] ? $this->e($tr->text('weekday_' . date('N', strtotime($o['date'])))) . ' ' . $this->day($o['date'])
                            : $this->e($tr->text('team_sheet_undated'))) : '') . '</td>'
                        . '<td width="18%" style="border:0.3px solid #ccc">' . ($k === 0 ? $this->e($o['supplier']) . '<br><span style="font-size:6pt;color:#555">' . $this->e($o['code']) . '</span>' : '') . '</td>'
                        . '<td width="26%" style="border:0.3px solid #ccc">' . $this->e($l['name']) . '</td>'
                        . '<td width="11%" align="right" style="border:0.3px solid #ccc">' . $this->e($this->qty($l['quantity'], $l['unit'], $l['factor']))
                        . '<br><span style="font-size:6pt;color:' . ($done ? '#22663a' : '#555') . '">' . $this->e($tr->text($done ? 'team_sheet_received' : ($l['received'] > 0 ? 'team_sheet_partial' : 'team_sheet_expected'))) . '</span></td>'
                        . '<td width="11%" align="right" style="border:0.3px solid #ccc">' . $this->e($this->qty($l['need'], $l['unit'], $l['factor'])) . '</td>'
                        . '<td width="24%" style="border:0.3px solid #ccc;font-size:6.5pt">' . $this->e(implode(', ', array_slice($l['products'], 0, 4)) . (count($l['products']) > 4 ? ' …' : '')) . '</td></tr>';
                }
            }
            $html .= '</tbody></table>';
        }
        $html .= '<p style="font-size:7pt;color:#555">' . $this->e($tr->text('team_sheet_signature')) . ' : ______________________</p>';
        return $html;
    }

    private function qty(float $v, string $unit, float|int $factor, bool $withUnit = true): string
    {
        $value = $v / ($factor ?: 1);
        return $this->n($value, $factor > 1 ? 2 : ($value == floor($value) ? 0 : 1)) . ($withUnit ? ' ' . $unit : '');
    }

    private function n(float|int|null $v, int $digits): string
    {
        if ($v === null) return '—';
        $s = number_format((float) $v, $digits, ',', ' ');
        return $digits > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
    }

    private function day(string $iso): string
    {
        return substr($iso, 8, 2) . '.' . substr($iso, 5, 2);
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function ascii(string $s): string
    {
        return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e',
            'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z', 'é' => 'e', 'è' => 'e', 'à' => 'a', 'ç' => 'c']);
    }
}
