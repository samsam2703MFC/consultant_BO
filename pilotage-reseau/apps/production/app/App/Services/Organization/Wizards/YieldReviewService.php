<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\YieldReviewRepository;
use App\Services\Purchasing\SupplierReviewService;
use App\Services\Workshop\Execution\OeeService;
use App\Services\Workshop\Execution\ProductionFollowUpService;

/**
 * Kreator « Wydajność i straty kwartału » [247] (makieta 23 ; Kierownik produkcji, co kwartał — poprzedni kwartał) :
 *   1. Straty : % i wartość (surowce kartonu) wg grup vs poprzedni kwartał, przyczyny z tabletów, produkty z największą stratą ;
 *   2. Wydajność : sztuki na godzinę pracy (zrobione ÷ godziny z odbić) vs poprzedni kwartał, plan zrobiony, TRS maszyn ;
 *   3. Działania : najwyżej 3 (co, cel, kto, grupa, do kiedy) → wracają na zebraniu produkcyjnym (n_wizard_action),
 *      opcjonalnie baner na tablecie grupy. « Zatwierdź przegląd » = zadanie zaliczone.
 */
final class YieldReviewService
{
    public const CODE = 'yield_reviewed';
    public const REASONS = ['quality' => 'Jakość / wygląd', 'burnt' => 'Przypalone', 'damaged' => 'Uszkodzone', 'samples' => 'Próbki / testy', 'other' => 'Inne'];
    public const MAX_ACTIONS = 3;

    public function __construct(private YieldReviewRepository $repo, private ProductionFollowUpService $followUp, private OeeService $oee, private LightWizardRepository $wizards)
    {
    }

    public function data(?string $q, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $Q = SupplierReviewService::quarter($q, $today);
        $P = SupplierReviewService::quarter(null, date('Y-m-d', strtotime($Q['from'] . ' +1 day')));   // kwartał przed ocenianym
        $cur = $this->period($Q);
        $prev = $this->period($P);
        $groups = [];
        foreach ($this->wizards->groups() as $g) {
            $c = $cur['groups'][$g['id']] ?? null;
            $p = $prev['groups'][$g['id']] ?? null;
            if (!$c && !$p) continue;
            $groups[] = ['id' => $g['id'], 'name' => $g['name'], 'waste_pct' => $c['waste_pct'] ?? null, 'waste_pct_prev' => $p['waste_pct'] ?? null, 'waste' => $c['waste'] ?? 0.0,
                'value' => round($c['value'] ?? 0.0, 2), 'produced' => $c['produced'] ?? 0.0, 'plan_pct' => $c['plan_pct'] ?? null, 'per_hour' => $c['per_hour'] ?? null,
                'per_hour_prev' => $p['per_hour'] ?? null, 'hours' => round($c['hours'] ?? 0.0, 1), 'trs' => $c['trs'] ?? null, 'trs_prev' => $p['trs'] ?? null];
        }
        $worst = null;
        foreach ($groups as $g) if ($g['waste_pct'] !== null && ($worst === null || $g['waste_pct'] > $worst['waste_pct'])) $worst = $g;
        $reasons = [];
        foreach ($cur['reasons'] as $k => $v) $reasons[] = ['code' => $k, 'label' => self::REASONS[$k] ?? $k, 'q' => $v['q'], 'value' => round($v['value'], 2)];
        usort($reasons, fn($a, $b) => $b['q'] <=> $a['q']);
        $done = $this->wizards->done(self::CODE, date('Y-m-d', strtotime($Q['to'])), date('Y-m-d', strtotime($Q['to'] . ' +3 months')));
        return ['quarter' => $Q, 'prev' => ['key' => $P['key'], 'label' => $P['label']], 'groups' => $groups, 'reasons' => $reasons, 'top' => $cur['top'], 'machines' => $cur['machines'],
            'totals' => ['waste_pct' => $cur['waste_pct'], 'waste_pct_prev' => $prev['waste_pct'], 'value' => round($cur['value'], 2), 'produced' => $cur['produced'],
                'worst' => $worst ? ['name' => $worst['name'], 'waste_pct' => $worst['waste_pct'], 'value' => $worst['value']] : null,
                'photos_pct' => null],
            'actions' => array_map(fn($a) => ['id' => (int) $a['id'], 'title' => $a['title'], 'goal' => $a['goal'], 'user' => $a['user_name'], 'group' => $a['group_name'], 'due_date' => $a['due_date'], 'status' => $a['status']],
                $this->wizards->actions(self::CODE, $Q['key'])),
            'users' => $this->wizards->users(), 'all_groups' => $this->wizards->groups(), 'max_actions' => self::MAX_ACTIONS,
            'done' => $done ? ['summary' => $done['summary'], 'day' => $done['day']] : null];
    }

    /** Liczby okresu : straty (szt., zł), zrobione, plan, godziny, sztuki / h, TRS — na grupę i razem. */
    private function period(array $Q): array
    {
        $to = date('Y-m-d', strtotime($Q['to'] . ' -1 day'));
        try {
            $fu = $this->followUp->summary($Q['from'], $to);
        } catch (\Throwable) {
            $fu = ['groups' => [], 'total' => []];
        }
        $groups = [];
        foreach ($fu['groups'] as $g) $groups[(int) $g['id']] = ['produced' => (float) $g['produced'], 'waste' => (float) $g['waste'], 'waste_pct' => $g['waste_pct'], 'plan_pct' => $g['plan_pct'], 'value' => 0.0];
        $reasons = [];
        $value = 0.0;
        foreach ($this->repo->waste($Q['from'], $to) as $w) {
            $gid = (int) $w['id_group'];
            $groups[$gid] ??= ['produced' => 0.0, 'waste' => 0.0, 'waste_pct' => null, 'plan_pct' => null, 'value' => 0.0];
            $groups[$gid]['value'] += (float) $w['value'];
            $value += (float) $w['value'];
            $reasons[$w['reason']] ??= ['q' => 0.0, 'value' => 0.0];
            $reasons[$w['reason']]['q'] += (float) $w['q'];
            $reasons[$w['reason']]['value'] += (float) $w['value'];
        }
        $hours = $this->repo->hours($Q['from'], $to);
        $produced = $this->repo->produced($Q['from'], $to);
        foreach ($groups as $gid => &$g) {
            $g['hours'] = $hours[$gid] ?? 0.0;
            $g['per_hour'] = ($hours[$gid] ?? 0) > 0 ? round(($produced[$gid] ?? $g['produced']) / $hours[$gid], 1) : null;
        }
        unset($g);
        $machines = [];
        try {
            foreach ($this->oee->summary($Q['from'], $to) as $m) {
                if (($m['run_h'] ?? 0) <= 0 && ($m['stop_h'] ?? 0) <= 0) continue;
                $machines[] = ['name' => $m['name'], 'group' => $m['group'] ?? null, 'group_id' => $m['group_id'] ?? null, 'oee' => $m['oee'], 'stop_h' => round((float) ($m['stop_h'] ?? 0), 1)];
                if (($m['group_id'] ?? null) !== null && $m['oee'] !== null) {
                    $gid = (int) $m['group_id'];
                    if (isset($groups[$gid])) $groups[$gid]['trs'] = isset($groups[$gid]['trs']) ? min($groups[$gid]['trs'], (float) $m['oee']) : (float) $m['oee'];
                }
            }
        } catch (\Throwable) {
        }
        $top = array_map(fn($r) => ['name' => $r['name'], 'q' => (float) $r['q'], 'value' => round((float) $r['value'], 2), 'group_id' => (int) $r['id_group']], $this->repo->topWaste($Q['from'], $to));
        return ['groups' => $groups, 'reasons' => $reasons, 'value' => $value, 'top' => $top, 'machines' => $machines,
            'waste_pct' => $fu['total']['waste_pct'] ?? null, 'produced' => (float) ($fu['total']['produced'] ?? 0)];
    }

    /** « Zatwierdź przegląd » : działania (najwyżej 3) + baner na tabletach grup → zadanie zaliczone. */
    public function finish(?string $q, array $actions, bool $tablets, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($q, $today);
        $users = array_column($d['users'], 'name', 'id');
        $groups = array_column($d['all_groups'], 'name', 'id');
        $clean = [];
        foreach ($actions as $i => $a) {
            if (!is_array($a)) continue;
            $title = trim((string) ($a['title'] ?? ''));
            if ($title === '') continue;
            if (mb_strlen($title) > 200) throw new CustomException('Działanie ' . ($i + 1) . ' : najwyżej 200 znaków.', 'errors', 422);
            $goal = mb_substr(trim((string) ($a['goal'] ?? '')), 0, 120);
            $uid = !empty($a['user_id']) ? (int) $a['user_id'] : null;
            $gid = !empty($a['group_id']) ? (int) $a['group_id'] : null;
            if ($uid !== null && !isset($users[$uid])) throw new CustomException('Działanie ' . ($i + 1) . ' : nieznana osoba.', 'errors', 422);
            if ($gid !== null && !isset($groups[$gid])) throw new CustomException('Działanie ' . ($i + 1) . ' : nieznana grupa.', 'errors', 422);
            $due = isset($a['due_date']) && is_string($a['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['due_date']) ? $a['due_date'] : null;
            $clean[] = ['title' => $title, 'goal' => $goal !== '' ? $goal : null, 'id_user' => $uid, 'id_group' => $gid, 'due_date' => $due];
        }
        if (count($clean) > self::MAX_ACTIONS) throw new CustomException('Najwyżej ' . self::MAX_ACTIONS . ' działania — wybierz najważniejsze.', 'errors', 422);
        if (!$clean) throw new CustomException('Wpisz co najmniej jedno działanie.', 'errors', 422);
        $this->wizards->deleteActions(self::CODE, $d['quarter']['key']);
        $sent = 0;
        foreach ($clean as $a) {
            $this->wizards->addAction($a + ['code' => self::CODE, 'period' => $d['quarter']['key'], 'created_by' => $userId]);
            if ($tablets && $a['id_group']) {
                $msg = $a['title'] . ($a['goal'] ? ' — cel : ' . $a['goal'] : '') . ($a['due_date'] ? ' — do ' . date('j.m', strtotime($a['due_date'])) : '');
                $this->wizards->tabletMessage($a['id_group'], $groups[$a['id_group']], 'Działanie po przeglądzie ' . $d['quarter']['label'], $msg, $today, $userId);
                $sent++;
            }
        }
        $t = $d['totals'];
        $summary = sprintf('%s : straty %s · %d %s', $d['quarter']['label'], $t['waste_pct'] === null ? '—' : number_format((float) $t['waste_pct'], 1, ',', '') . ' %',
            count($clean), count($clean) === 1 ? 'działanie' : 'działania') . ($sent ? ' · ' . $sent . ' na tablety' : '');
        $this->wizards->markDone(self::CODE, $today, $summary, $userId);
        return ['summary' => $summary, 'sent' => $sent];
    }
}
