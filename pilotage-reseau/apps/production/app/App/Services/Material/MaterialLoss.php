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

    /**
     * [295] SQL : odpad [%] surowca o aliasie $alias — z n_raw_material_loss (źródło tej wersji) ; surowiec bez wiersza
     * (dodany w ERP d'origine) : z raw_material.waste_perc w znaczeniu danej bazy — po migracji 158 to już odpad,
     * w trybie cohabitation to « wykorzystanie » (odpad = 100 − wartość ; ≤ 20 = już odpad ; puste / > 100 → 0).
     */
    public static function column(string $alias): string
    {
        $w = "{$alias}.waste_perc";
        $legacy = \App\Core\Cohabitation::on()
            ? "CASE WHEN {$w} IS NULL OR {$w} > 100 OR {$w} < 0 THEN 0 WHEN {$w} <= 20 THEN {$w} ELSE 100 - {$w} END"
            : "COALESCE({$w}, 0)";
        return "COALESCE((SELECT nl.loss_perc FROM n_raw_material_loss nl WHERE nl.id_raw_material = {$alias}.id), {$legacy})";
    }

    /** [295] SQL : « do sprawdzenia » (przeniesiony automatycznie) ; surowiec bez wiersza w trybie cohabitation : 1. */
    public static function reviewColumn(string $alias): string
    {
        $default = \App\Core\Cohabitation::on() ? '1' : '0';
        return "COALESCE((SELECT nl.review FROM n_raw_material_loss nl WHERE nl.id_raw_material = {$alias}.id), {$default})";
    }

    /** [295] Wartość raw_material.waste_perc dla odpadu tej wersji (ERP d'origine w trybie cohabitation : « wykorzystanie »). */
    public static function legacyValue(float $loss): float
    {
        $loss = max(0.0, min(99.99, $loss));
        return \App\Core\Cohabitation::on() ? round(100.0 - $loss, 2) : $loss;
    }

    /** SQL : mnożnik z aliasu tabeli raw_material (ta sama reguła co factor()). */
    public static function factorSql(string $alias): string
    {
        return '(1 / (1 - LEAST(GREATEST(' . self::column($alias) . ', 0), 99.99) / 100))';
    }

    /** SQL : cena jednostki użytecznej z aliasu tabeli raw_material. */
    public static function priceSql(string $alias): string
    {
        return "({$alias}.price_per_unit * " . self::factorSql($alias) . ')';
    }
}
