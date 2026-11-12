<?php
declare(strict_types=1);

namespace App\Controllers\Web\Hr;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Hr\ContractTemplateService;
use App\Services\Hr\HrQuickNoteService;
use App\Services\Hr\HrService;
use App\Services\Hr\HrSuccessionService;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\Request;

/**
 * [271] Rail « HR » : Pracownicy (tableau), karta pracownika (fiche, documents, notes), Dokumenty, Notatki miesiąca,
 * Ustawienia HR (administrateur). Pages : porte d'accès d'App (clé « hr ») ; ajax : même droit vérifié ici.
 */
final class HrController extends BaseController
{
    use JsonErrors;

    public function __construct(private HrService $hr, private PageAccessService $access, private ContractTemplateService $templates,
        private HrQuickNoteService $quick, private HrSuccessionService $succession)
    {
    }

    public function index()
    {
        $this->view('hr/index', ['tab' => 'people', 'is_admin' => $this->isAdmin(), 'can_pay' => $this->canPay()]);
    }

    public function documentsPage()
    {
        $this->view('hr/index', ['tab' => 'documents', 'is_admin' => $this->isAdmin()]);
    }

    public function templatesPage()
    {
        $this->view('hr/index', ['tab' => 'templates', 'is_admin' => $this->isAdmin(), 'can_pay' => $this->canPay()]);
    }

    public function notesPage()
    {
        $this->view('hr/index', ['tab' => 'notes', 'is_admin' => $this->isAdmin()]);
    }

    public function successionPage()
    {
        $this->view('hr/index', ['tab' => 'succession', 'is_admin' => $this->isAdmin()]);
    }

    public function settingsPage()
    {
        $this->view('hr/index', ['tab' => 'settings', 'is_admin' => $this->isAdmin()]);
    }

    public function people()
    {
        return $this->guarded(fn() => $this->hr->view($this->userId(), $this->isAdmin(), null, $this->canPay()));
    }

    public function card($id)
    {
        return $this->guarded(fn() => $this->hr->card((int) $id, $this->userId(), $this->isAdmin(), null, $this->canPay()));
    }

    public function save($id)
    {
        return $this->guarded(fn() => $this->hr->save((int) $id, $this->input(), $this->userId(), $this->canPay()));
    }

    public function upload($id)
    {
        return $this->guarded(fn() => $this->hr->upload((int) $id, $this->input(), $this->userId()));
    }

    public function deleteDocument($id)
    {
        return $this->guarded(function () use ($id) {
            $this->hr->deleteDocument((int) $id, $this->userId(), $this->isAdmin());
            return ['message' => 'Dokument usunięty.'];
        });
    }

    public function download($id)
    {
        try {
            if (!$this->canHr()) throw new CustomException('Brak dostępu do HR.', 'errors', 403);
            $file = $this->hr->download((int) $id, $this->userId());
        } catch (CustomException $e) {
            http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 404);
            return htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['filename']) ?: 'dokument';
        $inline = in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true) && ($_GET['inline'] ?? '') === '1';
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['filename']));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');
        header('Cache-Control: private, no-store');
        return $file['content'];
    }

    public function documents()
    {
        return $this->guarded(fn() => $this->hr->allDocuments($this->userId(), $this->isAdmin()));
    }

    public function addNote($id)
    {
        return $this->guarded(fn() => $this->hr->addNote((int) $id, $this->input(), $this->userId()));
    }

    public function deleteNote($id)
    {
        return $this->guarded(function () use ($id) {
            $this->hr->deleteNote((int) $id, $this->userId(), $this->isAdmin());
            return ['message' => 'Notatka usunięta.'];
        });
    }

    public function monthNotes()
    {
        $month = (string) ($_GET['month'] ?? date('Y-m'));
        return $this->guarded(fn() => $this->hr->monthNotes($month, $this->userId(), $this->isAdmin()));
    }

    public function sendSummary()
    {
        return $this->guarded(function () {
            if (!$this->isAdmin()) throw new CustomException('Podsumowanie wysyła administrator.', 'errors', 403);
            $r = $this->hr->sendSummary((string) ($this->input()['month'] ?? date('Y-m')), $this->userId());
            return ['message' => $r['recipients'] ? 'Podsumowanie wysłane : ' . $r['recipients'] . ' adres(y).' : ($r['notes'] ? 'Brak adresu e-mail administratora.' : 'Brak notatek w tym miesiącu.')] + $r;
        });
    }

    public function rules()
    {
        return $this->guarded(fn() => $this->hr->rules());
    }

    public function saveRules()
    {
        return $this->guarded(function () {
            if (!$this->isAdmin()) throw new CustomException('Ustawienia HR zmienia administrator.', 'errors', 403);
            return $this->hr->saveRules($this->input(), (int) $this->userId());
        });
    }

    // --- [279] Szybka notatka et follow-ups

    public function quickOptions()
    {
        return $this->guarded(fn() => $this->quick->options($this->userId()));
    }

    public function quickAdd()
    {
        return $this->guarded(fn() => $this->quick->add($this->input(), $this->userId()));
    }

    public function followups()
    {
        return $this->guarded(fn() => $this->quick->followups($this->userId(), $this->isAdmin()));
    }

    public function followupDone($id)
    {
        return $this->guarded(fn() => $this->quick->done((int) $id, $this->input(), $this->userId(), $this->isAdmin()));
    }

    // --- [278] Sukcesja (lecture sans écriture ; un plan affiché « à la volée » est enregistré à la première action)

    public function succession()
    {
        return $this->guarded(fn() => $this->succession->view($this->userId()));
    }

    public function successionOpen()
    {
        return $this->guarded(function () {
            $in = $this->input();
            $id = $this->succession->open($in, $this->userId(), null, !empty($in['auto']));
            return ['id' => $id, 'view' => $this->succession->view($this->userId())];
        });
    }

    public function successionSave($id)
    {
        return $this->guarded(function () use ($id) {
            $this->succession->update((int) $id, $this->input(), $this->userId());
            return $this->succession->view($this->userId());
        });
    }

    public function successionCheck($id)
    {
        return $this->guarded(function () use ($id) {
            $this->succession->check((int) $id, !empty($this->input()['done']), $this->userId());
            return $this->succession->view($this->userId());
        });
    }

    // --- [276] Szablony umów (salaire, PESEL, adresse : accès « payroll »)

    public function templateList()
    {
        return $this->guarded(fn() => ['templates' => $this->templatesOrFail()->list(), 'fields' => ContractTemplateService::FIELDS, 'is_admin' => $this->isAdmin()]);
    }

    public function templateSave($code)
    {
        return $this->guarded(function () use ($code) {
            if (!$this->isAdmin()) throw new CustomException('Treść szablonów zmienia administrator.', 'errors', 403);
            return $this->templatesOrFail()->save((string) $code, $this->input(), $this->userId());
        });
    }

    public function templateRender($code)
    {
        return $this->guarded(function () use ($code) {
            $in = $this->input();
            return $this->templatesOrFail()->render((string) $code, (int) ($in['employee_id'] ?? 0), (array) ($in['extra'] ?? []), $this->userId());
        });
    }

    public function templatePdf($code)
    {
        return $this->guarded(function () use ($code) {
            $in = $this->input();
            return $this->templatesOrFail()->savePdf((string) $code, (int) ($in['employee_id'] ?? 0), (array) ($in['extra'] ?? []), $this->userId());
        });
    }

    /** DOCX : GET ?employee=ID&extra[...]=… (pobranie). */
    public function templateDocx($code)
    {
        try {
            if (!$this->canHr()) throw new CustomException('Brak dostępu do HR.', 'errors', 403);
            $extra = array_map(fn($v) => is_string($v) ? mb_substr($v, 0, 500) : '', (array) ($_GET['extra'] ?? []));
            $f = $this->templatesOrFail()->docx((string) $code, (int) ($_GET['employee'] ?? 0), $extra, $this->userId());
        } catch (CustomException $e) {
            http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
            header('Content-Type: text/plain; charset=utf-8');
            return $e->getMessage();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . (preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['filename']) ?: 'umowa.docx') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        return $f['content'];
    }

    private function templatesOrFail(): ContractTemplateService
    {
        if (!$this->canPay()) throw new CustomException('Szablony umów (wynagrodzenie, PESEL) : administrator i finanse.', 'errors', 403);
        return $this->templates;
    }

    /** [277] Paie (salaire, compte, PESEL, adresse) : administrateur et accès « payroll ». */
    private function canPay(): bool
    {
        return $this->access->canSee($this->userId(), '/payroll');
    }

    private function guarded(callable $fn)
    {
        try {
            if (!$this->canHr()) throw new CustomException('Brak dostępu do HR.', 'errors', 403);
            $out = $fn();
            return $this->json(isset($out['message']) ? $out : ['data' => $out]);
        } catch (CustomException $e) {
            // Champs en erreur (formulaire de la fiche) renvoyés avec le message.
            $code = (int) $e->getCode();
            return $this->json(['message' => $e->getMessage(), 'fields' => $e->getErrors()['fields'] ?? null], $code >= 400 && $code < 600 ? $code : 422);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function canHr(): bool
    {
        return $this->access->canSee($this->userId(), '/hr');
    }

    private function isAdmin(): bool
    {
        return $this->access->isAdmin($this->userId()) && $this->access->viewPosition($this->userId()) === null;
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
