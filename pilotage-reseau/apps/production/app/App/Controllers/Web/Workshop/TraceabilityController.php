<?php

namespace App\Controllers\Web\Workshop;

use App\Core\Web\BaseController;
use App\Repositories\Workshop\GroupRepository;
use App\Repositories\Workshop\OutputRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Produkcja › Partie i identyfikowalność : partie wyrobu wpisane na tabletach grup, partie surowców
 * i opakowań (numer, data przydatności, zdjęcie etykiety) ; wyszukiwanie po numerze partii wyrobu
 * lub surowca (wycofanie z rynku : w których partiach wyrobu jest dana partia surowca) ; dokąd poszła partia
 * (P22) : kartony produktu oklejone w dniu produkcji (+ trace.label_days), palety, klienci, WZ.
 */
class TraceabilityController extends BaseController
{
    public function __construct(private OutputRepository $outputs, private GroupRepository $groups, private ParamRepository $params)
    {
    }

    public function index()
    {
        $valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        $to = $valid($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
        $from = $valid($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -6 days'));
        if ($from > $to) [$from, $to] = [$to, $from];
        $groupId = (int) ($_GET['group'] ?? 0) ?: null;
        $search = trim((string) ($_GET['q'] ?? '')) ?: null;
        $rows = [];
        $error = null;
        try {
            $rows = $this->outputs->trace($from, $to, $groupId, $search ? mb_substr($search, 0, 64) : null);
            $lots = $this->outputs->traceLots(array_map(fn($r) => (int) $r['id'], $rows));
            $totals = [];
            foreach ($rows as &$r) {
                $item = (int) $r['id_item'];
                if (!isset($totals[$item])) {
                    $i = $this->outputs->item($item);
                    $totals[$item] = $i && $i['id_recipe'] ? count($this->outputs->recipe((int) $i['id_recipe'])) : 0;
                }
                $r['lots'] = $lots[(int) $r['id']] ?? [];
                $r['lots_done'] = count(array_filter($r['lots'], fn($l) => $l['lot_number'] !== null && $l['lot_number'] !== ''));
                $r['lots_total'] = $totals[$item];
                $r['matches'] = $search ? array_values(array_filter($r['lots'], fn($l) => $l['lot_number'] !== null && stripos($l['lot_number'], $search) !== false)) : [];
            }
            unset($r);
            $labelDays = max(0, min(7, (int) $this->params->get('trace.label_days', '1')));
            $this->attachShipments($rows, $labelDays);
        } catch (\Throwable $e) {
            error_log('[traceability] ' . $e->getMessage());
            $error = 'Nie udało się wczytać partii.';
        }
        $active = array_filter($rows, fn($r) => $r['cancelled_at'] === null);
        // Wycofanie : klienci, którzy dostali kartony partii z trafionym numerem surowca (albo wszystkich pokazanych partii)
        $recall = [];
        foreach ($active as $r) {
            if ($search && !$r['matches'] && stripos((string) $r['lot_code'], $search) === false) continue;
            foreach ($r['ship']['customers'] ?? [] as $c) {
                $key = $c['id'] ?: 'x';
                $recall[$key] ??= ['id' => $c['id'], 'name' => $c['name'], 'cartons' => 0, 'dates' => [], 'products' => []];
                $recall[$key]['cartons'] += $c['cartons'];
                $recall[$key]['dates'][$c['delivery_date']] = true;
                $recall[$key]['products'][$r['item_name']] = true;
            }
        }
        foreach ($recall as &$c) { $c['dates'] = array_keys($c['dates']); sort($c['dates']); $c['products'] = array_keys($c['products']); }
        unset($c);
        usort($recall, fn($a, $b) => $b['cartons'] <=> $a['cartons']);
        $this->view('workshop/traceability', [
            'rows' => $rows, 'from' => $from, 'to' => $to, 'group' => $groupId, 'q' => $search, 'error' => $error,
            'groups' => $this->groups->list(true), 'recall' => $recall, 'label_days' => $labelDays ?? 1,
            'totals' => [
                'batches' => count($active),
                'complete' => count(array_filter($active, fn($r) => $r['lots_total'] > 0 && $r['lots_done'] >= $r['lots_total'])),
                'missing' => count(array_filter($active, fn($r) => $r['lots_done'] < $r['lots_total'])),
                'material_hits' => $search ? count(array_filter($active, fn($r) => $r['matches'])) : 0,
            ],
        ]);
    }

    /**
     * Dla każdej partii wyrobu : kartony tego produktu oklejone od dnia produkcji do + $labelDays dni, ich stan
     * (magazyn, paleta, wysłane) i klienci (paleta → klient, data dostawy, WZ).
     */
    private function attachShipments(array &$rows, int $labelDays): void
    {
        $products = array_filter(array_map(fn($r) => (int) ($r['id_final_product'] ?? 0), $rows));
        if (!$products) return;
        $dates = array_column($rows, 'work_date');
        $data = $this->outputs->cartonsShipped($products, min($dates), date('Y-m-d', strtotime(max($dates) . " +{$labelDays} days")));
        foreach ($rows as &$r) {
            $pid = (int) ($r['id_final_product'] ?? 0);
            if (!$pid) { $r['ship'] = null; continue; }
            $until = date('Y-m-d', strtotime($r['work_date'] . " +{$labelDays} days"));
            $ship = ['cartons' => 0, 'stock' => 0, 'pallet' => 0, 'shipped' => 0, 'customers' => []];
            foreach ($data as $d) {
                if ((int) $d['id_product'] !== $pid || $d['date_add'] < $r['work_date'] || $d['date_add'] > $until) continue;
                $n = (int) $d['cartons'];
                $ship['cartons'] += $n;
                $ship[(int) $d['status'] === 3 ? 'shipped' : ((int) $d['status'] === 2 ? 'pallet' : 'stock')] += $n;
                if ($d['id_customer'] === null) continue;
                $key = $d['id_customer'] . '|' . $d['delivery_date'];
                $ship['customers'][$key] ??= ['id' => (int) $d['id_customer'], 'name' => $d['customer'] ?: ('#' . $d['id_customer']),
                    'delivery_date' => $d['delivery_date'], 'cartons' => 0, 'pallets' => 0, 'wz' => [], 'shipped' => false];
                $ship['customers'][$key]['cartons'] += $n;
                $ship['customers'][$key]['pallets'] += (int) $d['pallets'];
                $ship['customers'][$key]['shipped'] = $ship['customers'][$key]['shipped'] || (int) $d['status'] === 3;
                foreach (array_filter(explode(', ', (string) $d['wz'])) as $wz) $ship['customers'][$key]['wz'][$wz] = true;
            }
            foreach ($ship['customers'] as &$c) $c['wz'] = array_keys($c['wz']);
            unset($c);
            $ship['customers'] = array_values($ship['customers']);
            $r['ship'] = $ship;
        }
        unset($r);
    }

    /** Zdjęcie etykiety partii surowca (tylko dla zalogowanych w ERP). */
    public function photo($id)
    {
        $photo = $this->outputs->photo((int) $id);
        if ($photo === null) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $photo['mime']);
        header('Content-Length: ' . strlen($photo['image']));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $photo['image'];
    }
}
