<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/**
 * OpenAPI : tablet jakości Kierownika produkcji [415–416] (makieta hali I, J, K) — obchód dnia, kontrola partii (waga,
 * wygląd, worki, próbka), brak skanu wysłany grupie, śledzenie partii (skąd / dokąd), PDF i wstrzymanie partii.
 */
final class OpenApiQuality
{
    public static function operations(): array
    {
        $t = 'kiosk';
        $who = ' Personne identifiée au poste KIEROWNIK_PRODUKCJI ou JAKOSC (sinon 403 QC_ROUND_POSITION).';
        return [
            'GET /kiosk/quality-round/people' => ['tag' => $t, 'id' => 'qualityRoundPeople', 'summary' => 'Tablet jakości : kto (Kierownik produkcji, Jakość) / Tablette qualité : qui', 'response' => 'QualityRoundPerson', 'list' => true, 'errors' => [401],
                'description' => 'Personnes actives aux postes KIEROWNIK_PRODUKCJI et JAKOSC, pour s\'identifier ensuite avec POST /kiosk/identify (employee_id + PIN).'],
            'GET /kiosk/quality-round' => ['tag' => $t, 'id' => 'qualityRound', 'summary' => 'Obchód jakości dnia / Tournée qualité du jour', 'response' => 'QualityRound', 'errors' => [401],
                'description' => "Parties du jour de toutes les grupy. status : urgent (signal d'une tablette de groupe non encore contrôlé : contrôle du groupe en non-conformité, non-conformité signalée sur le produit), "
                    . "now (première partie à contrôler, par heure de démarrage), later, puis ok / warn (écart accepté sans NC) / nc. todo : poids + aspect, sacs sans scan (même calcul que « Partie i kontrola dnia »), "
                    . "échantillon à faire (« Próbki produkcji »), rappels envoyés au groupe. round : obchód N sur M (qc_round.times)." . $who],
            'GET /kiosk/quality-round/batches/{id:\d+}' => ['tag' => $t, 'id' => 'qualityRoundBatch', 'summary' => 'Kontrola partii : dane / Contrôle d\'un lot : données', 'response' => 'QualityRoundBatch', 'errors' => [401, 404, 409],
                'description' => "Poids (qc_round.pieces pièces ; cible = final_product.weight, tolérance qc_round.weight_tolerance_pct sur la moyenne), sacs de la partie (scans de la tablette du groupe, lots du registre proposés), "
                    . "compositions d'autres groupes, échantillon (stored, planned, suggested, not_required), dernier contrôle, blocage." . $who],
            'POST /kiosk/quality-round/batches/{id:\d+}/controls' => ['tag' => $t, 'id' => 'qualityRoundControl', 'summary' => 'Zapisz kontrolę / Enregistrer le contrôle', 'body' => 'QualityRoundControlInput', 'response' => 'QualityRoundSaved', 'ok' => 201, 'errors' => [400, 401, 404, 409, 422],
                'description' => "Poids moyen hors tolérance ou aspect mauvais : décision obligatoire sur la non-conformité proposée (sinon 409 QC_ROUND_NC_DECISION) — nc.create true crée la non-conformité au registre de l'ERP "
                    . "(source production, groupe de la partie), false enregistre un écart accepté (warn). Sacs sans scan non envoyés au groupe : 409 QC_ROUND_LOTS_OPEN. Le résultat est aussi porté sur la partie "
                    . "(contrôle qualité de la tablette du groupe), sauf une non-conformité déjà portée par la partie." . $who],
            'PUT /kiosk/quality-round/batches/{id:\d+}/lots/{material:\d+}' => ['tag' => $t, 'id' => 'qualityRoundLot', 'summary' => 'Zeskanuj sam : partia worka / Scanner soi-même le lot d\'un sac', 'body' => 'OutputLotInput', 'response' => 'QualityRoundBatch', 'errors' => [400, 401, 403, 404, 409, 422],
                'description' => 'Même enregistrement que PUT /kiosk/production/{id}/lots/{material} de la tablette du groupe, au nom du groupe de la partie.' . $who],
            'POST /kiosk/quality-round/batches/{id:\d+}/lot-requests' => ['tag' => $t, 'id' => 'qualityRoundLotRequest', 'summary' => 'Wyślij grupie : brak skanu worka / Envoyer au groupe : sac sans scan', 'response' => 'QualityRoundBatch', 'ok' => 201, 'errors' => [401, 404, 409],
                'description' => "Message sur la tablette du groupe de la partie (messages du responsable, GET /kiosk/messages) : sacs sans scan. Un seul tant qu'il n'est pas lu ; fermé quand les sacs sont scannés. 409 QC_ROUND_NO_MISSING_LOTS." . $who],
            'POST /kiosk/quality-round/batches/{id:\d+}/sample' => ['tag' => $t, 'id' => 'qualityRoundSample', 'summary' => 'Próbka partii / Échantillon du lot', 'body' => 'QualityRoundSampleInput', 'response' => 'QualityRoundBatch', 'errors' => [400, 401, 404, 409, 422],
                'description' => "« Próbki produkcji » : create = échantillon de la partie (étiquette QR imprimée par l'assistant) ; store = étiquette QR scannée (code de l'échantillon de cette partie), échantillon posé. 422 QC_ROUND_SAMPLE_OTHER : étiquette d'une autre partie." . $who],
            'POST /kiosk/quality-round/photos' => ['tag' => $t, 'id' => 'qualityRoundPhoto', 'summary' => 'Zdjęcie wyglądu / Photo de l\'aspect', 'body' => 'PhotoInput', 'response' => 'PhotoUploaded', 'ok' => 201, 'errors' => [400, 401, 422],
                'description' => 'Data URL JPEG / PNG / WebP (kiosk.lot_photo_max_kb), conservée comme les étiquettes de lot ; id à passer dans photo_id.' . $who],
            'GET /kiosk/quality-round/trace' => ['tag' => $t, 'id' => 'qualityRoundScan', 'summary' => 'Śledzenie partii : skan etykiety kartonu albo partii / Suivi : scan d\'un carton ou d\'un lot', 'response' => 'QualityTraceScan', 'errors' => [401, 404, 422],
                'query' => ['code' => ['type' => 'string', 'description' => 'numéro de partie RRMMDD-GRUPA-NN ou numéro du carton (étiquette)']],
                'description' => "Partie trouvée : lot. Carton : produit et jour d'étiquette, parties du produit fabriquées dans la fenêtre trace.label_days (une seule : lot ; plusieurs : choices). 404 QC_TRACE_UNKNOWN_CODE." . $who],
            'GET /kiosk/quality-round/trace/{id:\d+}' => ['tag' => $t, 'id' => 'qualityRoundTrace', 'summary' => 'Partia : skąd surowce, dokąd kartony / Lot : d\'où, où', 'response' => 'QualityTrace', 'errors' => [401, 404],
                'description' => "Matières (lot, fournisseur, réception ; sac sans scan : missing ; non suivie : untracked), cartons du produit étiquetés du jour de production à + trace.label_days (partis : client, livraison, WDT ; sur palette ; au magasin), contrôle, blocage." . $who],
            'GET /kiosk/quality-round/trace/{id:\d+}/pdf' => ['tag' => $t, 'id' => 'qualityRoundTracePdf', 'summary' => 'PDF partii / PDF du lot', 'response' => 'QualityTracePdf', 'errors' => [401, 404],
                'description' => 'Même contenu que le suivi, gabarit des documents ; fichier en base64.' . $who],
            'POST /kiosk/quality-round/trace/{id:\d+}/hold' => ['tag' => $t, 'id' => 'qualityRoundHold', 'summary' => 'Wstrzymaj partię / Bloquer le lot', 'body' => 'QualityHoldInput', 'response' => 'QualityTrace', 'ok' => 201, 'errors' => [400, 401, 404, 422],
                'description' => "Blocage enregistré seulement (n_qc_hold) : aucun statut de carton ni stock modifié. La palettisation refuse le produit sans confirmation (PALLET_PRODUCT_HELD), le chargement refuse la palette (LOT_HELD_ON_PALLET). "
                    . "Partie sans produit fini : 422 QC_HOLD_NO_PRODUCT. Déjà bloquée : le blocage existant." . $who],
            'POST /kiosk/quality-round/holds/{id:\d+}/release' => ['tag' => $t, 'id' => 'qualityRoundRelease', 'summary' => 'Zwolnij partię / Lever le blocage', 'body' => 'QualityReleaseInput', 'response' => 'QualityTrace', 'errors' => [400, 401, 404, 409, 422],
                'description' => 'Décision Kierownik produkcji / Jakość, note obligatoire. 409 QC_HOLD_RELEASED.' . $who],
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
        $free = ['type' => 'object', 'additionalProperties' => true];
        $hold = ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $int, 'output_id' => $nInt, 'lot_code' => $nStr, 'product_id' => $int, 'product' => $nStr,
            'label_from' => $str, 'label_to' => $str, 'days' => $str, 'reason' => $str, 'held_at' => $str, 'held_by' => $nStr, 'released_at' => $nStr, 'released_by' => $nStr,
            'release_note' => $nStr, 'active' => $bool]];
        $control = ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $int, 'at' => $str, 'by' => $nStr, 'result' => ['type' => 'string', 'enum' => ['ok', 'warn', 'nc']],
            'weight_result' => ['type' => 'string', 'enum' => ['ok', 'light', 'heavy', 'none']], 'appearance' => ['type' => 'string', 'enum' => ['good', 'bad']],
            'weights' => ['type' => 'array', 'items' => $num], 'lots_missing' => $int, 'lots_requested' => $bool, 'sample' => $nStr, 'nc_id' => $nInt, 'photo' => $bool, 'note' => $nStr,
            'items' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['weight', 'appearance', 'lots', 'sample']]]]];
        $lot = ['type' => 'object', 'properties' => ['material_id' => $int, 'name' => $str, 'packaging' => $bool, 'state' => ['type' => 'string', 'enum' => ['ok', 'missing', 'reason', 'untracked']],
            'lot_number' => $nStr, 'best_before' => $nStr, 'missing_reason' => $nStr, 'supplier' => $nStr, 'received_on' => $nStr,
            'choices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'lot_number' => $str, 'best_before' => $nStr, 'supplier' => $nStr, 'received_on' => $nStr]]]]];
        $sample = ['type' => 'object', 'properties' => ['state' => ['type' => 'string', 'enum' => ['stored', 'planned', 'suggested', 'not_required']], 'id' => $nInt, 'code' => $nStr, 'frequency' => $nStr]];
        $employee = ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'position' => $str]];
        return [
            'QualityRoundPerson' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'position' => ['type' => 'string', 'enum' => ['KIEROWNIK_PRODUKCJI', 'JAKOSC']],
                'has_pin' => $bool, 'clocked_in' => $bool]],
            'QualityRound' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string', 'format' => 'date'], 'now' => $str, 'employee' => $employee,
                'round' => ['type' => 'object', 'nullable' => true, 'properties' => ['no' => $int, 'of' => $int]], 'to_check' => $int,
                'counts' => ['type' => 'object', 'properties' => ['batches' => $int, 'checked' => $int, 'urgent' => $int]], 'next_id' => $nInt,
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'group' => $str, 'product' => $str, 'lot_code' => $str, 'started_at' => $str,
                    'status' => ['type' => 'string', 'enum' => ['urgent', 'now', 'later', 'ok', 'warn', 'nc']],
                    'signal' => ['type' => 'object', 'nullable' => true, 'properties' => ['kind' => ['type' => 'string', 'enum' => ['group_nc', 'group_report']], 'at' => $str, 'text' => $nStr, 'number' => $nStr]],
                    'todo' => ['type' => 'object', 'properties' => ['weight' => $bool, 'missing_lots' => $int, 'lot_reasons' => $int, 'sample' => $bool, 'requested' => $int]],
                    'checked' => $control, 'hold' => $hold]]]]],
            'QualityRoundBatch' => ['type' => 'object', 'properties' => ['id' => $int, 'employee' => $employee, 'product' => $str, 'lot_code' => $str,
                'group' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str]], 'work_date' => $str, 'started_at' => $str, 'quantity' => $num, 'unit' => $nStr, 'piece_weight' => $nNum,
                'weight' => ['type' => 'object', 'properties' => ['pieces' => $int, 'target_g' => $nNum, 'tolerance_pct' => $num, 'min_g' => $nNum, 'max_g' => $nNum]],
                'lots' => ['type' => 'array', 'items' => $lot],
                'components' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['recipe_id' => $int, 'name' => $str, 'producer' => $str, 'state' => ['type' => 'string', 'enum' => ['ok', 'missing']], 'lot_code' => $nStr]]],
                'missing_lots' => $int, 'requested' => ['type' => 'object', 'nullable' => true, 'properties' => ['at' => $str, 'count' => $int]], 'sample' => $sample,
                'group_qc' => ['type' => 'object', 'nullable' => true, 'properties' => ['status' => $str, 'at' => $str, 'note' => $nStr]], 'last_control' => $control, 'hold' => $hold,
                'severities' => ['type' => 'array', 'items' => $str]]],
            'QualityRoundControlInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['weights', 'appearance'], 'properties' => [
                'weights' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => ['type' => 'number', 'exclusiveMinimum' => true, 'minimum' => 0], 'description' => 'grammes, une valeur par pièce'],
                'appearance' => ['type' => 'string', 'enum' => ['good', 'bad']], 'photo_id' => $nInt, 'note' => ['type' => 'string', 'nullable' => true, 'maxLength' => 255],
                'nc' => ['type' => 'object', 'nullable' => true, 'description' => 'décision sur la non-conformité proposée (obligatoire en cas d\'écart)', 'properties' => [
                    'create' => $bool, 'description' => ['type' => 'string', 'maxLength' => 2000], 'severity' => ['type' => 'string', 'enum' => ['minor', 'major', 'critical']]]],
            ]],
            'QualityRoundSaved' => ['type' => 'object', 'properties' => ['control_id' => $int, 'result' => ['type' => 'string', 'enum' => ['ok', 'warn', 'nc']], 'round' => ['$ref' => '#/components/schemas/QualityRound']]],
            'QualityRoundSampleInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['action'], 'properties' => [
                'action' => ['type' => 'string', 'enum' => ['create', 'store']], 'code' => ['type' => 'string', 'nullable' => true, 'description' => 'store : contenu du QR de l\'étiquette (adresse …/q/CODE ou code)']]],
            'QualityTrace' => ['type' => 'object', 'properties' => ['id' => $int, 'product' => $str, 'product_id' => $nInt, 'lot_code' => $str, 'group' => $str, 'work_date' => $str, 'started_at' => $str,
                'quantity' => $num, 'unit' => $nStr, 'cancelled' => $bool,
                'materials' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => $str, 'packaging' => $bool, 'state' => ['type' => 'string', 'enum' => ['ok', 'missing', 'reason', 'untracked']],
                    'lot_number' => $nStr, 'best_before' => $nStr, 'missing_reason' => $nStr, 'supplier' => $nStr, 'received_on' => $nStr]]], 'missing' => $int,
                'cartons' => ['type' => 'object', 'properties' => ['total' => $int, 'shipped' => $int, 'pallet' => $int, 'stock' => $int]],
                'destinations' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['shipped', 'pallet', 'stock']], 'customer' => $nStr,
                    'delivery_date' => $nStr, 'wdt_date' => $nStr, 'wdt' => $nStr, 'cartons' => $int]]],
                'label_from' => $nStr, 'label_to' => $nStr, 'control' => ['type' => 'object', 'nullable' => true, 'properties' => ['at' => $str, 'result' => $str, 'group' => $bool]], 'hold' => $hold]],
            'QualityTraceScan' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['lot', 'carton']], 'scanned' => $str,
                'carton' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $int, 'product_id' => $int, 'product' => $nStr, 'label_day' => $str, 'status' => $int, 'holds' => ['type' => 'array', 'items' => $free]]],
                'choices' => ['type' => 'array', 'items' => $free], 'lot' => ['allOf' => [['$ref' => '#/components/schemas/QualityTrace']], 'nullable' => true]]],
            'QualityTracePdf' => ['type' => 'object', 'properties' => ['filename' => $str, 'content_base64' => ['type' => 'string', 'format' => 'byte']]],
            'QualityHoldInput' => ['type' => 'object', 'required' => ['reason'], 'properties' => ['reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500]]],
            'QualityReleaseInput' => ['type' => 'object', 'required' => ['note'], 'properties' => ['note' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500]]],
        ];
    }
}
