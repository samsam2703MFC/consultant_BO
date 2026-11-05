<?php
declare(strict_types=1);

namespace App\Services\Material;

/**
 * [251] Odpad przy obróbce w koszcie : receptury podają wagę PO obróbce (obrane jabłka), więc 1 kg użyteczny kosztuje
 * cena zakupu ÷ (1 − odpad). Jabłko : 12,00 zł/kg, odpad 35 % → 18,46 zł za kg w recepturze ; z 1 kg w recepturze
 * kupujemy 1 / 0,65 = 1,54 kg. Odpad z karty surowca (raw_material.waste_perc, 0–99,99 %).
 */
final class MaterialLoss
{
    /** Mnożnik ilości kupionej na ilość w recepturze : 1 ÷ (1 − odpad). Odpad pusty / ujemny = 0 ; najwyżej 99,99 %. */
    public static function factor(mixed $wastePerc): float
    {
        $w = is_numeric($wastePerc) ? (float) $wastePerc : 0.0;
        $w = max(0.0, min(99.99, $w));
        return 1.0 / (1.0 - $w / 100.0);
    }

    /** Cena jednostki użytecznej (w recepturze) z ceny zakupu. */
    public static function usablePrice(mixed $price, mixed $wastePerc): float
    {
        return max(0.0, (float) $price) * self::factor($wastePerc);
    }

    /** SQL : mnożnik z aliasu tabeli raw_material (ta sama reguła co factor()). */
    public static function factorSql(string $alias): string
    {
        return "(1 / (1 - LEAST(GREATEST(COALESCE({$alias}.waste_perc, 0), 0), 99.99) / 100))";
    }

    /** SQL : cena jednostki użytecznej z aliasu tabeli raw_material. */
    public static function priceSql(string $alias): string
    {
        return "({$alias}.price_per_unit * " . self::factorSql($alias) . ')';
    }
}
