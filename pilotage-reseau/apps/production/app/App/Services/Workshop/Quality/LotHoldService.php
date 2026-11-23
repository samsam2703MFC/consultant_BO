<?php
declare(strict_types=1);

namespace App\Services\Workshop\Quality;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\TransactionManager;
use App\Repositories\Workshop\LotHoldRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * [416] « Wstrzymaj partię » (makieta hali K) : blocage d'une partie jusqu'à la décision Jakość. Sûr par construction :
 * le blocage est une ligne n_qc_hold — aucun statut carton_stock, aucune formule de stock ne change. La partie bloquée =
 * produit + jours d'étiquette des cartons (jour de production → + trace.label_days), comme la traçabilité de l'ERP.
 * Lu par la palettisation (tablette d'emballage : refus sans confirmation), le chargement (tablette chauffeur : refus) et
 * la tablette qualité (levée par Kierownik produkcji / Jakość, avec une note).
 */
final class LotHoldService
{
    public function __construct(private LotHoldRepository $repo, private ParamRepository $params, private TransactionManager $tx, private AuditTrail $audit)
    {
    }

    /** Jours d'étiquette des cartons d'une partie : [jour de production, + trace.label_days]. @return array{0:string, 1:string} */
    public function labelDays(string $workDate): array
    {
        $days = max(0, min(7, (int) $this->params->get('trace.label_days', '1')));
        return [$workDate, date('Y-m-d', strtotime($workDate . " +{$days} days"))];
    }

    public function forOutput(int $outputId): ?array
    {
        $h = $this->repo->activeForOutput($outputId);
        return $h === null ? null : self::row($h);
    }

    /** @return array<int, array> partie => blocage actif (liste du tour) */
    public function forOutputs(array $outputIds): array
    {
        return array_map(fn($h) => self::row($h), $this->repo->activeByOutputs($outputIds));
    }

    /**
     * Bloquer une partie (produit fini) : motif obligatoire ; déjà bloquée → le blocage existant.
     * @param array $o partie (QualityRoundRepository::outputRow)
     */
    public function hold(array $o, string $reason, ApiContext $context): array
    {
        $reason = trim($reason);
        if ($o['id_final_product'] === null) throw new ApiException('QC_HOLD_NO_PRODUCT', 422);
        if (mb_strlen($reason) < 3) throw ApiException::validation([['field' => 'reason', 'code' => 'REQUIRED', 'params' => []]]);
        if (($h = $this->repo->activeForOutput((int) $o['id'])) !== null) return self::row($h);
        [$from, $to] = $this->labelDays(substr((string) $o['work_date'], 0, 10));
        $id = $this->tx->execute(function () use ($o, $reason, $from, $to, $context) {
            $id = $this->repo->insert(['id_output' => (int) $o['id'], 'lot_code' => $o['lot_code'], 'id_final_product' => (int) $o['id_final_product'],
                'label_from' => $from, 'label_to' => $to, 'reason' => mb_substr($reason, 0, 500), 'held_at' => date('Y-m-d H:i:s'),
                'held_by_employee' => $context->employeeId, 'held_device' => $context->deviceId]);
            $this->audit->record($context, 'hold', 'lot_hold', $id, null, ['output' => (int) $o['id'], 'product' => (int) $o['id_final_product'], 'from' => $from, 'to' => $to]);
            return $id;
        });
        return self::row($this->repo->hold($id));
    }

    /** Lever le blocage (décision Jakość) : note obligatoire. */
    public function release(int $holdId, string $note, ApiContext $context): array
    {
        $h = $this->repo->hold($holdId) ?? throw ApiException::notFound('lot_hold', $holdId);
        if ($h['released_at'] !== null) throw new ApiException('QC_HOLD_RELEASED', 409);
        $note = trim($note);
        if (mb_strlen($note) < 3) throw ApiException::validation([['field' => 'note', 'code' => 'REQUIRED', 'params' => []]]);
        $this->tx->execute(function () use ($holdId, $note, $context, $h) {
            $this->repo->release($holdId, $context->employeeId, mb_substr($note, 0, 500));
            $this->audit->record($context, 'release', 'lot_hold', $holdId, ['released' => false], ['released' => true, 'output' => $h['id_output']]);
        });
        return self::row($this->repo->hold($holdId));
    }

    /** Blocages actifs couvrant le carton scanné (produit + jour d'étiquette). */
    public function forLabel(int $productId, string $day): array
    {
        return array_map(fn($h) => self::row($h), $this->repo->activeForLabel($productId, $day));
    }

    /**
     * Palettisation (tablette d'emballage) : par produit, les parties bloquées qui ont encore des cartons au magasin ou sur
     * palette. @return array<int, list<array{hold_id:int, lot_code:?string, product:?string, days:string, cartons:int}>>
     */
    public function heldStock(array $productIds): array
    {
        $out = [];
        foreach ($this->repo->heldStock($productIds) as $pid => $list) {
            foreach ($list as $h) {
                if ($h['cartons'] <= 0) continue;
                $out[$pid][] = ['hold_id' => $h['hold_id'], 'lot_code' => $h['lot_code'], 'product' => $h['product'], 'days' => self::days($h['label_from'], $h['label_to']),
                    'cartons' => $h['cartons']];
            }
        }
        return $out;
    }

    /**
     * Chargement (tablette chauffeur) : cartons de parties bloquées sur la palette de la place (ou, sans palette nommée, sur
     * les palettes du client pour ce jour de livraison). @return list<array{hold_id:int, lot_code:?string, product:?string, id_palet:int, cartons:int}>
     */
    public function onPallet(?int $palletId, int $customerId, string $deliveryDate): array
    {
        $pallets = $palletId !== null ? [$palletId] : $this->repo->customerPallets($customerId, $deliveryDate);
        return array_map(fn($r) => ['hold_id' => (int) $r['hold_id'], 'lot_code' => $r['lot_code'], 'product' => $r['product'], 'id_palet' => (int) $r['id_palet'],
            'cartons' => (int) $r['cartons']], $this->repo->heldOnPallets($pallets));
    }

    private static function row(array $h): array
    {
        return ['id' => (int) $h['id'], 'output_id' => $h['id_output'] !== null ? (int) $h['id_output'] : null, 'lot_code' => $h['lot_code'],
            'product_id' => (int) $h['id_final_product'], 'product' => $h['product'] ?? null, 'label_from' => (string) $h['label_from'], 'label_to' => (string) $h['label_to'],
            'days' => self::days((string) $h['label_from'], (string) $h['label_to']), 'reason' => $h['reason'], 'held_at' => $h['held_at'],
            'held_by' => trim((string) ($h['held_by_name'] ?? '')) ?: null, 'released_at' => $h['released_at'],
            'released_by' => trim((string) ($h['released_by_name'] ?? '')) ?: null, 'release_note' => $h['release_note'], 'active' => $h['released_at'] === null];
    }

    /** « 23.11 » ou « 23.11–24.11 ». */
    public static function days(string $from, string $to): string
    {
        $a = date('d.m', strtotime($from));
        $b = date('d.m', strtotime($to));
        return $a === $b ? $a : $a . '–' . $b;
    }
}
