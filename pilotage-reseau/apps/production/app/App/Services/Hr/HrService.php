<?php
declare(strict_types=1);

namespace App\Services\Hr;

use App\Domain\Payroll\BankXml;
use App\Exceptions\CustomException;
use App\Repositories\Hr\HrRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\TimeRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Notification\AppNotificationService;
use App\Services\Workshop\Time\StaffContactCipher;
use DateTimeImmutable;

/**
 * [271] Rail « HR » (makiety 36–38) : tous les employés (contact, ancienneté, contrat, retraite à 60 ans pour les femmes
 * et 65 pour les hommes — paramètres hr.retire_age_k / hr.retire_age_m), documents importés (chiffrés), notes
 * (publique = visible par le Kierownik produkcji et les finances ; privée = auteur et administrateur ; « do
 * administratora » = cloche de l'administrateur), notes regroupées en fin de mois et envoyées à l'administrateur.
 * Accès (clé « hr ») : administrateur, Kierownik produkcji, Administracja i fakturowanie — contrôlé par le contrôleur.
 * Chaque lecture de données personnelles est notée (n_hr_log) ; rien de personnel dans les journaux du serveur.
 */
final class HrService
{
    public const KINDS = ['umowa' => 'Umowa', 'aneks' => 'Aneks', 'badania' => 'Badania lekarskie', 'notatka' => 'Notatka', 'inne' => 'Inne'];
    public const CONTRACTS = ['nieokreslony' => 'na czas nieokreślony', 'okreslony' => 'na czas określony', 'probny' => 'okres próbny', 'zlecenie' => 'umowa zlecenie', 'inna' => 'inna'];
    private const MIMES = ['application/pdf' => '%PDF', 'image/jpeg' => "\xFF\xD8\xFF", 'image/png' => "\x89PNG",
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => "PK\x03\x04"];
    public const MAX_FILE = 8 * 1024 * 1024;
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(private HrRepository $repo, private HrCipher $cipher, private TimeRepository $time, private StaffContactCipher $contacts,
        private ParamRepository $params, private AppNotificationService $notifications, private OutgoingMessageService $outgoing)
    {
    }

    public function rules(): array
    {
        return ['retire_k' => max(40, min(80, (int) $this->params->get('hr.retire_age_k', '60'))), 'retire_m' => max(40, min(80, (int) $this->params->get('hr.retire_age_m', '65'))),
            'alert_days' => max(1, min(180, (int) $this->params->get('hr.alert_days', '30')))];
    }

    public function saveRules(array $in, int $userId): array
    {
        foreach (['retire_k' => 'hr.retire_age_k', 'retire_m' => 'hr.retire_age_m', 'alert_days' => 'hr.alert_days'] as $k => $code) {
            if (!array_key_exists($k, $in)) continue;
            $v = filter_var($in[$k], FILTER_VALIDATE_INT);
            [$min, $max] = $k === 'alert_days' ? [1, 180] : [40, 80];
            if ($v === false || $v < $min || $v > $max) throw new CustomException("Wartość od {$min} do {$max}.", 'errors', 422, ['fields' => [$k => "Od {$min} do {$max}."]]);
            $this->params->setValue($code, (string) $v, $userId);
        }
        return $this->rules();
    }

    // --- Tableau des employés (makieta 36) -----------------------------------------------------------------

    public function view(?int $viewer, bool $admin, ?DateTimeImmutable $today = null, bool $canPay = false): array
    {
        $today ??= new DateTimeImmutable('today');
        $rules = $this->rules();
        $from = $today->format('Y-m-01');
        $to = $today->modify('first day of next month')->format('Y-m-d');
        $notes = $this->repo->noteCounts($from, $to, $admin, $viewer);
        $emails = $this->time->contacts();
        $people = [];
        foreach ($this->repo->people() as $r) $people[] = $this->row($r, $emails, $notes, $rules, $today, $canPay);
        $this->repo->log($viewer, null, 'list');
        $active = array_values(array_filter($people, fn($p) => $p['active']));
        $seniority = array_values(array_filter(array_map(fn($p) => $p['seniority_months'], $active), fn($m) => $m !== null));
        return [
            'people' => $people, 'rules' => $rules, 'today' => $today->format('Y-m-d'), 'month' => $today->format('Y-m'), 'month_label' => self::monthLabel($today->format('Y-m')),
            'kinds' => self::KINDS, 'contracts' => self::CONTRACTS, 'is_admin' => $admin, 'can_pay' => $canPay,
            'kpi' => [
                'active' => count($active), 'groups' => count(array_unique(array_filter(array_column($active, 'group')))),
                'avg_seniority' => $seniority ? (int) round(array_sum($seniority) / count($seniority)) : null, 'max_seniority' => $seniority ? max($seniority) : null,
                'contracts_ending' => count(array_filter($active, fn($p) => $p['contract_soon'])), 'retire_soon' => count(array_filter($active, fn($p) => $p['retire_soon'])),
                'missing' => count(array_filter($active, fn($p) => $p['missing'])),
            ],
        ];
    }

    private function row(array $r, array $emails, array $notes, array $rules, DateTimeImmutable $today, bool $canPay = false): array
    {
        $id = (int) $r['id'];
        $email = isset($emails[$id]) ? $this->contacts->decrypt($emails[$id]) : null;
        $phone = $this->cipher->decrypt($r['phone_enc']);
        $birth = $this->cipher->decrypt($r['birth_enc']);
        $seniority = $r['hire_date'] ? self::months(new DateTimeImmutable($r['hire_date']), $today) : null;
        $retire = null;
        if ($birth && $r['gender']) {
            $age = $r['gender'] === 'K' ? $rules['retire_k'] : $rules['retire_m'];
            $date = (new DateTimeImmutable($birth))->modify("+{$age} years");
            $retire = ['date' => $date->format('Y-m-d'), 'year' => (int) $date->format('Y'), 'age' => $age, 'months_left' => self::months($today, $date)];
        }
        $soon = $today->modify('+' . $rules['alert_days'] . ' days')->format('Y-m-d');
        $contractSoon = $r['contract_end'] !== null && $r['contract_end'] <= $today->modify('+90 days')->format('Y-m-d');
        return [
            'id' => $id, 'active' => (bool) $r['active'], 'name' => $r['name'], 'group' => $r['group_name'], 'group_color' => $r['group_color'], 'position' => $r['position'],
            'email' => $email ?: null, 'phone' => $phone ?: null, 'birth_date' => $birth ?: null, 'gender' => $r['gender'],
            'hire_date' => $r['hire_date'], 'seniority_months' => $seniority, 'seniority' => $seniority === null ? null : self::span($seniority),
            'contract_type' => $r['contract_type'], 'contract_label' => $r['contract_type'] ? self::CONTRACTS[$r['contract_type']] ?? $r['contract_type'] : null,
            'contract_end' => $r['contract_end'], 'contract_soon' => $contractSoon, 'contract_alert' => $r['contract_end'] !== null && $r['contract_end'] <= $soon,
            'work_time' => $r['work_time'], 'medical_until' => $r['medical_until'], 'medical_alert' => $r['medical_until'] !== null && $r['medical_until'] <= $soon,
            'emergency' => $this->cipher->decrypt($r['emergency_enc']) ?: null,
            'retire' => $retire, 'retire_soon' => $retire !== null && $retire['months_left'] <= 24,
            'documents' => (int) $r['documents'], 'notes_month' => $notes[$id] ?? 0,
            'missing' => !$email || !$phone || !$r['hire_date'] || !$birth || !$r['gender'],
        ] + ($canPay ? ['pay' => $this->payFields($r)] : []);
    }

    /** [277] Paie et contrats : seulement pour l'administrateur et l'accès « payroll ». */
    private function payFields(array $r): array
    {
        $iban = BankXml::iban($this->cipher->decrypt($r['bank_enc'] ?? null));
        return [
            'type' => $r['pay_type'] ?? null, 'amount' => ($r['pay_amount'] ?? null) !== null ? (float) $r['pay_amount'] : null,
            'bank' => $iban ? BankXml::pretty($iban) : null, 'address' => $this->cipher->decrypt($r['address_enc'] ?? null) ?: null,
            'pesel' => $this->cipher->decrypt($r['pesel_enc'] ?? null) ?: null,
            'pit2' => (bool) ($r['pit2'] ?? 1), 'kup_raised' => (bool) ($r['kup_raised'] ?? 0), 'young_relief' => (bool) ($r['young_relief'] ?? 1),
            'student' => (bool) ($r['student'] ?? 0), 'sick_insurance' => (bool) ($r['sick_insurance'] ?? 0),
        ];
    }

    // --- Fiche (makieta 37) ---------------------------------------------------------------------------------

    public function card(int $employeeId, ?int $viewer, bool $admin, ?DateTimeImmutable $today = null, bool $canPay = false): array
    {
        $person = $this->person($employeeId, $today, $canPay);
        $this->repo->log($viewer, $employeeId, 'card');
        return ['person' => $person, 'documents' => array_map(fn($d) => self::docRow($d, $viewer, $admin), $this->repo->documents($employeeId)),
            'notes' => array_map(fn($n) => $this->noteRow($n, $viewer, $admin), $this->repo->notes($employeeId, null, null, $admin, $viewer))];
    }

    public function person(int $employeeId, ?DateTimeImmutable $today = null, bool $canPay = false): array
    {
        $today ??= new DateTimeImmutable('today');
        foreach ($this->repo->people() as $r) {
            if ((int) $r['id'] === $employeeId) return $this->row($r, $this->time->contacts(), [], $this->rules(), $today, $canPay);
        }
        throw new CustomException('Nie ma takiego pracownika.', 'errors', 404);
    }

    public function save(int $employeeId, array $in, ?int $viewer, bool $canPay = false): array
    {
        if (!$this->repo->exists($employeeId)) throw new CustomException('Nie ma takiego pracownika.', 'errors', 404);
        $errors = [];
        $data = [];
        $text = fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $date = function (string $k) use ($in, &$errors): ?string {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') return null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) { $errors[$k] = 'Data RRRR-MM-DD.'; return null; }
            return $v;
        };
        if (array_key_exists('phone', $in)) {
            $phone = $text('phone', 40);
            if ($phone !== '' && !preg_match('/^\+?[0-9 ()\/-]{6,30}$/', $phone)) $errors['phone'] = 'Numer telefonu : cyfry, spacje, +.';
            $data['phone_enc'] = $phone === '' ? null : $this->cipher->encrypt($phone);
        }
        if (array_key_exists('birth_date', $in)) {
            $birth = $date('birth_date');
            if ($birth !== null && ($birth < '1930-01-01' || $birth > date('Y-m-d', strtotime('-14 years')))) $errors['birth_date'] = 'Nieprawdopodobna data urodzenia.';
            $data['birth_enc'] = $birth === null ? null : $this->cipher->encrypt($birth);
        }
        if (array_key_exists('gender', $in)) {
            $g = (string) ($in['gender'] ?? '');
            if ($g !== '' && !in_array($g, ['K', 'M'], true)) $errors['gender'] = 'K albo M.';
            $data['gender'] = $g === '' ? null : $g;
        }
        foreach (['hire_date', 'contract_end', 'medical_until'] as $k) if (array_key_exists($k, $in)) $data[$k] = $date($k);
        if (array_key_exists('contract_type', $in)) {
            $c = (string) ($in['contract_type'] ?? '');
            if ($c !== '' && !isset(self::CONTRACTS[$c])) $errors['contract_type'] = 'Wybierz rodzaj umowy.';
            $data['contract_type'] = $c === '' ? null : $c;
        }
        if (array_key_exists('work_time', $in)) $data['work_time'] = $text('work_time', 30) ?: null;
        if (array_key_exists('emergency', $in)) { $e = $text('emergency', 200); $data['emergency_enc'] = $e === '' ? null : $this->cipher->encrypt($e); }
        $email = null;
        if (array_key_exists('email', $in)) {
            $email = strtolower($text('email', 190));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Niepoprawny adres e-mail.';
        }
        if (isset($data['hire_date'], $data['contract_end']) && $data['contract_end'] < $data['hire_date']) $errors['contract_end'] = 'Koniec umowy przed datą zatrudnienia.';
        if ($canPay) $data += $this->payInput($in, $errors);
        if ($errors) throw new CustomException('Popraw zaznaczone pola.', 'errors', 422, ['fields' => $errors]);
        if ($data) $this->repo->save($employeeId, $data, $viewer);
        // E-mail : un seul endroit, celui de Czas pracy › E-maile pracowników (n_time_contact).
        if ($email !== null) {
            if ($email === '') $this->time->deleteContact($employeeId);
            else $this->time->setContact($employeeId, $this->contacts->encrypt($email), $viewer);
        }
        $this->repo->log($viewer, $employeeId, 'save');
        return $this->person($employeeId, null, $canPay);
    }

    /** [277] Champs de paie de la fiche (validés ; compte, adresse et PESEL chiffrés). */
    private function payInput(array $in, array &$errors): array
    {
        $data = [];
        if (array_key_exists('pay_type', $in)) {
            $t = (string) ($in['pay_type'] ?? '');
            if ($t !== '' && !in_array($t, ['monthly', 'hourly'], true)) $errors['pay_type'] = 'Miesięcznie albo za godzinę.';
            $data['pay_type'] = $t === '' ? null : $t;
        }
        if (array_key_exists('pay_amount', $in)) {
            $v = str_replace([' ', ','], ['', '.'], (string) ($in['pay_amount'] ?? ''));
            if ($v !== '' && (!is_numeric($v) || (float) $v <= 0 || (float) $v > 1_000_000)) $errors['pay_amount'] = 'Kwota brutto większa od 0.';
            $data['pay_amount'] = $v === '' ? null : round((float) $v, 2);
        }
        if (array_key_exists('bank', $in)) {
            $raw = trim((string) ($in['bank'] ?? ''));
            $iban = $raw === '' ? null : BankXml::iban($raw);
            if ($raw !== '' && $iban === null) $errors['bank'] = 'Numer rachunku : 26 cyfr (sprawdzona suma kontrolna).';
            $data['bank_enc'] = $iban === null ? null : $this->cipher->encrypt($iban);
        }
        if (array_key_exists('address', $in)) {
            $a = mb_substr(trim((string) ($in['address'] ?? '')), 0, 300);
            $data['address_enc'] = $a === '' ? null : $this->cipher->encrypt($a);
        }
        if (array_key_exists('pesel', $in)) {
            $p = preg_replace('/\s+/', '', (string) ($in['pesel'] ?? ''));
            if ($p !== '' && !self::peselOk($p)) $errors['pesel'] = 'PESEL : 11 cyfr, zła suma kontrolna.';
            $data['pesel_enc'] = $p === '' ? null : $this->cipher->encrypt($p);
        }
        foreach (['pit2', 'kup_raised', 'young_relief', 'student', 'sick_insurance'] as $k) {
            if (array_key_exists($k, $in)) $data[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        return $data;
    }

    public static function peselOk(string $p): bool
    {
        if (!preg_match('/^\d{11}$/', $p)) return false;
        $w = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $sum = 0;
        for ($i = 0; $i < 10; $i++) $sum += $w[$i] * (int) $p[$i];
        return (10 - $sum % 10) % 10 === (int) $p[10];
    }

    // --- Documents ------------------------------------------------------------------------------------------

    public function upload(int $employeeId, array $in, ?int $viewer): array
    {
        if (!$this->repo->exists($employeeId)) throw new CustomException('Nie ma takiego pracownika.', 'errors', 404);
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) throw new CustomException('Wybierz rodzaj dokumentu.', 'errors', 422, ['fields' => ['kind' => 'Wybierz rodzaj.']]);
        $content = base64_decode((string) ($in['content_base64'] ?? ''), true);
        if ($content === false || $content === '') throw new CustomException('Brak pliku.', 'errors', 422);
        if (strlen($content) > self::MAX_FILE) throw new CustomException('Plik większy niż 8 MB.', 'errors', 422);
        $mime = null;
        foreach (self::MIMES as $m => $magic) if (str_starts_with($content, $magic)) { $mime = $m; break; }
        if ($mime === null) throw new CustomException('Dozwolone : PDF, JPG, PNG, DOCX.', 'errors', 422);
        $filename = preg_replace('/[\x00-\x1F\/\\\\]+/u', '_', mb_substr(trim((string) ($in['filename'] ?? 'dokument')), 0, 190)) ?: 'dokument';
        $date = trim((string) ($in['doc_date'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
        $id = $this->repo->insertDocument(['id_employee' => $employeeId, 'kind' => $kind, 'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 200) ?: $filename,
            'filename' => $filename, 'mime' => $mime, 'size' => strlen($content), 'doc_date' => $date ?: null, 'content' => $this->cipher->encryptBytes($content), 'uploaded_by' => $viewer]);
        $this->repo->log($viewer, $employeeId, 'upload');
        return ['id' => $id, 'documents' => array_map(fn($d) => self::docRow($d, $viewer, false), $this->repo->documents($employeeId))];
    }

    /** @return array{filename:string, mime:string, content:string} */
    public function download(int $documentId, ?int $viewer): array
    {
        $d = $this->repo->document($documentId) ?? throw new CustomException('Nie ma takiego dokumentu.', 'errors', 404);
        $content = $this->cipher->decryptBytes($d['content']) ?? throw new CustomException('Dokument nie do odczytania (inny sekret serwera).', 'errors', 409);
        $this->repo->log($viewer, (int) $d['id_employee'], 'document');
        return ['filename' => $d['filename'], 'mime' => $d['mime'], 'content' => $content];
    }

    public function deleteDocument(int $documentId, ?int $viewer, bool $admin): void
    {
        $d = $this->repo->document($documentId) ?? throw new CustomException('Nie ma takiego dokumentu.', 'errors', 404);
        if (!$admin && (int) $d['uploaded_by'] !== (int) $viewer) throw new CustomException('Dokument usuwa osoba, która go dodała, albo administrator.', 'errors', 403);
        $this->repo->deleteDocument($documentId);
        $this->repo->log($viewer, (int) $d['id_employee'], 'document_delete');
    }

    /** Onglet Dokumenty : tous les documents (sans contenu), par date d'ajout. */
    public function allDocuments(?int $viewer, bool $admin): array
    {
        $this->repo->log($viewer, null, 'documents');
        return ['kinds' => self::KINDS, 'documents' => array_map(fn($d) => ['id' => (int) $d['id'], 'employee_id' => (int) $d['id_employee'], 'name' => $d['name'],
            'kind' => $d['kind'], 'kind_label' => self::KINDS[$d['kind']] ?? $d['kind'], 'title' => $d['title'], 'filename' => $d['filename'], 'size' => (int) $d['size'],
            'date' => $d['doc_date'] ?? substr((string) $d['created_at'], 0, 10)], $this->repo->allDocuments())];
    }

    private static function docRow(array $d, ?int $viewer, bool $admin): array
    {
        return ['id' => (int) $d['id'], 'kind' => $d['kind'], 'kind_label' => self::KINDS[$d['kind']] ?? $d['kind'], 'title' => $d['title'], 'filename' => $d['filename'],
            'size' => (int) $d['size'], 'date' => $d['doc_date'] ?? substr((string) $d['created_at'], 0, 10), 'by' => $d['by_name'] ?? null,
            'can_delete' => $admin || (int) ($d['uploaded_by'] ?? 0) === (int) $viewer];
    }

    // --- Notes ----------------------------------------------------------------------------------------------

    public function addNote(int $employeeId, array $in, ?int $viewer): array
    {
        if (!$this->repo->exists($employeeId)) throw new CustomException('Nie ma takiego pracownika.', 'errors', 404);
        $body = trim((string) ($in['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 2000) throw new CustomException('Notatka : od 1 do 2000 znaków.', 'errors', 422, ['fields' => ['body' => 'Od 1 do 2000 znaków.']]);
        $visibility = ($in['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
        $toAdmin = !empty($in['to_admin']);
        $id = $this->repo->insertNote($employeeId, $this->cipher->encrypt($body), $visibility, $toAdmin, $viewer);
        if ($toAdmin) {
            $person = $this->person($employeeId);
            foreach ($this->repo->admins() as $a) {
                if ($a['id'] === $viewer) continue;
                // Le texte de la note reste dans l'ERP : la cloche ne dit que qui et où.
                $this->notifications->publishForUser($a['id'], 'hr_note', 'info', 'Notatka HR do administratora : ' . $person['name'], 'Nowa notatka w karcie pracownika (HR).',
                    '/hr?employee=' . $employeeId, ['note_id' => $id], 'hr_note:' . $id);
            }
        }
        $this->repo->log($viewer, $employeeId, 'note');
        return ['id' => $id];
    }

    public function deleteNote(int $noteId, ?int $viewer, bool $admin): void
    {
        $n = $this->repo->note($noteId) ?? throw new CustomException('Nie ma takiej notatki.', 'errors', 404);
        if (!$admin && (int) $n['author'] !== (int) $viewer) throw new CustomException('Notatkę usuwa jej autor albo administrator.', 'errors', 403);
        $this->repo->deleteNote($noteId);
    }

    private function noteRow(array $n, ?int $viewer, bool $admin): array
    {
        return ['id' => (int) $n['id'], 'employee_id' => (int) $n['id_employee'], 'name' => $n['name'] ?? null, 'group' => $n['group_name'] ?? null,
            'body' => $this->cipher->decrypt($n['body_enc']) ?? '(nie do odczytania)', 'visibility' => $n['visibility'], 'to_admin' => (bool) $n['to_admin'],
            'by' => $n['by_name'] ?? null, 'at' => $n['created_at'], 'can_delete' => $admin || (int) $n['author'] === (int) $viewer,
            // [279] Szybka notatka : rodzaj, temat, dla kogo, follow-up.
            'kind' => $n['kind'] ?? null, 'kind_label' => isset($n['kind']) ? (HrQuickNoteService::KINDS[$n['kind']][0] ?? null) : null,
            'topic' => isset($n['topic_enc']) ? $this->cipher->decrypt($n['topic_enc']) : null,
            'audience' => !empty($n['audience']) ? array_values(array_map(fn($c) => HrQuickNoteService::AUDIENCE[$c] ?? $c, explode(',', $n['audience']))) : [],
            'followup' => !empty($n['followup_due']) ? ['due' => $n['followup_due'], 'who' => $n['followup_name'] ?? null, 'done_at' => $n['followup_done_at'] ?? null,
                'done_by' => $n['followup_done_name'] ?? null, 'done_note' => $n['followup_done_note'] ?? null, 'late' => empty($n['followup_done_at']) && $n['followup_due'] < date('Y-m-d'),
                'can_close' => empty($n['followup_done_at']) && ($admin || (int) ($n['followup_user'] ?? 0) === (int) $viewer || (int) $n['author'] === (int) $viewer)] : null];
    }

    // --- Notes du mois (makieta 38) et bilan à l'administrateur -----------------------------------------------

    public function monthNotes(string $month, ?int $viewer, bool $admin): array
    {
        [$from, $to] = self::monthRange($month);
        $rows = array_map(fn($n) => $this->noteRow($n, $viewer, $admin), $this->repo->notes(null, $from, $to, $admin, $viewer));
        $people = [];
        foreach ($rows as $n) {
            $people[$n['employee_id']] ??= ['id' => $n['employee_id'], 'name' => $n['name'], 'group' => $n['group'], 'notes' => []];
            $people[$n['employee_id']]['notes'][] = $n;
        }
        $this->repo->log($viewer, null, 'month');
        $sent = $this->repo->summary($month);
        return ['month' => $month, 'month_label' => self::monthLabel($month), 'people' => array_values($people),
            'counts' => ['all' => count($rows), 'people' => count($people), 'public' => count(array_filter($rows, fn($n) => $n['visibility'] === 'public')),
                'private' => count(array_filter($rows, fn($n) => $n['visibility'] === 'private')), 'to_admin' => count(array_filter($rows, fn($n) => $n['to_admin']))],
            'summary' => $sent ? ['sent_at' => $sent['sent_at'], 'recipients' => (int) $sent['recipients'], 'auto' => $sent['sent_by'] === null] : null];
    }

    /**
     * Bilan du mois envoyé à chaque administrateur (e-mail, file d'envoi du serveur) : par personne, le nombre de notes
     * publiques, privées et « do administratora » — le texte se lit dans l'ERP (HR › Notatki miesiąca). $actor null = cron (dernier jour du mois). @return array{notes:int, recipients:int}
     */
    public function sendSummary(string $month, ?int $actor): array
    {
        [$from, $to] = self::monthRange($month);
        $notes = array_map(fn($n) => $this->noteRow($n, null, true), $this->repo->notes(null, $from, $to, true, null));
        $label = self::monthLabel($month);
        $by = [];
        foreach (array_reverse($notes) as $n) $by[$n['name'] . ($n['group'] ? ' · ' . $n['group'] : '')][] = $n;
        // Le texte des notes reste chiffré dans l'ERP : l'e-mail (et la file d'envoi) ne porte que qui, combien et le lien.
        $lines = ["Notatki HR — {$label} : " . count($notes) . ' (' . count($by) . ' ' . (count($by) === 1 ? 'osoba' : 'osób') . ").\n"];
        foreach ($by as $who => $list) {
            $pub = count(array_filter($list, fn($n) => $n['visibility'] === 'public'));
            $adm = count(array_filter($list, fn($n) => $n['to_admin']));
            $lines[] = '- ' . $who . ' : ' . count($list) . ' (publiczne ' . $pub . ', prywatne ' . (count($list) - $pub) . ($adm ? ', do administratora ' . $adm : '') . ')';
        }
        $lines[] = "\nTreść notatek : ERP › HR › Notatki miesiąca (" . $month . ').';
        $recipients = 0;
        if ($notes) {
            foreach ($this->repo->admins() as $a) {
                if (!$a['email'] || !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
                $this->outgoing->queue(['message_type' => 'hr_summary', 'template' => [], 'recipient' => $a['email'], 'subject' => 'HR — notatki miesiąca ' . $label,
                    'body' => implode("\n", $lines), 'variables' => ['month' => $month], 'created_by' => 'hr_summary:' . ($actor ?? 0)]);
                $recipients++;
            }
        }
        $this->repo->saveSummary($month, count($notes), $recipients, $actor);
        $this->repo->log($actor, null, 'summary');
        return ['notes' => count($notes), 'recipients' => $recipients];
    }

    /** Cron : le dernier jour du mois, une fois. @return array|null bilan envoyé, null = rien à faire aujourd'hui */
    public function autoSummary(?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable();
        if ($now->format('d') !== $now->format('t') || (int) $now->format('H') < 18) return null;
        $month = $now->format('Y-m');
        if ($this->repo->summary($month) !== null) return null;
        return $this->sendSummary($month, null);
    }

    public static function monthLabel(string $ym): string
    {
        return self::MONTHS[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
    }

    /** @return array{0:string,1:string} */
    private static function monthRange(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new CustomException('Miesiąc RRRR-MM.', 'errors', 422);
        return [$month . '-01 00:00:00', date('Y-m-01', strtotime($month . '-01 +1 month')) . ' 00:00:00'];
    }

    private static function months(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $d = $from->diff($to);
        return ($d->invert ? -1 : 1) * ($d->y * 12 + $d->m);
    }

    public static function span(int $months): string
    {
        $y = intdiv($months, 12);
        $m = $months % 12;
        return ($y ? $y . ' l. ' : '') . $m . ' m.';
    }
}
