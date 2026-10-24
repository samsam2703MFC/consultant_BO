<?php
declare(strict_types=1);

require __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Audit\ProductWeightAuditEvaluator;

function expectWeightAudit(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

expectWeightAudit('ERROR', ProductWeightAuditEvaluator::evaluate(null, 10)['audit_status'], 'NULL weight must be ERROR.');
expectWeightAudit('Brak masy produktu', ProductWeightAuditEvaluator::evaluate('', 10)['audit_reason'], 'Empty weight reason.');
expectWeightAudit('ERROR', ProductWeightAuditEvaluator::evaluate(0, 10)['audit_status'], 'Zero weight must be ERROR.');
expectWeightAudit('Masa produktu wynosi 0 g', ProductWeightAuditEvaluator::evaluate(0, 10)['audit_reason'], 'Zero weight reason.');
expectWeightAudit('ERROR', ProductWeightAuditEvaluator::evaluate(-4, 10)['audit_status'], 'Negative weight must be ERROR.');
expectWeightAudit('ERROR', ProductWeightAuditEvaluator::evaluate('invalid', 10)['audit_status'], 'Invalid format must be ERROR.');
expectWeightAudit('WARNING', ProductWeightAuditEvaluator::evaluate(1, 10)['audit_status'], 'One gram must be WARNING.');
expectWeightAudit('OK', ProductWeightAuditEvaluator::evaluate('250', 20)['audit_status'], 'Positive non-default weight must be OK.');
expectWeightAudit(5000.0, ProductWeightAuditEvaluator::evaluate('250', 20)['carton_net_content_weight_g'], 'Carton net content weight.');
expectWeightAudit(null, ProductWeightAuditEvaluator::evaluate('250', null)['carton_net_content_weight_g'], 'Missing carton quantity must not calculate a false zero.');
expectWeightAudit('Nie można wyliczyć — brak poprawnej liczby sztuk w kartonie', ProductWeightAuditEvaluator::evaluate('250', 0)['carton_weight_reason'], 'Invalid carton quantity reason.');

foreach ([0, -1, 'abc', ''] as $invalidWeight) {
    try {
        ProductWeightAuditEvaluator::validateInput($invalidWeight);
        throw new RuntimeException('Invalid weight was accepted: ' . var_export($invalidWeight, true));
    } catch (InvalidArgumentException) {
    }
}
expectWeightAudit(12.5, ProductWeightAuditEvaluator::validateInput('12,5'), 'Decimal input should retain actual field precision.');

echo "product_weight_audit_contract_test: OK\n";
