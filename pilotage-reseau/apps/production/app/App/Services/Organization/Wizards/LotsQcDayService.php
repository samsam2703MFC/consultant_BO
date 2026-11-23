<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\LotsQcDayRepository;
use App\Repositories\Workshop\OutputRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Execution\CompositionCut;

/**
 * Asystent « Partie i kontrola dnia » [377] (Specjalista ds. jakości, chaque jour ; obligation 5 « Identyfikowalność
 * partii » ; en lecture pour le contrôle des obligations atelier PIEKARZ 4, TARTY 4, CUKIERNIK 5, GARMAZER 5).
 * Le kiosque laisse clore une journée de groupe avec des trous et une simple note (OutputService::closeDay) : la
 * traçabilité est donc vérifiée depuis le bureau.
 *   1. Przegląd : les journées de groupe closes du jour ouvré précédent (ou de la dernière validation, rattrapage borné
 *      par lots_qc.catchup_days) à aujourd'hui, leurs parties et leurs trous :
 *        - lot_missing : surowiec suivi (ou composition d'un autre groupe) sans numéro de lot ni motif — même calcul que
 *          OutputService::lotsTotal / batchData (recette aplatie, CompositionCut, n_material_trace) ;
 *        - lot_reason : « brak partii » avec motif (n_prod_output_lot.missing_reason) — compté comme renseigné au
 *          kiosque, mais pas de lot ;
 *        - qc_missing : partie sans contrôle qualité (n_prod_output.qc_status NULL, comme OutputService::gaps) ;
 *        - lot_expired : lot utilisé dont la DLC (lot de la partie, sinon registre) est antérieure au jour de production ;
 *        - lot_no_number : lot du registre (n_material_lot) sans numéro (lot_number vide ou off_reason « no_number ») ;
 *      la note de clôture (n_prod_day_close.note) est affichée : exigée au kiosque quand il y avait des trous, elle met
 *      la journée à vérifier même si les trous ont été comblés depuis ;
 *   2. Do wyjaśnienia : un résultat par journée de groupe (n_lots_qc_check) — Uzupełnione (vérifié : plus aucun trou),
 *      Niezgodność (NC) avec note (lien vers la création de NC existante, aucune NC créée ici), Zaakceptowane avec note ;
 *   3. Zatwierdź : résumé, « Zakończ » écrit n_wizard_done — la tâche du poste se valide seule.
 * Lecture seule hors de n_lots_qc_check et n_wizard_done ; aucun e-mail ni SMS.
 */
final class LotsQcDayService
{
    public const CODE = 'lots_qc_day_checked';
    public const PATH = '/workshop/traceability/day-check';
    public const RESULTS = ['completed' => 'Uzupełnione', 'nc' => 'Niezgodność (NC)', 'accepted' => 'Zaakceptowane'];
    /** Trous d'une partie, dans l'ordre d'affichage. */
    public const GAPS = ['lot_missing' => 'brak partii surowca', 'lot_reason' => 'brak partii — powód', 'qc_missing' => 'brak kontroli jakości',
        'lot_expired' => 'partia po dacie ważności', 'lot_no_number' => 'partia bez numeru w rejestrze'];
    /** Création de NC existante (Produkcja › Niezgodności, même droit « production ») : le formulaire s'ouvre avec #new. */
    public const NC_LINK = '/purchasing/nc#new';
    /** Détail des parties d'une journée (Kontrola wejść produkcji, même droit). */
    public const CHECK_LINK = '/workshop/traceability/check';
    private const CATCHUP = ['lots_qc.catchup_days', 7];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    /** @var array<string, array{materials: array<int, string>, cuts: array<int, string>}> recette|groupe => lots attendus */
    private array $spec = [];

    public function __construct(private LotsQcDayRepository $repo, private OutputRepository $outputs, private CompositionCut $cut,
                                private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Jours rattrapés au plus (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
    public function catchupDays(): int
    {
        [$code, $default] = self::CATCHUP;
        $raw = trim($this->params->get($code, (string) $default));
        $v = ctype_digit($raw) ? (int) $raw : $default;
        [$min, $max] = ParamRules::RANGES[$code];
        return $v < $min || $v > $max ? $default : $v;
    }

    /** Jour ouvré précédent (week.production_days, lundi–vendredi par défaut). */
    public function previousWorkday(string $today): string
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        $d = $today;
        for ($i = 0; $i < 10; $i++) {
            $d = self::addDays($d, -1);
            if (in_array((int) date('N', strtotime($d)), $days, true)) return $d;
        }
        return self::addDays($today, -1);
    }

    /**
     * Fenêtre : du jour ouvré précédent à aujourd'hui (aujourd'hui = journées déjà closes) ; si la dernière validation
     * est plus ancienne, depuis son jour (rattrapage), au plus lots_qc.catchup_days jours en arrière.
     */
    public function window(string $today): array
    {
        $prev = $this->previousWorkday($today);
        $last = $this->repo->lastDoneDay(self::CODE, $today);
        $from = $prev;
        if ($last !== null && $last < $prev) $from = min($prev, max($last, self::addDays($today, -$this->catchupDays())));
        $kind = $from < $prev ? 'catchup' : 'workday';
        return ['from' => $from, 'to' => $today, 'kind' => $kind, 'last_done' => $last,
            'label' => self::label($from) . ' – ' . self::label($today) . ($kind === 'catchup' ? ' (nadrabianie od ostatniego zatwierdzenia)' : '')];
    }

    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $w = $this->window($today);
        $outputs = $this->repo->outputs($w['from'], $w['to']);
        $ids = array_map(fn($o) => (int) $o['id'], $outputs);
        $lots = $this->repo->lots($ids);
        $comps = $this->repo->components($ids);
        $byDay = [];
        foreach ($outputs as $o) {
            $b = $this->batch($o, $lots[(int) $o['id']] ?? [], $comps[(int) $o['id']] ?? []);
            $byDay[substr((string) $o['work_date'], 0, 10) . '|' . (int) $o['id_group']][] = $b;
        }
        $checks = $this->repo->checks($w['from'], $w['to']);

        $days = [];
        foreach ($this->repo->closes($w['from'], $w['to']) as $c) {
            $date = substr((string) $c['work_date'], 0, 10);
            $key = $date . '|' . (int) $c['id_group'];
            $batches = $byDay[$key] ?? [];
            $counts = array_fill_keys(array_keys(self::GAPS), 0);
            foreach ($batches as $b) foreach ($b['gaps'] as $g) $counts[$g['code']]++;
            $gaps = array_sum($counts);
            $note = $c['note'] !== null && trim((string) $c['note']) !== '' ? trim((string) $c['note']) : null;
            $ck = $checks[$key] ?? null;
            // À vérifier : un trou, la note de clôture du kiosque, ou un résultat déjà enregistré (la journée reste visible
            // une fois ses trous comblés).
            $review = $gaps > 0 || $note !== null || $ck !== null;
            $check = $ck ? ['result' => (string) $ck['result'], 'label' => self::RESULTS[$ck['result']] ?? (string) $ck['result'], 'note' => $ck['note'],
                'gaps' => (int) $ck['gaps'], 'by' => $ck['by_name'], 'on' => substr((string) $ck['checked_on'], 0, 10), 'at' => self::hm((string) $ck['updated_at'])] : null;
            // « Uzupełnione » ne vaut que tant qu'aucun trou n'est revenu (vérifié à chaque lecture).
            $waiting = $review && ($check === null || ($check['result'] === 'completed' && $gaps > 0));
            $days[] = ['key' => $key, 'work_date' => $date, 'day_label' => self::label($date), 'today' => $date === $today,
                'group' => ['id' => (int) $c['id_group'], 'name' => (string) $c['group_name'], 'color' => $c['color'] ?? null],
                'closed_at' => self::hm((string) $c['closed_at']), 'closed_by' => trim((string) $c['employee_name']) !== '' ? trim((string) $c['employee_name']) : null,
                'note' => $note, 'batches' => count($batches), 'gap_batches' => array_values(array_filter($batches, fn($b) => $b['gaps'] || $b['qc'] === 'nc')),
                'qc_done' => count(array_filter($batches, fn($b) => $b['qc'] !== null)), 'qc_nc' => count(array_filter($batches, fn($b) => $b['qc'] === 'nc')),
                'counts' => $counts, 'gaps' => $gaps, 'review' => $review, 'check' => $check, 'waiting' => $waiting,
                'check_link' => self::CHECK_LINK . '?date=' . $date];
        }

        $unclosed = array_map(fn($u) => ['work_date' => substr((string) $u['work_date'], 0, 10), 'day_label' => self::label(substr((string) $u['work_date'], 0, 10)),
            'group' => (string) $u['group_name'], 'batches' => (int) $u['batches']], $this->repo->unclosed($w['from'], $w['to']));
        $reviewed = array_values(array_filter($days, fn($d) => $d['review']));
        $count = fn(string $res) => count(array_filter($reviewed, fn($d) => !$d['waiting'] && $d['check'] !== null && $d['check']['result'] === $res));
        $byCode = array_fill_keys(array_keys(self::GAPS), 0);
        foreach ($days as $d) foreach ($d['counts'] as $k => $n) $byCode[$k] += $n;
        $totals = ['days' => count($days), 'batches' => array_sum(array_column($days, 'batches')), 'review' => count($reviewed),
            'clean' => count($days) - count($reviewed), 'waiting' => count(array_filter($days, fn($d) => $d['waiting'])),
            'resolved' => count(array_filter($reviewed, fn($d) => !$d['waiting'])), 'gaps' => array_sum($byCode), 'by_code' => $byCode,
            'gap_batches' => array_sum(array_map(fn($d) => count(array_filter($d['gap_batches'], fn($b) => $b['gaps'])), $days)),
            'notes' => count(array_filter($days, fn($d) => $d['note'] !== null)), 'qc_nc' => array_sum(array_column($days, 'qc_nc')),
            'unclosed' => count($unclosed), 'completed' => $count('completed'), 'nc' => $count('nc'), 'accepted' => $count('accepted')];
        $done = $this->wizards->done(self::CODE, $today, self::addDays($today, 1));
        return ['today' => $today, 'label' => self::label($today), 'window' => $w, 'catchup_days' => $this->catchupDays(),
            'days' => $days, 'unclosed' => $unclosed, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($totals),
            'results' => self::RESULTS, 'gap_labels' => self::GAPS, 'nc_link' => self::NC_LINK,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /**
     * Une partie : lots attendus (recette aplatie après CompositionCut, surowce suivis + compositions d'autres groupes),
     * lots renseignés, trous. @return array{id:int, lot_code:string, product:string, quantity:float, unit:?string, qc:?string,
     *   qc_at:?string, qc_note:?string, nc_id:?int, lots_total:int, lots_ok:int, gaps: list<array{code:string, label:string, detail:string}>}
     */
    private function batch(array $o, array $lots, array $comps): array
    {
        $date = substr((string) $o['work_date'], 0, 10);
        $spec = $o['id_recipe'] !== null ? $this->spec((int) $o['id_recipe'], (int) $o['id_group']) : ['materials' => [], 'cuts' => []];
        $gaps = [];
        $gap = function (string $code, string $detail) use (&$gaps) { $gaps[] = ['code' => $code, 'label' => self::GAPS[$code], 'detail' => $detail]; };
        $has = fn(?array $l) => $l !== null && trim((string) $l['lot_number']) !== '';
        foreach ($spec['materials'] as $mid => $name) {
            $l = $lots[$mid] ?? null;
            if ($has($l)) continue;
            if ($l !== null && $l['missing_reason'] !== null && trim((string) $l['missing_reason']) !== '') $gap('lot_reason', $name . ' : ' . trim((string) $l['missing_reason']));
            else $gap('lot_missing', $name);
        }
        foreach ($spec['cuts'] as $rid => $c) if (($comps[$rid] ?? null) === null) $gap('lot_missing', $c['name'] . ' (partia grupy ' . $c['producer'] . ')');
        // Lots utilisés : DLC au jour de production, numéro dans le registre (toutes les lignes renseignées de la partie).
        foreach ($lots as $l) {
            if (!$has($l)) continue;
            $name = (string) ($l['material_name'] ?? ('surowiec #' . (int) $l['id_material']));
            $bb = self::date($l['best_before']) ?? self::date($l['reg_best_before']);
            if ($bb !== null && $bb < $date) $gap('lot_expired', sprintf('%s · partia %s · DLC %s (produkcja %s)', $name, trim((string) $l['lot_number']), self::dmy($bb), self::dmy($date)));
            if ($l['id_material_lot'] !== null && (trim((string) $l['reg_lot_number']) === '' || $l['reg_off_reason'] === 'no_number')) {
                $gap('lot_no_number', $name . ' · partia ' . trim((string) $l['lot_number']) . ' — w rejestrze partii surowców bez numeru');
            }
        }
        if ($o['qc_status'] === null) $gap('qc_missing', 'partia bez kontroli jakości na tablecie');
        usort($gaps, fn($a, $b) => array_search($a['code'], array_keys(self::GAPS), true) <=> array_search($b['code'], array_keys(self::GAPS), true));
        $total = count($spec['materials']) + count($spec['cuts']);
        $missing = count(array_filter($gaps, fn($g) => $g['code'] === 'lot_missing'));
        return ['id' => (int) $o['id'], 'lot_code' => (string) $o['lot_code'], 'product' => (string) ($o['item_name'] ?? ('#' . (int) $o['id_item'])),
            'quantity' => round((float) $o['quantity'], 3), 'unit' => $o['base_unit'] ?? null, 'qc' => $o['qc_status'] ?? null,
            'qc_at' => $o['qc_at'] ? self::hm((string) $o['qc_at']) : null, 'qc_note' => $o['qc_note'] ?? null, 'nc_id' => $o['id_nc'] !== null ? (int) $o['id_nc'] : null,
            'lots_total' => $total, 'lots_ok' => max(0, $total - $missing), 'lots_done_kiosk' => min($total, (int) $o['lots_done']), 'gaps' => $gaps];
    }

    /** Lots attendus d'une recette dans un groupe (comme OutputService::lotsTotal) : surowce suivis et compositions coupées. */
    private function spec(int $recipeId, int $groupId): array
    {
        $key = $recipeId . '|' . $groupId;
        if (isset($this->spec[$key])) return $this->spec[$key];
        $cut = $this->cut->of($recipeId, $groupId);
        $materials = [];
        foreach (CompositionCut::apply($this->outputs->recipe($recipeId), $cut['minus']) as $r) {
            if ((int) ($r['tracked'] ?? 1) === 1) $materials[(int) $r['material_id']] = (string) $r['name'];
        }
        $cuts = [];
        foreach ($cut['cuts'] as $c) $cuts[(int) $c['recipe_id']] = ['name' => (string) $c['name'], 'producer' => (string) ($c['producer']['name_pl'] ?? '')];
        return $this->spec[$key] = ['materials' => $materials, 'cuts' => $cuts];
    }

    /** Krok 2 : résultat pour une journée de groupe à vérifier (un par journée, le dernier compte). */
    public function saveResult(array $input, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $date = (string) ($input['work_date'] ?? '');
        $groupId = (int) ($input['id_group'] ?? 0);
        $result = (string) ($input['result'] ?? '');
        $note = trim((string) ($input['note'] ?? ''));
        if (!isset(self::RESULTS[$result])) throw new CustomException('Wybierz wynik : uzupełnione, niezgodność (NC) albo zaakceptowane.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if ($result === 'nc' && mb_strlen($note) < 3) throw new CustomException('Niezgodność : wpisz notatkę (numer NC, co się stało, działanie).', 'errors', 422);
        if ($result === 'accepted' && mb_strlen($note) < 3) throw new CustomException('Zaakceptowane : wpisz notatkę (dlaczego brak jest akceptowalny).', 'errors', 422);
        $d = $this->data($today);
        $day = array_values(array_filter($d['days'], fn($x) => $x['work_date'] === $date && $x['group']['id'] === $groupId))[0] ?? null;
        if ($day === null) throw new CustomException('Tego dnia grupy nie ma na liście (dzień niezatwierdzony na tablecie albo poza zakresem).', 'errors', 422);
        // Journée sans trou ni note : seul « Uzupełnione » a un sens (trous comblés entre l'affichage et l'enregistrement).
        if (!$day['review'] && $result !== 'completed') throw new CustomException('Ten dzień grupy nie ma braków — wystarczy « Uzupełnione ».', 'errors', 422);
        if ($result === 'completed' && $day['gaps'] > 0) {
            $left = implode(', ', array_map(fn($k, $n) => $n . ' × ' . self::GAPS[$k], array_keys(array_filter($day['counts'])), array_filter($day['counts'])));
            throw new CustomException('Nadal brakuje : ' . $left . '. Uzupełnij na tablecie grupy (numery partii, kontrola jakości) albo wybierz Niezgodność (NC) lub Zaakceptowane.', 'errors', 422);
        }
        $flags = implode(',', array_map(fn($k, $n) => $k . ':' . $n, array_keys(array_filter($day['counts'])), array_filter($day['counts'])));
        $this->repo->saveCheck($date, $groupId, $result, $note !== '' ? $note : null, $flags, $day['gaps'], $today, $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — tylko gdy każdy dzień grupy do wyjaśnienia ma wynik ; zadanie stanowiska zalicza się samo. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw wyjaśnij ' . $w . ' ' . self::pl($w, 'dzień grupy', 'dni grup', 'dni grup') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « 6 dni grup · 4 braki w 2 dniach · 1 uzupełnione · 1 NC » (résumé sur la carte de la tâche). */
    public static function summary(array $t): string
    {
        if ($t['days'] === 0) return 'brak zatwierdzonych dni grup';
        $s = $t['days'] . ' ' . self::pl($t['days'], 'dzień grupy', 'dni grup', 'dni grup') . ' · ' . $t['batches'] . ' ' . self::pl($t['batches'], 'partia', 'partie', 'partii');
        if ($t['review'] === 0) return $s . ' · bez braków';
        $s .= ' · ' . $t['gaps'] . ' ' . self::pl($t['gaps'], 'brak', 'braki', 'braków') . ' w ' . $t['review'] . ' ' . self::pl($t['review'], 'dniu', 'dniach', 'dniach');
        foreach (['completed' => ['uzupełnione', 'uzupełnione', 'uzupełnionych'], 'nc' => ['NC', 'NC', 'NC'], 'accepted' => ['zaakceptowane', 'zaakceptowane', 'zaakceptowanych']] as $k => $f) {
            if ($t[$k]) $s .= ' · ' . $t[$k] . ' ' . self::pl($t[$k], ...$f);
        }
        return $s;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function dmy(string $day): string
    {
        return date('d.m.Y', strtotime($day));
    }

    private static function date(mixed $v): ?string
    {
        $v = $v === null ? '' : substr((string) $v, 0, 10);
        return $v === '' || $v === '0000-00-00' ? null : $v;
    }

    private static function addDays(string $day, int $n): string
    {
        return date('Y-m-d', strtotime($day . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
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
