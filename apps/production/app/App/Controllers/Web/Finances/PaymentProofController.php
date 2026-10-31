<?php
declare(strict_types=1);

namespace App\Controllers\Web\Finances;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Finances\PaymentProof\PaymentProofService;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wpłata z e-maila [233] (makieta 9) : potwierdzenia przelewów klientów z Poczty — lista do sprawdzenia (Płatności dnia),
 * odczyt (tekst PDF / OCR), okno « Wpłata — sprawdź i zatwierdź », zatwierdzenie, odrzucenie, plik dowodu.
 * Ta sama zgoda co « Płatności dnia » ; tylko skrzynka zalogowanego użytkownika.
 */
final class PaymentProofController extends BaseController
{
    use JsonErrors;

    public const PATH = '/finances/payments-day';

    public function __construct(private PaymentProofService $proofs, private PageAccessService $access)
    {
    }

    public function candidates()
    {
        return $this->run(fn(int $u) => $this->proofs->candidates($u));
    }

    public function mail($id)
    {
        return $this->run(fn(int $u) => $this->proofs->mailInfo($u, (int) $id));
    }

    /** POST {mail_id, index, ocr_text?} */
    public function read()
    {
        return $this->run(function (int $u) {
            $in = $this->getJson(Request::createFromGlobals());
            $ocr = is_string($in['ocr_text'] ?? null) ? $in['ocr_text'] : null;
            return $this->proofs->read($u, (int) ($in['mail_id'] ?? 0), (int) ($in['index'] ?? -1), $ocr);
        });
    }

    public function show($id)
    {
        return $this->run(fn(int $u) => $this->proofs->view($u, (int) $id));
    }

    /** POST {proformas: [id], paid_on, amount} */
    public function confirm($id)
    {
        return $this->run(function (int $u) use ($id) {
            $r = $this->proofs->confirm($u, (int) $id, $this->getJson(Request::createFromGlobals()));
            return $r + ['message' => 'Wpłata zatwierdzona : ' . implode(', ', $r['numbers']) . ' — opłacona ' . date('d.m.Y', strtotime($r['paid_on']))
                . ($r['failed'] ? ' · nie udało się : ' . count($r['failed']) : '') . '.'];
        });
    }

    public function reject($id)
    {
        return $this->run(function (int $u) use ($id) {
            $this->proofs->reject($u, (int) $id, null);
            return ['message' => 'Odrzucone : to nie jest wpłata.'];
        });
    }

    public function rejectMail($id)
    {
        return $this->run(function (int $u) use ($id) {
            $this->proofs->reject($u, null, (int) $id);
            return ['message' => 'Wiadomość usunięta z listy potwierdzeń.'];
        });
    }

    /** Plik potwierdzenia (PDF / zdjęcie) do podglądu — strona chroniona jak « Płatności dnia ». */
    public function file($id)
    {
        try {
            $f = $this->proofs->file($this->user(), (int) $id);
        } catch (CustomException $e) {
            http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 404);
            return htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['filename']) ?: 'potwierdzenie';
        header('Content-Type: ' . (in_array($f['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) ? $f['mime'] : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($f['filename']));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'; plugin-types application/pdf');
        header('Cache-Control: private, no-store');
        return $f['content'];
    }

    private function run(callable $action)
    {
        try {
            $u = $this->user();
            if (!$this->access->canSee($u, self::PATH)) throw new CustomException('Potwierdzenia wpłat : dostęp jak do « Płatności dnia ».', 'errors', 403);
            $r = $action($u);
            return $this->json(['data' => $r] + (is_array($r) && isset($r['message']) ? ['message' => $r['message']] : []));
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function user(): int
    {
        $id = (int) user('id');
        if ($id <= 0) throw new CustomException('Zaloguj się ponownie.', 'errors', 401);
        return $id;
    }
}
