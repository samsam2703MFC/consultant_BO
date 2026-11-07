<?php
declare(strict_types=1);

namespace App\Services\Workshop\Finance;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\FinanceRepository;
use App\Repositories\Workshop\InvoiceRepository;
use App\Services\Workshop\Referentiel\AuditTrail;
use App\Services\Workshop\Supply\InvoiceService;

/**
 * Finanse › Koszty usług miesiąca (makieta 3) : faktury usług (KSeF, pliki, wpisane ręcznie) pogrupowane po miesiącu kosztu
 * i kategorii kalkulatora, porównanie z poprzednim miesiącem — dostawca, który był w poprzednim miesiącu, a nie ma jeszcze
 * faktury, to « brakuje » (dodaj koszt albo « nie w tym miesiącu »). Zamknięcie miesiąca (miesiąc zakończony, wszystko
 * zatwierdzone, nic nie brakuje) zapisuje sumy w kalkulatorze kosztów : koszty stałe (wiersz na dostawcę), media (kwota
 * medium), logistyka (wiersz na typ) — ręczne wiersze tej kategorii są zastąpione i wracają przy ponownym otwarciu.
 * Zamknięty miesiąc kalkulatora (LOCKED) blokuje zamknięcie i otwarcie.
 */
final class ServiceCostService
{
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    private const CHIPS = 6;
    public const MAX_FILE = 5 * 1024 * 1024;
    private const TARGETS = ['fixed' => 'fixed', 'media' => 'media', 'log' => 'logistics'];

    public function __construct(
        private FinanceRepository $repo,
        private InvoiceRepository $invoices,
        private CostCategories $categories,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public static function label(string $ym): string
    {
        return self::MONTHS[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
    }

    /** Domyślnie poprzedni miesiąc (koszty miesiąca przychodzą na początku następnego). */
    public static function defaultMonth(?string $today = null): string
    {
        return date('Y-m', strtotime(($today ?? date('Y-m-d')) . ' first day of last month'));
    }

    /** Klucz dostawcy : NIP, inaczej nazwa (oczekiwane koszty, « nie w tym miesiącu »). */
    public static function sellerKey(?string $nip, ?string $name): string
    {
        $nip = preg_replace('/\D/', '', (string) $nip);
        return $nip !== '' ? 'nip:' . $nip : 'name:' . substr(sha1(CostCategories::fold(trim((string) $name))), 0, 16);
    }

    // --- Widok miesiąca ---------------------------------------------------------------------------------------------

    public function month(string $ym, ApiContext $context): array
    {
        $this->parse($ym, 'month');
        $cats = $this->categories->all();
        $prev = self::shift($ym, -1);
        $marks = [];
        foreach ($this->repo->marks($ym) as $m) $marks[$m['cost_category'] . '|' . $m['seller_key']] = true;
        $view = $this->expectations($ym, $this->repo->serviceTotals([$ym, $prev]), $marks);
        $rows = [];
        foreach ($this->repo->serviceInvoices($ym) as $i) {
            $rows[$i['cost_category'] ?? ''][] = $this->present($i);
        }
        $codes = array_values(array_unique(array_merge(array_keys($rows), array_keys($view['prev']), array_keys($view['missing']), array_keys($view['marked']))));
        $order = array_flip(array_keys($cats));
        usort($codes, fn($a, $b) => [$order[$a] ?? 999, $a] <=> [$order[$b] ?? 999, $b]);
        $closed = $this->repo->closed($ym);
        [$y, $m] = [(int) substr($ym, 0, 4), (int) substr($ym, 5, 2)];
        $calc = $this->repo->costMonth($y, $m);
        $pushed = [];
        foreach ($closed ? $this->repo->pushes($ym) : [] as $p) $pushed[$p['cost_category']] = ($pushed[$p['cost_category']] ?? 0) + (float) $p['amount'];
        $categories = [];
        foreach ($codes as $code) {
            $list = $rows[$code] ?? [];
            $c = $cats[$code] ?? null;
            $total = round(array_sum(array_map(fn($r) => (float) ($r['cost_amount'] ?? 0), $list)), 2);
            $prevTotal = round($view['prev'][$code] ?? 0.0, 2);
            $open = count(array_filter($list, fn($r) => $r['status'] === 'open'));
            $missing = $view['missing'][$code] ?? [];
            $marked = $view['marked'][$code] ?? [];
            $state = $code === '' || $c === null ? 'open' : ($open > 0 ? 'open' : ($missing ? 'missing' : ($list ? 'done' : ($marked ? 'none' : 'empty'))));
            $categories[] = ['code' => $code !== '' ? $code : null, 'label' => $c['label'] ?? ($code !== '' ? $code : 'Bez kategorii'), 'group' => $c['group'] ?? null, 'group_label' => $c['group_label'] ?? null,
                'invoices' => $list, 'count' => count($list), 'open' => $open, 'total' => $total, 'prev_total' => $prevTotal,
                'diff_pct' => $prevTotal > 0 && $list ? round(($total - $prevTotal) / $prevTotal * 100, 1) : null,
                'missing' => $missing, 'marked' => $marked, 'state' => $state,
                'calc' => $c !== null && $calc ? $this->calcAmount($c, (int) $calc['id'], $closed ? $this->pushedRows($ym, $code) : []) : null,
                'pushed' => $pushed[$code] ?? null];
        }
        $done = count(array_filter($categories, fn($c) => in_array($c['state'], ['done', 'none'], true)));
        $relevant = count(array_filter($categories, fn($c) => $c['state'] !== 'empty'));
        $openCount = array_sum(array_column($categories, 'open'));
        $missingCount = array_sum(array_map(fn($c) => count($c['missing']), $categories));
        $noAmount = count(array_filter(array_merge(...array_column($categories, 'invoices') ?: [[]]), fn($r) => $r['cost_amount'] === null));
        $over = $ym < date('Y-m');
        // Mois sans aucune facture de service ni « pas ce mois-ci » : rien à clôturer (pas de mois vide dans le calculateur).
        $empty = !array_filter($categories, fn($c) => $c['count'] > 0 || $c['marked']);
        $blockers = array_values(array_filter([$empty ? 'empty' : null, $openCount ? 'open' : null, $missingCount ? 'missing' : null, $noAmount ? 'no_amount' : null, !$over ? 'not_over' : null,
            $calc && $calc['status'] === 'LOCKED' ? 'cost_month_locked' : null]));
        return [
            'month' => $ym, 'label' => self::label($ym), 'prev_month' => $prev, 'prev_label' => self::label($prev), 'next_month' => $ym < date('Y-m') ? self::shift($ym, 1) : null,
            'closed' => $closed ? ['at' => $closed['closed_at'], 'by' => $closed['closed_by_name'], 'total' => (float) $closed['total']] : null,
            'cost_month' => $calc ? ['id' => (int) $calc['id'], 'status' => $calc['status']] : null,
            'categories' => $categories,
            'progress' => ['done' => $done, 'total' => $relevant],
            'totals' => ['net' => round(array_sum(array_column($categories, 'total')), 2), 'prev' => round(array_sum(array_column($categories, 'prev_total')), 2),
                'open' => $openCount, 'missing' => $missingCount, 'no_amount' => $noAmount],
            'can_close' => !$closed && !$blockers, 'blockers' => $blockers,
            'months' => $this->chips($ym),
            'category_list' => CostCategories::present($cats),
            'without_month' => $this->repo->serviceWithoutMonth(),
            'can_write' => $context->can('prod.finance.write'),
        ];
    }

    /**
     * Oczekiwane koszty : dostawca z poprzedniego miesiąca (kategoria + klucz) bez faktury w miesiącu i bez « nie w tym
     * miesiącu » ; sumy poprzedniego miesiąca na kategorię.
     */
    private function expectations(string $ym, array $totals, array $marks): array
    {
        $prev = self::shift($ym, -1);
        $cur = [];
        $prevSum = [];
        $prevSellers = [];
        foreach ($totals as $t) {
            $code = (string) $t['cost_category'];
            $key = self::sellerKey($t['seller_nip'], $t['seller_name']);
            if ($t['ym'] === $ym) $cur[$code][$key] = true;
            else {
                $prevSum[$code] = ($prevSum[$code] ?? 0) + (float) $t['amount'];
                $prevSellers[$code][$key] = ['seller_key' => $key, 'seller_name' => $t['seller_name'], 'seller_nip' => $t['seller_nip'],
                    'prev_amount' => round(($prevSellers[$code][$key]['prev_amount'] ?? 0) + (float) $t['amount'], 2)];
            }
        }
        $missing = [];
        $marked = [];
        foreach ($prevSellers as $code => $sellers) {
            foreach ($sellers as $key => $s) {
                if (isset($cur[$code][$key]) || $code === '') continue;
                if (isset($marks[$code . '|' . $key])) $marked[$code][] = $s;
                else $missing[$code][] = $s;
            }
        }
        return ['prev' => $prevSum, 'missing' => $missing, 'marked' => $marked, 'prev_month' => $prev];
    }

    /** Ostatnie miesiące : zamknięty, do sprawdzenia, brakuje, suma (chipy nad tabelą). */
    private function chips(string $ym): array
    {
        $last = max($ym, self::defaultMonth());
        $yms = [];
        for ($k = self::CHIPS - 1; $k >= 0; $k--) $yms[] = self::shift($last, -$k);
        if (!in_array($ym, $yms, true)) $yms[] = $ym;
        $all = $this->repo->serviceTotals(array_merge([self::shift($yms[0], -1)], $yms));
        $closed = $this->repo->closedMonths($yms);
        $out = [];
        foreach ($yms as $m) {
            $marks = [];
            foreach ($this->repo->marks($m) as $x) $marks[$x['cost_category'] . '|' . $x['seller_key']] = true;
            $mine = array_values(array_filter($all, fn($t) => $t['ym'] === $m || $t['ym'] === self::shift($m, -1)));
            $e = $this->expectations($m, $mine, $marks);
            $cur = array_values(array_filter($all, fn($t) => $t['ym'] === $m));
            $out[] = ['month' => $m, 'label' => self::label($m), 'closed' => isset($closed[$m]), 'open' => (int) array_sum(array_column($cur, 'open')),
                'missing' => array_sum(array_map('count', $e['missing'])), 'count' => (int) array_sum(array_column($cur, 'n')),
                'total' => round(array_sum(array_map(fn($t) => (float) $t['amount'], $cur)), 2), 'current' => $m === $ym, 'over' => $m < date('Y-m')];
        }
        return $out;
    }

    private function present(array $i): array
    {
        return ['id' => (int) $i['id'], 'source' => $i['source'], 'number' => $i['number'], 'issue_date' => $i['issue_date'], 'seller_name' => $i['seller_name'], 'seller_nip' => $i['seller_nip'],
            'seller_key' => self::sellerKey($i['seller_nip'], $i['seller_name']), 'supplier' => $i['supplier_name'] !== null ? trim((string) $i['supplier_name']) : null,
            'currency' => $i['currency'], 'total_net' => $i['total_net'] !== null ? (float) $i['total_net'] : null, 'total_gross' => $i['total_gross'] !== null ? (float) $i['total_gross'] : null,
            'cost_amount' => InvoiceService::costAmount($i), 'cost_amount_set' => $i['cost_amount'] !== null, 'cost_category' => $i['cost_category'], 'service_month' => $i['service_month'],
            'status' => $i['status'], 'by_rule' => $i['id_rule'] !== null, 'note' => $i['note'], 'decided_by' => $i['decided_by_name'], 'decided_at' => $i['decided_at'],
            'file' => $i['filename'] !== null ? ['filename' => $i['filename'], 'mime' => $i['mime']] : null, 'ksef_number' => $i['ksef_number']];
    }

    /** Kwota w kalkulatorze dla kategorii (bez wierszy zapisanych z faktur). */
    private function calcAmount(array $c, int $monthId, array $exclude): array
    {
        if ($c['group'] === 'fixed') {
            $rows = $this->repo->fixedRows($monthId, $c['ref'], $exclude);
            return ['target' => 'fixed', 'manual' => round(array_sum(array_map(fn($r) => (float) $r['amount'], $rows)), 2), 'rows' => count($rows)];
        }
        if ($c['group'] === 'media') {
            $row = $this->repo->mediaRow($monthId, (int) $c['type_id']);
            $mine = $row && in_array((int) $row['id'], $exclude, true);
            return ['target' => 'media', 'manual' => $row && !$mine ? round((float) $row['amount'], 2) : 0.0, 'rows' => $row && !$mine ? 1 : 0];
        }
        $rows = $this->repo->logisticsRows($monthId, (int) $c['type_id'], $exclude);
        return ['target' => 'logistics', 'manual' => round(array_sum(array_map(fn($r) => (float) $r['amount'], $rows)), 2), 'rows' => count($rows)];
    }

    private function pushedRows(string $ym, string $code): array
    {
        return array_values(array_filter(array_map(fn($p) => $p['cost_category'] === $code ? (int) $p['row_id'] : null, $this->repo->pushes($ym))));
    }

    // --- Faktury usług : zmiana, zatwierdzenie, koszt dopisany ręcznie, usunięcie ------------------------------------

    /** Kategoria, miesiąc kosztu, kwota PLN (null = suma netto faktury), notatka ; zatwierdzona wraca do sprawdzenia przy zmianie kwoty / kategorii. */
    public function update(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->service($id);
        $v = (new Validator($body))->only(['cost_category', 'service_month', 'cost_amount', 'note'])
            ->string('cost_category', 40, false)->string('service_month', 7, false, false, '/^\d{4}-(0[1-9]|1[0-2])$/', 'INVALID_MONTH')
            ->decimal('cost_amount', false, 0, 9999999, true)->string('note', 1000, false, true);
        $cats = $this->categories->all();
        if ($v->provided('cost_category') && !$v->hasError('cost_category') && !isset($cats[$v->value('cost_category')])) $v->add('cost_category', 'INVALID_CHOICE', ['allowed' => implode(', ', array_keys($cats))]);
        $v->throwIfInvalid();
        $data = array_intersect_key($v->clean(), array_flip(['cost_category', 'service_month', 'cost_amount', 'note']));
        if (!$data) return $this->invoice($id);
        $this->openMonth($inv['service_month']);
        if (isset($data['service_month'])) $this->openMonth($data['service_month']);
        if (array_intersect_key($data, array_flip(['cost_category', 'service_month', 'cost_amount'])) && $inv['status'] === 'accepted') $data += ['status' => 'open', 'decided_by' => null, 'decided_at' => null];
        $this->tx->execute(function () use ($id, $inv, $data, $context) {
            $this->invoices->update($id, $data);
            $this->audit->record($context, 'update', 'service_invoice', $id, array_intersect_key($inv, $data), $data);
        });
        return $this->invoice($id);
    }

    /** Zatwierdzenie (accepted) albo z powrotem do sprawdzenia (open) ; zatwierdzona ma kategorię i kwotę w PLN. */
    public function decide(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->service($id);
        $v = (new Validator($body))->only(['status'])->choice('status', ['accepted', 'open']);
        $v->throwIfInvalid();
        $status = $v->value('status');
        $this->openMonth($inv['service_month']);
        if ($status === 'accepted') $this->assertComplete($inv);
        $data = ['status' => $status, 'decided_by' => $status === 'accepted' ? $context->userId : null, 'decided_at' => $status === 'accepted' ? date('Y-m-d H:i:s') : null];
        $this->tx->execute(function () use ($id, $inv, $data, $context) {
            $this->invoices->update($id, $data);
            $this->audit->record($context, 'decide', 'service_invoice', $id, ['status' => $inv['status']], $data);
        });
        return $this->invoice($id);
    }

    /** « Zatwierdź wszystkie » miesiąca : faktury do sprawdzenia z kategorią i kwotą PLN (pozostałe zostają). */
    public function acceptAll(string $ym, ApiContext $context): array
    {
        $this->parse($ym, 'month');
        $this->openMonth($ym);
        $cats = $this->categories->all();
        $accepted = [];
        $left = 0;
        $this->tx->execute(function () use ($ym, $cats, $context, &$accepted, &$left) {
            foreach ($this->repo->serviceInvoices($ym) as $i) {
                if ($i['status'] !== 'open') continue;
                if (!isset($cats[(string) $i['cost_category']]) || InvoiceService::costAmount($i) === null) { $left++; continue; }
                $this->invoices->update((int) $i['id'], ['status' => 'accepted', 'decided_by' => $context->userId, 'decided_at' => date('Y-m-d H:i:s')]);
                $accepted[] = (int) $i['id'];
            }
            if ($accepted) $this->audit->record($context, 'accept_all', 'service_month', $ym, null, ['invoices' => $accepted]);
        });
        return ['accepted' => count($accepted), 'left' => $left] + $this->month($ym, $context);
    }

    /**
     * Koszt bez faktury w KSeF (dostawca zagraniczny, paragon, PDF) : sprzedawca, data, kategoria, miesiąc, kwota netto PLN,
     * opcjonalnie plik (PDF / zdjęcie ≤ 5 MB). Wpisany przez Finanse = od razu zatwierdzony.
     */
    public function create(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['seller_name', 'seller_nip', 'number', 'issue_date', 'cost_category', 'service_month', 'total_net', 'total_gross', 'note', 'filename', 'content_base64'])
            ->string('seller_name', 255)->string('seller_nip', 20, false, true, '/^[A-Z]{0,2}[\d\- ]{6,16}$/', 'INVALID_NIP')->string('number', 80, false, true)
            ->date('issue_date', true, false)->string('cost_category', 40)->string('service_month', 7, false, true, '/^\d{4}-(0[1-9]|1[0-2])$/', 'INVALID_MONTH')
            ->decimal('total_net', true, 0, 9999999, false, true)->decimal('total_gross', false, 0, 9999999, true)->string('note', 1000, false, true)
            ->string('filename', 255, false, true)->string('content_base64', (int) (self::MAX_FILE * 1.4), false, true);
        $cats = $this->categories->all();
        if (!$v->hasError('cost_category') && $v->provided('cost_category') && !isset($cats[$v->value('cost_category')])) $v->add('cost_category', 'INVALID_CHOICE', ['allowed' => implode(', ', array_keys($cats))]);
        $file = null;
        if ($v->value('content_base64') !== null) {
            $content = base64_decode((string) $v->value('content_base64'), true);
            $mime = $content === false ? null : (str_starts_with($content, '%PDF') ? 'application/pdf' : (str_starts_with($content, "\xFF\xD8\xFF") ? 'image/jpeg'
                : (str_starts_with($content, "\x89PNG") ? 'image/png' : null)));
            if ($content === false || $content === '' || $mime === null) $v->add('content_base64', 'INVALID_FILE');
            elseif (strlen($content) > self::MAX_FILE) $v->add('content_base64', 'FILE_TOO_LARGE', ['max_kb' => self::MAX_FILE / 1024]);
            else $file = ['filename' => basename((string) ($v->value('filename') ?? 'koszt.' . ($mime === 'application/pdf' ? 'pdf' : 'jpg'))), 'mime' => $mime, 'content' => $content];
        }
        $v->throwIfInvalid();
        $c = $v->clean();
        $nip = isset($c['seller_nip']) && $c['seller_nip'] !== null ? preg_replace('/[^\dA-Z]/', '', strtoupper($c['seller_nip'])) : null;
        $month = $c['service_month'] ?? substr($c['issue_date'], 0, 7);
        $this->openMonth($month);
        if ($nip !== null && ($c['number'] ?? null) !== null && ($dup = $this->invoices->findDuplicate(null, $nip, $c['number'])) !== null) throw new ApiException('INVOICE_EXISTS', 409, [], ['id' => $dup]);
        $id = $this->tx->execute(function () use ($c, $nip, $month, $file, $context) {
            $id = $this->invoices->insert(['source' => 'manual', 'kind' => 'service', 'cost_category' => $c['cost_category'], 'service_month' => $month,
                'seller_nip' => $nip, 'seller_name' => $c['seller_name'], 'number' => $c['number'] ?? null, 'issue_date' => $c['issue_date'], 'currency' => 'PLN',
                'total_net' => round((float) $c['total_net'], 2), 'total_gross' => isset($c['total_gross']) ? round((float) $c['total_gross'], 2) : null, 'note' => $c['note'] ?? null,
                'status' => 'accepted', 'decided_by' => $context->userId, 'decided_at' => date('Y-m-d H:i:s'), 'created_by' => $context->userId]);
            if ($file) $this->invoices->saveFile($id, $file['filename'], $file['mime'], $file['content'], null);
            $this->audit->record($context, 'create', 'service_invoice', $id, null, ['seller_name' => $c['seller_name'], 'cost_category' => $c['cost_category'], 'service_month' => $month, 'total_net' => $c['total_net'], 'file' => $file !== null]);
            return $id;
        });
        return $this->invoice($id);
    }

    /** Usunięcie kosztu wpisanego ręcznie (faktura z KSeF / pliku : « Odrzuć » w Zaopatrzeniu). */
    public function delete(int $id, ApiContext $context): array
    {
        $inv = $this->service($id);
        if ($inv['source'] !== 'manual') throw new ApiException('SERVICE_NOT_MANUAL', 409);
        $this->openMonth($inv['service_month']);
        $this->tx->execute(function () use ($id, $inv, $context) {
            $this->invoices->delete($id);
            $this->audit->record($context, 'delete', 'service_invoice', $id, ['seller_name' => $inv['seller_name'], 'total_net' => $inv['total_net'], 'service_month' => $inv['service_month']], null);
        });
        return ['deleted' => true, 'id' => $id];
    }

    /** « Nie w tym miesiącu » (none = true) albo cofnięcie — oczekiwany dostawca kategorii. */
    public function mark(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['month', 'cost_category', 'seller_key', 'none'])->string('month', 7, true, false, '/^\d{4}-(0[1-9]|1[0-2])$/', 'INVALID_MONTH')
            ->string('cost_category', 40)->string('seller_key', 40, true, false, '/^(nip|name):[\w]{1,34}$/', 'INVALID_CHOICE')->boolean('none', true);
        $v->throwIfInvalid();
        $c = $v->clean();
        $this->openMonth($c['month']);
        $this->tx->execute(function () use ($c, $context) {
            if ($c['none']) $this->repo->setMark($c['month'], $c['cost_category'], $c['seller_key'], $context->userId);
            else $this->repo->deleteMark($c['month'], $c['cost_category'], $c['seller_key']);
            $this->audit->record($context, $c['none'] ? 'mark_none' : 'unmark_none', 'service_month', $c['month'], null, ['cost_category' => $c['cost_category'], 'seller_key' => $c['seller_key']]);
        });
        return $this->month($c['month'], $context);
    }

    // --- Zamknięcie miesiąca → kalkulator kosztów ------------------------------------------------------------------

    public function close(string $ym, ApiContext $context): array
    {
        $this->parse($ym, 'month');
        $view = $this->month($ym, $context);
        if ($view['closed']) throw new ApiException('SERVICE_MONTH_CLOSED', 409, [], ['month' => $ym]);
        if ($view['blockers']) {
            if (in_array('cost_month_locked', $view['blockers'], true)) throw new ApiException('COST_MONTH_LOCKED', 409, [], ['month' => $ym]);
            if ($view['blockers'] === ['not_over']) throw new ApiException('SERVICE_MONTH_NOT_OVER', 409, [], ['month' => $ym]);
            if (in_array('empty', $view['blockers'], true)) throw new ApiException('SERVICE_MONTH_EMPTY', 409, [], ['month' => $ym]);
            throw new ApiException('SERVICE_MONTH_INCOMPLETE', 409, [], ['open' => $view['totals']['open'], 'missing' => $view['totals']['missing'], 'no_amount' => $view['totals']['no_amount']]);
        }
        $cats = $this->categories->all();
        [$y, $m] = [(int) substr($ym, 0, 4), (int) substr($ym, 5, 2)];
        $pushed = [];
        $this->tx->execute(function () use ($ym, $y, $m, $cats, $view, $context, &$pushed) {
            $calc = $this->repo->costMonth($y, $m);
            $monthId = $calc ? (int) $calc['id'] : $this->repo->createCostMonth($y, $m);
            if ($calc && $calc['status'] === 'LOCKED') throw new ApiException('COST_MONTH_LOCKED', 409, [], ['month' => $ym]);
            $total = 0.0;
            foreach ($view['categories'] as $row) {
                $c = $row['code'] !== null ? ($cats[$row['code']] ?? null) : null;
                if ($c === null || !$row['invoices'] || $row['total'] <= 0) continue;
                $this->push($ym, $monthId, $c, $row['invoices']);
                $pushed[] = ['code' => $c['code'], 'label' => $c['label'], 'amount' => $row['total'], 'replaced' => $row['calc']['manual'] ?? 0.0];
                $total += $row['total'];
            }
            $this->repo->close($ym, $total, $context->userId);
            $this->audit->record($context, 'close', 'service_month', $ym, null, ['cost_month_id' => $monthId, 'total' => round($total, 2), 'categories' => $pushed]);
        });
        return ['pushed' => $pushed] + $this->month($ym, $context);
    }

    /** Sumy kategorii do kalkulatora : ręczne wiersze kategorii zastąpione (zapamiętane do przywrócenia). */
    private function push(string $ym, int $monthId, array $c, array $invoices): void
    {
        $bySeller = [];
        foreach ($invoices as $i) {
            $k = $i['seller_key'];
            $bySeller[$k]['name'] ??= trim((string) ($i['supplier'] ?: $i['seller_name'])) ?: '—';
            $bySeller[$k]['amount'] = ($bySeller[$k]['amount'] ?? 0) + (float) $i['cost_amount'];
            $bySeller[$k]['n'] = ($bySeller[$k]['n'] ?? 0) + 1;
        }
        $total = round(array_sum(array_column($bySeller, 'amount')), 2);
        $names = implode(', ', array_map(fn($s) => $s['name'], $bySeller));
        $note = mb_substr('Faktury : ' . $names, 0, 255);
        if ($c['group'] === 'fixed') {
            $old = $this->repo->fixedRows($monthId, $c['ref']);
            foreach ($old as $r) $this->repo->deleteFixed((int) $r['id']);
            $first = true;
            foreach ($bySeller as $s) {
                $rowId = $this->repo->insertFixed($monthId, 'Faktury : ' . $s['name'] . ($s['n'] > 1 ? ' (' . $s['n'] . ')' : ''), $s['amount'], $c['ref'], $ym);
                $this->repo->addPush($ym, $c['code'], 'fixed', $rowId, $s['amount'], $first ? $old : null);
                $first = false;
            }
        } elseif ($c['group'] === 'media') {
            $row = $this->repo->mediaRow($monthId, (int) $c['type_id']);
            if ($row) $this->repo->updateMedia((int) $row['id'], $total, $note);
            $this->repo->addPush($ym, $c['code'], 'media', $row ? (int) $row['id'] : $this->repo->insertMedia($monthId, (int) $c['type_id'], $total, $note), $total, $row);
        } else {
            $old = $this->repo->logisticsRows($monthId, (int) $c['type_id']);
            foreach ($old as $r) $this->repo->deleteLogistics((int) $r['id']);
            $this->repo->addPush($ym, $c['code'], 'logistics', $this->repo->insertLogistics($monthId, (int) $c['type_id'], $total, $note), $total, $old);
        }
    }

    /** Otwarcie : wiersze z faktur usunięte z kalkulatora, ręczne wiersze przywrócone. */
    public function reopen(string $ym, ApiContext $context): array
    {
        $this->parse($ym, 'month');
        $closed = $this->repo->closed($ym) ?? throw new ApiException('SERVICE_MONTH_OPEN', 409, [], ['month' => $ym]);
        [$y, $m] = [(int) substr($ym, 0, 4), (int) substr($ym, 5, 2)];
        $calc = $this->repo->costMonth($y, $m);
        if ($calc && $calc['status'] === 'LOCKED') throw new ApiException('COST_MONTH_LOCKED', 409, [], ['month' => $ym]);
        $cats = $this->categories->all();
        $this->tx->execute(function () use ($ym, $calc, $cats, $closed, $context) {
            foreach ($this->repo->pushes($ym) as $p) {
                $old = $p['replaced'] !== null ? json_decode((string) $p['replaced'], true) : null;
                $monthId = $calc ? (int) $calc['id'] : null;
                if ($p['target'] === 'fixed') {
                    if ($p['row_id'] !== null) $this->repo->deleteFixed((int) $p['row_id']);
                    foreach ($monthId !== null && is_array($old) ? $old : [] as $r) $this->repo->insertFixed($monthId, (string) $r['name'], (float) $r['amount'], (string) $r['category'], (string) ($r['service_month'] ?? $ym));
                } elseif ($p['target'] === 'media') {
                    if (is_array($old) && $old) $this->repo->updateMedia((int) $p['row_id'], (float) $old['amount'], $old['note'] ?? null);
                    elseif ($p['row_id'] !== null) $this->repo->deleteMedia((int) $p['row_id']);
                } else {
                    if ($p['row_id'] !== null) $this->repo->deleteLogistics((int) $p['row_id']);
                    $type = $cats[$p['cost_category']]['type_id'] ?? null;
                    foreach ($monthId !== null && is_array($old) ? $old : [] as $r) $this->repo->insertLogistics($monthId, (int) ($r['logistics_type_id'] ?? $type), (float) $r['amount'], $r['note'] ?? null);
                }
            }
            $this->repo->reopen($ym);
            $this->audit->record($context, 'reopen', 'service_month', $ym, ['total' => (float) $closed['total'], 'closed_at' => $closed['closed_at']], null);
        });
        return $this->month($ym, $context);
    }

    // --- Pomocnicze ---------------------------------------------------------------------------------------------------

    public function invoice(int $id): array
    {
        $i = $this->service($id);
        $rows = array_values(array_filter($this->repo->serviceInvoices((string) $i['service_month']), fn($r) => (int) $r['id'] === $id));
        return $rows ? $this->present($rows[0]) : ['id' => $id, 'status' => $i['status']];
    }

    private function service(int $id): array
    {
        $i = $this->invoices->invoice($id);
        if ($i === null || $i['kind'] !== 'service' || $i['status'] === 'rejected') throw ApiException::notFound('service_invoice', $id);
        return $i;
    }

    private function assertComplete(array $i): void
    {
        $cats = $this->categories->all();
        if (!isset($cats[(string) $i['cost_category']])) throw ApiException::validation([['field' => 'cost_category', 'code' => 'REQUIRED', 'params' => []]]);
        if ($i['service_month'] === null) throw ApiException::validation([['field' => 'service_month', 'code' => 'REQUIRED', 'params' => []]]);
        if (InvoiceService::costAmount($i) === null) throw ApiException::validation([['field' => 'cost_amount', 'code' => 'REQUIRED', 'params' => []]]);
    }

    private function openMonth(?string $ym): void
    {
        if ($ym !== null && $this->repo->closed($ym)) throw new ApiException('SERVICE_MONTH_CLOSED', 409, [], ['month' => $ym]);
    }

    private function parse(string $ym, string $field): void
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym) || (int) substr($ym, 0, 4) < 2000 || $ym > date('Y-m')) {
            throw ApiException::validation([['field' => $field, 'code' => 'INVALID_MONTH', 'params' => []]]);
        }
    }

    private static function shift(string $ym, int $months): string
    {
        return date('Y-m', strtotime($ym . '-01 ' . ($months >= 0 ? '+' : '') . $months . ' month'));
    }
}
