<?php
namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\DevCard\AiSettings;
use App\Services\DevCard\DevCardService;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Nowy produkt z filmu [196] : kreator karty technologicznej (link + prompt → asystent AI → karta .docx / PDF / tablet),
 * pliki karty i Ustawienia › Asystent AI (klucz Anthropic, model, prompt — administrator).
 */
class DevCardController extends BaseController
{
    public function __construct(private DevCardService $cards, private AiSettings $ai, private PageAccessService $access)
    {
    }

    public function page($id = null)
    {
        $data = $this->cards->page($this->userId(), $id !== null ? (int) $id : null);
        $ai = $data['ai'];
        // Kreator dostaje tylko stan asystenta (bez końcówki klucza) : włączony, model, prompt, czy administrator.
        $data['ai'] = ['configured' => $ai['configured'], 'model' => $ai['model'], 'model_label' => AiSettings::MODELS[$ai['model']] ?? $ai['model'],
            'prompt' => $ai['prompt'], 'is_admin' => $ai['is_admin']];
        $this->view('product/test_product/new', ['boot' => json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)]);
    }

    /** Dawny adres kreatora z filmu → jeden kreator /test-products/new. */
    public function legacy($id = null)
    {
        header('Location: ' . ROOT . '/test-products/new' . ($id !== null ? '/' . (int) $id : ''), true, 301);
        exit;
    }

    /** « Sam pomysł » : produkt testowy bez karty. */
    public function createProduct()
    {
        return $this->run(fn() => $this->cards->createProduct($this->input(), $this->userId()), 201);
    }

    public function create()
    {
        return $this->run(fn() => $this->cards->create($this->input(), $this->userId()), 201);
    }

    public function show($id)
    {
        return $this->run(fn() => $this->cards->state((int) $id));
    }

    public function read($id)
    {
        return $this->run(fn() => $this->cards->readLink((int) $id));
    }

    public function video($id)
    {
        try {
            $this->guard();
            $this->cards->streamVideo((int) $id);
            return '';
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    public function frames($id)
    {
        return $this->run(fn() => $this->cards->addFrames((int) $id, $this->input()));
    }

    public function generate($id)
    {
        return $this->run(fn() => $this->cards->generate((int) $id));
    }

    public function update($id)
    {
        return $this->run(fn() => $this->cards->update((int) $id, $this->input(), $this->userId()));
    }

    public function save($id)
    {
        return $this->run(fn() => $this->cards->save((int) $id, $this->userId(), filter_var($this->input()['tablet'] ?? true, FILTER_VALIDATE_BOOLEAN)));
    }

    public function delete($id)
    {
        return $this->run(function () use ($id) {
            $this->cards->delete((int) $id);
            return ['deleted' => (int) $id];
        });
    }

    /** Gotowa karta .docx (formularz multipart : plik + nazwa, grupa, sprzedaż, sezon). */
    public function import()
    {
        return $this->run(function () {
            $f = $_FILES['file'] ?? null;
            if ($f && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new CustomException('Plik .docx jest za duży dla serwera (limit ' . ini_get('upload_max_filesize') . ') — zmniejsz zdjęcia w Wordzie.', 'errors', 413, ['fields' => ['file' => 'Plik za duży.']]);
            }
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                throw new CustomException('Wybierz plik .docx.', 'errors', 422, ['fields' => ['file' => 'Wybierz plik .docx.']]);
            }
            $in = $_POST;
            $in['quarters'] = array_filter(explode(',', (string) ($_POST['quarters'] ?? '')));
            return $this->cards->importDocx($in, $f['tmp_name'], $this->userId());
        }, 201);
    }

    public function frame($id, $frameId)
    {
        if (!$this->allowed()) { http_response_code(403); return ''; }
        $f = $this->cards->frame((int) $id, (int) $frameId);
        if ($f === null) { http_response_code(404); return ''; }
        header('Content-Type: ' . $f['mime']);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        return $f['image'];
    }

    public function docx($id)
    {
        return $this->file(fn() => $this->cards->docx((int) $id), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function pdf($id)
    {
        return $this->file(fn() => $this->cards->pdf((int) $id), 'application/pdf', isset($_GET['inline']));
    }

    // --- Ustawienia › Asystent AI ------------------------------------------------------------------------

    public function settings()
    {
        $this->view('settings/ai', $this->ai->state($this->userId()));
    }

    public function settingsState()
    {
        return $this->run(fn() => $this->ai->state($this->userId()));
    }

    public function saveKey()
    {
        return $this->run(fn() => $this->ai->saveKey((string) ($this->input()['key'] ?? ''), $this->userId()));
    }

    public function testKey()
    {
        return $this->run(fn() => $this->ai->test($this->userId()));
    }

    public function saveModel()
    {
        return $this->run(fn() => $this->ai->saveModel((string) ($this->input()['model'] ?? ''), $this->userId()));
    }

    public function savePrompt()
    {
        return $this->run(fn() => $this->ai->savePrompt((string) ($this->input()['prompt'] ?? ''), $this->userId()));
    }

    // ---------------------------------------------------------------------------------------------------

    private function file(callable $make, string $type, bool $inline = false)
    {
        try {
            $this->guard();
            $f = $make();
            header('Content-Type: ' . $type);
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $f['name'] . '"');
            header('Content-Length: ' . strlen($f['bytes']));
            header('Cache-Control: private, no-store');
            return $f['bytes'];
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    private function run(callable $action, int $status = 200): JsonResponse
    {
        try {
            $this->guard();
            return $this->json(['data' => $action()], $status);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    private function fail(\Throwable $e): JsonResponse
    {
        if ($e instanceof CustomException) {
            $code = (int) $e->getCode();
            return $this->json(['message' => $e->getMessage(), 'fields' => $e->getErrors()['fields'] ?? null], $code >= 400 && $code < 600 ? $code : 422);
        }
        error_log('[dev-card] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return $this->json(['message' => 'Błąd serwera — operacja nie została wykonana.'], 500);
    }

    /** Ajax nie przechodzi przez kontrolę stron : kreator = strona « Produkty » (produkty w rozwoju). */
    private function guard(): void
    {
        if ($this->userId() === null) throw new CustomException('Zaloguj się ponownie.', 'errors', 401);
        if (!$this->allowed()) throw new CustomException('Brak dostępu do produktów w rozwoju.', 'errors', 403);
    }

    private function allowed(): bool
    {
        return $this->access->canSee($this->userId(), '/test-products/pipeline');
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
