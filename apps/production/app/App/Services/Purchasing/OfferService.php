<?php
namespace App\Services\Purchasing;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Exceptions\CustomException;
use App\Repositories\Purchasing\MaterialCostRepository;
use App\Repositories\Purchasing\OfferRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Material\MaterialPriceService;

/**
 * Offres de prix des fournisseurs. Les quantités saisies sont dans l'unité affichée (kg, l, szt) ;
 * le prix par unité de base sert aux comparaisons. Appliquer une offre passe par
 * MaterialPriceService : prix courant, historique et coût des recettes, comme une saisie de prix.
 */
final class OfferService
{
    public const SOURCES = ['manual', 'tender', 'email', 'price_list'];

    public function __construct(
        private OfferRepository $offers,
        private MaterialCostRepository $costs,
        private MaterialPriceService $prices,
        private ParamRepository $params,
    ) {
    }

    public function list(?int $materialId = null, ?int $supplierId = null, ?string $status = null, ?int $tenderId = null): array
    {
        return array_map(function ($o) {
            $unit = CostOptimizationService::displayUnit($o['unit']);
            $current = (float) $o['current_price'];
            $price = (float) $o['price_per_base'];
            return $o + [
                'display_unit' => $unit['label'],
                'display_price' => $price * $unit['factor'],
                'display_current' => $current * $unit['factor'],
                'display_pack_size' => (float) $o['pack_size_base'] / $unit['factor'],
                'diff_pct' => $current > 0 ? ($price - $current) / $current * 100 : null,
            ];
        }, $this->offers->list($materialId, $supplierId, $status, $tenderId));
    }

    public function add(array $input, ?int $userId, ?int $tenderId = null): int
    {
        $v = (new Validator($input))->only(['supplier_id', 'material_id', 'pack_price', 'pack_size', 'valid_from', 'valid_to', 'min_order_qty', 'lead_time_days', 'source', 'notes'])
            ->integer('supplier_id', true, 1)
            ->integer('material_id', true, 1)
            ->decimal('pack_price', true, 0, null, false, true)
            ->decimal('pack_size', true, 0, null, false, true)
            ->date('valid_from', false, true)
            ->date('valid_to', false, true)
            ->decimal('min_order_qty', false, 0, null, true)
            ->integer('lead_time_days', false, 0, 365, true)
            ->choice('source', self::SOURCES, false)
            ->string('notes', 500, false, true);
        if ($v->value('supplier_id') && !$this->offers->supplierExists($v->value('supplier_id'))) {
            $v->add('supplier_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('supplier_id')]);
        }
        $material = $v->value('material_id') ? $this->costs->material($v->value('material_id')) : null;
        if ($v->value('material_id') && $material === null) {
            $v->add('material_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('material_id')]);
        }
        $from = $v->value('valid_from') ?? date('Y-m-d');
        $to = $v->value('valid_to') ?? date('Y-m-d', strtotime($from . ' +' . (int) $this->params->get('purchasing.offer_validity_days', '90') . ' days'));
        if ($to < $from) $v->add('valid_to', 'DATE_ORDER');
        $v->throwIfInvalid();

        $factor = CostOptimizationService::displayUnit($material['unit'])['factor'];
        $packSizeBase = $v->value('pack_size') * $factor;
        $id = $this->offers->insert([
            'id_supplier' => $v->value('supplier_id'),
            'id_raw_material' => $v->value('material_id'),
            'pack_price' => $v->value('pack_price'),
            'pack_size_base' => $packSizeBase,
            'price_per_base' => $v->value('pack_price') / $packSizeBase,
            'valid_from' => $from,
            'valid_to' => $to,
            'min_order_qty' => $v->value('min_order_qty'),
            'lead_time_days' => $v->value('lead_time_days'),
            'source' => $tenderId !== null ? 'tender' : ($v->value('source') ?? 'manual'),
            'id_tender' => $tenderId,
            'status' => 'received',
            'notes' => $v->value('notes'),
            'created_by' => $userId,
        ]);
        return $id;
    }

    public function reject(int $id): void
    {
        $this->load($id);
        $this->offers->setStatus($id, 'rejected');
    }

    /** Applique l'offre comme prix courant de la matière (et fournisseur courant). */
    public function apply(int $id): void
    {
        $offer = $this->load($id);
        if ($offer['status'] !== 'received') {
            throw new CustomException('Ta oferta została już rozpatrzona.', 'errors', 409);
        }
        $material = $this->costs->material((int) $offer['id_raw_material']) ?? throw new CustomException('Nie znaleziono surowca.', 'errors', 404);
        $ok = $this->prices->update((int) $offer['id_raw_material'], [
            'id_raw_material' => (int) $offer['id_raw_material'],
            'id_current_supplier' => (int) $offer['id_supplier'],
            'package_size_in_base_unit' => (int) round((float) $offer['pack_size_base']),
            'id_unit' => (int) $material['id_unit'],
            'pack_price' => (float) $offer['pack_price'],
        ]);
        if (!$ok) {
            throw new CustomException('Nie udało się zastosować ceny.', 'errors', 500);
        }
        $this->offers->setStatus($id, 'accepted');
    }

    private function load(int $id): array
    {
        return $this->offers->find($id) ?? throw new CustomException('Nie znaleziono oferty.', 'errors', 404);
    }
}
