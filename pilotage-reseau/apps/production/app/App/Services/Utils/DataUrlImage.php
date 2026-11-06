<?php
declare(strict_types=1);

namespace App\Services\Utils;

use App\Exceptions\CustomException;

/** Obraz przesłany jako data URL (zmniejszony w przeglądarce) : JPEG, PNG albo WebP, sprawdzony po zawartości. */
final class DataUrlImage
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{mime: string, bytes: string} */
    public static function parse(string $dataUrl, int $maxBytes): array
    {
        if (!preg_match('~^data:(image/[a-z]+);base64,([A-Za-z0-9+/=\s]+)$~', $dataUrl, $m) || !in_array($m[1], self::MIMES, true)) {
            throw new CustomException('Zdjęcie : JPEG, PNG albo WebP.', 'errors', 422);
        }
        $bytes = base64_decode(preg_replace('~\s+~', '', $m[2]), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > $maxBytes) {
            throw new CustomException('Zdjęcie za duże albo uszkodzone (najwyżej ' . round($maxBytes / 1_000_000) . ' MB).', 'errors', 422);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info['mime'] ?? '') !== $m[1]) throw new CustomException('To nie jest obraz.', 'errors', 422);
        return ['mime' => $m[1], 'bytes' => $bytes];
    }

    public static function toDataUrl(string $mime, string $bytes): string
    {
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
