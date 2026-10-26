<?php
namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Workshop\Execution\DevProductService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Nowe produkty (CRM) : tablica etapów (pomysł → test 1 → test 2 → receptura → wprowadzenie → wprowadzony), karta
 * produktu (grupa i jej tablet, testy zaplanowane i zrobione na tablecie ze zdjęciami, etap, wprowadzenie), film,
 * notatki, zdjęcia wzorcowe.
 */
class DevProductController extends BaseController
{
    use JsonErrors;

    public function __construct(private DevProductService $dev)
    {
    }

    public function pipeline()
    {
        $group = filter_var($_GET['group'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->view('product/test_product/pipeline', $this->dev->pipeline($group === false ? null : $group));
    }

    public function tablet($id)
    {
        $this->view('product/test_product/tablet', $this->dev->sheet((int) $id) + ['max_photos' => DevProductService::MAX_PHOTOS]);
    }

    /** Nowy produkt w rozwoju z tablicy : nazwa, grupa, data docelowa, notatki. */
    public function create()
    {
        try {
            $id = $this->dev->create($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Dodano produkt.', 'data' => ['id' => $id, 'url' => '/test-products/' . $id . '/tablet']], 201);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function planTrial($id)
    {
        try {
            return $this->json(['message' => 'Zaplanowano test — pojawi się na tablecie grupy.', 'data' => $this->dev->planTrial((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId())], 201);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function cancelTrial($id, $trialId)
    {
        try {
            return $this->json(['message' => 'Usunięto zaplanowany test.', 'data' => $this->dev->cancelTrial((int) $id, (int) $trialId)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function setStage($id)
    {
        try {
            return $this->json(['message' => 'Zmieniono etap.', 'data' => $this->dev->setStage((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function launch($id)
    {
        try {
            return $this->json(['message' => 'Produkt wprowadzony.', 'data' => $this->dev->launch((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function trialPhoto($photoId)
    {
        return $this->image($this->dev->trialPhoto((int) $photoId));
    }

    public function save($id)
    {
        try {
            return $this->json(['message' => 'Zapisano.', 'data' => $this->dev->save((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function addPhoto($id)
    {
        try {
            return $this->json(['message' => 'Dodano zdjęcie.', 'data' => $this->dev->addPhoto((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId())], 201);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function deletePhoto($id, $photoId)
    {
        try {
            return $this->json(['message' => 'Usunięto zdjęcie.', 'data' => $this->dev->deletePhoto((int) $id, (int) $photoId)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function photo($photoId)
    {
        return $this->image($this->dev->photo((int) $photoId));
    }

    private function image(?array $p)
    {
        if ($p === null) {
            http_response_code(404);
            return '';
        }
        header('Content-Type: ' . $p['mime']);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        return $p['image'];
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
