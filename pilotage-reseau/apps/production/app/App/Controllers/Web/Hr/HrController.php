<?php
declare(strict_types=1);

namespace App\Controllers\Web\Hr;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Hr\HrService;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\Request;

/**
 * [271] Rail « HR » : Pracownicy (tableau), karta pracownika (fiche, documents, notes), Dokumenty, Notatki miesiąca,
 * Ustawienia HR (administrateur). Pages : porte d'accès d'App (clé « hr ») ; ajax : même droit vérifié ici.
 */
final class HrController extends BaseController
{
    use JsonErrors;

    public function __construct(private HrService $hr, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('hr/index', ['tab' => 'people', 'is_admin' => $this->isAdmin()]);
    }

    public function documentsPage()
    {
        $this->view('hr/index', ['tab' => 'documents', 'is_admin' => $this->isAdmin()]);
    }

    public function notesPage()
    {
        $this->view('hr/index', ['tab' => 'notes', 'is_admin' => $this->isAdmin()]);
    }

    public function settingsPage()
    {
        $this->view('hr/index', ['tab' => 'settings', 'is_admin' => $this->isAdmin()]);
    }

    public function people()
    {
        return $this->guarded(fn() => $this->hr->view($this->userId(), $this->isAdmin()));
    }

    public function card($id)
    {
        return $this->guarded(fn() => $this->hr->card((int) $id, $this->userId(), $this->isAdmin()));
    }

    public function save($id)
    {
        return $this->guarded(fn() => $this->hr->save((int) $id, $this->input(), $this->userId()));
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
