<?php

declare(strict_types=1);

namespace Hr\Dimona;

use Hr\Auth\Identity;
use Hr\Dimona\Dto\DeclarationResponse;
use Hr\Dimona\Dto\DeclarationSearchRequest;
use Hr\Dimona\Dto\PeriodSearchRequest;
use Hr\Repository\AuditLog;
use Hr\Repository\CredentialRepository;
use Hr\Repository\DeclarationRepository;
use Hr\Repository\EmployerRepository;
use Hr\Repository\PeriodRepository;
use Hr\Repository\WorkerRepository;
use Hr\Support\Clock;
use Hr\Support\Config;
use Hr\Support\Crypto;
use Hr\Support\Database;
use Hr\Support\HttpTransportException;

/**
 * La logique métier des Dimona : construire la déclaration depuis la société et la personne, l'envoyer,
 * la garder, puis en relire le résultat (le POST ne rend pas le verdict) au rythme de Backoff, et tenir à
 * jour la période de la personne.
 */
final class DimonaService
{
    public function __construct(
        private Config $config,
        private Crypto $crypto,
        private DimonaClient $client,
        private DimonaAuthService $auth,
    ) {
    }

    /**
     * Déclare. `input` : type (IN|OUT|UPDATE|CANCEL), worker_id, et selon le type start_date, start_hour,
     * end_date, end_hour, worker_type, joint_commission, planned_hours, period_id, env.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> la déclaration (vue)
     */
    public function declare(Identity $who, array $input): array
    {
        $type = strtoupper((string) ($input['type'] ?? ''));
        $worker = WorkerRepository::find((int) ($input['worker_id'] ?? 0));
        if ($worker === null) {
            throw new ServiceException('Personne introuvable.', 404);
        }
        $employerId = (int) $worker['employer_id'];
        $employer = EmployerRepository::find($employerId);
        if ($employer === null || !EmployerRepository::canAccess($who, $employerId)) {
            throw new ServiceException('Cette personne n’appartient pas à vos sociétés.', 403);
        }
        if (!$who->canWrite()) {
            throw new ServiceException('Le franchiseur consulte en lecture seule.', 403);
        }

        $period = null;
        if ($type !== 'IN') {
            $period = !empty($input['period_id']) ? PeriodRepository::find((int) $input['period_id']) : PeriodRepository::active((int) $worker['id']);
            if ($period !== null && (int) $period['worker_id'] !== (int) $worker['id']) {
                throw new ServiceException('Cette période n’est pas celle de cette personne.', 403);
            }
        }
        if ($type === 'IN') {
            // la fiche porte le type et la commission paritaire par défaut
            $input['worker_type'] = $input['worker_type'] ?? $worker['worker_type'];
            $input['joint_commission'] = ($input['joint_commission'] ?? '') !== '' ? $input['joint_commission'] : ($worker['joint_commission'] ?? $employer['joint_commission']);
            $input['planned_hours'] = $input['planned_hours'] ?? $worker['planned_hours'];
        }
        $errors = PayloadBuilder::check($type, $input, $period);
        $ssin = WorkerRepository::ssin($worker, $this->crypto);
        if ($type === 'IN' && ($ssin === null || !Mask::ssinValid($ssin))) {
            $errors['ssin'] = 'NISS manquant ou invalide sur la fiche de la personne';
        }
        $credRow = CredentialRepository::forDeclaring($employerId, $this->config, isset($input['env']) ? strtoupper((string) $input['env']) : null);
        if ($credRow === null) {
            $errors['credential'] = 'aucune connexion ONSS active pour cette société (Paramètres › Connexion ONSS)';
        }
        if ($errors !== []) {
            throw new ServiceException('La déclaration n’est pas complète.', 422, $errors);
        }

        $ref = PayloadBuilder::reference();
        $req = match ($type) {
            'IN' => PayloadBuilder::in($employer, (string) $ssin, $input, $ref),
            'OUT' => PayloadBuilder::out((int) $period['dimona_period_id'], (string) $input['end_date'], $ref),
            'UPDATE' => PayloadBuilder::update((int) $period['dimona_period_id'], $input, $ref),
            'CANCEL' => PayloadBuilder::cancel((int) $period['dimona_period_id'], $ref),
        };
        $yaml = $req->validate();
        if ($yaml !== []) {
            $e = [];
            foreach ($yaml as $x) {
                $e[$x['path']] = $x['message'];
            }
            throw new ServiceException('La déclaration ne respecte pas le format ONSS (YAML Dimona v2).', 422, $e);
        }

        $full = (string) json_encode($req, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cred = CredentialRepository::toCredential($credRow, $this->crypto);
        $id = DeclarationRepository::create([
            'employer_id' => $employerId,
            'worker_id' => (int) $worker['id'],
            'erp_employee_id' => $worker['erp_employee_id'],
            'credential_id' => $cred->id,
            'env' => $cred->env,
            'type' => $type,
            'request_payload' => (string) json_encode(Mask::payload($req), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'request_encrypted' => $this->crypto->encrypt($full, 'request', 'employer:' . $employerId),
            'dimona_period_id' => $period !== null ? (int) $period['dimona_period_id'] : null,
            'status' => StatusMapper::PENDING,
            'submitted_by_user_id' => $who->userId,
            'submitted_by_name' => mb_substr($who->name, 0, 120),
        ]);
        AuditLog::add($who, 'declaration.create', $employerId, 'declaration', (string) $id, $type . ' · personne ' . $worker['id'] . ' · ' . $cred->env . ' · réf. ' . $ref);

        try {
            $dimonaId = $this->client->submitDeclaration($cred, $req);
            $now = Clock::now();
            DeclarationRepository::update($id, ['dimona_declaration_id' => $dimonaId, 'submitted_at' => Clock::sql($now),
                'next_poll_at' => Clock::sql($now + Backoff::delay(0)), 'http_status' => 201, 'last_error' => null]);
            AuditLog::add($who, 'declaration.submit', $employerId, 'declaration', (string) $id, 'reçue par l’ONSS, n° ' . $dimonaId);
        } catch (DimonaApiException $e) {
            DeclarationRepository::update($id, ['status' => StatusMapper::ERROR, 'http_status' => $e->httpStatus, 'last_error' => mb_substr($e->getMessage(), 0, 500),
                'anomalies' => (string) json_encode(array_map(static fn (array $i): array => ['errorId' => $i['name'] ?? null, 'fr' => $i['detail'] ?? ($i['title'] ?? ''), 'nl' => null, 'type' => $i['in'] ?? null], $e->issues), JSON_UNESCAPED_UNICODE),
                'resolved_at' => Clock::sql()]);
            AuditLog::add($who, 'declaration.rejected', $employerId, 'declaration', (string) $id, 'HTTP ' . $e->httpStatus);
        } catch (DimonaValidationException $e) {
            DeclarationRepository::update($id, ['status' => StatusMapper::ERROR, 'last_error' => mb_substr($e->getMessage(), 0, 500), 'resolved_at' => Clock::sql()]);
        } catch (DimonaAuthException | HttpTransportException $e) {
            // On ne sait pas si l'ONSS l'a reçue : la référence permet de la retrouver (refresh).
            DeclarationRepository::update($id, ['status' => StatusMapper::ERROR, 'last_error' => 'envoi interrompu : ' . mb_substr($e->getMessage(), 0, 400), 'submitted_at' => Clock::sql()]);
            AuditLog::add($who, 'declaration.send_failed', $employerId, 'declaration', (string) $id, mb_substr($e->getMessage(), 0, 200));
        }
        return DeclarationRepository::view((array) DeclarationRepository::find($id));
    }

    /**
     * Relit une déclaration à l'ONSS (bouton « Actualiser » ou cron). Une déclaration sans numéro ONSS (envoi
     * interrompu) est recherchée par sa référence.
     *
     * @return array<string, mixed>
     */
    public function refresh(int $id, Identity $who): array
    {
        $d = DeclarationRepository::find($id);
        if ($d === null) {
            throw new ServiceException('Déclaration introuvable.', 404);
        }
        if (!EmployerRepository::canAccess($who, (int) $d['employer_id'])) {
            throw new ServiceException('Hors de votre périmètre.', 403);
        }
        $credRow = $d['credential_id'] !== null ? CredentialRepository::find((int) $d['credential_id']) : null;
        if ($credRow === null) {
            throw new ServiceException('La connexion ONSS de cette déclaration n’existe plus.', 409);
        }
        $cred = CredentialRepository::toCredential($credRow, $this->crypto);
        $now = Clock::now();

        if ($d['dimona_declaration_id'] === null) {
            $found = $this->findByReference($cred, $d);
            if ($found === null) {
                DeclarationRepository::update($id, ['last_error' => 'introuvable à l’ONSS : la déclaration n’est pas partie (relancez-la)']);
                return DeclarationRepository::view((array) DeclarationRepository::find($id));
            }
            DeclarationRepository::update($id, ['dimona_declaration_id' => $found, 'status' => StatusMapper::PENDING, 'last_error' => null,
                'next_poll_at' => Clock::sql($now)]);
            AuditLog::add($who, 'declaration.recovered', (int) $d['employer_id'], 'declaration', (string) $id, 'retrouvée à l’ONSS, n° ' . $found);
            $d = (array) DeclarationRepository::find($id);
        }

        $attempts = (int) $d['attempts'] + 1;
        $submitted = Clock::ts($d['submitted_at']) ?? $now;
        try {
            $resp = $this->client->getDeclaration($cred, (int) $d['dimona_declaration_id']);
        } catch (DimonaApiException | DimonaAuthException | HttpTransportException $e) {
            $next = Backoff::next($attempts, $submitted, $now);
            DeclarationRepository::update($id, ['attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 500),
                'next_poll_at' => $next !== null ? Clock::sql($next) : null,
                'status' => $next === null && !StatusMapper::isFinal((string) $d['status']) ? StatusMapper::ERROR : $d['status']]);
            return DeclarationRepository::view((array) DeclarationRepository::find($id));
        }

        if ($resp === null) {
            // 404 : pas encore traitée (doc fonctionnelle v2, p. 5 et 24)
            $next = Backoff::next($attempts, $submitted, $now);
            if ($next === null) {
                DeclarationRepository::update($id, ['attempts' => $attempts, 'status' => StatusMapper::ERROR, 'next_poll_at' => null,
                    'resolved_at' => Clock::sql($now), 'last_error' => 'aucun résultat de l’ONSS en 24 h : vérifier sur le portail Dimona']);
                AuditLog::add($who, 'declaration.timeout', (int) $d['employer_id'], 'declaration', (string) $id, '24 h sans résultat');
            } else {
                DeclarationRepository::update($id, ['attempts' => $attempts, 'next_poll_at' => Clock::sql($next), 'last_error' => null]);
            }
            return DeclarationRepository::view((array) DeclarationRepository::find($id));
        }

        $this->applyResult($d, $resp, $attempts, $now, $who);
        return DeclarationRepository::view((array) DeclarationRepository::find($id));
    }

    /** @param array<string, mixed> $d */
    private function applyResult(array $d, DeclarationResponse $resp, int $attempts, int $now, Identity $who): void
    {
        $id = (int) $d['id'];
        $ds = $resp->declarationStatus;
        $code = $ds?->result;
        $status = StatusMapper::fromResult($code);
        $anomalies = [];
        foreach ($ds?->anomalies ?? [] as $a) {
            $anomalies[] = ['errorId' => $a->errorId, 'fr' => $a->label?->fr, 'nl' => $a->label?->nl, 'de' => $a->label?->de, 'type' => $a->type, 'value' => $a->value];
        }
        foreach ($ds?->informationsCollection ?? [] as $i) {
            $anomalies[] = ['errorId' => null, 'fr' => 'Information : ' . $i->type . ' = ' . $i->value, 'nl' => null, 'de' => null, 'type' => 'information', 'value' => $i->value];
        }
        $periodId = $ds?->period?->id;
        $upd = [
            'attempts' => $attempts,
            'result_code' => $code !== null ? mb_substr(strtoupper($code), 0, 1) : null,
            'result_payload' => (string) json_encode(Mask::payload($resp), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'anomalies' => (string) json_encode($anomalies, JSON_UNESCAPED_UNICODE),
            'last_error' => null,
            'http_status' => 200,
        ];
        if ($status === null) {
            $upd['status'] = StatusMapper::ERROR;
            $upd['last_error'] = 'résultat ONSS inconnu : « ' . (string) $code . ' »';
            $upd['next_poll_at'] = null;
            $upd['resolved_at'] = Clock::sql($now);
        } elseif ($status === StatusMapper::WAITING_SIGEDIS) {
            $upd['status'] = $status;
            $next = Backoff::next($attempts, Clock::ts($d['submitted_at']) ?? $now, $now);
            $upd['next_poll_at'] = $next !== null ? Clock::sql($next) : null;
        } else {
            $upd['status'] = $status;
            $upd['next_poll_at'] = null;
            $upd['resolved_at'] = Clock::sql($now);
        }
        if ($periodId !== null) {
            $upd['dimona_period_id'] = (int) $periodId;
        }
        Database::transaction(function () use ($id, $upd, $d, $status, $periodId): void {
            DeclarationRepository::update($id, $upd);
            if ($status !== null && StatusMapper::createsPeriod($status)) {
                $this->applyToPeriod($d, $periodId !== null ? (int) $periodId : ($d['dimona_period_id'] !== null ? (int) $d['dimona_period_id'] : null));
            }
        });
        if ($d['status'] !== $upd['status']) {
            AuditLog::add($who, 'declaration.result', (int) $d['employer_id'], 'declaration', (string) $id, ($code ?? '?') . ' → ' . $upd['status'] . ' · ' . count($anomalies) . ' anomalie(s)');
        }
    }

    /** La période après une déclaration acceptée : créée (IN), close (OUT), modifiée (UPDATE), annulée (CANCEL). */
    private function applyToPeriod(array $d, ?int $dimonaPeriodId): void
    {
        if ($dimonaPeriodId === null) {
            return;
        }
        $req = json_decode((string) $this->crypto->decrypt((string) $d['request_encrypted'], 'request', 'employer:' . $d['employer_id']), true);
        $req = is_array($req) ? $req : [];
        $type = (string) $d['type'];
        $cur = PeriodRepository::byDimonaId($dimonaPeriodId);
        if ($type === 'IN') {
            $in = (array) ($req['dimonaIn'] ?? []);
            PeriodRepository::upsert([
                'employer_id' => (int) $d['employer_id'], 'worker_id' => (int) $d['worker_id'], 'dimona_period_id' => $dimonaPeriodId,
                'start_date' => (string) ($in['startDate'] ?? ''), 'start_hour' => $in['startHour'] ?? null,
                'end_date' => $in['endDate'] ?? null, 'end_hour' => $in['endHour'] ?? null,
                'worker_type' => $in['features']['workerType'] ?? null, 'joint_commission' => $in['features']['jointCommissionNumber'] ?? null,
                'planned_hours' => $in['plannedHoursNumber'] ?? null, 'status' => PeriodRepository::ACTIVE, 'source' => 'DECLARED',
                'last_declaration_id' => (int) $d['id'],
            ]);
            return;
        }
        if ($cur === null) {
            return;
        }
        if ($type === 'OUT') {
            PeriodRepository::update((int) $cur['id'], ['end_date' => $req['dimonaOut']['endDate'] ?? $cur['end_date'], 'status' => PeriodRepository::CLOSED, 'last_declaration_id' => (int) $d['id']]);
        } elseif ($type === 'UPDATE') {
            $u = (array) ($req['dimonaUpdate'] ?? []);
            $map = ['startDate' => 'start_date', 'startHour' => 'start_hour', 'endDate' => 'end_date', 'endHour' => 'end_hour', 'plannedHoursNumber' => 'planned_hours'];
            $v = ['last_declaration_id' => (int) $d['id']];
            foreach ($map as $from => $to) {
                if (array_key_exists($from, $u)) {
                    $v[$to] = $u[$from];
                }
            }
            PeriodRepository::update((int) $cur['id'], $v);
        } elseif ($type === 'CANCEL') {
            PeriodRepository::update((int) $cur['id'], ['status' => PeriodRepository::CANCELED, 'last_declaration_id' => (int) $d['id']]);
        }
    }

    /** Une déclaration partie sans réponse (réseau coupé) : la retrouver par sa référence. */
    private function findByReference(Credential $cred, array $d): ?int
    {
        $req = json_decode((string) $this->crypto->decrypt((string) $d['request_encrypted'], 'request', 'employer:' . $d['employer_id']), true);
        $block = is_array($req) ? (array) ($req['dimonaIn'] ?? $req['dimonaOut'] ?? $req['dimonaUpdate'] ?? $req['dimonaCancel'] ?? []) : [];
        $ref = $block['references']['declaration'] ?? null;
        $employer = EmployerRepository::find((int) $d['employer_id']);
        if ($ref === null || $employer === null) {
            return null;
        }
        $res = $this->client->searchDeclarations($cred, DeclarationSearchRequest::fromArray([
            'employer' => ['enterpriseNumber' => PayloadBuilder::enterprise((string) $employer['enterprise_number'])],
            'references' => ['declaration' => $ref],
        ]));
        foreach ($res->items ?? [] as $it) {
            $n = $it->declarationStatus?->declarationId;
            if ($n !== null) {
                return (int) $n;
            }
        }
        return null;
    }

    /** Le cron : relit ce qui est dû. Rend le nombre de déclarations relues. */
    public function pollDue(int $limit = 25): int
    {
        $n = 0;
        foreach (DeclarationRepository::due(Clock::now(), $limit) as $d) {
            try {
                $this->refresh((int) $d['id'], Identity::system());
            } catch (\Throwable $e) {
                $attempts = (int) $d['attempts'] + 1;
                $next = Backoff::next($attempts, Clock::ts($d['submitted_at']) ?? Clock::now(), Clock::now());
                DeclarationRepository::update((int) $d['id'], ['attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'next_poll_at' => $next !== null ? Clock::sql($next) : null, 'status' => $next === null ? StatusMapper::ERROR : $d['status']]);
            }
            $n++;
        }
        return $n;
    }

    /**
     * Importe les périodes de la personne connues de l'ONSS (POST /periods/search, employeur + NISS) : une
     * personne déclarée hier sur le portail peut ainsi sortir aujourd'hui depuis le module.
     *
     * @return list<array<string, mixed>>
     */
    public function syncPeriods(Identity $who, int $workerId): array
    {
        $worker = WorkerRepository::find($workerId);
        if ($worker === null) {
            throw new ServiceException('Personne introuvable.', 404);
        }
        $employerId = (int) $worker['employer_id'];
        if (!EmployerRepository::canAccess($who, $employerId)) {
            throw new ServiceException('Hors de votre périmètre.', 403);
        }
        $ssin = WorkerRepository::ssin($worker, $this->crypto);
        if ($ssin === null) {
            throw new ServiceException('Le NISS de la personne est nécessaire pour retrouver ses périodes.', 422, ['ssin' => 'NISS manquant']);
        }
        $credRow = CredentialRepository::forDeclaring($employerId, $this->config);
        if ($credRow === null) {
            throw new ServiceException('Aucune connexion ONSS active pour cette société.', 422, ['credential' => 'à configurer']);
        }
        $cred = CredentialRepository::toCredential($credRow, $this->crypto);
        $employer = (array) EmployerRepository::find($employerId);
        try {
            $res = $this->client->searchPeriods($cred, PeriodSearchRequest::fromArray([
                'employer' => ['enterpriseNumber' => PayloadBuilder::enterprise((string) $employer['enterprise_number'])],
                'worker' => ['ssin' => $ssin],
            ]));
        } catch (DimonaApiException | DimonaAuthException | HttpTransportException $e) {
            throw new ServiceException('L’ONSS n’a pas rendu les périodes : ' . $e->getMessage(), 502);
        }
        $today = gmdate('Y-m-d', Clock::now());
        $n = 0;
        foreach ($res->items ?? [] as $p) {
            if ($p->periodId === null || $p->startDate === null) {
                continue;
            }
            $status = ($p->isCanceled || $p->isCanceledNsso) ? PeriodRepository::CANCELED
                : (($p->endDate !== null && $p->endDate < $today) ? PeriodRepository::CLOSED : PeriodRepository::ACTIVE);
            PeriodRepository::upsert([
                'employer_id' => $employerId, 'worker_id' => $workerId, 'dimona_period_id' => (int) $p->periodId,
                'start_date' => $p->startDate, 'start_hour' => $p->startHour, 'end_date' => $p->endDate, 'end_hour' => $p->endHour,
                'worker_type' => $p->dimonaFeatures?->workerType, 'joint_commission' => $p->dimonaFeatures?->jointCommissionNumber,
                'planned_hours' => $p->plannedHoursNumber, 'status' => $status, 'source' => 'SYNCED',
            ]);
            $n++;
        }
        AuditLog::add($who, 'periods.sync', $employerId, 'worker', (string) $workerId, $n . ' période(s) importée(s) de l’ONSS');
        return array_map([PeriodRepository::class, 'view'], PeriodRepository::forWorker($workerId));
    }

    /**
     * « Tester la connexion » : demande un jeton neuf au serveur OAuth. Ne déclare rien.
     *
     * @return array{ok: bool, message: string, expiresAt: ?string}
     */
    public function testCredential(Identity $who, int $credentialId): array
    {
        $row = CredentialRepository::find($credentialId);
        if ($row === null) {
            throw new ServiceException('Connexion introuvable.', 404);
        }
        if (!EmployerRepository::canAccess($who, (int) $row['employer_id'])) {
            throw new ServiceException('Hors de votre périmètre.', 403);
        }
        if (!in_array($row['env'], $this->config->enabledEnvs(), true)) {
            throw new ServiceException('L’environnement ' . $row['env'] . ' n’est pas ouvert sur ce serveur.', 409);
        }
        $cred = CredentialRepository::toCredential($row, $this->crypto);
        try {
            $this->auth->token($cred, true);
            $hit = $this->auth->cached($cred);
            $msg = 'Connexion réussie : l’ONSS a délivré un jeton d’accès (' . $cred->env . ').';
            CredentialRepository::saveTest($credentialId, true, $msg);
            AuditLog::add($who, 'credential.test', (int) $row['employer_id'], 'credential', (string) $credentialId, 'jeton obtenu');
            return ['ok' => true, 'message' => $msg, 'expiresAt' => $hit !== null ? gmdate('Y-m-d\TH:i:s\Z', $hit['expires_at']) : null];
        } catch (DimonaAuthException $e) {
            $msg = 'Connexion refusée : ' . $e->getMessage();
            CredentialRepository::saveTest($credentialId, false, $msg);
            AuditLog::add($who, 'credential.test', (int) $row['employer_id'], 'credential', (string) $credentialId, 'échec : ' . mb_substr($e->getMessage(), 0, 200));
            return ['ok' => false, 'message' => $msg, 'expiresAt' => null];
        }
    }
}
