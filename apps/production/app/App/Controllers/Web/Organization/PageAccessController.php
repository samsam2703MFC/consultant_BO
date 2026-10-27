<?php
namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Core\Web\PageAccess;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\Request;

/** Uprawnienia stanowisk (strona administratora) i strona « brak dostępu ». */
class PageAccessController extends BaseController
{
    use JsonErrors;

    public function __construct(private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('organization/access', $this->access->matrix());
    }

    public function save($id)
    {
        try {
            $keys = $this->access->save((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano uprawnienia stanowiska.', 'data' => ['pages' => $keys]]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** GET /home : strona startowa stanowiska (logo, po zalogowaniu). */
    public function home()
    {
        header('Location: ' . ROOT . $this->access->homePath($this->userId()));
        exit;
    }

    public function saveHome($id)
    {
        try {
            $page = $this->access->saveHome((int) $id, $this->getJson(Request::createFromGlobals())['home'] ?? null, $this->userId());
            return $this->json(['message' => 'Zapisano stronę startową : ' . PageAccess::HOME_PAGES[$page] . '.', 'data' => ['home' => $page]]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Strona spoza uprawnień użytkownika (kod 403 ustawia App). */
    public function forbidden(string $uri)
    {
        $key = PageAccess::keyFor($uri);
        $this->view('organization/forbidden', [
            'page_label' => $key !== null ? PageAccess::PAGES[$key][0] : null,
            'admin_only' => $key !== null && PageAccess::adminOnly($key),
        ]);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
