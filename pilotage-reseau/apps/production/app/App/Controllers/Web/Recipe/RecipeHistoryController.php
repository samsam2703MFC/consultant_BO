<?php

namespace App\Controllers\Web\Recipe;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Recipe\RecipeHistoryViewService;

/**
 * Historia receptury : wersje « obowiązuje od » i koszt surowców miesiąc po miesiącu (wszystkie typy receptur).
 */
class RecipeHistoryController extends BaseController
{
    public function __construct(private RecipeHistoryViewService $history) {}

    /** GET /recipes/{id}/history */
    public function show($id): void
    {
        try {
            $data = $this->history->build((int) $id);
        } catch (CustomException $e) {
            http_response_code(404);
            echo 'Nie znaleziono receptury.';
            return;
        }
        $this->view('recipe/history', $data);
    }
}
