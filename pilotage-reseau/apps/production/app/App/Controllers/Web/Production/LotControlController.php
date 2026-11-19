<?php
namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Workshop\Production\InputCheckService;
use App\Services\Workshop\Production\MaterialLotService;
use App\Services\Workshop\Production\SampleService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [352] Partie i identyfikowalność (makiety 68 i 68 B) : zakładki « Partie surowców » (rejestr z faktur KSeF i przyjęć,
 * przełączniki), « Kontrola wejść » (partie produkcji dnia : czy wszystko uzupełnione) i « Próbki » (asystent zadania :
 * wybór, arkusz A4 z kodami QR, skan przy odłożeniu).
 */
class LotControlController extends BaseController
{
    use JsonErrors;

    public function __construct(private MaterialLotService $lots, private InputCheckService $check, private SampleService $samples)
    {
    }

    /** GET /workshop/traceability/lots?q=&active=1 */
    public function materialLots()
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
        $active = ($_GET['active'] ?? '') === '1';
        $this->view('production/lot_control', ['tab' => 'lots', 'q' => $q, 'active_only' => $active, 'register' => $this->lots->register($q, $active)]);
    }

    /** GET /workshop/traceability/check?date= */
    public function inputCheck()
    {
        $day = $this->day();
        $this->view('production/lot_control', ['tab' => 'check', 'date' => $day, 'check' => $this->check->day($day)]);
    }

    /** GET /workshop/traceability/samples?date= */
    public function samples()
    {
        $day = $this->day();
        $this->view('production/lot_control', ['tab' => 'samples', 'date' => $day, 'page' => $this->samples->page($day),
            'frequencies' => SampleService::FREQUENCIES]);
    }

    /** GET /workshop/traceability/samples/pdf?date= — arkusz A4 (QR = adres próbki w ERP). */
    public function samplesPdf()
    {
        try {
            $file = $this->samples->pdf($this->day(), ROOT);
        } catch (CustomException $e) {
            return new Response($e->getMessage(), (int) $e->getCode() ?: 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response($file['file_string'], 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"', 'Content-Length' => strlen($file['file_string'])]);
    }

    /** GET /workshop/traceability/samples/s/{code} — dawne kody QR (pierwsze arkusze) : krótki adres /q/KOD. */
    public function sampleScan($code)
    {
        header('Location: ' . ROOT . '/q/' . strtoupper((string) $code), true, 302);
        exit;
    }

    /** [354] GET /q/{code} — karta próbki z kodu QR : bez logowania, bez menu (szybko na telefonie). */
    public function sampleQr($code)
    {
        $this->view('production/sample_qr', ['card' => $this->samples->card((string) $code), 'logged' => (bool) user('id'),
            'done' => ($_GET['ok'] ?? '') === '1']);
    }

    /** [354] POST /q/{code} — « Próbka odłożona » z telefonu (z sesją : kto ; bez : telefon). */
    public function sampleQrStore($code)
    {
        try {
            $this->samples->store((string) $code, $this->userId(), isset($_POST['location']) ? (string) $_POST['location'] : null);
        } catch (CustomException $e) {
            return new Response($e->getMessage(), (int) $e->getCode() ?: 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        header('Location: ' . ROOT . '/q/' . strtoupper((string) $code) . '?ok=1', true, 303);
        exit;
    }

    /** PUT /ajax/production/material-lots/{id} {lot_number?, best_before?, active?} */
    public function updateLot($id)
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            if (array_key_exists('lot_number', $in)) $this->lots->setNumber((int) $id, $in['lot_number'] !== null ? (string) $in['lot_number'] : null,
                isset($in['best_before']) && $in['best_before'] !== '' ? (string) $in['best_before'] : null, $this->userId());
            if (array_key_exists('active', $in)) $this->lots->setActive((int) $id, (bool) $in['active'], $this->userId());
            return $this->json(['message' => 'Zapisano.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** PUT /ajax/production/material-lots/material/{id} {tracked} */
    public function trackMaterial($id)
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $this->lots->setTracked((int) $id, (bool) ($in['tracked'] ?? true), $this->userId());
            return $this->json(['message' => !empty($in['tracked']) ? 'Surowiec śledzony : partia wymagana na tablecie.' : 'Surowiec nieśledzony : bez listy partii na tablecie.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** GET /ajax/production/input-check/{id} — składniki partii i ich partie. */
    public function batch($id)
    {
        try {
            return $this->json(['data' => $this->check->batch((int) $id)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/production/samples {date, outputs[]} — krok 1. */
    public function createSamples()
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $n = $this->samples->create((string) ($in['date'] ?? ''), (array) ($in['outputs'] ?? []), $this->userId());
            return $this->json(['message' => $n . ' ' . ($n === 1 ? 'próbka utworzona' : 'próbek utworzonych') . ' — wydrukuj arkusz A4.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** DELETE /ajax/production/samples/{id} — próbka nieodłożona (pomyłka). */
    public function removeSample($id)
    {
        try {
            $this->samples->remove((int) $id);
            return $this->json(['message' => 'Próbka usunięta.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/production/samples/store {code, location?} — krok 3 (skan QR albo przycisk). */
    public function storeSample()
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $s = $this->samples->store((string) ($in['code'] ?? ''), $this->userId(), isset($in['location']) ? (string) $in['location'] : null);
            return $this->json(['message' => ($s['already'] ? 'Już odłożona : ' : 'Odłożona : ') . $s['code'], 'data' => ['id' => (int) $s['id'], 'code' => $s['code']]]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/production/samples/done {date} — zadanie zaliczone. */
    public function finishSamples()
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $p = $this->samples->finish((string) ($in['date'] ?? ''), $this->userId());
            return $this->json(['message' => 'Próbki dnia : ' . $p['done']['summary'] . ' — zadanie zaliczone.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** PUT /ajax/production/sample-rules/{category} {frequency|null} */
    public function sampleRule($category)
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $f = isset($in['frequency']) && $in['frequency'] !== '' ? (string) $in['frequency'] : null;
            $this->samples->setRule((int) $category, $f, $this->userId());
            return $this->json(['message' => 'Reguła zapisana.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function day(): string
    {
        $d = (string) ($_GET['date'] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : date('Y-m-d');
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
