<?php
declare(strict_types=1);

namespace App\Repositories\Finances;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * Kreator cen [218] : stan faktur i przeliczeń, zmiany cen surowców, współczynnik surowców kategorii, publikacje cen
 * od daty (ceny standardowe i klientów, klienci : e-mail przed datą, strona franczyzy w dniu zmiany).
 */
final class PriceWizardRepository extends WorkshopRepository
{
    protected string $table = 'n_price_publication';

    public const SOURCE = 'price_wizard';

    /** Otwarte faktury surowców i ich pozycje jeszcze niesprawdzone (kreator faktur). @return array{invoices: int, todo: int} */
    public function openInvoices(): array
    {
        $r = $this->all("SELECT COUNT(DISTINCT i.id) AS invoices, COALESCE(SUM(l.id IS NOT NULL AND l.check_status IS NULL), 0) AS todo
            FROM n_inv i LEFT JOIN n_inv_line l ON l.id_inv = i.id WHERE i.kind = 'goods' AND i.status = 'open'")[0] ?? [];
        return ['invoices' => (int) ($r['invoices'] ?? 0), 'todo' => (int) ($r['todo'] ?? 0)];
    }

    /** Faktury z cenami zapisanymi do kartoteki w okresie (from, to]. */
    public function invoicesWithPrices(?string $from, string $to): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM n_inv WHERE prices_imported_at IS NOT NULL AND prices_imported_at <= ?' . ($from !== null ? ' AND prices_imported_at > ?' : '');
        return (int) ($this->all($sql, $from !== null ? [$to, $from] : [$to])[0]['n'] ?? 0);
    }

    /** Dwa ostatnie przeliczenia kosztów (najnowsze najpierw). @return list<array> */
    public function lastRuns(): array
    {
        return $this->all('SELECT r.run_at, r.recipes, r.products, r.changed, u.displayed_name AS by_name
            FROM n_margin_recalc_run r LEFT JOIN user u ON u.id = r.run_by ORDER BY r.id DESC LIMIT 2');
    }

    /**
     * Surowce, których cena (za jednostkę bazową) zmieniła się w okresie (from, to] : cena przed okresem i ostatnia w okresie.
     * @return array<int, array{id: int, name: string, old: ?float, new: float, pct: ?float, at: string}>
     */
    public function materialChanges(?string $from, string $to): array
    {
        $in = $from !== null ? 'timestamp > ? AND timestamp <= ?' : 'timestamp <= ?';
        $rows = $this->all("SELECT c.id_raw_material AS id, rm.name, c.pack_price / NULLIF(c.package_size_in_base_unit, 0) AS ppu, c.timestamp AS at
            FROM raw_material_price_changelog c JOIN raw_material rm ON rm.id = c.id_raw_material
            WHERE c.timestamp <= ? AND c.id_raw_material IN (SELECT DISTINCT id_raw_material FROM raw_material_price_changelog WHERE {$in})
            ORDER BY c.id_raw_material, c.timestamp", $from !== null ? [$to, $from, $to] : [$to, $to]);
        $out = [];
        foreach ($rows as $r) {
            if ($r['ppu'] === null) continue;
            $id = (int) $r['id'];
            $ppu = (float) $r['ppu'];
            $out[$id] ??= ['id' => $id, 'name' => (string) $r['name'], 'old' => null, 'new' => $ppu, 'pct' => null, 'at' => (string) $r['at']];
            if ($from !== null && $r['at'] <= $from) $out[$id]['old'] = $ppu;
            elseif ($out[$id]['old'] === null && $from === null) $out[$id]['old'] = $ppu;
            $out[$id]['new'] = $ppu;
            $out[$id]['at'] = (string) $r['at'];
        }
        foreach ($out as $id => $m) {
            $out[$id]['pct'] = $m['old'] !== null && $m['old'] > 0 ? round(($m['new'] - $m['old']) / $m['old'] * 100, 1) : null;
            if ($m['old'] !== null && abs($m['new'] - $m['old']) < 0.000001) unset($out[$id]);
        }
        return $out;
    }

    /** Surowce receptur produktów. @param list<int> $productIds @return array<int, list<int>> */
    public function ingredientsOf(array $productIds): array
    {
        if (!$productIds) return [];
        $in = implode(',', array_fill(0, count($productIds), '?'));
        $out = [];
        foreach ($this->all("SELECT DISTINCT fp.id, fri.material_id FROM final_product fp JOIN flattened_recipe_ingredient fri ON fri.recipe_id = fp.id_recipe
            WHERE fp.id IN ($in)", array_values($productIds)) as $r) {
            $out[(int) $r['id']][] = (int) $r['material_id'];
        }
        return $out;
    }

    /** @return array<int, ?float> kategoria => współczynnik surowców (NULL = domyślny) */
    public function rules(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_category, material_coef FROM n_category_price_rule') as $r) {
            $out[(int) $r['id_category']] = $r['material_coef'] !== null ? (float) $r['material_coef'] : null;
        }
        return $out;
    }

    /** Marża bazowa (category_product) i współczynnik surowców kategorii. */
    public function saveRule(int $categoryId, float $margin, ?float $coef, ?int $userId): void
    {
        $this->db->prepare('UPDATE category_product SET target_margin_percentage = ? WHERE id = ?')->execute([$margin, $categoryId]);
        $this->db->prepare('INSERT INTO n_category_price_rule (id_category, material_coef, updated_by) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE material_coef = VALUES(material_coef), updated_by = VALUES(updated_by)')->execute([$categoryId, $coef, $userId]);
    }

    public function categoryExists(int $categoryId): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM category_product WHERE id = ?', [$categoryId]);
    }

    /** Ostatnia zmiana ceny standardowej przez kreator od $since : produkt => data. @return array<int, string> */
    public function recentStandardChanges(string $since): array
    {
        $out = [];
        foreach ($this->all('SELECT id_final_product, MAX(changed_at) AS at FROM n_product_price_change
            WHERE source = ? AND id_customer IS NULL AND changed_at >= ? GROUP BY id_final_product', [self::SOURCE, $since]) as $r) {
            $out[(int) $r['id_final_product']] = (string) $r['at'];
        }
        return $out;
    }

    /** Produkty w zaplanowanych publikacjach : produkt => [id publikacji, data]. @return array<int, array{id: int, date: string}> */
    public function plannedProducts(): array
    {
        $out = [];
        foreach ($this->all("SELECT i.id_product, p.id, p.effective_date FROM n_price_publication_item i
            JOIN n_price_publication p ON p.id = i.id_publication WHERE p.status = 'planned' AND i.id_customer = 0") as $r) {
            $out[(int) $r['id_product']] = ['id' => (int) $r['id'], 'date' => (string) $r['effective_date']];
        }
        return $out;
    }

    /** Ceny standardowe produktów (aktywne). @param list<int> $ids @return array<int, array{price: float, name: string}> */
    public function standardPrices(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id, name, standard_price FROM final_product WHERE active = 1 AND id IN ($in)", array_values($ids)) as $r) {
            $out[(int) $r['id']] = ['price' => (float) $r['standard_price'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    /** Ceny aktywnych klientów dla produktów. @param list<int> $productIds @return list<array{id_customer: int, id_product: int, price: float}> */
    public function clientPrices(array $productIds): array
    {
        if (!$productIds) return [];
        $in = implode(',', array_fill(0, count($productIds), '?'));
        return array_map(fn(array $r) => ['id_customer' => (int) $r['id_customer'], 'id_product' => (int) $r['id_product'], 'price' => (float) $r['price']],
            $this->all("SELECT p.id_customer, p.id_final_product AS id_product, p.price FROM final_product_customer_price p
                JOIN customer c ON c.id = p.id_customer AND c.active = 1
                WHERE p.available = 1 AND p.price > 0 AND p.id_final_product IN ($in)", array_values($productIds)));
    }

    /** Klienci : nazwa, franczyza (uid), adresy. @param list<int> $ids @return array<int, array> */
    public function customers(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id, COALESCE(NULLIF(TRIM(company), ''), TRIM(CONCAT(name, ' ', surname))) AS label, company, name, surname,
                franchisee_uid, email_head, email_accountant, email_salesman, communication_lang
            FROM customer WHERE id IN ($in)", array_values($ids)) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /**
     * Nowa publikacja : ceny standardowe (id_customer 0), ceny klientów, klienci (e-mail, strona) w jednej transakcji.
     * @param list<array{id_product: int, id_customer: int, old: float, new: float, manual: bool}> $items
     * @param list<array{id_customer: int, site: bool, email: bool}> $clients
     */
    public function createPublication(array $pub, array $items, array $clients): int
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO n_price_publication (effective_date, status, notify_email, notice_days, products, client_prices, avg_pct, month_eur, created_by)
                VALUES (?, \'planned\', ?, ?, ?, ?, ?, ?, ?)')->execute([$pub['effective_date'], (int) $pub['notify_email'], $pub['notice_days'],
                $pub['products'], $pub['client_prices'], $pub['avg_pct'], $pub['month_eur'], $pub['created_by']]);
            $id = (int) $this->db->lastInsertId();
            $ins = $this->db->prepare('INSERT INTO n_price_publication_item (id_publication, id_product, id_customer, old_price, new_price, manual) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($items as $i) $ins->execute([$id, $i['id_product'], $i['id_customer'], $i['old'], $i['new'], (int) $i['manual']]);
            $cl = $this->db->prepare('INSERT INTO n_price_publication_client (id_publication, id_customer, site, email) VALUES (?, ?, ?, ?)');
            foreach ($clients as $c) $cl->execute([$id, $c['id_customer'], (int) $c['site'], (int) $c['email']]);
            if ($own) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
    }

    /** Ostatnie publikacje z liczbami klientów (e-maile, strona). @return list<array> */
    public function publications(int $limit = 20): array
    {
        return $this->all("SELECT p.*, u.displayed_name AS by_name, cu.displayed_name AS cancelled_name,
                (SELECT COUNT(*) FROM n_price_publication_client c WHERE c.id_publication = p.id) AS clients,
                (SELECT COUNT(*) FROM n_price_publication_client c WHERE c.id_publication = p.id AND c.email = 1) AS emails,
                (SELECT COUNT(*) FROM n_price_publication_client c WHERE c.id_publication = p.id AND c.email_status = 'queued') AS emails_queued,
                (SELECT COUNT(*) FROM n_price_publication_client c WHERE c.id_publication = p.id AND c.site = 1) AS sites,
                (SELECT COUNT(*) FROM n_price_publication_client c WHERE c.id_publication = p.id AND c.site_status = 'sent') AS sites_sent,
                (SELECT COUNT(*) FROM n_price_publication_client c WHERE c.id_publication = p.id AND c.site_status IN ('failed', 'no_site')) AS sites_failed,
                (SELECT COUNT(*) FROM n_price_publication_item i WHERE i.id_publication = p.id AND i.status = 'skipped') AS skipped
            FROM n_price_publication p LEFT JOIN user u ON u.id = p.created_by LEFT JOIN user cu ON cu.id = p.cancelled_by
            ORDER BY (p.status = 'planned') DESC, p.effective_date DESC, p.id DESC LIMIT " . max(1, $limit));
    }

    public function publication(int $id): ?array
    {
        return $this->all('SELECT * FROM n_price_publication WHERE id = ?', [$id])[0] ?? null;
    }

    /** @return list<array> */
    public function items(int $publicationId, ?int $customerId = null): array
    {
        $sql = 'SELECT i.*, fp.name FROM n_price_publication_item i JOIN final_product fp ON fp.id = i.id_product WHERE i.id_publication = ?'
            . ($customerId !== null ? ' AND i.id_customer = ?' : '') . ' ORDER BY i.id_customer, fp.name';
        return $this->all($sql, $customerId !== null ? [$publicationId, $customerId] : [$publicationId]);
    }

    /** @return list<array> klienci publikacji z nazwą */
    public function clients(int $publicationId): array
    {
        return $this->all("SELECT pc.*, COALESCE(NULLIF(TRIM(c.company), ''), TRIM(CONCAT(c.name, ' ', c.surname))) AS label
            FROM n_price_publication_client pc JOIN customer c ON c.id = pc.id_customer WHERE pc.id_publication = ? ORDER BY label", [$publicationId]);
    }

    /** E-maile do wysłania : publikacje zaplanowane, data − wyprzedzenie ≤ dziś. @return list<array> */
    public function dueNotices(string $today): array
    {
        return $this->all("SELECT pc.id_publication, pc.id_customer, p.effective_date FROM n_price_publication_client pc
            JOIN n_price_publication p ON p.id = pc.id_publication
            WHERE p.status = 'planned' AND p.notify_email = 1 AND pc.email = 1 AND pc.email_status IS NULL
              AND DATE_SUB(p.effective_date, INTERVAL p.notice_days DAY) <= ?
            ORDER BY p.effective_date, pc.id_publication, pc.id_customer LIMIT 200", [$today]);
    }

    /** Publikacje do zastosowania (data ≤ dziś). @return list<int> */
    public function duePublications(string $today): array
    {
        return array_map('intval', array_column($this->all("SELECT id FROM n_price_publication WHERE status = 'planned' AND effective_date <= ? ORDER BY effective_date, id", [$today]), 'id'));
    }

    /**
     * Zastosowanie publikacji : cena standardowa i ceny klientów zmieniane tylko, gdy w bazie jest nadal cena z planu
     * (inaczej pozycja « skipped ») ; każda zmiana w historii cen produktu (source price_wizard, autor publikacji).
     * @return array{applied: int, skipped: int, clients_ok: list<int>}|null null = już zastosowana albo anulowana
     */
    public function apply(int $publicationId): ?array
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM n_price_publication WHERE id = ? FOR UPDATE');
            $stmt->execute([$publicationId]);
            $pub = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$pub || $pub['status'] !== 'planned') {
                if ($own) $this->db->rollBack();
                return null;
            }
            $by = $pub['created_by'] !== null ? (int) $pub['created_by'] : null;
            $now = date('Y-m-d H:i:s');
            $log = $this->db->prepare('INSERT INTO n_product_price_change (id_final_product, id_customer, old_price, new_price, source, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $mark = $this->db->prepare('UPDATE n_price_publication_item SET status = ? WHERE id = ?');
            $std = $this->db->prepare('UPDATE final_product SET standard_price = ? WHERE id = ? AND ROUND(standard_price, 2) = ?');
            $cli = $this->db->prepare('UPDATE final_product_customer_price SET price = ? WHERE id_customer = ? AND id_final_product = ? AND ROUND(price, 2) = ? AND available = 1');
            $applied = 0;
            $skipped = 0;
            $clientSkipped = [];
            $clientSeen = [];
            foreach ($this->items($publicationId) as $i) {
                $old = number_format((float) $i['old_price'], 2, '.', '');
                $new = number_format((float) $i['new_price'], 2, '.', '');
                $customer = (int) $i['id_customer'];
                if ($customer === 0) {
                    $std->execute([$new, (int) $i['id_product'], $old]);
                    $ok = $std->rowCount() === 1;
                } else {
                    $clientSeen[$customer] = true;
                    $cli->execute([$new, $customer, (int) $i['id_product'], $old]);
                    $ok = $cli->rowCount() === 1;
                    if (!$ok) $clientSkipped[$customer] = true;
                }
                if ($ok) {
                    $log->execute([(int) $i['id_product'], $customer ?: null, $old, $new, self::SOURCE, $by, $now]);
                    $applied++;
                } else {
                    $skipped++;
                }
                $mark->execute([$ok ? 'applied' : 'skipped', (int) $i['id']]);
            }
            $this->db->prepare("UPDATE n_price_publication SET status = 'applied', applied_at = ? WHERE id = ?")->execute([$now, $publicationId]);
            if ($own) $this->db->commit();
            return ['applied' => $applied, 'skipped' => $skipped, 'clients_ok' => array_values(array_map('intval', array_keys(array_diff_key($clientSeen, $clientSkipped))))];
        } catch (\Throwable $e) {
            if ($own) $this->db->rollBack();
            throw $e;
        }
    }

    /** Strona franczyzy do wysłania : publikacje zastosowane, klient z flagą strony, jeszcze niewysłane. @return list<array> */
    public function pendingSites(): array
    {
        return $this->all("SELECT pc.id_publication, pc.id_customer, pc.site_attempts FROM n_price_publication_client pc
            JOIN n_price_publication p ON p.id = pc.id_publication
            WHERE p.status = 'applied' AND pc.site = 1 AND (pc.site_status IS NULL OR pc.site_status = 'retry')
            ORDER BY pc.id_publication, pc.id_customer LIMIT 100");
    }

    public function updateClient(int $publicationId, int $customerId, array $data): void
    {
        $allowed = ['email_status', 'email_note', 'email_at', 'id_message', 'site_status', 'site_note', 'site_at', 'site_attempts', 'site_version'];
        $data = array_intersect_key($data, array_flip($allowed));
        if (!$data) return;
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        $this->db->prepare("UPDATE n_price_publication_client SET $set WHERE id_publication = ? AND id_customer = ?")
            ->execute(array_merge(array_values($data), [$publicationId, $customerId]));
    }

    public function companyName(): string
    {
        return (string) ($this->all('SELECT wdt_company_name FROM erp_company_data ORDER BY id LIMIT 1')[0]['wdt_company_name'] ?? '');
    }

    public function pricelistVersion(int $customerId): ?int
    {
        $v = $this->all('SELECT pricelist_version FROM customer WHERE id = ?', [$customerId])[0]['pricelist_version'] ?? null;
        return $v !== null ? (int) $v : null;
    }

    /** Anulowanie zaplanowanej publikacji. */
    public function cancel(int $publicationId, ?int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE n_price_publication SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW() WHERE id = ? AND status = 'planned'");
        $stmt->execute([$userId, $publicationId]);
        $ok = $stmt->rowCount() === 1;
        if ($ok) $this->restoreBaselines($publicationId);
        return $ok;
    }

    // ── Ścieżka cen [351] : koszt surowców przy ostatnim cenniku ─────────────────────────────────────────────

    /** @param list<int> $ids @return array<int, array{food: float, set_at: string, id_publication: ?int}> */
    public function baselines(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->all("SELECT id_product, food_cost, set_at, id_publication FROM n_price_cost_baseline WHERE id_product IN ($in)", array_values($ids)) as $r) {
            $out[(int) $r['id_product']] = ['food' => (float) $r['food_cost'], 'set_at' => (string) $r['set_at'],
                'id_publication' => $r['id_publication'] !== null ? (int) $r['id_publication'] : null];
        }
        return $out;
    }

    /**
     * Pierwsza baza produktów bez bazy (raz : INSERT IGNORE — istniejąca baza nie zmienia się).
     * @param array<int, array{food: float, set_at: string}> $rows
     */
    public function initBaselines(array $rows): void
    {
        $ins = $this->db->prepare('INSERT IGNORE INTO n_price_cost_baseline (id_product, food_cost, set_at, id_publication) VALUES (?, ?, ?, NULL)');
        foreach ($rows as $id => $r) $ins->execute([(int) $id, round($r['food'], 4), $r['set_at']]);
    }

    /**
     * Po zaplanowaniu publikacji : baza opublikowanych produktów = koszt dziś (poprzednia zapamiętana do anulowania).
     * @param array<int, float> $foods produkt => koszt surowców kartonu (PLN)
     */
    public function setBaselines(array $foods, int $publicationId, string $at): void
    {
        $up = $this->db->prepare('INSERT INTO n_price_cost_baseline (id_product, food_cost, set_at, id_publication) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE prev_food_cost = food_cost, prev_set_at = set_at, prev_id_publication = id_publication,
                food_cost = VALUES(food_cost), set_at = VALUES(set_at), id_publication = VALUES(id_publication)');
        foreach ($foods as $id => $food) $up->execute([(int) $id, round($food, 4), $at, $publicationId]);
    }

    /** Anulowana publikacja : poprzednia baza wraca (bez poprzedniej — baza usunięta, zainicjuje się od nowa). */
    public function restoreBaselines(int $publicationId): void
    {
        $this->db->prepare('UPDATE n_price_cost_baseline SET food_cost = prev_food_cost, set_at = prev_set_at, id_publication = prev_id_publication,
            prev_food_cost = NULL, prev_set_at = NULL, prev_id_publication = NULL WHERE id_publication = ? AND prev_food_cost IS NOT NULL')->execute([$publicationId]);
        $this->db->prepare('DELETE FROM n_price_cost_baseline WHERE id_publication = ?')->execute([$publicationId]);
    }

    /** Przeliczenie kosztów poprzedzające daną chwilę (data kosztu « przed » z n_margin_recalc). */
    public function runBefore(string $at): ?string
    {
        $v = $this->all('SELECT MAX(run_at) AS at FROM n_margin_recalc_run WHERE run_at < ?', [$at])[0]['at'] ?? null;
        return $v !== null ? (string) $v : null;
    }

    /**
     * Ostatnia faktura z cenami zapisanymi do kartoteki po $from z pozycją surowca : surowiec => numer faktury.
     * @param list<int> $materialIds @return array<int, string>
     */
    public function invoiceOfMaterials(array $materialIds, string $from): array
    {
        if (!$materialIds) return [];
        $in = implode(',', array_fill(0, count($materialIds), '?'));
        $out = [];
        foreach ($this->all("SELECT l.id_raw_material AS id, i.number FROM n_inv i JOIN n_inv_line l ON l.id_inv = i.id
            WHERE i.prices_imported_at IS NOT NULL AND i.prices_imported_at > ? AND l.id_raw_material IN ($in) AND i.number IS NOT NULL
            ORDER BY i.prices_imported_at DESC, i.id DESC", array_merge([$from], array_values($materialIds))) as $r) {
            $out[(int) $r['id']] ??= (string) $r['number'];
        }
        return $out;
    }

    /** Publikacje utworzone w okresie [from, to) (nie anulowane) — najnowsza najpierw. @return list<array> */
    public function publicationsCreated(string $from, string $to): array
    {
        return $this->all("SELECT p.*, (SELECT COUNT(*) FROM n_price_publication_client c WHERE c.id_publication = p.id AND c.email = 1) AS emails,
                (SELECT COUNT(*) FROM n_price_publication_client c WHERE c.id_publication = p.id AND c.email = 1 AND c.email_status IS NOT NULL) AS emails_done
            FROM n_price_publication p WHERE p.created_at >= ? AND p.created_at < ? AND p.status <> 'cancelled' ORDER BY p.id DESC", [$from, $to]);
    }

    /** E-maile publikacji jeszcze niewysłane (przycisk « Wyślij teraz »). @return list<array{id_customer: int, effective_date: string}> */
    public function pendingNotices(int $publicationId): array
    {
        return array_map(fn($r) => ['id_customer' => (int) $r['id_customer'], 'effective_date' => (string) $r['effective_date']],
            $this->all("SELECT pc.id_customer, p.effective_date FROM n_price_publication_client pc JOIN n_price_publication p ON p.id = pc.id_publication
                WHERE pc.id_publication = ? AND p.status = 'planned' AND p.notify_email = 1 AND pc.email = 1 AND pc.email_status IS NULL ORDER BY pc.id_customer", [$publicationId]));
    }
}
