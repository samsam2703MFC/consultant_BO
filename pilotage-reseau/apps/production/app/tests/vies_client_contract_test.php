<?php
declare(strict_types=1);

require_once __DIR__ . '/../App/Services/Client/Vies/ViesUnavailableException.php';
require_once __DIR__ . '/../App/Services/Client/Vies/ViesClient.php';

use App\Services\Client\Vies\ViesClient;

function expectVies(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$polish = ViesClient::normalizeVat(' 123-456-78-90 ');
expectVies($polish === ['vat' => 'PL1234567890', 'country_code' => 'PL', 'vat_number' => '1234567890'], 'Polish NIP must receive the PL prefix and be normalized.');

$eu = ViesClient::normalizeVat('de 136-695-976');
expectVies($eu === ['vat' => 'DE136695976', 'country_code' => 'DE', 'vat_number' => '136695976'], 'EU VAT must preserve its country prefix and remove formatting.');

try {
    ViesClient::normalizeVat('not a vat');
    throw new RuntimeException('An invalid VAT must be rejected.');
} catch (\InvalidArgumentException) {
}

$address = ViesClient::mapAddressComponents([
    'street' => 'ul. Słoneczna', 'streetNumber' => '12', 'houseNumber' => '3', 'postalCode' => '00-001', 'city' => 'Warszawa',
]);
expectVies($address === ['fac_street' => 'ul. Słoneczna', 'fac_street_num' => '12/3', 'fac_zip' => '00-001', 'fac_town' => 'Warszawa'], 'Parsed VIESAPI address components must map to customer fields.');
expectVies(ViesClient::mapAddressComponents(['street' => 'Rue de la Paix']) === null, 'Incomplete parsed addresses must remain manual instead of being guessed.');

$belgianAddress = ViesClient::parseAddress("Route de Trazegnies 41\n6031 Charleroi");
expectVies($belgianAddress === ['fac_street' => 'Route de Trazegnies', 'fac_street_num' => '41', 'fac_zip' => '6031', 'fac_town' => 'Charleroi'], 'A standard two-line VIES address must populate customer address fields.');
expectVies(ViesClient::parseAddress("Building A\nBrussels") === null, 'Ambiguous VIES addresses must remain manual.');

$mapped = ViesClient::mapResponse(['vies' => ['countryCode' => 'PL', 'vatNumber' => '1234567890', 'valid' => true, 'traderName' => 'Przykład sp. z o.o.', 'traderAddress' => 'ul. Słoneczna 12/3\n00-001 Warszawa', 'traderAddressComponents' => ['street' => 'ul. Słoneczna', 'streetNumber' => '12', 'houseNumber' => '3', 'postalCode' => '00-001', 'city' => 'Warszawa'], 'id' => 'CONSULTATION-1', 'uid' => 'QUERY-1']], $polish);
expectVies($mapped['company'] === 'Przykład sp. z o.o.' && $mapped['consultation_number'] === 'CONSULTATION-1' && $mapped['address_fields'] === $address, 'The VIESAPI parsed response must expose company, consultation ID and address fields.');

$fallbackMapped = ViesClient::mapResponse(['vies' => ['countryCode' => 'BE', 'vatNumber' => '0438301725', 'valid' => true, 'traderName' => 'ASBL Le Réseau', 'traderAddress' => "Route de Trazegnies 41\n6031 Charleroi"]], ['vat' => 'BE0438301725', 'country_code' => 'BE', 'vat_number' => '0438301725']);
expectVies($fallbackMapped['address_fields'] === $belgianAddress, 'Unparsed VIESAPI responses must use the safe address fallback.');

$clientSource = file_get_contents(__DIR__ . '/../App/Services/Client/Vies/ViesClient.php');
expectVies(str_contains($clientSource, "/get/vies/parsed/euvat/") && str_contains($clientSource, "/get/vies/euvat/") && str_contains($clientSource, "=== 26"), 'The VIESAPI client must fall back when the parsed-address feature is unavailable on the plan.');

$routes = file_get_contents(__DIR__ . '/../App/Core/Routes/Client/ClientRoutes.php');
$controller = file_get_contents(__DIR__ . '/../App/Controllers/Web/Client/ClientController.php');
$newView = file_get_contents(__DIR__ . '/../App/Views/client/client/new.view.php');
$editView = file_get_contents(__DIR__ . '/../App/Views/client/client/edit.view.php');
expectVies(str_contains($routes, "/ajax/clients/vies-lookup") && str_contains($controller, 'ajaxViesLookup'), 'The VIES AJAX endpoint must be registered.');
expectVies(str_contains($newView, 'data-vies-lookup-button') && str_contains($editView, 'data-vies-lookup-button'), 'Both client forms must expose the VIES lookup control.');

echo "vies_client_contract_test: OK\n";
