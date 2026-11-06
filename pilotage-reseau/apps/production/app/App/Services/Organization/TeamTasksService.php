<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Exceptions\CustomException;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Organization\TaskWizardRepository;
use App\Repositories\Organization\TeamTasksRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Notification\AppNotificationService;
use DateTimeImmutable;

/**
 * Zadania zespołu [232] (makiety 7 i 8) — tablica Kierownika produkcji : zadania wszystkich grup produkcji (tablety),
 * stanowisk pracowni (pakowanie, kierowca, brygadzista) i stanowisk ERP (osoby z kontem) w okresie (dziś / tydzień /
 * miesiąc, ‹ › przesunięcie), zrobione / niezrobione / spóźnione, filtry (grupa / stanowisko, osoba, stan, częstotliwość,
 * kategoria, szukaj), kafelki i « Przypomnij » :
 *  - osoba z kontem ERP → dzwonek tylko dla niej + e-mail (adres z konta), przeczytane = dzwonek potwierdzony ;
 *  - grupa bez kont → komunikat na tablecie (grupy, pakowania, kierowcy ; brygadzista : tablety grup), « Przeczytane »
 *    PIN-em ; komunikat znika sam, gdy zadanie się zaliczy. Historia : kto, kiedy, do kogo, przeczytane.
 */
final class TeamTasksService
{
    public const PATH = '/organization/team-tasks';
    public const STATES = ['all', 'todo', 'late', 'done'];
    public const FREQUENCIES = ['daily' => 'codziennie', 'weekly' => 'co tydzień', 'monthly' => 'co miesiąc', 'quarterly' => 'co kwartał'];
    /** Stanowisko pracowni → tablet (odbiorca komunikatu). Grupy produkcji : TaskWizards::POSITION_GROUP. */
    private const TABLET_AUDIENCE = ['PAKOWACZ' => ['packing', 'Pakowanie (tablet pakowania)', 'komunikat w nagłówku tabletu pakowania'],
        'KIEROWCA' => ['driver', 'Kierowca (tablet kierowcy)', 'komunikat na tablecie kierowcy'],
        'BRYGADZISTA' => ['shift', 'Brygadzista zmiany (tablety grup)', 'komunikat na tabletach grup produkcji']];
    /** Grupy bez stanowiska z własnym tabletem (dzień grupy zatwierdzany na tablecie), poza pakowaniem. */
    private const SKIP_GROUP_CODES = ['PAKOWANIE'];
    private const DAY_ABBR = [1 => 'pn.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];
    private const DAY_LONG = [1 => 'poniedziałek', 2 => 'wtorek', 3 => 'środa', 4 => 'czwartek', 5 => 'piątek', 6 => 'sobota', 7 => 'niedziela'];
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    /** Komunikat na tablecie najwyżej tyle dni. */
    private const TABLET_DAYS = 7;

    public function __construct(
        private PositionTaskService $tasks,
        private PositionRepository $positions,
        private TeamTasksRepository $repo,
        private TaskWizardRepository $wizardRepo,
        private PageAccessService $access,
        private AppNotificationService $notifications,
        private OutgoingMessageService $outgoing,
    ) {
    }

    public function canUse(?int $actorId): bool
    {
        return $this->access->isAdmin($actorId) || $this->access->canSee($actorId, self::PATH);
    }

    private function assertAccess(?int $actorId): void
    {
        if (!$this->canUse($actorId)) throw new CustomException('Brak dostępu do tablicy zadań zespołu.', 'errors', 403);
    }

    /**
     * Tablica : okres, opcje filtrów, liczniki stanów, kafelki i sekcje (grupa / stanowisko) z zadaniami.
     * @param array{view?:string, offset?:int|string, group?:string, person?:string, state?:string, freq?:string, category?:string, q?:string} $q
     */
    public function board(array $q, ?int $actorId, ?DateTimeImmutable $today = null): array
    {
        $this->assertAccess($actorId);
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $todayStr = $today->format('Y-m-d');
        $period = $this->period((string) ($q['view'] ?? 'week'), (int) ($q['offset'] ?? 0), $today);
        $sections = $this->sections();
        $items = [];
        foreach ($this->tasks->itemsBetween($period['from'], $period['to'], $today) as $it) {
            $key = $this->sectionKeyFor($it['position_code'], $it['position_id']);
            if (!isset($sections[$key])) continue;
            $items[] = $it + ['section' => $key, 'group_id' => null];
        }
        foreach ($this->groupDays($sections, $period['from'], $period['to'], $todayStr) as $it) $items[] = $it;

        // Przypomnienia terminów okresu : ostatnie na zadanie + liczba.
        $reminders = [];
        foreach ($this->repo->remindersBetween($period['from'], $period['to']) as $r) {
            $k = $this->itemKey($r['id_task'] !== null ? (int) $r['id_task'] : null, $r['audience'], (string) $r['due_date']);
            $reminders[$k][] = ['id' => (int) $r['id'], 'to' => $r['target_label'], 'kind' => $r['target_kind'], 'channels' => $r['channels'], 'sent_at' => $r['sent_at'],
                'sent_by' => $r['sent_by_name'], 'urgent' => (bool) $r['urgent'], 'read_at' => $r['read_when'], 'read_via' => $r['read_how'], 'read_by' => $r['read_by_name'],
                'closed' => $r['closed_at'] !== null, 'message' => $r['message']];
        }

        // Filtry (bez stanu) → liczniki segmentu stanów ; potem stan.
        $groupKey = (string) ($q['group'] ?? '');
        $personKeys = $this->personSections((string) ($q['person'] ?? ''), $sections);
        $personUser = preg_match('/^u(\d+)$/', (string) ($q['person'] ?? ''), $m) ? (int) $m[1] : null;
        $freq = isset(self::FREQUENCIES[$q['freq'] ?? '']) ? (string) $q['freq'] : '';
        $category = preg_match('/^\d+$/', (string) ($q['category'] ?? '')) ? (int) $q['category'] : null;
        $needle = mb_strtolower(trim((string) ($q['q'] ?? '')));
        $state = in_array($q['state'] ?? 'todo', self::STATES, true) ? (string) ($q['state'] ?? 'todo') : 'todo';
        $filtered = [];
        foreach ($items as $it) {
            if ($groupKey !== '' && $it['section'] !== $groupKey) continue;
            if ($personKeys !== null && !in_array($it['section'], $personKeys, true) && !($personUser !== null && ($it['done_by_id'] ?? null) === $personUser)) continue;
            if ($freq !== '' && $it['frequency'] !== $freq) continue;
            if ($category !== null && $it['category_id'] !== $category) continue;
            if ($needle !== '' && !str_contains(mb_strtolower($it['title'] . ' ' . $sections[$it['section']]['name'] . ' ' . ($it['summary'] ?? '') . ' ' . ($it['category'] ?? '')), $needle)) continue;
            $filtered[] = $it;
        }
        $isDone = fn($it) => in_array($it['state'], ['ok', 'auto'], true);
        $counts = ['all' => count($filtered), 'todo' => 0, 'late' => 0, 'done' => 0];
        $tiles = ['done' => 0, 'total' => count($filtered), 'open' => 0, 'open_due' => 0, 'open_later' => 0, 'late' => 0, 'late_2' => 0, 'running' => 0];
        foreach ($filtered as $it) {
            if ($isDone($it)) { $counts['done']++; $tiles['done']++; continue; }
            $counts['todo']++;
            $tiles['open']++;
            if ($it['due'] <= $todayStr) $tiles['open_due']++; else $tiles['open_later']++;
            if ($it['state'] === 'late') { $counts['late']++; $tiles['late']++; if ($it['late_days'] > 2) $tiles['late_2']++; }
            if ($it['state'] === 'run') $tiles['running']++;
        }
        $sentToday = $this->repo->sentOn($todayStr);
        $tiles['reminders'] = $sentToday['sent'];
        $tiles['reminders_read'] = $sentToday['read'];

        // Sekcje w kolejności : tablety grup, pracownia, stanowiska ERP ; wiersz = termin zadania.
        $out = [];
        foreach ($sections as $key => $s) {
            $rows = [];
            $sDone = 0;
            $sTotal = 0;
            foreach ($filtered as $it) {
                if ($it['section'] !== $key) continue;
                $sTotal++;
                if ($isDone($it)) $sDone++;
                if ($state === 'todo' && $isDone($it)) continue;
                if ($state === 'late' && $it['state'] !== 'late') continue;
                if ($state === 'done' && !$isDone($it)) continue;
                $k = $this->itemKey($it['task'], $s['audience'], $it['due']);
                $rows[] = $this->row($it, $s, $reminders[$k] ?? []);
            }
            if (!$sTotal || (!$rows && $state !== 'all')) continue;
            usort($rows, fn($a, $b) => [$this->stateRank($a['state']), $a['due'], $a['title']] <=> [$this->stateRank($b['state']), $b['due'], $b['title']]);
            $out[] = ['key' => $key, 'kind' => $s['kind'], 'name' => $s['name'], 'short' => $s['short'], 'initials' => $s['initials'], 'color' => $s['color'],
                'sub' => $s['sub'], 'done' => $sDone, 'total' => $sTotal, 'remind' => $s['remind'], 'channel' => $s['channel'], 'rows' => $rows];
        }

        return [
            'today' => $todayStr, 'now' => date('Y-m-d H:i:s'), 'period' => $period,
            'filters' => ['view' => $period['view'], 'offset' => $period['offset'], 'group' => $groupKey, 'person' => (string) ($q['person'] ?? ''), 'state' => $state,
                'freq' => $freq, 'category' => $category, 'q' => (string) ($q['q'] ?? '')],
            'options' => $this->options($sections, $items),
            'counts' => $counts, 'tiles' => $tiles, 'sections' => $out,
        ];
    }

    private function stateRank(string $s): int
    {
        return ['late' => 0, 'run' => 1, 'todo' => 2, 'plan' => 3, 'ok' => 4, 'auto' => 5][$s] ?? 6;
    }

    private function row(array $it, array $s, array $reminders): array
    {
        $who = $s['kind'] === 'erp'
            ? ($it['done_by'] ?? null ? $it['done_by'] : ($s['people'] ? implode(', ', $s['people']) : $s['short']))
            : $s['short'];
        return [
            'key' => $this->itemKey($it['task'], $s['audience'], $it['due']), 'task' => $it['task'], 'group_id' => $it['group_id'] ?? null, 'occurrence' => $it['occurrence'],
            'title' => $it['title'], 'wizard' => $it['wizard'], 'hint' => $this->hint($it), 'who' => $who, 'who_initials' => $s['kind'] === 'erp' && count($s['people']) === 1 ? self::initials($s['people'][0]) : $s['initials'],
            'due' => $it['due'], 'due_label' => $this->dayLabel($it['due']), 'due_time' => $it['due_time'], 'state' => $it['state'], 'late_days' => $it['late_days'],
            'step' => $it['step'], 'steps' => $it['steps'], 'step_label' => $it['step_label'], 'summary' => $it['summary'], 'done_by' => $it['done_by'], 'done_at' => $it['done_at'],
            'frequency' => $it['frequency'], 'category' => $it['category'], 'link' => $it['link'], 'escalated' => $it['escalated'],
            'can_remind' => !in_array($it['state'], ['ok', 'auto'], true) && $s['remind'] !== null,
            'reminders' => array_slice($reminders, 0, 5), 'reminder_count' => count($reminders),
        ];
    }

    /** Podpowiedź pod nazwą : kreator i kroki (np. plan → zrobione → straty → zatwierdź). */
    private function hint(array $it): ?string
    {
        $h = $this->rawHint($it);
        // Nazwa zadania z lot 3 zawiera już opis w nawiasie (« … (plan → zrobione → straty → zatwierdź) ») — bez powtórzenia.
        return $h !== null && (str_contains($it['title'], $h) || str_contains($it['title'], '(')) ? null : $h;
    }

    private function rawHint(array $it): ?string
    {
        return match ($it['auto_code'] ?? null) {
            'group_day_closed', null => ($it['group_id'] ?? null) !== null || ($it['auto_code'] ?? null) === 'group_day_closed' ? 'plan → zrobione → straty → zatwierdź' : null,
            'receiving_checked' => 'tablet pakowania › Przyjęcia',
            'packing_done' => 'tablet pakowania › Pakowanie',
            'route_done' => 'tablet kierowcy › trasa dnia',
            'shift_day_validated' => 'wszystkie grupy zatwierdzają dzień na tabletach',
            'payments_reviewed' => 'wpłaty, przypomnienia, różnice dostaw',
            'suppliers_reviewed' => 'oceny A / B / C, surowce, zapytanie ofertowe',
            default => $it['wizard'],
        };
    }

    private function itemKey(?int $task, ?string $audience, string $due): string
    {
        return ($task !== null ? 't' . $task : 'a' . $audience) . '|' . $due;
    }

    /**
     * Sekcje tablicy : grupy produkcji (tablet grupy, także bez stanowiska — np. Tarty), stanowiska pracowni z tabletem
     * (pakowanie, kierowca, brygadzista) i stanowiska ERP z osobami (dzwonek + e-mail). @return array<string, array>
     */
    private function sections(): array
    {
        $groups = $this->repo->groups();
        $groupByCode = [];
        foreach ($groups as $g) $groupByCode[$g['code']] = $g;
        $users = [];
        foreach ($this->positions->users() as $u) if ($u['id_position']) $users[(int) $u['id_position']][] = ['id' => (int) $u['id'], 'name' => trim((string) ($u['displayed_name'] ?: $u['login'])), 'email' => $u['email'] ?? null];
        $tablet = [];
        $atelier = [];
        $erp = [];
        foreach ($this->positions->positions() as $p) {
            if ((int) $p['active'] !== 1) continue;
            $pid = (int) $p['id'];
            if (($p['kind'] ?? '') === 'atelier') {
                $gCode = TaskWizards::POSITION_GROUP[$p['code']] ?? null;
                if ($gCode !== null && isset($groupByCode[$gCode])) {
                    $g = $groupByCode[$gCode];
                    $tablet['g' . $g['id']] = $this->groupSection($g, $p);
                    continue;
                }
                $a = self::TABLET_AUDIENCE[$p['code']] ?? null;
                $pp = $this->repo->positionPeople($pid);
                $name = $a[1] ?? ($p['name_pl'] . ' (tablet)');
                $atelier['p' . $pid] = ['kind' => 'tablet', 'name' => $name, 'short' => preg_replace('/\s*\(.*$/', '', $name), 'initials' => self::initials($name), 'color' => '#2b5f9e',
                    'position_id' => $pid, 'position_code' => $p['code'], 'group_id' => null, 'audience' => $a[0] ?? null, 'people' => [],
                    'sub' => $pp['people'] ? sprintf('%d %s · %d %s', $pp['people'], self::pl($pp['people'], 'osoba', 'osoby', 'osób'), $pp['present'], self::pl($pp['present'], 'obecna', 'obecne', 'obecnych')) : null,
                    'remind' => $a ? 'tablet' : null, 'channel' => $a[2] ?? null, 'present' => $pp['present']];
                continue;
            }
            if (!isset($users[$pid])) continue;
            $names = array_column($users[$pid], 'name');
            $emails = count(array_filter($users[$pid], fn($u) => filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)));
            $erp['p' . $pid] = ['kind' => 'erp', 'name' => $p['name_pl'], 'short' => $p['name_pl'], 'initials' => self::initials($names[0] ?? $p['name_pl']), 'color' => '#8e1f2f',
                'position_id' => $pid, 'position_code' => $p['code'], 'group_id' => null, 'audience' => null, 'people' => $names, 'users' => $users[$pid],
                'sub' => implode(', ', $names), 'remind' => 'user', 'channel' => 'dzwonek ERP' . ($emails ? ' + e-mail (adres z konta)' : ' (brak adresu e-mail)'), 'present' => null];
        }
        // Grupy bez stanowiska (np. Tarty) : dzień zatwierdzany na tablecie grupy.
        foreach ($groups as $g) {
            if (isset($tablet['g' . $g['id']]) || in_array($g['code'], self::SKIP_GROUP_CODES, true)) continue;
            $tablet['g' . $g['id']] = $this->groupSection($g, null);
        }
        return $tablet + $atelier + $erp;
    }

    private function groupSection(array $g, ?array $position): array
    {
        $present = (int) $g['present'];
        return ['kind' => 'tablet', 'name' => $g['name_pl'] . ' (tablet grupy)', 'short' => $g['name_pl'], 'initials' => self::initials($g['name_pl']), 'color' => $g['color'] ?: '#1f5a37',
            'position_id' => $position ? (int) $position['id'] : null, 'position_code' => $position['code'] ?? null, 'group_id' => (int) $g['id'], 'group_code' => $g['code'],
            'audience' => 'group:' . (int) $g['id'], 'people' => [],
            'sub' => sprintf('%d %s · %d %s', (int) $g['people'], self::pl((int) $g['people'], 'osoba', 'osoby', 'osób'), $present, self::pl($present, 'obecna', 'obecne', 'obecnych')),
            'remind' => 'tablet', 'channel' => 'komunikat na ekranie startowym tabletu do potwierdzenia PIN-em', 'present' => $present];
    }

    private function sectionKeyFor(string $positionCode, int $positionId): string
    {
        static $groupIds = null;
        if ($groupIds === null) {
            $groupIds = [];
            foreach ($this->repo->groups() as $g) $groupIds[$g['code']] = (int) $g['id'];
        }
        $gCode = TaskWizards::POSITION_GROUP[$positionCode] ?? null;
        if ($gCode !== null && isset($groupIds[$gCode])) return 'g' . $groupIds[$gCode];
        return 'p' . $positionId;
    }

    /**
     * Dni grup bez stanowiska (tablet grupy) : zatwierdzony = zrobione ; pozycje wpisane bez zatwierdzenia = w toku / spóźnione ;
     * dzień miniony bez produkcji — pomijany (grupa nie pracuje każdego dnia).
     */
    private function groupDays(array $sections, string $from, string $to, string $today): array
    {
        $out = [];
        $work = $this->tasks->workdayNumbers();
        foreach ($sections as $key => $s) {
            if (($s['group_id'] ?? null) === null || $s['position_id'] !== null) continue;
            for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
                $day = $d->format('Y-m-d');
                if (!in_array((int) $d->format('N'), $work, true)) continue;
                $r = $day <= $today ? $this->wizardRepo->groupDay($day, $s['group_code']) : ['closed_at' => null, 'lines' => 0];
                if ($r['closed_at']) $state = 'auto';
                elseif ($day > $today) $state = 'plan';
                elseif ($r['lines'] > 0) $state = $day < $today ? 'late' : 'run';
                elseif ($day === $today) $state = 'todo';
                else continue;
                $out[] = ['task' => null, 'occurrence' => null, 'title' => 'Dzień zatwierdzony na tablecie', 'position_id' => null, 'position_code' => null, 'position_name' => $s['name'],
                    'position_kind' => 'atelier', 'frequency' => 'daily', 'category_id' => null, 'category' => 'Produkcja', 'auto_code' => null, 'wizard' => 'Dzień grupy (tablet produkcji)',
                    'wizard_steps' => 1, 'due' => $day, 'due_time' => null, 'state' => $state, 'late_days' => $state === 'late' ? (int) floor((strtotime($today) - strtotime($day)) / 86400) : 0,
                    'done_by' => null, 'done_by_id' => null, 'done_at' => $r['closed_at'], 'summary' => $r['closed_at'] ? 'dzień zatwierdzony ' . substr((string) $r['closed_at'], 11, 5)
                        : ($r['lines'] > 0 ? $r['lines'] . ' ' . self::pl($r['lines'], 'pozycja wpisana', 'pozycje wpisane', 'pozycji wpisanych') . ', dzień niezatwierdzony' : null),
                    'step' => $state === 'run' ? 1 : null, 'steps' => $state === 'run' ? 1 : null, 'step_label' => null, 'escalated' => false, 'link' => '/production/follow-up',
                    'minutes' => null, 'section' => $key, 'group_id' => $s['group_id']];
            }
        }
        return $out;
    }

    /** Osoba (filtr) → sekcje : użytkownik ERP → jego stanowisko ; pracownik → stanowisko pracowni / grupa. null = wszyscy. */
    private function personSections(string $person, array $sections): ?array
    {
        if ($person === '') return null;
        if (preg_match('/^u(\d+)$/', $person, $m)) {
            $u = $this->positions->user((int) $m[1]);
            return $u && $u['id_position'] ? [$this->sectionKeyFor((string) $u['position_code'], (int) $u['id_position'])] : [];
        }
        if (preg_match('/^e(\d+)$/', $person, $m)) {
            foreach ($this->repo->employees() as $e) {
                if ((int) $e['id'] !== (int) $m[1]) continue;
                $keys = [];
                if ($e['id_primary_group'] !== null && isset($sections['g' . (int) $e['id_primary_group']])) $keys[] = 'g' . (int) $e['id_primary_group'];
                foreach ($sections as $k => $s) if ($e['id_position'] !== null && ($s['position_id'] ?? null) === (int) $e['id_position']) $keys[] = $k;
                return array_values(array_unique($keys));
            }
        }
        return [];
    }

    /** Opcje filtrów : grupy / stanowiska (z liczbą zadań w okresie), osoby, kategorie, częstotliwości. */
    private function options(array $sections, array $items): array
    {
        $per = [];
        foreach ($items as $it) $per[$it['section']] = ($per[$it['section']] ?? 0) + 1;
        $groups = [];
        foreach ($sections as $k => $s) if (isset($per[$k])) $groups[] = ['key' => $k, 'name' => $s['name'], 'kind' => $s['kind'], 'count' => $per[$k]];
        $people = [];
        foreach ($sections as $k => $s) {
            if ($s['kind'] !== 'erp' || !isset($per[$k])) continue;
            foreach ($s['users'] as $u) $people[] = ['key' => 'u' . $u['id'], 'name' => $u['name'], 'sub' => $s['name'], 'kind' => 'erp'];
        }
        $posSection = [];
        foreach ($sections as $k => $s) if ($s['position_id'] !== null) $posSection[$s['position_id']] = $k;
        foreach ($this->repo->employees() as $e) {
            $k = $e['id_position'] !== null ? ($posSection[(int) $e['id_position']] ?? null) : null;
            if ($k === null && $e['id_primary_group'] !== null && isset($sections['g' . (int) $e['id_primary_group']])) $k = 'g' . (int) $e['id_primary_group'];
            if ($k === null || !isset($per[$k])) continue;
            $people[] = ['key' => 'e' . $e['id'], 'name' => $e['name'], 'sub' => $sections[$k]['short'], 'kind' => 'tablet'];
        }
        $cats = [];
        foreach ($items as $it) if ($it['category_id'] !== null) $cats[$it['category_id']] = $it['category'];
        asort($cats);
        $freq = [];
        foreach ($items as $it) $freq[$it['frequency']] = true;
        return ['groups' => $groups, 'people' => $people,
            'categories' => array_map(fn($id, $n) => ['id' => $id, 'name' => $n], array_keys($cats), array_values($cats)),
            'frequencies' => array_values(array_map(fn($f) => ['key' => $f, 'name' => self::FREQUENCIES[$f]], array_filter(array_keys(self::FREQUENCIES), fn($f) => isset($freq[$f]))))];
    }

    /** Okres : dziś (dzień), tydzień (dni robocze tygodnia), miesiąc ; offset = przesunięcie (‹ ›). */
    private function period(string $view, int $offset, DateTimeImmutable $today): array
    {
        $view = in_array($view, ['day', 'week', 'month'], true) ? $view : 'week';
        $offset = max(-52, min(52, $offset));
        if ($view === 'day') {
            $d = $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
            $label = self::DAY_ABBR[(int) $d->format('N')] . ' ' . $d->format('d.m.Y');
            return ['view' => $view, 'offset' => $offset, 'from' => $d->format('Y-m-d'), 'to' => $d->format('Y-m-d'), 'label' => $label, 'short' => $offset === 0 ? 'dziś' : $d->format('d.m')];
        }
        if ($view === 'week') {
            $monday = $today->setISODate((int) $today->format('o'), (int) $today->format('W'), 1)->modify(($offset >= 0 ? '+' : '') . ($offset * 7) . ' days');
            $work = $this->tasks->workdayNumbers();
            $first = $monday->modify('+' . (min($work) - 1) . ' days');
            $last = $monday->modify('+' . (max($work) - 1) . ' days');
            return ['view' => $view, 'offset' => $offset, 'from' => $monday->format('Y-m-d'), 'to' => $monday->modify('+6 days')->format('Y-m-d'),
                'label' => sprintf('tydzień %d (%s–%s)', (int) $monday->format('W'), $first->format('d.m'), $last->format('d.m.Y')), 'short' => 'tydz. ' . (int) $monday->format('W')];
        }
        $first = $today->modify('first day of this month')->modify(($offset >= 0 ? '+' : '') . $offset . ' months');
        return ['view' => $view, 'offset' => $offset, 'from' => $first->format('Y-m-d'), 'to' => $first->format('Y-m-t'),
            'label' => self::MONTHS[(int) $first->format('n')] . ' ' . $first->format('Y'), 'short' => self::MONTHS[(int) $first->format('n')]];
    }

    // --- Przypomnij -------------------------------------------------------------------------------------------------

    /**
     * « Wyślij przypomnienie » (makieta 8) : zadania (task + termin ; dzień grupy bez stanowiska : group_id + termin),
     * wiadomość (wspólna, opcjonalna — inaczej tekst z zadania), pilność, kanały. Osoby stanowiska ERP : dzwonek + e-mail
     * (jeden e-mail na osobę z listą zadań) ; grupy : komunikat na tablecie. @return array{batch:string, reminders:int, users:int, tablets:int, emails:int, skipped:list<string>}
     */
    public function remind(array $body, ?int $actorId, ?DateTimeImmutable $today = null): array
    {
        $this->assertAccess($actorId);
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $list = $body['items'] ?? null;
        if (!is_array($list) || !$list || count($list) > 60) throw new CustomException('Wybierz zadania do przypomnienia (najwyżej 60).', 'errors', 422);
        $channels = is_array($body['channels'] ?? null) ? $body['channels'] : [];
        $bell = ($channels['bell'] ?? true) !== false;
        $email = ($channels['email'] ?? true) !== false;
        $tablet = ($channels['tablet'] ?? true) !== false;
        if (!$bell && !$email && !$tablet) throw new CustomException('Wybierz co najmniej jeden kanał.', 'errors', 422);
        $urgent = !empty($body['urgent']);
        $custom = trim((string) ($body['message'] ?? ''));
        if (mb_strlen($custom) > 1000) throw new CustomException('Wiadomość jest za długa (najwyżej 1000 znaków).', 'errors', 422);

        $sections = $this->sections();
        $wanted = [];
        $from = null;
        $to = null;
        foreach ($list as $x) {
            $due = (string) ($x['due'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new CustomException('Nieprawidłowy termin zadania.', 'errors', 422);
            $task = isset($x['task']) && $x['task'] !== null ? (int) $x['task'] : null;
            $group = isset($x['group_id']) && $x['group_id'] !== null ? (int) $x['group_id'] : null;
            if ($task === null && $group === null) throw new CustomException('Brak zadania.', 'errors', 422);
            $wanted[($task !== null ? 't' . $task : 'g' . $group) . '|' . $due] = true;
            $from = $from === null || $due < $from ? $due : $from;
            $to = $to === null || $due > $to ? $due : $to;
        }
        // Stan zadań w chwili wysłania (bez zrobionych).
        $found = [];
        foreach ($this->tasks->itemsBetween($from, $to, $today) as $it) {
            $k = 't' . $it['task'] . '|' . $it['due'];
            if (isset($wanted[$k])) $found[$k] = $it + ['section' => $this->sectionKeyFor($it['position_code'], $it['position_id']), 'group_id' => null];
        }
        foreach ($this->groupDays($sections, $from, $to, $today->format('Y-m-d')) as $it) {
            $k = 'g' . $it['group_id'] . '|' . $it['due'];
            if (isset($wanted[$k])) $found[$k] = $it;
        }
        $batch = bin2hex(random_bytes(8));
        $now = date('Y-m-d H:i:s');
        $actor = $actorId ? $this->positions->user($actorId) : null;
        $actorName = $actor ? trim((string) ($actor['displayed_name'] ?: $actor['login'])) : 'Kierownik produkcji';
        $skipped = [];
        $stats = ['reminders' => 0, 'users' => [], 'tablets' => [], 'emails' => 0];
        $mails = [];
        foreach (array_keys($wanted) as $k) {
            $it = $found[$k] ?? null;
            if ($it === null) { $skipped[] = 'zadanie nie istnieje w tym terminie'; continue; }
            if (in_array($it['state'], ['ok', 'auto'], true)) { $skipped[] = '« ' . $it['title'] . ' » jest już zrobione'; continue; }
            $s = $sections[$it['section']] ?? null;
            if ($s === null || $s['remind'] === null) { $skipped[] = '« ' . $it['title'] . ' » : brak odbiorcy'; continue; }
            $message = $custom !== '' ? $custom : $this->autoMessage($it, $s, $today);
            $base = ['batch' => $batch, 'id_task' => $it['task'], 'id_occurrence' => $it['occurrence'], 'due_date' => $it['due'], 'title' => mb_substr($it['title'], 0, 255),
                'message' => $message, 'urgent' => $urgent ? 1 : 0, 'sent_by' => $actorId, 'sent_at' => $now];
            if ($s['remind'] === 'tablet') {
                if (!$tablet) { $skipped[] = '« ' . $it['title'] . ' » : kanał « tablet » wyłączony'; continue; }
                $this->repo->insertReminder($base + ['target_kind' => 'tablet', 'audience' => $s['audience'], 'target_label' => $s['short'], 'channels' => 'tablet']);
                $stats['reminders']++;
                $stats['tablets'][$s['audience']] = true;
                continue;
            }
            if (!$bell && !$email) { $skipped[] = '« ' . $it['title'] . ' » : dzwonek i e-mail wyłączone'; continue; }
            foreach ($s['users'] as $u) {
                $mailOk = $email && filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL);
                $ch = implode(',', array_filter([$bell ? 'bell' : null, $mailOk ? 'email' : null]));
                if ($ch === '') { $skipped[] = $u['name'] . ' : brak adresu e-mail'; continue; }
                $id = $this->repo->insertReminder($base + ['target_kind' => 'user', 'id_user' => $u['id'], 'target_label' => $u['name'], 'channels' => $ch]);
                $notification = null;
                if ($bell) {
                    $notification = $this->notifications->publishForUser($u['id'], 'task_reminder', $urgent ? 'danger' : 'warning', 'Przypomnienie : ' . $it['title'],
                        $message . ' · od ' . $actorName, $it['link'] ?: '/today', ['reminder' => $id, 'task' => $it['task'], 'due' => $it['due']], 'team-remind-' . $id);
                }
                $this->repo->setNotification($id, $notification, null);
                if ($mailOk) $mails[$u['id']]['user'] = $u;
                if ($mailOk) $mails[$u['id']]['items'][] = ['reminder' => $id, 'title' => $it['title'], 'due' => $it['due'], 'message' => $message, 'link' => $it['link']];
                $stats['reminders']++;
                $stats['users'][$u['id']] = true;
            }
        }
        // Jeden e-mail na osobę : lista zadań, wiadomość, link do ERP (wysyłka przez kolejkę i MAIL_DELIVERY_MODE).
        foreach ($mails as $m) {
            $u = $m['user'];
            $lines = array_map(fn($x) => '• ' . $x['title'] . ' — termin ' . $this->dayLabel($x['due']) . "\n  " . $x['message'], $m['items']);
            $subject = ($urgent ? '[PILNE] ' : '') . 'Przypomnienie : ' . (count($m['items']) === 1 ? $m['items'][0]['title'] : count($m['items']) . ' zadania do zrobienia');
            $text = 'Dzień dobry ' . $u['name'] . ",\n\n" . $actorName . " przypomina o zadaniach :\n\n" . implode("\n\n", $lines)
                . "\n\nLista zadań w ERP : Dzisiaj › Mój tydzień.\n";
            try {
                $messageId = $this->outgoing->queue(['message_type' => 'task_reminder', 'template' => [], 'recipient' => $u['email'], 'subject' => $subject, 'body' => $text,
                    'variables' => ['user' => $u['name'], 'tasks' => count($m['items'])], 'created_by' => 'team-tasks:' . $batch]);
                foreach ($m['items'] as $x) $this->repo->setNotification($x['reminder'], $this->notificationOf($x['reminder']), $messageId);
                $stats['emails']++;
            } catch (\Throwable $e) {
                error_log('team-tasks mail: ' . $e->getMessage());
                $skipped[] = $u['name'] . ' : e-mail nie został zapisany do wysyłki';
            }
        }
        if ($stats['reminders'] === 0) throw new CustomException('Nic nie wysłano : ' . ($skipped ? implode(' ; ', array_unique($skipped)) : 'brak odbiorców') . '.', 'errors', 422);
        return ['batch' => $batch, 'reminders' => $stats['reminders'], 'users' => count($stats['users']), 'tablets' => count($stats['tablets']), 'emails' => $stats['emails'],
            'skipped' => array_values(array_unique($skipped))];
    }

    private function notificationOf(int $reminderId): ?int
    {
        $r = $this->repo->reminder($reminderId);
        return $r && $r['id_notification'] !== null ? (int) $r['id_notification'] : null;
    }

    /** Tekst z zadania : « Dzień zatwierdzony na tablecie » — wtorek 03.11 nie jest zrobione (…). Proszę zrobić dziś. */
    private function autoMessage(array $it, array $s, DateTimeImmutable $today): string
    {
        $d = new DateTimeImmutable($it['due']);
        $when = $it['due'] === $today->format('Y-m-d') ? 'dziś' : self::DAY_LONG[(int) $d->format('N')] . ' ' . $d->format('d.m');
        $status = $it['state'] === 'run' ? 'jest w toku' . ($it['step_label'] ? ' (' . $it['step_label'] . ')' : '') : 'nie jest zrobione';
        $hint = $this->hint($it);
        return 'Przypomnienie od kierownika produkcji : « ' . $it['title'] . ' » — ' . $when . ' ' . $status . ($hint ? ' (' . $hint . ')' : '') . '. Proszę zrobić'
            . ($it['due_time'] && $it['due'] >= $today->format('Y-m-d') ? ' do ' . $it['due_time'] : ' dziś') . '.';
    }

    // --- Tablety : komunikaty i « Przeczytane » ------------------------------------------------------------------

    /**
     * Komunikaty dla tabletu : tablet grupy (grupa tabletu albo dotknięta na tablecie ogólnym) + brygadzista ; tablet
     * pakowania ; tablet kierowcy. Zadanie zaliczone → komunikat zamknięty (znika). @param string $for group|packing|driver
     */
    public function tabletMessages(ApiContext $context, string $for, ?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $audiences = match ($for) {
            'packing' => ['packing'],
            'driver' => ['driver'],
            default => $context->deviceGroupId !== null ? ['group:' . $context->deviceGroupId, 'shift'] : [],
        };
        $out = [];
        $rows = $this->repo->tabletMessages($audiences, $today->modify('-' . self::TABLET_DAYS . ' days')->format('Y-m-d 00:00:00'));
        $doneCache = [];
        foreach ($rows as $r) {
            $k = ($r['id_task'] ?? 'g' . $r['audience']) . '|' . $r['due_date'];
            $doneCache[$k] ??= $this->taskDone($r, $today);
            if ($doneCache[$k]) { $this->repo->close((int) $r['id']); continue; }
            $out[] = ['id' => (int) $r['id'], 'title' => $r['title'], 'message' => $r['message'], 'urgent' => (bool) $r['urgent'], 'due' => $r['due_date'],
                'due_label' => $this->dayLabel((string) $r['due_date']), 'sent_at' => $r['sent_at'], 'from' => 'Od kierownika produkcji', 'from_name' => $r['sent_by_name']];
        }
        return $out;
    }

    /** Zadanie przypomnienia zaliczone teraz (wystąpienie zrobione, kreator zakończony, dzień grupy zatwierdzony). */
    private function taskDone(array $r, DateTimeImmutable $today): bool
    {
        $due = (string) $r['due_date'];
        if ($r['id_task'] === null) {
            if (!preg_match('/^group:(\d+)$/', (string) $r['audience'], $m)) return false;
            $g = $this->repo->groups()[(int) $m[1]] ?? null;
            return $g !== null && $this->wizardRepo->groupDay($due, $g['code'])['closed_at'] !== null;
        }
        foreach ($this->tasks->itemsBetween($due, $due, $today) as $it) {
            if ($it['task'] === (int) $r['id_task']) return in_array($it['state'], ['ok', 'auto'], true);
        }
        return true; // zadanie usunięte / nieaktywne
    }

    /**
     * « Przeczytane (PIN) » : osoba z PIN-em potwierdza komunikat ($verifyPin sprawdza PIN jak przy logowaniu na tablecie) ;
     * osoba bez PIN-u, obecna w pracy — dotknięciem.
     * @param callable(array $body): array $verifyPin zwraca pracownika ['id' => …] albo rzuca ApiException
     */
    public function readTabletMessage(int $id, array $body, ApiContext $context, string $for, callable $verifyPin): array
    {
        $r = $this->repo->reminder($id);
        if ($r === null || $r['target_kind'] !== 'tablet') throw new ApiException('NOT_FOUND', 404);
        $allowed = array_column($this->tabletMessages($context, $for), 'id');
        if (!in_array($id, $allowed, true) && $r['read_at'] === null) throw new ApiException('NOT_FOUND', 404);
        $employeeId = is_int($body['employee_id'] ?? null) ? $body['employee_id'] : null;
        if ($employeeId === null) throw ApiException::validation([['field' => 'employee_id', 'code' => 'REQUIRED', 'params' => []]]);
        $via = 'pin';
        $pin = isset($body['pin']) && $body['pin'] !== null ? (string) $body['pin'] : '';
        if ($pin === '') {
            // Bez PIN-u tylko osoba, która go nie ma i jest dziś w pracy (wejście w biurze) ; z ustawionym PIN-em — PIN.
            if ($this->repo->hasPin($employeeId) || !$this->repo->isPresent($employeeId)) throw ApiException::validation([['field' => 'pin', 'code' => 'REQUIRED', 'params' => []]]);
            $via = 'tap';
        } else {
            $employee = $verifyPin(['employee_id' => $employeeId, 'pin' => $pin]);
            $employeeId = (int) $employee['id'];
        }
        $this->repo->markRead($id, $employeeId, $via);
        return ['id' => $id, 'read' => true, 'by' => $this->repo->employeeName($employeeId), 'via' => $via];
    }

    // --- Pomocnicze -------------------------------------------------------------------------------------------------

    private function dayLabel(string $d): string
    {
        $t = strtotime($d);
        return self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('d.m', $t);
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/[\s\-—–()\/]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $parts = array_values(array_filter($parts, fn($p) => mb_strlen($p) > 1 || ctype_upper($p)));
        $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
        $b = isset($parts[1]) && count($parts) > 1 && !in_array(mb_strtolower($parts[1]), ['i', 'ds.', 'tablet', 'grupy'], true) ? mb_strtoupper(mb_substr($parts[1], 0, 1)) : mb_strtoupper(mb_substr($parts[0] ?? '?', 1, 1));
        return $a . $b;
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
