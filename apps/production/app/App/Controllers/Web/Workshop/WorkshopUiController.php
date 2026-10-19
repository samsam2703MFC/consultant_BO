<?php
namespace App\Controllers\Web\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Core\Web\BaseController;
use App\Services\Workshop\Auth\AuthService;

/**
 * Pages web du module atelier. Elles ne lisent rien en base : le navigateur appelle l'API
 * /api/v1/production avec un jeton d'accès court délivré à l'utilisateur connecté à l'ERP.
 */
class WorkshopUiController extends BaseController
{
    public function __construct(private AuthService $auth, private WorkshopConfig $config)
    {
    }

    public function products()
    {
        $this->view('workshop/products', ['api_base' => $this->apiBase()]);
    }

    public function planning()
    {
        $this->view('workshop/planning', ['api_base' => $this->apiBase()]);
    }

    /** Jeton d'accès pour la session ERP en cours (POST, même origine). */
    public function token()
    {
        header('Cache-Control: no-store');
        $userId = user('id');
        if (!$userId) return $this->json(['message' => 'Sesja wygasła — zaloguj się ponownie.'], 401);
        try {
            return $this->json(['data' => $this->auth->sessionToken((int) $userId)]);
        } catch (ApiException $e) {
            $lang = Translator::negotiate($_GET['lang'] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
            return $this->json(['description' => $e->errorCode(), 'message' => (new Translator($lang))->error($e->errorCode(), $e->params())], $e->httpStatus());
        }
    }

    private function apiBase(): string
    {
        $base = rtrim($this->config->apiBaseUrl(), '/');
        return ($base !== '' ? $base : rtrim(ROOT, '/')) . WorkshopApiRoutes::PREFIX;
    }
}
