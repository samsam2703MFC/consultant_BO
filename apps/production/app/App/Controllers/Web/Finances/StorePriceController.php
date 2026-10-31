<?php
namespace App\Controllers\Web\Finances;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Finances\StorePrice\StorePriceAccountService;
use App\Services\Finances\StorePrice\StorePriceSyncService;
use App\Services\Finances\StorePrice\StoreSellabilityService;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\Request;

/** Ceny w sklepach (API panelu) : konto API (administrator), synchronizacja, powiązania produktów, progi Belgii. */
class StorePriceController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private StorePriceAccountService $account,
        private StorePriceSyncService $sync,
        private StoreSellabilityService $sellability,
        private PageAccessService $access,
    ) {
    }

    public function settings()
    {
        $this->view('settings/panel_api', $this->account->show());
    }

    public function saveAccount()
    {
        try {
            return $this->json(['message' => 'Zapisano konto API panelu.', 'data' => $this->account->save($this->input(), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function testAccount()
    {
        try {
            $r = $this->account->test($this->userId());
            return $this->json(['message' => 'Połączono z API panelu : ' . $r['shops'] . ' sklep(ów) w Belgii.', 'data' => $r]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function syncPrices()
    {
        try {
            $this->canSeeMargins();
            $r = $this->sync->sync($this->userId());
            return $this->json(['message' => 'Ceny w sklepach : ' . $r['products'] . ' produktów z ' . ($r['shops'] - $r['shops_failed']) . ' sklepów.' . ($r['message'] ? ' ' . $r['message'] : ''), 'data' => $r]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function candidates($id)
    {
        try {
            $this->canSeeMargins();
            return $this->json(['data' => $this->sellability->candidates((int) $id, (string) ($_GET['q'] ?? ''))]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function link($id)
    {
        try {
            return $this->json(['message' => 'Zapisano powiązanie.', 'data' => $this->sellability->setLink((int) $id, $this->input(), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function params()
    {
        try {
            return $this->json(['message' => 'Zapisano progi.', 'data' => $this->sellability->saveParams($this->input(), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function canSeeMargins(): void
    {
        if (!$this->access->canSee($this->userId(), '/finances/category-margin')) throw new CustomException('Brak dostępu do marż.', 'errors', 403);
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals()) ?: [];
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
