<?php
declare(strict_types=1);

namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Services\Logistics\DriverService;
use Symfony\Component\HttpFoundation\Response;

/** Logistyka › Dostawy : potwierdzenia z tabletu kierowcy (przejazdy, postoje, podpisy, zdjęcia, PDF potwierdzenia). */
final class DeliveryProofController extends BaseController
{
    public function __construct(private DriverService $driver)
    {
    }

    public function index()
    {
        $date = (string) ($_GET['date'] ?? '') ?: date('Y-m-d');
        try {
            $data = $this->driver->erpDay($date);
        } catch (\Throwable $e) {
            $data = $this->driver->erpDay(date('Y-m-d'));
        }
        $this->view('logistics/deliveries', $data + ['prev' => date('Y-m-d', strtotime($data['date'] . ' -1 day')), 'next' => date('Y-m-d', strtotime($data['date'] . ' +1 day'))]);
    }

    public function proof($id)
    {
        $lang = (string) ($_GET['lang'] ?? 'pl');
        $file = $this->driver->proofPdf((int) $id, $lang);
        return new Response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
            'Content-Length' => strlen($file['content']),
        ]);
    }

    public function media($id)
    {
        $file = $this->driver->media((int) $id);
        return new Response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Length' => strlen($file['content']),
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
