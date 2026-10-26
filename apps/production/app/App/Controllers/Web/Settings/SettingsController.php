<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Repositories\Workshop\ParamRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Ustawienia › Pozostałe : operacje na recepturach i parametry ogólne (zapasy produktów na magazynie, alerty i terminy). */
class SettingsController extends BaseController
{
    /** Parametry zapasów (n_prod_param) : kod => [min, maks, liczba całkowita]. */
    private const STOCK_PARAMS = [
        'stock.suggest_weeks' => [1, 52, true],
        'stock.suggest_min_weeks' => [0, 26, false],
        'stock.suggest_max_weeks' => [0.5, 52, false],
    ];

    /** Alerty i terminy w dzwonku (n_prod_param) : kod => [min, maks, domyślnie]. */
    private const ALERT_PARAMS = [
        'alerts.complaint_days' => [1, 60, '3'],
        'alerts.complaint_escalate_days' => [1, 90, '7'],
        'alerts.cost_due_day' => [1, 28, '10'],
        'shipping_issue.decision_days' => [1, 30, '3'],
        'shipping_issue.min_amount' => [0, 1000, '1'],
        'shipping_issue.lookback_days' => [7, 365, '45'],
    ];

    public function __construct(private ParamRepository $params)
    {
    }

    public function index()
    {
        $stock = [];
        foreach (array_keys(self::STOCK_PARAMS) as $code) $stock[$code] = $this->params->get($code, ['stock.suggest_weeks' => '6', 'stock.suggest_min_weeks' => '1', 'stock.suggest_max_weeks' => '3'][$code]);
        $alerts = [];
        foreach (self::ALERT_PARAMS as $code => [, , $default]) $alerts[$code] = $this->params->get($code, $default);
        $this->view('settings/settings', ['stock_params' => $stock, 'alert_params' => $alerts]);
    }

    /** POST /ajax/settings/alert-params : reklamacja bez decyzji (alert, alert pilny), dzień miesiąca na koszty poprzedniego miesiąca. */
    public function saveAlertParams(): JsonResponse
    {
        $data = $this->getJson(Request::createFromGlobals());
        $values = [];
        $errors = [];
        foreach (self::ALERT_PARAMS as $code => [$min, $max]) {
            $raw = trim((string) ($data[$code] ?? ''));
            if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
                $errors[$code] = "Liczba całkowita od {$min} do {$max}.";
                continue;
            }
            $values[$code] = (string) (int) $raw;
        }
        if (!$errors && (int) $values['alerts.complaint_escalate_days'] < (int) $values['alerts.complaint_days']) {
            $errors['alerts.complaint_escalate_days'] = 'Alert pilny nie może być wcześniej niż zwykły.';
        }
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
        foreach (self::STOCK_PARAMS as $code => [$min, $max, $int]) {
            $raw = str_replace(',', '.', trim((string) ($data[$code] ?? '')));
            if (!is_numeric($raw) || ($int && (string) (int) $raw !== $raw) || (float) $raw < $min || (float) $raw > $max) {
                $errors[$code] = "Wartość od {$min} do {$max}" . ($int ? ' (liczba całkowita)' : '') . '.';
                continue;
            }
            $values[$code] = $int ? (string) (int) $raw : rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.');
        }
        if (!$errors && (float) $values['stock.suggest_max_weeks'] < (float) $values['stock.suggest_min_weeks']) {
            $errors['stock.suggest_max_weeks'] = 'Zapas maksymalny nie może być mniejszy niż minimalny.';
        }
        if ($errors) return $this->json(['ok' => false, 'errors' => $errors, 'error' => implode(' ', $errors)], 422);
        $userId = user('id');
        foreach ($values as $code => $value) $this->params->setValue($code, $value, $userId ? (int) $userId : null);
        return $this->json(['ok' => true, 'values' => $values, 'message' => 'Zapisano parametry zapasów.']);
    }
}
