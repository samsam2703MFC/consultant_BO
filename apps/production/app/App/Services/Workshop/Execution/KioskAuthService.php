<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Jwt;
use App\Core\Api\Workshop\JwtException;
use App\Core\Api\Workshop\Translator;
use App\Core\Api\Workshop\Validator;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\TransactionManager;
use App\Repositories\Workshop\ExecutionRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Referentiel\AuditTrail;

/**
 * P7 — tablettes : appairage, identification des personnes, contexte des appels.
 *
 * Une tablette est enregistrée par un responsable (code d'appairage à usage unique, kiosk.pairing_ttl_min)
 * et reçoit un jeton d'appareil ; seuls des condensés SHA-256 sont stockés. Une personne s'identifie
 * par badge (NFC / QR) ou en touchant son nom, puis son code PIN (bcrypt) ; après kiosk.pin_max_attempts
 * erreurs elle est bloquée kiosk.lock_minutes. Elle reçoit un jeton court (kiosk.employee_token_ttl_s)
 * lié à la tablette, pour ses actions. Pointage par QR code : la tablette affiche un QR changeant
 * (kiosk.qr_ttl_s) ; la personne le scanne avec son téléphone, enregistré une fois (nom + PIN), puis
 * entre ou sort d'un geste — sans QR frais, pas de pointage. Tablette de bureau : la personne choisit sa section puis
 * son nom ; le QR est personnel (personne + jour) et seul le téléphone enregistré de cette personne le valide. Biométrie : seulement avec consentement
 * écrit enregistré, et jamais seule (le badge + PIN reste toujours possible).
 */
final class KioskAuthService
{
    public const AUDIENCE = 'tfb-kiosk';
    public const QR_AUDIENCE = 'tfb-clock-qr';
    /** atelier : tablette d'un groupe ; driver : tablette chauffeur ; office : tablette de bureau (section → nom → QR personnel). */
    public const MODES = ['atelier', 'driver', 'office'];
    /** Poste des chauffeurs (n_position.code) : liste de la tablette chauffeur. */
    public const DRIVER_POSITION = 'KIEROWCA';
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private ExecutionRepository $repo,
        private ParamRepository $params,
        private WorkshopConfig $config,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Contexte d'un appel de tablette. Le groupe : celui de la tablette ; sur une tablette générique (atelier sans groupe,
     * 27/10/2026), celui que la personne a choisi en s'identifiant (jeton, claim grp), sinon le groupe touché à l'accueil
     * (en-tête X-Kiosk-Group, groupe actif) — la production, le stock, les recettes suivent alors ce groupe.
     */
    public function context(string $deviceToken, ?string $employeeToken, string $requestId, Translator $translator, ?int $chosenGroup = null): ApiContext
    {
        if ($deviceToken === '') throw new ApiException('DEVICE_UNAUTHENTICATED', 401);
        $device = $this->repo->deviceByToken(hash('sha256', $deviceToken)) ?? throw new ApiException('DEVICE_UNAUTHENTICATED', 401);
        if (!(int) $device['active']) throw new ApiException('DEVICE_DISABLED', 403);
        $this->repo->touchDevice((int) $device['id']);
        $employeeId = null;
        $tokenGroup = null;
        if ($employeeToken !== null) {
            if ($employeeToken === '') throw new ApiException('EMPLOYEE_UNAUTHENTICATED', 401);
            try {
                $claims = $this->jwt()->decode($employeeToken, self::AUDIENCE);
            } catch (JwtException $e) {
                throw new ApiException($e->getMessage() === 'expired' ? 'EMPLOYEE_SESSION_EXPIRED' : 'EMPLOYEE_UNAUTHENTICATED', 401);
            }
            $employee = $this->repo->employee((int) ($claims['sub'] ?? 0));
            if ((int) ($claims['dev'] ?? 0) !== (int) $device['id'] || $employee === null || !(int) $employee['active']) {
                throw new ApiException('EMPLOYEE_UNAUTHENTICATED', 401);
            }
            $employeeId = (int) $employee['id'];
            $tokenGroup = isset($claims['grp']) && (int) $claims['grp'] > 0 ? (int) $claims['grp'] : null;
        }
        return new ApiContext($requestId, $translator, null, [], ['prod.kiosk' => true], [],
            deviceId: (int) $device['id'], employeeId: $employeeId, deviceGroupId: $this->groupOf($device, $tokenGroup, $chosenGroup));
    }

    /**
     * Le groupe d'un appel : celui du jeton, sinon celui touché à l'accueil, sinon celui de la tablette. Une tablette de
     * production liée à un groupe peut aussi prendre un autre groupe (27/10/2026) ; chauffeur et bureau gardent le leur.
     */
    private function groupOf(array $device, ?int $tokenGroup, ?int $chosenGroup): ?int
    {
        $own = $device['id_group'] !== null ? (int) $device['id_group'] : null;
        if (($device['mode'] ?? 'atelier') !== 'atelier') return $own;
        if ($tokenGroup !== null) return $tokenGroup;
        return $chosenGroup !== null && $chosenGroup !== $own && $this->repo->groupActive($chosenGroup) ? $chosenGroup : $own;
    }

    // --- Responsable : tablettes et identifiants ----------------------------------------------------

    public function devices(): array
    {
        return array_map(fn($d) => [
            'id' => (int) $d['id'], 'name' => $d['name'], 'group_id' => $d['id_group'] !== null ? (int) $d['id_group'] : null,
            'mode' => $d['mode'] ?? 'atelier', 'vehicle_id' => $d['id_carrier_vehicle'] !== null ? (int) $d['id_carrier_vehicle'] : null, 'vehicle_label' => $d['vehicle_label'] ?? null,
            'active' => (bool) $d['active'], 'paired' => (bool) $d['paired'], 'last_seen_at' => $d['last_seen_at'],
            'pairing_pending' => $d['pairing_expires_at'] !== null && $d['pairing_expires_at'] > date('Y-m-d H:i:s'),
        ], $this->repo->devices());
    }

    /** Nouvelle tablette, ou nouvel appairage (l'ancien jeton est révoqué) : le code n'est rendu qu'une fois. */
    public function createDevice(array $body, ApiContext $context, ?int $id = null): array
    {
        $v = (new Validator($body))->only(['name', 'group_id', 'mode', 'vehicle_id', 'active'])
            ->string('name', 100, $id === null)->integer('group_id', false, 1, null, true)->choice('mode', self::MODES, false)
            ->integer('vehicle_id', false, 1, null, true)->boolean('active');
        $this->checkVehicle($v);
        $v->throwIfInvalid();
        $c = $v->clean();
        if ($id !== null && $this->repo->find($id) === null) throw ApiException::notFound('device', $id);
        $code = '';
        for ($i = 0; $i < 8; $i++) $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        $ttl = max(1, (int) $this->params->get('kiosk.pairing_ttl_min', '15'));
        $data = array_intersect_key($c, array_flip(['name', 'active'])) + [
            'pairing_code_hash' => hash('sha256', $code), 'pairing_expires_at' => date('Y-m-d H:i:s', time() + $ttl * 60), 'token_hash' => null,
        ];
        if (array_key_exists('group_id', $c)) $data['id_group'] = $c['group_id'];
        $data = array_merge($data, $this->modeData($c));
        $this->tx->execute(function () use (&$id, $data, $context) {
            if ($id === null) {
                $id = $this->repo->insert($data + ['created_by' => $context->userId, 'active' => 1]);
                $this->audit->record($context, 'create', 'device', $id, null, ['name' => $data['name']]);
            } else {
                $this->repo->update($id, $data);
                $this->audit->record($context, 'pairing', 'device', $id, null, ['token_revoked' => true]);
            }
        });
        return ['id' => $id, 'pairing_code' => $code, 'expires_at' => $data['pairing_expires_at']];
    }

    public function updateDevice(int $id, array $body, ApiContext $context): array
    {
        if (!empty($body['new_pairing_code'])) {
            unset($body['new_pairing_code']);
            return $this->createDevice($body, $context, $id);
        }
        $device = $this->repo->find($id) ?? throw ApiException::notFound('device', $id);
        $v = (new Validator($body))->only(['name', 'group_id', 'mode', 'vehicle_id', 'active'])->string('name', 100, false)->integer('group_id', false, 1, null, true)
            ->choice('mode', self::MODES, false)->integer('vehicle_id', false, 1, null, true)->boolean('active');
        $this->checkVehicle($v);
        $v->throwIfInvalid();
        $c = $v->clean();
        $data = array_intersect_key($c, array_flip(['name', 'active']));
        if (array_key_exists('group_id', $c)) $data['id_group'] = $c['group_id'];
        $data = array_merge($data, $this->modeData($c));
        if (isset($data['active'])) $data['active'] = $data['active'] ? 1 : 0;
        $this->repo->update($id, $data);
        $this->audit->record($context, 'update', 'device', $id, ['active' => (bool) $device['active']], $data);
        return ['id' => $id] + $data;
    }

    /** Badge (le numéro n'est jamais stocké en clair), PIN de 4 à 6 chiffres, consentement biométrique. */
    public function setCredentials(int $employeeId, array $body, ApiContext $context): array
    {
        if ($this->repo->employee($employeeId) === null) throw ApiException::notFound('employee', $employeeId);
        $v = (new Validator($body))->only(['badge', 'pin', 'biometric_consent', 'unlock'])
            ->string('badge', 64, false, true)->string('pin', 6, false, true, '/^\d{4,6}$/', 'INVALID_PIN')->boolean('biometric_consent')->boolean('unlock');
        $v->throwIfInvalid();
        $c = $v->clean();
        $data = ['updated_by' => $context->userId];
        if (array_key_exists('badge', $c)) {
            $hash = $c['badge'] === null || trim($c['badge']) === '' ? null : $this->badgeHash($c['badge']);
            $other = $hash ? $this->repo->credentialByBadge($hash) : null;
            if ($other && (int) $other['id_employee'] !== $employeeId) throw ApiException::validation([['field' => 'badge', 'code' => 'BADGE_TAKEN', 'params' => []]]);
            $data['badge_hash'] = $hash;
        }
        if (array_key_exists('pin', $c)) {
            $data['pin_hash'] = $c['pin'] === null ? null : password_hash($c['pin'], PASSWORD_BCRYPT);
            $data['failed_attempts'] = 0;
            $data['locked_until'] = null;
        }
        if (!empty($c['unlock'])) { $data['failed_attempts'] = 0; $data['locked_until'] = null; }
        if (array_key_exists('biometric_consent', $c)) {
            $data['biometric_consent'] = $c['biometric_consent'] ? 1 : 0;
            $data['biometric_consent_at'] = $c['biometric_consent'] ? date('Y-m-d H:i:s') : null;
            $data['biometric_consent_by'] = $c['biometric_consent'] ? $context->userId : null;
        }
        $this->repo->saveCredential($employeeId, $data);
        $this->audit->record($context, 'credentials', 'employee', $employeeId, null,
            ['badge' => array_key_exists('badge_hash', $data) ? ($data['badge_hash'] ? 'set' : 'removed') : 'unchanged', 'pin' => array_key_exists('pin_hash', $data) ? ($data['pin_hash'] ? 'set' : 'removed') : 'unchanged',
             'biometric_consent' => $data['biometric_consent'] ?? null]);
        $cred = $this->repo->credential($employeeId);
        return ['employee_id' => $employeeId, 'has_badge' => $cred['badge_hash'] !== null, 'has_pin' => $cred['pin_hash'] !== null,
            'biometric_consent' => (bool) $cred['biometric_consent'], 'locked' => $cred['locked_until'] !== null && $cred['locked_until'] > date('Y-m-d H:i:s')];
    }

    // --- Tablette -----------------------------------------------------------------------------------

    public function pair(array $body): array
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', (string) ($body['code'] ?? '')));
        $device = $code === '' ? null : $this->repo->deviceByPairing(hash('sha256', $code));
        if ($device === null || !(int) $device['active']) throw ApiException::validation([['field' => 'code', 'code' => 'PAIRING_CODE_INVALID', 'params' => []]]);
        $token = bin2hex(random_bytes(32));
        $this->repo->update((int) $device['id'], ['token_hash' => hash('sha256', $token), 'pairing_code_hash' => null, 'pairing_expires_at' => null, 'last_seen_at' => date('Y-m-d H:i:s')]);
        return ['device_token' => $token, 'device' => ['id' => (int) $device['id'], 'name' => $device['name'], 'group_id' => $device['id_group'] !== null ? (int) $device['id_group'] : null,
            'mode' => $device['mode'] ?? 'atelier']];
    }

    /** Tablette appairée (jeton en en-tête ou en cookie) : nom et groupe — la tablette vérifie au démarrage qu'elle est appairée. */
    public function device(ApiContext $context): array
    {
        $d = $this->repo->find((int) $context->deviceId);
        $mode = $d['mode'] ?? 'atelier';
        // generic : tablette de production sans groupe — groupe → personne → PIN, puis la production du groupe choisi.
        // home_group_id : le groupe de la tablette (group_id peut être un autre groupe touché à l'accueil).
        return ['id' => (int) $context->deviceId, 'name' => $d['name'] ?? null, 'group_id' => $context->deviceGroupId, 'mode' => $mode,
            'home_group_id' => isset($d['id_group']) && $d['id_group'] !== null ? (int) $d['id_group'] : null,
            'generic' => $mode === 'atelier' && ($d['id_group'] ?? null) === null,
            'vehicle_id' => isset($d['id_carrier_vehicle']) ? (int) $d['id_carrier_vehicle'] : null];
    }

    /** Pojazdy do wyboru dla tabletu kierowcy (zarządzanie tabletami). */
    public function vehicles(): array
    {
        return array_map(fn($v) => ['id' => (int) $v['id'], 'label' => trim((string) $v['regis_num']) !== '' ? $v['regis_num'] : (string) $v['carrier'],
            'carrier' => $v['carrier'], 'driver' => $v['driver'] !== '' ? $v['driver'] : null], $this->repo->vehicleChoices());
    }

    private function checkVehicle(Validator $v): void
    {
        if ($v->value('vehicle_id') !== null && !$this->repo->vehicleExists((int) $v->value('vehicle_id'))) {
            $v->add('vehicle_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('vehicle_id')]);
        }
    }

    /** Tryb tabletu : pracownia (grupa), kierowca (pojazd domyślny ; bez grupy) albo biuro (wszystkie działy ; bez grupy i pojazdu). */
    private function modeData(array $c): array
    {
        $data = [];
        if (array_key_exists('mode', $c)) $data['mode'] = $c['mode'];
        if (array_key_exists('vehicle_id', $c)) $data['id_carrier_vehicle'] = $c['vehicle_id'];
        if (in_array($c['mode'] ?? null, ['driver', 'office'], true)) $data['id_group'] = null;
        if (in_array($c['mode'] ?? null, ['atelier', 'office'], true)) $data['id_carrier_vehicle'] = null;
        return $data;
    }

    /**
     * Personnes proposées sur la tablette, pour s'identifier en touchant son nom : le groupe de la tablette ; tablette
     * chauffeur : les personnes au poste Kierowca-dostawca (toutes les personnes actives si aucun poste n'est encore saisi).
     */
    public function people(ApiContext $context): array
    {
        $device = $this->repo->find((int) $context->deviceId);
        $drivers = [];
        if (($device['mode'] ?? 'atelier') === 'driver') {
            // Tablette chauffeur (27/10/2026) : tout le personnel, les chauffeurs (poste Kierowca) en tête.
            foreach ($this->repo->people(null, self::DRIVER_POSITION) as $p) $drivers[(int) $p['id']] = $p;
            $others = array_filter($this->repo->people(null), fn($p) => !isset($drivers[(int) $p['id']]));
            $rows = array_merge(array_values($drivers), array_values($others));
        } else {
            $rows = $this->repo->people($context->deviceGroupId);
        }
        return array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'has_pin' => (bool) $p['has_pin'], 'clocked_in' => $p['clocked_in_at'] !== null,
            'driver' => isset($drivers[(int) $p['id']])], $rows);
    }

    /**
     * Groupes de production proposés à l'accueil d'une tablette de production (27/10/2026) : nom dans la langue de la tablette,
     * couleur, personnes et présents ; own = le groupe de la tablette (une tablette de groupe peut en prendre un autre).
     */
    public function groups(ApiContext $context): array
    {
        $device = $this->repo->find((int) $context->deviceId);
        $fixed = isset($device['id_group']) && $device['id_group'] !== null ? (int) $device['id_group'] : null;
        $fr = $context->translator->lang() === 'fr';
        $out = [];
        foreach ($this->repo->kioskGroups() as $g) {
            $out[] = ['id' => (int) $g['id'], 'name' => $fr && trim((string) $g['name_fr']) !== '' ? $g['name_fr'] : $g['name_pl'],
                'color' => $g['color'], 'people' => (int) $g['people'], 'present' => (int) $g['present'], 'own' => (int) $g['id'] === $fixed];
        }
        return $out;
    }

    public function identify(array $body, ApiContext $context): array
    {
        // Tablette de bureau : PIN + signature pour qui n'a pas de téléphone enregistré (kiosk.office_pin_all = 1 : pour tous) ;
        // avec un téléphone, le QR personnel validé sur le téléphone (OFFICE_USE_PHONE, avant le contrôle du PIN).
        $device = $this->repo->find((int) $context->deviceId);
        if (($device['mode'] ?? 'atelier') === 'office' && is_int($body['employee_id'] ?? null) && !$this->officePinAll()
            && $this->repo->activePhones($body['employee_id']) > 0) {
            throw new ApiException('OFFICE_USE_PHONE', 409);
        }
        $employee = $this->authenticateEmployee($body, (int) $context->deviceId, $context);
        // Tablette générique : le groupe touché à l'accueil, à défaut le groupe principal de la personne (badge).
        return $this->employeeToken($employee, (int) $context->deviceId, $context->deviceGroupId
            ?? (isset($employee['id_primary_group']) && $employee['id_primary_group'] !== null ? (int) $employee['id_primary_group'] : null));
    }

    /**
     * Nowy jeton osoby już zidentyfikowanej (jeton jeszcze ważny) : tablet przedłuża sesję, póki osoba
     * pracuje (wpisywanie partii, zdjęcia) ; bezczynność nadal wylogowuje (kiosk.idle_logout_s).
     */
    public function refresh(ApiContext $context): array
    {
        $employee = $this->repo->employee((int) $context->employeeId);
        if ($employee === null || !(int) $employee['active']) throw new ApiException('EMPLOYEE_UNAUTHENTICATED', 401);
        return $this->employeeToken($employee, (int) $context->deviceId, $context->deviceGroupId);
    }

    private function employeeToken(array $employee, int $deviceId, ?int $groupId = null): array
    {
        $now = time();
        $device = $this->repo->find($deviceId);
        // Groupe touché à l'accueil (tablette générique, ou tablette de groupe sur un autre groupe) : il voyage dans le jeton (claim grp).
        $own = ($device['id_group'] ?? null) !== null ? (int) $device['id_group'] : null;
        $claimGroup = ($device['mode'] ?? 'atelier') === 'atelier' && $groupId !== null && $groupId !== $own ? ['grp' => $groupId] : [];
        // Tablette chauffeur : la session dure la tournée (driver.session_hours) ; atelier : jeton court prolongé en activité.
        $ttl = ($device['mode'] ?? 'atelier') === 'driver'
            ? max(1, min(24, (int) $this->params->get('driver.session_hours', '12'))) * 3600
            : max(30, (int) $this->params->get('kiosk.employee_token_ttl_s', '120'));
        return [
            'employee_token' => $this->jwt()->encode(['iss' => self::AUDIENCE, 'aud' => self::AUDIENCE, 'sub' => (string) $employee['id'], 'dev' => $deviceId,
                'iat' => $now, 'nbf' => $now, 'exp' => $now + $ttl, 'jti' => bin2hex(random_bytes(8))] + $claimGroup),
            'expires_in' => $ttl,
            'employee' => ['id' => (int) $employee['id'], 'name' => $employee['name']],
            'group_id' => $claimGroup['grp'] ?? $own,
        ];
    }

    // --- Pointage par QR code scanné avec son téléphone ---------------------------------------------

    /**
     * QR code affiché par la tablette : jeton signé de la tablette, court (kiosk.qr_ttl_s) ; l'adresse
     * encodée est {base}/kiosk/scan#t=… (le fragment n'est jamais envoyé au serveur ni journalisé).
     */
    public function qr(ApiContext $context, string $base): array
    {
        $ttl = max(20, (int) $this->params->get('kiosk.qr_ttl_s', '90'));
        // Tablette de groupe sur un autre groupe : le téléphone propose les personnes de ce groupe-là (claim grp).
        $own = $this->repo->find((int) $context->deviceId)['id_group'] ?? null;
        $grp = $own !== null && $context->deviceGroupId !== null && $context->deviceGroupId !== (int) $own ? ['grp' => $context->deviceGroupId] : [];
        return $this->qrPayload(['dev' => (int) $context->deviceId] + $grp, $base, $ttl)
            + ['refresh_s' => max(5, min($ttl - 10, (int) $this->params->get('kiosk.qr_refresh_s', '20')))];
    }

    /**
     * Tablette de bureau : QR personnel (personne + jour du serveur), valable kiosk.office_qr_ttl_s ; seul le téléphone
     * enregistré de cette personne peut le valider (QR_OTHER_PERSON sinon), et seulement ce jour-là.
     */
    public function personalQr(ApiContext $context, int $employeeId, string $base): array
    {
        $ttl = max(30, (int) $this->params->get('kiosk.office_qr_ttl_s', '120'));
        return $this->qrPayload(['dev' => (int) $context->deviceId, 'emp' => $employeeId, 'day' => date('Y-m-d')], $base, $ttl)
            + ['refresh_s' => max(10, $ttl - 15)];
    }

    /** Jeton signé, adresse {base}/kiosk/scan#t=… (le fragment n'est jamais envoyé au serveur) et matrice du QR. */
    private function qrPayload(array $claims, string $base, int $ttl): array
    {
        $base = rtrim(trim($base), '/');
        if (!preg_match('#^https?://[^\s"<>]{1,200}$#', $base)) throw ApiException::validation([['field' => 'base', 'code' => 'INVALID_URL', 'params' => []]]);
        $now = time();
        $token = $this->jwt()->encode(['iss' => self::AUDIENCE, 'aud' => self::QR_AUDIENCE] + $claims + ['iat' => $now, 'nbf' => $now - 5, 'exp' => $now + $ttl, 'jti' => bin2hex(random_bytes(6))]);
        $url = $base . '/kiosk/scan#t=' . $token;
        $code = new \TCPDF2DBarcode($url, 'QRCODE,M');
        $matrix = $code->getBarcodeArray();
        return [
            'url' => $url, 'expires_in' => $ttl,
            'size' => (int) $matrix['num_cols'], 'rows' => array_map(fn($row) => implode('', array_map(fn($v) => $v ? '1' : '0', $row)), $matrix['bcode']),
        ];
    }

    /** Contexte d'un appel avec un QR frais de la tablette (preuve de présence sur place). */
    public function qrContext(string $qr, string $requestId, Translator $translator): ApiContext
    {
        [$device, $qrEmployee, $qrGroup] = $this->deviceFromQr($qr);
        // QR personnel (tablette de bureau) : la personne du QR porte le contexte (liste et enregistrement limités à elle).
        return new ApiContext($requestId, $translator, null, [], ['prod.kiosk' => true], [],
            deviceId: (int) $device['id'], employeeId: $qrEmployee, deviceGroupId: $qrGroup ?? ($device['id_group'] !== null ? (int) $device['id_group'] : null));
    }

    /** Appel d'un téléphone enregistré, avec un QR frais de la tablette. */
    public function phoneContext(string $phoneToken, string $qr, string $requestId, Translator $translator): ApiContext
    {
        if ($phoneToken === '') throw new ApiException('PHONE_UNAUTHENTICATED', 401);
        $phone = $this->repo->phoneByHash(hash('sha256', $phoneToken)) ?? throw new ApiException('PHONE_UNAUTHENTICATED', 401);
        $days = max(1, (int) $this->params->get('kiosk.phone_token_days', '180'));
        if ($phone['revoked_at'] !== null || strtotime($phone['created_at']) < time() - $days * 86400) throw new ApiException('PHONE_REVOKED', 401);
        $employee = $this->repo->employee((int) $phone['id_employee']);
        if ($employee === null || !(int) $employee['active']) throw new ApiException('PHONE_REVOKED', 401);
        $context = $this->qrContext($qr, $requestId, $translator);
        if ($context->employeeId !== null && $context->employeeId !== (int) $employee['id']) $this->otherPerson($context->employeeId);
        $this->repo->touchPhone((int) $phone['id']);
        return new ApiContext($requestId, $translator, null, [], ['prod.kiosk' => true], [],
            deviceId: $context->deviceId, employeeId: (int) $employee['id'], deviceGroupId: $context->deviceGroupId);
    }

    /** Personnes proposées au premier scan (groupe de la tablette qui affiche le QR). */
    public function phonePeople(ApiContext $context): array
    {
        // QR personnel : seulement la personne du QR (son PIN au premier scan).
        $people = $context->employeeId !== null
            ? array_values(array_filter($this->repo->people(null), fn($p) => (int) $p['id'] === $context->employeeId))
            : $this->repo->people($context->deviceGroupId);
        return array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'has_pin' => (bool) $p['has_pin']], $people);
    }

    /** Premier scan : la personne choisit son nom (ou badge) et donne son PIN ; le téléphone reçoit son jeton. */
    public function enrollPhone(array $body, ApiContext $context, ?string $userAgent): array
    {
        if ($context->employeeId !== null && isset($body['employee_id']) && (int) $body['employee_id'] !== $context->employeeId) $this->otherPerson($context->employeeId);
        $employee = $this->authenticateEmployee($body, (int) $context->deviceId, $context);
        if ($context->employeeId !== null && (int) $employee['id'] !== $context->employeeId) $this->otherPerson($context->employeeId);
        $token = bin2hex(random_bytes(32));
        $label = $userAgent ? mb_substr(trim(preg_replace('/\s+/', ' ', $userAgent)), 0, 120) : null;
        $id = $this->tx->execute(function () use ($employee, $token, $label, $context) {
            $id = $this->repo->insertPhone((int) $employee['id'], hash('sha256', $token), $label);
            $this->audit->record($context, 'phone_enrolled', 'employee', (int) $employee['id'], null, ['phone_id' => $id, 'device_id' => (int) $context->deviceId]);
            return $id;
        });
        return ['phone_token' => $token, 'employee' => ['id' => (int) $employee['id'], 'name' => $employee['name']],
            'expires_in_days' => max(1, (int) $this->params->get('kiosk.phone_token_days', '180'))];
    }

    public function revokePhones(int $employeeId, ApiContext $context): array
    {
        if ($this->repo->employee($employeeId) === null) throw ApiException::notFound('employee', $employeeId);
        $count = $this->repo->revokePhones($employeeId);
        $this->audit->record($context, 'phones_revoked', 'employee', $employeeId, null, ['count' => $count]);
        return ['employee_id' => $employeeId, 'revoked' => $count];
    }

    /** @return array{0: array, 1: ?int} tablette du QR et personne du QR personnel (null : QR de groupe) */
    private function deviceFromQr(string $qr): array
    {
        if ($qr === '') throw new ApiException('QR_INVALID', 401);
        try {
            $claims = $this->jwt()->decode($qr, self::QR_AUDIENCE);
        } catch (JwtException $e) {
            throw new ApiException($e->getMessage() === 'expired' ? 'QR_EXPIRED' : 'QR_INVALID', 401);
        }
        $device = $this->repo->find((int) ($claims['dev'] ?? 0));
        if ($device === null || !(int) $device['active'] || $device['token_hash'] === null) throw new ApiException('QR_INVALID', 401);
        // QR personnel : valable seulement le jour où il a été affiché.
        if (isset($claims['day']) && $claims['day'] !== date('Y-m-d')) throw new ApiException('QR_EXPIRED', 401);
        $group = isset($claims['grp']) && (int) $claims['grp'] > 0 && $this->repo->groupActive((int) $claims['grp']) ? (int) $claims['grp'] : null;
        return [$device, isset($claims['emp']) ? (int) $claims['emp'] : null, $group];
    }

    /** Le QR personnel d'une autre personne : refus avec son nom (le téléphone n'est pas le sien). */
    private function otherPerson(int $employeeId): never
    {
        throw new ApiException('QR_OTHER_PERSON', 403, [], ['name' => $this->repo->employee($employeeId)['name'] ?? '?']);
    }

    /** Badge ou personne + PIN, avec blocage après kiosk.pin_max_attempts erreurs. */
    private function authenticateEmployee(array $body, int $deviceId, ApiContext $context): array
    {
        $v = (new Validator($body))->only(['badge', 'employee_id', 'pin'])
            ->string('badge', 64, false)->integer('employee_id', false, 1)->string('pin', 6, true, false, '/^\d{4,6}$/', 'INVALID_PIN');
        $v->throwIfInvalid();
        $c = $v->clean();
        if (!isset($c['badge']) && !isset($c['employee_id'])) throw ApiException::validation([['field' => 'badge', 'code' => 'REQUIRED', 'params' => []]]);
        $cred = isset($c['badge']) ? $this->repo->credentialByBadge($this->badgeHash($c['badge'])) : $this->repo->credential((int) $c['employee_id']);
        if ($cred === null && isset($c['badge'])) throw new ApiException('BADGE_UNKNOWN', 401);
        $employeeId = $cred !== null ? (int) $cred['id_employee'] : (int) $c['employee_id'];
        $employee = $this->repo->employee($employeeId);
        if ($employee === null || !(int) $employee['active']) throw new ApiException('BADGE_UNKNOWN', 401);
        if ($cred === null || $cred['pin_hash'] === null) throw new ApiException('PIN_NOT_SET', 409);
        if ($cred['locked_until'] !== null && $cred['locked_until'] > date('Y-m-d H:i:s')) {
            throw new ApiException('EMPLOYEE_LOCKED', 423, [], ['until' => substr($cred['locked_until'], 11, 5)]);
        }
        if (!password_verify($c['pin'], $cred['pin_hash'])) {
            $failed = (int) $cred['failed_attempts'] + 1;
            $max = max(1, (int) $this->params->get('kiosk.pin_max_attempts', '5'));
            $lock = $failed >= $max ? date('Y-m-d H:i:s', time() + max(1, (int) $this->params->get('kiosk.lock_minutes', '15')) * 60) : null;
            $this->repo->saveCredential($employeeId, ['failed_attempts' => $lock ? 0 : $failed, 'locked_until' => $lock]);
            $this->audit->record($context, 'pin_failed', 'employee', $employeeId, null, ['device_id' => $deviceId, 'locked' => $lock !== null]);
            throw $lock ? new ApiException('EMPLOYEE_LOCKED', 423, [], ['until' => substr($lock, 11, 5)]) : new ApiException('PIN_INVALID', 401, [], ['left' => $max - $failed]);
        }
        if ((int) $cred['failed_attempts'] > 0) $this->repo->saveCredential($employeeId, ['failed_attempts' => 0, 'locked_until' => null]);
        return $employee;
    }

    /** kiosk.office_pin_all : PIN + signature sur la tablette de bureau aussi pour les personnes avec un téléphone. */
    public function officePinAll(): bool
    {
        return $this->params->get('kiosk.office_pin_all', '0') === '1';
    }

    public function badgeHash(string $badge): string
    {
        $normalized = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $badge));
        return hash_hmac('sha256', $normalized, $this->config->jwtSecret());
    }

    private function jwt(): Jwt
    {
        return new Jwt($this->config->jwtSecret());
    }
}
