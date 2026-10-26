<?php
declare(strict_types=1);

namespace App\Repositories\Product;

use App\Repositories\Workshop\WorkshopRepository;

/** Karta produktu, krok « Ceny i zgodność » : cena standardowa, ceny klientów, opakowanie sprzedażowe PPWR. */
final class ProductCommercialRepository extends WorkshopRepository
{
    protected string $table = 'final_product';
    protected array $writable = [];

    public function product(int $id): ?array
    {
        return $this->all('SELECT id, name, standard_price, ppc, active FROM final_product WHERE id = ?', [$id])[0] ?? null;
    }

    /** Aktywni klienci z ceną produktu w cenniku (NULL = brak) ; franczyza ma cennik w systemie franczyzy. */
    public function clientPrices(int $productId): array
    {
        return $this->all(
            "SELECT c.id, COALESCE(NULLIF(TRIM(c.company), ''), TRIM(CONCAT(c.name, ' ', c.surname))) AS name,
                    c.franchisee_uid IS NOT NULL AS franchise, p.price, p.available
             FROM customer c LEFT JOIN final_product_customer_price p ON p.id_customer = c.id AND p.id_final_product = ?
             WHERE c.active = 1 ORDER BY name",
            [$productId]
        );
    }

    /** Dopisuje cenę klientom, którzy jej jeszcze nie mają (unikalny klucz klient × produkt) ; zwraca liczbę nowych cen. */
    public function addClientPrices(int $productId, array $clientIds, float $price): int
    {
        $insert = $this->db->prepare('INSERT IGNORE INTO final_product_customer_price (id_customer, id_final_product, price, available) VALUES (?, ?, ?, 1)');
        $count = 0;
        foreach ($clientIds as $clientId) {
            $insert->execute([$clientId, $productId, $price]);
            $count += $insert->rowCount();
        }
        return $count;
    }

    public function ppwr(int $productId): ?array
    {
        return $this->all('SELECT verification_status, sales_unit_name, id_outer_raw_material FROM final_product_ppwr_sales_packaging WHERE id_final_product = ?', [$productId])[0] ?? null;
    }
}
