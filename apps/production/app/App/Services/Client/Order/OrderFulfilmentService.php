<?php
declare(strict_types=1);

namespace App\Services\Client\Order;

use App\Domain\Client\Order\CustomerOrderStatus;
use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderChangelogRepository;
use App\Repositories\Client\Order\OrderRepository;

/**
 * Boucle d'expédition (audit 2, P1) : une seule règle pour le statut des commandes après les documents.
 *  - Wyślij dzień (documents WZ / CMR / WDT du client) → commandes du jour « W transporcie » ;
 *  - avis à la boutique du franchisé par la file existante (webhook_outbox_shipments, Stan wysyłek › Ponów) ;
 *  - livraison confirmée sur la tablette chauffeur → « Zrealizowane ».
 * Chaque changement est noté dans le journal de la commande (customer_order_changelog, source system).
 */
class OrderFulfilmentService
{
    public function __construct(
        private OrderRepository $orders,
        private OrderChangelogRepository $changelog,
        private InTransitPusher $inTransit,
    ) {
    }

    /** Commandes « Oczekujące » de cette WDT → « W transporcie » ; à appeler dans la transaction de création. @return list<int> */
    public function markShippedByWdt(int $wdtId, string $reason): array
    {
        $ids = $this->orders->pendingIdsByWdt($wdtId);
        $this->apply($ids, CustomerOrderStatus::IN_TRANSIT, [CustomerOrderStatus::PENDING], $reason);
        return $ids;
    }

    /** « Oznacz jako wysłane » (liste des commandes) : une commande, même règle. */
    public function markShipped(int $orderId, string $reason): bool
    {
        return $this->apply([$orderId], CustomerOrderStatus::IN_TRANSIT, [CustomerOrderStatus::PENDING], $reason) > 0;
    }

    /** Livraison confirmée par le chauffeur (date d'expédition + client) → « Zrealizowane ». @return list<int> */
    public function markDelivered(string $shipDate, int $customerId, string $reason): array
    {
        $ids = $this->orders->deliverableIds($shipDate, $customerId);
        $this->apply($ids, CustomerOrderStatus::COMPLETED, [CustomerOrderStatus::PENDING, CustomerOrderStatus::IN_TRANSIT], $reason);
        return $ids;
    }

    /**
     * Avis « wysłane » aux boutiques des clients intégrés, mis en file (rien n'est envoyé ici). Un client sans boutique
     * est ignoré ; une boutique sans identifiants valables est signalée (sans bloquer l'expédition).
     * @param list<int> $orderIds
     * @return list<array{id_order:int, status:string, code:?string}> status : queued | not_integrated | error
     */
    public function queueShopNotices(array $orderIds): array
    {
        $out = [];
        foreach (array_values(array_unique(array_map('intval', $orderIds))) as $id) {
            try {
                $this->inTransit->enqueueInTransit($id);
                $out[] = ['id_order' => $id, 'status' => 'queued', 'code' => null];
            } catch (CustomException $e) {
                $code = $e->getMessage();
                $out[] = ['id_order' => $id, 'status' => $code === 'CLIENT_NOT_INTEGRATED' ? 'not_integrated' : 'error', 'code' => $code];
            } catch (\Throwable $e) {
                $out[] = ['id_order' => $id, 'status' => 'error', 'code' => 'SHOP_NOTICE_FAILED'];
            }
        }
        return $out;
    }

    private function apply(array $ids, int $status, array $from, string $reason): int
    {
        if ($ids === []) return 0;
        $changed = $this->orders->setStatus($ids, $status, $from);
        if ($changed === 0) return 0;
        $userId = function_exists('user') ? (user('id') ?: null) : null;
        foreach ($ids as $id) {
            $this->changelog->log([
                'id_order' => (int) $id,
                'id_user' => $userId,
                'action_type' => 'update',
                'source' => 'system',
                'changed_fields' => json_encode(['after' => ['status' => $status], 'reason' => $reason], JSON_UNESCAPED_UNICODE),
            ]);
        }
        return $changed;
    }
}
