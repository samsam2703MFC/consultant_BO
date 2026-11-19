<?php

namespace App\Employee\app\Http\Controllers\Stats;

use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Primes\PrimesService;
use App\Employee\core\Support\Route;

/**
 * « Statistiques » (16/10/2026, à la place de Tâches dans la barre du bas) : ses chiffres à elle, aujourd'hui,
 * le mois au jour J, ses douze dernières semaines, le magasin face à son objectif, ses titres. Rien n'est
 * calculé ici : la fiche est celle que le cockpit donne à « Mes primes » (GET /ventes/moi).
 */
class StatsController extends Controller
{
    public function __construct(
        private PrimesService $primesService,
    ) {}

    #[Route('GET', '/statistiques')]
    public function index()
    {
        $mois = $_GET['m'] ?? null;
        if (!is_string($mois) || !preg_match('/^\d{4}-\d{2}$/', $mois)) { $mois = null; }
        $r = $this->primesService->pourMoi($mois);
        $data['fiche'] = $r['fiche'] ?? null;
        $data['ficheErreur'] = $r['erreur'] ?? null;
        $data['jour'] = (int) date('j');

        $this->view("stats/statistiques", $data);
    }
}
