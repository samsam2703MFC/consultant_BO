<?php
declare(strict_types=1);

namespace App\Services\Workshop\Time;

use App\Exceptions\CustomException;
use App\Repositories\Workshop\AuditLogRepository;
use App\Repositories\Workshop\TimeRepository;
use App\Services\Communication\OutgoingMessageService;

/**
 * [266] Karta czasu pracy par e-mail : l'adresse de chaque salarié (encodée dans Czas pracy › Miesiąc, gardée chiffrée)
 * et l'envoi de sa karta du mois en PDF. Le message part par la file commune (communication_message), donc à travers
 * MAIL_DELIVERY_MODE : rien ne sort d'un serveur de test. Chaque envoi est noté (n_time_sheet_mail) et montré dans le mois.
 */
final class TimeSheetMailService
{
    public function __construct(
        private TimeRepository $repo,
        private TimeService $time,
        private TimeSheetPdf $pdf,
        private OutgoingMessageService $outgoing,
        private StaffContactCipher $cipher,
        private AuditLogRepository $log,
    ) {
    }

    /**
     * Tout le personnel actif avec son adresse et le dernier envoi de la karta du mois.
     * @return list<array{employee_id:int, name:string, group:?string, email:?string, sent:?array}>
     */
    public function contacts(string $month): array
    {
        $emails = $this->emails();
        $sent = $this->repo->sheetMails($month);
        return array_map(fn($p) => ['employee_id' => (int) $p['id'], 'name' => $p['name'], 'group' => $p['group_name'],
            'email' => $emails[(int) $p['id']] ?? null, 'sent' => self::sentRow($sent[(int) $p['id']] ?? null)], $this->repo->staff());
    }

    /** Pour le tableau du mois : salarié => {email: bool, sent: ?{at, status}} (sans l'adresse). */
    public function summary(string $month): array
    {
        $out = [];
        foreach (array_keys($this->emails()) as $id) $out[$id] = ['email' => true, 'sent' => null];
        foreach ($this->repo->sheetMails($month) as $id => $r) $out[$id] = ['email' => isset($out[$id]), 'sent' => self::sentRow($r)];
        return $out;
    }

    /** Adresse vide = supprimer. */
    public function setEmail(int $employeeId, ?string $email, ?int $userId): array
    {
        $e = $this->repo->employee($employeeId) ?? throw new CustomException('Nie ma takiego pracownika.', 'errors', 404);
        $email = trim((string) $email);
        if ($email === '') {
            $this->repo->deleteContact($employeeId);
        } else {
            if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new CustomException('Nieprawidłowy adres e-mail : ' . $e['name'] . '.', 'errors', 422);
            $this->repo->setContact($employeeId, $this->cipher->encrypt(mb_strtolower($email)), $userId);
        }
        // Journal : le fait, pas l'adresse.
        $this->log->record($userId, $email === '' ? 'time.contact.delete' : 'time.contact.set', 'n_time_contact', $employeeId, null, ['email' => $email !== ''], null);
        return ['employee_id' => $employeeId, 'email' => $email === '' ? null : mb_strtolower($email)];
    }

    /**
     * Envoie la karta du mois : $employeeIds null = toutes les personnes du mois qui ont une adresse.
     * @return array{queued:int, month:string, missing:list<string>}
     */
    public function send(string $month, ?array $employeeIds, ?int $userId, ?int $now = null): array
    {
        $now ??= time();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new CustomException('Nieprawidłowy miesiąc.', 'errors', 422);
        if ($month > date('Y-m', $now)) throw new CustomException('Ten miesiąc jeszcze się nie zaczął.', 'errors', 422);
        $people = array_column($this->time->month($month, $now)['people'], null, 'employee_id');
        $ids = $employeeIds === null ? array_keys($people) : array_values(array_unique(array_map('intval', $employeeIds)));
        if (!$ids) throw new CustomException('Brak odbić i nieobecności w tym miesiącu — nie ma kart do wysłania.', 'errors', 422);
        $emails = $this->emails();
        $rules = $this->time->rules();
        $label = TimeService::monthLabel($month);
        $queued = 0;
        $missing = [];
        foreach ($ids as $id) {
            $p = $people[$id] ?? null;
            if ($p === null) {
                if ($employeeIds !== null) throw new CustomException('Ta osoba nie ma odbić ani nieobecności w tym miesiącu.', 'errors', 422);
                continue;
            }
            $to = $emails[$id] ?? null;
            if ($to === null) { $missing[] = $p['name']; continue; }
            $file = $this->pdf->render([$id], $month);
            $first = trim((string) strtok($p['name'], ' '));
            $body = 'Dzień dobry' . ($first !== '' ? ' ' . $first : '') . ",\n\n"
                . 'w załączniku Twoja karta czasu pracy za ' . $label . " : godziny wejścia i wyjścia, nadgodziny, urlopy i bank nadgodzin.\n"
                . "Sprawdź ją proszę. Jeśli coś się nie zgadza, zgłoś to kierownikowi produkcji.\n\n" . $rules['company'] . "\n";
            $messageId = $this->outgoing->queue([
                'message_type' => 'time_sheet', 'template' => [], 'recipient' => $to, 'subject' => 'Karta czasu pracy — ' . $label, 'body' => $body,
                'variables' => ['employee_id' => $id, 'month' => $month], 'created_by' => 'time_sheet:' . ($userId ?? 0),
            ], [['filename' => $file['filename'], 'mime_type' => 'application/pdf', 'content' => $file['content']]]);
            $this->repo->insertSheetMail($id, $month, $messageId, $userId);
            $queued++;
        }
        if ($queued === 0) throw new CustomException('Nic nie wysłano : brak adresu e-mail (' . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? '…' : '') . ').', 'errors', 422);
        $this->log->record($userId, 'time.sheet.mail', 'n_time_sheet_mail', $month, null, ['queued' => $queued, 'missing' => count($missing)], null);
        return ['queued' => $queued, 'month' => $month, 'missing' => $missing];
    }

    /** @return array<int, string> salarié => adresse en clair (les adresses illisibles — autre secret du serveur — sont ignorées) */
    private function emails(): array
    {
        $out = [];
        foreach ($this->repo->contacts() as $id => $enc) {
            $plain = $this->cipher->decrypt($enc);
            if ($plain !== null && $plain !== '') $out[(int) $id] = $plain;
        }
        return $out;
    }

    private static function sentRow(?array $r): ?array
    {
        return $r === null ? null : ['at' => $r['sent_at'] ?? $r['created_at'], 'status' => $r['status'] ?? 'queued'];
    }
}
