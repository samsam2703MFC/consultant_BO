<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/**
 * Nowe produkty na tablecie : produkty testowe ERP (test_product, statusy, typy, etapy) z ustawieniami tabletu
 * (tfb_dev_product : pokaż, film, notatki) i zdjęciami (tfb_dev_product_photo).
 */
final class DevProductRepository extends WorkshopRepository
{
    protected string $table = 'tfb_dev_product';

    private const PRODUCT = "SELECT tp.id, tp.name, tp.id_recipe, tp.id_section, tp.id_status, tp.id_type, tp.create_timestamp,
            st.name AS status_name, ty.name AS type_name, ps.name AS section_name, pr.name AS recipe_name,
            COALESCE(d.on_tablet, 0) AS on_tablet, d.video_url, d.notes, d.updated_at,
            (SELECT COUNT(*) FROM tfb_dev_product_photo ph WHERE ph.id_test_product = tp.id) AS photo_count
        FROM test_product tp
        LEFT JOIN test_product_status st ON st.id = tp.id_status
        LEFT JOIN test_product_type ty ON ty.id = tp.id_type
        LEFT JOIN production_sections ps ON ps.id = tp.id_section
        LEFT JOIN product_recipe pr ON pr.id = tp.id_recipe
        LEFT JOIN tfb_dev_product d ON d.id_test_product = tp.id";

    /** Produkty pokazane na tablecie grupy : bez działu (dla wszystkich) albo z działem grupy. */
    public function forGroup(int $groupId): array
    {
        return $this->all(self::PRODUCT . ' WHERE d.on_tablet = 1 AND (tp.id_section IS NULL OR tp.id_section = (SELECT g.id_section FROM tfb_prod_group g WHERE g.id = ?))
            ORDER BY tp.id_status, tp.name', [$groupId]);
    }

    public function product(int $id): ?array
    {
        return $this->all(self::PRODUCT . ' WHERE tp.id = ?', [$id])[0] ?? null;
    }

    /** Etapy rozwoju (dziennik zmian produktu testowego), najnowsze pierwsze. */
    public function stages(int $id): array
    {
        return $this->all('SELECT c.id, s.name AS stage, s.priority, c.description, c.deadline, c.create_timestamp
            FROM test_product_changelog c LEFT JOIN test_product_stage s ON s.id = c.id_stage
            WHERE c.id_test_product = ? ORDER BY c.create_timestamp DESC, c.id DESC', [$id]);
    }

    /** @return array<int, array> produkt => najnowszy etap */
    public function lastStages(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->all("SELECT c.id_test_product, s.name AS stage, c.description, c.deadline FROM test_product_changelog c
                             LEFT JOIN test_product_stage s ON s.id = c.id_stage WHERE c.id_test_product IN ({$in})
                             ORDER BY c.create_timestamp DESC, c.id DESC") as $r) {
            $out[(int) $r['id_test_product']] ??= $r;
        }
        return $out;
    }

    /** Zdjęcia (bez danych obrazu). */
    public function photos(int $id): array
    {
        return $this->all('SELECT id, caption, bytes, created_at FROM tfb_dev_product_photo WHERE id_test_product = ? ORDER BY id', [$id]);
    }

    /** Zdjęcia z obrazem (tablet : data URL). */
    public function photosWithImage(int $id): array
    {
        return $this->all('SELECT id, caption, mime, image FROM tfb_dev_product_photo WHERE id_test_product = ? ORDER BY id', [$id]);
    }

    public function photo(int $photoId): ?array
    {
        return $this->all('SELECT id, id_test_product, mime, image FROM tfb_dev_product_photo WHERE id = ?', [$photoId])[0] ?? null;
    }

    public function saveSettings(int $id, bool $onTablet, ?string $videoUrl, ?string $notes, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO tfb_dev_product (id_test_product, on_tablet, video_url, notes, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE on_tablet = VALUES(on_tablet), video_url = VALUES(video_url), notes = VALUES(notes), updated_by = VALUES(updated_by)')
            ->execute([$id, $onTablet ? 1 : 0, $videoUrl, $notes, $userId]);
    }

    public function addPhoto(int $id, string $mime, string $image, ?string $caption, ?int $userId): int
    {
        $this->db->prepare('INSERT INTO tfb_dev_product_photo (id_test_product, mime, bytes, image, caption, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $mime, strlen($image), $image, $caption, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function deletePhoto(int $id, int $photoId): bool
    {
        $st = $this->db->prepare('DELETE FROM tfb_dev_product_photo WHERE id = ? AND id_test_product = ?');
        $st->execute([$photoId, $id]);
        return $st->rowCount() > 0;
    }

    public function photoCount(int $id): int
    {
        return (int) ($this->all('SELECT COUNT(*) AS n FROM tfb_dev_product_photo WHERE id_test_product = ?', [$id])[0]['n'] ?? 0);
    }
}
