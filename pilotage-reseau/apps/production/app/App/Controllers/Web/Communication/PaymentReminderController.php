<?php
namespace App\Controllers\Web\Communication;

use App\Core\Web\BaseController;
use App\Domain\Params\ParamRules;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\PaymentReminderAutoService;
use App\Services\Document\ProformaService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Przypomnienia płatności (audit 2, P5) : envoi automatique des niveaux 1–2 (paramètre reminders.auto_levels) et
 * « Opłacone » en lot pour les proformas cochées. Les rappels relus restent dans CommunicationController.
 */
class PaymentReminderController extends BaseController
{
    use \App\Core\Web\JsonErrors;

    private const MAX_BATCH = 200;

    public function __construct(private PaymentReminderAutoService $auto, private ParamRepository $params, private ProformaService $proformas)
    {
    }

    /** PUT /ajax/communication/payment-reminders/settings {auto_levels: 0|1|2} */
    public function saveSettings()
    {
        try {
            $raw = trim((string) ($this->getJson(Request::createFromGlobals())['auto_levels'] ?? ''));
            if ($error = ParamRules::rangeError('reminders.auto_levels', $raw)) return $this->json(['message' => 'Poziomy automatyczne : ' . $error], 422);
            $id = user('id');
            $this->params->setValue('reminders.auto_levels', $raw, $id ? (int) $id : null);
            $levels = $this->auto->levels();
            $plan = $this->auto->plan();
            return $this->json(['message' => $levels ? 'Wysyłka automatyczna : ' . ($levels === 1 ? 'poziom 1' : 'poziomy 1–2') . ' — przy następnym przebiegu (co 5 min) w kolejce : ' . count($plan) . '.'
                : 'Wysyłka automatyczna wyłączona : wszystkie przypomnienia przygotowujesz ręcznie.', 'levels' => $levels, 'planned' => count($plan)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/communication/payment-reminders/paid {ids: [...]} : « Opłacone » dla zaznaczonych proform. */
    public function markPaid()
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($this->getJson(Request::createFromGlobals())['ids'] ?? [])))));
        if (!$ids) return $this->json(['message' => 'Zaznacz co najmniej jedną proformę.'], 422);
        if (count($ids) > self::MAX_BATCH) return $this->json(['message' => 'Najwyżej ' . self::MAX_BATCH . ' proform naraz.'], 422);
        $done = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                if ($this->proformas->markPaid($id)) $done++; else $failed[] = $id;
            } catch (\Throwable $e) {
                $failed[] = $id;
            }
        }
        return $this->json(['message' => "Oznaczono jako opłacone : {$done}" . ($failed ? ' · nie udało się : ' . count($failed) . ' (anulowane albo bez pozycji)' : '') . '.',
            'paid' => $done, 'failed' => $failed], $done ? 200 : 422);
    }
}
