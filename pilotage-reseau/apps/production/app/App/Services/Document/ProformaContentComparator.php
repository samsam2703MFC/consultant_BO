<?php

namespace App\Services\Document;

/** Compares only fields that determine a proforma's document content. */
class ProformaContentComparator
{
    public static function differs(array $orderProducts, array $proformaProducts, bool $isFreebie = false): bool
    {
        return self::normalizeOrder($orderProducts, $isFreebie) !== self::normalizeProforma($proformaProducts);
    }

    public static function quantitiesDiffer(array $orderProducts, array $proformaProducts): bool
    {
        $order = self::normalizeQuantities($orderProducts, 'quantity_carton');
        $proforma = self::normalizeQuantities($proformaProducts, 'quantity');
        return $order !== $proforma;
    }

    public static function normalizeOrder(array $products, bool $isFreebie = false): array
    {
        $normalized = [];
        foreach ($products as $product) {
            $key = (int) $product['id_product'] . ':' . self::moneyKey($isFreebie ? 0 : ($product['purchase_price'] ?? 0));
            $normalized[$key] = ($normalized[$key] ?? 0) + (float) ($product['quantity_carton'] ?? 0);
        }
        ksort($normalized);
        return $normalized;
    }

    public static function normalizeProforma(array $products): array
    {
        $normalized = [];
        foreach ($products as $product) {
            $key = (int) $product['id_product'] . ':' . self::moneyKey($product['price_per_carton'] ?? 0);
            $normalized[$key] = ($normalized[$key] ?? 0) + (float) ($product['quantity'] ?? 0);
        }
        ksort($normalized);
        return $normalized;
    }

    private static function moneyKey($value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private static function normalizeQuantities(array $products, string $quantityField): array
    {
        $normalized = [];
        foreach ($products as $product) {
            $idProduct = (int) ($product['id_product'] ?? 0);
            $normalized[$idProduct] = ($normalized[$idProduct] ?? 0) + (float) ($product[$quantityField] ?? 0);
        }
        ksort($normalized);
        return $normalized;
    }
}
