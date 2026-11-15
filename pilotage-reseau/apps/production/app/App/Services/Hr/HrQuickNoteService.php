<?php
declare(strict_types=1);

namespace App\Services\Hr;

use App\Exceptions\CustomException;
use App\Repositories\Hr\HrRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Logistics\Sms\SmsTransport;
use App\Services\Notification\AppNotificationService;
use DateTimeImmutable;

/**
 * [279] HR › Szybka notatka (makieta 44) : une note en une fenêtre — rodzaj, pracownik, temat, treść, dla kogo, follow-up
 * (date + responsable), alertes immédiates (cloche ERP, e-mail, SMS, tâche dans « Mój tydzień »).
 *
 * « Dla kogo » : administrateur et postes de bureau ; « tylko ja » = note privée (auteur et administrateur). Une note
 * publique avec des destinataires n'est visible que par eux (et l'administrateur, l'auteur, le responsable du follow-up).
 * E-mail et SMS : qui, type, sujet et lien — le texte de la note reste chiffré dans l'ERP. Le SMS passe par le transport
 * du serveur (sans passerelle : seulement noté). Follow-up : cloche du responsable, rappel chaque jour à partir du terme
 * (Bin/hr_month_summary.php), rouge après le terme, « Zrobione » en un clic avec une courte remarque.
 */
final class HrQuickNoteService
{
    public const KINDS = [
        'ocena' => ['Ocena / przegląd', ['Przegląd po okresie próbnym', 'Ocena roczna', 'Rozmowa po 3 miesiącach']],
        'pochwala' => ['Pochwała', ['Bardzo dobra praca', 'Pomoc zespołowi', 'Inwentaryzacja bez błędów']],
        'uwaga' => ['Uwaga / upomnienie', ['Spóźnienie', 'Brak dokumentacji partii', 'Nieprzestrzeganie zasad higieny']],
        'rozmowa' => ['Rozmowa', ['Rozmowa o podwyżce', 'Rozmowa o grafiku', 'Rozmowa o celach']],
        'szkolenie' => ['Szkolenie', ['Szkolenie BHP', 'Szkolenie HACCP', 'Szkolenie stanowiskowe']],
        'bhp' => ['Zdarzenie BHP', ['Skaleczenie', 'Oparzenie', 'Upadek / poślizgnięcie']],
        'nieobecnosc' => ['Nieobecność / spóźnienie', ['Nieobecność nieusprawiedliwiona', 'Spóźnienie', 'Wcześniejsze wyjście']],
        'inne' => ['Inne', []],
    ];
    /** Dla kogo : administrateur + postes de bureau (n_position.code) ; « me » = prywatna. */
    public const AUDIENCE = ['admin' => 'Administrator', 'ADMINISTRACJA' => 'Finanse', 'KIEROWNIK_PRODUKCJI' => 'Kierownik produkcji', 'LOGISTYKA' => 'Logistyka',
        'JAKOSC' => 'Jakość', 'me' => 'Tylko ja (prywatna)'];
    public const CHANNELS = ['bell' => 'Dzwonek w ERP', 'email' => 'E-mail', 'sms' => 'SMS', 'task' => 'Zadanie w « Mój tydzień »'];

    public function __construct(private HrRepository $repo, private HrCipher $cipher, private HrService $hr, private AppNotificationService $notifications,
        private OutgoingMessageService $outgoing, private SmsTransport $sms)
    {
    }

    public function options(?int $viewer): array
    {
        $codes = array_values(array_filter(array_keys(self::AUDIENCE), fn($c) => !in_array($c, ['admin', 'me'], true)));
        $counts = [];
        foreach (array_keys(self::AUDIENCE) as $c) $counts[$c] = $c === 'me' ? 1 : count($this->repo->recipients([$c]));
        return [
            'kinds' => array_map(fn($k, $v) => ['code' => $k, 'label' => $v[0], 'topics' => $v[1]], array_keys(self::KINDS), self::KINDS),
            'audience' => array_map(fn($k, $v) => ['code' => $k, 'label' => $v, 'count' => $counts[$k]], array_keys(self::AUDIENCE), self::AUDIENCE),
            'channels' => array_map(fn($k, $v) => ['code' => $k, 'label' => $v], array_keys(self::CHANNELS), self::CHANNELS),
            'sms_delivers' => $this->sms->delivers(),
            'users' => $this->repo->followupUsers($codes),
            'people' => array_values(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'group' => $p['group']], array_filter($this->hr->view($viewer, false)['people'], fn($p) => $p['active']))),
            'me' => $viewer,
        ];
    }

    /** @return array{id:int, notified:array{bell:int, email:int, sms:int}, message:string} */
    public function add(array $in, ?int $viewer, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $errors = [];
        $employeeId = (int) ($in['employee_id'] ?? 0);
        if (!$employeeId || !$this->repo->exists($employeeId)) $errors['employee_id'] = 'Wybierz pracownika.';
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) $errors['kind'] = 'Wybierz rodzaj.';
        $topic = mb_substr(trim((string) ($in['topic'] ?? '')), 0, 160);
        if ($topic === '') $errors['topic'] = 'Temat jest wymagany.';
        $body = trim((string) ($in['body'] ?? ''));
        if (mb_strlen($body) > 2000) $errors['body'] = 'Do 2000 znaków.';
        $audience = array_values(array_unique(array_intersect(array_map('strval', (array) ($in['audience'] ?? [])), array_keys(self::AUDIENCE))));
        if (!$audience) $errors['audience'] = 'Wybierz, kto widzi notatkę.';
        $channels = array_values(array_unique(array_intersect(array_map('strval', (array) ($in['channels'] ?? [])), array_keys(self::CHANNELS))));
        $due = null;
        $fuUser = null;
        if (!empty($in['followup'])) {
            $due = trim((string) ($in['followup_due'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < $today->format('Y-m-d')) $errors['followup_due'] = 'Termin : dziś albo później.';
            $fuUser = (int) ($in['followup_user'] ?? 0);
            if (!in_array($fuUser, array_column($this->repo->followupUsers(['ADMINISTRACJA', 'KIEROWNIK_PRODUKCJI', 'LOGISTYKA', 'JAKOSC']), 'id'), true)) $errors['followup_user'] = 'Wybierz osobę odpowiedzialną.';
        }
        if ($errors) throw new CustomException('Uzupełnij zaznaczone pola.', 'errors', 422, ['fields' => $errors]);

        $private = $audience === ['me'];
        $codes = array_values(array_diff($audience, ['me']));
        $id = $this->repo->insertQuickNote([
            'id_employee' => $employeeId, 'kind' => $kind, 'topic_enc' => $this->cipher->encrypt($topic), 'body_enc' => $this->cipher->encrypt($body !== '' ? $body : $topic),
            'visibility' => $private ? 'private' : 'public', 'to_admin' => in_array('admin', $codes, true), 'audience' => $private ? null : implode(',', $codes),
            'channels' => $channels ? implode(',', $channels) : null, 'followup_due' => $due, 'followup_user' => $fuUser ?: null, 'author' => $viewer,
        ]);
        $person = $this->hr->person($employeeId);
        $kindLabel = self::KINDS[$kind][0];
        $link = '/hr/notes?note=' . $id;
        $short = 'HR · nowa notatka (' . $kindLabel . ') — ' . $person['name'] . ' : « ' . $topic . ' »' . ($due ? '. Follow-up do ' . date('d.m.Y', strtotime($due)) : '') . '.';
        $sent = ['bell' => 0, 'email' => 0, 'sms' => 0];
        $recipients = $private ? [] : $this->repo->recipients($codes);
        unset($recipients[(int) $viewer]);
        foreach ($recipients as $uid => $u) {
            if (in_array('bell', $channels, true) && $this->notifications->publishForUser((int) $uid, 'hr_note', 'info', 'HR : ' . $kindLabel . ' — ' . $person['name'], $topic, $link, ['note_id' => $id], 'hr_note:' . $id . ':' . $uid) !== null) $sent['bell']++;
            if (in_array('email', $channels, true) && $u['email'] && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
                $this->outgoing->queue(['message_type' => 'hr_note', 'template' => [], 'recipient' => $u['email'], 'subject' => 'HR — ' . $kindLabel . ' : ' . $person['name'],
                    'body' => $short . "\n\nTreść notatki : ERP › HR › Notatki miesiąca.", 'variables' => ['note_id' => $id], 'created_by' => 'hr_note:' . (int) $viewer]);
                $sent['email']++;
            }
            if (in_array('sms', $channels, true) && $u['phone']) {
                $r = $this->sms->send((string) $u['phone'], mb_substr($short, 0, 300));
                if (($r['status'] ?? '') !== 'failed') $sent['sms']++;
            }
        }
        // Responsable du follow-up : toujours prévenu dans la cloche (sauf s'il est l'auteur).
        if ($fuUser && $fuUser !== (int) $viewer) {
            $this->notifications->publishForUser($fuUser, 'hr_followup', 'warning', 'Follow-up HR do ' . date('d.m', strtotime($due)) . ' — ' . $person['name'], $topic, $link,
                ['note_id' => $id], 'hr_followup:' . $id . ':new');
        }
        $this->repo->log($viewer, $employeeId, 'quick_note');
        $parts = array_filter([$sent['bell'] ? 'dzwonek ' . $sent['bell'] : null, $sent['email'] ? 'e-mail ' . $sent['email'] : null,
            $sent['sms'] ? 'SMS ' . $sent['sms'] . ($this->sms->delivers() ? '' : ' (zapisany — brak bramki SMS)') : null]);
        return ['id' => $id, 'notified' => $sent, 'message' => 'Notatka zapisana' . ($parts ? ' — powiadomienia : ' . implode(', ', $parts) : '') . ($due ? ' ; follow-up do ' . date('d.m', strtotime($due)) : '') . '.'];
    }

    /** Follow-ups ouverts visibles par la personne (tous pour l'administrateur). */
    public function followups(?int $viewer, bool $admin, ?DateTimeImmutable $today = null): array
    {
        $date = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $rows = array_map(fn($r) => ['id' => (int) $r['id'], 'employee_id' => (int) $r['id_employee'], 'name' => $r['name'], 'kind' => $r['kind'],
            'kind_label' => self::KINDS[$r['kind']][0] ?? 'Notatka', 'topic' => $this->cipher->decrypt($r['topic_enc']) ?? '', 'due' => $r['followup_due'],
            'late' => $r['followup_due'] < $date, 'who' => $r['followup_name'], 'mine' => (int) $r['followup_user'] === (int) $viewer,
            'can_close' => $admin || (int) $r['followup_user'] === (int) $viewer || (int) $r['author'] === (int) $viewer], $this->repo->openFollowups($viewer, $admin));
        return ['items' => $rows, 'open' => count($rows), 'late' => count(array_filter($rows, fn($r) => $r['late']))];
    }

    public function done(int $noteId, array $in, ?int $viewer, bool $admin): array
    {
        $n = $this->repo->note($noteId) ?? throw new CustomException('Nie ma takiej notatki.', 'errors', 404);
        if ($n['followup_due'] === null) throw new CustomException('Ta notatka nie ma follow-upu.', 'errors', 422);
        if (!$admin && (int) $n['followup_user'] !== (int) $viewer && (int) $n['author'] !== (int) $viewer) throw new CustomException('Follow-up zamyka osoba odpowiedzialna, autor albo administrator.', 'errors', 403);
        $this->repo->followupDone($noteId, $viewer, mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null);
        $this->repo->log($viewer, (int) $n['id_employee'], 'followup_done');
        return $this->followups($viewer, $admin);
    }

    /** Cron : rappel dans la cloche du responsable, chaque jour à partir du terme, tant que le follow-up est ouvert. */
    public function reminders(?DateTimeImmutable $now = null): int
    {
        $date = ($now ?? new DateTimeImmutable())->format('Y-m-d');
        $n = 0;
        foreach ($this->repo->dueFollowups($date) as $r) {
            $late = $r['followup_due'] < $date;
            $this->notifications->publishForUser((int) $r['followup_user'], 'hr_followup', $late ? 'danger' : 'warning',
                ($late ? 'Follow-up HR po terminie' : 'Follow-up HR dziś') . ' — ' . $r['name'], $this->cipher->decrypt($r['topic_enc']) ?? '', '/hr/notes?note=' . $r['id'],
                ['note_id' => (int) $r['id']], 'hr_followup:' . $r['id'] . ':' . $date);
            $n++;
        }
        return $n;
    }
}
