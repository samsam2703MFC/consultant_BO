<?php
namespace App\Employee\app\Services\Profil;

use App\Employee\app\Services\Cockpit\CockpitClient;

/**
 * « Mon profil » (30/10/2026) : ce que l'employé encode lui-même — identité, coordonnées, contact d'urgence, paie,
 * tenue, disponibilités, carte d'identité — est gardé chiffré par le cockpit (src/profil_employe.php), comme « Mes
 * primes » y est calculé. L'app s'y présente par CockpitClient (identité signée, jeton d'employé) ; le cockpit ne lit et
 * n'écrit que le profil de cette personne.
 */
class ProfilService
{
    public function __construct(private CockpitClient $cockpit)
    {
    }

    /**
     * Le profil de la personne connectée. $panel : prénom, nom, téléphone, e-mail tels que le panel les connaît (pour
     * compter ce qui manque).
     *
     * @return array{ok:bool, code:int, data?:array, erreur?:string}
     */
    public function lire(array $panel): array
    {
        return $this->cockpit->appel('GET', '/ventes/moi/profil?panel=' . rawurlencode(base64_encode((string) json_encode($panel))));
    }

    /** Enregistre une fiche (identite, coordonnees, urgence, paie, tenue, dispo). 422 avec data.erreurs si un champ ne passe pas. */
    public function enregistrer(string $section, array $valeurs, array $panel): array
    {
        return $this->cockpit->appel('POST', '/ventes/moi/profil', ['section' => $section, 'valeurs' => $valeurs, 'panel' => $panel]);
    }

    /** Une photo de la carte d'identité (recto ou verso), en base64. */
    public function carte(string $face, string $data): array
    {
        return $this->cockpit->appel('POST', '/ventes/moi/profil/carte', ['face' => $face, 'data' => $data]);
    }
}
