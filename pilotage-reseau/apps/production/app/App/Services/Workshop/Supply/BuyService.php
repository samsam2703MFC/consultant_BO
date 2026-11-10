<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Purchasing\MaterialCostRepository;
use App\Repositories\Workshop\BuyRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Communication\TemplateRenderer;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Zakupy tygodnia (makiety 1 à 5) : co tydzień surowce i opakowania na uzupełnienie stanów, w 3 krokach.
 *  1. Co kupić : stan → co wyprodukować (arkusz tygodnia : zamówienia + uzupełnienie stanów) → receptury → potrzeba ;
 *     zapas +0 / +5 / +10 % potrzeby, w górę do pełnych opakowań ; ostatnia najtańsza cena i dostawca (historia cen).
 *  2. Komu i jak : 1 do 3 dostawców na pozycję (dostawcy pozycji, potem historia), warunki dla wszystkich : płatność 7 / 14 /
 *     30 dni albo proforma, dostawa albo odbiór, dostawa najpóźniej, odpowiedź do, cena za podwójną ilość.
 *  3. Wyślij : jeden e-mail na dostawcę (tylko jego pozycje, w jego języku — szablon buy_request, kolejka Komunikacji),
 *     z linkiem do wpisania cen bez logowania ; odpowiedzi także wpisywane ręcznie.
 * Oferty (n_mp_offer, source = weekly) z minimum zamówienia (ilość, kwota zł albo opis) : najtańsza ważna oferta
 * (minimum spełnione) ★ ; zamówienie u wybranych → zamówienia u dostawców (n_po, e-mail supplier_order) i decyzje
 * (n_buy_order : oszczędność = (ostatnia cena − cena) × ilość). Wyniki operatora i zespołu : miesiąc / kwartał / rok,
 * marża = oszczędność ÷ sprzedaż, zwrot = oszczędność ÷ koszt czasu w kreatorze (rejestrator aktywności × koszt godziny).
 * Ilości w API w jednostkach bazowych (g, ml, szt), unit / factor podają jednostkę wyświetlaną (kg, l…) ; ceny za jednostkę bazową.
 */
final class BuyService
{
    public const SAFETY = [0, 5, 10];
    public const PAY = ['7', '14', '30', 'proforma'];
    public const DELIVERY = ['delivery', 'pickup'];
    public const STATUSES = ['draft', 'sent', 'ordered', 'closed'];
    public const MAX_SUPPLIERS = 3;
    public const PERIODS = ['month', 'quarter', 'year'];
    private const MAX_LINES = 200;
    private const MAX_QTY = 1_000_000_000;
    private const LANGS = ['pl', 'fr', 'nl', 'en'];
    /** Teksty e-maili do dostawców (warunki, podwójna ilość). */
    private const TEXT = [
        'pay' => [
            'pl' => ['7' => 'płatność 7 dni od faktury', '14' => 'płatność 14 dni od faktury', '30' => 'płatność 30 dni od faktury', 'proforma' => 'proforma (przedpłata)'],
            'fr' => ['7' => 'paiement à 7 jours', '14' => 'paiement à 14 jours', '30' => 'paiement à 30 jours', 'proforma' => 'proforma (paiement d\'avance)'],
            'nl' => ['7' => 'betaling binnen 7 dagen', '14' => 'betaling binnen 14 dagen', '30' => 'betaling binnen 30 dagen', 'proforma' => 'proforma (vooruitbetaling)'],
            'en' => ['7' => 'payment within 7 days', '14' => 'payment within 14 days', '30' => 'payment within 30 days', 'proforma' => 'proforma (prepayment)'],
        ],
        'delivery' => [
            'pl' => ['delivery' => 'dostawa do nas', 'pickup' => 'odbiór własny'],
            'fr' => ['delivery' => 'livraison chez nous', 'pickup' => 'enlèvement par nos soins'],
            'nl' => ['delivery' => 'levering bij ons', 'pickup' => 'afhaling door ons'],
            'en' => ['delivery' => 'delivery to us', 'pickup' => 'collection by us'],
        ],
        'by' => ['pl' => 'najpóźniej %s', 'fr' => 'au plus tard le %s', 'nl' => 'uiterlijk %s', 'en' => 'by %s'],
        'double' => [
            'pl' => "Prosimy także o cenę za podwójną ilość.\n", 'fr' => "Merci d'indiquer aussi le prix pour le double de la quantité.\n",
            'nl' => "Graag ook de prijs voor de dubbele hoeveelheid.\n", 'en' => "Please also quote the price for double the quantity.\n",
        ],
        'reminder' => ['pl' => 'Przypomnienie : ', 'fr' => 'Rappel : ', 'nl' => 'Herinnering : ', 'en' => 'Reminder : '],
    ];

    public function __construct(
        private BuyRepository $repo,
        private SupplyService $supply,
        private SupplyRepository $supplyRepo,
        private PriceHistoryService $prices,
        private CommunicationTemplateRepository $templates,
        private TemplateRenderer $renderer,
        private OutgoingMessageService $outgoing,
        private MaterialCostRepository $company,
        private ParamRepository $params,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    // --- Krok 1 : co kupić -----------------------------------------------------------------------------------------

    /**
     * Tydzień produkcji : zakupy tego tygodnia (jeśli są) albo propozycja (pozycje do kupienia z zapasem), produkty do
     * uzupełnienia stanów. Domyślnie następny tydzień produkcji (zakupy w poniedziałek na tydzień od środy).
     */
    public function week(?string $date, ?int $safety, ApiContext $context): array
    {
        $date ??= date('Y-m-d', strtotime('+7 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw ApiException::validation([['field' => 'date', 'code' => 'INVALID_DATE', 'params' => []]]);
        $needs = $this->supply->needs($date, 1, $context);
        $week = $needs['weeks'][0];
        $round = $this->repo->roundForWeek($week['week_start']);
        $products = $this->products($this->supply->lastSheets()[0] ?? ['rows' => []]);
        $base = ['week' => $week + ['this' => $week['week_start'] <= date('Y-m-d') && date('Y-m-d') <= $week['week_end']], 'products' => $products,
            'weeks' => $this->weekChoices(), 'can_write' => $context->can('prod.supply.write'),
            'missing_suppliers' => $this->materialSuppliers(true, $context)['missing']];
        // Propozycja : bez zakupów, w szkicu (powrót do kroku 1 : inny zapas) i po zakończeniu ; wysłane — tylko zakupy.
        $open = $round !== null && in_array($round['status'], ['sent', 'ordered'], true);
        $safety = in_array($safety, self::SAFETY, true) ? $safety : ($round !== null && $round['status'] === 'draft' ? (int) $round['safety_pct'] : 5);
        return $base + ['round' => $round !== null ? $this->present($round, $context) : null, 'proposal' => $open ? null : $this->proposal($needs['rows'], $safety, $context)];
    }

    /** Pozycje z potrzebą w tygodniu : do kupienia (z zapasem) i ostatnia najtańsza cena ; zaznaczone, gdy trzeba kupić. */
    private function proposal(array $rows, int $safety, ApiContext $context): array
    {
        $rows = array_values(array_filter($rows, fn($r) => $r['need'] > 0 || $r['to_order'] > 0));
        $last = $this->lastPrices(array_column($rows, 'id'));
        $usual = $this->usualDays(array_filter(array_map(fn($r) => $last[$r['id']]['supplier_id'] ?? ($r['supplier']['id'] ?? null), $rows)));
        $names = array_map(fn($s) => $s['name'], $this->repo->suppliersById(array_column($last, 'supplier_id')));
        $lines = [];
        foreach ($rows as $r) {
            $qty = $this->plan($r, $safety);
            $l = $last[$r['id']] ?? null;
            $sid = $l['supplier_id'] ?? ($r['supplier']['id'] ?? null);
            $lines[] = [
                'material' => $this->material($r), 'need' => $r['need'], 'stock' => $r['stock'], 'on_order' => $r['on_order'], 'quantity' => $qty,
                'packages' => $r['package_size'] && $qty > 0 ? (int) round($qty / $r['package_size']) : null, 'package_size' => $r['package_size'],
                'last' => $l ? $l + ['supplier' => $names[$l['supplier_id']] ?? null] : null, 'selected' => $qty > 0, 'value' => $l && $qty > 0 ? round($qty * $l['price'], 2) : null,
                'arrives' => $sid !== null ? DeliveryScheduleService::nextUsualDay(date('Y-m-d', strtotime('tomorrow')), $usual[$sid] ?? []) : null,
            ];
        }
        usort($lines, fn($a, $b) => [!$a['selected'], $a['material']['packaging'], $a['material']['name']] <=> [!$b['selected'], $b['material']['packaging'], $b['material']['name']]);
        $chosen = array_filter($lines, fn($l) => $l['selected']);
        return ['safety_pct' => $safety, 'lines' => $lines, 'totals' => ['selected' => count($chosen), 'value' => round(array_sum(array_map(fn($l) => $l['value'] ?? 0.0, $chosen)), 2)]];
    }

    /**
     * Ile kupić z zapasem : potrzeba × (1 + zapas %) ; na stanie (z maks.) — uzupełnienie do maks., gdy dostępne < min. ;
     * na zamówienie — brak do potrzeby ; w górę do pełnych opakowań.
     */
    private function plan(array $r, int $safety): float
    {
        $after = max(0.0, (float) ($r['stock'] ?? 0.0)) + (float) $r['on_order'] - (float) $r['need'] * (1 + $safety / 100);
        if ($r['mode'] === 'stock' && $r['stock_max'] !== null) $qty = $after < (float) ($r['stock_min'] ?? 0) - 1e-9 ? (float) $r['stock_max'] - $after : 0.0;
        else $qty = max(0.0, -$after);
        $pack = (float) ($r['package_size'] ?? 0);
        if ($qty > 1e-9 && $pack > 0) $qty = ceil(round($qty / $pack, 6)) * $pack;
        return round($qty, 3);
    }

    /** Produkty tygodnia do zrobienia (zamówienia + uzupełnienie stanów) w kartonach : największe najpierw. */
    private function products(array $sheet): array
    {
        $out = [];
        foreach ($sheet['rows'] ?? [] as $r) {
            if ($r['item_type'] !== 'product' || $r['to_produce'] <= 0 || !$r['ppc']) continue;
            $ppc = (float) $r['ppc'];
            $out[] = ['id' => (int) $r['item_id'], 'name' => $r['name'], 'to_produce' => round($r['to_produce'] / $ppc, 1),
                'stock' => $r['stock'] !== null ? round($r['stock'] / $ppc, 1) : null, 'min' => $r['stock_min'] !== null ? round($r['stock_min'] / $ppc, 1) : null,
                'refill' => round(($r['refill'] ?? 0) / $ppc, 1), 'orders' => round(($r['orders'] ?? 0) / $ppc, 1)];
        }
        usort($out, fn($a, $b) => $b['to_produce'] <=> $a['to_produce']);
        return $out;
    }

    private function weekChoices(): array
    {
        return array_map(fn($d) => ['date' => $d], [date('Y-m-d'), date('Y-m-d', strtotime('+7 days'))]);
    }

    // --- Zakupy : utworzenie, zmiany (krok 1 i 2) ------------------------------------------------------------------

    public function create(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['week_start', 'safety_pct', 'lines'])->date('week_start', true, false)->integer('safety_pct', false, 0, 10);
        if ($v->value('safety_pct') !== null && !in_array($v->value('safety_pct'), self::SAFETY, true)) $v->add('safety_pct', 'INVALID_CHOICE', ['allowed' => '0, 5, 10']);
        $lines = $this->lineInput($v, $body);
        $v->throwIfInvalid();
        $needs = $this->supply->needs((string) $v->value('week_start'), 1, $context);
        $week = $needs['weeks'][0];
        $existing = $this->repo->roundForWeek($week['week_start']);
        if ($existing !== null && in_array($existing['status'], ['draft', 'sent', 'ordered'], true)) throw new ApiException('BUY_ROUND_EXISTS', 409, [], ['code' => $existing['code']]);
        $byId = array_column($needs['rows'], null, 'id');
        $last = $this->lastPrices(array_keys($lines));
        $materials = $this->materialsById(array_keys($lines));
        foreach (array_keys($lines) as $mid) if (!isset($materials[$mid])) throw ApiException::notFound('raw_material', $mid);
        $id = $this->tx->execute(function () use ($v, $week, $lines, $byId, $last, $materials, $context) {
            $id = $this->repo->insertRound([
                'code' => $this->repo->nextCode($week['week_start']), 'week_start' => $week['week_start'], 'week_end' => $week['week_end'], 'status' => 'draft',
                'safety_pct' => $v->value('safety_pct') ?? 5, 'pay_terms' => '7', 'delivery' => 'delivery',
                'deliver_by' => max(date('Y-m-d', strtotime('tomorrow')), date('Y-m-d', strtotime($week['week_start'] . ' -1 day'))),
                'reply_by' => $this->defaultReplyBy(), 'ask_double' => 1, 'id_user' => (int) $context->userId,
            ]);
            foreach ($lines as $mid => $qty) $this->repo->insertLine($this->lineRow($id, $mid, $qty, $byId[$mid] ?? null, $materials[$mid], $last[$mid] ?? null));
            $this->audit->record($context, 'create', 'buy_round', $id, null, ['week_start' => $week['week_start'], 'lines' => count($lines)]);
            return $id;
        });
        return $this->detail($id, $context);
    }

    /**
     * Zakupy w szkicu : pozycje i ilości (krok 1), dostawcy pozycji (1–3, krok 2) i warunki. Dostawcy : {pozycja → [dostawcy]} —
     * jedno zapytanie na dostawcę z jego pozycjami.
     */
    public function update(int $id, array $body, ApiContext $context): array
    {
        $round = $this->draft($id);
        $v = (new Validator($body, true))->only(['safety_pct', 'lines', 'pay_terms', 'delivery', 'deliver_by', 'reply_by', 'ask_double', 'suppliers'])
            ->integer('safety_pct', false, 0, 10)->choice('pay_terms', self::PAY, false)->choice('delivery', self::DELIVERY, false)
            ->date('deliver_by', false, false)->datetime('reply_by', false)->boolean('ask_double');
        if ($v->value('safety_pct') !== null && !in_array($v->value('safety_pct'), self::SAFETY, true)) $v->add('safety_pct', 'INVALID_CHOICE', ['allowed' => '0, 5, 10']);
        if ($v->value('deliver_by') !== null && $v->value('deliver_by') < date('Y-m-d')) $v->add('deliver_by', 'BUY_DATE_PAST');
        if ($v->value('reply_by') !== null && strtotime((string) $v->value('reply_by')) < time()) $v->add('reply_by', 'BUY_DATE_PAST');
        $lines = $v->hasRaw('lines') ? $this->lineInput($v, $body) : null;
        $suppliers = $v->hasRaw('suppliers') ? $this->supplierInput($v, $body, $id) : null;
        $v->throwIfInvalid();
        $this->tx->execute(function () use ($id, $round, $v, $lines, $suppliers, $context) {
            $data = array_intersect_key(array_filter(['safety_pct' => $v->value('safety_pct'), 'pay_terms' => $v->value('pay_terms'), 'delivery' => $v->value('delivery'),
                'deliver_by' => $v->value('deliver_by'), 'reply_by' => $v->value('reply_by') !== null ? date('Y-m-d H:i:s', strtotime((string) $v->value('reply_by'))) : null], fn($x) => $x !== null),
                array_flip(['safety_pct', 'pay_terms', 'delivery', 'deliver_by', 'reply_by']));
            if ($v->provided('ask_double')) $data['ask_double'] = $v->value('ask_double') ? 1 : 0;
            $this->repo->updateRound($id, $data);
            if ($lines !== null) $this->replaceLines($id, $round, $lines, $context);
            if ($suppliers !== null) $this->replaceRequests($id, $suppliers);
            $this->audit->record($context, 'update', 'buy_round', $id, null, $data + ['lines' => $lines !== null ? count($lines) : null, 'suppliers' => $suppliers !== null]);
        });
        return $this->detail($id, $context);
    }

    public function delete(int $id, ApiContext $context): array
    {
        $round = $this->draft($id);
        $this->tx->execute(function () use ($id, $round, $context) {
            $this->repo->deleteRound($id);
            $this->audit->record($context, 'delete', 'buy_round', $id, ['code' => $round['code']], null);
        });
        return ['deleted' => true, 'id' => $id];
    }

    /** @return array<int, float> surowiec → ilość (jednostka bazowa) */
    private function lineInput(Validator $v, array $body): array
    {
        $rows = $body['lines'] ?? null;
        if (!is_array($rows) || !$rows) { $v->add('lines', 'REQUIRED'); return []; }
        if (count($rows) > self::MAX_LINES) { $v->add('lines', 'TOO_LONG', ['max' => self::MAX_LINES]); return []; }
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $mid = is_array($r) ? ($r['material_id'] ?? null) : null;
            $qty = is_array($r) ? ($r['quantity'] ?? null) : null;
            if (!is_int($mid) || $mid <= 0) { $v->add("lines.$i.material_id", 'REQUIRED'); continue; }
            if ((!is_int($qty) && !is_float($qty)) || $qty <= 0 || $qty > self::MAX_QTY) { $v->add("lines.$i.quantity", 'OUT_OF_RANGE', ['min' => 0, 'max' => self::MAX_QTY]); continue; }
            $out[$mid] = (float) $qty;
        }
        return $out;
    }

    /** @return array<int, list<int>> pozycja → dostawcy (1 do 3, aktywni) */
    private function supplierInput(Validator $v, array $body, int $roundId): array
    {
        $raw = $body['suppliers'] ?? null;
        if (!is_array($raw)) { $v->add('suppliers', 'REQUIRED'); return []; }
        $lineIds = array_map(fn($l) => (int) $l['id'], $this->repo->lines($roundId));
        $known = array_column($this->repo->suppliers(), null, 'id');
        $out = [];
        foreach ($raw as $lineId => $ids) {
            $lineId = (int) $lineId;
            if (!in_array($lineId, $lineIds, true)) { $v->add("suppliers.$lineId", 'REFERENCE_NOT_FOUND', ['id' => $lineId]); continue; }
            $ids = is_array($ids) ? array_values(array_unique(array_map('intval', array_filter($ids, 'is_int')))) : [];
            if (count($ids) < 1 || count($ids) > self::MAX_SUPPLIERS) { $v->add("suppliers.$lineId", 'BUY_LINE_SUPPLIERS', ['min' => 1, 'max' => self::MAX_SUPPLIERS]); continue; }
            foreach ($ids as $sid) if (!isset($known[$sid])) $v->add("suppliers.$lineId", 'REFERENCE_NOT_FOUND', ['id' => $sid]);
            $out[$lineId] = $ids;
        }
        return $out;
    }

    private function replaceLines(int $id, array $round, array $lines, ApiContext $context): void
    {
        $existing = array_column($this->repo->lines($id), null, 'id_raw_material');
        $needs = $this->supply->needs($round['week_start'], 1, $context);
        $byId = array_column($needs['rows'], null, 'id');
        $new = array_diff(array_keys($lines), array_map('intval', array_keys($existing)));
        $last = $this->lastPrices($new);
        $materials = $this->materialsById($new);
        $keep = [];
        foreach ($lines as $mid => $qty) {
            if (isset($existing[$mid])) {
                $this->repo->updateLine((int) $existing[$mid]['id'], ['quantity_base' => $qty]);
                $keep[] = (int) $existing[$mid]['id'];
            } else {
                if (!isset($materials[$mid])) throw ApiException::notFound('raw_material', $mid);
                $keep[] = $this->repo->insertLine($this->lineRow($id, $mid, $qty, $byId[$mid] ?? null, $materials[$mid], $last[$mid] ?? null));
            }
        }
        $this->repo->deleteLines($id, $keep);
    }

    private function lineRow(int $roundId, int $mid, float $qty, ?array $need, array $m, ?array $last): array
    {
        $pack = (float) ($m['package_size'] ?? 0);
        return ['id_round' => $roundId, 'id_raw_material' => $mid, 'need_base' => round((float) ($need['need'] ?? 0), 3),
            'stock_base' => $need['stock'] ?? null, 'on_order_base' => round((float) ($need['on_order'] ?? 0), 3), 'quantity_base' => round($qty, 3),
            'package_size_base' => $pack > 0 ? $pack : null, 'last_price' => $last['price'] ?? null, 'last_supplier_id' => $last['supplier_id'] ?? null, 'last_day' => $last['day'] ?? null];
    }

    /** Jedno zapytanie na dostawcę z jego pozycjami ; istniejące zapytania zachowują link (token). */
    private function replaceRequests(int $roundId, array $map): void
    {
        $bySupplier = [];
        foreach ($map as $lineId => $ids) foreach ($ids as $sid) $bySupplier[$sid][] = $lineId;
        $existing = array_column($this->repo->requests($roundId), null, 'id_supplier');
        $suppliers = $this->repo->suppliersById(array_keys($bySupplier));
        foreach ($existing as $sid => $q) if (!isset($bySupplier[(int) $sid])) $this->repo->deleteRequest((int) $q['id']);
        foreach ($bySupplier as $sid => $lineIds) {
            $s = $suppliers[$sid];
            $email = trim((string) $s['email']);
            $lang = in_array($s['order_lang'] ?? null, self::LANGS, true) ? $s['order_lang'] : 'pl';
            $requestId = isset($existing[$sid]) ? (int) $existing[$sid]['id'] : $this->repo->insertRequest([
                'id_round' => $roundId, 'id_supplier' => $sid, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, 'lang' => $lang, 'token' => bin2hex(random_bytes(24))]);
            if (isset($existing[$sid])) $this->repo->updateRequest($requestId, ['email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, 'lang' => $lang]);
            $this->repo->setRequestLines($requestId, $lineIds);
        }
    }

    // --- Krok 3 : podgląd i wysyłka --------------------------------------------------------------------------------

    public function preview(int $id, int $supplierId, ApiContext $context): array
    {
        $round = $this->load($id);
        $request = null;
        foreach ($this->repo->requests($id) as $q) if ((int) $q['id_supplier'] === $supplierId) $request = $q;
        if ($request === null) throw ApiException::notFound('supplier', $supplierId);
        [$template, $subject, $body] = $this->mail($round, $request);
        return ['supplier' => ['id' => $supplierId, 'name' => $request['supplier_name']], 'recipient' => $request['email'], 'language' => $request['lang'],
            'subject' => $subject, 'body' => $body, 'link' => $this->link($request['token']), 'template_id' => isset($template['id']) ? (int) $template['id'] : null];
    }

    /** Wysyłka : jeden e-mail na dostawcę ; dostawcy wysłanych zapytań zostają dostawcami pozycji (do 3). */
    public function send(int $id, ApiContext $context): array
    {
        $round = $this->draft($id);
        $lines = $this->repo->lines($id);
        $requests = $this->repo->requests($id);
        $map = $this->repo->requestLines($id);
        if (!$lines) throw new ApiException('BUY_NO_LINES', 422);
        $asked = array_unique(array_merge([], ...array_values($map)));
        $orphans = array_filter($lines, fn($l) => !in_array((int) $l['id'], $asked, true));
        if (!$requests || $orphans) {
            $names = $this->materialsById(array_map(fn($l) => (int) $l['id_raw_material'], $orphans ?: $lines));
            throw new ApiException('BUY_LINE_NO_SUPPLIER', 422, [], ['materials' => implode(', ', array_map(fn($m) => $m['name'], array_slice($names, 0, 5)))]);
        }
        $noEmail = array_filter($requests, fn($q) => !filter_var((string) $q['email'], FILTER_VALIDATE_EMAIL));
        if ($noEmail) throw new ApiException('BUY_SUPPLIER_NO_EMAIL', 422, [], ['suppliers' => implode(', ', array_column($noEmail, 'supplier_name'))]);
        $materialOf = array_column($lines, 'id_raw_material', 'id');
        $this->tx->execute(function () use ($id, $round, $requests, $map, $materialOf, $context) {
            $now = date('Y-m-d H:i:s');
            foreach ($requests as $q) {
                [$template, $subject, $body, $variables] = $this->mail($round, $q);
                $messageId = $this->outgoing->queue(['message_type' => 'buy_request', 'template' => $template, 'recipient' => $q['email'], 'subject' => $subject,
                    'body' => $body, 'variables' => $variables, 'created_by' => 'buy:' . $round['code']]);
                $this->repo->updateRequest((int) $q['id'], ['id_message' => $messageId, 'sent_at' => $now]);
                foreach ($map[(int) $q['id']] ?? [] as $lineId) {
                    $this->repo->addMaterialSupplier((int) $materialOf[$lineId], (int) $q['id_supplier'], 'round', $context->userId, self::MAX_SUPPLIERS);
                }
            }
            $this->repo->updateRound($id, ['status' => 'sent', 'sent_at' => $now]);
            $this->audit->record($context, 'send', 'buy_round', $id, ['status' => 'draft'], ['status' => 'sent', 'requests' => count($requests)]);
        });
        return $this->detail($id, $context);
    }

    /** Przypomnienie dostawcom bez odpowiedzi (ten sam link). */
    public function remind(int $id, ApiContext $context): array
    {
        $round = $this->load($id);
        if (!in_array($round['status'], ['sent', 'ordered'], true)) throw new ApiException('BUY_ROUND_NOT_SENT', 409, [], ['status' => $round['status']]);
        $waiting = array_filter($this->repo->requests($id), fn($q) => $q['responded_at'] === null && $q['sent_at'] !== null && filter_var((string) $q['email'], FILTER_VALIDATE_EMAIL));
        $this->tx->execute(function () use ($round, $waiting) {
            foreach ($waiting as $q) {
                [$template, $subject, $body, $variables] = $this->mail($round, $q);
                $this->outgoing->queue(['message_type' => 'buy_request', 'template' => $template, 'recipient' => $q['email'],
                    'subject' => mb_substr(self::TEXT['reminder'][$q['lang']] ?? self::TEXT['reminder']['pl'], 0, 30) . $subject,
                    'body' => $body, 'variables' => $variables, 'created_by' => 'buy:' . $round['code']]);
                $this->repo->updateRequest((int) $q['id'], ['reminded_at' => date('Y-m-d H:i:s'), 'reminders' => (int) $q['reminders'] + 1]);
            }
        });
        return ['reminded' => count($waiting)] + $this->detail($id, $context);
    }

    /** @return array{0: array, 1: string, 2: string, 3: array} szablon, temat, treść, zmienne */
    private function mail(array $round, array $q): array
    {
        $lang = in_array($q['lang'], self::LANGS, true) ? $q['lang'] : 'pl';
        $template = $this->templates->getDefault('buy_request', $lang) ?: $this->templates->getDefault('buy_request', 'pl');
        if (!$template) throw new ApiException('BUY_NO_TEMPLATE', 422);
        $lineIds = $this->repo->requestLines((int) $round['id'])[(int) $q['id']] ?? [];
        $lines = array_values(array_filter($this->repo->lines((int) $round['id']), fn($l) => in_array((int) $l['id'], $lineIds, true)));
        $materials = $this->materialsById(array_map(fn($l) => (int) $l['id_raw_material'], $lines));
        $items = [];
        foreach ($lines as $l) {
            $m = $materials[(int) $l['id_raw_material']] ?? null;
            if ($m === null) continue;
            $unit = CostOptimizationService::displayUnit($m['unit']);
            $qty = (float) $l['quantity_base'];
            $pack = (float) ($l['package_size_base'] ?? 0);
            $name = $lang === 'pl' ? $m['name'] : ($m['name_fr'] ?: $m['name']);
            $text = '- ' . $name . ' — ' . $this->number($qty / $unit['factor']) . ' ' . $unit['label'];
            if ($pack > 0 && abs($pack - $unit['factor']) > 1e-9) $text .= ' (' . $this->number($qty / $pack) . ' × ' . $this->number($pack / $unit['factor']) . ' ' . $unit['label'] . ')';
            $items[] = $text;
        }
        $conditions = [self::TEXT['pay'][$lang][$round['pay_terms']], self::TEXT['delivery'][$lang][$round['delivery']]];
        if ($round['deliver_by']) $conditions[] = sprintf(self::TEXT['by'][$lang], date('d.m.Y', strtotime($round['deliver_by'])));
        $variables = [
            'request' => ['code' => $round['code'], 'items' => implode("\n", $items), 'conditions' => implode(' · ', $conditions),
                'deadline' => $round['reply_by'] ? date('d.m.Y H:i', strtotime($round['reply_by'])) : '', 'double' => $round['ask_double'] ? self::TEXT['double'][$lang] : '',
                'link' => $this->link($q['token'])],
            'user' => ['name' => (string) ($round['user_name'] ?? '')],
            'company' => ['name' => $this->company->companyName()],
        ];
        return [$template, $this->renderer->render($template['subject_template'], $variables), $this->renderer->render($template['body_template'], $variables), $variables];
    }

    private function link(string $token): string
    {
        return (defined('ROOT') ? rtrim((string) ROOT, '/') : '') . '/offer/' . $token;
    }

    // --- Oferty ----------------------------------------------------------------------------------------------------

    /** Oferta wpisana przez operatora (odpowiedź e-mailem, telefon). */
    public function addOffer(int $id, array $body, ApiContext $context): array
    {
        $round = $this->load($id);
        if (!in_array($round['status'], ['sent', 'ordered'], true)) throw new ApiException('BUY_ROUND_NOT_SENT', 409, [], ['status' => $round['status']]);
        $v = (new Validator($body))->only(['line_id', 'supplier_id', 'price', 'price_double', 'min_qty', 'min_value', 'min_note', 'delivery_date', 'note'])
            ->integer('line_id', true, 1)->integer('supplier_id', true, 1);
        $this->offerFields($v);
        $line = null;
        foreach ($this->repo->lines($id) as $l) if ((int) $l['id'] === (int) $v->value('line_id')) $line = $l;
        if ($v->value('line_id') && $line === null) $v->add('line_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('line_id')]);
        if ($v->value('supplier_id') && !$this->repo->suppliersById([(int) $v->value('supplier_id')])) $v->add('supplier_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('supplier_id')]);
        if ($line !== null && $this->orderOf($id, (int) $line['id']) !== null) $v->add('line_id', 'BUY_LINE_ORDERED');
        $v->throwIfInvalid();
        $this->tx->execute(function () use ($id, $line, $v, $context) {
            $this->saveOffer($line, (int) $v->value('supplier_id'), $v->clean(), $context->userId);
            foreach ($this->repo->requests($id) as $q) {
                if ((int) $q['id_supplier'] === (int) $v->value('supplier_id') && $q['responded_at'] === null) $this->repo->updateRequest((int) $q['id'], ['responded_at' => date('Y-m-d H:i:s')]);
            }
        });
        return $this->detail($id, $context);
    }

    /** Pola oferty (w jednostce wyświetlanej surowca : zł/kg, kg…). */
    private function offerFields(Validator $v): void
    {
        $v->decimal('price', true, 0, 1_000_000, false, true)->decimal('price_double', false, 0, 1_000_000, true, true)
            ->decimal('min_qty', false, 0, self::MAX_QTY, true)->decimal('min_value', false, 0, 100_000_000, true)
            ->string('min_note', 60, false, true)->date('delivery_date', false, true)->string('note', 500, false, true);
        if ($v->value('delivery_date') !== null && $v->value('delivery_date') < date('Y-m-d', strtotime('-1 day'))) $v->add('delivery_date', 'BUY_DATE_PAST');
    }

    private function saveOffer(array $line, int $supplierId, array $f, ?int $userId): int
    {
        $m = $this->materialsById([(int) $line['id_raw_material']])[(int) $line['id_raw_material']];
        $factor = (float) CostOptimizationService::displayUnit($m['unit'])['factor'];
        $price = (float) $f['price'];
        return $this->repo->saveOffer([
            'id_supplier' => $supplierId, 'id_raw_material' => (int) $line['id_raw_material'], 'pack_price' => $price, 'pack_size_base' => $factor,
            'price_per_base' => $price / $factor, 'price_double_base' => isset($f['price_double']) && $f['price_double'] !== null ? (float) $f['price_double'] / $factor : null,
            'currency' => 'PLN', 'valid_from' => date('Y-m-d'), 'valid_to' => date('Y-m-d', strtotime('+' . max(1, (int) $this->params->get('purchasing.offer_validity_days', '90')) . ' days')),
            'min_order_qty' => $f['min_qty'] ?? null, 'min_value' => $f['min_value'] ?? null, 'min_note' => $f['min_note'] ?? null,
            'delivery_date' => $f['delivery_date'] ?? null, 'source' => 'weekly', 'id_buy_line' => (int) $line['id'], 'status' => 'received',
            'notes' => $f['note'] ?? null, 'created_by' => $userId,
        ]);
    }

    // --- Link dla dostawcy (bez logowania) -------------------------------------------------------------------------

    /** Strona dostawcy : jego pozycje, warunki, ceny już wpisane ; null = link nieznany ; expired = po terminie. */
    public function publicView(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $q = $this->repo->requestByToken($token);
        if ($q === null) return null;
        $round = $this->repo->round((int) $q['id_round']);
        if ($round === null || $round['status'] === 'draft') return null;
        $days = max(1, (int) $this->params->get('buy.link_days', '7'));
        $expired = $round['status'] === 'closed' || ($round['reply_by'] !== null && time() > strtotime($round['reply_by'] . " +{$days} days"));
        if ($q['opened_at'] === null) $this->repo->updateRequest((int) $q['id'], ['opened_at' => date('Y-m-d H:i:s')]);
        $lang = in_array($q['lang'], self::LANGS, true) ? $q['lang'] : 'pl';
        $lineIds = $this->repo->requestLines((int) $round['id'])[(int) $q['id']] ?? [];
        $lines = array_values(array_filter($this->repo->lines((int) $round['id']), fn($l) => in_array((int) $l['id'], $lineIds, true)));
        $materials = $this->materialsById(array_map(fn($l) => (int) $l['id_raw_material'], $lines));
        $offers = [];
        foreach ($this->repo->offers((int) $round['id']) as $o) if ((int) $o['id_supplier'] === (int) $q['id_supplier']) $offers[(int) $o['id_buy_line']] = $o;
        $ordered = array_column($this->repo->orders((int) $round['id']), null, 'id_line');
        $conditions = [self::TEXT['pay'][$lang][$round['pay_terms']], self::TEXT['delivery'][$lang][$round['delivery']]];
        if ($round['deliver_by']) $conditions[] = sprintf(self::TEXT['by'][$lang], date('d.m.Y', strtotime($round['deliver_by'])));
        return [
            'lang' => $lang, 'expired' => $expired, 'company' => $this->company->companyName(), 'supplier' => $q['supplier_name'], 'code' => $round['code'],
            'deadline' => $round['reply_by'], 'conditions' => implode(' · ', $conditions), 'ask_double' => (bool) $round['ask_double'], 'responded_at' => $q['responded_at'],
            'lines' => array_map(function ($l) use ($materials, $offers, $lang, $ordered) {
                $m = $materials[(int) $l['id_raw_material']];
                $unit = CostOptimizationService::displayUnit($m['unit']);
                $o = $offers[(int) $l['id']] ?? null;
                $pack = (float) ($l['package_size_base'] ?? 0);
                return ['id' => (int) $l['id'], 'name' => $lang === 'pl' ? $m['name'] : ($m['name_fr'] ?: $m['name']), 'unit' => $unit['label'],
                    'quantity' => round((float) $l['quantity_base'] / $unit['factor'], 3),
                    'package' => $pack > 0 && abs($pack - $unit['factor']) > 1e-9 ? round($pack / $unit['factor'], 3) : null,
                    'closed' => isset($ordered[(int) $l['id']]),
                    'offer' => $o ? ['price' => (float) $o['pack_price'], 'price_double' => $o['price_double_base'] !== null ? round((float) $o['price_double_base'] * $unit['factor'], 4) : null,
                        'min_qty' => $o['min_order_qty'] !== null ? (float) $o['min_order_qty'] : null, 'min_value' => $o['min_value'] !== null ? (float) $o['min_value'] : null,
                        'min_note' => $o['min_note'], 'delivery_date' => $o['delivery_date'], 'note' => $o['notes']] : null];
            }, $lines),
        ];
    }

    /**
     * Ceny wpisane przez dostawcę : offers = {pozycja → {price, price_double, min_qty, min_value, min_note, delivery_date, note}} ;
     * pusta cena = bez oferty na tę pozycję. @return array{saved:int, errors:array<int, string>}
     */
    public function publicSubmit(string $token, array $offers): array
    {
        $view = $this->publicView($token);
        if ($view === null) throw new ApiException('BUY_LINK_UNKNOWN', 404);
        if ($view['expired']) throw new ApiException('BUY_LINK_EXPIRED', 410);
        $q = $this->repo->requestByToken($token);
        $lines = array_column($this->repo->lines((int) $q['id_round']), null, 'id');
        $open = array_column(array_filter($view['lines'], fn($l) => !$l['closed']), 'id');
        $clean = [];
        $errors = [];
        foreach ($offers as $lineId => $row) {
            $lineId = (int) $lineId;
            if (!in_array($lineId, $open, true) || !is_array($row)) continue;
            $row = array_map(fn($x) => is_string($x) ? trim(str_replace([' ', ','], ['', '.'], $x)) : $x, array_intersect_key($row, array_flip(['price', 'price_double', 'min_qty', 'min_value', 'min_note', 'delivery_date', 'note'])));
            foreach (['min_note', 'note'] as $k) if (isset($offers[$lineId][$k])) $row[$k] = trim((string) $offers[$lineId][$k]);
            if (($row['price'] ?? '') === '') continue;
            $input = [];
            foreach (['price', 'price_double', 'min_qty', 'min_value'] as $k) if (($row[$k] ?? '') !== '') $input[$k] = is_numeric($row[$k]) ? (float) $row[$k] : $row[$k];
            foreach (['min_note', 'delivery_date', 'note'] as $k) if (($row[$k] ?? '') !== '') $input[$k] = (string) $row[$k];
            $v = (new Validator($input))->only(['price', 'price_double', 'min_qty', 'min_value', 'min_note', 'delivery_date', 'note']);
            $this->offerFields($v);
            if ($v->errors()) { $errors[$lineId] = 'invalid'; continue; }
            $clean[$lineId] = $v->clean();
        }
        if ($clean) {
            $this->tx->execute(function () use ($clean, $lines, $q) {
                foreach ($clean as $lineId => $f) $this->saveOffer($lines[$lineId], (int) $q['id_supplier'], $f, null);
                $this->repo->updateRequest((int) $q['id'], ['responded_at' => date('Y-m-d H:i:s')]);
            });
        }
        return ['saved' => count($clean), 'errors' => $errors];
    }

    // --- Zamówienie u wybranych ------------------------------------------------------------------------------------

    /**
     * lines = [{line_id, offer_id | null (ostatni dostawca po ostatniej cenie), quantity}] → zamówienie u każdego dostawcy (n_po,
     * warunki w uwagach) wysłane e-mailem, decyzje z oszczędnością. Ilość poniżej minimum oferty : BUY_BELOW_MINIMUM.
     */
    public function order(int $id, array $body, ApiContext $context): array
    {
        $round = $this->load($id);
        if (!in_array($round['status'], ['sent', 'ordered'], true)) throw new ApiException('BUY_ROUND_NOT_SENT', 409, [], ['status' => $round['status']]);
        $rows = $body['lines'] ?? null;
        $v = new Validator(is_array($body) ? $body : []);
        if (!is_array($rows) || !$rows) $v->add('lines', 'REQUIRED');
        $v->throwIfInvalid();
        $lines = array_column($this->repo->lines($id), null, 'id');
        $offers = array_column($this->repo->offers($id), null, 'id');
        $materials = $this->materialsById(array_map(fn($l) => (int) $l['id_raw_material'], $lines));
        $plan = [];
        foreach (array_values($rows) as $i => $r) {
            $lineId = is_array($r) ? (int) ($r['line_id'] ?? 0) : 0;
            $offerId = is_array($r) && isset($r['offer_id']) ? (int) $r['offer_id'] : null;
            $qty = is_array($r) ? ($r['quantity'] ?? null) : null;
            $line = $lines[$lineId] ?? null;
            if ($line === null) { $v->add("lines.$i.line_id", 'REFERENCE_NOT_FOUND', ['id' => $lineId]); continue; }
            if ($this->orderOf($id, $lineId) !== null) { $v->add("lines.$i.line_id", 'BUY_LINE_ORDERED'); continue; }
            if ((!is_int($qty) && !is_float($qty)) || $qty <= 0 || $qty > self::MAX_QTY) { $v->add("lines.$i.quantity", 'OUT_OF_RANGE', ['min' => 0, 'max' => self::MAX_QTY]); continue; }
            $m = $materials[(int) $line['id_raw_material']];
            if ($offerId !== null) {
                $o = $offers[$offerId] ?? null;
                if ($o === null || (int) $o['id_buy_line'] !== $lineId) { $v->add("lines.$i.offer_id", 'REFERENCE_NOT_FOUND', ['id' => $offerId]); continue; }
                $min = $this->belowMinimum($o, (float) $qty, $m);
                if ($min !== null) throw new ApiException('BUY_BELOW_MINIMUM', 409, [], ['material' => $m['name'], 'minimum' => $min]);
                $plan[] = ['line' => $line, 'offer' => $o, 'supplier' => (int) $o['id_supplier'], 'price' => (float) $o['price_per_base'], 'qty' => (float) $qty, 'delivery' => $o['delivery_date']];
            } else {
                $sid = $line['last_supplier_id'] !== null ? (int) $line['last_supplier_id'] : ($m['supplier_id'] !== null ? (int) $m['supplier_id'] : null);
                $price = $line['last_price'] !== null ? (float) $line['last_price'] : ((float) $m['price_per_unit'] > 0 ? (float) $m['price_per_unit'] : null);
                if ($sid === null || $price === null) { $v->add("lines.$i.offer_id", 'BUY_NO_PRICE', ['material' => $m['name']]); continue; }
                $plan[] = ['line' => $line, 'offer' => null, 'supplier' => $sid, 'price' => $price, 'qty' => (float) $qty, 'delivery' => null];
            }
        }
        $v->throwIfInvalid();
        $bySupplier = [];
        foreach ($plan as $p) $bySupplier[$p['supplier']][] = $p;
        $suppliers = $this->repo->suppliersById(array_keys($bySupplier));
        $created = $this->tx->execute(function () use ($id, $round, $bySupplier, $suppliers, $context) {
            $created = [];
            foreach ($bySupplier as $sid => $items) {
                $s = $suppliers[$sid];
                $email = trim((string) $s['email']);
                $lang = ($s['order_lang'] ?? 'pl') === 'fr' ? 'fr' : 'pl';
                $delivery = array_filter(array_column($items, 'delivery'));
                $poId = $this->supplyRepo->insert(['code' => $this->supplyRepo->nextCode(date('Y-m-d')), 'id_supplier' => $sid, 'status' => 'draft', 'week_start' => $round['week_start'],
                    'delivery_date' => $delivery ? min($delivery) : $round['deliver_by'], 'language' => $lang, 'email_to' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'note' => $this->conditions($round, $lang) . ' · ' . $round['code'], 'created_by' => $context->userId]);
                foreach ($items as $p) {
                    $this->supplyRepo->insertLine($poId, (int) $p['line']['id_raw_material'], $p['qty'], $p['line']['package_size_base'] !== null ? (float) $p['line']['package_size_base'] : null, $p['price']);
                    $last = $p['line']['last_price'] !== null ? (float) $p['line']['last_price'] : null;
                    $this->repo->insertOrder(['id_round' => $id, 'id_line' => (int) $p['line']['id'], 'id_offer' => $p['offer'] ? (int) $p['offer']['id'] : null, 'id_supplier' => $sid,
                        'quantity_base' => $p['qty'], 'price' => $p['price'], 'last_price' => $last, 'saving' => $last !== null ? round(($last - $p['price']) * $p['qty'], 2) : 0,
                        'id_po' => $poId, 'id_user' => (int) $context->userId]);
                    $this->repo->setOfferStatuses((int) $p['line']['id'], $p['offer'] ? (int) $p['offer']['id'] : null);
                }
                $created[] = ['id' => $poId, 'supplier' => $s['name'], 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null];
            }
            $allOrdered = count($this->repo->orders($id)) >= count($this->repo->lines($id));
            $this->repo->updateRound($id, ['status' => $allOrdered ? 'closed' : 'ordered', 'ordered_at' => $round['ordered_at'] ?? date('Y-m-d H:i:s')] + ($allOrdered ? ['closed_at' => date('Y-m-d H:i:s')] : []));
            $this->audit->record($context, 'order', 'buy_round', $id, ['status' => $round['status']], ['orders' => count($created)]);
            return $created;
        });
        // Zamówienia e-mailem (szablon supplier_order) ; bez adresu dostawcy zostają w szkicu (Zamówienia).
        $orders = [];
        foreach ($created as $c) {
            $sent = false;
            if ($c['email'] !== null) {
                try { $this->supply->send($c['id'], [], $context); $sent = true; } catch (ApiException) { $sent = false; }
            }
            $po = $this->supplyRepo->find($c['id']);
            $orders[] = ['id' => $c['id'], 'code' => $po['code'] ?? null, 'supplier' => $c['supplier'], 'sent' => $sent];
        }
        return ['orders' => $orders] + $this->detail($id, $context);
    }

    public function close(int $id, ApiContext $context): array
    {
        $round = $this->load($id);
        if (!in_array($round['status'], ['sent', 'ordered'], true)) throw new ApiException('BUY_ROUND_NOT_SENT', 409, [], ['status' => $round['status']]);
        $this->repo->updateRound($id, ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s')]);
        $this->audit->record($context, 'close', 'buy_round', $id, ['status' => $round['status']], ['status' => 'closed']);
        return $this->detail($id, $context);
    }

    /** Minimum oferty niespełnione przy tej ilości : opis minimum (np. « 1 000 kg », « 1 000 zł ») albo null. */
    private function belowMinimum(array $o, float $qtyBase, array $m): ?string
    {
        $unit = CostOptimizationService::displayUnit($m['unit']);
        if ($o['min_order_qty'] !== null && $qtyBase < (float) $o['min_order_qty'] * $unit['factor'] - 1e-6) return $this->number((float) $o['min_order_qty']) . ' ' . $unit['label'];
        if ($o['min_value'] !== null && $qtyBase * (float) $o['price_per_base'] < (float) $o['min_value'] - 0.005) return $this->number((float) $o['min_value']) . ' zł';
        return null;
    }

    private function conditions(array $round, string $lang): string
    {
        $parts = [self::TEXT['pay'][$lang][$round['pay_terms']], self::TEXT['delivery'][$lang][$round['delivery']]];
        if ($round['deliver_by']) $parts[] = sprintf(self::TEXT['by'][$lang], date('d.m.Y', strtotime($round['deliver_by'])));
        return implode(' · ', $parts);
    }

    // --- Widok zakupów ---------------------------------------------------------------------------------------------

    public function detail(int $id, ApiContext $context): array
    {
        return $this->present($this->load($id), $context);
    }

    private function present(array $round, ApiContext $context): array
    {
        $id = (int) $round['id'];
        $lines = $this->repo->lines($id);
        $mids = array_map(fn($l) => (int) $l['id_raw_material'], $lines);
        $materials = $this->materialsById($mids);
        $requests = $this->repo->requests($id);
        $map = $this->repo->requestLines($id);
        $offers = $this->repo->offers($id);
        $orders = array_column($this->repo->orders($id), null, 'id_line');
        $assigned = [];
        foreach ($this->repo->materialSuppliers($mids) as $s) $assigned[(int) $s['id_raw_material']][] = (int) $s['id_supplier'];
        $history = $this->prices->lastBySupplier($mids);
        $suggest = $this->categorySuggestions($mids, $assigned);
        $askedBy = [];
        foreach ($requests as $q) foreach ($map[(int) $q['id']] ?? [] as $lineId) $askedBy[$lineId][] = (int) $q['id_supplier'];
        $offersBy = [];
        foreach ($offers as $o) $offersBy[(int) $o['id_buy_line']][] = $o;
        $supplierIds = array_unique(array_merge(array_column($requests, 'id_supplier'), array_merge([], ...array_values($assigned)),
            array_merge([], ...array_map('array_keys', array_values($history))), array_merge([], ...array_values($suggest)), array_filter(array_column($lines, 'last_supplier_id'))));
        $suppliers = $this->repo->suppliersById(array_map('intval', $supplierIds));
        $sup = fn(int $sid) => isset($suppliers[$sid]) ? ['id' => $sid, 'name' => $suppliers[$sid]['name'], 'email' => filter_var(trim((string) $suppliers[$sid]['email']), FILTER_VALIDATE_EMAIL) ? trim((string) $suppliers[$sid]['email']) : null,
            'active' => (bool) $suppliers[$sid]['is_active']] : ['id' => $sid, 'name' => '#' . $sid, 'email' => null, 'active' => false];
        $outLines = [];
        $totals = ['lines' => 0, 'value' => 0.0, 'potential' => 0.0, 'savings' => 0.0, 'ordered' => 0, 'with_offers' => 0];
        foreach ($lines as $l) {
            $lid = (int) $l['id'];
            $mid = (int) $l['id_raw_material'];
            $m = $materials[$mid] ?? ['name' => '#' . $mid, 'name_fr' => null, 'unit' => 'szt', 'packaging' => 0, 'category' => null, 'supplier_id' => null, 'price_per_unit' => null];
            $qty = (float) $l['quantity_base'];
            $last = $l['last_price'] !== null ? (float) $l['last_price'] : null;
            $presented = [];
            foreach ($offersBy[$lid] ?? [] as $o) {
                $presented[] = ['id' => (int) $o['id'], 'supplier' => $sup((int) $o['id_supplier']), 'price' => (float) $o['price_per_base'],
                    'price_double' => $o['price_double_base'] !== null ? (float) $o['price_double_base'] : null,
                    'min_qty' => $o['min_order_qty'] !== null ? (float) $o['min_order_qty'] : null, 'min_value' => $o['min_value'] !== null ? (float) $o['min_value'] : null,
                    'min_note' => $o['min_note'], 'delivery_date' => $o['delivery_date'], 'note' => $o['notes'], 'status' => $o['status'], 'manual' => $o['created_by'] !== null,
                    'below_min' => $this->belowMinimum($o, $qty, $m) !== null, 'min_quantity' => $this->minimumQuantity($o, $m, $l)];
            }
            usort($presented, fn($a, $b) => $a['price'] <=> $b['price']);
            $valid = array_values(array_filter($presented, fn($o) => !$o['below_min']));
            $best = $valid[0] ?? null;
            $cheapest = $presented[0] ?? null;
            // Najtańsza oferta poniżej minimum : ile kupić, żeby spełnić minimum, i oszczędność przy tej ilości.
            $hint = null;
            if ($cheapest !== null && $cheapest['below_min'] && ($best === null || $cheapest['price'] < $best['price'])) {
                $q = $cheapest['min_quantity'];
                $hint = ['offer_id' => $cheapest['id'], 'quantity' => $q, 'extra' => $q !== null ? round($q - $qty, 3) : null,
                    'weeks' => $q !== null && (float) $l['need_base'] > 0 ? round($q / (float) $l['need_base'], 1) : null,
                    'saving' => $q !== null && $last !== null ? round(($last - $cheapest['price']) * $q, 2) : null];
            }
            $order = $orders[$lid] ?? null;
            $unit = CostOptimizationService::displayUnit($m['unit']);
            $asked = $askedBy[$lid] ?? [];
            $known = $assigned[$mid] ?? [];
            $outLines[] = [
                'id' => $lid, 'material' => ['id' => $mid, 'name' => $context->translator->lang() === 'fr' && $m['name_fr'] ? $m['name_fr'] : $m['name'], 'unit' => $unit['label'],
                    'factor' => $unit['factor'], 'base_unit' => $m['unit'] ?: 'szt', 'packaging' => (bool) $m['packaging'], 'category' => $m['category']],
                'need' => (float) $l['need_base'], 'stock' => $l['stock_base'] !== null ? (float) $l['stock_base'] : null, 'on_order' => (float) $l['on_order_base'],
                'quantity' => $qty, 'package_size' => $l['package_size_base'] !== null ? (float) $l['package_size_base'] : null,
                'packages' => $l['package_size_base'] !== null && (float) $l['package_size_base'] > 0 ? (int) round($qty / (float) $l['package_size_base']) : null,
                'last' => $last !== null ? ['price' => $last, 'supplier' => $l['last_supplier_id'] !== null ? $sup((int) $l['last_supplier_id']) : null, 'day' => $l['last_day']] : null,
                // Dostawcy : zapytani (po zapisie kroku 2), inaczej dostawcy pozycji (najwyżej 3) ; do wyboru : pozycja, historia, ta sama kategoria.
                'suppliers' => array_map($sup, $asked ?: array_slice($known, 0, self::MAX_SUPPLIERS)),
                'assigned' => count($known),
                'candidates' => array_map(fn($sid) => $sup($sid) + ['last_price' => $history[$mid][$sid]['price'] ?? null, 'last_day' => $history[$mid][$sid]['day'] ?? null,
                    'source' => in_array($sid, $known, true) ? 'assigned' : (isset($history[$mid][$sid]) ? 'history' : 'category')],
                    array_values(array_unique(array_merge($known, array_keys($history[$mid] ?? []), $suggest[$mid] ?? [])))),
                'offers' => $presented, 'best_offer_id' => $best['id'] ?? null, 'hint' => $hint,
                'saving' => $best !== null && $last !== null ? round(($last - $best['price']) * $qty, 2) : null,
                'order' => $order ? ['supplier' => $sup((int) $order['id_supplier']), 'quantity' => (float) $order['quantity_base'], 'price' => (float) $order['price'],
                    'saving' => (float) $order['saving'], 'po' => $order['id_po'] !== null ? ['id' => (int) $order['id_po'], 'code' => $order['po_code'], 'status' => $order['po_status']] : null,
                    'offer_id' => $order['id_offer'] !== null ? (int) $order['id_offer'] : null] : null,
            ];
            $totals['lines']++;
            if ($last !== null) $totals['value'] += $qty * $last;
            if ($presented) $totals['with_offers']++;
            if ($order) { $totals['ordered']++; $totals['savings'] += (float) $order['saving']; }
            elseif ($best !== null && $last !== null) $totals['potential'] += max(0.0, ($last - $best['price']) * $qty);
        }
        usort($outLines, fn($a, $b) => [$a['material']['packaging'], $a['material']['name']] <=> [$b['material']['packaging'], $b['material']['name']]);
        $presentedRequests = array_map(fn($q) => ['id' => (int) $q['id'], 'supplier' => $sup((int) $q['id_supplier']), 'email' => $q['email'], 'language' => $q['lang'],
            'lines' => count($map[(int) $q['id']] ?? []), 'sent_at' => $q['sent_at'], 'opened_at' => $q['opened_at'], 'responded_at' => $q['responded_at'],
            'reminded_at' => $q['reminded_at'], 'link' => $round['status'] !== 'draft' ? $this->link($q['token']) : null], $requests);
        return [
            'id' => $id, 'code' => $round['code'], 'status' => $round['status'], 'week_start' => $round['week_start'], 'week_end' => $round['week_end'],
            'safety_pct' => (int) $round['safety_pct'], 'pay_terms' => $round['pay_terms'], 'delivery' => $round['delivery'], 'deliver_by' => $round['deliver_by'],
            'reply_by' => $round['reply_by'], 'ask_double' => (bool) $round['ask_double'], 'user' => ['id' => (int) $round['id_user'], 'name' => $round['user_name'] ?? null],
            'created_at' => $round['created_at'], 'sent_at' => $round['sent_at'], 'ordered_at' => $round['ordered_at'], 'closed_at' => $round['closed_at'],
            'lines' => $outLines, 'requests' => $presentedRequests,
            'totals' => ['lines' => $totals['lines'], 'value' => round($totals['value'], 2), 'potential' => round($totals['potential'], 2), 'savings' => round($totals['savings'], 2),
                'ordered' => $totals['ordered'], 'with_offers' => $totals['with_offers'], 'requests' => count($requests),
                'responded' => count(array_filter($requests, fn($q) => $q['responded_at'] !== null))],
            'can_write' => $context->can('prod.supply.write'),
        ];
    }

    /** Ilość potrzebna do minimum oferty (w górę do pełnych opakowań) albo null (minimum opisem). */
    private function minimumQuantity(array $o, array $m, array $l): ?float
    {
        $unit = CostOptimizationService::displayUnit($m['unit']);
        $q = 0.0;
        if ($o['min_order_qty'] !== null) $q = max($q, (float) $o['min_order_qty'] * $unit['factor']);
        if ($o['min_value'] !== null && (float) $o['price_per_base'] > 0) $q = max($q, (float) $o['min_value'] / (float) $o['price_per_base']);
        if ($q <= 0) return null;
        $pack = (float) ($l['package_size_base'] ?? 0);
        if ($pack > 0) $q = ceil(round($q / $pack, 6)) * $pack;
        return round(max($q, (float) $l['quantity_base']), 3);
    }

    /** Nowy dostawca do zapytania : sprzedaje inne pozycje tej samej kategorii (najczęściej), gdy pozycja ma mniej niż 3. */
    private function categorySuggestions(array $mids, array $assigned): array
    {
        $cats = $this->repo->materialCategories($mids);
        $byCat = [];
        foreach ($this->repo->categorySuppliers() as $r) $byCat[(int) $r['id_category']][(int) $r['id_supplier']] = (int) $r['n'];
        $out = [];
        foreach ($mids as $mid) {
            if (count($assigned[$mid] ?? []) >= self::MAX_SUPPLIERS) continue;
            $pool = $byCat[$cats[$mid] ?? 0] ?? [];
            arsort($pool);
            $out[$mid] = array_slice(array_values(array_diff(array_keys($pool), $assigned[$mid] ?? [])), 0, 2);
        }
        return $out;
    }

    // --- Dostawcy pozycji (1 do 3) ---------------------------------------------------------------------------------

    /**
     * Pozycje zużyte w ostatnich tygodniach (buy.supplier_weeks) z ich dostawcami ; only_missing : mniej niż 3 dostawców
     * (zadanie « znajdź dostawców »). Także wszyscy aktywni dostawcy do wyboru.
     */
    public function materialSuppliers(bool $onlyMissing, ApiContext $context): array
    {
        return $this->supplierRows($onlyMissing, $context->translator->lang()) + ['max' => self::MAX_SUPPLIERS,
            'suppliers' => array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'email' => $this->email($s['email'])], $this->repo->suppliers()),
            'can_write' => $context->can('prod.supply.write')];
    }

    /** @return array{rows: list<array>, weeks: int, missing: int} */
    private function supplierRows(bool $onlyMissing, string $lang): array
    {
        $weeks = max(1, (int) $this->params->get('buy.supplier_weeks', '8'));
        $to = date('Y-m-d', strtotime('yesterday'));
        $use = $this->supplyRepo->consumed(date('Y-m-d', strtotime($to . ' -' . (7 * $weeks - 1) . ' days')), $to);
        $mids = array_keys(array_filter($use, fn($q) => $q > 0));
        $materials = $this->materialsById($mids);
        $assigned = [];
        foreach ($this->repo->materialSuppliers($mids) as $s) $assigned[(int) $s['id_raw_material']][] = ['id' => (int) $s['id_supplier'], 'name' => $s['name'],
            'email' => $this->email($s['email']), 'active' => (bool) $s['is_active']];
        $rows = [];
        foreach ($mids as $mid) {
            if (!isset($materials[$mid])) continue;
            $m = $materials[$mid];
            $list = $assigned[$mid] ?? [];
            if ($onlyMissing && count($list) >= self::MAX_SUPPLIERS) continue;
            $unit = CostOptimizationService::displayUnit($m['unit']);
            $rows[] = ['id' => $mid, 'name' => $lang === 'fr' && $m['name_fr'] ? $m['name_fr'] : $m['name'], 'category' => $m['category'], 'packaging' => (bool) $m['packaging'],
                'unit' => $unit['label'], 'factor' => $unit['factor'], 'weekly_use' => round($use[$mid] / $weeks, 3), 'suppliers' => $list];
        }
        usort($rows, fn($a, $b) => [count($a['suppliers']), -$a['weekly_use']] <=> [count($b['suppliers']), -$b['weekly_use']]);
        return ['rows' => $rows, 'weeks' => $weeks, 'missing' => count(array_filter($rows, fn($r) => count($r['suppliers']) < self::MAX_SUPPLIERS))];
    }

    /**
     * Do obsłużenia i Dzisiaj (stanowisko Zakupy) : zakupy otwarte z postępem (szkic niewysłany, oferty do decyzji) i pozycje
     * zużywane z mniej niż 3 dostawcami (liczba i pierwsze nazwy).
     */
    public function queue(): array
    {
        $rows = $this->supplierRows(true, 'pl')['rows'];
        return ['rounds' => array_map(fn($r) => $r + $this->repo->progress((int) $r['id']), $this->repo->openRounds()),
            'missing' => count($rows), 'examples' => array_column(array_slice($rows, 0, 3), 'name')];
    }

    public function saveMaterialSuppliers(int $materialId, array $body, ApiContext $context): array
    {
        if (!$this->materialsById([$materialId])) throw ApiException::notFound('raw_material', $materialId);
        $v = (new Validator($body))->only(['supplier_ids']);
        $ids = $body['supplier_ids'] ?? null;
        if (!is_array($ids) || array_filter($ids, fn($x) => !is_int($x))) $v->add('supplier_ids', 'REQUIRED');
        else {
            $ids = array_values(array_unique($ids));
            if (count($ids) < 1 || count($ids) > self::MAX_SUPPLIERS) $v->add('supplier_ids', 'BUY_LINE_SUPPLIERS', ['min' => 1, 'max' => self::MAX_SUPPLIERS]);
            $known = array_column($this->repo->suppliers(), null, 'id');
            foreach ($ids as $sid) if (!isset($known[$sid])) $v->add('supplier_ids', 'REFERENCE_NOT_FOUND', ['id' => $sid]);
        }
        $v->throwIfInvalid();
        $before = array_map(fn($s) => (int) $s['id_supplier'], $this->repo->materialSuppliers([$materialId]));
        $this->tx->execute(function () use ($materialId, $ids, $before, $context) {
            $this->repo->replaceMaterialSuppliers($materialId, $ids, 'manual', $context->userId);
            $this->audit->record($context, 'update', 'material_suppliers', $materialId, ['suppliers' => $before], ['suppliers' => $ids]);
        });
        $list = array_map(fn($s) => ['id' => (int) $s['id_supplier'], 'name' => $s['name'], 'email' => filter_var(trim((string) $s['email']), FILTER_VALIDATE_EMAIL) ? trim((string) $s['email']) : null,
            'active' => (bool) $s['is_active']], $this->repo->materialSuppliers([$materialId]));
        return ['id' => $materialId, 'suppliers' => $list];
    }

    // --- Wyniki ----------------------------------------------------------------------------------------------------

    /**
     * Wyniki : moje (operator) i zespołu (dyrektor zakładu : uprawnienie prod.finance.read) w okresie — zakupy, e-maile,
     * oferty, decyzje, udane (taniej niż ostatnio), oszczędność, marża (oszczędność ÷ sprzedaż), czas w kreatorze, zwrot.
     */
    public function results(string $period, ApiContext $context): array
    {
        if (!in_array($period, self::PERIODS, true)) throw ApiException::validation([['field' => 'period', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => implode(', ', self::PERIODS)]]]);
        $month = (int) date('n');
        $from = match ($period) {
            'month' => date('Y-m-01'),
            'quarter' => date('Y') . '-' . sprintf('%02d', 3 * intdiv($month - 1, 3) + 1) . '-01',
            'year' => date('Y-01-01'),
        };
        $to = date('Y-m-d');
        $stats = $this->repo->stats($from . ' 00:00:00', $to . ' 23:59:59');
        $revenue = $this->repo->revenuePln($from, $to);
        $team = $context->can('prod.finance.read');
        $ids = array_keys($stats);
        if (!in_array((int) $context->userId, $ids, true)) $ids[] = (int) $context->userId;
        $users = $this->repo->users($ids);
        $row = function (int $uid) use ($stats, $users, $revenue) {
            $s = $stats[$uid] ?? [];
            $hours = round(($s['active_s'] ?? 0) / 3600, 2);
            $rate = $users[$uid]['hourly_cost'] ?? null;
            $cost = $rate !== null ? round($hours * $rate, 2) : null;
            $savings = round($s['savings'] ?? 0, 2);
            return ['user' => ['id' => $uid, 'name' => $users[$uid]['name'] ?? '#' . $uid], 'rounds' => (int) ($s['rounds'] ?? 0),
                'emails' => (int) (($s['emails'] ?? 0) + ($s['po'] ?? 0)), 'offers' => (int) ($s['offers'] ?? 0), 'decisions' => (int) ($s['decisions'] ?? 0),
                'successful' => (int) ($s['successful'] ?? 0), 'savings' => $savings, 'margin_pp' => $revenue > 0 ? round($savings / $revenue * 100, 2) : null,
                'hours' => $hours, 'hourly_cost' => $rate, 'cost' => $cost, 'roi' => $cost !== null && $cost > 0 ? round($savings / $cost, 1) : null];
        };
        $me = $row((int) $context->userId);
        $teamRows = null;
        if ($team) {
            $teamRows = array_map($row, array_values(array_filter(array_keys($stats), fn($uid) => ($stats[$uid]['rounds'] ?? 0) + ($stats[$uid]['decisions'] ?? 0) > 0)));
            usort($teamRows, fn($a, $b) => $b['savings'] <=> $a['savings']);
        }
        $months = [];
        foreach ($this->repo->monthlySavings(date('Y-01-01')) as $r) {
            if (!$team && (int) $r['id_user'] !== (int) $context->userId) continue;
            $months[$r['ym']] = round(($months[$r['ym']] ?? 0) + (float) $r['savings'], 2);
        }
        return ['period' => $period, 'from' => $from, 'to' => $to, 'revenue' => round($revenue, 2), 'me' => $me, 'team' => $teamRows,
            'team_total' => $teamRows !== null ? $this->sumRows($teamRows, $revenue) : null,
            'months' => array_map(fn($ym, $v) => ['month' => $ym, 'savings' => $v], array_keys($months), array_values($months)),
            'year_savings' => round(array_sum($months), 2)];
    }

    private function sumRows(array $rows, float $revenue): array
    {
        $sum = fn($k) => array_sum(array_column($rows, $k));
        $cost = array_sum(array_map(fn($r) => $r['cost'] ?? 0, $rows));
        $savings = round($sum('savings'), 2);
        return ['rounds' => (int) $sum('rounds'), 'emails' => (int) $sum('emails'), 'offers' => (int) $sum('offers'), 'decisions' => (int) $sum('decisions'),
            'successful' => (int) $sum('successful'), 'savings' => $savings, 'margin_pp' => $revenue > 0 ? round($savings / $revenue * 100, 2) : null,
            'hours' => round($sum('hours'), 2), 'cost' => round($cost, 2), 'roi' => $cost > 0 ? round($savings / $cost, 1) : null];
    }

    // --- Pomocnicze ------------------------------------------------------------------------------------------------

    /** @return array<int, array{price:float, supplier_id:int, day:string}> najtańsza z ostatnich cen dostawców */
    private function lastPrices(array $mids): array
    {
        $out = [];
        foreach ($this->prices->lastBySupplier(array_values($mids)) as $mid => $bySupplier) {
            foreach ($bySupplier as $sid => $p) {
                if (!isset($out[$mid]) || $p['price'] < $out[$mid]['price']) $out[$mid] = ['price' => round($p['price'], 6), 'supplier_id' => (int) $sid, 'day' => $p['day']];
            }
        }
        return $out;
    }

    /** @return array<int, list<int>> dostawca → zwykłe dni dostaw (1 = poniedziałek) */
    private function usualDays(array $supplierIds): array
    {
        $history = [];
        foreach ($this->supplyRepo->deliveryDays(date('Y-m-d', strtotime('-180 days'))) as $d) $history[(int) $d['id_supplier']][] = $d['day'];
        $out = [];
        foreach ($this->repo->suppliersById(array_values(array_unique($supplierIds))) as $sid => $s) {
            $out[$sid] = DeliveryScheduleService::usualDays($s['delivery_weekdays'] !== null ? (int) $s['delivery_weekdays'] : null, array_unique($history[$sid] ?? []));
        }
        return $out;
    }

    /** @return array<int, array> surowce według id (dane jak w Zaopatrzeniu) */
    private function materialsById(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        return array_column($this->supplyRepo->materials($ids), null, 'id');
    }

    private function material(array $r): array
    {
        return ['id' => $r['id'], 'name' => $r['name'], 'unit' => $r['unit'], 'factor' => $r['factor'], 'base_unit' => $r['base_unit'], 'packaging' => $r['packaging'],
            'category' => $r['category'], 'mode' => $r['mode']];
    }

    private function orderOf(int $roundId, int $lineId): ?array
    {
        foreach ($this->repo->orders($roundId) as $o) if ((int) $o['id_line'] === $lineId) return $o;
        return null;
    }

    /** Odpowiedź do : 12:00 dnia po buy.reply_hours godzinach (24 h → jutro 12:00), co najmniej 2 godziny od teraz. */
    private function defaultReplyBy(): string
    {
        $hours = max(1, (int) $this->params->get('buy.reply_hours', '24'));
        $noon = strtotime(date('Y-m-d 12:00:00', strtotime("+{$hours} hours")));
        if ($noon < time() + 7200) $noon = strtotime('+1 day', $noon);
        return date('Y-m-d H:i:s', $noon);
    }

    private function load(int $id): array
    {
        return $this->repo->round($id) ?? throw ApiException::notFound('buy_round', $id);
    }

    private function draft(int $id): array
    {
        $round = $this->load($id);
        if ($round['status'] !== 'draft') throw new ApiException('BUY_ROUND_NOT_DRAFT', 409, [], ['status' => $round['status']]);
        return $round;
    }

    private function email(?string $email): ?string
    {
        $email = trim((string) $email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function number(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }
}
