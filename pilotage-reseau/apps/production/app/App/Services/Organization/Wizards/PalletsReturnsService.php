<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Logistics\PalletsReturnsRepository;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\DriverService;

/**
 * Asystent « Palety zwrotne » [381] (Logistyka, Kierowca-dostawca — chaque semaine ; obligations Logistyka 6 « Zwroty palet
 * i opakowań » et Kierowca 6 « Rozliczenie palet EUR i dokumentów » de la matrice 224). Le chauffeur saisit à chaque
 * arrêt, sur sa tablette, les palettes livrées et les palettes EUR rendues (« Palety dostarczone », « Palety zwrócone
 * (EUR) » ; écran Dostawy › potwierdzenia : colonnes « Dostarczone » / « Zwrócone EUR »), le motif d'une palette manquante
 * (pallets_note) et les palettes abîmées (« Coś nie tak » → « Uszkodzona paleta »). Il n'existait aucun solde par client :
 * seul le rapport mensuel additionne les palettes rendues (DeliveryRepository::summary). L'assistant fait ce solde :
 *   1. Przegląd : solde des palettes EUR par client = Σ pallets_delivered − Σ pallets_returned des arrêts confirmés
 *      (livré ou problème ; saisie vide = 0) sur pallets_returns.balance_weeks semaines jusqu'à la fin de la semaine
 *      examinée (lundi → dimanche précédents, jour d'expédition ship_date) ; même solde une semaine plus tôt (évolution) ;
 *      arrêts de la semaine avec palette manquante (livrées > rendues, pallets_note vide) ; palettes abîmées de la semaine
 *      (n_delivery_incident.kind = 'pallet_damaged'). Si aucune palette n'est saisie sur la période (colonnes vides partout,
 *      ou aucun arrêt) : « brak danych — tablet kierowcy nie zapisuje palet » ;
 *   2. Do wyjaśnienia : un client est signalé quand son solde dépasse pallets_returns.threshold palettes, ou qu'il a dans la
 *      semaine un arrêt avec palette manquante ou une palette abîmée ; une décision par client et par semaine examinée
 *      (n_pallet_return_review) — Przypomnienie / odbiór zaplanowany (note obligatoire), OK — saldo uzgodnione (note),
 *      Spisane / do faktury (note obligatoire) ; rien n'est envoyé (pas de SMS ni d'e-mail) ;
 *   3. Zatwierdź : solde total, clients à relancer, évolution par rapport à la semaine précédente et par semaine ;
 *      « Zakończ » écrit n_wizard_done — bloqué tant qu'un client signalé attend ; la tâche du poste se valide seule.
 * Lecture seule hors de n_pallet_return_review et n_wizard_done : aucun arrêt ni incident modifié, aucune facture créée.
 */
final class PalletsReturnsService
{
    public const CODE = 'pallets_returns_reviewed';
    public const PATH = '/logistics/pallets-returns';
    public const DECISIONS = ['reminder' => 'Przypomnienie / odbiór zaplanowany', 'agreed' => 'OK — saldo uzgodnione', 'written_off' => 'Spisane / do faktury'];
    /** Décisions qui demandent une note (au moins 3 caractères). */
    public const NOTE_REQUIRED = ['reminder', 'written_off'];
    /** Signaux d'un client, dans l'ordre de priorité. */
    public const SIGNALS = ['missing' => 'brakujące palety bez uzasadnienia', 'damaged' => 'uszkodzona paleta', 'balance_high' => 'saldo powyżej progu'];
    /** Écran existant des confirmations de livraison (jour d'expédition en paramètre). */
    public const DELIVERIES_LINK = '/logistics/deliveries';
    public const NO_DATA = 'brak danych — tablet kierowcy nie zapisuje palet';
    public const NO_TABLE = 'brak danych — brak tabeli w bazie';
    private const PARAMS = ['weeks' => ['pallets_returns.balance_weeks', 8], 'threshold' => ['pallets_returns.threshold', 4]];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private PalletsReturnsRepository $repo, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Réglage pallets_returns.* (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
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

    /** Données des trois étapes. */
    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $weeks = $this->param('weeks');
        $threshold = $this->param('threshold');
        $week = self::reviewedWeek($today);
        $weekEnd = self::addDays($week, 6);
        $from = self::addDays($weekEnd, 1 - 7 * $weeks);
        $prevEnd = self::addDays($week, -1);
        $prevFrom = self::addDays($from, -7);
        $stops = $this->repo->stops($prevFrom, $weekEnd);
        $damaged = $this->repo->damaged($week, $weekEnd);
        $decisions = $this->repo->decisions($week);
        $previous = $this->repo->decisions(self::addDays($week, -7));

        // Saisie des palettes sur la période (solde courant + semaine précédente) : sinon « brak danych ».
        $withPallets = $stops === null ? 0 : count(array_filter($stops, fn($s) => $s['pallets_delivered'] !== null || $s['pallets_returned'] !== null));
        $available = $withPallets > 0;
        $sources = ['pallets' => ['available' => $available, 'rows' => $stops === null ? null : count($stops), 'with_pallets' => $withPallets,
                'note' => $stops === null ? self::NO_TABLE : ($available ? null : self::NO_DATA)],
            'damaged' => ['available' => $damaged !== null, 'rows' => $damaged === null ? null : count($damaged), 'note' => $damaged === null ? self::NO_TABLE : null]];

        $c = [];
        $cust = function (int $id, ?string $name) use (&$c) {
            $c[$id] ??= ['id' => $id, 'name' => trim((string) $name) !== '' ? trim((string) $name) : ('klient #' . $id), 'delivered' => 0, 'returned' => 0, 'prev_delivered' => 0, 'prev_returned' => 0,
                'week_delivered' => 0, 'week_returned' => 0, 'week_stops' => [], 'missing' => [], 'damaged' => [], 'last_ship' => null, 'stops' => 0];
        };
        $series = [];
        for ($k = $weeks - 1; $k >= 0; $k--) {
            $ws = self::addDays($week, -7 * $k);
            $series[$ws] = ['week_start' => $ws, 'label' => self::dm($ws) . ' – ' . self::dm(self::addDays($ws, 6)), 'delivered' => 0, 'returned' => 0, 'stops' => 0, 'reviewed' => $k === 0];
        }
        foreach ($stops ?? [] as $s) {
            $id = (int) $s['id_customer'];
            $day = substr((string) $s['ship_date'], 0, 10);
            $del = (int) ($s['pallets_delivered'] ?? 0);
            $ret = (int) ($s['pallets_returned'] ?? 0);
            if ($s['pallets_delivered'] === null && $s['pallets_returned'] === null && $day < $week) continue;
            $cust($id, $s['customer']);
            $x = &$c[$id];
            if ($day <= $prevEnd) { $x['prev_delivered'] += $del; $x['prev_returned'] += $ret; }
            if ($day >= $from) {
                $x['delivered'] += $del; $x['returned'] += $ret; $x['stops']++;
                $x['last_ship'] = max((string) $x['last_ship'], $day);
                $series[self::monday($day)]['delivered'] += $del;
                $series[self::monday($day)]['returned'] += $ret;
                $series[self::monday($day)]['stops']++;
            }
            if ($day >= $week) {
                $x['week_delivered'] += $del; $x['week_returned'] += $ret;
                $pnote = trim((string) ($s['pallets_note'] ?? ''));
                $stop = ['id' => (int) $s['id'], 'day' => $day, 'day_label' => self::label($day), 'delivered' => $s['pallets_delivered'] !== null ? $del : null,
                    'returned' => $s['pallets_returned'] !== null ? $ret : null, 'short' => max(0, $del - $ret), 'pallets_note' => $pnote !== '' ? $pnote : null,
                    'note' => trim((string) ($s['note'] ?? '')) !== '' ? mb_substr(trim((string) $s['note']), 0, 200) : null, 'driver' => $s['driver_name'],
                    'status' => (string) $s['status'], 'status_label' => $s['status'] === 'problem' ? 'problem : ' . (DriverService::REASON_LABELS[$s['reason'] ?? 'other'] ?? DriverService::REASON_LABELS['other'])[0] : 'dostarczono',
                    'link' => ['href' => self::DELIVERIES_LINK . '?date=' . $day, 'label' => 'Dostawy ' . self::dm($day)]];
                $x['week_stops'][] = $stop;
                if ($s['pallets_delivered'] !== null && $del > $ret && $pnote === '') $x['missing'][] = $stop;
            }
            unset($x);
        }
        foreach ($damaged ?? [] as $d) {
            $id = (int) $d['id_customer'];
            $cust($id, $d['customer']);
            $day = substr((string) $d['ship_date'], 0, 10);
            $c[$id]['damaged'][] = ['id' => (int) $d['id'], 'id_stop' => (int) $d['id_stop'], 'day' => $day, 'day_label' => self::label($day),
                'note' => trim((string) ($d['note'] ?? '')) !== '' ? mb_substr(trim((string) $d['note']), 0, 200) : null, 'driver' => $d['driver_name'],
                'label' => DriverService::INCIDENTS['pallet_damaged'][0], 'link' => ['href' => self::DELIVERIES_LINK . '?date=' . $day, 'label' => 'Dostawy ' . self::dm($day)]];
        }
        foreach ($decisions as $id => $r) $cust($id, $r['customer']);

        $items = [];
        foreach ($c as $id => $x) $items[] = $this->item($x, $threshold, $weeks, $decisions[$id] ?? null, $previous[$id] ?? null);
        $rank = array_flip(array_keys(self::SIGNALS));
        $review = array_values(array_filter($items, fn($i) => $i['review']));
        usort($review, fn($a, $b) => [$a['signals'] ? $rank[$a['signals'][0]['code']] : 99, -$a['balance'], $a['name']] <=> [$b['signals'] ? $rank[$b['signals'][0]['code']] : 99, -$b['balance'], $b['name']]);
        $balances = array_values(array_filter($items, fn($i) => $i['delivered'] || $i['returned'] || $i['prev_balance']));
        usort($balances, fn($a, $b) => [-$a['balance'], $a['name']] <=> [-$b['balance'], $b['name']]);

        $sum = fn(string $k, ?callable $f = null) => array_sum(array_map(fn($i) => $i[$k], $f ? array_filter($items, $f) : $items));
        $decided = fn(string $k) => count(array_filter($review, fn($i) => $i['decision'] !== null && $i['decision']['code'] === $k));
        $byCode = array_fill_keys(array_keys(self::SIGNALS), 0);
        foreach ($review as $i) foreach ($i['signals'] as $s) $byCode[$s['code']]++;
        $total = $sum('balance');
        $prevTotal = $sum('prev_balance');
        $totals = ['balance' => $total, 'balance_positive' => $sum('balance', fn($i) => $i['balance'] > 0), 'customers_positive' => count(array_filter($items, fn($i) => $i['balance'] > 0)),
            'customers_over' => count(array_filter($items, fn($i) => $i['over'])), 'prev_balance' => $prevTotal, 'delta' => $total - $prevTotal,
            'delivered' => $sum('delivered'), 'returned' => $sum('returned'), 'week_delivered' => $sum('week_delivered'), 'week_returned' => $sum('week_returned'),
            'week_stops' => array_sum(array_map(fn($i) => count($i['week_stops']), $items)),
            'week_stops_pallets' => array_sum(array_map(fn($i) => count(array_filter($i['week_stops'], fn($s) => $s['delivered'] !== null || $s['returned'] !== null)), $items)),
            'missing_stops' => array_sum(array_map(fn($i) => count($i['missing']), $items)), 'missing_pallets' => array_sum(array_map(fn($i) => array_sum(array_column($i['missing'], 'short')), $items)),
            'damaged' => array_sum(array_map(fn($i) => count($i['damaged']), $items)), 'by_code' => $byCode,
            'review' => count($review), 'waiting' => count(array_filter($review, fn($i) => $i['waiting'])), 'resolved' => count(array_filter($review, fn($i) => !$i['waiting'])),
            'reminder' => $decided('reminder'), 'agreed' => $decided('agreed'), 'written_off' => $decided('written_off'),
            'reminder_balance' => array_sum(array_map(fn($i) => $i['balance'], array_filter($review, fn($i) => $i['decision'] !== null && $i['decision']['code'] === 'reminder')))];
        $done = $this->wizards->done(self::CODE, self::monday($today), self::addDays(self::monday($today), 7));
        return ['today' => $today, 'label' => self::label($today), 'week_start' => $week, 'week_end' => $weekEnd,
            'week_label' => self::dm($week) . ' – ' . self::dm($weekEnd) . '.' . substr($weekEnd, 0, 4), 'balance_from' => $from, 'balance_from_label' => self::label($from) . '.' . substr($from, 0, 4),
            'balance_weeks' => $weeks, 'threshold' => $threshold, 'available' => $available, 'sources' => $sources,
            'review' => $review, 'balances' => $balances, 'series' => array_values($series), 'totals' => $totals,
            'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($totals, $available, self::dm($week) . ' – ' . self::dm($weekEnd)),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'signal_labels' => self::SIGNALS, 'deliveries_link' => self::DELIVERIES_LINK,
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Un client : solde, évolution, arrêts de la semaine, signaux, décision de la semaine (et celle de la semaine précédente). */
    private function item(array $x, int $threshold, int $weeks, ?array $r, ?array $prev): array
    {
        $balance = $x['delivered'] - $x['returned'];
        $prevBalance = $x['prev_delivered'] - $x['prev_returned'];
        $signals = [];
        $sig = function (string $code, string $detail) use (&$signals) { $signals[] = ['code' => $code, 'label' => self::SIGNALS[$code], 'detail' => $detail]; };
        foreach ($x['missing'] as $s) {
            $sig('missing', $s['day_label'] . ' : ' . $s['delivered'] . ' ' . self::pl($s['delivered'], 'dostarczona', 'dostarczone', 'dostarczonych') . ', ' . ($s['returned'] === null ? 'zwrot niewpisany'
                : $s['returned'] . ' ' . self::pl($s['returned'], 'zwrócona', 'zwrócone', 'zwróconych')) . ' — brakuje ' . $s['short'] . ' ' . self::pl($s['short'], 'palety', 'palet', 'palet') . ', bez uzasadnienia'
                . ($s['driver'] ? ' (' . $s['driver'] . ')' : ''));
        }
        foreach ($x['damaged'] as $d) $sig('damaged', $d['day_label'] . ' — ' . ($d['note'] ?? 'bez opisu') . ($d['driver'] ? ' (' . $d['driver'] . ')' : ''));
        $over = $balance > $threshold;
        if ($over) $sig('balance_high', 'saldo ' . $balance . ' ' . self::pl($balance, 'paleta', 'palety', 'palet') . ' EUR (' . $x['delivered'] . ' dostarczone − ' . $x['returned']
            . ' zwrócone w ' . $weeks . ' ' . self::pl($weeks, 'tygodniu', 'tygodniach', 'tygodniach') . ') — próg ' . $threshold);
        $decision = $r !== null ? ['code' => (string) $r['decision'], 'label' => self::DECISIONS[$r['decision']] ?? (string) $r['decision'], 'note' => $r['note'],
            'balance' => $r['balance'] !== null ? (int) $r['balance'] : null, 'flags' => $r['flags'], 'by' => $r['decided_by'],
            'at' => self::label(substr((string) $r['updated_at'], 0, 10)) . ', ' . self::hm((string) $r['updated_at'])] : null;
        $previous = $prev !== null ? ['code' => (string) $prev['decision'], 'label' => self::DECISIONS[$prev['decision']] ?? (string) $prev['decision'], 'note' => $prev['note'],
            'balance' => $prev['balance'] !== null ? (int) $prev['balance'] : null] : null;
        return ['id' => $x['id'], 'key' => (string) $x['id'], 'name' => $x['name'], 'delivered' => $x['delivered'], 'returned' => $x['returned'], 'balance' => $balance,
            'prev_balance' => $prevBalance, 'delta' => $balance - $prevBalance, 'over' => $over, 'stops' => $x['stops'],
            'last_ship' => $x['last_ship'], 'last_ship_label' => $x['last_ship'] ? self::label($x['last_ship']) : null,
            'week_delivered' => $x['week_delivered'], 'week_returned' => $x['week_returned'], 'week_stops' => $x['week_stops'], 'missing' => $x['missing'], 'damaged' => $x['damaged'],
            'signals' => $signals, 'decision' => $decision, 'previous' => $previous, 'review' => $signals !== [] || $decision !== null, 'waiting' => $signals !== [] && $decision === null];
    }

    /** Krok 2 : décision pour un client signalé (une par semaine examinée, la dernière compte). */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $id = (int) ($in['id_customer'] ?? 0);
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : przypomnienie / odbiór zaplanowany, OK — saldo uzgodnione albo spisane / do faktury.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        if (in_array($decision, self::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new CustomException($decision === 'reminder' ? 'Przypomnienie / odbiór zaplanowany : wpisz notatkę (kto, kiedy, ile palet odbieramy).'
                : 'Spisane / do faktury : wpisz notatkę (ile palet, dlaczego, kto fakturuje).', 'errors', 422);
        }
        $d = $this->data($today);
        $c = array_values(array_filter($d['review'], fn($x) => $x['id'] === $id))[0] ?? null;
        if ($c === null) throw new CustomException('Tego klienta nie ma na liście do wyjaśnienia (saldo w progu, bez brakujących i uszkodzonych palet w ocenianym tygodniu).', 'errors', 422);
        $this->repo->saveDecision($d['week_start'], $id, $decision, $note !== '' ? $note : null, $c['balance'], implode(',', array_column($c['signals'], 'code')), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — chaque client signalé a sa décision ; la tâche de la semaine se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'klienta', 'klientów', 'klientów') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « tydzień 16.11 – 22.11 : saldo 37 palet EUR u 9 klientów (+5 vs poprzedni tydzień) · 2 brakujące · 1 uszkodzona · 4 do wyjaśnienia : 2 przypomnienia · … ». */
    public static function summary(array $t, bool $available, string $weekLabel): string
    {
        $s = 'tydzień ' . $weekLabel . ' : ';
        if (!$available) $s .= self::NO_DATA;
        else {
            $s .= 'saldo ' . $t['balance'] . ' ' . self::pl(abs($t['balance']), 'paleta', 'palety', 'palet') . ' EUR';
            if ($t['customers_positive']) $s .= ' u ' . $t['customers_positive'] . ' ' . self::pl($t['customers_positive'], 'klienta', 'klientów', 'klientów');
            $s .= ' (' . ($t['delta'] > 0 ? '+' : ($t['delta'] < 0 ? '−' : '±')) . abs($t['delta']) . ' vs poprzedni tydzień)';
            if ($t['missing_pallets']) $s .= ' · ' . $t['missing_pallets'] . ' ' . self::pl($t['missing_pallets'], 'brakująca', 'brakujące', 'brakujących') . ' bez uzasadnienia';
        }
        if ($t['damaged']) $s .= ' · ' . $t['damaged'] . ' ' . self::pl($t['damaged'], 'uszkodzona', 'uszkodzone', 'uszkodzonych');
        if ($t['review'] === 0) $s .= ' · nic do wyjaśnienia';
        else {
            $s .= ' · ' . $t['review'] . ' do wyjaśnienia';
            $parts = [];
            if ($t['reminder']) $parts[] = $t['reminder'] . ' ' . self::pl($t['reminder'], 'przypomnienie', 'przypomnienia', 'przypomnień');
            if ($t['agreed']) $parts[] = $t['agreed'] . ' ' . self::pl($t['agreed'], 'uzgodnione', 'uzgodnione', 'uzgodnionych');
            if ($t['written_off']) $parts[] = $t['written_off'] . ' ' . self::pl($t['written_off'], 'spisane', 'spisane', 'spisanych');
            if ($parts) $s .= ' : ' . implode(' · ', $parts);
        }
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
