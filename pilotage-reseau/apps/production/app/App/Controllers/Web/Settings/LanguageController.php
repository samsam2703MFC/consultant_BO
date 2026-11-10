<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Services\Settings\LanguageService;
use Symfony\Component\HttpFoundation\Request;

class LanguageController extends BaseController
{
    public function __construct(private LanguageService $languageService)
    {
    }

    public function index()
    {
        $data['languages'] = $this->languageService->getAll();
        $this->view('settings/languages', $data);
    }

    public function ajaxCreate()
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        $res = $this->handleAction(fn() => $this->languageService->create($data));
        return $this->json($res, $res['code'] ?? 200);
    }

    public function ajaxUpdate($id)
    {
        $request = Request::createFromGlobals();
        $data    = $this->getJson($request);

        $res = $this->handleAction(fn() => $this->languageService->update((int)$id, $data));
        return $this->json($res, $res['code'] ?? 200);
    }

    public function ajaxDelete($id)
    {
        $res = $this->handleAction(fn() => $this->languageService->delete((int)$id));
        return $this->json($res, $res['code'] ?? 200);
    }
}
