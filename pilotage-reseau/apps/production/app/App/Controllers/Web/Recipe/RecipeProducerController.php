<?php
namespace App\Controllers\Web\Recipe;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Workshop\Product\CompositionProducerService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Półprodukt / kompozycja : grupa, która go przygotowuje, i wyprzedzenie zamówienia (kalkulacja, formularz receptury,
 * kreator produktu → tablet). Karta półproduktu grupy robiącej tworzona albo przenoszona (CompositionProducerService).
 */
class RecipeProducerController extends BaseController
{
    use JsonErrors;

    public function __construct(private CompositionProducerService $compositions) {}

    public function save($id)
    {
        try {
            $id = (int) $id;
            $input = $this->getJson(Request::createFromGlobals());
            $groupId = isset($input['group_id']) && $input['group_id'] !== '' && $input['group_id'] !== null ? (int) $input['group_id'] : null;
            // lead_days absent = inchangé ; '' / null = sans délai.
            $lead = !array_key_exists('lead_days', $input) ? false : ($input['lead_days'] === '' || $input['lead_days'] === null ? null : (int) $input['lead_days']);
            $saved = $this->compositions->set($id, $groupId, user('id') ? (int) user('id') : null, $lead);
            return $this->json(['message' => $groupId === null ? 'Usunięto grupę.' : 'Zapisano grupę.', 'data' => $saved]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
