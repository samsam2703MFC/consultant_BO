<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Repositories\Workshop\WorkshopRepository;

/** Cenniki klientów (final_product_customer_price) dla cen wg marży [212] i zapis podwyżek z historią. */
final class ClientPriceRepository extends WorkshopRepository
{
    protected string $table = 'final_product_customer_price';

    /** Aktywni klienci z cennikiem : id => nazwa (firma albo imię i nazwisko). @return array<int, string> */
    public function customers(): array
    {
        $rows = $this->all("SELECT c.id, COALESCE(NULLIF(TRIM(c.company), ''), TRIM(CONCAT(c.name, ' ', c.surname))) AS name
            FROM customer c WHERE c.active = 1
              AND EXISTS (SELECT 1 FROM final_product_customer_price p WHERE p.id_customer = c.id AND p.available = 1 AND p.price > 0)");
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (string) $r['name'] !== '' ? (string) $r['name'] : '#' . $r['id'];
        return $out;
    }

    /** Ceny dostępnych produktów aktywnych klientów. @return list<array{id_customer: int, id_product: int, price: float}> */
    public function prices(?int $customerId = null): array
    {
        $sql = 'SELECT p.id_customer, p.id_final_product AS id_product, p.price FROM final_product_customer_price p
            JOIN customer c ON c.id = p.id_customer AND c.active = 1
            WHERE p.available = 1 AND p.price > 0' . ($customerId !== null ? ' AND p.id_customer = ?' : '');
        return array_map(fn(array $r) => ['id_customer' => (int) $r['id_customer'], 'id_product' => (int) $r['id_product'], 'price' => (float) $r['price']],
            $this->all($sql, $customerId !== null ? [$customerId] : []));
    }

    /** Ostatnia podwyżka wg marży od $since : [klient][produkt] => data. @return array<int, array<int, string>> */
    public function lastRaises(string $source, string $since, ?int $customerId = null): array
    {
        $rows = $this->all('SELECT id_customer, id_final_product, MAX(changed_at) AS at FROM n_product_price_change
            WHERE source = ? AND id_customer IS NOT NULL AND changed_at >= ?' . ($customerId !== null ? ' AND id_customer = ?' : '') . '
            GROUP BY id_customer, id_final_product', $customerId !== null ? [$source, $since, $customerId] : [$source, $since]);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id_customer']][(int) $r['id_final_product']] = (string) $r['at'];
        return $out;
    }

    /**
     * Nowe ceny jednego klienta : tylko gdy cena w bazie jest nadal równa obecnej (nikt jej nie zmienił w międzyczasie)
     * i tylko w górę ; każda zmiana w historii cen. @param array<int, array{old: float, new: float}> $changes
     * @return list<int> produkty zmienione
     */
    public function raise(int $customerId, array $changes, string $source, ?int $userId): array
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare('UPDATE final_product_customer_price SET price = ? WHERE id_customer = ? AND id_final_product = ? AND price = ? AND available = 1');
            $log = $this->db->prepare('INSERT INTO n_product_price_change (id_final_product, id_customer, old_price, new_price, source, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            $done = [];
            foreach ($changes as $productId => $c) {
                if ($c['new'] <= $c['old']) continue;
                $upd->execute([number_format($c['new'], 2, '.', ''), $customerId, $productId, number_format($c['old'], 2, '.', '')]);
                if ($upd->rowCount() !== 1) continue;
                $log->execute([$productId, $customerId, $c['old'], $c['new'], $source, $userId, $now]);
                $done[] = (int) $productId;
            }
            if ($own) $this->db->commit();
            return $done;
        } catch (\Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
    }
}
