<?php
declare(strict_types=1);

namespace App\Services\Finances;

use App\Exceptions\CustomException;
use App\Repositories\Product\Price\ProductPriceChangeRepository;

/**
 * Jedna reguła zmiany ceny standardowej (cennik ogólny i marże kategorii) : ceny klientów równe dotychczasowej
 * cenie standardowej idą za nią, ceny wynegocjowane zostają. Zmiany w historii cen produktu.
 */
final class StandardPriceService
{
    public const SOURCES = ['general_price_list', 'category_margin'];

    public function __construct(private ProductPriceChangeRepository $repo)
    {
    }

    /** @return array{price: float, old_price: float, followers: int, kept: int, message: string} */
    public function change(int $productId, mixed $rawPrice, string $source, ?int $userId): array
    {
        if (!in_array($source, self::SOURCES, true)) throw new CustomException('Nieznane źródło zmiany ceny.', 'errors', 422);
        $price = is_string($rawPrice) ? str_replace([' ', ','], ['', '.'], trim($rawPrice)) : $rawPrice;
        $price = filter_var($price, FILTER_VALIDATE_FLOAT);
        if ($price === false || $price <= 0 || $price >= 100000) throw new CustomException('Podaj cenę większą od zera.', 'errors', 422);
        $price = round($price, 2);
        $old = $this->repo->standardPrice($productId) ?? throw new CustomException('Nie znaleziono produktu.', 'errors', 404);
        $result = abs($old - $price) < 0.005 ? ['followers' => 0, 'kept' => 0] : $this->repo->changeStandardPrice($productId, $old, $price, $source, $userId);
        return ['price' => $price, 'old_price' => $old] + $result + ['message' => self::message($old, $price, $result)];
    }

    /** @param array{followers: int, kept: int} $result */
    private static function message(float $old, float $new, array $result): string
    {
        $fmt = fn(float $v) => number_format($v, 2, ',', ' ');
        if (abs($old - $new) < 0.005) return "Cena standardowa bez zmian ({$fmt($new)} €).";
        $text = "Cena standardowa: {$fmt($old)} → {$fmt($new)} €.";
        if ($result['followers']) $text .= " Zmieniono też u klientów z ceną standardową: {$result['followers']}.";
        if ($result['kept']) $text .= " Ceny wynegocjowane bez zmian: {$result['kept']}.";
        return $text;
    }
}
