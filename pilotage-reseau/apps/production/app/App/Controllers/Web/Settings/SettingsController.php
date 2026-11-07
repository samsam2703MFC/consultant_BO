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
    private const STOCK_PARAMS = ['stock.suggest_weeks' => '6', 'stock.suggest_min_weeks' => '1', 'stock.suggest_max_weeks' => '3',
        // Prognoza zapasu [216] : bezpieczeństwo ponad wyjazd (%), miejsca paletowe zamrażarki (0 = nie podano).
        'stock.plan_safety_pct' => '25', 'stock.freezer_pallet_places' => '0'];

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

    /** Ceny nowych produktów [198] (n_prod_param) : kod => domyślnie. */
    private const NEW_PRODUCT_PARAMS = ['new_product.factory_coefs' => '2.5,3,4', 'new_product.factory_coef' => '3', 'new_product.store_mults' => '3,4,5',
        'new_product.store_mult' => '4', 'new_product.store_round_eur' => '0.1'];

    public function __construct(private ParamRepository $params, private \App\Core\Api\Workshop\WorkshopConfig $config,
        private \App\Services\Organization\PageAccessService $access)
    {
    }

    public function index()
    {
        $stock = [];
        foreach (self::STOCK_PARAMS as $code => $default) $stock[$code] = $this->params->get($code, $default);
        $alerts = [];
        foreach (self::ALERT_PARAMS as $code => $default) $alerts[$code] = $this->params->get($code, $default);
        $newProduct = [];
        foreach (self::NEW_PRODUCT_PARAMS as $code => $default) $newProduct[$code] = $this->params->get($code, $default);
        $this->view('settings/settings', ['stock_params' => $stock, 'alert_params' => $alerts, 'new_product_params' => $newProduct, 'api_base' => $this->config->webApiBase(ROOT)]);
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

    /**
     * POST /ajax/settings/new-product-params [198] : współczynniki ceny fabrycznej (cena fabryczna = koszt surowców ×
     * współczynnik) i mnożniki ceny w sklepie (cena w sklepie = cena fabryczna × mnożnik) — listy do wyboru w kreatorze,
     * wartości domyślne (z listy) i zaokrąglenie ceny końcowej.
     */
    public function saveNewProductParams(): JsonResponse
    {
        $userId = user('id') ? (int) user('id') : null;
        if (!$this->access->canSee($userId, '/other-settings')) return $this->json(['ok' => false, 'error' => 'Brak dostępu do ustawień.'], 403);
        $data = $this->getJson(Request::createFromGlobals());
        $values = [];
        $errors = [];
        foreach (['new_product.factory_coefs' => 'Współczynniki', 'new_product.store_mults' => 'Mnożniki'] as $code => $label) {
            $list = \App\Services\DevCard\DevCardCostService::parseList((string) ($data[$code] ?? ''));
            if ($list === null) $errors[$code] = $label . ' : od 1 do 6 liczb od 1 do 10, np. 2,5 ; 3 ; 4.';
            else $values[$code] = implode(',', array_map(fn($v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'), $list));
        }
        foreach (['new_product.factory_coef', 'new_product.store_mult', 'new_product.store_round_eur'] as $code) {
            $raw = str_replace(',', '.', trim((string) ($data[$code] ?? '')));
            if (($error = ParamRules::rangeError($code, $raw)) !== null) { $errors[$code] = $error; continue; }
            $values[$code] = rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.');
        }
        foreach (['new_product.factory_coef' => 'new_product.factory_coefs', 'new_product.store_mult' => 'new_product.store_mults'] as $code => $listCode) {
            if (isset($values[$code], $values[$listCode]) && !in_array((float) $values[$code], array_map('floatval', explode(',', $values[$listCode])), true)) {
                $errors[$code] = 'Wartość domyślna musi być na liście.';
            }
        }
        if ($errors) return $this->json(['ok' => false, 'errors' => $errors, 'error' => implode(' ', $errors)], 422);
        foreach ($values as $code => $value) $this->params->setValue($code, $value, $userId);
        return $this->json(['ok' => true, 'values' => $values, 'message' => 'Zapisano ceny nowych produktów.']);
    }
}
