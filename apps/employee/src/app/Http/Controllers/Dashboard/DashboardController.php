<?php

namespace App\Employee\app\Http\Controllers\Dashboard;

use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Primes\PrimesService;
use App\Employee\app\Services\Schedule\ScheduleService;
use App\Employee\app\Services\Task\TaskService;
use App\Employee\core\Support\GlobalRegistry;
use App\Employee\core\Support\Route;

/**
 * L'Accueil : centré sur la performance de la personne (ses primes, son compteur du jour, sa
 * prochaine étape, sa semaine), le service du jour et les tâches, le magasin en une ligne.
 * Chaque lecture est isolée : si l'une échoue, les autres s'affichent.
 */
class DashboardController extends Controller
{
    public function __construct(
        private PrimesService $primesService,
        private ScheduleService $scheduleService,
        private TaskService $taskService,
    ) {}

    #[Route('GET', '/dashboard')]
    public function index()
    {
        $user = GlobalRegistry::get('user');
        // Le prénom : le premier mot du nom d'affichage du panel (« Prénom N. »).
        $nom = $user ? trim((string) $user->getDisplayName()) : '';
        $data['prenom'] = $nom !== '' ? explode(' ', $nom)[0] : '';
        $data['jour'] = (int) date('j');
        $data['heure'] = date('H:i');
        $data['dateLib'] = date('d/m');

        $fiche = null; $erreur = null;
        try { $r = $this->primesService->pourMoi(null); $fiche = $r['fiche'] ?? null; $erreur = $r['erreur'] ?? null; }
        catch (\Throwable $e) { $erreur = $e->getMessage(); }
        $data['primes'] = $fiche;
        $data['primesErreur'] = $erreur;
        $data['etapes'] = $fiche ? $this->etapes($fiche) : [];
        $data['semaine'] = $fiche ? $this->semaine($fiche) : null;

        $service = null;
        try {
            $auj = date('Y-m-d');
            foreach ($this->scheduleService->getForMe($auj, $auj) as $s) {
                if ($s->getWorkDate() === $auj) { $service = ['debut' => substr((string) $s->getStartHour(), 0, 5), 'fin' => substr((string) $s->getEndHour(), 0, 5)]; break; }
            }
        } catch (\Throwable $e) { $service = null; }
        $data['service'] = $service;

        $taches = null;
        try {
            $tous = $this->taskService->getForMe();
            $taches = ['total' => count($tous), 'restantes' => count(array_filter($tous, static fn ($t) => !$t->isDone()))];
        } catch (\Throwable $e) { $taches = null; }
        $data['taches'] = $taches;

        $this->view("dashboard/dashboard", $data);
    }

    /** Les prochaines étapes qui rapportent, la plus proche d'abord : [{lib, gain, detail}]. */
    private function etapes(array $f): array
    {
        $b = $f['briques'] ?? [];
        $out = [];
        $c = $b['croisees'] ?? null;
        if ($c && !empty($c['prochain'])) {
            $out[] = ['cle' => 'croisees', 'lib' => 'Ventes croisées', 'gain' => (int) $c['prochain']['montant'],
                'detail' => (int) $c['prochain']['manque'] . ' ventes croisées de plus pour passer ' . number_format((float) $c['prochain']['taux'], 0, ',', ' ') . ' %', 'ordre' => (int) $c['prochain']['manque']];
        }
        $r = $b['record'] ?? null;
        if ($r && isset($r['ecart']) && $r['ecart'] !== null && $r['ecart'] < 0 && !empty($r['eurDixieme'])) {
            $out[] = ['cle' => 'record', 'lib' => 'Bats ton record', 'gain' => (int) $r['eurDixieme'],
                'detail' => 'à ' . number_format(abs((float) $r['ecart']), 2, ',', ' ') . ' ligne par ticket du record ' . number_format((float) $r['record'], 1, ',', ' '), 'ordre' => (int) round(abs((float) $r['ecart']) * 100)];
        }
        $k = $b['concours'] ?? null;
        if ($k && !empty($k['actif']) && ($k['rangMag'] ?? null) !== 1 && isset($k['premierMag']) && $k['premierMag'] !== null) {
            $manque = max(1, (int) ceil((float) $k['premierMag'] - (float) ($k['pieces'] ?? 0)) + 1);
            $out[] = ['cle' => 'concours', 'lib' => 'Concours ' . ($k['lib'] ?? ''), 'gain' => (int) $k['montantMag'],
                'detail' => $manque . ' pièces de plus pour passer 1re du magasin', 'ordre' => $manque];
        }
        $v = $b['meilleure'] ?? null;
        if ($v && ($v['rangMag'] ?? null) !== null && $v['rangMag'] !== 1 && !empty($v['scorePremier']) && isset($v['score'])) {
            $out[] = ['cle' => 'meilleure', 'lib' => 'Meilleure vendeuse', 'gain' => (int) $v['montantMag'],
                'detail' => 'score ' . number_format((float) $v['score'], 0, ',', ' ') . ', la 1re du magasin est à ' . number_format((float) $v['scorePremier'], 0, ',', ' '), 'ordre' => 50];
        }
        usort($out, static fn ($a, $z) => $a['ordre'] <=> $z['ordre']);
        return array_slice($out, 0, 3);
    }

    /** La semaine en cours, depuis les douze dernières semaines de la fiche. */
    private function semaine(array $f): ?array
    {
        $s = $f['semaines12'] ?? [];
        if (!$s) { return null; }
        $w = end($s);
        return ['lib' => $w['lib'] ?? '', 'tickets' => (int) ($w['tickets'] ?? 0), 'taux' => $w['taux'] ?? null, 'rang' => $w['rang'] ?? null, 'sur' => (int) ($w['sur'] ?? 0),
            'pieces' => $w['pieces'] ?? null, 'rangPieces' => $w['rangPieces'] ?? null, 'caHeure' => $w['caHeure'] ?? null];
    }
}
