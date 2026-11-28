<?php
declare(strict_types=1);

namespace App\Services\Workshop\Quality;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Organization\TeamTasksRepository;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\LotsQcDayRepository;
use App\Repositories\Workshop\OutputRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\QualityRepository;
use App\Repositories\Workshop\QualityRoundRepository;
use App\Services\Organization\Wizards\LotsQcDayService;
use App\Services\Purchasing\NcService;
use App\Services\Workshop\Execution\OutputService;
use App\Services\Workshop\Production\SampleService;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * [415] Tablet jakości Kierownika produkcji (makieta hali I « obchód jakości », J « kontrola partii »).
 *
 * Accès : une tablette appairée (comme la tablette d'emballage), puis la personne au poste KIEROWNIK_PRODUKCJI ou JAKOSC
 * s'identifie par son PIN (POST /kiosk/identify) ; toute autre personne : 403 QC_ROUND_POSITION.
 *
 * I. Le tour : parties du jour de toutes les grupy (n_prod_output). Ordre : « Pilne » (signal d'une tablette de groupe non
 *    encore contrôlé : non-conformité au contrôle du groupe, non-conformité signalée par le groupe sur ce produit), puis
 *    par heure de démarrage — la première partie à contrôler est « Teraz », les suivantes « Potem » —, puis les contrôlées
 *    (« Dobrze », « Uwaga » sans NC, « Niezgodność »). Ce qui reste à voir : poids + aspect, sacs sans scan (même calcul
 *    que l'assistant « Partie i kontrola dnia » : LotsQcDayService::batch), échantillon à faire (« Próbki produkcji »).
 *    [420] Température : refroidissement hors limite saisi sur la tablette de hala (n_hala_chill) — même partie, sinon même
 *    groupe et même article le même jour.
 * J. Une partie : poids de N pièces (qc_round.pieces ; cible = poids net d'une pièce de la fiche, final_product.weight ;
 *    tolérance qc_round.weight_tolerance_pct sur la moyenne, chaque pièce marquée lekka / ciężka), aspect bon / mauvais +
 *    photo (n_prod_lot_photo), sacs de la partie (OutputService::batchFor, les scans de la tablette du groupe) : « Zeskanuj
 *    sam » = OutputService::setLot au nom du groupe de la partie, « Wyślij grupie » = message sur la tablette du groupe
 *    (rappels n_task_reminder, audience group:N, lu au PIN ou fermé quand les sacs sont complets) ; échantillon :
 *    SampleService (create, store — scan de l'étiquette QR). « Zapisz kontrolę » : n_qc_control + le contrôle de la partie
 *    par le mécanisme existant (QualityRepository::setQc → comble « brak kontroli jakości » de « Partie i kontrola dnia »).
 *    Poids moyen hors tolérance ou aspect mauvais : une non-conformité est PROPOSÉE — créée (NcService, source production,
 *    groupe de la partie) seulement si la personne l'accepte, sinon le contrôle est « warn » (écart accepté, note).
 * Aucun e-mail, SMS ni appel externe ; n_wizard_done n'est jamais écrit ici.
 */
final class QualityRoundService
{
    /** Postes autorisés sur la tablette qualité (n_position.code). */
    public const POSITIONS = ['KIEROWNIK_PRODUKCJI', 'JAKOSC'];
    public const APPEARANCE = ['good', 'bad'];
    private const RANK = ['urgent' => 0, 'now' => 1, 'later' => 2, 'nc' => 3, 'warn' => 3, 'ok' => 4];

    public function __construct(
        private QualityRoundRepository $repo,
        private LotsQcDayRepository $lotsRepo,
        private LotsQcDayService $lotsQc,
        private OutputService $outputs,
        private OutputRepository $outputRepo,
        private SampleService $samples,
        private QualityRepository $quality,
        private NcService $nc,
        private ExecutionRepository $execution,
        private TeamTasksRepository $team,
        private ParamRepository $params,
        private LotHoldService $holds,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    // --- Qui : personnes aux postes Kierownik produkcji / Jakość ----------------------------------------------------

    /** Personnes proposées à l'identification (poste, PIN posé, présence). */
    public function people(): array
    {
        $out = [];
        foreach (self::POSITIONS as $code) {
            foreach ($this->execution->people(null, $code) as $p) {
                $out[(int) $p['id']] ??= ['id' => (int) $p['id'], 'name' => $p['name'], 'position' => $code, 'has_pin' => (bool) $p['has_pin'],
                    'clocked_in' => $p['clocked_in_at'] !== null];
            }
        }
        return array_values($out);
    }

    /** La personne identifiée sur la tablette, à un poste autorisé. @return array{id:int, name:string, position:string} */
    public function assertManager(ApiContext $context): array
    {
        if ($context->employeeId === null) throw new ApiException('EMPLOYEE_UNAUTHENTICATED', 401);
        $code = $this->repo->positionCode($context->employeeId);
        if ($code === null || !in_array($code, self::POSITIONS, true)) throw new ApiException('QC_ROUND_POSITION', 403);
        $e = $this->execution->employee($context->employeeId);
        return ['id' => $context->employeeId, 'name' => (string) ($e['name'] ?? ''), 'position' => $code];
    }

    // --- I. Le tour ---------------------------------------------------------------------------------------------------

    public function round(ApiContext $context, ?string $now = null): array
    {
        $me = $this->assertManager($context);
        $now ??= date('Y-m-d H:i:s');
        $day = substr($now, 0, 10);
        $fr = $context->translator->lang() === 'fr';
        $outs = $this->repo->dayOutputs($day);
        $ids = array_map(fn($o) => (int) $o['id'], $outs);
        $lots = $this->lotsRepo->lots($ids);
        $comps = $this->lotsRepo->components($ids);
        $controls = $this->repo->lastControls($ids);
        $samples = $this->sampleRows($day);
        $reports = $this->repo->groupReports($day);
        $chills = $this->repo->chillAlerts($day, $now);
        $holds = $this->holds->forOutputs($ids);
        $requests = $this->repo->requestCounts($ids);

        $rows = [];
        foreach ($outs as $o) {
            $id = (int) $o['id'];
            $gaps = $this->lotsQc->batch($o, $lots[$id] ?? [], $comps[$id] ?? [])['gaps'];
            $missing = count(array_filter($gaps, fn($g) => $g['code'] === 'lot_missing'));
            if ($missing === 0 && !empty($requests[$id])) $this->closeRequests($id);
            $c = $controls[$id] ?? null;
            $signal = $this->signal($o, $reports, $c, $chills, $fr);
            $urgent = $signal !== null && ($c === null || (string) $c['created_at'] < (string) $signal['at']);
            $sample = $this->sampleState($samples[$id] ?? null);
            $status = $urgent ? 'urgent' : ($c !== null ? (string) $c['result'] : 'todo');
            $rows[] = [
                'id' => $id, 'group' => $fr && trim((string) $o['group_name_fr']) !== '' ? $o['group_name_fr'] : (string) $o['group_name'],
                'product' => (string) $o['item_name'], 'lot_code' => (string) $o['lot_code'], 'started_at' => self::hm((string) $o['created_at']),
                'created_at' => (string) $o['created_at'], 'status' => $status, 'signal' => $urgent ? $signal : null,
                'todo' => ['weight' => true, 'missing_lots' => $missing, 'lot_reasons' => count(array_filter($gaps, fn($g) => $g['code'] === 'lot_reason')),
                    'sample' => in_array($sample['state'], ['planned', 'suggested'], true), 'requested' => (int) ($requests[$id] ?? 0)],
                'checked' => $c !== null ? $this->controlRow($c) : null,
                'hold' => $holds[$id] ?? null,
            ];
        }
        // Teraz : la première partie pas encore contrôlée (dans l'ordre de démarrage) ; les autres : Potem.
        $first = true;
        foreach ($rows as &$r) {
            if ($r['status'] !== 'todo') continue;
            $r['status'] = $first ? 'now' : 'later';
            $first = false;
        }
        unset($r);
        usort($rows, fn($a, $b) => [self::RANK[$a['status']], $a['created_at'], $a['id']] <=> [self::RANK[$b['status']], $b['created_at'], $b['id']]);
        $next = null;
        foreach (['now', 'urgent'] as $st) foreach ($rows as $r) if ($next === null && $r['status'] === $st) $next = $r['id'];
        [$roundNo, $rounds] = $this->roundOf(substr($now, 11, 5));
        return [
            'date' => $day, 'now' => $now, 'employee' => $me,
            'round' => $rounds > 0 ? ['no' => $roundNo, 'of' => $rounds] : null,
            'to_check' => count(array_filter($rows, fn($r) => in_array($r['status'], ['urgent', 'now'], true))),
            'counts' => ['batches' => count($rows), 'checked' => count(array_filter($rows, fn($r) => $r['checked'] !== null)),
                'urgent' => count(array_filter($rows, fn($r) => $r['status'] === 'urgent'))],
            'next_id' => $next,
            'rows' => array_map(fn($r) => array_diff_key($r, ['created_at' => 1]), $rows),
        ];
    }

    /**
     * Signal d'une tablette de groupe sur la partie (le plus récent) : contrôle du groupe en non-conformité (hors celle de
     * notre contrôle), non-conformité signalée par le groupe sur ce produit aujourd'hui. Point d'entrée des futurs signaux
     * (température de la tablette du groupe).
     */
    private function signal(array $o, array $reports, ?array $c, array $chills = [], bool $fr = false): ?array
    {
        $s = null;
        if ($o['qc_status'] === 'nc' && $o['qc_at'] !== null && ($c === null || (int) ($c['id_nc'] ?? 0) !== (int) ($o['id_nc'] ?? 0) || $c['id_nc'] === null)) {
            $s = ['kind' => 'group_nc', 'at' => (string) $o['qc_at'], 'text' => trim((string) $o['qc_note']) ?: null];
        }
        foreach ($reports as $r) {
            if ((int) $r['id_prod_group'] !== (int) $o['id_group'] || $o['id_final_product'] === null || (int) $r['id_final_product'] !== (int) $o['id_final_product']) continue;
            if ($s === null || (string) $r['created_at'] > $s['at']) {
                $s = ['kind' => 'group_report', 'at' => (string) $r['created_at'], 'text' => mb_substr(trim((string) $r['description']), 0, 140), 'number' => $r['number']];
            }
        }
        foreach ($chills as $h) {
            $same = $h['id_output'] !== null ? (int) $h['id_output'] === (int) $o['id'] : ((int) $h['id_group'] === (int) $o['id_group'] && (int) $h['id_item'] === (int) $o['id_item']);
            if (!$same || ($s !== null && (string) $h['at'] <= $s['at'])) continue;
            $deg = fn($v) => str_replace('.', ',', (string) (float) $v) . ' °C';
            $text = $h['temp_c'] !== null ? ($fr ? 'Refroidissement : ' : 'Schładzanie : ') . $deg($h['temp_c']) . ' (limit ' . $deg($h['target_c']) . ')'
                : ($fr ? 'Refroidissement : pas de mesure après ' : 'Schładzanie : brak pomiaru po ') . (int) $h['max_minutes'] . ' min';
            $s = ['kind' => 'chill', 'at' => (string) $h['at'], 'text' => $text];
        }
        return $s;
    }

    /** « obchód 2 z 3 » : heures qc_round.times déjà passées (au moins la première). @return array{0:int, 1:int} */
    private function roundOf(string $hm): array
    {
        $times = [];
        foreach (explode(',', $this->params->get('qc_round.times', '08:00,10:30,13:00')) as $t) {
            $t = trim($t);
            if (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t)) $times[] = str_pad($t, 5, '0', STR_PAD_LEFT);
        }
        sort($times);
        if (!$times) return [0, 0];
        return [max(1, count(array_filter($times, fn($t) => $t <= $hm))), count($times)];
    }

    // --- J. Une partie ------------------------------------------------------------------------------------------------

    public function batch(int $id, ApiContext $context): array
    {
        $me = $this->assertManager($context);
        $o = $this->output($id);
        $fr = $context->translator->lang() === 'fr';
        $b = $this->outputs->batchFor($id, $context->translator->lang()) ?? throw ApiException::notFound('output', $id);
        $registry = $this->repo->registryLots(array_filter(array_map(fn($i) => $i['material_lot_id'], $b['ingredients'])));
        $lots = array_map(function ($i) use ($registry) {
            $state = !$i['tracked'] ? 'untracked' : (($i['lot_number'] ?? '') !== '' ? 'ok' : ($i['missing_reason'] !== null ? 'reason' : 'missing'));
            $reg = $i['material_lot_id'] !== null ? ($registry[$i['material_lot_id']] ?? null) : null;
            return ['material_id' => $i['material_id'], 'name' => $i['name'], 'packaging' => $i['packaging'], 'state' => $state, 'lot_number' => $i['lot_number'],
                'best_before' => $i['best_before'], 'missing_reason' => $i['missing_reason'], 'supplier' => $reg['supplier'] ?? null, 'received_on' => $reg['received_on'] ?? null,
                'choices' => array_map(fn($r) => ['id' => $r['id'], 'lot_number' => $r['lot_number'], 'best_before' => $r['best_before'], 'supplier' => $r['supplier'],
                    'received_on' => $r['received_on']], $i['received'])];
        }, $b['ingredients']);
        // Sacs d'abord, emballages ensuite ; les non suivis (eau…) à la fin.
        usort($lots, fn($a, $c) => [$a['state'] === 'untracked', $a['packaging']] <=> [$c['state'] === 'untracked', $c['packaging']]);
        $components = array_map(fn($c) => ['recipe_id' => $c['recipe_id'], 'name' => $c['name'], 'producer' => $c['producer']['name'],
            'state' => $c['source'] !== null ? 'ok' : 'missing', 'lot_code' => $c['source']['lot_code'] ?? null], $b['components']);
        $missing = count(array_filter($lots, fn($l) => $l['state'] === 'missing')) + count(array_filter($components, fn($c) => $c['state'] === 'missing'));
        $requests = $this->repo->openRequests($id);
        if ($missing === 0 && $requests) { $this->closeRequests($id); $requests = []; }
        $last = $this->repo->lastControls([$id])[$id] ?? null;
        return [
            'id' => $id, 'employee' => $me, 'product' => (string) $o['item_name'], 'lot_code' => (string) $o['lot_code'],
            // [431] Karta produktu sur la tablette (même assistant que l'ERP, en mode tablette).
            'item_id' => (int) $o['id_item'], 'product_id' => $o['id_final_product'] !== null ? (int) $o['id_final_product'] : null,
            'group' => ['id' => (int) $o['id_group'], 'name' => $fr && trim((string) $o['group_name_fr']) !== '' ? $o['group_name_fr'] : (string) $o['group_name']],
            'work_date' => substr((string) $o['work_date'], 0, 10), 'started_at' => self::hm((string) $o['created_at']),
            'quantity' => (float) $o['quantity'], 'unit' => $o['base_unit'], 'piece_weight' => $o['piece_weight'] !== null && (float) $o['piece_weight'] > 0 ? (float) $o['piece_weight'] : null,
            'weight' => $this->spec($o),
            'lots' => $lots, 'components' => $components, 'missing_lots' => $missing,
            'requested' => $requests ? ['at' => self::hm((string) $requests[0]['sent_at']), 'count' => count($requests)] : null,
            'sample' => $this->sampleState($this->sampleRows(substr((string) $o['work_date'], 0, 10))[$id] ?? null),
            'group_qc' => $o['qc_status'] !== null ? ['status' => $o['qc_status'], 'at' => self::hm((string) $o['qc_at']), 'note' => $o['qc_note']] : null,
            'last_control' => $last !== null ? $this->controlRow($last) : null,
            'hold' => $this->holds->forOutput($id),
            'severities' => NcService::SEVERITIES,
        ];
    }

    /** Poids : pièces à peser, cible de la fiche, tolérance, bornes « dobrze ». */
    public function spec(array $o): array
    {
        $pieces = max(1, min(10, (int) $this->params->get('qc_round.pieces', '3')));
        $tol = (float) str_replace(',', '.', $this->params->get('qc_round.weight_tolerance_pct', '2'));
        if ($tol < 0 || $tol > 50) $tol = 2.0;
        $target = ($o['item_type'] ?? null) === 'product' && $o['piece_weight'] !== null && (float) $o['piece_weight'] > 0 ? round((float) $o['piece_weight'], 1) : null;
        return ['pieces' => $pieces, 'target_g' => $target, 'tolerance_pct' => $tol,
            'min_g' => $target !== null ? round($target * (1 - $tol / 100), 1) : null, 'max_g' => $target !== null ? round($target * (1 + $tol / 100), 1) : null];
    }

    /**
     * Verdict du poids : chaque pièce (lekka / ciężka hors bornes), moyenne dans les bornes = ok, sinon light / heavy ;
     * sans cible sur la fiche : none. @return array{result:string, average:?float, pieces:list<?string>}
     */
    public static function weigh(array $spec, array $weights): array
    {
        $w = array_map('floatval', $weights);
        $avg = $w ? round(array_sum($w) / count($w), 1) : null;
        if ($spec['target_g'] === null || $avg === null) return ['result' => 'none', 'average' => $avg, 'pieces' => array_fill(0, count($w), null)];
        $one = fn(float $x) => $x < $spec['min_g'] ? 'light' : ($x > $spec['max_g'] ? 'heavy' : 'ok');
        return ['result' => $one($avg), 'average' => $avg, 'pieces' => array_map($one, $w)];
    }

    /**
     * « Zapisz kontrolę » : poids, aspect, photo, note ; écart → décision sur la non-conformité proposée (nc.create).
     * Sacs sans scan : à scanner ou envoyés au groupe avant d'enregistrer (409 QC_ROUND_LOTS_OPEN).
     */
    public function saveControl(int $id, array $body, ApiContext $context): array
    {
        $me = $this->assertManager($context);
        $o = $this->output($id);
        $spec = $this->spec($o);
        $v = (new Validator($body))->only(['weights', 'appearance', 'photo_id', 'note', 'nc'])
            ->choice('appearance', self::APPEARANCE, true)->integer('photo_id', false, 1, null, true)->string('note', 255, false, true);
        $weights = $body['weights'] ?? null;
        if (!is_array($weights) || !array_is_list($weights) || count($weights) < 1 || count($weights) > 10
            || array_filter($weights, fn($x) => !is_int($x) && !is_float($x) || $x <= 0 || $x > 100000)) $v->add('weights', 'INVALID_LIST');
        $nc = $body['nc'] ?? null;
        if ($nc !== null && (!is_array($nc) || !is_bool($nc['create'] ?? null))) $v->add('nc', 'INVALID_CHOICE', ['field' => 'nc', 'allowed' => 'create: true|false']);
        $v->throwIfInvalid();
        $photoId = $v->value('photo_id') !== null ? (int) $v->value('photo_id') : null;
        if ($photoId !== null && $this->outputRepo->photo($photoId) === null) throw ApiException::validation([['field' => 'photo_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => ['id' => $photoId]]]);
        $note = $v->value('note') !== null && trim((string) $v->value('note')) !== '' ? trim((string) $v->value('note')) : null;
        $w = self::weigh($spec, $weights);
        $appearance = (string) $v->value('appearance');
        $reasons = array_values(array_filter([$w['result'] === 'light' ? 'weight_light' : null, $w['result'] === 'heavy' ? 'weight_heavy' : null, $appearance === 'bad' ? 'appearance_bad' : null]));
        if ($reasons && $nc === null) throw new ApiException('QC_ROUND_NC_DECISION', 409, [], ['reasons' => implode(', ', $reasons)]);
        $createNc = $reasons && $nc['create'] === true;
        $ncText = $createNc ? trim((string) ($nc['description'] ?? '')) : '';
        $severity = $createNc ? (string) ($nc['severity'] ?? 'minor') : 'minor';
        if ($createNc && mb_strlen($ncText) < 3) throw ApiException::validation([['field' => 'nc.description', 'code' => 'REQUIRED', 'params' => []]]);
        if ($createNc && !in_array($severity, NcService::SEVERITIES, true)) throw ApiException::validation([['field' => 'nc.severity', 'code' => 'INVALID_CHOICE', 'params' => ['field' => 'severity', 'allowed' => implode(', ', NcService::SEVERITIES)]]]);

        $b = $this->batch($id, $context);
        $requested = $b['requested'] !== null;
        if ($b['missing_lots'] > 0 && !$requested) throw new ApiException('QC_ROUND_LOTS_OPEN', 409, [], ['count' => $b['missing_lots']]);
        $sample = $b['sample'];
        [$roundNo] = $this->roundOf(date('H:i'));
        $result = !$reasons ? 'ok' : ($createNc ? 'nc' : 'warn');
        $qcNote = mb_substr('Kontrola ' . $me['name'] . ' : ' . ($w['average'] !== null ? 'waga śr. ' . self::g($w['average']) . ' g'
            . ($spec['target_g'] !== null ? ' (' . self::g($spec['min_g']) . '–' . self::g($spec['max_g']) . ')' : '') . ', ' : '')
            . 'wygląd ' . ($appearance === 'good' ? 'dobry' : 'zły') . ($note !== null ? ' — ' . $note : ''), 0, 255);

        $controlId = $this->tx->execute(function () use ($o, $id, $context, $roundNo, $weights, $spec, $w, $appearance, $photoId, $b, $requested, $sample, $result, $createNc,
                                                         $ncText, $severity, $note, $qcNote, $me) {
            $ncId = null;
            if ($createNc) {
                $ncId = $this->nc->save(array_filter([
                    'source' => 'production', 'product_id' => $o['id_final_product'] !== null ? (int) $o['id_final_product'] : null,
                    'description' => $o['lot_code'] . ' · ' . $ncText . ' — kontrola ' . $me['name'] . ', tablet jakości',
                    'severity' => $severity, 'quantity' => (float) $o['quantity'], 'unit' => $o['base_unit'],
                ], fn($x) => $x !== null), null, null);
                $this->quality->setNcGroup($ncId, (int) $o['id_group']);
            }
            $controlId = $this->repo->insert([
                'id_output' => $id, 'work_date' => substr((string) $o['work_date'], 0, 10), 'round_no' => $roundNo ?: null, 'id_employee' => $context->employeeId,
                'id_device' => $context->deviceId, 'weights' => implode(',', array_map(fn($x) => self::g((float) $x), $weights)), 'target_g' => $spec['target_g'],
                'tolerance_pct' => $spec['target_g'] !== null ? $spec['tolerance_pct'] : null, 'weight_result' => $w['result'], 'appearance' => $appearance,
                'id_photo' => $photoId, 'lots_total' => count(array_filter($b['lots'], fn($l) => $l['state'] !== 'untracked')) + count($b['components']),
                'lots_missing' => $b['missing_lots'], 'lots_requested' => $requested ? 1 : 0,
                'sample' => match ($sample['state']) { 'stored' => 'stored', 'planned', 'suggested' => 'planned', default => 'not_required' },
                'id_sample' => $sample['id'] ?? null, 'result' => $result, 'id_nc' => $ncId, 'note' => $note,
            ]);
            // Le contrôle de la partie, par le mécanisme de la tablette du groupe : une non-conformité déjà portée par la partie
            // (contrôle du groupe) reste la sienne.
            if ($o['qc_status'] !== 'nc') $this->quality->setQc($id, $result === 'nc' ? 'nc' : 'ok', $context->employeeId, $qcNote, $ncId);
            $this->audit->record($context, 'qc_round', 'output', $id, $o['qc_status'] ? ['qc' => $o['qc_status']] : null,
                ['control' => $controlId, 'result' => $result, 'weight' => $w['result'], 'appearance' => $appearance, 'nc' => $ncId]);
            return $controlId;
        });
        return ['control_id' => $controlId, 'result' => $result, 'round' => $this->round($context)];
    }

    /** « Zeskanuj sam » : le lot d'un sac de la partie, par le mécanisme de la tablette du groupe (au nom de son groupe). */
    public function scanLot(int $id, int $materialId, array $body, ApiContext $context): array
    {
        $this->assertManager($context);
        $o = $this->output($id);
        $this->outputs->setLot($id, $materialId, $body, $this->asGroup($context, (int) $o['id_group']));
        return $this->batch($id, $context);
    }

    /** « Wyślij grupie » : message sur la tablette du groupe (sacs sans scan), une fois tant qu'il n'est pas lu ou fermé. */
    public function requestLots(int $id, ApiContext $context): array
    {
        $me = $this->assertManager($context);
        $o = $this->output($id);
        $b = $this->batch($id, $context);
        $names = array_merge(array_column(array_filter($b['lots'], fn($l) => $l['state'] === 'missing'), 'name'),
            array_column(array_filter($b['components'], fn($c) => $c['state'] === 'missing'), 'name'));
        if (!$names) throw new ApiException('QC_ROUND_NO_MISSING_LOTS', 409);
        if ($b['requested'] !== null) return $b;
        $this->tx->execute(function () use ($id, $o, $names, $me, $context) {
            $rid = $this->team->insertReminder([
                'batch' => QualityRoundRepository::requestBatch($id), 'id_task' => null, 'id_occurrence' => null, 'due_date' => substr((string) $o['work_date'], 0, 10),
                'title' => mb_substr('Brak skanu worka — partia ' . $o['lot_code'], 0, 255),
                'message' => 'Kontrola jakości (' . $me['name'] . ') : zeskanujcie worek — ' . implode(', ', $names) . ' — partia ' . $o['lot_code'] . ' (' . $o['item_name'] . ').',
                'urgent' => 0, 'target_kind' => 'tablet', 'audience' => 'group:' . (int) $o['id_group'], 'target_label' => mb_substr((string) $o['group_name'], 0, 120),
                'channels' => 'tablet', 'sent_by' => null, 'sent_at' => date('Y-m-d H:i:s'),
            ]);
            $this->audit->record($context, 'request_lots', 'output', $id, null, ['reminder' => $rid, 'materials' => $names]);
        });
        return $this->batch($id, $context);
    }

    /**
     * Échantillon de la partie (« Próbki produkcji ») : create = l'échantillon proposé (ou voulu) ; store = étiquette QR scannée
     * (code de cet échantillon), échantillon posé.
     */
    public function sample(int $id, array $body, ApiContext $context): array
    {
        $this->assertManager($context);
        $o = $this->output($id);
        $v = (new Validator($body))->only(['action', 'code'])->choice('action', ['create', 'store'], true)->string('code', 255, false, true);
        $v->throwIfInvalid();
        try {
            if ($v->value('action') === 'create') {
                $this->samples->create(substr((string) $o['work_date'], 0, 10), [$id], null);
                $this->audit->record($context, 'sample_create', 'output', $id, null, null);
            } else {
                $code = trim((string) $v->value('code'));
                $s = $code !== '' ? $this->samples->byCode($code) : null;
                if ($s === null) throw ApiException::validation([['field' => 'code', 'code' => 'QC_ROUND_SAMPLE_UNKNOWN', 'params' => []]]);
                if ((int) $s['id_output'] !== $id) throw ApiException::validation([['field' => 'code', 'code' => 'QC_ROUND_SAMPLE_OTHER', 'params' => []]]);
                $this->samples->store($code, null);
                $this->audit->record($context, 'sample_store', 'output', $id, null, ['sample' => (int) $s['id']]);
            }
        } catch (CustomException $e) {
            throw new ApiException('QC_ROUND_REFUSED', $e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 422, [], ['message' => $e->getMessage()]);
        }
        return $this->batch($id, $context);
    }

    /** Photo de l'aspect (n_prod_lot_photo). */
    public function photo(array $body, ApiContext $context): array
    {
        $this->assertManager($context);
        return $this->outputs->storePhoto($body['photo'] ?? null, $context);
    }

    // --- Aides ----------------------------------------------------------------------------------------------------------

    /** Partie existante, non annulée. */
    public function output(int $id): array
    {
        $o = $this->repo->outputRow($id) ?? throw ApiException::notFound('output', $id);
        if ($o['cancelled_at'] !== null) throw new ApiException('OUTPUT_CANCELLED', 409);
        return $o;
    }

    /** Même tablette et même personne, au nom du groupe de la partie (mécanismes de la tablette du groupe). */
    private function asGroup(ApiContext $c, int $groupId): ApiContext
    {
        return new ApiContext($c->requestId, $c->translator, null, [], ['prod.kiosk' => true], [], deviceId: $c->deviceId, employeeId: $c->employeeId, deviceGroupId: $groupId);
    }

    private function closeRequests(int $outputId): void
    {
        foreach ($this->repo->openRequests($outputId) as $r) $this->team->close((int) $r['id']);
    }

    /** @return array<int, array> partie => ligne de l'assistant « Próbki produkcji » du jour */
    private function sampleRows(string $day): array
    {
        try {
            return array_column($this->samples->page($day)['rows'], null, 'output_id');
        } catch (CustomException) {
            return [];
        }
    }

    /** stored (posé) · planned (créé, étiquette à scanner) · suggested (règle de la catégorie, pas encore créé) · not_required. */
    private function sampleState(?array $row): array
    {
        if ($row === null) return ['state' => 'not_required', 'id' => null, 'code' => null, 'frequency' => null];
        if ($row['sample'] !== null) {
            return ['state' => $row['sample']['status'] === 'stored' ? 'stored' : 'planned', 'id' => $row['sample']['id'], 'code' => $row['sample']['code'], 'frequency' => $row['frequency']];
        }
        return ['state' => $row['suggested'] ? 'suggested' : 'not_required', 'id' => null, 'code' => null, 'frequency' => $row['frequency']];
    }

    private function controlRow(array $c): array
    {
        $items = [];
        if ($c['weight_result'] !== 'none' || trim((string) $c['weights']) !== '') $items[] = 'weight';
        $items[] = 'appearance';
        if ((int) $c['lots_total'] > 0 && (int) $c['lots_missing'] === 0) $items[] = 'lots';
        if ($c['sample'] === 'stored') $items[] = 'sample';
        return ['id' => (int) $c['id'], 'at' => self::hm((string) $c['created_at']), 'by' => trim((string) ($c['employee_name'] ?? '')) ?: null, 'result' => $c['result'],
            'weight_result' => $c['weight_result'], 'appearance' => $c['appearance'], 'weights' => $c['weights'] !== null && $c['weights'] !== '' ? array_map('floatval', explode(',', $c['weights'])) : [],
            'lots_missing' => (int) $c['lots_missing'], 'lots_requested' => (bool) $c['lots_requested'], 'sample' => $c['sample'], 'nc_id' => $c['id_nc'] !== null ? (int) $c['id_nc'] : null,
            'photo' => $c['id_photo'] !== null, 'note' => $c['note'], 'items' => $items];
    }

    private static function hm(string $dt): string
    {
        return $dt !== '' ? date('G:i', strtotime($dt)) : '';
    }

    /** Grammes sans zéros inutiles (497, 496.5). */
    private static function g(float $x): string
    {
        return rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.');
    }
}
