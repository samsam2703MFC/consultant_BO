<?php
declare(strict_types=1);

namespace App\Controllers\Web\Workshop;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Workshop\Time\TimeService;
use App\Services\Workshop\Time\TimeSheetMailService;
use App\Services\Workshop\Time\TimeSheetPdf;
use Symfony\Component\HttpFoundation\Request;

/**
 * [262] Czas pracy (moduł Pointages) : Dzień (zatwierdzenie odbić — zadanie nr 1 kierownika produkcji), Miesiąc (wszyscy ×
 * dni, karty A4), Urlopy i dni wolne (kalendarz), Nadgodziny (bank, odbiór w 3 miesiące, dzień wolny z podpisem).
 * Strony : bramka uprawnień App (klucz « time ») ; zapisy ajax : to samo prawo sprawdzane tutaj.
 */
final class TimeController extends BaseController
{
    use JsonErrors;

    public function __construct(private TimeService $time, private TimeSheetPdf $pdf, private PageAccessService $access, private TimeSheetMailService $mail)
    {
    }

    // --- Strony ------------------------------------------------------------------------------------------

    public function day()
    {
        $date = self::date($_GET['date'] ?? null) ?? date('Y-m-d');
        $this->page('day', $this->time->day($date));
    }

    public function month()
    {
        $month = self::month_($_GET['month'] ?? null) ?? date('Y-m');
        $this->page('month', $this->monthWithMail($month));
    }

    public function leave()
    {
        $from = self::date($_GET['from'] ?? null) ?? date('Y-m-d', strtotime('monday this week'));
        $this->page('leave', $this->time->calendar($from, (int) ($_GET['days'] ?? 28)));
    }

    public function overtime()
    {
        $this->page('overtime', $this->time->overtime());
    }

    /** Karta A4 : ?employee=ID (jedna osoba) albo bez — wszyscy z miesiąca. */
    public function sheetPdf()
    {
        $month = self::month_($_GET['month'] ?? null) ?? date('Y-m');
        try {
            $ids = isset($_GET['employee']) && ctype_digit((string) $_GET['employee'])
                ? [(int) $_GET['employee']]
                : array_column($this->time->month($month)['people'], 'employee_id');
            if (!$ids) throw new CustomException('Brak odbić w tym miesiącu.', 'errors', 404);
            $file = $this->pdf->render($ids, $month);
        } catch (CustomException $e) {
            http_response_code($e->getCode() >= 400 && $e->getCode() < 500 ? (int) $e->getCode() : 422);
            echo htmlspecialchars($e->getMessage());
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file['filename'] . '"');
        header('Cache-Control: private, no-store');
        echo $file['content'];
    }

    private function page(string $tab, array $data): void
    {
        $this->view('workshop/time', [
            'tab' => $tab, 'data' => $data, 'is_admin' => $this->access->isAdmin($this->userId()),
            'page_title' => ['day' => 'Czas pracy — dzień', 'month' => 'Czas pracy — miesiąc', 'leave' => 'Urlopy i dni wolne', 'overtime' => 'Nadgodziny'][$tab],
        ]);
    }

    // --- Dane (ajax) -------------------------------------------------------------------------------------

    public function dayData()
    {
        return $this->guarded(fn() => $this->time->day(self::date($_GET['date'] ?? null) ?? date('Y-m-d')));
    }

    public function monthData()
    {
        return $this->guarded(fn() => $this->monthWithMail(self::month_($_GET['month'] ?? null) ?? date('Y-m')));
    }

    public function leaveData()
    {
        return $this->guarded(fn() => $this->time->calendar(self::date($_GET['from'] ?? null) ?? date('Y-m-d', strtotime('monday this week')), (int) ($_GET['days'] ?? 28)));
    }

    public function overtimeData()
    {
        return $this->guarded(fn() => $this->time->overtime());
    }

    // --- Zapisy (ajax) -----------------------------------------------------------------------------------

    /**
     * Powód nadgodzin (kind overtime) albo nieobecności (kind absence) ; reason pusty = usuń. employee_ids : ten sam powód
     * dla kilku osób naraz (np. wszyscy bez odbicia tego dnia).
     */
    public function saveReason()
    {
        return $this->guarded(function () {
            $in = $this->input();
            $ids = isset($in['employee_ids']) && is_array($in['employee_ids']) ? array_values(array_unique(array_map('intval', $in['employee_ids']))) : [(int) ($in['employee_id'] ?? 0)];
            if (count($ids) > 200) throw new CustomException('Za dużo osób naraz.', 'errors', 422);
            $day = (string) ($in['day'] ?? '');
            $reason = (string) ($in['reason'] ?? '');
            $note = isset($in['note']) ? (string) $in['note'] : null;
            foreach ($ids as $employee) {
                match ($in['kind'] ?? '') {
                    'overtime' => $this->time->setOvertimeReason($employee, $day, $reason, $note, $this->userId()),
                    'absence' => $this->time->setAbsenceReason($employee, $day, $reason, $note, $this->userId()),
                    default => throw new CustomException('Nieznany rodzaj powodu.', 'errors', 422),
                };
            }
            $who = count($ids) > 1 ? ' (' . count($ids) . ' osób)' : '';
            return ['message' => ($reason === '' ? 'Usunięto powód.' : 'Zapisano powód.') . $who, 'data' => $this->time->day($day)];
        });
    }

    public function validateDay($day)
    {
        return $this->guarded(fn() => ['message' => 'Dzień ' . date('d.m.Y', strtotime((string) $day)) . ' zatwierdzony.', 'data' => $this->time->validateDay((string) $day, $this->userId())]);
    }

    public function reopenDay($day)
    {
        return $this->guarded(function () use ($day) {
            $this->time->reopenDay((string) $day, $this->userId());
            return ['message' => 'Zatwierdzenie cofnięte — dzień do ponownego zatwierdzenia.', 'data' => $this->time->day((string) $day)];
        });
    }

    public function correctClock($id)
    {
        return $this->guarded(function () use ($id) {
            $in = $this->input();
            $clock = $this->time->correctClock((int) $id, $in['clock_in'] ?? null, $in['clock_out'] ?? null, (string) ($in['note'] ?? ''), $this->userId());
            return ['message' => 'Odbicie poprawione (w dzienniku).', 'data' => $this->time->day(substr((string) $clock['clock_in'], 0, 10))];
        });
    }

    public function addClock()
    {
        return $this->guarded(function () {
            $in = $this->input();
            $clock = $this->time->addClock((int) ($in['employee_id'] ?? 0), (string) ($in['clock_in'] ?? ''), (string) ($in['clock_out'] ?? ''), (string) ($in['note'] ?? ''), $this->userId());
            return ['message' => 'Dodano odbicie ręczne (w dzienniku).', 'data' => $this->time->day(substr((string) $clock['clock_in'], 0, 10))];
        });
    }

    public function closeMonth($month)
    {
        return $this->guarded(fn() => ['message' => 'Miesiąc zamknięty.', 'data' => $this->time->closeMonth((string) $month, $this->userId())]);
    }

    /** Ponowne otwarcie zamkniętego miesiąca : tylko administrator. */
    public function reopenMonth($month)
    {
        return $this->guarded(function () use ($month) {
            if (!$this->access->isAdmin($this->userId())) throw new CustomException('Zamknięty miesiąc otwiera ponownie tylko administrator.', 'errors', 403);
            $this->time->reopenMonth((string) $month, $this->userId());
            return ['message' => 'Miesiąc otwarty ponownie.', 'data' => $this->time->month((string) $month)];
        });
    }

    public function addAbsence()
    {
        return $this->guarded(function () {
            $in = $this->input();
            $this->time->addAbsence((int) ($in['employee_id'] ?? 0), (string) ($in['type'] ?? ''), (string) ($in['from'] ?? ''), (string) ($in['to'] ?? ($in['from'] ?? '')),
                isset($in['note']) ? (string) $in['note'] : null, $this->userId());
            return ['message' => 'Dodano do kalendarza.'];
        });
    }

    public function deleteAbsence($id)
    {
        return $this->guarded(function () use ($id) {
            $this->time->deleteAbsence((int) $id, $this->userId());
            return ['message' => 'Usunięto z kalendarza.'];
        });
    }

    public function saveLeaveRight($id)
    {
        return $this->guarded(function () use ($id) {
            $this->time->setLeaveRight((int) $id, (int) ($this->input()['days'] ?? -1), $this->userId());
            return ['message' => 'Zapisano wymiar urlopu.'];
        });
    }

    public function proposeComp()
    {
        return $this->guarded(function () {
            $in = $this->input();
            $comp = $this->time->proposeComp((int) ($in['employee_id'] ?? 0), (string) ($in['day'] ?? ''), (int) ($in['minutes'] ?? 0), $this->userId());
            return ['message' => 'Dzień wolny zaproponowany — do podpisu pracownika (tutaj albo na tablecie biurowym).', 'data' => $comp];
        });
    }

    /** Podpis pracownika na ekranie kierownika (ta sama ramka podpisu co tablet). */
    public function signComp($id)
    {
        return $this->guarded(fn() => ['message' => 'Podpisane — dzień wolny w kalendarzu, bank nadgodzin zmniejszony.',
            'data' => $this->time->signComp((int) $id, $this->input()['image'] ?? null, null, null, $this->userId())]);
    }

    public function cancelComp($id)
    {
        return $this->guarded(function () use ($id) {
            $this->time->cancelComp((int) $id, $this->userId());
            return ['message' => 'Dzień wolny anulowany.'];
        });
    }

    // --- [266] Adresy e-mail pracowników i karty e-mailem ---------------------------------------------------

    /** Wszyscy aktywni pracownicy z adresem i ostatnią wysyłką karty miesiąca. */
    public function contacts()
    {
        return $this->guarded(fn() => $this->mail->contacts(self::month_($_GET['month'] ?? null) ?? date('Y-m')));
    }

    /** Zapis kilku adresów naraz : contacts = [{employee_id, email}] ; pusty adres = usuń. */
    public function saveContacts()
    {
        return $this->guarded(function () {
            $in = $this->input();
            $rows = isset($in['contacts']) && is_array($in['contacts']) ? $in['contacts'] : [];
            if (!$rows || count($rows) > 300) throw new CustomException('Brak adresów do zapisania.', 'errors', 422);
            // Tout ou rien : une adresse invalide ne laisse pas la liste à moitié enregistrée.
            $clean = [];
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $email = trim((string) ($r['email'] ?? ''));
                if ($email !== '' && (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new CustomException('Nieprawidłowy adres e-mail : ' . $email . '.', 'errors', 422);
                $clean[(int) ($r['employee_id'] ?? 0)] = $email;
            }
            foreach ($clean as $employeeId => $email) $this->mail->setEmail($employeeId, $email, $this->userId());
            $month = self::month_($in['month'] ?? null) ?? date('Y-m');
            return ['message' => 'Zapisano adresy e-mail.', 'data' => ['contacts' => $this->mail->contacts($month), 'mail' => $this->mail->summary($month)]];
        });
    }

    /** Karty miesiąca e-mailem : employee_ids (wybrane osoby) albo bez — wszyscy z adresem. */
    public function mailSheets($month)
    {
        return $this->guarded(function () use ($month) {
            $in = $this->input();
            $ids = isset($in['employee_ids']) && is_array($in['employee_ids']) ? $in['employee_ids'] : null;
            $r = $this->mail->send((string) $month, $ids, $this->userId());
            $msg = 'Karty w kolejce do wysyłki : ' . $r['queued'] . '.' . ($r['missing'] ? ' Bez adresu e-mail : ' . implode(', ', array_slice($r['missing'], 0, 6)) . (count($r['missing']) > 6 ? '…' : '') . '.' : '');
            return ['message' => $msg, 'data' => $r + ['mail' => $this->mail->summary((string) $month)]];
        });
    }

    // --- Narzędzia ---------------------------------------------------------------------------------------

    private function guarded(callable $fn)
    {
        try {
            if (!$this->access->canSee($this->userId(), '/time')) throw new CustomException('Brak dostępu do Czasu pracy.', 'errors', 403);
            $out = $fn();
            return $this->json(isset($out['message']) ? $out : ['data' => $out]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function monthWithMail(string $month): array
    {
        return $this->time->month($month) + ['mail' => $this->mail->summary($month)];
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }

    private static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
    }

    private static function month_(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : null;
    }
}
