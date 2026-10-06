<?php
namespace App\Employee\app\Services\Primes;

use App\Employee\core\Cookie\CookieManager;
use App\Employee\core\Support\GlobalRegistry;

/**
 * « Mes primes » : la fiche de la personne connectée, calculée par le cockpit (GET /ventes/moi).
 * L'app se présente avec l'identité de la session (identifiant et nom du jeton), signée avec le
 * secret partagé, et avec le jeton lui-même ; le cockpit ne rend que la fiche de cette personne.
 * Rien n'est calculé ici. Le cockpit vit sur le même serveur : on l'appelle d'abord en local
 * (127.0.0.1, avec l'hôte public en en-tête), puis par son adresse publique.
 */
class PrimesService
{
    public function __construct(private CookieManager $cookieManager)
    {
    }

    /**
     * La fiche du mois demandé (AAAA-MM, le mois en cours par défaut).
     *
     * @return array{ok:bool, fiche?:array, erreur?:string}
     */
    public function pourMoi(?string $mois = null): array
    {
        $jeton = $this->cookieManager->getAccessToken();
        if (!$jeton || !defined('COCKPIT_API_URL') || COCKPIT_API_URL === '') { return ['ok' => false, 'erreur' => 'pas de session ou pas d’adresse du cockpit']; }
        $chemin = '/ventes/moi' . ($mois !== null ? '?m=' . rawurlencode($mois) : '');
        $entetes = ['Accept: application/json', 'Authorization: Bearer ' . $jeton, 'X-Employee-Token: ' . $jeton];
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
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $hote !== null ? array_merge($entetes, ['Host: ' . $hote]) : $entetes,
                // La première lecture d'un mois peut prendre de longues secondes (douze mois relus pour
                // le record) ; les suivantes viennent du cache du cockpit.
                CURLOPT_TIMEOUT => 90,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw !== false && $code === 200) {
                $d = json_decode((string) $raw, true);
                if (is_array($d) && isset($d['emp'])) { return ['ok' => true, 'fiche' => $d]; }
                $erreur = 'réponse illisible du cockpit';
                continue;
            }
            $d = $raw !== false ? json_decode((string) $raw, true) : null;
            $erreur = ($code > 0 ? 'HTTP ' . $code : 'injoignable') . (is_array($d) && isset($d['error']) ? ' : ' . $d['error'] : ($err !== '' ? ' : ' . $err : ''));
            // Une réponse claire du cockpit (401, 404…) ne s'améliore pas par une autre adresse.
            if ($code >= 400 && $code !== 502 && $code !== 503 && $code !== 504) { break; }
        }
        error_log('primes : ' . $erreur . ' sur ' . COCKPIT_API_URL . $chemin);
        return ['ok' => false, 'erreur' => $erreur];
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
