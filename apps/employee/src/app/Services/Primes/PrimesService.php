<?php
namespace App\Employee\app\Services\Primes;

use App\Employee\core\Cookie\CookieManager;

/**
 * « Mes primes » : la fiche de la personne connectée, calculée par le cockpit (GET /ventes/moi).
 * L'app se présente avec le jeton d'employé de la session ; le cockpit le fait confirmer par le
 * panel et ne rend que la fiche de cette personne. Rien n'est calculé ici.
 */
class PrimesService
{
    public function __construct(private CookieManager $cookieManager)
    {
    }

    /** La fiche du mois demandé (AAAA-MM, le mois en cours par défaut) ; null si le cockpit ne répond pas. */
    public function pourMoi(?string $mois = null): ?array
    {
        $jeton = $this->cookieManager->getAccessToken();
        if (!$jeton || !defined('COCKPIT_API_URL') || COCKPIT_API_URL === '') { return null; }
        $url = COCKPIT_API_URL . '/ventes/moi' . ($mois !== null ? '?m=' . rawurlencode($mois) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Le jeton deux fois : Apache ne transmet pas toujours Authorization à PHP.
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $jeton, 'X-Employee-Token: ' . $jeton],
            // La première lecture d'un mois peut prendre de longues secondes (douze mois relus pour
            // le record) ; les suivantes viennent du cache du cockpit.
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code !== 200) {
            error_log('primes : le cockpit a répondu ' . $code . ($err !== '' ? ' (' . $err . ')' : '') . ' sur ' . $url);
            return null;
        }
        $d = json_decode((string) $raw, true);
        return is_array($d) && isset($d['emp']) ? $d : null;
    }
}
