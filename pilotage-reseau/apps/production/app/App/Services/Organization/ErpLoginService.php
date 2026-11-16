<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Api\Workshop\ApiException;
use App\Repositories\Organization\ErpLoginRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Execution\KioskAuthService;

/**
 * [300] Logowanie do ERP kodem QR (makieta 55). La page de connexion demande un QR : une demande par navigateur, liée à un
 * jeton aléatoire gardé dans sa session (ici seulement son empreinte) ; le QR ne porte que le numéro de la demande, signé
 * au nom de l'appareil interne de l'ERP (erp.qr_device_id). La personne le scanne avec son téléphone enregistré : l'entrée
 * doit être pointée (le téléphone le fait d'abord, questions et signature comme à la tablette de bureau), puis elle valide.
 * Le navigateur qui attend ouvre alors le compte ERP lié à la personne (n_user_employee), une seule fois. Rien n'est ouvert
 * pour une personne sans compte lié.
 */
final class ErpLoginService
{
    public function __construct(private ErpLoginRepository $repo, private KioskAuthService $kiosk, private ParamRepository $params,
        private PageAccessService $access)
    {
    }

    /** Connexion par QR possible sur ce serveur (appareil interne des QR présent). */
    public function enabled(): bool
    {
        return (int) $this->params->get('erp.qr_device_id', '0') > 0;
    }

    /**
     * QR pour le navigateur. $current = demande gardée dans sa session [id, nonce] : réutilisée tant qu'elle vaut encore
     * 30 s et n'est pas validée, sinon nouvelle demande. @return array{request:array{id:int, nonce:string}, qr:array}
     */
    public function qr(?array $current, string $base, ?string $userAgent): array
    {
        $device = (int) $this->params->get('erp.qr_device_id', '0');
        if ($device <= 0) throw new \RuntimeException('Brak urządzenia kodów QR (migracja 174).');
        $ttl = max(60, min(600, (int) $this->params->get('erp.login_qr_ttl_s', '180')));
        $row = $current ? $this->repo->request((int) $current['id']) : null;
        $valid = $row && hash_equals($row['nonce_hash'], hash('sha256', (string) $current['nonce'])) && $row['approved_at'] === null
            && strtotime((string) $row['expires_at']) - time() > 30;
        if (!$valid) {
            $this->repo->purge();
            $nonce = bin2hex(random_bytes(32));
            $ua = $userAgent !== null ? mb_substr(trim(preg_replace('/\s+/', ' ', $userAgent)), 0, 160) : null;
            $current = ['id' => $this->repo->create(hash('sha256', $nonce), date('Y-m-d H:i:s', time() + $ttl), $ua), 'nonce' => $nonce];
            $row = $this->repo->request($current['id']);
        }
        $left = max(30, strtotime((string) $row['expires_at']) - time());
        return ['request' => ['id' => (int) $current['id'], 'nonce' => (string) $current['nonce']], 'qr' => $this->kiosk->erpLoginQr($device, (int) $current['id'], $base, $left)];
    }

    /** Navigateur qui attend : compte à ouvrir quand le téléphone a validé (une seule fois), sinon null. */
    public function poll(?array $current): ?array
    {
        if (!$current) return null;
        $row = $this->repo->request((int) $current['id']);
        if (!$row || !hash_equals($row['nonce_hash'], hash('sha256', (string) $current['nonce'])) || $row['approved_at'] === null || $row['id_user'] === null) return null;
        if (!$this->repo->consume((int) $row['id'])) return null;
        return $this->repo->userOfEmployee((int) $row['id_employee']);
    }

    /** Téléphone : ce que la validation ouvrira (compte, session, entrée pointée ou non). */
    public function preview(int $requestId, int $employeeId): array
    {
        $row = $this->pending($requestId);
        $user = $this->repo->userOfEmployee($employeeId) ?? throw new ApiException('ERP_LOGIN_NO_ACCOUNT', 409);
        $open = $this->repo->openClock($employeeId);
        return ['account' => trim(($user['displayed_name'] ?? '') ?: trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''))), 'session' => $this->sessionName((int) $user['id']),
            'clocked_in' => $open !== null, 'since' => $open ? substr((string) $open['clock_in'], 11, 5) : null, 'browser' => self::browser($row['user_agent'] ?? null)];
    }

    /** Téléphone : validation (l'entrée doit être pointée). */
    public function approve(int $requestId, int $employeeId): array
    {
        $this->pending($requestId);
        $user = $this->repo->userOfEmployee($employeeId) ?? throw new ApiException('ERP_LOGIN_NO_ACCOUNT', 409);
        if ($this->repo->openClock($employeeId) === null) throw new ApiException('ERP_LOGIN_CLOCK_FIRST', 409);
        if (!$this->repo->approve($requestId, $employeeId, (int) $user['id'])) throw new ApiException('QR_EXPIRED', 401);
        return ['session' => $this->sessionName((int) $user['id'])];
    }

    private function pending(int $requestId): array
    {
        $row = $this->repo->request($requestId);
        if (!$row || $row['approved_at'] !== null || strtotime((string) $row['expires_at']) <= time()) throw new ApiException('QR_EXPIRED', 401);
        return $row;
    }

    private function sessionName(int $userId): string
    {
        if ($this->access->isAdmin($userId)) return 'Administracja';
        if ($this->access->isCeo($userId)) return 'CEO';
        $p = $this->repo->positionOf($userId);
        return $p ? PageAccessService::areaName((string) $p['code'], (string) $p['name_pl']) : 'ERP';
    }

    /** « Chrome · Windows » : assez pour reconnaître son poste, rien de plus. */
    public static function browser(?string $ua): string
    {
        $ua = (string) $ua;
        $b = match (true) { str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => '' };
        $o = match (true) { str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Linux') => 'Linux', default => '' };
        return trim($b . ($b && $o ? ' · ' : '') . $o);
    }
}
