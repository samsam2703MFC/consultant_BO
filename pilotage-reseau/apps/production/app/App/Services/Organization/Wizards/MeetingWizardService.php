<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Exceptions\CustomException;
use App\Repositories\Analysis\MonthlyReportRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Organization\TaskFormRepository;

/**
 * Kreator « Zebranie produkcyjne » [247] (makieta 20) nad formularzem protokołu (TaskFormService, bez zmian w bazie) :
 *   1. Tydzień w liczbach (śledzenie produkcji : plan, straty, TRS, niezgodności ; reklamacje tygodnia),
 *   2. Sprawy i decyzje (działania z poprzednich zebrań i z przeglądów kwartału, nowe sprawy : decyzja, kto, do kiedy),
 *   3. Protokół (zapis końcowy = zadanie zrobione) + wiadomość z decyzjami na tablety grup.
 */
final class MeetingWizardService
{
    public const FORM = 'production_meeting';

    public function __construct(private MonthlyReportRepository $report, private LightWizardRepository $repo, private TaskFormRepository $forms)
    {
    }

    /** Dane do kroków 1–2 poza formularzem : reklamacje okresu, działania z przeglądów, grupy. */
    public function extras(array $open): array
    {
        $from = $open['follow_up']['from'] ?? date('Y-m-d', strtotime('-7 days'));
        $to = $open['follow_up']['to'] ?? date('Y-m-d', strtotime('-1 day'));
        try {
            $complaints = $this->report->complaintTotals($from, $to);
        } catch (\Throwable) {
            $complaints = null;
        }
        return ['complaints' => $complaints, 'review_actions' => $this->repo->openActions(), 'groups' => $this->repo->groups()];
    }

    /** Krok 2 : działanie z przeglądu zrobione / otwarte ponownie. */
    public function reviewAction(int $id, bool $done, ?int $userId): void
    {
        $this->repo->setActionStatus($id, $done, $userId);
    }

    /** Krok 3 : decyzje zakończonego protokołu → baner « Od kierownika produkcji » na tabletach grup. @return int liczba tabletów */
    public function tablets(int $occurrenceId, ?int $userId): int
    {
        $form = $this->forms->byOccurrence($occurrenceId) ?? throw new CustomException('Najpierw zapisz protokół.', 'errors', 422);
        if (($form['status'] ?? '') !== 'final') throw new CustomException('Najpierw zakończ protokół.', 'errors', 422);
        $actions = $this->forms->actions((int) $form['id']);
        if (!$actions) return 0;
        $day = date('j.m', strtotime((string) $form['meeting_date']));
        $lines = [];
        foreach (array_slice($actions, 0, 6) as $i => $a) {
            $lines[] = ($i + 1) . ') ' . $a['title'] . ($a['due_date'] ? ' — do ' . date('j.m', strtotime((string) $a['due_date'])) : '');
        }
        $msg = 'Zebranie ' . $day . ' : ' . implode(' ', $lines);
        $n = 0;
        foreach ($this->repo->groups() as $g) {
            $this->repo->tabletMessage($g['id'], $g['name'], 'Decyzje zebrania ' . $day, mb_substr($msg, 0, 1000), date('Y-m-d'), $userId);
            $n++;
        }
        return $n;
    }
}
