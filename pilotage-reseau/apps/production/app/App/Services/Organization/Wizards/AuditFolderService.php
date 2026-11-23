<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\AuditFolderRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\PpwrMonthRepository;
use App\Services\Material\Ppwr\RawMaterialPpwrService;
use App\Services\Organization\TaskWizards;
use App\Services\Utils\TCPDFService;

/**
 * Asystent « Teczka do audytu i kontroli (przegląd kwartału) » [406] (co kwartał ; zakres obowiązków « produkcja » 237 :
 * Kierownik produkcji 2.8, 2.9, 7.4, 8.4). Trimestre = celui de la tâche (état aujourd'hui).
 *   1. Przegląd : ce que l'ERP sait déjà, et la liste des documents à tenir prêts :
 *        - wizard:<code> — assistants de contrôle en usage (une tâche de poste active les porte) : wizard_never (jamais
 *          « Zakończ »), wizard_stale (dernier « Zakończ » plus ancien que le rythme le plus court de ses tâches +
 *          audit_folder.grace_days jours) ; un assistant sans tâche est listé « nieużywany », sans signal ;
 *        - erp:haccp / erp:nc / erp:complaints / erp:ppwr — open_items : ruptures de la chaîne du froid non traitées,
 *          niezgodności ouvertes, réclamations sans décision, opakowania d'un produit actif au profil PPWR incomplet
 *          (RawMaterialPpwrService::issues, la règle du Rejestr PPWR) ; erp:sop — sop_review : SOP en vigueur après leur
 *          date de revue ou sans date ;
 *        - doc:<id> (n_audit_folder_item) — doc_unchecked (jamais contrôlé), doc_due (dernier contrôle + N mois dans les
 *          audit_folder.alert_days jours ou dépassé), doc_no_owner, doc_no_location ; liste modifiable ici.
 *   2. Do decyzji : une décision par élément signalé (n_audit_folder_review, par trimestre) — Gotowe — w teczce (document :
 *      responsable et emplacement exigés, contrôle du jour enregistré ; élément de l'ERP encore signalé : note obligatoire),
 *      Do uzupełnienia (date d'ici un an et note : ce qui manque), Nie dotyczy (note).
 *   3. Zatwierdź : « Zakończ » écrit n_wizard_done — bloqué tant qu'un élément attend ; « teczka » PDF à imprimer.
 * Lecture seule des tables de l'ERP ; n'écrit que n_audit_folder_item, n_audit_folder_review et n_wizard_done ; rien n'est envoyé.
 */
final class AuditFolderService
{
    public const CODE = 'audit_folder_reviewed';
    public const PATH = '/audit-folder';
    public const DECISIONS = ['ready' => 'Gotowe — w teczce', 'action' => 'Do uzupełnienia', 'not_applicable' => 'Nie dotyczy'];
    public const KINDS = ['doc' => 'Dokument', 'wizard' => 'Asystent kontroli', 'erp' => 'Sprawy w ERP'];
    /** Signaux, dans l'ordre de priorité. */
    public const SIGNALS = ['wizard_never' => 'asystent nigdy nie zakończony', 'wizard_stale' => 'asystent nie zakończony w swoim rytmie', 'open_items' => 'otwarte sprawy',
        'sop_review' => 'procedury po terminie przeglądu albo bez daty', 'doc_unchecked' => 'dokument jeszcze nie sprawdzony', 'doc_due' => 'czas sprawdzić dokument',
        'doc_no_owner' => 'brak osoby odpowiedzialnej', 'doc_no_location' => 'brak miejsca (segregator, folder, plik)'];
    /** Assistants de contrôle dont l'état compte pour un audit (codes de TaskWizards::CATALOG). */
    public const WIZARDS = ['haccp_month_reviewed', 'ppwr_month_reviewed', 'nc_reviewed', 'complaints_reviewed', 'lots_qc_day_checked', 'samples_done',
        'staff_deadlines_reviewed', 'maintenance_reviewed'];
    public const PERIOD_DAYS = ['daily' => 1, 'weekly' => 7, 'monthly' => 31, 'quarterly' => 92];
    public const RHYTHMS = ['daily' => 'codziennie', 'weekly' => 'co tydzień', 'monthly' => 'co miesiąc', 'quarterly' => 'co kwartał'];
    /** Éléments ouverts de l'ERP : clé => [libellé, écran]. */
    public const ERP = ['haccp' => ['Zdarzenia HACCP (łańcuch chłodniczy)', HaccpMonthService::PATH], 'nc' => ['Niezgodności (NC)', '/purchasing/nc'],
        'complaints' => ['Reklamacje klientów', '/franchise-complaints'], 'ppwr' => ['Rejestr PPWR opakowań', '/ppwr/register'], 'sop' => ['Procedury SOP', '/sop']];
    public const AREAS = ['haccp' => 'HACCP', 'sanepid' => 'Sanepid', 'ppwr' => 'PPWR', 'bdo' => 'BDO', 'hr' => 'Kadry i BHP', 'equipment' => 'Maszyny i urządzenia',
        'quality' => 'Jakość', 'other' => 'Inne'];
    public const FORMATS = ['paper' => 'papier', 'electronic' => 'elektronicznie', 'both' => 'papier i elektronicznie'];
    public const SETTINGS = ['grace_days' => 'audit_folder.grace_days', 'alert_days' => 'audit_folder.alert_days'];
    private const DEFAULTS = ['grace_days' => 7, 'alert_days' => 14];
    private const ROMAN = [1 => 'I', 'II', 'III', 'IV'];

    public function __construct(private AuditFolderRepository $repo, private PpwrMonthRepository $ppwr, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Premier jour du trimestre (clé des décisions et de l'état de la tâche du trimestre). */
    public static function quarterStart(string $today): string
    {
        $m = (int) substr($today, 5, 2);
        return sprintf('%s-%02d-01', substr($today, 0, 4), intdiv($m - 1, 3) * 3 + 1);
    }

    /** « IV kwartał 2026 ». */
    public static function quarterLabel(string $quarterStart): string
    {
        return self::ROMAN[intdiv((int) substr($quarterStart, 5, 2) - 1, 3) + 1] . ' kwartał ' . substr($quarterStart, 0, 4);
    }

    public function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS as $k => $code) {
            $raw = trim($this->params->get($code, (string) self::DEFAULTS[$k]));
            $out[$k] = ParamRules::rangeError($code, $raw) === null ? (int) $raw : self::DEFAULTS[$k];
        }
        return $out;
    }

    /** Données des trois étapes ($withAll : aussi les éléments sans signal). */
    public function data(?string $today = null, bool $withAll = false): array
    {
        $today ??= date('Y-m-d');
        $quarter = self::quarterStart($today);
        $set = $this->settings();
        $soon = date('Y-m-d', strtotime($today . ' +' . $set['alert_days'] . ' days'));
        $items = [];

        // Assistants de contrôle en usage.
        $codes = array_values(array_filter(self::WIZARDS, fn($c) => isset(TaskWizards::CATALOG[$c])));
        $freq = $this->repo->wizardTasks($codes);
        $last = $this->repo->lastDone($codes, $today);
        $unused = [];
        foreach ($codes as $c) {
            $info = TaskWizards::info($c);
            if (!isset($freq[$c])) { $unused[] = $info['label']; continue; }
            $rhythm = array_reduce($freq[$c], fn($a, $f) => $a === null || (self::PERIOD_DAYS[$f] ?? 99) < (self::PERIOD_DAYS[$a] ?? 99) ? $f : $a);
            $limit = date('Y-m-d', strtotime($today . ' -' . ((self::PERIOD_DAYS[$rhythm] ?? 31) + $set['grace_days']) . ' days'));
            $l = $last[$c] ?? null;
            $codesW = $l === null ? ['wizard_never'] : ($l['day'] < $limit ? ['wizard_stale'] : []);
            $state = $l === null ? 'nigdy nie zakończony' : 'ostatnio ' . self::d($l['day']) . ($l['summary'] ? ' — ' . $l['summary'] : '');
            $items[] = ['key' => 'wizard:' . $c, 'kind' => 'wizard', 'kind_label' => self::KINDS['wizard'], 'title' => $info['label'], 'sub' => self::RHYTHMS[$rhythm] ?? $rhythm,
                'state' => $state, 'codes' => $codesW,
                'signals' => array_map(fn($k) => self::sig($k, $k === 'wizard_stale' ? 'ostatnio ' . self::d($l['day']) . ', rytm ' . (self::RHYTHMS[$rhythm] ?? $rhythm) . ' + ' . $set['grace_days'] . ' dni' : 'brak « Zakończ » w ERP'), $codesW),
                'links' => [['href' => $info['link'], 'label' => $info['label']]]];
        }

        // Éléments ouverts de l'ERP.
        $counts = ['haccp' => $this->repo->haccpUntreated(), 'nc' => $this->repo->ncOpen(), 'complaints' => $this->repo->complaintsOpen(), 'ppwr' => $this->ppwrIncomplete()];
        $what = ['haccp' => 'nieobsłużonych przerwań łańcucha chłodniczego', 'nc' => 'niezgodności otwartych albo w toku', 'complaints' => 'reklamacji bez decyzji',
            'ppwr' => 'opakowań aktywnych produktów z brakami w profilu PPWR'];
        foreach ($counts as $k => $n) {
            $codesE = $n !== null && $n > 0 ? ['open_items'] : [];
            $items[] = ['key' => 'erp:' . $k, 'kind' => 'erp', 'kind_label' => self::KINDS['erp'], 'title' => self::ERP[$k][0], 'sub' => null, 'count' => $n,
                'state' => $n === null ? 'brak danych w ERP' : ($n === 0 ? 'brak otwartych spraw' : $n . ' ' . $what[$k]), 'codes' => $codesE,
                'signals' => array_map(fn($x) => self::sig($x, $n . ' ' . $what[$k]), $codesE), 'links' => [['href' => self::ERP[$k][1], 'label' => self::ERP[$k][0]]]];
        }
        $sops = $this->repo->sopLate($today);
        $late = $sops === null ? 0 : count(array_filter($sops, fn($s) => $s['review_at'] !== null));
        $noDate = $sops === null ? 0 : count($sops) - $late;
        $sopDetail = $sops ? implode(' · ', array_filter([$late ? $late . ' po terminie przeglądu' : null, $noDate ? $noDate . ' bez daty przeglądu' : null]))
            . ' : ' . implode(', ', array_slice(array_map(fn($s) => (string) $s['code'], $sops), 0, 8)) . (count($sops) > 8 ? '…' : '') : null;
        $items[] = ['key' => 'erp:sop', 'kind' => 'erp', 'kind_label' => self::KINDS['erp'], 'title' => self::ERP['sop'][0], 'sub' => null, 'count' => $sops === null ? null : count($sops),
            'state' => $sops === null ? 'brak danych w ERP' : ($sops === [] ? 'wszystkie w terminie przeglądu' : $sopDetail), 'codes' => $sops ? ['sop_review'] : [],
            'signals' => $sops ? [self::sig('sop_review', $sopDetail)] : [], 'links' => [['href' => self::ERP['sop'][1], 'label' => self::ERP['sop'][0]]]];

        // Documents de la teczka.
        $docs = $this->repo->hasTable('n_audit_folder_item') ? $this->repo->items() : [];
        foreach ($docs as $doc) {
            $id = (int) $doc['id'];
            $lastC = $doc['last_checked'] !== null ? substr((string) $doc['last_checked'], 0, 10) : null;
            $next = $lastC !== null ? MaintenanceService::addMonths($lastC, (int) $doc['check_months']) : null;
            $codesD = [];
            if ($lastC === null) $codesD[] = 'doc_unchecked';
            elseif ($next <= $soon) $codesD[] = 'doc_due';
            if (trim((string) $doc['owner']) === '') $codesD[] = 'doc_no_owner';
            if (trim((string) $doc['location']) === '') $codesD[] = 'doc_no_location';
            $items[] = ['key' => 'doc:' . $id, 'kind' => 'doc', 'kind_label' => self::KINDS['doc'], 'title' => (string) $doc['title'],
                'sub' => self::AREAS[$doc['area']] . ' · ' . self::FORMATS[$doc['format']] . ' · co ' . (int) $doc['check_months'] . ' mies.',
                'state' => $lastC === null ? 'nie sprawdzony' : 'sprawdzony ' . self::d($lastC) . ($doc['checked_by_name'] ? ' (' . $doc['checked_by_name'] . ')' : '') . ', następny ' . self::d($next),
                'doc' => ['id' => $id, 'title' => (string) $doc['title'], 'area' => $doc['area'], 'format' => $doc['format'], 'owner' => $doc['owner'], 'location' => $doc['location'],
                    'check_months' => (int) $doc['check_months'], 'note' => $doc['note'], 'last_checked' => $lastC, 'next_check' => $next],
                'codes' => $codesD, 'signals' => array_map(fn($k) => self::sig($k, $k === 'doc_due' ? 'następne sprawdzenie ' . self::d($next) : null), $codesD), 'links' => []];
        }

        // Décisions du trimestre (pas de reprise d'un trimestre à l'autre : chaque teczka est revue).
        $dec = $this->repo->hasTable('n_audit_folder_review') ? $this->repo->decisions($quarter) : [];
        $all = array_column($items, null, 'key');
        foreach ($all as $key => &$it) {
            $mine = $dec[$key] ?? null;
            $covers = $mine !== null && array_diff($it['codes'], array_filter(explode(',', (string) $mine['flags']))) === [];
            $covered = $mine !== null && match ((string) $mine['decision']) {
                'ready' => $it['kind'] === 'doc' ? $it['codes'] === [] : true,
                'action' => ($mine['due_on'] !== null && substr((string) $mine['due_on'], 0, 10) >= $today) || $it['codes'] === [],
                default => $covers,
            };
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['waiting'] = $it['codes'] !== [] && !$covered;
            $it['reason'] = !$it['waiting'] || $mine === null ? null : match ((string) $mine['decision']) {
                'action' => 'termin uzupełnienia ' . self::d(substr((string) $mine['due_on'], 0, 10)) . ' minął',
                'ready' => 'sygnał wrócił po « Gotowe »',
                default => 'nowy sygnał po decyzji',
            };
        }
        unset($it);
        $items = array_values(array_filter($all, fn($i) => $i['codes'] !== [] || isset($dec[$i['key']])));
        $rank = array_flip(array_keys(self::SIGNALS));
        $kr = ['wizard' => 0, 'erp' => 1, 'doc' => 2];
        $first = fn(array $i) => $i['codes'] ? $rank[$i['codes'][0]] : 99;
        usort($items, fn($a, $b) => [$b['waiting'], $kr[$a['kind']], $first($a), $a['title']] <=> [$a['waiting'], $kr[$b['kind']], $first($b), $b['title']]);

        $count = fn(array $list, callable $f) => count(array_filter($list, $f));
        $allList = array_values($all);
        $wiz = array_values(array_filter($allList, fn($i) => $i['kind'] === 'wizard'));
        $erp = array_values(array_filter($allList, fn($i) => $i['kind'] === 'erp'));
        $docsL = array_values(array_filter($allList, fn($i) => $i['kind'] === 'doc'));
        $totals = ['review' => count($items), 'waiting' => $count($items, fn($i) => $i['waiting']), 'resolved' => $count($items, fn($i) => !$i['waiting']),
            'ready' => $count($items, fn($i) => ($i['decision']['code'] ?? null) === 'ready'), 'action' => $count($items, fn($i) => ($i['decision']['code'] ?? null) === 'action'),
            'not_applicable' => $count($items, fn($i) => ($i['decision']['code'] ?? null) === 'not_applicable'),
            'wizards' => count($wiz), 'wizards_ok' => $count($wiz, fn($i) => $i['codes'] === []), 'wizards_unused' => count($unused),
            'erp_open' => $count($erp, fn($i) => $i['codes'] !== []), 'docs' => count($docsL), 'docs_ok' => $count($docsL, fn($i) => $i['codes'] === []),
            'by_kind' => array_combine(array_keys(self::KINDS), array_map(fn($k) => $count($items, fn($i) => $i['kind'] === $k), array_keys(self::KINDS)))];
        $label = self::quarterLabel($quarter);
        $done = $this->wizards->done(self::CODE, $quarter, date('Y-m-d', strtotime($quarter . ' +3 months')));
        return ($withAll ? ['all' => $all] : []) + ['today' => $today, 'quarter_start' => $quarter, 'quarter_label' => $label, 'soon_until' => $soon, 'settings' => $set,
            'checks' => array_merge($wiz, $erp), 'documents' => $docsL, 'unused' => $unused,
            'review' => $items, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $totals),
            'decisions' => self::DECISIONS, 'kind_labels' => self::KINDS, 'signal_labels' => self::SIGNALS, 'areas' => self::AREAS, 'formats' => self::FORMATS,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::d((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Opakowania utilisés dans un produit actif au profil PPWR incomplet (null : tables absentes). */
    private function ppwrIncomplete(): ?int
    {
        $m = $this->ppwr->packagingMaterials();
        if ($m === null) return null;
        return count(array_filter($m, fn($r) => ($r['active_products'] === null || (int) $r['active_products'] > 0) && RawMaterialPpwrService::issues($r) !== []));
    }

    private function presentDecision(array $d): array
    {
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'],
            'flags' => array_values(array_filter(explode(',', (string) $d['flags']))), 'due_on' => $d['due_on'] !== null ? substr((string) $d['due_on'], 0, 10) : null,
            'by' => $d['decided_by'] ?? null, 'at' => self::d(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /**
     * Krok 2 : décision pour un élément (une par trimestre, la dernière compte). « Gotowe — w teczce » : pour un document,
     * responsable et emplacement exigés, le contrôle du jour est enregistré ; pour un élément de l'ERP encore signalé, note
     * obligatoire. « Do uzupełnienia » : date (d'aujourd'hui à un an) et note. « Nie dotyczy » : note.
     */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : gotowe, do uzupełnienia albo nie dotyczy.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $d = $this->data($today, true);
        $it = $d['all'][$key] ?? null;
        if ($it === null) throw new CustomException('Nie ma takiego elementu w teczce.', 'errors', 422);
        if ($decision !== 'ready' && $it['codes'] === []) throw new CustomException('Ten element nie ma sygnału — « Gotowe — w teczce » albo nic.', 'errors', 422);
        $due = null;
        if ($decision === 'action') {
            $due = StaffDeadlinesService::dateIn($in['due_on'] ?? null);
            if ($due === null || $due < $today || $due > date('Y-m-d', strtotime($today . ' +1 year'))) throw new CustomException('Do uzupełnienia : termin od dziś do roku naprzód.', 'errors', 422);
            if (mb_strlen($note) < 3) throw new CustomException('Do uzupełnienia : wpisz, czego brakuje i kto uzupełnia.', 'errors', 422);
        }
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego.', 'errors', 422);
        if ($decision === 'ready' && $it['kind'] === 'doc') {
            $miss = array_values(array_intersect($it['codes'], ['doc_no_owner', 'doc_no_location']));
            if ($miss) throw new CustomException('Najpierw uzupełnij : ' . implode(', ', array_map(fn($c) => self::SIGNALS[$c], $miss)) . ' (pola dokumentu).', 'errors', 422);
            $this->repo->markChecked((int) $it['doc']['id'], $today, $userId);
        } elseif ($decision === 'ready' && $it['codes'] !== [] && mb_strlen($note) < 3) {
            throw new CustomException('Gotowe mimo sygnału (' . implode(', ', array_map(fn($c) => self::SIGNALS[$c], $it['codes'])) . ') : wpisz dlaczego teczka jest kompletna.', 'errors', 422);
        }
        $this->repo->saveDecision($d['quarter_start'], $key, $it['kind'], $decision, $due, $note !== '' ? $note : null, implode(',', $it['codes']), $userId);
        return $this->data($today);
    }

    /** Liste des documents : ajout ou modification (id) — titre, domaine, forme, responsable, emplacement, contrôle tous les N mois. */
    public function saveItem(array $in, ?int $userId, ?string $today = null): array
    {
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0 && $this->repo->item($id) === null) throw new CustomException('Nie ma takiego dokumentu na liście.', 'errors', 404);
        $f = ['title' => trim((string) ($in['title'] ?? '')), 'area' => (string) ($in['area'] ?? 'other'), 'format' => (string) ($in['format'] ?? 'both'),
            'owner' => trim((string) ($in['owner'] ?? '')), 'location' => trim((string) ($in['location'] ?? '')), 'note' => trim((string) ($in['note'] ?? ''))];
        if (mb_strlen($f['title']) < 3 || mb_strlen($f['title']) > 200) throw new CustomException('Dokument : nazwa od 3 do 200 znaków.', 'errors', 422);
        if (!isset(self::AREAS[$f['area']]) || !isset(self::FORMATS[$f['format']])) throw new CustomException('Wybierz obszar i formę dokumentu.', 'errors', 422);
        if (mb_strlen($f['owner']) > 120 || mb_strlen($f['location']) > 255 || mb_strlen($f['note']) > 255) throw new CustomException('Odpowiedzialny : najwyżej 120 znaków, miejsce i notatka : 255.', 'errors', 422);
        $months = trim((string) ($in['check_months'] ?? '3'));
        if (!ctype_digit($months) || (int) $months < 1 || (int) $months > 36) throw new CustomException('Sprawdzać co : od 1 do 36 miesięcy.', 'errors', 422);
        $f['check_months'] = (int) $months;
        foreach (['owner', 'location', 'note'] as $k) $f[$k] = $f[$k] !== '' ? $f[$k] : null;
        if ($id > 0) $this->repo->updateItem($id, $f, $userId);
        else $this->repo->addItem($f, $userId);
        return $this->data($today ?? date('Y-m-d'));
    }

    /** Document retiré de la liste (désactivé). */
    public function removeItem(array $in, ?int $userId, ?string $today = null): array
    {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0 || $this->repo->item($id) === null) throw new CustomException('Nie ma takiego dokumentu na liście.', 'errors', 404);
        $this->repo->removeItem($id, $userId);
        return $this->data($today ?? date('Y-m-d'));
    }

    /** Krok 3 : « Zakończ » — chaque élément signalé a sa décision ; la tâche du trimestre se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'elementu', 'elementów', 'elementów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /**
     * « Teczka » imprimable (TCPDFService, style des documents de l'ERP) : stan w ERP, dokumenty (forme, responsable, où,
     * dernier contrôle, décision), à compléter avec les termes, signatures.
     * @return array{filename:string, content:string}
     */
    public function pdf(?string $today = null, ?string $preparedBy = null): array
    {
        $d = $this->data($today);
        $q = $d['quarter_label'];
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle('Teczka do audytu i kontroli — ' . $q);
        $pdf->brandPage('Teczka do audytu i kontroli', ['ref' => $q]);
        $pdf->brandHeader('Teczka do audytu i kontroli', array_values(array_filter([['Kwartał', $q], ['Stan na', self::d($d['today'])],
            $preparedBy ? ['Przygotował(a)', $preparedBy] : null, $d['done'] ? ['Zatwierdzona', $d['done']['at']] : null])), 'Dokumentacja na potrzeby audytów, Sanepidu i organów kontrolnych');
        $pdf->brandText($d['summary'] . '.', ['size' => 9]);
        $dec = fn(array $i) => $i['decision'] ? $i['decision']['label'] . ($i['decision']['due_on'] ? ' do ' . self::d($i['decision']['due_on']) : '') . ($i['decision']['note'] ? ' — ' . $i['decision']['note'] : '')
            : ($i['codes'] ? 'bez decyzji' : 'w porządku');
        $pdf->brandSection('Stan w ERP', 'Asystenci kontroli w użyciu i otwarte sprawy');
        $pdf->brandTable([['label' => 'Kontrola', 'w' => 52], ['label' => 'Stan', 'w' => 70], ['label' => 'Decyzja / notatka', 'w' => 64]],
            array_map(fn($i) => [$i['title'] . ($i['sub'] ? ' (' . $i['sub'] . ')' : ''), $i['state'], $dec($i)], $d['checks']), ['font' => 8]);
        if ($d['unused']) $pdf->brandNote('Asystenci bez zadania stanowiska (nieużywani) : ' . implode(', ', $d['unused']) . '.');
        $pdf->brandSection('Dokumenty do teczki', 'Papier i elektronicznie — kto odpowiada, gdzie leży, kiedy sprawdzone');
        $pdf->brandTable([['label' => 'Dokument', 'w' => 50], ['label' => 'Forma', 'w' => 20], ['label' => 'Odpowiedzialny', 'w' => 26], ['label' => 'Gdzie', 'w' => 36],
            ['label' => 'Sprawdzony', 'w' => 22], ['label' => 'Decyzja', 'w' => 32]],
            $d['documents'] ? array_map(fn($i) => [$i['title'] . ' (' . self::AREAS[$i['doc']['area']] . ')', self::FORMATS[$i['doc']['format']], (string) ($i['doc']['owner'] ?? '—'),
                (string) ($i['doc']['location'] ?? '—'), $i['doc']['last_checked'] ? self::d($i['doc']['last_checked']) : '—', $dec($i)], $d['documents'])
                : [['Lista dokumentów jest pusta.', '', '', '', '', '']], ['font' => 7.5]);
        $todo = array_values(array_filter(array_merge($d['checks'], $d['documents']), fn($i) => ($i['decision']['code'] ?? null) === 'action'));
        if ($todo) {
            $pdf->brandSection('Do uzupełnienia');
            $pdf->brandTable([['label' => 'Element', 'w' => 70], ['label' => 'Termin', 'w' => 24], ['label' => 'Co i kto', 'w' => 92]],
                array_map(fn($i) => [$i['title'], self::d($i['decision']['due_on']), (string) $i['decision']['note']], $todo), ['font' => 8]);
        }
        $pdf->brandSignatures(['Przygotował(a)', 'Zatwierdził(a)']);
        return ['filename' => 'TECZKA_AUDYT_' . substr($d['quarter_start'], 0, 4) . '-Q' . (intdiv((int) substr($d['quarter_start'], 5, 2) - 1, 3) + 1) . '.pdf', 'content' => $pdf->Output('', 'S')];
    }

    /** « IV kwartał 2026 : asystenci 6 w rytmie / 8 · otwarte sprawy w 2 z 5 · dokumenty 7 gotowe / 9 · decyzje : 3 gotowe · 1 do uzupełnienia » */
    public static function summary(string $quarter, array $t): string
    {
        $s = $quarter . ' : asystenci ' . $t['wizards_ok'] . ' w rytmie / ' . $t['wizards'] . ' · otwarte sprawy w ' . $t['erp_open'] . ' z 5 · dokumenty ' . $t['docs_ok'] . ' gotowe / ' . $t['docs'];
        $dec = array_filter([$t['ready'] ? $t['ready'] . ' gotowe' : null, $t['action'] ? $t['action'] . ' do uzupełnienia' : null, $t['not_applicable'] ? $t['not_applicable'] . ' nie dotyczy' : null]);
        return $dec ? $s . ' · decyzje : ' . implode(' · ', $dec) : $s;
    }

    private static function sig(string $code, ?string $detail = null): array
    {
        return ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail];
    }

    private static function d(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : date('d.m.Y', $t);
    }

    private static function hm(string $ts): string
    {
        return strlen($ts) >= 16 ? (int) substr($ts, 11, 2) . substr($ts, 13, 3) : '';
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
