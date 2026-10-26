<?php
declare(strict_types=1);

namespace App\Services\Workshop\Supply;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Repositories\Workshop\InvoiceRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Material\MaterialPriceService;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * Kontrola faktur zakupowych : faktura z KSeF (pobrana jako nabywca), z pliku XML KSeF, z PDF (tekst
 * pdftotext) albo ze skanu / zdjęcia (tekst OCR z przeglądarki) — linie rozpoznane gdy ilość × cena = wartość ;
 * reszta wpisywana ręcznie. Nazwy dostawcy przypisywane do naszych surowców z przelicznikiem (jednostki bazowe
 * surowca na jednostkę faktury) — zapamiętane na dostawcę (NIP albo dostawca ERP), stosowane do kolejnych faktur. Porównanie z zamówieniem :
 * ilość fakturowana vs przyjęta (albo zamówiona), cena vs cena zamówienia (tolerancje 1 % ilości, 0,5 % ceny),
 * pozycje spoza zamówienia, suma linii vs suma faktury. Decyzja : zaakceptowana albo reklamacja.
 */
final class InvoiceService
{
    public const STATUSES = ['open', 'accepted', 'disputed'];
    public const MAX_FILE = 5 * 1024 * 1024;
    /** Linie odczytane z tekstu (PDF, OCR) albo wpisane : edytowalne ; z KSeF / XML nie. */
    private const EDITABLE_SOURCES = ['pdf', 'ocr', 'manual'];
    private const QTY_TOL = 0.01;
    private const PRICE_TOL = 0.005;

    public function __construct(
        private InvoiceRepository $repo,
        private SupplyRepository $supply,
        private SupplyService $orders,
        private KsefInvoiceSource $ksef,
        private TransactionManager $tx,
        private AuditTrail $audit,
        private MaterialPriceService $prices,
    ) {
    }

    // --- Lista i szczegóły ------------------------------------------------------------------------------------------

    public function list(string $status, ApiContext $context): array
    {
        if (!in_array($status, ['all', ...self::STATUSES], true)) throw ApiException::validation([['field' => 'status', 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => 'all, ' . implode(', ', self::STATUSES)]]]);
        $rows = array_map(function ($i) {
            $control = $this->control($i, $this->repo->lines((int) $i['id']));
            return $this->summary($i) + ['control' => $control['summary']];
        }, $this->repo->invoices($status === 'all' ? null : $status));
        return ['invoices' => $rows, 'can_write' => $context->can('prod.supply.write')];
    }

    public function detail(int $id, ApiContext $context): array
    {
        $i = $this->load($id);
        $lines = $this->repo->lines($id);
        $control = $this->control($i, $lines);
        $lang = $context->translator->lang();
        $materials = $this->repo->materials();
        $orderMaterials = $i['id_po'] ? array_map(fn($l) => (int) $l['id_raw_material'], $this->supply->lines((int) $i['id_po'])) : [];
        $out = [];
        foreach ($lines as $k => $l) {
            $c = $control['lines'][$k];
            $row = $this->presentLine($l, $lang) + ['control' => $c];
            if ($l['id_raw_material'] === null) $row['suggestions'] = $this->suggest($l, $i, $materials, $orderMaterials, $lang);
            $out[] = $row;
        }
        return $this->summary($i) + [
            'note' => $i['note'], 'decided_at' => $i['decided_at'],
            'lines' => $out, 'control' => $control['summary'],
            'orders' => $i['id_supplier'] ? $this->candidates((int) $i['id_supplier'], $lines) : [],
            'suppliers' => $i['status'] === 'open' ? $this->supplierChoices($i) : [],
            'text' => in_array($i['source'], ['pdf', 'ocr'], true) ? $this->repo->fileText($id) : null,
            'can_write' => $context->can('prod.supply.write'), 'editable' => $i['status'] === 'open' && $context->can('prod.supply.write'),
            'lines_editable' => in_array($i['source'], self::EDITABLE_SOURCES, true) && $i['status'] === 'open' && $context->can('prod.supply.write'),
            // Ilości : do zamówienia (wysłane / częściowo przyjęte) albo — bez zamówienia — przez zamówienie utworzone z faktury.
            'can_receive' => $i['status'] === 'open' && $context->can('prod.supply.write') && $control['summary']['mapped'] > 0
                && ($i['id_po'] !== null ? in_array($i['po_status'], ['sent', 'partial'], true) : $i['id_supplier'] !== null),
            'receive_mode' => $i['id_po'] !== null ? 'order' : 'invoice',
            'can_import_prices' => $i['status'] !== 'disputed' && $context->can('prod.supply.write') && $control['summary']['mapped'] > 0,
            'prices_imported_at' => $i['prices_imported_at'],
        ];
    }

    /**
     * Kontrola linii : ilość w jednostkach bazowych (ilość × przelicznik), cena netto za jednostkę bazową,
     * porównanie z linią zamówienia tego surowca (ilość przyjęta, inaczej zamówiona ; cena zamówienia) i z cennikiem.
     */
    private function control(array $inv, array $lines): array
    {
        $po = [];
        if ($inv['id_po']) foreach ($this->supply->lines((int) $inv['id_po']) as $pl) $po[(int) $pl['id_raw_material']] = $pl;
        $out = [];
        $issues = 0;
        $mapped = 0;
        $gap = 0.0;
        $sum = 0.0;
        foreach ($lines as $l) {
            $flags = [];
            $net = $l['net_value'] !== null ? (float) $l['net_value'] : ($l['unit_price'] !== null ? (float) $l['unit_price'] * (float) $l['quantity'] : null);
            $sum += (float) ($net ?? 0);
            $c = ['quantity_base' => null, 'price_base' => null, 'ordered' => null, 'received' => null, 'order_price' => null, 'catalog_price' => null,
                'qty_gap_pct' => null, 'price_gap_pct' => null, 'catalog_gap_pct' => null, 'value_gap' => null, 'flags' => []];
            if ($l['id_raw_material'] === null) {
                $flags[] = 'unmapped';
            } else {
                $mapped++;
                $factor = (float) ($l['factor'] ?: 1);
                $qtyBase = (float) $l['quantity'] * $factor;
                $priceBase = $net !== null && $qtyBase > 0 ? $net / $qtyBase : null;
                $c['quantity_base'] = round($qtyBase, 3);
                $c['price_base'] = $priceBase;
                $catalog = $l['catalog_price'] !== null && (float) $l['catalog_price'] > 0 ? (float) $l['catalog_price'] : null;
                $c['catalog_price'] = $catalog;
                if ($catalog !== null && $priceBase !== null) $c['catalog_gap_pct'] = round(($priceBase - $catalog) / $catalog * 100, 2);
                if ($inv['id_po']) {
                    $pl = $po[(int) $l['id_raw_material']] ?? null;
                    if ($pl === null) $flags[] = 'not_in_order';
                    else {
                        $ordered = (float) $pl['quantity'];
                        $received = (float) $pl['received_qty'];
                        $c['ordered'] = $ordered;
                        $c['received'] = $received;
                        $ref = $received > 0 ? $received : $ordered;
                        if ($ref > 0) {
                            $c['qty_gap_pct'] = round(($qtyBase - $ref) / $ref * 100, 2);
                            if ($qtyBase > $ref * (1 + self::QTY_TOL)) $flags[] = $received > 0 ? 'qty_over_received' : 'qty_over_ordered';
                            elseif ($received > 0 && $qtyBase < $ref * (1 - self::QTY_TOL)) $flags[] = 'qty_under_received';
                        }
                        $orderPrice = $pl['unit_price'] !== null ? (float) $pl['unit_price'] : null;
                        $c['order_price'] = $orderPrice;
                        if ($orderPrice !== null && $orderPrice > 0 && $priceBase !== null) {
                            $c['price_gap_pct'] = round(($priceBase - $orderPrice) / $orderPrice * 100, 2);
                            if ($priceBase > $orderPrice * (1 + self::PRICE_TOL)) $flags[] = 'price_over';
                            elseif ($priceBase < $orderPrice * (1 - self::PRICE_TOL)) $flags[] = 'price_under';
                            $c['value_gap'] = $net !== null ? round($net - $qtyBase * $orderPrice, 2) : null;
                            $gap += (float) ($c['value_gap'] ?? 0);
                        }
                    }
                }
            }
            $c['flags'] = $flags;
            if (array_intersect($flags, ['unmapped', 'not_in_order', 'qty_over_received', 'qty_over_ordered', 'price_over'])) $issues++;
            $out[] = $c;
        }
        $totalMismatch = $inv['total_net'] !== null && $lines && abs((float) $inv['total_net'] - $sum) > 0.05;
        $state = !$lines ? 'no_lines' : ($mapped < count($lines) ? 'unmapped' : (!$inv['id_po'] ? 'no_order' : ($issues > 0 || $totalMismatch ? 'gaps' : 'clean')));
        return ['lines' => $out, 'summary' => ['state' => $state, 'lines' => count($lines), 'mapped' => $mapped, 'issues' => $issues, 'value_gap' => round($gap, 2),
            'lines_net' => round($sum, 2), 'total_mismatch' => $totalMismatch]];
    }

    // --- Import ---------------------------------------------------------------------------------------------------

    /** Faktury zakupowe w KSeF za okres (bez zapisu) ; zaznaczone już zaimportowane. */
    public function ksefSearch(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['from', 'to'])->date('from', true, false)->date('to', true, false);
        if (!$v->hasError('from') && !$v->hasError('to') && $v->value('from') > $v->value('to')) $v->add('to', 'DATE_ORDER');
        $v->throwIfInvalid();
        try {
            $rows = $this->ksef->search((string) $v->value('from'), (string) $v->value('to'));
        } catch (\Throwable $e) {
            throw new ApiException('KSEF_UNAVAILABLE', 422, [], ['reason' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $done = array_flip($this->repo->importedKsef(array_column($rows, 'ksef_number')));
        return ['invoices' => array_map(fn($r) => $r + ['imported' => isset($done[$r['ksef_number']])], $rows)];
    }

    /** Import wybranych faktur z KSeF (XML) ; już zaimportowane pomijane. */
    public function ksefImport(array $body, ApiContext $context): array
    {
        $numbers = $body['ksef_numbers'] ?? null;
        $v = (new Validator($body))->only(['ksef_numbers']);
        if (!is_array($numbers) || !$numbers) $v->add('ksef_numbers', 'REQUIRED');
        elseif (count($numbers) > 50) $v->add('ksef_numbers', 'TOO_MANY', ['max' => 50]);
        elseif (array_filter($numbers, fn($n) => !is_string($n) || !preg_match('/^[\w\-]{10,64}$/', $n))) $v->add('ksef_numbers', 'INVALID_CHOICE', ['allowed' => 'KSeF numbers']);
        $v->throwIfInvalid();
        $imported = [];
        $skipped = 0;
        $errors = [];
        foreach (array_unique($numbers) as $n) {
            if ($this->repo->findDuplicate($n, null, null) !== null) { $skipped++; continue; }
            try {
                $xml = $this->ksef->xml($n);
                $parsed = InvoiceParser::ksefXml($xml);
                $dup = $this->repo->findDuplicate(null, $parsed['seller_nip'], $parsed['number']);
                if ($dup !== null) { $skipped++; continue; }
                $imported[] = $this->create($parsed, 'ksef', $n, ['filename' => $n . '.xml', 'mime' => 'application/xml', 'content' => $xml, 'text' => null], $context);
            } catch (\Throwable $e) {
                $errors[] = ['ksef_number' => $n, 'message' => $e instanceof \InvalidArgumentException ? 'NOT_KSEF_XML' : mb_substr($e->getMessage(), 0, 300)];
            }
        }
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Plik faktury : XML KSeF (pobrany ręcznie), PDF (tekst pdftotext), skan albo zdjęcie (tekst OCR z przeglądarki,
     * pole ocr_text). PDF bez tekstu (skan) bez ocr_text : PDF_SCANNED — przeglądarka odczytuje strony OCR i wysyła ponownie.
     */
    public function upload(array $body, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['filename', 'content_base64', 'ocr_text'])->string('filename', 255)->string('content_base64', (int) (self::MAX_FILE * 1.4))
            ->string('ocr_text', 200000, false, true);
        $v->throwIfInvalid();
        $content = base64_decode((string) $v->value('content_base64'), true);
        if ($content === false || $content === '') throw ApiException::validation([['field' => 'content_base64', 'code' => 'INVALID_FILE', 'params' => []]]);
        if (strlen($content) > self::MAX_FILE) throw ApiException::validation([['field' => 'content_base64', 'code' => 'FILE_TOO_LARGE', 'params' => ['max_kb' => self::MAX_FILE / 1024]]]);
        $filename = basename((string) $v->value('filename'));
        $hasOcr = $v->hasRaw('ocr_text');
        $ocr = InvoiceParser::ocrText((string) $v->value('ocr_text', ''));
        $image = self::imageMime($content);
        if (str_starts_with($content, '%PDF')) {
            try {
                $text = $this->pdfText($content);
            } catch (ApiException $e) {
                if (!$hasOcr) throw $e;
                $text = '';
            }
            if (InvoiceParser::hasText($text)) {
                $source = 'pdf';
            } elseif ($hasOcr) {
                [$text, $source] = [$ocr, 'ocr'];
            } else {
                throw new ApiException('PDF_SCANNED', 422);
            }
            $file = ['filename' => $filename, 'mime' => 'application/pdf', 'content' => $content, 'text' => $text];
            $parsed = InvoiceParser::pdfText($text, $this->repo->companyNip());
        } elseif ($image !== null) {
            // Zdjęcie bez tekstu OCR : zapisane, linie wpisywane ręcznie.
            $source = 'ocr';
            $file = ['filename' => $filename, 'mime' => $image, 'content' => $content, 'text' => $ocr !== '' ? $ocr : null];
            $parsed = InvoiceParser::pdfText($ocr, $this->repo->companyNip());
        } else {
            try {
                $parsed = InvoiceParser::ksefXml($content);
            } catch (\InvalidArgumentException) {
                throw ApiException::validation([['field' => 'content_base64', 'code' => 'INVALID_FILE', 'params' => []]]);
            }
            $source = 'xml';
            $file = ['filename' => $filename, 'mime' => 'application/xml', 'content' => $content, 'text' => null];
        }
        $dup = $this->repo->findDuplicate(null, $parsed['seller_nip'], $parsed['number']);
        if ($dup !== null) throw new ApiException('INVOICE_EXISTS', 409, [], ['id' => $dup]);
        $id = $this->create($parsed, $source, null, $file, $context);
        return $this->detail($id, $context);
    }

    /** Zdjęcie (JPEG, PNG, WebP) po sygnaturze pliku ; inaczej null. */
    private static function imageMime(string $content): ?string
    {
        if (str_starts_with($content, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($content, "\x89PNG\r\n\x1A\n")) return 'image/png';
        if (str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP') return 'image/webp';
        return null;
    }

    private function pdfText(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tfbinv');
        file_put_contents($tmp, $pdf);
        try {
            $proc = @proc_open(['pdftotext', '-layout', '-enc', 'UTF-8', $tmp, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) throw new ApiException('PDF_TEXT_UNAVAILABLE', 422);
            $text = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code === 127) throw new ApiException('PDF_TEXT_UNAVAILABLE', 422);
            return mb_substr($text, 0, 200000);
        } finally {
            @unlink($tmp);
        }
    }

    /** Zapis faktury : dostawca (NIP zapamiętany albo nazwa), linie z zapamiętanymi przypisaniami, zamówienie podpowiedziane. */
    private function create(array $p, string $source, ?string $ksefNumber, array $file, ApiContext $context): int
    {
        $supplierId = $p['seller_nip'] ? $this->repo->supplierForNip($p['seller_nip']) : null;
        if ($supplierId === null && $p['seller_name']) {
            $best = null;
            $second = 0.0;
            foreach ($this->repo->suppliers() as $s) {
                $score = InvoiceParser::similarity((string) $p['seller_name'], (string) $s['name']);
                if ($best === null || $score > $best[1]) { $second = $best[1] ?? 0.0; $best = [(int) $s['id'], $score]; }
                elseif ($score > $second) $second = $score;
            }
            if ($best && $best[1] >= 0.99 && $second < 0.99) $supplierId = $best[0];
        }
        return $this->tx->execute(function () use ($p, $source, $ksefNumber, $file, $supplierId, $context) {
            $id = $this->repo->insert(['source' => $source, 'ksef_number' => $ksefNumber, 'id_supplier' => $supplierId, 'seller_nip' => $p['seller_nip'],
                'seller_name' => $p['seller_name'] !== null ? mb_substr($p['seller_name'], 0, 255) : null, 'number' => $p['number'] !== null ? mb_substr($p['number'], 0, 80) : null,
                'issue_date' => $p['issue_date'], 'currency' => $p['currency'] ?: 'PLN', 'total_net' => $p['total_net'], 'total_gross' => $p['total_gross'],
                'status' => 'open', 'created_by' => $context->userId]);
            $this->repo->saveFile($id, $file['filename'], $file['mime'], $file['content'], $file['text']);
            if ($supplierId !== null && $p['seller_nip']) $this->repo->rememberSupplier($p['seller_nip'], $supplierId);
            $inv = $this->repo->invoice($id);
            foreach ($p['lines'] as $k => $l) $this->repo->insertLine($id, ['line_no' => $l['line_no'] ?? $k + 1] + $l + $this->known($inv, $l));
            $this->autoLink($id);
            $this->audit->record($context, 'create', 'purchase_invoice', $id, null, ['source' => $source, 'ksef_number' => $ksefNumber, 'number' => $p['number'], 'lines' => count($p['lines'])]);
            return $id;
        });
    }

    /** Zapamiętane przypisanie linii (dostawca + nazwa + jednostka). */
    private function known(array $inv, array $l): array
    {
        foreach ($this->supplierKeys($inv) as $key) {
            $m = $this->repo->mapping($key, InvoiceParser::nameKey((string) $l['name']), InvoiceParser::unitKey($l['unit'] ?? null));
            if ($m) return ['id_raw_material' => (int) $m['id_raw_material'], 'factor' => (float) $m['factor']];
        }
        return [];
    }

    /** @return list<string> klucze dostawcy : NIP, potem dostawca ERP */
    private function supplierKeys(array $inv): array
    {
        $keys = [];
        if ($inv['seller_nip']) $keys[] = 'nip:' . $inv['seller_nip'];
        if ($inv['id_supplier']) $keys[] = 'sup:' . $inv['id_supplier'];
        return $keys;
    }

    /** Bez zamówienia : powiązanie z zamówieniem dostawcy, które ma najwięcej surowców faktury (nie zafakturowane). */
    private function autoLink(int $id): void
    {
        $inv = $this->repo->invoice($id);
        if ($inv['id_po'] !== null || $inv['id_supplier'] === null) return;
        $best = $this->candidates((int) $inv['id_supplier'], $this->repo->lines($id))[0] ?? null;
        if ($best && $best['overlap'] > 0 && $best['invoices'] === 0) $this->repo->update($id, ['id_po' => $best['id']]);
    }

    /** Dostawcy ERP do wyboru : najpierw najbardziej podobni do nazwy sprzedawcy. */
    private function supplierChoices(array $i): array
    {
        $rows = array_map(fn($s) => ['id' => (int) $s['id'], 'name' => trim((string) $s['name']),
            'score' => $i['seller_name'] ? round(InvoiceParser::similarity((string) $i['seller_name'], (string) $s['name']), 2) : 0.0], $this->repo->suppliers());
        usort($rows, fn($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);
        return $rows;
    }

    private function candidates(int $supplierId, array $lines): array
    {
        $materials = array_filter(array_map(fn($l) => $l['id_raw_material'] !== null ? (int) $l['id_raw_material'] : null, $lines));
        $rows = array_map(function ($o) use ($materials) {
            $poMaterials = array_map('intval', array_filter(explode(',', (string) $o['materials'])));
            return ['id' => (int) $o['id'], 'code' => $o['code'], 'status' => $o['status'], 'week_start' => $o['week_start'], 'delivery_date' => $o['delivery_date'],
                'invoices' => (int) $o['invoices'], 'overlap' => count(array_intersect($materials, $poMaterials)), 'lines' => count($poMaterials)];
        }, $this->repo->orderCandidates($supplierId));
        usort($rows, fn($a, $b) => [$b['overlap'], $a['invoices']] <=> [$a['overlap'], $b['invoices']]);
        return $rows;
    }

    /** Podpowiedzi surowca : z zamówienia, od dostawcy, potem wszystkie — wg podobieństwa nazw. */
    private function suggest(array $l, array $inv, array $materials, array $orderMaterials, string $lang): array
    {
        $scored = [];
        foreach ($materials as $m) {
            $score = InvoiceParser::similarity((string) $l['name'], (string) $m['name']);
            if (in_array((int) $m['id'], $orderMaterials, true)) $score += 0.3;
            if ($inv['id_supplier'] && (int) $m['id_current_supplier'] === (int) $inv['id_supplier']) $score += 0.2;
            if ($score >= 0.3) $scored[] = [$score, $m];
        }
        usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
        return array_map(fn($x) => ['raw_material_id' => (int) $x[1]['id'], 'name' => $lang === 'fr' && $x[1]['name_fr'] ? $x[1]['name_fr'] : $x[1]['name'], 'score' => round($x[0], 2),
            'base_unit' => $x[1]['unit'] ?: 'szt', 'factor' => InvoiceParser::defaultFactor($l['unit'], $x[1]['unit'], $x[1]['package_size'] !== null ? (float) $x[1]['package_size'] : null)],
            array_slice($scored, 0, 5));
    }

    // --- Zmiany -------------------------------------------------------------------------------------------------------

    public function update(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->open($id);
        $v = (new Validator($body, true))->only(['supplier_id', 'po_id', 'number', 'issue_date', 'note'])
            ->integer('supplier_id', false, 1, null, true)->integer('po_id', false, 1, null, true)->string('number', 80, false, true)->date('issue_date')->string('note', 1000, false, true);
        $supplierId = $v->provided('supplier_id') ? $v->value('supplier_id') : ($inv['id_supplier'] !== null ? (int) $inv['id_supplier'] : null);
        if ($v->value('supplier_id') !== null && !$v->hasError('supplier_id') && !$this->repo->supplierExists((int) $v->value('supplier_id'))) $v->add('supplier_id', 'REFERENCE_NOT_FOUND', ['id' => (string) $v->value('supplier_id')]);
        if ($v->value('po_id') !== null && !$v->hasError('po_id') && ($supplierId === null || !$this->repo->orderOfSupplier((int) $v->value('po_id'), (int) $supplierId))) $v->add('po_id', 'REFERENCE_NOT_FOUND', ['id' => (string) $v->value('po_id')]);
        $v->throwIfInvalid();
        $data = [];
        foreach (['supplier_id' => 'id_supplier', 'po_id' => 'id_po', 'number' => 'number', 'issue_date' => 'issue_date', 'note' => 'note'] as $in => $col) if ($v->provided($in)) $data[$col] = $v->value($in);
        if (array_key_exists('id_supplier', $data) && (string) $data['id_supplier'] !== (string) $inv['id_supplier'] && !array_key_exists('id_po', $data)) $data['id_po'] = null;
        $this->tx->execute(function () use ($id, $inv, $data, $context) {
            if ($data) $this->repo->update($id, $data);
            if (!empty($data['id_supplier']) && $inv['seller_nip']) $this->repo->rememberSupplier($inv['seller_nip'], (int) $data['id_supplier']);
            // Nowy dostawca : zapamiętane przypisania tego dostawcy dla linii jeszcze bez surowca.
            if (!empty($data['id_supplier'])) {
                $fresh = $this->repo->invoice($id);
                foreach ($this->repo->lines($id) as $l) {
                    if ($l['id_raw_material'] !== null) continue;
                    $k = $this->known($fresh, $l);
                    if ($k) $this->repo->mapLine((int) $l['id'], $k['id_raw_material'], $k['factor']);
                }
                if (!array_key_exists('id_po', $data) || $data['id_po'] === null) $this->autoLink($id);
            }
            $this->audit->record($context, 'update', 'purchase_invoice', $id, array_intersect_key($inv, $data), $data);
        });
        return $this->detail($id, $context);
    }

    /** Linie faktury z PDF / ręcznej (zastępowane) ; przypisania zapamiętane stosowane od razu. */
    public function saveLines(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->open($id);
        if (!in_array($inv['source'], self::EDITABLE_SOURCES, true)) throw new ApiException('INVOICE_LINES_READ_ONLY', 409);
        $v = (new Validator($body))->only(['lines']);
        $rows = $body['lines'] ?? null;
        $clean = [];
        if (!is_array($rows)) $v->add('lines', 'NOT_ARRAY');
        elseif (count($rows) > 300) $v->add('lines', 'TOO_MANY', ['max' => 300]);
        else foreach (array_values($rows) as $i => $r) {
            $name = is_array($r) ? trim((string) ($r['name'] ?? '')) : '';
            if ($name === '') { $v->add("lines.$i.name", 'REQUIRED'); continue; }
            $qty = $r['quantity'] ?? null;
            if ((!is_int($qty) && !is_float($qty)) || $qty <= 0) { $v->add("lines.$i.quantity", 'GREATER_THAN', ['min' => 0]); continue; }
            $price = $r['unit_price'] ?? null;
            $net = $r['net_value'] ?? null;
            if ($price !== null && ((!is_int($price) && !is_float($price)) || $price < 0)) { $v->add("lines.$i.unit_price", 'MIN', ['min' => 0]); continue; }
            if ($net !== null && ((!is_int($net) && !is_float($net)) || $net < 0)) { $v->add("lines.$i.net_value", 'MIN', ['min' => 0]); continue; }
            $clean[] = ['line_no' => $i + 1, 'name' => mb_substr($name, 0, 500), 'code' => isset($r['code']) && $r['code'] !== '' ? mb_substr((string) $r['code'], 0, 80) : null,
                'quantity' => (float) $qty, 'unit' => isset($r['unit']) && $r['unit'] !== '' ? mb_substr((string) $r['unit'], 0, 20) : null,
                'unit_price' => $price !== null ? (float) $price : ($net !== null ? (float) $net / (float) $qty : null),
                'net_value' => $net !== null ? round((float) $net, 2) : ($price !== null ? round((float) $price * (float) $qty, 2) : null),
                'vat_rate' => isset($r['vat_rate']) && $r['vat_rate'] !== '' ? mb_substr((string) $r['vat_rate'], 0, 8) : null];
        }
        $v->throwIfInvalid();
        $before = [];
        foreach ($this->repo->lines($id) as $l) $before[InvoiceParser::nameKey($l['name']) . '|' . InvoiceParser::unitKey($l['unit'])] = $l;
        $this->tx->execute(function () use ($id, $inv, $clean, $before, $context) {
            $this->repo->deleteLines($id);
            foreach ($clean as $l) {
                $prev = $before[InvoiceParser::nameKey($l['name']) . '|' . InvoiceParser::unitKey($l['unit'])] ?? null;
                $map = $prev && $prev['id_raw_material'] !== null ? ['id_raw_material' => (int) $prev['id_raw_material'], 'factor' => (float) $prev['factor']] : $this->known($inv, $l);
                $this->repo->insertLine($id, $l + $map);
            }
            if ($inv['total_net'] === null || $inv['source'] === 'manual') $this->repo->update($id, ['total_net' => round(array_sum(array_map(fn($l) => (float) ($l['net_value'] ?? 0), $clean)), 2)]);
            $this->autoLink($id);
            $this->audit->record($context, 'update', 'purchase_invoice_lines', $id, null, ['lines' => count($clean)]);
        });
        return $this->detail($id, $context);
    }

    /** Przypisanie linii do surowca (null = usuń) ; remember = zapamiętaj dla dostawcy i zastosuj do takich samych linii. */
    public function mapLine(int $id, int $lineId, array $body, ApiContext $context): array
    {
        $inv = $this->open($id);
        $line = $this->repo->line($id, $lineId);
        if ($line === null) throw ApiException::notFound('purchase_invoice_line', $lineId);
        $v = (new Validator($body))->only(['raw_material_id', 'factor', 'remember'])
            ->integer('raw_material_id', true, 1, null, true)->decimal('factor', false, 0, 10_000_000, true, true)->boolean('remember');
        $mid = $v->value('raw_material_id');
        $material = $mid !== null && !$v->hasError('raw_material_id') ? $this->repo->material((int) $mid) : null;
        if ($mid !== null && !$v->hasError('raw_material_id') && $material === null) $v->add('raw_material_id', 'REFERENCE_NOT_FOUND', ['id' => (string) $mid]);
        $v->throwIfInvalid();
        // Bez przelicznika : domyślny z jednostek (kg → g = 1000 ; worek, karton… = opakowanie dostawcy).
        $factor = $mid === null ? null : (float) ($v->value('factor')
            ?? InvoiceParser::defaultFactor($line['unit'], $material['unit'], $material['package_size'] !== null ? (float) $material['package_size'] : null));
        $remember = $v->value('remember') !== false;
        $keys = $this->supplierKeys($inv);
        $this->tx->execute(function () use ($id, $line, $mid, $factor, $remember, $keys, $inv, $context) {
            $nameKey = InvoiceParser::nameKey($line['name']);
            $unitKey = InvoiceParser::unitKey($line['unit']);
            foreach ($this->repo->lines($id) as $l) {
                if ((int) $l['id'] === (int) $line['id'] || ($remember && InvoiceParser::nameKey($l['name']) === $nameKey && InvoiceParser::unitKey($l['unit']) === $unitKey)) {
                    $this->repo->mapLine((int) $l['id'], $mid, $factor);
                }
            }
            if ($remember && $mid !== null && $keys) {
                $this->repo->saveMapping($keys[0], $inv['id_supplier'] !== null ? (int) $inv['id_supplier'] : null, $nameKey, $unitKey, $line['name'], $line['unit'], (int) $mid, (float) $factor, $context->userId);
            }
            $this->autoLink($id);
            $this->audit->record($context, 'map', 'purchase_invoice_line', (int) $line['id'], ['raw_material_id' => $line['id_raw_material'], 'factor' => $line['factor']],
                ['raw_material_id' => $mid, 'factor' => $factor, 'remember' => $remember]);
        });
        return $this->detail($id, $context);
    }

    public function decide(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->load($id);
        $v = (new Validator($body))->only(['status', 'note'])->choice('status', self::STATUSES)->string('note', 1000, false, true);
        $v->throwIfInvalid();
        $status = $v->value('status');
        $data = ['status' => $status, 'decided_by' => $status === 'open' ? null : $context->userId, 'decided_at' => $status === 'open' ? null : date('Y-m-d H:i:s')];
        if ($v->provided('note')) $data['note'] = $v->value('note');
        $this->tx->execute(function () use ($id, $inv, $data, $context) {
            $this->repo->update($id, $data);
            $this->audit->record($context, 'decide', 'purchase_invoice', $id, ['status' => $inv['status']], $data);
        });
        return $this->detail($id, $context);
    }

    /** Przyjęcie dostawy wg faktury : ilości i ceny netto z faktury do zamówienia (linie przypisane do jego surowców). */
    public function receive(int $id, ApiContext $context): array
    {
        $inv = $this->open($id);
        if ($inv['id_po'] === null) return $this->receiveWithoutOrder($inv, $context);
        $po = [];
        $already = [];
        foreach ($this->supply->lines((int) $inv['id_po']) as $pl) {
            $po[(int) $pl['id_raw_material']] = (int) $pl['id'];
            $already[(int) $pl['id_raw_material']] = (float) ($pl['received_qty'] ?? 0);
        }
        // Pas de double réception : ce qui est déjà reçu sur la commande (page Dostawy ou facture précédente) est déduit.
        $lines = [];
        $mapped = false;
        foreach ($this->repo->lines($id) as $l) {
            if ($l['id_raw_material'] === null || !isset($po[(int) $l['id_raw_material']])) continue;
            $mapped = true;
            $mid = (int) $l['id_raw_material'];
            $qty = (float) $l['quantity'] * (float) ($l['factor'] ?: 1);
            $net = $l['net_value'] !== null ? (float) $l['net_value'] : null;
            $left = $qty - $already[$mid];
            $already[$mid] = max(0.0, $already[$mid] - $qty);
            if ($left <= 0.0005) continue;
            $lines[] = ['id' => $po[$mid], 'quantity' => round($left, 3), 'unit_price' => $net !== null && $qty > 0 ? $net / $qty : null];
        }
        if (!$lines) throw $mapped ? new ApiException('INVOICE_ALREADY_RECEIVED', 409) : new ApiException('INVOICE_NOTHING_TO_RECEIVE', 422);
        $day = $inv['issue_date'] && $inv['issue_date'] <= date('Y-m-d') ? $inv['issue_date'] : date('Y-m-d');
        $this->orders->receive((int) $inv['id_po'], ['received_on' => $day, 'lines' => $lines], $context);
        return $this->detail($id, $context);
    }

    /**
     * Bez zamówienia : zamówienie « przyjęte » utworzone z faktury (linie przypisane, ilość × przelicznik, cena netto
     * na jednostkę bazową) — ilości wchodzą na stan jak każde przyjęcie ; faktura zostaje z nim powiązana.
     */
    private function receiveWithoutOrder(array $inv, ApiContext $context): array
    {
        if ($inv['id_supplier'] === null) throw new ApiException('INVOICE_NO_SUPPLIER', 422);
        $lines = $this->materialLines($this->repo->lines((int) $inv['id']));
        if (!$lines) throw new ApiException('INVOICE_NOTHING_TO_RECEIVE', 422);
        $day = $inv['issue_date'] && $inv['issue_date'] <= date('Y-m-d') ? $inv['issue_date'] : date('Y-m-d');
        $this->tx->execute(function () use ($inv, $lines, $day, $context) {
            $po = $this->supply->insertReceived((int) $inv['id_supplier'], $day, 'Faktura ' . ($inv['number'] ?: '#' . $inv['id']), $context->userId);
            foreach ($lines as $mid => $l) {
                $m = $this->repo->material($mid);
                $lineId = $this->supply->insertLine($po, $mid, $l['quantity'], $m && (float) $m['package_size'] > 0 ? (float) $m['package_size'] : null, $l['price']);
                $this->supply->addReceipt($lineId, $day, $l['quantity'], $l['price'], $context->userId);
            }
            $this->repo->update((int) $inv['id'], ['id_po' => $po]);
            $this->audit->record($context, 'receive', 'purchase_invoice', (int) $inv['id'], null, ['order_id' => $po, 'lines' => count($lines), 'received_on' => $day]);
        });
        return $this->detail((int) $inv['id'], $context);
    }

    /**
     * Ostatnie ceny z faktury do kartoteki surowców : cena netto na jednostkę bazową (kilka linii tego samego surowca —
     * średnia ważona), dostawca faktury jako bieżący ; przez MaterialPriceService (historia cen, przeliczenie receptur).
     * line_ids : tylko wybrane linie (domyślnie wszystkie przypisane).
     */
    public function importPrices(int $id, array $body, ApiContext $context): array
    {
        $inv = $this->load($id);
        if ($inv['status'] === 'disputed') throw new ApiException('INVOICE_CLOSED', 409, [], ['status' => $inv['status']]);
        $v = (new Validator($body))->only(['line_ids']);
        $wanted = $body['line_ids'] ?? null;
        if ($wanted !== null && (!is_array($wanted) || array_filter($wanted, fn($x) => !is_int($x)))) $v->add('line_ids', 'NOT_ARRAY');
        $v->throwIfInvalid();
        $rows = array_filter($this->repo->lines($id), fn($l) => $wanted === null || in_array((int) $l['id'], $wanted, true));
        $lines = array_filter($this->materialLines($rows), fn($l) => $l['price'] !== null && $l['price'] > 0);
        if (!$lines) throw new ApiException('INVOICE_NO_PRICES', 422);
        $changes = [];
        $skipped = [];
        foreach ($lines as $mid => $l) {
            $m = $this->repo->material($mid);
            $supplier = $inv['id_supplier'] !== null ? (int) $inv['id_supplier'] : ($m['id_current_supplier'] !== null ? (int) $m['id_current_supplier'] : null);
            $package = (float) $m['package_size'] > 0 ? (float) $m['package_size'] : (float) CostOptimizationService::displayUnit($m['unit'])['factor'];
            $packPrice = round($l['price'] * $package, 2);
            // Kartoteka : cena za opakowanie (2 miejsca) ÷ opakowanie w całych jednostkach bazowych.
            if ($supplier === null || !$m['id_unit'] || $packPrice <= 0 || abs($package - round($package)) > 1e-9) { $skipped[] = $m['name']; continue; }
            $this->prices->update($mid, ['id_raw_material' => $mid, 'id_current_supplier' => $supplier, 'package_size_in_base_unit' => (int) round($package),
                'id_unit' => (int) $m['id_unit'], 'pack_price' => $packPrice], 'invoice', $context->userId);
            $unit = CostOptimizationService::displayUnit($m['unit']);
            $changes[] = ['raw_material_id' => $mid, 'name' => $m['name'], 'unit' => $unit['label'], 'factor' => $unit['factor'],
                'old_price' => $m['price_per_unit'] !== null ? (float) $m['price_per_unit'] : null, 'new_price' => round($packPrice / round($package), 5)];
        }
        if ($changes) {
            $this->repo->update($id, ['prices_imported_at' => date('Y-m-d H:i:s'), 'prices_imported_by' => $context->userId]);
            $this->audit->record($context, 'import_prices', 'purchase_invoice', $id, null, ['materials' => array_map(fn($c) => [$c['raw_material_id'], $c['old_price'], $c['new_price']], $changes)]);
        }
        return $this->detail($id, $context) + ['imported_prices' => $changes, 'skipped' => $skipped];
    }

    /** Linie przypisane według surowca : ilość w jednostkach bazowych i cena netto za jednostkę bazową (ważona). */
    private function materialLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if ($l['id_raw_material'] === null) continue;
            $qty = (float) $l['quantity'] * (float) ($l['factor'] ?: 1);
            if ($qty <= 0) continue;
            $net = $l['net_value'] !== null ? (float) $l['net_value'] : ($l['unit_price'] !== null ? (float) $l['unit_price'] * (float) $l['quantity'] : null);
            $mid = (int) $l['id_raw_material'];
            $out[$mid] ??= ['quantity' => 0.0, 'value' => 0.0, 'priced' => 0.0];
            $out[$mid]['quantity'] += $qty;
            if ($net !== null) { $out[$mid]['value'] += $net; $out[$mid]['priced'] += $qty; }
        }
        return array_map(fn($x) => ['quantity' => round($x['quantity'], 3), 'price' => $x['priced'] > 0 ? $x['value'] / $x['priced'] : null], $out);
    }

    public function delete(int $id, ApiContext $context): array
    {
        $inv = $this->open($id);
        $this->tx->execute(function () use ($id, $inv, $context) {
            $this->repo->delete($id);
            $this->audit->record($context, 'delete', 'purchase_invoice', $id, ['number' => $inv['number'], 'seller_nip' => $inv['seller_nip'], 'source' => $inv['source']], null);
        });
        return ['deleted' => true, 'id' => $id];
    }

    public function mappings(ApiContext $context): array
    {
        return ['mappings' => array_map(fn($m) => ['id' => (int) $m['id'], 'supplier_key' => $m['supplier_key'], 'supplier' => $m['supplier_name_erp'] !== null ? trim((string) $m['supplier_name_erp']) : null,
            'supplier_name' => $m['supplier_name'], 'supplier_unit' => $m['supplier_unit'], 'raw_material_id' => (int) $m['id_raw_material'], 'material' => $m['material_name'],
            'base_unit' => $m['material_unit'] ?: 'szt', 'factor' => (float) $m['factor'], 'updated_at' => $m['updated_at']], $this->repo->mappings()),
            'can_write' => $context->can('prod.supply.write')];
    }

    public function deleteMapping(int $id, ApiContext $context): array
    {
        if (!$this->repo->deleteMapping($id)) throw ApiException::notFound('purchase_invoice_mapping', $id);
        $this->audit->record($context, 'delete', 'purchase_invoice_mapping', $id, null, null);
        return ['deleted' => true, 'id' => $id];
    }

    /** Plik faktury (PDF albo XML KSeF) w base64 : przeglądarka otwiera go z pamięci. */
    public function file(int $id): array
    {
        $this->load($id);
        $f = $this->repo->file($id);
        if ($f === null) throw ApiException::notFound('invoice_file', $id);
        return ['filename' => $f['filename'], 'mime' => $f['mime'], 'size' => strlen($f['content']), 'content_base64' => base64_encode($f['content'])];
    }

    // --- Pomocnicze ---------------------------------------------------------------------------------------------------

    private function load(int $id): array
    {
        $i = $this->repo->invoice($id);
        if ($i === null) throw ApiException::notFound('purchase_invoice', $id);
        return $i;
    }

    private function open(int $id): array
    {
        $i = $this->load($id);
        if ($i['status'] !== 'open') throw new ApiException('INVOICE_CLOSED', 409, [], ['status' => $i['status']]);
        return $i;
    }

    private function summary(array $i): array
    {
        return [
            'id' => (int) $i['id'], 'source' => $i['source'], 'ksef_number' => $i['ksef_number'], 'number' => $i['number'], 'issue_date' => $i['issue_date'],
            'seller_nip' => $i['seller_nip'], 'seller_name' => $i['seller_name'],
            'supplier' => $i['id_supplier'] !== null ? ['id' => (int) $i['id_supplier'], 'name' => trim((string) ($i['supplier_name'] ?? ''))] : null,
            'currency' => $i['currency'], 'total_net' => $i['total_net'] !== null ? (float) $i['total_net'] : null, 'total_gross' => $i['total_gross'] !== null ? (float) $i['total_gross'] : null,
            'order' => $i['id_po'] !== null ? ['id' => (int) $i['id_po'], 'code' => $i['po_code'], 'status' => $i['po_status']] : null,
            'status' => $i['status'], 'file' => $i['filename'] !== null ? ['filename' => $i['filename'], 'mime' => $i['mime']] : null, 'created_at' => $i['created_at'],
        ];
    }

    private function presentLine(array $l, string $lang): array
    {
        $unit = $l['material_unit'] !== null ? CostOptimizationService::displayUnit($l['material_unit']) : null;
        return [
            'id' => (int) $l['id'], 'line_no' => (int) $l['line_no'], 'name' => $l['name'], 'code' => $l['code'], 'quantity' => (float) $l['quantity'], 'unit' => $l['unit'],
            'unit_price' => $l['unit_price'] !== null ? (float) $l['unit_price'] : null, 'net_value' => $l['net_value'] !== null ? (float) $l['net_value'] : null, 'vat_rate' => $l['vat_rate'],
            'material' => $l['id_raw_material'] !== null ? ['id' => (int) $l['id_raw_material'], 'name' => $lang === 'fr' && $l['material_name_fr'] ? $l['material_name_fr'] : $l['material_name'],
                'base_unit' => $l['material_unit'] ?: 'szt', 'unit' => $unit['label'] ?? 'szt', 'factor_display' => $unit['factor'] ?? 1] : null,
            'factor' => $l['factor'] !== null ? (float) $l['factor'] : null,
        ];
    }
}
