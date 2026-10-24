<?php
declare(strict_types=1);

namespace App\Repositories\Workshop;

/** Zdjęcie produktu (slot 0) i 4 etapy produkcji ze zdjęciem (sloty 1–4) : tfb_product_media. */
final class ProductMediaRepository extends WorkshopRepository
{
    protected string $table = 'tfb_product_media';

    /** @return array<int, array> slot => opis bez obrazu (has_image, bytes, caption, caption_fr, updated_at) */
    public function meta(int $productId): array
    {
        $out = [];
        foreach ($this->all('SELECT slot, mime, bytes, image IS NOT NULL AS has_image, caption, caption_fr, updated_at FROM tfb_product_media
                             WHERE id_final_product = ? ORDER BY slot', [$productId]) as $r) $out[(int) $r['slot']] = $r;
        return $out;
    }

    /** @return array<int, array> slot => wiersz z obrazem */
    public function withImages(int $productId): array
    {
        $out = [];
        foreach ($this->all('SELECT slot, mime, image, caption, caption_fr FROM tfb_product_media WHERE id_final_product = ? ORDER BY slot', [$productId]) as $r) $out[(int) $r['slot']] = $r;
        return $out;
    }

    public function image(int $productId, int $slot): ?array
    {
        return $this->all('SELECT mime, image, updated_at FROM tfb_product_media WHERE id_final_product = ? AND slot = ? AND image IS NOT NULL', [$productId, $slot])[0] ?? null;
    }

    public function saveImage(int $productId, int $slot, string $mime, string $bytes, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO tfb_product_media (id_final_product, slot, mime, bytes, image, updated_by) VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE mime = VALUES(mime), bytes = VALUES(bytes), image = VALUES(image), updated_by = VALUES(updated_by)')
            ->execute([$productId, $slot, $mime, strlen($bytes), $bytes, $userId]);
    }

    public function saveCaption(int $productId, int $slot, ?string $caption, ?string $captionFr, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO tfb_product_media (id_final_product, slot, caption, caption_fr, updated_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE caption = VALUES(caption), caption_fr = VALUES(caption_fr), updated_by = VALUES(updated_by)')
            ->execute([$productId, $slot, $caption, $captionFr, $userId]);
    }

    /** Usuwa obraz ; wiersz bez obrazu i bez opisu znika. */
    public function deleteImage(int $productId, int $slot): void
    {
        $this->db->prepare('UPDATE tfb_product_media SET mime = NULL, bytes = NULL, image = NULL WHERE id_final_product = ? AND slot = ?')->execute([$productId, $slot]);
        $this->prune($productId, $slot);
    }

    public function prune(int $productId, int $slot): void
    {
        $this->db->prepare("DELETE FROM tfb_product_media WHERE id_final_product = ? AND slot = ? AND image IS NULL
                            AND COALESCE(caption, '') = '' AND COALESCE(caption_fr, '') = ''")->execute([$productId, $slot]);
    }

    public function productExists(int $productId): bool
    {
        return (bool) $this->all('SELECT 1 FROM final_product WHERE id = ?', [$productId]);
    }
}
