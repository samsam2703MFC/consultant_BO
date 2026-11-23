<?php
declare(strict_types=1);

namespace App\Services\Workshop\Planning;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Repositories\Workshop\PlanningRepository;
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
        private PlanningRepository $planning,
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
        // [386] Ce qui reste « do rozplanowania » est inscrit dans les jours, comme le répartirait « Rozdziel na dni »
        // (calcul seul : le plan n'est pas modifié).
        $suggested = $this->weeks->suggested($date, $context);
        // [388] Ce qui est déjà en stock (cartons en magasin × pièces par carton) : affiché à part, ce n'est pas à produire.
        $free = $this->planning->stockCartons();
        $onHand = [];
        $byProduct = [];
        $info = [];
        $mts = [];
        foreach ($this->planning->items() as $it) {
            if ($it['item_type'] !== 'product' || (int) $it['ppc'] <= 0 || !$it['id_final_product']) continue;
            $info[(int) $it['id']] = $it;
            // [395] Produits faits pour le stock (MTS) : niveaux min. / maks. en vigueur (période exceptionnelle comprise).
            if ($it['mode'] === 'MTS' && $it['stock_max'] !== null) $mts[(int) $it['id']] = ['min' => $it['stock_min'] !== null ? (float) $it['stock_min'] : null, 'max' => (float) $it['stock_max']];
            $onHand[(int) $it['id']] = (float) (($free[(int) $it['id_final_product']] ?? 0) * (int) $it['ppc']);
            $byProduct[(int) $it['id_final_product']] = ['id' => (int) $it['id'], 'ppc' => (int) $it['ppc']];
        }
        // [390] Ce qu'il faut envoyer cette semaine : commandes ouvertes (brut, avant le stock), avec le premier départ.
        $ordered = [];
        foreach ($this->planning->openOrderLines($week['week_start'], $week['week_end']) as $o) {
            $m = $byProduct[(int) $o['id_product']] ?? null;
            if ($m === null) continue;
            $x = &$ordered[$m['id']];
            $x ??= ['qty' => 0.0, 'due' => null];
            $x['qty'] += (float) $o['cartons'] * $m['ppc'];
            $x['due'] = $x['due'] === null || $o['expected_shipping_date'] < $x['due'] ? $o['expected_shipping_date'] : $x['due'];
            unset($x);
        }
        $products = [];
        foreach ($week['products'] as $p) $products[(int) ($p['group_id'] ?? 0)][] = $p;
        // [390] Comme la Lista produkcyjna : chaque produit commandé est sur la feuille de son équipe, même quand le stock
        // couvre tout (rien à produire) — sinon on ne voit pas ce qui part du stock.
        $inWeek = array_flip(array_map(fn($p) => (int) $p['item_id'], $week['products']));
        foreach ($ordered as $id => $o) {
            if (isset($inWeek[$id]) || !isset($info[$id])) continue;
            $it = $info[$id];
            $products[$it['id_primary_group'] !== null ? (int) $it['id_primary_group'] : 0][] = ['item_id' => $id, 'name' => $it['name'], 'item_type' => 'product',
                'unit' => $it['base_unit'], 'ppc' => (int) $it['ppc'], 'batch' => null, 'quantity' => 0.0, 'slots' => [], 'unassigned_qty' => 0.0, 'unassigned_h' => 0.0,
                'direct' => 0.0, 'direct_parts' => []];
        }
        $lanes = array_column(array_map(fn($g) => $g + ['key' => (int) ($g['id'] ?? 0)], $week['groups']), null, 'key');
        $ids = $groupId !== null ? [$groupId] : array_values(array_filter(array_keys($lanes), fn($id) => !empty($products[$id])));
        $teams = [];
        foreach ($ids as $id) {
            $lane = $lanes[$id] ?? ['id' => $id ?: null, 'name' => null, 'color' => null, 'days' => []];
            $info = $known[$id] ?? ['id' => null, 'name' => null, 'color' => null];
            $teams[] = $this->team($info, $lane, $products[$id] ?? [], $week, $recipes, $deliveries, $suggested, $onHand, $ordered, $mts);
        }
        return [
            'week_start' => $week['week_start'], 'week_end' => $week['week_end'], 'iso_week' => $week['iso_week'], 'today' => $week['today'],
            'days' => array_map(fn($d) => ['date' => $d['date'], 'weekday' => $d['weekday'], 'today' => $d['today'], 'past' => $d['past']], $week['days']),
            'groups' => array_values($known), 'teams' => $teams, 'generated_at' => date('Y-m-d H:i'),
        ];
    }

    /**
     * [407] Okno « Zamówienie » : klienci i ilości zamówione produktu w tygodniu (te same zamówienia co kolumna
     * Zamówienie — otwarte, bez gratisów), jeden wiersz na zamówienie. Tylko odczyt.
     */
    public function orders(string $date, int $itemId): array
    {
        $item = null;
        foreach ($this->planning->items() as $it) if ((int) $it['id'] === $itemId) { $item = $it; break; }
        if ($item === null || $item['item_type'] !== 'product' || !$item['id_final_product']) throw ApiException::notFound('item', $itemId);
        $ppc = max(1, (int) $item['ppc']);
        $w = $this->weeks->bounds($date);
        $rows = array_map(fn($o) => ['order_id' => (int) $o['customer_order_id'], 'customer_id' => $o['id_customer'] !== null ? (int) $o['id_customer'] : null,
            'customer' => $o['customer'], 'due' => $o['expected_shipping_date'] !== null ? substr((string) $o['expected_shipping_date'], 0, 10) : null,
            'cartons' => round((float) $o['cartons'], 3), 'quantity' => round((float) $o['cartons'] * $ppc, 3)],
            $this->planning->productOrders((int) $item['id_final_product'], $w['start'], $w['end']));
        return ['item_id' => $itemId, 'name' => $item['name'], 'ppc' => $ppc, 'week_start' => $w['start'], 'week_end' => $w['end'], 'orders' => $rows,
            'total_cartons' => round(array_sum(array_column($rows, 'cartons')), 3), 'total_quantity' => round(array_sum(array_column($rows, 'quantity')), 3),
            'customers' => count(array_unique(array_map(fn($r) => $r['customer_id'] ?? -$r['order_id'], $rows)))];
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

    /**
     * Lista dnia z planu tygodnia (P18, jak tablet) : jeden dzień produkcji — bloki każdego zespołu (godzina, produkt,
     * ilość, kartony, partie, godziny) i zapotrzebowanie surowców i opakowań tego dnia (receptura × zaplanowane kartony,
     * ta sama reguła co zapotrzebowanie w Zakupach). Jedna odpowiedź na « co produkujemy ».
     */
    public function dayList(string $date, ApiContext $context): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw ApiException::validation([['field' => 'date', 'code' => 'INVALID_DATE', 'params' => []]]);
        $data = $this->build($date, null, $context);
        $lang = $context->translator->lang();
        $recipes = $this->supply->recipes();
        $teams = [];
        $need = [];
        $usedBy = [];
        $noRecipe = [];
        foreach ($data['teams'] as $team) {
            $d = array_values(array_filter($team['days'], fn($x) => $x['date'] === $date))[0] ?? null;
            if (!$d || !$d['blocks']) continue;
            $rows = array_column($team['products'], null, 'item_id');
            foreach ($d['blocks'] as $b) {
                $row = $rows[$b['item_id']] ?? null;
                // Półprodukty : ich surowce są już w recepturach produktów (jak w zapotrzebowaniu Zakupów).
                if (($row['item_type'] ?? 'product') !== 'product') continue;
                if (!$row || !$row['ppc'] || !isset($recipes[$b['item_id']])) { $noRecipe[$b['item_id']] = $b['name']; continue; }
                foreach ($recipes[$b['item_id']] as $ing) {
                    $need[$ing['material_id']] = ($need[$ing['material_id']] ?? 0.0) + $b['quantity'] / $row['ppc'] * $ing['quantity'];
                    $usedBy[$ing['material_id']][$b['item_id']] = $b['name'];
                }
            }
            $teams[] = ['id' => $team['id'], 'name' => $team['name'], 'color' => $team['color'], 'people' => $d['people'], 'available_h' => $d['available_h'],
                'hours' => round(array_sum(array_column($d['blocks'], 'hours')), 2), 'blocks' => $d['blocks']];
        }
        $materials = [];
        foreach ($need ? $this->supply->materials(array_keys($need)) : [] as $m) {
            $unit = CostOptimizationService::displayUnit($m['unit']);
            $materials[] = ['id' => (int) $m['id'], 'name' => $lang === 'fr' && $m['name_fr'] ? $m['name_fr'] : $m['name'], 'packaging' => (bool) $m['packaging'],
                'quantity' => round($need[(int) $m['id']], 3), 'unit' => $unit['label'], 'factor' => $unit['factor'], 'products' => array_values($usedBy[(int) $m['id']] ?? [])];
        }
        usort($materials, fn($a, $b) => [$a['packaging'], mb_strtolower($a['name'])] <=> [$b['packaging'], mb_strtolower($b['name'])]);
        return ['date' => $date, 'week_start' => $data['week_start'], 'week_end' => $data['week_end'], 'iso_week' => $data['iso_week'],
            'teams' => $teams, 'materials' => $materials, 'no_recipe' => array_values($noRecipe), 'generated_at' => date('Y-m-d H:i')];
    }

    /** Lista dnia z planu jako PDF (A4 pionowo) : zespoły (bloki dnia), potem zapotrzebowanie surowców dnia. */
    public function dayListPdf(string $date, ApiContext $context): array
    {
        $data = $this->dayList($date, $context);
        $tr = $context->translator;
        $lang = $tr->lang();
        $title = $tr->text('day_list_title', ['date' => $this->fullDay($date)]);
        $week = $tr->text('team_sheet_week', ['week' => $data['iso_week'], 'from' => $this->day($data['week_start']), 'to' => $this->day($data['week_end'])]);
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('TFB');
        $pdf->SetTitle($title);
        $pdf->brandPage($title, ['orientation' => 'P', 'ref' => $week, 'lang' => $lang]);
        $pdf->brandHeader($title, [[trim($tr->text('team_sheet_printed', ['at' => ''])), $this->fullDay(substr($data['generated_at'], 0, 10)) . ' ' . substr($data['generated_at'], 11)]],
            $week . "\n" . $tr->text('day_list_source'));
        $write = function (string $heading, string $html) use ($pdf) {
            $pdf->brandSection($heading);
            $pdf->SetFont(TCPDFService::TABLE_FONT, '', 8);
            $pdf->SetTextColorArray(TCPDFService::INK);
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->SetY($pdf->GetY() + 3);
        };
        $th = fn(string $w, string $label, string $align = 'left') => '<td width="' . $w . '" align="' . $align . '" style="' . self::HEAD . '"><b>' . $this->e($label) . '</b></td>';
        $td = fn(string $w, string $html, string $align = 'left', string $extra = '') => '<td width="' . $w . '" align="' . $align . '" style="' . self::CELL . $extra . '">' . $html . '</td>';
        if (!$data['teams']) $pdf->brandText($tr->text('day_list_empty'));
        foreach ($data['teams'] as $team) {
            $rows = '';
            foreach ($team['blocks'] as $b) {
                $rows .= '<tr>' . $td('10%', $this->e($b['time'] ?? '—'))
                    . $td('40%', '<b>' . $this->e($b['name']) . '</b>' . ($b['late'] ? ' <span style="color:#b00020">' . $this->e($tr->text('team_sheet_late')) . '</span>' : ''))
                    . $td('16%', $this->e($this->qty($b['quantity'], $b['unit'], $b['factor'])), 'right')
                    . $td('12%', $b['cartons'] ? $this->n($b['cartons'], 1) : '—', 'right')
                    . $td('11%', $b['batches'] ? $this->n($b['batches'], 2) : '—', 'right')
                    . $td('11%', $this->n($b['hours'], 1) . ' h', 'right') . '</tr>';
            }
            $head = '<tr>' . $th('10%', $tr->text('day_list_col_time')) . $th('40%', $tr->text('team_sheet_col_product')) . $th('16%', $tr->text('day_list_col_qty'), 'right')
                . $th('12%', $tr->text('team_sheet_cartons'), 'right') . $th('11%', $tr->text('team_sheet_batches'), 'right') . $th('11%', 'h', 'right') . '</tr>';
            $write(($team['name'] ?? $tr->text('team_sheet_no_group')) . ' · ' . $tr->text('team_sheet_day_hours', ['people' => $team['people'], 'required' => $this->n($team['hours'], 1), 'available' => $this->n($team['available_h'], 1)]),
                '<table cellpadding="3">' . $head . $rows . '</table>');
        }
        if ($data['materials']) {
            $rows = '';
            foreach ($data['materials'] as $m) {
                $rows .= '<tr>' . $td('38%', '<b>' . $this->e($m['name']) . '</b>') . $td('17%', $this->e($this->qty($m['quantity'], $m['unit'], $m['factor'])), 'right')
                    . $td('45%', $this->e(implode(', ', array_slice($m['products'], 0, 4)) . (count($m['products']) > 4 ? '…' : '')), 'left', ';color:' . self::MUTED) . '</tr>';
            }
            $write($tr->text('day_list_materials'), '<table cellpadding="3"><tr>' . $th('38%', $tr->text('team_sheet_col_material')) . $th('17%', $tr->text('day_list_col_need'), 'right')
                . $th('45%', $tr->text('team_sheet_col_for')) . '</tr>' . $rows . '</table>');
        }
        if ($data['no_recipe']) $pdf->brandText($tr->text('day_list_no_recipe', ['list' => implode(', ', $data['no_recipe'])]));
        $pdf->brandSignatures([$tr->text('team_sheet_signature')]);
        return ['filename' => $tr->text('day_list_file') . '_' . $date . '.pdf', 'content_base64' => base64_encode($pdf->Output('', 'S'))];
    }

    private function fullDay(string $iso): string
    {
        return substr($iso, 8, 2) . '.' . substr($iso, 5, 2) . '.' . substr($iso, 0, 4);
    }

    private function team(array $info, array $lane, array $products, array $week, array $recipes, array $deliveries, array $suggested = [], array $onHand = [], array $ordered = [], array $mts = []): array
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
            // [386] Reste à produire : quantité proposée par jour (en italique), en plus de ce qui est déjà au plan.
            $proposed = 0.0;
            foreach ($suggested[$p['item_id']] ?? [] as $day => $q) {
                if (!isset($days[$day]) || $q <= self::EPS) continue;
                $cur = $perDay[$day] ?? ['quantity' => 0.0, 'cartons' => 0.0, 'time' => null];
                $perDay[$day] = ['quantity' => round($cur['quantity'] + $q, 3), 'cartons' => $p['ppc'] ? round(($cur['quantity'] + $q) / $p['ppc'], 1) : $cur['cartons'],
                    'time' => $cur['time'], 'proposed' => round($q, 3)];
                $proposed += $q;
            }
            ksort($perDay);
            $due = array_values(array_filter(array_column($p['direct_parts'], 'due_date')));
            // Do zrobienia = to, co leży na dniach (także 📌 ponad potrzebę tygodnia), plus nierozplanowana reszta.
            $total = array_sum(array_column($p['slots'], 'quantity')) + (float) $p['unassigned_qty'];
            $rows[] = ['item_id' => $p['item_id'], 'name' => $p['name'], 'item_type' => $p['item_type'], 'unit' => $unit['label'], 'factor' => $unit['factor'], 'ppc' => $p['ppc'],
                'batch' => $p['batch'], 'quantity' => round($total, 3), 'need' => $p['quantity'], 'cartons' => $p['ppc'] ? round($total / $p['ppc'], 1) : null,
                'batches' => $p['batch'] ? round($total / $p['batch'], 2) : null, 'hours' => round(array_sum(array_column($p['slots'], 'hours')) + (float) $p['unassigned_h'], 2),
                // Nadwyżka ponad potrzebę tygodnia idzie na magazyn.
                'direct' => $p['direct'], 'stock' => round(max(0.0, $total - (float) $p['direct']), 3), 'due_date' => $due ? min($due) : null,
                // Reste que la répartition n'a pu poser sur aucun jour (semaine passée) : seul cas encore affiché « nierozplanowane ».
                'unassigned_qty' => round(max(0.0, (float) $p['unassigned_qty'] - $proposed), 3), 'proposed_qty' => round($proposed, 3), 'days' => $perDay,
                'on_hand' => isset($onHand[$p['item_id']]) ? round($onHand[$p['item_id']], 3) : null,
                'ordered' => isset($ordered[$p['item_id']]) ? round($ordered[$p['item_id']]['qty'], 3) : 0.0, 'ordered_due' => $ordered[$p['item_id']]['due'] ?? null,
                // [395] MTS : pour le stock, on peut produire au plus jusqu'au maks. — ce qui restera après les commandes compte
                // (jamais sous zéro : le manque pour les commandes est déjà dans « Do produkcji »).
                'mts' => isset($mts[$p['item_id']]) ? $mts[$p['item_id']] + ['room' => round(max(0.0, $mts[$p['item_id']]['max']
                    - max(0.0, ($onHand[$p['item_id']] ?? 0.0) - ($ordered[$p['item_id']]['qty'] ?? 0.0))), 3)] : null];
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
        // [390] Ordre alphabétique, comme la Lista produkcyjna.
        usort($rows, fn($a, $b) => strcmp(mb_strtolower((string) $a['name']), mb_strtolower((string) $b['name'])));
        return [
            'proposed' => count(array_filter($rows, fn($r) => $r['proposed_qty'] > self::EPS)),
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
    /** [388] En-tête « Jest na stanie » : gris, pour ne pas le lire comme une quantité à produire. */
    private const HEAD_STOCK = 'background-color:#6C757D;color:#ffffff;border:0.3px solid #6C757D';

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
            // [390] Même présentation que la Lista produkcyjna (référence de l'utilisateur, 23.11.2026) : W magazynie
            // (kart. / szt.), Zamówienie (kart. / szt.), Do produkcji = plan de la semaine (commandé − en stock, plus le
            // réassort des produits de stock, qu'on signale en petit), puis les jours.
            $productWidth = 100 - 6 * count($team['days']) - 44;
            $sub = fn(string $w, string $t, string $style = self::HEAD) => '<th width="' . $w . '%" align="right" style="' . $style . '">' . $this->e($t) . '</th>';
            $html = '<table cellpadding="2" style="font-size:7.5pt"><thead><tr>'
                . '<th width="' . $productWidth . '%" rowspan="2" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_product')) . '</th>'
                . '<th width="11%" colspan="2" align="center" style="' . self::HEAD_STOCK . '">' . $this->e($tr->text('team_sheet_col_on_hand')) . '</th>'
                . '<th width="11%" colspan="2" align="center" style="' . self::HEAD_STOCK . '">' . $this->e($tr->text('team_sheet_col_ordered')) . '</th>'
                . '<th width="7%" align="center" style="' . self::HEAD . '"><b>' . $this->e($tr->text('team_sheet_col_total')) . '</b></th>'
                . '<th width="6%" align="center" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_col_stock')) . '</th>'
                . '<th width="5%" rowspan="2" align="right" style="' . self::HEAD . '">' . $this->e($tr->text('team_sheet_batches')) . '</th>'
                . '<th width="4%" rowspan="2" align="right" style="' . self::HEAD . '">h</th>'
                . str_replace('<th width="6%"', '<th width="6%" rowspan="2"', $dayCols) . '</tr><tr>'
                . $sub('5', $tr->text('team_sheet_cartons_short'), self::HEAD_STOCK) . $sub('6', $tr->text('team_sheet_pieces_short'), self::HEAD_STOCK)
                . $sub('5', $tr->text('team_sheet_cartons_short'), self::HEAD_STOCK) . $sub('6', $tr->text('team_sheet_pieces_short'), self::HEAD_STOCK)
                . $sub('7', $tr->text('team_sheet_pieces_short')) . $sub('6', $tr->text('team_sheet_pieces_short'))
                . '</tr></thead><tbody>';
            foreach ($team['products'] as $i => $r) {
                $bg = $i % 2 === 1 ? 'background-color:#F8F9FA;' : '';
                $cols = '';
                foreach ($team['days'] as $d) {
                    $x = $r['days'][$d['date']] ?? null;
                    $q = $x ? $this->e($this->qty($x['quantity'], $r['unit'], $r['factor'], false)) : '';
                    // [386] Quantité proposée (pas encore au plan) : en italique.
                    if ($x && !empty($x['proposed'])) $q = '<i>' . $q . '</i>';
                    $cols .= '<td width="6%" align="center" style="' . $bg . self::CELL . '">' . ($x ? ($x['time'] ? '<span style="font-size:6pt;color:' . self::MUTED . '">' . $this->e($x['time']) . '</span><br>' : '')
                        . $q : '') . '</td>';
                }
                $cell = fn(string $w, string $v, string $extra = '') => '<td width="' . $w . '%" align="right" style="' . $bg . self::CELL . $extra . '">' . $v . '</td>';
                $ctn = fn(?float $v) => $v !== null && $v > self::EPS && $r['ppc'] ? $this->n($v / $r['ppc'], ($v / $r['ppc']) == floor($v / $r['ppc']) ? 0 : 1) : '-';
                $pcs = fn(?float $v) => $v !== null && $v > self::EPS ? $this->e($this->qty($v, $r['unit'], $r['factor'], false)) : '-';
                // [393] Do produkcji = commandé − en stock (comme l'ancienne Lista produkcyjna) ; le réassort des produits de
                // stock (min. / max.) est à part, dans « Na zapas ».
                $make = $r['direct'] > self::EPS ? '<b>' . $this->e($this->qty($r['direct'], $r['unit'], $r['factor'], false)) . '</b>' : '-';
                $refill = ($r['stock'] > self::EPS ? $this->e($this->qty($r['stock'], $r['unit'], $r['factor'], false)) : '-')
                    . (($r['mts'] ?? null) ? '<br><span style="font-size:6pt">' . $this->e($tr->text('team_sheet_room', ['qty' => $this->qty($r['mts']['room'], $r['unit'], $r['factor'], false)])) . '</span>' : '');
                $html .= '<tr nobr="true"><td width="' . $productWidth . '%" style="' . $bg . self::CELL . '"><b>' . $this->e($r['name']) . '</b>'
                    . (($r['mts'] ?? null) ? '<br><span style="font-size:6pt;color:' . self::MUTED . '">' . $this->e($tr->text('team_sheet_mts', ['min' => $r['mts']['min'] !== null ? $this->qty($r['mts']['min'], $r['unit'], $r['factor'], false) : '—',
                        'max' => $this->qty($r['mts']['max'], $r['unit'], $r['factor'], false)])) . '</span>' : '')
                    . ($r['unassigned_qty'] > self::EPS ? '<br><span style="font-size:6pt;color:#b00020">' . $this->e($tr->text('team_sheet_waiting', ['qty' => $this->qty($r['unassigned_qty'], $r['unit'], $r['factor'])])) . '</span>' : '') . '</td>'
                    . $cell('5', $ctn($r['on_hand']), ';color:' . self::MUTED) . $cell('6', $pcs($r['on_hand']), ';color:' . self::MUTED)
                    . $cell('5', $ctn($r['ordered'])) . $cell('6', $pcs($r['ordered']) . ($r['ordered'] > self::EPS && $r['ordered_due'] ? '<br><span style="font-size:6pt;color:' . self::MUTED . '">' . $this->e($tr->text('team_sheet_due', ['day' => $this->day($r['ordered_due'])])) . '</span>' : ''))
                    . $cell('7', $make) . $cell('6', $refill, ';color:' . self::MUTED)
                    . $cell('5', $r['batches'] ? $this->n($r['batches'], 2) : '—') . $cell('4', $this->n($r['hours'], 1))
                    . $cols . '</tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p style="font-size:6.5pt;color:' . self::MUTED . '">' . $this->e($tr->text('team_sheet_columns_note')) . '</p>';
            if ($team['proposed'] ?? 0) $html .= '<p style="font-size:6.5pt;color:' . self::MUTED . '"><i>' . $this->e($tr->text('team_sheet_proposed_note')) . '</i></p>';
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
