<?php
declare(strict_types=1);

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Workshop\Execution\HalaService;
use Symfony\Component\HttpFoundation\Request;

/**
 * [409] Produkcja › Tablet hali : kroki i strefy sprzątania. Par groupe : ścieżka « Mój dzień » oui / non et ses étapes
 * (Co robię, Worki, Schładzanie, Sprzątanie, Pakuję) ; strefy sprzątania (ajout, nom PL / FR, icône, retrait). Droit
 * propre « hala » (page et appels ajax), donné par la migration 249 à Jakość et au Kierownik produkcji seulement.
 * N'écrit que n_hala_group et n_hala_clean_zone ; rien n'est envoyé.
 */
final class HalaSettingsController extends BaseController
{
    use JsonErrors;

    public const PATH = '/production/hala';

    public function __construct(private HalaService $hala, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('production/hala_settings', ['data' => $this->hala->settingsData()]);
    }

    public function data()
    {
        return $this->run(fn() => ['data' => $this->hala->settingsData()]);
    }

    /** POST {group_id, guided, make, bags, chill, clean, pack} */
    public function group()
    {
        return $this->run(fn() => ['message' => 'Zapisano kroki grupy.', 'data' => $this->hala->saveGroup($this->input(), $this->userId())]);
    }

    /** POST {id?, group_id, name_pl, name_fr?, icon, active?} */
    public function zone()
    {
        return $this->run(fn() => ['message' => 'Zapisano strefę.', 'data' => $this->hala->saveZone($this->input(), $this->userId())]);
    }

    /** Droit vérifié, puis réponse JSON (erreurs : JsonErrors). */
    private function run(callable $f)
    {
        try {
            if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do ustawień tabletu hali.', 'errors', 403);
            return $this->json($f());
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
