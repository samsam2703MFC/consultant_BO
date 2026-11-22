<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderDayCheckRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * Asystent « Zamówienia dnia » [369] (Kierownik operacyjny, chaque jour ; obligations 1.1, 1.2 et 1.8) :
 *   1. Nowe i zmienione : commandes créées depuis la validation précédente de l'assistant (sinon depuis le jour
 *      ouvré précédent, 0:00) et commandes modifiées depuis (customer_order_changelog, hors système) ;
 *   2. Do wyjaśnienia : signaux automatiques sur les commandes ouvertes (date d'expédition passée, absente ou trop
 *      proche, quantité inhabituelle pour le client, produit inactif) ; un résultat par commande signalée
 *      (n_order_check : sprawdzone / kontakt z klientem / zmienione) ;
 *   3. Zatwierdź : résumé, « Zakończ » écrit n_wizard_done — la tâche du poste se valide seule.
 * Aucun e-mail ni SMS : le contact client est noté, pas envoyé.
 */
final class OrdersDayCheckService
{
    public const CODE = 'orders_day_checked';
    public const RESULTS = ['ok' => 'OK — sprawdzone', 'contact' => 'Kontakt z klientem', 'changed' => 'Zmienione'];
    /** Quantité inhabituelle : au-dessus de 2× ou sous 0,3× la moyenne du client (8 semaines, au moins 3 commandes). */
    public const QTY_HIGH = 2.0;
    public const QTY_LOW = 0.3;
    public const HISTORY_DAYS = 56;
    public const HISTORY_MIN = 3;
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];
    /** Champs de l'historique (« after » seul) et leur libellé ; les champs techniques ne font pas une modification. */
    private const FIELDS = ['expected_shipping_date' => 'data wysyłki', 'order_description' => 'opis', 'reference' => 'referencja', 'is_freebie' => 'gratis',
        'standard_price_list' => 'cennik', 'id_customer' => 'klient', 'order_type' => 'typ', 'products' => 'pozycje'];

    public function __construct(private OrderDayCheckRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Début de la fenêtre : validation précédente de l'assistant, sinon jour ouvré précédent à 0:00. */
    public function since(string $today): array
    {
        $last = $this->repo->lastDone(self::CODE, $today);
        if ($last !== null) return ['at' => $last, 'kind' => 'done', 'label' => 'od zatwierdzenia ' . self::label(substr($last, 0, 10)) . ', ' . self::hm($last)];
        $day = $this->previousWorkday($today);
        return ['at' => $day . ' 00:00:00', 'kind' => 'workday', 'label' => 'od ' . self::label($day) . ', 0:00 (pierwsze sprawdzenie)'];
    }

    /** Jour ouvré précédent (week.production_days, lundi–vendredi par défaut). */
    public function previousWorkday(string $today): string
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $this->params->get('week.production_days', '1,2,3,4,5'))), fn($d) => $d >= 1 && $d <= 7)) ?: [1, 2, 3, 4, 5];
        $d = $today;
        for ($i = 0; $i < 10; $i++) {
            $d = date('Y-m-d', strtotime($d . ' -1 day'));
            if (in_array((int) date('N', strtotime($d)), $days, true)) return $d;
        }
        return date('Y-m-d', strtotime($today . ' -1 day'));
    }

    public function leadDays(): int
    {
        return max(0, (int) $this->params->get('orders.min_lead_days', '1'));
    }

    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $since = $this->since($today);
        $lead = $this->leadDays();
        $rows = $this->repo->orders($since['at']);
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $changes = $this->repo->changes($ids, $since['at']);
        $lines = $this->repo->lines($ids);
        $names = [];
        foreach ($lines as $ls) foreach ($ls as $l) if ($l['name'] !== null) $names[(int) $l['id_product']] = (string) $l['name'];
        $names += $this->repo->productNames(self::productIds($changes));
        $customers = array_values(array_unique(array_map(fn($r) => (int) $r['id_customer'], $rows)));
        $oldest = $rows ? min(array_map(fn($r) => (string) $r['create_order_date'], $rows)) : $since['at'];
        $history = $this->repo->history($customers, date('Y-m-d H:i:s', strtotime(min($oldest, $since['at']) . ' -' . self::HISTORY_DAYS . ' days')));
        $checks = $this->repo->checks($today);

        $orders = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $isNew = (string) $r['create_order_date'] >= $since['at'];
            $diff = [];
            $lastChange = null;
            $by = null;
            foreach ($changes[$id] ?? [] as $c) {
                $d = self::describe((string) $c['changed_fields'], $names);
                if (!$d) continue;
                array_push($diff, ...$d);
                $lastChange = (string) $c['created_at'];
                $by = $c['by_name'] ?: ($c['source'] === 'web' ? null : ($c['source'] === 'api' ? 'API' : 'sklep (kolektor)'));
            }
            if (!$isNew && !$diff) continue; // seulement des changements techniques (proforma, statut…) : pas une modification
            $avg = self::average($history[(int) $r['id_customer']] ?? [], $id, (string) $r['create_order_date']);
            $o = ['id' => $id, 'reference' => $r['reference'] !== null && trim((string) $r['reference']) !== '' ? (string) $r['reference'] : null,
                'description' => $r['order_description'] !== null ? mb_substr(trim((string) $r['order_description']), 0, 120) : null,
                'customer' => (string) $r['company'], 'id_customer' => (int) $r['id_customer'], 'created_at' => (string) $r['create_order_date'],
                'shipping_date' => $r['shipping_date'] ?: null, 'lines' => (int) $r['line_count'], 'cartons' => round((float) $r['cartons'], 1),
                'type' => $r['order_type'] === 'SUPPLEMENT' ? 'SUPPLEMENT' : 'STANDARD', 'freebie' => (int) $r['is_freebie'] === 1,
                'kind' => $isNew ? 'new' : 'changed', 'changes' => array_values(array_unique($diff)), 'changed_at' => $lastChange, 'changed_by' => $by,
                'status' => (int) $r['status'], 'status_label' => CustomerOrderStatus::labels()[(int) $r['status']] ?? (string) $r['status'], 'open' => (int) $r['is_open'] === 1,
                'average' => $avg ? ['cartons' => round($avg['avg'], 1), 'orders' => $avg['n']] : null];
            $o['flags'] = $o['open'] ? self::flags($o, $today, $lead, $avg, $lines[$id] ?? []) : [];
            $ck = $checks[$id] ?? null;
            $o['check'] = $ck ? ['result' => $ck['result'], 'label' => self::RESULTS[$ck['result']] ?? $ck['result'], 'note' => $ck['note'], 'by' => $ck['by_name'], 'at' => self::hm((string) $ck['updated_at'])] : null;
            $orders[] = $o;
        }

        $flagged = array_values(array_filter($orders, fn($o) => $o['flags']));
        $resolved = array_values(array_filter($flagged, fn($o) => $o['check'] !== null));
        $count = fn(string $res) => count(array_filter($resolved, fn($o) => $o['check']['result'] === $res));
        $totals = ['orders' => count($orders), 'new' => count(array_filter($orders, fn($o) => $o['kind'] === 'new')),
            'changed' => count(array_filter($orders, fn($o) => $o['kind'] === 'changed')), 'flagged' => count($flagged), 'resolved' => count($resolved),
            'waiting' => count($flagged) - count($resolved), 'cartons' => round(array_sum(array_column($orders, 'cartons')), 1),
            'supplements' => count(array_filter($orders, fn($o) => $o['type'] === 'SUPPLEMENT')),
            'ok' => $count('ok'), 'contact' => $count('contact'), 'changed_result' => $count('changed')];
        $done = $this->wizards->done(self::CODE, $today, date('Y-m-d', strtotime($today . ' +1 day')));
        return ['today' => $today, 'label' => self::label($today), 'since' => $since, 'lead_days' => $lead, 'orders' => $orders, 'totals' => $totals,
            'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($totals), 'results' => self::RESULTS,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /**
     * Signaux d'une commande ouverte, chacun avec une raison courte (pl).
     * @param array{avg: float, n: int}|null $avg moyenne des commandes ordinaires du client (8 semaines avant la commande)
     * @return list<array{code: string, reason: string}>
     */
    public static function flags(array $o, string $today, int $leadDays, ?array $avg, array $lines): array
    {
        $out = [];
        $ship = $o['shipping_date'] ?? null;
        if ($ship === null || $ship === '' || $ship === '0000-00-00') {
            $out[] = ['code' => 'date_missing', 'reason' => 'brak daty wysyłki'];
        } elseif ($ship < $today) {
            $out[] = ['code' => 'date_past', 'reason' => 'data wysyłki ' . self::label($ship) . ' już minęła'];
        } else {
            // Délai compté depuis la commande (nouvelle) ou sa dernière modification (modifiée).
            $ref = substr((string) (($o['kind'] ?? 'new') === 'changed' && !empty($o['changed_at']) ? $o['changed_at'] : $o['created_at']), 0, 10);
            $days = (int) round((strtotime($ship) - strtotime($ref)) / 86400);
            if ($leadDays > 0 && $days < $leadDays) {
                $out[] = ['code' => 'date_short', 'reason' => sprintf('wysyłka %s — %d %s od %s, minimum %d %s', self::label($ship), $days, $days === 1 ? 'dzień' : 'dni',
                    ($o['kind'] ?? 'new') === 'changed' ? 'zmiany' : 'złożenia', $leadDays, $leadDays === 1 ? 'dzień' : 'dni')];
            }
        }
        // Quantité : commandes ordinaires seulement (une domówienie ou un gratuit est petit par nature).
        if ($avg !== null && $avg['n'] >= self::HISTORY_MIN && $avg['avg'] > 0 && ($o['type'] ?? 'STANDARD') === 'STANDARD' && empty($o['freebie'])) {
            $c = (float) $o['cartons'];
            if ($c > self::QTY_HIGH * $avg['avg']) {
                $out[] = ['code' => 'qty_high', 'reason' => sprintf('%s kart. — ponad 2× średniej klienta (%s kart., %d zam. w 8 tyg.)', self::num($c), self::num($avg['avg'], 1), $avg['n'])];
            } elseif ($c < self::QTY_LOW * $avg['avg']) {
                $out[] = ['code' => 'qty_low', 'reason' => sprintf('%s kart. — poniżej 0,3× średniej klienta (%s kart., %d zam. w 8 tyg.)', self::num($c), self::num($avg['avg'], 1), $avg['n'])];
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
     * Moyenne des commandes ordinaires du client dans les 8 semaines avant la commande (elle exclue).
     * @param list<array{id:int, at:string, cartons:float}> $history
     * @return array{avg: float, n: int}|null
     */
    public static function average(array $history, int $orderId, string $createdAt): ?array
    {
        $from = date('Y-m-d H:i:s', strtotime($createdAt . ' -' . self::HISTORY_DAYS . ' days'));
        $prev = array_values(array_filter($history, fn($h) => $h['id'] !== $orderId && $h['at'] >= $from && $h['at'] < $createdAt));
        if (!$prev) return null;
        return ['avg' => array_sum(array_column($prev, 'cartons')) / count($prev), 'n' => count($prev)];
    }

    /**
     * Ce qui a changé dans une entrée de l'historique (avant / après, ou après seul) ; vide = changement technique.
     * @param array<int, string> $names
     * @return list<string>
     */
    public static function describe(string $json, array $names = []): array
    {
        $j = json_decode($json, true);
        if (!is_array($j) || !is_array($j['after'] ?? null)) return [];
        $after = $j['after'];
        $before = is_array($j['before'] ?? null) ? $j['before'] : null;
        $out = [];
        if ($before === null) {
            foreach ($after as $k => $v) {
                if (!isset(self::FIELDS[$k])) continue;
                $out[] = $k === 'expected_shipping_date' ? 'data wysyłki → ' . self::label(substr((string) $v, 0, 10)) : self::FIELDS[$k];
            }
            return $out;
        }
        $date = fn($v) => $v ? substr((string) $v, 0, 10) : null;
        if (array_key_exists('expected_shipping_date', $after) && $date($before['expected_shipping_date'] ?? null) !== $date($after['expected_shipping_date'])) {
            $b = $date($before['expected_shipping_date'] ?? null);
            $a = $date($after['expected_shipping_date']);
            $out[] = 'data wysyłki ' . ($b ? self::label($b) : '—') . ' → ' . ($a ? self::label($a) : '—');
        }
        foreach (['order_description' => 'opis', 'is_freebie' => 'gratis', 'standard_price_list' => 'cennik'] as $k => $label) {
            if (array_key_exists($k, $after) && trim((string) ($before[$k] ?? '')) !== trim((string) ($after[$k] ?? ''))) $out[] = $label;
        }
        if (is_array($after['products'] ?? null)) {
            $map = function (array $ps): array {
                $m = [];
                foreach ($ps as $p) if (isset($p['id_product'])) $m[(int) $p['id_product']] = ($m[(int) $p['id_product']] ?? 0) + (float) ($p['quantity_carton'] ?? 0);
                return $m;
            };
            $b = $map(is_array($before['products'] ?? null) ? $before['products'] : []);
            $a = $map($after['products']);
            $name = fn(int $id) => $names[$id] ?? ('produkt #' . $id);
            foreach ($a as $id => $q) {
                if (!isset($b[$id])) $out[] = '+ ' . $name($id) . ' ' . self::num($q) . ' kart.';
                elseif (abs($b[$id] - $q) > 0.001) $out[] = $name($id) . ' ' . self::num($b[$id]) . ' → ' . self::num($q) . ' kart.';
            }
            foreach ($b as $id => $q) if (!isset($a[$id])) $out[] = '− ' . $name($id);
        }
        return $out;
    }

    /** Krok 2 : résultat pour une commande de la liste (une ligne par commande et par jour, la dernière compte). */
    public function saveResult(array $input, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($input['id_order'] ?? 0);
        $result = (string) ($input['result'] ?? '');
        $note = trim((string) ($input['note'] ?? ''));
        if (!isset(self::RESULTS[$result])) throw new CustomException('Wybierz wynik : OK, kontakt z klientem albo zmienione.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if ($result === 'contact' && mb_strlen($note) < 3) throw new CustomException('Kontakt z klientem : wpisz notatkę (z kim, co ustalono).', 'errors', 422);
        $d = $this->data($today);
        $order = array_values(array_filter($d['orders'], fn($o) => $o['id'] === $id))[0] ?? null;
        if ($order === null) throw new CustomException('Tego zamówienia nie ma na liście dnia.', 'errors', 422);
        $this->repo->saveCheck($id, $today, $result, $note !== '' ? $note : null, implode(',', array_column($order['flags'], 'code')), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — tylko gdy każde zamówienie do wyjaśnienia ma wynik ; zadanie stanowiska zalicza się samo. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw wyjaśnij ' . $w . ' ' . self::pl($w, 'zamówienie', 'zamówienia', 'zamówień') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « 12 zamówień · 3 wyjaśnione · 1 kontakt » (résumé sur la carte de la tâche). */
    public static function summary(array $t): string
    {
        if ($t['orders'] === 0) return 'brak nowych i zmienionych zamówień';
        $s = $t['orders'] . ' ' . self::pl($t['orders'], 'zamówienie', 'zamówienia', 'zamówień');
        if ($t['flagged'] === 0) return $s . ' · bez uwag';
        $s .= ' · ' . $t['resolved'] . ' ' . self::pl($t['resolved'], 'wyjaśnione', 'wyjaśnione', 'wyjaśnionych');
        if ($t['contact']) $s .= ' · ' . $t['contact'] . ' ' . self::pl($t['contact'], 'kontakt', 'kontakty', 'kontaktów');
        if ($t['changed_result']) $s .= ' · ' . $t['changed_result'] . ' ' . self::pl($t['changed_result'], 'zmienione', 'zmienione', 'zmienionych');
        return $s;
    }

    private static function productIds(array $changes): array
    {
        $ids = [];
        foreach ($changes as $list) foreach ($list as $c) {
            $j = json_decode((string) $c['changed_fields'], true);
            foreach (['before', 'after'] as $side) foreach ((is_array($j[$side]['products'] ?? null) ? $j[$side]['products'] : []) as $p) if (isset($p['id_product'])) $ids[] = (int) $p['id_product'];
        }
        return $ids;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function hm(string $ts): string
    {
        return strlen($ts) >= 16 ? (int) substr($ts, 11, 2) . substr($ts, 13, 3) : '';
    }

    private static function num(float $v, int $d = 0): string
    {
        return number_format($v, abs($v - round($v)) > 0.001 ? max($d, 1) : $d, ',', "\u{00A0}");
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
