<?php

namespace App\Controllers\Web\Alias;

use App\Core\Route;
use App\Core\Web\AbstractAliasController;
use App\Services\Generic\GenericAliasService;
use App\Services\Settings\LanguageService;
use Symfony\Component\HttpFoundation\Request;

class AllergenController extends AbstractAliasController
{
    public function __construct(GenericAliasService $svc, private LanguageService $languageService)
    {
        parent::__construct($svc);
    }

    protected function aliasConfig(): array
    {
        return [
            'fields' => [
                'name' => [
                    'alias_table' => 'allergen_alias',
                    'fk_col'      => 'allergen_id',
                    'base_table'  => 'allergen',
                    'base_field'  => 'name',
                    'value_type'  => 'short',
                ],
            ],
        ];
    }

    #[Route('GET', '/allergens/aliases')]
    public function aliases()
    {
        $data = ['languages' => $this->languageService->getActive()];

        // Widok działa analogicznie jak dla materiałów: lista ładuje się po wyborze języka.
        if (isset($_GET['lang_code'])) {
            $data['langCode'] = $_GET['lang_code'];
            $data['aliasType'] = $_GET['alias_type'] ?? 'default';
            $data['aliases'] = $this->listInternal('name');
        }

        $this->view('alias/allergen', $data);
    }

    #[Route('PATCH', '/ajax/allergens/{id:\d+}/aliases')]
    public function updateAliasByElementId($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);
        $resp = $this->upsertInternal((int)$id, $data, 'name');

        return $this->json($resp);
    }
}