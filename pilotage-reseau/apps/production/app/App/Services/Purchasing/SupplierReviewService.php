<?php
declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Exceptions\CustomException;
use App\Repositories\Purchasing\SupplierReviewRepository;

/**
 * Kreator « Ocena dostawców i zapytanie ofertowe » [230] (Kierownik produkcji, co kwartał ; dane poprzedniego kwartału) :
 *   1. Oceny : zakupy, dostawy, terminowość, niezgodności, zmiana cen ; ocena = terminowość 40 % · niezgodności 30 % ·
 *      ceny 30 % (składniki bez danych pomijane, wagi przeliczone) ; A ≥ 85, B ≥ 65, C poniżej ; decyzja z oceny
 *      (A zostaje, B obserwuj, C zmień dostawcę) — do poprawienia, z notatką ;
 *   2. Surowce do zapytania : cena +5 % w kwartale, bieżący dostawca z decyzją « zmień » albo kluczowy surowiec
 *      (≥ 5 % zakupów kwartału) z mniej niż 3 dostawcami ;
 *   3. Zapytanie ofertowe : szkic w Zapytaniach ofertowych (obecni dostawcy + dostawcy tych samych kategorii z e-mailem),
 *      wysyłka po sprawdzeniu w Zapytaniach.
 * « Zatwierdź » zapisuje oceny i wynik (n_wizard_done) — zadanie zalicza się samo (TaskWizards : suppliers_reviewed).
 */
final class SupplierReviewService
{
    public const CODE = 'suppliers_reviewed';
    public const DECISIONS = ['keep' => 'Zostaje', 'watch' => 'Obserwuj', 'change' => 'Zmień dostawcę'];
    public const WEIGHTS = ['on_time' => 40, 'nc' => 30, 'price' => 30];
    public const PRICE_ALERT = 5.0;
    public const KEY_SHARE = 5.0;
    private const MAX_MATERIALS = 12;

    public function __construct(private SupplierReviewRepository $repo, private TenderService $tenders)
    {
    }

    /** Oceniany kwartał : ?q=2026-Q3 (ostatnie 8) albo poprzedni względem dnia. @return array{key:string, from:string, to:string, label:string} */
    public static function quarter(?string $q, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $cur = (int) substr($today, 0, 4) * 4 + intdiv((int) substr($today, 5, 2) - 1, 3);
        $idx = $cur - 1;
        if ($q !== null && preg_match('/^(\d{4})-Q([1-4])$/', $q, $m)) {
            $want = (int) $m[1] * 4 + (int) $m[2] - 1;
            if ($want < $cur && $want >= $cur - 8) $idx = $want;
        }
        $y = intdiv($idx, 4);
        $n = $idx % 4 + 1;
        $from = sprintf('%04d-%02d-01', $y, ($n - 1) * 3 + 1);
        $to = date('Y-m-d', strtotime($from . ' +3 months'));
        return ['key' => sprintf('%04d-Q%d', $y, $n), 'from' => $from, 'to' => $to, 'label' => ['I', 'II', 'III', 'IV'][$n - 1] . ' kwartał ' . $y];
    }

    /** Ocena 0–100 i klasa ze składników (null = brak danych). @return array{score:?float, grade:?string} */
    public static function score(?float $onTimePct, ?float $ncScore, ?float $priceChangePct): array
    {
        $parts = [];
        if ($onTimePct !== null) $parts['on_time'] = max(0.0, min(1.0, $onTimePct / 100));
        if ($ncScore !== null) $parts['nc'] = max(0.0, min(1.0, $ncScore));
        if ($priceChangePct !== null) $parts['price'] = max(0.0, min(1.0, 1 - max(0.0, $priceChangePct) / 10));
        if (!$parts) return ['score' => null, 'grade' => null];
        $w = 0;
        $s = 0.0;
        foreach ($parts as $k => $v) { $w += self::WEIGHTS[$k]; $s += self::WEIGHTS[$k] * $v; }
        $score = round($s / $w * 100, 1);
        return ['score' => $score, 'grade' => $score >= 85 ? 'A' : ($score >= 65 ? 'B' : 'C')];
    }

    public static function defaultDecision(?string $grade): string
    {
        return match ($grade) { 'C' => 'change', 'B' => 'watch', default => 'keep' };
    }

    /** Krok 1–3 : oceny kwartału, propozycja surowców, kandydaci do zapytania. */
    public function data(?string $q, ?string $today = null): array
    {
        $Q = self::quarter($q, $today);
        [$from, $to] = [$Q['from'], $Q['to']];
        $rec = $this->repo->receipts($from, $to);
        $inv = $this->repo->invoices($from, $to);
        $ot = $this->repo->onTime($from, $to);
        $nc = $this->repo->ncs($from, $to);
        $start = $this->repo->pricesBefore($from . ' 00:00:00');
        $end = $this->repo->pricesBefore($to . ' 00:00:00');
        $mv = $this->repo->materialValues($from, $to);
        $suppliers = $this->repo->suppliers();
        $materials = $this->repo->materials();
        $msup = $this->repo->materialSuppliers();
        $saved = $this->repo->reviews($Q['key']);
        $ids = array_unique(array_merge(array_keys($rec), array_keys($inv), array_keys($ot), array_keys($nc), $this->repo->pricedIn($from, $to)));

        // Zmiana ceny dostawca × surowiec : koniec kwartału vs początek (oba znane).
        $changes = [];
        foreach ($end as $key => $p1) {
            if (!isset($start[$key]) || $start[$key] <= 0) continue;
            [$sid, $mid] = array_map('intval', explode(':', $key));
            $changes[$sid][$mid] = ($p1 - $start[$key]) / $start[$key] * 100;
        }
        $bySupplierMaterials = [];
        foreach ($msup as $mid => $sids) foreach ($sids as $sid) $bySupplierMaterials[$sid][] = $mid;

        $rows = [];
        foreach ($ids as $sid) {
            if (!isset($suppliers[$sid])) continue;
            $value = max($rec[$sid]['value'] ?? 0.0, $inv[$sid]['value'] ?? 0.0);
            $deliveries = max($rec[$sid]['deliveries'] ?? 0, $inv[$sid]['count'] ?? 0, $ot[$sid]['orders'] ?? 0);
            $onTime = isset($ot[$sid]) && $ot[$sid]['orders'] > 0 ? round($ot[$sid]['on_time'] / $ot[$sid]['orders'] * 100, 1) : null;
            $ncN = $nc[$sid] ?? 0;
            $ncScore = $deliveries > 0 ? max(0.0, 1 - 2 * $ncN / $deliveries) : ($ncN > 0 ? 0.0 : 1.0);
            $price = null;
            if (!empty($changes[$sid])) {
                $wsum = 0.0; $acc = 0.0;
                foreach ($changes[$sid] as $mid => $chg) { $w = max($mv[$mid] ?? 0.0, 1.0); $wsum += $w; $acc += $w * $chg; }
                $price = round($acc / $wsum, 1);
            }
            ['score' => $score, 'grade' => $grade] = self::score($onTime, $ncScore, $price);
            $mats = $bySupplierMaterials[$sid] ?? [];
            $names = array_slice(array_values(array_filter(array_map(fn($m) => $materials[$m]['name'] ?? null, $mats))), 0, 3);
            $s = $saved[$sid] ?? null;
            $rows[] = ['id' => $sid, 'name' => trim((string) $suppliers[$sid]['name']), 'materials' => count($mats), 'material_names' => $names,
                'value' => round($value, 2), 'deliveries' => $deliveries, 'orders' => $ot[$sid]['orders'] ?? 0, 'on_time' => $onTime, 'nc' => $ncN, 'price' => $price,
                'score' => $score, 'grade' => $grade, 'decision' => $s['decision'] ?? self::defaultDecision($grade), 'note' => $s['note'] ?? '', 'saved' => $s !== null,
                'email' => filter_var(trim((string) $suppliers[$sid]['email']), FILTER_VALIDATE_EMAIL) ? true : false];
        }
        usort($rows, fn($a, $b) => [$b['value'], $b['deliveries'], $a['name']] <=> [$a['value'], $a['deliveries'], $b['name']]);
        $grades = ['A' => 0, 'B' => 0, 'C' => 0];
        foreach ($rows as $r) if ($r['grade']) $grades[$r['grade']]++;
        $done = $this->repo->done(self::CODE, date('Y-m-d', strtotime($Q['to'])), date('Y-m-d', strtotime($Q['to'] . ' +3 months')));
        return ['quarter' => $Q, 'suppliers' => $rows, 'grades' => $grades, 'decisions' => self::DECISIONS, 'weights' => self::WEIGHTS,
            'materials' => $this->proposal($rows, $materials, $msup, $mv, $changes),
            'candidates' => $this->candidates($rows, $materials, $msup, $suppliers),
            'reply_deadline' => date('Y-m-d', strtotime(($today ?? date('Y-m-d')) . ' +14 days')),
            'title' => 'Zapytanie ofertowe — kluczowe surowce (po ocenie dostawców : ' . $Q['label'] . ')',
            'done' => $done ? ['summary' => $done['summary'], 'day' => $done['day']] : null];
    }

    /** Surowce do zapytania (reguła : +5 % ceny, dostawca « zmień », kluczowy z mniej niż 3 dostawcami). */
    private function proposal(array $rows, array $materials, array $msup, array $mv, array $changes): array
    {
        $decision = array_column($rows, 'decision', 'id');
        $total = array_sum($mv);
        $out = [];
        $ids = array_unique(array_merge(array_keys($mv), ...array_map('array_keys', array_values($changes) ?: [[]])));
        foreach ($ids as $mid) {
            $m = $materials[$mid] ?? null;
            if ($m === null || (int) $m['packaging'] === 1) continue;
            $cur = $m['id_current_supplier'] !== null ? (int) $m['id_current_supplier'] : null;
            $chg = null;
            if ($cur !== null && isset($changes[$cur][$mid])) $chg = $changes[$cur][$mid];
            else foreach ($changes as $sid => $c) if (isset($c[$mid])) $chg = max($chg ?? -INF, $c[$mid]);
            $share = $total > 0 ? ($mv[$mid] ?? 0) / $total * 100 : 0.0;
            $count = count($msup[$mid] ?? []);
            $reasons = [];
            if ($chg !== null && $chg >= self::PRICE_ALERT) $reasons[] = 'price';
            if ($cur !== null && ($decision[$cur] ?? null) === 'change') $reasons[] = 'supplier';
            if ($share >= self::KEY_SHARE && $count < 3) $reasons[] = 'few';
            if (!$reasons) continue;
            $out[] = ['id' => $mid, 'name' => $m['name'], 'category' => $m['category'], 'change' => $chg !== null ? round($chg, 1) : null, 'share' => round($share, 1),
                'suppliers' => $count, 'reasons' => $reasons, 'value' => round($mv[$mid] ?? 0, 2), 'id_category' => $m['id_category'] !== null ? (int) $m['id_category'] : null];
        }
        usort($out, fn($a, $b) => [count($b['reasons']), $b['value'], $b['change'] ?? -INF] <=> [count($a['reasons']), $a['value'], $a['change'] ?? -INF]);
        return array_slice($out, 0, self::MAX_MATERIALS);
    }

    /** Dostawcy do zapytania : obecni dostawcy surowców z propozycji + dostawcy tych samych kategorii (z e-mailem, aktywni). */
    private function candidates(array $rows, array $materials, array $msup, array $suppliers): array
    {
        $grade = array_column($rows, 'grade', 'id');
        $decision = array_column($rows, 'decision', 'id');
        $out = [];
        foreach ($msup as $mid => $sids) {
            foreach ($sids as $sid) {
                $s = $suppliers[$sid] ?? null;
                if ($s === null || !(int) $s['is_active'] || !filter_var(trim((string) $s['email']), FILTER_VALIDATE_EMAIL)) continue;
                $out[$sid] ??= ['id' => $sid, 'name' => trim((string) $s['name']), 'grade' => $grade[$sid] ?? null, 'decision' => $decision[$sid] ?? null, 'materials' => [], 'categories' => []];
                $out[$sid]['materials'][] = $mid;
                if (isset($materials[$mid]['id_category'])) $out[$sid]['categories'][(int) $materials[$mid]['id_category']] = true;
            }
        }
        return array_values(array_map(fn($c) => ['categories' => array_keys($c['categories'])] + $c, $out));
    }

    /**
     * Zapis ocen (krok 1 « Dalej » i « Zatwierdź ») : [{supplier_id, decision, note}] — ocena i dane liczone na serwerze.
     * @return int zapisane
     */
    public function saveGrades(?string $q, array $rows, ?int $userId, ?string $today = null): int
    {
        $d = $this->data($q, $today);
        $by = array_column($d['suppliers'], null, 'id');
        $n = 0;
        foreach ($rows as $i => $r) {
            $sid = is_array($r) ? ($r['supplier_id'] ?? null) : null;
            if (!is_int($sid) || !isset($by[$sid])) throw new CustomException('Ocena ' . ($i + 1) . ' : dostawca bez danych w kwartale.', 'errors', 422);
            $decision = $r['decision'] ?? null;
            if (!isset(self::DECISIONS[$decision])) throw new CustomException('Ocena « ' . $by[$sid]['name'] . ' » : wybierz decyzję.', 'errors', 422);
            $note = trim((string) ($r['note'] ?? ''));
            if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
            $s = $by[$sid];
            $this->repo->saveReview($d['quarter']['key'], $sid, $s['score'], $s['grade'], $decision,
                ['value' => $s['value'], 'deliveries' => $s['deliveries'], 'on_time' => $s['on_time'], 'nc' => $s['nc'], 'price' => $s['price']], $note !== '' ? $note : null, $userId);
            $n++;
        }
        return $n;
    }

    /** Krok 3 : szkic zapytania ofertowego (Zapytania ofertowe) — nic nie jest wysyłane. @return array{id:int, code:?string} */
    public function createTender(array $input, ?int $userId): array
    {
        $materials = array_values(array_unique(array_filter(is_array($input['material_ids'] ?? null) ? $input['material_ids'] : [], 'is_int')));
        $suppliers = array_values(array_unique(array_filter(is_array($input['supplier_ids'] ?? null) ? $input['supplier_ids'] : [], 'is_int')));
        if (!$materials) throw new CustomException('Wybierz co najmniej jeden surowiec.', 'errors', 422);
        if (!$suppliers) throw new CustomException('Wybierz co najmniej jednego dostawcę (z adresem e-mail).', 'errors', 422);
        $id = $this->tenders->create(['title' => mb_substr(trim((string) ($input['title'] ?? 'Zapytanie ofertowe')) ?: 'Zapytanie ofertowe', 0, 200),
            'reply_deadline' => is_string($input['reply_deadline'] ?? null) ? $input['reply_deadline'] : null, 'language' => 'pl',
            'intro' => is_string($input['intro'] ?? null) && trim($input['intro']) !== '' ? mb_substr(trim($input['intro']), 0, 2000) : null,
            'material_ids' => $materials, 'supplier_ids' => $suppliers], $userId);
        return ['id' => $id, 'code' => $this->repo->tenderCode($id)];
    }

    /** « Zatwierdź ocenę » : oceny + wynik na karcie zadania (oceny A / B / C, zapytanie). */
    public function finish(?string $q, array $rows, ?int $tenderId, ?int $userId, ?string $today = null): array
    {
        if ($rows) $this->saveGrades($q, $rows, $userId, $today);
        $d = $this->data($q, $today);
        $g = $d['grades'];
        $code = $tenderId ? $this->repo->tenderCode($tenderId) : null;
        $summary = sprintf('%s : %d A · %d B · %d C', $d['quarter']['label'], $g['A'], $g['B'], $g['C']) . ($code ? ' · zapytanie ' . $code : ' · bez zapytania');
        $this->repo->markDone(self::CODE, $today ?? date('Y-m-d'), $summary, $userId);
        return ['summary' => $summary];
    }
}
