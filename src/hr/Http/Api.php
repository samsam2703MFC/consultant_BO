<?php

declare(strict_types=1);

namespace Hr\Http;

use Hr\App;
use Hr\Auth\ConsoleUnavailableException;
use Hr\Auth\Identity;
use Hr\Auth\IdentityResolver;
use Hr\Dimona\Certificate;
use Hr\Dimona\Generated\Spec;
use Hr\Dimona\JointCommission;
use Hr\Dimona\Mask;
use Hr\Dimona\PayloadBuilder;
use Hr\Dimona\ServiceException;
use Hr\Dimona\StatusMapper;
use Hr\Dimona\WorkerTypes;
use Hr\Repository\AuditLog;
use Hr\Repository\CredentialRepository;
use Hr\Repository\DeclarationRepository;
use Hr\Repository\EmployerRepository;
use Hr\Repository\PeriodRepository;
use Hr\Repository\WorkerRepository;
use Hr\Support\Schema;

/**
 * L'API REST du module RH : /api/v1/erp/dimona/* (standard /api/v1/{app}/{domaine}/{ressource}).
 *
 * Toute route sauf /health et /session demande une identité de la console franchisé. Les écritures
 * demandent en plus l'en-tête X-HR-Request (que seule la page du module envoie : un formulaire d'un autre
 * site ne peut pas le poser) et, si le navigateur l'indique, une origine identique. Le franchisé n'atteint
 * que les sociétés de ses boutiques ; le franchiseur lit tout, n'écrit rien. Chaque écriture va au journal.
 */
final class Api
{
    /** @var list<array{0: string, 1: string, 2: string, 3: bool}> méthode, motif, gestionnaire, public */
    private const ROUTES = [
        ['GET', '/health', 'health', true],
        ['GET', '/session', 'session', true],
        ['GET', '/employers', 'employers', false],
        ['POST', '/employers', 'createEmployer', false],
        ['PATCH', '/employers/{id}', 'updateEmployer', false],
        ['GET', '/credentials', 'credentials', false],
        ['POST', '/credentials', 'createCredential', false],
        ['POST', '/credentials/{id}/test', 'testCredential', false],
        ['GET', '/workers', 'workers', false],
        ['POST', '/workers', 'saveWorker', false],
        ['GET', '/workers/{id}', 'worker', false],
        ['POST', '/workers/{id}/ssin', 'revealSsin', false],
        ['GET', '/prefill', 'prefill', false],
        ['GET', '/declarations', 'declarations', false],
        ['POST', '/declarations', 'createDeclaration', false],
        ['GET', '/declarations/{id}', 'declaration', false],
        ['POST', '/declarations/{id}/refresh', 'refreshDeclaration', false],
        ['GET', '/periods', 'periods', false],
        ['POST', '/periods/sync', 'syncPeriods', false],
        ['GET', '/audit', 'audit', false],
    ];

    private ?Identity $who = null;

    public function __construct(private App $app, private IdentityResolver $identities)
    {
    }

    public function handle(Request $r): Response
    {
        try {
            [$handler, $params, $public] = $this->match($r);
            if ($handler === null) {
                return Response::error('Route inconnue : ' . $r->method . ' ' . $r->path, $params === ['method'] ? 405 : 404);
            }
            Schema::ensure();
            if ($r->method !== 'GET') {
                $csrf = $this->csrf($r);
                if ($csrf !== null) {
                    return $csrf;
                }
            }
            if (!$public) {
                $this->who = $this->identities->resolve($r);
                if ($this->who === null) {
                    return Response::error('Connectez-vous avec votre compte de la console franchisé.', 401, [], 'login_required');
                }
            }
            return $this->{$handler}($r, ...$params);
        } catch (ServiceException $e) {
            return Response::error($e->getMessage(), $e->httpStatus, $e->fields);
        } catch (ConsoleUnavailableException $e) {
            return Response::error($e->getMessage(), 503, [], 'console_unavailable');
        } catch (\Throwable $e) {
            // Le message d'une exception du module ne porte jamais de secret ; la trace, on ne la montre pas.
            error_log('[hr] ' . get_class($e) . ' : ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return Response::error('Erreur interne du module RH.', 500);
        }
    }

    /** @return array{0: ?string, 1: list<string>, 2: bool} */
    private function match(Request $r): array
    {
        $methodMismatch = false;
        foreach (self::ROUTES as [$m, $pattern, $handler, $public]) {
            $re = '#^' . preg_replace('#\{[a-z]+\}#', '(\d+)', $pattern) . '$#';
            if (preg_match($re, $r->path, $mm)) {
                if ($m !== $r->method) {
                    $methodMismatch = true;
                    continue;
                }
                return [$handler, array_slice($mm, 1), $public];
            }
        }
        return [null, $methodMismatch ? ['method'] : [], false];
    }

    private function csrf(Request $r): ?Response
    {
        if ($r->header('x-hr-request') !== '1') {
            return Response::error('Requête refusée (en-tête X-HR-Request manquant).', 403, [], 'csrf');
        }
        $origin = $r->header('origin');
        if ($origin !== '') {
            $oh = parse_url($origin, PHP_URL_HOST);
            $host = preg_replace('/:\d+$/', '', $r->header('host'));
            if ($oh === null || $oh === false || strcasecmp((string) $oh, (string) $host) !== 0) {
                return Response::error('Requête refusée (origine différente).', 403, [], 'csrf');
            }
        }
        return null;
    }

    private function me(): Identity
    {
        return $this->who ?? throw new ServiceException('Non connecté.', 401);
    }

    private function mustWrite(): Identity
    {
        $w = $this->me();
        if (!$w->canWrite()) {
            throw new ServiceException('Le franchiseur consulte en lecture seule.', 403);
        }
        return $w;
    }

    /** @return array<string, mixed> */
    private function employerOr404(int $id): array
    {
        $e = EmployerRepository::find($id);
        if ($e === null || !EmployerRepository::canAccess($this->me(), $id)) {
            throw new ServiceException('Société introuvable dans votre périmètre.', 404);
        }
        return $e;
    }

    /** @return array<string, mixed> */
    private function workerOr404(int $id): array
    {
        $w = WorkerRepository::find($id);
        if ($w === null || !EmployerRepository::canAccess($this->me(), (int) $w['employer_id'])) {
            throw new ServiceException('Personne introuvable dans votre périmètre.', 404);
        }
        return $w;
    }

    /* ---------------------------------------------------------------- public */

    private function health(Request $r): Response
    {
        return Response::json(['ok' => true, 'module' => 'hr-dimona', 'schema' => Schema::VERSION, 'openapi' => Spec::VERSION,
            'environments' => $this->app->config->enabledEnvs(), 'console' => $this->app->config->consoleApi() !== null]);
    }

    private function session(Request $r): Response
    {
        $cfg = $this->app->config;
        $out = [
            'identity' => null,
            'console' => ['api' => $cfg->consoleApi(), 'configured' => $cfg->consoleApi() !== null && $cfg->consoleApiInternal() !== null, 'available' => true, 'message' => null, 'bo' => null, 'csrf' => null],
            'environments' => ['enabled' => $cfg->enabledEnvs(), 'prodEnabled' => $cfg->prodEnabled()],
            'workerTypes' => WorkerTypes::forScreen(),
            'jointCommission' => ['explicit' => JointCommission::EXPLICIT, 'other' => JointCommission::OTHER],
            'certAlertDays' => (int) $cfg->get('certAlertDays'),
        ];
        try {
            $this->who = $this->identities->resolve($r);
        } catch (ConsoleUnavailableException $e) {
            $out['console']['available'] = false;
            $out['console']['message'] = $e->getMessage();
            return Response::json($out);
        }
        if ($this->who === null) {
            return Response::json($out);
        }
        $out['identity'] = $this->who->view();
        $out['console'] = array_merge($out['console'], $this->identities->lastSession());
        $shops = $this->app->directory->shops();
        $mine = $this->who->seesAll() ? array_keys($shops) : (array) $this->who->shopIds;
        $out['shops'] = array_map(static fn ($id): array => ['id' => (string) $id, 'name' => $shops[(string) $id] ?? ('Boutique ' . $id),
            'societeId' => EmployerRepository::shopOwner((string) $id)], array_values($mine));
        $out['employers'] = $this->employerViews();
        return Response::json($out);
    }

    /** @return list<array<string, mixed>> */
    private function employerViews(): array
    {
        $alert = (int) $this->app->config->get('certAlertDays');
        $out = [];
        foreach (EmployerRepository::visibleTo($this->me()) as $e) {
            $v = EmployerRepository::view($e);
            $v['credentials'] = array_map(static fn (array $c): array => CredentialRepository::view($c, $alert),
                array_values(array_filter(CredentialRepository::forEmployer((int) $e['id']), static fn (array $c): bool => (bool) $c['is_active'])));
            $v['connected'] = false;
            foreach ($v['credentials'] as $c) {
                if (in_array($c['env'], $this->app->config->enabledEnvs(), true) && ($c['lastTest']['ok'] ?? false)) {
                    $v['connected'] = true;
                }
            }
            $out[] = $v;
        }
        return $out;
    }

    /* ---------------------------------------------------------------- sociétés */

    private function employers(Request $r): Response
    {
        return Response::json(['items' => $this->employerViews()]);
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function employerInput(Request $r, ?int $id): array
    {
        $b = $r->body;
        $e = [];
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            $e['name'] = 'raison sociale obligatoire (160 caractères au plus)';
        }
        $bce = PayloadBuilder::enterprise((string) ($b['enterprise_number'] ?? ''));
        if (!preg_match('/^[01]\d{9}$/', $bce) || 97 - ((int) substr($bce, 0, 8) % 97) !== (int) substr($bce, 8, 2)) {
            $e['enterprise_number'] = 'numéro d’entreprise (BCE) invalide : 10 chiffres, ex. 0411.702.543';
        }
        $onss = preg_replace('/\D/', '', (string) ($b['onss_employer_id'] ?? '')) ?? '';
        if ($onss !== '' && ((int) $onss < 197 || (int) $onss > 5999999999)) {
            $e['onss_employer_id'] = 'numéro ONSS invalide (YAML : entre 197 et 5999999999)';
        }
        $cp = trim((string) ($b['joint_commission'] ?? ''));
        if ($cp !== '' && !JointCommission::isPlausible($cp)) {
            $e['joint_commission'] = 'commission paritaire attendue sous la forme 118, 119 ou 302.00';
        }
        $shops = array_values(array_unique(array_map('strval', (array) ($b['shops'] ?? []))));
        if ($shops === []) {
            $e['shops'] = 'choisissez au moins une boutique';
        }
        foreach ($shops as $s) {
            if (!$this->me()->hasShop($s)) {
                $e['shops'] = 'la boutique ' . $s . ' n’est pas dans votre périmètre';
            }
            $owner = EmployerRepository::shopOwner($s);
            if ($owner !== null && $owner !== $id) {
                $e['shops'] = 'la boutique ' . $s . ' est déjà rattachée à une autre société';
            }
        }
        $other = EmployerRepository::byEnterprise($bce);
        if ($other !== null && (int) $other['id'] !== $id) {
            $e['enterprise_number'] = 'cette société existe déjà dans le module';
        }
        if ($e !== []) {
            throw new ServiceException('La société n’est pas complète.', 422, $e);
        }
        return [['name' => $name, 'enterprise_number' => $bce, 'onss_employer_id' => $onss !== '' ? (int) $onss : null, 'joint_commission' => $cp !== '' ? $cp : null], $shops];
    }

    private function createEmployer(Request $r): Response
    {
        $who = $this->mustWrite();
        [$v, $shops] = $this->employerInput($r, null);
        $id = EmployerRepository::create($v, $shops, $who->userId);
        AuditLog::add($who, 'employer.create', $id, 'employer', (string) $id, 'BCE ' . $v['enterprise_number'] . ' · boutiques ' . implode(', ', $shops));
        return Response::json(EmployerRepository::view((array) EmployerRepository::find($id)), 201);
    }

    private function updateEmployer(Request $r, string $id): Response
    {
        $who = $this->mustWrite();
        $this->employerOr404((int) $id);
        [$v, $shops] = $this->employerInput($r, (int) $id);
        EmployerRepository::update((int) $id, $v);
        EmployerRepository::setShops((int) $id, $shops);
        AuditLog::add($who, 'employer.update', (int) $id, 'employer', $id, 'BCE ' . $v['enterprise_number'] . ' · boutiques ' . implode(', ', $shops));
        return Response::json(EmployerRepository::view((array) EmployerRepository::find((int) $id)));
    }

    /* ---------------------------------------------------------------- connexion ONSS */

    private function credentials(Request $r): Response
    {
        $sid = $r->int('societe_id') ?? throw new ServiceException('societe_id attendu.', 400);
        $this->employerOr404($sid);
        $alert = (int) $this->app->config->get('certAlertDays');
        return Response::json(['items' => array_map(static fn (array $c): array => CredentialRepository::view($c, $alert), CredentialRepository::forEmployer($sid))]);
    }

    private function createCredential(Request $r): Response
    {
        $who = $this->mustWrite();
        $b = $r->body;
        $sid = (int) ($b['societe_id'] ?? 0);
        $this->employerOr404($sid);
        $env = strtoupper((string) ($b['env'] ?? 'SIM'));
        $e = [];
        if (!in_array($env, $this->app->config->enabledEnvs(), true)) {
            $e['env'] = $env === 'PROD' ? 'la production n’est pas encore ouverte sur ce serveur (simulation seulement)' : 'environnement inconnu';
        }
        $client = trim((string) ($b['client_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.:\-]{3,128}$/', $client)) {
            $e['client_id'] = 'Client ID Chaman attendu (ex. self_service_expeditor_123456)';
        }
        if (trim((string) ($b['certificate'] ?? '')) === '') {
            $e['certificate'] = 'certificat (.crt ou .pem) à déposer';
        }
        if (trim((string) ($b['private_key'] ?? '')) === '') {
            $e['private_key'] = 'clé privée (.key ou .pem) à déposer';
        }
        if ($e !== []) {
            throw new ServiceException('La connexion n’est pas complète.', 422, $e);
        }
        try {
            $c = Certificate::inspect((string) $b['certificate'], (string) $b['private_key'], ($b['passphrase'] ?? '') !== '' ? (string) $b['passphrase'] : null);
        } catch (\InvalidArgumentException $x) {
            throw new ServiceException($x->getMessage(), 422, ['certificate' => $x->getMessage()]);
        }
        $id = CredentialRepository::create(['employer_id' => $sid, 'env' => $env, 'client_id' => $client, 'private_key_pem' => $c['privateKeyPem'],
            'certificate_pem' => $c['certificatePem'], 'subject' => $c['subject'], 'fingerprint' => $c['fingerprint'], 'expires_at' => $c['expiresAt']],
            $this->app->crypto, $who->userId);
        AuditLog::add($who, 'credential.create', $sid, 'credential', (string) $id, $env . ' · ' . $client . ' · certificat ' . substr($c['fingerprint'], 0, 16) . '… · expire ' . gmdate('d/m/Y', $c['expiresAt']));
        return Response::json(CredentialRepository::view((array) CredentialRepository::find($id), (int) $this->app->config->get('certAlertDays')), 201);
    }

    private function testCredential(Request $r, string $id): Response
    {
        return Response::json($this->app->dimona->testCredential($this->mustWrite(), (int) $id));
    }

    /* ---------------------------------------------------------------- personnel */

    /** L'équipe d'une société : le personnel de ses boutiques (panel) et les personnes saisies ici, avec leur état Dimona. */
    private function workers(Request $r): Response
    {
        $sid = $r->int('societe_id') ?? throw new ServiceException('societe_id attendu.', 400);
        $emp = $this->employerOr404($sid);
        $shopNames = $this->app->directory->shops();
        $local = WorkerRepository::forEmployer($sid);
        $byErp = [];
        foreach ($local as $w) {
            if ($w['erp_employee_id'] !== null) {
                $byErp[(int) $w['erp_employee_id']] = $w;
            }
        }
        $rows = [];
        $seen = [];
        foreach ($emp['shops'] as $shop) {
            foreach ($this->app->directory->employees($shop) as $e) {
                if (isset($seen[$e['id']])) {
                    continue;
                }
                $seen[$e['id']] = true;
                $rows[] = $this->teamRow($byErp[$e['id']] ?? null, $e + ['shopId' => $shop], $shopNames);
            }
        }
        foreach ($local as $w) {
            if ($w['erp_employee_id'] === null || !isset($seen[(int) $w['erp_employee_id']])) {
                $rows[] = $this->teamRow($w, null, $shopNames);
            }
        }
        $rang = ['problem' => 0, 'pending' => 1, 'declared' => 2, 'closed' => 3, 'canceled' => 3, 'none' => 4];
        usort($rows, static fn (array $a, array $b): int => [$rang[$a['dimona']], $a['name']] <=> [$rang[$b['dimona']], $b['name']]);
        return Response::json(['societeId' => $sid, 'items' => $rows]);
    }

    /**
     * @param array<string, mixed>|null $w
     * @param array<string, mixed>|null $erp
     * @param array<string, string> $shopNames
     * @return array<string, mixed>
     */
    private function teamRow(?array $w, ?array $erp, array $shopNames): array
    {
        $period = $w !== null ? PeriodRepository::active((int) $w['id']) : null;
        $last = $w !== null ? DeclarationRepository::lastForWorker((int) $w['id']) : null;
        $shop = $erp['shopId'] ?? ($w['shop_id'] ?? null);
        $shown = $period ?? ($w !== null ? (PeriodRepository::forWorker((int) $w['id'])[0] ?? null) : null);
        $state = 'none';
        if ($period !== null) {
            $state = 'declared';
        } elseif ($shown !== null) {
            $state = $shown['status'] === PeriodRepository::CANCELED ? 'canceled' : 'closed';
        }
        if ($last !== null && in_array($last['status'], [StatusMapper::PENDING, StatusMapper::WAITING_SIGEDIS], true)) {
            $state = 'pending';
        } elseif ($last !== null && $period === null && in_array($last['status'], [StatusMapper::REFUSED, StatusMapper::ERROR], true)) {
            $state = 'problem';
        }
        return [
            'key' => $w !== null ? 'w' . $w['id'] : 'e' . $erp['id'],
            'workerId' => $w !== null ? (int) $w['id'] : null,
            'employeeId' => $erp['id'] ?? ($w !== null && $w['erp_employee_id'] !== null ? (int) $w['erp_employee_id'] : null),
            'name' => $w !== null ? trim($w['given_name'] . ' ' . $w['family_name']) : (string) $erp['name'],
            'shopId' => $shop,
            'shopName' => $shop !== null ? ($shopNames[(string) $shop] ?? 'Boutique ' . $shop) : null,
            'active' => $erp['active'] ?? true,
            'inPanel' => $erp !== null,
            'hasSsin' => $w !== null && !empty($w['ssin_encrypted']),
            'workerType' => $period['worker_type'] ?? ($w['worker_type'] ?? null),
            'dimona' => $state,
            'period' => $shown !== null ? PeriodRepository::view($shown) : null,
            'lastDeclaration' => $last !== null ? ['id' => (int) $last['id'], 'type' => $last['type'], 'status' => $last['status'], 'at' => \Hr\Support\Clock::iso($last['created_at'])] : null,
        ];
    }

    private function prefill(Request $r): Response
    {
        $sid = $r->int('societe_id') ?? throw new ServiceException('societe_id attendu.', 400);
        $emp = $this->employerOr404($sid);
        $eid = $r->int('employee_id') ?? throw new ServiceException('employee_id attendu.', 400);
        $e = $this->erpEmployee($emp, $eid);
        $p = $this->app->directory->profile($eid);
        $existing = WorkerRepository::byErp($sid, $eid);
        return Response::json([
            'employeeId' => $eid,
            'shopId' => $e['shopId'],
            'givenName' => $p['givenName'] ?? $e['givenName'],
            'familyName' => $p['familyName'] ?? $e['familyName'],
            'birthDate' => $p['birthDate'] ?? null,
            // le NISS du profil n'est jamais renvoyé : seulement s'il existe et s'il est valide
            'profileSsin' => !empty($p['ssin']) ? (Mask::ssinValid((string) $p['ssin']) ? 'valid' : 'invalid') : 'none',
            'profileStatus' => $p['status'] ?? null,
            'worker' => $existing !== null ? WorkerRepository::view($existing) : null,
        ]);
    }

    /** @param array<string, mixed> $employer @return array<string, mixed> */
    private function erpEmployee(array $employer, int $employeeId): array
    {
        foreach ($employer['shops'] as $shop) {
            foreach ($this->app->directory->employees($shop) as $e) {
                if ($e['id'] === $employeeId) {
                    return $e + ['shopId' => $shop];
                }
            }
        }
        throw new ServiceException('Cet employé n’appartient pas aux boutiques de cette société.', 404);
    }

    private function saveWorker(Request $r): Response
    {
        $who = $this->mustWrite();
        $b = $r->body;
        $sid = (int) ($b['societe_id'] ?? 0);
        $emp = $this->employerOr404($sid);
        $id = !empty($b['id']) ? (int) $b['id'] : null;
        $cur = $id !== null ? $this->workerOr404($id) : null;
        if ($cur !== null && (int) $cur['employer_id'] !== $sid) {
            throw new ServiceException('Cette personne appartient à une autre société.', 409);
        }
        $e = [];
        $v = [];
        $erpId = !empty($b['erp_employee_id']) ? (int) $b['erp_employee_id'] : null;
        $erp = null;
        if ($erpId !== null) {
            $erp = $this->erpEmployee($emp, $erpId);
            $dup = WorkerRepository::byErp($sid, $erpId);
            if ($dup !== null && (int) $dup['id'] !== $id) {
                $id = (int) $dup['id'];
                $cur = $dup;
            }
            $v['erp_employee_id'] = $erpId;
            $v['shop_id'] = $erp['shopId'];
        } elseif (!empty($b['shop_id'])) {
            if (!in_array((string) $b['shop_id'], $emp['shops'], true)) {
                $e['shop_id'] = 'boutique hors de cette société';
            }
            $v['shop_id'] = (string) $b['shop_id'];
        }
        // YAML, WorkerDeclaration : givenName 48 caractères au plus, familyName 128. À défaut de saisie : la
        // fiche existante, puis le profil de l'employé, puis le panel.
        $profile = $erpId !== null ? $this->app->directory->profile($erpId) : null;
        $pick = static function (string ...$c): string {
            foreach ($c as $v) {
                if (trim($v) !== '') {
                    return trim($v);
                }
            }
            return '';
        };
        $given = $pick((string) ($b['given_name'] ?? ''), (string) ($cur['given_name'] ?? ''), (string) ($profile['givenName'] ?? ''), (string) ($erp['givenName'] ?? ''));
        $family = $pick((string) ($b['family_name'] ?? ''), (string) ($cur['family_name'] ?? ''), (string) ($profile['familyName'] ?? ''), (string) ($erp['familyName'] ?? ''));
        if (empty($b['birth_date']) && empty($cur['birth_date']) && !empty($profile['birthDate'])) {
            $b['birth_date'] = $profile['birthDate'];
        }
        if ($given === '' || mb_strlen($given) > 48) {
            $e['given_name'] = 'prénom obligatoire (48 caractères au plus)';
        }
        if ($family === '' || mb_strlen($family) > 128) {
            $e['family_name'] = 'nom obligatoire (128 caractères au plus)';
        }
        $v['given_name'] = $given;
        $v['family_name'] = $family;
        $ssin = preg_replace('/\D/', '', (string) ($b['ssin'] ?? '')) ?? '';
        if ($ssin === '' && !empty($b['use_profile_ssin']) && $erpId !== null) {
            $ssin = preg_replace('/\D/', '', (string) ($this->app->directory->profile($erpId)['ssin'] ?? '')) ?? '';
            if ($ssin === '') {
                $e['ssin'] = 'le profil de l’employé ne porte pas de numéro national';
            }
        }
        if ($ssin !== '') {
            if (!Mask::ssinValid($ssin)) {
                $e['ssin'] = 'numéro national (NISS) invalide : 11 chiffres et leur contrôle';
            } else {
                $dup = WorkerRepository::bySsin($sid, $ssin, $this->app->crypto);
                if ($dup !== null && (int) $dup['id'] !== $id) {
                    $e['ssin'] = 'ce NISS est déjà celui de ' . trim($dup['given_name'] . ' ' . $dup['family_name']) . ' dans cette société';
                }
                $v['ssin'] = $ssin;
            }
        } elseif ($cur === null || empty($cur['ssin_encrypted'])) {
            $e['ssin'] = 'numéro national (NISS) obligatoire pour une Dimona';
        }
        if (!empty($b['birth_date'])) {
            $d = (string) $b['birth_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
                $e['birth_date'] = 'date illisible';
            }
            $v['birth_date'] = $d;
        }
        if (!empty($b['worker_type'])) {
            if (!WorkerTypes::exists((string) $b['worker_type'])) {
                $e['worker_type'] = 'type de travailleur inconnu';
            }
            $v['worker_type'] = strtoupper((string) $b['worker_type']);
        }
        if (array_key_exists('joint_commission', $b)) {
            $cp = trim((string) $b['joint_commission']);
            if ($cp !== '' && !JointCommission::isPlausible($cp)) {
                $e['joint_commission'] = 'commission paritaire attendue sous la forme 118, 119 ou 302.00';
            }
            $v['joint_commission'] = $cp;
        }
        if (array_key_exists('planned_hours', $b)) {
            $h = $b['planned_hours'];
            if ($h !== null && $h !== '' && (!is_numeric($h) || (int) $h < 1 || (int) $h > 999)) {
                $e['planned_hours'] = 'entre 1 et 999 heures';
            }
            $v['planned_hours'] = $h === '' || $h === null ? null : (int) $h;
        }
        if ($e !== []) {
            throw new ServiceException('La fiche n’est pas complète.', 422, $e);
        }
        $wid = WorkerRepository::save($id, $sid, $v, $this->app->crypto, $who->userId);
        AuditLog::add($who, $id === null ? 'worker.create' : 'worker.update', $sid, 'worker', (string) $wid,
            ($erpId !== null ? 'employé panel ' . $erpId : 'personne hors panel') . (isset($v['ssin']) ? ' · NISS enregistré' : ''));
        return Response::json(WorkerRepository::view((array) WorkerRepository::find($wid)), $id === null ? 201 : 200);
    }

    private function worker(Request $r, string $id): Response
    {
        $w = $this->workerOr404((int) $id);
        $v = WorkerRepository::view($w);
        $v['periods'] = array_map([PeriodRepository::class, 'view'], PeriodRepository::forWorker((int) $id));
        $v['declarations'] = array_map([DeclarationRepository::class, 'view'], DeclarationRepository::search([(int) $w['employer_id']], ['worker_id' => (int) $id], 100));
        $v['activePeriod'] = ($p = PeriodRepository::active((int) $id)) !== null ? PeriodRepository::view($p) : null;
        return Response::json($v);
    }

    /** Le NISS en clair, à la demande — écrit au journal (qui, quand, pour qui). */
    private function revealSsin(Request $r, string $id): Response
    {
        $w = $this->workerOr404((int) $id);
        $ssin = WorkerRepository::ssin($w, $this->app->crypto);
        AuditLog::add($this->me(), 'worker.ssin_reveal', (int) $w['employer_id'], 'worker', $id, 'NISS affiché en clair');
        return Response::json(['ssin' => $ssin !== null ? Mask::ssinFormat($ssin) : null]);
    }

    /* ---------------------------------------------------------------- déclarations */

    /** @return list<int> */
    private function visibleEmployerIds(?int $sid): array
    {
        if ($sid !== null) {
            $this->employerOr404($sid);
            return [$sid];
        }
        return array_map(static fn (array $e): int => (int) $e['id'], EmployerRepository::visibleTo($this->me()));
    }

    private function declarations(Request $r): Response
    {
        $ids = $this->visibleEmployerIds($r->int('societe_id'));
        $items = DeclarationRepository::search($ids, ['employee_id' => $r->int('employee_id'), 'worker_id' => $r->int('worker_id'), 'status' => $r->q('status')]);
        $names = [];
        $out = [];
        foreach ($items as $d) {
            $v = DeclarationRepository::view($d);
            $wid = (int) $d['worker_id'];
            if (!isset($names[$wid])) {
                $w = WorkerRepository::find($wid);
                $names[$wid] = $w !== null ? trim($w['given_name'] . ' ' . $w['family_name']) : '—';
            }
            $v['workerName'] = $names[$wid];
            $out[] = $v;
        }
        return Response::json(['items' => $out]);
    }

    private function createDeclaration(Request $r): Response
    {
        $b = $r->body;
        if (empty($b['worker_id']) && !empty($b['employee_id']) && !empty($b['societe_id'])) {
            $w = WorkerRepository::byErp((int) $b['societe_id'], (int) $b['employee_id']);
            $b['worker_id'] = $w['id'] ?? null;
        }
        return Response::json($this->app->dimona->declare($this->mustWrite(), $b), 201);
    }

    private function declaration(Request $r, string $id): Response
    {
        $d = DeclarationRepository::find((int) $id);
        if ($d === null || !EmployerRepository::canAccess($this->me(), (int) $d['employer_id'])) {
            throw new ServiceException('Déclaration introuvable dans votre périmètre.', 404);
        }
        return Response::json(DeclarationRepository::view($d));
    }

    private function refreshDeclaration(Request $r, string $id): Response
    {
        $who = $this->me();
        $out = $this->app->dimona->refresh((int) $id, $who);
        AuditLog::add($who, 'declaration.refresh', (int) $out['societeId'], 'declaration', $id, 'statut ' . $out['status']);
        return Response::json($out);
    }

    /* ---------------------------------------------------------------- périodes, journal */

    private function periods(Request $r): Response
    {
        $wid = $r->int('worker_id');
        if ($wid === null && ($eid = $r->int('employee_id')) !== null) {
            foreach ($this->visibleEmployerIds($r->int('societe_id')) as $sid) {
                $w = WorkerRepository::byErp($sid, $eid);
                if ($w !== null) {
                    $wid = (int) $w['id'];
                    break;
                }
            }
            if ($wid === null) {
                return Response::json(['items' => []]);
            }
        }
        if ($wid === null) {
            throw new ServiceException('worker_id ou employee_id attendu.', 400);
        }
        $this->workerOr404($wid);
        return Response::json(['items' => array_map([PeriodRepository::class, 'view'], PeriodRepository::forWorker($wid))]);
    }

    private function syncPeriods(Request $r): Response
    {
        $wid = (int) ($r->body['worker_id'] ?? 0);
        return Response::json(['items' => $this->app->dimona->syncPeriods($this->mustWrite(), $wid)]);
    }

    private function audit(Request $r): Response
    {
        $sid = $r->int('societe_id') ?? throw new ServiceException('societe_id attendu.', 400);
        $this->employerOr404($sid);
        return Response::json(['items' => AuditLog::forEmployer($sid, 150)]);
    }
}
