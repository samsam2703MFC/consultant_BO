<?php
namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Workshop\Production\SopService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [353] Procedury SOP (makiety 69 i 69 B). /sop : lista, asystent tworzenia i zatwierdzenie (prawo « sop » — Kierownik
 * produkcji, Jakość). /procedure : procedura asystowana (podgląd, wykonanie krok po kroku, PDF, zdjęcia) — dla każdego
 * zalogowanego (przycisk « Procedura » na stronach i asystentach, do których SOP jest przypięta).
 */
class SopController extends BaseController
{
    use JsonErrors;

    public function __construct(private SopService $sop)
    {
    }

    /** GET /sop?tab=list|review|runs|missing */
    public function index()
    {
        $tab = in_array($_GET['tab'] ?? '', ['review', 'runs', 'missing'], true) ? $_GET['tab'] : 'list';
        $list = $this->sop->list();
        $this->view('sop/index', ['tab' => $tab, 'list' => $list, 'page_names' => $this->pages(), 'runs' => $tab === 'runs' ? $this->sop->runs() : [],
            'missing' => $this->sop->suggestions(), 'review' => array_values(array_filter($list, fn($s) => $s['review_due'] || $s['status'] === 'draft' || $s['changed'] && $s['status'] === 'active'))]);
    }

    /** GET /sop/new?title=&link= (propozycja z asystenta bez procedury) */
    public function create()
    {
        $this->view('sop/editor', ['sop' => null, 'prefixes' => SopService::PREFIXES, 'pages' => $this->pages(),
            'preset' => ['title' => mb_substr((string) ($_GET['title'] ?? ''), 0, 200), 'link' => SopService::path((string) ($_GET['link'] ?? ''))]]);
    }

    /** GET /sop/{id}/edit */
    public function edit($id)
    {
        $this->view('sop/editor', ['sop' => $this->sop->get((int) $id), 'prefixes' => SopService::PREFIXES, 'pages' => $this->pages(), 'preset' => null]);
    }

    /** GET /procedure/{id}?from=&embed=1 — procedura asystowana (krok po kroku). */
    public function show($id)
    {
        try {
            $s = $this->sop->get((int) $id);
        } catch (CustomException $e) {
            return new Response($e->getMessage(), 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $this->view('sop/run', ['sop' => $s, 'from' => SopService::path((string) ($_GET['from'] ?? '')), 'embed' => ($_GET['embed'] ?? '') === '1',
            'runs' => $this->sop->runs((int) $id, 10)]);
    }

    /** GET /procedure/{id}/pdf */
    public function pdf($id)
    {
        try {
            $f = $this->sop->pdf((int) $id, ROOT);
        } catch (CustomException $e) {
            return new Response($e->getMessage(), (int) $e->getCode() ?: 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response($f['file_string'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $f['filename'] . '"', 'Content-Length' => strlen($f['file_string'])]);
    }

    /** GET /procedure/photo/{id} */
    public function photo($id)
    {
        $p = $this->sop->photo((int) $id);
        if ($p === null) return new Response('', 404);
        return new Response($p['bytes'], 200, ['Content-Type' => $p['mime'], 'Cache-Control' => 'private, max-age=86400', 'Content-Length' => strlen($p['bytes'])]);
    }

    /** POST /ajax/sop | PUT /ajax/sop/{id} — zapis asystenta. */
    public function save($id = null)
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $newId = $this->sop->save($id !== null ? (int) $id : null, $in, $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $this->sop->get($newId)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/sop/{id}/approve */
    public function approve($id)
    {
        try {
            $s = $this->sop->approve((int) $id, $this->userId());
            return $this->json(['message' => $s['code'] . ' v' . $s['version'] . ' zatwierdzona — obowiązuje.', 'data' => $s]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/sop/{id}/archive */
    public function archive($id)
    {
        try {
            $this->sop->archive((int) $id, $this->userId());
            return $this->json(['message' => 'Procedura w archiwum.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/sop/photos {photo: dataURL} — zdjęcie / zrzut ekranu etapu. */
    public function upload()
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $id = $this->sop->uploadPhoto($in['photo'] ?? null, $this->userId());
            return $this->json(['message' => 'Zdjęcie dodane.', 'data' => ['id' => $id, 'url' => ROOT . '/procedure/photo/' . $id]]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/procedure/{id}/runs — wykonanie procedury asystowanej. */
    public function run($id)
    {
        try {
            $in = $this->getJson(Request::createFromGlobals());
            $r = $this->sop->run((int) $id, $in, $this->userId());
            return $this->json(['message' => $r['result'] === 'ok' ? 'Procedura wykonana — zapisano.' : 'Zapisano z odchyleniem — niezgodność w rejestrze.', 'data' => $r]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Strony do przypięcia : asystenci zadań i ich ścieżki (krok « Powiązanie »). */
    private function pages(): array
    {
        $out = [];
        foreach (\App\Services\Organization\TaskWizards::CATALOG as [$name, $link]) {
            $p = SopService::path((string) $link);
            if ($p !== null) $out[$p] ??= $name;
        }
        $out['/settings/ksef/purchase-invoices'] ??= 'Faktury KSeF (pobieranie)';
        $out['/workshop/supply'] ??= 'Zaopatrzenie i faktury';
        asort($out);
        return $out;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
