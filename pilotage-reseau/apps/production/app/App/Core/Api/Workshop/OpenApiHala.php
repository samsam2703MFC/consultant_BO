<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/** OpenAPI du tablet hali « Mój dzień » [409–411] (HalaController). */
final class OpenApiHala
{
    public static function operations(): array
    {
        $day = 'HalaDay';
        return [
            'GET /kiosk/hala/day' => ['tag' => 'kiosk', 'id' => 'halaDay', 'summary' => 'Mój dzień : ścieżka dnia grupy / Ma journée : parcours du jour du groupe', 'response' => $day, 'errors' => [401, 403, 409],
                'description' => "Groupe de la tablette, personne identifiée (PIN). Étapes réglées pour le groupe (n_hala_group, Produkcja › Tablet hali) dans l'ordre fixe make, bags, chill, clean, pack puis end ; état done / now / later (étape terminée = « Dalej » confirmé aujourd'hui et condition remplie). Blocs : make (plan du jour de l'équipe, fait, pertes), bags (surowce suivis des parties du jour), chill (mesures, limites hala.chill_*), clean (strefy du groupe), pack (commandes emballées du jour d'expédition), end (résumé, can_close). person.lead : poste dans hala.shift_lead_positions (vue Zmiana). help : wezwanie ouvert du groupe."],
            'PUT /kiosk/hala/made/{id:\d+}' => ['tag' => 'kiosk', 'id' => 'halaMade', 'summary' => 'Licznik « zrobione » / Compteur « fait »', 'body' => 'HalaMadeInput', 'response' => $day, 'errors' => [400, 401, 403, 409, 422],
                'description' => "id = article (n_prod_item). made = quantité faite aujourd'hui (unité de la fiche) : plus → la partie la plus récente du jour grandit (sinon nouvelle partie), moins → retiré des parties récentes. Pas moins que les pertes (409 WASTE_EXCEEDS_PRODUCED). Jour validé : 409 DAY_CLOSED. La ligne n'est pas validée (« Zatwierdź dzień » valide le jour)."],
            'POST /kiosk/hala/steps' => ['tag' => 'kiosk', 'id' => 'halaStep', 'summary' => 'Dalej : krok zrobiony / Suivant : étape faite', 'body' => 'HalaStepInput', 'response' => $day, 'errors' => [400, 401, 403, 404, 409, 422],
                'description' => "step parmi les étapes du groupe. bags : chaque partie du jour a le numéro de lot de chaque surowiec suivi ; chill : chaque sortie de four mesurée ; clean : toutes les strefy faites — sinon 409 HALA_STEP_INCOMPLETE."],
            'POST /kiosk/hala/bags' => ['tag' => 'kiosk', 'id' => 'halaBag', 'summary' => 'Skanuj worek / Scanner un sac', 'body' => 'HalaScanInput', 'response' => 'HalaScanResult', 'errors' => [400, 401, 403, 409, 422],
                'description' => "code = numéro lu sur le sac (registre « Partie surowców »). result ok : lot enregistré dans les parties du jour du produit (comme PUT /kiosk/production/{id}/lots/{material}) ; wrong : surowiec hors recette (STOP, rien enregistré) ; expired : date dépassée (STOP) ; unknown : code absent du registre — renvoyer avec material_id (numéro ajouté au registre « do sprawdzenia »). 409 HALA_NO_BATCH : rien de fait pour ce produit aujourd'hui."],
            'POST /kiosk/hala/chill' => ['tag' => 'kiosk', 'id' => 'halaChillStart', 'summary' => 'Wyjście z pieca : minutnik / Sortie du four : minuteur', 'body' => 'HalaChillInput', 'response' => $day, 'ok' => 201, 'errors' => [400, 401, 403, 409, 422],
                'description' => "Article du jour ; limites copiées au départ (hala.chill_target_c, hala.chill_max_minutes). client_uuid rejouable."],
            'PUT /kiosk/hala/chill/{id:\d+}' => ['tag' => 'kiosk', 'id' => 'halaChillMeasure', 'summary' => 'Temperatura w środku / Température à cœur', 'body' => 'HalaChillMeasureInput', 'response' => $day, 'errors' => [400, 401, 403, 404, 409, 422],
                'description' => "Au-dessus de la limite ou après le délai : wezwanie « chill » au brygadzista et élément du HACCP miesiąca."],
            'PUT /kiosk/hala/clean/{id:\d+}' => ['tag' => 'kiosk', 'id' => 'halaClean', 'summary' => 'Strefa sprzątnięta / Zone nettoyée', 'body' => 'HalaCleanInput', 'response' => $day, 'errors' => [400, 401, 403, 404, 409, 422],
                'description' => "id = strefa du groupe de la tablette ; done = false annule (erreur de toucher). Qui et quand sont enregistrés."],
            'POST /kiosk/hala/close' => ['tag' => 'kiosk', 'id' => 'halaClose', 'summary' => 'Zatwierdź dzień / Valider la journée', 'response' => $day, 'errors' => [401, 403, 409, 422],
                'description' => "Toutes les étapes terminées (sinon 409 HALA_STEP_INCOMPLETE, step). Avec production : validation du jour (comme POST /kiosk/production/close — tâche « Dzień grupy ») ; partie sans kontrola jakości (obchód du Kierownik produkcji) : notée automatiquement. Puis fin de journée du groupe (n_hala_day)."],
            'GET /kiosk/hala/help' => ['tag' => 'kiosk', 'id' => 'halaHelpState', 'summary' => 'Stan wezwania grupy / État de l\'appel du groupe', 'response' => 'HalaHelpState', 'errors' => [401, 403, 409]],
            'POST /kiosk/hala/help' => ['tag' => 'kiosk', 'id' => 'halaHelp', 'summary' => 'Pomoc : wezwij brygadzistę / Aide : appeler le chef d\'équipe', 'body' => 'HalaHelpInput', 'response' => 'HalaHelpState', 'ok' => 201, 'errors' => [400, 401, 403, 409, 422],
                'description' => "Un seul wezwanie ouvert par groupe : un nouvel appui le complète (motif bag / chill plus précis que help). Lu par la vue Zmiana (GET /kiosk/hala/shift)."],
            'PATCH /kiosk/hala/help/{id:\d+}' => ['tag' => 'kiosk', 'id' => 'halaHelpAction', 'summary' => 'Idę / załatwione — Je viens / réglé', 'body' => 'HalaHelpAction', 'response' => 'HalaShift', 'errors' => [400, 401, 403, 404, 409, 422],
                'description' => "Chef de zmiana seulement (403 HALA_NOT_SHIFT_LEAD). take : open → taken ; close : → closed. 409 HALA_HELP_CLOSED."],
            'GET /kiosk/hala/shift' => ['tag' => 'kiosk', 'id' => 'halaShift', 'summary' => 'Zmiana : stan grup i wezwania / Équipe : état des groupes et appels', 'response' => 'HalaShift', 'errors' => [401, 403],
                'description' => "Chef de zmiana (poste dans hala.shift_lead_positions). Par groupe actif ce jour : fait (%), statut green / gold (retard sur l'heure au-delà de hala.delay_tolerance_pct) / red (wezwanie ouvert, schładzanie hors limites), jour validé. Rafraîchir toutes les poll_s secondes."],
            'POST /kiosk/hala/shift/validate' => ['tag' => 'kiosk', 'id' => 'halaShiftValidate', 'summary' => 'Zatwierdź zmianę / Valider la journée de l\'équipe', 'response' => 'HalaShift', 'errors' => [401, 403, 409],
                'description' => "Chaque groupe vert et validé, aucun wezwanie ouvert — sinon 409 HALA_SHIFT_NOT_READY. Enregistre qui et quand (n_hala_shift)."],
            'GET /kiosk/hala/photos/{id:\d+}' => ['tag' => 'kiosk', 'id' => 'halaPhoto', 'summary' => 'Zdjęcie produktu / Photo du produit', 'response' => 'HalaPhoto', 'errors' => [401, 403, 404],
                'description' => "id = article ; photo de la fiche produit (emplacement 0), en data URL."],
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
        $obj = ['type' => 'object'];
        $nObj = ['type' => 'object', 'nullable' => true];
        $list = ['type' => 'array', 'items' => ['type' => 'object']];
        $help = ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $int, 'group_id' => $nInt, 'group' => $nStr, 'employee' => $nStr, 'step' => $nStr,
            'reason' => ['type' => 'string', 'enum' => ['help', 'bag', 'chill']], 'note' => $nStr, 'status' => ['type' => 'string', 'enum' => ['open', 'taken', 'closed']],
            'created_at' => $str, 'taken_at' => $nStr, 'taken_by' => $nStr, 'closed_at' => $nStr]];
        return [
            'HalaDay' => ['type' => 'object', 'properties' => ['date' => $str, 'guided' => $bool, 'settings' => $obj, 'group' => $obj,
                'person' => ['type' => 'object', 'properties' => ['id' => $nInt, 'name' => $nStr, 'first_name' => $nStr, 'lead' => $bool]], 'shift' => $obj,
                'steps' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string', 'enum' => ['make', 'bags', 'chill', 'clean', 'pack', 'end']],
                    'state' => ['type' => 'string', 'enum' => ['done', 'now', 'later']], 'done_at' => $nStr]]], 'now' => $nStr,
                'make' => $nObj, 'bags' => $nObj, 'chill' => $nObj, 'clean' => $nObj, 'pack' => $nObj, 'end' => $obj,
                'waste_reasons' => ['type' => 'array', 'items' => $str], 'help' => $help, 'poll_s' => $int]],
            'HalaMadeInput' => ['type' => 'object', 'required' => ['made'], 'additionalProperties' => false, 'properties' => ['made' => ['type' => 'number', 'minimum' => 0]]],
            'HalaStepInput' => ['type' => 'object', 'required' => ['step'], 'additionalProperties' => false, 'properties' => ['step' => ['type' => 'string', 'enum' => ['make', 'bags', 'chill', 'clean', 'pack']]]],
            'HalaScanInput' => ['type' => 'object', 'required' => ['item_id', 'code'], 'additionalProperties' => false, 'properties' => ['item_id' => $int, 'code' => ['type' => 'string', 'maxLength' => 64], 'material_id' => $nInt]],
            'HalaScanResult' => ['type' => 'object', 'properties' => ['result' => ['type' => 'string', 'enum' => ['ok', 'wrong', 'expired', 'unknown']], 'code' => $str, 'material' => $nStr,
                'material_id' => $nInt, 'best_before' => $nStr, 'expected' => ['type' => 'array', 'items' => $str], 'choices' => $list, 'batches' => $nInt,
                'day' => ['$ref' => '#/components/schemas/HalaDay']]],
            'HalaChillInput' => ['type' => 'object', 'required' => ['item_id'], 'additionalProperties' => false, 'properties' => ['item_id' => $int, 'client_uuid' => $nStr]],
            'HalaChillMeasureInput' => ['type' => 'object', 'required' => ['temp_c'], 'additionalProperties' => false, 'properties' => ['temp_c' => ['type' => 'number', 'minimum' => -40, 'maximum' => 120]]],
            'HalaCleanInput' => ['type' => 'object', 'required' => ['done'], 'additionalProperties' => false, 'properties' => ['done' => $bool]],
            'HalaHelpInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['step' => $nStr, 'reason' => ['type' => 'string', 'enum' => ['help', 'bag', 'chill'], 'nullable' => true], 'note' => $nStr]],
            'HalaHelpState' => ['type' => 'object', 'properties' => ['help' => $help, 'poll_s' => $int]],
            'HalaHelpAction' => ['type' => 'object', 'required' => ['action'], 'additionalProperties' => false, 'properties' => ['action' => ['type' => 'string', 'enum' => ['take', 'close']]]],
            'HalaShift' => ['type' => 'object', 'properties' => ['date' => $str, 'person' => $obj, 'shift' => $obj,
                'groups' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'kind' => ['type' => 'string', 'enum' => ['make', 'pack']], 'pct' => $int,
                    'status' => ['type' => 'string', 'enum' => ['green', 'gold', 'red']], 'closed_at' => $nStr, 'calls' => $int, 'chill_bad' => $int, 'delay_min' => $int]]],
                'calls' => ['type' => 'array', 'items' => $help], 'calls_today' => $int, 'validated' => $nObj, 'can_validate' => $bool, 'poll_s' => $int]],
            'HalaPhoto' => ['type' => 'object', 'properties' => ['item_id' => $int, 'src' => $str]],
        ];
    }
}
