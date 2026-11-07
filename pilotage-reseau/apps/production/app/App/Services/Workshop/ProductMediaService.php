<?php
declare(strict_types=1);

namespace App\Services\Workshop;

use App\Exceptions\CustomException;
use App\Repositories\Workshop\ProductMediaRepository;
use App\Services\Utils\DataUrlImage;

/**
 * Karta produktu → tablet : zdjęcie produktu i 4 etapy produkcji (zdjęcie + krótki opis PL / FR).
 * Biuro dodaje je w karcie produktu (przeciągnij i upuść), tablet pokazuje je w recepturze produktu.
 */
final class ProductMediaService
{
    public const STEPS = 4;
    public const MAX_BYTES = 3_000_000;
    public const MAX_CAPTION = 300;

    public function __construct(private ProductMediaRepository $repo)
    {
    }

    /** Karta produktu : zdjęcie i etapy (adres obrazu z wersją, opisy). */
    public function sheet(int $productId): array
    {
        $meta = $this->repo->meta($productId);
        $slot = fn(int $s) => [
            'slot' => $s,
            'image' => !empty($meta[$s]['has_image']) ? ROOT . "/products/{$productId}/media/{$s}?v=" . strtotime((string) $meta[$s]['updated_at']) : null,
            'caption' => (string) ($meta[$s]['caption'] ?? ''),
            'caption_fr' => (string) ($meta[$s]['caption_fr'] ?? ''),
        ];
        return ['photo' => $slot(0), 'steps' => array_map($slot, range(1, self::STEPS))];
    }

    /** Zapis slotu : obraz (data URL) i / albo opis ; brakujący klucz zostaje bez zmian. */
    public function save(int $productId, int $slot, array $input, ?int $userId): array
    {
        $this->check($productId, $slot);
        $image = trim((string) ($input['image'] ?? ''));
        if ($image !== '') {
            $parsed = DataUrlImage::parse($image, self::MAX_BYTES);
            $this->repo->saveImage($productId, $slot, $parsed['mime'], $parsed['bytes'], $userId);
        }
        if (array_key_exists('caption', $input) || array_key_exists('caption_fr', $input)) {
            $current = $this->repo->meta($productId)[$slot] ?? [];
            $text = fn(string $key) => array_key_exists($key, $input) ? self::caption($input[$key]) : (($current[$key] ?? '') !== '' ? (string) $current[$key] : null);
            $this->repo->saveCaption($productId, $slot, $text('caption'), $text('caption_fr'), $userId);
        }
        $this->repo->prune($productId, $slot);
        return $this->sheet($productId);
    }

    public function deleteImage(int $productId, int $slot): array
    {
        $this->check($productId, $slot);
        $this->repo->deleteImage($productId, $slot);
        return $this->sheet($productId);
    }

    public function image(int $productId, int $slot): ?array
    {
        return $slot >= 0 && $slot <= self::STEPS ? $this->repo->image($productId, $slot) : null;
    }

    /** Tablet : zdjęcie i wypełnione etapy jako data URL, opis w języku tabletu (polski, gdy brak francuskiego). */
    public function forKiosk(int $productId, bool $fr): array
    {
        $rows = $this->repo->withImages($productId);
        $src = fn(?array $r) => $r !== null && $r['image'] !== null ? DataUrlImage::toDataUrl((string) $r['mime'], (string) $r['image']) : null;
        $steps = [];
        for ($s = 1; $s <= self::STEPS; $s++) {
            $r = $rows[$s] ?? null;
            if ($r === null) continue;
            $text = $fr && (string) $r['caption_fr'] !== '' ? (string) $r['caption_fr'] : (string) ($r['caption'] ?? '');
            if ($r['image'] === null && $text === '') continue;
            $steps[] = ['no' => $s, 'text' => $text, 'src' => $src($r)];
        }
        return ['photo' => $src($rows[0] ?? null), 'steps' => $steps];
    }

    private function check(int $productId, int $slot): void
    {
        if ($slot < 0 || $slot > self::STEPS) throw new CustomException('Nieprawidłowe miejsce zdjęcia.', 'errors', 422);
        if (!$this->repo->productExists($productId)) throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
    }

    private static function caption(mixed $value): ?string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) > self::MAX_CAPTION) throw new CustomException('Opis etapu : najwyżej ' . self::MAX_CAPTION . ' znaków.', 'errors', 422);
        return $text === '' ? null : $text;
    }
}
