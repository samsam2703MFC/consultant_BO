<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Workshop\Auth\AuthService;
use App\Services\Workshop\Execution\KioskAuthService;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point d'entrée de {API_BASE_URL}/api/v1/production/* : langue, CORS, routage,
 * authentification JWT, droits, puis enveloppe JSON commune.
 *   succès : {"status":"ok","data":…,"meta":…}
 *   erreur : {"status":"error","description":"CODE","message":"…","errors":[{field,code,message}],"request_id":"…"}
 */
final class WorkshopApiKernel
{
    /** Cookie du jeton de tablette (HttpOnly, SameSite=Strict) : l'appairage survit au vidage du stockage du navigateur. */
    public const DEVICE_COOKIE = 'tfb_kiosk_device';

    public function __construct(
        private ContainerInterface $container,
        private AuthService $auth,
        private ParamRepository $params,
        private WorkshopConfig $config,
        private KioskAuthService $kiosk,
    ) {
    }

    /** @param string $path chemin sans le préfixe d'installation, ex. /api/v1/production/referentiels/groups */
    public function handle(Request $request, string $path): Response
    {
        $requestId = bin2hex(random_bytes(16));
        $translator = new Translator(Translator::negotiate(
            $request->query->get('lang'),
            $request->headers->get('Accept-Language'),
            $this->params->get('api.default_language', 'pl')
        ));
        $context = new ApiContext($requestId, $translator);

        try {
            if ($request->getMethod() === 'OPTIONS') {
                $response = new Response('', 204);
            } else {
                $response = $this->dispatch($request, substr($path, strlen(WorkshopApiRoutes::PREFIX)) ?: '/', $context);
            }
        } catch (ApiException $e) {
            $response = $this->error($e, $translator, $requestId);
        } catch (\Throwable $e) {
            error_log(sprintf('[workshop-api %s] %s in %s:%d', $requestId, $e->getMessage(), $e->getFile(), $e->getLine()));
            $response = $this->error(new ApiException('INTERNAL_ERROR', 500, [], ['request_id' => $requestId]), $translator, $requestId);
        }

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('Content-Language', $translator->lang());
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $this->cors($request, $response);
        return $response;
    }

    private function dispatch(Request $request, string $route, ApiContext $context): Response
    {
        $info = WorkshopApiRoutes::dispatcher()->dispatch($request->getMethod(), $route);
        if ($info[0] === Dispatcher::NOT_FOUND) {
            throw new ApiException('ROUTE_NOT_FOUND', 404);
        }
        if ($info[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            throw new ApiException('METHOD_NOT_ALLOWED', 405);
        }
        [, $handler, $vars] = $info;

        $permission = $handler['permission'];
        $deviceToken = null;
        if ($permission === 'kiosk' || $permission === 'kiosk_employee') {
            // Tablette (P7) : jeton de l'appareil (en-tête X-Device-Token, sinon cookie posé à l'appairage — la tablette
            // reste appairée si le navigateur vide son stockage), et pour les actions d'une personne son jeton court.
            $header = trim((string) $request->headers->get('X-Device-Token', ''));
            $cookie = trim((string) $request->cookies->get(self::DEVICE_COOKIE, ''));
            $employee = $permission === 'kiosk_employee' ? trim((string) $request->headers->get('X-Employee-Token', '')) : null;
            $deviceToken = $header !== '' ? $header : $cookie;
            try {
                $context = $this->kiosk->context($deviceToken, $employee, $context->requestId, $context->translator);
            } catch (ApiException $e) {
                if ($e->errorCode() !== 'DEVICE_UNAUTHENTICATED' || $cookie === '' || $cookie === $deviceToken) throw $e;
                $deviceToken = $cookie;
                $context = $this->kiosk->context($deviceToken, $employee, $context->requestId, $context->translator);
            }
        } elseif ($permission === 'qr' || $permission === 'phone') {
            // Téléphone personnel (pointage par QR code) : QR frais de la tablette, et pour pointer le jeton du téléphone.
            $qr = trim((string) $request->headers->get('X-Qr-Token', ''));
            $context = $permission === 'phone'
                ? $this->kiosk->phoneContext(trim((string) $request->headers->get('X-Phone-Token', '')), $qr, $context->requestId, $context->translator)
                : $this->kiosk->qrContext($qr, $context->requestId, $context->translator);
        } elseif ($permission !== null) {
            $context = $this->auth->authenticate($this->accessToken($request), $context->requestId, $context->translator);
            if ($permission !== 'auth' && !$context->canSomewhere($permission)) {
                throw new ApiException('FORBIDDEN', 403, [], ['permission' => $permission]);
            }
        }

        $controller = $this->container->get($handler['controller']);
        $result = $controller->{$handler['method']}(new ApiRequest($request, $vars, $context));
        if ($result instanceof Response) {
            return $result;
        }
        $body = ['status' => 'ok', 'data' => $result->data];
        if ($result->meta !== null) $body['meta'] = $result->meta;
        $response = $this->json($body, $result->status);
        // Jeton de la tablette en cookie (appairage, puis renouvelé à chaque appel : les navigateurs limitent la durée à ~400 jours).
        $deviceToken = $result->deviceToken ?? $deviceToken;
        if ($deviceToken !== null && $deviceToken !== '') {
            $response->headers->setCookie(Cookie::create(self::DEVICE_COOKIE, $deviceToken, time() + 400 * 86400, $request->getBasePath() ?: '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT));
        }
        return $response;
    }

    /** Jeton d'accès : « Authorization: Bearer … », ou X-Access-Token quand Authorization est
     *  déjà pris par un mot de passe navigateur (serveur de test derrière Basic Auth). */
    private function accessToken(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }
        $alt = trim((string) $request->headers->get('X-Access-Token', ''));
        if ($alt !== '') {
            return preg_replace('/^Bearer\s+/i', '', $alt);
        }
        throw new ApiException('UNAUTHENTICATED', 401);
    }

    private function error(ApiException $e, Translator $t, string $requestId): Response
    {
        $params = $e->params();
        if (isset($params['entity'])) $params['entity'] = $t->entity((string) $params['entity']);
        $body = [
            'status' => 'error',
            'description' => $e->errorCode(),
            'message' => $t->error($e->errorCode(), $params),
            'request_id' => $requestId,
        ];
        if ($e->fieldErrors()) {
            $body['errors'] = array_map(fn($err) => [
                'field' => $err['field'],
                'code' => $err['code'],
                'message' => $t->fieldError(preg_replace('/^.*\./', '', $err['field']), $err['code'], $err['params'] ?? []),
            ], $e->fieldErrors());
        }
        $response = $this->json($body, $e->httpStatus());
        if ($e->httpStatus() === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="tfb-production"');
        }
        return $response;
    }

    private function json(array $body, int $status): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $response->setData($body);
        return $response;
    }

    private function cors(Request $request, Response $response): void
    {
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '' || !in_array($origin, $this->config->corsOrigins(), true)) {
            return;
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, X-Access-Token, X-Device-Token, X-Employee-Token, X-Qr-Token, X-Phone-Token, Content-Type, Accept-Language');
        $response->headers->set('Access-Control-Expose-Headers', 'X-Request-Id, Content-Language');
        $response->headers->set('Access-Control-Max-Age', '600');
    }
}
