<?php

namespace App\Controllers\Web\Settings;

use App\Domain\Params\ParamRules;

use App\Core\Web\BaseController;
use App\Repositories\Workshop\ParamRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Ustawienia › Pozostałe : operacje na recepturach i parametry ogólne (zapasy produktów na magazynie, alerty i terminy). */
class SettingsController extends BaseController
{
    /** Parametry zapasów (n_prod_param) : kod => domyślnie ; zakresy w App\Domain\Params\ParamRules (jedna reguła). */
    private const STOCK_PARAMS = ['stock.suggest_weeks' => '6', 'stock.suggest_min_weeks' => '1', 'stock.suggest_max_weeks' => '3'];

    /** Alerty i terminy w dzwonku (n_prod_param) : kod => domyślnie ; zakresy w ParamRules. */
    private const ALERT_PARAMS = [
        'alerts.complaint_days' => '3',
        'alerts.complaint_escalate_days' => '7',
        'alerts.cost_due_day' => '10',
        'shipping_issue.decision_days' => '3',
        'shipping_issue.min_amount' => '1',
        'shipping_issue.lookback_days' => '45',
        'send_status.stuck_minutes' => '30',
        'send_status.lookback_days' => '30',
    ];

    public function __construct(private ParamRepository $params, private \App\Core\Api\Workshop\WorkshopConfig $config)
    {
    }

    public function index()
    {
        $stock = [];
        foreach (self::STOCK_PARAMS as $code => $default) $stock[$code] = $this->params->get($code, $default);
        $alerts = [];
        foreach (self::ALERT_PARAMS as $code => $default) $alerts[$code] = $this->params->get($code, $default);
        $this->view('settings/settings', ['stock_params' => $stock, 'alert_params' => $alerts, 'api_base' => $this->config->webApiBase(ROOT)]);
    }

    /** POST /ajax/settings/alert-params : reklamacja bez decyzji (alert, alert pilny), dzień miesiąca na koszty poprzedniego miesiąca. */
    public function saveAlertParams(): JsonResponse
    {
        $data = $this->getJson(Request::createFromGlobals());
        $values = [];
        $errors = [];
        foreach (array_keys(self::ALERT_PARAMS) as $code) {
            $raw = trim((string) ($data[$code] ?? ''));
            if (($error = ParamRules::rangeError($code, $raw)) !== null) { $errors[$code] = $error; continue; }
            $values[$code] = (string) (int) $raw;
        }
        // Mêmes règles que l'API et l'import Excel (App\Domain\Params\ParamRules).
        if (!$errors) foreach (ParamRules::orderErrors($values, fn($c) => $this->params->get($c, '')) as $code => [, $message]) $errors[$code] = $message;
        if ($errors) return $this->json(['ok' => false, 'errors' => $errors, 'error' => implode(' ', $errors)], 422);
        $userId = user('id');
        foreach ($values as $code => $value) $this->params->setValue($code, $value, $userId ? (int) $userId : null);
        return $this->json(['ok' => true, 'values' => $values, 'message' => 'Zapisano alerty i terminy.']);
    }

    /** POST /ajax/settings/stock-params : tygodnie sprzedaży liczone i tygodnie zapasu min. / maks. */
    public function saveStockParams(): JsonResponse
    {
        $data = $this->getJson(Request::createFromGlobals());
        $values = [];
        $errors = [];
        foreach (array_keys(self::STOCK_PARAMS) as $code) {
            $int = ParamRules::RANGES[$code][2];
            $raw = str_replace(',', '.', trim((string) ($data[$code] ?? '')));
            if (($error = ParamRules::rangeError($code, $raw)) !== null) { $errors[$code] = $error; continue; }
            $values[$code] = $int ? (string) (int) $raw : rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.');
        }
        if (!$errors) foreach (ParamRules::orderErrors($values, fn($c) => $this->params->get($c, '')) as $code => [, $message]) $errors[$code] = $message;
        if ($errors) return $this->json(['ok' => false, 'errors' => $errors, 'error' => implode(' ', $errors)], 422);
        $userId = user('id');
        foreach ($values as $code => $value) $this->params->setValue($code, $value, $userId ? (int) $userId : null);
        return $this->json(['ok' => true, 'values' => $values, 'message' => 'Zapisano parametry zapasów.']);
    }
}
