<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\GroupOrderRepository;
use App\Repositories\Workshop\HalaRepository;
use App\Repositories\Workshop\OutputRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\LogisticsBoardService;
use App\Services\Logistics\LogisticsService;
use App\Services\Utils\DataUrlImage;
use App\Services\Workshop\Production\MaterialLotService;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Tablet hali « Mój dzień » [409–411] (makieta des asystenci stanowisk, ekrany A–H) : le chemin du jour d'un groupe, une
 * étape à la fois, au-dessus des données de production existantes — rien n'est recopié :
 *   - Co robię dziś : plan du jour de l'équipe (n_prod_week_slot, comme la liste « Produkcja »), compteur « zrobione » =
 *     quantité faite du jour (OutputService::setMade : une partie par produit et jour), pertes par POST /kiosk/waste ;
 *   - Skanuj worki : le code lu sur le sac est cherché dans le registre « Partie surowców » (n_material_lot) ; bon
 *     surowiec et date valable → lot enregistré dans la partie (OutputService::setLot, comme l'écran « Partia ») ; autre
 *     surowiec ou date dépassée → STOP (rien n'est enregistré) ; code inconnu → la personne choisit le surowiec (numéro
 *     ajouté au registre « do sprawdzenia », comme une saisie manuelle) ;
 *   - Schładzanie (groupes réglés pour) : sortie du four → minutnik, mesure au cœur → n_hala_chill, limites
 *     hala.chill_target_c / hala.chill_max_minutes ; trop chaud → wezwanie au brygadzista (motif « chill ») ;
 *   - Sprzątanie : strefy du groupe (n_hala_clean_zone), une ligne par strefa et jour ;
 *   - Pakuję : tablette d'emballage existante (GET /kiosk/pallets), résumé ici ;
 *   - Koniec dnia : validation du jour de production (OutputService::closeDay — tâche « Dzień grupy ») puis n_hala_day ;
 *   - Pomoc : wezwanie (n_hala_help) ; Zmiana : état des groupes, wezwania, « Zatwierdź zmianę » (n_hala_shift).
 * Étape terminée = « Dalej » confirmé aujourd'hui (n_hala_step) ET condition remplie (lots, mesures, strefy) : une étape
 * redevenue incomplète revient « Teraz ». Aucun e-mail, SMS ni appel extérieur.
 */
final class HalaService
{
    public const STEPS = ['make', 'bags', 'chill', 'clean', 'pack'];
    public const ZONE_ICONS = ['table', 'machine', 'floor', 'bin', 'oven', 'fridge', 'sink', 'tools', 'other'];
    public const HELP_REASONS = ['help', 'bag', 'chill'];
    public const HELP_STEPS = ['home', 'make', 'bags', 'chill', 'clean', 'pack', 'end', 'shift'];
    /** Réglage d'un groupe sans ligne n_hala_group (groupe d'emballage : voir settingsOf). */
    private const DEFAULTS = ['guided' => true, 'make' => true, 'bags' => true, 'chill' => false, 'clean' => true, 'pack' => false];

    public function __construct(
        private HalaRepository $repo,
        private OutputService $outputs,
        private OutputRepository $outputRepo,
        private GroupOrderRepository $groupOrders,
        private CompositionCut $cut,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
        private LogisticsBoardService $board,
        private LogisticsService $logistics,
    ) {
    }

    // --- Réglages ----------------------------------------------------------------------------------------------------------

    /** Code du groupe d'emballage (même paramètre que la tablette d'emballage). */
    public function packingCode(): string
    {
        return (string) $this->params->get('logistics.packing_group', 'PAKOWANIE');
    }

    /** Réglage d'un groupe : ligne enregistrée, sinon défaut (groupe d'emballage : Sprzątanie → Pakuję). */
    public function settingsOf(int $groupId, ?array $saved = null, ?string $code = null): array
    {
        $saved ??= $this->repo->settings()[$groupId] ?? null;
        if ($saved !== null) {
            return ['guided' => (bool) (int) $saved['guided'], 'make' => (bool) (int) $saved['step_make'], 'bags' => (bool) (int) $saved['step_bags'],
                'chill' => (bool) (int) $saved['step_chill'], 'clean' => (bool) (int) $saved['step_clean'], 'pack' => (bool) (int) $saved['step_pack']];
        }
        $code ??= (string) ($this->repo->group($groupId)['code'] ?? '');
        return $code !== '' && $code === $this->packingCode() ? ['make' => false, 'bags' => false, 'pack' => true] + self::DEFAULTS : self::DEFAULTS;
    }

    /** Étapes du groupe dans l'ordre fixe (sans « Koniec dnia », toujours la dernière). @return list<string> */
    public static function stepsOf(array $settings): array
    {
        return array_values(array_filter(self::STEPS, fn($s) => !empty($settings[$s])));
    }

    public function chillLimits(): array
    {
        $t = (float) str_replace(',', '.', $this->params->get('hala.chill_target_c', '10'));
        $m = (int) $this->params->get('hala.chill_max_minutes', '120');
        return ['target_c' => $t, 'max_minutes' => $m > 0 ? $m : 120];
    }

    /** Postes qui voient « Zmiana » (codes n_position). @return list<string> */
    public function leadPositions(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->params->get('hala.shift_lead_positions', 'BRYGADZISTA,KIEROWNIK_PRODUKCJI')))));
    }

    public function pollSeconds(): int
    {
        return max(5, min(120, (int) $this->params->get('hala.help_poll_s', '15')));
    }

    // --- Mój dzień : lecture -------------------------------------------------------------------------------------------------

    /** Tout l'écran du jour du groupe de la tablette pour la personne identifiée. */
    public function day(ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $date = date('Y-m-d');
        $lang = $context->translator->lang();
        $group = $this->repo->group($groupId) ?? throw ApiException::notFound('group', $groupId);
        $set = $this->settingsOf($groupId, null, (string) $group['code']);
        $person = $this->person($context);
        $prod = ($set['make'] || $set['bags'] || $set['chill']) ? $this->outputs->dayOf($groupId, $date, $lang) : null;
        $blocks = [
            'make' => $set['make'] ? $this->makeBlock($prod, $date) : null,
            'bags' => $set['bags'] ? $this->bagsBlock($prod, $groupId, $lang === 'fr') : null,
            'chill' => $set['chill'] ? $this->chillBlock($date, $groupId, $prod) : null,
            'clean' => $set['clean'] ? $this->cleanBlock($date, $groupId, $lang === 'fr') : null,
            'pack' => $set['pack'] ? $this->packBlock() : null,
        ];
        $done = $this->repo->steps($date, $groupId);
        $steps = [];
        $now = null;
        foreach (self::stepsOf($set) as $k) {
            $complete = isset($done[$k]) && $blocks[$k]['complete'];
            $state = $complete ? 'done' : ($now === null ? 'now' : 'later');
            if (!$complete && $now === null) $now = $k;
            $steps[] = ['key' => $k, 'state' => $state, 'done_at' => $done[$k] ?? null];
        }
        $closed = $prod !== null && $prod['closed'] !== null ? $prod['closed'] : null;
        $finished = $this->repo->dayFinished($date, $groupId);
        $end = $this->endBlock($prod, $blocks, $closed, $finished);
        $steps[] = ['key' => 'end', 'state' => $end['finished'] ? 'done' : ($now === null ? 'now' : 'later'), 'done_at' => $finished['finished_at'] ?? null];
        $end['can_close'] = $now === null && !$end['finished'];
        $call = $this->repo->openCall($date, $groupId);
        return [
            'date' => $date, 'guided' => $set['guided'], 'settings' => $set,
            'group' => ['id' => $groupId, 'code' => $group['code'], 'name' => $lang === 'fr' && $group['name_fr'] ? $group['name_fr'] : $group['name_pl']],
            'person' => $person, 'shift' => ['start' => $this->params->get('hala.shift_start', '06:00'), 'end' => $this->params->get('hala.shift_end', '14:00')],
            'steps' => $steps, 'now' => $now ?? ($end['finished'] ? null : 'end'),
            'make' => $blocks['make'], 'bags' => $blocks['bags'], 'chill' => $blocks['chill'], 'clean' => $blocks['clean'], 'pack' => $blocks['pack'], 'end' => $end,
            'waste_reasons' => OutputService::WASTE_REASONS,
            'help' => $call ? $this->callRow($call, $lang) : null, 'poll_s' => $this->pollSeconds(),
        ];
    }

    /** Personne identifiée : nom, prénom, chef de zmiana (poste dans hala.shift_lead_positions). */
    private function person(ApiContext $context): array
    {
        $e = $context->employeeId ? $this->repo->employee($context->employeeId) : null;
        return ['id' => $context->employeeId, 'name' => $e['name'] ?? null, 'first_name' => $e['first_name'] ?? null,
            'lead' => $e !== null && in_array((string) $e['position_code'], $this->leadPositions(), true)];
    }

    /** Co robię dziś : produits du plan du jour (et faits hors plan), compteur, pertes ; le premier pas fini = « teraz ». */
    private function makeBlock(array $prod, string $date): array
    {
        $rows = array_values(array_filter($prod['items'], fn($i) => $i['planned'] !== null || $i['produced'] > 0));
        $meta = $this->repo->itemsMeta(array_column($rows, 'item_id'), $date);
        $items = array_map(function ($i) use ($meta) {
            $m = $meta[$i['item_id']] ?? ['product_id' => null, 'has_photo' => false, 'start' => null];
            $done = $i['planned'] !== null ? $i['produced'] + 1e-6 >= $i['planned'] : $i['produced'] > 0;
            return ['item_id' => $i['item_id'], 'name' => $i['name'], 'unit' => $i['unit'], 'ppc' => $i['ppc'], 'planned' => $i['planned'],
                'made' => $i['produced'], 'waste' => $i['waste'], 'start' => $m['start'], 'has_photo' => $m['has_photo'], 'done' => $done];
        }, $rows);
        usort($items, fn($a, $b) => [$a['planned'] === null, $a['start'] ?? '', $a['name']] <=> [$b['planned'] === null, $b['start'] ?? '', $b['name']]);
        $now = null;
        foreach ($items as &$it) {
            $it['state'] = $it['done'] ? 'done' : ($now === null ? 'now' : 'later');
            if (!$it['done'] && $now === null) $now = $it['item_id'];
        }
        unset($it);
        return ['items' => $items, 'now_item' => $now, 'total' => count($items), 'done' => count(array_filter($items, fn($i) => $i['done'])),
            'waste' => round(array_sum(array_column($items, 'waste')), 3), 'locked' => !empty($prod['closed']['locked']), 'complete' => true];
    }

    /**
     * Skanuj worki : par produit avec partie(s) vivante(s) du jour, surowce suivis de la recette (après kompozycje d'autres
     * groupes) — fait quand chaque partie a son numéro (ou un motif d'absence) ; kompozycje d'autres groupes (écran « Partia »).
     */
    private function bagsBlock(array $prod, int $groupId, bool $fr): array
    {
        $products = [];
        foreach ($prod['items'] as $i) {
            $live = array_values(array_filter($i['batches'], fn($b) => !$b['cancelled']));
            if (!$live) continue;
            $item = $this->outputRepo->item($i['item_id']);
            $recipeId = $item && $item['id_recipe'] ? (int) $item['id_recipe'] : null;
            $cut = $recipeId ? $this->cut->of($recipeId, $groupId) : ['cuts' => [], 'minus' => []];
            $recipe = $recipeId ? CompositionCut::apply($this->outputRepo->recipe($recipeId), $cut['minus']) : [];
            $lots = [];
            $comps = [];
            foreach ($live as $b) { $lots[$b['id']] = $this->outputRepo->lots($b['id']); $comps[$b['id']] = $this->groupOrders->components($b['id']); }
            $ingredients = [];
            foreach ($recipe as $r) {
                $m = (int) $r['material_id'];
                $tracked = (int) ($r['tracked'] ?? 1) === 1;
                $filled = array_filter($lots, fn($l) => isset($l[$m]) && (($l[$m]['lot_number'] ?? '') !== '' || ($l[$m]['missing_reason'] ?? null) !== null));
                $last = null;
                foreach ($lots as $l) if (isset($l[$m]) && ($l[$m]['lot_number'] ?? '') !== '') { $last = $l[$m]; break; }
                $ingredients[] = ['material_id' => $m, 'name' => $fr && !empty($r['name_fr']) ? $r['name_fr'] : $r['name'], 'packaging' => (bool) $r['packaging'],
                    'tracked' => $tracked, 'done' => !$tracked || count($filled) === count($live),
                    'lot_number' => $last['lot_number'] ?? null, 'best_before' => $last['best_before'] ?? null];
            }
            $components = array_map(fn($c) => ['recipe_id' => $c['recipe_id'], 'name' => $c['name'],
                'producer' => $fr && $c['producer']['name_fr'] ? $c['producer']['name_fr'] : $c['producer']['name_pl'],
                'done' => count(array_filter($comps, fn($x) => isset($x[$c['recipe_id']]) && $x[$c['recipe_id']]['id_source_output'] !== null)) === count($live)], $cut['cuts']);
            $complete = !array_filter($ingredients, fn($x) => !$x['done']) && !array_filter($components, fn($x) => !$x['done']);
            $products[] = ['item_id' => $i['item_id'], 'name' => $i['name'], 'batch_id' => $live[0]['id'], 'lot_code' => $live[0]['lot_code'], 'batches' => count($live),
                'ingredients' => $ingredients, 'components' => $components, 'complete' => $complete,
                'scans' => array_sum(array_map(fn($l) => count(array_filter($l, fn($x) => ($x['lot_number'] ?? '') !== '')), $lots))];
        }
        $open = array_values(array_filter($products, fn($p) => !$p['complete']));
        return ['products' => $products, 'focus' => ($open[0] ?? $products[0] ?? null)['item_id'] ?? null,
            'scans' => array_sum(array_column($products, 'scans')), 'complete' => !$open];
    }

    /** Schładzanie : mesures du jour (minutnik, état), produits du jour à sortir du four, limites. */
    private function chillBlock(string $date, int $groupId, ?array $prod): array
    {
        $rows = array_map(fn($r) => $this->chillRow($r), $this->repo->chills($date, $groupId));
        $products = [];
        foreach ($prod['items'] ?? [] as $i) if ($i['planned'] !== null || $i['produced'] > 0) $products[] = ['item_id' => $i['item_id'], 'name' => $i['name']];
        $bad = count(array_filter($rows, fn($r) => in_array($r['state'], ['warm', 'late'], true)));
        return ['rows' => $rows, 'products' => $products, 'limits' => $this->chillLimits(), 'bad' => $bad,
            'ok' => count(array_filter($rows, fn($r) => $r['state'] === 'ok')),
            'complete' => !array_filter($rows, fn($r) => $r['measured_at'] === null)];
    }

    /** Une mesure : running (minutnik), ok, warm (au-dessus de la limite), late (mesurée ou encore pas mesurée après le délai). */
    public static function chillState(array $r, ?int $nowTs = null): array
    {
        $nowTs ??= time();
        $out = strtotime((string) $r['out_at']);
        $max = (int) $r['max_minutes'];
        if ($r['measured_at'] !== null && $r['temp_c'] !== null) {
            $minutes = (int) floor((strtotime((string) $r['measured_at']) - $out) / 60);
            $state = (float) $r['temp_c'] > (float) $r['target_c'] + 1e-9 ? 'warm' : ($minutes > $max ? 'late' : 'ok');
            return ['state' => $state, 'minutes' => $minutes, 'left' => null];
        }
        $minutes = (int) floor(($nowTs - $out) / 60);
        return ['state' => $minutes > $max ? 'late' : 'running', 'minutes' => $minutes, 'left' => max(0, $max - $minutes)];
    }

    private function chillRow(array $r): array
    {
        $s = self::chillState($r);
        return ['id' => (int) $r['id'], 'item_id' => (int) $r['id_item'], 'name' => $r['name'], 'out_at' => $r['out_at'],
            'temp_c' => $r['temp_c'] !== null ? (float) $r['temp_c'] : null, 'measured_at' => $r['measured_at'],
            'target_c' => (float) $r['target_c'], 'max_minutes' => (int) $r['max_minutes']] + $s;
    }

    private function cleanBlock(string $date, int $groupId, bool $fr): array
    {
        $done = $this->repo->cleanRecords($date, $groupId);
        $zones = array_map(fn($z) => ['id' => (int) $z['id'], 'name' => $fr && $z['name_fr'] ? $z['name_fr'] : $z['name_pl'], 'icon' => $z['icon'],
            'done' => isset($done[(int) $z['id']]), 'at' => $done[(int) $z['id']]['done_at'] ?? null, 'by' => $done[(int) $z['id']]['employee'] ?? null],
            $this->repo->zones($groupId));
        $n = count(array_filter($zones, fn($z) => $z['done']));
        return ['zones' => $zones, 'total' => count($zones), 'done' => $n, 'complete' => $n === count($zones)];
    }

    /** Pakuję : jour d'expédition de la tablette d'emballage, commandes (clients) emballées. */
    private function packBlock(): array
    {
        $date = $this->logistics->defaultDate();
        try {
            $p = $this->board->pallets($date);
        } catch (\Throwable) {
            return ['date' => $date, 'total' => 0, 'done' => 0, 'complete' => true];
        }
        $clients = array_values(array_filter($p['clients'], fn($c) => $c['ordered'] > 0));
        $done = count(array_filter($clients, fn($c) => $c['left'] === 0));
        return ['date' => $date, 'total' => count($clients), 'done' => $done, 'complete' => true];
    }

    private function endBlock(?array $prod, array $b, ?array $closed, ?array $finished): array
    {
        return [
            'make' => $b['make'] ? ['done' => $b['make']['done'], 'total' => $b['make']['total']] : null,
            'waste' => $b['make'] ? $b['make']['waste'] : null,
            'scans' => $b['bags'] ? ['scans' => $b['bags']['scans'], 'complete' => $b['bags']['complete']] : null,
            'chill' => $b['chill'] ? ['ok' => $b['chill']['ok'], 'bad' => $b['chill']['bad'], 'rows' => count($b['chill']['rows'])] : null,
            'clean' => $b['clean'] ? ['done' => $b['clean']['done'], 'total' => $b['clean']['total']] : null,
            'pack' => $b['pack'] ? ['done' => $b['pack']['done'], 'total' => $b['pack']['total']] : null,
            'gaps' => $prod['gaps'] ?? ['lots' => [], 'qc' => []],
            'closed' => $closed ? ['at' => $closed['at'], 'by' => $closed['employee']] : null,
            'finished' => $finished !== null, 'finished_at' => $finished['finished_at'] ?? null, 'finished_by' => $finished['employee'] ?? null,
        ];
    }

    // --- Mój dzień : actions -------------------------------------------------------------------------------------------------

    /** Compteur « zrobione » d'un produit (quantité faite du jour, unité de la fiche). */
    public function made(int $itemId, array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['made'])->decimal('made', true, 0, 1000000);
        $v->throwIfInvalid();
        $this->outputs->setMade($itemId, (float) $v->value('made'), $context);
        return $this->day($context);
    }

    /** « Dalej » d'une étape : condition remplie (lots, mesures, strefy), sinon 409 HALA_STEP_INCOMPLETE. */
    public function step(string $step, ApiContext $context): array
    {
        $day = $this->day($context);
        if (!in_array($step, self::stepsOf($day['settings']), true)) throw ApiException::notFound('step', $step);
        if (!$day[$step]['complete']) throw new ApiException('HALA_STEP_INCOMPLETE', 409, [], ['step' => $step]);
        $this->repo->markStep($day['date'], $day['group']['id'], $step, $context->employeeId, $context->deviceId);
        $this->audit->record($context, 'step', 'hala_day', $day['group']['id'], null, ['date' => $day['date'], 'step' => $step]);
        return $this->day($context);
    }

    /**
     * Skan d'un sac : code (numéro de lot du registre), produit du jour ; material_id = surowiec choisi pour un code inconnu.
     * result : ok (enregistré), wrong (autre surowiec : STOP), expired (date dépassée : STOP), unknown (choisir le surowiec).
     */
    public function scanBag(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['item_id', 'code', 'material_id'])->integer('item_id', true, 1)->string('code', 64)
            ->integer('material_id', false, 1, null, true);
        $v->throwIfInvalid();
        $groupId = $this->groupId($context);
        $date = date('Y-m-d');
        $itemId = (int) $v->value('item_id');
        $code = MaterialLotService::normalize((string) $v->value('code')) ?? throw ApiException::validation([['field' => 'code', 'code' => 'REQUIRED']]);
        $live = $this->outputRepo->liveBatches($date, $groupId, $itemId);
        if (!$live) throw new ApiException('HALA_NO_BATCH', 409);
        $item = $this->outputRepo->item($itemId);
        $recipeId = $item && $item['id_recipe'] ? (int) $item['id_recipe'] : 0;
        $cut = $recipeId ? $this->cut->of($recipeId, $groupId) : ['minus' => []];
        $recipe = [];
        foreach ($recipeId ? CompositionCut::apply($this->outputRepo->recipe($recipeId), $cut['minus']) : [] as $r) $recipe[(int) $r['material_id']] = $r;
        $fr = $context->translator->lang() === 'fr';
        $name = fn(int $m) => isset($recipe[$m]) ? ($fr && !empty($recipe[$m]['name_fr']) ? $recipe[$m]['name_fr'] : $recipe[$m]['name']) : ($this->repo->materialName($m) ?? ('#' . $m));
        $missing = array_values(array_map(fn($r) => ['material_id' => (int) $r['material_id'], 'name' => $name((int) $r['material_id'])],
            array_filter($recipe, fn($r) => (int) ($r['tracked'] ?? 1) === 1)));
        $today = $date;
        $chosen = $v->value('material_id');
        $lots = $this->repo->lotsByNumber($code);
        $inRecipe = array_values(array_filter($lots, fn($l) => isset($recipe[(int) $l['id_raw_material']])));
        $result = null;
        if ($chosen !== null) {
            if (!isset($recipe[(int) $chosen])) throw ApiException::validation([['field' => 'material_id', 'code' => 'MATERIAL_NOT_IN_RECIPE']]);
            $known = array_values(array_filter($lots, fn($l) => (int) $l['id_raw_material'] === (int) $chosen))[0] ?? null;
            $lot = ['id_raw_material' => (int) $chosen, 'lot_number' => $code, 'best_before' => $known['best_before'] ?? null];
        } elseif ($inRecipe) {
            $lot = $inRecipe[0];
        } elseif ($lots) {
            $other = $lots[0];
            return ['result' => 'wrong', 'code' => $code, 'material' => $other['material'] ?? $name((int) $other['id_raw_material']),
                'expected' => array_column($missing, 'name'), 'best_before' => $other['best_before'], 'day' => $this->day($context)];
        } else {
            return ['result' => 'unknown', 'code' => $code, 'choices' => $missing, 'day' => $this->day($context)];
        }
        $materialId = (int) $lot['id_raw_material'];
        if ($lot['best_before'] !== null && (string) $lot['best_before'] < $today) {
            return ['result' => 'expired', 'code' => $code, 'material' => $name($materialId), 'best_before' => $lot['best_before'], 'day' => $this->day($context)];
        }
        // Parties du produit sans ce surowiec d'abord ; toutes remplies : la plus récente (nouveau sac pour la suite).
        $targets = array_values(array_filter($live, fn($b) => !isset($this->outputRepo->lots((int) $b['id'])[$materialId])));
        if (!$targets) $targets = [$live[0]];
        foreach ($targets as $b) {
            $this->outputs->setLot((int) $b['id'], $materialId, ['lot_number' => $code, 'best_before' => $lot['best_before']], $context);
        }
        $result = ['result' => 'ok', 'code' => $code, 'material' => $name($materialId), 'material_id' => $materialId, 'best_before' => $lot['best_before'], 'batches' => count($targets)];
        return $result + ['day' => $this->day($context)];
    }

    /** Sortie du four (minutnik) d'un produit du jour ; client_uuid rejouable. */
    public function chillStart(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['item_id', 'client_uuid'])->integer('item_id', true, 1)
            ->string('client_uuid', 36, false, true, '/^[0-9a-fA-F-]{36}$/', 'INVALID_UUID');
        $v->throwIfInvalid();
        $groupId = $this->groupId($context);
        $uuid = $v->value('client_uuid');
        if ($uuid !== null && $this->repo->chillByUuid((string) $uuid) !== null) return $this->day($context);
        $itemId = (int) $v->value('item_id');
        if ($this->outputRepo->item($itemId) === null) throw ApiException::validation([['field' => 'item_id', 'code' => 'NOT_FOUND_REF']]);
        $date = date('Y-m-d');
        $live = $this->outputRepo->liveBatches($date, $groupId, $itemId);
        $limits = $this->chillLimits();
        $id = $this->repo->insertChill(['work_date' => $date, 'id_group' => $groupId, 'id_item' => $itemId, 'id_output' => $live[0]['id'] ?? null,
            'target_c' => $limits['target_c'], 'max_minutes' => $limits['max_minutes'], 'id_employee' => $context->employeeId, 'id_device' => $context->deviceId,
            'client_uuid' => $uuid]);
        $this->audit->record($context, 'create', 'hala_chill', $id, null, ['item_id' => $itemId, 'date' => $date]);
        return $this->day($context);
    }

    /** Température au cœur : trop chaud (ou mesurée après le délai) → wezwanie « chill » au brygadzista. */
    public function chillMeasure(int $id, array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['temp_c'])->decimal('temp_c', true, -40, 120);
        $v->throwIfInvalid();
        $groupId = $this->groupId($context);
        $r = $this->repo->chill($id);
        if ($r === null || (int) $r['id_group'] !== $groupId || (string) $r['work_date'] !== date('Y-m-d')) throw ApiException::notFound('chill', $id);
        $temp = round((float) $v->value('temp_c'), 1);
        $this->repo->measureChill($id, $temp, $context->employeeId);
        $this->audit->record($context, 'measure', 'hala_chill', $id, null, ['temp_c' => $temp]);
        $rows = array_values(array_filter($this->repo->chills((string) $r['work_date'], $groupId), fn($x) => (int) $x['id'] === $id));
        $row = $this->chillRow($rows[0]);
        if (in_array($row['state'], ['warm', 'late'], true)) {
            $note = sprintf('%s %s °C%s', $row['name'], self::num($temp), $row['state'] === 'warm' ? ', za ciepło' : ', po czasie (' . $row['minutes'] . ' min)');
            $this->call($groupId, $context, 'chill', 'chill', $note);
        }
        return $this->day($context);
    }

    /** Strefa sprzątnięta (done) ou annulée (pomyłka). */
    public function clean(int $zoneId, array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['done'])->boolean('done', true);
        $v->throwIfInvalid();
        $groupId = $this->groupId($context);
        $z = $this->repo->zone($zoneId);
        if ($z === null || (int) $z['id_group'] !== $groupId || !(int) $z['active']) throw ApiException::notFound('zone', $zoneId);
        $date = date('Y-m-d');
        if ($v->value('done')) $this->repo->markClean($date, $groupId, $zoneId, $context->employeeId, $context->deviceId);
        else $this->repo->unmarkClean($date, $zoneId);
        $this->audit->record($context, $v->value('done') ? 'clean' : 'unclean', 'hala_zone', $zoneId, null, ['date' => $date]);
        return $this->day($context);
    }

    /**
     * « Zatwierdź dzień » : toutes les étapes terminées ; avec production → validation du jour (n_prod_day_close, tâche « Dzień
     * grupy ») — la kontrola jakości n'est plus dans le chemin des ouvriers (obchód du Kierownik produkcji) : son absence est
     * notée automatiquement ; un lot manquant renvoie à « Skanuj worki » (409 HALA_STEP_INCOMPLETE).
     */
    public function close(ApiContext $context): array
    {
        $day = $this->day($context);
        if ($day['end']['finished']) return $day;
        if (!$day['end']['can_close']) throw new ApiException('HALA_STEP_INCOMPLETE', 409, [], ['step' => $day['now'] ?? 'end']);
        $gaps = $day['end']['gaps'];
        if ($gaps['lots']) throw new ApiException('HALA_STEP_INCOMPLETE', 409, [], ['step' => 'bags']);
        $parts = [];
        $prod = $day['make'] !== null || $day['bags'] !== null;
        if ($prod) {
            $current = $this->outputs->dayOf($day['group']['id'], $day['date'], $context->translator->lang());
            if ($current['items'] && !($current['closed']['locked'] ?? false)) {
                $note = $gaps['qc'] ? 'Kontrola jakości : obchód Kierownika produkcji (tablet hali)' : null;
                $this->outputs->closeDay(['date' => $day['date'], 'note' => $note], $context);
            }
            if ($day['make']) $parts[] = 'zrobione ' . $day['make']['done'] . '/' . $day['make']['total'];
        }
        if ($day['clean']) $parts[] = 'sprzątanie ' . $day['clean']['done'] . '/' . $day['clean']['total'];
        if ($day['chill']) $parts[] = 'schładzanie ' . $day['chill']['ok'] . ' ok' . ($day['chill']['bad'] ? ', ' . $day['chill']['bad'] . ' poza limitem' : '');
        if ($day['pack']) $parts[] = 'pakowanie ' . $day['pack']['done'] . '/' . $day['pack']['total'];
        $this->repo->finishDay($day['date'], $day['group']['id'], $context->employeeId, $context->deviceId, implode(' · ', $parts));
        $this->audit->record($context, 'close', 'hala_day', $day['group']['id'], null, ['date' => $day['date'], 'summary' => implode(' · ', $parts)]);
        return $this->day($context);
    }

    // --- Pomoc et Zmiana ----------------------------------------------------------------------------------------------------

    /** « Pomoc » : un seul wezwanie ouvert par groupe (un nouvel appui le complète) ; renvoie l'état de l'appel. */
    public function help(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['step', 'reason', 'note'])->choice('step', self::HELP_STEPS, false, true)
            ->choice('reason', self::HELP_REASONS, false, true)->string('note', 255, false, true);
        $v->throwIfInvalid();
        $groupId = $this->groupId($context);
        $call = $this->call($groupId, $context, (string) ($v->value('step') ?? 'home'), (string) ($v->value('reason') ?? 'help'), $v->value('note'));
        return ['help' => $this->callRow($call, $context->translator->lang()), 'poll_s' => $this->pollSeconds()];
    }

    /** État de l'appel du groupe (écran « Pomoc » : le brygadzista l'a-t-il pris ?). */
    public function helpState(ApiContext $context): array
    {
        $call = $this->repo->openCall(date('Y-m-d'), $this->groupId($context));
        return ['help' => $call ? $this->callRow($call, $context->translator->lang()) : null, 'poll_s' => $this->pollSeconds()];
    }

    private function call(int $groupId, ApiContext $context, string $step, string $reason, ?string $note): array
    {
        $date = date('Y-m-d');
        $open = $this->repo->openCall($date, $groupId);
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        if ($open !== null) {
            // Un motif plus précis (zły worek, za ciepło) remplace « pomoc » ; le statut reste (déjà pris : le brygadzista vient).
            if ($reason !== 'help' || $note !== null) $this->repo->update((int) $open['id'], ['reason' => $reason !== 'help' ? $reason : $open['reason'], 'note' => $note ?? $open['note'], 'step' => $step]);
            return $this->repo->openCall($date, $groupId);
        }
        $id = $this->repo->insert(['work_date' => $date, 'id_group' => $groupId, 'id_device' => $context->deviceId, 'id_employee' => $context->employeeId,
            'step' => $step, 'reason' => $reason, 'note' => $note, 'status' => 'open', 'created_at' => date('Y-m-d H:i:s')]);
        $this->audit->record($context, 'create', 'hala_help', $id, null, ['group' => $groupId, 'reason' => $reason, 'step' => $step]);
        return $this->repo->openCall($date, $groupId);
    }

    private function callRow(array $c, string $lang): array
    {
        return ['id' => (int) $c['id'], 'group_id' => $c['id_group'] !== null ? (int) $c['id_group'] : null,
            'group' => $lang === 'fr' && !empty($c['group_name_fr']) ? $c['group_name_fr'] : ($c['group_name'] ?? null),
            'employee' => $c['employee'] ?? null, 'step' => $c['step'], 'reason' => $c['reason'], 'note' => $c['note'], 'status' => $c['status'],
            'created_at' => $c['created_at'], 'taken_at' => $c['taken_at'], 'taken_by' => $c['taken_name'] ?? null, 'closed_at' => $c['closed_at']];
    }

    /**
     * Zmiana (chef de zmiana) : état de chaque groupe actif ce jour — vert (dzień zatwierdzony ou dans les temps), or (retard
     * sur l'heure, au-delà de hala.delay_tolerance_pct), rouge (wezwanie ouvert, schładzanie hors limites) —, wezwania du jour,
     * « Zatwierdź zmianę » possible quand chaque groupe est vert et zatwierdzony.
     */
    public function shift(ApiContext $context): array
    {
        $this->assertLead($context);
        $date = date('Y-m-d');
        $lang = $context->translator->lang();
        $closes = $this->repo->dayCloses($date);
        $finished = $this->repo->daysFinished($date);
        $calls = array_map(fn($c) => $this->callRow($c, $lang), $this->repo->calls($date));
        $chills = $this->repo->chills($date);
        [$elapsed, $shiftMin] = $this->elapsed();
        $tolerance = max(0, (int) $this->params->get('hala.delay_tolerance_pct', '15'));
        $saved = $this->repo->settings();
        $groups = [];
        foreach ($this->repo->groups() as $g) {
            $gid = (int) $g['id'];
            $set = $this->settingsOf($gid, $saved[$gid] ?? null, (string) $g['code']);
            $row = ['id' => $gid, 'name' => $lang === 'fr' && $g['name_fr'] ? $g['name_fr'] : $g['name_pl'], 'color' => $g['color'], 'kind' => $set['pack'] && !$set['make'] ? 'pack' : 'make'];
            $open = array_values(array_filter($calls, fn($c) => $c['group_id'] === $gid && $c['status'] !== 'closed'));
            $bad = array_values(array_filter(array_map(fn($r) => $this->chillRow($r), array_filter($chills, fn($r) => (int) $r['id_group'] === $gid)), fn($r) => in_array($r['state'], ['warm', 'late'], true)));
            if ($row['kind'] === 'pack') {
                $p = $this->packBlock();
                if (!$p['total'] && !$open && !isset($finished[$gid])) continue;
                $row += ['pct' => $p['total'] ? (int) round($p['done'] / $p['total'] * 100) : 100, 'orders' => ['done' => $p['done'], 'total' => $p['total']]];
            } else {
                $prod = $this->outputs->dayOf($gid, $date, $lang);
                $planned = array_values(array_filter($prod['items'], fn($i) => $i['planned'] !== null && $i['planned'] > 0));
                if (!$planned && !array_filter($prod['items'], fn($i) => $i['produced'] > 0) && !$open && !isset($closes[$gid]) && !isset($finished[$gid])) continue;
                $pct = $planned ? (int) round(array_sum(array_map(fn($i) => min(1, $i['produced'] / $i['planned']), $planned)) / count($planned) * 100) : 100;
                $row += ['pct' => $pct, 'items' => ['done' => count(array_filter($planned, fn($i) => $i['produced'] + 1e-6 >= $i['planned'])), 'total' => count($planned)],
                    'bags_ok' => !$prod['gaps']['lots']];
            }
            $closedAt = $finished[$gid] ?? ($row['kind'] === 'make' ? ($closes[$gid] ?? null) : null);
            $delay = !$closedAt && $row['pct'] + $tolerance < $elapsed ? (int) round(($elapsed - $row['pct']) / 100 * $shiftMin) : 0;
            $row += ['closed_at' => $closedAt, 'calls' => count($open), 'chill_bad' => count($bad), 'chill_ok' => !$bad && $set['chill'] ? true : null,
                'delay_min' => $delay, 'status' => $open || $bad ? 'red' : ($delay > 0 ? 'gold' : 'green')];
            $groups[] = $row;
        }
        $openCalls = array_values(array_filter($calls, fn($c) => $c['status'] !== 'closed'));
        $shift = $this->repo->shift($date);
        $ready = $groups && !$openCalls && !array_filter($groups, fn($g) => $g['status'] !== 'green' || $g['closed_at'] === null);
        return ['date' => $date, 'person' => $this->person($context), 'shift' => ['start' => $this->params->get('hala.shift_start', '06:00'), 'end' => $this->params->get('hala.shift_end', '14:00'), 'elapsed_pct' => $elapsed],
            'groups' => $groups, 'calls' => $openCalls, 'calls_today' => count($calls), 'validated' => $shift ? ['at' => $shift['validated_at'], 'by' => $shift['employee'], 'summary' => $shift['summary']] : null,
            'can_validate' => $ready && $shift === null, 'poll_s' => $this->pollSeconds()];
    }

    /** « Idę » (take) ou « Załatwione » (close) sur un wezwanie du jour. */
    public function helpAction(int $id, array $body, ApiContext $context): array
    {
        $this->assertLead($context);
        $v = (new Validator($body))->only(['action'])->choice('action', ['take', 'close'], true);
        $v->throwIfInvalid();
        $c = $this->repo->find($id);
        if ($c === null || (string) $c['work_date'] !== date('Y-m-d')) throw ApiException::notFound('help', $id);
        if ($c['status'] === 'closed') throw new ApiException('HALA_HELP_CLOSED', 409);
        $now = date('Y-m-d H:i:s');
        $data = $v->value('action') === 'take'
            ? ['status' => 'taken', 'taken_at' => $c['taken_at'] ?? $now, 'taken_by' => $c['taken_by'] ?? $context->employeeId]
            : ['status' => 'closed', 'closed_at' => $now, 'closed_by' => $context->employeeId, 'taken_at' => $c['taken_at'] ?? $now, 'taken_by' => $c['taken_by'] ?? $context->employeeId];
        $this->repo->update($id, $data);
        $this->audit->record($context, $v->value('action'), 'hala_help', $id, ['status' => $c['status']], ['status' => $data['status']]);
        return $this->shift($context);
    }

    /** « Zatwierdź zmianę » : chaque groupe vert et zatwierdzony, aucun wezwanie ouvert. */
    public function validateShift(ApiContext $context): array
    {
        $s = $this->shift($context);
        if ($s['validated'] !== null) return $s;
        if (!$s['can_validate']) throw new ApiException('HALA_SHIFT_NOT_READY', 409, [], ['groups' => count(array_filter($s['groups'], fn($g) => $g['status'] !== 'green' || !$g['closed_at'])), 'calls' => count($s['calls'])]);
        $summary = count($s['groups']) . ' grup zatwierdziło dzień · ' . $s['calls_today'] . ' wezwań';
        $this->repo->validateShift($s['date'], $context->employeeId, $context->deviceId, $summary);
        $this->audit->record($context, 'validate', 'hala_shift', $s['date'], null, ['summary' => $summary]);
        return $this->shift($context);
    }

    /** Photo du produit (fiche, emplacement 0), en data URL. */
    public function photo(int $itemId): array
    {
        $p = $this->repo->productPhoto($itemId) ?? throw ApiException::notFound('photo', $itemId);
        return ['item_id' => $itemId, 'src' => DataUrlImage::toDataUrl((string) $p['mime'], (string) $p['image'])];
    }

    // --- ERP : Produkcja › Tablet hali (kroki i strefy sprzątania ; droit « hala ») ----------------------------------------

    /** Groupes actifs, leur réglage (enregistré ou par défaut), leurs strefy (actives et retirées), limites de schładzanie. */
    public function settingsData(): array
    {
        $saved = $this->repo->settings();
        $zones = [];
        foreach ($this->repo->zones(null, false) as $z) {
            $zones[(int) $z['id_group']][] = ['id' => (int) $z['id'], 'name_pl' => $z['name_pl'], 'name_fr' => $z['name_fr'], 'icon' => $z['icon'], 'active' => (bool) (int) $z['active']];
        }
        $groups = array_map(fn($g) => ['id' => (int) $g['id'], 'code' => $g['code'], 'name' => $g['name_pl'], 'color' => $g['color'],
            'settings' => $this->settingsOf((int) $g['id'], $saved[(int) $g['id']] ?? null, (string) $g['code']), 'saved' => isset($saved[(int) $g['id']]),
            'zones' => $zones[(int) $g['id']] ?? []], $this->repo->groups());
        return ['groups' => $groups, 'icons' => self::ZONE_ICONS, 'steps' => self::STEPS, 'chill' => $this->chillLimits(),
            'packing_group' => $this->packingCode(), 'lead_positions' => $this->leadPositions(),
            'shift' => ['start' => $this->params->get('hala.shift_start', '06:00'), 'end' => $this->params->get('hala.shift_end', '14:00')]];
    }

    /** {group_id, guided, make, bags, chill, clean, pack} : étapes du groupe (au moins une étape). */
    public function saveGroup(array $in, ?int $userId): array
    {
        $id = (int) ($in['group_id'] ?? 0);
        if ($this->repo->group($id) === null) throw new \App\Exceptions\CustomException('Nieznana grupa.', 'errors', 422);
        $s = ['guided' => !empty($in['guided'])];
        foreach (self::STEPS as $k) $s[$k] = !empty($in[$k]);
        if (!array_filter(array_intersect_key($s, array_flip(self::STEPS)))) throw new \App\Exceptions\CustomException('Grupa potrzebuje co najmniej jednego kroku.', 'errors', 422);
        if ($s['bags'] && !$s['make']) throw new \App\Exceptions\CustomException('« Skanuj worki » wymaga kroku « Co robię dziś » (worki skanuje się do tego, co zrobione).', 'errors', 422);
        $this->repo->saveSettings($id, $s, $userId);
        return $this->settingsData();
    }

    /** {id?, group_id, name_pl, name_fr?, icon, active?} : strefa ajoutée ou modifiée (retirée : active = false, l'historique reste). */
    public function saveZone(array $in, ?int $userId): array
    {
        $name = trim((string) ($in['name_pl'] ?? ''));
        $fr = trim((string) ($in['name_fr'] ?? ''));
        $icon = (string) ($in['icon'] ?? 'other');
        if ($name === '' || mb_strlen($name) > 80 || mb_strlen($fr) > 80) throw new \App\Exceptions\CustomException('Nazwa strefy : od 1 do 80 znaków.', 'errors', 422);
        if (!in_array($icon, self::ZONE_ICONS, true)) throw new \App\Exceptions\CustomException('Nieznana ikona strefy.', 'errors', 422);
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            $z = $this->repo->zone($id) ?? throw new \App\Exceptions\CustomException('Nie znaleziono strefy.', 'errors', 404);
            $this->repo->updateZone($id, $name, $fr !== '' ? $fr : null, $icon, array_key_exists('active', $in) ? !empty($in['active']) : (bool) (int) $z['active'], $userId);
        } else {
            $group = (int) ($in['group_id'] ?? 0);
            if ($this->repo->group($group) === null) throw new \App\Exceptions\CustomException('Nieznana grupa.', 'errors', 422);
            $this->repo->insertZone($group, $name, $fr !== '' ? $fr : null, $icon, $userId);
        }
        return $this->settingsData();
    }

    // ---------------------------------------------------------------------------------------------------------------------

    private function assertLead(ApiContext $context): void
    {
        if (!$this->person($context)['lead']) throw new ApiException('HALA_NOT_SHIFT_LEAD', 403);
    }

    /** Part de la zmiana écoulée (0–100) et sa durée en minutes. @return array{0:int, 1:int} */
    private function elapsed(): array
    {
        $start = strtotime(date('Y-m-d') . ' ' . $this->params->get('hala.shift_start', '06:00'));
        $end = strtotime(date('Y-m-d') . ' ' . $this->params->get('hala.shift_end', '14:00'));
        if ($start === false || $end === false || $end <= $start) return [0, 0];
        $pct = (int) round(max(0, min(1, (time() - $start) / ($end - $start))) * 100);
        return [$pct, (int) (($end - $start) / 60)];
    }

    private function groupId(ApiContext $context): int
    {
        if ($context->deviceGroupId === null) throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
        return $context->deviceGroupId;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }
}
