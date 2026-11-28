<?php
declare(strict_types=1);

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Ai\MorningRoutineService;
use Symfony\Component\HttpFoundation\Request;

/**
 * [387] Rutyny poranne AI : Ustawienia › Asystent AI › Rutyny poranne (administrator : stan, zapis, « Uruchom teraz »,
 * podgląd ładunku dnia bez wywołania API) i decyzja o propozycji z kroku 2 asystenta (« Odrzuć » — prawo do strony
 * asystenta). App kontroluje tylko strony : każde wywołanie sprawdza uprawnienie w MorningRoutineService.
 */
final class AiRoutinesController extends BaseController
{
    use JsonErrors;

    public function __construct(private MorningRoutineService $routines)
    {
    }

    /** GET — stan rutyn (administrator). */
    public function state()
    {
        return $this->run(fn() => $this->routines->settingsState($this->userId()));
    }

    /** PUT {enabled, model, budget_pln, usd_pln, assistants: {kod: {on, time}}} — zapis (administrator). */
    public function save()
    {
        return $this->run(fn() => $this->routines->saveSettings($this->getJson(Request::createFromGlobals()), $this->userId()));
    }

    /** POST — « Uruchom teraz » : jeden przebieg (prawdziwe wywołanie API, tylko gdy włączone). */
    public function runNow($code)
    {
        return $this->run(fn() => $this->routines->runNow((string) $code, $this->userId()));
    }

    /** GET — ładunek dnia, jak poszedłby do AI (zanonimizowany), bez wywołania API. */
    public function preview($code)
    {
        return $this->run(fn() => $this->routines->previewFor((string) $code, $this->userId()));
    }

    /** POST {status: rejected|accepted|edited} — decyzja człowieka o propozycji (prawo do strony asystenta). */
    public function mark($id)
    {
        return $this->run(fn() => $this->routines->mark((int) $id, (string) ($this->getJson(Request::createFromGlobals())['status'] ?? ''), $this->userId()));
    }

    private function run(callable $action)
    {
        try {
            return $this->json(['data' => $action()]);
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
