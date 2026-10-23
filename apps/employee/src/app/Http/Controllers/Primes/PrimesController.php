<?php

namespace App\Employee\app\Http\Controllers\Primes;

use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Primes\PrimesPlan;
use App\Employee\app\Services\Primes\PrimesService;
use App\Employee\core\Support\Route;

/** « Mes primes » : ce qui est encore en jeu, comment y arriver, où elle en est, les classements (06/10/2026, motivation le 23/10/2026). */
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
        // Comment y arriver (23/10/2026) : les primes encore à aller chercher, et le temps qu'il lui reste.
        $data['plan'] = $data['primes'] ? PrimesPlan::pour($data['primes']) : [];
        $data['heuresRestantes'] = $data['primes'] ? PrimesPlan::heuresRestantes($data['primes']) : null;
        $data['vue'] = $_GET['vue'] ?? null; // MAQUETTE — à retirer
        $data['jour'] = (int) date('j');
        $data['heure'] = date('H:i');

        $this->view("primes/primes", $data);
    }
}
