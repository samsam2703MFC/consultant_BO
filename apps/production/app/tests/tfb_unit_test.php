<?php
declare(strict_types=1);
/*
 * Module atelier — tests unitaires sans base : JWT, validation, durée des shifts,
 * découpage des migrations, mot de passe, description OpenAPI.
 *   php tests/tfb_unit_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Jwt;
use App\Core\Api\Workshop\JwtException;
use App\Core\Api\Workshop\OpenApiSpec;
use App\Core\Api\Workshop\Translator;
use App\Core\Api\Workshop\Validator;
use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Services\Workshop\Auth\AuthService;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Referentiel\ShiftTemplateService;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function jwtError(callable $fn): ?string
{
    try { $fn(); } catch (JwtException $e) { return $e->getMessage(); }
    return null;
}

// --- JWT
$secret = str_repeat('k', 40);
$jwt = new Jwt($secret);
$now = 1_800_000_000;
$token = $jwt->encode(['sub' => '7', 'aud' => 'tfb-production', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 900]);
check($jwt->decode($token, 'tfb-production', $now + 10)['sub'] === '7', 'valid token decodes');
check(jwtError(fn() => $jwt->decode($token, 'tfb-production', $now + 900 + 31)) === 'expired', 'expired token is refused');
check(jwtError(fn() => $jwt->decode($token, 'other', $now)) === 'audience', 'wrong audience is refused');
check(jwtError(fn() => (new Jwt(str_repeat('x', 40)))->decode($token, 'tfb-production', $now)) === 'signature', 'other secret is refused');
[$h, $p] = explode('.', $token);
$tampered = $h . '.' . rtrim(strtr(base64_encode(json_encode(['sub' => '1', 'aud' => 'tfb-production', 'exp' => $now + 900])), '+/', '-_'), '=') . '.' . explode('.', $token)[2];
check(jwtError(fn() => $jwt->decode($tampered, 'tfb-production', $now)) === 'signature', 'tampered payload is refused');
$none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.' . $p . '.';
check(jwtError(fn() => $jwt->decode($none, 'tfb-production', $now)) === 'algorithm', 'alg=none is refused');
check(jwtError(fn() => $jwt->decode('abc', 'tfb-production', $now)) === 'malformed', 'garbage is refused');
$short = false;
try { new Jwt('short'); } catch (InvalidArgumentException) { $short = true; }
check($short, 'a short secret is refused');

// --- Mots de passe : bcrypt et comptes historiques en clair
check(AuthService::passwordMatches(password_hash('s3cret', PASSWORD_BCRYPT), 's3cret'), 'bcrypt password matches');
check(!AuthService::passwordMatches(password_hash('s3cret', PASSWORD_BCRYPT), 'nope'), 'wrong bcrypt password fails');
check(AuthService::passwordMatches('legacy', 'legacy') && !AuthService::passwordMatches('legacy', 'Legacy'), 'legacy plain password');
check(!AuthService::passwordMatches('', '') && !AuthService::passwordMatches('x', ''), 'empty passwords never match');

// --- Validation
$errors = function (array $input, bool $partial, callable $rules): array {
    $v = new Validator($input, $partial);
    $rules($v);
    return array_map(fn($e) => $e['field'] . ':' . $e['code'], $v->errors());
};
check($errors([], false, fn($v) => $v->string('name_pl', 100)) === ['name_pl:REQUIRED'], 'required on create');
check($errors([], true, fn($v) => $v->string('name_pl', 100)) === [], 'absent field is fine on PATCH');
check($errors(['name_pl' => '  '], true, fn($v) => $v->string('name_pl', 100)) === ['name_pl:REQUIRED'], 'blank string is missing');
check($errors(['x' => 1], false, fn($v) => $v->only(['a'])) === ['x:UNKNOWN_FIELD'], 'unknown field is reported');
check($errors(['code' => 'ab'], false, fn($v) => $v->code('code')) === [], 'code is upper-cased');
check($errors(['code' => 'a b'], false, fn($v) => $v->code('code')) === ['code:INVALID_CODE_FORMAT'], 'bad code');
check($errors(['c' => 0], false, fn($v) => $v->decimal('c', true, 0, null, false, true)) === ['c:GREATER_THAN'], 'capacity must be > 0');
check($errors(['t' => '24:00'], false, fn($v) => $v->time('t')) === ['t:INVALID_TIME'], '24:00 is not a time');
check($errors(['d' => [0, 3]], false, fn($v) => $v->days('d')) === ['d:INVALID_DAYS'], 'day 0 is refused');
check($errors(['u' => 'http://x.test/a.jpg'], false, fn($v) => $v->url('u')) === ['u:INVALID_URL'], 'http photo refused');
check($errors(['u' => 'https://other.test/a.jpg'], false, fn($v) => $v->url('u', 'https://photos.r2.test')) === ['u:URL_NOT_R2'], 'photo outside the R2 bucket refused');
$v = new Validator(['d' => [3, 1, 3], 't' => '6:00'], false);
$v->days('d')->time('t');
check($v->value('d') === '1,3' && $v->hasError('t'), 'days are sorted and deduplicated; 6:00 needs two digits');

// --- Durée d'un shift, minuit compris
check(ShiftTemplateService::durationMinutes('22:00', '06:00') === 480, 'night shift lasts 480 min');
check(ShiftTemplateService::durationMinutes('06:00', '14:00') === 480, 'day shift lasts 480 min');
check(ShiftTemplateService::durationMinutes('23:00', '07:30') === 510, 'shift crossing midnight');

// --- Découpage SQL (points-virgules dans les chaînes, commentaires, couleurs #)
$parts = MigrationRunner::splitStatements("-- a; comment\nINSERT INTO t VALUES ('a;b', 'l''x', '#8D1D2C');\n# other; comment\nSELECT 1;\n");
check(count($parts) === 2 && str_contains($parts[0], "'a;b'") && str_contains($parts[0], '#8D1D2C'), 'SQL splitter respects quotes and comments');

// --- Traductions : mêmes clés en polonais et en français
$pl = json_decode(file_get_contents(__DIR__ . '/../public/resources/lang/api/pl/production.json'), true);
$fr = json_decode(file_get_contents(__DIR__ . '/../public/resources/lang/api/fr/production.json'), true);
foreach (['errors', 'field_errors', 'fields', 'entities'] as $section) {
    check(array_keys($pl[$section]) === array_keys($fr[$section]), "translations: same keys in {$section}");
}
check(Translator::negotiate(null, 'fr-BE,fr;q=0.9', 'pl') === 'fr' && Translator::negotiate('xx', 'de', 'pl') === 'pl', 'language negotiation');
check((new Translator('fr'))->fieldError('robot_mode', 'ROBOT_MODE_REQUIRED') !== 'ROBOT_MODE_REQUIRED', 'field error is translated');

// --- Chaque route est documentée dans OpenAPI
$spec = OpenApiSpec::build('https://erp.test');
$documented = 0;
foreach ($spec['paths'] as $operations) $documented += count($operations);
check($documented === count(WorkshopApiRoutes::routes()), 'every route has an OpenAPI operation');
check($spec['servers'][0]['url'] === 'https://erp.test/api/v1/production', 'OpenAPI server uses API_BASE_URL');
$committed = __DIR__ . '/../docs/api/production-v1.openapi.json';
check(is_file($committed), 'docs/api/production-v1.openapi.json is committed');
$copy = json_decode((string) file_get_contents($committed), true);
check(array_keys($copy['paths']) === array_keys($spec['paths']), 'committed OpenAPI file is up to date (php Bin/tfb_openapi.php)');

echo "tfb_unit_test: OK\n";
