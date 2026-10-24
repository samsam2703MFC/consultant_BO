<?php

namespace App\Controllers\Web\Alias;

use App\Core\Route;
use App\Core\Web\AbstractAliasController;
use App\Core\Web\BaseController;
use App\Services\Generic\GenericAliasService;
use App\Services\Settings\LanguageService;
use Symfony\Component\HttpFoundation\Request;

class MaterialController extends AbstractAliasController
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
                    'alias_table' => 'raw_material_alias',
                    'fk_col'      => 'material_id',
                    'base_table'  => 'raw_material',
                    'base_field'  => 'name',
                    'value_type'  => 'short',
                ]
            ],
        ];
    }


    #[Route('GET', '/materials/aliases')]
    public function aliases()
    {
        $data = ['languages' => $this->languageService->getActive()];
        if(isset($_GET['lang_code'])){
            $data['langCode'] = $_GET['lang_code'];
            $data['aliasType'] = $_GET['alias_type'] ?? "default";
            $data['aliases'] = $this->listInternal('name');
        }

        $this->view("alias/material", $data);
    }

    #[Route('PATCH', '/ajax/materials/{id:\d+}/aliases')]
    public function updateAliasByElementId($id)
    {
        $request = Request::createFromGlobals();
        $data = $this->getJson($request);
        $resp = $this->upsertInternal($id, $data, 'name');

        return $this->json($resp);
    }
}