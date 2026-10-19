<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/** Documentation OpenAPI du domaine « exécution » (P7 : tablettes, pointage, pointage au téléphone par QR code, signatures, heures, KPIs, cockpit). */
final class OpenApiExecution
{
    public static function operations(): array
    {
        $t = 'execution';
        $period = ['from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date']];
        return [
            'GET /kiosk/devices' => ['tag' => $t, 'id' => 'listDevices', 'summary' => 'Tablety / Tablettes', 'response' => 'Device', 'list' => true],
            'POST /kiosk/devices' => ['tag' => $t, 'id' => 'createDevice', 'summary' => 'Nowy tablet (kod parowania) / Nouvelle tablette (code d\'appairage)', 'body' => 'DeviceInput', 'response' => 'PairingCode', 'ok' => 201, 'errors' => [400, 422],
                'description' => "Le code d'appairage (8 caractères, kiosk.pairing_ttl_min) n'est rendu qu'une fois ; seul son condensé est stocké."],
            'PATCH /kiosk/devices/{id:\d+}' => ['tag' => $t, 'id' => 'updateDevice', 'summary' => 'Zmień / zablokuj tablet / Modifier, bloquer une tablette', 'body' => 'DeviceUpdateInput', 'response' => 'Device', 'errors' => [400, 404, 422],
                'description' => 'new_pairing_code: true révoque le jeton de la tablette et rend un nouveau code.'],
            'PUT /kiosk/employees/{id:\d+}/credentials' => ['tag' => $t, 'id' => 'setCredentials', 'summary' => 'Identyfikator, PIN, zgoda biometryczna / Badge, PIN, consentement biométrique', 'body' => 'CredentialsInput', 'response' => 'Credentials', 'errors' => [400, 404, 422],
                'description' => 'Badge : condensé HMAC, jamais en clair. PIN : 4 à 6 chiffres (bcrypt). Biométrie : consentement écrit enregistré, jamais seule.'],
            'GET /kiosk/employees' => ['tag' => $t, 'id' => 'listTimesheets', 'summary' => 'Godziny miesiąca na osobę / Heures du mois par personne', 'response' => 'TimesheetSummary', 'list' => true, 'errors' => [422],
                'query' => ['month' => ['type' => 'string', 'example' => '2026-10'], 'group_id' => ['type' => 'integer']]],
            'GET /kiosk/employees/{id:\d+}/timesheet' => ['tag' => $t, 'id' => 'getTimesheet', 'summary' => 'Karta czasu pracy / Feuille d\'heures du mois', 'response' => 'Timesheet', 'errors' => [404, 422],
                'query' => ['month' => ['type' => 'string', 'example' => '2026-10']]],
            'PATCH /kiosk/clocks/{id:\d+}' => ['tag' => $t, 'id' => 'correctClock', 'summary' => 'Korekta wejścia / wyjścia / Corriger un pointage', 'body' => 'ClockCorrectionInput', 'response' => 'Clock', 'errors' => [400, 404, 422],
                'description' => 'Motif obligatoire, journalisé.'],
            'POST /kiosk/clocks' => ['tag' => $t, 'id' => 'addClock', 'summary' => 'Dopisz godziny / Ajouter un pointage manuel', 'body' => 'ClockManualInput', 'response' => 'Clock', 'ok' => 201, 'errors' => [400, 422]],
            'DELETE /kiosk/employees/{id:\d+}/phones' => ['tag' => $t, 'id' => 'revokePhones', 'summary' => 'Odwołaj telefony osoby / Révoquer les téléphones d\'une personne', 'response' => 'PhonesRevoked', 'errors' => [404],
                'description' => 'Les téléphones enregistrés de la personne ne peuvent plus pointer ; un nouvel enregistrement demande le PIN.'],
            'GET /execution/kpis' => ['tag' => $t, 'id' => 'getKpis', 'summary' => 'Wskaźniki produkcji / KPIs de production', 'response' => 'Kpis', 'errors' => [422], 'query' => $period + ['group_id' => ['type' => 'integer']],
                'description' => "OTD (MTO finis avant l'échéance), ponctualité (démarrage à ± kpi.start_tolerance_min), efficience (heures standard ÷ heures passées), utilisation (heures sur opérations ÷ présence), présence (pointage ÷ heures planifiées), rebut."],
            'GET /execution/cockpit' => ['tag' => $t, 'id' => 'getCockpit', 'summary' => 'Kokpit dnia / Cockpit du jour', 'response' => 'Cockpit',
                'description' => 'Présents par groupe, opérations du jour par statut et en retard, MTO à risque (échéance < 3 h), KPIs de la semaine, alertes.'],

            'POST /kiosk/pair' => ['tag' => 'kiosk', 'id' => 'pairDevice', 'summary' => 'Sparuj tablet / Appairer la tablette', 'body' => 'PairInput', 'response' => 'DeviceToken', 'errors' => [400, 422],
                'description' => "Échange le code d'appairage contre le jeton de l'appareil (à envoyer ensuite dans X-Device-Token)."],
            'GET /kiosk/people' => ['tag' => 'kiosk', 'id' => 'kioskPeople', 'summary' => 'Osoby przy tablecie / Personnes de la tablette', 'response' => 'KioskPerson', 'list' => true, 'errors' => [401, 403]],
            'POST /kiosk/identify' => ['tag' => 'kiosk', 'id' => 'identify', 'summary' => 'Identyfikacja: identyfikator lub osoba + PIN / Identification : badge ou personne + PIN', 'body' => 'IdentifyInput', 'response' => 'EmployeeToken', 'errors' => [400, 401, 403, 409, 422, 423],
                'description' => "Jeton court (kiosk.employee_token_ttl_s) lié à la tablette, à envoyer dans X-Employee-Token. Blocage après kiosk.pin_max_attempts PIN erronés (423)."],
            'GET /kiosk/me' => ['tag' => 'kiosk', 'id' => 'kioskMe', 'summary' => 'Ja: pointowanie i operacje / Moi : pointage et opérations', 'response' => 'KioskMe', 'errors' => [401]],
            'POST /kiosk/clock' => ['tag' => 'kiosk', 'id' => 'clock', 'summary' => 'Wejście / wyjście jednym dotknięciem / Entrée / sortie en un geste', 'body' => 'ClockInput', 'response' => 'ClockResult', 'errors' => [400, 401, 409, 422],
                'description' => "Sens déduit s'il est absent. client_uuid : rejouable sans doublon (tablette hors ligne). Une sortie ferme les temps d'opération ouverts."],
            'POST /kiosk/operations/{id:\d+}/start' => ['tag' => 'kiosk', 'id' => 'startOperation', 'summary' => 'Rozpocznij operację / Démarrer une opération', 'body' => 'StartInput', 'response' => 'KioskOperation', 'errors' => [401, 403, 404, 409],
                'description' => 'Personne pointée, opération de ses groupes ou du groupe de la tablette.'],
            'POST /kiosk/operations/{id:\d+}/pause' => ['tag' => 'kiosk', 'id' => 'pauseOperation', 'summary' => 'Pauza / Pause', 'response' => 'KioskOperation', 'errors' => [401, 403, 404, 409]],
            'POST /kiosk/operations/{id:\d+}/finish' => ['tag' => 'kiosk', 'id' => 'finishOperation', 'summary' => 'Zakończ z ilościami / Terminer avec les quantités', 'body' => 'FinishInput', 'response' => 'KioskOperation', 'errors' => [400, 401, 403, 404, 409, 422],
                'description' => "Le temps réel d'une opération de travail devient une mesure de chronométrage de l'étape (P2)."],
            'POST /kiosk/signatures' => ['tag' => 'kiosk', 'id' => 'sign', 'summary' => 'Podpis odręczny / Signature manuscrite', 'body' => 'SignatureInput', 'response' => 'Signature', 'ok' => 201, 'errors' => [400, 401, 422],
                'description' => 'PNG en data URL (300 Ko max) ; condensé SHA-256 conservé pour l\'intégrité ; stockage en base ou bucket privé.'],
            'GET /kiosk/qr' => ['tag' => 'kiosk', 'id' => 'clockQr', 'summary' => 'Kod QR wejścia / wyjścia / QR code d\'entrée / sortie', 'response' => 'ClockQr', 'errors' => [401, 403, 422],
                'query' => ['base' => ['type' => 'string', 'example' => 'https://erp.example/production']],
                'description' => "QR changeant affiché par la tablette : {base}/kiosk/scan#t=<jeton> (kiosk.qr_ttl_s, renouvelé toutes les kiosk.qr_refresh_s). rows = matrice (1 = noir)."],

            'GET /kiosk/phone/people' => ['tag' => 'phone', 'id' => 'phonePeople', 'summary' => 'Osoby (pierwsze skanowanie) / Personnes (premier scan)', 'response' => 'PhonePerson', 'list' => true, 'errors' => [401]],
            'POST /kiosk/phone/enroll' => ['tag' => 'phone', 'id' => 'enrollPhone', 'summary' => 'Zarejestruj telefon: osoba + PIN / Enregistrer le téléphone : personne + PIN', 'body' => 'IdentifyInput', 'response' => 'PhoneToken', 'ok' => 201, 'errors' => [400, 401, 409, 422, 423],
                'description' => "QR frais obligatoire (présence sur place). Jeton du téléphone (kiosk.phone_token_days) rendu une fois, seul son condensé est stocké. Mêmes blocages PIN que la tablette."],
            'GET /kiosk/phone/me' => ['tag' => 'phone', 'id' => 'phoneMe', 'summary' => 'Stan wejścia / État du pointage', 'response' => 'ClockState', 'errors' => [401]],
            'POST /kiosk/phone/clock' => ['tag' => 'phone', 'id' => 'phoneClock', 'summary' => 'Wejście / wyjście telefonem / Entrée / sortie au téléphone', 'body' => 'ClockInput', 'response' => 'ClockResult', 'errors' => [400, 401, 409, 422],
                'description' => 'Comme POST /kiosk/clock ; la tablette du QR est enregistrée comme lieu du pointage.'],
            'POST /kiosk/phone/signatures' => ['tag' => 'phone', 'id' => 'phoneSign', 'summary' => 'Podpis palcem na telefonie / Signature au doigt sur le téléphone', 'body' => 'SignatureInput', 'response' => 'Signature', 'ok' => 201, 'errors' => [400, 401, 422]],
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
        $dt = ['type' => 'string', 'example' => '2026-10-20 06:02:00'];
        return [
            'Device' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'group_id' => $nInt, 'active' => $bool, 'paired' => $bool, 'last_seen_at' => $nStr, 'pairing_pending' => $bool]],
            'DeviceInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'maxLength' => 100], 'group_id' => $nInt]],
            'DeviceUpdateInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['name' => ['type' => 'string', 'maxLength' => 100], 'group_id' => $nInt, 'active' => $bool, 'new_pairing_code' => $bool]],
            'PairingCode' => ['type' => 'object', 'properties' => ['id' => $int, 'pairing_code' => ['type' => 'string', 'example' => 'K7M2Q9XA'], 'expires_at' => $dt]],
            'CredentialsInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'badge' => ['type' => 'string', 'maxLength' => 64, 'nullable' => true, 'description' => 'Numéro NFC / QR ; null = retirer'],
                'pin' => ['type' => 'string', 'pattern' => '^\d{4,6}$', 'nullable' => true], 'biometric_consent' => $bool, 'unlock' => $bool]],
            'Credentials' => ['type' => 'object', 'properties' => ['employee_id' => $int, 'has_badge' => $bool, 'has_pin' => $bool, 'biometric_consent' => $bool, 'locked' => $bool]],
            'PairInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['code'], 'properties' => ['code' => $str]],
            'DeviceToken' => ['type' => 'object', 'properties' => ['device_token' => $str, 'device' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'group_id' => $nInt]]]],
            'KioskPerson' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'has_pin' => $bool, 'clocked_in' => $bool]],
            'IdentifyInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['pin'], 'properties' => ['badge' => $str, 'employee_id' => $int, 'pin' => ['type' => 'string', 'pattern' => '^\d{4,6}$']]],
            'EmployeeToken' => ['type' => 'object', 'properties' => ['employee_token' => $str, 'expires_in' => $int, 'employee' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str]]]],
            'Clock' => ['type' => 'object', 'properties' => ['id' => $int, 'clock_in' => $dt, 'clock_out' => $nStr, 'hours' => $num]],
            'ClockInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['direction' => ['type' => 'string', 'enum' => ['in', 'out']], 'client_uuid' => ['type' => 'string', 'format' => 'uuid']]],
            'ClockResult' => ['type' => 'object', 'properties' => ['replayed' => $bool, 'clock' => ['$ref' => '#/components/schemas/Clock'], 'signature_required' => $bool]],
            'ClockCorrectionInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['note'], 'properties' => ['clock_in' => $dt, 'clock_out' => ['type' => 'string', 'nullable' => true], 'note' => ['type' => 'string', 'maxLength' => 255]]],
            'ClockManualInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['employee_id', 'clock_in', 'clock_out', 'note'], 'properties' => ['employee_id' => $int, 'clock_in' => $dt, 'clock_out' => $dt, 'note' => ['type' => 'string', 'maxLength' => 255]]],
            'KioskOperation' => ['allOf' => [['$ref' => '#/components/schemas/Operation'], ['type' => 'object', 'properties' => [
                'actual_start' => $nStr, 'actual_end' => $nStr, 'qty_good' => $nNum, 'qty_scrap' => $nNum, 'working' => ['type' => 'array', 'items' => $int], 'late' => $bool]]]],
            'KioskMe' => ['type' => 'object', 'properties' => ['employee' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str]], 'clocked_in' => $bool,
                'clock' => ['allOf' => [['$ref' => '#/components/schemas/Clock']], 'nullable' => true], 'signature_on_clock_out' => $bool, 'idle_logout_s' => $int,
                'operations' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/KioskOperation']], 'working_on' => ['type' => 'array', 'items' => $int]]],
            'StartInput' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['client_uuid' => ['type' => 'string', 'format' => 'uuid']]],
            'FinishInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['qty_good'], 'properties' => ['qty_good' => ['type' => 'number', 'minimum' => 0],
                'qty_scrap' => ['type' => 'number', 'minimum' => 0], 'scrap_reason' => ['type' => 'string', 'maxLength' => 255, 'description' => 'obligatoire si rebut']]],
            'SignatureInput' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['purpose', 'ref', 'image'], 'properties' => [
                'purpose' => ['type' => 'string', 'enum' => ['clock_out', 'timesheet_month', 'operation']], 'ref' => ['type' => 'string', 'description' => 'id du pointage, AAAA-MM ou id de l\'opération'],
                'image' => ['type' => 'string', 'description' => 'data:image/png;base64,…']]],
            'ClockQr' => ['type' => 'object', 'properties' => ['url' => $str, 'expires_in' => $int, 'refresh_s' => $int, 'size' => $int, 'rows' => ['type' => 'array', 'items' => ['type' => 'string', 'example' => '1111111010…']]]],
            'PhonePerson' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str, 'has_pin' => $bool]],
            'PhoneToken' => ['type' => 'object', 'properties' => ['phone_token' => $str, 'employee' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str]], 'expires_in_days' => $int]],
            'ClockState' => ['type' => 'object', 'properties' => ['employee' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str]], 'clocked_in' => $bool,
                'clock' => ['allOf' => [['$ref' => '#/components/schemas/Clock']], 'nullable' => true], 'signature_on_clock_out' => $bool]],
            'PhonesRevoked' => ['type' => 'object', 'properties' => ['employee_id' => $int, 'revoked' => $int]],
            'Signature' => ['type' => 'object', 'properties' => ['id' => $int, 'purpose' => $str, 'ref' => $str, 'sha256' => $str]],
            'TimesheetSummary' => ['type' => 'object', 'properties' => ['employee_id' => $int, 'name' => $str, 'group_id' => $nInt, 'hours' => $num, 'night_hours' => $num,
                'days' => $int, 'open' => $int, 'signed' => $int, 'month_signed' => $bool, 'clocked_in' => $bool,
                'has_badge' => $bool, 'has_pin' => $bool, 'biometric_consent' => $bool, 'locked' => $bool, 'phones' => $int]],
            'Timesheet' => ['type' => 'object', 'properties' => ['employee' => ['type' => 'object', 'properties' => ['id' => $int, 'name' => $str]], 'month' => $str,
                'days' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $int, 'date' => $str, 'clock_in' => $dt, 'clock_out' => $nStr, 'hours' => $num, 'night_hours' => $num,
                    'open' => $bool, 'source' => $str, 'note' => $nStr, 'corrected' => $bool, 'signature_id' => $nInt]]],
                'totals' => ['type' => 'object', 'properties' => ['hours' => $num, 'night_hours' => $num, 'days' => $int, 'open' => $int, 'signed' => $int]],
                'month_signature' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $int, 'signed_at' => $str, 'sha256' => $str]]]],
            'KpiValues' => ['type' => 'object', 'properties' => ['operations' => $int, 'operations_done' => $int, 'started' => $int, 'started_on_time' => $int, 'punctuality_pct' => $nNum,
                'standard_h' => $num, 'worked_on_done_h' => $num, 'efficiency_pct' => $nNum, 'labour_h' => $num, 'present_h' => $num, 'utilisation_pct' => $nNum,
                'planned_h' => $num, 'attendance_pct' => $nNum, 'qty_good' => $num, 'qty_scrap' => $num, 'scrap_pct' => $nNum]],
            'Kpis' => ['type' => 'object', 'properties' => ['from' => $str, 'to' => $str,
                'totals' => ['allOf' => [['$ref' => '#/components/schemas/KpiValues'], ['type' => 'object', 'properties' => ['mto_orders' => $int, 'mto_on_time' => $int, 'mto_late' => $int, 'otd_pct' => $nNum]]]],
                'groups' => ['type' => 'array', 'items' => ['allOf' => [['$ref' => '#/components/schemas/KpiValues'], ['type' => 'object', 'properties' => ['group_id' => $int, 'group' => $str, 'color' => $str]]]]]]],
            'Cockpit' => ['type' => 'object', 'properties' => ['date' => $str, 'people_present' => $int,
                'people_by_group' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['group' => $str, 'count' => $int, 'names' => ['type' => 'array', 'items' => $str]]]],
                'operations' => ['type' => 'object', 'properties' => ['total' => $int, 'planned' => $int, 'in_progress' => $int, 'paused' => $int, 'done' => $int, 'late' => $int]],
                'mto_due_today' => $int, 'mto_at_risk' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['code' => $str, 'due_at' => $str, 'open_operations' => $int]]],
                'week' => ['$ref' => '#/components/schemas/KpiValues'], 'alerts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['code' => $str]]]]],
        ];
    }
}
