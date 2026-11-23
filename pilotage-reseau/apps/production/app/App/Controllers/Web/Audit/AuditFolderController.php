<?php
declare(strict_types=1);

namespace App\Controllers\Web\Audit;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\AuditFolderService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asystent « Teczka do audytu i kontroli » [406] (Kierownik produkcji, co kwartał) : przegląd (asystenci kontroli, otwarte
 * sprawy w ERP, dokumenty do teczki), decyzja dla elementu, zatwierdzenie, teczka PDF. Droit propre « audit_folder » (page,
 * PDF et appels ajax). Les liens vers les écrans d'origine (asystenci, NC, reklamacje, Rejestr PPWR, SOP) ne sont donnés que
 * si l'utilisateur les voit. Écrit seulement n_audit_folder_item, n_audit_folder_review et n_wizard_done ; rien n'est envoyé.
 */
final class AuditFolderController extends BaseController
{
    use JsonErrors;

    public const PATH = AuditFolderService::PATH;

    public function __construct(private AuditFolderService $folder, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('audit/audit_folder', ['data' => $this->withLinks($this->folder->data())]);
    }

    public function data()
    {
        return $this->run(fn() => ['data' => $this->withLinks($this->folder->data())]);
    }

    /** POST {key, decision: ready|action|not_applicable, due_on?, note} — krok 2 : decyzja. */
    public function decision()
    {
        return $this->run(fn() => ['message' => 'Zapisano.', 'data' => $this->withLinks($this->folder->saveDecision($this->input(), $this->userId()))]);
    }

    /** POST {id?, title, area, format, owner?, location?, check_months, note?} — dokument na liście (nowy albo zmieniony). */
    public function item()
    {
        return $this->run(fn() => ['message' => 'Zapisano dokument.', 'data' => $this->withLinks($this->folder->saveItem($this->input(), $this->userId()))]);
    }

    /** POST {id} — dokument usunięty z listy (wyłączony). */
    public function removeItem()
    {
        return $this->run(fn() => ['message' => 'Usunięto z listy.', 'data' => $this->withLinks($this->folder->removeItem($this->input(), $this->userId()))]);
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie kwartału zaliczone. */
    public function finish()
    {
        return $this->run(function () {
            $r = $this->folder->finish($this->userId());
            return ['message' => 'Teczka gotowa — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])];
        });
    }

    /** GET — teczka PDF (do wydruku). */
    public function pdf()
    {
        try {
            $this->guard();
            $f = $this->folder->pdf(null, (string) (user('displayed_name') ?: user('login') ?: '') ?: null);
            return new Response($f['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $f['filename'] . '"',
                'Content-Length' => (string) strlen($f['content'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function run(callable $f)
    {
        try {
            $this->guard();
            return $this->json($f());
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « Teczka do audytu i kontroli ».', 'errors', 403);
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    /** Liens vers les écrans d'origine : seulement ceux que l'utilisateur peut ouvrir (droits des stanowisk). */
    private function withLinks(array $d): array
    {
        $uid = $this->userId();
        $seen = [];
        $can = function (string $href) use ($uid, &$seen): bool {
            $path = (string) strtok($href, '?#');
            return $seen[$path] ??= $this->access->canSee($uid, $path);
        };
        foreach (['review', 'checks', 'documents'] as $list) {
            foreach ($d[$list] as &$it) $it['links'] = array_values(array_filter($it['links'], fn($l) => $can($l['href'])));
            unset($it);
        }
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
