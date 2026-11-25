<?php
declare(strict_types=1);

namespace App\Services\Finances\Fx;

/**
 * [438] Client HTTP réel de l'API NBP (curl, JSON, délais courts). L'adresse vient du réglage fx.nbp_api_url
 * (NbpRateService), jamais d'ici. Rien n'est journalisé hors du code d'erreur.
 */
final class CurlNbpHttpClient implements NbpHttpClient
{
    private const USER_AGENT = 'TFB-ERP-Atelier/1.0 (kursy NBP)';

    public function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . self::USER_AGENT]]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new \RuntimeException('NBP niedostępny : ' . mb_substr($err, 0, 160));
        return [$code, (string) $raw];
    }
}
