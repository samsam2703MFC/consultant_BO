<?php

declare(strict_types=1);

namespace Hr\Dimona\Generated;

/**
 * Ce que le YAML OpenAPI Dimona v2 (docs/dimona/openapi_dimona_v2.yaml) dit des adresses : rien n'est écrit à la main ici.
 * Les variables d'environnement HR_DIMONA_* les surchargent (voir Hr\Support\Config).
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class Spec
{
    public const SOURCE = 'docs/dimona/openapi_dimona_v2.yaml';
    public const SHA256 = 'fce64f86dba3842fb4f842c303d0946897b8e19115d434e4d7383b09cb607ab3';
    public const VERSION = '2.0.0';
    /** Bloc `servers` du YAML : l'hôte « services-sim. » est la simulation (doc fonctionnelle v2, § 6). */
    public const SERVER_SIM = 'https://services-sim.socialsecurity.be/REST/dimona/v2';
    public const SERVER_PROD = 'https://services.socialsecurity.be/REST/dimona/v2';
    /** securitySchemes.Oauth.flows.clientCredentials.tokenUrl — la seule URL de jeton que le YAML donne. */
    public const TOKEN_URL = 'https://services.socialsecurity.be/REST/oauth/v5/token';
    /** operationId → [méthode, chemin]. */
    public const OPERATIONS = ['checkHealth' => ['GET', '/health'], 'submitDeclaration' => ['POST', '/declarations'], 'searchDeclarations' => ['POST', '/declarations/search'], 'getDeclarationByDeclarationId' => ['GET', '/declarations/{declarationId}'], 'getPeriodByPeriodId' => ['GET', '/periods/{periodId}'], 'searchPeriods' => ['POST', '/periods/search'], 'searchRelations' => ['POST', '/relations/search'], 'getDailyRegistrationByDailyRegistrationId' => ['GET', '/dailyRegistrations/{dailyRegistrationId}'], 'searchDailyRegistrations' => ['GET', '/dailyRegistrations']];
}
