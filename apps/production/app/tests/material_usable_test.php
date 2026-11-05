<?php
declare(strict_types=1);
/*
 * [250] Karta surowca : « Wykorzystanie [%] » (colonne waste_perc = part utilisable après épluchage, 100 = sans perte).
 * Fonctions pures : contrôle 1–100 (vide accepté), valeur enregistrée (vide = 100, arrondi), valeur douteuse (≤ 20 %),
 * et validation des formulaires de création et d'édition. Sans base de données.
 *
 *   php tests/material_usable_test.php
 */

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Material\MaterialService;

function expectMu(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// Contrôle de saisie
foreach ([null, '', '  ', '1', '65', '100', '65,0', 99.5] as $ok) expectMu(MaterialService::usableError($ok) === null, 'accepté : ' . var_export($ok, true));
foreach (['0', '-5', '101', 'abc', '150', '0,5'] as $bad) expectMu(MaterialService::usableError($bad) !== null, 'refusé : ' . var_export($bad, true));

// Valeur enregistrée
expectMu(MaterialService::usableValue('') === 100, 'vide = 100');
expectMu(MaterialService::usableValue(null) === 100, 'null = 100');
expectMu(MaterialService::usableValue('65') === 65, '65');
expectMu(MaterialService::usableValue('64,6') === 65, 'arrondi 64,6 → 65');
expectMu(MaterialService::usableValue('100') === 100, '100');

// Valeur douteuse (perte saisie à la place de la part utilisable)
expectMu(MaterialService::usableSuspicious(1) && MaterialService::usableSuspicious('20'), '1 et 20 douteux');
expectMu(!MaterialService::usableSuspicious(21) && !MaterialService::usableSuspicious(65) && !MaterialService::usableSuspicious(null), '21, 65, null non douteux');

// Formulaires : création (étape Zakup) et édition
$svc = (new ReflectionClass(MaterialService::class))->newInstanceWithoutConstructor();
$edit = ['name' => 'jabłko', 'id_category' => '106'];
expectMu($svc->validateUpdate($edit + ['waste_perc' => '65']), 'édition 65 % acceptée');
expectMu($svc->validateUpdate($edit), 'édition sans le champ acceptée (valeur inchangée)');
expectMu(!$svc->validateUpdate($edit + ['waste_perc' => '0']) && isset($svc->lastErrors['waste_perc']), 'édition 0 % refusée');
$new = ['name' => 'jabłko', 'id_unit' => '1', 'id_category' => '106', 'pack_price' => '37.10', 'package_size_in_base_unit' => '10000', 'id_current_supplier' => '3'];
expectMu($svc->validate($new + ['waste_perc' => '65'], true), 'création 65 % acceptée');
expectMu(!$svc->validate($new + ['waste_perc' => '120'], true) && isset($svc->lastErrors['waste_perc']), 'création 120 % refusée');

// Données normalisées avant enregistrement
$adj = $svc->adjustData(['waste_perc' => '', 'name' => 'x']);
expectMu($adj['waste_perc'] === 100, 'adjustData : vide → 100');
expectMu(!array_key_exists('waste_perc', $svc->adjustData(['name' => 'x'])), 'adjustData : champ absent laissé absent');

echo "material_usable_test: OK\n";
