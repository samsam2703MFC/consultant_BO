<?php
declare(strict_types=1);

namespace App\Services\Workshop\Quality;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ColdVisitRepository;
use App\Repositories\Workshop\OutputRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Workshop\Referentiel\AuditTrail;
use App\Services\Workshop\Supply\SupplyService;

/**
 * [432] Obchód chłodni przed zakupami (makieta « Obchód chłodni ») — tablette qualité du Kierownik produkcji, puis ERP.
 *
 * Accès tablette : la même restriction que la tablette qualité (QualityRoundService::assertManager : personne identifiée
 * au poste KIEROWNIK_PRODUKCJI ou JAKOSC, sinon 403 QC_ROUND_POSITION).
 *
 * A. Miejsca : chłodnie / mroźnie actives du rejestr « Serwisy i przeglądy » (n_equipment.kind = 'cold') et miejsca ajoutées
 *    dans l'ERP (Magazyn suchy…). Statut dans le obchód en cours : zrobione / teraz (la première pas encore zapisana) /
 *    potem ; surowce, różnice, porządek, heure.
 * B. Une miejsce : un rang par surowiec rattaché (appris au premier obchód — « Dodaj surowiec, który tu leży », retrait
 *    possible) : partia bieżąca (rejestr des partii, FEFO), « W ERP » = stan de Zaopatrzenie (SupplyService::stockLevels :
 *    dernière inwentaryzacja + przyjęcia − zużycie z receptur), « Jest naprawdę », date (OK / krótka — najpierw), perte
 *    (do wyrzucenia / uszkodzone : ilość + powód), porządek tak / nie (nie = zdjęcie obligatoire, n_prod_lot_photo comme la
 *    tablette qualité). « Zapisz chłodnię » :
 *    - inventaire par le mécanisme de Zaopatrzenie (SupplyRepository::addCount → n_mp_count, date du jour) : quantité =
 *      policzone − strata, additionnée sur toutes les miejsca du surowiec dans ce obchód ; un surowiec rangé dans plusieurs
 *      miejsca est écrit quand la dernière d'entre elles est zapisana (sinon le stan ne compterait qu'une partie) ;
 *    - pertes : l'ERP n'a pas de mécanisme de perte de surowce (n_prod_waste = produits, n_raw_material_loss = odpad
 *      przy obróbce en %) — elles restent dans n_cold_visit_line, l'inventaire écrit est la quantité restante ;
 *    - obchód : ouvert au premier zapis (repris tant qu'il a moins de cold_visit.valid_hours heures), complet quand toutes
 *      les miejsca actives sont zapisane.
 * C. ERP (Zakupy tygodnia, krok « Obchód chłodni ») : dernier obchód complet (qui, quand, miejsca, surowce), tableau des
 *    różnice (ERP, policzone, uwagi : krótka data, do wyrzucenia, uszkodzone) ; « Co kupić » fermé sans obchód complet de
 *    moins de cold_visit.valid_hours heures (défaut 24) — sauf sans aucune miejsce à obejść (rien à obejść).
 * Aucun e-mail, SMS ni appel externe.
 */
final class ColdVisitService
{
    public const DATE_STATES = ['ok', 'short'];
    public const LOSS_KINDS = ['discard', 'damaged'];
    public const CLEAN = ['yes', 'no'];
    public const PLACE_KINDS = ['cold', 'dry', 'other'];
    private const MAX_QTY = 1_000_000_000;
    private const EPS = 0.0005;

    public function __construct(
        private ColdVisitRepository $repo,
        private QualityRoundService $round,
        private SupplyService $supply,
        private SupplyRepository $supplyRepo,
        private OutputRepository $outputs,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    /** Fenêtre de validité du obchód en heures (cold_visit.valid_hours, 1–168 ; défaut 24). */
    public function validHours(): int
    {
        $raw = trim($this->params->get('cold_visit.valid_hours', '24'));
        $v = ctype_digit($raw) ? (int) $raw : 24;
        return $v < 1 || $v > 168 ? 24 : $v;
    }

    // --- A. Miejsca du obchód -------------------------------------------------------------------------------------------

    public function home(ApiContext $context, ?string $now = null): array
    {
        $me = $this->round->assertManager($context);
        $now ??= date('Y-m-d H:i:s');
        $places = $this->places();
        $cur = $this->current($now);
        $saved = $cur !== null ? $this->repo->visitPlaces((int) $cur['visit']['id']) : [];
        $rows = [];
        $first = true;
        foreach ($places as $p) {
            $s = $saved[(int) $p['id']] ?? null;
            $status = $s !== null ? 'done' : ($first ? 'now' : 'later');
            if ($s === null) $first = false;
            $rows[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'kind' => $p['kind'], 'source' => $p['id_equipment'] !== null ? 'equipment' : 'extra',
                'location' => $p['location'] !== null && trim((string) $p['location']) !== '' ? (string) $p['location'] : null, 'materials' => (int) $p['materials'], 'status' => $status,
                'saved' => $s !== null ? ['at' => self::hm((string) $s['saved_at']), 'materials' => (int) $s['materials'], 'differences' => (int) $s['differences'],
                    'clean' => $s['clean'], 'by' => trim((string) $s['employee_name']) ?: null] : null];
        }
        $next = null;
        foreach ($rows as $r) if ($next === null && $r['status'] === 'now') $next = $r['id'];
        return ['now' => $now, 'employee' => $me, 'valid_hours' => $this->validHours(),
            'visit' => $cur !== null ? $this->visitInfo($cur['visit'], $cur['state'], $now) : null,
            'to_do' => count(array_filter($rows, fn($r) => $r['status'] !== 'done')), 'next_id' => $next, 'places' => $rows];
    }

    // --- B. Une miejsce -----------------------------------------------------------------------------------------------------

    public function place(int $id, ApiContext $context, ?string $now = null): array
    {
        $me = $this->round->assertManager($context);
        $now ??= date('Y-m-d H:i:s');
        $p = $this->activePlace($id);
        $fr = $context->translator->lang() === 'fr';
        $mats = $this->repo->placeMaterials($id);
        $ids = array_map(fn($m) => (int) $m['id'], $mats);
        $levels = $this->supply->stockLevels($ids);
        $lots = $this->repo->currentLots($ids);
        $where = $this->repo->materialPlaces($ids);
        $names = array_column($this->places(), 'name', 'id');
        $cur = $this->current($now);
        $visitPlace = $cur !== null && $cur['state'] === 'open' ? ($this->repo->visitPlaces((int) $cur['visit']['id'])[$id] ?? null) : null;
        $savedLines = [];
        $erpBefore = [];
        if ($cur !== null && $cur['state'] === 'open') {
            foreach ($this->repo->visitLines((int) $cur['visit']['id']) as $l) {
                $erpBefore[(int) $l['id_raw_material']] ??= $l['erp_qty'] !== null ? (float) $l['erp_qty'] : null;
                if ((int) $l['id_place'] === $id) $savedLines[(int) $l['id_raw_material']] = $l;
            }
        }
        $rows = [];
        foreach ($mats as $m) {
            $mid = (int) $m['id'];
            $unit = CostOptimizationService::displayUnit($m['unit']);
            // Stan ERP d'avant le obchód : celui gardé au premier zapis de ce obchód (le stan change dès l'inventaire écrit).
            $erp = array_key_exists($mid, $erpBefore) ? $erpBefore[$mid] : ($levels[$mid]['stock'] ?? null);
            $l = $savedLines[$mid] ?? null;
            $rows[] = ['material_id' => $mid, 'name' => $fr && trim((string) $m['name_fr']) !== '' ? (string) $m['name_fr'] : (string) $m['name'],
                'base_unit' => $m['unit'] ?: 'szt', 'unit' => $unit['label'], 'factor' => $unit['factor'], 'erp' => $erp !== null ? round((float) $erp, 3) : null,
                'lot' => isset($lots[$mid]) ? $lots[$mid] : null,
                'also_in' => array_values(array_map(fn($pid) => (string) ($names[$pid] ?? '#' . $pid), array_filter($where[$mid] ?? [], fn($pid) => $pid !== $id))),
                'saved' => $l !== null ? ['quantity' => (float) $l['counted_qty'], 'date_state' => $l['date_state'],
                    'loss' => $l['loss_kind'] !== null ? ['kind' => $l['loss_kind'], 'quantity' => (float) $l['loss_qty'], 'reason' => $l['loss_reason']] : null] : null];
        }
        return ['id' => $id, 'name' => (string) $p['name'], 'kind' => $p['kind'], 'source' => $p['id_equipment'] !== null ? 'equipment' : 'extra', 'employee' => $me,
            'rows' => $rows, 'saved' => $visitPlace !== null ? ['at' => self::hm((string) $visitPlace['saved_at']), 'clean' => $visitPlace['clean'],
                'photo_id' => $visitPlace['id_photo'] !== null ? (int) $visitPlace['id_photo'] : null, 'note' => $visitPlace['note']] : null,
            'loss_kinds' => self::LOSS_KINDS, 'date_states' => self::DATE_STATES];
    }

    /** « Dodaj surowiec, który tu leży » (une fois par miejsce). */
    public function assign(int $placeId, int $materialId, ApiContext $context): array
    {
        $this->round->assertManager($context);
        $this->activePlace($placeId);
        $m = $this->repo->material($materialId) ?? throw ApiException::notFound('raw_material', $materialId);
        if (!$this->repo->assigned($placeId, $materialId)) {
            $this->tx->execute(function () use ($placeId, $materialId, $context, $m) {
                $this->repo->assign($placeId, $materialId, $context->employeeId, $context->userId);
                $this->audit->record($context, 'cold_assign', 'cold_place', $placeId, null, ['raw_material_id' => $materialId, 'name' => $m['name']]);
            });
        }
        return $this->place($placeId, $context);
    }

    /** Surowiec qui ne leży plus ici. */
    public function unassign(int $placeId, int $materialId, ApiContext $context): array
    {
        $this->round->assertManager($context);
        $this->activePlace($placeId);
        if ($this->repo->assigned($placeId, $materialId)) {
            $this->tx->execute(function () use ($placeId, $materialId, $context) {
                $this->repo->unassign($placeId, $materialId);
                $this->audit->record($context, 'cold_unassign', 'cold_place', $placeId, ['raw_material_id' => $materialId], null);
            });
        }
        return $this->place($placeId, $context);
    }

    /** Recherche d'un surowiec à rattacher (au moins 2 caractères). */
    public function search(string $q, ApiContext $context): array
    {
        $this->round->assertManager($context);
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $fr = $context->translator->lang() === 'fr';
        return array_map(function ($m) use ($fr) {
            $unit = CostOptimizationService::displayUnit($m['unit']);
            return ['id' => (int) $m['id'], 'name' => $fr && trim((string) $m['name_fr']) !== '' ? (string) $m['name_fr'] : (string) $m['name'], 'unit' => $unit['label']];
        }, $this->repo->search($q, 20));
    }

    /**
     * « Zapisz chłodnię » : chaque surowiec de la miejsce policzony (jednostka bazowa), date, perte, porządek (nie = zdjęcie).
     * Écrit la miejsce dans le obchód en cours (ouvert au besoin), l'inventaire de Zaopatrzenie, ferme le obchód complet.
     */
    public function save(int $placeId, array $body, ApiContext $context, ?string $now = null): array
    {
        $me = $this->round->assertManager($context);
        $now ??= date('Y-m-d H:i:s');
        $p = $this->activePlace($placeId);
        $v = (new Validator($body))->only(['lines', 'clean', 'photo_id', 'note'])
            ->choice('clean', self::CLEAN, true)->integer('photo_id', false, 1, null, true)->string('note', 255, false, true);
        $mats = array_column($this->repo->placeMaterials($placeId), null, 'id');
        $lines = $body['lines'] ?? [];
        $clean = [];
        if (!is_array($lines) || !array_is_list($lines)) $v->add('lines', 'NOT_ARRAY');
        else {
            foreach ($lines as $i => $l) {
                $f = "lines.{$i}";
                $mid = is_array($l) ? ($l['material_id'] ?? null) : null;
                if (!is_int($mid) || !isset($mats[$mid])) { $v->add("{$f}.material_id", 'REFERENCE_NOT_FOUND', ['id' => is_scalar($mid) ? (string) $mid : '?']); continue; }
                if (isset($clean[$mid])) { $v->add("{$f}.material_id", 'DUPLICATE_IN_LIST'); continue; }
                $qty = $l['quantity'] ?? null;
                if ((!is_int($qty) && !is_float($qty)) || $qty < 0 || $qty > self::MAX_QTY) { $v->add("{$f}.quantity", 'OUT_OF_RANGE', ['min' => 0, 'max' => self::MAX_QTY]); continue; }
                $date = $l['date_state'] ?? null;
                if ($date !== null && !in_array($date, self::DATE_STATES, true)) { $v->add("{$f}.date_state", 'INVALID_CHOICE', ['allowed' => implode(', ', self::DATE_STATES)]); continue; }
                $loss = $l['loss'] ?? null;
                if ($loss !== null) {
                    $kind = is_array($loss) ? ($loss['kind'] ?? null) : null;
                    $lq = is_array($loss) ? ($loss['quantity'] ?? null) : null;
                    $reason = is_array($loss) && is_string($loss['reason'] ?? null) ? trim($loss['reason']) : '';
                    if (!in_array($kind, self::LOSS_KINDS, true)) { $v->add("{$f}.loss.kind", 'INVALID_CHOICE', ['allowed' => implode(', ', self::LOSS_KINDS)]); continue; }
                    if ((!is_int($lq) && !is_float($lq)) || $lq <= 0 || $lq > $qty + self::EPS) { $v->add("{$f}.loss.quantity", 'OUT_OF_RANGE', ['min' => 0, 'max' => $qty]); continue; }
                    if (mb_strlen($reason) < 3) { $v->add("{$f}.loss.reason", 'REQUIRED'); continue; }
                    if (mb_strlen($reason) > 120) { $v->add("{$f}.loss.reason", 'TOO_LONG', ['max' => 120]); continue; }
                    $loss = ['kind' => $kind, 'quantity' => round((float) $lq, 3), 'reason' => $reason];
                }
                $clean[$mid] = ['quantity' => round((float) $qty, 3), 'date_state' => $date, 'loss' => $loss];
            }
        }
        $v->throwIfInvalid();
        $missing = count(array_diff_key($mats, $clean));
        if ($missing > 0) throw new ApiException('COLD_VISIT_NOT_COUNTED', 422, [], ['count' => $missing]);
        $isClean = $v->value('clean');
        $photoId = $v->value('photo_id') !== null ? (int) $v->value('photo_id') : null;
        if ($isClean === 'no' && $photoId === null) throw new ApiException('COLD_VISIT_PHOTO_REQUIRED', 422);
        if ($photoId !== null && $this->outputs->photo($photoId) === null) throw ApiException::validation([['field' => 'photo_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => ['id' => $photoId]]]);
        $note = $v->value('note') !== null && trim((string) $v->value('note')) !== '' ? trim((string) $v->value('note')) : null;

        $this->tx->execute(function () use ($placeId, $p, $clean, $isClean, $photoId, $note, $context, $me, $now) {
            $since = date('Y-m-d H:i:s', strtotime($now) - $this->validHours() * 3600);
            $visit = $this->repo->openVisit($since);
            $visitId = $visit !== null ? (int) $visit['id'] : $this->repo->insert(['started_at' => $now, 'id_employee' => $context->employeeId, 'id_device' => $context->deviceId]);
            // Stan ERP d'avant le obchód : gardé du premier zapis de ce obchód, sinon calculé maintenant.
            $before = [];
            foreach ($this->repo->visitLines($visitId) as $l) $before[(int) $l['id_raw_material']] ??= $l['erp_qty'] !== null ? (float) $l['erp_qty'] : null;
            $levels = $this->supply->stockLevels(array_keys($clean));
            $lots = $this->repo->currentLots(array_keys($clean));
            $diffs = 0;
            $rows = [];
            foreach ($clean as $mid => $c) {
                $erp = array_key_exists($mid, $before) ? $before[$mid] : (isset($levels[$mid]) ? (float) $levels[$mid]['stock'] : null);
                if ($erp !== null && abs($c['quantity'] - $erp) > self::EPS) $diffs++;
                $rows[$mid] = ['id_raw_material' => $mid, 'erp_qty' => $erp !== null ? round($erp, 3) : null, 'counted_qty' => $c['quantity'], 'date_state' => $c['date_state'],
                    'loss_kind' => $c['loss']['kind'] ?? null, 'loss_qty' => $c['loss']['quantity'] ?? null, 'loss_reason' => $c['loss']['reason'] ?? null,
                    'lot_number' => $lots[$mid]['lot_number'] ?? null, 'best_before' => $lots[$mid]['best_before'] ?? null];
            }
            $vpId = $this->repo->savePlace($visitId, $placeId, ['place_name' => mb_substr((string) $p['name'], 0, 160), 'saved_at' => $now, 'id_employee' => $context->employeeId,
                'id_device' => $context->deviceId, 'clean' => $isClean, 'id_photo' => $photoId, 'note' => $note, 'materials' => count($clean), 'differences' => $diffs]);
            foreach ($rows as $r) $this->repo->insertLine(['id_visit_place' => $vpId] + $r);
            $counts = $this->writeCounts($visitId, array_keys($clean), substr($now, 0, 10), $me['name']);
            $this->audit->record($context, 'cold_visit_place', 'cold_place', $placeId, null, ['visit' => $visitId, 'materials' => count($clean), 'differences' => $diffs,
                'clean' => $isClean, 'photo' => $photoId, 'counts' => $counts]);
            $this->closeIfComplete($visitId, $now);
        });
        return $this->home($context, $now);
    }

    /**
     * Inventaire de Zaopatrzenie (SupplyRepository::addCount) pour les surowce de cette miejsce dont toutes les miejsca sont
     * zapisane dans ce obchód : quantité = Σ (policzone − strata). @return array<int, int> surowiec → n_mp_count.id
     */
    private function writeCounts(int $visitId, array $materialIds, string $day, string $who): array
    {
        $where = $this->repo->materialPlaces($materialIds);
        $saved = $this->repo->visitPlaces($visitId);
        $lines = [];
        foreach ($this->repo->visitLines($visitId) as $l) $lines[(int) $l['id_raw_material']][] = $l;
        $out = [];
        foreach ($materialIds as $mid) {
            $places = $where[$mid] ?? [];
            if (array_diff($places, array_keys($saved))) continue;   // une autre miejsce du surowiec reste à zapisać
            $mine = $lines[$mid] ?? [];
            if (!$mine) continue;
            $qty = round(max(0.0, array_sum(array_map(fn($l) => (float) $l['counted_qty'] - (float) ($l['loss_qty'] ?? 0), $mine))), 3);
            $loss = array_sum(array_map(fn($l) => (float) ($l['loss_qty'] ?? 0), $mine));
            $note = mb_substr('Obchód chłodni #' . $visitId . ' : ' . implode(', ', array_unique(array_map(fn($l) => (string) $l['place_name'], $mine))) . ' — ' . $who
                . ($loss > self::EPS ? ' (strata ' . self::qtyText($loss, $mine[0]['unit']) . ' odjęta)' : ''), 0, 255);
            $countId = $this->supplyRepo->addCount($mid, $day, $qty, $note, null);
            $this->repo->setLineCount(array_map(fn($l) => (int) $l['id'], $mine), $qty, $countId);
            $out[$mid] = $countId;
        }
        return $out;
    }

    /** Totaux du obchód ; complet quand toutes les miejsca actives sont zapisane. */
    private function closeIfComplete(int $visitId, string $now): void
    {
        $saved = $this->repo->visitPlaces($visitId);
        $active = array_map(fn($p) => (int) $p['id'], $this->places());
        $agg = $this->aggregate($this->repo->visitLines($visitId));
        $data = ['places' => count($saved), 'materials' => count($agg), 'differences' => count(array_filter($agg, fn($a) => $a['difference'] !== null && abs($a['difference']) > self::EPS))];
        if ($active && !array_diff($active, array_keys($saved))) $data['completed_at'] = $now;
        $this->repo->updateVisit($visitId, $data);
    }

    // --- C. ERP : Zakupy tygodnia --------------------------------------------------------------------------------------

    /**
     * Krok « Obchód chłodni » : dernier obchód complet, valide ou non, obchód en cours, różnice ; blocked = « Co kupić » fermé.
     * null si la migration 255 n'est pas passée (rien à afficher, rien de bloqué).
     */
    public function summary(string $lang = 'pl', ?string $now = null): ?array
    {
        if (!$this->repo->ready()) return null;
        $now ??= date('Y-m-d H:i:s');
        $hours = $this->validHours();
        $places = $this->places();
        $last = $this->repo->lastComplete();
        $validUntil = $last !== null ? date('Y-m-d H:i:s', strtotime((string) $last['completed_at']) + $hours * 3600) : null;
        $valid = $last !== null && $validUntil > $now;
        $open = $this->repo->openVisit(date('Y-m-d H:i:s', strtotime($now) - $hours * 3600));
        $lines = $last !== null ? $this->aggregate($this->repo->visitLines((int) $last['id']), $lang) : [];
        $shown = array_values(array_filter($lines, fn($a) => ($a['difference'] !== null && abs($a['difference']) > self::EPS) || $a['short'] || $a['losses']));
        $lastPlaces = $last !== null ? $this->repo->visitPlaces((int) $last['id']) : [];
        return [
            'valid_hours' => $hours, 'valid' => $valid, 'blocked' => $places !== [] && !$valid, 'no_places' => $places === [],
            'last' => $last !== null ? $this->visitInfo($last, 'complete', $now) + ['valid_until' => $validUntil,
                'days_ago' => (int) floor((strtotime(substr($now, 0, 10)) - strtotime(substr((string) $last['completed_at'], 0, 10))) / 86400),
                'dirty' => array_values(array_map(fn($s) => ['place' => (string) $s['place_name'], 'photo_id' => $s['id_photo'] !== null ? (int) $s['id_photo'] : null, 'note' => $s['note']],
                    array_filter($lastPlaces, fn($s) => $s['clean'] === 'no')))] : null,
            'open' => $open !== null ? ['started_at' => (string) $open['started_at'], 'by' => trim((string) $open['employee_name']) ?: null,
                'saved' => count($this->repo->visitPlaces((int) $open['id'])), 'places' => count($places)] : null,
            'lines' => $shown, 'unchanged' => count($lines) - count($shown),
            'places' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'kind' => $p['kind'], 'source' => $p['id_equipment'] !== null ? 'equipment' : 'extra',
                'materials' => (int) $p['materials']], $places),
        ];
    }

    /** « Co kupić » fermé : des miejsca à obejść et pas de obchód complet dans la fenêtre. */
    public function blocked(?string $now = null): bool
    {
        $s = $this->summary('pl', $now);
        return $s !== null && $s['blocked'];
    }

    /** ERP : miejsce sans urządzenie (Magazyn suchy…). */
    public function addPlace(array $body, ApiContext $context): array
    {
        if (!$this->repo->ready()) throw new ApiException('COLD_VISIT_NOT_READY', 409);
        $v = (new Validator($body))->only(['name', 'kind'])->string('name', 160, true)->choice('kind', self::PLACE_KINDS, false);
        $name = trim((string) ($body['name'] ?? ''));
        if (!$v->hasError('name') && mb_strlen($name) < 2) $v->add('name', 'REQUIRED');
        $v->throwIfInvalid();
        foreach ($this->places() as $p) {
            if (mb_strtolower((string) $p['name']) === mb_strtolower($name)) throw ApiException::validation([['field' => 'name', 'code' => 'DUPLICATE_IN_LIST', 'params' => []]]);
        }
        $this->tx->execute(function () use ($name, $v, $context) {
            $id = $this->repo->addPlace($name, (string) ($v->value('kind') ?? 'dry'), $context->userId);
            $this->audit->record($context, 'create', 'cold_place', $id, null, ['name' => $name, 'kind' => $v->value('kind') ?? 'dry']);
        });
        return $this->summary($context->translator->lang()) ?? [];
    }

    /** ERP : retirer une miejsce ajoutée (une chłodnia du rejestr se retire dans « Serwisy i przeglądy »). */
    public function removePlace(int $id, ApiContext $context): array
    {
        $p = $this->repo->ready() ? $this->repo->placeRow($id) : null;
        if ($p === null || !(int) $p['active']) throw ApiException::notFound('cold_place', $id);
        if ($p['id_equipment'] !== null) throw new ApiException('COLD_PLACE_EQUIPMENT', 409);
        $this->tx->execute(function () use ($id, $p, $context) {
            $this->repo->deactivatePlace($id);
            $this->audit->record($context, 'deactivate', 'cold_place', $id, ['name' => $p['name']], null);
        });
        return $this->summary($context->translator->lang()) ?? [];
    }

    // --- Aides ----------------------------------------------------------------------------------------------------------

    private function places(): array
    {
        if (!$this->repo->ready()) return [];
        $this->repo->syncEquipment();
        return $this->repo->places();
    }

    private function activePlace(int $id): array
    {
        if (!$this->repo->ready()) throw ApiException::notFound('cold_place', $id);
        $this->repo->syncEquipment();
        return $this->repo->place($id) ?? throw ApiException::notFound('cold_place', $id);
    }

    /** Obchód en cours (moins de N heures), sinon le dernier complet encore valide. @return ?array{visit:array, state:string} */
    private function current(string $now): ?array
    {
        $since = date('Y-m-d H:i:s', strtotime($now) - $this->validHours() * 3600);
        $open = $this->repo->openVisit($since);
        if ($open !== null) return ['visit' => $open, 'state' => 'open'];
        $last = $this->repo->lastComplete();
        return $last !== null && (string) $last['completed_at'] >= $since ? ['visit' => $last, 'state' => 'complete'] : null;
    }

    private function visitInfo(array $v, string $state, string $now): array
    {
        return ['id' => (int) $v['id'], 'state' => $state, 'started_at' => (string) $v['started_at'], 'completed_at' => $v['completed_at'] !== null ? (string) $v['completed_at'] : null,
            'by' => trim((string) ($v['employee_name'] ?? '')) ?: null, 'places' => (int) $v['places'], 'materials' => (int) $v['materials'], 'differences' => (int) $v['differences'],
            'valid_until' => $v['completed_at'] !== null ? date('Y-m-d H:i:s', strtotime((string) $v['completed_at']) + $this->validHours() * 3600) : null];
    }

    /**
     * Lignes d'un obchód par surowiec (toutes ses miejsca) : ERP d'avant, policzone, différence, krótka data, pertes,
     * inventaire écrit. Quantités en jednostka bazowa, unit / factor = jednostka wyświetlana.
     */
    private function aggregate(array $lines, string $lang = 'pl'): array
    {
        $by = [];
        foreach ($lines as $l) {
            $mid = (int) $l['id_raw_material'];
            $unit = CostOptimizationService::displayUnit($l['unit']);
            $a = $by[$mid] ??= ['material_id' => $mid, 'name' => $lang === 'fr' && trim((string) $l['name_fr']) !== '' ? (string) $l['name_fr'] : (string) $l['name'],
                'unit' => $unit['label'], 'factor' => $unit['factor'], 'places' => [], 'erp' => null, 'counted' => 0.0, 'difference' => null, 'short' => null, 'losses' => [], 'stock_after' => null];
            $a['places'][] = (string) $l['place_name'];
            if ($a['erp'] === null && $l['erp_qty'] !== null) $a['erp'] = (float) $l['erp_qty'];
            $a['counted'] = round($a['counted'] + (float) $l['counted_qty'], 3);
            if ($l['date_state'] === 'short') $a['short'] = ['best_before' => $l['best_before'], 'lot_number' => $l['lot_number']];
            if ($l['loss_kind'] !== null) $a['losses'][] = ['kind' => $l['loss_kind'], 'quantity' => (float) $l['loss_qty'], 'reason' => $l['loss_reason'], 'place' => (string) $l['place_name']];
            if ($l['count_qty'] !== null) $a['stock_after'] = (float) $l['count_qty'];
            $by[$mid] = $a;
        }
        foreach ($by as &$a) {
            $a['places'] = array_values(array_unique($a['places']));
            sort($a['places']);
            $a['difference'] = $a['erp'] !== null ? round($a['counted'] - $a['erp'], 3) : null;
        }
        unset($a);
        $rows = array_values($by);
        usort($rows, fn($x, $y) => [$x['places'][0], $x['name']] <=> [$y['places'][0], $y['name']]);
        return $rows;
    }

    private static function qtyText(float $base, ?string $unit): string
    {
        $u = CostOptimizationService::displayUnit($unit);
        return rtrim(rtrim(number_format($base / $u['factor'], 3, ',', ''), '0'), ',') . ' ' . $u['label'];
    }

    private static function hm(string $dt): string
    {
        return $dt !== '' ? date('G:i', strtotime($dt)) : '';
    }
}
