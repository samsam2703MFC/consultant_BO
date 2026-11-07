<?php
namespace App\Employee\app\Services\Cockpit;

use App\Employee\core\Cookie\CookieManager;
use App\Employee\core\Support\GlobalRegistry;

/**
 * L'app parle au cockpit (31/10/2026, tiré de ProfilService) : l'identité de la session signée avec le secret partagé
 * (comme PrimesService), et le jeton d'employé ; l'adresse locale d'abord (127.0.0.1 avec l'hôte public), puis la
 * publique. Sert « Mon profil » (ProfilService) et le planning (ScheduleService).
 */
class CockpitClient
{
    public function __construct(private CookieManager $cookieManager)
    {
    }

    /**
     * @return array{ok:bool, code:int, data?:array, erreur?:string} — 422 garde ses données (les erreurs par champ).
     */
    public function appel(string $methode, string $chemin, ?array $corps = null): array
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
        error_log('cockpit : ' . $erreur . ' sur ' . $methode . ' ' . $chemin);
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
