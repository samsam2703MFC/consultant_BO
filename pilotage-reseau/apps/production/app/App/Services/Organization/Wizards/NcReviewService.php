<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Purchasing\NcReviewRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Purchasing\NcService;

/**
 * Kreator « Niezgodności miesiąca » [247] (makieta 21 ; Jakość, co miesiąc — zastępstwo : Kierownik produkcji) :
 *   1. Lista : NC zgłoszone w ocenianym miesiącu (domyślnie poprzedni) + starsze wciąż otwarte, koszt, źródło ;
 *   2. Przyczyna i działanie : każda otwarta NC po kolei — kategoria przyczyny, działanie korygujące, kto, do kiedy ;
 *   3. Dostawcy : NC dostaw w 3 miesiącach + ostatnia ocena A / B / C → audyt (co kwartał : marzec, czerwiec, wrzesień,
 *      grudzień — zastępuje zadanie « Audyt dostawcy ») i opcjonalny e-mail do dostawcy z NC i zdjęciami.
 * « Zatwierdź miesiąc » wymaga działania przy każdej otwartej NC ; zapisuje n_wizard_done (zadanie zalicza się samo).
 */
final class NcReviewService
{
    public const CODE = 'nc_reviewed';
    public const CAUSES = ['transport' => 'Transport', 'supplier' => 'Dostawca', 'storage' => 'Magazyn', 'production' => 'Produkcja', 'other' => 'Inne'];
    public const SOURCES = ['supplier_delivery' => 'Dostawa', 'production' => 'Produkcja', 'customer' => 'Klient', 'internal' => 'Wewnętrzna'];
    public const SEVERITIES = ['minor' => 'drobna', 'major' => 'poważna', 'critical' => 'krytyczna'];
    public const RESULTS = ['ok' => 'bez uwag', 'remarks' => 'z uwagami', 'failed' => 'negatywny'];
    /** Audyt proponowany : co najmniej tyle NC dostaw w 3 miesiącach albo ostatnia ocena C. */
    public const AUDIT_NC = 3;
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    public function __construct(private NcReviewRepository $repo, private NcService $nc, private LightWizardRepository $wizards, private OutgoingMessageService $outgoing)
    {
    }

    /** Oceniany miesiąc : ?m=YYYY-MM (ostatnie 12) albo poprzedni względem dnia. */
    public static function month(?string $m, string $today): array
    {
        $prev = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month'));
        $ym = is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) && $m <= substr($today, 0, 7) && $m >= date('Y-m', strtotime(substr($today, 0, 7) . '-01 -12 months')) ? $m : $prev;
        $from = $ym . '-01';
        $mon = (int) substr($ym, 5, 2);
        return ['key' => $ym, 'from' => $from, 'to' => date('Y-m-d', strtotime($from . ' +1 month')), 'label' => self::MONTHS[$mon] . ' ' . substr($ym, 0, 4),
            'quarter' => substr($ym, 0, 4) . '-Q' . (intdiv($mon - 1, 3) + 1), 'quarter_end' => $mon % 3 === 0];
    }

    public function data(?string $m, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $M = self::month($m, $today);
        $rows = array_map(fn($r) => $this->present($r, $M), $this->repo->ncs($M['from'], $M['to']));
        $inMonth = array_values(array_filter($rows, fn($r) => $r['in_month']));
        $bySource = array_fill_keys(array_keys(self::SOURCES), 0);
        foreach ($inMonth as $r) $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
        $open = array_values(array_filter($rows, fn($r) => $r['needs_action']));
        $since = date('Y-m-d', strtotime($M['to'] . ' -3 months'));
        $grades = $this->repo->lastGrades();
        $audits = $this->repo->audits($M['quarter']);
        $suppliers = [];
        foreach ($this->repo->supplierNcs($since, $M['to']) as $sid => $s) {
            $g = $grades[$sid] ?? null;
            $propose = $s['nc'] >= self::AUDIT_NC || ($g['grade'] ?? null) === 'C';
            $a = $audits[$sid] ?? null;
            $suppliers[] = ['id' => $sid, 'name' => $s['name'], 'nc3' => $s['nc'], 'cost3' => round($s['cost'], 2), 'grade' => $g['grade'] ?? null, 'grade_quarter' => $g['quarter'] ?? null,
                'propose_audit' => $propose, 'email' => filter_var(trim((string) $s['email']), FILTER_VALIDATE_EMAIL) ? true : false,
                'month_nc' => count(array_filter($inMonth, fn($r) => $r['supplier_id'] === $sid)),
                'audit' => $a ? ['planned_on' => $a['planned_on'], 'done_on' => $a['done_on'], 'result' => $a['result'], 'note' => $a['note']] : null];
        }
        usort($suppliers, fn($a, $b) => [$b['propose_audit'], $b['nc3'], $a['name']] <=> [$a['propose_audit'], $a['nc3'], $b['name']]);
        $top = $suppliers[0] ?? null;
        $done = $this->wizards->done(self::CODE, date('Y-m-01', strtotime($today)), date('Y-m-d', strtotime(date('Y-m-01', strtotime($today)) . ' +1 month')));
        return ['month' => $M, 'rows' => $rows, 'open' => array_map(fn($r) => $r['id'], $open),
            'totals' => ['count' => count($inMonth), 'by_source' => $bySource, 'cost' => round(array_sum(array_map(fn($r) => (float) ($r['cost'] ?? 0), $inMonth)), 2),
                'open' => count($open), 'older_open' => count(array_filter($rows, fn($r) => !$r['in_month'])), 'top' => $top && $top['nc3'] > 0 ? ['name' => $top['name'], 'nc3' => $top['nc3']] : null],
            'suppliers' => $suppliers, 'users' => $this->wizards->users(), 'causes' => self::CAUSES, 'sources' => self::SOURCES, 'severities' => self::SEVERITIES, 'results' => self::RESULTS,
            'done' => $done ? ['summary' => $done['summary'], 'day' => $done['day']] : null];
    }

    private function present(array $r, array $M): array
    {
        $action = trim((string) $r['corrective_action']) !== '';
        return ['id' => (int) $r['id'], 'number' => $r['number'], 'date' => $r['reported_at'], 'in_month' => $r['reported_at'] >= $M['from'] && $r['reported_at'] < $M['to'],
            'source' => $r['source'], 'severity' => $r['severity'], 'status' => $r['status'], 'description' => mb_substr((string) $r['description'], 0, 240),
            'supplier_id' => $r['id_supplier'] !== null ? (int) $r['id_supplier'] : null, 'supplier' => $r['supplier_name'], 'material' => $r['material_name'],
            'product' => $r['product_name'], 'group' => $r['group_name'], 'quantity' => $r['quantity'] !== null ? (float) $r['quantity'] : null, 'unit' => $r['unit'],
            'cost' => $r['cost_impact'] !== null ? (float) $r['cost_impact'] : null, 'immediate' => $r['immediate_action'], 'cause_cat' => $r['cause_cat'], 'root_cause' => $r['root_cause'],
            'action' => $r['corrective_action'], 'owner' => $r['owner_user'] !== null ? (int) $r['owner_user'] : null, 'owner_name' => $r['owner_name'], 'due_date' => $r['due_date'],
            'photos' => (int) $r['photos'], 'photo_id' => $r['photo_id'] !== null ? (int) $r['photo_id'] : null,
            'needs_action' => $r['status'] !== 'closed' && (!$action || $r['cause_cat'] === null)];
    }

    /** Krok 2 : przyczyna i działanie jednej NC (status : w toku albo zamknięta). */
    public function saveAction(int $id, array $in, ?int $userId): void
    {
        $cat = $in['cause_cat'] ?? null;
        if (!is_string($cat) || !isset(self::CAUSES[$cat])) throw new CustomException('Wybierz przyczynę.', 'errors', 422);
        $action = trim((string) ($in['corrective_action'] ?? ''));
        if (mb_strlen($action) < 5) throw new CustomException('Wpisz działanie korygujące (co najmniej 5 znaków).', 'errors', 422);
        $status = ($in['status'] ?? 'in_progress') === 'closed' ? 'closed' : 'in_progress';
        $payload = ['corrective_action' => $action, 'status' => $status];
        if (isset($in['root_cause']) && trim((string) $in['root_cause']) !== '') $payload['root_cause'] = trim((string) $in['root_cause']);
        if (!empty($in['owner_user'])) $payload['owner_user'] = (int) $in['owner_user'];
        if (!empty($in['due_date'])) $payload['due_date'] = (string) $in['due_date'];
        $this->nc->save($payload, $id, $userId);
        $this->repo->setCause($id, $cat);
    }

    /** Krok 3 : audyt dostawcy w kwartale miesiąca (planowany / zrobiony, wynik, notatka). */
    public function saveAudit(?string $m, array $in, ?int $userId, ?string $today = null): void
    {
        $M = self::month($m, $today ?? date('Y-m-d'));
        $sid = (int) ($in['supplier_id'] ?? 0);
        if ($sid <= 0 || !$this->repo->supplier($sid)) throw new CustomException('Nieznany dostawca.', 'errors', 422);
        $date = fn($k) => isset($in[$k]) && is_string($in[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in[$k]) ? $in[$k] : null;
        $result = isset($in['result']) && isset(self::RESULTS[$in['result']]) ? $in['result'] : null;
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $this->repo->saveAudit($M['quarter'], $sid, ['reason' => mb_substr(trim((string) ($in['reason'] ?? '')), 0, 120) ?: null, 'planned_on' => $date('planned_on') ?? date('Y-m-d', strtotime('+14 days')),
            'done_on' => $date('done_on'), 'result' => $result, 'note' => $note !== '' ? $note : null], $userId);
    }

    /** Krok 3 : e-mail do dostawcy z NC miesiąca (i zdjęciami) — kolejka wysyłki, język dostawcy. */
    public function mailSupplier(?string $m, int $supplierId, ?int $userId, ?string $today = null): array
    {
        $M = self::month($m, $today ?? date('Y-m-d'));
        $s = $this->repo->supplier($supplierId) ?? throw new CustomException('Nieznany dostawca.', 'errors', 422);
        $email = trim((string) $s['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new CustomException('Dostawca « ' . $s['name'] . ' » nie ma adresu e-mail.', 'errors', 422);
        $ncs = array_values(array_filter($this->data($M['key'], $today)['rows'], fn($r) => $r['supplier_id'] === $supplierId && $r['source'] === 'supplier_delivery'));
        if (!$ncs) throw new CustomException('Brak niezgodności dostaw tego dostawcy.', 'errors', 422);
        $lang = in_array($s['order_lang'], ['fr', 'nl'], true) ? $s['order_lang'] : 'pl';
        $T = ['pl' => ['subj' => 'Niezgodności dostaw — %s', 'hello' => 'Dzień dobry,', 'intro' => 'przy przyjęciu Państwa dostaw stwierdziliśmy następujące niezgodności :',
                'ask' => 'Prosimy o informację o działaniach korygujących w ciągu 7 dni.', 'bye' => 'Z poważaniem'],
            'fr' => ['subj' => 'Non-conformités de livraison — %s', 'hello' => 'Bonjour,', 'intro' => 'à la réception de vos livraisons, nous avons constaté les non-conformités suivantes :',
                'ask' => 'Merci de nous indiquer vos actions correctives sous 7 jours.', 'bye' => 'Cordialement'],
            'nl' => ['subj' => 'Afwijkingen bij levering — %s', 'hello' => 'Goedendag,', 'intro' => 'bij ontvangst van uw leveringen stelden wij de volgende afwijkingen vast :',
                'ask' => 'Gelieve ons binnen 7 dagen uw corrigerende maatregelen mee te delen.', 'bye' => 'Met vriendelijke groeten']][$lang];
        $lines = array_map(fn($r) => '- ' . $r['number'] . ' (' . date('d.m.Y', strtotime($r['date'])) . ')' . ($r['material'] ? ' · ' . $r['material'] : '')
            . ($r['quantity'] !== null ? ' · ' . rtrim(rtrim(number_format($r['quantity'], 3, ',', ''), '0'), ',') . ' ' . $r['unit'] : '') . ' : ' . $r['description'], $ncs);
        $body = $T['hello'] . "\n\n" . $T['intro'] . "\n\n" . implode("\n", $lines) . "\n\n" . $T['ask'] . "\n\n" . $T['bye'];
        $files = [];
        foreach ($this->repo->photos(array_map(fn($r) => $r['id'], $ncs)) as $i => $p) {
            $ext = str_contains((string) $p['mime'], 'png') ? 'png' : 'jpg';
            $files[] = ['filename' => 'NC-' . ($i + 1) . '.' . $ext, 'mime_type' => (string) $p['mime'], 'content' => (string) $p['content']];
        }
        $id = $this->outgoing->queue(['message_type' => 'supplier_nc', 'template' => [], 'recipient' => $email, 'subject' => sprintf($T['subj'], $M['label']),
            'body' => $body, 'variables' => ['supplier' => $s['name'], 'month' => $M['key'], 'nc' => array_map(fn($r) => $r['number'], $ncs)], 'created_by' => 'nc_review:' . ($userId ?? 0)], $files);
        return ['message_id' => $id, 'ncs' => count($ncs), 'photos' => count($files)];
    }

    /** « Zatwierdź miesiąc » : każda otwarta NC ma przyczynę i działanie → zadanie zaliczone. */
    public function finish(?string $m, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($m, $today);
        if ($d['open']) throw new CustomException(count($d['open']) . ' ' . (count($d['open']) === 1 ? 'niezgodność nie ma' : 'niezgodności nie ma') . ' przyczyny i działania — krok 2.', 'errors', 422);
        $audits = count(array_filter($d['suppliers'], fn($s) => $s['audit'] !== null));
        $summary = sprintf('%s : %d NC · koszt %s zł%s', $d['month']['label'], $d['totals']['count'], number_format($d['totals']['cost'], 0, ',', "\u{00A0}"), $audits ? ' · ' . $audits . ' audyt' . ($audits === 1 ? '' : 'y') : '');
        $this->wizards->markDone(self::CODE, $today, $summary, $userId);
        return ['summary' => $summary];
    }
}
