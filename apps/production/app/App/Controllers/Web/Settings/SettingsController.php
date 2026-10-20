<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Repositories\Workshop\ParamRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Ustawienia › Pozostałe : operacje na recepturach i parametry ogólne (zapasy produktów na magazynie). */
class SettingsController extends BaseController
{
    /** Parametry zapasów (tfb_prod_param) : kod => [min, maks, liczba całkowita]. */
    private const STOCK_PARAMS = [
        'stock.suggest_weeks' => [1, 52, true],
        'stock.suggest_min_weeks' => [0, 26, false],
        'stock.suggest_max_weeks' => [0.5, 52, false],
    ];

    public function __construct(private ParamRepository $params)
    {
    }

    public function index()
    {
        $stock = [];
        foreach (array_keys(self::STOCK_PARAMS) as $code) $stock[$code] = $this->params->get($code, ['stock.suggest_weeks' => '6', 'stock.suggest_min_weeks' => '1', 'stock.suggest_max_weeks' => '3'][$code]);
        $this->view('settings/settings', ['stock_params' => $stock]);
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
