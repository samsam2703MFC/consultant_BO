<?php
declare(strict_types=1);

namespace App\Controllers\Web\Workshop;

use App\Core\Web\BaseController;
use App\Services\Organization\PageAccessService;
use App\Services\Workshop\Manager\ManagerService;

/**
 * [256] « Moja karta stanowiska » (makieta 35) : jedna strona A4 — misje i zadania, godziny na misję, cele. Osoba na
 * stanowisku kierownika produkcji widzi tylko tę kartę ; administrator widzi kartę osoby na stanowisku (podgląd, druk).
 */
class ManagerCardController extends BaseController
{
    public function __construct(private ManagerService $manager, private PageAccessService $access)
    {
    }

    public function card()
    {
        $userId = (int) (user('id') ?? 0);
        $admin = $this->access->isAdmin($userId);
        $this->view('workshop/manager_card', ['card' => $this->manager->card($userId, $admin), 'is_admin' => $admin]);
    }
}
