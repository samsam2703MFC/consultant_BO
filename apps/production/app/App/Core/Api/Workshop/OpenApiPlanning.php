<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Planning\PlanningService;
use App\Services\Workshop\Scheduling\ShiftOptimizer;

/** Documentation OpenAPI du domaine « planning » (P3 : besoins, plans, OF ; P4–P6 : ordonnancement, Gantt, charge, optimiseur). */
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
            'POST /planning/plans/{id:\d+}/schedule' => ['tag' => $t, 'id' => 'schedulePlan', 'summary' => 'Uszereguj zlecenia planu / Ordonnancer les OF du plan', 'response' => 'ScheduleResult', 'errors' => [404, 409],
                'description' => "À rebours depuis l'échéance, au plus tard possible. Fenêtres = shifts actifs où le poste est disponible ; la nuit : robots autonomes, attentes passives, groupes avec équipe de nuit. Chambres à capacité cumulée (1 unité = 1 passage de l'étape). "
                    . "Gros OF découpés en lots (scheduling.max_operation_min). Un OF qui ne tient pas avant son échéance n'est jamais placé après : il est rendu en conflit. Les opérations verrouillées sont conservées."],
            'GET /planning/operations' => ['tag' => $t, 'id' => 'listOperations', 'summary' => 'Operacje (Gantt) / Opérations planifiées (Gantt)', 'response' => 'Operation', 'list' => true, 'errors' => [422],
                'query' => $period + ['group_id' => ['type' => 'integer']]],
            'PATCH /planning/operations/{id:\d+}' => ['tag' => $t, 'id' => 'updateOperation', 'summary' => 'Przesuń / zablokuj operację / Déplacer ou verrouiller une opération', 'body' => 'OperationInput', 'response' => 'Operation', 'errors' => [400, 403, 404, 409, 422],
                'description' => "Durée conservée. Refusé si l'opération finirait après l'échéance de l'OF (AFTER_DUE), avant la fin de l'étape précédente ou après le début de la suivante, si un semi-fini finirait après le début de l'OF parent, "
                    . "hors d'un shift où le groupe a assez de monde (OUTSIDE_STAFFED_SHIFT), sur un poste occupé ou une chambre pleine, ou dans le passé. Poste : celui de l'étape ou une alternative. Une opération déplacée est verrouillée. "
                    . 'Chef d\'équipe : seulement dans ses groupes (prod.gantt.move).'],
            'GET /planning/load' => ['tag' => $t, 'id' => 'getLoad', 'summary' => 'Obciążenie zespołów / Charge des équipes par groupe, jour et shift', 'response' => 'Load', 'list' => true, 'errors' => [422], 'query' => $period,
                'description' => 'Heures planifiées (opérateurs × durée, nettoyage compris) ÷ effectif prévu des équipes × durée du shift. Vert entre load.green_min_pct et load.green_max_pct.'],
            'GET /planning/optimizer' => ['tag' => $t, 'id' => 'proposeShifts', 'summary' => 'Optymalizator zmian 95 % / Optimiseur de shifts', 'response' => 'OptimizerProposal', 'errors' => [422], 'query' => $period,
                'description' => "Par groupe, jour et shift, leviers dans l'ordre optimizer.levers_order : avancer du MTS (stock max, surgélateur), réaffecter les polyvalents, fermer / réduire / ouvrir, heures sup. (optimizer.max_overtime_h par personne). "
                    . 'Cible load.target_pct dans [load.green_min_pct, load.green_max_pct]. Les opérations existantes ne bougent pas : le MTO n\'est jamais retardé.'],
            'POST /planning/optimizer/apply' => ['tag' => $t, 'id' => 'applyShifts', 'summary' => 'Zastosuj propozycję / Appliquer la proposition', 'body' => 'PeriodInput', 'response' => 'OptimizerApplyResult', 'errors' => [400, 422],
                'description' => "OF MTS des avances ajoutés au plan validé de la date (échéance = fin du shift) et ordonnancés dans les places libres ; ceux qui ne tiennent pas (poste ou chambre pleins) sont retirés (mts_not_placed, conflicts). "
                    . "Puis l'obsada est recalculée sur la charge réellement ordonnancée et écrite (source optimizer)."],
            'POST /planning/simulate' => ['tag' => $t, 'id' => 'simulateStaffing', 'summary' => 'Symulacja obsady / Simuler une obsada', 'body' => 'SimulationInput', 'response' => 'SimulationResult', 'errors' => [400, 422],
                'description' => 'Charge recalculée avec une autre obsada (personnes, heures sup.) ; rien n\'est enregistré.'],
            'PUT /planning/staffing' => ['tag' => $t, 'id' => 'saveStaffing', 'summary' => 'Obsada dnia / Effectif du jour', 'body' => 'StaffingInput', 'response' => 'Load', 'errors' => [400, 422],
                'description' => 'Effectif d\'un groupe sur un shift un jour donné (remplace les équipes) ; headcount null = retour aux équipes.'],
            'GET /planning/week' => ['tag' => $t, 'id' => 'getWeek', 'summary' => 'Gantt tygodnia / Gantt de la semaine', 'response' => 'Week', 'errors' => [422],
                'query' => ['date' => ['type' => 'string', 'format' => 'date', 'description' => 'un jour de la semaine (défaut : aujourd\'hui)']],
                'description' => "Production à la semaine : un bloc = un produit sur un jour. Quantités du plan validé (figé, clos) qui commence dans la semaine, sinon proposition en direct (commandes + réassort + lots fixes). "
                    . "Heures = normes des fiches × quantité ; disponibles = effectif × shift sur les jours week.production_days. Ce qui n'est sur aucun jour attend dans unassigned_qty. "
                    . "Réalisé = part des opérations terminées des OF du plan, sans plan : part de la quantité produite (lots saisis sur les tablettes). "
                    . "Lots fixes 📌 : à la première ouverture de la semaine, chaque lot fixe est posé sur son jour (à partir d'aujourd'hui, jours avec équipe), une seule fois par semaine."],
            'POST /planning/week/move' => ['tag' => $t, 'id' => 'moveWeekProduct', 'summary' => 'Przenieś produkt na dzień / Déplacer un produit sur un jour', 'body' => 'WeekMoveInput', 'response' => 'Week', 'errors' => [400, 403, 422],
                'description' => "from / to null = « À répartir ». quantity absente = tout le bloc. Jamais vers un jour passé (DAY_IN_PAST) ni hors semaine (DAY_NOT_IN_WEEK). Un bloc après l'échéance de la commande est signalé (late), pas refusé. "
                    . "À l'ordonnancement, un OF finit au plus tard le dernier jour où son produit est placé (MTS : ce jour devient l'échéance)."],
            'POST /planning/week/distribute' => ['tag' => $t, 'id' => 'distributeWeek', 'summary' => 'Rozdziel na dni / Répartir par jour', 'body' => 'WeekDistributeInput', 'response' => 'Week', 'errors' => [400, 403, 409, 422],
                'description' => "Remplit les jours à partir d'aujourd'hui, au plus tôt, échéances les plus proches d'abord ; découpe en cartons entiers quand un jour est plein, jamais après l'échéance (sauf échéance passée). "
                    . "reset = retire d'abord les placements à partir d'aujourd'hui. Seulement les groupes où l'utilisateur a prod.gantt.move. 409 WEEK_IN_PAST si la semaine est finie."],
            'POST /planning/week/pin' => ['tag' => $t, 'id' => 'pinWeekProduct', 'summary' => '📌 Co tydzień / Chaque semaine', 'body' => 'WeekPinInput', 'response' => 'Week', 'errors' => [400, 403, 422],
                'description' => "pinned = true : la quantité du produit sur ce jour devient un lot fixe (ce jour de la semaine, chaque semaine suivante) ; false : plus de lot fixe ce jour-là. "
                    . "La semaine courante reste telle quelle ; les semaines suivantes où le lot était déjà posé sont annulées (reposées avec la nouvelle quantité, ou plus du tout). "
                    . "422 NOTHING_ON_DAY (rien sur ce jour), NOT_PINNED (pas de lot fixe à retirer)."],
            'POST /planning/week/time' => ['tag' => $t, 'id' => 'setWeekSlotTime', 'summary' => 'Godzina startu bloku / Heure de début du bloc', 'body' => 'WeekTimeInput', 'response' => 'Week', 'errors' => [400, 403, 422],
                'description' => "Semaine type : heure de début du produit ce jour-là (time null = sans heure). Si le produit a un lot fixe 📌 ce jour de la semaine, le lot fixe prend la même heure (posé à cette heure chaque semaine). "
                    . "Un bloc déplacé en entier vers un jour vide garde son heure. 422 NOTHING_ON_DAY, INVALID_TIME."],
            'POST /planning/week/clear' => ['tag' => $t, 'id' => 'clearWeek', 'summary' => 'Wyczyść tydzień / Tout annuler', 'body' => 'WeekDistributeInput', 'response' => 'Week', 'errors' => [400, 403, 409, 422],
                'description' => "Retire tous les produits des jours à partir d'aujourd'hui (jours passés inchangés), dans les groupes où l'utilisateur a prod.gantt.move : tout revient dans « À produire ». "
                    . "Les lots fixes 📌 restent des règles et ne reviennent pas d'eux-mêmes cette semaine ; « Organiser la semaine » les replace d'abord sur leurs jours."],
            'POST /planning/plans/{id:\d+}/validate' => ['tag' => $t, 'id' => 'validatePlan', 'summary' => 'Zatwierdź plan i utwórz zlecenia / Valider et créer les OF', 'response' => 'Plan', 'errors' => [404, 409],
                'description' => 'Un seul plan validé par période (409 PLAN_OVERLAP). OF MTO reliés aux commandes clients, OF SEMI reliés à leur OF parent et dus à son démarrage au plus tard.'],
        ];
    }

    public static function schemas(): array
    {
        $num = ['type' => 'number'];
        $int = ['type' => 'integer'];
        $nInt = ['type' => 'integer', 'nullable' => true];
        $qty = ['qty_mto' => $num, 'qty_mts' => $num, 'qty_semi' => $num, 'qty_fixed' => ['type' => 'number', 'description' => 'lots fixes 📌 au-delà des commandes et du réassort'], 'qty_proposed' => $num, 'batches' => $int, 'standard_hours' => $num,
            'batch_size' => ['type' => 'number', 'nullable' => true, 'description' => 'partie de production (fiche, étape « Etapy i czasy ») ; quantités arrondies au multiple']];
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
                    'unit' => ['type' => 'string'], 'ppc' => $nInt, 'customer_orders' => $int, 'late_orders' => ['type' => 'integer', 'description' => 'passées après la clôture'],
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
                'materials' => ['type' => 'array', 'description' => 'Surowce i opakowania : sztuki ÷ szt./karton × receptura na karton (wypłaszczona)', 'items' => ['type' => 'object', 'properties' => [
                    'material_id' => $int, 'name' => ['type' => 'string'], 'unit' => ['type' => 'string', 'nullable' => true], 'packaging' => ['type' => 'boolean'],
                    'quantity' => $num, 'price_per_unit' => ['type' => 'number', 'nullable' => true], 'value' => ['type' => 'number', 'nullable' => true], 'products' => $int]]],
                'orders' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => $int, 'code' => ['type' => 'string', 'example' => 'OF-20261020-001'], 'item_id' => $int, 'name' => ['type' => 'string'],
                    'quantity' => $num, 'unit' => ['type' => 'string'], 'origin' => ['type' => 'string', 'enum' => ['MTO', 'MTS', 'SEMI']], 'parent_order_id' => $nInt,
                    'due_at' => ['type' => 'string'], 'priority' => $int, 'status' => ['type' => 'string'], 'customer_orders' => $int,
                ]]],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true], 'description' => 'après création'],
            ]],
            'ScheduleResult' => ['type' => 'object', 'properties' => [
                'plan_id' => $int, 'operations' => ['type' => 'integer', 'description' => 'opérations posées'],
                'conflicts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'order_id' => $int, 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'due_at' => ['type' => 'string'], 'step_id' => $nInt,
                    'reason' => ['type' => 'string', 'enum' => ['NO_CAPACITY_BEFORE_DUE', 'NO_ROUTING', 'DUE_IN_PAST', 'PARENT_NOT_SCHEDULED']], 'message' => ['type' => 'string'],
                ]]],
                'load' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Load']],
            ]],
            'Operation' => ['type' => 'object', 'properties' => [
                'id' => $int, 'order_id' => $int, 'order_code' => ['type' => 'string'], 'origin' => ['type' => 'string'], 'parent_order_id' => $nInt,
                'item_id' => $int, 'item' => ['type' => 'string'], 'quantity' => $num, 'unit' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['work', 'cleaning', 'delay']], 'step_no' => $nInt, 'step' => ['type' => 'string', 'nullable' => true],
                'workstation_id' => $int, 'workstation' => ['type' => 'string'], 'workstation_name' => ['type' => 'string'], 'workstation_type' => ['type' => 'string'],
                'group_id' => $int, 'team' => ['type' => 'string', 'nullable' => true], 'operators' => $num,
                'start' => ['type' => 'string'], 'end' => ['type' => 'string'], 'due_at' => ['type' => 'string'], 'status' => ['type' => 'string'], 'locked' => ['type' => 'boolean'],
            ]],
            'OptimizerRow' => ['type' => 'object', 'properties' => [
                'key' => ['type' => 'string'], 'date' => ['type' => 'string', 'format' => 'date'], 'shift_id' => $int, 'shift' => ['type' => 'string'],
                'group_id' => $int, 'group' => ['type' => 'string'], 'color' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string'],
                'shift_h' => $num, 'headcount' => $int, 'base_headcount' => $int, 'overtime_h' => $num, 'required_h' => $num, 'max_operators' => $int,
                'staffing' => ['type' => 'string', 'nullable' => true, 'enum' => ['optimizer', 'manual', 'simulation', null]],
                'before' => ['$ref' => '#/components/schemas/ShiftState'], 'after' => ['$ref' => '#/components/schemas/ShiftState'],
                'actions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'lever' => ['type' => 'string', 'enum' => ShiftOptimizer::LEVERS], 'action' => ['type' => 'string', 'enum' => ['close', 'reduce', 'add', 'open']],
                    'people' => $int, 'hours' => $num, 'item_id' => $int, 'item' => ['type' => 'string'], 'quantity' => $num, 'plan_id' => $nInt,
                    'from_group_id' => $int, 'to_group_id' => $int,
                ]]],
            ]],
            'ShiftState' => ['type' => 'object', 'properties' => [
                'headcount' => $int, 'overtime_h' => $num, 'required_h' => $num, 'available_h' => $num, 'load_pct' => ['type' => 'number', 'nullable' => true],
                'status' => ['type' => 'string', 'enum' => ['under', 'ok', 'over', 'no_team', 'closed']],
            ]],
            'ShiftSummary' => ['type' => 'object', 'properties' => [
                'required_h' => $num, 'available_h' => $num, 'load_pct' => ['type' => 'number', 'nullable' => true], 'green' => $int, 'shifts' => $int,
            ]],
            'OptimizerProposal' => ['type' => 'object', 'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date'],
                'levers' => ['type' => 'array', 'items' => ['type' => 'string']], 'config' => ['type' => 'object'],
                'rows' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OptimizerRow']],
                'summary' => ['type' => 'object', 'properties' => ['before' => ['$ref' => '#/components/schemas/ShiftSummary'], 'after' => ['$ref' => '#/components/schemas/ShiftSummary']]],
                'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
            'OptimizerApplyResult' => ['type' => 'object', 'properties' => [
                'staffing_rows' => $int, 'mts_orders' => $int, 'mts_not_placed' => $int, 'mts_skipped_no_plan' => $int, 'operations' => $int,
                'conflicts' => ['type' => 'array', 'items' => ['type' => 'object']],
                'summary_before' => ['$ref' => '#/components/schemas/ShiftSummary'], 'summary_after' => ['$ref' => '#/components/schemas/ShiftSummary'],
            ]],
            'PeriodInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['from', 'to'], 'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date'],
            ]],
            'SimulationInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['from', 'to'], 'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date'],
                'staffing' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['date', 'group_id', 'shift_id', 'headcount'], 'properties' => [
                    'date' => ['type' => 'string', 'format' => 'date'], 'group_id' => $int, 'shift_id' => $int, 'headcount' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50],
                    'overtime_h' => ['type' => 'number', 'minimum' => 0, 'maximum' => 200],
                ]]],
            ]],
            'SimulationResult' => ['type' => 'object', 'properties' => [
                'rows' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OptimizerRow']], 'summary' => ['$ref' => '#/components/schemas/ShiftSummary'],
            ]],
            'StaffingInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['date', 'group_id', 'shift_id', 'headcount'], 'properties' => [
                'date' => ['type' => 'string', 'format' => 'date'], 'group_id' => $int, 'shift_id' => $int,
                'headcount' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'nullable' => true, 'description' => 'null = retour aux équipes'],
                'overtime_h' => ['type' => 'number', 'minimum' => 0, 'maximum' => 200], 'note' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
            ]],
            'OperationInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'start' => ['type' => 'string', 'example' => '2026-10-21 08:30', 'description' => 'AAAA-MM-JJ HH:MM[:SS]'],
                'workstation_id' => $int, 'locked' => ['type' => 'boolean'],
            ]],
            'Load' => ['type' => 'object', 'properties' => [
                'group_id' => $int, 'group' => ['type' => 'string'], 'color' => ['type' => 'string'], 'date' => ['type' => 'string', 'format' => 'date'],
                'shift' => ['type' => 'string'], 'shift_id' => $int, 'night' => ['type' => 'boolean'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string'],
                'headcount' => $int, 'base_headcount' => $int, 'overtime_h' => $num, 'staffing' => ['type' => 'string', 'nullable' => true], 'shift_h' => $num, 'max_operators' => $int,
                'available_h' => $num, 'required_h' => $num, 'load_pct' => ['type' => 'number', 'nullable' => true], 'target_pct' => $num,
                'status' => ['type' => 'string', 'enum' => ['under', 'ok', 'over', 'no_team']],
            ]],
            'Week' => ['type' => 'object', 'properties' => [
                'week_start' => ['type' => 'string', 'format' => 'date', 'description' => 'premier jour de la semaine de production (week.start_day)'], 'week_end' => ['type' => 'string', 'format' => 'date'], 'iso_week' => $int,
                'week_start_day' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7, 'description' => '1 = lundi … 7 = dimanche (atelier : 3 = mercredi)'],
                'today' => ['type' => 'string', 'format' => 'date'], 'now' => ['type' => 'string'],
                'days' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string', 'format' => 'date'], 'weekday' => $int, 'past' => ['type' => 'boolean'], 'today' => ['type' => 'boolean'],
                    'stock_day' => ['type' => 'boolean', 'description' => 'jour de production pour le stock (week.stock_days)']]]],
                'source' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['plan', 'demand']], 'plans' => ['type' => 'array', 'items' => ['type' => 'object']]]],
                'can_move' => ['type' => 'boolean'], 'can_schedule' => ['type' => 'boolean'],
                'fte_hours' => ['type' => 'number', 'description' => 'heures d\'un temps plein par semaine (week.fte_hours) : ETP du groupe = heures ÷ cette valeur'],
                'pins' => ['type' => 'array', 'description' => 'tous les lots fixes 📌', 'items' => ['$ref' => '#/components/schemas/WeekPin']],
                'groups' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer', 'nullable' => true], 'name' => ['type' => 'string', 'nullable' => true], 'color' => ['type' => 'string', 'nullable' => true], 'people' => $int,
                    'hours' => $num, 'available_h' => $num, 'required_h' => $num, 'load_pct' => ['type' => 'number', 'nullable' => true], 'unassigned_h' => $num, 'over_h' => $num,
                    'done_h' => $num, 'expected_h' => $num, 'movable' => ['type' => 'boolean'], 'direct_h' => $num, 'stock_h' => $num,
                    'days' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'date' => ['type' => 'string', 'format' => 'date'], 'people' => $int, 'available_h' => $num, 'required_h' => $num, 'free_h' => $num, 'direct_h' => $num, 'stock_h' => $num,
                        'load_pct' => ['type' => 'number', 'nullable' => true], 'status' => ['type' => 'string', 'enum' => ['under', 'ok', 'over', 'no_team', 'free']],
                        'elapsed' => ['type' => 'number', 'description' => 'part du shift déjà passée (1 = jour passé)'],
                    ]]],
                ]]],
                'products' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'item_id' => $int, 'name' => ['type' => 'string'], 'item_type' => ['type' => 'string'], 'mode' => ['type' => 'string'], 'group_id' => ['type' => 'integer', 'nullable' => true],
                    'unit' => ['type' => 'string'], 'ppc' => ['type' => 'integer', 'nullable' => true], 'quantity' => $num, 'cartons' => ['type' => 'number', 'nullable' => true], 'hours' => $num,
                    'batch' => ['type' => 'number', 'nullable' => true, 'description' => 'partie de production ; « Ułóż tydzień » et « ce qui tient » placent des parties entières'],
                    'batches' => ['type' => 'number', 'nullable' => true],
                    'direct' => ['type' => 'number', 'description' => 'à expédier : commandes MTO et semi-finis, avant le départ du camion'], 'stock' => ['type' => 'number', 'description' => 'pour le stock (MTS), jours week.stock_days'],
                    'direct_cartons' => ['type' => 'number', 'nullable' => true], 'stock_cartons' => ['type' => 'number', 'nullable' => true], 'direct_h' => $num, 'stock_h' => $num,
                    'direct_parts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['due_date' => ['type' => 'string', 'format' => 'date'], 'quantity' => $num, 'cartons' => ['type' => 'number', 'nullable' => true]]]],
                    'due_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true], 'latest_day' => ['type' => 'string', 'format' => 'date'],
                    'done_h' => $num, 'done_pct' => ['type' => 'number', 'nullable' => true], 'state' => ['type' => 'string', 'enum' => ['planned', 'in_progress', 'done']],
                    'unassigned_qty' => $num, 'unassigned_h' => $num, 'over_qty' => $num, 'movable' => ['type' => 'boolean'],
                    'fixed_qty' => ['type' => 'number', 'description' => 'lots fixes 📌 posés dans la semaine (quantité minimale de la semaine)'],
                    'pins' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/WeekPin']],
                    'slots' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'day' => ['type' => 'string', 'format' => 'date'], 'time' => ['type' => 'string', 'nullable' => true, 'description' => 'heure de début HH:MM'],
                        'quantity' => $num, 'cartons' => ['type' => 'number', 'nullable' => true], 'batches' => ['type' => 'number', 'nullable' => true], 'hours' => $num, 'done_h' => $num,
                        'direct_qty' => $num, 'stock_qty' => $num, 'direct_cartons' => ['type' => 'number', 'nullable' => true], 'stock_cartons' => ['type' => 'number', 'nullable' => true], 'direct_h' => $num,
                        'stock_off_day' => ['type' => 'boolean', 'description' => 'stock placé hors des jours de stock'],
                        'late' => ['type' => 'boolean', 'description' => 'après l\'échéance de la commande'], 'behind' => ['type' => 'boolean', 'description' => 'jour passé, pas terminé'],
                    ]]],
                ]]],
                'totals' => ['type' => 'object'],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string']]]],
            ]],
            'WeekMoveInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['date', 'item_id'], 'properties' => [
                'date' => ['type' => 'string', 'format' => 'date'], 'item_id' => $int,
                'from' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'null = « À répartir »'],
                'to' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'null = retour dans « À répartir »'],
                'quantity' => ['type' => 'number', 'minimum' => 0, 'exclusiveMinimum' => true, 'nullable' => true],
            ]],
            'WeekDistributeInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['date'], 'properties' => [
                'date' => ['type' => 'string', 'format' => 'date'], 'reset' => ['type' => 'boolean'],
            ]],
            'WeekTimeInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['date', 'item_id', 'day'], 'properties' => [
                'date' => ['type' => 'string', 'format' => 'date'], 'item_id' => ['type' => 'integer'], 'day' => ['type' => 'string', 'format' => 'date'],
                'time' => ['type' => 'string', 'nullable' => true, 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$', 'description' => 'HH:MM ; null = sans heure'],
            ]],
            'WeekPinInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['date', 'item_id', 'day', 'pinned'], 'properties' => [
                'date' => ['type' => 'string', 'format' => 'date'], 'item_id' => ['type' => 'integer'], 'day' => ['type' => 'string', 'format' => 'date'], 'pinned' => ['type' => 'boolean'],
            ]],
            'WeekPin' => ['type' => 'object', 'properties' => [
                'item_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'group_id' => $nInt, 'unit' => ['type' => 'string'], 'ppc' => $nInt,
                'batch' => ['type' => 'number', 'nullable' => true], 'weekday' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                'quantity' => ['type' => 'number'], 'day' => ['type' => 'string', 'format' => 'date', 'description' => 'ce jour dans la semaine affichée'],
                'time' => ['type' => 'string', 'nullable' => true, 'description' => 'heure de début HH:MM (semaine type)'],
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
