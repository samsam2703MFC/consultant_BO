<?php

declare(strict_types=1);

namespace Hr\Dimona;

use Hr\Dimona\Dto\AbstractDto;
use Hr\Dimona\Dto\DeclarationRequest;
use Hr\Dimona\Dto\DeclarationResponse;
use Hr\Dimona\Dto\DeclarationSearchRequest;
use Hr\Dimona\Dto\Period;
use Hr\Dimona\Dto\PeriodSearchRequest;
use Hr\Dimona\Dto\SearchDeclarations200Response;
use Hr\Dimona\Dto\SearchPeriods200Response;
use Hr\Dimona\Generated\Spec;
use Hr\Support\Config;
use Hr\Support\HttpClient;
use Hr\Support\HttpResponse;

/**
 * Le client typé de l'API REST Dimona v2 : les chemins viennent de Spec::OPERATIONS (le YAML), l'URL de base
 * de la configuration (environnement SIM ou PROD du compte). Un 401 vaut un jeton périmé : on en reprend un,
 * une fois.
 *
 * Doc fonctionnelle v2 (§ 3, p. 5 ; § 7.1 et 7.2, p. 20 à 24) : le POST rend 201 et le numéro de déclaration
 * dans l'en-tête Location ; tant que l'ONSS n'a pas traité la déclaration, le GET rend 404.
 */
final class DimonaClient
{
    public function __construct(private HttpClient $http, private DimonaAuthService $auth, private Config $config)
    {
    }

    /** POST /declarations → le numéro de déclaration ONSS (en-tête Location). */
    public function submitDeclaration(Credential $c, DeclarationRequest $req): int
    {
        $errors = $req->validate();
        if ($errors !== []) {
            throw new DimonaValidationException($errors);
        }
        $r = $this->call($c, 'submitDeclaration', [], $req);
        if ($r->status === 201) {
            $loc = (string) $r->header('location');
            if (preg_match('~/declarations/(\d+)\s*$~', $loc, $m) || preg_match('~(\d+)\s*$~', $loc, $m)) {
                return (int) $m[1];
            }
            throw new DimonaApiException('déclaration reçue, mais sans numéro (en-tête Location absent)', 201);
        }
        throw DimonaApiException::fromResponse($r, 'déclaration refusée à l’envoi');
    }

    /** GET /declarations/{id} → la déclaration traitée, ou null tant que l'ONSS ne l'a pas traitée (404). */
    public function getDeclaration(Credential $c, int $declarationId): ?DeclarationResponse
    {
        $r = $this->call($c, 'getDeclarationByDeclarationId', ['declarationId' => $declarationId]);
        if ($r->status === 404) {
            return null;
        }
        if ($r->status !== 200) {
            throw DimonaApiException::fromResponse($r, 'lecture de la déclaration');
        }
        return DeclarationResponse::fromArray((array) $r->json());
    }

    public function searchDeclarations(Credential $c, DeclarationSearchRequest $req, int $page = 1, int $pageSize = 50): SearchDeclarations200Response
    {
        $r = $this->call($c, 'searchDeclarations', [], $req, ['page' => $page, 'pageSize' => $pageSize]);
        if ($r->status !== 200) {
            throw DimonaApiException::fromResponse($r, 'recherche de déclarations');
        }
        return SearchDeclarations200Response::fromArray((array) $r->json());
    }

    public function getPeriod(Credential $c, int $periodId): ?Period
    {
        $r = $this->call($c, 'getPeriodByPeriodId', ['periodId' => $periodId]);
        if ($r->status === 404) {
            return null;
        }
        if ($r->status !== 200) {
            throw DimonaApiException::fromResponse($r, 'lecture de la période');
        }
        return Period::fromArray((array) $r->json());
    }

    public function searchPeriods(Credential $c, PeriodSearchRequest $req, int $page = 1, int $pageSize = 50): SearchPeriods200Response
    {
        $r = $this->call($c, 'searchPeriods', [], $req, ['page' => $page, 'pageSize' => $pageSize]);
        if ($r->status !== 200) {
            throw DimonaApiException::fromResponse($r, 'recherche de périodes');
        }
        return SearchPeriods200Response::fromArray((array) $r->json());
    }

    /**
     * @param array<string, int|string> $pathParams
     * @param array<string, int|string> $query
     */
    private function call(Credential $c, string $operationId, array $pathParams = [], ?AbstractDto $body = null, array $query = []): HttpResponse
    {
        [$method, $path] = Spec::OPERATIONS[$operationId];
        foreach ($pathParams as $k => $v) {
            $path = str_replace('{' . $k . '}', rawurlencode((string) $v), $path);
        }
        $base = $this->config->baseUrl($c->env);
        if ($base === '') {
            throw new DimonaApiException('URL de l’API Dimona non configurée pour ' . $c->env, 0);
        }
        $url = $base . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $json = $body !== null ? (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $headers = ['Authorization' => 'Bearer ' . $this->auth->token($c, $attempt > 0), 'Accept' => 'application/json, application/problem+json'];
            if ($json !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            $r = $this->http->request($method, $url, $headers, $json, (int) $this->config->get('httpTimeout'));
            if ($r->status === 401 && $attempt === 0) {
                $this->auth->forget($c);
                continue;
            }
            return $r;
        }
        return $r;
    }
}
