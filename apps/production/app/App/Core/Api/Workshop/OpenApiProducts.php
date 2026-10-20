<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Product\ItemService;
use App\Services\Workshop\Product\TimeStudyService;

/** Documentation OpenAPI du domaine « produits » (P2 : fiche, gamme, temps standards, chronométrage). */
final class OpenApiProducts
{
    public static function operations(): array
    {
        $t = 'produits';
        return [
            'GET /produits/items' => ['tag' => $t, 'id' => 'listItems', 'summary' => 'Artykuły planowane / Articles planifiables', 'response' => 'ItemSummary', 'list' => true, 'errors' => [422],
                'query' => ['item_type' => ['type' => 'string', 'enum' => ItemService::TYPES], 'group_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ItemService::STATUSES], 'mode' => ['type' => 'string', 'enum' => ItemService::MODES], 'search' => ['type' => 'string', 'maxLength' => 100],
                    'final_product_id' => ['type' => 'integer', 'description' => 'karta produktu ERP (edycja produktu otwiera tę kartę)']]],
            'GET /produits/erp-options' => ['tag' => $t, 'id' => 'getItemErpOptions', 'summary' => 'Listy kroku « Produkt » / Listes de l\'étape « Produit »', 'response' => 'ErpOptions', 'errors' => [],
                'description' => 'Catégories de produits et départements de production de l\'ERP, types d\'étiquette (0 petite, 1 grande).'],
            'POST /produits/items' => ['tag' => $t, 'id' => 'createItem', 'summary' => 'Nowa karta z produktu ERP / Nouvelle fiche depuis un article de l\'ERP (étape 1)', 'body' => 'ItemInput', 'response' => 'Item', 'ok' => 201, 'errors' => [400, 409, 422],
                'description' => 'Le produit fini (final_product) ou le semi-fini (product_recipe) existe déjà dans l\'ERP ; rien n\'est recopié. 409 ITEM_EXISTS si l\'article a déjà une fiche.'],
            'GET /produits/candidates' => ['tag' => $t, 'id' => 'listItemCandidates', 'summary' => 'Produkty ERP bez karty / Articles de l\'ERP sans fiche', 'response' => 'ItemCandidate', 'list' => true, 'errors' => [422],
                'query' => ['item_type' => ['type' => 'string', 'enum' => ItemService::TYPES], 'search' => ['type' => 'string', 'maxLength' => 100]]],
            'GET /produits/items/{id:\d+}' => ['tag' => $t, 'id' => 'getItem', 'summary' => 'Karta produkcyjna / Fiche de production complète', 'response' => 'Item', 'errors' => [404]],
            'PATCH /produits/items/{id:\d+}/settings' => ['tag' => $t, 'id' => 'updateItemSettings', 'summary' => 'Tryb, zapasy, grupa, trwałość / Mode, stocks, groupe, durées de vie (étapes 2–3)', 'body' => 'ItemSettingsInput', 'response' => 'Item', 'errors' => [400, 404, 422],
                'description' => 'Jamais congelé ⇒ jamais MTS (NEVER_FROZEN_MTS) ; MTS ⇒ stock_max obligatoire ; stock_max ≥ stock_min.'],
            'PUT /produits/items/{id:\d+}/product' => ['tag' => $t, 'id' => 'updateItemProduct', 'summary' => 'Dane produktu ERP / Données du produit ERP (étape 1)', 'body' => 'ItemProductInput', 'response' => 'Item', 'errors' => [400, 403, 404, 409, 422],
                'description' => "Mêmes champs que l'ancien formulaire d'édition du produit (final_product). Changer de département place la fiche dans le groupe de ce département. Produits seulement (409 NOT_ALLOWED_FOR_TYPE)."],
            'GET /produits/items/{id:\d+}/production-summary' => ['tag' => $t, 'id' => 'getItemProductionSummary', 'summary' => 'Produkcja i Gantt / Production et Gantt (étape 7)', 'response' => 'ProductionSummary', 'errors' => [404],
                'description' => "Rendement (pièces par heure de travail, par personne et pour le groupe), temps par carton et pour 1000 pièces, durée du début à la fin d'un lot (palette, sinon lot minimum, sinon 1000), étapes avec début depuis le départ et étape suivante : tout de suite, + minutes ou J+1 quand l'attente dure 8 h ou plus ; quantité de la semaine (plan), jours du Gantt de la semaine, opérations."],
            'GET /produits/items/{id:\d+}/stock-suggestion' => ['tag' => $t, 'id' => 'getItemStockSuggestion', 'summary' => 'Stany ze sprzedaży / Stocks depuis les ventes (étape 4)', 'response' => 'StockSuggestion', 'errors' => [404, 409],
                'description' => "Ventes WDT (sans doublons) des stock.suggest_weeks dernières semaines : moyenne par semaine ; stock min = stock.suggest_min_weeks semaines, max = stock.suggest_max_weeks semaines, arrondis aux cartons entiers. Rien n'est enregistré."],
            'PUT /produits/items/{id:\d+}/logistics' => ['tag' => $t, 'id' => 'updateItemLogistics', 'summary' => 'Logistyka: karton, paleta, przechowywanie / Logistique : carton, palette, conservation (étape 1)', 'body' => 'ItemLogisticsInput', 'response' => 'Item', 'errors' => [400, 404, 409, 422],
                'description' => 'Poids, pièces par sachet / carton, cartons par couche et par palette : champs de l\'ERP (final_product). Carton (dimensions, tare, type, EAN), palette et conservation : fiche de production. 409 NOT_ALLOWED_FOR_TYPE pour un semi-fini ; cpp ≤ cpl × lpp (PALLET_OVER_LAYERS).'],
            'PUT /produits/items/{id:\d+}/routing' => ['tag' => $t, 'id' => 'replaceItemRouting', 'summary' => 'Marszruta / Gamme opératoire (étape 5)', 'body' => 'RoutingInput', 'response' => 'Item', 'errors' => [400, 404, 422],
                'description' => 'Remplace la gamme : étapes avec id mises à jour, sans id créées, absentes supprimées (avec leurs chronométrages). Durée d\'une étape = temps fixe × ⌈quantité ÷ capacité⌉ + temps par pièce × quantité.'],
            'PUT /produits/items/{id:\d+}/bom-losses' => ['tag' => $t, 'id' => 'saveItemBomLosses', 'summary' => 'Straty składników / Pertes de la nomenclature (étape 4)', 'body' => 'BomLossesInput', 'response' => 'Item', 'errors' => [400, 404, 422]],
            'POST /produits/items/{id:\d+}/validate' => ['tag' => $t, 'id' => 'validateItem', 'summary' => 'Zatwierdź kartę / Valider la fiche (étape 7)', 'response' => 'Item', 'errors' => [404, 422],
                'description' => 'Refusé (422) tant que la liste de contrôle n\'est pas complète : groupe, mode cohérent, stock max en MTS, au moins une étape, un temps par étape ; produit : pièces par carton, cuisson renseignée, four dans la gamme si cuit ou précuit (BAKE_WITHOUT_OVEN).'],
            'GET /produits/items/{id:\d+}/duration' => ['tag' => $t, 'id' => 'getItemDuration', 'summary' => 'Czas standardowy dla ilości / Temps standard pour une quantité (étape 6)', 'response' => 'Duration', 'errors' => [404, 422],
                'query' => ['quantity' => ['type' => 'number', 'exclusiveMinimum' => true, 'minimum' => 0]]],
            'GET /produits/routing-steps/{id:\d+}/time-studies' => ['tag' => $t, 'id' => 'listTimeStudies', 'summary' => 'Pomiary czasu i propozycja normy / Chronométrages et norme proposée', 'response' => 'TimeStudies', 'errors' => [404]],
            'POST /produits/routing-steps/{id:\d+}/time-studies' => ['tag' => $t, 'id' => 'recordTimeStudy', 'summary' => 'Zapisz pomiar / Enregistrer un chronométrage', 'body' => 'TimeStudyInput', 'response' => 'TimeStudies', 'ok' => 201, 'errors' => [400, 404, 422],
                'description' => 'Temps unitaire déduit : par pièce = (durée − temps fixe × passages) ÷ quantité ; par passage = (durée − temps par pièce × quantité) ÷ passages.'],
            'PATCH /produits/time-studies/{id:\d+}' => ['tag' => $t, 'id' => 'updateTimeStudy', 'summary' => 'Wyklucz / przywróć pomiar / Écarter ou reprendre une mesure', 'body' => 'TimeStudyUpdateInput', 'response' => 'TimeStudies', 'errors' => [400, 404, 422]],
            'POST /produits/routing-steps/{id:\d+}/apply-standard' => ['tag' => $t, 'id' => 'applyStandard', 'summary' => 'Nowa norma ze średniej pomiarów / Appliquer la norme proposée', 'body' => 'ApplyStandardInput', 'response' => 'TimeStudies', 'errors' => [400, 404, 409, 422],
                'description' => 'Moyenne des timing.moving_average_n dernières mesures retenues ; 409 NOT_ENOUGH_MEASUREMENTS sous timing.min_measurements.'],
        ];
    }

    public static function schemas(): array
    {
        $num = ['type' => 'number', 'nullable' => true];
        $int = ['type' => 'integer', 'nullable' => true];
        $stepTimes = [
            'fixed_time_s' => ['type' => 'integer', 'minimum' => 0, 'description' => 'par passage'],
            'variable_time_s' => ['type' => 'number', 'minimum' => 0, 'description' => 'par pièce'],
            'capacity_per_pass' => ['type' => 'number', 'exclusiveMinimum' => true, 'minimum' => 0, 'description' => 'défaut : capacité du poste'],
        ];
        return [
            'ItemSettings' => ['type' => 'object', 'properties' => [
                'mode' => ['type' => 'string', 'enum' => ItemService::MODES], 'never_frozen' => ['type' => 'boolean'],
                'bake_state' => ['type' => 'string', 'enum' => ItemService::BAKE_STATES, 'nullable' => true, 'description' => 'none = cru, par_baked = précuit, baked = cuit'],
                'stock_min' => $num, 'stock_max' => $num, 'coverage_days' => $num, 'batch_min' => $num, 'batch_multiple' => $num,
                'shelf_life_workshop_days' => $num, 'shelf_life_shop_days' => $num, 'primary_group_id' => $int,
                'status' => ['type' => 'string', 'enum' => ItemService::STATUSES], 'wizard_step' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                'validated_at' => ['type' => 'string', 'nullable' => true],
            ]],
            'ItemSummary' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'item_type' => ['type' => 'string', 'enum' => ItemService::TYPES],
                'final_product_id' => $int, 'recipe_id' => $int, 'name' => ['type' => 'string', 'description' => 'nom dans l\'ERP'],
                'base_unit' => ['type' => 'string', 'example' => 'szt'],
                'erp' => ['type' => 'object', 'properties' => ['ppc' => $int, 'weight' => $num, 'ppb' => $int, 'bpc' => $int, 'cpl' => $int, 'lpp' => $int, 'cpp' => $int,
                    'section_id' => $int, 'active' => ['type' => 'boolean']]],
                'settings' => ['$ref' => '#/components/schemas/ItemSettings'], 'step_count' => ['type' => 'integer'],
                'can_edit' => ['type' => 'boolean', 'description' => 'prod.products.write dans le groupe de l\'article'],
            ]],
            'Item' => ['allOf' => [['$ref' => '#/components/schemas/ItemSummary'], ['type' => 'object', 'properties' => [
                'logistics' => ['type' => 'object', 'nullable' => true, 'description' => 'produit seulement', 'properties' => [
                    'carton_length_mm' => $int, 'carton_width_mm' => $int, 'carton_height_mm' => $int, 'carton_tare_g' => $int,
                    'carton_type' => ['type' => 'string', 'nullable' => true], 'carton_ean' => ['type' => 'string', 'nullable' => true],
                    'pallet_type' => ['type' => 'string', 'nullable' => true], 'storage' => ['type' => 'string', 'nullable' => true],
                    'computed' => ['type' => 'object', 'properties' => [
                        'carton_net_kg' => $num, 'carton_gross_kg' => $num, 'pallet_units' => $int, 'pallet_net_kg' => $num, 'pallet_gross_kg' => $num,
                        'pallet_height_mm' => $int, 'pallet_footprint_mm' => ['type' => 'array', 'nullable' => true, 'items' => ['type' => 'integer']],
                        'labour_s_per_carton' => $num + ['description' => 'travail par carton pour une palette de production'],
                    ]],
                ]],
                'equipment' => ['type' => 'array', 'description' => 'postes de la gamme (machines, fours…)', 'items' => ['type' => 'object', 'properties' => [
                    'workstation_id' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'type' => ['type' => 'string'],
                    'steps' => ['type' => 'array', 'items' => ['type' => 'integer']], 'temperatures_c' => ['type' => 'array', 'items' => ['type' => 'number']],
                ]]],
                'routing' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RoutingStep']],
                'bom' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'child_kind' => ['type' => 'string', 'enum' => ['material', 'recipe']], 'child_id' => ['type' => 'integer'], 'name' => ['type' => 'string'],
                    'quantity' => ['type' => 'number'], 'unit' => ['type' => 'string', 'nullable' => true], 'loss_pct' => ['type' => 'number'],
                ]]],
                'durations' => ['type' => 'array', 'description' => 'quantités de timing.sample_quantities', 'items' => ['$ref' => '#/components/schemas/Duration']],
                'checklist' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'field' => ['type' => 'string'], 'code' => ['type' => 'string'], 'ok' => ['type' => 'boolean'],
                ]]],
            ]]]],
            'RoutingStep' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'step_no' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'name_pl' => ['type' => 'string'], 'name_fr' => ['type' => 'string'],
                'workstation_id' => ['type' => 'integer'], 'workstation' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'type' => ['type' => 'string']]],
                'operators_required' => ['type' => 'number'], 'min_level_order' => $int, 'delay_after_s' => ['type' => 'integer'], 'delay_workstation_id' => $int,
                'cleaning_required' => ['type' => 'boolean'], 'cleaning_time_s' => ['type' => 'integer'], 'temperature_c' => $num,
                'alternatives' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'workstation_id' => ['type' => 'integer'], 'priority' => ['type' => 'integer'],
                ] + $stepTimes]],
            ] + $stepTimes],
            'ItemCandidate' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'ppc' => $int, 'weight' => $num, 'section_id' => $int,
            ]],
            'ItemInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['item_type'], 'properties' => [
                'item_type' => ['type' => 'string', 'enum' => ItemService::TYPES],
                'final_product_id' => ['type' => 'integer', 'description' => 'si item_type = product'],
                'recipe_id' => ['type' => 'integer', 'description' => 'si item_type = semi'],
            ]],
            'ItemSettingsInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'mode' => ['type' => 'string', 'enum' => ItemService::MODES], 'never_frozen' => ['type' => 'boolean'],
                'bake_state' => ['type' => 'string', 'enum' => ItemService::BAKE_STATES, 'nullable' => true],
                'stock_min' => $num, 'stock_max' => $num, 'coverage_days' => $num, 'batch_min' => $num, 'batch_multiple' => $num,
                'shelf_life_workshop_days' => $num, 'shelf_life_shop_days' => $num, 'primary_group_id' => $int,
                'wizard_step' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
            ]],
            'ItemProductInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 255], 'category_id' => ['type' => 'integer', 'minimum' => 1], 'section_id' => ['type' => 'integer', 'minimum' => 1],
                'label_type' => ['type' => 'integer', 'enum' => ItemService::LABEL_TYPES], 'shelf_life' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3650],
                'stock_minimum' => ['type' => 'integer', 'minimum' => 0, 'description' => 'cartons (liste de production ERP)'],
            ]],
            'StockSuggestion' => ['type' => 'object', 'properties' => [
                'weeks' => ['type' => 'integer'], 'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date'],
                'unit' => ['type' => 'string'], 'ppc' => ['type' => 'integer'], 'shipped_cartons' => ['type' => 'number'], 'shipped_quantity' => ['type' => 'number'],
                'weekly_cartons' => ['type' => 'number'], 'weekly_quantity' => ['type' => 'number'], 'min_weeks' => ['type' => 'number'], 'max_weeks' => ['type' => 'number'],
                'stock_min' => ['type' => 'integer'], 'stock_max' => ['type' => 'integer'], 'stock_min_cartons' => ['type' => 'integer'], 'stock_max_cartons' => ['type' => 'integer'],
            ]],
            'ErpOptions' => ['type' => 'object', 'properties' => [
                'categories' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]]],
                'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]]],
                'label_types' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ]],
            'ProductionSummary' => ['type' => 'object', 'properties' => [
                'item_id' => ['type' => 'integer'], 'group' => ['type' => 'string', 'nullable' => true], 'group_people' => ['type' => 'integer'],
                'reference' => ['type' => 'object', 'properties' => ['quantity' => ['type' => 'number'], 'kind' => ['type' => 'string', 'enum' => ['pallet', 'batch', 'thousand']], 'cartons' => ['type' => 'number', 'nullable' => true]]],
                'per_hour' => ['type' => 'number', 'nullable' => true], 'per_hour_group' => ['type' => 'number', 'nullable' => true],
                'minutes_per_carton' => ['type' => 'number', 'nullable' => true], 'hours_per_1000' => ['type' => 'number', 'nullable' => true],
                'labour_s' => ['type' => 'number'], 'lead_time_s' => ['type' => 'number'], 'next_day' => ['type' => 'boolean'],
                'steps' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'step_no' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'workstation' => ['type' => 'string'], 'passes' => ['type' => 'integer'],
                    'work_s' => ['type' => 'number'], 'per_hour' => ['type' => 'number', 'nullable' => true], 'start_s' => ['type' => 'number'],
                    'gap' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['none', 'minutes', 'next_day']], 'seconds' => ['type' => 'integer']]],
                    'delay_place' => ['type' => 'string', 'nullable' => true],
                ]]],
                'week' => ['type' => 'object'],
            ]],
            'ItemLogisticsInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'weight' => ['type' => 'integer', 'minimum' => 1, 'description' => 'g par pièce (ERP)'],
                'ppc' => ['type' => 'integer', 'minimum' => 1, 'description' => 'pièces par carton (ERP)'],
                'ppb' => ['type' => 'integer', 'minimum' => 0, 'description' => 'pièces par sachet (ERP, 0 = sans sachet)'],
                'bpc' => ['type' => 'integer', 'minimum' => 0, 'description' => 'sachets par carton (ERP)'],
                'cpl' => ['type' => 'integer', 'minimum' => 0, 'description' => 'cartons par couche (ERP)'],
                'lpp' => ['type' => 'integer', 'minimum' => 0, 'description' => 'couches par palette (ERP)'],
                'cpp' => ['type' => 'integer', 'minimum' => 0, 'description' => 'cartons par palette (ERP), ≤ cpl × lpp'],
                'carton_length_mm' => $int, 'carton_width_mm' => $int, 'carton_height_mm' => $int, 'carton_tare_g' => $int,
                'carton_type' => ['type' => 'string', 'enum' => ItemService::CARTON_TYPES, 'nullable' => true],
                'carton_ean' => ['type' => 'string', 'pattern' => '^[0-9]{8,14}$', 'nullable' => true],
                'pallet_type' => ['type' => 'string', 'enum' => ItemService::PALLET_TYPES, 'nullable' => true],
                'storage' => ['type' => 'string', 'enum' => ItemService::STORAGES, 'nullable' => true],
            ]],
            'RoutingInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['steps'], 'properties' => [
                'steps' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name_pl', 'workstation_id'], 'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'étape existante à conserver'],
                    'name_pl' => ['type' => 'string', 'maxLength' => 100], 'name_fr' => ['type' => 'string', 'maxLength' => 100, 'description' => 'défaut : name_pl'],
                    'workstation_id' => ['type' => 'integer'], 'operators_required' => ['type' => 'number', 'minimum' => 0],
                    'min_level_order' => $int, 'delay_after_s' => ['type' => 'integer', 'minimum' => 0],
                    'delay_workstation_id' => $int + ['description' => 'poste de type fermentation, froid ou surgelation'],
                    'cleaning_required' => ['type' => 'boolean'], 'cleaning_time_s' => ['type' => 'integer', 'minimum' => 0],
                    'temperature_c' => $num + ['minimum' => -60, 'maximum' => 400, 'description' => 'four, pousse, froid ou surgélation'],
                    'alternatives' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['workstation_id'], 'properties' => [
                        'workstation_id' => ['type' => 'integer'], 'priority' => ['type' => 'integer', 'minimum' => 1],
                    ] + $stepTimes]],
                ] + $stepTimes]],
            ]],
            'BomLossesInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['losses'], 'properties' => [
                'losses' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['child_kind', 'child_id', 'loss_pct'], 'properties' => [
                    'child_kind' => ['type' => 'string', 'enum' => ['material', 'recipe']], 'child_id' => ['type' => 'integer'],
                    'loss_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 99.99],
                ]]],
            ]],
            'Duration' => ['type' => 'object', 'properties' => [
                'quantity' => ['type' => 'number'],
                'steps' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'step_no' => ['type' => 'integer'], 'passes' => ['type' => 'integer'], 'work_s' => ['type' => 'number'],
                    'cleaning_s' => ['type' => 'number'], 'delay_s' => ['type' => 'number'], 'labour_s' => ['type' => 'number'],
                ]]],
                'work_s' => ['type' => 'number'], 'elapsed_s' => ['type' => 'number', 'description' => 'travail + nettoyage + attentes'],
                'labour_s' => ['type' => 'number', 'description' => 'heures-personne (opérateurs × temps)'], 'labour_s_per_unit' => $num,
            ]],
            'TimeStudies' => ['type' => 'object', 'properties' => [
                'step' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'item_id' => ['type' => 'integer'], 'step_no' => ['type' => 'integer'], 'name' => ['type' => 'string'],
                    'workstation_id' => ['type' => 'integer'],
                ] + $stepTimes],
                'proposals' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'component' => ['type' => 'string', 'enum' => TimeStudyService::COMPONENTS], 'current_s' => ['type' => 'number'], 'proposed_s' => $num,
                    'count' => ['type' => 'integer'], 'window' => ['type' => 'integer'], 'min_measurements' => ['type' => 'integer'], 'ready' => ['type' => 'boolean'],
                    'deviation_pct' => $num, 'alert' => ['type' => 'boolean'], 'measurement_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ]]],
                'measurements' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'], 'component' => ['type' => 'string'], 'workstation_id' => $int, 'quantity' => ['type' => 'number'],
                    'passes' => ['type' => 'integer'], 'duration_s' => ['type' => 'integer'], 'unit_time_s' => ['type' => 'number'],
                    'measured_at' => ['type' => 'string'], 'production_employee_id' => $int, 'employee' => ['type' => 'string', 'nullable' => true],
                    'measured_by' => ['type' => 'string', 'nullable' => true], 'note' => ['type' => 'string', 'nullable' => true],
                    'excluded' => ['type' => 'boolean'], 'applied_at' => ['type' => 'string', 'nullable' => true],
                ]]],
                'created_id' => ['type' => 'integer', 'description' => 'après POST'],
            ]],
            'TimeStudyInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['quantity', 'duration_s'], 'properties' => [
                'quantity' => ['type' => 'number', 'exclusiveMinimum' => true, 'minimum' => 0], 'duration_s' => ['type' => 'integer', 'minimum' => 1],
                'passes' => ['type' => 'integer', 'minimum' => 1, 'description' => 'défaut : ⌈quantité ÷ capacité⌉'],
                'component' => ['type' => 'string', 'enum' => TimeStudyService::COMPONENTS, 'description' => 'défaut : variable si l\'étape a un temps par pièce'],
                'workstation_id' => $int + ['description' => 'poste mesuré si alternative'], 'measured_at' => ['type' => 'string', 'example' => '2026-10-19 06:40'],
                'production_employee_id' => $int, 'note' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
            ]],
            'TimeStudyUpdateInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['excluded'], 'properties' => [
                'excluded' => ['type' => 'boolean'], 'note' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
            ]],
            'ApplyStandardInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['component'], 'properties' => [
                'component' => ['type' => 'string', 'enum' => TimeStudyService::COMPONENTS],
            ]],
        ];
    }
}
