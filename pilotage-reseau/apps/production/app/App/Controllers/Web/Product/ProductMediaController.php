<?php
namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Workshop\ProductMediaService;
use Symfony\Component\HttpFoundation\Request;

/** Karta produktu : zdjęcie produktu i 4 etapy produkcji ze zdjęciem (pokazywane na tablecie). */
class ProductMediaController extends BaseController
{
    use JsonErrors;

    public function __construct(private ProductMediaService $media) {}

    public function sheet($id)
    {
        return $this->json(['data' => $this->media->sheet((int) $id)]);
    }

    public function save($id, $slot)
    {
        try {
            return $this->json(['message' => 'Zapisano.', 'data' => $this->media->save((int) $id, (int) $slot, $this->getJson(Request::createFromGlobals()), $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function deleteImage($id, $slot)
    {
        try {
            return $this->json(['message' => 'Usunięto zdjęcie.', 'data' => $this->media->deleteImage((int) $id, (int) $slot)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function image($id, $slot)
    {
        $img = $this->media->image((int) $id, (int) $slot);
        if ($img === null) {
            http_response_code(404);
            return '';
        }
        header('Content-Type: ' . $img['mime']);
        header('Cache-Control: private, max-age=604800');
        header('X-Content-Type-Options: nosniff');
        return $img['image'];
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
