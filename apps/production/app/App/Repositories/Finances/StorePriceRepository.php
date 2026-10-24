<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Ceny w sklepach franczyzy (API panelu) : konto API (zaszyfrowane), produkty sklepów z medianą cen,
 * ceny według sklepu, powiązania produkt fabryki ↔ produkt sklepu, dziennik synchronizacji.
 */
final class StorePriceRepository extends WorkshopRepository
{
    protected string $table = 'n_store_product';

    public function config(): array
    {
        return $this->all('SELECT * FROM n_store_price_config WHERE id = 1')[0]
            ?? ['id' => 1, 'base_url' => 'https://atelierby.tfbuddy.com/api/v1', 'phone' => null, 'password_enc' => null, 'token_enc' => null,
                'token_exp' => null, 'multiplier' => '2.50', 'limit_coef' => '2.20', 'reserve_coef' => '3.20', 'source' => null, 'updated_by' => null, 'updated_at' => null];
    }

    /** Zapis wybranych pól konfiguracji (konto, token, progi). */
    public function saveConfig(array $data): void
    {
        $allowed = ['base_url', 'phone', 'password_enc', 'token_enc', 'token_exp', 'multiplier', 'limit_coef', 'reserve_coef', 'source', 'updated_by'];
        $data = array_intersect_key($data, array_flip($allowed));
        if (!$data) return;
        $this->db->exec('INSERT IGNORE INTO n_store_price_config (id) VALUES (1)');
        $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));
        $this->db->prepare("UPDATE n_store_price_config SET {$set} WHERE id = 1")->execute($data);
    }

    /** @return list<array> produkty sklepów (bez cen według sklepu) */
    public function storeProducts(): array
    {
        return $this->all('SELECT * FROM n_store_product ORDER BY name');
    }

    public function storeProductByKey(string $key): ?array
    {
        return $this->all('SELECT * FROM n_store_product WHERE name_key = ?', [$key])[0] ?? null;
    }

    /** @return list<array> ceny według sklepu */
    public function shopPrices(int $storeProductId): array
    {
        return $this->all('SELECT shop_id, shop_name, price_gross, price_net FROM n_store_product_shop WHERE id_store_product = ? ORDER BY shop_name, shop_id', [$storeProductId]);
    }

    /**
     * Zastępuje produkty sklepów wynikiem synchronizacji (w transakcji) : nowe i zmienione nadpisane, zniknięte
     * z panelu usunięte (z cenami według sklepu). Powiązania ręczne zostają (klucz nazwy).
     *
     * @param array<string, array{name: string, category: ?string, price_net: ?float, price_gross: ?float, min: ?float, max: ?float, vat: ?float, shops: list<array{id: int, name: ?string, gross: ?float, net: ?float}>}> $products
     */
    public function replaceStoreProducts(array $products, string $at): void
    {
        if (!$products) throw new \InvalidArgumentException('Brak produktów sklepów do zapisania.');
        $this->db->beginTransaction();
        try {
            $up = $this->db->prepare('INSERT INTO n_store_product (name_key, name, category, shops, price_net, price_gross, price_gross_min, price_gross_max, vat, synced_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), shops = VALUES(shops), price_net = VALUES(price_net),
                    price_gross = VALUES(price_gross), price_gross_min = VALUES(price_gross_min), price_gross_max = VALUES(price_gross_max), vat = VALUES(vat), synced_at = VALUES(synced_at)');
            $id = $this->db->prepare('SELECT id FROM n_store_product WHERE name_key = ?');
            $del = $this->db->prepare('DELETE FROM n_store_product_shop WHERE id_store_product = ?');
            $shop = $this->db->prepare('INSERT INTO n_store_product_shop (id_store_product, shop_id, shop_name, price_gross, price_net) VALUES (?, ?, ?, ?, ?)');
            foreach ($products as $key => $p) {
                $up->execute([$key, mb_substr($p['name'], 0, 255), $p['category'] !== null ? mb_substr($p['category'], 0, 255) : null, count($p['shops']),
                    $p['price_net'], $p['price_gross'], $p['min'], $p['max'], $p['vat'], $at]);
                $id->execute([$key]);
                $pid = (int) $id->fetchColumn();
                $del->execute([$pid]);
                foreach ($p['shops'] as $s) $shop->execute([$pid, $s['id'], $s['name'] !== null ? mb_substr($s['name'], 0, 191) : null, $s['gross'], $s['net']]);
            }
            $keys = array_keys($products);
            $this->db->prepare('DELETE FROM n_store_product WHERE name_key NOT IN (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_map('strval', $keys));
            $this->db->exec('DELETE s FROM n_store_product_shop s LEFT JOIN n_store_product p ON p.id = s.id_store_product WHERE p.id IS NULL');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<int, ?string> id produktu fabryki => klucz produktu sklepu (null = nie sprzedawany w sklepach) */
    public function links(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_final_product, store_key FROM n_store_product_link') as $r) $out[(int) $r['id_final_product']] = $r['store_key'];
        return $out;
    }

    public function setLink(int $productId, ?string $storeKey, ?int $by): void
    {
        $this->db->prepare('INSERT INTO n_store_product_link (id_final_product, store_key, linked_by) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE store_key = VALUES(store_key), linked_by = VALUES(linked_by)')->execute([$productId, $storeKey, $by]);
    }

    public function deleteLink(int $productId): void
    {
        $this->db->prepare('DELETE FROM n_store_product_link WHERE id_final_product = ?')->execute([$productId]);
    }

    /**
     * Produkty fabryki : cena kartonu (EUR netto), sztuk w kartonie, kategoria.
     *
     * @param list<int>|null $ids null = wszystkie aktywne
     */
    public function factoryProducts(?array $ids = null): array
    {
        $sql = 'SELECT fp.id, fp.name, fp.standard_price, fp.ppc, fp.id_category, c.name AS category
                FROM final_product fp LEFT JOIN category_product c ON c.id = fp.id_category';
        if ($ids === null) return $this->all($sql . ' WHERE fp.active = 1 ORDER BY fp.name');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        return $this->all($sql . ' WHERE fp.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    }

    public function productExists(int $productId): bool
    {
        return (bool) $this->all('SELECT 1 FROM final_product WHERE id = ?', [$productId]);
    }

    public function syncStart(?int $by, string $at): int
    {
        $this->db->prepare('INSERT INTO n_store_price_sync (started_at, run_by) VALUES (?, ?)')->execute([$at, $by]);
        return (int) $this->db->lastInsertId();
    }

    public function syncFinish(int $id, string $status, int $shops, int $failed, int $products, ?string $message): void
    {
        $this->db->prepare('UPDATE n_store_price_sync SET finished_at = ?, status = ?, shops = ?, shops_failed = ?, products = ?, message = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $status, $shops, $failed, $products, $message !== null ? mb_substr($message, 0, 500) : null, $id]);
    }

    public function lastSync(): ?array
    {
        return $this->all("SELECT s.*, COALESCE(NULLIF(TRIM(u.displayed_name), ''), TRIM(CONCAT(u.name, ' ', u.surname)), u.login) AS run_by_name
                           FROM n_store_price_sync s LEFT JOIN user u ON u.id = s.run_by ORDER BY s.id DESC LIMIT 1")[0] ?? null;
    }

    public function lastSuccessfulSync(): ?array
    {
        return $this->all("SELECT * FROM n_store_price_sync WHERE status IN ('ok', 'partial') ORDER BY id DESC LIMIT 1")[0] ?? null;
    }
}
