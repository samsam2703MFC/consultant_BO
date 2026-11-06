<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Manager\CompetencyQuestionnaire;
use App\Services\Workshop\Manager\ManagerIndicators;
use App\Services\Workshop\Manager\ManagerService;

/** Documentation OpenAPI du domaine « responsable production » : fiche de poste, objectifs, suivi mensuel, prime, entretien, audit des compétences. */
final class OpenApiManager
{
    public static function operations(): array
    {
        $t = 'manager';
        $month = ['month' => ['type' => 'string', 'example' => '2026-09', 'description' => 'défaut : mois précédent']];
        return [
            'GET /manager/plan' => ['tag' => $t, 'id' => 'getManagerPlan', 'summary' => 'Plan roku i cele / Plan de l\'année et objectifs', 'response' => 'ManagerPlan', 'errors' => [422],
                'query' => ['year' => ['type' => 'integer', 'example' => 2026]],
                'description' => "Titulaire, salaire, enveloppe (défaut 20 % du brut annuel), heures par mission, objectifs (brouillon, validé, historique) "
                    . "et indication : réalisé des 3 derniers mois complets (ERP)."],
            'PUT /manager/plan' => ['tag' => $t, 'id' => 'saveManagerPlan', 'summary' => 'Zapisz plan roku / Enregistrer le plan de l\'année', 'body' => 'ManagerPlanInput', 'response' => 'ManagerPlan', 'errors' => [400, 422]],
            'PUT /manager/objectives' => ['tag' => $t, 'id' => 'saveManagerObjectives', 'summary' => 'Szkice celów / Brouillons d\'objectifs', 'body' => 'ManagerObjectivesInput', 'response' => 'ManagerPlan', 'errors' => [400, 422],
                'description' => 'null supprime le brouillon. Un objectif validé ne change pas : la nouvelle valeur est un brouillon jusqu\'à validation.'],
            'POST /manager/objectives/validate' => ['tag' => $t, 'id' => 'validateManagerObjectives', 'summary' => 'Zatwierdź cele / Valider les objectifs', 'body' => 'ManagerYearInput', 'response' => 'ManagerPlan', 'errors' => [400, 409, 422],
                'description' => "Un objectif ne compte pour la prime d'une période que s'il a été validé avant son début (sinon pas de prime pour la section). MANAGER_NO_DRAFTS (409) : rien à valider."],
            'GET /manager/report' => ['tag' => $t, 'id' => 'getManagerReport', 'summary' => 'Raport miesięczny / Suivi mensuel', 'response' => 'ManagerReport', 'errors' => [422], 'query' => $month,
                'description' => "1. Alertes (HACCP d'abord, puis indicateurs < 90 %). 2. Indicateurs : objectif, réalisé, écart, statut (vert ≥ 100 %, orange 90–100 %, rouge < 90 %). "
                    . "3. Nouveaux produits (marge et volume réels vs objectifs, décision recommandée). 4. Données manquantes ou non vérifiées. 5. Trois actions (proposées si aucune n'est enregistrée)."],
            'PUT /manager/values' => ['tag' => $t, 'id' => 'saveManagerValue', 'summary' => 'Wartość ręczna miesiąca / Valeur saisie du mois', 'body' => 'ManagerValueInput', 'response' => 'ManagerReport', 'errors' => [400, 422],
                'description' => "Seulement pour les indicateurs que l'ERP ne calcule pas (ruptures bloquantes ; pertes sans tablettes ; heures sup sans pointage). Saisie par le titulaire = « non vérifiée »."],
            'POST /manager/haccp' => ['tag' => $t, 'id' => 'addHaccpEvent', 'summary' => 'Zdarzenie HACCP / Événement HACCP', 'body' => 'HaccpEventInput', 'response' => 'HaccpEvent', 'ok' => 201, 'errors' => [400, 422],
                'description' => "Contrôle officiel (résultat obligatoire), rupture de la chaîne du froid, audit indépendant (score obligatoire), auto-contrôle. "
                    . "Bloquent toute la prime de la période : contrôle défavorable, rupture non traitée, audit sous le seuil du plan."],
            'PATCH /manager/haccp/{id:\d+}' => ['tag' => $t, 'id' => 'updateHaccpEvent', 'summary' => 'Zmień zdarzenie HACCP (np. obsłużone) / Modifier un événement HACCP', 'body' => 'HaccpEventInput', 'response' => 'HaccpEvent', 'errors' => [400, 404, 422]],
            'DELETE /manager/haccp/{id:\d+}' => ['tag' => $t, 'id' => 'deleteHaccpEvent', 'summary' => 'Usuń zdarzenie HACCP / Supprimer un événement HACCP', 'response' => 'ManagerDeleted', 'errors' => [404]],
            'PUT /manager/launches/{id:\d+}' => ['tag' => $t, 'id' => 'saveProductLaunch', 'summary' => 'Cele wprowadzenia produktu / Objectifs de lancement d\'un produit', 'body' => 'ProductLaunchInput', 'response' => 'ProductLaunch', 'errors' => [400, 404, 422],
                'description' => 'id = produit ERP (final_product). Coût de revient et prix cibles (EUR / carton), marge brute minimale, volume attendu (cartons / semaine / boutique), décision.'],
            'PUT /manager/actions' => ['tag' => $t, 'id' => 'saveManagerActions', 'summary' => 'Trzy działania na następny miesiąc / Trois actions du mois suivant', 'body' => 'ManagerActionsInput', 'response' => 'ManagerReport', 'errors' => [400, 422]],
            'GET /manager/bonus' => ['tag' => $t, 'id' => 'getManagerBonus', 'summary' => 'Premia za kwartał / Prime du trimestre', 'response' => 'ManagerBonus', 'errors' => [422],
                'query' => ['period' => ['type' => 'string', 'example' => '2026-Q3']],
                'description' => "Prime d'une section = enveloppe × poids × prorata de la période × coefficient (< 90 % → 0 ; puis proportionnel, plafonné à 120 %). "
                    . "Sections trimestrielles sur le trimestre, développement produits sur le semestre (Q2, Q4). Objectif validé avant le début de la période, sinon pas de prime. "
                    . "Condition HACCP d'abord ; pas de compensation entre sections."],
            'GET /manager/interview' => ['tag' => $t, 'id' => 'getManagerInterview', 'summary' => 'Przygotowanie rozmowy / Préparation d\'entretien', 'response' => 'ManagerInterview', 'errors' => [422], 'query' => $month,
                'description' => '3 derniers mois jusqu\'au mois donné : points forts (vert ≥ 2 mois sur 3), écarts récurrents, objectifs proposés (meilleur mois s\'il bat l\'objectif), dernier audit des compétences.'],
            'GET /manager/audits' => ['tag' => $t, 'id' => 'listCompetencyAudits', 'summary' => 'Audyt kompetencji : kwestionariusz i historia / Audit des compétences', 'response' => 'CompetencyAudits'],
            'POST /manager/audits' => ['tag' => $t, 'id' => 'addCompetencyAudit', 'summary' => 'Nowy audyt kompetencji / Nouvel audit des compétences', 'body' => 'CompetencyAuditInput', 'response' => 'CompetencyAudits', 'ok' => 201, 'errors' => [400, 422],
                'description' => 'Une note 0–3 par question (0 absent, 1 à développer, 2 maîtrisé = attendu, 3 expert), commentaire optionnel ; toutes les questions sont obligatoires (INCOMPLETE).'],
        ];
    }

    public static function schemas(): array
    {
        $num = ['type' => 'number'];
        $nNum = ['type' => 'number', 'nullable' => true];
        $nStr = ['type' => 'string', 'nullable' => true];
        $date = ['type' => 'string', 'format' => 'date'];
        $nDate = ['type' => 'string', 'format' => 'date', 'nullable' => true];
        $indicator = ['type' => 'string', 'enum' => array_keys(ManagerIndicators::INDICATORS)];
        $obj = ['type' => 'object', 'additionalProperties' => true];
        $measure = ['type' => 'object', 'properties' => [
            'code' => $indicator, 'section' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'target' => $nNum, 'value' => $nNum, 'attainment' => $nNum,
            'status' => ['type' => 'string', 'enum' => ['green', 'orange', 'red'], 'nullable' => true], 'origin' => ['type' => 'string', 'enum' => ['erp', 'manual'], 'nullable' => true],
            'unverified' => ['type' => 'boolean'], 'missing' => ['type' => 'string', 'nullable' => true, 'description' => 'raison : NO_SALES, NO_TABLET_DATA, NO_MARGIN_THRESHOLD, NO_NEW_PRODUCTS, NO_PRICES, MANUAL_ENTRY_MISSING, NO_EUR_RATE, NO_COST_MONTH, NO_PRODUCTION, NO_CLOCK_DATA, NO_ORDERS, NO_LOGISTICS_COST'],
            'detail' => $obj,
        ]];
        $plan = ['type' => 'object', 'properties' => [
            'exists' => ['type' => 'boolean'], 'year' => ['type' => 'integer'], 'holder_user_id' => ['type' => 'integer', 'nullable' => true], 'holder_name' => $nStr, 'holder_start' => $nDate,
            'salary_monthly' => $nNum, 'envelope_pct' => $num, 'currency' => ['type' => 'string'], 'team_organisation' => $nStr, 'auditor' => $nStr, 'audit_threshold' => $nNum,
            'margin_threshold_pct' => $nNum, 'price_reference_date' => $nDate, 'workplace' => $nStr, 'contract_type' => $nStr, 'working_time' => $nStr,
            'monthly_hours' => $nNum, 'mission_hours' => ['type' => 'object', 'additionalProperties' => $nNum, 'description' => 'heures par mois et par mission : ' . implode(', ', ManagerIndicators::MISSIONS)],
            'envelope_annual' => $nNum, 'monthly_at_target' => ['type' => 'number', 'nullable' => true, 'description' => 'brut mensuel, tous les objectifs à 100 %'],
            'monthly_at_cap' => ['type' => 'number', 'nullable' => true, 'description' => 'brut mensuel au plafond de 120 %'],
        ]];
        return [
            'ManagerPlan' => ['type' => 'object', 'properties' => [
                'year' => ['type' => 'integer'], 'plan' => $plan, 'drafts' => ['type' => 'integer'], 'can_write' => ['type' => 'boolean'], 'can_report' => ['type' => 'boolean'],
                'indicators' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'code' => $indicator, 'section' => ['type' => 'string'], 'direction' => ['type' => 'string', 'enum' => ['higher', 'lower', 'index']], 'unit' => ['type' => 'string'],
                    'source' => ['type' => 'string', 'enum' => ['erp', 'manual', 'erp+manual']], 'default' => $nNum, 'draft' => $nNum, 'validated' => ['type' => 'object', 'nullable' => true],
                    'history' => ['type' => 'array', 'items' => $obj], 'hint' => $nNum, 'hint_missing' => $nStr,
                ]]],
                'sections' => ['type' => 'array', 'items' => $obj], 'missions' => ['type' => 'array', 'items' => ['type' => 'string']], 'hint_period' => $obj,
                'users' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]]],
            ]],
            'ManagerPlanInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['year'], 'properties' => [
                'year' => ['type' => 'integer'], 'holder_user_id' => ['type' => 'integer', 'nullable' => true], 'holder_name' => $nStr, 'holder_start' => $nDate,
                'salary_monthly' => ['type' => 'number', 'nullable' => true, 'description' => 'brut mensuel fixe'], 'envelope_pct' => ['type' => 'number', 'description' => '% du brut annuel (défaut 20)'],
                'currency' => ['type' => 'string', 'example' => 'PLN'], 'team_organisation' => $nStr, 'auditor' => $nStr, 'audit_threshold' => ['type' => 'number', 'nullable' => true, 'description' => 'score minimal de l\'audit indépendant (%)'],
                'margin_threshold_pct' => ['type' => 'number', 'nullable' => true, 'description' => 'seuil de marge brute (%)'], 'price_reference_date' => $nDate,
                'workplace' => $nStr, 'contract_type' => $nStr, 'working_time' => $nStr, 'monthly_hours' => $nNum,
                'mission_hours' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => $nNum],
            ]],
            'ManagerObjectivesInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['year', 'objectives'], 'properties' => [
                'year' => ['type' => 'integer'], 'objectives' => ['type' => 'object', 'additionalProperties' => $nNum, 'description' => 'indicateur → objectif (null supprime le brouillon)'],
            ]],
            'ManagerYearInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['year'], 'properties' => ['year' => ['type' => 'integer']]],
            'ManagerReport' => ['type' => 'object', 'properties' => [
                'month' => ['type' => 'string'], 'from' => $date, 'to' => $date, 'extracted_at' => ['type' => 'string'], 'partial' => ['type' => 'boolean'], 'plan' => $plan,
                'haccp' => ['type' => 'object', 'properties' => ['events' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/HaccpEvent']],
                    'blocking' => ['type' => 'array', 'items' => $obj], 'unverified' => ['type' => 'array', 'items' => ['type' => 'integer']], 'audit_threshold' => $nNum]],
                'alerts' => ['type' => 'array', 'items' => $obj], 'indicators' => ['type' => 'array', 'items' => $measure],
                'products' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'product_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'launch_date' => $date, 'weeks' => ['type' => 'integer'], 'min_margin_pct' => $nNum,
                    'expected_week_shop' => $nNum, 'margin_pct' => $nNum, 'per_shop_week' => $num, 'shops' => ['type' => 'integer'],
                    'recommendation' => ['type' => 'string', 'enum' => ['keep', 'fix', 'stop', 'too_early', 'targets_missing']], 'decision' => $nStr,
                ], 'additionalProperties' => true]],
                'missing' => ['type' => 'array', 'items' => $obj],
                'actions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'owner' => $nStr, 'due_date' => $nDate,
                    'status' => ['type' => 'string', 'enum' => ['open', 'done']], 'indicator' => $nStr,
                    'kind' => ['type' => 'string', 'enum' => ['gap', 'data'], 'nullable' => true, 'description' => 'action proposée : écart à corriger ou donnée à rendre mesurable']]]],
                'actions_suggested' => ['type' => 'boolean'], 'can_report' => ['type' => 'boolean'], 'can_write' => ['type' => 'boolean'],
            ]],
            'ManagerValueInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['month', 'indicator', 'value'], 'properties' => [
                'month' => ['type' => 'string', 'example' => '2026-09'], 'indicator' => ['type' => 'string', 'enum' => array_keys(array_filter(ManagerIndicators::INDICATORS, fn($d) => $d['source'] !== 'erp'))],
                'value' => ['type' => 'number', 'nullable' => true, 'minimum' => 0], 'note' => $nStr,
            ]],
            'HaccpEvent' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'event_date' => $date, 'kind' => ['type' => 'string', 'enum' => ManagerService::HACCP_KINDS], 'authority' => $nStr,
                'result' => ['type' => 'string', 'enum' => ['favorable', 'unfavorable'], 'nullable' => true], 'score' => $nNum, 'treated' => ['type' => 'boolean'], 'treated_at' => $nDate,
                'description' => $nStr, 'unverified' => ['type' => 'boolean', 'description' => 'saisi par le titulaire'],
            ]],
            'HaccpEventInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['event_date', 'kind'], 'properties' => [
                'event_date' => $date, 'kind' => ['type' => 'string', 'enum' => ManagerService::HACCP_KINDS], 'authority' => ['type' => 'string', 'nullable' => true, 'example' => 'Sanepid'],
                'result' => ['type' => 'string', 'enum' => ['favorable', 'unfavorable'], 'nullable' => true], 'score' => ['type' => 'number', 'nullable' => true, 'minimum' => 0, 'maximum' => 100],
                'treated' => ['type' => 'boolean'], 'treated_at' => $nDate, 'description' => $nStr,
            ]],
            'ManagerDeleted' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean']]],
            'ProductLaunchInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['launch_date'], 'properties' => [
                'launch_date' => $date, 'target_cost' => $nNum, 'target_price' => $nNum, 'min_margin_pct' => $nNum, 'expected_week_shop' => $nNum,
                'decision' => ['type' => 'string', 'enum' => ['keep', 'fix', 'stop'], 'nullable' => true], 'decision_note' => $nStr,
            ]],
            'ProductLaunch' => ['type' => 'object', 'additionalProperties' => true, 'properties' => ['product_id' => ['type' => 'integer'], 'launch_date' => $date]],
            'ManagerActionsInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['month', 'actions'], 'properties' => [
                'month' => ['type' => 'string'], 'actions' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['text'], 'properties' => [
                    'text' => ['type' => 'string', 'maxLength' => 500], 'owner' => $nStr, 'due_date' => $nDate, 'status' => ['type' => 'string', 'enum' => ['open', 'done']],
                ]]],
            ]],
            'ManagerBonus' => ['type' => 'object', 'properties' => [
                'period' => ['type' => 'string'], 'from' => $date, 'to' => $date, 'extracted_at' => ['type' => 'string'], 'plan' => $plan, 'total' => $num,
                'haccp' => ['type' => 'object', 'properties' => ['blocked' => ['type' => 'boolean'], 'blocking' => ['type' => 'array', 'items' => $obj], 'events' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/HaccpEvent']]]],
                'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'code' => ['type' => 'string'], 'weight' => $num, 'period' => $obj, 'indicators' => ['type' => 'array', 'items' => $measure],
                    'attainment' => $nNum, 'coefficient' => $nNum, 'presence' => $nNum, 'share' => $nNum, 'amount' => $nNum,
                    'status' => ['type' => 'string', 'enum' => ['ok', 'no_objective', 'missing_data', 'no_salary', 'haccp_blocked']],
                    'provisional' => ['type' => 'boolean', 'description' => 'période pas encore finie : montant provisoire'],
                ]]],
                'missing' => ['type' => 'array', 'items' => $obj],
            ]],
            'ManagerInterview' => ['type' => 'object', 'properties' => [
                'month' => ['type' => 'string'], 'months' => ['type' => 'array', 'items' => ['type' => 'string']], 'plan' => $plan,
                'indicators' => ['type' => 'array', 'items' => $obj], 'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recurring_gaps' => ['type' => 'array', 'items' => $obj], 'no_data' => ['type' => 'array', 'items' => ['type' => 'string']],
                'competency' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true],
            ]],
            'CompetencyAudits' => ['type' => 'object', 'properties' => [
                'areas' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']], 'example' => ['quality' => CompetencyQuestionnaire::AREAS['quality']]],
                'scale' => ['type' => 'array', 'items' => ['type' => 'integer']], 'expected' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'audits' => ['type' => 'array', 'items' => $obj], 'can_report' => ['type' => 'boolean'], 'created_id' => ['type' => 'integer'],
            ]],
            'CompetencyAuditInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['audit_date', 'assessed_name', 'answers'], 'properties' => [
                'audit_date' => $date, 'assessed_name' => ['type' => 'string', 'maxLength' => 120], 'assessed_user_id' => ['type' => 'integer', 'nullable' => true],
                'answers' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'required' => ['score'], 'properties' => ['score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3], 'comment' => $nStr]]],
                'comment' => $nStr,
            ]],
        ];
    }
}
