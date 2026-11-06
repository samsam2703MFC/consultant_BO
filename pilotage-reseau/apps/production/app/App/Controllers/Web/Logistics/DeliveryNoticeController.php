<?php
namespace App\Controllers\Web\Logistics;

use App\Core\Helpers\GlobalRegistry;
use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Logistics\DeliveryNoticeService;
use App\Services\Logistics\LogisticsService;
use Symfony\Component\HttpFoundation\Request;

/** Logistyka › Powiadomienia klientów : kierowca, pojazd, trasa, godzina i palety dostawy — e-mail albo SMS w języku klienta. */
class DeliveryNoticeController extends BaseController
{
    use JsonErrors;

    public function __construct(private DeliveryNoticeService $notices, private LogisticsService $logistics) {}

    public function index()
    {
        $date = (string) ($_GET['date'] ?? '') ?: $this->logistics->defaultDate();
        try {
            $data = $this->notices->day($date);
        } catch (\Throwable $e) {
            $data = $this->notices->day($this->logistics->defaultDate());
        }
        $this->view('logistics/notices', $data);
    }

    /** Podgląd po zmianie godziny przyjazdu albo daty dostawy. */
    public function preview()
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $overrides = [];
            foreach ((array) ($input['items'] ?? []) as $item) $overrides[(int) ($item['id'] ?? 0)] = $item;
            $day = $this->notices->day((string) ($input['date'] ?? ''), $overrides);
            return $this->json(['data' => array_map(fn($c) => ['id' => $c['id'], 'arrival_date' => $c['arrival_date'], 'eta' => $c['eta'],
                'mail' => ['subject' => $c['mail']['subject'], 'body' => $c['mail']['body'], 'error' => $c['mail']['error']],
                'sms' => ['body' => $c['sms']['body'], 'error' => $c['sms']['error']]], $day['customers'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function send()
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $user = GlobalRegistry::get('user');
            $author = $user ? $user->getDisplayName() : (user('displayed_name') ?: user('login') ?: null);
            $result = $this->notices->send((string) ($input['date'] ?? ''), (array) ($input['items'] ?? []), $author);
            $count = count($result['sent']);
            return $this->json(['message' => $count ? "Zapisano powiadomienia : {$count}." : 'Nic nie wysłano.', 'data' => $result], $count || !$result['problems'] ? 200 : 422);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
