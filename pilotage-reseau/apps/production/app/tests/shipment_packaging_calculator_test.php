<?php
declare(strict_types=1);

require __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Shipping\ShipmentPackagingCalculator;

function expectPackaging(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// A material measured in grams has its mass already stored in flattened_recipe_ingredient.quantity.
$foil = ShipmentPackagingCalculator::calculate(12, 2.5, 'g', null);
expectPackaging(30.0, $foil['packaging_units_total'], 'Foil quantity must be summed in grams.');
expectPackaging(30.0, $foil['total_weight_g'], 'Gram-based packaging must not require unit mass.');
expectPackaging(false, $foil['requires_unit_weight'], 'Gram-based packaging must not require unit mass.');

// A carton measured in pieces requires a declared mass of one carton.
$carton = ShipmentPackagingCalculator::calculate(12, 1, 'szt', 180);
expectPackaging(12.0, $carton['packaging_units_total'], 'Carton units must be counted.');
expectPackaging(2160.0, $carton['total_weight_g'], 'Carton mass must use the declared unit mass.');
expectPackaging(true, $carton['requires_unit_weight'], 'Piece-based packaging requires unit mass.');

$missingWeight = ShipmentPackagingCalculator::calculate(12, 1, 'szt', null);
expectPackaging(null, $missingWeight['total_weight_g'], 'Piece-based packaging without mass must stay unresolved.');

echo "shipment_packaging_calculator_test: OK\n";
