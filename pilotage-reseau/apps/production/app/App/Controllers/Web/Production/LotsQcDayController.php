<?php
declare(strict_types=1);

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\LotsQcDayService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Partie i kontrola dnia » [377] : przegląd zamkniętych dni grup (partie, kontrola jakości, partie surowców),
 * do wyjaśnienia (wynik dla dnia grupy), zatwierdzenie. La page est sous /workshop/traceability (droit existant
 * « production » : partie, śledzenie, niezgodności — le même que la création de NC vers laquelle l'assistant renvoie) ;
 * les appels ajax vérifient le même droit (App ne contrôle que les pages). Lecture seule hors de n_lots_qc_check et
 * n_wizard_done ; aucune NC créée, aucun e-mail ni SMS.
 */
final class LotsQcDayController extends BaseController
{
    use JsonErrors;

    public const PATH = LotsQcDayService::PATH;

    public function __construct(private LotsQcDayService $lots, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('production/lots_qc_day', ['data' => $this->lots->data()]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->lots->data()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {work_date, id_group, result: completed|nc|accepted, note} — krok 2 : wynik dla dnia grupy. */
    public function result()
    {
        try {
            $this->guard();
            $data = $this->lots->saveResult($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie dnia zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->lots->finish($this->userId());
            return $this->json(['message' => 'Partie i kontrola dnia sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do produkcji (partie i identyfikowalność).', 'errors', 403);
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
