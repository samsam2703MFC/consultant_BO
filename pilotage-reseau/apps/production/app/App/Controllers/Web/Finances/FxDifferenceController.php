<?php
declare(strict_types=1);

namespace App\Controllers\Web\Finances;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Finances\Fx\BankStatementService;
use App\Services\Finances\Fx\FxDifferenceService;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [440] Różnice kursowe (Finanse) : wpłaty klientów w walucie, różnica par facture (kurs NBP / kurs ING), wyciągi ING
 * (PDF chiffré, saisie et correction à la main), dopasowanie « do potwierdzenia », zestawienie miesiąca PDF et CSV.
 * Droit propre « fx_differences » (page, appels ajax et fichiers : App ne contrôle que les pages, les appels vérifient le
 * même droit). Montants et wpłaty des clients : profil Finanse et CEO seulement (PageAccessService::seesOrderValues,
 * [312]) — sinon la page ne montre que le cadre et toute action est refusée. Aucun e-mail.
 */
final class FxDifferenceController extends BaseController
{
    use JsonErrors;

    public const PATH = FxDifferenceService::PATH;

    public function __construct(private FxDifferenceService $fx, private BankStatementService $statements, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('finances/fx_differences', ['data' => $this->fx->data($this->month(), $this->values(), null, $this->userId())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->fx->data($this->month(), $this->values(), null, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST multipart : file (PDF), account (EUR|PLN), period_from, period_to, month — wyciąg ING. */
    public function upload()
    {
        try {
            $this->guard(true);
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new CustomException('Wybierz plik PDF wyciągu.', 'errors', 422);
            if ((int) $f['size'] > BankStatementService::MAX_BYTES) throw new CustomException('Plik jest za duży (najwyżej 15 MB).', 'errors', 413);
            $r = $this->statements->upload((string) file_get_contents($f['tmp_name']), basename((string) $f['name']), $_POST, $this->userId());
            $month = is_string($_POST['month'] ?? null) && $_POST['month'] !== '' ? $_POST['month'] : $this->month();
            return $this->json(['message' => $r['message'], 'result' => $r, 'data' => $this->fx->data($month, true, null, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** GET — plik wyciągu (odszyfrowany). */
    public function statementFile($id)
    {
        try {
            $this->guard(true);
            $f = $this->statements->file((int) $id);
            $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['filename']) ?: 'wyciag.pdf';
            return new Response($f['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Length' => (string) strlen($f['content']),
                'Content-Disposition' => 'inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($f['filename'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — wyciąg usunięty avec ses wpłaty. */
    public function deleteStatement($id)
    {
        return $this->act(fn() => $this->statements->deleteStatement((int) $id), 'Wyciąg usunięty.');
    }

    /** GET — wpłata pour le formulaire de correction. */
    public function line($id)
    {
        try {
            $this->guard(true);
            return $this->json(['line' => $this->statements->lineView((int) $id)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {account, id_statement, booked_on, amount, currency, amount_pln, bank_rate, id_customer, payer, title} — dodaj / popraw. */
    public function saveLine($id = null)
    {
        return $this->act(fn(array $in) => $this->statements->saveLine($id !== null ? (int) $id : null, $in, $this->userId()), $id !== null ? 'Wpłata poprawiona.' : 'Wpłata dodana.');
    }

    /** POST {docs: ["wdt:12", "proforma:5"]} — « Zapisz » d'une wpłata do potwierdzenia. */
    public function allocate($id)
    {
        return $this->act(fn(array $in) => $this->statements->allocate((int) $id, (array) ($in['docs'] ?? []), $this->userId()), 'Wpłata przypisana.');
    }

    /** POST {ignore: true|false} — « Nie dotyczy » ou retour au dopasowanie. */
    public function ignore($id)
    {
        return $this->act(fn(array $in) => $this->statements->ignore((int) $id, (bool) ($in['ignore'] ?? true), $this->userId()), 'Zapisano.');
    }

    public function deleteLine($id)
    {
        return $this->act(fn() => $this->statements->deleteLine((int) $id), 'Wpłata usunięta.');
    }

    /** GET ?month=RRRR-MM — zestawienie miesiąca PDF. */
    public function pdf()
    {
        return $this->file(fn() => $this->fx->pdf($this->month()), 'application/pdf');
    }

    /** GET ?month=RRRR-MM — zestawienie miesiąca CSV. */
    public function csv()
    {
        return $this->file(fn() => $this->fx->csv($this->month()), 'text/csv; charset=UTF-8');
    }

    // --- pomocnicze -------------------------------------------------------------------------------------------------

    private function act(callable $fn, string $message)
    {
        try {
            $this->guard(true);
            $in = $this->getJson(Request::createFromGlobals());
            $fn($in);
            $month = is_string($in['month'] ?? null) && $in['month'] !== '' ? $in['month'] : $this->month();
            return $this->json(['message' => $message, 'data' => $this->fx->data($month, true, null, $this->userId())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function file(callable $fn, string $type)
    {
        try {
            $this->guard(true);
            $f = $fn();
            return new Response($f['content'], 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="' . $f['name'] . '"', 'Content-Length' => (string) strlen($f['content'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Droit « fx_differences » ; $values : aussi les montants (profil Finanse / CEO). */
    private function guard(bool $values = false): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do « Różnice kursowe ».', 'errors', 403);
        if ($values && !$this->values()) throw new CustomException('Wpłaty i kwoty klientów : profil Finanse albo CEO (wybierz profil pod awatarem).', 'errors', 403);
    }

    /** [312] Kwoty : profil Finanse i CEO. */
    private function values(): bool
    {
        return $this->access->seesOrderValues($this->userId());
    }

    private function month(): ?string
    {
        $m = $_GET['month'] ?? null;
        return is_string($m) && $m !== '' ? $m : null;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
