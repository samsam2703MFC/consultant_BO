<?php

namespace App\Employee\app\Http\Controllers\Primes;

use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Primes\PrimesService;
use App\Employee\core\Support\Route;

/** « Mes primes » : un seul écran, centré sur elle : ce qu'elle peut gagner, comment, sa collection, les classements (06/10/2026). */
class PrimesController extends Controller
{
    public function __construct(
        private PrimesService $primesService,
    ) {}

    #[Route('GET', '/primes')]
    public function index()
    {
        $mois = $_GET['m'] ?? null;
        if (!is_string($mois) || !preg_match('/^\d{4}-\d{2}$/', $mois)) { $mois = null; }
        $r = $this->primesService->pourMoi($mois);
        $data['primes'] = $r['fiche'] ?? null;
        $data['primesErreur'] = $r['erreur'] ?? null;
        $data['jour'] = (int) date('j');
        $data['heure'] = date('H:i');

        $this->view("primes/primes", $data);
    }
}
