<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Domain\Params\ParamRules;
use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Stock\FinalProduct\StockLedgerRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\StockMonthRepository;
use App\Services\Organization\TaskWizards;
use App\Services\Utils\TCPDFService;

/**
 * Asystent « Stany magazynowe na koniec miesiąca » [403] (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik
 * produkcji 6.4 « Sporządzanie miesięcznych zestawień stanów magazynowych na koniec miesiąca i przekazywanie ich do Asi »).
 * Mois examiné = le mois écoulé. L'ERP n'enregistre aucun état de stock daté : l'assistant le recalcule et l'enregistre
 * (instantané n_stock_month_statement / n_stock_month_item, refait à la demande tant qu'il n'est pas przekazane), puis le
 * PDF et le CSV sont toujours tirés de l'instantané — reproductibles :
 *   - produits finis : cartons en stock au début et à la fin du mois, règle du rejestr ruchów (StockLedgerRepository::
 *     summary : karton entré à la date de son étiquette, sorti à la date de sa palette expédiée) ; valeur = coût surowców
 *     de la karta produktu au moment de l'instantané (FinalProductStockStateService : sans coût, hors somme) — l'ERP ne
 *     garde pas l'historique de ce coût ;
 *   - surowce : règle de Zaopatrzenie (dernière inwentaryzacja + przyjęcia − zużycie des recettes) bornée à la fin du mois
 *     (et à la fin du mois précédent pour le début) ; prix = historique des prix à cette date, sinon prix actuel.
 * Signaux : produit sans coût, cartons périmés en stock, produit inactif avec stock ; surowiec en stock négatif, sans
 * inwentaryzacja (seulement les przyjęcia), sans prix ; écart de valeur début → fin au-delà des réglages
 * stock_month.variance_pct et stock_month.variance_min_pln. Décisions (n_stock_month_review) : Sprawdzone — stan poprawny
 * (note : pourquoi), Do korekty (note : quoi) ; puis « Przekazane do księgowości » pour l'ensemble (note : comment, quand —
 * l'humain le remet, l'ERP n'envoie rien). « Zakończ » écrit n_wizard_done ; la tâche du mois se valide seule.
 * Lecture seule hors de n_stock_month_* et n_wizard_done : aucun stock, aucun prix changé, aucun e-mail.
 */
final class StockMonthService
{
    public const CODE = 'stock_month_reported';
    public const PATH = '/stock/month-end';
    public const DECISIONS = ['confirmed' => 'Sprawdzone — stan poprawny', 'to_correct' => 'Do korekty'];
    public const HANDED = ['handed' => 'Przekazane do księgowości'];
    public const NOTE_REQUIRED = ['confirmed', 'to_correct', 'handed'];
    public const KINDS = ['product' => 'Produkt gotowy', 'material' => 'Surowiec'];
    /** Signaux : code => [libellé, écran (clé de LINKS)]. */
    public const SIGNALS = [
        'NEGATIVE' => ['stan ujemny', 'supply'],
        'NO_COUNT' => ['stan bez inwentaryzacji (tylko przyjęcia)', 'supply'],
        'NO_PRICE' => ['brak ceny — poza wartością', 'prices'],
        'NO_COST' => ['brak kosztu surowców karty — poza wartością', 'card'],
        'EXPIRED' => ['kartony po dacie ważności w stanie', 'stock'],
        'INACTIVE' => ['produkt nieaktywny ze stanem', 'stock'],
        'VARIANCE' => ['duża zmiana wartości wobec początku miesiąca', null],
    ];
    public const LINKS = [
        'supply' => ['/workshop/supply', 'Zaopatrzenie › stan i inwentaryzacja'],
        'prices' => ['/materials/prices', 'Ceny surowców'],
        'card' => ['/workshop/products#/product/%d?step=4', 'Karta produktu › Receptura'],
        'stock' => ['/logistics/stock', 'Stany i palety'],
        'movements' => ['/workshop/stock-movements', 'Ruchy magazynowe'],
    ];
    /** clé => [code n_prod_param, défaut] */
    private const PARAMS = ['variance_pct' => ['stock_month.variance_pct', 50], 'variance_min' => ['stock_month.variance_min_pln', 1000]];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private StockMonthRepository $repo, private StockLedgerRepository $ledger, private LightWizardRepository $wizards, private ParamRepository $params)
    {
    }

    /** Premier jour du mois examiné (le mois écoulé) : clé de l'instantané, des décisions et de l'état de la tâche du mois. */
    public static function reviewedMonth(string $today): string
    {
        return date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month'));
    }

    /** Réglage stock_month.* (n_prod_param, borné par ParamRules ; valeur invalide → défaut). */
    public function param(string $name): int
    {
        [$code, $default] = self::PARAMS[$name];
        $raw = trim($this->params->get($code, (string) $default));
        $v = ctype_digit($raw) ? (int) $raw : $default;
        [$min, $max] = ParamRules::RANGES[$code];
        return $v < $min || $v > $max ? $default : $v;
    }

    /** Données des trois étapes : instantané s'il existe, sinon calcul du moment (non enregistré). */
    public function data(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewedMonth($today);
        $end = date('Y-m-t', strtotime($month));
        $sources = $this->repo->sources();
        $statement = $this->repo->statement($month);
        $rows = $statement ? array_map(fn($r) => self::fromSnapshot($r), $this->repo->items($month)) : $this->compute($month)['items'];
        $items = array_map(fn($r) => $this->present($r), $rows);

        $dec = $this->repo->decisions($month);
        foreach ($items as &$it) {
            $mine = $dec[$it['key']] ?? null;
            $covers = $mine !== null && array_diff($it['codes'], array_filter(explode(',', (string) $mine['flags']))) === [];
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['waiting'] = $it['codes'] !== [] && !$covers;
            $it['reason'] = $it['waiting'] && $mine !== null ? 'nowy sygnał po decyzji' : null;
        }
        unset($it);
        $review = array_values(array_filter($items, fn($i) => $i['codes'] !== [] || $i['decision'] !== null));
        usort($review, fn($a, $b) => [$b['waiting'], $a['kind'] === 'material', count($b['codes']), $a['title'], $a['key']] <=> [$a['waiting'], $b['kind'] === 'material', count($a['codes']), $b['title'], $b['key']]);
        $handed = isset($dec['statement']) ? $this->presentDecision($dec['statement']) : null;

        $sum = fn(string $kind, string $f) => round(array_sum(array_map(fn($i) => (float) ($i[$f] ?? 0), array_filter($items, fn($i) => $i['kind'] === $kind))), 2);
        $count = fn(callable $f) => count(array_filter($review, $f));
        $byCode = [];
        foreach (array_keys(self::SIGNALS) as $c) $byCode[$c] = count(array_filter($items, fn($i) => in_array($c, $i['codes'], true)));
        $totals = ['products' => ['rows' => count(array_filter($items, fn($i) => $i['kind'] === 'product')), 'value' => $sum('product', 'value'), 'opening_value' => $sum('product', 'opening_value'),
                'cartons' => (int) round(array_sum(array_map(fn($i) => $i['kind'] === 'product' ? $i['quantity'] : 0, $items)))],
            'materials' => ['rows' => count(array_filter($items, fn($i) => $i['kind'] === 'material')), 'value' => $sum('material', 'value'), 'opening_value' => $sum('material', 'opening_value')],
            'review' => count($review), 'waiting' => $count(fn($i) => $i['waiting']), 'resolved' => $count(fn($i) => !$i['waiting']), 'by_code' => $byCode];
        $totals['value'] = round($totals['products']['value'] + $totals['materials']['value'], 2);
        foreach (array_keys(self::DECISIONS) as $k) $totals[$k] = $count(fn($i) => $i['decision'] !== null && $i['decision']['code'] === $k);
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        $taskMonth = substr($today, 0, 7) . '-01';
        $done = $this->wizards->done(self::CODE, $taskMonth, date('Y-m-01', strtotime($taskMonth . ' +1 month')));
        return ['today' => $today, 'label' => self::label($today), 'month_start' => $month, 'month_end' => $end, 'month_label' => $label,
            'end_label' => self::label($end) . '.' . substr($end, 0, 4),
            'sources' => $sources, 'available' => $sources['products'] || $sources['materials'],
            'snapshot' => $statement ? ['at' => self::label(substr((string) $statement['taken_at'], 0, 10)) . '.' . substr((string) $statement['taken_at'], 0, 4) . ', ' . self::hm((string) $statement['taken_at']),
                'by' => $statement['taken_by_name'], 'after_month' => substr((string) $statement['taken_at'], 0, 10) > $end] : null,
            'inconsistent' => $sources['products'] ? $this->ledger->inconsistent($month, $end) : 0,
            'items' => $items, 'review' => $review, 'totals' => $totals, 'handed' => $handed,
            'can_hand' => $statement !== null && $totals['waiting'] === 0,
            'can_finish' => $statement !== null && $totals['waiting'] === 0 && $handed !== null,
            'summary' => self::summary($label, $totals, $handed !== null),
            'params' => ['variance_pct' => $this->param('variance_pct'), 'variance_min' => $this->param('variance_min')],
            'decisions' => self::DECISIONS, 'handed_label' => self::HANDED['handed'], 'note_required' => self::NOTE_REQUIRED, 'kind_labels' => self::KINDS,
            'files' => ['pdf' => '/ajax/stock/month-end/pdf', 'csv' => '/ajax/stock/month-end/csv'],
            'links' => ['movements' => ['href' => self::LINKS['movements'][0], 'label' => self::LINKS['movements'][1]], 'supply' => ['href' => self::LINKS['supply'][0], 'label' => self::LINKS['supply'][1]]],
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Calcul de l'état au dernier jour du mois examiné (et au dernier jour du mois précédent pour le début). */
    private function compute(string $month): array
    {
        $end = date('Y-m-t', strtotime($month));
        $prevEnd = date('Y-m-d', strtotime($month . ' -1 day'));
        $pct = $this->param('variance_pct');
        $min = $this->param('variance_min');
        $items = [];
        $sources = $this->repo->sources();
        if ($sources['products']) {
            $state = $this->repo->productState($end);
            foreach ($this->ledger->summary($month, $end) as $r) {
                $closing = (int) $r['closing'];
                $opening = (int) $r['opening'];
                if ($closing === 0 && $opening === 0) continue;
                $id = (int) $r['id_product'];
                $cost = (float) $r['card_cost'] > 0 ? (float) $r['card_cost'] : null;
                $flags = [];
                $detail = [];
                if ($closing > 0 && $cost === null) $flags[] = 'NO_COST';
                if (($state[$id]['expired'] ?? 0) > 0) { $flags[] = 'EXPIRED'; $detail[] = $state[$id]['expired'] . ' kart. po dacie ważności'; }
                if ($closing > 0 && ($state[$id]['active'] ?? 1) === 0) $flags[] = 'INACTIVE';
                $value = $cost !== null ? round($closing * $cost, 2) : null;
                $openingValue = $cost !== null ? round($opening * $cost, 2) : null;
                if (($v = self::variance($value, $openingValue, $pct, $min)) !== null) { $flags[] = 'VARIANCE'; $detail[] = $v; }
                $items[] = ['kind' => 'product', 'item_id' => $id, 'name' => trim((string) ($r['name'] ?? '')) ?: 'produkt #' . $id, 'unit' => 'kart.', 'quantity' => $closing, 'opening' => $opening,
                    'unit_price' => $cost, 'price_source' => $cost !== null ? 'card' : null, 'value' => $value, 'opening_value' => $openingValue, 'basis' => 'ledger', 'count_date' => null,
                    'flags' => $flags, 'detail' => $detail ? implode(' ; ', $detail) : null];
            }
        }
        $now = $this->repo->materialsAt($end) ?? [];
        $before = $this->repo->materialsAt($prevEnd) ?? [];
        foreach ($now + array_diff_key($before, $now) as $id => $m) {
            $q = isset($now[$id]) ? (float) $now[$id]['quantity'] : 0.0;
            $o = isset($before[$id]) ? (float) $before[$id]['quantity'] : 0.0;
            if (abs($q) < 0.0005 && abs($o) < 0.0005) continue;
            $price = $now[$id]['price'] ?? $m['price'];
            $flags = [];
            $detail = [];
            if ($q < 0) $flags[] = 'NEGATIVE';
            if (isset($now[$id]) && $now[$id]['basis'] === 'receipts' && abs($q) >= 0.0005) $flags[] = 'NO_COUNT';
            if ($q > 0 && $price === null) $flags[] = 'NO_PRICE';
            $value = $price !== null ? round($q * $price, 2) : null;
            $openingValue = ($before[$id]['price'] ?? null) !== null ? round($o * $before[$id]['price'], 2) : null;
            if (!in_array('NEGATIVE', $flags, true) && ($v = self::variance($value, $openingValue, $pct, $min)) !== null) { $flags[] = 'VARIANCE'; $detail[] = $v; }
            $items[] = ['kind' => 'material', 'item_id' => (int) $id, 'name' => $m['name'], 'unit' => $m['unit'], 'quantity' => round($q, 3), 'opening' => round($o, 3),
                'unit_price' => $price, 'price_source' => isset($now[$id]) ? $now[$id]['price_source'] : $m['price_source'], 'value' => $value, 'opening_value' => $openingValue,
                'basis' => isset($now[$id]) ? $now[$id]['basis'] : 'none', 'count_date' => $now[$id]['count_date'] ?? null, 'flags' => $flags, 'detail' => $detail ? implode(' ; ', $detail) : null];
        }
        usort($items, fn($a, $b) => [$a['kind'] === 'material', $a['name'], $a['item_id']] <=> [$b['kind'] === 'material', $b['name'], $b['item_id']]);
        return ['items' => $items];
    }

    /** « z 1 200,00 zł na 3 400,00 zł (+183 %) » quand l'écart dépasse les deux seuils, sinon null. */
    private static function variance(?float $value, ?float $opening, int $pct, int $min): ?string
    {
        if ($value === null || $opening === null) return null;
        $delta = $value - $opening;
        if (abs($delta) < $min || abs($delta) < 0.005) return null;
        if ($opening > 0 && abs($delta) / $opening * 100 < $pct) return null;
        return 'z ' . self::money($opening) . ' na ' . self::money($value) . ($opening > 0 ? ' (' . ($delta > 0 ? '+' : '−') . (int) round(abs($delta) / $opening * 100) . ' %)' : '');
    }

    private static function fromSnapshot(array $r): array
    {
        $f = fn($v) => $v === null ? null : (float) $v;
        return ['kind' => (string) $r['kind'], 'item_id' => (int) $r['item_id'], 'name' => (string) $r['name'], 'unit' => $r['unit'], 'quantity' => (float) $r['quantity'],
            'opening' => (float) $r['opening'], 'unit_price' => $f($r['unit_price']), 'price_source' => $r['price_source'], 'value' => $f($r['value']), 'opening_value' => $f($r['opening_value']),
            'basis' => (string) $r['basis'], 'count_date' => $r['count_date'], 'flags' => array_values(array_filter(explode(',', (string) $r['flags']))), 'detail' => $r['detail']];
    }

    /** Ligne affichée : quantité lisible (g → kg, ml → l), prix, valeur, signaux et écrans liés. */
    private function present(array $r): array
    {
        [$qty, $unit, $factor] = self::readable($r['quantity'], $r['unit']);
        $codes = array_values(array_intersect(array_keys(self::SIGNALS), $r['flags']));
        $sub = [self::num($qty) . ' ' . $unit];
        if ($r['unit_price'] !== null) $sub[] = self::money($r['unit_price'] * $factor) . ' / ' . ($r['kind'] === 'product' ? 'karton' : $unit) . ($r['price_source'] === 'history' ? ' (cena z końca miesiąca)' : ($r['price_source'] === 'current' ? ' (cena aktualna)' : ''));
        if ($r['kind'] === 'material' && $r['count_date']) $sub[] = 'inwentaryzacja ' . self::label((string) $r['count_date']);
        $keys = array_values(array_unique(array_filter(array_map(fn($c) => self::SIGNALS[$c][1], $codes))));
        if ($keys === []) $keys = [$r['kind'] === 'product' ? 'stock' : 'supply'];
        if ($r['kind'] === 'product') $keys[] = 'movements';
        // Le détail (cartons périmés, écart de valeur) s'affiche une fois, sous le premier de ces deux signaux.
        $detailOn = array_values(array_intersect($codes, ['EXPIRED', 'VARIANCE']))[0] ?? null;
        return ['key' => $r['kind'] . ':' . $r['item_id'], 'kind' => $r['kind'], 'kind_label' => self::KINDS[$r['kind']], 'item_id' => $r['item_id'], 'title' => $r['name'],
            'sub' => implode(' · ', $sub), 'quantity' => $r['quantity'], 'qty_label' => self::num($qty) . ' ' . $unit, 'opening_label' => self::num(self::readable($r['opening'], $r['unit'])[0]) . ' ' . $unit,
            'unit_price' => $r['unit_price'], 'value' => $r['value'], 'opening_value' => $r['opening_value'], 'basis' => $r['basis'], 'codes' => $codes,
            'signals' => array_map(fn($c) => ['code' => $c, 'label' => self::SIGNALS[$c][0], 'detail' => $c === $detailOn ? $r['detail'] : null], $codes),
            'links' => array_map(fn($k) => ['href' => str_replace('%d', (string) $r['item_id'], self::LINKS[$k][0]), 'label' => self::LINKS[$k][1]], $keys)];
    }

    private function presentDecision(array $d): array
    {
        $label = self::DECISIONS[$d['decision']] ?? self::HANDED[$d['decision']] ?? (string) $d['decision'];
        return ['code' => (string) $d['decision'], 'label' => $label, 'note' => $d['note'], 'flags' => array_values(array_filter(explode(',', (string) $d['flags']))),
            'by' => $d['decided_by'], 'at' => self::label(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /** Krok 1 : enregistre (ou refait) l'instantané du mois examiné — refusé une fois l'ensemble przekazane. */
    public function snapshot(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewedMonth($today);
        if (isset($this->repo->decisions($month)['statement'])) throw new CustomException('Zestawienie jest już przekazane do księgowości — nie można go odświeżyć.', 'errors', 422);
        $sources = $this->repo->sources();
        if (!$sources['products'] && !$sources['materials']) throw new CustomException('Brak danych o stanach w bazie (kartony, inwentaryzacje, przyjęcia).', 'errors', 422);
        $items = $this->compute($month)['items'];
        $value = fn(string $k) => round(array_sum(array_map(fn($i) => (float) ($i['value'] ?? 0), array_filter($items, fn($i) => $i['kind'] === $k))), 2);
        $rows = fn(string $k) => count(array_filter($items, fn($i) => $i['kind'] === $k));
        $this->repo->saveSnapshot($month, ['products_value' => $value('product'), 'materials_value' => $value('material'), 'products_rows' => $rows('product'), 'materials_rows' => $rows('material'),
            'note' => 'stan na ' . date('Y-m-t', strtotime($month)) . ' ; ' . $this->param('variance_pct') . ' % / ' . $this->param('variance_min') . ' zł'], $items, $userId);
        return $this->data($today);
    }

    /**
     * Krok 2 : décision pour une ligne signalée de l'instantané (Sprawdzone — stan poprawny / Do korekty, note obligatoire), ou
     * pour l'ensemble (key « statement » : Przekazane do księgowości, note obligatoire, toutes les lignes décidées).
     */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $d = $this->data($today);
        if ($d['snapshot'] === null) throw new CustomException('Najpierw zapisz zestawienie (krok 1).', 'errors', 422);
        if ($key === 'statement') {
            if ($decision !== 'handed') throw new CustomException('Wybierz « Przekazane do księgowości ».', 'errors', 422);
            if ($d['totals']['waiting'] > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $d['totals']['waiting'] . ' ' . self::pl($d['totals']['waiting'], 'pozycji', 'pozycji', 'pozycji') . ' z sygnałem.', 'errors', 422);
            if (mb_strlen($note) < 3) throw new CustomException('Przekazane do księgowości : wpisz komu, jak i kiedy (np. Asia, e-mail z PDF i CSV 3.12).', 'errors', 422);
            $this->repo->saveDecision($d['month_start'], 'statement', 'handed', $note, '', $userId);
            return $this->data($today);
        }
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : sprawdzone — stan poprawny albo do korekty.', 'errors', 422);
        $it = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        if ($it === null) throw new CustomException('Tej pozycji nie ma wśród pozycji z sygnałem w zestawieniu.', 'errors', 422);
        if (mb_strlen($note) < 3) {
            throw new CustomException($decision === 'confirmed' ? 'Sprawdzone : wpisz, dlaczego stan jest poprawny (np. inwentaryzacja 30.11, towar sezonowy).'
                : 'Do korekty : wpisz, co trzeba poprawić (np. stan −2 kg — brak przyjęcia faktury 12/11).', 'errors', 422);
        }
        if ($d['handed'] !== null) throw new CustomException('Zestawienie jest już przekazane do księgowości.', 'errors', 422);
        $this->repo->saveDecision($d['month_start'], $key, $decision, $note, implode(',', $it['codes']), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — instantané, chaque ligne signalée décidée, ensemble przekazane ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        if ($d['snapshot'] === null) throw new CustomException('Najpierw zapisz zestawienie (krok 1).', 'errors', 422);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'pozycji', 'pozycji', 'pozycji') . ' z kroku 2.', 'errors', 422);
        if ($d['handed'] === null) throw new CustomException('Zapisz « Przekazane do księgowości » (krok 2).', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** CSV de l'instantané (séparateur « ; », UTF-8 avec BOM, montants à virgule). */
    public function csv(?string $today = null): array
    {
        $d = $this->snapshotData($today);
        $num = fn(?float $v, int $dec) => $v === null ? '' : number_format($v, $dec, ',', '');
        $cell = fn(?string $v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $out = ["\u{FEFF}" . implode(';', array_map($cell, ['Miesiąc', 'Stan na dzień', 'Rodzaj', 'ID', 'Nazwa', 'Ilość', 'Jednostka', 'Cena jednostkowa (PLN)', 'Źródło ceny',
            'Wartość (PLN)', 'Ilość na początek miesiąca', 'Wartość na początek (PLN)', 'Podstawa stanu', 'Inwentaryzacja', 'Sygnały', 'Decyzja', 'Notatka']))];
        foreach ($d['items'] as $i) {
            $dec = $d['decisions'][$i['key']] ?? null;
            $out[] = implode(';', array_map($cell, [substr($d['month_start'], 0, 7), $d['month_end'], $i['kind_label'], (string) $i['item_id'], $i['title'], $num($i['quantity'], 3),
                $i['raw_unit'], $num($i['unit_price'], 6), $i['price_source'] ?? '', $num($i['value'], 2), $num($i['opening'], 3), $num($i['opening_value'], 2), $i['basis'], $i['count_date'] ?? '',
                implode(', ', array_column($i['signals'], 'label')), $dec ? $dec['label'] : '', $dec ? (string) $dec['note'] : '']));
        }
        return ['name' => 'STANY_MAGAZYNOWE_' . substr($d['month_start'], 0, 7) . '.csv', 'content' => implode("\r\n", $out) . "\r\n"];
    }

    /** PDF de l'instantané : produits finis puis surowce, totaux, base du calcul. */
    public function pdf(?string $today = null): array
    {
        $d = $this->snapshotData($today);
        $pdf = new TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false);
        $pdf->brandPage('Stany magazynowe', ['ref' => substr($d['month_start'], 0, 7)]);
        $pdf->brandHeader('Stany magazynowe na koniec miesiąca', [['Miesiąc', $d['month_label']], ['Stan na', $d['month_end']], ['Zestawienie zapisane', $d['snapshot']['at'] . ($d['snapshot']['by'] ? ' · ' . $d['snapshot']['by'] : '')]],
            'Produkty gotowe i surowce — zestawienie dla księgowości');
        foreach (['product' => 'Produkty gotowe (kartony)', 'material' => 'Surowce'] as $kind => $title) {
            $rows = array_values(array_filter($d['items'], fn($i) => $i['kind'] === $kind));
            $pdf->brandSection($title, count($rows) . ' ' . self::pl(count($rows), 'pozycja', 'pozycje', 'pozycji'));
            $table = array_map(fn($i) => [$i['title'] . ($i['value'] === null && $i['quantity'] > 0 ? ' **' : ''), $i['qty_label'], $i['unit_price'] === null ? '—' : $i['price_label'], $i['value'] === null ? 'brak ceny' : self::money($i['value'])], $rows);
            $table[] = ['_total' => ['Razem', '', '', self::money($d['totals'][$kind === 'product' ? 'products' : 'materials']['value'])]];
            $pdf->brandTable([['label' => $kind === 'product' ? 'Produkt' : 'Surowiec', 'w' => 90], ['label' => 'Ilość', 'w' => 28, 'align' => 'R'],
                ['label' => 'Cena', 'w' => 36, 'align' => 'R'], ['label' => 'Wartość', 'w' => 32, 'align' => 'R']], $table, ['font' => 8]);
        }
        $pdf->brandSection('Razem', self::money($d['totals']['value']));
        $pdf->brandNote('Produkty : stan odtworzony z kartonów (etykieta = wejście, paleta wysłana = wyjście) na koniec miesiąca ; wartość wg kosztu surowców karty produktu w chwili zapisu zestawienia.');
        $pdf->brandNote('Surowce : ostatnia inwentaryzacja + przyjęcia − zużycie wg receptur do końca miesiąca (bez inwentaryzacji : tylko przyjęcia) ; cena z historii cen na koniec miesiąca, inaczej aktualna.');
        $pdf->brandNote('** Brak ceny albo kosztu : pozycja poza wartością.');
        return ['name' => 'STANY_MAGAZYNOWE_' . substr($d['month_start'], 0, 7) . '.pdf', 'content' => $pdf->Output('', 'S')];
    }

    /** Données de l'instantané pour les fichiers (404 sans instantané : les fichiers ne sont jamais recalculés). */
    private function snapshotData(?string $today): array
    {
        $d = $this->data($today);
        if ($d['snapshot'] === null) throw new CustomException('Brak zapisanego zestawienia za ' . $d['month_label'] . ' — zapisz je w kroku 1.', 'errors', 404);
        $raw = array_column(array_map(fn($r) => ['key' => $r['kind'] . ':' . $r['item_id']] + $r, $this->repo->items($d['month_start'])), null, 'key');
        foreach ($d['items'] as &$i) {
            $r = $raw[$i['key']];
            [, $unit, $factor] = self::readable((float) $r['quantity'], $r['unit']);
            $i['raw_unit'] = (string) ($r['unit'] ?? '');
            $i['opening'] = (float) $r['opening'];
            $i['price_source'] = $r['price_source'];
            $i['count_date'] = $r['count_date'];
            $i['price_label'] = $i['unit_price'] === null ? '—' : self::money($i['unit_price'] * $factor) . ' / ' . ($i['kind'] === 'product' ? 'karton' : $unit);
        }
        unset($i);
        $d['decisions'] = [];
        foreach ($d['review'] as $r) if ($r['decision']) $d['decisions'][$r['key']] = $r['decision'];
        return $d;
    }

    /** « październik 2026 : produkty 12 340,00 zł (35 poz.) · surowce 8 120,50 zł (60 poz.) · 4 z sygnałem · przekazane » */
    public static function summary(string $month, array $t, bool $handed): string
    {
        $s = $month . ' : produkty ' . self::money($t['products']['value']) . ' (' . $t['products']['rows'] . ' poz.) · surowce ' . self::money($t['materials']['value']) . ' (' . $t['materials']['rows'] . ' poz.)';
        if ($t['review']) $s .= ' · ' . $t['review'] . ' z sygnałem';
        if ($t['confirmed'] || $t['to_correct']) $s .= ' · decyzje : ' . implode(' · ', array_filter([$t['confirmed'] ? $t['confirmed'] . ' sprawdzone' : null, $t['to_correct'] ? $t['to_correct'] . ' do korekty' : null]));
        return $s . ($handed ? ' · przekazane' : '');
    }

    /** Quantité lisible : g → kg, ml → l (comme Zaopatrzenie) ; [quantité, unité, facteur du prix]. */
    private static function readable(float $qty, ?string $unit): array
    {
        return match ($unit) { 'g' => [$qty / 1000, 'kg', 1000], 'ml' => [$qty / 1000, 'l', 1000], default => [$qty, $unit ?: 'j.', 1] };
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

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' zł';
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
