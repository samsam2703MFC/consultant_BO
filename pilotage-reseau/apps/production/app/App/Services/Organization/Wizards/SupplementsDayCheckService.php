<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\SupplementsDayCheckRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Asystent « Domówienia dnia » [371] (Kierownik operacyjny, chaque jour ; obligation 1.7 : koordynowanie przepływu
 * informacji pomiędzy odbiorcami a produkcją) :
 *   1. Przegląd : domówienia (customer_order.order_type SUPPLEMENT, sans les archivées) passées depuis la validation
 *      précédente de l'assistant (sinon depuis le jour ouvré précédent, 0:00), et domówienia ouvertes à expédier entre
 *      le jour de début de la fenêtre et le jour ouvré suivant ; pour chacune : lignes, commande principale, envoi au
 *      panel du magasin (supplement_push_status) et état côté production (voir production()) ;
 *   2. Do wyjaśnienia : signaux (voir flags()) ; un résultat par domówienie signalée (n_supplement_check) :
 *      przekazane do produkcji / kontakt z klientem (notatka) / przesunięte albo odrzucone (notatka) ;
 *   3. Zatwierdź : résumé, « Zakończ » écrit n_wizard_done — la tâche du poste se valide seule.
 * Lecture seule hors de n_supplement_check : ni la commande ni le plan ne sont modifiés ; aucun e-mail ni SMS.
 *
 * L'« envoi de domówienie » existant (OrderSupplementPusherService, supplement_push_status) part vers le panel du
 * magasin franchisé (webhook), pas vers la production : il est montré comme information, il ne vaut pas transmission
 * à la production. L'ERP n'enregistre aucune « transmission à la production » : l'état est déduit du plan.
 */
final class SupplementsDayCheckService
{
    public const CODE = 'supplements_day_checked';
    public const RESULTS = ['sent' => 'Przekazane do produkcji', 'contact' => 'Kontakt z klientem', 'moved' => 'Przesunięte / odrzucone'];
    /** Résultats qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['contact', 'moved'];
    public const DEFAULT_CUTOFF = '12:00';
    /** États production : du plus grave au plus sûr (l'état d'une domówienie = le plus grave de ses lignes). */
    public const STATES = [
        'missing' => 'poza planem produkcji', 'unplanned' => 'bez karty planowania',
        'live' => 'w propozycji planu', 'stock' => 'ze stanu (MTS)', 'in_plan' => 'w planie produkcji', 'shipped' => 'wysłane',
    ];
    /** États qui valent « transmise à la production » (prise en compte par le calcul du plan, ou déjà expédiée). */
    public const OK_STATES = ['in_plan', 'live', 'stock', 'shipped'];
    private const OF_STATUS = ['planned' => 'zaplanowane', 'released' => 'zwolnione', 'in_progress' => 'w produkcji', 'done' => 'wyprodukowane'];
    private const PUSH = ['sent' => 'w systemie sklepu', 'pending' => 'czeka na wysłanie do sklepu', 'failed' => 'błąd wysłania do sklepu'];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private SupplementsDayCheckRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Jours ouvrés (week.production_days) et heure limite (orders.supplement_cutoff, HH:MM). @return array{workdays:list<int>, cutoff:string} */
    public function settings(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        sort($days);
        $cut = trim($this->params->get('orders.supplement_cutoff', self::DEFAULT_CUTOFF));
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)/', $cut, $m)) $cut = self::DEFAULT_CUTOFF;
        else $cut = sprintf('%02d:%s', (int) $m[1], $m[2]);
        return ['workdays' => array_values(array_unique($days)), 'cutoff' => $cut];
    }

    /** Début de la fenêtre : validation précédente de l'assistant, sinon jour ouvré précédent à 0:00. */
    public function since(string $today, array $workdays): array
    {
        $last = $this->repo->lastDone(self::CODE, $today);
        if ($last !== null) return ['at' => $last, 'kind' => 'done', 'label' => 'od zatwierdzenia ' . self::label(substr($last, 0, 10)) . ', ' . self::hm($last)];
        $day = self::workday($today, -1, $workdays);
        return ['at' => $day . ' 00:00:00', 'kind' => 'workday', 'label' => 'od ' . self::label($day) . ', 0:00 (pierwsze sprawdzenie)'];
    }

    /** Jour ouvré suivant ($dir = 1) ou précédent ($dir = -1) d'un jour (week.production_days). */
    public static function workday(string $day, int $dir, array $workdays): string
    {
        $d = $day;
        for ($i = 0; $i < 10; $i++) {
            $d = self::addDays($d, $dir);
            if (in_array((int) date('N', strtotime($d)), $workdays, true)) return $d;
        }
        return self::addDays($day, $dir);
    }

    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $set = $this->settings();
        $since = $this->since($today, $set['workdays']);
        $next = self::workday($today, 1, $set['workdays']);
        $rows = $this->repo->supplements($since['at'], min(substr($since['at'], 0, 10), $today), $next);
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $lines = $this->repo->lines($ids);
        $demand = $this->repo->demand($ids);
        $dates = array_values(array_filter(array_map(fn($r) => self::date($r['shipping_date']), $rows)));
        $plans = $dates ? $this->repo->plans(min($dates), max($dates)) : [];
        $parents = $this->repo->ordersById(array_map(fn($r) => (int) ($r['parent_order_id'] ?? 0), $rows));
        $sameDay = $dates ? $this->repo->standardByCustomerDay(array_map(fn($r) => (int) $r['id_customer'], $rows), min($dates), max($dates)) : [];
        $checks = $this->repo->checks($today);

        $orders = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $ship = self::date($r['shipping_date']);
            $o = ['id' => $id, 'reference' => $r['reference'] !== null && trim((string) $r['reference']) !== '' ? (string) $r['reference'] : null,
                'description' => $r['order_description'] !== null ? mb_substr(trim((string) $r['order_description']), 0, 120) : null,
                'customer' => (string) $r['company'], 'id_customer' => (int) $r['id_customer'], 'created_at' => (string) $r['create_order_date'],
                'created_label' => self::label(substr((string) $r['create_order_date'], 0, 10)) . ', ' . self::hm((string) $r['create_order_date']),
                'shipping_date' => $ship, 'shipping_label' => $ship ? self::label($ship) : null, 'freebie' => (int) $r['is_freebie'] === 1,
                'lines' => (int) $r['line_count'], 'cartons' => round((float) $r['cartons'], 1),
                'kind' => (string) $r['create_order_date'] >= $since['at'] ? 'new' : 'upcoming',
                'upcoming' => $ship !== null && $ship >= $today && $ship <= $next,
                'status' => (int) $r['status'], 'status_label' => CustomerOrderStatus::labels()[(int) $r['status']] ?? (string) $r['status'], 'open' => (int) $r['is_open'] === 1,
                'push' => isset(self::PUSH[(string) $r['supplement_push_status']]) ? ['status' => (string) $r['supplement_push_status'], 'label' => self::PUSH[(string) $r['supplement_push_status']],
                    'at' => $r['supplement_push_at'] ? self::label(substr((string) $r['supplement_push_at'], 0, 10)) . ', ' . self::hm((string) $r['supplement_push_at']) : null] : null,
                'parent' => self::parent($r, $parents, $sameDay)];
            $o['production'] = self::production($o, $lines[$id] ?? [], $demand[$id] ?? [], $plans);
            $o['products'] = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'cartons' => $l['cartons'], 'state' => $l['state'], 'of' => $l['of']], $o['production']['lines']);
            unset($o['production']['lines']);
            $o['flags'] = $o['open'] ? self::flags($o, $today, $next, $set, $lines[$id] ?? []) : [];
            $ck = $checks[$id] ?? null;
            $o['check'] = $ck ? ['result' => $ck['result'], 'label' => self::RESULTS[$ck['result']] ?? $ck['result'], 'note' => $ck['note'], 'by' => $ck['by_name'], 'at' => self::hm((string) $ck['updated_at'])] : null;
            $orders[] = $o;
        }

        $open = array_values(array_filter($orders, fn($o) => $o['open']));
        $flagged = array_values(array_filter($orders, fn($o) => $o['flags']));
        $resolved = array_values(array_filter($flagged, fn($o) => $o['check'] !== null));
        $count = fn(string $res) => count(array_filter($resolved, fn($o) => $o['check']['result'] === $res));
        $totals = ['supplements' => count($orders), 'new' => count(array_filter($orders, fn($o) => $o['kind'] === 'new')),
            'upcoming' => count(array_filter($open, fn($o) => $o['upcoming'])), 'cartons' => round(array_sum(array_column($orders, 'cartons')), 1),
            'in_production' => count(array_filter($open, fn($o) => $o['production']['ok'])), 'not_in_production' => count(array_filter($open, fn($o) => !$o['production']['ok'])),
            'shipped' => count($orders) - count($open), 'push_failed' => count(array_filter($orders, fn($o) => ($o['push']['status'] ?? null) === 'failed')),
            'flagged' => count($flagged), 'resolved' => count($resolved), 'waiting' => count($flagged) - count($resolved),
            'sent' => $count('sent'), 'contact' => $count('contact'), 'moved' => $count('moved')];
        $done = $this->wizards->done(self::CODE, $today, self::addDays($today, 1));
        return ['today' => $today, 'label' => self::label($today), 'since' => $since, 'next_workday' => $next, 'next_label' => self::label($next),
            'settings' => $set + ['workdays_label' => implode(', ', array_map(fn($d) => self::DAY_ABBR[$d], $set['workdays']))],
            'orders' => $orders, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($totals),
            'results' => self::RESULTS, 'note_required' => self::NOTE_REQUIRED, 'states' => self::STATES,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /**
     * État côté production, par ligne puis pour la domówienie (la ligne la plus grave). Mêmes règles que le calcul du
     * plan (PlanningService::compute / validate, OpenOrders::demandSql) :
     *  - shipped : domówienie fermée (WDT, « W transporcie », « Zrealizowane ») ;
     *  - [408] gratis : mêmes règles que les autres (compté dans le plan et le stock depuis le 23.11.2026) ;
     *  - unplanned : produit sans fiche de planification (n_prod_item + n_prod_product_settings), hors calcul du plan ;
     *  - stock : produit MTS, servi sur le stock (la demande réduit le disponible et déclenche le réassort) ;
     *  - in_plan : MTO relié à un zlecenie du plan (n_prod_order_demand), ou plan du jour d'expédition validé après la
     *    domówienie (elle était dans les commandes ouvertes à la validation : couverte sans zlecenie, p. ex. par le stock) ;
     *  - missing : MTO sans date d'expédition, ou plan du jour d'expédition validé avant la domówienie (ses quantités
     *    sont figées : la domówienie n'y est pas) ;
     *  - live : MTO sans plan validé sur le jour d'expédition : la semaine est calculée sur le vif depuis les commandes
     *    ouvertes (WeekService::sourceLines), la domówienie y entre d'elle-même.
     * @param list<array> $lines lignes du dépôt (id_product, quantity_carton, name, active, id_item, mode)
     * @param list<array{id_final_product:int, code:string, status:string, due_at:string}> $demand zlecenia reliés
     * @param list<array> $plans plans validés / figés / clos recouvrant les dates d'expédition (le plus récent d'abord)
     * @return array{state:string, label:string, ok:bool, detail:?string, lines:list<array>}
     */
    public static function production(array $o, array $lines, array $demand, array $plans): array
    {
        if (empty($o['open'])) {
            return ['state' => 'shipped', 'label' => self::STATES['shipped'], 'ok' => true, 'detail' => $o['status_label'] ?? null, 'lines' => self::lineList($lines)];
        }
        $ship = $o['shipping_date'] ?? null;
        $plan = null;
        if ($ship !== null) {
            foreach ($plans as $p) if ((string) $p['period_start'] <= $ship && (string) $p['period_end'] >= $ship) { $plan = $p; break; }
        }
        $planAfter = $plan !== null && $plan['validated_at'] !== null && (string) $plan['validated_at'] >= (string) $o['created_at'];
        $ofs = [];
        foreach ($demand as $d) $ofs[$d['id_final_product']][] = $d;
        $out = [];
        foreach ($lines as $l) {
            $pid = (int) $l['id_product'];
            $of = null;
            if ($l['id_item'] === null || $l['mode'] === null) $state = 'unplanned';
            elseif ($l['mode'] === 'MTS') $state = 'stock';
            elseif (isset($ofs[$pid])) {
                $state = 'in_plan';
                $of = implode(', ', array_map(fn($d) => $d['code'] . ' (' . (self::OF_STATUS[$d['status']] ?? $d['status']) . ')', $ofs[$pid]));
            } elseif ($ship === null) $state = 'missing';
            elseif ($plan !== null) $state = $planAfter ? 'in_plan' : 'missing';
            else $state = 'live';
            $out[] = ['id' => $pid, 'name' => $l['name'] !== null ? (string) $l['name'] : 'produkt #' . $pid, 'cartons' => round((float) $l['quantity_carton'], 1), 'state' => $state, 'of' => $of];
        }
        $rank = array_flip(array_keys(self::STATES));
        $state = $out ? array_reduce($out, fn($s, $l) => $s === null || $rank[$l['state']] < $rank[$s] ? $l['state'] : $s) : 'missing';
        $planLabel = $plan ? 'plan ' . self::label((string) $plan['period_start']) . ' – ' . self::label((string) $plan['period_end'])
            . ($plan['validated_at'] ? ' zatwierdzony ' . self::label(substr((string) $plan['validated_at'], 0, 10)) . ', ' . self::hm((string) $plan['validated_at']) : '') : null;
        $names = fn(string $s) => implode(', ', array_slice(array_column(array_filter($out, fn($l) => $l['state'] === $s), 'name'), 0, 3));
        $detail = match ($state) {
            'missing' => !$out ? 'domówienie bez pozycji' : ($ship === null ? 'brak daty wysyłki : plan jej nie uwzględnia' : $planLabel . ' — przed domówieniem : ' . $names('missing')),
            'unplanned' => 'produkt bez karty planowania : ' . $names('unplanned'),
            'live' => 'plan tygodnia jeszcze niezatwierdzony : liczony na żywo z zamówień otwartych',
            'stock' => 'produkty na stan (MTS) : wydanie ze stanu, uzupełnianie stanu',
            'in_plan' => implode(' · ', array_filter([
                ($of = array_values(array_unique(array_filter(array_column($out, 'of'))))) ? 'zlecenie ' . implode(', ', $of) : null,
                array_filter($out, fn($l) => $l['state'] === 'in_plan' && $l['of'] === null) ? $planLabel . ' — po domówieniu' : null])),
            default => null,
        };
        return ['state' => $state, 'label' => self::STATES[$state], 'ok' => in_array($state, self::OK_STATES, true), 'detail' => $detail, 'lines' => $out];
    }

    /** Lignes d'une domówienie déjà expédiée : plus d'état production à suivre. */
    private static function lineList(array $lines): array
    {
        return array_map(fn($l) => ['id' => (int) $l['id_product'], 'name' => $l['name'] !== null ? (string) $l['name'] : 'produkt #' . (int) $l['id_product'],
            'cartons' => round((float) $l['quantity_carton'], 1), 'state' => 'shipped', 'of' => null], $lines);
    }

    /**
     * Signaux d'une domówienie ouverte, chacun avec une raison courte (pl) :
     *  - date_missing / date_past : date d'expédition absente ou passée ;
     *  - not_in_production : pas transmise à la production (état missing ou unplanned) et expédition dans au
     *    plus un jour ouvré (aujourd'hui → jour ouvré suivant) ;
     *  - late_cutoff : passée après orders.supplement_cutoff du jour ouvré précédant l'expédition (ou plus tard) ;
     *  - product_inactive : produit inactif ou absent de la kartoteka.
     * @param array{workdays:list<int>, cutoff:string} $set
     * @return list<array{code: string, reason: string}>
     */
    public static function flags(array $o, string $today, string $next, array $set, array $lines): array
    {
        $out = [];
        $ship = $o['shipping_date'] ?? null;
        if ($ship === null) $out[] = ['code' => 'date_missing', 'reason' => 'brak daty wysyłki'];
        elseif ($ship < $today) $out[] = ['code' => 'date_past', 'reason' => 'data wysyłki ' . self::label($ship) . ' już minęła, bez WDT'];
        else {
            $p = $o['production'] ?? null;
            if ($p !== null && !$p['ok'] && $ship <= $next) {
                $out[] = ['code' => 'not_in_production', 'reason' => 'nie w produkcji (' . $p['label'] . ') — wysyłka ' . self::label($ship) . ($ship === $today ? ' (dziś)' : '')];
            }
            $day = self::workday($ship, -1, $set['workdays']);
            $cutoff = $day . ' ' . $set['cutoff'] . ':00';
            if ((string) $o['created_at'] > $cutoff) {
                $out[] = ['code' => 'late_cutoff', 'reason' => sprintf('złożone %s, %s — po godzinie %s dnia %s (wysyłka %s)', self::label(substr((string) $o['created_at'], 0, 10)),
                    self::hm((string) $o['created_at']), $set['cutoff'], self::label($day), self::label($ship))];
            }
        }
        $inactive = [];
        foreach ($lines as $l) {
            if ($l['active'] === null) $inactive[] = 'produkt #' . (int) $l['id_product'] . ' (brak w kartotece)';
            elseif ((int) $l['active'] !== 1) $inactive[] = (string) $l['name'];
        }
        if ($inactive) $out[] = ['code' => 'product_inactive', 'reason' => (count($inactive) === 1 ? 'produkt nieaktywny : ' : 'produkty nieaktywne : ') . implode(', ', array_slice($inactive, 0, 3)) . (count($inactive) > 3 ? '…' : '')];
        return $out;
    }

    /**
     * Commande principale : parent_order_id (domówienie créée depuis une commande payée ou un écart d'expédition), sinon
     * une commande ordinaire du même client avec le même jour d'expédition (probable, marquée « same_day »).
     */
    private static function parent(array $r, array $parents, array $sameDay): ?array
    {
        $pid = (int) ($r['parent_order_id'] ?? 0);
        $ship = self::date($r['shipping_date']);
        $p = $pid > 0 ? ($parents[$pid] ?? ['id' => $pid, 'shipping_date' => null, 'status' => null, 'is_open' => 0]) : ($ship !== null ? ($sameDay[(int) $r['id_customer'] . '|' . $ship] ?? null) : null);
        if ($p === null) return null;
        $pship = self::date($p['shipping_date']);
        return ['id' => (int) $p['id'], 'kind' => $pid > 0 ? 'linked' : 'same_day', 'shipping_date' => $pship, 'shipping_label' => $pship ? self::label($pship) : null,
            'same_day' => $pship !== null && $pship === $ship, 'open' => (int) $p['is_open'] === 1,
            'status_label' => $p['status'] !== null ? (CustomerOrderStatus::labels()[(int) $p['status']] ?? (string) $p['status']) : null];
    }

    /** Krok 2 : résultat pour une domówienie signalée (une ligne par domówienie et par jour, la dernière compte). */
    public function saveResult(array $input, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($input['id_order'] ?? 0);
        $result = (string) ($input['result'] ?? '');
        $note = trim((string) ($input['note'] ?? ''));
        if (!isset(self::RESULTS[$result])) throw new CustomException('Wybierz wynik : przekazane do produkcji, kontakt z klientem albo przesunięte / odrzucone.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if (in_array($result, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException($result === 'contact' ? 'Kontakt z klientem : wpisz notatkę (z kim, co ustalono).' : 'Przesunięte / odrzucone : wpisz notatkę (na kiedy albo dlaczego).', 'errors', 422);
        }
        $d = $this->data($today);
        $order = array_values(array_filter($d['orders'], fn($o) => $o['id'] === $id))[0] ?? null;
        if ($order === null) throw new CustomException('Tego domówienia nie ma na liście dnia.', 'errors', 422);
        if (!$order['flags']) throw new CustomException('To domówienie nie ma nic do wyjaśnienia.', 'errors', 422);
        $this->repo->saveCheck($id, $today, $result, $note !== '' ? $note : null, implode(',', array_column($order['flags'], 'code')), $order['production']['state'], $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — tylko gdy każde domówienie do wyjaśnienia ma wynik ; zadanie stanowiska zalicza się samo. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw wyjaśnij ' . $w . ' ' . self::pl($w, 'domówienie', 'domówienia', 'domówień') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « 5 domówień · 3 do wyjaśnienia · 2 przekazane · 1 kontakt » (résumé sur la carte de la tâche). */
    public static function summary(array $t): string
    {
        if ($t['supplements'] === 0) return 'brak domówień do sprawdzenia';
        $s = $t['supplements'] . ' ' . self::pl($t['supplements'], 'domówienie', 'domówienia', 'domówień');
        if ($t['flagged'] === 0) return $s . ' · bez uwag';
        $s .= ' · ' . $t['flagged'] . ' do wyjaśnienia';
        if ($t['sent']) $s .= ' · ' . $t['sent'] . ' ' . self::pl($t['sent'], 'przekazane', 'przekazane', 'przekazanych') . ' do produkcji';
        if ($t['contact']) $s .= ' · ' . $t['contact'] . ' ' . self::pl($t['contact'], 'kontakt', 'kontakty', 'kontaktów');
        if ($t['moved']) $s .= ' · ' . $t['moved'] . ' ' . self::pl($t['moved'], 'przesunięte / odrzucone', 'przesunięte / odrzucone', 'przesuniętych / odrzuconych');
        return $s;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
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
