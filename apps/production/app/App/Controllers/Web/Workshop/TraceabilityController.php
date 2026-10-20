<?php

namespace App\Controllers\Web\Workshop;

use App\Core\Web\BaseController;
use App\Repositories\Workshop\GroupRepository;
use App\Repositories\Workshop\OutputRepository;

/**
 * Produkcja › Partie i identyfikowalność : partie wyrobu wpisane na tabletach grup, partie surowców
 * i opakowań (numer, data przydatności, zdjęcie etykiety) ; wyszukiwanie po numerze partii wyrobu
 * lub surowca (wycofanie z rynku : w których partiach wyrobu jest dana partia surowca).
 */
class TraceabilityController extends BaseController
{
    public function __construct(private OutputRepository $outputs, private GroupRepository $groups)
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
        } catch (\Throwable $e) {
            error_log('[traceability] ' . $e->getMessage());
            $error = 'Nie udało się wczytać partii.';
        }
        $active = array_filter($rows, fn($r) => $r['cancelled_at'] === null);
        $this->view('workshop/traceability', [
            'rows' => $rows, 'from' => $from, 'to' => $to, 'group' => $groupId, 'q' => $search, 'error' => $error,
            'groups' => $this->groups->list(true),
            'totals' => [
                'batches' => count($active),
                'complete' => count(array_filter($active, fn($r) => $r['lots_total'] > 0 && $r['lots_done'] >= $r['lots_total'])),
                'missing' => count(array_filter($active, fn($r) => $r['lots_done'] < $r['lots_total'])),
                'material_hits' => $search ? count(array_filter($active, fn($r) => $r['matches'])) : 0,
            ],
        ]);
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
