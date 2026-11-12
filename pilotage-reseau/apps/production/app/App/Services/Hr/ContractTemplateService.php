<?php
declare(strict_types=1);

namespace App\Services\Hr;

use App\Exceptions\CustomException;
use App\Repositories\Hr\HrRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Utils\TCPDFService;
use DateTimeImmutable;

/**
 * [276] HR › Szablony umów (makieta 42) : modèles de contrats et d'écrits RH (n_hr_template) remplis depuis la fiche HR
 * (personne, adresse, PESEL, poste, salaire, wymiar, dates) et les données de la société (erp_company_data, réglages
 * pay.*). Un champ manquant est signalé en rouge ; tant qu'il en manque, rien n'est généré. Sortie : DOCX à éditer, ou PDF
 * rangé directement dans les documents de la personne (chiffré). Le texte des modèles est modifiable par
 * l'administrateur ; à faire relire par un juriste ou le bureau RH avant le premier usage (affiché).
 */
final class ContractTemplateService
{
    /** Champ => libellé (repère des champs manquants). */
    public const FIELDS = [
        'pracownik' => 'pracownik', 'adres_pracownika' => 'adres pracownika (karta HR)', 'pesel' => 'PESEL (karta HR)', 'stanowisko' => 'stanowisko',
        'wymiar' => 'wymiar etatu (karta HR)', 'wynagrodzenie' => 'wynagrodzenie (karta HR)', 'stawka_godzinowa' => 'stawka godzinowa (karta HR)',
        'data_od' => 'data od', 'data_do' => 'data do', 'data_zawarcia' => 'data zawarcia', 'miejscowosc' => 'miejscowość (Ustawienia płac)',
        'miejsce_pracy' => 'miejsce pracy (Ustawienia płac)', 'firma' => 'nazwa firmy', 'adres_firmy' => 'adres firmy', 'nip_firmy' => 'NIP firmy',
        'reprezentant' => 'reprezentant firmy (Ustawienia płac)', 'przedmiot' => 'przedmiot umowy', 'przyczyna' => 'przyczyna', 'okres_wypowiedzenia' => 'okres wypowiedzenia',
    ];
    private const PARTIES = ['zlecenie' => ['Zleceniodawca', 'Zleceniobiorca'], 'dzielo' => ['Zamawiający', 'Wykonawca'], 'wypowiedzenie' => ['Pracodawca', 'Otrzymałem/am (data, podpis)'],
        'swiadectwo' => ['Pracodawca', 'Otrzymałem/am (data, podpis)'], 'informacja' => ['Pracodawca', 'Otrzymałem/am (data, podpis)']];

    public function __construct(private HrRepository $repo, private HrService $hr, private HrCipher $cipher, private ParamRepository $params)
    {
    }

    public function list(): array
    {
        return array_map(fn($t) => ['code' => $t['code'], 'title' => $t['title'], 'legal' => $t['legal'], 'kind' => $t['kind'], 'body' => $t['body'],
            'fields' => self::placeholders($t['body']), 'updated_at' => $t['updated_at'], 'by' => $t['by_name']], $this->repo->templates());
    }

    public function save(string $code, array $in, ?int $userId): array
    {
        $t = $this->repo->template($code) ?? throw new CustomException('Nie ma takiego szablonu.', 'errors', 404);
        $title = mb_substr(trim((string) ($in['title'] ?? $t['title'])), 0, 160);
        $body = str_replace("\r\n", "\n", trim((string) ($in['body'] ?? '')));
        if ($title === '' || mb_strlen($body) < 20) throw new CustomException('Tytuł i treść szablonu są wymagane.', 'errors', 422);
        if (mb_strlen($body) > 60000) throw new CustomException('Treść za długa.', 'errors', 422);
        $unknown = array_diff(self::placeholders($body), array_keys(self::FIELDS));
        if ($unknown) throw new CustomException('Nieznane pola : {' . implode('}, {', $unknown) . '}.', 'errors', 422);
        $this->repo->saveTemplate($code, $title, $body, $userId);
        return $this->list();
    }

    /**
     * Aperçu : paragraphes avec les champs remplis ; un champ manquant reste « ⟦brak : …⟧ ».
     * $extra : sign_date, date_from, date_to, salary, position, work_time, subject, reason.
     */
    public function render(string $code, int $employeeId, array $extra, ?int $viewer): array
    {
        $t = $this->repo->template($code) ?? throw new CustomException('Nie ma takiego szablonu.', 'errors', 404);
        $values = $this->values($employeeId, $extra);
        $missing = [];
        $text = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values, &$missing) {
            $v = $values[$m[1]] ?? null;
            if ($v === null || $v === '') { $missing[$m[1]] = self::FIELDS[$m[1]] ?? $m[1]; return '⟦brak : ' . (self::FIELDS[$m[1]] ?? $m[1]) . '⟧'; }
            return $v;
        }, $t['body']);
        $paras = array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", $text)), fn($p) => $p !== ''));
        $this->repo->log($viewer, $employeeId, 'template');
        return ['code' => $code, 'title' => $t['title'], 'kind' => $t['kind'], 'legal' => $t['legal'], 'heading' => array_shift($paras) ?? $t['title'],
            'paragraphs' => $paras, 'missing' => array_values($missing), 'parties' => self::PARTIES[$code] ?? ['Pracodawca', 'Pracownik'],
            'employee' => $values['pracownik'], 'sign_date' => $values['_sign_iso']];
    }

    /** @return array{filename: string, content: string} */
    public function docx(string $code, int $employeeId, array $extra, ?int $viewer): array
    {
        $r = $this->complete($code, $employeeId, $extra, $viewer);
        return ['filename' => $this->filename($r, 'docx'), 'content' => ContractDocx::build($r['heading'], $r['paragraphs'], $r['parties'])];
    }

    /** PDF rangé dans les documents de la personne (n_hr_document, chiffré). */
    public function savePdf(string $code, int $employeeId, array $extra, ?int $viewer): array
    {
        $r = $this->complete($code, $employeeId, $extra, $viewer);
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle($r['heading']);
        $pdf->brandPage($r['heading'], ['ref' => $r['employee'], 'company' => ($this->repo->company()['name'] ?? '') . ' · dokument kadrowy', 'producer_only' => true]);
        $pdf->SetY(34);
        $pdf->brandText($r['heading'], ['style' => 'B', 'size' => 13, 'align' => 'C', 'after' => 5]);
        foreach ($r['paragraphs'] as $p) $pdf->brandText($p, ['size' => 10, 'align' => 'J', 'after' => 3]);
        $pdf->SetY($pdf->GetY() + 8);
        $pdf->brandSignatures($r['parties']);
        $content = $pdf->Output('', 'S');
        $filename = $this->filename($r, 'pdf');
        $id = $this->repo->insertDocument(['id_employee' => $employeeId, 'kind' => $r['kind'], 'title' => $r['title'], 'filename' => $filename, 'mime' => 'application/pdf',
            'size' => strlen($content), 'doc_date' => $r['sign_date'], 'content' => $this->cipher->encryptBytes($content), 'uploaded_by' => $viewer]);
        $this->repo->log($viewer, $employeeId, 'template_pdf');
        return ['document_id' => $id, 'filename' => $filename, 'message' => 'Zapisano w dokumentach pracownika : ' . $r['title'] . '.'];
    }

    private function complete(string $code, int $employeeId, array $extra, ?int $viewer): array
    {
        $r = $this->render($code, $employeeId, $extra, $viewer);
        if ($r['missing']) throw new CustomException('Uzupełnij : ' . implode(', ', $r['missing']) . '.', 'errors', 422, ['fields' => ['missing' => $r['missing']]]);
        return $r;
    }

    private function filename(array $r, string $ext): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '_', strtr($r['employee'], ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']));
        return $r['code'] . '_' . trim($name, '_') . '_' . $r['sign_date'] . '.' . $ext;
    }

    /** Valeurs des champs pour une personne. */
    private function values(int $employeeId, array $x): array
    {
        $p = $this->hr->person($employeeId, null, true);
        $pay = $p['pay'] ?? [];
        $c = $this->repo->company();
        $date = fn(?string $v) => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? date('d.m.Y', strtotime($v)) : null;
        $sign = ($x['sign_date'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $x['sign_date']) ? (string) $x['sign_date'] : date('Y-m-d');
        $from = ($x['date_from'] ?? '') ?: $p['hire_date'];
        $to = ($x['date_to'] ?? '') ?: $p['contract_end'];
        $salary = trim((string) ($x['salary'] ?? ''));
        $amount = $salary !== '' && is_numeric(str_replace([' ', ','], ['', '.'], $salary)) ? (float) str_replace([' ', ','], ['', '.'], $salary) : ($pay['amount'] ?? null);
        $money = fn(?float $v) => $v === null ? null : number_format($v, 2, ',', ' ');
        $param = fn(string $k) => trim($this->params->get($k, '')) ?: null;
        return [
            'pracownik' => $p['name'], 'adres_pracownika' => $pay['address'] ?? null, 'pesel' => $pay['pesel'] ?? null,
            'stanowisko' => trim((string) ($x['position'] ?? '')) ?: ($p['position'] ?: null),
            'wymiar' => trim((string) ($x['work_time'] ?? '')) ?: ($p['work_time'] ?: null),
            'wynagrodzenie' => ($pay['type'] ?? 'monthly') === 'hourly' && $salary === '' ? null : $money($amount),
            'stawka_godzinowa' => ($pay['type'] ?? null) === 'hourly' || $salary !== '' ? $money($amount) : null,
            'data_od' => $date($from), 'data_do' => $date($to), 'data_zawarcia' => $date($sign), '_sign_iso' => $sign,
            'miejscowosc' => $param('pay.company_city'), 'miejsce_pracy' => $param('pay.workplace'), 'reprezentant' => $param('pay.company_rep'),
            'firma' => trim((string) ($c['name'] ?? '')) ?: null, 'adres_firmy' => trim(implode(', ', array_filter([$c['address_1'] ?? null, $c['address_2'] ?? null]))) ?: null,
            'nip_firmy' => trim((string) ($c['nip'] ?? '')) ?: null,
            'przedmiot' => trim((string) ($x['subject'] ?? '')) ?: null, 'przyczyna' => trim((string) ($x['reason'] ?? '')) ?: null,
            'okres_wypowiedzenia' => self::notice($p['contract_type'], $p['hire_date'], $p['contract_end'], $sign),
        ];
    }

    /** Okres wypowiedzenia : art. 36 § 1 (staż u pracodawcy) ; okres próbny : art. 34. */
    public static function notice(?string $contract, ?string $hire, ?string $end, string $on): ?string
    {
        if (!$hire) return null;
        if ($contract === 'probny') {
            $days = $end ? (int) ((strtotime($end) - strtotime($hire)) / 86400) + 1 : 90;
            return $days <= 14 ? '3 dni robocze' : ($days < 90 ? '1 tydzień' : '2 tygodnie');
        }
        $months = (int) (new DateTimeImmutable($hire))->diff(new DateTimeImmutable($on))->format('%y') * 12 + (int) (new DateTimeImmutable($hire))->diff(new DateTimeImmutable($on))->format('%m');
        return $months < 6 ? '2 tygodnie' : ($months < 36 ? '1 miesiąc' : '3 miesiące');
    }

    /** @return list<string> */
    public static function placeholders(string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $body, $m);
        return array_values(array_unique($m[1]));
    }
}
