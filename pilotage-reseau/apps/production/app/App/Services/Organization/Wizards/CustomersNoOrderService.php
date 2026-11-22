<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Exceptions\CustomException;
use App\Repositories\Client\Order\CustomersNoOrderRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Asystent « Klienci bez zamówienia » [370] (Kierownik operacyjny, chaque jour ; obligation 1.3) :
 *   1. Przegląd : clients actifs réguliers (au moins orders.regular_min_orders jours de commande sur les 8 semaines
 *      avant aujourd'hui ; commandes ordinaires seulement), leur rythme habituel et leur commande attendue :
 *      attendus aujourd'hui (commandé ou pas encore), en retard ;
 *   2. Do kontaktu : chaque client en retard reçoit un résultat (n_customer_no_order, un par client et par date
 *      attendue) : kontakt z klientem (notatka wymagana), zamówienie przyszło, brak zamówienia w planie (przerwa, urlop…
 *      avec une date de fin facultative : les dates attendues suivantes jusqu'à elle sont couvertes) ;
 *   3. Zatwierdź : résumé, « Zakończ » écrit n_wizard_done — la tâche du poste se valide seule.
 *
 * Rythme (8 semaines = 8 blocs de 7 jours avant aujourd'hui, comptés depuis le bloc de la première commande) :
 *  - jours de commande : un jour de la semaine est habituel si le client a commandé ce jour-là dans au moins
 *    orders.weekday_share_pct % des blocs observés (et au moins 2 fois). Date attendue = dernier jour habituel ;
 *    elle est couverte par toute commande passée depuis la moitié de l'écart avec le jour habituel précédent
 *    (client du lundi : depuis le jeudi d'avant ; client lundi + jeudi, pour le lundi : depuis le samedi) ;
 *  - sinon intervalle moyen : écart moyen entre les jours de commande, date attendue = dernière commande + écart ;
 *    couverte par toute commande passée après la dernière.
 * En retard : date attendue non couverte et au moins orders.missing_grace_days jours ouvrés (week.production_days)
 * écoulés depuis elle. Aucun e-mail ni SMS : le contact client est noté, pas envoyé.
 */
final class CustomersNoOrderService
{
    public const CODE = 'customers_ordered_checked';
    public const RESULTS = ['contacted' => 'Kontakt z klientem', 'arrived' => 'Zamówienie przyszło', 'paused' => 'Brak zamówienia w planie'];
    public const HISTORY_DAYS = 56;
    /** Pause : au plus 120 jours. */
    public const PAUSE_MAX_DAYS = 120;
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private CustomersNoOrderRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Seuils (Ustawienia › Parametry) et jours ouvrés. @return array{min_orders:int, share:int, grace:int, workdays:list<int>} */
    public function settings(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        sort($days);
        return ['min_orders' => max(2, (int) $this->params->get('orders.regular_min_orders', '4')),
            'share' => min(100, max(1, (int) $this->params->get('orders.weekday_share_pct', '50'))),
            'grace' => max(0, (int) $this->params->get('orders.missing_grace_days', '1')), 'workdays' => array_values(array_unique($days))];
    }

    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $set = $this->settings();
        $from = self::addDays($today, -self::HISTORY_DAYS);
        $orders = $this->repo->orders($from . ' 00:00:00', self::addDays($today, 1) . ' 00:00:00');
        $evals = [];
        foreach ($orders as $cid => $list) {
            $e = self::evaluate($list, $today, $set);
            if ($e !== null) $evals[$cid] = $e;
        }
        $info = $this->repo->customers(array_keys($evals));
        $results = $this->repo->results(array_keys($evals), $from);

        $clients = [];
        foreach ($evals as $cid => $e) {
            if ($e['status'] === null || !isset($info[$cid])) continue;
            $c = $info[$cid];
            $check = null;
            if ($e['status'] === 'late') $check = self::checkFor($results[$cid] ?? [], (string) $e['expected_on']);
            $contact = trim(trim((string) ($c['name'] ?? '')) . ' ' . trim((string) ($c['surname'] ?? '')));
            $phone = trim((string) ($c['phone_head'] ?? '')) !== '' ? trim((string) $c['phone_head']) : trim((string) ($c['phone_alt'] ?? ''));
            $clients[] = ['id' => $cid, 'customer' => (string) $c['company'], 'contact' => $contact !== '' ? $contact : null,
                'phone' => $phone !== '' ? $phone : null, 'email' => trim((string) ($c['email_head'] ?? '')) ?: null,
                'rhythm' => $e['rhythm'], 'order_days' => $e['order_days'], 'status' => $e['status'],
                'expected_on' => $e['expected_on'], 'expected_label' => $e['expected_on'] ? self::label($e['expected_on']) : null, 'days_late' => $e['days_late'],
                'expected_today' => $e['expected_today'], 'last_order' => $e['last_order'] ? $e['last_order'] + ['label' => self::label($e['last_order']['day'])] : null,
                'check' => $check];
        }
        $rank = ['late' => 0, 'waiting' => 1, 'ordered' => 2];
        usort($clients, fn($a, $b) => [$rank[$a['status']], $a['check'] !== null, -($a['days_late'] ?? 0), $a['customer']] <=> [$rank[$b['status']], $b['check'] !== null, -($b['days_late'] ?? 0), $b['customer']]);

        $late = array_values(array_filter($clients, fn($c) => $c['status'] === 'late'));
        $resolved = array_values(array_filter($late, fn($c) => $c['check'] !== null));
        $count = fn(string $res) => count(array_filter($resolved, fn($c) => $c['check']['result'] === $res));
        $expected = array_values(array_filter($evals, fn($e) => $e['expected_today']));
        $totals = ['regular' => count($evals), 'expected_today' => count($expected),
            'ordered_today' => count(array_filter($expected, fn($e) => $e['covered_today'])),
            'waiting' => count(array_filter($clients, fn($c) => $c['status'] === 'waiting')),
            'late' => count($late), 'resolved' => count($resolved), 'open' => count($late) - count($resolved),
            'contacted' => $count('contacted'), 'arrived' => $count('arrived'), 'paused' => $count('paused')];
        $done = $this->wizards->done(self::CODE, $today, self::addDays($today, 1));
        return ['today' => $today, 'label' => self::label($today), 'settings' => $set + ['weeks' => intdiv(self::HISTORY_DAYS, 7),
                'workdays_label' => implode(', ', array_map(fn($d) => self::DAY_ABBR[$d], $set['workdays']))],
            'clients' => $clients, 'totals' => $totals, 'can_finish' => $totals['open'] === 0, 'summary' => self::summary($totals),
            'results' => self::RESULTS, 'pause_max' => self::addDays($today, self::PAUSE_MAX_DAYS),
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /**
     * Rythme et état d'un client pour le jour donné ; null = client pas régulier.
     * @param list<array{id:int, at:string, day:string, shipping_date:?string}> $orders commandes ordinaires (8 semaines et aujourd'hui), dans l'ordre
     * @param array{min_orders:int, share:int, grace:int, workdays:list<int>} $set
     * @return array{rhythm: array, order_days: int, status: ?string, expected_on: ?string, days_late: ?int, expected_today: bool, covered_today: bool, last_order: ?array}|null
     *   status : late (en retard, à contacter), waiting (attendu, encore dans le délai), ordered (attendu aujourd'hui et commandé), null (rien à suivre)
     */
    public static function evaluate(array $orders, string $today, array $set): ?array
    {
        $days = array_values(array_unique(array_map(fn($o) => $o['day'], array_filter($orders, fn($o) => $o['day'] < $today))));
        sort($days);
        if (count($days) < $set['min_orders']) return null;
        $rhythm = self::rhythm($days, $today, $set['share']);
        $orderDays = array_values(array_unique(array_map(fn($o) => $o['day'], $orders)));
        $coveredFrom = fn(string $from) => (bool) array_filter($orderDays, fn($d) => $d >= $from);
        $last = $orders ? end($orders) : null;
        $out = ['rhythm' => $rhythm, 'order_days' => count($days), 'status' => null, 'expected_on' => null, 'days_late' => null, 'expected_today' => false,
            'covered_today' => false, 'last_order' => $last ? ['id' => $last['id'], 'at' => substr($last['at'], 0, 16), 'day' => $last['day'], 'shipping_date' => $last['shipping_date']] : null];

        if ($rhythm['kind'] === 'weekday') {
            // Occurrences en remontant depuis aujourd'hui : la plus récente avec assez de jours ouvrés écoulés est « due ».
            $occ = self::occurrence($rhythm['days'], $today);
            $first = $occ;
            for ($i = 0; $i < 4 && $occ !== null; $i++) {
                $covered = $coveredFrom($occ['from']);
                if ($occ === $first) {
                    $out['expected_today'] = $occ['on'] === $today;
                    $out['covered_today'] = $out['expected_today'] && $covered;
                }
                $wd = self::workdaysBetween($occ['on'], $today, $set['workdays']);
                if ($wd >= $set['grace']) {
                    if (!$covered) $out = ['status' => 'late', 'expected_on' => $occ['on'], 'days_late' => $wd] + $out;
                    break;
                }
                if (!$covered && $out['status'] === null) $out = ['status' => 'waiting', 'expected_on' => $occ['on'], 'days_late' => 0] + $out;
                $occ = self::occurrence($rhythm['days'], self::addDays($occ['on'], -1));
            }
        } else {
            $lastHist = end($days);
            $on = self::addDays($lastHist, $rhythm['gap']);
            $covered = $coveredFrom(self::addDays($lastHist, 1));
            $out['expected_today'] = $on === $today;
            $out['covered_today'] = $out['expected_today'] && $covered;
            if ($on <= $today && !$covered) {
                $wd = self::workdaysBetween($on, $today, $set['workdays']);
                $out = ($wd >= $set['grace'] ? ['status' => 'late', 'days_late' => $wd] : ['status' => 'waiting', 'days_late' => 0]) + ['expected_on' => $on] + $out;
            }
        }
        if ($out['status'] === null && $out['covered_today']) $out = ['status' => 'ordered', 'expected_on' => $today, 'days_late' => 0] + $out;
        return $out;
    }

    /**
     * Rythme habituel à partir des jours de commande des 8 semaines avant aujourd'hui.
     * @param list<string> $days jours distincts (Y-m-d), croissants, tous avant $today
     * @return array{kind: string, days?: list<int>, gap?: int, label: string, weeks: int}
     */
    public static function rhythm(array $days, string $today, int $sharePct): array
    {
        $blocks = [];
        $maxBlock = 0;
        foreach ($days as $d) {
            $k = intdiv(self::diffDays($d, $today) - 1, 7); // 0 = les 7 derniers jours avant aujourd'hui
            $maxBlock = max($maxBlock, $k);
            $blocks[(int) date('N', strtotime($d))][$k] = true;
        }
        $weeks = $maxBlock + 1;
        $need = max(2, (int) ceil($sharePct / 100 * $weeks));
        $habit = [];
        foreach ($blocks as $wd => $ks) if (count($ks) >= $need) $habit[] = $wd;
        sort($habit);
        if ($habit) return ['kind' => 'weekday', 'days' => $habit, 'label' => implode(', ', array_map(fn($d) => self::DAY_ABBR[$d], $habit)), 'weeks' => $weeks];
        $gap = max(1, (int) round(self::diffDays($days[0], end($days)) / max(1, count($days) - 1)));
        return ['kind' => 'interval', 'gap' => $gap, 'label' => 'co ok. ' . $gap . ' ' . ($gap === 1 ? 'dzień' : 'dni'), 'weeks' => $weeks];
    }

    /**
     * Dernier jour habituel ≤ $ref et début de sa fenêtre (moitié de l'écart avec le jour habituel précédent).
     * @param list<int> $weekdays 1 = lundi … 7 = dimanche
     * @return array{on: string, from: string}|null
     */
    public static function occurrence(array $weekdays, string $ref): ?array
    {
        $on = null;
        for ($i = 0; $i < 7; $i++) {
            $d = self::addDays($ref, -$i);
            if (in_array((int) date('N', strtotime($d)), $weekdays, true)) { $on = $d; break; }
        }
        if ($on === null) return null;
        $gap = 7;
        for ($i = 1; $i <= 7; $i++) {
            if (in_array((int) date('N', strtotime(self::addDays($on, -$i))), $weekdays, true)) { $gap = $i; break; }
        }
        return ['on' => $on, 'from' => self::addDays($on, -intdiv($gap, 2))];
    }

    /** Jours ouvrés dans ]$from, $to] (week.production_days). */
    public static function workdaysBetween(string $from, string $to, array $workdays): int
    {
        $n = 0;
        for ($d = self::addDays($from, 1), $i = 0; $d <= $to && $i < 400; $d = self::addDays($d, 1), $i++) {
            if (in_array((int) date('N', strtotime($d)), $workdays, true)) $n++;
        }
        return $n;
    }

    /**
     * Résultat de la date attendue : le sien, sinon une pause enregistrée plus tôt qui la couvre (paused_until).
     * @param list<array> $rows résultats du client, date attendue la plus récente d'abord
     */
    public static function checkFor(array $rows, string $expectedOn): ?array
    {
        $own = array_values(array_filter($rows, fn($r) => (string) $r['expected_on'] === $expectedOn))[0] ?? null;
        $pause = $own ? null : (array_values(array_filter($rows, fn($r) => $r['result'] === 'paused' && (string) $r['expected_on'] < $expectedOn
            && $r['paused_until'] !== null && (string) $r['paused_until'] >= $expectedOn))[0] ?? null);
        $r = $own ?? $pause;
        if ($r === null) return null;
        return ['result' => (string) $r['result'], 'label' => self::RESULTS[$r['result']] ?? (string) $r['result'], 'note' => $r['note'],
            'paused_until' => $r['paused_until'] ?: null, 'paused_label' => $r['paused_until'] ? self::label((string) $r['paused_until']) : null,
            'inherited' => $own === null, 'saved_on' => self::label((string) $r['day']), 'by' => $r['by_name'], 'at' => self::hm((string) $r['updated_at'])];
    }

    /** Krok 2 : résultat pour un client en retard (un par client et par date attendue, le dernier compte). */
    public function saveResult(array $input, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($input['id_customer'] ?? 0);
        $result = (string) ($input['result'] ?? '');
        $note = trim((string) ($input['note'] ?? ''));
        $until = trim((string) ($input['paused_until'] ?? ''));
        if (!isset(self::RESULTS[$result])) throw new CustomException('Wybierz wynik : kontakt z klientem, zamówienie przyszło albo brak zamówienia w planie.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if ($result === 'contacted' && mb_strlen($note) < 3) throw new CustomException('Kontakt z klientem : wpisz notatkę (z kim, co ustalono).', 'errors', 422);
        if ($until !== '') {
            if ($result !== 'paused') throw new CustomException('Data « do kiedy » tylko dla przerwy w zamówieniach.', 'errors', 422);
            $t = \DateTime::createFromFormat('!Y-m-d', $until);
            if (!$t || $t->format('Y-m-d') !== $until || $until < $today || $until > self::addDays($today, self::PAUSE_MAX_DAYS)) {
                throw new CustomException('Przerwa : data od dziś do ' . self::PAUSE_MAX_DAYS . ' dni.', 'errors', 422);
            }
        }
        $d = $this->data($today);
        $client = array_values(array_filter($d['clients'], fn($c) => $c['id'] === $id && $c['status'] === 'late'))[0] ?? null;
        if ($client === null) throw new CustomException('Tego klienta nie ma na liście do kontaktu.', 'errors', 422);
        $this->repo->saveResult($id, (string) $client['expected_on'], $today, $result, $note !== '' ? $note : null, $until !== '' ? $until : null, (string) $client['rhythm']['label'], $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — tylko gdy każdy klient do kontaktu ma wynik ; zadanie stanowiska zalicza się samo. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $n = $d['totals']['open'];
        if ($n > 0) throw new CustomException('Najpierw zapisz wynik dla ' . $n . ' ' . self::pl($n, 'klienta', 'klientów', 'klientów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « 14 stałych klientów · 3 bez zamówienia · 2 kontakty · 1 przerwa » (résumé sur la carte de la tâche). */
    public static function summary(array $t): string
    {
        if ($t['regular'] === 0) return 'brak stałych klientów do sprawdzenia';
        $s = $t['regular'] . ' ' . self::pl($t['regular'], 'stały klient', 'stałych klientów', 'stałych klientów');
        if ($t['late'] === 0) return $s . ' · wszyscy zamówili w terminie';
        $s .= ' · ' . $t['late'] . ' bez zamówienia';
        if ($t['contacted']) $s .= ' · ' . $t['contacted'] . ' ' . self::pl($t['contacted'], 'kontakt', 'kontakty', 'kontaktów');
        if ($t['arrived']) $s .= ' · ' . $t['arrived'] . ' ' . self::pl($t['arrived'], 'zamówienie przyszło', 'zamówienia przyszły', 'zamówień przyszło');
        if ($t['paused']) $s .= ' · ' . $t['paused'] . ' ' . self::pl($t['paused'], 'przerwa', 'przerwy', 'przerw');
        return $s;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function addDays(string $day, int $n): string
    {
        return date('Y-m-d', strtotime($day . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
    }

    /** Jours calendaires de $from à $to (dates Y-m-d). */
    private static function diffDays(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
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
