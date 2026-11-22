<?php
namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\StructureService;
use Symfony\Component\HttpFoundation\Request;

/** [368] Administracja › Struktura i obowiązki : organigram (przeciągnij i upuść) i matryca obowiązków. */
class StructureController extends BaseController
{
    use JsonErrors;

    private const MAX_DOCX = 10 * 1024 * 1024;

    public function __construct(private StructureService $service)
    {
    }

    public function index()
    {
        $tab = ($_GET['tab'] ?? '') === 'matrix' ? 'matrix' : 'chart';
        $this->view('organization/structure', ['tab' => $tab]);
    }

    public function data()
    {
        try {
            return $this->json(['chart' => $this->service->chart(), 'matrix' => $this->service->matrix()]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function move($id)
    {
        return $this->act(fn() => $this->service->move((int) $id, $this->body(), $this->userId()), 'Zapisano przełożonego.');
    }

    public function createDuty()
    {
        return $this->act(fn() => ['id' => $this->service->saveDuty(null, $this->body(), $this->userId())], 'Dodano obowiązek.');
    }

    public function updateDuty($id)
    {
        return $this->act(fn() => $this->service->saveDuty((int) $id, $this->body(), $this->userId()), 'Zapisano.');
    }

    public function deleteDuty($id)
    {
        return $this->act(fn() => $this->service->deleteDuty((int) $id, $this->userId()), 'Usunięto obowiązek.');
    }

    public function addTask($id)
    {
        return $this->act(fn() => ['task_id' => $this->service->addTask((int) $id)], 'Dodano zadanie stanowiska.');
    }

    public function importSheets()
    {
        return $this->act(fn() => ['count' => $this->service->importSheets($this->userId())], 'Wczytano obowiązki z kart stanowisk.');
    }

    public function parseDocx()
    {
        try {
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new CustomException('Wybierz plik Word (.docx).', 'errors', 422);
            if ((int) $f['size'] > self::MAX_DOCX) throw new CustomException('Plik jest za duży (maks. 10 MB).', 'errors', 422);
            if (!preg_match('/\.docx$/i', (string) $f['name'])) throw new CustomException('Tylko pliki Word .docx.', 'errors', 422);
            return $this->json($this->service->parseDocx($f['tmp_name'], basename((string) $f['name'])));
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function importDocx()
    {
        return $this->act(fn() => ['count' => $this->service->importItems($this->body(), $this->userId())], 'Zapisano obowiązki z pliku.');
    }

    private function body(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    private function act(callable $fn, string $message)
    {
        try {
            $r = $fn();
            return $this->json(['message' => $message] + (is_array($r) ? $r : []));
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
