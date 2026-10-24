<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Repositories\Workshop\SchedulingRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Utils\TCPDFService;

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

    /** PDF do druku : A4 poziomo, każdy zespół od nowej strony (styl dokumentów L'Atelier, własna numeracja stron). */
    public function pdf(string $date, ?int $groupId, ApiContext $context): array
    {
        $data = $this->build($date, $groupId, $context);
        $tr = $context->translator;
        $lang = $tr->lang();
        $title = $tr->text('team_sheet_title');
        $week = $tr->text('team_sheet_week', ['week' => $data['iso_week'], 'from' => $this->day($data['week_start']), 'to' => $this->day($data['week_end'])]);
        $printed = $this->day(substr($data['generated_at'], 0, 10)) . '.' . substr($data['generated_at'], 0, 4) . ' ' . substr($data['generated_at'], 11);
        $pdf = new TCPDFService('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('TFB');
        $pdf->SetTitle($title);
        if (!$data['teams']) {
            $pdf->brandPage($title, ['orientation' => 'L', 'ref' => $week, 'lang' => $lang]);
            $pdf->brandHeader($title, [], $week);
            $pdf->brandText($tr->text('team_sheet_empty_week'));
        }
        foreach ($data['teams'] as $team) {
            $name = $team['name'] ?? $tr->text('team_sheet_no_group');
            $pdf->brandPage($title . ' · ' . $name, ['orientation' => 'L', 'ref' => $week, 'lang' => $lang]);
            $pdf->brandHeader($title . ' : ' . $name, [[trim($tr->text('team_sheet_printed', ['at' => ''])), $printed]],
                $week . "\n" . $tr->text('team_sheet_hours', ['people' => $team['people'], 'hours' => $this->n($team['hours'], 1), 'available' => $this->n($team['available_h'], 1)]));
            foreach ($this->teamSections($team, $tr) as [$heading, $html]) {
                $pdf->brandSection($heading);
                $pdf->SetFont(TCPDFService::TABLE_FONT, '', 8);
                $pdf->SetTextColorArray(TCPDFService::INK);
                $pdf->writeHTML($html, true, false, true, false, '');
                $pdf->SetY($pdf->GetY() + 3);
            }
            $pdf->brandSignatures([$tr->text('team_sheet_signature')]);
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
            // Do zrobienia = to, co leży na dniach (także 📌 ponad potrzebę tygodnia), plus nierozplanowana reszta.
            $total = array_sum(array_column($p['slots'], 'quantity')) + (float) $p['unassigned_qty'];
            $rows[] = ['item_id' => $p['item_id'], 'name' => $p['name'], 'item_type' => $p['item_type'], 'unit' => $unit['label'], 'factor' => $unit['factor'], 'ppc' => $p['ppc'],
                'batch' => $p['batch'], 'quantity' => round($total, 3), 'need' => $p['quantity'], 'cartons' => $p['ppc'] ? round($total / $p['ppc'], 1) : null,
                'batches' => $p['batch'] ? round($total / $p['batch'], 2) : null, 'hours' => round(array_sum(array_column($p['slots'], 'hours')) + (float) $p['unassigned_h'], 2),
                // Nadwyżka ponad potrzebę tygodnia idzie na magazyn.
                'direct' => $p['direct'], 'stock' => round(max(0.0, $total - (float) $p['direct']), 3), 'due_date' => $due ? min($due) : null, 'unassigned_qty' => round((float) $p['unassigned_qty'], 3), 'days' => $perDay];
            if ($p['ppc'] && $total > self::EPS && isset($recipes[$p['item_id']])) {
                foreach ($recipes[$p['item_id']] as $ing) {
                    $need[$ing['material_id']] = ($need[$ing['material_id']] ?? 0.0) + $total / $p['ppc'] * $ing['quantity'];
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

    /** Kolory tabel HTML w stylu dokumentów (TCPDFService). */
    private const HEAD = 'background-color:#8D1D2C;color:#ffffff;border:0.3px solid #8D1D2C';
    private const HEAD_TODAY = 'background-color:#F8ECEE;color:#8D1D2C;border:0.3px solid #8D1D2C';
    private const CELL = 'border:0.3px solid #DEE2E6';
    private const MUTED = '#6C757D';

    /** Sekcje planu zespołu : [tytuł, html] — kalendarz, produkcja tygodnia, dostawy (gdy są). */
    private function teamSections(array $team, Translator $tr): array
    {
        $sections = [];

        // Kalendarz : dni w kolumnach, bloki w kolejności godzin, dostawy dnia.
        $width = round(100 / max(1, count($team['days'])), 2);
        $head = '';
        $cells = '';
        foreach ($team['days'] as $d) {
            $head .= '<td width="' . $width . '%" style="' . ($d['today'] ? self::HEAD_TODAY : self::HEAD) . '"><b>' . $this->e($tr->text('weekday_' . $d['weekday'])) . ' ' . $this->day($d['date']) . '</b><br>'
                . '<span style="font-size:6.5pt">' . ($d['available_h'] > 0 || $d['required_h'] > 0
                    ? $this->e($tr->text('team_sheet_day_hours', ['people' => $d['people'], 'required' => $this->n($d['required_h'], 1), 'available' => $this->n($d['available_h'], 1)]))
                    : $this->e($tr->text('team_sheet_no_shift'))) . '</span></td>';
            $body = '';
            foreach ($d['blocks'] as $b) {
                $body .= '<b>' . ($b['time'] ? '<span style="color:#8D1D2C">' . $this->e($b['time']) . '</span> ' : '') . $this->e($b['name']) . '</b><br><span style="font-size:6.5pt;color:' . self::MUTED . '">'
                    . $this->e($this->qty($b['quantity'], $b['unit'], $b['factor']) . ($b['cartons'] ? ' · ' . $this->n($b['cartons'], 1) . ' ' . $tr->text('team_sheet_cartons') : '')
                        . ($b['batches'] ? ' · ' . $this->n($b['batches'], 2) . ' ' . $tr->text('team_sheet_batches') : '') . ' · ' . $this->n($b['hours'], 1) . ' h')
                    . ($b['late'] ? ' <span style="color:#b00020">' . $this->e($tr->text('team_sheet_late')) . '</span>' : '') . '</span><br>';
            }
            foreach ($d['deliveries'] as $x) {
                $body .= '<span style="font-size:6.5pt;color:#1f4f7a"><b>' . $this->e($tr->text('team_sheet_delivery', ['supplier' => $x['supplier']])) . '</b> ' . $this->e(implode(', ', $x['materials'])) . '</span><br>';
            }
            $cells .= '<td width="' . $width . '%" style="' . self::CELL . '">' . ($body ?: '<span style="color:#999">—</span>') . '</td>';
        }
        $sections[] = [$tr->text('team_sheet_calendar'), '<table cellpadding="3" style="font-size:7.5pt"><tr>' . $head . '</tr><tr>' . $cells . '</tr></table>'];

        // Produkcja tygodnia.
        $dayCols = '';
        foreach ($team['days'] as $d) $dayCols .= '<th width="6%" align="center" style="' . ($d['today'] ? self::HEAD_TODAY : self::HEAD) . '">' . $this->e($tr->text('weekday_' . $d['weekday'])) . '<br>' . $this->day($d['date']) . '</th>';
        if (!$team['products']) {
            $html = '<p>' . $this->e($tr->text('team_sheet_empty')) . '</p>';
        } else {
            $productWidth = 100 - 6 * count($team['days']) - 34;
            $html = '<table cellpadding="2" style="font-size:7.5pt"><thead><tr>'
                . '<th width="' . $productWidth . '%" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_product')) . '</th>'
                . '<th width="9%" align="right" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_total')) . '</th>'
                . '<th width="5%" align="right" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_batches')) . '</th>'
                . '<th width="4%" align="right" style="' . self::HEAD . '">h</th>'
                . '<th width="9%" align="right" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_ship')) . '</th>'
                . '<th width="7%" align="right" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_stock')) . '</th>'
                . $dayCols . '</tr></thead><tbody>';
            foreach ($team['products'] as $i => $r) {
                $bg = $i % 2 === 1 ? 'background-color:#F8F9FA;' : '';
                $cols = '';
                foreach ($team['days'] as $d) {
                    $x = $r['days'][$d['date']] ?? null;
                    $cols .= '<td width="6%" align="center" style="' . $bg . self::CELL . '">' . ($x ? ($x['time'] ? '<span style="font-size:6pt;color:' . self::MUTED . '">' . $this->e($x['time']) . '</span><br>' : '')
                        . $this->e($this->qty($x['quantity'], $r['unit'], $r['factor'], false)) : '') . '</td>';
                }
                $html .= '<tr nobr="true"><td width="' . $productWidth . '%" style="' . $bg . self::CELL . '"><b>' . $this->e($r['name']) . '</b>'
                    . ($r['unassigned_qty'] > self::EPS ? '<br><span style="font-size:6pt;color:#b00020">' . $this->e($tr->text('team_sheet_waiting', ['qty' => $this->qty($r['unassigned_qty'], $r['unit'], $r['factor'])])) . '</span>' : '')
                    . (abs($r['need'] - $r['quantity']) > 0.5 ? '<br><span style="font-size:6pt;color:' . self::MUTED . '">' . $this->e($tr->text('team_sheet_need', ['qty' => $this->qty($r['need'], $r['unit'], $r['factor'])])) . '</span>' : '') . '</td>'
                    . '<td width="9%" align="right" style="' . $bg . self::CELL . '">' . $this->e($this->qty($r['quantity'], $r['unit'], $r['factor']))
                    . ($r['cartons'] ? '<br><span style="font-size:6pt;color:' . self::MUTED . '">' . $this->n($r['cartons'], 1) . ' ' . $this->e($tr->text('team_sheet_cartons')) . '</span>' : '') . '</td>'
                    . '<td width="5%" align="right" style="' . $bg . self::CELL . '">' . ($r['batches'] ? $this->n($r['batches'], 2) : '—') . '</td>'
                    . '<td width="4%" align="right" style="' . $bg . self::CELL . '">' . $this->n($r['hours'], 1) . '</td>'
                    . '<td width="9%" align="right" style="' . $bg . self::CELL . '">' . ($r['direct'] > self::EPS ? $this->e($this->qty($r['direct'], $r['unit'], $r['factor']))
                        . ($r['due_date'] ? '<br><span style="font-size:6pt;color:' . self::MUTED . '">' . $this->e($tr->text('team_sheet_due', ['day' => $this->day($r['due_date'])])) . '</span>' : '') : '—') . '</td>'
                    . '<td width="7%" align="right" style="' . $bg . self::CELL . '">' . ($r['stock'] > self::EPS ? $this->e($this->qty($r['stock'], $r['unit'], $r['factor'])) : '—') . '</td>'
                    . $cols . '</tr>';
            }
            $html .= '</tbody></table>';
        }
        $sections[] = [$tr->text('team_sheet_production'), $html];

        // Dostawy surowców potrzebnych zespołowi.
        if ($team['deliveries']) {
            $html = '<table cellpadding="2" style="font-size:7.5pt"><thead><tr>'
                . '<th width="10%" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_date')) . '</th>'
                . '<th width="18%" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_supplier')) . '</th>'
                . '<th width="26%" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_material')) . '</th>'
                . '<th width="11%" align="right" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_quantity')) . '</th>'
                . '<th width="11%" align="right" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_need')) . '</th>'
                . '<th width="24%" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_for')) . '</th></tr></thead><tbody>';
            foreach ($team['deliveries'] as $o) {
                foreach ($o['lines'] as $k => $l) {
                    $done = $l['received'] >= $l['quantity'] - self::EPS;
                    $html .= '<tr nobr="true"><td width="10%" style="' . self::CELL . '">' . ($k === 0 ? ($o['date'] ? $this->e($tr->text('weekday_' . date('N', strtotime($o['date'])))) . ' ' . $this->day($o['date'])
                            : $this->e($tr->text('team_sheet_undated'))) : '') . '</td>'
                        . '<td width="18%" style="' . self::CELL . '">' . ($k === 0 ? $this->e($o['supplier']) . '<br><span style="font-size:6pt;color:' . self::MUTED . '">' . $this->e($o['code']) . '</span>' : '') . '</td>'
                        . '<td width="26%" style="' . self::CELL . '">' . $this->e($l['name']) . '</td>'
                        . '<td width="11%" align="right" style="' . self::CELL . '">' . $this->e($this->qty($l['quantity'], $l['unit'], $l['factor']))
                        . '<br><span style="font-size:6pt;color:' . ($done ? '#22663a' : self::MUTED) . '">' . $this->e($tr->text($done ? 'team_sheet_received' : ($l['received'] > 0 ? 'team_sheet_partial' : 'team_sheet_expected'))) . '</span></td>'
                        . '<td width="11%" align="right" style="' . self::CELL . '">' . $this->e($this->qty($l['need'], $l['unit'], $l['factor'])) . '</td>'
                        . '<td width="24%" style="' . self::CELL . ';font-size:6.5pt">' . $this->e(implode(', ', array_slice($l['products'], 0, 4)) . (count($l['products']) > 4 ? ' …' : '')) . '</td></tr>';
                }
            }
            $html .= '</tbody></table>';
            $sections[] = [$tr->text('team_sheet_deliveries'), $html];
        }
        return $sections;
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
