<?php
declare(strict_types=1);

namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\ErpEntryService;
use App\Services\Organization\PageAccessService;
use Symfony\Component\HttpFoundation\Request;

/**
 * [275] Wejście do ERP przez QR (makieta 40) : écran « Dzień dobry » (QR d'entrée), état de la journée pour la barre du
 * haut, QR et attente du pointage (entrée / sortie), réglage administrateur (postes concernés, dans « Uprawnienia i menu »).
 */
final class ErpEntryController extends BaseController
{
    use JsonErrors;

    public function __construct(private ErpEntryService $entry, private PageAccessService $access)
    {
    }

    /** GET /erp-entry : écran du QR d'entrée ; déjà entré (ou non concerné) → page demandée. */
    public function page()
    {
        $next = self::safeNext((string) ($_GET['next'] ?? ''));
        if (!$this->entry->locked($this->userId())) {
            header('Location: ' . ROOT . ($next ?: '/home'), true, 302);
            exit;
        }
        $this->view('organization/erp_entry', ['state' => $this->entry->state($this->userId()), 'next' => $next ?: '/home']);
    }

    public function state()
    {
        return $this->guarded(fn() => $this->entry->state($this->userId()));
    }

    /** GET /ajax/erp-entry/qr?base=… : QR du jour (aucune écriture). */
    public function qr()
    {
        return $this->guarded(fn() => $this->entry->qr($this->userId(), (string) ($_GET['base'] ?? '')));
    }

    public function status()
    {
        return $this->guarded(fn() => $this->entry->status($this->userId(), (string) ($_GET['since'] ?? '')));
    }

    /** PUT /ajax/organization/erp-qr/{id} {enabled} : poste concerné ou non (administrateur). */
    public function savePosition($id)
    {
        return $this->guarded(function () use ($id) {
            if (!$this->access->isAdmin($this->userId())) throw new CustomException('Tylko administrator.', 'errors', 403);
            $in = $this->getJson(Request::createFromGlobals());
            return $this->entry->setPosition((int) $id, !empty($in['enabled']));
        });
    }

    /** Seulement un chemin interne de l'ERP (pas d'URL externe, pas de retour vers l'écran lui-même). */
    public static function safeNext(string $next): string
    {
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]*$#', $next) && !str_starts_with($next, '/erp-entry') && !str_starts_with($next, '/ajax/') ? $next : '';
    }

    private function guarded(callable $fn)
    {
        try {
            return $this->json(['data' => $fn()]);
        } catch (CustomException $e) {
            return $this->json(['message' => $e->getMessage()], in_array((int) $e->getCode(), [403, 404, 422, 503], true) ? (int) $e->getCode() : 400);
        } catch (\Throwable $e) {
            error_log('[erp-entry] ' . $e->getMessage());
            return $this->json(['message' => 'Błąd serwera.'], 500);
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
