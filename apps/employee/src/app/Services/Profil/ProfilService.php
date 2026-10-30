<?php
namespace App\Employee\app\Services\Profil;

use App\Employee\core\Cookie\CookieManager;
use App\Employee\core\Support\GlobalRegistry;

/**
 * « Mon profil » (30/10/2026) : ce que l'employé encode lui-même — identité, coordonnées, contact d'urgence, paie,
 * tenue, disponibilités, carte d'identité — est gardé chiffré par le cockpit (src/profil_employe.php), comme « Mes
 * primes » y est calculé. L'app se présente de la même façon que PrimesService : l'identité de la session signée avec
 * le secret partagé, et le jeton d'employé ; le cockpit ne lit et n'écrit que le profil de cette personne.
 */
class ProfilService
{
    public function __construct(private CookieManager $cookieManager)
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
        return $this->appel('GET', '/ventes/moi/profil?panel=' . rawurlencode(base64_encode((string) json_encode($panel))));
    }

    /** Enregistre une fiche (identite, coordonnees, urgence, paie, tenue, dispo). 422 avec data.erreurs si un champ ne passe pas. */
    public function enregistrer(string $section, array $valeurs, array $panel): array
    {
        return $this->appel('POST', '/ventes/moi/profil', ['section' => $section, 'valeurs' => $valeurs, 'panel' => $panel]);
    }

    /** Une photo de la carte d'identité (recto ou verso), en base64. */
    public function carte(string $face, string $data): array
    {
        return $this->appel('POST', '/ventes/moi/profil/carte', ['face' => $face, 'data' => $data]);
    }

    private function appel(string $methode, string $chemin, ?array $corps = null): array
    {
        $jeton = $this->cookieManager->getAccessToken();
        if (!$jeton || !defined('COCKPIT_API_URL') || COCKPIT_API_URL === '') { return ['ok' => false, 'code' => 0, 'erreur' => 'pas de session ou pas d’adresse du cockpit']; }
        $entetes = ['Accept: application/json', 'Authorization: Bearer ' . $jeton, 'X-Employee-Token: ' . $jeton];
        if ($corps !== null) { $entetes[] = 'Content-Type: application/json'; }
        $user = GlobalRegistry::get('user');
        if ($user && defined('COCKPIT_WORKER_SECRET') && COCKPIT_WORKER_SECRET !== '') {
            $emp = (int) $user->getId(); $nom = (string) $user->getDisplayName(); $jour = date('Y-m-d');
            $entetes[] = 'X-Worker-Emp: ' . $emp;
            $entetes[] = 'X-Worker-Nom: ' . base64_encode($nom);
            $entetes[] = 'X-Worker-Jour: ' . $jour;
            $entetes[] = 'X-Worker-Sign: ' . hash_hmac('sha256', $emp . '|' . $nom . '|' . $jour, COCKPIT_WORKER_SECRET);
        }
        $erreur = '';
        foreach ($this->adresses(COCKPIT_API_URL . $chemin) as [$url, $hote]) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $methode,
                CURLOPT_HTTPHEADER => $hote !== null ? array_merge($entetes, ['Host: ' . $hote]) : $entetes,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
            ];
            if ($corps !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($corps, JSON_UNESCAPED_UNICODE); }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $d = $raw !== false ? json_decode((string) $raw, true) : null;
            if ($code === 200 && is_array($d)) { return ['ok' => true, 'code' => 200, 'data' => $d]; }
            if ($code === 422 && is_array($d)) { return ['ok' => false, 'code' => 422, 'data' => $d, 'erreur' => (string) ($d['error'] ?? 'à corriger')]; }
            $erreur = ($code > 0 ? 'HTTP ' . $code : 'injoignable') . (is_array($d) && isset($d['error']) ? ' : ' . $d['error'] : ($err !== '' ? ' : ' . $err : ''));
            // Une réponse claire du cockpit (401, 404…) ne s'améliore pas par une autre adresse.
            if ($code >= 400 && $code !== 502 && $code !== 503 && $code !== 504) { break; }
        }
        error_log('profil : ' . $erreur . ' sur ' . $methode . ' ' . $chemin);
        return ['ok' => false, 'code' => 502, 'erreur' => $erreur];
    }

    /** Les adresses à essayer : la locale (127.0.0.1 avec l'hôte public), puis la publique. */
    private function adresses(string $url): array
    {
        $u = parse_url($url);
        $hote = (string) ($u['host'] ?? '');
        $port = isset($u['port']) ? ':' . $u['port'] : '';
        $reste = ($u['path'] ?? '') . (isset($u['query']) ? '?' . $u['query'] : '');
        if ($hote === '' || in_array($hote, ['127.0.0.1', 'localhost'], true)) { return [[$url, null]]; }
        return [['http://127.0.0.1' . $port . $reste, $hote . $port], [$url, null]];
    }
}
