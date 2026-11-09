<?php

namespace App\Employee\app\Http\Controllers\Pointage;

use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Pointage\PointageService;
use App\Employee\core\Support\Route;

/**
 * Le pointage (01/11/2026, Sam ; maquette docs/maquettes/pointage-app) : « Pointer » ouvre la caméra, qui lit le QR code
 * de la borne du magasin (ou on tape son code à six chiffres) ; le cockpit décide de l'arrivée ou du départ. Le lien du
 * QR (« /pointage?c=… ») pointe aussi depuis l'appareil photo du téléphone. Profil › Mes pointages : la semaine, face au
 * planning.
 */
class PointageController extends Controller
{
    public function __construct(private PointageService $pointageService) {}

    /** GET /pointage[?c=] — la caméra ; avec « c » (le QR lu par l'appareil photo), le pointage part tout de suite. */
    #[Route('GET', '/pointage')]
    public function scanner()
    {
        $data['etat'] = $this->pointageService->aujourdhui();
        $data['lu'] = is_string($_GET['c'] ?? null) ? mb_substr((string) $_GET['c'], 0, 200) : '';
        $this->view('pointage/scanner', $data);
    }

    /** POST /pointage {lu} — relayé au cockpit ; rend le pointage, ou le motif d'un refus (422). */
    #[Route('POST', '/pointage')]
    public function pointer()
    {
        $b = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($b) || !is_string($b['lu'] ?? null) || trim($b['lu']) === '') { return $this->json(['error' => 'rien de lu', 'motif' => 'illisible'], 422); }
        $r = $this->pointageService->pointer($b['lu']);
        if ($r['ok']) { return $this->json($r['data']); }
        return $this->json($r['data'] ?? ['error' => $r['erreur'] ?? 'indisponible'], $r['code'] === 422 ? 422 : 502);
    }

    /** GET /me/pointages[?semaine=AAAA-MM-JJ] — la semaine (du lundi au dimanche) : prévu, arrivée, départ. */
    #[Route('GET', '/me/pointages')]
    public function liste()
    {
        $j = is_string($_GET['semaine'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['semaine']) ? strtotime($_GET['semaine']) : false;
        $t = $j ?: time();
        $lundi = date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t));
        $dimanche = date('Y-m-d', strtotime($lundi . ' +6 days'));
        $r = $this->pointageService->liste($lundi, $dimanche);
        $n = (int) date('N');
        $data['semaine'] = ['lundi' => $lundi, 'dimanche' => $dimanche, 'numero' => (int) date('W', strtotime($lundi)),
            'prec' => date('Y-m-d', strtotime($lundi . ' -7 days')), 'suiv' => date('Y-m-d', strtotime($lundi . ' +7 days')),
            'courante' => $lundi === date('Y-m-d', strtotime('-' . ($n - 1) . ' days'))];
        $data['liste'] = $r['ok'] ? PointageService::semaineVue($r['data'], date('Y-m-d'), time()) : null;
        $data['aujourdhui'] = date('Y-m-d');
        $this->view('pointage/liste', $data);
    }

    /** POST /me/pointage/depart {id, heure} — un départ oublié, dit par la personne. */
    #[Route('POST', '/me/pointage/depart')]
    public function depart()
    {
        $b = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($b) || (int) ($b['id'] ?? 0) <= 0 || !is_string($b['heure'] ?? null)) { return $this->json(['error' => 'requête illisible'], 400); }
        $r = $this->pointageService->declarerDepart((int) $b['id'], $b['heure']);
        if ($r['ok']) { return $this->json($r['data']); }
        return $this->json($r['data'] ?? ['error' => $r['erreur'] ?? 'indisponible'], $r['code'] === 422 ? 422 : 502);
    }
}
