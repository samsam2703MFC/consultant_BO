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

    public function __construct(private PageAccessService $access, private \App\Services\Organization\ErpEntryService $erpEntry)
    {
    }

    public function index()
    {
        // [253] « Uprawnienia i menu » : menu stanowisk (pozycje railu) i strony szczegółowo.
        // [275] Wejście do ERP przez QR : stanowiska ERP i konta (powiązanie z Czasem pracy).
        $this->view('organization/access', $this->access->matrix() + ['menu' => $this->access->menuMatrix(), 'erp_qr' => $this->erpEntry->matrix()]);
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

    /** PUT /ajax/organization/menu/{id} : stan pozycji menu stanowiska ({item, state}) albo całe menu ({reset: true}). */
    public function saveMenu($id)
    {
        try {
            $res = $this->access->saveMenuState((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            $message = 'Zapisano menu stanowiska.' . ($res['side'] ? ' Bez dostępu także (wspólne strony) : ' . implode(', ', $res['side']) . '.' : '');
            return $this->json(['message' => $message, 'data' => ['states' => $res['states'], 'count' => count(array_filter($res['states'], fn($s) => $s === 'menu'))]]);
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

    /** [239] POST {position_id|null} : sesja administratora (menu pod awatarem) → strona startowa sesji. */
    public function session()
    {
        try {
            $home = $this->access->switchSession($this->userId(), $this->getJson(Request::createFromGlobals())['position_id'] ?? null);
            return $this->json(['data' => ['home' => $home]]);
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
