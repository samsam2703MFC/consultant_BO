<?php
declare(strict_types=1);

namespace App\Repositories\Product\Price;

use App\Repositories\Workshop\WorkshopRepository;

/** Cena standardowa produktu, ceny klientów idące za nią i historia zmian (tfb_product_price_change). */
final class ProductPriceChangeRepository extends WorkshopRepository
{
    protected string $table = 'tfb_product_price_change';

    public function standardPrice(int $productId): ?float
    {
        $stmt = $this->db->prepare('SELECT standard_price FROM final_product WHERE id = ?');
        $stmt->execute([$productId]);
        $price = $stmt->fetchColumn();
        return $price === false ? null : (float) $price;
    }

    /**
     * Nowa cena standardowa ; klienci z ceną równą dotychczasowej standardowej dostają nową,
     * pozostali (cena wynegocjowana) zostają bez zmian. Każda zmiana zapisana w historii.
     * @return array{followers: int, kept: int}
     */
    public function changeStandardPrice(int $productId, float $old, float $new, string $source, ?int $userId): array
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id_customer, price = ? AS follows FROM final_product_customer_price WHERE id_final_product = ? FOR UPDATE');
            $stmt->execute([number_format($old, 2, '.', ''), $productId]);
            $followers = [];
            $kept = 0;
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if ((int) $row['follows']) $followers[] = (int) $row['id_customer'];
                else $kept++;
            }
            $this->db->prepare('UPDATE final_product SET standard_price = ? WHERE id = ?')->execute([$new, $productId]);
            $now = date('Y-m-d H:i:s');
            $log = $this->db->prepare('INSERT INTO tfb_product_price_change (id_final_product, id_customer, old_price, new_price, source, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $log->execute([$productId, null, $old, $new, $source, $userId, $now]);
            if ($followers) {
                $in = implode(',', array_fill(0, count($followers), '?'));
                $this->db->prepare("UPDATE final_product_customer_price SET price = ? WHERE id_final_product = ? AND id_customer IN ($in)")
                    ->execute(array_merge([$new, $productId], $followers));
                foreach ($followers as $customerId) $log->execute([$productId, $customerId, $old, $new, $source, $userId, $now]);
            }
            if ($own) $this->db->commit();
            return ['followers' => count($followers), 'kept' => $kept];
        } catch (\Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
    }
}
