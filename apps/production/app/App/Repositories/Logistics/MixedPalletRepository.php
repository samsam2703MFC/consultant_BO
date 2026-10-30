<?php
declare(strict_types=1);

namespace App\Repositories\Logistics;

use App\Repositories\Workshop\WorkshopRepository;

/** Palety mieszane zatwierdzone [215] : zapis (paleta + produkty od dołu) ; odczyt dnia — LogisticsRepository::mixedPallets. */
final class MixedPalletRepository extends WorkshopRepository
{
    protected string $table = 'n_mixed_pallet';

    /** @return ?array{id:int, ship_date:string, id_customer:int} */
    public function head(int $id): ?array
    {
        $r = $this->all('SELECT id, ship_date, id_customer FROM n_mixed_pallet WHERE id = ?', [$id])[0] ?? null;
        return $r ? ['id' => (int) $r['id'], 'ship_date' => (string) $r['ship_date'], 'id_customer' => (int) $r['id_customer']] : null;
    }

    /**
     * Nowa albo poprawiona paleta (produkty zastąpione) w jednej transakcji.
     * @param list<array{product_id:int, cartons:int, layers:int, per_layer:int}> $items od dołu
     */
    public function save(?int $id, string $date, int $customerId, string $pallet, int $heightMm, float $kg, array $items, ?int $userId): int
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            if ($id === null) {
                $this->db->prepare('INSERT INTO n_mixed_pallet (ship_date, id_customer, pallet, height_mm, kg, confirmed_by) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$date, $customerId, $pallet, $heightMm, $kg, $userId]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $this->db->prepare('UPDATE n_mixed_pallet SET pallet = ?, height_mm = ?, kg = ?, confirmed_by = ?, confirmed_at = NOW() WHERE id = ?')
                    ->execute([$pallet, $heightMm, $kg, $userId, $id]);
                $this->db->prepare('DELETE FROM n_mixed_pallet_item WHERE id_mixed_pallet = ?')->execute([$id]);
            }
            $ins = $this->db->prepare('INSERT INTO n_mixed_pallet_item (id_mixed_pallet, position, id_final_product, cartons, layers, per_layer) VALUES (?, ?, ?, ?, ?, ?)');
            foreach (array_values($items) as $i => $it) $ins->execute([$id, $i + 1, $it['product_id'], $it['cartons'], $it['layers'], $it['per_layer']]);
            if ($own) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
    }

    /** Paleta i jej produkty (także bez kaskady klucza obcego, np. kopia struktury). */
    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM n_mixed_pallet_item WHERE id_mixed_pallet = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM n_mixed_pallet WHERE id = ?')->execute([$id]);
    }
}
