<?php
declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Helpers\ComplaintReasonTranslator;
use App\Repositories\Notification\DeadlineAlertRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Execution\DevProductService;

/**
 * Alertes et échéances (cloche de l'ERP), lancées avec Bin/mail_dispatch.php : réclamation sans décision depuis
 * alerts.complaint_days jours (puis alerte rouge après alerts.complaint_escalate_days), essai d'un nouveau produit
 * planifié et pas fait, date visée d'un nouveau produit dépassée, coûts du mois précédent non saisis à partir du
 * alerts.cost_due_day du mois, écart d'expédition sans décision à l'échéance (shipping_issue.decision_days). Chaque
 * alerte n'est publiée qu'une fois (clé de dédoublonnage) ; la page Dzisiaj
 * montre les mêmes points dans « Do zrobienia ».
 */
final class DeadlineAlertService
{
    private const MONTHS_PL = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(
        private DeadlineAlertRepository $repo,
        private ParamRepository $params,
        private AppNotificationService $notifications,
    ) {
    }

    /** @return array{complaints:int, trials:int, targets:int, costs:int, shipping:int} alertes examinées (publiées une seule fois chacune) */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $out = ['complaints' => 0, 'trials' => 0, 'targets' => 0, 'costs' => 0, 'shipping' => 0];

        $days = $this->int('alerts.complaint_days', 3, 1);
        $escalate = max($days, $this->int('alerts.complaint_escalate_days', 7, 1));
        foreach ($this->repo->lateComplaints($now->modify("-{$days} days")->format('Y-m-d H:i:s')) as $c) {
            $age = (int) floor(($now->getTimestamp() - strtotime((string) $c['received_at'])) / 86400);
            $urgent = $age > $escalate;
            $this->notifications->publish('complaint_late', $urgent ? 'danger' : 'warning',
                'Reklamacja bez decyzji od ' . $age . ' ' . ($age === 1 ? 'dnia' : 'dni'),
                implode(' · ', array_filter([$c['customer'], $c['product'], ComplaintReasonTranslator::translate($c['complaint_reason'] ?? null)]))
                    . ($c['status'] === 'IN_REVIEW' ? ' — w weryfikacji.' : ' — nowa.'),
                '/franchise-complaints', ['complaint_id' => (int) $c['id'], 'days' => $age], ($urgent ? 'complaint_late_urgent:' : 'complaint_late:') . $c['id']);
            $out['complaints']++;
        }

        foreach ($this->repo->lateTrials($now->format('Y-m-d')) as $t) {
            $this->notifications->publish('dev_trial_late', 'warning', 'Nowy produkt : test ' . $t['trial_no'] . ' zaległy',
                trim((string) $t['name']) . ' — zaplanowany na ' . date('d.m.Y', strtotime((string) $t['planned_date'])) . ($t['group_name'] ? ' (' . $t['group_name'] . ')' : '')
                    . ' ; zespół potwierdza go na tablecie grupy albo biuro zmienia datę.',
                '/test-products/' . $t['product_id'] . '/tablet', ['id_test_product' => (int) $t['product_id'], 'trial_id' => (int) $t['id']], 'dev_trial_late:' . $t['id']);
            $out['trials']++;
        }

        foreach ($this->repo->lateTargets($now->format('Y-m-d')) as $p) {
            $this->notifications->publish('dev_target_late', 'info', 'Nowy produkt : minął termin wprowadzenia',
                trim((string) $p['name']) . ' — termin ' . date('d.m.Y', strtotime((string) $p['target_date'])) . ', etap « ' . (DevProductService::STAGES[$p['stage']][0] ?? $p['stage']) . ' ».',
                '/test-products/' . $p['id'] . '/tablet', ['id_test_product' => (int) $p['id']], 'dev_target_late:' . $p['id'] . ':' . $p['target_date']);
            $out['targets']++;
        }

        foreach ($this->repo->lateShippingIssues($now->format('Y-m-d')) as $i) {
            $what = $i['source'] === 'delivery' ? 'Problem przy dostawie' : 'Różnica wysyłki';
            $this->notifications->publish('shipping_issue_late', 'warning', $what . ' bez decyzji',
                trim((string) ($i['customer'] ?: 'Klient')) . ' — od ' . date('d.m.Y', strtotime((string) $i['opened_on'])) . ', termin decyzji ' . date('d.m.Y', strtotime((string) $i['due_on']))
                    . ($i['owner'] ? ' ; odpowiedzialny : ' . $i['owner'] : ' ; brak odpowiedzialnego') . '.',
                '/shipments/differences', ['issue_id' => (int) $i['id']], 'shipping_issue_late:' . $i['id']);
            $out['shipping']++;
        }

        foreach ($this->missingCostMonths($now) as [$ym, $source]) {
            $this->notifications->publish('cost_month_missing', 'danger', 'Brak kosztów za ' . self::monthLabel($ym),
                'Rentowność, wynik grup i raport miesięczny liczą ' . self::monthLabel($ym) . ($source ? ' na kosztach z ' . self::monthLabel($source) : ' bez kosztów')
                    . '. Uzupełnij personel, media i koszty stałe w kalkulatorze kosztów.',
                '/cost-calculator/' . $ym, ['month' => $ym], 'cost_month_missing:' . $ym);
            $out['costs']++;
        }
        return $out;
    }

    /** « Do zrobienia » de la page Dzisiaj : essais en retard, lancements à faire, coûts du mois manquants. */
    public function todo(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $items = [];
        $late = count($this->repo->lateTrials($now->format('Y-m-d')));
        if ($late) {
            $items[] = ['tone' => 'warning', 'icon' => 'bi-kanban', 'count' => $late,
                'one' => 'zaległy test nowego produktu', 'few' => 'zaległe testy nowych produktów', 'many' => 'zaległych testów nowych produktów',
                'url' => '/test-products/pipeline', 'positions' => ['KIEROWNIK_PRODUKCJI', 'JAKOSC']];
        }
        $launch = $this->repo->waitingLaunch();
        if ($launch) {
            $items[] = ['tone' => 'info', 'icon' => 'bi-rocket-takeoff', 'count' => $launch,
                'one' => 'nowy produkt czeka na wprowadzenie', 'few' => 'nowe produkty czekają na wprowadzenie', 'many' => 'nowych produktów czeka na wprowadzenie',
                'note' => 'receptura zatwierdzona na tablecie — połącz z produktem ERP', 'url' => '/test-products/pipeline', 'positions' => ['KIEROWNIK_PRODUKCJI']];
        }
        $issues = array_column($this->repo->openShippingIssues(), 'n', 'status');
        $toDecide = (int) ($issues['open'] ?? 0);
        if ($toDecide) {
            $late = count($this->repo->lateShippingIssues($now->format('Y-m-d')));
            $items[] = ['tone' => $late ? 'danger' : 'warning', 'icon' => 'bi-exclamation-diamond', 'count' => $toDecide,
                'one' => 'różnica wysyłki czeka na decyzję', 'few' => 'różnice wysyłki czekają na decyzję', 'many' => 'różnic wysyłki czeka na decyzję',
                'note' => $late ? $late . ' po terminie' : null, 'url' => '/shipments/differences', 'positions' => ['ADMINISTRACJA', 'LOGISTYKA']];
        }
        $missing = $this->missingCostMonths($now);
        if ($missing) {
            $items[] = ['tone' => 'danger', 'icon' => 'bi-calculator', 'count' => count($missing),
                'one' => 'miesiąc bez kosztów', 'few' => 'miesiące bez kosztów', 'many' => 'miesięcy bez kosztów',
                'note' => implode(', ', array_map(fn($m) => self::monthLabel($m[0]), $missing)) . ' — rentowność liczona na szacunku',
                'url' => '/cost-calculator/' . $missing[0][0], 'positions' => ['ADMINISTRACJA']];
        }
        return $items;
    }

    /**
     * Mois sans coûts saisis, à partir du jour alerts.cost_due_day : du mois qui suit le dernier mois saisi jusqu'au
     * mois précédent (6 au plus) ; chacun avec le dernier mois saisi avant lui (repris en estimation).
     * @return list<array{0:string, 1:?string}>
     */
    private function missingCostMonths(\DateTimeImmutable $now): array
    {
        if ((int) $now->format('j') < $this->int('alerts.cost_due_day', 10, 1)) return [];
        $previous = $now->modify('first day of last month');
        $entered = $this->repo->costMonthsEntered();
        $last = $entered ? end($entered) : null;
        $out = [];
        for ($k = 5; $k >= 0; $k--) {
            $ym = $previous->modify("-{$k} months")->format('Y-m');
            if (($last !== null && $ym <= $last) || in_array($ym, $entered, true)) continue;
            $out[] = [$ym, $last];
        }
        if ($last === null) $out = [[$previous->format('Y-m'), null]];
        return $out;
    }

    private function int(string $code, int $default, int $min): int
    {
        $v = $this->params->get($code, (string) $default);
        return is_numeric($v) && (int) $v >= $min ? (int) $v : $default;
    }

    private static function monthLabel(string $ym): string
    {
        return self::MONTHS_PL[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4);
    }
}
