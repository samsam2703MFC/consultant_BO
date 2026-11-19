<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Logistics\DriverContractService;

/**
 * [309] Umowa zlecenia na przewóz towaru (makieta 57) z Logistyki : DOCX do edycji albo PDF w karcie HR kierowcy na
 * zlecenie. Dostęp jak Logistyka (prawo « logistics ») ; tylko ten szablon i tylko kierowcy na zlecenie ; każde otwarcie
 * zapisane w dzienniku HR (n_hr_log).
 */
final class DriverContractController extends BaseController
{
    use JsonErrors;

    public function __construct(private DriverContractService $contracts)
    {
    }

    public function docx($id)
    {
        try {
            $f = $this->contracts->docx((int) $id, (int) user('id') ?: null);
        } catch (CustomException $e) {
            http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
            header('Content-Type: text/plain; charset=utf-8');
            return $e->getMessage();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . (preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['filename']) ?: 'umowa.docx') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        return $f['content'];
    }

    public function pdf($id)
    {
        try {
            $r = $this->contracts->savePdf((int) $id, (int) user('id') ?: null);
            return $this->json(['message' => $r['message'], 'data' => ['document_id' => $r['document_id'], 'drivers' => $this->contracts->drivers()]]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
