<?php
namespace App\Controllers\Web\Recipe;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\RecipeProducerRepository;
use Symfony\Component\HttpFoundation\Request;

/** Półprodukt / kompozycja : grupa, która go przygotowuje (kalkulacja, formularz receptury → tablet). */
class RecipeProducerController extends BaseController
{
    use JsonErrors;

    public function __construct(private RecipeProducerRepository $producers) {}

    public function save($id)
    {
        try {
            $id = (int) $id;
            if (!$this->producers->isComponent($id)) throw new CustomException('Grupę ustawia się dla półproduktu albo kompozycji.', 'errors', 422);
            $input = $this->getJson(Request::createFromGlobals());
            $groupId = isset($input['group_id']) && $input['group_id'] !== '' && $input['group_id'] !== null ? (int) $input['group_id'] : null;
            if ($groupId !== null && !$this->producers->groupExists($groupId)) throw new CustomException('Nieznana grupa.', 'errors', 422);
            $this->producers->set($id, $groupId, user('id') ? (int) user('id') : null);
            return $this->json(['message' => $groupId === null ? 'Usunięto grupę.' : 'Zapisano grupę.', 'data' => $this->producers->forRecipes([$id])[$id] ?? null]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
