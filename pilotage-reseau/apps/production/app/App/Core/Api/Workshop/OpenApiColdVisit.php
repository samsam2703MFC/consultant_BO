<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Services\Workshop\Quality\ColdVisitService;

/**
 * OpenAPI : obchód chłodni przed zakupami [432] — tablet jakości Kierownika produkcji (miejsca, jedno miejsce, « Zapisz
 * chłodnię », surowce miejsca) et miejsca ajoutées dans l'ERP (Zakupy tygodnia, krok « Obchód chłodni »).
 */
final class OpenApiColdVisit
{
    public static function operations(): array
    {
        $t = 'kiosk';
        $who = ' Personne identifiée au poste KIEROWNIK_PRODUKCJI ou JAKOSC (sinon 403 QC_ROUND_POSITION).';
        $units = ' Quantités en unité de base (g, ml, szt) ; unit / factor donnent l\'unité affichée (kg, l…).';
        return [
            'GET /kiosk/cold-visit' => ['tag' => $t, 'id' => 'coldVisit', 'summary' => 'Obchód chłodni : miejsca / Tournée des chambres froides : lieux', 'response' => 'ColdVisit', 'errors' => [401],
                'description' => "Miejsca : chłodnie / mroźnie actives du rejestr « Serwisy i przeglądy » (n_equipment.kind = cold) et miejsca ajoutées dans l'ERP. status : done (zapisane dans le obchód en cours), "
                    . "now (la première pas encore zapisana), later. visit : obchód en cours (state open, moins de cold_visit.valid_hours heures) ou dernier complet encore valide (state complete)." . $who],
            'GET /kiosk/cold-visit/materials' => ['tag' => $t, 'id' => 'coldVisitMaterials', 'summary' => 'Szukaj surowca / Chercher une matière', 'response' => 'ColdVisitMaterial', 'list' => true, 'errors' => [401],
                'query' => ['q' => ['type' => 'string', 'description' => 'au moins 2 caractères du nom (polonais ou français)']],
                'description' => '20 résultats au plus, les noms les plus courts d\'abord.' . $who],
            'GET /kiosk/cold-visit/places/{id:\d+}' => ['tag' => $t, 'id' => 'coldVisitPlace', 'summary' => 'Jedno miejsce : surowce do policzenia / Un lieu : matières à compter', 'response' => 'ColdVisitPlace', 'errors' => [401, 404],
                'description' => "Un rang par surowiec rattaché : erp = stan de Zaopatrzenie (dernière inwentaryzacja + przyjęcia − zużycie z receptur ; null = inconnu ; dans un obchód en cours, celui d'avant le premier zapis), "
                    . "lot = partia bieżąca du rejestr (date la plus courte d'abord), also_in = autres miejsca du surowiec, saved = zapis de ce obchód." . $units . $who],
            'POST /kiosk/cold-visit/places/{id:\d+}' => ['tag' => $t, 'id' => 'coldVisitSave', 'summary' => 'Zapisz chłodnię / Enregistrer le lieu', 'body' => 'ColdVisitPlaceInput', 'response' => 'ColdVisit', 'ok' => 201, 'errors' => [400, 401, 404, 422],
                'description' => "Chaque surowiec de la miejsce policzony (sinon 422 COLD_VISIT_NOT_COUNTED) ; porządek « no » : photo obligatoire (POST /kiosk/quality-round/photos, sinon 422 COLD_VISIT_PHOTO_REQUIRED). "
                    . "Inventaire de Zaopatrzenie (n_mp_count, jour même) = Σ (policzone − strata) sur toutes les miejsca du surowiec dans ce obchód, écrit quand la dernière est zapisana ; "
                    . "pertes (do wyrzucenia / uszkodzone : quantité ≤ policzone, raison) gardées dans le obchód. Obchód ouvert au premier zapis, complet quand toutes les miejsca actives sont zapisane." . $units . $who],
            'PUT /kiosk/cold-visit/places/{id:\d+}/materials/{material:\d+}' => ['tag' => $t, 'id' => 'coldVisitAssign', 'summary' => 'Dodaj surowiec, który tu leży / Ajouter une matière rangée ici', 'response' => 'ColdVisitPlace', 'errors' => [401, 404],
                'description' => 'Rattachement surowiec ↔ miejsce (appris au premier obchód) ; déjà rattaché : rien.' . $who],
            'DELETE /kiosk/cold-visit/places/{id:\d+}/materials/{material:\d+}' => ['tag' => $t, 'id' => 'coldVisitUnassign', 'summary' => 'Surowiec już tu nie leży / La matière n\'est plus rangée ici', 'response' => 'ColdVisitPlace', 'errors' => [401, 404],
                'description' => 'Retire le rattachement ; les obchody déjà zapisane restent.' . $who],
            'POST /supply/buy/cold-places' => ['tag' => 'supply', 'id' => 'addColdPlace', 'summary' => 'Obchód chłodni : dodaj miejsce / Ajouter un lieu à la tournée', 'body' => 'ColdPlaceInput', 'response' => 'ColdVisitSummary', 'ok' => 201, 'errors' => [400, 409, 422],
                'description' => "Miejsce sans urządzenie (Magazyn suchy…) ; les chłodnie / mroźnie viennent seules du rejestr « Serwisy i przeglądy ». Nom déjà pris : DUPLICATE_IN_LIST. Réponse : krok « Obchód chłodni » de Zakupy tygodnia."],
            'DELETE /supply/buy/cold-places/{id:\d+}' => ['tag' => 'supply', 'id' => 'removeColdPlace', 'summary' => 'Obchód chłodni : usuń miejsce / Retirer un lieu de la tournée', 'response' => 'ColdVisitSummary', 'errors' => [404, 409],
                'description' => "Miejsce ajoutée seulement : une chłodnia du rejestr se retire dans « Serwisy i przeglądy » (409 COLD_PLACE_EQUIPMENT)."],
        ];
    }

    public static function schemas(): array
    {
        $int = ['type' => 'integer'];
        $nInt = ['type' => 'integer', 'nullable' => true];
        $num = ['type' => 'number'];
        $nNum = ['type' => 'number', 'nullable' => true];
        $str = ['type' => 'string'];
        $nStr = ['type' => 'string', 'nullable' => true];
        $bool = ['type' => 'boolean'];
        $source = ['type' => 'string', 'enum' => ['equipment', 'extra']];
        $kind = ['type' => 'string', 'enum' => ColdVisitService::PLACE_KINDS];
        $employee = ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'position' => $str]];
        $visit = ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $int, 'state' => ['type' => 'string', 'enum' => ['open', 'complete']], 'started_at' => $str, 'completed_at' => $nStr,
            'by' => $nStr, 'places' => $int, 'materials' => $int, 'differences' => $int, 'valid_until' => $nStr]];
        $loss = ['type' => 'object', 'nullable' => true, 'properties' => ['kind' => ['type' => 'string', 'enum' => ColdVisitService::LOSS_KINDS], 'quantity' => $num, 'reason' => $str]];
        return [
            'ColdVisit' => ['type' => 'object', 'properties' => ['now' => $str, 'employee' => $employee, 'valid_hours' => $int, 'visit' => $visit, 'to_do' => $int, 'next_id' => $nInt,
                'places' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'kind' => $kind, 'source' => $source, 'location' => $nStr, 'materials' => $int,
                    'status' => ['type' => 'string', 'enum' => ['done', 'now', 'later']],
                    'saved' => ['type' => 'object', 'nullable' => true, 'properties' => ['at' => $str, 'materials' => $int, 'differences' => $int, 'clean' => ['type' => 'string', 'enum' => ColdVisitService::CLEAN], 'by' => $nStr]]]]]]],
            'ColdVisitMaterial' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'unit' => $str]],
            'ColdVisitPlace' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'kind' => $kind, 'source' => $source, 'employee' => $employee,
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['material_id' => $int, 'name' => $str, 'base_unit' => $str, 'unit' => $str, 'factor' => $num, 'erp' => $nNum,
                    'lot' => ['type' => 'object', 'nullable' => true, 'properties' => ['lot_number' => $nStr, 'best_before' => $nStr]], 'also_in' => ['type' => 'array', 'items' => $str],
                    'saved' => ['type' => 'object', 'nullable' => true, 'properties' => ['quantity' => $num, 'date_state' => $nStr, 'loss' => $loss]]]]],
                'saved' => ['type' => 'object', 'nullable' => true, 'properties' => ['at' => $str, 'clean' => $str, 'photo_id' => $nInt, 'note' => $nStr]],
                'loss_kinds' => ['type' => 'array', 'items' => $str], 'date_states' => ['type' => 'array', 'items' => $str]]],
            'ColdVisitPlaceInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lines', 'clean'], 'properties' => [
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['material_id', 'quantity'], 'properties' => [
                    'material_id' => $int, 'quantity' => ['type' => 'number', 'minimum' => 0, 'description' => 'jest naprawdę, unité de base'],
                    'date_state' => ['type' => 'string', 'nullable' => true, 'enum' => ColdVisitService::DATE_STATES, 'description' => 'short = krótka data, zużyć najpierw'],
                    'loss' => ['type' => 'object', 'nullable' => true, 'required' => ['kind', 'quantity', 'reason'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ColdVisitService::LOSS_KINDS],
                        'quantity' => ['type' => 'number', 'description' => 'unité de base, au plus quantity'], 'reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120]]]]]],
                'clean' => ['type' => 'string', 'enum' => ColdVisitService::CLEAN], 'photo_id' => $nInt, 'note' => ['type' => 'string', 'nullable' => true, 'maxLength' => 255]]],
            'ColdPlaceInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 160],
                'kind' => ['type' => 'string', 'enum' => ColdVisitService::PLACE_KINDS, 'description' => 'défaut dry (magazyn suchy)']]],
            'ColdVisitSummary' => ['type' => 'object', 'properties' => ['valid_hours' => $int, 'valid' => $bool, 'blocked' => $bool, 'no_places' => $bool,
                'last' => ['allOf' => [$visit], 'nullable' => true, 'description' => 'dernier obchód complet (+ days_ago, dirty : miejsca « porządek : nie »)'],
                'open' => ['type' => 'object', 'nullable' => true, 'properties' => ['started_at' => $str, 'by' => $nStr, 'saved' => $int, 'places' => $int]],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['material_id' => $int, 'name' => $str, 'unit' => $str, 'factor' => $num, 'places' => ['type' => 'array', 'items' => $str],
                    'erp' => $nNum, 'counted' => $num, 'difference' => $nNum, 'short' => ['type' => 'object', 'nullable' => true, 'properties' => ['best_before' => $nStr, 'lot_number' => $nStr]],
                    'losses' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['kind' => $str, 'quantity' => $num, 'reason' => $nStr, 'place' => $str]]], 'stock_after' => $nNum]],
                    'description' => 'surowce avec différence, krótka data ou perte (unité de base)'],
                'unchanged' => $int,
                'places' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'kind' => $kind, 'source' => $source, 'materials' => $int]]]]],
        ];
    }
}
