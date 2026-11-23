<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Core\Helpers\ComplaintReasonTranslator;
use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Client\Complaint\ComplaintsReviewRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Asystent « Reklamacje klientów » [379] (Specjalista ds. jakości, chaque semaine ; obligation 3 « Reklamacje klientów :
 * ocena, odpowiedź, działania » de la matrice 224). Les réclamations arrivent des magasins (ComplaintCollectorService),
 * le bureau décide et répond dans « Reklamacje » (/franchise-complaints : statut, production_response), la réclamation
 * acceptée s'affiche sur le tablet du groupe du produit qui confirme « Widziane » (n_complaint_seen, [367]).
 * L'assistant contrôle que rien ne reste en chemin :
 *   1. Przegląd : réclamations ouvertes ou mal suivies, avec les signaux :
 *        - open_late : NEW / IN_REVIEW (statut vide = NEW, comme ComplaintRepository::chartRows) depuis plus de
 *          complaints_review.open_days jours — jour de signalement reported_at, sinon acceptance_timestamp (même règle
 *          que le graphique des réclamations) ;
 *        - no_response : ACCEPTED sans réponse au magasin (production_response vide) ;
 *        - not_seen : ACCEPTED, réclamation produit, pas de « Widziane » du groupe du produit depuis plus de
 *          complaints_review.open_days jours après l'acceptation (acceptance_timestamp, date d'acceptation lue par le
 *          tablet : ComplaintBoardRepository::accepted) ;
 *        - no_group : ACCEPTED, produit sans groupe de production — aucun tablet ne la montre ;
 *      (les trois derniers sur les réclamations acceptées depuis complaints_review.lookback_days jours) ; puis les
 *      réclamations de la semaine écoulée (lundi → dimanche, jour de signalement) par type (complaint_type), motif
 *      (complaint_reason, ComplaintReasonTranslator), client, produit et groupe ; valeur au carton entamé
 *      (ComplaintRepository::valueSql) seulement pour le profil Finanse / CEO (PageAccessService::seesOrderValues, [312]) —
 *      sinon aucune colonne de valeur n'est lue ; NC « klient » (n_nc.source = 'customer') rapprochées : même produit,
 *      enregistrées de 0 à NC_MATCH_DAYS jours après la réclamation (n_nc n'a pas de colonne vers la réclamation) ;
 *   2. Do wyjaśnienia : une décision par réclamation signalée (n_complaint_review, par semaine examinée) — Odpowiedź /
 *      zamknięta (note ; vérifiée : réclamation REJECTED, ou ACCEPTED avec réponse, au moment de l'enregistrement),
 *      Niezgodność (NC) (note ; lien vers le registre NC existant, aucune NC créée ici), Do obserwacji (note obligatoire) ;
 *      lien vers la liste des réclamations (la fenêtre de détail de complaints-chart.js n'a pas d'URL propre) ;
 *   3. Zatwierdź : nombres par type et motif, réclamations récurrentes (même client et même produit, au moins 2 sur
 *      RECURRING_WEEKS semaines jusqu'à la fin de la semaine examinée), « Zakończ » écrit n_wizard_done — bloqué tant
 *      qu'une réclamation signalée attend ; la tâche du poste se valide seule.
 * Lecture seule hors de n_complaint_review et n_wizard_done : aucun statut de réclamation changé, aucune NC créée, aucun
 * e-mail ni SMS. Le résumé (carte de la tâche) ne contient jamais de montant.
 */
final class ComplaintsReviewService
{
    public const CODE = 'complaints_reviewed';
    public const PATH = '/franchise-complaints/review';
    public const DECISIONS = ['answered' => 'Odpowiedź / zamknięta', 'nc' => 'Niezgodność (NC)', 'watch' => 'Do obserwacji'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['watch'];
    /** Signaux d'une réclamation, dans l'ordre de priorité. */
    public const SIGNALS = ['open_late' => 'bez decyzji', 'no_response' => 'przyjęta bez odpowiedzi do magazynu', 'not_seen' => 'grupa nie potwierdziła « Widziane »',
        'no_group' => 'produkt bez grupy produkcji'];
    /** Statuts (complaint.acceptance_status) : libellés de ComplaintService::detail. */
    public const STATUS = ['NEW' => 'Nowa', 'IN_REVIEW' => 'W weryfikacji', 'ACCEPTED' => 'Przyjęta', 'REJECTED' => 'Odrzucona'];
    /** Types (complaint.complaint_type, migration 039) : libellés de la liste des réclamations. */
    public const TYPES = ['PRODUCT' => 'Reklamacja produktowa', 'NON_PRODUCT' => 'Inna reklamacja'];
    private const NC_STATUS = ['open' => 'otwarta', 'in_progress' => 'w toku', 'closed' => 'zamknięta'];
    /** Écrans existants : liste des réclamations (décision, réponse, graphique et fenêtre de détail), registre NC (#new = formulaire). */
    public const LIST_LINK = '/franchise-complaints';
    public const NC_LINK = '/purchasing/nc#new';
    public const RECURRING_WEEKS = 8;
    /** Rapprochement d'une NC « klient » : enregistrée au plus tant de jours après la réclamation, même produit. */
    public const NC_MATCH_DAYS = 30;
    private const PARAMS = ['open' => ['complaints_review.open_days', 3], 'lookback' => ['complaints_review.lookback_days', 56]];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private ComplaintsReviewRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Réglage complaints_review.* (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
    public function param(string $name): int
    {
        [$code, $default] = self::PARAMS[$name];
        $raw = trim($this->params->get($code, (string) $default));
        $v = ctype_digit($raw) ? (int) $raw : $default;
        [$min, $max] = ParamRules::RANGES[$code];
        return $v < $min || $v > $max ? $default : $v;
    }

    public static function monday(string $day): string
    {
        return self::addDays($day, 1 - (int) date('N', strtotime($day)));
    }

    /** Semaine examinée : celle qui précède la semaine de $today (lundi). */
    public static function reviewedWeek(string $today): string
    {
        return self::addDays(self::monday($today), -7);
    }

    /**
     * Données des trois étapes.
     * @param bool $values montants (profil Finanse / CEO, [312]) : sans eux, aucune valeur n'est lue ni renvoyée
     */
    public function data(?string $today = null, bool $values = false): array
    {
        $today ??= date('Y-m-d');
        $openDays = $this->param('open');
        $lookback = $this->param('lookback');
        $week = self::reviewedWeek($today);
        $weekEnd = self::addDays($week, 6);
        $recurFrom = self::addDays($weekEnd, 1 - 7 * self::RECURRING_WEEKS);
        $acceptedFrom = self::addDays($today, -$lookback);
        $rows = $this->repo->complaints($acceptedFrom, $recurFrom, $week, $values);
        $seen = $this->repo->seen(array_column($rows, 'id'));
        $groups = $this->repo->groups();
        $first = $rows ? min(array_filter(array_map(fn($r) => self::date($r['reported_at'] ?? null) ?? self::date($r['acceptance_timestamp'] ?? null), $rows)) ?: [$recurFrom]) : $recurFrom;
        $ncs = $this->repo->customerNcs(min($first, $recurFrom));

        $items = [];
        foreach ($rows as $r) $items[] = $this->item($r, $today, $openDays, $acceptedFrom, $seen[(int) $r['id']] ?? [], $groups, $ncs, $values, $week, $weekEnd);
        $rank = array_flip(array_keys(self::SIGNALS));
        $review = array_values(array_filter($items, fn($c) => $c['review']));
        usort($review, fn($a, $b) => [$a['signals'] ? $rank[$a['signals'][0]['code']] : 99, $a['reported'] ?? '', $a['id']] <=> [$b['signals'] ? $rank[$b['signals'][0]['code']] : 99, $b['reported'] ?? '', $b['id']]);
        $inWeek = array_values(array_filter($items, fn($c) => $c['in_week']));

        $byCode = array_fill_keys(array_keys(self::SIGNALS), 0);
        foreach ($items as $c) foreach ($c['signals'] as $s) $byCode[$s['code']]++;
        $decided = fn(string $k) => count(array_filter($review, fn($c) => $c['decision'] !== null && $c['decision']['code'] === $k));
        $open = array_filter($items, fn($c) => in_array($c['status'], ['NEW', 'IN_REVIEW'], true));
        $recurring = self::recurring(array_values(array_filter($items, fn($c) => $c['reported'] !== null && $c['reported'] >= $recurFrom && $c['reported'] <= $weekEnd)));
        $totals = ['open' => count($open), 'open_new' => count(array_filter($open, fn($c) => $c['status'] === 'NEW')),
            'open_review' => count(array_filter($open, fn($c) => $c['status'] === 'IN_REVIEW')),
            'by_code' => $byCode, 'review' => count($review), 'waiting' => count(array_filter($review, fn($c) => $c['waiting'])),
            'resolved' => count(array_filter($review, fn($c) => !$c['waiting'])),
            'answered' => $decided('answered'), 'nc' => $decided('nc'), 'watch' => $decided('watch'),
            'week' => count($inWeek), 'week_product' => count(array_filter($inWeek, fn($c) => $c['type'] === 'PRODUCT')),
            'week_other' => count(array_filter($inWeek, fn($c) => $c['type'] !== 'PRODUCT')),
            'week_accepted' => count(array_filter($inWeek, fn($c) => $c['status'] === 'ACCEPTED')), 'week_rejected' => count(array_filter($inWeek, fn($c) => $c['status'] === 'REJECTED')),
            'week_open' => count(array_filter($inWeek, fn($c) => in_array($c['status'], ['NEW', 'IN_REVIEW'], true))),
            'week_customers' => count(array_unique(array_filter(array_map(fn($c) => $c['customer']['id'], $inWeek), fn($v) => $v !== null))),
            'recurring' => count($recurring)];
        if ($values) {
            $totals['week_value'] = round(array_sum(array_map(fn($c) => $c['value'], $inWeek)), 2);
            $totals['week_accepted_value'] = round(array_sum(array_map(fn($c) => $c['status'] === 'ACCEPTED' ? $c['accepted_value'] : 0.0, $inWeek)), 2);
        }
        $done = $this->wizards->done(self::CODE, self::monday($today), self::addDays(self::monday($today), 7));
        return ['today' => $today, 'label' => self::label($today), 'week_start' => $week, 'week_end' => $weekEnd,
            'week_label' => self::dm($week) . ' – ' . self::dm($weekEnd) . '.' . substr($weekEnd, 0, 4), 'recurring_from' => $recurFrom, 'recurring_weeks' => self::RECURRING_WEEKS,
            'open_days' => $openDays, 'lookback_days' => $lookback, 'values' => $values,
            'review' => $review, 'week' => $inWeek, 'totals' => $totals,
            'breakdown' => ['types' => self::groupBy($inWeek, fn($c) => [$c['type'], $c['type_label']], $values),
                'reasons' => self::groupBy($inWeek, fn($c) => [$c['reason_code'] ?? '', $c['reason']], $values),
                'customers' => self::groupBy($inWeek, fn($c) => [(string) ($c['customer']['id'] ?? ''), $c['customer']['name']], $values),
                'products' => self::groupBy(array_values(array_filter($inWeek, fn($c) => $c['product'] !== null)), fn($c) => [(string) $c['product']['id'], $c['product']['name']], $values),
                'groups' => self::groupBy(array_values(array_filter($inWeek, fn($c) => $c['type'] === 'PRODUCT')), fn($c) => $c['group'] ? [(string) $c['group']['id'], $c['group']['name']] : ['', 'bez grupy'], $values)],
            'recurring' => $recurring, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'signal_labels' => self::SIGNALS,
            'list_link' => self::LIST_LINK, 'nc_link' => self::NC_LINK,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Une réclamation : client, produit, groupe, statut, signaux, NC rapprochées, décision de la semaine. */
    private function item(array $r, string $today, int $openDays, string $acceptedFrom, array $seen, array $groups, array $ncs, bool $values, string $week, string $weekEnd): array
    {
        $id = (int) $r['id'];
        $type = (string) ($r['complaint_type'] ?? 'PRODUCT') === 'NON_PRODUCT' ? 'NON_PRODUCT' : 'PRODUCT';
        $status = (string) $r['status'];
        $reported = self::date($r['reported_at'] ?? null) ?? self::date($r['acceptance_timestamp'] ?? null);
        $acceptedOn = $status === 'ACCEPTED' ? self::date($r['acceptance_timestamp'] ?? null) : null;
        $product = $r['id_product'] !== null && $type === 'PRODUCT' ? ['id' => (int) $r['id_product'], 'name' => (string) ($r['product'] ?? ('produkt #' . (int) $r['id_product']))] : null;
        $gid = $r['group_id'] !== null ? (int) $r['group_id'] : null;
        $group = $gid !== null ? ['id' => $gid, 'name' => (string) ($groups[$gid] ?? ('grupa #' . $gid))] : null;
        $groupSeen = $gid !== null && isset($seen[$gid]) ? ['at' => self::dm(substr($seen[$gid]['seen_at'], 0, 10)) . ' ' . self::hm($seen[$gid]['seen_at']), 'by' => $seen[$gid]['name']] : null;
        $response = trim((string) ($r['production_response'] ?? ''));
        $age = $reported !== null ? self::days($reported, $today) : null;

        $signals = [];
        $sig = function (string $code, string $detail) use (&$signals) { $signals[] = ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail]; };
        if (in_array($status, ['NEW', 'IN_REVIEW'], true) && $age !== null && $age > $openDays) {
            $sig('open_late', self::STATUS[$status] . ' · zgłoszona ' . self::label($reported) . ' · ' . $age . ' ' . self::pl($age, 'dzień', 'dni', 'dni') . ' bez decyzji');
        }
        if ($acceptedOn !== null && $acceptedOn >= $acceptedFrom) {
            $accAge = self::days($acceptedOn, $today);
            if ($response === '') $sig('no_response', 'przyjęta ' . self::label($acceptedOn) . ' — pole « Odpowiedź » puste, magazyn nie wie, co dalej');
            if ($type === 'PRODUCT' && $product !== null) {
                if ($group === null) $sig('no_group', $product['name'] . ' nie ma grupy produkcji — żaden tablet nie pokaże tej reklamacji');
                elseif ($groupSeen === null && $accAge > $openDays) $sig('not_seen', 'grupa ' . $group['name'] . ' — przyjęta ' . self::label($acceptedOn) . ', ' . $accAge . ' ' . self::pl($accAge, 'dzień', 'dni', 'dni') . ' bez « Widziane » na tablecie');
            }
        }
        $linked = [];
        if ($product !== null && $reported !== null) {
            $until = self::addDays($reported, self::NC_MATCH_DAYS);
            foreach ($ncs as $n) {
                $on = substr((string) $n['reported_at'], 0, 10);
                if ((int) $n['id_final_product'] === $product['id'] && $on >= $reported && $on <= $until) {
                    $linked[] = ['id' => (int) $n['id'], 'number' => (string) $n['number'], 'on' => $on, 'on_label' => self::label($on), 'status' => (string) $n['status'],
                        'status_label' => self::NC_STATUS[$n['status']] ?? (string) $n['status']];
                }
            }
        }
        $decision = $r['decision'] !== null ? ['code' => (string) $r['decision'], 'label' => self::DECISIONS[$r['decision']] ?? (string) $r['decision'], 'note' => $r['note'],
            'flags' => $r['flags'], 'status_at' => $r['status_at'], 'by' => $r['decided_by'], 'at' => self::label(substr((string) $r['decided_at'], 0, 10)) . ', ' . self::hm((string) $r['decided_at'])] : null;
        $review = $signals !== [] || $decision !== null;
        $comment = trim((string) ($r['client_comment'] ?? ''));
        $out = ['id' => $id, 'key' => (string) $id, 'type' => $type, 'type_label' => self::TYPES[$type],
            'reason_code' => $r['complaint_reason'] !== null && $r['complaint_reason'] !== '' ? (string) $r['complaint_reason'] : null,
            'reason' => ComplaintReasonTranslator::translate($r['complaint_reason'] ?? null, 'bez powodu'),
            'customer' => ['id' => $r['id_customer'] !== null ? (int) $r['id_customer'] : null, 'name' => trim((string) ($r['customer'] ?? '')) !== '' ? (string) $r['customer'] : ('klient #' . (int) $r['id_customer'])],
            'product' => $product, 'group' => $group, 'seen' => $groupSeen,
            'status' => $status, 'status_label' => self::STATUS[$status] ?? $status,
            'reported' => $reported, 'reported_label' => $reported !== null ? self::label($reported) : '—', 'age' => $age,
            'accepted_on' => $acceptedOn, 'accepted_label' => $acceptedOn !== null ? self::label($acceptedOn) : null,
            'quantity' => $r['product_quantity'] !== null ? round((float) $r['product_quantity'], 2) : null,
            'accepted_quantity' => $r['accepted_quantity'] !== null ? round((float) $r['accepted_quantity'], 2) : null,
            'comment' => $comment !== '' ? mb_substr($comment, 0, 200) : null, 'response' => $response !== '' ? mb_substr($response, 0, 200) : null,
            'in_week' => $reported !== null && $reported >= $week && $reported <= $weekEnd,
            'signals' => $signals, 'ncs' => $linked, 'decision' => $decision, 'review' => $review, 'waiting' => $signals !== [] && $decision === null];
        if ($values) {
            $out['value'] = round((float) ($r['value'] ?? 0), 2);
            $out['accepted_value'] = $status === 'ACCEPTED' ? round((float) ($r['accepted_value'] ?? 0), 2) : 0.0;
        }
        return $out;
    }

    /** Comptes par clé (le plus fréquent d'abord) : réclamations, przyjęte, odrzucone, otwarte (+ valeur si autorisée). */
    private static function groupBy(array $items, callable $key, bool $values): array
    {
        $out = [];
        foreach ($items as $c) {
            [$k, $label] = $key($c);
            $g = &$out[$k];
            $g ??= ['key' => $k, 'label' => $label, 'count' => 0, 'accepted' => 0, 'rejected' => 0, 'open' => 0] + ($values ? ['value' => 0.0] : []);
            $g['count']++;
            if ($c['status'] === 'ACCEPTED') $g['accepted']++;
            elseif ($c['status'] === 'REJECTED') $g['rejected']++;
            else $g['open']++;
            if ($values) $g['value'] = round($g['value'] + $c['value'], 2);
            unset($g);
        }
        $out = array_values($out);
        usort($out, fn($a, $b) => [-$a['count'], $a['label']] <=> [-$b['count'], $b['label']]);
        return $out;
    }

    /** Même client et même produit au moins deux fois sur RECURRING_WEEKS semaines (réclamations produit). */
    private static function recurring(array $items): array
    {
        $by = [];
        foreach ($items as $c) {
            if ($c['product'] === null || $c['customer']['id'] === null) continue;
            $by[$c['customer']['id'] . '|' . $c['product']['id']][] = $c;
        }
        $out = [];
        foreach ($by as $list) {
            if (count($list) < 2) continue;
            usort($list, fn($a, $b) => [$a['reported'], $a['id']] <=> [$b['reported'], $b['id']]);
            $out[] = ['customer' => $list[0]['customer'], 'product' => $list[0]['product'], 'count' => count($list), 'in_week' => count(array_filter($list, fn($c) => $c['in_week'])),
                'ids' => array_column($list, 'id'), 'dates' => array_map(fn($c) => $c['reported_label'], $list),
                'reasons' => array_values(array_unique(array_column($list, 'reason')))];
        }
        usort($out, fn($a, $b) => [-$a['count'], $a['customer']['name'], $a['product']['name']] <=> [-$b['count'], $b['customer']['name'], $b['product']['name']]);
        return $out;
    }

    /** Krok 2 : décision pour une réclamation signalée (une par semaine examinée, la dernière compte). */
    public function saveDecision(array $in, ?int $userId, bool $values = false, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($in['id_complaint'] ?? 0);
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : odpowiedź / zamknięta, niezgodność (NC) albo do obserwacji.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if (in_array($decision, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException('Do obserwacji : wpisz notatkę (co obserwujemy, do kiedy, kto).', 'errors', 422);
        }
        $d = $this->data($today);
        $c = array_values(array_filter($d['review'], fn($x) => $x['id'] === $id))[0] ?? null;
        if ($c === null) throw new CustomException('Tej reklamacji nie ma na liście do wyjaśnienia (bez sygnału w ocenianym tygodniu).', 'errors', 422);
        // « Odpowiedź / zamknięta » : vérifiée sur l'état réel de la réclamation (l'assistant ne change aucun statut).
        if ($decision === 'answered' && !($c['status'] === 'REJECTED' || ($c['status'] === 'ACCEPTED' && $c['response'] !== null))) {
            $why = $c['status'] === 'ACCEPTED' ? 'reklamacja przyjęta, ale bez odpowiedzi do magazynu' : 'reklamacja nadal « ' . $c['status_label'] . ' » — bez decyzji';
            throw new CustomException('Nie można zapisać « Odpowiedź / zamknięta » : ' . $why . '. Zapisz decyzję i odpowiedź w « Reklamacje » (lista reklamacji), albo wybierz « Niezgodność (NC) » lub « Do obserwacji ».', 'errors', 422);
        }
        $flags = implode(',', array_column($c['signals'], 'code'));
        $this->repo->saveDecision($d['week_start'], $id, $decision, $note !== '' ? $note : null, $flags, $c['status'], $userId);
        return $this->data($today, $values);
    }

    /** Krok 3 : « Zakończ » — chaque réclamation signalée a sa décision ; la tâche de la semaine se valide seule. */
    public function finish(?int $userId, bool $values = false, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'reklamacji', 'reklamacji', 'reklamacji') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today, $values)];
    }

    /** « 7 reklamacji w tygodniu (5 produktowych, 2 inne) · 4 do wyjaśnienia : 1 odpowiedź · 1 NC · 2 do obserwacji · 1 powtarzająca się » — sans montant. */
    public static function summary(array $t): string
    {
        $s = $t['week'] === 0 ? 'brak reklamacji w tygodniu'
            : $t['week'] . ' ' . self::pl($t['week'], 'reklamacja', 'reklamacje', 'reklamacji') . ' w tygodniu (' . $t['week_product'] . ' ' . self::pl($t['week_product'], 'produktowa', 'produktowe', 'produktowych')
                . ', ' . $t['week_other'] . ' ' . self::pl($t['week_other'], 'inna', 'inne', 'innych') . ')';
        if ($t['review'] === 0) $s .= ' · nic do wyjaśnienia';
        else {
            $s .= ' · ' . $t['review'] . ' do wyjaśnienia';
            $parts = [];
            if ($t['answered']) $parts[] = $t['answered'] . ' ' . self::pl($t['answered'], 'odpowiedź', 'odpowiedzi', 'odpowiedzi');
            if ($t['nc']) $parts[] = $t['nc'] . ' NC';
            if ($t['watch']) $parts[] = $t['watch'] . ' do obserwacji';
            if ($parts) $s .= ' : ' . implode(' · ', $parts);
        }
        if ($t['recurring']) $s .= ' · ' . $t['recurring'] . ' ' . self::pl($t['recurring'], 'powtarzająca się', 'powtarzające się', 'powtarzających się');
        return $s;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function dm(string $day): string
    {
        return date('j.m', strtotime($day));
    }

    /** Jours calendaires de $from à $to (0 = le même jour). */
    private static function days(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime(substr($from, 0, 10) . ' 12:00:00')) / 86400);
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
