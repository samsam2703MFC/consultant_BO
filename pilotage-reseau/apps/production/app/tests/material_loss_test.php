<?php
declare(strict_types=1);
/*
 * [251] Karta surowca : « Odpad przy obróbce [%] » (colonne waste_perc = perte à l'épluchage, 0 = sans perte, jabłko 35).
 * Fonctions pures : contrôle 0–99,99 (vide accepté), valeur enregistrée (vide = 0, arrondi 0,01), valeur douteuse
 * (≥ 80 %), validation des formulaires de création et d'édition, et l'enregistrement qui confirme une valeur à vérifier.
 * Sans base de données.
 *
 *   php tests/material_loss_test.php
 */

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Material\MaterialService;
use App\Services\Material\MaterialLoss;

function expectMl(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// Contrôle de saisie
foreach ([null, '', '  ', '0', '1', '35', '2,5', '99.99', 12.5] as $ok) expectMl(MaterialService::lossError($ok) === null, 'accepté : ' . var_export($ok, true));
foreach (['-1', '100', '101', 'abc', '850'] as $bad) expectMl(MaterialService::lossError($bad) !== null, 'refusé : ' . var_export($bad, true));

// Valeur enregistrée
expectMl(MaterialService::lossValue('') === 0.0, 'vide = 0');
expectMl(MaterialService::lossValue(null) === 0.0, 'null = 0');
expectMl(MaterialService::lossValue('35') === 35.0, '35');
expectMl(MaterialService::lossValue('2,5') === 2.5, '2,5 → 2.5');
expectMl(MaterialService::lossValue('12.345') === 12.35, 'arrondi 0,01');

// Valeur douteuse (part utilisable saisie à la place de la perte)
expectMl(MaterialService::lossSuspicious(80) && MaterialService::lossSuspicious('99'), '80 et 99 douteux');
expectMl(!MaterialService::lossSuspicious(79) && !MaterialService::lossSuspicious(35) && !MaterialService::lossSuspicious(null), '79, 35, null non douteux');

// Formulaires : création (étape Zakup) et édition
$svc = (new ReflectionClass(MaterialService::class))->newInstanceWithoutConstructor();
$edit = ['name' => 'jabłko', 'id_category' => '106'];
expectMl($svc->validateUpdate($edit + ['waste_perc' => '35']), 'édition 35 % acceptée');
expectMl($svc->validateUpdate($edit + ['waste_perc' => '0']), 'édition 0 % acceptée');
expectMl($svc->validateUpdate($edit), 'édition sans le champ acceptée (valeur inchangée)');
expectMl(!$svc->validateUpdate($edit + ['waste_perc' => '100']) && isset($svc->lastErrors['waste_perc']), 'édition 100 % refusée');
$new = ['name' => 'jabłko', 'id_unit' => '1', 'id_category' => '106', 'pack_price' => '37.10', 'package_size_in_base_unit' => '10000', 'id_current_supplier' => '3'];
expectMl($svc->validate($new + ['waste_perc' => '35'], true), 'création 35 % acceptée');
expectMl(!$svc->validate($new + ['waste_perc' => '120'], true) && isset($svc->lastErrors['waste_perc']), 'création 120 % refusée');

// Données normalisées avant enregistrement : la fiche enregistrée confirme la valeur (fin de « do sprawdzenia »)
$adj = $svc->adjustData(['waste_perc' => '', 'name' => 'x']);
expectMl($adj['waste_perc'] === 0.0 && $adj['waste_review'] === 0, 'adjustData : vide → 0 et confirmé');
expectMl($svc->adjustData(['waste_perc' => '35'])['waste_perc'] === 35.0, 'adjustData : 35');
$untouched = $svc->adjustData(['name' => 'x']);
expectMl(!array_key_exists('waste_perc', $untouched) && !array_key_exists('waste_review', $untouched), 'adjustData : champ absent laissé absent');

// [251] Perte dans le coût : recettes pesées après épluchage → prix du kg utilisable = prix ÷ (1 − perte)
expectMl(abs(MaterialLoss::factor(35) - 1 / 0.65) < 1e-9, 'facteur 35 % = 1/0,65');
expectMl(MaterialLoss::factor(0) === 1.0 && MaterialLoss::factor(null) === 1.0 && MaterialLoss::factor('') === 1.0 && MaterialLoss::factor(-5) === 1.0, 'sans perte = 1');
expectMl(MaterialLoss::factor(150) === MaterialLoss::factor(99.99), 'perte bornée à 99,99 %');
expectMl(abs(MaterialLoss::usablePrice(0.012, 35) * 1000 - 18.4615) < 0.001, 'jabłko : 12,00 zł/kg → 18,46 zł/kg utilisable');
expectMl(MaterialLoss::usablePrice(-1, 35) === 0.0, 'prix négatif = 0');
expectMl(str_contains(MaterialLoss::priceSql('mat'), 'mat.price_per_unit') && str_contains(MaterialLoss::factorSql('rm'), 'rm.waste_perc'), 'SQL avec l\'alias');
// Kalkulacja : prix utilisable dans la colonne, perte et prix d'achat pour l'étiquette
$rows = \App\Services\Recipe\RecipeCalculationViewService::rows([[ 'type' => 'ingredient', 'id' => 4053, 'name' => 'jabłko', 'unit_name' => 'g',
    'required_quantity' => 650, 'suggested_base_unit_price' => 0.012 / 0.65, 'purchase_unit_price' => 0.012, 'waste_perc' => 35, 'price_for_required_quantity' => 12.0 ]], 12.0);
expectMl(abs($rows[0]['loss'] - 35.0) < 1e-9 && abs($rows[0]['purchase_price'] - 0.012) < 1e-9 && abs($rows[0]['cost'] - 12.0) < 1e-9, 'kalkulacja : perte et prix d\'achat');

echo "material_loss_test: OK\n";
