<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Purchasing\CostReviewRepository;
use App\Repositories\Purchasing\MaterialCostRepository;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Purchasing\OfferService;
use App\Services\Purchasing\TenderService;

/**
 * Kreator « Koszty surowców i oszczędności » [247] (makieta 22 ; Kierownik produkcji, co miesiąc) :
 *   1. Co zdrożało : cena bazowa teraz vs 3 miesiące temu (dziennik cen), zużycie miesięczne (zamówienia × receptury,
 *      średnia 3 mies.) → wpływ zł / miesiąc ; surowce posortowane po wpływie ;
 *   2. Tańsze opcje : najlepsza ważna oferta innego dostawcy (Oferty) albo ostatnia niższa cena innego dostawcy
 *      (dziennik cen, 12 mies.) → oszczędność / miesiąc ; decyzja : zmień (tylko z ofertą) / zapytaj / zostaw ;
 *   3. Decyzje : « zmień » przyjmuje ofertę (dostawca bieżący ; cena bazowa = najwyższa zatwierdzona), « zapytaj » tworzy
 *      szkic zapytania ofertowego (wysyłka po sprawdzeniu w Zapytaniach), decyzje i oszczędność w n_cost_review.
 */
final class CostReviewService
{
    public const CODE = 'material_costs_reviewed';
    public const DECISIONS = ['change' => 'Zmień', 'ask' => 'Zapytaj', 'keep' => 'Zostaw'];
    private const MIN_CHANGE = 1.0;
    private const MAX_ROWS = 15;

    public function __construct(private MaterialCostRepository $costs, private CostReviewRepository $repo, private LightWizardRepository $wizards,
        private OfferService $offers, private TenderService $tenders)
    {
    }

    public function data(?string $m, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $M = NcReviewService::month($m, $today);
        $ref = strtotime($M['to'] . ' -3 months');
        $use = $this->costs->consumption(3);
        $history = [];
        foreach ($this->costs->priceHistory() as $h) $history[(int) $h['id_raw_material']][] = $h;
        $best = [];
        foreach ($this->costs->validOffers() as $o) $best[(int) $o['id_raw_material']][] = $o;
        $others = $this->repo->lastPrices(date('Y-m-d', strtotime($today . ' -12 months')));
        $saved = $this->repo->decisions($M['key']);
        $rows = [];
        $spend = 0.0;
        $all = [];
        foreach ($this->costs->materials() as $mat) {
            $id = (int) $mat['id'];
            $price = (float) $mat['price_per_unit'];
            $qty = ($use[$id] ?? 0.0) / 3;
            if ($qty <= 0 || $price <= 0) continue;
            $spend += $qty * $price;
            $all[$id] = $qty * $price;
            $old = self::priceAt($history[$id] ?? [], $ref);
            if ($old <= 0) continue;
            $chg = ($price - $old) / $old * 100;
            if (abs($chg) < self::MIN_CHANGE) continue;
            $unit = CostOptimizationService::displayUnit($mat['unit']);
            $cur = $mat['id_current_supplier'] !== null ? (int) $mat['id_current_supplier'] : null;
            $rows[] = ['id' => $id, 'name' => $mat['name'], 'category' => $mat['category'], 'packaging' => (bool) $mat['is_part_of_package'], 'supplier_id' => $cur,
                'supplier' => trim((string) $mat['supplier_name']), 'unit' => $unit['label'], 'factor' => $unit['factor'],
                'qty' => round($qty / $unit['factor'], 2), 'price' => round($price * $unit['factor'], 4), 'price_old' => round($old * $unit['factor'], 4),
                'change_pct' => round($chg, 1), 'impact' => round($qty * ($price - $old), 2),
                'alt' => $this->alternative($id, $cur, $price, $qty, $unit['factor'], $best[$id] ?? [], $others[$id] ?? []),
                'decision' => isset($saved[$id]) ? $saved[$id]['decision'] : null];
        }
        usort($rows, fn($a, $b) => $b['impact'] <=> $a['impact']);
        $up = array_values(array_filter($rows, fn($r) => $r['impact'] > 0));
        arsort($all);
        $top10 = array_sum(array_slice($all, 0, 10));
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        foreach ($rows as &$r) $r['decision'] ??= $r['impact'] <= 0 ? 'keep' : ($r['alt'] && $r['alt']['offer_id'] ? 'change' : 'ask');
        unset($r);
        $done = $this->wizards->done(self::CODE, date('Y-m-01', strtotime($today)), date('Y-m-d', strtotime(date('Y-m-01', strtotime($today)) . ' +1 month')));
        return ['month' => $M, 'rows' => $rows, 'decisions' => self::DECISIONS,
            'totals' => ['spend' => round($spend, 2), 'increase' => round(array_sum(array_column($up, 'impact')), 2), 'up' => count($up),
                'top_name' => $up[0]['name'] ?? null, 'top_share' => $up && array_sum(array_column($up, 'impact')) > 0 ? round($up[0]['impact'] / array_sum(array_column($up, 'impact')) * 100) : null,
                'top10_pct' => $spend > 0 ? round($top10 / $spend * 100) : null, 'materials' => count($all)],
            'done' => $done ? ['summary' => $done['summary'], 'day' => $done['day']] : null];
    }

    /** Tańsza opcja : najlepsza ważna oferta innego dostawcy, inaczej najniższa ostatnia cena innego dostawcy. */
    private function alternative(int $id, ?int $cur, float $price, float $qty, float $factor, array $offers, array $others): ?array
    {
        foreach ($offers as $o) {
            $p = (float) $o['price_per_base'];
            if ((int) $o['id_supplier'] === $cur || $p <= 0 || $p >= $price) continue;
            return ['offer_id' => (int) $o['id'], 'supplier_id' => (int) $o['id_supplier'], 'supplier' => trim((string) $o['supplier_name']), 'price' => round($p * $factor, 4),
                'saving' => round(($price - $p) * $qty, 2), 'source' => 'offer', 'at' => $o['valid_from']];
        }
        $bestOther = null;
        foreach ($others as $sid => $o) {
            if ($sid === $cur || $o['price'] <= 0 || $o['price'] >= $price) continue;
            if ($bestOther === null || $o['price'] < $bestOther['price']) $bestOther = ['supplier_id' => $sid] + $o;
        }
        return $bestOther ? ['offer_id' => null, 'supplier_id' => $bestOther['supplier_id'], 'supplier' => $bestOther['name'], 'price' => round($bestOther['price'] * $factor, 4),
            'saving' => round(($price - $bestOther['price']) * $qty, 2), 'source' => 'history', 'at' => $bestOther['at']] : null;
    }

    /** Cena za jednostkę bazową obowiązująca w chwili $ts (ostatnia zmiana przed nią, inaczej pierwsza znana). */
    public static function priceAt(array $history, int $ts): float
    {
        $price = 0.0;
        foreach ($history as $row) {
            if (strtotime((string) $row['timestamp']) > $ts) return $price > 0 ? $price : (float) $row['price_per_unit'];
            $price = (float) $row['price_per_unit'];
        }
        return $price;
    }

    /**
     * « Zapisz decyzje » : [{id, decision}] — zmień (oferta przyjęta), zapytaj (jeden szkic zapytania na wszystkie),
     * zostaw ; zadanie zaliczone. @return array{summary:string, tender:?array}
     */
    public function finish(?string $m, array $rows, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($m, $today);
        $by = array_column($d['rows'], null, 'id');
        $plan = [];
        foreach ($rows as $i => $r) {
            $id = is_array($r) ? (int) ($r['id'] ?? 0) : 0;
            $dec = is_array($r) ? ($r['decision'] ?? null) : null;
            if (!isset($by[$id])) throw new CustomException('Decyzja ' . ($i + 1) . ' : surowiec spoza listy miesiąca.', 'errors', 422);
            if (!isset(self::DECISIONS[$dec])) throw new CustomException('« ' . $by[$id]['name'] . ' » : wybierz decyzję.', 'errors', 422);
            if ($dec === 'change' && empty($by[$id]['alt']['offer_id'])) throw new CustomException('« ' . $by[$id]['name'] . ' » : zmiana dostawcy tylko z ważną ofertą — wybierz « Zapytaj ».', 'errors', 422);
            $plan[$id] = $dec;
        }
        $tender = null;
        $ask = array_keys(array_filter($plan, fn($x) => $x === 'ask'));
        if ($ask) {
            $sup = [];
            foreach ($this->repo->materialSuppliers($ask) as $list) foreach ($list as $sid) $sup[$sid] = true;
            foreach ($ask as $mid) {
                if ($by[$mid]['supplier_id']) $sup[$by[$mid]['supplier_id']] = true;
                if ($by[$mid]['alt']) $sup[$by[$mid]['alt']['supplier_id']] = true;
            }
            $mail = $this->repo->mailable(array_keys($sup));
            if ($mail) {
                $tid = $this->tenders->create(['title' => mb_substr('Zapytanie ofertowe — surowce, które zdrożały (' . $d['month']['label'] . ')', 0, 200),
                    'reply_deadline' => date('Y-m-d', strtotime($today . ' +10 days')), 'language' => 'pl', 'material_ids' => array_slice($ask, 0, 50), 'supplier_ids' => array_slice(array_keys($mail), 0, 30)], $userId);
                $tender = ['id' => $tid, 'code' => $this->repo->tenderCode($tid), 'suppliers' => count($mail)];
            }
        }
        $saving = 0.0;
        $changed = 0;
        foreach ($plan as $id => $dec) {
            $r = $by[$id];
            $f = $r['factor'] ?: 1;
            if ($dec === 'change') {
                $this->offers->apply((int) $r['alt']['offer_id']);
                $saving += (float) $r['alt']['saving'];
                $changed++;
            }
            $this->repo->saveDecision($d['month']['key'], $id, ['decision' => $dec, 'from' => $r['supplier_id'], 'to' => $dec === 'change' ? $r['alt']['supplier_id'] : null,
                'price_from' => $r['price'] / $f, 'price_to' => $r['alt'] ? $r['alt']['price'] / $f : null, 'saving' => $dec === 'change' ? $r['alt']['saving'] : null,
                'offer' => $dec === 'change' ? $r['alt']['offer_id'] : null, 'tender' => $dec === 'ask' ? ($tender['id'] ?? null) : null], $userId);
        }
        $summary = sprintf('%s : %d %s', $d['month']['label'], count($plan), count($plan) === 1 ? 'decyzja' : 'decyzji')
            . ($changed ? sprintf(' · %d zmian%s dostawcy (−%s zł/mies.)', $changed, $changed === 1 ? 'a' : 'y', number_format($saving, 0, ',', "\u{00A0}")) : '')
            . ($tender ? ' · zapytanie ' . $tender['code'] : (count($ask) ? ' · bez zapytania (brak e-maili)' : ''));
        $this->wizards->markDone(self::CODE, $today, $summary, $userId);
        return ['summary' => $summary, 'tender' => $tender];
    }
}
