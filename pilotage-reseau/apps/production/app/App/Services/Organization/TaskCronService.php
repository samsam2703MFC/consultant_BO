<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\Translator;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Organization\TaskWizardRepository;
use App\Services\Forecast\ForecastService;
use App\Services\Forecast\PanelSeasonService;
use App\Services\Notification\AppNotificationService;
use App\Services\Production\Cost\MonthCloseService;
use App\Services\Workshop\Product\ItemService;
use App\Repositories\Workshop\ParamRepository;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;

/**
 * Zadania wykonywane automatycznie (cron) [227] : przełącznik « Automatycznie » w zadaniu stanowiska — kiedy (codziennie
 * w dni tygodnia, co tydzień w dzień, co miesiąc / co kwartał w dzień reguły wd1, d1…), o której, co robi (ACTIONS), potem
 * « czeka na osobę » (przygotowane, osoba kończy kreator) albo « zalicza się samo ». Jedno udane uruchomienie na okres
 * crona ; zaległe (serwer nie działał) nadrabia się w tym samym okresie ; błąd : najwyżej 3 próby co 30 min i dzwonek.
 * W tym samym przebiegu (Bin/task_cron.php co 5 min) : wystąpienia bieżących okresów, zaliczenie po kreatorach,
 * przypomnienie w dniu terminu (o godzinie zadania) i przełożony po N dniach spóźnienia.
 */
final class TaskCronService
{
    /** Akcje do wyboru : kod => [nazwa, opis wyniku]. */
    public const ACTIONS = [
        'forecast_panel' => 'Odczyt panelu TF Buddy + przeliczenie prognozy 13 tygodni',
        'stock_recalc' => 'Przelicz stany min. / maks. z 6 tygodni (produkty MTS)',
        'month_prepare' => 'Rozpocznij zamknięcie poprzedniego miesiąca (kalkulator kosztów, personel z budżetu)',
    ];
    public const MAX_TRIES = 3;
    public const RETRY_MINUTES = 30;
    /** Godziny zadań (cron, przypomnienia) — czas polski, niezależnie od strefy serwera. */
    public const TZ = 'Europe/Warsaw';
    /** Spóźnienia starsze niż próg + tyle dni nie dzwonią (pierwsze uruchomienie, stare zaległości). */
    public const ESCALATE_WINDOW_DAYS = 7;

    public function __construct(
        private TaskWizardRepository $repo,
        private PositionRepository $positions,
        private PositionTaskService $tasks,
        private AppNotificationService $notifications,
        private ParamRepository $params,
        /** Akcje (stany, panel i prognoza, zamknięcie miesiąca) : dużo zależności — tylko gdy akcja się wykonuje. */
        private ContainerInterface $container,
    ) {
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone(self::TZ));
    }

    /** Okres crona (dzień, tydzień ISO, miesiąc, kwartał). */
    public static function periodKey(string $freq, DateTimeImmutable $now): string
    {
        return match ($freq) {
            'daily' => $now->format('Y-m-d'),
            'weekly' => $now->format('o-\WW'),
            'monthly' => $now->format('Y-m'),
            'quarterly' => $now->format('Y') . '-Q' . (intdiv((int) $now->format('n') - 1, 3) + 1),
            default => throw new \InvalidArgumentException('Nieznana częstotliwość crona: ' . $freq),
        };
    }

    /**
     * Czy zadanie ma się wykonać teraz : dzień harmonogramu osiągnięty w okresie (codziennie : dzisiaj w dniach crona ;
     * tydzień : dzień tygodnia minął ; miesiąc / kwartał : dzień reguły minął) i godzina minęła.
     */
    public function scheduled(array $task, DateTimeImmutable $now): bool
    {
        $freq = (string) $task['cron_freq'];
        $time = substr((string) $task['cron_time'], 0, 5);
        $days = (string) ($task['cron_days'] ?? '');
        $dow = (int) $now->format('N');
        $today = $now->format('Y-m-d');
        $rule = preg_match('/^(wd|d)\d{1,2}$/', $days) ? $days : 'd1';
        // Dzień uruchomienia w okresie ; później w tym samym okresie (serwer nie działał) — nadrabia od razu.
        $day = match ($freq) {
            'daily' => in_array($dow, $days !== '' && !str_starts_with($days, 'd') && !str_starts_with($days, 'wd') ? array_map('intval', explode(',', $days)) : [1, 2, 3, 4, 5], true) ? $today : null,
            'weekly' => $now->setISODate((int) $now->format('o'), (int) $now->format('W'), preg_match('/^[1-7]$/', $days) ? (int) $days : 1)->format('Y-m-d'),
            'monthly' => $this->tasks->monthDue($now->format('Y-m'), $rule),
            'quarterly' => $this->tasks->monthDue(sprintf('%s-%02d', $now->format('Y'), (intdiv((int) $now->format('n') - 1, 3)) * 3 + 1), $rule),
            default => null,
        };
        if ($day === null || $today < $day) return false;
        return $today > $day || $now->format('H:i') >= $time;
    }

    /** Czy trzeba uruchomić : w harmonogramie, bez udanego uruchomienia w okresie, bez trwającego, limit prób po błędzie. */
    public function due(array $task, DateTimeImmutable $now): bool
    {
        if (!(int) $task['cron_on'] || !isset(self::ACTIONS[$task['cron_action'] ?? '']) || !$this->scheduled($task, $now)) return false;
        $runs = $this->repo->runs((int) $task['id'], self::periodKey((string) $task['cron_freq'], $now));
        $failed = 0;
        foreach ($runs as $r) {
            if ((int) ($r['ok'] ?? 0) === 1) return false;
            if ($r['ok'] === null && self::ts((string) $r['started_at']) > $now->getTimestamp() - self::RETRY_MINUTES * 60) return false;
            if ($r['ok'] !== null) $failed++;
        }
        if ($failed >= self::MAX_TRIES) return false;
        return !$runs || self::ts((string) $runs[0]['started_at']) <= $now->getTimestamp() - self::RETRY_MINUTES * 60;
    }

    /** Godzina zapisana w dzienniku (czas polski) → znacznik czasu, niezależnie od strefy serwera. */
    private static function ts(string $at): int
    {
        return (new DateTimeImmutable($at, new \DateTimeZone(self::TZ)))->getTimestamp();
    }

    /**
     * Przebieg (co 5 min) : wystąpienia i zaliczenia, zadania automatyczne do wykonania, przypomnienia, przełożeni.
     * @return array{ran:list<array>, reminded:int, escalated:int}
     */
    public function tick(?DateTimeImmutable $now = null): array
    {
        $now ??= self::now();
        $this->tasks->ensureCurrent($now);
        $ran = [];
        foreach ($this->repo->cronTasks() as $task) {
            if (!$this->due($task, $now)) continue;
            $ran[] = $this->run($task, $now);
        }
        [$reminded, $escalated] = $this->remind($now);
        return ['ran' => $ran, 'reminded' => $reminded, 'escalated' => $escalated];
    }

    /** Uruchamia akcję zadania (też « Uruchom teraz » w planie stanowiska). */
    public function run(array $task, DateTimeImmutable $now): array
    {
        $key = self::periodKey((string) ($task['cron_freq'] ?: 'daily'), $now);
        $action = (string) $task['cron_action'];
        $id = $this->repo->startRun((int) $task['id'], $key, $action, $now->format('Y-m-d H:i:s'));
        $t0 = microtime(true);
        try {
            $summary = $this->execute($action, $now);
            $this->repo->finishRun($id, true, $summary, null, self::now()->format('Y-m-d H:i:s'));
            if (($task['cron_then'] ?? 'wait') === 'done') {
                $period = $this->tasks->taskPeriod($task, $now);
                if ($period) {
                    $this->positions->ensureOccurrence((int) $task['id'], $period['key'], $period['due']);
                    $o = $this->tasks->occurrenceFor((int) $task['id'], $period['key']);
                    if ($o && $o['status'] === 'open') $this->positions->autoDone((int) $o['id'], 'Automatycznie (cron) ' . $now->format('H:i') . ' : ' . self::ACTIONS[$action], $summary);
                }
            }
            return ['task' => (int) $task['id'], 'ok' => true, 'summary' => $summary, 'seconds' => round(microtime(true) - $t0, 1)];
        } catch (\Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 240);
            $this->repo->finishRun($id, false, null, $msg, self::now()->format('Y-m-d H:i:s'));
            $this->notifications->publish('task_cron_failed', 'danger', 'Zadanie automatyczne nie powiodło się : ' . $task['title_pl'],
                ($task['position_name'] ?? '') . ' — ' . self::ACTIONS[$action] . ' : ' . $msg, '/organization/execution', ['task' => (int) $task['id'], 'period' => $key],
                'task-cron-fail-' . $task['id'] . '-' . $key . '-' . $id);
            return ['task' => (int) $task['id'], 'ok' => false, 'error' => $msg];
        }
    }

    /** Akcja crona : krótki wynik do karty zadania. */
    private function execute(string $action, DateTimeImmutable $now): string
    {
        switch ($action) {
            case 'stock_recalc':
                $ctx = new ApiContext('cron-' . bin2hex(random_bytes(4)), new Translator('pl'), null, ['system'], ['prod.products.write' => true]);
                $r = $this->container->get(ItemService::class)->applyStockSuggestions([], $ctx);
                return $r['applied'] . ' produktów przeliczonych' . ($r['failed'] ? ' · ' . count($r['failed']) . ' pominiętych' : '');
            case 'forecast_panel':
                $panelService = $this->container->get(PanelSeasonService::class);
                if (!$panelService->configured()) throw new \RuntimeException('Konto API panelu nie jest skonfigurowane.');
                $panel = $panelService->refresh(null, $now->getTimestamp(), (int) $this->params->get('forecast.weeks', '13'), (int) $this->params->get('forecast.trend_weeks', '8'));
                $f = $this->container->get(ForecastService::class)->compute($now->format('Y-m-d'), $panel);
                return 'panel odczytany · ' . number_format((int) $f['totals']['cartons'], 0, ',', "\u{00A0}") . ' kart. w prognozie';
            case 'month_prepare':
                $ym = $now->modify('first day of last month')->format('Y-m');
                try {
                    $r = $this->container->get(MonthCloseService::class)->start($ym);
                } catch (\App\Exceptions\CustomException $e) {
                    if ((int) $e->getCode() === 409) return TaskWizards::monthLabel($ym) . ' już zamknięty';
                    throw $e;
                }
                return TaskWizards::monthLabel($ym) . ' rozpoczęty · ' . (int) $r['synced'] . ' osób z budżetu';
        }
        throw new \InvalidArgumentException('Nieznana akcja: ' . $action);
    }

    /**
     * Przypomnienie w dniu terminu (o godzinie zadania) i przełożony po N dniach spóźnienia (dzwonek ; raz na wystąpienie).
     * @return array{0:int, 1:int}
     */
    public function remind(DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $reminded = 0;
        $escalated = 0;
        foreach ($this->repo->openUntil($today) as $o) {
            $people = $this->repo->positionPeople((int) $o['id_position']);
            $who = $people ? implode(', ', $people) : 'nikt nie ma tego stanowiska';
            if ($o['due_date'] === $today && $o['remind_time'] !== null && $o['reminded_at'] === null && $now->format('H:i') >= substr((string) $o['remind_time'], 0, 5)) {
                $due = $o['due_time'] ? ' do ' . substr((string) $o['due_time'], 0, 5) : '';
                $this->notifications->publish('task_reminder', 'info', 'Dziś termin' . $due . ' : ' . $o['title_pl'], $o['position_name'] . ' · ' . $who, '/today',
                    ['occurrence' => (int) $o['id']], 'task-remind-' . $o['id']);
                $this->repo->markReminded((int) $o['id'], $now->format('Y-m-d H:i:s'));
                $reminded++;
            }
            if ($o['escalate_days'] === null || $o['escalated_at'] !== null) continue;
            $late = (int) floor((strtotime($today) - strtotime((string) $o['due_date'])) / 86400);
            if ($late < max(1, (int) $o['escalate_days']) || $late > max(1, (int) $o['escalate_days']) + self::ESCALATE_WINDOW_DAYS) continue;
            $boss = $this->repo->positionName($o['id_reports_to'] !== null ? (int) $o['id_reports_to'] : null) ?? 'Administrator';
            $this->notifications->publish('task_late', 'warning', sprintf('Spóźnione %d %s : %s', $late, $late === 1 ? 'dzień' : 'dni', $o['title_pl']),
                $o['position_name'] . ' (' . $who . ') — termin ' . date('d.m', strtotime((string) $o['due_date'])) . ' · przełożony : ' . $boss, '/organization/execution',
                ['occurrence' => (int) $o['id']], 'task-late-' . $o['id']);
            $this->repo->markEscalated((int) $o['id'], $now->format('Y-m-d H:i:s'));
            $escalated++;
        }
        return [$reminded, $escalated];
    }
}
