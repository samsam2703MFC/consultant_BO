<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Planning\PlanningService;

/** Documentation OpenAPI du domaine « planning » (P3 : besoins MTO/MTS/semi-finis, plans, OF). */
final class OpenApiPlanning
{
    public static function operations(): array
    {
        $t = 'planning';
        $period = ['from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date']];
        return [
            'GET /planning/demand' => ['tag' => $t, 'id' => 'getDemand', 'summary' => 'Zapotrzebowanie MTO / MTS / półprodukty / Besoins MTO, MTS et semi-finis', 'response' => 'Demand', 'errors' => [422], 'query' => $period,
                'description' => "MTO : commandes non expédiées (cartons × colisage), dues au départ du camion (jour d'expédition à planning.default_departure_time − planning.loading_time_min).\n"
                    . "MTS : stock projeté = stock libre + OF MTS en cours − commandes servies sur stock ; cible = consommation (WDT des planning.consumption_weeks dernières semaines) × couverture, bornée par stock min/max ; jamais au-delà du stock max ; plafond cold.freezer_capacity_cartons.\n"
                    . 'Semi-finis : nomenclature des lignes (grammes par carton × (1 + perte)).'],
            'GET /planning/plans' => ['tag' => $t, 'id' => 'listPlans', 'summary' => 'Plany / Plans', 'response' => 'PlanSummary', 'list' => true, 'errors' => [422],
                'query' => ['status' => ['type' => 'string', 'enum' => PlanningService::STATUSES]]],
            'POST /planning/plans' => ['tag' => $t, 'id' => 'createPlan', 'summary' => 'Zaproponuj plan / Proposer un plan', 'body' => 'PlanInput', 'response' => 'Plan', 'ok' => 201, 'errors' => [400, 422]],
            'GET /planning/plans/{id:\d+}' => ['tag' => $t, 'id' => 'getPlan', 'summary' => 'Plan z pozycjami i zleceniami / Plan, lignes et OF', 'response' => 'Plan', 'errors' => [404]],
            'PATCH /planning/plans/{id:\d+}/lines/{lineId:\d+}' => ['tag' => $t, 'id' => 'adjustPlanLine', 'summary' => 'Zmień ilość / Ajuster une ligne', 'body' => 'PlanLineInput', 'response' => 'Plan', 'errors' => [400, 404, 409, 422],
                'description' => 'Plan proposé uniquement. Jamais sous les commandes et semi-finis (BELOW_ORDERS), jamais au-delà du stock max pour un MTS (MTS_ABOVE_STOCK_MAX). Motif obligatoire si la quantité change.'],
            'POST /planning/plans/{id:\d+}/validate' => ['tag' => $t, 'id' => 'validatePlan', 'summary' => 'Zatwierdź plan i utwórz zlecenia / Valider et créer les OF', 'response' => 'Plan', 'errors' => [404, 409],
                'description' => 'Un seul plan validé par période (409 PLAN_OVERLAP). OF MTO reliés aux commandes clients, OF SEMI reliés à leur OF parent et dus à son démarrage au plus tard.'],
        ];
    }

    public static function schemas(): array
    {
        $num = ['type' => 'number'];
        $int = ['type' => 'integer'];
        $nInt = ['type' => 'integer', 'nullable' => true];
        $qty = ['qty_mto' => $num, 'qty_mts' => $num, 'qty_semi' => $num, 'qty_proposed' => $num, 'batches' => $int, 'standard_hours' => $num];
        $planSummary = [
            'id' => $int, 'period_start' => ['type' => 'string', 'format' => 'date'], 'period_end' => ['type' => 'string', 'format' => 'date'],
            'status' => ['type' => 'string', 'enum' => PlanningService::STATUSES], 'version' => $int, 'notes' => ['type' => 'string', 'nullable' => true],
            'created_at' => ['type' => 'string'], 'created_by' => ['type' => 'string', 'nullable' => true],
            'validated_at' => ['type' => 'string', 'nullable' => true], 'validated_by' => ['type' => 'string', 'nullable' => true],
            'line_count' => $int, 'standard_hours' => $num,
        ];
        return [
            'Demand' => ['type' => 'object', 'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date'],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'item_id' => $int, 'name' => ['type' => 'string'], 'item_type' => ['type' => 'string'], 'mode' => ['type' => 'string'], 'group_id' => $nInt,
                    'due_date' => ['type' => 'string', 'format' => 'date'], 'due_at' => ['type' => 'string', 'description' => 'départ du camion moins le chargement'],
                    'unit' => ['type' => 'string'], 'customer_orders' => $int, 'late_orders' => ['type' => 'integer', 'description' => 'passées après la clôture'],
                    'mts' => ['type' => 'object', 'nullable' => true, 'properties' => [
                        'projected' => $num, 'target' => $num, 'need' => $num, 'qty' => $num, 'capped' => ['type' => 'boolean'], 'below_batch_min' => ['type' => 'boolean'],
                        'daily' => $num, 'coverage_days' => $num, 'free_stock' => $num, 'reason' => ['type' => 'string'],
                    ]],
                    'parents' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['item_id' => $int, 'due_date' => ['type' => 'string'], 'qty' => $num]]],
                ] + $qty]],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string']], 'additionalProperties' => true]],
            ]],
            'PlanSummary' => ['type' => 'object', 'properties' => $planSummary],
            'Plan' => ['type' => 'object', 'properties' => $planSummary + [
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => $int, 'item_id' => $int, 'name' => ['type' => 'string'], 'item_type' => ['type' => 'string'], 'mode' => ['type' => 'string'], 'group_id' => $nInt,
                    'due_date' => ['type' => 'string', 'format' => 'date'], 'unit' => ['type' => 'string'], 'ppc' => $nInt, 'qty_final' => $num,
                    'adjusted' => ['type' => 'boolean'], 'adjust_reason' => ['type' => 'string', 'nullable' => true],
                ] + $qty]],
                'hours_by_group' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['group_id' => $nInt, 'standard_hours' => $num]]],
                'orders' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => $int, 'code' => ['type' => 'string', 'example' => 'OF-20261020-001'], 'item_id' => $int, 'name' => ['type' => 'string'],
                    'quantity' => $num, 'unit' => ['type' => 'string'], 'origin' => ['type' => 'string', 'enum' => ['MTO', 'MTS', 'SEMI']], 'parent_order_id' => $nInt,
                    'due_at' => ['type' => 'string'], 'priority' => $int, 'status' => ['type' => 'string'], 'customer_orders' => $int,
                ]]],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true], 'description' => 'après création'],
            ]],
            'PlanInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['from', 'to'], 'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date', 'description' => 'au plus planning.max_period_days jours'],
                'notes' => ['type' => 'string', 'maxLength' => 1000, 'nullable' => true],
            ]],
            'PlanLineInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['qty_final'], 'properties' => [
                'qty_final' => ['type' => 'number', 'minimum' => 0], 'adjust_reason' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
            ]],
        ];
    }
}
