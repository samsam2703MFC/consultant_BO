<?php
declare(strict_types=1);

namespace App\Controllers\Web\Integration;

use App\Core\Web\BaseController;

/** Routes reserved for document archives whose persistent history will be added later. */
final class DocumentArchiveController extends BaseController
{
    public function future(string $type): void
    {
        $archives = [
            'production-lists' => ['title' => 'Archiwum list produkcyjnych', 'description' => 'Historia będzie dostępna po wdrożeniu archiwizacji list produkcyjnych.'],
            'requirement-lists' => ['title' => 'Archiwum list zapotrzebowania', 'description' => 'Historia będzie dostępna po wdrożeniu archiwizacji list zapotrzebowania materiałowego.'],
            'complaint-production-lists' => ['title' => 'Archiwum list produkcyjnych zwrotów', 'description' => 'Historia będzie dostępna po wdrożeniu archiwizacji list produkcyjnych dla zwrotów.'],
            'complaint-picking-lists' => ['title' => 'Archiwum list kompletacyjnych zwrotów', 'description' => 'Historia będzie dostępna po wdrożeniu archiwizacji list kompletacyjnych dla zwrotów.'],
        ];
        if (!isset($archives[$type])) {
            http_response_code(404);
            $this->view('404');
            return;
        }
        $this->view('integration/document_archive/future', $archives[$type]);
    }
}
