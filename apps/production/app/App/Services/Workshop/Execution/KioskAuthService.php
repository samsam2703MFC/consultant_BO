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
 * lié à la tablette, pour ses actions. Biométrie : seulement avec consentement écrit enregistré, et
 * jamais seule (le badge + PIN reste toujours possible).
 */
final class KioskAuthService
{
    public const AUDIENCE = 'tfb-kiosk';
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private ExecutionRepository $repo,
        private ParamRepository $params,
        private WorkshopConfig $config,
        private TransactionManager $tx,
        private AuditTrail $audit,
    ) {
    }

    public function context(string $deviceToken, ?string $employeeToken, string $requestId, Translator $translator): ApiContext
    {
        if ($deviceToken === '') throw new ApiException('DEVICE_UNAUTHENTICATED', 401);
        $device = $this->repo->deviceByToken(hash('sha256', $deviceToken)) ?? throw new ApiException('DEVICE_UNAUTHENTICATED', 401);
        if (!(int) $device['active']) throw new ApiException('DEVICE_DISABLED', 403);
        $this->repo->touchDevice((int) $device['id']);
        $employeeId = null;
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
        }
        return new ApiContext($requestId, $translator, null, [], ['prod.kiosk' => true], [],
            deviceId: (int) $device['id'], employeeId: $employeeId, deviceGroupId: $device['id_group'] !== null ? (int) $device['id_group'] : null);
    }

    // --- Responsable : tablettes et identifiants ----------------------------------------------------

    public function devices(): array
    {
        return array_map(fn($d) => [
            'id' => (int) $d['id'], 'name' => $d['name'], 'group_id' => $d['id_group'] !== null ? (int) $d['id_group'] : null,
            'active' => (bool) $d['active'], 'paired' => (bool) $d['paired'], 'last_seen_at' => $d['last_seen_at'],
            'pairing_pending' => $d['pairing_expires_at'] !== null && $d['pairing_expires_at'] > date('Y-m-d H:i:s'),
        ], $this->repo->devices());
    }

    /** Nouvelle tablette, ou nouvel appairage (l'ancien jeton est révoqué) : le code n'est rendu qu'une fois. */
    public function createDevice(array $body, ApiContext $context, ?int $id = null): array
    {
        $v = (new Validator($body))->only(['name', 'group_id', 'active'])
            ->string('name', 100, $id === null)->integer('group_id', false, 1, null, true)->boolean('active');
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
        $v = (new Validator($body))->only(['name', 'group_id', 'active'])->string('name', 100, false)->integer('group_id', false, 1, null, true)->boolean('active');
        $v->throwIfInvalid();
        $c = $v->clean();
        $data = array_intersect_key($c, array_flip(['name', 'active']));
        if (array_key_exists('group_id', $c)) $data['id_group'] = $c['group_id'];
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
        return ['device_token' => $token, 'device' => ['id' => (int) $device['id'], 'name' => $device['name'], 'group_id' => $device['id_group'] !== null ? (int) $device['id_group'] : null]];
    }

    /** Personnes proposées sur la tablette (groupe de la tablette), pour s'identifier en touchant son nom. */
    public function people(ApiContext $context): array
    {
        return array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'has_pin' => (bool) $p['has_pin'], 'clocked_in' => $p['clocked_in_at'] !== null],
            $this->repo->people($context->deviceGroupId));
    }

    public function identify(array $body, ApiContext $context): array
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
            $this->audit->record($context, 'pin_failed', 'employee', $employeeId, null, ['device_id' => $context->deviceId, 'locked' => $lock !== null]);
            throw $lock ? new ApiException('EMPLOYEE_LOCKED', 423, [], ['until' => substr($lock, 11, 5)]) : new ApiException('PIN_INVALID', 401, [], ['left' => $max - $failed]);
        }
        if ((int) $cred['failed_attempts'] > 0) $this->repo->saveCredential($employeeId, ['failed_attempts' => 0, 'locked_until' => null]);
        $now = time();
        $ttl = max(30, (int) $this->params->get('kiosk.employee_token_ttl_s', '120'));
        return [
            'employee_token' => $this->jwt()->encode(['iss' => self::AUDIENCE, 'aud' => self::AUDIENCE, 'sub' => (string) $employeeId, 'dev' => (int) $context->deviceId,
                'iat' => $now, 'nbf' => $now, 'exp' => $now + $ttl, 'jti' => bin2hex(random_bytes(8))]),
            'expires_in' => $ttl,
            'employee' => ['id' => $employeeId, 'name' => $employee['name']],
        ];
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
