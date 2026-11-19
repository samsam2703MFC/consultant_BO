<?php
declare(strict_types=1);

namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Repositories\Document\CmrSignatureRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [311] « Mój podpis » : każdy użytkownik ERP rysuje (albo wgrywa) swój podpis raz ; trafia do pola 22 CMR (nadawca)
 * każdej wysyłki, którą wystawia. Tylko własny podpis : odczyt, zapis i usunięcie.
 */
final class UserSignatureController extends BaseController
{
    use JsonErrors;

    private const MAX_BYTES = 400 * 1024;

    public function __construct(private CmrSignatureRepository $signatures)
    {
    }

    /** GET /my-signature */
    public function page()
    {
        $userId = $this->userId();
        if (!$userId) { header('Location: ' . ROOT . '/', true, 302); exit; }
        $current = $this->signatures->userSignature($userId);
        $this->view('organization/my_signature', ['has_signature' => $current !== null, 'updated_at' => $current['updated_at'] ?? null]);
    }

    /** GET /ajax/my-signature/image : własny podpis (PNG). */
    public function image()
    {
        $userId = $this->userId();
        $current = $userId ? $this->signatures->userSignature($userId) : null;
        if (!$current) return new Response('', 404);
        return new Response($current['image_png'], 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    /** POST /ajax/my-signature {image: data:image/png;base64,…} */
    public function save()
    {
        try {
            $userId = $this->userId() ?? throw new CustomException('Zaloguj się.', 'errors', 401);
            $data = (string) ($this->getJson(Request::createFromGlobals())['image'] ?? '');
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data, $m)) throw new CustomException('Narysuj albo wgraj podpis (PNG).', 'errors', 422);
            $png = base64_decode($m[1], true);
            if ($png === false || strlen($png) > self::MAX_BYTES) throw new CustomException('Plik podpisu jest za duży (najwyżej 400 KB).', 'errors', 422);
            $info = @getimagesizefromstring($png);
            if (!$info || ($info['mime'] ?? '') !== 'image/png' || $info[0] < 50 || $info[1] < 20) throw new CustomException('Nieprawidłowy obraz podpisu.', 'errors', 422);
            $this->signatures->saveUserSignature($userId, $png);
            return $this->json(['message' => 'Podpis zapisany — pojawi się w polu 22 CMR Twoich wysyłek.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** DELETE /ajax/my-signature */
    public function delete()
    {
        try {
            $userId = $this->userId() ?? throw new CustomException('Zaloguj się.', 'errors', 401);
            $this->signatures->deleteUserSignature($userId);
            return $this->json(['message' => 'Podpis usunięty — pole 22 CMR zostanie puste.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
