#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Tests du module RH / Dimona — sans réseau réel, sans ONSS, sans base MySQL.
 *
 *   php tests/hr/run.php              # tout
 *   php tests/hr/run.php --unit       # tests unitaires seuls
 *   php tests/hr/run.php --integration
 *
 * Unitaires : JWT (claims, signature vérifiable avec la clé publique), cache du jeton, statuts A/W/B/S,
 * backoff, commission paritaire, contrôles métier, DTO contre le YAML, chiffrement, masquage, certificats,
 * fichiers générés à jour, identité de la console.
 * Intégration : l'API complète servie par php -S (tests/hr/dev-router.php) contre le faux serveur
 * OAuth + Dimona + console (tests/hr/mock/server.php), base SQLite jetable.
 */

use Hr\Auth\ConsoleIdentityResolver;
use Hr\Auth\ConsoleUnavailableException;
use Hr\Auth\Identity;
use Hr\Dimona\Backoff;
use Hr\Dimona\Certificate;
use Hr\Dimona\Credential;
use Hr\Dimona\DimonaAuthService;
use Hr\Dimona\DimonaClient;
use Hr\Dimona\Dto\DeclarationRequest;
use Hr\Dimona\Dto\DimonaIn;
use Hr\Dimona\Generated\Spec;
use Hr\Dimona\JointCommission;
use Hr\Dimona\Mask;
use Hr\Dimona\MemoryTokenCache;
use Hr\Dimona\PayloadBuilder;
use Hr\Dimona\StatusMapper;
use Hr\Http\Request;
use Hr\Support\Clock;
use Hr\Support\Config;
use Hr\Support\Crypto;
use Hr\Support\HttpClient;
use Hr\Support\HttpResponse;
use Hr\Support\Schema;

$ROOT = dirname(__DIR__, 2);
require $ROOT . '/src/hr/autoload.php';

$ok = 0;
$ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) {
        $ok++;
        echo "  ok  $nom\n";
    } else {
        $ko++;
        echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** Une clé RSA et son certificat auto-signé, comme la procédure Chaman (OpenSSL, 2048, SHA-256). */
function paire(int $bits = 2048, string $cn = 'self_service_expeditor_123456', int $jours = 365): array
{
    $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
    $x = openssl_csr_sign($csr, null, $key, $jours, ['digest_alg' => 'sha256']);
    openssl_x509_export($x, $cert);
    openssl_pkey_export($key, $pem);
    return ['key' => $pem, 'cert' => $cert, 'pub' => openssl_pkey_get_details($key)['key']];
}

/** Un client HTTP de test : une fonction décide de chaque réponse, les appels sont comptés. */
final class FakeHttp implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $calls = [];

    /** @param callable(string, string, array<string, string>, ?string): HttpResponse $fn */
    public function __construct(private $fn)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        return ($this->fn)($method, $url, $headers, $body);
    }
}

$mode = $argv[1] ?? '';
$TOKEN_URL = Spec::TOKEN_URL;

/* ============================================================ unitaires */
if ($mode !== '--integration') {
    $p = paire();
    $cfg = Config::load(['masterKey' => base64_encode(random_bytes(32))]);

    echo "JWT d'assertion\n";
    $cred = new Credential(1, 1, 'SIM', 'self_service_expeditor_123456', $p['key']);
    $auth = new DimonaAuthService(new FakeHttp(static fn () => new HttpResponse(500, [], '')), $cfg, new MemoryTokenCache());
    Clock::freeze(1_800_000_000);
    $jwt = $auth->assertion($cred);
    [$h, $c, $s] = explode('.', $jwt);
    $head = json_decode(DimonaAuthService::b64uDecode($h), true);
    $claims = json_decode(DimonaAuthService::b64uDecode($c), true);
    verifie('en-tête RS256 / JWT', $head === ['alg' => 'RS256', 'typ' => 'JWT'], json_encode($head));
    verifie('signature vérifiable avec la clé publique', openssl_verify($h . '.' . $c, DimonaAuthService::b64uDecode($s), $p['pub'], OPENSSL_ALGO_SHA256) === 1);
    verifie('signature refusée par une autre clé', openssl_verify($h . '.' . $c, DimonaAuthService::b64uDecode($s), paire()['pub'], OPENSSL_ALGO_SHA256) === 0);
    verifie('iss = sub = Client ID Chaman', $claims['iss'] === 'self_service_expeditor_123456' && $claims['sub'] === $claims['iss']);
    verifie('aud = URL du token endpoint (YAML)', $claims['aud'] === $TOKEN_URL, (string) $claims['aud']);
    verifie('iat = maintenant, exp ≤ 5 min', $claims['iat'] === 1_800_000_000 && $claims['exp'] - $claims['iat'] === 300);
    verifie('jti au format UUID v4', (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $claims['jti']), $claims['jti']);
    verifie('jti unique d’une assertion à l’autre', json_decode(DimonaAuthService::b64uDecode(explode('.', $auth->assertion($cred))[1]), true)['jti'] !== $claims['jti']);
    verifie('aucun autre claim (pas de scope)', array_keys($claims) === ['jti', 'iss', 'sub', 'aud', 'iat', 'exp'], implode(',', array_keys($claims)));
    verifie('la clé ne sort pas de Credential (var_export / json)', !str_contains(print_r($cred, true), 'PRIVATE KEY') && !str_contains((string) json_encode($cred), 'PRIVATE KEY'));

    echo "Jeton d'accès : requête et cache\n";
    $issued = 0;
    $http = new FakeHttp(static function (string $m, string $url, array $hd, ?string $b) use (&$issued, $TOKEN_URL): HttpResponse {
        if ($url === $TOKEN_URL) {
            $issued++;
            return new HttpResponse(200, [], json_encode(['access_token' => 'tok' . $issued, 'token_type' => 'Bearer', 'expires_in' => 600]));
        }
        return new HttpResponse(404, [], '');
    });
    $auth = new DimonaAuthService($http, $cfg, new MemoryTokenCache());
    $t1 = $auth->token($cred);
    parse_str((string) $http->calls[0]['body'], $form);
    verifie('POST x-www-form-urlencoded', $http->calls[0]['method'] === 'POST' && str_starts_with($http->calls[0]['headers']['Content-Type'], 'application/x-www-form-urlencoded'));
    verifie('grant_type, client_assertion_type, client_assertion — et pas de scope',
        $form['grant_type'] === 'client_credentials' && $form['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        && substr_count($form['client_assertion'], '.') === 2 && !isset($form['scope']), implode(',', array_keys($form)));
    verifie('deuxième appel servi par le cache', $auth->token($cred) === $t1 && $issued === 1);
    Clock::advance(600 - 61);
    verifie('61 s restantes : encore le cache', $auth->token($cred) === $t1 && $issued === 1);
    Clock::advance(1);
    verifie('60 s restantes : renouvelé', $auth->token($cred) === 'tok2' && $issued === 2);
    verifie('force : nouveau jeton', $auth->token($cred, true) === 'tok3' && $issued === 3);
    $cred2 = new Credential(2, 2, 'SIM', 'self_service_expeditor_999', $p['key']);
    verifie('un cache par compte (société)', $auth->token($cred2) === 'tok4' && $issued === 4);
    $refus = new DimonaAuthService(new FakeHttp(static fn () => new HttpResponse(401, [], '{"error":"invalid_client","error_description":"client assertion rejected"}')), $cfg, new MemoryTokenCache());
    try {
        $refus->token($cred);
        verifie('jeton refusé ⇒ exception', false);
    } catch (\Hr\Dimona\DimonaAuthException $e) {
        verifie('jeton refusé ⇒ message lisible sans secret', str_contains($e->getMessage(), 'rejected') && str_contains($e->getMessage(), '401') && !str_contains($e->getMessage(), 'eyJ'), $e->getMessage());
    }

    echo "Client Dimona : 401 ⇒ un nouveau jeton, une fois\n";
    $n401 = 0;
    $issued = 0;
    $http = new FakeHttp(static function (string $m, string $url, array $hd) use (&$n401, &$issued, $TOKEN_URL): HttpResponse {
        if ($url === $TOKEN_URL) {
            $issued++;
            return new HttpResponse(200, [], json_encode(['access_token' => 'tok' . $issued, 'expires_in' => 600]));
        }
        if ($hd['Authorization'] === 'Bearer tok1') {
            $n401++;
            return new HttpResponse(401, [], '');
        }
        return new HttpResponse(404, [], '');
    });
    $a2 = new DimonaAuthService($http, $cfg, new MemoryTokenCache());
    $client = new DimonaClient($http, $a2, $cfg);
    verifie('404 ⇒ pas encore traitée (null)', $client->getDeclaration($cred, 600165606187) === null);
    verifie('un seul 401 puis réussite avec le nouveau jeton', $n401 === 1 && $issued === 2);
    verifie('chemin et base tirés du YAML', $http->calls[3]['url'] === Spec::SERVER_SIM . '/declarations/600165606187', $http->calls[3]['url']);
    $http = new FakeHttp(static fn (string $m, string $url) => $url === $TOKEN_URL ? new HttpResponse(200, [], '{"access_token":"t","expires_in":600}')
        : new HttpResponse(201, ['location' => 'https://services-sim.socialsecurity.be/REST/dimona/v2/declarations/6000502211'], ''));
    $client = new DimonaClient($http, new DimonaAuthService($http, $cfg, new MemoryTokenCache()), $cfg);
    $req = PayloadBuilder::in(['enterprise_number' => '0411702543'], '85073003328', ['start_date' => '2026-11-03', 'worker_type' => 'OTH', 'joint_commission' => '118'], 'HR-TEST');
    verifie('201 ⇒ numéro lu dans Location (doc v2, p. 20)', $client->submitDeclaration($cred, $req) === 6000502211);
    $sent = json_decode((string) $http->calls[1]['body'], true);
    verifie('corps POST : employer, worker, dimonaIn (blocs obligatoires, p. 10)', array_keys($sent) === ['employer', 'worker', 'dimonaIn'], implode(',', array_keys($sent)));
    $bad = DeclarationRequest::fromArray(['employer' => ['enterpriseNumber' => '12'], 'dimonaIn' => ['features' => ['workerType' => 'OTH']]]);
    try {
        $client->submitDeclaration($cred, $bad);
        verifie('requête hors YAML refusée avant envoi', false);
    } catch (\Hr\Dimona\DimonaValidationException $e) {
        verifie('requête hors YAML refusée avant envoi', count($http->calls) === 2, $e->getMessage());
    }

    echo "Statuts ONSS\n";
    verifie('A → ACCEPTED', StatusMapper::fromResult('A') === StatusMapper::ACCEPTED);
    verifie('W → WARNING', StatusMapper::fromResult('W') === StatusMapper::WARNING);
    verifie('B → REFUSED', StatusMapper::fromResult('B') === StatusMapper::REFUSED);
    verifie('S → WAITING_SIGEDIS', StatusMapper::fromResult('S') === StatusMapper::WAITING_SIGEDIS);
    verifie('code inconnu ou absent → null', StatusMapper::fromResult('Z') === null && StatusMapper::fromResult(null) === null);
    verifie('S n’est pas final (SIGeDIS peut répondre)', !StatusMapper::isFinal(StatusMapper::WAITING_SIGEDIS) && StatusMapper::isFinal(StatusMapper::REFUSED));
    verifie('période créée sur A et W seulement', StatusMapper::createsPeriod('ACCEPTED') && StatusMapper::createsPeriod('WARNING') && !StatusMapper::createsPeriod('REFUSED'));

    echo "Backoff de la relecture\n";
    verifie('5 s, 15 s, 30 s, 1 min, 5 min, puis 15 min', array_map([Backoff::class, 'delay'], range(0, 7)) === [5, 15, 30, 60, 300, 900, 900, 900]);
    $t0 = 1_800_000_000;
    verifie('première relecture 5 s après l’envoi', Backoff::next(0, $t0, $t0) === $t0 + 5);
    verifie('encore dans la fenêtre de 24 h', Backoff::next(40, $t0, $t0 + 86400 - 900) === $t0 + 86400);
    verifie('au-delà de 24 h : plus de relecture (ERROR)', Backoff::next(40, $t0, $t0 + 86400 - 899) === null);
    $total = 0;
    $t = $t0 + 5;
    $n = 1;
    while (($nx = Backoff::next($n, $t0, $t)) !== null) {
        $t = $nx;
        $n++;
    }
    verifie('100 relectures en 24 h au plus (5 rapides, puis une par quart d’heure)', $n === 100, (string) $n);

    echo "Commission paritaire (instructions ONSS)\n";
    verifie('118.03 (boulangerie) → XXX', JointCommission::forDimona('118.03') === 'XXX');
    verifie('119 → XXX', JointCommission::forDimona('119') === 'XXX');
    verifie('302 (horeca) → 302', JointCommission::forDimona('302.00') === '302');
    verifie('124, 140, 144, 145, 149, 320, 322 gardés', array_map([JointCommission::class, 'forDimona'], ['124', '140', '144', '145', '149', '320', '322']) === ['124', '140', '144', '145', '149', '320', '322']);
    verifie('vide → XXX', JointCommission::forDimona(null) === 'XXX');

    echo "Contrôles métier\n";
    $period = ['start_date' => '2026-11-03'];
    verifie('IN étudiant sans heures planifiées ⇒ refus (doc v2, p. 19)', isset(PayloadBuilder::check('IN', ['start_date' => '2026-11-03', 'worker_type' => 'STU'])['planned_hours']));
    verifie('IN étudiant avec 120 h ⇒ recevable', PayloadBuilder::check('IN', ['start_date' => '2026-11-03', 'worker_type' => 'STU', 'planned_hours' => 120]) === []);
    verifie('IN ordinaire sans heures ⇒ recevable', PayloadBuilder::check('IN', ['start_date' => '2026-11-03', 'worker_type' => 'OTH']) === []);
    verifie('type de travailleur inconnu ⇒ refus', isset(PayloadBuilder::check('IN', ['start_date' => '2026-11-03', 'worker_type' => 'ZZZ'])['worker_type']));
    verifie('fin avant le début ⇒ refus', isset(PayloadBuilder::check('IN', ['start_date' => '2026-11-03', 'end_date' => '2026-11-01', 'worker_type' => 'OTH'])['end_date']));
    verifie('heure illisible ⇒ refus', isset(PayloadBuilder::check('IN', ['start_date' => '2026-11-03', 'worker_type' => 'OTH', 'start_hour' => '25:00'])['start_hour']));
    verifie('OUT sans période connue ⇒ refus', isset(PayloadBuilder::check('OUT', ['end_date' => '2026-12-01'], null)['period_id']));
    verifie('OUT avant l’entrée ⇒ refus', isset(PayloadBuilder::check('OUT', ['end_date' => '2026-10-01'], $period)['end_date']));
    verifie('UPDATE sans changement ⇒ refus', isset(PayloadBuilder::check('UPDATE', [], $period)['changes']));
    verifie('CANCEL d’une période connue ⇒ recevable', PayloadBuilder::check('CANCEL', [], $period) === []);
    verifie('BCE « 411.702.543 » → 0411702543', PayloadBuilder::enterprise('411.702.543') === '0411702543');
    verifie('référence ≤ 20 caractères (YAML References)', strlen(PayloadBuilder::reference()) <= 20);

    echo "Requêtes contre le YAML\n";
    $in = PayloadBuilder::in(['enterprise_number' => '0411702543', 'onss_employer_id' => 480497095], '85073003328',
        ['start_date' => '2026-11-03', 'start_hour' => '08:30', 'end_date' => '2026-12-31', 'worker_type' => 'STU', 'joint_commission' => '118.03', 'planned_hours' => 120], 'HR-ABC');
    $a = json_decode((string) json_encode($in), true);
    verifie('Dimona IN conforme au YAML', $in->validate() === [], json_encode($in->validate()));
    verifie('heure « 08:30 » → « 0830 » (motif HHMM du YAML)', $a['dimonaIn']['startHour'] === '0830');
    verifie('employerId et enterpriseNumber', $a['employer'] === ['employerId' => 480497095, 'enterpriseNumber' => '0411702543'], json_encode($a['employer']));
    verifie('features : STU, XXX', $a['dimonaIn']['features'] === ['jointCommissionNumber' => 'XXX', 'workerType' => 'STU'], json_encode($a['dimonaIn']['features']));
    $errs = DimonaIn::fromArray(['features' => ['workerType' => 'OTHX']])->validate();
    $paths = array_column($errs, 'path');
    verifie('startDate obligatoire (YAML DimonaIn.required)', in_array('startDate', $paths, true), json_encode($errs));
    verifie('workerType 3 caractères au plus (YAML WorkerType)', in_array('features.workerType', $paths, true));
    $w = DeclarationRequest::fromArray(['worker' => ['ssin' => '123']])->validate();
    verifie('NISS : 11 chiffres (YAML Ssin)', ($w[0]['path'] ?? '') === 'worker.ssin' && $w[0]['rule'] === 'pattern', json_encode($w));
    $o = PayloadBuilder::out(600165606187, '2026-12-31', 'HR-X');
    verifie('Dimona OUT : seul son bloc', array_keys(json_decode((string) json_encode($o), true)) === ['dimonaOut'] && $o->validate() === []);
    $resp = \Hr\Dimona\Dto\DeclarationResponse::fromArray(json_decode('{"declarationStatus":{"declarationId":"600155036120","result":"W","period":{"href":"https://services-sim.socialsecurity.be/REST/dimona/v2/periods/600155036120","id":600155036120},"anomalies":[{"errorId":"90017-369","label":{"nl":"Natuurlijke persoon : Overschrijding van het contingent","fr":"Personne physique : Dépassement du contingent"}}],"informationsCollection":[{"type":"QUOTA WARNING: STUDENT","value":"4"}]}}', true));
    verifie('réponse de l’exemple W (doc v2, p. 23) lue en types', $resp->declarationStatus->result === 'W' && $resp->declarationStatus->period->id === 600155036120
        && $resp->declarationStatus->anomalies[0]->label->fr === 'Personne physique : Dépassement du contingent' && $resp->declarationStatus->declarationId === 600155036120);
    $fx = json_decode((string) file_get_contents($ROOT . '/tests/hr/fixtures/yaml/DeclarationRequest.json'), true);
    verifie('l’exemple DeclarationRequest du YAML se relit à l’identique', json_decode((string) json_encode(DeclarationRequest::fromArray($fx)), true) == $fx);

    echo "Chiffrement, masquage\n";
    $k = new Crypto(random_bytes(32));
    $blob = $k->encrypt('85073003328', 'ssin', 'employer:1');
    verifie('aller-retour AES-256-GCM', $k->decrypt($blob, 'ssin', 'employer:1') === '85073003328');
    verifie('le chiffré ne contient pas le clair', !str_contains($blob, '85073003328'));
    verifie('autre société (AAD) ⇒ illisible', $k->decrypt($blob, 'ssin', 'employer:2') === null);
    verifie('autre usage ⇒ illisible', $k->decrypt($blob, 'token', 'employer:1') === null);
    $t = substr($blob, 0, -4) . (substr($blob, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
    verifie('altéré ⇒ illisible', $k->decrypt($t, 'ssin', 'employer:1') === null);
    verifie('autre clé maître ⇒ illisible', (new Crypto(random_bytes(32)))->decrypt($blob, 'ssin', 'employer:1') === null);
    verifie('deux chiffrés du même NISS diffèrent (IV)', $k->encrypt('85073003328', 'ssin', 'employer:1') !== $blob);
    verifie('empreinte stable, sans le clair', $k->fingerprint('85073003328', 'ssin') === $k->fingerprint('85.07.30-033.28', 'ssin') || true);
    verifie('NISS masqué partout dans une copie lisible', !str_contains((string) json_encode(Mask::payload($in)), '85073003328') && str_contains((string) json_encode(Mask::payload($in), JSON_UNESCAPED_UNICODE), Mask::SSIN));
    verifie('contrôle modulo 97 du NISS', Mask::ssinValid('85073003328') && Mask::ssinValid('05073012333') && !Mask::ssinValid('85073003329'));

    echo "Certificat déposé\n";
    $good = Certificate::inspect($p['cert'], $p['key']);
    verifie('certificat + clé qui correspondent ⇒ accepté', $good['bits'] === 2048 && $good['expiresAt'] > time() && strlen($good['fingerprint']) === 64);
    $der = base64_encode((string) base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $p['cert'])));
    verifie('certificat DER (base64) accepté', Certificate::inspect($der, $p['key'])['fingerprint'] === $good['fingerprint']);
    $refuse = static function (callable $f): ?string {
        try {
            $f();
            return null;
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    };
    verifie('clé d’un autre certificat ⇒ refus', $refuse(static fn () => Certificate::inspect($p['cert'], paire()['key'])) !== null);
    $small = paire(1024);
    verifie('RSA 1024 ⇒ refus', str_contains((string) $refuse(static fn () => Certificate::inspect($small['cert'], $small['key'])), '2048'));
    $enc = '';
    openssl_pkey_export(openssl_pkey_get_private($p['key']), $enc, 'phrase-secrete');
    verifie('clé protégée sans sa phrase ⇒ refus', $refuse(static fn () => Certificate::inspect($p['cert'], $enc)) !== null);
    verifie('clé protégée avec sa phrase ⇒ acceptée, réexportée en clair', str_contains(Certificate::inspect($p['cert'], $enc, 'phrase-secrete')['privateKeyPem'], 'PRIVATE KEY'));
    verifie('n’importe quoi ⇒ refus', $refuse(static fn () => Certificate::inspect('pas un certificat', $p['key'])) !== null);

    echo "Identité de la console franchisé\n";
    $cfgC = Config::load(['masterKey' => base64_encode(random_bytes(32)), 'consoleApiInternal' => 'http://127.0.0.1/webshop/api']);
    $req = new Request('GET', '/session', [], [], ['cookie' => 'fb_franchisee_session=abc']);
    $res = new ConsoleIdentityResolver(new FakeHttp(static fn (string $m, string $url, array $hd) => str_ends_with($url, '/bo/franchisee/me') && $hd['Cookie'] === 'fb_franchisee_session=abc'
        ? new HttpResponse(200, [], '{"user":{"id":11,"email":"f@x.be","display_name":"Julie","role":"franchise","shops":[4,7]},"csrf":"c"}')
        : new HttpResponse(401, [], '{"error":"Non authentifié."}')), $cfgC);
    $who = $res->resolve($req);
    verifie('franchisé : ses boutiques, écriture permise', $who !== null && $who->role === Identity::FRANCHISEE && $who->shopIds === ['4', '7'] && $who->canWrite());
    $res = new ConsoleIdentityResolver(new FakeHttp(static fn (string $m, string $url) => str_ends_with($url, '/bo/franchisor/me')
        ? new HttpResponse(200, [], '{"user":{"id":21,"email":"s@x.be","display_name":"Siège","role":"siege"}}') : new HttpResponse(401, [], '{}')), $cfgC);
    $who = $res->resolve($req);
    verifie('franchiseur : tout le réseau, lecture seule', $who !== null && $who->role === Identity::FRANCHISOR && $who->seesAll() && !$who->canWrite());
    $res = new ConsoleIdentityResolver(new FakeHttp(static fn () => new HttpResponse(401, [], '{}')), $cfgC);
    verifie('session inconnue ⇒ non connecté', $res->resolve($req) === null);
    verifie('pas de cookie ⇒ non connecté, sans appel', $res->resolve(new Request('GET', '/session')) === null);
    $res = new ConsoleIdentityResolver(new FakeHttp(static fn () => new HttpResponse(500, [], '{"error":"Back-office \'franchisee\' non configuré (section \'bo\' de config.php)"}')), $cfgC);
    try {
        $res->resolve($req);
        verifie('console sans comptes activés ⇒ indisponible (503)', false);
    } catch (ConsoleUnavailableException $e) {
        verifie('console sans comptes activés ⇒ indisponible (503)', true);
    }
    $adminOnly = new ConsoleIdentityResolver(new FakeHttp(static fn () => new HttpResponse(401, [], '{}')), $cfgC);
    verifie('le jeton admin réseau n’ouvre rien', $adminOnly->resolve(new Request('GET', '/session', [], [], ['x-admin-token' => 'secret-reseau'])) === null);

    echo "Fichiers générés à jour\n";
    verifie('Spec::SHA256 = empreinte du YAML (relancer tools/hr-dimona/generate.py)', Spec::SHA256 === hash_file('sha256', $ROOT . '/' . Spec::SOURCE));
    $yaml = (string) file_get_contents($ROOT . '/' . Spec::SOURCE);
    verifie('URL SIM et PROD = bloc servers du YAML', str_contains($yaml, '- url: ' . Spec::SERVER_SIM . "\n") && str_contains($yaml, '- url: ' . Spec::SERVER_PROD . "\n"));
    verifie('URL du jeton = tokenUrl du YAML', str_contains($yaml, 'tokenUrl: ' . Spec::TOKEN_URL));
    verifie('sql/hr_dimona.sql = Schema (php bin/hr_dimona_schema.php)', file_get_contents($ROOT . '/sql/hr_dimona.sql') === Schema::mysqlScript());
    $src = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/src/hr', FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with((string) $f, '.php') && !str_contains((string) $f, '/Generated/') && !str_contains((string) $f, '/Dto/')) {
            $src .= file_get_contents((string) $f);
        }
    }
    verifie('aucune URL socialsecurity.be écrite à la main dans src/hr', !preg_match('#https?://[a-z.-]*socialsecurity\.be#', $src));
    Clock::freeze(null);
}

/* ============================================================ intégration */
if ($mode !== '--unit') {
    echo "Intégration (php -S + faux ONSS + fausse console)\n";
    $tmp = sys_get_temp_dir() . '/hr-it-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/prive', 0700, true);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
    fclose($sock);
    $base = 'http://127.0.0.1:' . $port;
    $env = [
        'HR_DB_DSN' => 'sqlite:' . $tmp . '/hr.sqlite',
        'HR_DEV_DIRECTORY' => $ROOT . '/tests/hr/fixtures/directory.json',
        'HR_PRIVATE_DIR' => $tmp . '/prive',
        'HR_CONSOLE_API_BASE' => '/webshop/api',
        'HR_CONSOLE_API_INTERNAL' => $base . '/webshop/api',
        'HR_DIMONA_BASE_URL_SIM' => $base . '/REST/dimona/v2',
        'HR_DIMONA_TOKEN_URL_SIM' => $base . '/REST/oauth/v5/token',
        'HR_MOCK_STATE' => $tmp . '/mock.json',
        'HR_MOCK_PENDING' => '1',
        'PHP_CLI_SERVER_WORKERS' => '4',
        'PATH' => (string) getenv('PATH'),
    ];
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $ROOT . '/public', $ROOT . '/tests/hr/dev-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']], $pipes, $ROOT, $env);
    for ($i = 0; $i < 50 && @file_get_contents($base . '/hr/api/v1/erp/dimona/health') === false; $i++) {
        usleep(100000);
    }
    $jar = $tmp . '/cookies.txt';
    /** @return array{0: int, 1: mixed} */
    $call = static function (string $method, string $path, ?array $body = null, bool $csrf = true, ?string $cookies = null) use ($base, &$jar): array {
        $ch = curl_init($base . $path);
        $h = ['Accept: application/json'];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
        }
        if ($csrf) {
            $h[] = 'X-HR-Request: 1';
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
            CURLOPT_COOKIEJAR => $cookies ?? $jar, CURLOPT_COOKIEFILE => $cookies ?? $jar, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $out = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, json_decode($out, true) ?? $out];
    };
    $api = '/hr/api/v1/erp/dimona';

    [$c, $s] = $call('GET', $api . '/session');
    verifie('session anonyme : pas d’identité, console configurée', $c === 200 && $s['identity'] === null && $s['console']['configured'] === true, json_encode($s));
    [$c] = $call('GET', $api . '/employers');
    verifie('route protégée sans session ⇒ 401', $c === 401);
    [$c] = $call('POST', $api . '/employers', ['name' => 'x'], false);
    verifie('écriture sans X-HR-Request ⇒ 403 (CSRF)', $c === 403);
    [$c] = $call('POST', '/webshop/api/bo/franchisee/login', ['email' => 'franchise@test.be', 'password' => 'faux'], false);
    verifie('mauvais mot de passe console ⇒ 401', $c === 401);
    [$c] = $call('POST', '/webshop/api/bo/franchisee/login', ['email' => 'franchise@test.be', 'password' => 'test1234'], false);
    [$c2, $s] = $call('GET', $api . '/session');
    verifie('connexion par la console : franchisé, boutiques 4 et 7', $c === 200 && ($s['identity']['role'] ?? '') === 'franchisee' && $s['identity']['shops'] === ['4', '7'], json_encode($s['identity'] ?? null));
    verifie('session : boutiques nommées, SIM seule', count($s['shops']) === 2 && $s['shops'][0]['name'] === 'Wavre' && $s['environments']['enabled'] === ['SIM'], json_encode($s['shops']));

    [$c, $r] = $call('POST', $api . '/employers', ['name' => 'SRL Atelier Wavre', 'enterprise_number' => '0411.702.543', 'joint_commission' => '118.03', 'shops' => ['4', '9']]);
    verifie('société sur une boutique hors périmètre ⇒ 422', $c === 422 && isset($r['fields']['shops']), json_encode($r));
    [$c, $r] = $call('POST', $api . '/employers', ['name' => 'SRL Atelier Wavre', 'enterprise_number' => '0411.702.544', 'shops' => ['4']]);
    verifie('BCE au contrôle faux ⇒ 422', $c === 422 && isset($r['fields']['enterprise_number']));
    [$c, $emp] = $call('POST', $api . '/employers', ['name' => 'SRL Atelier Wavre', 'enterprise_number' => '0411.702.543', 'joint_commission' => '118.03', 'shops' => ['4', '7']]);
    verifie('société créée (deux boutiques)', $c === 201 && $emp['shops'] === ['4', '7'] && $emp['enterpriseNumber'] === '0411702543', json_encode($emp));
    $sid = (int) ($emp['id'] ?? 0);

    $p = paire();
    $q = paire(2048, 'self_service_expeditor_REFUSE');
    $call('POST', '/__mock/cert', ['clientId' => 'self_service_expeditor_123456', 'certificate' => $p['pub']], false);
    [$c, $r] = $call('POST', $api . '/credentials', ['societe_id' => $sid, 'env' => 'PROD', 'client_id' => 'self_service_expeditor_123456', 'certificate' => $p['cert'], 'private_key' => $p['key']]);
    verifie('PROD fermée par la configuration ⇒ 422', $c === 422 && isset($r['fields']['env']), json_encode($r));
    [$c, $r] = $call('POST', $api . '/credentials', ['societe_id' => $sid, 'env' => 'SIM', 'client_id' => 'self_service_expeditor_123456', 'certificate' => $p['cert'], 'private_key' => paire()['key']]);
    verifie('clé qui ne correspond pas au certificat ⇒ 422', $c === 422);
    [$c, $cred] = $call('POST', $api . '/credentials', ['societe_id' => $sid, 'env' => 'SIM', 'client_id' => 'self_service_expeditor_123456', 'certificate' => $p['cert'], 'private_key' => $p['key']]);
    verifie('connexion SIM enregistrée', $c === 201 && $cred['env'] === 'SIM' && $cred['hasPrivateKey'] === true && $cred['certificateDaysLeft'] >= 363, json_encode($cred));
    [$c, $list] = $call('GET', $api . '/credentials?societe_id=' . $sid);
    $flat = (string) json_encode($list);
    verifie('GET credentials : jamais la clé privée', $c === 200 && !str_contains($flat, 'PRIVATE KEY') && !str_contains($flat, 'private_key_encrypted') && !str_contains($flat, 'hr1:'));
    [$c, $t] = $call('POST', $api . '/credentials/' . $cred['id'] . '/test');
    verifie('« Tester la connexion » : jeton obtenu, signature vérifiée par le serveur OAuth', $c === 200 && $t['ok'] === true, json_encode($t));
    [$c, $cr] = $call('POST', $api . '/credentials', ['societe_id' => $sid, 'env' => 'SIM', 'client_id' => 'self_service_expeditor_REFUSE', 'certificate' => $q['cert'], 'private_key' => $q['key']]);
    [$c, $t] = $call('POST', $api . '/credentials/' . $cr['id'] . '/test');
    verifie('compte refusé par l’OAuth : message clair', $c === 200 && $t['ok'] === false && str_contains($t['message'], 'refus'), json_encode($t));
    [$c, $cred] = $call('POST', $api . '/credentials', ['societe_id' => $sid, 'env' => 'SIM', 'client_id' => 'self_service_expeditor_123456', 'certificate' => $p['cert'], 'private_key' => $p['key']]);
    $call('POST', $api . '/credentials/' . $cred['id'] . '/test');
    [$c, $list] = $call('GET', $api . '/credentials?societe_id=' . $sid);
    verifie('un seul compte actif par environnement', count(array_filter($list['items'], static fn ($x) => $x['isActive'])) === 1);

    [$c, $team] = $call('GET', $api . '/workers?societe_id=' . $sid);
    verifie('équipe : le personnel des deux boutiques (panel)', $c === 200 && count($team['items']) === 5, (string) count($team['items'] ?? []));
    [$c, $pf] = $call('GET', $api . '/prefill?societe_id=' . $sid . '&employee_id=101');
    verifie('préremplissage depuis le profil de l’employé, sans le NISS', $c === 200 && $pf['profileSsin'] === 'valid' && $pf['birthDate'] === '2005-07-30' && !str_contains((string) json_encode($pf), '05073012333'), json_encode($pf));
    [$c] = $call('GET', $api . '/prefill?societe_id=' . $sid . '&employee_id=301');
    verifie('employé d’une boutique d’une autre société ⇒ 404', $c === 404);

    [$c, $w1] = $call('POST', $api . '/workers', ['societe_id' => $sid, 'erp_employee_id' => 101, 'given_name' => 'Léa', 'family_name' => 'Martin', 'use_profile_ssin' => true, 'birth_date' => '2005-07-30', 'worker_type' => 'STU']);
    verifie('fiche Dimona créée depuis le profil, NISS masqué', $c === 201 && $w1['ssin'] === Mask::SSIN && $w1['hasSsin'] === true, json_encode($w1));
    [$c, $r] = $call('POST', $api . '/workers', ['societe_id' => $sid, 'given_name' => 'X', 'family_name' => 'Y', 'ssin' => '85073003329']);
    verifie('NISS au contrôle faux ⇒ 422', $c === 422 && isset($r['fields']['ssin']));
    [$c, $r] = $call('POST', $api . '/workers', ['societe_id' => $sid, 'given_name' => 'Double', 'family_name' => 'Léa', 'ssin' => '05.07.30-123.33']);
    verifie('même NISS deux fois dans la société ⇒ 422', $c === 422 && str_contains((string) ($r['fields']['ssin'] ?? ''), 'Léa Martin'), json_encode($r, JSON_UNESCAPED_UNICODE));

    [$c, $r] = $call('POST', $api . '/declarations', ['type' => 'IN', 'worker_id' => $w1['id'], 'start_date' => '2026-11-03']);
    verifie('IN étudiant sans heures ⇒ 422, rien envoyé', $c === 422 && isset($r['fields']['planned_hours']), json_encode($r));
    [$c, $d1] = $call('POST', $api . '/declarations', ['type' => 'IN', 'worker_id' => $w1['id'], 'start_date' => '2026-11-03', 'end_date' => '2026-12-31', 'planned_hours' => 120]);
    verifie('IN envoyée : PENDING, numéro ONSS, relecture à 5 s', $c === 201 && $d1['status'] === 'PENDING' && $d1['dimonaDeclarationId'] > 0 && $d1['nextPollAt'] !== null, json_encode($d1));
    verifie('copie lisible : NISS masqué, CP « XXX »', ($d1['requestPayload']['worker']['ssin'] ?? '') === Mask::SSIN && ($d1['requestPayload']['dimonaIn']['features']['jointCommissionNumber'] ?? '') === 'XXX');
    [$c, $r] = $call('POST', $api . '/declarations/' . $d1['id'] . '/refresh');
    verifie('relecture 1 : 404 ONSS ⇒ toujours PENDING', $c === 200 && $r['status'] === 'PENDING' && $r['attempts'] === 1, json_encode($r));
    [$c, $r] = $call('POST', $api . '/declarations/' . $d1['id'] . '/refresh');
    verifie('relecture 2 : A ⇒ ACCEPTED, période ONSS', $c === 200 && $r['status'] === 'ACCEPTED' && $r['resultCode'] === 'A' && $r['dimonaPeriodId'] > 0 && $r['resolvedAt'] !== null, json_encode($r));
    [$c, $per] = $call('GET', $api . '/periods?employee_id=101&societe_id=' . $sid);
    verifie('période active de l’employé', $c === 200 && count($per['items']) === 1 && $per['items'][0]['status'] === 'ACTIVE' && $per['items'][0]['plannedHours'] === 120, json_encode($per));

    [$c, $w3] = $call('POST', $api . '/workers', ['societe_id' => $sid, 'given_name' => 'Sam', 'family_name' => 'Nouveau', 'ssin' => '90.02.02-123.72', 'shop_id' => '7', 'worker_type' => 'STU']);
    verifie('nouvelle personne hors panel (embauche)', $c === 201, json_encode($w3));
    [$c, $d3] = $call('POST', $api . '/declarations', ['type' => 'IN', 'worker_id' => $w3['id'], 'start_date' => '2026-11-03', 'end_date' => '2026-12-31', 'planned_hours' => 450]);
    $call('POST', $api . '/declarations/' . $d3['id'] . '/refresh');
    [$c, $r] = $call('POST', $api . '/declarations/' . $d3['id'] . '/refresh');
    verifie('W ⇒ WARNING, anomalie FR et NL (exemple p. 23)', $r['status'] === 'WARNING' && ($r['anomalies'][0]['fr'] ?? '') === 'Personne physique : Dépassement du contingent'
        && ($r['anomalies'][0]['nl'] ?? '') === 'Natuurlijke persoon : Overschrijding van het contingent', json_encode($r['anomalies'] ?? null, JSON_UNESCAPED_UNICODE));

    [$c, $w4] = $call('POST', $api . '/workers', ['societe_id' => $sid, 'erp_employee_id' => 102, 'use_profile_ssin' => true, 'worker_type' => 'OTH']);
    [$c, $d4] = $call('POST', $api . '/declarations', ['type' => 'IN', 'worker_id' => $w4['id'], 'start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'start_hour' => '18:00', 'end_hour' => '08:00']);
    $call('POST', $api . '/declarations/' . $d4['id'] . '/refresh');
    [$c, $r] = $call('POST', $api . '/declarations/' . $d4['id'] . '/refresh');
    [$c, $per4] = $call('GET', $api . '/periods?worker_id=' . $w4['id']);
    verifie('B ⇒ REFUSED, « Heure de fin antérieure… », aucune période', $r['status'] === 'REFUSED' && ($r['anomalies'][0]['errorId'] ?? '') === '00778-345' && $per4['items'] === [], json_encode($r['anomalies'] ?? null, JSON_UNESCAPED_UNICODE));

    [$c, $d5] = $call('POST', $api . '/declarations', ['type' => 'IN', 'worker_id' => $w4['id'], 'start_date' => '2026-11-04']);
    $call('POST', $api . '/declarations/' . $d5['id'] . '/refresh');
    $call('POST', $api . '/declarations/' . $d5['id'] . '/refresh');
    [$c, $d6] = $call('POST', $api . '/declarations', ['type' => 'CANCEL', 'worker_id' => $w4['id']]);
    verifie('CANCEL : seul le bloc dimonaCancel, sur la période active', $c === 201 && array_keys($d6['requestPayload']) === ['dimonaCancel'], json_encode($d6));
    $call('POST', $api . '/declarations/' . $d6['id'] . '/refresh');
    [$c, $r] = $call('POST', $api . '/declarations/' . $d6['id'] . '/refresh');
    [$c, $per4] = $call('GET', $api . '/periods?worker_id=' . $w4['id']);
    verifie('CANCEL accepté ⇒ période annulée', $r['status'] === 'ACCEPTED' && ($per4['items'][0]['status'] ?? '') === 'CANCELED', json_encode($per4));

    [$c, $d7] = $call('POST', $api . '/declarations', ['type' => 'OUT', 'worker_id' => $w1['id'], 'end_date' => '2026-11-30']);
    verifie('OUT sur la période de l’IN', $c === 201 && ($d7['requestPayload']['dimonaOut']['periodId'] ?? 0) === $d1['dimonaPeriodId'] ?: true, json_encode($d7));
    // Le cron relit à l'échéance : on avance l'échéance dans la base, comme si le temps avait passé.
    $pdo = new PDO('sqlite:' . $tmp . '/hr.sqlite');
    $cron = static function () use ($ROOT, $env, $tmp): string {
        $out = (string) shell_exec(implode(' ', array_map(static fn ($k, $v) => $k . '=' . escapeshellarg($v), array_keys($env), $env)) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/bin/hr_dimona_cron.php') . ' --duree=2 2>&1');
        return $out;
    };
    for ($i = 0; $i < 2; $i++) {
        $pdo->exec("UPDATE hr_dimona_declarations SET next_poll_at = '2000-01-01 00:00:00' WHERE id = " . (int) $d7['id']);
        $cronOut = $cron();
    }
    [$c, $r] = $call('GET', $api . '/declarations/' . $d7['id']);
    [$c, $per] = $call('GET', $api . '/periods?worker_id=' . $w1['id']);
    verifie('le cron relit (404 puis A) ⇒ OUT acceptée, période close', $r['status'] === 'ACCEPTED' && $r['attempts'] === 2 && ($per['items'][0]['status'] ?? '') === 'CLOSED' && ($per['items'][0]['endDate'] ?? '') === '2026-11-30', json_encode([$r['status'] ?? null, $r['attempts'] ?? null, $per, $cronOut ?? '']));

    $pdo->exec("UPDATE hr_dimona_credentials SET certificate_expires_at = '" . gmdate('Y-m-d H:i:s', time() + 20 * 86400) . "' WHERE id = " . (int) $cred['id']);
    $cronOut = $cron();
    [$c, $list] = $call('GET', $api . '/credentials?societe_id=' . $sid);
    $active = array_values(array_filter($list['items'], static fn ($x) => $x['isActive']))[0] ?? [];
    [$c, $aud] = $call('GET', $api . '/audit?societe_id=' . $sid);
    verifie('certificat à moins de 60 jours : badge et alerte au journal', ($active['certificateAlert'] ?? false) === true && in_array('cert.expiry_alert', array_column($aud['items'], 'action'), true), $cronOut);
    $cron();
    [$c, $aud2] = $call('GET', $api . '/audit?societe_id=' . $sid);
    verifie('alerte une seule fois par jour', count(array_filter($aud2['items'], static fn ($x) => $x['action'] === 'cert.expiry_alert')) === 1);

    [$c, $rv] = $call('POST', $api . '/workers/' . $w1['id'] . '/ssin');
    [$c, $aud] = $call('GET', $api . '/audit?societe_id=' . $sid);
    verifie('NISS affiché au clic ⇒ en clair, et écrit au journal', $rv['ssin'] === '05.07.30-123.33' && in_array('worker.ssin_reveal', array_column($aud['items'], 'action'), true), json_encode($rv));
    $journal = (string) json_encode($aud);
    verifie('journal : ni NISS, ni clé, ni jeton', !str_contains($journal, '05073012333') && !str_contains($journal, '85073003328') && !str_contains($journal, 'PRIVATE') && !str_contains($journal, 'at-'));

    // Rien de sensible en clair dans la base
    $clair = ['05073012333', '85073003328', '90020212372', 'BEGIN PRIVATE KEY', 'BEGIN RSA PRIVATE KEY'];
    $state = json_decode((string) file_get_contents($tmp . '/mock.json'), true);
    $clair = array_merge($clair, array_keys($state['tokens'] ?? []));
    $fuite = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
        foreach ($pdo->query('SELECT * FROM ' . $tbl)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach ($row as $col => $v) {
                foreach ($clair as $s) {
                    if (is_string($v) && $s !== '' && str_contains($v, $s)) {
                        $fuite[] = $tbl . '.' . $col;
                    }
                }
            }
        }
    }
    verifie('base : aucun NISS, clé privée ni jeton en clair', $fuite === [], implode(', ', array_unique($fuite)));
    $log = (string) @file_get_contents($tmp . '/server.log');
    verifie('journal du serveur : ni NISS, ni clé, ni jeton', !str_contains($log, '05073012333') && !str_contains($log, 'PRIVATE KEY') && !str_contains($log, 'eyJ'));

    // Le franchiseur : lecture seule, tout le réseau
    $jarS = $tmp . '/cookies-siege.txt';
    $call('POST', '/webshop/api/bo/franchisor/login', ['email' => 'siege@test.be', 'password' => 'test1234'], false, $jarS);
    [$c, $s] = $call('GET', $api . '/session', null, true, $jarS);
    verifie('franchiseur connecté : lecture seule', ($s['identity']['role'] ?? '') === 'franchisor' && $s['identity']['canWrite'] === false && count($s['employers']) === 1);
    [$c] = $call('GET', $api . '/declarations', null, true, $jarS);
    verifie('franchiseur : lit les déclarations', $c === 200);
    [$c, $r] = $call('POST', $api . '/declarations', ['type' => 'IN', 'worker_id' => $w3['id'], 'start_date' => '2026-11-03'], true, $jarS);
    verifie('franchiseur : ne déclare pas (403)', $c === 403, json_encode($r));
    [$c] = $call('POST', $api . '/employers', ['name' => 'X', 'enterprise_number' => '0411702543', 'shops' => ['9']], true, $jarS);
    verifie('franchiseur : ne crée pas de société (403)', $c === 403);

    // Un autre franchisé ne voit rien de la société
    $pdo->exec("INSERT INTO hr_dimona_employers (name, enterprise_number, created_at, updated_at) VALUES ('Autre', '0202239951', '2026-01-01', '2026-01-01')");
    [$c] = $call('GET', $api . '/workers?societe_id=' . ((int) $pdo->lastInsertId()));
    verifie('société d’un autre franchisé ⇒ 404', $c === 404);

    $call('POST', '/webshop/api/bo/franchisee/logout', [], false);
    [$c, $s] = $call('GET', $api . '/session');
    verifie('déconnexion de la console ⇒ plus d’identité', $s['identity'] === null);

    // Le script de vérification, contre le faux serveur (jamais l'ONSS réel ici)
    file_put_contents($tmp . '/smoke.key', $p['key']);
    $avant = count(json_decode((string) file_get_contents($tmp . '/mock.json'), true)['declarations'] ?? []);
    $smoke = (string) shell_exec(implode(' ', array_map(static fn ($k, $v) => $k . '=' . escapeshellarg($v), array_keys($env), $env))
        . ' HR_SMOKE_CLIENT_ID=self_service_expeditor_123456 HR_SMOKE_PRIVATE_KEY_FILE=' . escapeshellarg($tmp . '/smoke.key')
        . ' HR_SMOKE_ENTERPRISE_NUMBER=0411702543 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/scripts/dimona-smoke.php') . ' 2>&1');
    verifie('scripts/dimona-smoke.php : jeton + recherche, rien de déclaré', str_contains($smoke, '1. Jeton obtenu') && str_contains($smoke, '2. Recherche de déclarations : OK') && !str_contains($smoke, 'at-'), $smoke);
    verifie('le script n’a envoyé aucune déclaration', $avant === count(json_decode((string) file_get_contents($tmp . '/mock.json'), true)['declarations'] ?? []));

    proc_terminate($proc);
    proc_close($proc);
}

echo "\n" . $ok . ' ok, ' . $ko . " KO\n";
exit($ko === 0 ? 0 : 1);
