<?php
namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Workshop\Execution\DevProductService;
use Symfony\Component\HttpFoundation\Request;

/** Produkt testowy na tablecie produkcji : pokaż / ukryj, film, notatki, zdjęcia. */
class DevProductController extends BaseController
{
    use JsonErrors;

    public function __construct(private DevProductService $dev)
    {
    }

    public function tablet($id)
    {
        $this->view('product/test_product/tablet', $this->dev->sheet((int) $id) + ['max_photos' => DevProductService::MAX_PHOTOS]);
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
        $p = $this->dev->photo((int) $photoId);
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
