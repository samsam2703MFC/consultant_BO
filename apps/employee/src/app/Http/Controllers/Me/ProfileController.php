<?php

namespace App\Employee\app\Http\Controllers\Me;


use App\Employee\app\Http\Controllers\Controller;
use App\Employee\app\Services\Employee\EmployeeService;
use App\Employee\app\Services\Profil\ProfilService;
use App\Employee\app\Services\Shop\ShopService;
use App\Employee\core\Support\Route;

/**
 * « Mon profil » (30/10/2026, Sam : « que l'employé puisse encoder toutes ses données » ; maquette
 * docs/maquettes/profil-employe) : ce qui manque en tête, puis six fiches que la personne remplit elle-même et la photo
 * de sa carte d'identité. Le profil est gardé chiffré par le cockpit (ProfilService) ; le prénom, le nom, le téléphone
 * et l'e-mail viennent du panel tant qu'elle ne les a pas changés.
 */
class ProfileController extends Controller
{

    public function __construct(
        private EmployeeService $employeeService,
        private ShopService $shopService,
        private ProfilService $profilService
    ) {}

    #[Route('GET', '/me')]
    public function index()
    {
        $data['employee'] = $this->employeeService->getMe(['positions', 'competencies']);
        $data['shop'] = $this->shopService->getMe();
        $r = $this->profilService->lire($this->panel($data['employee']));
        $data['profil'] = $r['ok'] ? $r['data'] : null;
        $data['profilErreur'] = $r['ok'] ? null : ($r['erreur'] ?? 'indisponible');
        $data['panel'] = $this->panel($data['employee']);

        $this->view("me/profile", $data);
    }

    /** POST /me/profil {section, valeurs} — une fiche, relayée au cockpit ; rend le profil, ou les erreurs par champ (422). */
    #[Route('POST', '/me/profil')]
    public function enregistrer()
    {
        $b = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($b) || !is_string($b['section'] ?? null) || !is_array($b['valeurs'] ?? null)) { return $this->json(['error' => 'requête illisible'], 400); }
        $r = $this->profilService->enregistrer($b['section'], $b['valeurs'], $this->panel($this->employeeService->getMe()));
        if ($r['ok']) { return $this->json($r['data']); }
        return $this->json($r['data'] ?? ['error' => $r['erreur'] ?? 'indisponible'], $r['code'] === 422 ? 422 : 502);
    }

    /** POST /me/carte {face, data} — une photo de la carte d'identité (réduite dans le navigateur), relayée au cockpit. */
    #[Route('POST', '/me/carte')]
    public function carte()
    {
        $b = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($b) || !in_array($b['face'] ?? '', ['recto', 'verso'], true) || !is_string($b['data'] ?? null)) { return $this->json(['error' => 'requête illisible'], 400); }
        $r = $this->profilService->carte($b['face'], $b['data']);
        if ($r['ok']) { return $this->json($r['data']); }
        return $this->json($r['data'] ?? ['error' => $r['erreur'] ?? 'indisponible'], $r['code'] === 422 ? 422 : 502);
    }

    /** Ce que le panel sait de la personne : prénom, nom, téléphone, e-mail. */
    private function panel($e): array
    {
        if (!$e) { return []; }
        return ['prenom' => (string) $e->getName(), 'nom' => (string) $e->getSurname(), 'telephone' => (string) $e->getPhone(), 'email' => (string) $e->getEmail()];
    }
}
