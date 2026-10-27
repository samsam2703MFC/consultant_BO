<?php

namespace App\Controllers\Web\Search;

use App\Core\Web\BaseController;
use App\Services\Search\GlobalSearchService;

/** Wyszukiwarka z górnego paska. */
class SearchController extends BaseController
{
    public function __construct(private GlobalSearchService $search)
    {
    }

    /** GET /ajax/search?q= */
    public function ajaxSearch()
    {
        $q = $this->search->normalize((string) ($_GET['q'] ?? ''));
        if ($q === null) {
            return $this->json(['message' => 'Wpisz co najmniej ' . GlobalSearchService::MIN_LENGTH . ' znaki.', 'groups' => []], 422);
        }

        try {
            return $this->json($this->search->search($q, user('id') ? (int) user('id') : null));
        } catch (\Throwable $e) {
            error_log('[search] failed: ' . $e->getMessage());
            return $this->json(['message' => 'Wyszukiwanie chwilowo nie działa.', 'groups' => []], 500);
        }
    }
}
