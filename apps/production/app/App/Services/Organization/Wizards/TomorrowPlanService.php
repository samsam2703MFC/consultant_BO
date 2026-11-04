<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Planning\TeamSheetService;
use App\Services\Workshop\Planning\WeekService;
use App\Services\Workshop\Supply\DeliveryScheduleService;
use App\Services\Workshop\Supply\SupplyService;

/**
 * Kreator « Plan na jutro » [247] (makieta 19 ; Kierownik produkcji, codziennie przed 14:00) — następny dzień produkcji :
 *   1. Co jutro : grupy z planu tygodnia (produkty, kartony, godziny planu, godziny ludzi, obciążenie) ; grupa ponad
 *      obsadę → jedna propozycja : przesunąć blok na późniejszy dzień tego samego tygodnia z wolnymi godzinami ;
 *   2. Ludzie i surowce : obsada grup i tylko surowce z brakiem albo z dostawą w tym dniu ;
 *   3. Potwierdzenie : tablety grup czytają plan na bieżąco — opcjonalna wiadomość « Od kierownika produkcji » na
 *      tablety grup, lista dnia PDF ; « Zatwierdź plan » zapisuje n_wizard_done (zadanie zalicza się samo).
 */
final class TomorrowPlanService
{
    public const CODE = 'tomorrow_sent';
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];
    private const EPS = 0.05;

    public function __construct(private WeekService $week, private TeamSheetService $sheet, private SupplyService $supply,
        private DeliveryScheduleService $deliveries, private LightWizardRepository $repo, private ParamRepository $params)
    {
    }

    /** Następny dzień produkcji po dniu (week.production_days, domyślnie pn–pt). */
    public function targetDay(string $today): string
    {
        $days = array_values(array_filter(array_map('intval', explode(',', (string) $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        $d = $today;
        for ($i = 0; $i < 10; $i++) {
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
            if (in_array((int) date('N', strtotime($d)), $days, true)) return $d;
        }
        return date('Y-m-d', strtotime($today . ' +1 day'));
    }

    public static function label(string $day): string
    {
        return self::DAY_ABBR[(int) date('N', strtotime($day))] . ' ' . date('j.m', strtotime($day));
    }

    public function data(?string $date, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $day = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false && $date > $today ? $date : $this->targetDay($today);
        $ctx = $this->ctx($userId);
        $week = $this->week->week($day, $ctx);
        $list = $this->sheet->dayList($day, $ctx);
        $teams = array_column($list['teams'], null, 'id');
        $groups = [];
        foreach ($week['groups'] as $g) {
            if (!$g['id']) continue;
            $d = array_values(array_filter($g['days'], fn($x) => $x['date'] === $day))[0] ?? null;
            $blocks = $teams[$g['id']]['blocks'] ?? [];
            if (!$d || (!$blocks && $d['required_h'] <= self::EPS)) continue;
            $later = array_values(array_filter($g['days'], fn($x) => $x['date'] > $day));
            $groups[] = ['id' => (int) $g['id'], 'name' => $g['name'], 'color' => $g['color'], 'people' => (int) $d['people'],
                'available_h' => (float) $d['available_h'], 'hours' => (float) $d['required_h'], 'free_h' => (float) $d['free_h'],
                'load_pct' => $d['load_pct'], 'products' => count($blocks), 'cartons' => round(array_sum(array_map(fn($b) => (float) ($b['cartons'] ?? 0), $blocks)), 1),
                'blocks' => array_map(fn($b) => ['item_id' => (int) $b['item_id'], 'name' => $b['name'], 'cartons' => (float) ($b['cartons'] ?? 0), 'quantity' => (float) $b['quantity'],
                    'batches' => $b['batches'] ?? null, 'hours' => (float) $b['hours'], 'unit' => $b['unit'] ?? 'szt', 'time' => $b['time'] ?? null], $blocks),
                'later' => array_map(fn($x) => ['date' => $x['date'], 'label' => self::label($x['date']), 'free_h' => (float) $x['free_h']], $later)];
        }
        foreach ($groups as &$g) $g['proposal'] = self::proposal($g);
        unset($g);

        $totals = ['groups' => count($groups), 'products' => array_sum(array_column($groups, 'products')), 'cartons' => round(array_sum(array_column($groups, 'cartons')), 1),
            'hours' => round(array_sum(array_column($groups, 'hours')), 1), 'available_h' => round(array_sum(array_column($groups, 'available_h')), 1),
            'people' => array_sum(array_column($groups, 'people')), 'over' => count(array_filter($groups, fn($g) => $g['free_h'] < -self::EPS))];
        $done = $this->repo->done(self::CODE, $today, date('Y-m-d', strtotime($today . ' +1 day')));
        return ['day' => $day, 'label' => self::label($day), 'today' => $today, 'groups' => $groups, 'totals' => $totals,
            'materials' => $this->materials($day, $list['materials'], $ctx), 'no_recipe' => $list['no_recipe'],
            'done' => $done ? ['summary' => $done['summary'], 'at' => substr((string) $done['done_at'], 11, 5)] : null];
    }

    /**
     * Grupa ponad obsadę : najmniejszy blok, który sam pokrywa nadmiar (inaczej największy), na pierwszy późniejszy
     * dzień tygodnia z wolnymi godzinami ≥ godziny bloku. Brak takiego dnia → null.
     */
    public static function proposal(array $g): ?array
    {
        $excess = -$g['free_h'];
        if ($excess <= self::EPS || !$g['blocks']) return null;
        $blocks = array_values(array_filter($g['blocks'], fn($b) => $b['hours'] > 0));
        if (!$blocks) return null;
        usort($blocks, fn($a, $b) => $a['hours'] <=> $b['hours']);
        $covering = array_values(array_filter($blocks, fn($b) => $b['hours'] + self::EPS >= $excess));
        $block = $covering[0] ?? end($blocks);
        foreach ($g['later'] as $d) {
            if ($d['free_h'] + self::EPS >= $block['hours']) {
                return ['item_id' => $block['item_id'], 'name' => $block['name'], 'hours' => round($block['hours'], 1), 'cartons' => $block['cartons'],
                    'to' => $d['date'], 'to_label' => $d['label'], 'to_free_h' => round($d['free_h'], 1), 'excess_h' => round($excess, 1)];
            }
        }
        return ['item_id' => null, 'excess_h' => round($excess, 1)];
    }

    /** Surowce dnia : potrzeba vs stan i dostawa w tym dniu — status ok / dostawa / brak. */
    private function materials(string $day, array $need, ApiContext $ctx): array
    {
        if (!$need) return ['rows' => [], 'ok' => 0];
        $stock = [];
        try {
            foreach ($this->supply->needs($day, 1, $ctx)['rows'] ?? [] as $m) $stock[(int) $m['id']] = $m['stock'];
        } catch (\Throwable) {
        }
        $coming = [];
        try {
            foreach ($this->deliveries->week($day, $ctx)['days'] ?? [] as $d) {
                if (($d['date'] ?? null) !== $day) continue;
                foreach ($d['deliveries'] as $o) foreach ($o['lines'] as $l) {
                    $coming[$l['material_id']] = ($coming[$l['material_id']] ?? 0.0) + max(0.0, $l['quantity'] - $l['received']);
                }
            }
        } catch (\Throwable) {
        }
        $rows = [];
        $ok = 0;
        foreach ($need as $m) {
            $have = $stock[$m['id']] ?? null;
            $in = $coming[$m['id']] ?? 0.0;
            $missing = $have === null ? null : max(0.0, $m['quantity'] - $have - $in);
            $state = $have === null ? 'unknown' : ($missing > 0.0005 ? 'short' : ($have < $m['quantity'] ? 'delivery' : 'ok'));
            if ($state === 'ok' || ($state === 'unknown' && $in <= 0)) { $ok++; continue; }
            $f = $m['factor'] ?: 1;
            $rows[] = ['id' => $m['id'], 'name' => $m['name'], 'unit' => $m['unit'], 'need' => round($m['quantity'] / $f, 2), 'stock' => $have === null ? null : round($have / $f, 2),
                'delivery' => round($in / $f, 2), 'missing' => $missing === null ? null : round($missing / $f, 2), 'state' => $state, 'packaging' => $m['packaging']];
        }
        usort($rows, fn($a, $b) => [$a['state'] !== 'short', $a['packaging'], $a['name']] <=> [$b['state'] !== 'short', $b['packaging'], $b['name']]);
        return ['rows' => $rows, 'ok' => $ok];
    }

    /** Krok 1 : przesunięcie bloku na inny dzień tygodnia (jak na tablicy tygodnia). */
    public function move(array $input, ?int $userId): array
    {
        $day = (string) ($input['date'] ?? '');
        try {
            return $this->week->move(['date' => $day, 'item_id' => $input['item_id'] ?? null, 'from' => $day, 'to' => $input['to'] ?? null], $this->ctx($userId));
        } catch (ApiException $e) {
            $codes = implode(', ', array_unique(array_map(fn($f) => is_array($f) ? (string) ($f['code'] ?? '') : (string) $f, $e->fieldErrors())));
            throw new CustomException('Nie można przesunąć bloku' . ($codes !== '' ? " ({$codes})" : '') . '.', 'errors', 422);
        }
    }

    /** Krok 3 : potwierdzenie planu (+ wiadomość na tablety grup) → zadanie zaliczone. */
    public function confirm(array $input, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data(is_string($input['date'] ?? null) ? $input['date'] : null, $userId, $today);
        if (!$d['groups']) throw new CustomException('Brak planu na ' . $d['label'] . ' — zaplanuj dzień na tablicy tygodnia.', 'errors', 422);
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 300) throw new CustomException('Wiadomość : najwyżej 300 znaków.', 'errors', 422);
        $sent = 0;
        if (!empty($input['tablets'])) {
            foreach ($d['groups'] as $g) {
                $msg = sprintf('Plan na %s : %d %s · %s kart. · %s h', $d['label'], $g['products'], $g['products'] === 1 ? 'produkt' : 'produktów',
                    self::num($g['cartons']), self::num($g['hours'], 1)) . ($note !== '' ? ' — ' . $note : '');
                $this->repo->tabletMessage($g['id'], (string) $g['name'], 'Plan na ' . $d['label'], $msg, $d['day'], $userId);
                $sent++;
            }
        }
        $t = $d['totals'];
        $summary = sprintf('plan na %s : %d %s · %s kart.%s', $d['label'], $t['groups'], $t['groups'] === 1 ? 'grupa' : ($t['groups'] < 5 ? 'grupy' : 'grup'),
            self::num($t['cartons']), $sent ? ' · wiadomość na ' . $sent . ' tabl.' : '');
        $this->repo->markDone(self::CODE, $today, $summary, $userId);
        return ['summary' => $summary, 'sent' => $sent];
    }

    public function pdf(?string $date, ?int $userId): array
    {
        $day = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $this->targetDay(date('Y-m-d'));
        $out = $this->sheet->dayListPdf($day, $this->ctx($userId));
        return ['filename' => $out['filename'], 'content' => base64_decode((string) $out['content_base64'])];
    }

    private function ctx(?int $userId): ApiContext
    {
        return new ApiContext('tomorrow-' . bin2hex(random_bytes(4)), new Translator('pl'), $userId, [], ['prod.plan.read' => true, 'prod.gantt.move' => true, 'prod.supply.read' => true]);
    }

    private static function num(float $v, int $d = 0): string
    {
        return number_format($v, $d, ',', "\u{00A0}");
    }
}
