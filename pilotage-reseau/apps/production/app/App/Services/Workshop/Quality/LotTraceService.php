<?php
declare(strict_types=1);

namespace App\Services\Workshop\Quality;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Repositories\Workshop\QualityRoundRepository;
use App\Services\Utils\TCPDFService;
use App\Services\Workshop\Execution\OutputService;

/**
 * [416] Śledzenie partii (makieta hali K) : étiquette d'un carton ou numéro de partie scanné → d'où viennent les matières
 * (sacs de la partie : matière, lot, fournisseur, réception — sac sans scan signalé, matière non suivie « bez partii »)
 * et où sont allés les cartons (client et jour de livraison, WDT ; sur palette ; au magasin), contrôle qualité, blocage.
 * Même chaîne que la traçabilité de l'ERP (Partie i identyfikowalność) : partie → cartons du même produit étiquetés du
 * jour de production au jour + trace.label_days (l'étiquette du carton ne porte pas le numéro de partie de la tablette :
 * lien probable, signalé comme tel). Un carton → les parties de son produit fabriquées dans cette fenêtre ; plusieurs :
 * la personne choisit. PDF de la partie : même contenu, gabarit des documents (TCPDFService). Lecture seule.
 */
final class LotTraceService
{
    /** Numéro de partie de la tablette : RRMMDD-GRUPA-NN. */
    private const LOT_CODE = '/^\d{6}-[A-Z0-9]{1,8}-\d{2,}$/';

    public function __construct(private QualityRoundRepository $repo, private QualityRoundService $round, private OutputService $outputs,
                                private LotHoldService $holds)
    {
    }

    /**
     * Code scanné : numéro de partie (RRMMDD-GRUPA-NN), sinon numéro de carton (chiffres, aussi en fin d'adresse ou de texte).
     * @return array{kind:string, scanned:string, carton:?array, choices:list<array>, lot:?array}
     */
    public function scan(string $code, ApiContext $context): array
    {
        $this->round->assertManager($context);
        $raw = trim($code);
        if ($raw === '') throw ApiException::validation([['field' => 'code', 'code' => 'REQUIRED', 'params' => []]]);
        $upper = strtoupper($raw);
        if ((preg_match(self::LOT_CODE, $upper) || !ctype_digit($upper)) && ($o = $this->repo->outputByLotCode(mb_substr($upper, 0, 32))) !== null) {
            return ['kind' => 'lot', 'scanned' => $upper, 'carton' => null, 'choices' => [], 'lot' => $this->lot((int) $o['id'], $context)];
        }
        if (!preg_match('/(\d{1,12})\s*$/', $raw, $m) || ($carton = $this->repo->carton((int) $m[1])) === null) {
            throw new ApiException('QC_TRACE_UNKNOWN_CODE', 404, [], ['code' => mb_substr($raw, 0, 40)]);
        }
        $day = substr((string) $carton['date_add'], 0, 10);
        $labelDays = (int) ((strtotime($this->holds->labelDays($day)[1]) - strtotime($day)) / 86400);
        $outs = $this->repo->productOutputs((int) $carton['id_product'], date('Y-m-d', strtotime($day . " -{$labelDays} days")), $day);
        $cartonRow = ['id' => (int) $carton['id_carton'], 'product_id' => (int) $carton['id_product'], 'product' => $carton['product'], 'label_day' => $day,
            'status' => (int) $carton['status'], 'holds' => $this->holds->forLabel((int) $carton['id_product'], $day)];
        if (count($outs) === 1) return ['kind' => 'carton', 'scanned' => $raw, 'carton' => $cartonRow, 'choices' => [], 'lot' => $this->lot((int) $outs[0]['id'], $context)];
        return ['kind' => 'carton', 'scanned' => $raw, 'carton' => $cartonRow, 'lot' => null,
            'choices' => array_map(fn($o) => ['id' => (int) $o['id'], 'lot_code' => $o['lot_code'], 'product' => $o['item_name'], 'group' => $o['group_name'],
                'work_date' => substr((string) $o['work_date'], 0, 10), 'started_at' => date('G:i', strtotime((string) $o['created_at'])), 'quantity' => (float) $o['quantity'],
                'unit' => $o['base_unit']], $outs)];
    }

    /** Une partie : en-tête, matières (d'où), cartons (où), contrôle, blocage. */
    public function lot(int $id, ApiContext $context): array
    {
        $this->round->assertManager($context);
        $o = $this->repo->outputRow($id) ?? throw ApiException::notFound('output', $id);
        $fr = $context->translator->lang() === 'fr';
        $b = $this->outputs->batchFor($id, $context->translator->lang()) ?? throw ApiException::notFound('output', $id);
        $registry = $this->repo->registryLots(array_filter(array_map(fn($i) => $i['material_lot_id'], $b['ingredients'])));
        $materials = [];
        foreach ($b['ingredients'] as $i) {
            $reg = $i['material_lot_id'] !== null ? ($registry[$i['material_lot_id']] ?? null) : null;
            $materials[] = ['name' => $i['name'], 'packaging' => $i['packaging'],
                'state' => !$i['tracked'] ? 'untracked' : (($i['lot_number'] ?? '') !== '' ? 'ok' : ($i['missing_reason'] !== null ? 'reason' : 'missing')),
                'lot_number' => $i['lot_number'], 'best_before' => $i['best_before'], 'missing_reason' => $i['missing_reason'],
                'supplier' => $reg['supplier'] ?? null, 'received_on' => $reg['received_on'] ?? null];
        }
        foreach ($b['components'] as $c) {
            $materials[] = ['name' => $c['name'], 'packaging' => false, 'state' => $c['source'] !== null ? 'ok' : 'missing', 'lot_number' => $c['source']['lot_code'] ?? null,
                'best_before' => null, 'missing_reason' => null, 'supplier' => $c['producer']['name'], 'received_on' => isset($c['source']['created_at']) ? substr((string) $c['source']['created_at'], 0, 10) : null];
        }
        usort($materials, fn($a, $c) => [$a['state'] === 'untracked', $a['packaging']] <=> [$c['state'] === 'untracked', $c['packaging']]);
        $destinations = [];
        $cartons = ['total' => 0, 'shipped' => 0, 'pallet' => 0, 'stock' => 0];
        $from = $to = null;
        if ($o['id_final_product'] !== null) {
            [$from, $to] = $this->holds->labelDays(substr((string) $o['work_date'], 0, 10));
            foreach ($this->repo->cartonDestinations((int) $o['id_final_product'], $from, $to) as $d) {
                $n = (int) $d['cartons'];
                $st = (int) $d['status'];
                $kind = $st === 3 ? 'shipped' : ($st === 2 ? 'pallet' : 'stock');
                $cartons['total'] += $n;
                $cartons[$kind] += $n;
                $key = $kind === 'stock' ? 'stock' : $kind . '|' . ($d['id_customer'] ?? 'x') . '|' . ($d['delivery_date'] ?? '');
                $destinations[$key] ??= ['kind' => $kind, 'customer' => $kind === 'stock' ? null : ($d['customer'] ?: ($d['id_customer'] ? '#' . $d['id_customer'] : null)),
                    'delivery_date' => $kind === 'stock' ? null : ($d['delivery_date'] ? substr((string) $d['delivery_date'], 0, 10) : null),
                    'wdt_date' => $d['wdt_date'] ? substr((string) $d['wdt_date'], 0, 10) : null, 'wdt' => $d['wdt_num'] ?: null, 'cartons' => 0];
                $destinations[$key]['cartons'] += $n;
            }
        }
        $order = ['shipped' => 0, 'pallet' => 1, 'stock' => 2];
        $destinations = array_values($destinations);
        usort($destinations, fn($a, $c) => [$order[$a['kind']], (string) $a['delivery_date'], (string) $a['customer']] <=> [$order[$c['kind']], (string) $c['delivery_date'], (string) $c['customer']]);
        $control = $this->repo->lastControls([$id])[$id] ?? null;
        return [
            'id' => $id, 'product' => (string) $o['item_name'], 'product_id' => $o['id_final_product'] !== null ? (int) $o['id_final_product'] : null,
            'lot_code' => (string) $o['lot_code'], 'group' => $fr && trim((string) $o['group_name_fr']) !== '' ? $o['group_name_fr'] : (string) $o['group_name'],
            'work_date' => substr((string) $o['work_date'], 0, 10), 'started_at' => date('G:i', strtotime((string) $o['created_at'])),
            'quantity' => (float) $o['quantity'], 'unit' => $o['base_unit'], 'cancelled' => $o['cancelled_at'] !== null,
            'materials' => $materials, 'missing' => count(array_filter($materials, fn($m) => $m['state'] === 'missing')),
            'cartons' => $cartons, 'destinations' => $destinations, 'label_from' => $from, 'label_to' => $to,
            'control' => $control !== null ? ['at' => date('G:i', strtotime((string) $control['created_at'])), 'result' => $control['result']]
                : ($o['qc_status'] !== null ? ['at' => date('G:i', strtotime((string) $o['qc_at'])), 'result' => $o['qc_status'] === 'nc' ? 'nc' : 'ok', 'group' => true] : null),
            'hold' => $this->holds->forOutput($id),
        ];
    }

    /** « Wstrzymaj partię » (confirmation faite sur la tablette) : motif obligatoire. */
    public function hold(int $id, array $body, ApiContext $context): array
    {
        $this->round->assertManager($context);
        $o = $this->repo->outputRow($id) ?? throw ApiException::notFound('output', $id);
        $this->holds->hold($o, is_string($body['reason'] ?? null) ? $body['reason'] : '', $context);
        return $this->lot($id, $context);
    }

    /** Lever le blocage (décision Kierownik produkcji / Jakość), note obligatoire. */
    public function release(int $holdId, array $body, ApiContext $context): array
    {
        $this->round->assertManager($context);
        $h = $this->holds->release($holdId, is_string($body['note'] ?? null) ? $body['note'] : '', $context);
        return $h['output_id'] !== null ? $this->lot($h['output_id'], $context) : ['hold' => $h];
    }

    /**
     * PDF de la partie (gabarit des documents) : en-tête, matières, cartons, contrôle, blocage.
     * @return array{filename:string, content:string}
     */
    public function pdf(int $id, ApiContext $context): array
    {
        $d = $this->lot($id, $context);
        $dmy = fn(?string $x) => $x ? date('d.m.Y', strtotime($x)) : '—';
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('ERP');
        $pdf->SetTitle('Partia ' . $d['lot_code']);
        $pdf->brandPage('Śledzenie partii', ['ref' => $d['lot_code']]);
        $pdf->brandHeader('Śledzenie partii', [['Partia', $d['lot_code']], ['Produkcja', $dmy($d['work_date']) . ', ' . $d['started_at']], ['Grupa', $d['group']]],
            $d['product'] . ' · ' . self::qty($d['quantity']) . ' ' . $d['unit'] . ($d['cartons']['total'] ? ' · ' . $d['cartons']['total'] . ' kart.' : ''));
        $pdf->brandSection('Surowce (skąd)');
        $pdf->brandTable([['label' => 'Surowiec', 'w' => 3], ['label' => 'Partia', 'w' => 2], ['label' => 'Dostawca', 'w' => 2], ['label' => 'Przyjęte', 'w' => 1], ['label' => 'DLC', 'w' => 1]],
            array_map(fn($m) => [$m['name'], match ($m['state']) { 'ok' => (string) $m['lot_number'], 'reason' => 'brak : ' . $m['missing_reason'], 'untracked' => 'bez partii', default => 'BRAK SKANU' },
                (string) ($m['supplier'] ?? '—'), $dmy($m['received_on']), $dmy($m['best_before'])], $d['materials']), ['font' => 9, 'min_h' => 6.5]);
        $pdf->Ln(4);
        $pdf->brandSection('Kartony (dokąd)', $d['label_from'] ? 'etykiety ' . LotHoldService::days($d['label_from'], $d['label_to']) . ' (ten sam produkt i dzień etykiety — powiązanie prawdopodobne)' : '');
        if ($d['destinations']) {
            $pdf->brandTable([['label' => 'Dokąd', 'w' => 3], ['label' => 'Kartony', 'w' => 1], ['label' => 'Dostawa', 'w' => 1], ['label' => 'WDT', 'w' => 2]],
                array_map(fn($x) => [$x['kind'] === 'stock' ? 'Magazyn (na stanie)' : ((string) $x['customer'] . ($x['kind'] === 'pallet' ? ' (na palecie)' : '')), (string) $x['cartons'],
                    $dmy($x['delivery_date']), $x['wdt'] ? $x['wdt'] . ' · ' . $dmy($x['wdt_date']) : '—'], $d['destinations']), ['font' => 9, 'min_h' => 6.5]);
        } else {
            $pdf->brandText('Brak kartonów z etykietą tego produktu w tych dniach.');
        }
        $pdf->Ln(4);
        $pdf->brandSection('Kontrola i wstrzymanie');
        $lines = [];
        $lines[] = 'Kontrola jakości : ' . ($d['control'] ? $d['control']['at'] . ' — ' . ['ok' => 'dobrze', 'warn' => 'odchylenie zaakceptowane', 'nc' => 'niezgodność'][$d['control']['result']] : 'brak');
        $lines[] = 'Wstrzymanie : ' . ($d['hold'] ? 'partia wstrzymana od ' . date('d.m.Y H:i', strtotime((string) $d['hold']['held_at'])) . ' — ' . $d['hold']['reason'] : 'nie');
        foreach ($lines as $l) $pdf->brandText($l);
        $name = 'partia-' . preg_replace('/[^A-Za-z0-9-]/', '', $d['lot_code']) . '.pdf';
        return ['filename' => $name, 'content' => $pdf->Output($name, 'S')];
    }

    private static function qty(float $q): string
    {
        return number_format($q, fmod($q, 1.0) == 0.0 ? 0 : 1, ',', ' ');
    }
}
