<?php
declare(strict_types=1);

namespace App\Services\Hr;

use App\Domain\Logistics\Holidays;
use App\Exceptions\CustomException;
use App\Repositories\Hr\HrClockRepository;
use App\Repositories\Hr\HrRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\Sms\SmsGateway;
use DateTimeImmutable;

/**
 * [348] Karta pracownika › « Grafik tygodnia i przypomnienie o wejściu » : grafik stały (jour 1 = lundi … 7 = dimanche,
 * jour vide = wolne), SMS coupé ou non, texte propre à la personne (vide = texte commun hr.clock_sms_text).
 *
 * Cron (Bin/hr_clock_reminder.php, chaque minute) : une personne active avec un grafik ce jour reçoit un SMS quand,
 * à « début + hr.clock_sms_delay minutes » et avant la fin du grafik, elle n'a pas pointé son entrée ; jamais un jour
 * férié PL, ni pendant une absence du calendrier, ni sans téléphone dans la fiche. Un seul SMS par personne et par jour :
 * la ligne n_hr_clock_reminder est posée d'abord (unique), puis le SMS part par SmsGateway (n_sms_log, mode live seul).
 * Heures : fuseau PHP du serveur (date.timezone = Europe/Warsaw), le même que les pointages de Czas pracy.
 * Ni numéro ni nom dans les journaux : run() ne rend que des nombres.
 */
final class ClockReminderService
{
    public const DEFAULT_TEXT = 'Jesteś w grafiku od {godzina}, a nie zarejestrowano jeszcze Twojego wejścia. Zarejestruj wejście albo poinformuj biuro o nieobecności. Dziękujemy.';
    public const MAX_TEXT = 480;
    public const DAYS = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'Sb', 7 => 'Nd'];
    private const STATUS = ['pending' => 'w toku', 'sent' => 'wysłany', 'logged' => 'zapisany (bramka SMS nie wysyła)', 'failed' => 'błąd'];

    public function __construct(private HrClockRepository $repo, private HrRepository $hr, private HrCipher $cipher, private ParamRepository $params,
        private SmsGateway $sms)
    {
    }

    /** Opóźnienie SMS (min) : wspólne, HR › Ustawienia. */
    public function delay(): int
    {
        return max(1, min(240, (int) $this->params->get('hr.clock_sms_delay', '15')));
    }

    public function defaultText(): string
    {
        $t = trim($this->params->get('hr.clock_sms_text', self::DEFAULT_TEXT));
        return $t !== '' ? $t : self::DEFAULT_TEXT;
    }

    // --- Fiche ------------------------------------------------------------------------------------------------

    public function card(int $employeeId): array
    {
        $s = $this->repo->settings($employeeId);
        $phone = $this->cipher->decrypt($s['phone_enc']);
        $last = $this->repo->lastReminder($employeeId);
        $default = $this->defaultText();
        return [
            'days' => (object) $this->repo->schedule($employeeId),
            'sms' => $s['clock_sms'], 'text' => $s['clock_sms_text'] ?? '', 'default_text' => $default, 'delay' => $this->delay(),
            'phone' => $phone ? self::mask($phone) : null, 'sms_delivers' => $this->sms->delivers(),
            'last' => $last ? ['day' => $last['day'], 'at' => substr((string) $last['sent_at'], 0, 16), 'start' => substr((string) $last['scheduled_start'], 0, 5),
                'status' => $last['status'], 'label' => self::STATUS[$last['status']] ?? $last['status'], 'error' => $last['error']] : null,
        ];
    }

    /** @param array{days?:array, sms?:mixed, text?:mixed} $in */
    public function save(int $employeeId, array $in, ?int $viewer): array
    {
        if (!$this->hr->exists($employeeId)) throw new CustomException('Nie ma takiego pracownika.', 'errors', 404);
        $errors = [];
        $days = [];
        $raw = is_array($in['days'] ?? null) ? $in['days'] : [];
        foreach (self::DAYS as $wd => $label) {
            $d = is_array($raw[$wd] ?? null) ? $raw[$wd] : [];
            $start = self::time((string) ($d['start'] ?? ''));
            $end = self::time((string) ($d['end'] ?? ''));
            $hasStart = trim((string) ($d['start'] ?? '')) !== '';
            $hasEnd = trim((string) ($d['end'] ?? '')) !== '';
            if (!$hasStart && !$hasEnd) continue;
            if ($hasStart && $start === null) $errors["start_{$wd}"] = "{$label} : godzina GG:MM (24 h).";
            if ($hasEnd && $end === null) $errors["end_{$wd}"] = "{$label} : godzina GG:MM (24 h).";
            if (!$hasStart) $errors["start_{$wd}"] = "{$label} : brak początku.";
            if (!$hasEnd) $errors["end_{$wd}"] = "{$label} : brak końca.";
            if ($start !== null && $end !== null && $start === $end) $errors["end_{$wd}"] = "{$label} : koniec = początek.";
            if ($start !== null && $end !== null) $days[$wd] = ['start' => $start, 'end' => $end];
        }
        $text = trim(str_replace("\r", '', (string) ($in['text'] ?? '')));
        if (mb_strlen($text) > self::MAX_TEXT) $errors['text'] = 'Treść SMS : najwyżej ' . self::MAX_TEXT . ' znaków.';
        if ($errors) throw new CustomException(reset($errors), 'errors', 422, ['fields' => $errors]);
        // Texte identique au texte commun = pas de texte propre (suit les changements de HR › Ustawienia).
        $custom = $text === '' || $text === $this->defaultText() ? null : $text;
        $this->repo->saveSchedule($employeeId, $days, $viewer);
        $this->repo->saveSettings($employeeId, filter_var($in['sms'] ?? true, FILTER_VALIDATE_BOOLEAN), $custom, $viewer);
        $this->hr->log($viewer, $employeeId, 'schedule');
        return $this->card($employeeId) + ['message' => 'Zapisano grafik.'];
    }

    // --- Envoi (cron) -----------------------------------------------------------------------------------------

    /** @return array<string, int> compteurs seulement */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $day = $now->format('Y-m-d');
        $stats = ['due' => 0, 'sent' => 0, 'logged' => 0, 'failed' => 0, 'clocked_in' => 0, 'absent' => 0, 'no_phone' => 0, 'off' => 0, 'holiday' => 0];
        if (Holidays::between($day, $day, ['PL'])) { $stats['holiday'] = 1; return $stats; }
        $delay = $this->delay();
        $default = $this->defaultText();
        foreach ($this->repo->due((int) $now->format('N'), $day) as $r) {
            $id = (int) $r['id_employee'];
            $start = new DateTimeImmutable($day . ' ' . $r['start_time'], $now->getTimezone());
            $end = new DateTimeImmutable($day . ' ' . $r['end_time'], $now->getTimezone());
            if ($end <= $start) $end = $end->modify('+1 day'); // grafik de nuit : fin le lendemain
            if ($now < $start->modify("+{$delay} minutes") || $now >= $end) continue;
            $stats['due']++;
            if (!(int) $r['clock_sms']) { $stats['off']++; continue; }
            if ($this->repo->clockedIn($id, $day . ' 00:00:00')) { $stats['clocked_in']++; continue; }
            if ($this->repo->absent($id, $day)) { $stats['absent']++; continue; }
            $phone = trim((string) $this->cipher->decrypt($r['phone_enc']));
            if ($phone === '') { $stats['no_phone']++; continue; }
            if (!$this->repo->claim($id, $day, (string) $r['start_time'], $now->format('Y-m-d H:i:s'))) continue;
            $number = $this->sms->e164(self::polish($phone));
            if ($number === null) {
                // Le numéro lui-même ne va ni dans l'erreur ni dans n_sms_log.
                $this->repo->finish($id, $day, 'failed', 'Nieczytelny numer telefonu w karcie HR.');
                $stats['failed']++;
                continue;
            }
            $text = str_replace('{godzina}', $start->format('H:i'), trim((string) $r['clock_sms_text']) !== '' ? (string) $r['clock_sms_text'] : $default);
            try {
                $res = $this->sms->send($number, $text, ['context' => 'clock_reminder', 'ref' => $id, 'author' => 'hr_clock_reminder']);
            } catch (\Throwable) {
                $res = ['status' => 'failed', 'error' => 'Błąd bramki SMS.'];
            }
            $status = in_array($res['status'] ?? '', ['sent', 'logged'], true) ? $res['status'] : 'failed';
            $this->repo->finish($id, $day, $status, $res['error'] ?? null);
            $stats[$status]++;
        }
        return $stats;
    }

    // --- Outils -------------------------------------------------------------------------------------------------

    /** « 7:5 » refusé ; « 7:05 » → « 07:05 » ; null = pas une heure 24 h. */
    public static function time(string $v): ?string
    {
        $v = trim($v);
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m)) return null;
        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    /** Numéro masqué sauf les 2 derniers chiffres (« +•• ••• ••• •01 »). */
    public static function mask(string $phone): string
    {
        $digits = preg_match_all('/\d/', $phone);
        $i = 0;
        return (string) preg_replace_callback('/\d/', function ($m) use (&$i, $digits) { return ++$i > $digits - 2 ? $m[0] : '•'; }, $phone);
    }

    /**
     * Numéro de la fiche sans indicatif : 9 chiffres sans 0 initial = numéro polonais (+48) — le préfixe par défaut des
     * SMS (sms.default_prefix) vise les clients belges et enverrait le SMS d'un salarié à un autre pays.
     */
    public static function polish(string $phone): string
    {
        $d = (string) preg_replace('/[\s().-]/', '', $phone);
        return preg_match('/^[1-9]\d{8}$/', $d) ? '+48' . $d : $phone;
    }
}
